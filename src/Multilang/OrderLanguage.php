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

	private Polylang $polylang;

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
		$opened = false;
		$locale = $this->locale_for( $payload );
		if ( '' !== $locale && get_locale() !== $locale ) {
			$opened = (bool) switch_to_locale( $locale );
		}
		$this->switched[] = $opened;
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
