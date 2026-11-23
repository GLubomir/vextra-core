<?php
/**
 * Abilities API listing closed for customers (SEC-023, decision D13).
 *
 * `/wp-abilities/v1/*` (listing, categories, single ability, run) answers 403 to a signed-in user who has none of the caps
 * `edit_posts`, `edit_products`, `vx_owner_bot` (a plain customer). The route stays registered; per-ability execution permissions
 * are untouched, anonymous requests are not changed here (core / the ability decide). Cap `vx_owner_bot` is created in TASK-029b.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Guard\Failsafe;
use Vextra\Core\Guard\Responder;

final class Abilities {

	private const ROUTE = '#^/wp-abilities/v1(?:/|$)#i';

	public function register(): void {
		add_filter( 'rest_pre_dispatch', [ $this, 'guard' ], 3, 3 );
	}

	/**
	 * `rest_pre_dispatch`: 403 for signed-in users without staff / owner-bot caps.
	 *
	 * @param mixed $result  Short-circuit value (null = continue).
	 * @param mixed $server  REST server.
	 * @param mixed $request Request.
	 * @return mixed
	 */
	public function guard( $result, $server = null, $request = null ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof \WP_REST_Request || ! is_user_logged_in() ) {
			return $result;
		}
		return Failsafe::run(
			'rest-abilities',
			static function () use ( $request, $result ) {
				if ( 1 !== preg_match( self::ROUTE, UserEnumeration::normalised_route( $request ) ) ) {
					return $result;
				}
				// phpcs:disable WordPress.WP.Capabilities.Unknown -- vx_owner_bot is created by TASK-029b, edit_products by WooCommerce.
				$staff = current_user_can( 'edit_posts' ) || current_user_can( 'edit_products' ) || current_user_can( 'vx_owner_bot' );
				// phpcs:enable
				if ( $staff ) {
					return $result;
				}
				Responder::log( '403', 'rest-abilities', 'security' );
				return UserEnumeration::error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', 403 );
			},
			$result
		);
	}
}
