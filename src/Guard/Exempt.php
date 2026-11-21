<?php
/**
 * Who is never limited: shop managers, site admins, the owner bot (cap/role `vx_owner_bot`, created in TASK-029),
 * IPs in `VX_GUARD_EXEMPT_IPS` and anything the filter `vextra/core/guard/exempt` approves.
 * 127.0.0.1 is NOT exempt by default (local tests must hit the limits).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

final class Exempt {

	/**
	 * Is the current request exempt from limits, route blocks and UA rules.
	 */
	public static function is_exempt(): bool {
		$ip = ClientIp::get( false );

		/**
		 * Filters the exemption of the current request.
		 *
		 * @param bool   $exempt Default false.
		 * @param string $ip     Client IP.
		 */
		if ( apply_filters( 'vextra/core/guard/exempt', false, $ip ) ) {
			return true;
		}

		if ( ClientIp::in_list( $ip, ClientIp::list_constant( 'VX_GUARD_EXEMPT_IPS' ) ) ) {
			return true;
		}

		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return false;
		}
		// phpcs:disable WordPress.WP.Capabilities.Unknown -- vx_owner_bot is created by TASK-029, manage_woocommerce by WooCommerce.
		return in_array( 'vx_owner_bot', (array) $user->roles, true )
			|| user_can( $user, 'vx_owner_bot' )
			|| user_can( $user, 'manage_woocommerce' )
			|| user_can( $user, 'manage_options' );
		// phpcs:enable
	}
}
