<?php
/**
 * `?wc-ajax=` guard: bad-UA deny, per-IP bucket for every endpoint, stricter coupon bucket for `apply_coupon`.
 *
 * Hook: `init` priority 1 (WooCommerce defines DOING_AJAX at `init` 0 and runs the handler on `template_redirect` 0,
 * so the limiter always answers first). Responses are JSON 429 / 403. robots.txt and sitemaps never reach it.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

final class WcAjax {

	public function register(): void {
		add_action( 'init', [ $this, 'handle' ], 1 );
	}

	public function handle(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || Request::is_never_limited() ) {
			return;
		}
		Failsafe::run( 'wc-ajax', fn() => $this->check() );
		Failsafe::run( 'checkout-post', fn() => $this->checkout_post() );
	}

	/**
	 * Classic checkout / order-pay POST (`wc-ajax=checkout`, the plain checkout form POST, the order-pay form): the client IP bucket of the
	 * checkout throttle (TASK-045a, same bucket as the Store API checkout). The billing e-mail bucket runs after validation
	 * (`Checkout\Gate`), when the visitor has passed the Turnstile check.
	 */
	private function checkout_post(): void {
		if ( 'POST' !== Request::method() || Exempt::is_exempt() ) {
			return;
		}
		$ajax = 'checkout' === Request::wc_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence checks of the submit fields only; Woo verifies its own nonces.
		if ( ! $ajax && ! isset( $_POST['woocommerce_checkout_place_order'] ) && ! isset( $_POST['woocommerce_pay'] ) ) {
			return;
		}
		$hit = CheckoutThrottle::hit_ip();
		if ( ! $hit['allowed'] ) {
			Responder::rate_limited( $hit['retry_after'], $ajax ? Responder::FORMAT_JSON : Responder::FORMAT_HTML, 'checkout-post', 'guard', CheckoutThrottle::message( $hit['retry_after'] ) );
		}
	}

	private function check(): void {
		$action = Request::wc_ajax();
		if ( '' === $action || Exempt::is_exempt() ) {
			return;
		}

		if ( UserAgents::is_bad() ) {
			Responder::forbidden( Responder::FORMAT_JSON, 'wc-ajax-ua' );
		}

		$ip  = ClientIp::get();
		$hit = RateLimiter::hit( 'ajax', $ip, Config::get( 'ajax_limit' ), Config::get( 'ajax_window' ) );
		if ( ! $hit['allowed'] ) {
			Responder::rate_limited( $hit['retry_after'], Responder::FORMAT_JSON, 'wc-ajax' );
		}

		if ( 'apply_coupon' === $action ) {
			$hit = RateLimiter::hit( 'coupon', $ip, Config::get( 'coupon_limit' ), Config::get( 'coupon_window' ) );
			if ( ! $hit['allowed'] ) {
				Responder::rate_limited( $hit['retry_after'], Responder::FORMAT_JSON, 'wc-ajax-coupon' );
			}
		}
	}
}
