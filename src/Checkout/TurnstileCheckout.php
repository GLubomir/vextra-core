<?php
/**
 * Turnstile on the checkout (TASK-045a, F7). Registered ONLY when the adapter has both keys: without keys no hook, no script, no request and no
 * extension schema exist, so the checkout is byte-identical to a site without Turnstile.
 *
 * Spike result (block checkout can carry the token): the Checkout block posts `extensions` with every place-order request; the script of the theme
 * (TASK-045b) hands the widget token to the documented store action `wp.data.dispatch( 'wc/store/checkout' ).setExtensionData( 'vextra', { turnstile: token } )`.
 * The server registers that property for the checkout endpoint (`WooCommerce::register_checkout_extension()`, no storage) and reads
 * `extensions.vextra.turnstile`.
 *
 * Verification (`verify( $token, $ip, 'checkout' )`, the adapter contract incl. action + hostname, SEC-049, is not weakened):
 * - Store API: `Gate` verifies in `rest_pre_dispatch` BEFORE Woo creates a draft order (failure = HTTP 403 `vx_turnstile_failed`, no order, no draft);
 *   `woocommerce_store_api_checkout_update_order_from_request` is a backstop (same token, memoised by the adapter: no second Cloudflare call) that throws a
 *   `RouteException` should a request reach the order step unchecked.
 * - Classic checkout (`[woocommerce_checkout]`, the fallback): widget printed before the submit button, verified in `woocommerce_after_checkout_validation`
 *   from the posted `cf-turnstile-response`. Order-pay has no validation hook: only the IP throttle applies there.
 * - `api.js` loads on the checkout form page only (not the cart, the thank-you or the order-pay endpoint). Skipped for the owner bot account.
 * Provider failure follows `VX_TURNSTILE_FAIL` (open by default, one log line). Logged-in customers are verified like guests.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Checkout;

use Vextra\Core\Guard\CheckoutThrottle;
use Vextra\Core\Guard\ClientIp;
use Vextra\Core\Guard\Exempt;
use Vextra\Core\Guard\Failsafe;
use Vextra\Core\Guard\Responder;
use Vextra\Core\Integrations\Turnstile\Turnstile;
use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;

final class TurnstileCheckout {

	public const ERROR_CODE = 'vx_turnstile_failed';
	public const NAMESPACE  = 'vextra';
	public const PROPERTY   = 'turnstile';

	/**
	 * Store API requests whose token was already checked (the backstop does not repeat it).
	 *
	 * @var \WeakMap<\WP_REST_Request,bool>|null
	 */
	private ?\WeakMap $passed = null;

	/**
	 * Whether the module is active (adapter available).
	 */
	public static function active(): bool {
		$ts = Plugin::instance()->integration( 'turnstile' );
		return $ts instanceof Turnstile && $ts->is_available();
	}

	public function register(): void {
		if ( ! self::active() ) {
			return;
		}
		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( $woo instanceof WooCommerce ) {
			$woo->register_checkout_extension( self::NAMESPACE, self::PROPERTY, 'Cloudflare Turnstile token of the checkout widget.' );
			$woo->on_checkout_order_update( [ $this, 'backstop' ], 1 );
		}
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 20 );
		add_action( 'woocommerce_review_order_before_submit', [ $this, 'classic_widget' ] );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'classic_verify' ], 10, 2 );
	}

	/**
	 * Load api.js on the checkout form page only.
	 */
	public function enqueue(): void {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		$ts  = $this->adapter();
		if ( null === $ts || ! $woo instanceof WooCommerce || ! $woo->is_checkout_form_page() || Exempt::is_owner_bot() ) {
			return;
		}
		$ts->enqueue();
	}

	/**
	 * Classic checkout form: the widget before the submit button.
	 */
	public function classic_widget(): void {
		$ts = $this->adapter();
		if ( null !== $ts && ! Exempt::is_owner_bot() ) {
			$ts->render_widget( 'checkout' );
		}
	}

	/**
	 * Classic checkout validation.
	 *
	 * @param mixed $data   Posted data.
	 * @param mixed $errors WP_Error.
	 */
	public function classic_verify( $data, $errors = null ): void {
		unset( $data );
		if ( ! $errors instanceof \WP_Error || in_array( self::ERROR_CODE, $errors->get_error_codes(), true ) || Exempt::is_owner_bot() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- the token is the proof, validated by Cloudflare; only a string is accepted, unslashed and sanitised below.
		$raw   = isset( $_POST[ Turnstile::FIELD ] ) && is_string( $_POST[ Turnstile::FIELD ] ) ? wp_unslash( $_POST[ Turnstile::FIELD ] ) : '';
		$token = '' !== $raw ? substr( sanitize_text_field( $raw ), 0, 2100 ) : '';
		if ( ! $this->passes( $token ) ) {
			$errors->add( self::ERROR_CODE, $this->message() );
		}
	}

	/**
	 * Store API: pre-dispatch check (called by `Gate`). Null = passed; otherwise the refusal response.
	 *
	 * @param \WP_REST_Request $request Checkout POST.
	 * @return \WP_REST_Response|null
	 */
	public function check_request( \WP_REST_Request $request ) {
		if ( Exempt::is_owner_bot() ) {
			return null;
		}
		if ( ! $this->passes( self::token_of( $request ) ) ) {
			return rest_convert_error_to_response( new \WP_Error( self::ERROR_CODE, $this->message(), [ 'status' => 403 ] ) );
		}
		$this->passed()[ $request ] = true;
		return null;
	}

	/**
	 * Backstop on `woocommerce_store_api_checkout_update_order_from_request`.
	 *
	 * @param mixed $order   Order.
	 * @param mixed $request Request.
	 * @throws \Exception The Woo route exception (HTTP 403).
	 */
	public function backstop( $order, $request = null ): void {
		unset( $order );
		if ( ! $request instanceof \WP_REST_Request || 'POST' !== strtoupper( $request->get_method() ) || isset( $this->passed()[ $request ] ) || Exempt::is_owner_bot() ) {
			return;
		}
		if ( $this->passes( self::token_of( $request ) ) ) {
			$this->passed()[ $request ] = true;
			return;
		}
		$woo       = Plugin::instance()->integration( 'woocommerce' );
		$exception = $woo instanceof WooCommerce ? $woo->route_exception( self::ERROR_CODE, $this->message(), 403 ) : null;
		if ( null !== $exception ) {
			throw $exception;
		}
		wp_die( esc_html( $this->message() ), '', [ 'response' => 403 ] );
	}

	/**
	 * Token of a Store API request (`extensions.vextra.turnstile`), sanitised.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function token_of( \WP_REST_Request $request ): string {
		$extensions = $request->get_param( 'extensions' );
		$value      = is_array( $extensions ) && is_array( $extensions[ self::NAMESPACE ] ?? null ) ? ( $extensions[ self::NAMESPACE ][ self::PROPERTY ] ?? '' ) : '';
		return is_string( $value ) && '' !== $value ? substr( sanitize_text_field( $value ), 0, 2100 ) : '';
	}

	/**
	 * Verify a token; provider failure follows the adapter's open / closed rule.
	 *
	 * @param string $token Widget token.
	 */
	private function passes( string $token ): bool {
		$ts = $this->adapter();
		if ( null === $ts ) {
			return true;
		}
		$verdict = Failsafe::run(
			'turnstile-verify',
			static fn(): array => $ts->verify( $token, ClientIp::get( false ), 'checkout' ),
			[
				'ok'     => true,
				'reason' => 'guard_failed',
			]
		);
		if ( ! empty( $verdict['ok'] ) ) {
			return true;
		}
		Responder::log( 'turnstile-' . (string) ( $verdict['reason'] ?? 'failed' ), 'checkout', 'security' );
		return false;
	}

	/**
	 * Generic refusal text in the shopper's language.
	 */
	private function message(): string {
		$switched = CheckoutThrottle::switch_locale();
		$text     = __( 'Підтвердіть, що ви не робот і спробуйте ще раз.', 'vextra-core' );
		if ( $switched ) {
			restore_previous_locale();
		}
		return $text;
	}

	/**
	 * Requests whose token already passed (lazy map).
	 *
	 * @return \WeakMap<\WP_REST_Request,bool>
	 */
	private function passed(): \WeakMap {
		if ( null === $this->passed ) {
			$this->passed = new \WeakMap();
		}
		return $this->passed;
	}

	private function adapter(): ?Turnstile {
		$ts = Plugin::instance()->integration( 'turnstile' );
		return $ts instanceof Turnstile && $ts->is_available() ? $ts : null;
	}
}
