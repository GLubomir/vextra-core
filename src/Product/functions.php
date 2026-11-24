<?php
/**
 * Public functions of the Product module for the theme (TASK-044a). The theme wraps every call in `function_exists`, so a theme without this
 * plugin (or with the module switched off) renders Woo's own product page.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

use Vextra\Core\Product\Bundle;
use Vextra\Core\Product\InfoRows;
use Vextra\Core\Product\ReviewsData;
use Vextra\Core\Recommend\Store;
use Vextra\Core\Support\Cache;

if ( ! function_exists( 'vx_pdp_context' ) ) {
	/**
	 * Product page context of the current request: `active` is true only on a single product request (not 404, not admin) while the module
	 * is on. `product_id`, `protected` (password required) help the theme choose what to print.
	 *
	 * @return array{active:bool,product_id:int,protected:bool}
	 */
	function vx_pdp_context(): array {
		static $none = [
			'active'     => false,
			'product_id' => 0,
			'protected'  => false,
		];
		if ( is_admin() || is_404() || ! is_singular( 'product' ) ) {
			return $none;
		}
		$id = (int) get_queried_object_id();
		return [
			'active'     => $id > 0,
			'product_id' => $id,
			'protected'  => $id > 0 && post_password_required( $id ),
		];
	}
}

if ( ! function_exists( 'vx_pdp_bundle' ) ) {
	/**
	 * Variation / attribute / image / stock bundle of a product (see `Product\Bundle`).
	 *
	 * @param int $product_id Product id.
	 * @return array<string,mixed>
	 */
	function vx_pdp_bundle( int $product_id ): array {
		return Bundle::get( $product_id );
	}
}

if ( ! function_exists( 'vx_pdp_info' ) ) {
	/**
	 * Complete info rows of a language: `[ [ key, text, url ] ]`.
	 *
	 * @param string $lang Language slug ('' = the current one).
	 * @return array<int,array{key:string,text:string,url:string}>
	 */
	function vx_pdp_info( string $lang = '' ): array {
		return InfoRows::for_language( $lang );
	}
}

if ( ! function_exists( 'vx_pdp_reviews' ) ) {
	/**
	 * Reviews view-model of a product (see `Product\ReviewsData`).
	 *
	 * @param int $product_id Product id.
	 * @return array<string,mixed>
	 */
	function vx_pdp_reviews( int $product_id ): array {
		return ReviewsData::get( $product_id );
	}
}

if ( ! function_exists( 'vx_pdp_prefetch_names' ) ) {
	/**
	 * Option names of every cache entry the product page will read (variation bundle, info rows, recommendation bundle), so the theme primes
	 * them with ONE `wp_prime_option_caches()` together with its own entries.
	 *
	 * @param int $product_id Product id.
	 * @return string[]
	 */
	function vx_pdp_prefetch_names( int $product_id ): array {
		$pll     = \Vextra\Core\Plugin::instance()->integration( 'polylang' );
		$lang    = $pll instanceof \Vextra\Core\Integrations\Polylang\Polylang ? $pll->post_language( $product_id ) : '';
		$names   = array_merge(
			Cache::option_names( InfoRows::GROUP, 'rows:' . InfoRows::slot( $lang ) ),
			class_exists( Store::class ) ? Store::option_names( $product_id, $lang ) : []
		);
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
			$names = array_merge( $names, Cache::swr_option_names( Bundle::GROUP, Bundle::key( $product_id, $lang ) ) );
		}
		return $names;
	}
}
