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
