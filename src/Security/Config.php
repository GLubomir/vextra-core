<?php
/**
 * Security configuration: the one place that knows every number.
 *
 * Resolution per key: constant `VX_SECURITY_<KEY>` (wp-config.php) -> filter `vextra/core/security/limits` -> default.
 * Master switch: constant `VX_SECURITY_DISABLED` or filter `vextra/core/security/enabled`.
 * Exemptions: constant `VX_SECURITY_EXEMPT_IPS` (CIDR list) + filter `vextra/core/security/exempt` (127.0.0.1 is NOT exempt).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Guard\ClientIp;

final class Config {

	/**
	 * Default numbers. `*_limit` = failures / requests allowed per `*_window` seconds. 0 disables the bucket.
	 */
	public const DEFAULTS = [
		'login_ip_limit'          => 20,   // Failed logins per client IP (high: mobile carrier NAT shares IPs).
		'login_user_limit'        => 8,    // Failed logins per (username / e-mail, client IP) PAIR: failures from IP A never lock IP B.
		'login_user_global_limit' => 30,   // Failed logins per username across all IPs; locks unknown IPs only, and ONLY without Turnstile keys.
		'login_window'            => 900,
		'reset_ip_limit'          => 5,    // Lost-password requests per client IP.
		'reset_ip_window'         => 900,
		'reset_user_limit'        => 3,    // Lost-password mails per (account, client IP) pair per `reset_user_window`.
		'reset_user_global_limit' => 20,   // Lost-password mails per account across all IPs (mail-flood ceiling for one victim).
		'reset_user_window'       => 3600,
		'register_ip_limit'       => 5,    // Registration submits per client IP.
		'register_ip_window'      => 3600,
		'lock_base'               => 900,  // First lock, seconds; every repeat inside `strike_window` doubles it.
		'lock_max'                => 86400,
		'strike_window'           => 86400,
		'known_ips'               => 5,    // Remembered (hashed) IPs per user that skip the global per-username lock (no-keys mode).
	];

	/**
	 * Resolved integer for a key (never negative).
	 *
	 * @param string $key Key of self::DEFAULTS.
	 */
	public static function get( string $key ): int {
		$value = self::DEFAULTS[ $key ] ?? 0;

		/**
		 * Filters every Security number.
		 *
		 * @param array<string,int> $limits Key => value (see Config::DEFAULTS).
		 */
		$limits = apply_filters( 'vextra/core/security/limits', self::DEFAULTS );
		if ( is_array( $limits ) && isset( $limits[ $key ] ) && is_numeric( $limits[ $key ] ) ) {
			$value = (int) $limits[ $key ];
		}

		$const = 'VX_SECURITY_' . strtoupper( $key );
		if ( defined( $const ) && is_numeric( constant( $const ) ) ) {
			$value = (int) constant( $const );
		}

		return max( 0, $value );
	}

	/**
	 * Master kill switch.
	 */
	public static function enabled(): bool {
		$on = ! ( defined( 'VX_SECURITY_DISABLED' ) && VX_SECURITY_DISABLED );

		/**
		 * Filters whether the Security module is active.
		 *
		 * @param bool $on Default: true unless VX_SECURITY_DISABLED is truthy.
		 */
		return (bool) apply_filters( 'vextra/core/security/enabled', $on );
	}

	/**
	 * Is the current client exempt from throttling (listed IP / CIDR or approved by the filter).
	 */
	public static function is_exempt(): bool {
		$ip = ClientIp::get( false );

		/**
		 * Filters the throttle exemption of the current request.
		 *
		 * @param bool   $exempt Default false.
		 * @param string $ip     Client IP (raw, IPv6 not masked).
		 */
		if ( apply_filters( 'vextra/core/security/exempt', false, $ip ) ) {
			return true;
		}
		return ClientIp::in_list( $ip, ClientIp::list_constant( 'VX_SECURITY_EXEMPT_IPS' ) );
	}
}
