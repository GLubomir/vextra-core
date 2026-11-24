<?php
/**
 * Facet count bundles of a catalog scope: scope resolution (SQL through the `Lookup` adapter) + the PHP engine (`Facets`) + the cache.
 *
 * One bundle per (scope, language, filter-state hash) in the group `catalog`, stored with `Support\Cache::remember_swr`: product /
 * stock / price / sale / term changes only MARK the group stale (`Catalog::invalidate()`), the next request still answers from the
 * stale entry and ONE deduplicated Action Scheduler job (`vextra/cache/rebuild`) rebuilds it; only the first-ever build of a state is
 * synchronous. Under WP-CLI nothing is cached. Cold cost = 2 fact queries (+ 1 to 3 term-name queries); warm cost = one cache read.
 *
 * Scope (`kind`): `category` (term + descendants), `brand` (one `product_brand` term), `search` (ids of the unfiltered product
 * search `s`), `sale` (`SaleProducts::ids()`), `shop` (no facets). A scope above `Lookup::MAX_SCOPE` products yields NO groups
 * (`capped`), the listing still works.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

use Vextra\Core\Integrations\WooCommerce\Lookup;
use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;
use Vextra\Core\Support\Cache;
use Vextra\Core\Support\Listable;

final class Counts {

	public const GROUP = 'catalog';

	private const TTL = HOUR_IN_SECONDS;

	/**
	 * Group, lifetime (seconds) and row cap of the non-canonical states.
	 */
	public const BOUNDED_GROUP = 'catalog_s';

	private const BOUNDED_TTL = 600;

	private const BOUNDED_ROWS = 200;

	/**
	 * Per-request memo of bundles.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $memo = [];

	/**
	 * Register the SWR rebuilder (idempotent).
	 */
	public static function register(): void {
		Cache::boot_swr();
		Cache::register_rebuilder(
			self::GROUP,
			static function ( array $recipe ) {
				$scope = is_array( $recipe['scope'] ?? null ) ? $recipe['scope'] : [];
				$state = is_array( $recipe['state'] ?? null ) ? $recipe['state'] : Params::empty_state();
				return self::build( $scope, array_merge( Params::empty_state(), $state ) );
			}
		);
	}

	/**
	 * Scope descriptor of a request.
	 *
	 * @param string $kind     category|brand|search|sale|shop.
	 * @param int    $term_id  Term id (category / brand).
	 * @param string $search   Search text (search).
	 * @param string $lang     Language slug.
	 * @return array<string,mixed>
	 */
	public static function scope( string $kind, int $term_id, string $search, string $lang ): array {
		return [
			'kind'    => $kind,
			'term_id' => 'category' === $kind || 'brand' === $kind ? $term_id : 0,
			's'       => 'search' === $kind ? $search : '',
			'lang'    => $lang,
		];
	}

	/**
	 * Bundle for a scope and a state (cached).
	 *
	 * @param array<string,mixed> $scope Scope descriptor.
	 * @param array<string,mixed> $state Normalised state.
	 * @return array{found:int,groups:array,cats:array,capped:bool}
	 */
	public static function bundle( array $scope, array $state ): array {
		$key = self::key( $scope, $state );
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		if ( self::is_canonical( $scope, $state ) ) {
			$data = Cache::remember_swr(
				self::GROUP,
				$key,
				self::TTL,
				static fn(): array => self::build( $scope, $state ),
				[
					'scope' => $scope,
					'state' => $state,
				]
			);
		} else {
			// Any other state (price range, several values or groups, search text) is not persisted for long and is capped: bots
			// must not be able to grow the options table. Short TTL + at most BOUNDED_ROWS rows, oldest evicted.
			$built = false;
			$data  = Cache::remember(
				self::BOUNDED_GROUP,
				$key,
				self::BOUNDED_TTL,
				static function () use ( $scope, $state, &$built ): array {
					$built = true;
					return self::build( $scope, $state );
				}
			);
			if ( $built ) {
				Cache::track( self::BOUNDED_GROUP, $key, self::BOUNDED_ROWS );
			}
		}
		if ( ! is_array( $data ) || ! isset( $data['groups'] ) ) {
			$data = self::build( $scope, $state );
		}
		self::$memo[ $key ] = $data;
		return $data;
	}

	/**
	 * Cache key of a bundle.
	 *
	 * @param array<string,mixed> $scope Scope descriptor.
	 * @param array<string,mixed> $state Normalised state.
	 */
	public static function key( array $scope, array $state ): string {
		$state['orderby'] = ''; // Counts do not depend on the sort: one entry serves every sort.
		return 'b4:' . md5( (string) wp_json_encode( $scope ) ) . ':' . Params::hash( $state ) . ':' . ( DiscountMeta::ready() ? 'd' : 'n' );
	}

	/**
	 * Option names of the cache entry of a scope + state (for the early combined prefetch).
	 *
	 * @param array<string,mixed> $scope Scope descriptor.
	 * @param array<string,mixed> $state Normalised state.
	 * @return string[]
	 */
	public static function option_names( array $scope, array $state ): array {
		if ( ! self::is_canonical( $scope, $state ) ) {
			return Cache::option_names( self::BOUNDED_GROUP, self::key( $scope, $state ) );
		}
		return Cache::swr_option_names( self::GROUP, self::key( $scope, $state ) );
	}

	/**
	 * Canonical states are the only ones cached for long (SWR, a week of storage): the unfiltered base and states with exactly ONE
	 * selected value (one brand, one attribute term, one stock status, ...) of a category / brand / sale scope. Their number is bounded
	 * by the catalog itself (terms x options). Price ranges, several values / groups and every search text (raw user input) are not.
	 *
	 * @param array<string,mixed> $scope Scope descriptor.
	 * @param array<string,mixed> $state Normalised state.
	 */
	public static function is_canonical( array $scope, array $state ): bool {
		if ( 'search' === ( $scope['kind'] ?? '' ) || null !== ( $state['min_price'] ?? null ) || null !== ( $state['max_price'] ?? null ) ) {
			return false;
		}
		$values = count( (array) $state['stock'] ) + count( (array) $state['brand'] ) + ( array() === (array) $state['rating'] ? 0 : 1 )
			+ ( empty( $state['on_sale'] ) ? 0 : 1 ) + ( (int) $state['discount'] > 0 ? 1 : 0 ) + ( '' === (string) $state['cat'] ? 0 : 1 );
		foreach ( (array) $state['attrs'] as $terms ) {
			$values += count( (array) $terms );
		}
		return $values <= 1;
	}

	/**
	 * Empty bundle.
	 *
	 * @param bool $capped Scope above the cap.
	 * @return array{found:int,groups:array,cats:array,capped:bool}
	 */
	private static function empty( bool $capped = false ): array {
		return [
			'found'  => 0,
			'groups' => [],
			'cats'   => [],
			'capped' => $capped,
		];
	}

	/**
	 * Cold build.
	 *
	 * @param array<string,mixed> $scope Scope descriptor.
	 * @param array<string,mixed> $state Normalised state.
	 * @return array{found:int,groups:array,cats:array,capped:bool}
	 */
	public static function build( array $scope, array $state ): array {
		$out  = self::build_facets( $scope, $state );
		$kind = (string) ( $scope['kind'] ?? '' );

		// Term data rides in the same cache entry (one read per request): pills / accessories of the category, of the main result
		// category of a search, the shop-root cards.
		$out['term']       = [];
		$out['shop_cards'] = [];
		if ( 'category' === $kind ) {
			$out['term'] = TermData::build( (int) $scope['term_id'], (string) ( $scope['lang'] ?? '' ) );
		} elseif ( 'search' === $kind && ! empty( $out['cats'][0]['id'] ) ) {
			$out['term'] = TermData::build( (int) $out['cats'][0]['id'], (string) ( $scope['lang'] ?? '' ) );
		} elseif ( 'shop' === $kind ) {
			$out['shop_cards'] = TermData::build_shop( (string) ( $scope['lang'] ?? '' ) );
		}
		return $out;
	}

	/**
	 * Facet part of the bundle.
	 *
	 * @param array<string,mixed> $scope Scope descriptor.
	 * @param array<string,mixed> $state Normalised state.
	 * @return array{found:int,groups:array,cats:array,capped:bool}
	 */
	private static function build_facets( array $scope, array $state ): array {
		$kind = (string) ( $scope['kind'] ?? '' );
		$woo  = Plugin::instance()->integration( 'woocommerce' );
		if ( 'shop' === $kind || ! $woo instanceof WooCommerce || ! $woo->is_available() || ! function_exists( 'wc_get_product_visibility_term_ids' ) ) {
			return self::empty();
		}
		$lookup = $woo->lookup();
		if ( ! $lookup->available() ) {
			return self::empty();
		}

		$visibility = (array) wc_get_product_visibility_term_ids();
		$hidden_tt  = (int) ( $visibility[ 'search' === $kind ? 'exclude-from-search' : 'exclude-from-catalog' ] ?? 0 );
		$hide_oos   = 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' );
		$lang       = (string) ( $scope['lang'] ?? '' );
		$term_id    = (int) ( $scope['term_id'] ?? 0 );

		switch ( $kind ) {
			case 'category':
				$term_ids = array_merge( [ $term_id ], array_map( 'intval', (array) get_term_children( $term_id, 'product_cat' ) ) );
				$facts    = $lookup->facts_by_terms( $term_ids, $hidden_tt, $hide_oos );
				break;
			case 'brand':
				$facts = $lookup->facts_by_terms( [ $term_id ], $hidden_tt, $hide_oos, 'product_brand' );
				break;
			case 'search':
				$facts = $lookup->facts_by_ids( self::search_ids( (string) $scope['s'], $lang ), $hidden_tt, $hide_oos );
				break;
			case 'sale':
				$facts = $lookup->facts_by_ids( SaleProducts::ids( '' === $lang ? null : $lang ), $hidden_tt, $hide_oos );
				break;
			default:
				return self::empty();
		}
		if ( null === $facts ) {
			return self::empty( true );
		}
		if ( array() === $facts ) {
			return self::empty();
		}

		$with_cat = in_array( $kind, [ 'search', 'sale' ], true );
		$taxes    = [];
		if ( 'brand' !== $kind ) {
			$taxes[] = 'product_brand';
		}
		if ( $with_cat ) {
			$taxes[] = 'product_cat';
		}

		$labels     = FilterAttributes::all();
		$configured = 'category' === $kind ? FilterAttributes::for_term( $term_id ) : [];
		$state_attr = array_map( static fn( $slug ): string => 'pa_' . $slug, array_keys( (array) $state['attrs'] ) );
		$candidates = array() !== $configured ? $configured : array_keys( $labels );
		$tfact      = $lookup->term_facts( array_keys( $facts ), array_merge( $taxes, $candidates, $state_attr ) );

		$attr_taxes = $configured;
		if ( array() === $attr_taxes ) {
			$attr_taxes = self::top_attributes( $tfact, array_keys( $labels ), 3 );
		}

		$terms       = self::terms( $tfact, $state, $kind );
		$cat_parents = [];
		if ( $with_cat ) {
			$cat_parents = array_map(
				'intval',
				(array) get_terms(
					[
						'taxonomy'   => 'product_cat',
						'hide_empty' => false,
						'lang'       => '',
						'fields'     => 'id=>parent',
					]
				)
			);
		}

		$spec = [
			'attr_taxes'  => $attr_taxes,
			'brand'       => 'brand' !== $kind,
			'cat'         => $with_cat,
			'on_sale'     => 'sale' !== $kind,
			'discount'    => 'sale' === $kind && DiscountMeta::ready(),
			'cat_parents' => $cat_parents,
			'hidden_cats' => self::default_cats(),
			'labels'      => $labels,
		];

		if ( $with_cat ) {
			$terms = self::with_ancestors( $terms, $tfact, $cat_parents );
		}

		$out           = Facets::compute( $facts, $tfact, $terms, $state, $spec );
		$out['capped'] = false;
		return $out;
	}

	/**
	 * Ids of the unfiltered product search of a language.
	 *
	 * @param string $search Search text.
	 * @param string $lang   Language slug.
	 * @return int[]
	 */
	private static function search_ids( string $search, string $lang ): array {
		if ( '' === trim( $search ) ) {
			return [];
		}
		$query = new \WP_Query(
			Listable::apply(
				[
					's'                      => $search,
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'posts_per_page'         => Lookup::MAX_SCOPE + 1,
					'no_found_rows'          => true,
					'orderby'                => 'none',
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'lang'                   => $lang,
				]
			)
		);
		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * The attribute taxonomies with the most products, with at least 2 terms (the fallback when a category has no selection).
	 *
	 * @param array<int,array<string,int[]>> $tfact      Term assignments.
	 * @param string[]                       $label_keys Registered attribute taxonomies.
	 * @param int                            $limit      How many.
	 * @return string[]
	 */
	private static function top_attributes( array $tfact, array $label_keys, int $limit ): array {
		$products = [];
		$terms    = [];
		foreach ( $tfact as $id => $by_tax ) {
			foreach ( $by_tax as $taxonomy => $ids ) {
				if ( in_array( $taxonomy, $label_keys, true ) ) {
					$products[ $taxonomy ] = ( $products[ $taxonomy ] ?? 0 ) + 1;
					foreach ( $ids as $term_id ) {
						$terms[ $taxonomy ][ $term_id ] = true;
					}
				}
			}
		}
		$products = array_filter( $products, static fn( int $n, string $t ): bool => count( $terms[ $t ] ?? [] ) >= 2, ARRAY_FILTER_USE_BOTH );
		arsort( $products );
		return array_slice( array_keys( $products ), 0, $limit );
	}

	/**
	 * Term rows (id => slug, name, tax, parent) for every assigned term and every selected slug.
	 *
	 * @param array<int,array<string,int[]>> $tfact Term assignments.
	 * @param array<string,mixed>            $state State.
	 * @param string                         $kind  Scope kind.
	 * @return array<int,array<string,mixed>>
	 */
	private static function terms( array $tfact, array $state, string $kind ): array {
		$by_tax = [];
		foreach ( $tfact as $assigned ) {
			foreach ( $assigned as $taxonomy => $ids ) {
				foreach ( $ids as $id ) {
					$by_tax[ $taxonomy ][ $id ] = true;
				}
			}
		}

		$rows = [];
		foreach ( $by_tax as $taxonomy => $ids ) {
			self::fetch(
				$rows,
				[
					'taxonomy' => $taxonomy,
					'include'  => array_keys( $ids ),
				]
			);
		}

		// Selected slugs the scope has no product for still need their term (label, filter semantics).
		$selected = [ 'product_brand' => (array) $state['brand'] ];
		foreach ( (array) $state['attrs'] as $slug => $slugs ) {
			$selected[ 'pa_' . $slug ] = (array) $slugs;
		}
		if ( '' !== (string) $state['cat'] ) {
			$selected['product_cat'] = [ (string) $state['cat'] ];
		}
		foreach ( $selected as $taxonomy => $slugs ) {
			$known   = array_column( array_filter( $rows, static fn( array $r ): bool => $r['tax'] === $taxonomy ), 'slug' );
			$missing = array_values( array_diff( $slugs, $known ) );
			if ( array() !== $missing && ( 'brand' !== $kind || 'product_brand' !== $taxonomy ) ) {
				self::fetch(
					$rows,
					[
						'taxonomy' => $taxonomy,
						'slug'     => $missing,
					]
				);
			}
		}
		return $rows;
	}

	/**
	 * Add the ancestors of the assigned categories to the term rows (the category group lists parents too).
	 *
	 * @param array<int,array<string,mixed>> $rows        Rows.
	 * @param array<int,array<string,int[]>> $tfact       Term assignments.
	 * @param array<int,int>                 $cat_parents id => parent.
	 * @return array<int,array<string,mixed>>
	 */
	private static function with_ancestors( array $rows, array $tfact, array $cat_parents ): array {
		$need = [];
		foreach ( $tfact as $assigned ) {
			foreach ( $assigned['product_cat'] ?? [] as $id ) {
				for ( $guard = 0; $id > 0 && $guard < 12; ++$guard ) {
					$id = (int) ( $cat_parents[ $id ] ?? 0 );
					if ( $id > 0 && ! isset( $rows[ $id ] ) ) {
						$need[ $id ] = true;
					}
				}
			}
		}
		if ( array() !== $need ) {
			self::fetch(
				$rows,
				[
					'taxonomy' => 'product_cat',
					'include'  => array_keys( $need ),
				]
			);
		}
		return $rows;
	}

	/**
	 * Fetch terms (no language filter: ids and slugs are already language-specific) into the rows array.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows (by reference).
	 * @param array<string,mixed>            $args get_terms arguments (taxonomy + include | slug).
	 */
	private static function fetch( array &$rows, array $args ): void {
		$found = get_terms(
			array_merge(
				[
					'hide_empty'             => false,
					'lang'                   => '',
					'update_term_meta_cache' => false,
				],
				$args
			)
		);
		foreach ( is_array( $found ) ? $found : [] as $term ) {
			if ( $term instanceof \WP_Term ) {
				$rows[ (int) $term->term_id ] = [
					'slug'   => $term->slug,
					'name'   => $term->name,
					'tax'    => $term->taxonomy,
					'parent' => (int) $term->parent,
				];
			}
		}
	}

	/**
	 * Default product category and its translations (never offered as a category facet).
	 *
	 * @return int[]
	 */
	private static function default_cats(): array {
		$default = (int) get_option( 'default_product_cat' );
		$ids     = [ $default ];
		$pll     = Plugin::instance()->integration( 'polylang' );
		if ( $pll instanceof \Vextra\Core\Integrations\Polylang\Polylang ) {
			$ids = array_merge( $ids, array_values( $pll->term_translations( $default ) ) );
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}
}
