<?php
/**
 * Read-only REST route of the Recommend module (TASK-038): `GET /wp-json/vextra/v1/recommend?context=post_add|cart&product_id=<int>`.
 *
 * Optional `variant=compact` (TASK-045a): the compact card with `rec_src=drawer` in its add-to-cart form; `cart` on an empty cart answers `items: []` without calling the engine.
 * Response `{ items: [ { id, src, html } ], lang }`: product ids, whitelisted source names and the server-rendered `product-card`
 * markup of the theme (same component as the catalog, escaped there, no per-user data). No personal data, no cookies, no session for
 * `post_add`.
 *
 * - `post_add`: the language is the one of the product; the result depends only on the product (the server cart is NOT read), so it
 *   is publicly cacheable: `Cache-Control: public, max-age=300`. The client hides ids already in its cart.
 * - `cart`: the cart comes from the server session (`wc_load_cart()`); any client-supplied ids are ignored; `Cache-Control: no-store`.
 * - A password-protected, hidden, unpublished or unknown product as `post_add` anchor answers the same generic 400 (SEC-066).
 * - Params are validated (context enum, positive integer id, language slug); errors are a generic 400 without detail.
 * - Rate limit: the Guard `rest` bucket already counts every anonymous REST route (`Guard\StoreApi::check()`); 429 + `Retry-After`.
 * - WordPress core adds `X-Robots-Tag: noindex` to every REST response; the route is in no link, sitemap or robots.txt line.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;
use Vextra\Core\Support\CardMarkup;

final class Rest {

	public const NAMESPACE = 'vextra/v1';
	public const ROUTE     = '/recommend';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
		add_filter( 'rest_post_dispatch', [ $this, 'no_store_errors' ], 20, 3 );
	}

	public function register_route(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true', // Public read: ids and card markup of published products, nothing personal.
				'args'                => [
					'context'    => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && in_array( $value, [ 'post_add', 'cart' ], true ),
					],
					'product_id' => [
						'type'              => 'string',
						'validate_callback' => static fn( $value ): bool => is_scalar( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,9}$/', (string) $value ),
						'sanitize_callback' => static fn( $value ): int => absint( $value ),
					],
					'lang'       => [
						'type'              => 'string',
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && 1 === preg_match( '/^[a-z]{2,3}(-[a-z]{2,4})?$/', $value ),
					],
					'variant'    => [
						'type'              => 'string',
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && in_array( $value, [ 'grid', 'compact' ], true ),
					],
				],
			]
		);
	}

	/**
	 * Errors of this route (400 / 404 / 429) are never cached; only the valid `post_add` answer is public.
	 *
	 * @param mixed $response Response.
	 * @param mixed $server   Server.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public function no_store_errors( $response, $server = null, $request = null ) {
		if ( $response instanceof \WP_REST_Response && $request instanceof \WP_REST_Request && $response->get_status() >= 400 && self::NAMESPACE . self::ROUTE === ltrim( $request->get_route(), '/' ) ) {
			$response->header( 'Cache-Control', 'no-store' );
		}
		return $response;
	}

	/**
	 * Handle a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$plugin  = Plugin::instance();
		$woo     = $plugin->integration( 'woocommerce' );
		$pll     = $plugin->integration( 'polylang' );
		$context = (string) $request->get_param( 'context' );
		$lang    = '';
		$args    = [];

		$asked = $request->get_param( 'lang' );
		if ( null !== $asked ) {
			// Whitelist = the Polylang languages; anything else (or Polylang absent) is a bad request.
			if ( ! is_string( $asked ) || ! $pll instanceof Polylang || ! $pll->is_ready() || ! in_array( $asked, $pll->languages(), true ) ) {
				return new \WP_Error( 'vx_rec_bad_request', 'Invalid request.', [ 'status' => 400 ] );
			}
			$lang = $asked;
		}

		if ( 'post_add' === $context ) {
			$id    = absint( $request->get_param( 'product_id' ) );
			$facts = $id > 0 ? Pool::light( [ $id ] )[ $id ] ?? [] : [];
			if ( array() === $facts || 'publish' !== $facts['status'] || ! empty( $facts['hidden'] ) || ! empty( $facts['protected'] ) ) {
				return new \WP_Error( 'vx_rec_bad_request', 'Invalid request.', [ 'status' => 400 ] );
			}
			$own  = Pool::language_of( $id );
			$lang = '' !== $own ? $own : $lang;
			$args = [
				'product_id'   => $id,
				'lang'         => $lang,
				'exclude_cart' => false,
			];
		} else {
			if ( $woo instanceof WooCommerce ) {
				$woo->load_cart();
			}
			if ( '' === $lang ) {
				$first = $woo instanceof WooCommerce ? ( $woo->cart_product_ids()[0] ?? 0 ) : 0;
				$lang  = $first > 0 ? Pool::language_of( $first ) : '';
			}
			$lang = '' !== $lang ? $lang : Pool::current_language();
			$args = [ 'lang' => $lang ];
			if ( $woo instanceof WooCommerce && array() === $woo->cart_product_ids() ) {
				// TASK-045a: an empty cart has nothing to complement (no engine call, no markup).
				$empty = new \WP_REST_Response(
					[
						'items' => [],
						'lang'  => $lang,
					]
				);
				$empty->header( 'Cache-Control', 'no-store' );
				return $empty;
			}
		}

		$compact  = 'compact' === (string) $request->get_param( 'variant' );
		$items    = Engine::recommend( $context, $args );
		$response = new \WP_REST_Response(
			[
				'items' => $this->with_markup( $items, $lang, $compact, $context ),
				'lang'  => $lang,
			]
		);
		$response->header( 'Cache-Control', 'post_add' === $context ? 'public, max-age=300' : 'no-store' );
		return $response;
	}

	/**
	 * Add the card markup of the theme to every item (empty string when the theme has no `vx_component()`).
	 *
	 * @param array<int,array{id:int,src:string}> $items   Items.
	 * @param string                              $lang    Language slug (the markup is rendered in it).
	 * @param bool                                $compact `variant=compact`: the compact card, attributed to the drawer (`rec_src=drawer`).
	 * @param string                              $context `post_add` or `cart`.
	 * @return array<int,array{id:int,src:string,html:string}>
	 */
	private function with_markup( array $items, string $lang, bool $compact = false, string $context = '' ): array {
		if ( $compact ) {
			$card = [
				'variant' => 'compact',
				'rec_src' => 'drawer',
			];
		} else {
			$card = [
				'variant' => 'grid',
				'rec_src' => 'cart' === $context ? 'cart_crosssell' : '',
			];
		}
		return CardMarkup::attach( $items, $lang, $card );
	}
}
