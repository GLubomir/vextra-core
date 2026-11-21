<?php
/**
 * Known-bad User-Agent deny-list. Substrings, case-insensitive, filterable (`vextra/core/guard/bad_user_agents`).
 *
 * Scope is narrow on purpose: cart / checkout / account pages, `?wc-ajax=`, Store API routes and search requests.
 * It is a deny-list, never an allow-list: an empty UA is allowed, search-engine bots can never match
 * (hard-coded safety net even if the filter adds a pattern that hits them), catalog / product / home / sitemaps / robots.txt
 * are never checked (see the callers).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

final class UserAgents {

	/**
	 * Default patterns. `curl/` is intentionally absent (local tooling).
	 */
	public const BAD = [
		'sqlmap',
		'nikto',
		'masscan',
		'zgrab',
		'nmap',
		'wpscan',
		'dirbuster',
		'gobuster',
		'python-requests',
		'go-http-client',
		'ahrefsbot',
		'semrushbot',
		'mj12bot',
		'dotbot',
		'petalbot',
		'bytespider',
		'blexbot',
		'dataforseobot',
	];

	/**
	 * Search-engine crawlers that must never be denied.
	 */
	private const GOOD = [ 'googlebot', 'google-inspectiontool', 'bingbot', 'yandexbot', 'yandex.com/bots', 'applebot', 'duckduckbot' ];

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'handle_pages' ], 0 );
	}

	/**
	 * Cart, checkout and account pages (conditional tags are ready on `template_redirect`).
	 */
	public function handle_pages(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || Request::is_never_limited() ) {
			return;
		}
		Failsafe::run(
			'ua-pages',
			static function (): void {
				if ( ( is_cart() || is_checkout() || is_account_page() ) && self::is_bad() && ! Exempt::is_exempt() ) {
					Responder::forbidden( Responder::FORMAT_HTML, 'page-ua' );
				}
			}
		);
	}

	/**
	 * Does the current request carry a denied UA.
	 */
	public static function is_bad(): bool {
		$ua = strtolower( Request::user_agent() );
		if ( '' === $ua ) {
			return false;
		}
		foreach ( self::GOOD as $good ) {
			if ( str_contains( $ua, $good ) ) {
				return false;
			}
		}

		/**
		 * Filters the denied User-Agent substrings.
		 *
		 * @param string[] $patterns Lower-case substrings.
		 */
		$patterns = apply_filters( 'vextra/core/guard/bad_user_agents', self::BAD );
		if ( ! is_array( $patterns ) ) {
			return false;
		}
		foreach ( $patterns as $pattern ) {
			$pattern = strtolower( trim( (string) $pattern ) );
			if ( '' !== $pattern && str_contains( $ua, $pattern ) ) {
				return true;
			}
		}
		return false;
	}
}
