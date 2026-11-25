<?php
/**
 * Checkout gate (TASK-045a): what must hold BEFORE a place-order request is allowed to create or touch an order.
 *
 * Store API `POST /wc/store/v1/checkout` (also as a sub-request of `/batch`: `rest_pre_dispatch` fires for each one), runs right after the Guard route
 * rules (priority 5, which count the IP bucket):
 *   1. Turnstile (when keys are set): missing / failed token = HTTP 403 `vx_turnstile_failed`, no order, no draft order (TurnstileCheckout).
 *   2. Billing e-mail bucket: `checkout_email_limit` per hour (Guard `CheckoutThrottle`, hashed key), counted AFTER the token passed so a client without a
 *      valid token cannot lock somebody else's address. Exceeded = 429 + `Retry-After`.
 * Classic checkout: the same e-mail bucket in `woocommerce_after_checkout_validation` (the IP bucket runs in `Guard\WcAjax`).
 * The owner-bot / exempt accounts (`Guard\Exempt`) and a disabled Guard module skip the throttle; the owner bot also skips Turnstile.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Checkout;

use Vextra\Core\Guard\CheckoutThrottle;
use Vextra\Core\Guard\ClientIp;
use Vextra\Core\Guard\Config;
use Vextra\Core\Guard\Exempt;
use Vextra\Core\Guard\Failsafe;
use Vextra\Core\Guard\Responder;
use Vextra\Core\Guard\StoreApi;
use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;

final class Gate {

	private const BATCH     = '#^/wc/store(?:/v\d+)?/batch/?$#i';
	private const MAX_FIELD = 255;

	/**
	 * Turnstile helper (null when the adapter has no keys).
	 *
	 * @var TurnstileCheckout|null
	 */
	private ?TurnstileCheckout $turnstile;

	public function __construct( ?TurnstileCheckout $turnstile ) {
		$this->turnstile = $turnstile;
	}

	/**
	 * A locale switch of this request is open (restored after the dispatch).
	 *
	 * @var bool
	 */
	private bool $switched = false;

	/**
	 * Name of the MySQL lock held by this request (null = none).
	 *
	 * @var string|null
	 */
	private ?string $lock_name = null;

	public function register(): void {
		add_filter( 'rest_pre_dispatch', [ $this, 'pre_dispatch' ], 6, 3 );
		add_filter( 'rest_post_dispatch', [ $this, 'restore_locale' ], 999 );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'classic_email_bucket' ], 20, 2 );
	}

	/**
	 * Store API checkout POST.
	 *
	 * @param mixed $result  Short-circuit value.
	 * @param mixed $server  REST server.
	 * @param mixed $request Request.
	 * @return mixed
	 */
	public function pre_dispatch( $result, $server = null, $request = null ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		$route = strtolower( (string) $request->get_route() );
		if ( 1 === preg_match( self::BATCH, $route ) && 'POST' === strtoupper( $request->get_method() ) && $this->batch_has_checkout( $request ) ) {
			// SEC-073: one Turnstile token authorises exactly one checkout; a batch would run several under one verdict. Place-order is never a batch sub-request.
			return $this->bad_request( 'vx_checkout_not_batchable' );
		}
		if ( 1 !== preg_match( StoreApi::CHECKOUT, $route ) ) {
			return $result;
		}
		// A REST request has no Polylang context: every message of the checkout answer (Woo's and ours) is rendered in the language of the page it came from.
		if ( ! $this->switched ) {
			$this->switched = (bool) Failsafe::run( 'checkout-locale', static fn(): bool => CheckoutThrottle::switch_locale(), false );
		}
		if ( 'POST' !== strtoupper( $request->get_method() ) ) {
			return $result;
		}
		// Fail open: a bug in the gate must never stop real orders.
		$response = Failsafe::run( 'checkout-gate', fn() => $this->check( $request ), null );
		return null === $response ? $result : $response;
	}

	/**
	 * Close the locale switch opened for the checkout request and release the order lock.
	 *
	 * @param mixed $response REST response (returned unchanged).
	 * @return mixed
	 */
	public function restore_locale( $response ) {
		$this->unlock();
		if ( $this->switched ) {
			$this->switched = false;
			restore_previous_locale();
		}
		return $response;
	}

	/**
	 * Run the gate for one Store API checkout POST.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|null
	 */
	private function check( \WP_REST_Request $request ) {
		// 1. Shape of the payload first (SEC-075): nothing is verified, locked or counted for a request Woo would crash on.
		$bad = $this->invalid_payload( $request );
		if ( null !== $bad ) {
			return $bad;
		}
		// Account creation at checkout is off (SEC-071): the body cannot switch it on (it is also the "e-mail already registered" oracle).
		if ( 'yes' !== (string) get_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' ) ) {
			$request->set_param( 'create_account', false );
		}
		// 2. Turnstile: one token, one checkout.
		if ( null !== $this->turnstile ) {
			$refused = $this->turnstile->check_request( $request );
			if ( null !== $refused ) {
				return $refused;
			}
		}
		$billing = $request->get_param( 'billing_address' );
		$email   = is_array( $billing ) && isset( $billing['email'] ) && is_string( $billing['email'] ) ? $billing['email'] : '';
		// 3. One place-order at a time per (client key, mailbox) (SEC-072): server-derived key, taken only after the token passed.
		if ( ! $this->lock( $email ) ) {
			$busy = rest_convert_error_to_response( new \WP_Error( 'vx_checkout_busy', __( 'Замовлення вже оформлюється. Зачекайте кілька секунд.', 'vextra-core' ), [ 'status' => 409 ] ) );
			$busy->header( 'Cache-Control', 'no-store' );
			return $busy;
		}
		if ( ! Config::enabled() || Exempt::is_exempt() ) {
			return null;
		}
		// 4. E-mail bucket (SEC-074): only for a request that can reach order creation (non-empty cart), keyed on the normalised mailbox.
		if ( $this->cart_is_empty() ) {
			return null;
		}
		$hit = CheckoutThrottle::hit_email( $email );
		if ( $hit['allowed'] ) {
			return null;
		}
		Responder::log( '429', 'store-api-checkout-email' );
		$response = rest_convert_error_to_response( new \WP_Error( 'vx_rate_limited', CheckoutThrottle::message( $hit['retry_after'] ), [ 'status' => 429 ] ) );
		$response->header( 'Retry-After', (string) max( 1, $hit['retry_after'] ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The cart of this session holds nothing (Woo answers 409 `cart_empty`). The session is loaded here, never created.
	 */
	private function cart_is_empty(): bool {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( $woo instanceof WooCommerce ) {
			$woo->load_cart();
		}
		return ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart || WC()->cart->is_empty();
	}

	/**
	 * SEC-075: billing / shipping address values must be scalar strings with a length cap, `customer_note` / `payment_method` strings: otherwise a
	 * generic 400 BEFORE the token is verified or Woo validates (an array e-mail made `is_email()` fatal inside Woo).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|null
	 */
	private function invalid_payload( \WP_REST_Request $request ) {
		foreach ( [ 'billing_address', 'shipping_address' ] as $key ) {
			$address = $request->get_param( $key );
			if ( null === $address ) {
				continue;
			}
			if ( ! is_array( $address ) ) {
				return $this->bad_request( 'vx_bad_request' );
			}
			foreach ( $address as $field => $value ) {
				if ( ! is_string( $field ) || strlen( $field ) > 64 || ! is_string( $value ) || strlen( $value ) > self::MAX_FIELD ) {
					return $this->bad_request( 'vx_bad_request' );
				}
			}
		}
		foreach ( [
			'customer_note'  => 4000,
			'payment_method' => 100,
		] as $key => $max ) {
			$value = $request->get_param( $key );
			if ( null !== $value && ( ! is_string( $value ) || strlen( $value ) > $max ) ) {
				return $this->bad_request( 'vx_bad_request' );
			}
		}
		return null;
	}

	/**
	 * Does a `/batch` body carry a checkout sub-request?
	 *
	 * @param \WP_REST_Request $request Batch request.
	 */
	private function batch_has_checkout( \WP_REST_Request $request ): bool {
		$requests = $request->get_param( 'requests' );
		if ( ! is_array( $requests ) ) {
			return false;
		}
		foreach ( $requests as $sub ) {
			$path = is_array( $sub ) && isset( $sub['path'] ) && is_string( $sub['path'] ) ? strtolower( (string) wp_parse_url( $sub['path'], PHP_URL_PATH ) ) : '';
			if ( '' !== $path && 1 === preg_match( StoreApi::CHECKOUT, rtrim( $path, '/' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Generic 400.
	 *
	 * @param string $code Machine code.
	 */
	private function bad_request( string $code ): \WP_REST_Response {
		$response = rest_convert_error_to_response( new \WP_Error( $code, __( 'Перевірте введені дані й спробуйте ще раз.', 'vextra-core' ), [ 'status' => 400 ] ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Name of the order lock: server-derived client key (IP, IPv6 per /64) + normalised mailbox (SEC-072). Nothing the client sends decides it.
	 *
	 * @param string $email Raw billing e-mail.
	 */
	public static function lock_name( string $email ): string {
		global $wpdb;
		return 'vx_co_' . substr( md5( $wpdb->prefix . '|' . ClientIp::get() . '|' . CheckoutThrottle::normalise_email( $email ) ), 0, 24 );
	}

	/**
	 * One place-order request per (client key, mailbox) at a time: a MySQL named lock (atomic, released when the request ends or in `rest_post_dispatch`).
	 * Fail open: no database lock available = allowed.
	 *
	 * @param string $email Raw billing e-mail.
	 */
	private function lock( string $email ): bool {
		global $wpdb;
		if ( null !== $this->lock_name ) {
			return true;
		}
		$name = self::lock_name( $email );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- named lock, no table.
		if ( null === $got || '' === (string) $got ) {
			return true;
		}
		if ( 1 !== (int) $got ) {
			return false;
		}
		$this->lock_name = $name;
		return true;
	}

	/**
	 * Release the order lock.
	 */
	private function unlock(): void {
		global $wpdb;
		if ( null === $this->lock_name ) {
			return;
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $this->lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- named lock, no table.
		$this->lock_name = null;
	}

	/**
	 * Classic checkout: e-mail bucket after the form validated (the Turnstile check ran at priority 10).
	 *
	 * @param mixed $data   Posted data.
	 * @param mixed $errors WP_Error.
	 */
	public function classic_email_bucket( $data, $errors = null ): void {
		if ( ! is_array( $data ) || ! $errors instanceof \WP_Error || $errors->has_errors() || ! Config::enabled() || Exempt::is_exempt() ) {
			return;
		}
		$email = isset( $data['billing_email'] ) && is_string( $data['billing_email'] ) ? $data['billing_email'] : '';
		$hit   = Failsafe::run( 'checkout-email', static fn(): array => CheckoutThrottle::hit_email( $email ), [ 'allowed' => true ] );
		if ( ! empty( $hit['allowed'] ) ) {
			return;
		}
		Responder::log( '429', 'checkout-email' );
		$errors->add( 'vx_rate_limited', CheckoutThrottle::message( (int) ( $hit['retry_after'] ?? 60 ) ) );
	}
}
