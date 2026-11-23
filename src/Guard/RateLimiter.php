<?php
/**
 * Fixed-window per-subject counter. Storage: external object cache when present (no database write per request),
 * otherwise a short-lived transient (`vx_guard_*`, expires with the window).
 *
 * Atomicity (SEC-045): with a persistent object cache the counter is a separate entry advanced by `wp_cache_incr()` after an
 * `wp_cache_add()` (add-then-increment: Redis INCR / Memcached incr are atomic, so a parallel burst cannot overshoot the limit by
 * more than the requests already in flight). Without a persistent cache the counter is a transient read-modify-write: a
 * burst of parallel requests may overshoot the limit by a few hits (best effort, accepted for abuse throttling). It never
 * blocks a legitimate user earlier than the limit.
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

		$now = time();
		$key = $bucket . ':' . md5( $subject );
		if ( wp_using_ext_object_cache() ) {
			$atomic = self::hit_atomic( $key, $limit, $window, $now );
			if ( null !== $atomic ) {
				return $atomic;
			}
		}
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
	 * Add-then-increment counter on a persistent object cache. Null = the backend refused (caller uses the legacy path).
	 *
	 * @param string $key    Entry key.
	 * @param int    $limit  Hits per window.
	 * @param int    $window Window seconds.
	 * @param int    $now    Current timestamp.
	 * @return array{allowed:bool,retry_after:int,remaining:int}|null
	 */
	private static function hit_atomic( string $key, int $limit, int $window, int $now ): ?array {
		$end = wp_cache_get( $key . ':e', self::GROUP );
		if ( is_numeric( $end ) && (int) $end <= $now ) {
			// Window over but the counter outlived it by a tick: start a new one.
			wp_cache_delete( $key . ':e', self::GROUP );
			wp_cache_delete( $key . ':n', self::GROUP );
		}
		wp_cache_add( $key . ':e', $now + $window, self::GROUP, $window );
		wp_cache_add( $key . ':n', 0, self::GROUP, $window );
		$count = wp_cache_incr( $key . ':n', 1, self::GROUP );
		if ( false === $count ) {
			return null;
		}
		$end     = wp_cache_get( $key . ':e', self::GROUP );
		$left    = is_numeric( $end ) ? max( 1, (int) $end - $now ) : $window;
		$allowed = (int) $count <= $limit;
		return [
			'allowed'     => $allowed,
			'retry_after' => $allowed ? 0 : $left,
			'remaining'   => max( 0, $limit - (int) $count ),
		];
	}

	/**
	 * Read a bucket without counting a hit (lock checks, status output).
	 *
	 * @param string $bucket  Bucket name.
	 * @param string $subject Subject.
	 * @param int    $limit   Hits per window (as passed to hit()); 0 = bucket disabled.
	 * @return array{count:int,remaining:int,retry_after:int} `retry_after` is the seconds left in the window (0 when no window is open).
	 */
	public static function peek( string $bucket, string $subject, int $limit ): array {
		$key   = $bucket . ':' . md5( $subject );
		$state = self::read( $key );
		$now   = time();
		if ( wp_using_ext_object_cache() && ! is_array( $state ) ) {
			$count = wp_cache_get( $key . ':n', self::GROUP );
			$end   = wp_cache_get( $key . ':e', self::GROUP );
			$state = is_numeric( $count ) && is_numeric( $end ) ? [ (int) $count, (int) $end ] : null;
		}
		if ( $limit <= 0 || ! is_array( $state ) || ! isset( $state[0], $state[1] ) || (int) $state[1] <= $now ) {
			return [
				'count'       => 0,
				'remaining'   => $limit > 0 ? $limit : PHP_INT_MAX,
				'retry_after' => 0,
			];
		}
		return [
			'count'       => (int) $state[0],
			'remaining'   => max( 0, $limit - (int) $state[0] ),
			'retry_after' => max( 1, (int) $state[1] - $now ),
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
			wp_cache_delete( $key . ':n', self::GROUP );
			wp_cache_delete( $key . ':e', self::GROUP );
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
