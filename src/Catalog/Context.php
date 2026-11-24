<?php
/**
 * Listing context of the current request (TASK-043b): the one read-only answer the theme asks for (`vx_catalog_context()`).
 *
 * Built once per request. Category / brand / shop / search: the main WordPress query (already filtered and sorted by `Query`) supplies
 * the page ids, the totals come from it. Sale page: the main query is the editor page, so the listing is an own `WP_Query` over
 * `SaleProducts::ids()` with the same `Query` clauses. Facet groups and result-category pills come from the cached `Counts` bundle
 * of the request's filter state. The theme never reads `$_GET` for catalog logic: everything it needs is in this array.
 *
 * Shape: kind, term_id, search, state, query_string, active, sort_options, discount_ready, ids, found, pages, paged, per_page, facets,
 * facets_found, cats, capped, term_data (children / siblings / parent / accessories of the term, or of the main result category of a
 * search), shop_cards.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;

final class Context {

	/**
	 * Per-request memo.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $memo = null;

	/**
	 * Own sale queries by page number: [ paged => [ ids, found, pages ] ].
	 *
	 * @var array<int,array{ids:int[],found:int,pages:int}>
	 */
	private static array $sale = [];

	/**
	 * Register the bridges: sale page flag + real max pages for the 043a guard.
	 */
	public function register(): void {
		add_filter( 'vextra/catalog/is_sale_page', static fn( $is ): bool => $is || SalePage::is_sale_page() );
		add_filter( 'vextra/catalog/sale_max_pages', [ self::class, 'sale_max_pages' ] );
	}

	/**
	 * Forget the memo (tests).
	 */
	public static function reset(): void {
		self::$memo = null;
		self::$sale = [];
		Query::reset();
	}

	/**
	 * Real number of pages of the FILTERED sale listing (`ceil( found / 24 )`, at least 1).
	 *
	 * @param mixed $pages Default value of the filter.
	 */
	public static function sale_max_pages( $pages = 1 ): int {
		unset( $pages );
		return max( 1, self::sale_page( 1 )['pages'] );
	}

	/**
	 * Own sale query result of one page.
	 *
	 * @param int $paged Page number.
	 * @return array{ids:int[],found:int,pages:int}
	 */
	private static function sale_page( int $paged ): array {
		if ( isset( self::$sale[ $paged ] ) ) {
			return self::$sale[ $paged ];
		}
		$query                = new \WP_Query( Query::own_args( 'sale', SaleProducts::ids(), $paged ) );
		self::$sale[ $paged ] = [
			'ids'   => array_map( 'intval', (array) $query->posts ),
			'found' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		];
		return self::$sale[ $paged ];
	}

	/**
	 * Option names of the cache entry the request will read (so the theme can prime them together with its own bundles in ONE query).
	 * Empty when the request is not a catalog listing or nothing is cached through options.
	 *
	 * @return string[]
	 */
	public static function prefetch_names(): array {
		$kind = Query::main_context();
		if ( '' === $kind && SalePage::is_sale_page() ) {
			$kind = 'sale';
		}
		if ( '' === $kind || is_404() ) {
			return [];
		}
		$term_id = in_array( $kind, [ 'category', 'brand' ], true ) ? (int) get_queried_object_id() : 0;
		$search  = 'search' === $kind ? trim( (string) get_search_query( false ) ) : '';
		$pll     = Plugin::instance()->integration( 'polylang' );
		$lang    = $pll instanceof Polylang ? $pll->current_language() : '';
		return Counts::option_names( Counts::scope( $kind, $term_id, $search, $lang ), 'shop' === $kind ? Params::empty_state() : Query::state( $kind ) );
	}

	/**
	 * Context of the current request. `active` is false when the request is not a catalog listing (everything else empty).
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		if ( null === self::$memo ) {
			self::$memo = self::build();
		}
		return self::$memo;
	}

	/**
	 * Build the context.
	 *
	 * @return array<string,mixed>
	 */
	private static function build(): array {
		$kind = Query::main_context();
		if ( '' === $kind && SalePage::is_sale_page() ) {
			$kind = 'sale';
		}
		$empty = [
			'active'         => false,
			'kind'           => '',
			'term_id'        => 0,
			'search'         => '',
			'state'          => Params::empty_state(),
			'query_string'   => '',
			'active_filters' => 0,
			'sort_options'   => [],
			'discount_ready' => DiscountMeta::ready(),
			'ids'            => [],
			'found'          => 0,
			'pages'          => 1,
			'paged'          => 1,
			'per_page'       => Params::PER_PAGE,
			'facets'         => [],
			'facets_found'   => 0,
			'cats'           => [],
			'capped'         => false,
			'term_data'      => [],
			'shop_cards'     => [],
		];
		if ( '' === $kind ) {
			return $empty;
		}

		$state   = Query::state( $kind );
		$term_id = 0;
		$search  = '';
		if ( in_array( $kind, [ 'category', 'brand' ], true ) ) {
			$term_id = (int) get_queried_object_id();
		}
		if ( 'search' === $kind ) {
			$search = trim( (string) get_search_query( false ) );
		}

		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( 'sale' === $kind ) {
			$page  = self::sale_page( $paged );
			$ids   = $page['ids'];
			$found = $page['found'];
			$pages = max( 1, $page['pages'] );
		} else {
			global $wp_query;
			$ids   = array_values( array_filter( array_map( static fn( $post ): int => $post instanceof \WP_Post ? (int) $post->ID : 0, (array) $wp_query->posts ) ) );
			$found = 'shop' === $kind ? 0 : (int) $wp_query->found_posts;
			$pages = max( 1, (int) $wp_query->max_num_pages );
			if ( 'shop' === $kind ) {
				$ids = [];
			}
		}

		$pll  = Plugin::instance()->integration( 'polylang' );
		$lang = $pll instanceof Polylang ? $pll->current_language() : '';
		$bund = Counts::bundle( Counts::scope( $kind, $term_id, $search, $lang ), 'shop' === $kind ? Params::empty_state() : $state );

		return array_merge(
			$empty,
			[
				'active'         => true,
				'kind'           => $kind,
				'term_id'        => $term_id,
				'search'         => $search,
				'state'          => $state,
				'query_string'   => Params::serialise( $state ),
				'active_filters' => Params::active_count( $state ),
				'sort_options'   => Sorting::options( 'brand' === $kind ? 'category' : $kind ),
				'ids'            => $ids,
				'found'          => $found,
				'pages'          => $pages,
				'paged'          => $paged,
				'facets'         => (array) ( $bund['groups'] ?? [] ),
				'facets_found'   => (int) ( $bund['found'] ?? 0 ),
				'cats'           => (array) ( $bund['cats'] ?? [] ),
				'capped'         => ! empty( $bund['capped'] ),
				'term_data'      => (array) ( $bund['term'] ?? [] ),
				'shop_cards'     => (array) ( $bund['shop_cards'] ?? [] ),
			]
		);
	}
}
