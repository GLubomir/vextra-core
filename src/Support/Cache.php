<?php
/**
 * Versioned cache with a persistent fallback (ADR-004 s6). Same semantics as the theme's Support\Cache,
 * duplicated on purpose (the plugin must not depend on the theme) with its own prefixes.
 *
 * External object cache present: wp_cache_* with the group version from wp_cache_get_last_changed().
 * Otherwise: transients (`vx_core_*`), group version in an autoloaded option `vx_core_cache_ver_{group}` (no query
 * per read, TASK-035; older rows become autoloaded at their next flush()), bumped by flush().
 * The caller puts the language (and anything else that varies) into $key.
 * Never cache per-user or per-request state.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Support;

use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;

final class Cache {

	/** Action Scheduler hook of the stale-while-revalidate rebuild job (args: group, key). Same name as the theme twin; each side only touches its own entries. */
	public const REBUILD_HOOK = 'vextra/cache/rebuild';

	private const SWR_LIFETIME = WEEK_IN_SECONDS;
	private const SWR_RETRY    = 120;

	/**
	 * Rebuilders per group (recipe array -> fresh value), registered by the feature that owns the group.
	 *
	 * @var array<string,callable>
	 */
	private static array $rebuilders = [];

	/**
	 * Work waiting for `shutdown`: jobs to enqueue ('enqueue') or entries to rebuild after the response ('rebuild').
	 *
	 * @var array<string,array{0:string,1:string,2:string,3:array}>
	 */
	private static array $deferred = [];

	/**
	 * Per-request memo of group versions.
	 *
	 * @var array<string,string>
	 */
	private static array $versions = [];

	/**
	 * Return the cached value or build, store and return it.
	 *
	 * @param string   $group Cache group (also the version scope).
	 * @param string   $key   Key, must already include the language.
	 * @param int      $ttl   Seconds.
	 * @param callable $cb    Builder. A `false` result is not cached.
	 * @return mixed
	 */
	public static function remember( string $group, string $key, int $ttl, callable $cb ) {
		// WP-CLI has no front-end language context: never read or store, so a CLI run cannot poison a
		// per-language entry. Filter `vextra/cache/allow_cli` opts in.
		if ( defined( 'WP_CLI' ) && WP_CLI && ! apply_filters( 'vextra/cache/allow_cli', false ) ) {
			return $cb();
		}

		$version = self::version( $group );

		if ( wp_using_ext_object_cache() ) {
			$full = $key . ':' . $version;
			$hit  = wp_cache_get( $full, $group );
			if ( false !== $hit ) {
				return $hit;
			}
			$value = $cb();
			if ( false !== $value ) {
				wp_cache_set( $full, $value, $group, $ttl );
			}
			return $value;
		}

		$name = 'vx_core_' . substr( $group, 0, 20 ) . '_' . md5( $key . $version );
		$hit  = get_transient( $name );
		if ( false !== $hit ) {
			return $hit;
		}
		$value = $cb();
		if ( false !== $value ) {
			set_transient( $name, $value, $ttl );
		}
		return $value;
	}

	/* ------------------------------------------------------------------ stale-while-revalidate (port of the theme twin, TASK-043b) */

	/**
	 * Hook the Action Scheduler callback and the shutdown fallback (idempotent; called by the module that owns an SWR group).
	 */
	public static function boot_swr(): void {
		if ( false === has_action( self::REBUILD_HOOK, [ self::class, 'run_rebuild' ] ) ) {
			add_action( self::REBUILD_HOOK, [ self::class, 'run_rebuild' ], 10, 2 );
			add_action( 'shutdown', [ self::class, 'run_deferred' ], 100 );
		}
	}

	/**
	 * Register the rebuilder of an SWR group: receives the recipe given to remember_swr() and returns the fresh value. It runs in a
	 * background job without a front-end language context: it must be self-contained (the language comes from the recipe).
	 *
	 * @param string   $group   Cache group.
	 * @param callable $rebuild fn( array $recipe ): mixed.
	 */
	public static function register_rebuilder( string $group, callable $rebuild ): void {
		self::$rebuilders[ $group ] = $rebuild;
	}

	/**
	 * Stale-while-revalidate. flush() only bumps the group version = marks every entry stale; the last value is KEPT.
	 * Fresh: returned. Stale: returned at once; at `shutdown` ONE deduplicated Action Scheduler job is enqueued (no new enqueue for
	 * SWR_RETRY seconds); without Action Scheduler the entry is rebuilt after the response. No value at all: built synchronously.
	 * Not used under WP-CLI (same rule as remember()).
	 *
	 * @param string   $group   Cache group (also the version scope).
	 * @param string   $key     Key, must already include the language.
	 * @param int      $ttl     Freshness in seconds.
	 * @param callable $builder Synchronous builder for the first-ever build.
	 * @param array    $recipe  Plain data the registered rebuilder needs.
	 * @return mixed
	 */
	public static function remember_swr( string $group, string $key, int $ttl, callable $builder, array $recipe ) {
		if ( defined( 'WP_CLI' ) && WP_CLI && ! apply_filters( 'vextra/cache/allow_cli', false ) ) {
			return $builder();
		}

		$entry = self::swr_read( $group, $key );
		if ( is_array( $entry ) && array_key_exists( 'data', $entry ) ) {
			if ( ( $entry['ver'] ?? '' ) === self::version( $group ) && (int) ( $entry['exp'] ?? 0 ) > time() ) {
				return $entry['data'];
			}
			$fresh = self::revalidate( $group, $key, $entry );
			return array_key_exists( 'data', $fresh ) ? $fresh['data'] : $entry['data'];
		}

		$ver   = self::version( $group ); // Read before the build: a flush during it leaves the entry stale.
		$value = $builder();
		if ( false !== $value ) {
			self::swr_write( $group, $key, $ttl, $ver, $value, $recipe );
		}
		return $value;
	}

	/**
	 * Enqueue (or run) the rebuild of a stale entry. Returns ['data' => fresh] only when it was rebuilt synchronously.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @param array  $entry Stale entry.
	 * @return array<string,mixed>
	 */
	private static function revalidate( string $group, string $key, array $entry ): array {
		if ( time() - (int) ( $entry['queued'] ?? 0 ) < self::SWR_RETRY ) {
			return []; // A job was enqueued a moment ago.
		}

		$woo  = Plugin::instance()->integration( 'woocommerce' );
		$mode = $woo instanceof WooCommerce && $woo->has_action_scheduler() ? 'enqueue' : ( function_exists( 'fastcgi_finish_request' ) ? 'rebuild' : '' );
		if ( '' !== $mode ) {
			self::$deferred[ $group . '|' . $key ] = [ $group, $key, $mode, $entry ];
			return [];
		}

		$rebuilt = self::rebuild( $group, $key );
		return null === $rebuilt ? [] : [ 'data' => $rebuilt ];
	}

	/**
	 * Action Scheduler callback: rebuild one entry and store it as fresh.
	 *
	 * @param mixed $group Group.
	 * @param mixed $key   Key.
	 */
	public static function run_rebuild( $group = '', $key = '' ): void {
		if ( is_string( $group ) && is_string( $key ) && '' !== $group && '' !== $key ) {
			self::rebuild( $group, $key );
		}
	}

	/**
	 * `shutdown`: enqueue the queued jobs / rebuild after the response.
	 */
	public static function run_deferred(): void {
		if ( array() === self::$deferred ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		$work           = self::$deferred;
		self::$deferred = [];
		$woo            = Plugin::instance()->integration( 'woocommerce' );
		foreach ( $work as [ $group, $key, $mode, $entry ] ) {
			if ( 'enqueue' === $mode && $woo instanceof WooCommerce && $woo->enqueue_async_action( self::REBUILD_HOOK, [ $group, $key ], 'vextra' ) ) {
				$entry['queued'] = time();
				self::swr_store( $group, $key, $entry );
				continue;
			}
			self::rebuild( $group, $key );
		}
	}

	/**
	 * Rebuild through the registered rebuilder; always stores.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @return mixed|null New value, null when there is nothing to rebuild.
	 */
	private static function rebuild( string $group, string $key ) {
		$entry = self::swr_read( $group, $key );
		if ( ! is_array( $entry ) || ! isset( self::$rebuilders[ $group ] ) ) {
			return null;
		}

		$ver    = self::version( $group );
		$recipe = is_array( $entry['recipe'] ?? null ) ? $entry['recipe'] : [];
		$value  = ( self::$rebuilders[ $group ] )( $recipe );
		if ( false === $value ) {
			return null;
		}
		self::swr_write( $group, $key, (int) ( $entry['ttl'] ?? HOUR_IN_SECONDS ), $ver, $value, $recipe );
		return $value;
	}

	/**
	 * Option names (value + timeout) of the transients behind a SWR entry, for a combined `wp_prime_option_caches()` call.
	 * Empty with an external object cache (reads are not queries there).
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @return string[]
	 */
	public static function swr_option_names( string $group, string $key ): array {
		if ( wp_using_ext_object_cache() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return [];
		}
		$name = self::swr_name( $group, $key );
		return [ '_transient_' . $name, '_transient_timeout_' . $name ];
	}

	private static function swr_name( string $group, string $key ): string {
		return 'vx_core_swr_' . substr( sanitize_key( $group ), 0, 14 ) . '_' . md5( $key );
	}

	/**
	 * Raw stored entry of a SWR key.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @return mixed
	 */
	private static function swr_read( string $group, string $key ) {
		$name = self::swr_name( $group, $key );
		return wp_using_ext_object_cache() ? wp_cache_get( $name, $group ) : get_transient( $name );
	}

	/**
	 * Store a freshly built value as a fresh entry.
	 *
	 * @param string $group  Group.
	 * @param string $key    Key.
	 * @param int    $ttl    Freshness seconds.
	 * @param string $ver    Group version read before the build.
	 * @param mixed  $value  Built value.
	 * @param array  $recipe Rebuild recipe.
	 */
	private static function swr_write( string $group, string $key, int $ttl, string $ver, $value, array $recipe ): void {
		self::swr_store(
			$group,
			$key,
			[
				'ver'    => $ver,
				'exp'    => time() + $ttl,
				'ttl'    => $ttl,
				'data'   => $value,
				'recipe' => $recipe,
				'queued' => 0,
			]
		);
	}

	/**
	 * Write the raw entry.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @param array  $entry Entry.
	 */
	private static function swr_store( string $group, string $key, array $entry ): void {
		$name = self::swr_name( $group, $key );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $name, $entry, $group, self::SWR_LIFETIME );
			return;
		}
		set_transient( $name, $entry, self::SWR_LIFETIME );
	}

	/**
	 * Invalidate every entry of a group (bumps the group version).
	 *
	 * @param string $group Cache group.
	 */
	public static function flush( string $group ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set_last_changed( $group );
			unset( self::$versions[ $group ] );
			return;
		}

		$version = (string) microtime( true );
		if ( false === get_option( self::option_name( $group ), false ) ) {
			add_option( self::option_name( $group ), $version, '', true );
		} else {
			update_option( self::option_name( $group ), $version, true );
		}
		self::$versions[ $group ] = $version;
	}

	private static function version( string $group ): string {
		if ( isset( self::$versions[ $group ] ) ) {
			return self::$versions[ $group ];
		}

		if ( wp_using_ext_object_cache() ) {
			$version = (string) wp_cache_get_last_changed( $group );
		} else {
			$version = get_option( self::option_name( $group ), false );
			if ( ! is_string( $version ) || '' === $version ) {
				add_option( self::option_name( $group ), '1', '', true );
				$version = '1';
			}
		}

		self::$versions[ $group ] = $version;
		return $version;
	}

	private static function option_name( string $group ): string {
		return 'vx_core_cache_ver_' . sanitize_key( $group );
	}
}
