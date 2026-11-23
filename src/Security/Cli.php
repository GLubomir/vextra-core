<?php
/**
 * WP-CLI: `wp vextra security status` and `wp vextra security unlock`. CLI only, no web entry point.
 * TASK-029b adds the owner-bot commands next to these.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Guard\ClientIp;
use Vextra\Core\Guard\RateLimiter;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;

final class Cli {

	public function register(): void {
		\WP_CLI::add_command(
			'vextra security status',
			[ $this, 'status' ],
			[ 'shortdesc' => 'Security module state: limits, Rank Math author-archive setting, registration, residual notes. Read-only.' ]
		);
		\WP_CLI::add_command(
			'vextra security unlock',
			[ $this, 'unlock' ],
			[
				'shortdesc' => 'Clear login / lost-password / registration counters and locks.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'login',
						'description' => 'Login name or e-mail to unlock.',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'ip',
						'description' => 'Client IP to unlock (IPv6 is keyed by its /64 prefix).',
						'optional'    => true,
					],
					[
						'type'        => 'flag',
						'name'        => 'all',
						'description' => 'Forget every counter and lock.',
						'optional'    => true,
					],
				],
			]
		);
	}

	/**
	 * `wp vextra security status`.
	 */
	public function status(): void {
		\WP_CLI::line( 'module security: ' . ( Config::enabled() ? 'enabled' : 'DISABLED (VX_SECURITY_DISABLED)' ) );
		$rows = [];
		foreach ( array_keys( Config::DEFAULTS ) as $key ) {
			$const  = 'VX_SECURITY_' . strtoupper( $key );
			$rows[] = [
				'limit'  => $key,
				'value'  => Config::get( $key ),
				'source' => defined( $const ) ? 'constant' : ( Config::get( $key ) === Config::DEFAULTS[ $key ] ? 'default' : 'filter' ),
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'limit', 'value', 'source' ] );

		$ip = ClientIp::get();
		\WP_CLI::line( 'trusted proxies configured: ' . ( array() === ClientIp::list_constant( 'VX_TRUSTED_PROXIES' ) ? 'no' : 'yes' ) );
		\WP_CLI::line( 'exempt IP entries: ' . count( ClientIp::list_constant( 'VX_SECURITY_EXEMPT_IPS' ) ) );
		\WP_CLI::line( 'this machine (ip hash ' . ClientIp::hash( $ip ) . '): login IP failures ' . RateLimiter::peek( 'sec_login_ip', Store::subject( $ip ), max( 1, Config::get( 'login_ip_limit' ) ) )['count'] . ', locked ' . Store::lock_remaining( 'ip', $ip ) . ' s' );
		\WP_CLI::line( 'users_can_register: ' . (string) get_option( 'users_can_register', '0' ) . ' (must be 0)' );

		$rank = Plugin::instance()->integration( 'rankmath' );
		if ( $rank instanceof RankMath && $rank->is_available() ) {
			$value = $rank->setting( 'titles.disable_author_archives', 'unknown' );
			\WP_CLI::line( 'Rank Math titles.disable_author_archives: ' . ( is_scalar( $value ) ? (string) $value : 'unknown' ) . ' (must be on)' );
		} else {
			\WP_CLI::line( 'Rank Math: not active, author-archive setting not checked (front-end 404 for authors still applies).' );
		}

		\WP_CLI::line( 'residual: anonymous /wp-json/ index lists namespaces (INFO, kept); readme.html / license.txt / plugin readme.txt are a server-level task.' );
	}

	/**
	 * `wp vextra security unlock [--login=<l>] [--ip=<ip>] [--all]`.
	 *
	 * @param array<int,string>   $args  Positional args (unused).
	 * @param array<string,mixed> $assoc Flags.
	 */
	public function unlock( array $args, array $assoc ): void {
		unset( $args );
		if ( ! empty( $assoc['all'] ) ) {
			Store::bump_epoch();
			\WP_CLI::success( 'All login, lost-password and registration counters and locks cleared.' );
			return;
		}

		$done = false;
		if ( isset( $assoc['login'] ) && is_string( $assoc['login'] ) && '' !== trim( $assoc['login'] ) ) {
			$ident = Store::identity( sanitize_user( $assoc['login'] ) );
			RateLimiter::reset( 'sec_login_user', Store::subject( $ident ) );
			RateLimiter::reset( 'sec_strike', Store::subject( 'user:' . $ident ) );
			RateLimiter::reset( 'sec_reset_user', Store::subject( $ident ) );
			Store::unlock( 'user', $ident );
			\WP_CLI::success( 'Login unlocked.' );
			$done = true;
		}
		if ( isset( $assoc['ip'] ) && is_string( $assoc['ip'] ) ) {
			if ( false === filter_var( $assoc['ip'], FILTER_VALIDATE_IP ) ) {
				\WP_CLI::error( 'Invalid IP address.' );
			}
			$key = ClientIp::mask( $assoc['ip'] );
			foreach ( [ 'sec_login_ip', 'sec_reset_ip', 'sec_register_ip' ] as $bucket ) {
				RateLimiter::reset( $bucket, Store::subject( $key ) );
			}
			RateLimiter::reset( 'sec_strike', Store::subject( 'ip:' . $key ) );
			Store::unlock( 'ip', $key );
			\WP_CLI::success( 'IP unlocked.' );
			$done = true;
		}
		if ( ! $done ) {
			\WP_CLI::error( 'Pass --login=<login|e-mail>, --ip=<ip> or --all.' );
		}
	}
}
