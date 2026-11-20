<?php
/**
 * Thin logger over wc_get_logger(). Source = `vextra-<channel>` (default channel `core`).
 *
 * SECURITY: never pass secrets as messages. Context entries whose key contains
 * password|token|secret|key|authorization (case-insensitive, recursive) are removed before writing.
 * Falls back to error_log() only when WP_DEBUG_LOG is on and WooCommerce's logger is unavailable.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Support;

final class Log {

	private const SENSITIVE = [ 'password', 'token', 'secret', 'key', 'authorization' ];

	/**
	 * Log at debug level.
	 *
	 * @param string              $msg     Message.
	 * @param array<string,mixed> $context Extra data (secrets are stripped).
	 * @param string              $channel Channel suffix of the log source.
	 */
	public static function debug( string $msg, array $context = [], string $channel = 'core' ): void {
		self::write( 'debug', $msg, $context, $channel );
	}

	/**
	 * Log at info level. See debug() for params.
	 *
	 * @param string              $msg     Message.
	 * @param array<string,mixed> $context Extra data.
	 * @param string              $channel Channel.
	 */
	public static function info( string $msg, array $context = [], string $channel = 'core' ): void {
		self::write( 'info', $msg, $context, $channel );
	}

	/**
	 * Log at warning level. See debug() for params.
	 *
	 * @param string              $msg     Message.
	 * @param array<string,mixed> $context Extra data.
	 * @param string              $channel Channel.
	 */
	public static function warning( string $msg, array $context = [], string $channel = 'core' ): void {
		self::write( 'warning', $msg, $context, $channel );
	}

	/**
	 * Log at error level. See debug() for params.
	 *
	 * @param string              $msg     Message.
	 * @param array<string,mixed> $context Extra data.
	 * @param string              $channel Channel.
	 */
	public static function error( string $msg, array $context = [], string $channel = 'core' ): void {
		self::write( 'error', $msg, $context, $channel );
	}

	/**
	 * Recursively drop sensitive keys.
	 *
	 * @param array<mixed> $context Raw context.
	 * @return array<mixed>
	 */
	public static function scrub( array $context ): array {
		foreach ( $context as $name => $value ) {
			if ( is_string( $name ) && self::is_sensitive( $name ) ) {
				unset( $context[ $name ] );
			} elseif ( is_array( $value ) ) {
				$context[ $name ] = self::scrub( $value );
			}
		}
		return $context;
	}

	private static function is_sensitive( string $name ): bool {
		$name = strtolower( $name );
		foreach ( self::SENSITIVE as $needle ) {
			if ( str_contains( $name, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Write one entry.
	 *
	 * @param string              $level   PSR-3 level.
	 * @param string              $msg     Message.
	 * @param array<string,mixed> $context Context.
	 * @param string              $channel Channel.
	 */
	private static function write( string $level, string $msg, array $context, string $channel ): void {
		$source = 'vextra-' . sanitize_key( $channel );
		$clean  = self::scrub( $context );
		$line   = array() === $clean ? $msg : $msg . ' ' . wp_json_encode( $clean );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $line, [ 'source' => $source ] );
			return;
		}

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( '[%s] %s: %s', $source, $level, $line ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
