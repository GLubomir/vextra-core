<?php
/**
 * REST guards (SEC-009, SEC-011): Store API limiter, catalog-export block, coupon brute-force bucket, anonymous REST bucket.
 *
 * - WooCommerce's built-in Store API limiter is switched on through `woocommerce_store_api_rate_limit_options` (numbers from
 *   Config); `woocommerce_store_api_rate_limit_id` makes it key anonymous clients by our trusted-proxy-aware IP.
 * - `rest_pre_dispatch` (priority 5; also fires for sub-requests of `/wc/store/v1/batch`, so batch cannot smuggle a blocked
 *   route) blocks the unused product-listing Store API routes for anonymous clients (401, logged-in non-exempt 403), applies the
 *   coupon bucket, the Store API bad-UA rule, an anonymous bucket for every other route and the `per_page` cap of wp/v2 product listings.
 * Cart, checkout, batch and cart/* routes stay open (header hydration, add-to-cart).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

final class StoreApi {

	/**
	 * Store API routes blocked for anonymous clients: product listings and everything below them
	 * (single, reviews, collection-data, attributes*, categories, tags, brands). Filter `vextra/core/guard/blocked_routes`.
	 */
	public const BLOCKED = [
		'#^/wc/store(?:/v\d+)?/products(?:/|$)#',
	];

	private const COUPON_ROUTE = '#^/wc/store(?:/v\d+)?/cart/(?:apply-coupon|coupons)(?:/|$)#';
	private const STORE_ROUTE  = '#^/wc/store(?:/|$)#';
	private const LISTING      = '#^/wp/v2/(?:product|product_cat|product_tag)(?:/|$)#';

	public function register(): void {
		add_filter( 'woocommerce_store_api_rate_limit_options', [ $this, 'limiter_options' ], 20 );
		add_filter( 'woocommerce_store_api_rate_limit_id', [ $this, 'limiter_id' ], 20 );
		add_filter( 'rest_pre_dispatch', [ $this, 'pre_dispatch' ], 5, 3 );
		add_filter( 'rest_post_dispatch', [ $this, 'retry_after_header' ], 10, 1 );
	}

	/**
	 * Enable Woo's limiter with our numbers (off for exempt requests).
	 *
	 * @param mixed $options Woo defaults (`enabled`, `proxy_support`, `limit`, `seconds`).
	 * @return mixed
	 */
	public function limiter_options( $options ) {
		if ( ! is_array( $options ) ) {
			return $options;
		}
		return Failsafe::run(
			'store-limiter',
			static function () use ( $options ): array {
				$limit                    = Config::get( 'store_limit' );
				$options['enabled']       = $limit > 0 && ! Exempt::is_exempt();
				$options['limit']         = $limit;
				$options['seconds']       = max( 1, Config::get( 'store_window' ) );
				$options['proxy_support'] = false; // Our id filter resolves the client; Woo's own proxy trust stays off.
				return $options;
			},
			$options
		);
	}

	/**
	 * Anonymous clients are keyed by the trusted-proxy-aware IP; signed-in users keep Woo's user id.
	 *
	 * @param mixed $id Woo's identifier.
	 * @return mixed
	 */
	public function limiter_id( $id ) {
		if ( is_user_logged_in() ) {
			return $id;
		}
		return Failsafe::run( 'store-limiter-id', static fn(): string => md5( ClientIp::get() ), $id );
	}

	/**
	 * WooCommerce's limiter sends `RateLimit-Retry-After`; clients and crawlers expect the standard `Retry-After` too.
	 *
	 * @param mixed $response REST response.
	 * @return mixed
	 */
	public function retry_after_header( $response ) {
		if ( ! $response instanceof \WP_REST_Response || 429 !== $response->get_status() || isset( $response->get_headers()['Retry-After'] ) ) {
			return $response;
		}
		return Failsafe::run(
			'retry-after',
			static function () use ( $response ) {
				foreach ( headers_list() as $line ) {
					if ( 1 === preg_match( '/^RateLimit-Retry-After:\s*(\d+)/i', $line, $m ) ) {
						$response->header( 'Retry-After', (string) max( 1, (int) $m[1] ) );
						$response->header( 'Cache-Control', 'no-store' );
						break;
					}
				}
				return $response;
			},
			$response
		);
	}

	/**
	 * Route guards.
	 *
	 * @param mixed            $result  Short-circuit value (null = continue).
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function pre_dispatch( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request || ! $server instanceof \WP_REST_Server ) {
			return $result;
		}
		return Failsafe::run( 'rest', fn() => $this->check( $server, $request ), $result );
	}

	/**
	 * Run the guards for one dispatch.
	 *
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|null
	 */
	private function check( \WP_REST_Server $server, \WP_REST_Request $request ) {
		$route = (string) $request->get_route();
		if ( Exempt::is_exempt() ) {
			return null;
		}

		$ip = ClientIp::get();

		if ( 1 === preg_match( self::STORE_ROUTE, $route ) ) {
			if ( UserAgents::is_bad() ) {
				Responder::log( '403', 'store-api-ua' );
				return $this->error( 'rest_forbidden', 'Forbidden.', 403 );
			}

			if ( $this->is_blocked( $route ) ) {
				Responder::log( is_user_logged_in() ? '403' : '401', 'store-api-listing' );
				return $this->error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', is_user_logged_in() ? 403 : 401 );
			}

			if ( 'POST' === strtoupper( $request->get_method() ) && 1 === preg_match( self::COUPON_ROUTE, $route ) ) {
				$hit = RateLimiter::hit( 'coupon', $ip, Config::get( 'coupon_limit' ), Config::get( 'coupon_window' ) );
				if ( ! $hit['allowed'] ) {
					return $this->limited( $hit['retry_after'], 'store-api-coupon' );
				}
			}
			return null; // Woo's limiter counts the rest of the Store API.
		}

		if ( is_user_logged_in() ) {
			return null;
		}

		if ( 'GET' === strtoupper( $request->get_method() ) && 1 === preg_match( self::LISTING, $route ) ) {
			$cap = Config::get( 'per_page_cap' );
			$per = $request->get_param( 'per_page' );
			if ( $cap > 0 && is_numeric( $per ) && (int) $per > $cap ) {
				$request->set_param( 'per_page', $cap );
			}
		}

		$hit = RateLimiter::hit( 'rest', $ip, Config::get( 'rest_limit' ), Config::get( 'rest_window' ) );
		if ( ! $hit['allowed'] ) {
			return $this->limited( $hit['retry_after'], 'rest' );
		}

		$search = $request->get_param( 'search' );
		if ( is_string( $search ) && '' !== trim( $search ) ) {
			$hit = RateLimiter::hit( 'search', $ip, Config::get( 'search_limit' ), Config::get( 'search_window' ) );
			if ( ! $hit['allowed'] ) {
				return $this->limited( $hit['retry_after'], 'rest-search' );
			}
		}

		return null;
	}

	private function is_blocked( string $route ): bool {
		/**
		 * Filters the regular expressions of Store API routes blocked for anonymous clients.
		 *
		 * @param string[] $patterns PCRE patterns matched against the REST route (e.g. `/wc/store/v1/products`).
		 */
		$patterns = apply_filters( 'vextra/core/guard/blocked_routes', self::BLOCKED );
		foreach ( (array) $patterns as $pattern ) {
			if ( is_string( $pattern ) && 1 === @preg_match( $pattern, $route ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filtered pattern may be invalid.
				return true;
			}
		}
		return false;
	}

	/**
	 * Error as a response object. Returned instead of a WP_Error because `rest_post_dispatch` listeners (Query Monitor) are
	 * typed for responses and fatal on a bare WP_Error inside `/wc/store/v1/batch`.
	 *
	 * @param string $code   Error code.
	 * @param string $msg    Message.
	 * @param int    $status HTTP status.
	 * @return \WP_REST_Response
	 */
	private function error( string $code, string $msg, int $status ): \WP_REST_Response {
		return rest_convert_error_to_response( new \WP_Error( $code, $msg, [ 'status' => $status ] ) );
	}

	/**
	 * 429 response + Retry-After.
	 *
	 * @param int    $retry Seconds.
	 * @param string $route Log label.
	 * @return \WP_REST_Response
	 */
	private function limited( int $retry, string $route ): \WP_REST_Response {
		Responder::log( '429', $route );
		$response = $this->error( 'vx_rate_limited', 'Too many requests. Please wait before trying again.', 429 );
		$response->header( 'Retry-After', (string) max( 1, $retry ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
