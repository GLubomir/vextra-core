<?php
/**
 * 429 / 403 output + sampled event log (max one line per IP hash, event and route per minute; IP always hashed).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

use Vextra\Core\Support\Log;

final class Responder {

	public const FORMAT_JSON = 'json';
	public const FORMAT_HTML = 'html';

	/**
	 * Send 429 with Retry-After and stop.
	 *
	 * @param int    $retry  Seconds until the window resets.
	 * @param string $format `json` (AJAX / REST-like) or `html` (page).
	 * @param string $route  Short route label for the log.
	 */
	public static function rate_limited( int $retry, string $format, string $route ): never {
		self::log( '429', $route );
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

		wp_die(
			esc_html__( 'Забагато запитів. Спробуйте за кілька секунд.', 'vextra-core' ),
			esc_html__( 'Забагато запитів', 'vextra-core' ),
			[ 'response' => 429 ]
		);
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
	 * @param string $event `429`, `403`, `redirect`.
	 * @param string $route Short label (no query string).
	 */
	public static function log( string $event, string $route ): void {
		$hash = ClientIp::hash();
		$key  = 'log:' . md5( $event . '|' . $route . '|' . $hash );

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
			'Guard ' . $event,
			[
				'route' => substr( $route, 0, 80 ),
				'ip'    => $hash,
			],
			'guard'
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
