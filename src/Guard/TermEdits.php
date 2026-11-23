<?php
/**
 * Owner-bot term writes (SEC-061): a category edit rewrites redirect rows, so the owner-bot role (exempt from every other bucket)
 * gets its own bucket `termedit` (default 30 edits / 10 min, per user). Beyond it: 429 + Retry-After on REST writes to product
 * categories. Admins and shop managers are not limited here (they are human, and the redirect work itself is capped separately).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

final class TermEdits {

	private const ROUTE = '#^/(?:wc/v\d+/products/categories|wp/v2/product_cat)/\d+/?$#i';

	public function register(): void {
		add_filter( 'rest_pre_dispatch', [ $this, 'pre_dispatch' ], 6, 3 );
	}

	/**
	 * Count a write to an existing product category by the owner bot.
	 *
	 * @param mixed $result  Short-circuit value (null = continue).
	 * @param mixed $server  Server.
	 * @param mixed $request Request.
	 * @return mixed
	 */
	public function pre_dispatch( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		return Failsafe::run(
			'term-edits',
			function () use ( $request ) {
				if ( ! in_array( strtoupper( $request->get_method() ), [ 'POST', 'PUT', 'PATCH' ], true ) || 1 !== preg_match( self::ROUTE, strtolower( (string) $request->get_route() ) ) ) {
					return null;
				}
				$user = wp_get_current_user();
				// phpcs:ignore WordPress.WP.Capabilities.Unknown -- vx_owner_bot is carried by the role `vextra_owner_bot` (Security\OwnerBotRole).
				if ( ! $user->exists() || ! ( in_array( 'vextra_owner_bot', (array) $user->roles, true ) || user_can( $user, 'vx_owner_bot' ) ) ) {
					return null;
				}
				$hit = RateLimiter::hit( 'termedit', 'u' . $user->ID, Config::get( 'termedit_limit' ), Config::get( 'termedit_window' ) );
				if ( $hit['allowed'] ) {
					return null;
				}
				Responder::log( '429', 'term-edit' );
				$response = new \WP_REST_Response(
					[
						'code'    => 'vx_rate_limited',
						'message' => 'Too many category edits. Please wait before trying again.',
						'data'    => [ 'status' => 429 ],
					],
					429
				);
				$response->header( 'Retry-After', (string) max( 1, $hit['retry_after'] ) );
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			},
			$result
		);
	}
}
