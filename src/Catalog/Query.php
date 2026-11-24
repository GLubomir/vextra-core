<?php
/**
 * Catalog listing query (TASK-043b): applies the normalised, allow-listed filter / sort state to the main product queries
 * (category, brand, shop root, product search) and to the own sale query, through public WordPress hooks only.
 *
 * - `parse_request` (priority 3, after the Guard): the Woo-native request values Woo reads straight from `$_GET` (`min_price`,
 *   `max_price`, `rating_filter`, `filter_<attribute>`, `query_type_<attribute>`, `orderby`) are replaced by their normalised form
 *   (keys stay, so the robots / hreflang rules of 043a still see a facet URL). Garbage never reaches Woo.
 * - `pre_get_posts` (priority 20, after Woo's own): marks the main query `vx_catalog`, 24 per page, adds the term filters (brand,
 *   attributes OR within a group, category on search) as `tax_query`, and the category of a search as the `product_cat` var.
 * - `posts_clauses` (priority 20): price / stock / rating / on-sale / discount conditions and the ORDER BY through the guarded
 *   `Lookup` adapter (the one place that writes SQL). The same clauses serve every context, so the main queries and the sale
 *   listing share ONE implementation of the filter semantics (the counts engine mirrors it).
 * The state is read once per request per context. Pure data helpers live in `Params`, `Sorting`, `Counts`.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;
use Vextra\Core\Support\Listable;

final class Query {

	/**
	 * Per-request state memo by context.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $states = [];

	/**
	 * Context of the main query (set in pre_get_posts).
	 *
	 * @var string
	 */
	private static string $main_context = '';

	/**
	 * The query string as it arrived (before `sanitise_get()` blanks the values Woo would filter a second time).
	 *
	 * @var array<mixed>|null
	 */
	private static ?array $raw = null;

	public function register(): void {
		add_action( 'parse_request', [ $this, 'sanitise_get' ], 3 );
		add_action( 'pre_get_posts', [ $this, 'route_search' ], 5 );
		add_action( 'pre_get_posts', [ $this, 'main_query' ], 20 );
		add_filter( 'posts_clauses', [ $this, 'clauses' ], 20, 2 );
		add_filter( 'loop_shop_per_page', static fn(): int => Params::PER_PAGE, 99 );
		// Woo's own price clause (reads the raw parameters, an empty `max_price=` would exclude everything): this module applies the price.
		add_filter( 'woocommerce_enable_post_clause_filtering', static fn( $enabled, $query = null ) => $query instanceof \WP_Query && '' !== (string) $query->get( 'vx_catalog' ) ? false : $enabled, 10, 2 );
	}

	/**
	 * Context of the main query ('' when the request is not a catalog listing).
	 */
	public static function main_context(): string {
		return self::$main_context;
	}

	/**
	 * Normalised state of a context for the current request.
	 *
	 * @param string $context category|brand|shop|search|sale.
	 * @return array<string,mixed>
	 */
	public static function state( string $context ): array {
		if ( ! isset( self::$states[ $context ] ) ) {
			self::$states[ $context ] = Params::normalise( self::get(), 'brand' === $context ? 'category' : $context, [ self::class, 'known' ] );
		}
		return self::$states[ $context ];
	}

	/**
	 * Forget the memo (tests).
	 */
	public static function reset(): void {
		self::$states       = [];
		self::$main_context = '';
		self::$raw          = null;
	}

	/**
	 * The query string array (read only here, normalised by Params).
	 *
	 * @return array<mixed>
	 */
	private static function get(): array {
		return self::$raw ?? $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitised and allow-listed by Params::normalise().
	}

	/**
	 * Every slug of a taxonomy (cached), null when the taxonomy does not exist. Used to drop unknown filter values.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return string[]|null
	 */
	public static function known( string $taxonomy ): ?array {
		return TermMap::slugs( $taxonomy );
	}

	/**
	 * Replace the Woo-native values of `$_GET` by their normalised form (keys stay).
	 *
	 * @param mixed $wp WP object of `parse_request` (its raw query vars are cleaned).
	 */
	public function sanitise_get( $wp = null ): void {
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || array() === $_GET ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$get = self::get();
		if ( ! Params::has_facet( $get ) ) {
			return;
		}
		// Woo's product-filter layer (WC 11.3) reads the same parameters as query vars (`filter_stock_status`, `filter_<attr>`, price,
		// rating) and would apply raw, unvalidated values (`filter_stock_status=bogus` = empty list). Everything it knows is applied
		// by this module from the normalised state, so the raw query vars are removed (the `$_GET` keys stay for robots / hreflang).
		if ( $wp instanceof \WP && is_array( $wp->query_vars ) ) {
			foreach ( array_keys( $wp->query_vars ) as $var ) {
				$var = (string) $var;
				if ( in_array( $var, [ 'min_price', 'max_price', 'rating_filter' ], true ) || str_starts_with( $var, 'filter_' ) || str_starts_with( $var, 'query_type_' ) ) {
					unset( $wp->query_vars[ $var ] );
				}
			}
		}
		self::$raw = $get;
		$state     = Params::normalise( $get, 'sale', [ self::class, 'known' ] );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- values are produced by Params::normalise().
		if ( isset( $_GET['orderby'] ) ) {
			$_GET['orderby'] = (string) $state['orderby'];
		}
		foreach ( [ 'min_price', 'max_price' ] as $bound ) {
			if ( isset( $_GET[ $bound ] ) ) {
				$_GET[ $bound ] = null === $state[ $bound ] ? '' : (string) $state[ $bound ];
			}
		}
		// Rating and attributes are filtered by this module (lookup table / term_taxonomy_id): Woo's own second pass over the same
		// parameters would only cost queries, so the values it reads are emptied (the keys stay: the URL is still a facet URL).
		foreach ( array_keys( $get ) as $key ) {
			$key = (string) $key;
			if ( 'rating_filter' === $key || str_starts_with( $key, 'query_type_' ) || ( str_starts_with( $key, 'filter_' ) && ! in_array( $key, [ 'filter_brand', 'filter_stock_status', 'filter_on_sale', 'filter_discount' ], true ) ) ) {
				$_GET[ $key ] = '';
			}
		}
		// phpcs:enable
		self::$states = [];
	}

	/**
	 * Context of a main query ('' = not ours).
	 *
	 * @param \WP_Query $q Main query.
	 */
	private static function detect( \WP_Query $q ): string {
		if ( $q->is_search() && 'product' === $q->get( 'post_type' ) ) {
			return 'search';
		}
		if ( $q->is_tax( 'product_cat' ) ) {
			return 'category';
		}
		if ( $q->is_tax( 'product_brand' ) ) {
			return 'brand';
		}
		if ( $q->is_post_type_archive( 'product' ) && ! $q->is_tax() && ! $q->is_search() ) {
			return 'shop';
		}
		return '';
	}

	/**
	 * The store only searches products: a front search without `post_type` (`?s=`, `/search/<q>/`; not `?s=` on a taxonomy / archive URL, which keeps its listing) becomes the product search, so it
	 * is answered by the catalog search template (no redirect, same URL, same noindex rules as `?post_type=product`).
	 *
	 * @param mixed $q Query.
	 */
	public function route_search( $q ): void {
		if ( ! $q instanceof \WP_Query || is_admin() || ! $q->is_main_query() || ! $q->is_search() || $q->is_tax() || $q->is_post_type_archive() || '' !== (string) ( is_array( $q->get( 'post_type' ) ) ? 'x' : $q->get( 'post_type' ) ) ) {
			return;
		}
		$q->set( 'post_type', 'product' );
	}

	/**
	 * Main product query: mark it, 24 per page, term filters.
	 *
	 * @param mixed $q Query.
	 */
	public function main_query( $q ): void {
		if ( ! $q instanceof \WP_Query || ! $q->is_main_query() || is_admin() ) {
			return;
		}
		$context = self::detect( $q );
		if ( '' === $context ) {
			return;
		}
		self::$main_context = $context;

		$q->set( 'vx_catalog', $context );
		if ( 'shop' === $context ) {
			// Shop root = category cards only (Woo "shop page display = categories"): the product list is not printed.
			$q->set( 'posts_per_page', 1 );
			$q->set( 'no_found_rows', true );
			return;
		}

		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( $woo instanceof WooCommerce ) {
			$woo->drop_loop_priming();
		}

		$state = self::state( $context );
		$q->set( 'posts_per_page', Params::PER_PAGE );
		$q->set( 'vx_catalog_key', Params::hash( $state ) );

		$tax_query = $q->get( 'tax_query' );
		$tax_query = is_array( $tax_query ) ? $tax_query : [];
		$tax_query = array_merge( $tax_query, self::tax_query( $state ) );
		if ( array() !== $tax_query ) {
			$q->set( 'tax_query', $tax_query );
		}
		if ( 'search' === $context ) {
			$q->set( 'product_cat', '' ); // The category facet of a search is a tax_query clause above (no native slug lookup).
		}
	}

	/**
	 * Term filters of a state as tax_query clauses (brand, attributes: OR inside a group, AND across groups).
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<int,array<string,mixed>>
	 */
	public static function tax_query( array $state ): array {
		$groups = [];
		if ( ! empty( $state['brand'] ) ) {
			$groups['product_brand'] = (array) $state['brand'];
		}
		foreach ( (array) $state['attrs'] as $slug => $terms ) {
			if ( ! empty( $terms ) ) {
				$groups[ 'pa_' . $slug ] = (array) $terms;
			}
		}
		if ( '' !== (string) $state['cat'] ) {
			$groups['product_cat'] = [ (string) $state['cat'] ]; // Category + descendants (TermMap).
		}

		$out = [];
		foreach ( $groups as $taxonomy => $slugs ) {
			$ids   = TermMap::tt_ids( $taxonomy, $slugs );
			$out[] = [
				'taxonomy'         => $taxonomy,
				'field'            => 'term_taxonomy_id',
				'terms'            => array() === $ids ? [ 0 ] : $ids, // A slug without term matches nothing.
				'operator'         => 'IN',
				'include_children' => false,
			];
		}
		return $out;
	}

	/**
	 * Lookup-table conditions and ordering for every catalog query.
	 *
	 * @param mixed $clauses Clauses.
	 * @param mixed $q       Query.
	 * @return mixed
	 */
	public function clauses( $clauses, $q ) {
		if ( ! is_array( $clauses ) || ! $q instanceof \WP_Query ) {
			return $clauses;
		}
		$context = (string) $q->get( 'vx_catalog' );
		if ( '' === $context || 'shop' === $context ) {
			return $clauses;
		}
		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( ! $woo instanceof WooCommerce || ! $woo->is_available() ) {
			return $clauses;
		}
		$state = self::state( $context );
		return $woo->lookup()->apply_clauses( $clauses, $state, Sorting::order_key( $context, (string) $state['orderby'] ) );
	}

	/**
	 * Args of an own listing query over an explicit id list (the sale page).
	 *
	 * @param string $context Context key (`sale`).
	 * @param int[]  $ids     Candidate product ids.
	 * @param int    $paged   Page number.
	 * @return array<string,mixed>
	 */
	public static function own_args( string $context, array $ids, int $paged ): array {
		$state     = self::state( $context );
		$tax_query = self::tax_query( $state );
		if ( function_exists( 'wc_get_product_visibility_term_ids' ) ) {
			$hidden = (int) ( wc_get_product_visibility_term_ids()['exclude-from-catalog'] ?? 0 );
			if ( $hidden > 0 ) {
				$tax_query[] = [
					'taxonomy' => 'product_visibility',
					'field'    => 'term_taxonomy_id',
					'terms'    => [ $hidden ],
					'operator' => 'NOT IN',
				];
			}
		}
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && function_exists( 'wc_get_product_visibility_term_ids' ) ) {
			$oos = (int) ( wc_get_product_visibility_term_ids()['outofstock'] ?? 0 );
			if ( $oos > 0 ) {
				$tax_query[] = [
					'taxonomy' => 'product_visibility',
					'field'    => 'term_taxonomy_id',
					'terms'    => [ $oos ],
					'operator' => 'NOT IN',
				];
			}
		}

		return Listable::apply(
			[
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'post__in'            => array() === $ids ? [ 0 ] : $ids,
				'orderby'             => 'post__in',
				'posts_per_page'      => Params::PER_PAGE,
				'paged'               => max( 1, $paged ),
				'ignore_sticky_posts' => true,
				'fields'              => 'ids',
				'no_found_rows'       => false,
				'lang'                => '', // The id list is already language-bound (SaleProducts).
				'tax_query'           => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'vx_catalog'              => $context,
			'update_post_meta_cache'  => false,
			'update_post_term_cache'  => false,
			]
		);
	}
}
