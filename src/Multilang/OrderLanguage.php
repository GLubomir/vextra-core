<?php
/**
 * Order language: stores `_vx_order_lang` on every new order and sends order-bound emails in that language.
 *
 * Hooks (verified in WC 11.3.0): classic checkout `woocommerce_checkout_create_order` ( $order, $data );
 * Store API `woocommerce_store_api_checkout_update_order_meta` ( $order ). In AJAX / REST requests Polylang has no front-end
 * language, so the language comes from the HTTP referer URL (same-host, validated by wp_get_referer()), then the current language.
 *
 * Emails: order-bound `*_notification` triggers are wrapped in `switch_to_locale( order locale )` at priority 1 and
 * `restore_previous_locale()` at priority 999, so admin- and cron-initiated mails use the customer language.
 *
 * Subject + heading (TASK-046): the default texts are re-resolved at send time under the order locale (filters `woocommerce_email_subject_{id}`
 * / `woocommerce_email_heading_{id}`) unless the owner customised them in WooCommerce > Settings > Emails (a customised string is kept as saved).
 * Admin e-mails (new / cancelled / failed order) are addressed to the (Ukrainian) store owner: their trigger is re-registered inside a locale
 * switch to the site default language, so the WHOLE admin mail (subject, heading, body) stays Ukrainian for any order language.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Multilang;

use Vextra\Core\Integrations\Polylang\Polylang;

final class OrderLanguage {

	public const META = '_vx_order_lang';

	/**
	 * Order-bound notification hooks (suffix `_notification` is already included).
	 */
	private const EMAIL_HOOKS = [
		'woocommerce_order_status_pending_to_processing_notification',
		'woocommerce_order_status_pending_to_completed_notification',
		'woocommerce_order_status_pending_to_on-hold_notification',
		'woocommerce_order_status_failed_to_processing_notification',
		'woocommerce_order_status_failed_to_completed_notification',
		'woocommerce_order_status_failed_to_on-hold_notification',
		'woocommerce_order_status_cancelled_to_processing_notification',
		'woocommerce_order_status_cancelled_to_completed_notification',
		'woocommerce_order_status_cancelled_to_on-hold_notification',
		'woocommerce_order_status_on-hold_to_processing_notification',
		'woocommerce_order_status_processing_to_cancelled_notification',
		'woocommerce_order_status_on-hold_to_cancelled_notification',
		'woocommerce_order_status_processing_to_failed_notification',
		'woocommerce_order_status_on-hold_to_failed_notification',
		'woocommerce_order_status_completed_notification',
		'woocommerce_order_status_refunded_notification',
		'woocommerce_order_fully_refunded_notification',
		'woocommerce_order_partially_refunded_notification',
		'woocommerce_new_customer_note_notification',
	];

	/**
	 * E-mail ids whose default subject / heading are re-resolved under the active locale.
	 */
	private const TEXT_EMAIL_IDS = [
		'customer_processing_order',
		'customer_completed_order',
		'customer_on_hold_order',
		'customer_refunded_order',
		'customer_partially_refunded_order',
		'customer_note',
		'customer_failed_order',
		'customer_invoice',
		'new_order',
		'cancelled_order',
		'failed_order',
	];

	/**
	 * Admin-bound e-mail ids (always in the site default language).
	 */
	private const ADMIN_EMAIL_IDS = [ 'new_order', 'cancelled_order', 'failed_order' ];

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	/**
	 * Admin triggers already wrapped (`hook|email id`).
	 *
	 * @var bool[]
	 */
	private array $wrapped = [];

	/**
	 * Whether a locale switch is open per nested notification.
	 *
	 * @var bool[]
	 */
	private array $switched = [];

	public function __construct( Polylang $polylang ) {
		$this->polylang = $polylang;
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', [ $this, 'store' ] );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', [ $this, 'store' ] );

		foreach ( self::TEXT_EMAIL_IDS as $id ) {
			add_filter( 'woocommerce_email_subject_' . $id, [ $this, 'email_subject' ], 10, 3 );
			add_filter( 'woocommerce_email_heading_' . $id, [ $this, 'email_heading' ], 10, 3 );
		}
		foreach ( self::EMAIL_HOOKS as $hook ) {
			add_action( $hook, [ $this, 'switch_locale' ], 1 );
			add_action( $hook, [ $this, 'restore_locale' ], 999 );
		}
	}

	/**
	 * Save the language on the order (HPOS-safe, meta only).
	 *
	 * @param mixed $order Order being created.
	 */
	public function store( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$lang = $this->detect();
		if ( '' !== $lang ) {
			$order->update_meta_data( self::META, $lang );
		}
	}

	/**
	 * Open the order locale before an email trigger runs.
	 *
	 * @param mixed $payload Order ID, or the args array of the customer-note hook.
	 */
	public function switch_locale( $payload = null ): void {
		$this->wrap_admin_triggers( (string) current_filter() );
		$opened = false;
		$locale = $this->locale_for( $payload );
		if ( '' !== $locale && get_locale() !== $locale ) {
			$opened = (bool) switch_to_locale( $locale );
		}
		$this->switched[] = $opened;
	}

	/**
	 * Subject: the Woo default re-resolved in the active locale (a customised subject is kept).
	 *
	 * @param mixed $subject Formatted subject.
	 * @param mixed $order_or_object Order / object (unused).
	 * @param mixed $email   WC_Email.
	 * @return mixed
	 */
	public function email_subject( $subject, $order_or_object = null, $email = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( $email instanceof \WC_Email && ! $this->is_customised( $email, 'subject' ) ) {
			return $email->format_string( $email->get_default_subject() );
		}
		return $subject;
	}

	/**
	 * Heading: the Woo default re-resolved in the active locale (a customised heading is kept).
	 *
	 * @param mixed $heading Formatted heading.
	 * @param mixed $order_or_object Order / object (unused).
	 * @param mixed $email   WC_Email.
	 * @return mixed
	 */
	public function email_heading( $heading, $order_or_object = null, $email = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( $email instanceof \WC_Email && ! $this->is_customised( $email, 'heading' ) ) {
			return $email->format_string( $email->get_default_heading() );
		}
		return $heading;
	}

	/**
	 * Whether the store owner saved their own text for a field. The email object's `get_option()` is not usable for this: it falls back to a
	 * default string translated when the form fields were first built (in whatever language that was), so the stored option is read directly.
	 *
	 * @param \WC_Email $email E-mail.
	 * @param string    $field Field key (`subject` / `heading`).
	 */
	private function is_customised( \WC_Email $email, string $field ): bool {
		$saved = get_option( $email->get_option_key(), [] );
		return is_array( $saved ) && isset( $saved[ $field ] ) && '' !== trim( (string) $saved[ $field ] );
	}

	/**
	 * Re-register the triggers of the admin e-mails of this notification hook inside a switch to the site default locale.
	 * Runs at priority 1 of the hook (WooCommerce has instantiated its mailer by then); idempotent per hook + e-mail.
	 *
	 * @param string $hook Current notification hook.
	 */
	private function wrap_admin_triggers( string $hook ): void {
		if ( '' === $hook || ! function_exists( 'WC' ) || ! WC() || ! method_exists( WC(), 'mailer' ) ) {
			return;
		}
		$default = $this->polylang->locale_of( $this->polylang->default_language() );
		if ( '' === $default ) {
			return;
		}
		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( ! $email instanceof \WC_Email || ! in_array( $email->id, self::ADMIN_EMAIL_IDS, true ) || isset( $this->wrapped[ $hook . '|' . $email->id ] ) ) {
				continue;
			}
			$priority = has_action( $hook, [ $email, 'trigger' ] );
			if ( false === $priority ) {
				continue;
			}
			$this->wrapped[ $hook . '|' . $email->id ] = true;
			remove_action( $hook, [ $email, 'trigger' ], $priority );
			add_action(
				$hook,
				static function () use ( $email, $default ): void {
					$opened = get_locale() !== $default && (bool) switch_to_locale( $default );
					try {
						$email->trigger( ...func_get_args() );
					} finally {
						if ( $opened ) {
							restore_previous_locale();
						}
					}
				},
				(int) $priority,
				3
			);
		}
	}

	public function restore_locale(): void {
		if ( array_pop( $this->switched ) ) {
			restore_previous_locale();
		}
	}

	/**
	 * Locale of the order referenced by the hook payload ('' when unknown).
	 *
	 * @param mixed $payload Order ID or args array.
	 */
	private function locale_for( $payload ): string {
		if ( is_array( $payload ) ) {
			$payload = $payload['order_id'] ?? 0;
		}
		$order_id = is_numeric( $payload ) ? (int) $payload : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}
		$lang = (string) $order->get_meta( self::META, true );
		return '' === $lang ? '' : $this->polylang->locale_of( sanitize_key( $lang ) );
	}

	/**
	 * Language of the current checkout request.
	 */
	private function detect(): string {
		$current = $this->polylang->current_language();
		$async   = wp_doing_ajax() || defined( 'REST_REQUEST' ) || isset( $_GET['wc-ajax'] ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( $async || '' === $current ) {
			$referer = wp_get_referer();
			$from    = is_string( $referer ) ? $this->polylang->language_from_url( $referer ) : '';
			if ( '' !== $from ) {
				return $from;
			}
		}

		return '' !== $current ? $current : $this->polylang->default_language();
	}
}
