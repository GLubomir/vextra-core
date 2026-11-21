<?php
/**
 * Fixed-window per-subject counter. Storage: external object cache when present (no database write per request),
 * otherwise a short-lived transient (`vx_guard_*`, expires with the window).
 *
 * The counter is read-modify-write, not atomic: a burst of parallel requests may overshoot the limit by a few
 * hits. That is acceptable for abuse throttling; it never blocks a legitimate user earlier than the limit.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

final class RateLimiter {

	private const GROUP = 'vx_guard';

	/**
	 * Register one hit and tell whether it is still inside the limit.
	 *
	 * @param string $bucket  Bucket name (`ajax`, `coupon`, `rest`, `search`).
	 * @param string $subject Usually the client IP.
	 * @param int    $limit   Hits per window; 0 disables the bucket (always allowed).
	 * @param int    $window  Window in seconds.
	 * @return array{allowed:bool,retry_after:int,remaining:int}
	 */
	public static function hit( string $bucket, string $subject, int $limit, int $window ): array {
		if ( $limit <= 0 || $window <= 0 ) {
			return [
				'allowed'     => true,
				'retry_after' => 0,
				'remaining'   => PHP_INT_MAX,
			];
		}

		$now   = time();
		$key   = $bucket . ':' . md5( $subject );
		$state = self::read( $key );

		if ( ! is_array( $state ) || ! isset( $state[0], $state[1] ) || (int) $state[1] <= $now ) {
			$state = [ 0, $now + $window ];
		}
		++$state[0];
		self::write( $key, $state, max( 1, (int) $state[1] - $now ) );

		$allowed = (int) $state[0] <= $limit;
		return [
			'allowed'     => $allowed,
			'retry_after' => $allowed ? 0 : max( 1, (int) $state[1] - $now ),
			'remaining'   => max( 0, $limit - (int) $state[0] ),
		];
	}

	/**
	 * Drop a bucket entry (tests, owner-requested resets).
	 *
	 * @param string $bucket  Bucket name.
	 * @param string $subject Subject.
	 */
	public static function reset( string $bucket, string $subject ): void {
		$key = $bucket . ':' . md5( $subject );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, self::GROUP );
			return;
		}
		delete_transient( self::transient_name( $key ) );
	}

	/**
	 * Stored state.
	 *
	 * @param string $key Entry key.
	 * @return mixed
	 */
	private static function read( string $key ) {
		return wp_using_ext_object_cache() ? wp_cache_get( $key, self::GROUP ) : get_transient( self::transient_name( $key ) );
	}

	/**
	 * Store state.
	 *
	 * @param string           $key   Entry key.
	 * @param array<int,mixed> $state [ count, reset timestamp ].
	 * @param int              $ttl   Seconds left in the window.
	 */
	private static function write( string $key, array $state, int $ttl ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $state, self::GROUP, $ttl );
			return;
		}
		set_transient( self::transient_name( $key ), $state, $ttl );
	}

	private static function transient_name( string $key ): string {
		return 'vx_guard_' . md5( $key );
	}
}
