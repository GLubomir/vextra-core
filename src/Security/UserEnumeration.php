<?php
/**
 * User enumeration closed for anonymous visitors (SEC-006, decision D4).
 *
 * - REST: every route under `/wp/v2/users` answers one generic 401 JSON for a visitor without a signed-in user (existing and
 *   nonexistent ids look the same); a signed-in customer (no `edit_posts` / `list_users`) gets 403 except for `/users/me`.
 *   `rest_pre_dispatch` priority 3: the route stays registered (the block editor keeps working for staff) and sub-requests
 *   of `/batch/v1` pass through the same filter. The route is lower-cased, decoded and its slashes collapsed before
 *   matching (lessons SEC-035 / TASK-028: case, `?rest_route=`, `index.php/`, double slashes).
 * - `author` links are removed from anonymous REST responses (so `_embed` has nothing to resolve).
 * - Front: `?author=N`, `?author_name=` and `/author/<slug>/` answer a hard 404 for visitors who cannot edit posts
 *   (`wp` priority 1: Rank Math 301s archives to the home page on `wp` 10 and `redirect_canonical` would send `?author=N` to `/author/<login>/`).
 * - oEmbed: `author_name` / `author_url` dropped for anonymous requests. Core sitemap `users` provider removed.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Guard\Failsafe;
use Vextra\Core\Guard\Responder;

final class UserEnumeration {

	private const USERS_ROUTE = '#^/wp/v2/users(?:/|$)#i';

	public function register(): void {
		add_filter( 'rest_pre_dispatch', [ $this, 'rest_guard' ], 3, 3 );
		add_filter( 'rest_post_dispatch', [ $this, 'strip_author_links' ], 20, 1 );
		add_action( 'wp', [ $this, 'author_404' ], 1 );
		add_filter( 'oembed_response_data', [ $this, 'oembed' ], 99, 1 );
		add_filter( 'wp_sitemaps_add_provider', [ $this, 'sitemap_provider' ], 99, 2 );
	}

	/**
	 * Lower-cased, decoded REST route with collapsed slashes and no trailing slash.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function normalised_route( \WP_REST_Request $request ): string {
		$route = (string) $request->get_route();
		if ( str_contains( $route, '%' ) ) {
			$route = rawurldecode( $route );
		}
		$route = (string) preg_replace( '#/{2,}#', '/', strtolower( $route ) );
		return '/' . trim( $route, '/' );
	}

	/**
	 * Generic REST error as a response object (typed `rest_post_dispatch` listeners such as Query Monitor expect a response).
	 *
	 * @param string $code   Error code.
	 * @param string $msg    Message.
	 * @param int    $status HTTP status.
	 */
	public static function error( string $code, string $msg, int $status ): \WP_REST_Response {
		$response = rest_convert_error_to_response( new \WP_Error( $code, $msg, [ 'status' => $status ] ) );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/**
	 * `rest_pre_dispatch`: block `/wp/v2/users*` for anonymous clients (401) and for signed-in users who are not staff
	 * (customers can still read their own `/users/me`; 403).
	 *
	 * @param mixed $result  Short-circuit value (null = continue).
	 * @param mixed $server  REST server.
	 * @param mixed $request Request.
	 * @return mixed
	 */
	public function rest_guard( $result, $server = null, $request = null ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		return Failsafe::run(
			'rest-users',
			function () use ( $request, $result ) {
				$route = self::normalised_route( $request );
				if ( 1 !== preg_match( self::USERS_ROUTE, $route ) ) {
					return $result;
				}
				if ( ! is_user_logged_in() ) {
					Responder::log( '401', 'rest-users', 'security' );
					return self::error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', 401 );
				}
				if ( '/wp/v2/users/me' === $route || current_user_can( 'edit_posts' ) || current_user_can( 'list_users' ) ) {
					return $result;
				}
				Responder::log( '403', 'rest-users', 'security' );
				return self::error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', 403 );
			},
			$result
		);
	}

	/**
	 * `rest_post_dispatch`: no `author` link (and so no `_embed` of the user) in anonymous responses.
	 *
	 * @param mixed $response REST response.
	 * @return mixed
	 */
	public function strip_author_links( $response ) {
		if ( ! $response instanceof \WP_REST_Response || is_user_logged_in() ) {
			return $response;
		}
		return Failsafe::run(
			'rest-author-links',
			static function () use ( $response ) {
				$response->remove_link( 'author' );
				$data = $response->get_data();
				if ( is_array( $data ) && array_is_list( $data ) ) {
					foreach ( $data as $index => $item ) {
						if ( is_array( $item ) && isset( $item['_links']['author'] ) ) {
							unset( $item['_links']['author'] );
							$data[ $index ] = $item;
						}
					}
					$response->set_data( $data );
				}
				return $response;
			},
			$response
		);
	}

	/**
	 * `wp` priority 1 (after the main query and `handle_404`, before Rank Math's author-archive redirect at 10 and before
	 * `redirect_canonical`): hard 404 for author archives (no 301 to `/author/<login>/` or to the home page, no difference
	 * between an existing and an unknown slug).
	 */
	public function author_404(): void {
		if ( current_user_can( 'edit_posts' ) ) {
			return;
		}
		$author = (string) get_query_var( 'author' );
		$name   = (string) get_query_var( 'author_name' );
		if ( ! is_author() && '' === $name && ( '' === $author || '0' === $author ) ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		Responder::log( '404', 'author-archive', 'security' );
	}

	/**
	 * `oembed_response_data`: no author name / URL for anonymous consumers.
	 *
	 * @param mixed $data oEmbed response data.
	 * @return mixed
	 */
	public function oembed( $data ) {
		if ( is_array( $data ) && ! is_user_logged_in() ) {
			unset( $data['author_name'], $data['author_url'] );
		}
		return $data;
	}

	/**
	 * `wp_sitemaps_add_provider`: the core `users` sitemap lists author URLs.
	 *
	 * @param mixed $provider Provider object.
	 * @param mixed $name     Provider name.
	 * @return mixed
	 */
	public function sitemap_provider( $provider, $name = '' ) {
		return 'users' === $name ? false : $provider;
	}
}
