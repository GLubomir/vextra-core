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

use Vextra\Core\Guard\ClientIp;

final class Store {

	/**
	 * Lock kinds: `ip`, `user` (username-wide, degraded no-keys mode only) and `pair` (account + client IP).
	 */
	public const KINDS = [ 'ip', 'user', 'pair' ];

	/**
	 * User meta: generation of the account's pair keys (`wp vextra security unlock --login` bumps it, orphaning every pair lock).
	 */
	public const META_GENERATION = '_vx_login_gen';

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
		self::purge_transients();
	}

	/**
	 * Throttle key of one (account or name, client IP) pair. Existing accounts carry a generation (user meta) so that an
	 * unlock by login can orphan every pair lock of the account without knowing the IPs behind them.
	 *
	 * @param string      $ident Store::identity().
	 * @param string|null $ip    Client IP key (already masked); null = the current client.
	 */
	public static function pair( string $ident, ?string $ip = null ): string {
		$gen = '';
		if ( 1 === preg_match( '/^u(\d+)$/', $ident, $m ) ) {
			$meta = get_user_meta( (int) $m[1], self::META_GENERATION, true );
			$gen  = is_scalar( $meta ) && '' !== (string) $meta ? '|' . (string) $meta : '';
		}
		return $ident . '|' . ClientIp::hash( $ip ?? ClientIp::get() ) . $gen;
	}

	/**
	 * Orphan every pair lock / counter of an existing account (unlock by login).
	 *
	 * @param string $ident Store::identity().
	 */
	public static function bump_generation( string $ident ): void {
		if ( 1 === preg_match( '/^u(\d+)$/', $ident, $m ) ) {
			update_user_meta( (int) $m[1], self::META_GENERATION, (string) time() . wp_rand( 10, 99 ) );
		}
	}

	/**
	 * Locks currently set per kind (transient backend only; null = unknown with a persistent object cache, which cannot be listed).
	 *
	 * @return array<string,int>|null
	 */
	public static function lock_counts(): ?array {
		if ( wp_using_ext_object_cache() ) {
			return null;
		}
		global $wpdb;
		$counts = [];
		foreach ( self::KINDS as $kind ) {
			$like            = $wpdb->esc_like( '_transient_timeout_vx_sec_' . $kind . '_' ) . '%';
			$count           = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) > %d", $like, time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin CLI status, counts our own lock transients.
			$counts[ $kind ] = (int) $count;
		}
		return $counts;
	}

	/**
	 * Delete the lock transients of every epoch (after `unlock --all`; keeps the options table free of orphans).
	 */
	private static function purge_transients(): void {
		if ( wp_using_ext_object_cache() ) {
			return;
		}
		global $wpdb;
		foreach ( [ '_transient_vx_sec_', '_transient_timeout_vx_sec_' ] as $prefix ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- only our own lock transients.
		}
	}

	/**
	 * Seconds left of a lock (0 = not locked).
	 *
	 * @param string $kind  `ip` or `user`.
	 * @param string $value IP key / identity.
	 */
	public static function lock_remaining( string $kind, string $value ): int {
		$until = self::read( $kind, self::lock_key( $kind, $value ) );
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
		self::write( $kind, self::lock_key( $kind, $value ), time() + $seconds, $seconds );
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
		delete_transient( self::transient_name( $kind, $key ) );
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
	 * @param string $kind Lock kind.
	 * @param string $key  Entry key.
	 * @return mixed
	 */
	private static function read( string $kind, string $key ) {
		return wp_using_ext_object_cache() ? wp_cache_get( $key, self::GROUP ) : get_transient( self::transient_name( $kind, $key ) );
	}

	/**
	 * Store a value.
	 *
	 * @param string $kind  Lock kind.
	 * @param string $key   Entry key.
	 * @param int    $value Lock end (timestamp).
	 * @param int    $ttl   Seconds.
	 */
	private static function write( string $kind, string $key, int $value, int $ttl ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $value, self::GROUP, $ttl );
			return;
		}
		set_transient( self::transient_name( $kind, $key ), $value, $ttl );
	}

	private static function transient_name( string $kind, string $key ): string {
		return 'vx_sec_' . $kind . '_' . md5( $key );
	}
}
