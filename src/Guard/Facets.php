<?php
/**
 * Facet-URL guard (TASK-043a, ADR-007 L1/L2): per-IP bucket for front-end GET requests whose query string carries a facet /
 * sort / search / paged parameter (`Catalog\Params::has_facet()`). Facet URLs are Disallowed in robots.txt, so legitimate crawlers
 * never reach them; humans toggling filters stay far below the limit (default 120 requests / 60 s, `facet_limit` / `facet_window`,
 * constants `VX_GUARD_FACET_LIMIT` / `VX_GUARD_FACET_WINDOW`, 0 disables). The limit is generous on purpose: mobile carriers put
 * many shoppers behind one IP (CGNAT), IPv6 is bucketed per /64 (`ClientIp`).
 *
 * `parse_request` priority 1 (before the main query). Over the limit: the 429 page in the language of the URL (`Responder`) with
 * `Retry-After` and `X-Robots-Tag: noindex`. Clean category / product URLs never touch this bucket. Skipped for REST, admin,
 * AJAX, cron, WP-CLI, `wc-ajax`, owner-bot / `Exempt` clients. Fails open.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

use Vextra\Core\Catalog\Params;

final class Facets {

	public function register(): void {
		add_action( 'parse_request', [ $this, 'on_parse_request' ], 1 );
	}

	/**
	 * Guard the main request before the query runs.
	 *
	 * @param \WP $wp Request object.
	 */
	public function on_parse_request( $wp ): void {
		if ( ! $wp instanceof \WP || is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_ajax() || wp_doing_cron() || Request::is_never_limited() ) {
			return;
		}
		Failsafe::run( 'facets', fn() => $this->guard( $wp ) );
	}

	private function guard( \WP $wp ): void {
		if ( ! in_array( Request::method(), [ 'GET', 'HEAD' ], true ) ) {
			return;
		}
		// REST routes are bucketed by StoreApi; `wc-ajax` by WcAjax. Their `orderby=` etc. are not catalog URLs.
		if ( ! empty( $wp->query_vars['rest_route'] ) || isset( $_GET['rest_route'] ) || isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- key presence only.
			return;
		}
		if ( ! Params::has_facet( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- keys only, nothing is read or output.
			return;
		}
		if ( Exempt::is_exempt() ) {
			return;
		}

		$hit = RateLimiter::hit( 'facet', ClientIp::get(), Config::get( 'facet_limit' ), Config::get( 'facet_window' ) );
		if ( ! $hit['allowed'] ) {
			Responder::rate_limited( $hit['retry_after'], Responder::FORMAT_HTML, 'facet' );
		}
	}
}
