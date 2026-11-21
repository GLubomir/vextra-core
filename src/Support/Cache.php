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

final class Cache {

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
