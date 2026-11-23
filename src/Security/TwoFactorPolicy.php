<?php
/**
 * Staff 2FA policy on top of the official "Two Factor" plugin (TASK-029b, SEC-008, D2). SOFT policy for now:
 * - providers limited to TOTP + Backup Codes (`two_factor_providers`, priority 99 so Two Factor's debug-only Dummy provider is
 *   removed too; e-mail codes are off: same channel as the password reset);
 * - a persistent admin notice for every staff user (cap `manage_options` or `manage_woocommerce`; the owner bot never logs in)
 *   who has no second factor, linking to the profile section; "dismiss" lasts for the browser session only (session token data);
 * - `staff_without_two_factor()` feeds `wp vextra security status` and the compat rule `staff-without-2fa`.
 * No redirect, no lockout. Mandatory 2FA for staff is a LAUNCH GATE (docs/system/modules/security.md): hard enforcement comes
 * in a later task. Application Password REST authentication bypasses 2FA by design (owner bot only); Two Factor throttles code
 * entry itself, our login throttle counts password failures.
 * Nothing here runs when the plugin is absent, apart from one notice telling an administrator to activate it.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Guard\Failsafe;
use Vextra\Core\Integrations\TwoFactor\TwoFactor;
use Vextra\Core\Plugin;

final class TwoFactorPolicy {

	private const DISMISS_ARG   = 'vx_2fa_dismiss';
	private const DISMISS_NONCE = 'vx_2fa_dismiss';
	private const SESSION_KEY   = 'vx_2fa_dismissed';

	public function register(): void {
		add_filter( 'two_factor_providers', [ $this, 'providers' ], 99 );
		if ( is_admin() ) {
			add_action( 'admin_init', [ $this, 'handle_dismiss' ] );
			add_action( 'admin_notices', [ $this, 'notice' ] );
		}
	}

	/**
	 * `two_factor_providers`: authenticator app and backup codes only.
	 *
	 * @param mixed $providers Class => file map.
	 * @return mixed
	 */
	public function providers( $providers ) {
		$adapter = self::adapter();
		return null === $adapter ? $providers : $adapter->restrict_providers( $providers );
	}

	/**
	 * Is the account staff (can manage the shop or the site).
	 *
	 * @param \WP_User $user Account.
	 */
	public static function is_staff( \WP_User $user ): bool {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- manage_woocommerce is registered by WooCommerce.
		return ! OwnerBotRole::is_bot( $user ) && ( user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) );
	}

	/**
	 * Staff accounts without a configured second factor, or null when Two Factor is not active.
	 *
	 * @return string[]|null Logins.
	 */
	public static function staff_without_two_factor(): ?array {
		$adapter = self::adapter();
		if ( null === $adapter || ! $adapter->is_available() ) {
			return null;
		}
		$users = get_users(
			[
				'capability__in' => [ 'manage_options', 'manage_woocommerce' ],
				'number'         => 200,
				'orderby'        => 'ID',
			]
		);
		$out   = [];
		foreach ( $users as $user ) {
			if ( $user instanceof \WP_User && self::is_staff( $user ) && ! $adapter->user_has_two_factor( $user ) ) {
				$out[] = $user->user_login;
			}
		}
		return $out;
	}

	/**
	 * `admin_notices`: remind a staff user without 2FA (persistent; dismissed for this session only).
	 */
	public function notice(): void {
		Failsafe::run(
			'two-factor-notice',
			function (): void {
				$user = wp_get_current_user();
				if ( ! $user->exists() || ! self::is_staff( $user ) || $this->dismissed( $user ) ) {
					return;
				}
				$adapter = self::adapter();
				if ( null === $adapter || ! $adapter->is_available() ) {
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}
					$text = __( 'Двофакторну автентифікацію персоналу не увімкнено: активуйте плагін «Two Factor» (Плагіни).', 'vextra-core' );
					$link = '';
				} else {
					if ( $adapter->user_has_two_factor( $user ) ) {
						return;
					}
					$text = __( 'Ваш обліковий запис керування магазином ще не захищений двофакторною автентифікацією. Налаштуйте додаток-автентифікатор (TOTP) та резервні коди у своєму профілі.', 'vextra-core' );
					$link = sprintf( ' <a href="%1$s">%2$s</a>', esc_url( $adapter->settings_url( (int) $user->ID ) ), esc_html__( 'Налаштувати зараз', 'vextra-core' ) );
				}
				$dismiss = wp_nonce_url( add_query_arg( self::DISMISS_ARG, '1' ), self::DISMISS_NONCE );
				printf(
					'<div class="notice notice-warning"><p><strong>vextra-core:</strong> %1$s%2$s &nbsp;<a href="%3$s">%4$s</a></p></div>',
					esc_html( $text ),
					$link, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
					esc_url( $dismiss ),
					esc_html__( 'Приховати до наступного входу', 'vextra-core' )
				);
			}
		);
	}

	/**
	 * `admin_init`: remember the dismissal in the current session (nonce + staff check; no persistent state).
	 */
	public function handle_dismiss(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified on the next line.
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) || wp_doing_ajax() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! self::is_staff( $user ) || ! check_admin_referer( self::DISMISS_NONCE ) ) {
			return;
		}
		Failsafe::run(
			'two-factor-dismiss',
			static function () use ( $user ): void {
				$manager = \WP_Session_Tokens::get_instance( (int) $user->ID );
				$token   = wp_get_session_token();
				$session = $manager->get( $token );
				if ( is_array( $session ) ) {
					$session[ self::SESSION_KEY ] = true;
					$manager->update( $token, $session );
				}
			}
		);
		wp_safe_redirect( remove_query_arg( [ self::DISMISS_ARG, '_wpnonce' ] ) );
		exit;
	}

	/**
	 * Has this session dismissed the notice.
	 *
	 * @param \WP_User $user Current user.
	 */
	private function dismissed( \WP_User $user ): bool {
		$session = \WP_Session_Tokens::get_instance( (int) $user->ID )->get( wp_get_session_token() );
		return is_array( $session ) && ! empty( $session[ self::SESSION_KEY ] );
	}

	private static function adapter(): ?TwoFactor {
		$adapter = Plugin::instance()->integration( 'twofactor' );
		return $adapter instanceof TwoFactor ? $adapter : null;
	}
}
