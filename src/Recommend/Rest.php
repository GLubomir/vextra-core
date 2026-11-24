<?php
/**
 * Read-only REST route of the Recommend module (TASK-038): `GET /wp-json/vextra/v1/recommend?context=post_add|cart&product_id=<int>`.
 *
 * Response `{ items: [ { id, src, html } ], lang }`: product ids, whitelisted source names and the server-rendered `product-card`
 * markup of the theme (same component as the catalog, escaped there, no per-user data). No personal data, no cookies, no session for
 * `post_add`.
 *
 * - `post_add`: the language is the one of the product; the result depends only on the product (the server cart is NOT read), so it
 *   is publicly cacheable: `Cache-Control: public, max-age=300`. The client hides ids already in its cart.
 * - `cart`: the cart comes from the server session (`wc_load_cart()`); any client-supplied ids are ignored; `Cache-Control: no-store`.
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

final class Rest {

	public const NAMESPACE = 'vextra/v1';
	public const ROUTE     = '/recommend';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
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
				],
			]
		);
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
			if ( array() === $facts || 'publish' !== $facts['status'] || ! empty( $facts['hidden'] ) ) {
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
		}

		$items    = Engine::recommend( $context, $args );
		$response = new \WP_REST_Response(
			[
				'items' => $this->with_markup( $items, $lang ),
				'lang'  => $lang,
			]
		);
		$response->header( 'Cache-Control', 'post_add' === $context ? 'public, max-age=300' : 'no-store' );
		return $response;
	}

	/**
	 * Add the card markup of the theme to every item (empty string when the theme has no `vx_component()`).
	 *
	 * @param array<int,array{id:int,src:string}> $items Items.
	 * @param string                              $lang  Language slug (the markup is rendered in it).
	 * @return array<int,array{id:int,src:string,html:string}>
	 */
	private function with_markup( array $items, string $lang ): array {
		$out = [];
		foreach ( $items as $item ) {
			$out[] = $item + [ 'html' => '' ];
		}
		if ( array() === $out || ! function_exists( 'vx_component' ) ) {
			return $out;
		}

		$switched = $this->switch_locale( $lang );
		if ( class_exists( '\Vextra\Theme\Woo\ProductCardData' ) ) {
			\Vextra\Theme\Woo\ProductCardData::prime_catalog( array_column( $out, 'id' ) );
		}
		foreach ( $out as $i => $item ) {
			ob_start();
			vx_component(
				'product-card',
				[
					'product'    => $item['id'],
					'variant'    => 'grid',
					'heading'    => 'h3',
					'image_size' => 'vx-product-card',
					'lazy'       => true,
					'cache'      => true,
				]
			);
			$out[ $i ]['html'] = trim( (string) ob_get_clean() );
			if ( '' === $out[ $i ]['html'] ) {
				unset( $out[ $i ] ); // The card cannot be shown (product trashed since the bundle was built): skip it.
			}
		}
		if ( $switched ) {
			restore_previous_locale();
		}
		return array_values( $out );
	}

	/**
	 * Render in the language of the list when the request locale differs (REST has no Polylang language context of its own).
	 *
	 * @param string $lang Language slug.
	 */
	private function switch_locale( string $lang ): bool {
		$pll = Plugin::instance()->integration( 'polylang' );
		if ( '' === $lang || ! $pll instanceof Polylang ) {
			return false;
		}
		$locale = $pll->locale_of( $lang );
		return '' !== $locale && determine_locale() !== $locale && switch_to_locale( $locale );
	}
}
