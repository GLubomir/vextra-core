<?php
/**
 * Lock state + identity helpers shared by the Security services. Counters live in Guard\RateLimiter; this class only adds
 * what it cannot do: a lock with its own TTL (progressive lockout), a global "epoch" that makes `unlock --all` work on
 * any cache backend (every subject carries the epoch, bumping it orphans all counters and locks), and the normalised
 * identity of a login name / e-mail.
 *
 * Nothing stores a raw IP, user name or e-mail: keys are md5 hashes, values are timestamps.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

final class Store {

	private const GROUP  = 'vx_security';
	private const OPTION = 'vx_security_epoch';

	/**
	 * Subject for a RateLimiter bucket or a lock: value prefixed with the epoch.
	 *
	 * @param string $value Client IP key, identity, ...
	 */
	public static function subject( string $value ): string {
		return self::epoch() . '|' . $value;
	}

	/**
	 * Current epoch (changes on `wp vextra security unlock --all`).
	 */
	public static function epoch(): string {
		$epoch = get_option( self::OPTION, '' );
		return is_string( $epoch ) && '' !== $epoch ? $epoch : '0';
	}

	/**
	 * Forget every counter and lock.
	 */
	public static function bump_epoch(): void {
		update_option( self::OPTION, (string) time() . wp_rand( 10, 99 ), true );
	}

	/**
	 * Seconds left of a lock (0 = not locked).
	 *
	 * @param string $kind  `ip` or `user`.
	 * @param string $value IP key / identity.
	 */
	public static function lock_remaining( string $kind, string $value ): int {
		$until = self::read( self::lock_key( $kind, $value ) );
		if ( ! is_numeric( $until ) ) {
			return 0;
		}
		return max( 0, (int) $until - time() );
	}

	/**
	 * Lock a subject.
	 *
	 * @param string $kind    `ip` or `user`.
	 * @param string $value   IP key / identity.
	 * @param int    $seconds Lock length.
	 */
	public static function lock( string $kind, string $value, int $seconds ): void {
		$seconds = max( 1, $seconds );
		self::write( self::lock_key( $kind, $value ), time() + $seconds, $seconds );
	}

	/**
	 * Remove a lock.
	 *
	 * @param string $kind  `ip` or `user`.
	 * @param string $value IP key / identity.
	 */
	public static function unlock( string $kind, string $value ): void {
		$key = self::lock_key( $kind, $value );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, self::GROUP );
			return;
		}
		delete_transient( self::transient_name( $key ) );
	}

	/**
	 * Account behind a login name or e-mail (same lookup order as core: login, then e-mail).
	 *
	 * @param string $login Login or e-mail as typed.
	 */
	public static function find_user( string $login ): ?\WP_User {
		$login = trim( $login );
		if ( '' === $login ) {
			return null;
		}
		$user = get_user_by( 'login', $login );
		if ( ! $user && is_email( $login ) ) {
			$user = get_user_by( 'email', $login );
		}
		return $user instanceof \WP_User ? $user : null;
	}

	/**
	 * Throttle identity of a login name / e-mail: `u<ID>` for an existing account (so login and e-mail share one bucket),
	 * `n:<md5 of the lower-cased text>` otherwise (a nonexistent name locks exactly like an existing one). '' for empty input.
	 *
	 * @param string $login Login or e-mail as typed.
	 */
	public static function identity( string $login ): string {
		$login = trim( $login );
		if ( '' === $login ) {
			return '';
		}
		$user = self::find_user( $login );
		if ( null !== $user ) {
			return 'u' . $user->ID;
		}
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $login, 'UTF-8' ) : strtolower( $login );
		return 'n:' . md5( substr( $lower, 0, 1000 ) );
	}

	private static function lock_key( string $kind, string $value ): string {
		return 'lock:' . $kind . ':' . md5( self::subject( $value ) );
	}

	/**
	 * Stored value.
	 *
	 * @param string $key Entry key.
	 * @return mixed
	 */
	private static function read( string $key ) {
		return wp_using_ext_object_cache() ? wp_cache_get( $key, self::GROUP ) : get_transient( self::transient_name( $key ) );
	}

	/**
	 * Store a value.
	 *
	 * @param string $key   Entry key.
	 * @param int    $value Lock end (timestamp).
	 * @param int    $ttl   Seconds.
	 */
	private static function write( string $key, int $value, int $ttl ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $value, self::GROUP, $ttl );
			return;
		}
		set_transient( self::transient_name( $key ), $value, $ttl );
	}

	private static function transient_name( string $key ): string {
		return 'vx_sec_' . md5( $key );
	}
}
