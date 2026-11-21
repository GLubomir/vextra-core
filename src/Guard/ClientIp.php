<?php
/**
 * Client IP + short hash for logs. REMOTE_ADDR only; X-Forwarded-For is honoured ONLY when REMOTE_ADDR belongs to
 * `VX_TRUSTED_PROXIES` (comma separated string or array of IPs / CIDRs). Default: none, so a spoofed header never
 * changes the bucket.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

final class ClientIp {

	/**
	 * Resolved client IP, or `0.0.0.0` when unknown.
	 */
	public static function get(): string {
		$remote = self::remote_addr();
		if ( '' === $remote ) {
			return '0.0.0.0';
		}

		$proxies = self::list_constant( 'VX_TRUSTED_PROXIES' );
		if ( array() === $proxies || ! self::in_list( $remote, $proxies ) || ! isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) || ! is_string( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			return $remote;
		}

		// Walk the chain from the right: the first address that is not a trusted proxy is the client.
		$chain = array_reverse( array_map( 'trim', explode( ',', substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ), 0, 500 ) ) ) );
		foreach ( $chain as $candidate ) {
			if ( false === filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( ! self::in_list( $candidate, $proxies ) ) {
				return $candidate;
			}
		}
		return $remote;
	}

	/**
	 * Short salted hash (log correlation without storing the address).
	 *
	 * @param string $ip IP; empty = current client.
	 */
	public static function hash( string $ip = '' ): string {
		$ip = '' === $ip ? self::get() : $ip;
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 10 );
	}

	/**
	 * Is the IP inside any entry (plain IP or CIDR) of the list.
	 *
	 * @param string        $ip   IPv4 / IPv6.
	 * @param array<string> $list Entries.
	 */
	public static function in_list( string $ip, array $list ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
		if ( false === $bin ) {
			return false;
		}
		foreach ( $list as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) {
				continue;
			}
			$parts = explode( '/', $entry, 2 );
			$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $net || strlen( $net ) !== strlen( $bin ) ) {
				continue;
			}
			$bits = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : strlen( $net ) * 8;
			if ( $bits > strlen( $net ) * 8 ) {
				continue;
			}
			$bytes = intdiv( $bits, 8 );
			if ( 0 !== $bytes && substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
			if ( ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Constant holding a list (array or comma separated string) as clean array.
	 *
	 * @param string $name Constant name.
	 * @return array<string>
	 */
	public static function list_constant( string $name ): array {
		if ( ! defined( $name ) ) {
			return [];
		}
		$value = constant( $name );
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return [];
		}
		return array_values( array_filter( array_map( static fn( $v ): string => trim( (string) $v ), $value ) ) );
	}

	private static function remote_addr(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) || ! is_string( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
