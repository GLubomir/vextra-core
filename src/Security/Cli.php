<?php
/**
 * WP-CLI: `wp vextra security status|unlock` and `wp vextra owner-bot create|revoke|list|repair` (TASK-029b). CLI only, no web
 * entry point. The only secret ever printed is the one-time Application Password of `owner-bot create`; it is never logged.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Guard\ClientIp;
use Vextra\Core\Guard\RateLimiter;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Integrations\Turnstile\Turnstile;
use Vextra\Core\Integrations\TwoFactor\TwoFactor;
use Vextra\Core\Plugin;

final class Cli {

	public function register(): void {
		$this->register_owner_bot();
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

		$failed = $this->status_access();

		\WP_CLI::line( 'residual: anonymous /wp-json/ index lists namespaces (INFO, kept); readme.html / license.txt / plugin readme.txt are a server-level task.' );
		if ( $failed ) {
			\WP_CLI::error( 'Owner-bot role holds capabilities outside the allowed list (run: wp vextra owner-bot repair).' );
		}
	}

	/**
	 * Status lines of TASK-029b: owner-bot role, Turnstile, Two Factor.
	 *
	 * @return bool True when the owner-bot role is wrong (the command exits with an error after printing everything).
	 */
	private function status_access(): bool {
		$failed = false;
		$extra  = OwnerBotRole::extra_caps();
		if ( null === $extra ) {
			\WP_CLI::line( 'owner-bot role ' . OwnerBotRole::ROLE . ': missing (created on the next request or by `wp vextra owner-bot repair`)' );
		} elseif ( array() !== $extra ) {
			$failed = true;
			\WP_CLI::line( 'owner-bot role ' . OwnerBotRole::ROLE . ': EXTRA CAPABILITIES ' . implode( ', ', $extra ) );
		} else {
			\WP_CLI::line( 'owner-bot role ' . OwnerBotRole::ROLE . ': ok (' . count( OwnerBotRole::caps() ) . ' capabilities, no extras); accounts: ' . count( $this->bots() ) );
		}
		$ips = ClientIp::list_constant( 'VX_OWNER_BOT_IPS' );
		\WP_CLI::line( 'owner-bot IP allow-list (VX_OWNER_BOT_IPS): ' . ( array() === $ips ? 'none (no restriction)' : count( $ips ) . ' entries' ) );

		$ts = Plugin::instance()->integration( 'turnstile' );
		if ( $ts instanceof Turnstile && $ts->is_available() ) {
			\WP_CLI::line( 'Turnstile: ON (fail ' . ( $ts->fails_open() ? 'open' : 'closed' ) . ')' );
		} else {
			\WP_CLI::line( 'Turnstile: off (keys missing)' );
		}

		$tf = Plugin::instance()->integration( 'twofactor' );
		if ( $tf instanceof TwoFactor && $tf->is_available() ) {
			\WP_CLI::line( 'Two Factor: active ' . (string) $tf->version() );
		} else {
			\WP_CLI::line( 'Two Factor: NOT active (staff 2FA is a LAUNCH GATE)' );
		}
		$staff = TwoFactorPolicy::staff_without_two_factor();
		if ( null !== $staff ) {
			\WP_CLI::line( 'staff without 2FA (LAUNCH GATE): ' . ( array() === $staff ? 'none' : implode( ', ', $staff ) ) );
		}
		return $failed;
	}

	/**
	 * Owner-bot accounts (by role).
	 *
	 * @return \WP_User[]
	 */
	private function bots(): array {
		return get_users( [ 'role' => OwnerBotRole::ROLE ] );
	}

	private function register_owner_bot(): void {
		\WP_CLI::add_command(
			'vextra owner-bot create',
			[ $this, 'bot_create' ],
			[
				'shortdesc' => 'Create the owner-bot account (no interactive login; random password, never shown). Optionally mint its first Application Password, printed ONCE.',
				'synopsis'  => [
					[
						'type'     => 'positional',
						'name'     => 'login',
						'optional' => false,
					],
					[
						'type'     => 'assoc',
						'name'     => 'email',
						'optional' => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'app-password',
						'description' => 'Name of an Application Password to create now (printed once).',
						'optional'    => true,
					],
				],
			]
		);
		\WP_CLI::add_command(
			'vextra owner-bot revoke',
			[ $this, 'bot_revoke' ],
			[
				'shortdesc' => 'Delete every Application Password of an owner bot (the account stays).',
				'synopsis'  => [
					[
						'type'     => 'positional',
						'name'     => 'login',
						'optional' => false,
					],
				],
			]
		);
		\WP_CLI::add_command( 'vextra owner-bot list', [ $this, 'bot_list' ], [ 'shortdesc' => 'List owner-bot accounts and their Application Passwords (no secrets).' ] );
		\WP_CLI::add_command( 'vextra owner-bot repair', [ $this, 'bot_repair' ], [ 'shortdesc' => 'Re-sync the owner-bot role capabilities and delete Application Passwords of non-bot users.' ] );
	}

	/**
	 * `wp vextra owner-bot create <login> [--email=<e>] [--app-password=<name>]`.
	 *
	 * @param array<int,string>   $args  Login.
	 * @param array<string,mixed> $assoc Flags.
	 */
	public function bot_create( array $args, array $assoc ): void {
		$login = sanitize_user( (string) ( $args[0] ?? '' ), true );
		if ( '' === $login || strlen( $login ) > 60 ) {
			\WP_CLI::error( 'Invalid login.' );
		}
		OwnerBotRole::ensure();

		$existing = get_user_by( 'login', $login );
		if ( $existing instanceof \WP_User ) {
			if ( ! OwnerBotRole::is_bot( $existing ) ) {
				\WP_CLI::error( 'A user with this login exists and is not an owner bot; nothing was changed.' );
			}
			\WP_CLI::line( 'Owner bot "' . $login . '" already exists (ID ' . $existing->ID . '); nothing created.' );
			$user = $existing;
		} else {
			$email = isset( $assoc['email'] ) && is_string( $assoc['email'] ) ? sanitize_email( $assoc['email'] ) : $login . '@owner-bot.invalid';
			if ( ! is_email( $email ) ) {
				\WP_CLI::error( 'Invalid e-mail.' );
			}
			if ( email_exists( $email ) ) {
				\WP_CLI::error( 'This e-mail already belongs to another user; no account was created.' );
			}
			$id = wp_insert_user(
				[
					'user_login'           => $login,
					'user_pass'            => wp_generate_password( 64, true, true ), // Never shown: the bot signs in with an Application Password only.
					'user_email'           => $email,
					'role'                 => OwnerBotRole::ROLE,
					'show_admin_bar_front' => 'false',
				]
			);
			if ( is_wp_error( $id ) ) {
				\WP_CLI::error( $id->get_error_message() );
			}
			$user = get_userdata( (int) $id );
			\WP_CLI::success( 'Owner bot "' . $login . '" created (ID ' . (int) $id . ').' );
		}

		if ( isset( $assoc['app-password'] ) && $user instanceof \WP_User ) {
			$name = is_string( $assoc['app-password'] ) ? trim( sanitize_text_field( $assoc['app-password'] ) ) : '';
			$name = '' === $name ? 'owner-bot' : ( function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100 ) : substr( $name, 0, 100 ) );
			$new  = \WP_Application_Passwords::create_new_application_password( (int) $user->ID, [ 'name' => $name ] );
			if ( is_wp_error( $new ) ) {
				\WP_CLI::error( $new->get_error_message() );
			}
			\WP_CLI::line( 'Application Password "' . $name . '" (shown ONCE, store it now):' );
			\WP_CLI::line( (string) $new[0] );
		}
	}

	/**
	 * `wp vextra owner-bot revoke <login>`.
	 *
	 * @param array<int,string> $args Login.
	 */
	public function bot_revoke( array $args ): void {
		$user = get_user_by( 'login', sanitize_user( (string) ( $args[0] ?? '' ), true ) );
		if ( ! $user instanceof \WP_User || ! OwnerBotRole::is_bot( $user ) ) {
			\WP_CLI::error( 'No owner bot with this login.' );
		}
		$count = \WP_Application_Passwords::delete_all_application_passwords( (int) $user->ID );
		\WP_CLI::success( is_wp_error( $count ) ? $count->get_error_message() : 'Application Passwords deleted: ' . (int) $count . '.' );
	}

	/**
	 * `wp vextra owner-bot list`.
	 */
	public function bot_list(): void {
		$rows = [];
		foreach ( $this->bots() as $user ) {
			$items  = \WP_Application_Passwords::get_user_application_passwords( (int) $user->ID );
			$latest = 0;
			foreach ( $items as $item ) {
				$latest = max( $latest, (int) ( $item['last_used'] ?? 0 ) );
			}
			$rows[] = [
				'ID'        => $user->ID,
				'login'     => $user->user_login,
				'app_pw'    => count( $items ),
				'last_used' => $latest > 0 ? gmdate( 'Y-m-d H:i', $latest ) . ' UTC' : '-',
			];
		}
		if ( array() === $rows ) {
			\WP_CLI::line( 'No owner-bot accounts.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'ID', 'login', 'app_pw', 'last_used' ] );
	}

	/**
	 * `wp vextra owner-bot repair`.
	 */
	public function bot_repair(): void {
		OwnerBotRole::ensure();
		\WP_CLI::line( 'Role ' . OwnerBotRole::ROLE . ' re-synced (' . count( OwnerBotRole::caps() ) . ' capabilities).' );

		$removed = 0;
		$users   = get_users(
			[
				'meta_key'     => \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'EXISTS',
				'number'       => -1,
			]
		);
		foreach ( $users as $user ) {
			if ( ! $user instanceof \WP_User || OwnerBotRole::is_bot( $user ) ) {
				continue;
			}
			$count    = \WP_Application_Passwords::delete_all_application_passwords( (int) $user->ID );
			$removed += is_wp_error( $count ) ? 0 : (int) $count;
		}
		\WP_CLI::success( 'Application Passwords of non-bot users deleted: ' . $removed . '.' );
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
