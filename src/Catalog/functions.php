<?php
/**
 * Public functions of the Catalog module for the theme (TASK-043b). The theme wraps every call in `function_exists`, so a theme
 * without this plugin (or with the module switched off) keeps working with empty data.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

use Vextra\Core\Catalog\AccessoryRules;
use Vextra\Core\Catalog\Context;
use Vextra\Core\Catalog\Params;
use Vextra\Core\Catalog\SalePage;
use Vextra\Core\Catalog\SaleProducts;
use Vextra\Core\Catalog\TermData;
use Vextra\Core\Multilang\AttributeTranslation;

if ( ! function_exists( 'vx_catalog_context' ) ) {
	/**
	 * Listing context of the current request (see Catalog\Context). `active` = false outside catalog listings.
	 *
	 * @return array<string,mixed>
	 */
	function vx_catalog_context(): array {
		return Context::get();
	}
}

if ( ! function_exists( 'vx_catalog_prefetch_names' ) ) {
	/**
	 * Option names the catalog context will read, for one combined `wp_prime_option_caches()` call (saves a query per entry).
	 *
	 * @return string[]
	 */
	function vx_catalog_prefetch_names(): array {
		return Context::prefetch_names();
	}
}

if ( ! function_exists( 'vx_catalog_term_data' ) ) {
	/**
	 * Cached term data of a `product_cat` term in the current language: children, siblings, parent, accessories
	 * (rows `[ id, name, url, count, thumb_id ]`).
	 *
	 * @param int $term_id Term id.
	 * @return array<string,mixed>
	 */
	function vx_catalog_term_data( int $term_id ): array {
		return TermData::bundle( $term_id );
	}
}

if ( ! function_exists( 'vx_catalog_attribute_label' ) ) {
	/**
	 * Attribute label (Woo setting, Ukrainian source) in the current language; the cached facet bundle keeps the source label.
	 *
	 * @param string $label Source label.
	 */
	function vx_catalog_attribute_label( string $label ): string {
		return AttributeTranslation::label( $label );
	}
}

if ( ! function_exists( 'vx_catalog_accessories' ) ) {
	/**
	 * Accessory categories of a category (non-empty, admin order, at most 8) as `[ term_id, name, url, count, thumb_id ]`.
	 * TASK-038 `Recommend` consumes this as its `accessory rules` source.
	 *
	 * @param int $term_id Term id (any language).
	 * @return array<int,array<string,mixed>>
	 */
	function vx_catalog_accessories( int $term_id ): array {
		return AccessoryRules::for_term( $term_id, TermData::counts() );
	}
}

if ( ! function_exists( 'vx_catalog_shop_cards' ) ) {
	/**
	 * Top-level non-empty categories of the current language for the shop root (image id, count, up to 4 sub links).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	function vx_catalog_shop_cards(): array {
		return TermData::shop_cards();
	}
}

if ( ! function_exists( 'vx_catalog_query_string' ) ) {
	/**
	 * Canonical query string of a filter state (`orderby=price&filter_brand=a,b`, no `?`).
	 *
	 * @param array<string,mixed> $state State from the context.
	 */
	function vx_catalog_query_string( array $state ): string {
		return Params::serialise( array_merge( Params::empty_state(), $state ) );
	}
}

if ( ! function_exists( 'vx_catalog_term_total' ) ) {
	/**
	 * Hierarchical published product count of a `product_cat` term.
	 *
	 * @param int $term_id Term id.
	 */
	function vx_catalog_term_total( int $term_id ): int {
		return TermData::counts()->count( $term_id );
	}
}

if ( ! function_exists( 'vx_catalog_sale_url' ) ) {
	/**
	 * Permalink of the sale page `/aktsii/` in the current language, '' when the page does not exist or nothing is on sale.
	 */
	function vx_catalog_sale_url(): string {
		$page_id = SalePage::page_id();
		if ( $page_id <= 0 || SaleProducts::count() <= 0 ) {
			return '';
		}
		$url = get_permalink( $page_id );
		return is_string( $url ) ? $url : '';
	}
}
