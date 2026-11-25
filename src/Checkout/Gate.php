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

final class Gate {

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
		if ( null !== $result || ! $request instanceof \WP_REST_Request || 1 !== preg_match( StoreApi::CHECKOUT, strtolower( (string) $request->get_route() ) ) ) {
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
		if ( ! $this->lock() ) {
			// A second place-order request of the same shopper while the first one is still running: refuse it (never two orders).
			$busy = rest_convert_error_to_response( new \WP_Error( 'vx_checkout_busy', __( 'Замовлення вже оформлюється. Зачекайте кілька секунд.', 'vextra-core' ), [ 'status' => 409 ] ) );
			$busy->header( 'Cache-Control', 'no-store' );
			return $busy;
		}
		if ( null !== $this->turnstile ) {
			$refused = $this->turnstile->check_request( $request );
			if ( null !== $refused ) {
				return $refused;
			}
		}
		if ( ! Config::enabled() || Exempt::is_exempt() ) {
			return null;
		}
		$billing = $request->get_param( 'billing_address' );
		$email   = is_array( $billing ) && isset( $billing['email'] ) && is_string( $billing['email'] ) ? $billing['email'] : '';
		$hit     = CheckoutThrottle::hit_email( $email );
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
	 * One place-order request per shopper at a time: a MySQL named lock (atomic, released when the request ends or in `rest_post_dispatch`).
	 * Key = the Woo session id (cookie value up to the first `||`), else the client IP. Fail open: no database lock available = allowed.
	 */
	private function lock(): bool {
		global $wpdb;
		if ( null !== $this->lock_name ) {
			return true; // A batch with two checkout sub-requests shares one lock: the second sub-request is refused by Woo's own cart checks.
		}
		$who = '';
		foreach ( (array) $_COOKIE as $cookie_name => $cookie_value ) {
			if ( is_string( $cookie_name ) && str_starts_with( $cookie_name, 'wp_woocommerce_session_' ) && is_string( $cookie_value ) ) {
				$who = (string) strtok( sanitize_text_field( wp_unslash( $cookie_value ) ), '|' );
				break;
			}
		}
		$who  = '' !== $who ? $who : ClientIp::get();
		$name = 'vx_co_' . substr( md5( $wpdb->prefix . '|' . $who ), 0, 24 );
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
