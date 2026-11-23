<?php
/**
 * Application Passwords for the owner bot only (SEC-015, D8).
 *
 * - `wp_is_application_passwords_available_for_user`: true only for an account holding the marker cap `vx_owner_bot`.
 *   Everyone else loses the profile section, gets 403 `application_passwords_disabled_for_user` on the REST route, and
 *   core refuses their existing passwords at authentication (availability is re-checked there).
 * - The bot cannot mint, edit or delete Application Passwords itself (meta caps denied): a stolen password could otherwise
 *   create a persistent twin. New / rotated passwords come from `wp vextra owner-bot create|revoke`.
 * - Optional IP allow-list: constant `VX_OWNER_BOT_IPS` (comma separated IPs / CIDRs, default none = no restriction). A bot
 *   authenticating from any other address fails with the generic wrong-password error (and counts as a failed login).
 * HTTPS-only is core behaviour (the local environment is exempt in core).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Guard\ClientIp;
use Vextra\Core\Guard\Failsafe;

final class AppPasswords {

	/**
	 * Meta capabilities of Application Passwords the bot never holds.
	 */
	private const DENIED_FOR_BOT = [ 'create_app_password', 'edit_app_password', 'delete_app_password', 'delete_app_passwords' ];

	public function register(): void {
		add_filter( 'wp_is_application_passwords_available_for_user', [ $this, 'available' ], 99, 2 );
		add_action( 'wp_authenticate_application_password_errors', [ $this, 'check_ip' ], 10, 4 );
		add_filter( 'map_meta_cap', [ $this, 'deny_self_service' ], 10, 4 );
	}

	/**
	 * Only the owner bot may have Application Passwords.
	 *
	 * @param mixed $available Core verdict.
	 * @param mixed $user      WP_User.
	 * @return mixed
	 */
	public function available( $available, $user = null ) {
		if ( ! $available ) {
			return $available;
		}
		return $user instanceof \WP_User && OwnerBotRole::is_bot( $user );
	}

	/**
	 * Reject a bot Application Password used from an address outside `VX_OWNER_BOT_IPS`.
	 *
	 * @param mixed $error WP_Error collecting the problems (object, modified in place).
	 * @param mixed $user  WP_User whose password is being checked.
	 */
	public function check_ip( $error = null, $user = null ): void {
		if ( ! $error instanceof \WP_Error || ! $user instanceof \WP_User ) {
			return;
		}
		$list = ClientIp::list_constant( 'VX_OWNER_BOT_IPS' );
		if ( array() === $list ) {
			return;
		}
		$denied = Failsafe::run(
			'owner-bot-ip',
			static fn(): bool => OwnerBotRole::is_bot( $user ) && ! ClientIp::in_list( ClientIp::get( false ), $list ),
			false
		);
		if ( $denied ) {
			// Same code and text as a wrong password (GenericErrors turns it into `vx_invalid_login`).
			$error->add( 'incorrect_password', __( 'Невірний логін або пароль.', 'vextra-core' ) );
		}
	}

	/**
	 * The owner bot cannot create, edit or revoke Application Passwords (its own or anyone's).
	 *
	 * @param mixed $caps    Required primitive caps.
	 * @param mixed $cap     Meta cap being resolved.
	 * @param mixed $user_id Acting user id.
	 * @return mixed
	 */
	public function deny_self_service( $caps, $cap = '', $user_id = 0 ) {
		if ( ! is_string( $cap ) || ! in_array( $cap, self::DENIED_FOR_BOT, true ) || ! is_numeric( $user_id ) ) {
			return $caps;
		}
		$actor = get_userdata( (int) $user_id );
		return $actor instanceof \WP_User && OwnerBotRole::is_bot( $actor ) ? [ 'do_not_allow' ] : $caps;
	}
}
