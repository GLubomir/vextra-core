<?php
/**
 * Small request readers shared by the guards (every superglobal read is unslashed + sanitised here).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

final class Request {

	/**
	 * Upper-case HTTP method.
	 */
	public static function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
	}

	/**
	 * Lower-case decoded request path (no query string).
	 */
	public static function path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed, lower-cased, never output.
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) ? strtolower( rawurldecode( $path ) ) : '';
	}

	/**
	 * User-Agent, cut to 300 chars. Empty string when absent.
	 */
	public static function user_agent(): string {
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) || ! is_string( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}
		return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 300 );
	}

	/**
	 * Value of `?wc-ajax=` (empty when absent or not a plain string).
	 */
	public static function wc_ajax(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing flag.
		if ( ! isset( $_GET['wc-ajax'] ) || ! is_string( $_GET['wc-ajax'] ) ) {
			return '';
		}
		return sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Requests that no bucket and no UA rule may ever touch: robots.txt and sitemaps (Google treats 429/5xx there as
	 * "disallow all" / crawl-rate signal).
	 */
	public static function is_never_limited(): bool {
		// Only a plain GET/HEAD without routing args is exempt: wc-ajax / rest_route / add-to-cart work on any path.
		if ( ! in_array( self::method(), [ 'GET', 'HEAD' ], true ) || '' !== self::wc_ajax() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only.
		foreach ( [ 'wc-ajax', 'rest_route', 'add-to-cart' ] as $arg ) {
			if ( array_key_exists( $arg, $_GET ) || array_key_exists( $arg, $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return false;
			}
		}
		$path = self::path();
		if ( '' === $path ) {
			return false;
		}
		if ( preg_match( '#/robots\.txt$#', $path ) ) {
			return true;
		}
		return 1 === preg_match( '#sitemap[^/]*\.(xml|xsl)$#', $path );
	}
}
