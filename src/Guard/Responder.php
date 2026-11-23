<?php
/**
 * 429 / 403 output + sampled event log (max one line per IP hash, event and route per minute; IP always hashed).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;
use Vextra\Core\Support\Log;

final class Responder {

	public const FORMAT_JSON = 'json';
	public const FORMAT_HTML = 'html';

	/**
	 * Send 429 with Retry-After and stop.
	 *
	 * @param int    $retry  Seconds until the window resets.
	 * @param string $format `json` (AJAX / REST-like) or `html` (page).
	 * @param string $route   Short route label for the log.
	 * @param string $channel Log channel (`guard` default; the Security module logs under `security`).
	 */
	public static function rate_limited( int $retry, string $format, string $route, string $channel = 'guard' ): never {
		self::log( '429', $route, $channel );
		self::headers( 429 );
		header( 'Retry-After: ' . max( 1, $retry ) );

		if ( self::FORMAT_JSON === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode(
				[
					'code'    => 'vx_rate_limited',
					'message' => 'Too many requests. Please wait before trying again.',
					'data'    => [ 'status' => 429 ],
				]
			);
			exit;
		}

		$locale = self::url_locale();
		$args   = [ 'response' => 429 ];
		if ( '' !== $locale ) {
			// Polylang has not set the language yet at this point: render in the language of the URL prefix.
			unload_textdomain( 'vextra-core' );
			load_textdomain( 'vextra-core', dirname( __DIR__, 2 ) . '/languages/vextra-core-' . $locale . '.mo', $locale );
			$args['lang'] = str_replace( '_', '-', $locale );
		}

		wp_die(
			esc_html__( 'Забагато запитів. Спробуйте за кілька секунд.', 'vextra-core' ),
			esc_html__( 'Забагато запитів', 'vextra-core' ),
			$args // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed integer / language code built above.
		);
	}

	/**
	 * WordPress locale of the language named by the URL path prefix (`/ru/...`); '' for the default language, no prefix,
	 * an unknown prefix or a missing `.mo` file. Only the path is read and it must equal a Polylang language slug.
	 */
	private static function url_locale(): string {
		$pll = Plugin::instance()->integration( 'polylang' );
		if ( ! $pll instanceof Polylang || ! $pll->is_ready() ) {
			return '';
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- matched against known slugs, never output.
		$path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$base = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && str_starts_with( $path, $base ) ) {
			$path = ltrim( substr( $path, strlen( $base ) ), '/' );
		}
		$slug = strtolower( (string) strtok( $path, '/' ) );
		if ( '' === $slug || $slug === $pll->default_language() || ! in_array( $slug, $pll->languages(), true ) ) {
			return '';
		}
		$locale = $pll->locale_of( $slug );
		if ( 1 !== preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale ) || ! is_readable( dirname( __DIR__, 2 ) . '/languages/vextra-core-' . $locale . '.mo' ) ) {
			return '';
		}
		return $locale;
	}

	/**
	 * Send 403 without detail and stop.
	 *
	 * @param string $format `json` or `html`.
	 * @param string $route  Short route label for the log.
	 */
	public static function forbidden( string $format, string $route ): never {
		self::log( '403', $route );
		self::headers( 403 );

		if ( self::FORMAT_JSON === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode(
				[
					'code'    => 'rest_forbidden',
					'message' => 'Forbidden.',
					'data'    => [ 'status' => 403 ],
				]
			);
			exit;
		}

		wp_die( '', '', [ 'response' => 403 ] );
	}

	/**
	 * Log a guard event, sampled. Never writes a raw IP.
	 *
	 * @param string $event   `429`, `403`, `redirect` (Security: `lock`, `429`).
	 * @param string $route   Short label (no query string).
	 * @param string $channel Log channel (source `vextra-<channel>`).
	 */
	public static function log( string $event, string $route, string $channel = 'guard' ): void {
		$hash = ClientIp::hash();
		$key  = 'log:' . md5( $channel . '|' . $event . '|' . $route . '|' . $hash );

		if ( wp_using_ext_object_cache() ) {
			if ( ! wp_cache_add( $key, 1, 'vx_guard', 60 ) ) {
				return;
			}
		} else {
			$name = 'vx_guard_' . md5( $key );
			if ( false !== get_transient( $name ) ) {
				return;
			}
			set_transient( $name, 1, 60 );
		}

		Log::warning(
			ucfirst( $channel ) . ' ' . $event,
			[
				'route' => substr( $route, 0, 80 ),
				'ip'    => $hash,
			],
			$channel
		);
	}

	/**
	 * Common no-cache, no-index headers for guard responses.
	 *
	 * @param int $status HTTP status.
	 */
	private static function headers( int $status ): void {
		if ( ! headers_sent() ) {
			status_header( $status );
			nocache_headers();
			header( 'X-Robots-Tag: noindex' );
		}
	}
}
