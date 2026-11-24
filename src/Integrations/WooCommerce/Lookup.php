<?php
/**
 * Guarded SQL adapter of the catalog module over Woo's product lookup table (TASK-043b).
 *
 * The ONLY place of the catalog module that writes SQL. Woo has no public API for "facet counts" and for the lookup-table
 * conditions of the listing, so this adapter reads `{prefix}wc_product_meta_lookup` (documented Woo table: min/max price, stock
 * status, rating, on-sale, total sales) plus core tables (`posts`, `postmeta`, `term_relationships`, `term_taxonomy`).
 * Every statement is prepared (placeholders for every value, ids through `%d`), the table existence is verified once per request,
 * and every method answers an empty value when the table is missing (the module then shows no facets, Woo-native listing stays).
 * Registered in docs/compat/integrations.md. Term relationships are read directly because no Woo lookup exists for brands /
 * attribute terms of the listing (`wc_product_attributes_lookup` is empty unless the optional Woo feature is switched on).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Integrations\WooCommerce;

final class Lookup {

	/**
	 * Safety cap of a scope: above it the counts are not computed (facets hidden, listing still works).
	 */
	public const MAX_SCOPE = 20000;

	/**
	 * Alias of the lookup table (the same alias Woo's own sorting / price clauses use, so the join is never doubled).
	 */
	private const ALIAS = 'wc_product_meta_lookup';

	/**
	 * Per-request memo of the table check.
	 *
	 * @var bool|null
	 */
	private static ?bool $exists = null;

	/**
	 * Real table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_product_meta_lookup';
	}

	/**
	 * Does the lookup table exist?
	 */
	public function available(): bool {
		if ( null === self::$exists ) {
			// Woo's installer has created the table since 3.6 (the DB version is an autoloaded option: no query on the hot path).
			if ( version_compare( (string) get_option( 'woocommerce_db_version', '0' ), '3.6.0', '>=' ) ) {
				self::$exists = true;
				return true;
			}
			global $wpdb;
			$found        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::$exists = self::table() === $found;
		}
		return self::$exists;
	}

	/**
	 * Add the lookup-table conditions and the ordering of a catalog state to the clauses of a product query.
	 *
	 * State keys read: min_price, max_price (int|null), stock (string[]), rating (int[]), on_sale (bool), discount (int).
	 * `$order`: default | popularity | date | price | price-desc | rating | discount | relevance (relevance = untouched).
	 *
	 * @param array<string,string> $clauses  posts_clauses array.
	 * @param array<string,mixed>  $state    Normalised catalog state (Catalog\Params::normalise()).
	 * @param string               $order    Order key.
	 * @return array<string,string>
	 */
	public function apply_clauses( array $clauses, array $state, string $order ): array {
		global $wpdb;

		if ( ! $this->available() ) {
			return $clauses;
		}

		$alias = self::ALIAS;
		$join  = (string) ( $clauses['join'] ?? '' );
		$where = (string) ( $clauses['where'] ?? '' );

		if ( false === strpos( $join, $alias ) ) {
			$join .= ' LEFT JOIN ' . self::table() . ' ' . $alias . ' ON ' . $wpdb->posts . '.ID = ' . $alias . '.product_id ';
		}

		$min      = isset( $state['min_price'] ) && null !== $state['min_price'] ? (int) $state['min_price'] : null;
		$max      = isset( $state['max_price'] ) && null !== $state['max_price'] ? (int) $state['max_price'] : null;
		$stock    = array_values( array_intersect( (array) ( $state['stock'] ?? [] ), [ 'instock', 'onbackorder' ] ) );
		$rating   = array_values( array_filter( array_map( 'intval', (array) ( $state['rating'] ?? [] ) ), static fn( int $n ): bool => $n >= 1 && $n <= 5 ) );
		$discount = (int) ( $state['discount'] ?? 0 );

		if ( null !== $min ) {
			$where .= $wpdb->prepare( ' AND ' . $alias . '.max_price >= %d ', $min ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- alias constant.
		}
		if ( null !== $max ) {
			$where .= $wpdb->prepare( ' AND ' . $alias . '.min_price <= %d ', $max ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( array() !== $stock ) {
			$where .= $wpdb->prepare( ' AND ' . $alias . '.stock_status IN ( ' . $this->placeholders_s( $stock ) . ' ) ', $stock ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		if ( array() !== $rating ) {
			$where .= $wpdb->prepare( ' AND ROUND( ' . $alias . '.average_rating ) IN ( ' . $this->placeholders_d( $rating ) . ' ) ', $rating ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		if ( ! empty( $state['on_sale'] ) ) {
			$where .= ' AND ' . $alias . '.onsale = 1 ';
		}

		$needs_discount = $discount > 0 || 'discount' === $order;
		if ( $needs_discount && false === strpos( $join, 'vx_disc' ) ) {
			$join .= $wpdb->prepare( ' LEFT JOIN ' . $wpdb->postmeta . ' vx_disc ON vx_disc.post_id = ' . $wpdb->posts . '.ID AND vx_disc.meta_key = %s ', '_vx_discount_pct' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names only.
		}
		if ( $discount > 0 ) {
			$where .= $wpdb->prepare( ' AND CAST( vx_disc.meta_value AS UNSIGNED ) >= %d ', $discount ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$posts = $wpdb->posts;
		switch ( $order ) {
			case 'popularity':
				$orderby = $alias . '.total_sales DESC, ' . $posts . '.ID DESC';
				break;
			case 'date':
				$orderby = $posts . '.post_date DESC, ' . $posts . '.ID DESC';
				break;
			case 'price':
				$orderby = $alias . '.min_price ASC, ' . $posts . '.ID ASC';
				break;
			case 'price-desc':
				$orderby = $alias . '.max_price DESC, ' . $posts . '.ID DESC';
				break;
			case 'rating':
				$orderby = $alias . '.average_rating DESC, ' . $alias . '.rating_count DESC, ' . $posts . '.ID DESC';
				break;
			case 'discount':
				$orderby = 'COALESCE( CAST( vx_disc.meta_value AS UNSIGNED ), 0 ) DESC, ' . $alias . '.total_sales DESC, ' . $posts . '.ID DESC';
				break;
			case 'relevance':
				$orderby = null;
				break;
			default:
				$orderby = $posts . '.menu_order ASC, ( COALESCE( ' . $alias . ".stock_status, 'instock' ) = 'outofstock' ) ASC, " . $alias . '.total_sales DESC, ' . $posts . '.ID DESC';
		}

		$clauses['join']  = $join;
		$clauses['where'] = $where;
		if ( null !== $orderby ) {
			$clauses['orderby'] = $orderby;
		}
		return $clauses;
	}

	/**
	 * Facts of every visible published product of a category subtree: id => [ min, max, stock, rating, onsale, disc ].
	 * Returns null when the scope is above MAX_SCOPE or the table is missing.
	 *
	 * @param int[]  $term_ids       Term ids of the taxonomy (the term and its descendants).
	 * @param int    $hidden_term_tt `exclude-from-catalog` term_taxonomy_id (0 = none).
	 * @param bool   $hide_oos       Store setting "hide out of stock items".
	 * @param string $taxonomy       `product_cat` (default) or `product_brand`.
	 * @return array<int,array<string,mixed>>|null
	 */
	public function facts_by_terms( array $term_ids, int $hidden_term_tt, bool $hide_oos, string $taxonomy = 'product_cat' ): ?array {
		global $wpdb;

		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
		if ( ! $this->available() || array() === $term_ids ) {
			return null;
		}

		$sql  = 'SELECT p.ID AS id, l.min_price, l.max_price, l.stock_status, l.average_rating, l.onsale, m.meta_value AS disc
			FROM ' . $wpdb->posts . ' p
			INNER JOIN ' . self::table() . ' l ON l.product_id = p.ID
			LEFT JOIN ' . $wpdb->postmeta . ' m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = %s
			AND p.ID IN ( SELECT tr.object_id FROM ' . $wpdb->term_relationships . ' tr
				INNER JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tt.taxonomy = %s AND tt.term_id IN ( ' . $this->placeholders_d( $term_ids ) . ' ) )';
		$args = array_merge( [ '_vx_discount_pct', 'product', 'publish', $taxonomy ], $term_ids );

		if ( $hidden_term_tt > 0 ) {
			$sql   .= ' AND p.ID NOT IN ( SELECT hr.object_id FROM ' . $wpdb->term_relationships . ' hr WHERE hr.term_taxonomy_id = %d )';
			$args[] = $hidden_term_tt;
		}
		if ( $hide_oos ) {
			$sql .= " AND l.stock_status <> 'outofstock'";
		}
		$sql   .= ' LIMIT %d';
		$args[] = self::MAX_SCOPE + 1;

		return $this->run_facts( $sql, $args );
	}

	/**
	 * Facts of an explicit id list (search result ids, sale ids). Same shape and rules as facts_by_terms().
	 *
	 * @param int[] $ids            Product ids.
	 * @param int   $hidden_term_tt `exclude-from-catalog` / `exclude-from-search` term_taxonomy_id (0 = none).
	 * @param bool  $hide_oos       Store setting "hide out of stock items".
	 * @return array<int,array<string,mixed>>|null
	 */
	public function facts_by_ids( array $ids, int $hidden_term_tt, bool $hide_oos ): ?array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $this->available() || array() === $ids || count( $ids ) > self::MAX_SCOPE ) {
			return null;
		}

		$sql  = 'SELECT p.ID AS id, l.min_price, l.max_price, l.stock_status, l.average_rating, l.onsale, m.meta_value AS disc
			FROM ' . $wpdb->posts . ' p
			INNER JOIN ' . self::table() . ' l ON l.product_id = p.ID
			LEFT JOIN ' . $wpdb->postmeta . ' m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = %s AND p.ID IN ( ' . $this->placeholders_d( $ids ) . ' )';
		$args = array_merge( [ '_vx_discount_pct', 'product', 'publish' ], $ids );

		if ( $hidden_term_tt > 0 ) {
			$sql   .= ' AND p.ID NOT IN ( SELECT hr.object_id FROM ' . $wpdb->term_relationships . ' hr WHERE hr.term_taxonomy_id = %d )';
			$args[] = $hidden_term_tt;
		}
		if ( $hide_oos ) {
			$sql .= " AND l.stock_status <> 'outofstock'";
		}

		return $this->run_facts( $sql, $args );
	}

	/**
	 * Published product ids of a set of `product_cat` terms (descendants already included by the caller), ascending, capped. One
	 * statement (the WP_Query tax-query resolution costs three). Empty when the list is empty.
	 *
	 * @param int[] $term_ids Term ids.
	 * @param int   $limit    Maximum ids.
	 * @return int[]
	 */
	public function product_ids_in_terms( array $term_ids, int $limit ): array {
		global $wpdb;

		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
		if ( array() === $term_ids || $limit < 1 ) {
			return [];
		}
		$sql = 'SELECT DISTINCT p.ID FROM ' . $wpdb->posts . ' p
			INNER JOIN ' . $wpdb->term_relationships . ' tr ON tr.object_id = p.ID
			INNER JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE p.post_type = %s AND p.post_status = %s AND tt.taxonomy = %s AND tt.term_id IN ( ' . $this->placeholders_d( $term_ids ) . ' )
			ORDER BY p.ID ASC LIMIT %d';
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( [ 'product', 'publish', 'product_cat' ], $term_ids, [ $limit ] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Recommendation facts of an explicit id list (TASK-038): post status, price range, stock status, total sales and the catalog
	 * visibility flag, in ONE statement. Rows of other post types are ignored; a product without a lookup row answers `has_row` false.
	 * Null when the table is missing.
	 *
	 * @param int[] $ids            Product ids (at most MAX_SCOPE are read).
	 * @param int   $hidden_term_tt `exclude-from-catalog` term_taxonomy_id (0 = none).
	 * @return array<int,array{status:string,min:float,max:float,stock:string,sales:int,hidden:bool,has_row:bool}>|null
	 */
	public function candidate_facts( array $ids, int $hidden_term_tt ): ?array {
		global $wpdb;

		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, self::MAX_SCOPE );
		if ( ! $this->available() ) {
			return null;
		}
		if ( array() === $ids ) {
			return [];
		}

		$hidden = $hidden_term_tt > 0
			? 'EXISTS( SELECT 1 FROM ' . $wpdb->term_relationships . ' hr WHERE hr.object_id = p.ID AND hr.term_taxonomy_id = %d )'
			: '0';
		$sql    = 'SELECT p.ID AS id, p.post_status AS status, l.product_id AS lookup_id, l.min_price, l.max_price, l.stock_status, l.total_sales, ' . $hidden . ' AS hidden
			FROM ' . $wpdb->posts . ' p
			LEFT JOIN ' . self::table() . ' l ON l.product_id = p.ID
			WHERE p.post_type = %s AND p.ID IN ( ' . $this->placeholders_d( $ids ) . ' )';
		$args   = array_merge( $hidden_term_tt > 0 ? [ $hidden_term_tt ] : [], [ 'product' ], $ids );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = [];
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = [
				'status'  => (string) $row['status'],
				'min'     => (float) $row['min_price'],
				'max'     => (float) $row['max_price'],
				'stock'   => (string) $row['stock_status'],
				'sales'   => (int) $row['total_sales'],
				'hidden'  => 1 === (int) $row['hidden'],
				'has_row' => null !== $row['lookup_id'],
			];
		}
		return $out;
	}

	/**
	 * Term assignments of products for a taxonomy list: product id => [ taxonomy => term ids ].
	 *
	 * @param int[]    $ids        Product ids.
	 * @param string[] $taxonomies Taxonomy names (already validated with taxonomy_exists()).
	 * @return array<int,array<string,int[]>>
	 */
	public function term_facts( array $ids, array $taxonomies ): array {
		global $wpdb;

		$ids        = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$taxonomies = array_values( array_unique( array_filter( array_map( 'strval', $taxonomies ) ) ) );
		if ( array() === $ids || array() === $taxonomies ) {
			return [];
		}

		$out = [];
		foreach ( array_chunk( $ids, 2000 ) as $chunk ) {
			$sql  = 'SELECT tr.object_id AS id, tt.taxonomy AS tax, tt.term_id AS term
				FROM ' . $wpdb->term_relationships . ' tr
				INNER JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tt.taxonomy IN ( ' . $this->placeholders_s( $taxonomies ) . ' ) AND tr.object_id IN ( ' . $this->placeholders_d( $chunk ) . ' )';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $taxonomies, $chunk ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['id'] ][ (string) $row['tax'] ][] = (int) $row['term'];
			}
		}
		return $out;
	}

	/**
	 * Run a facts statement and shape the rows. Null when the scope is above MAX_SCOPE.
	 *
	 * @param string       $sql  Statement with placeholders.
	 * @param array<mixed> $args Placeholder values.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function run_facts( string $sql, array $args ): ?array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) || count( $rows ) > self::MAX_SCOPE ) {
			return null;
		}

		$out = [];
		foreach ( $rows as $row ) {
			$out[ (int) $row['id'] ] = [
				'min'    => (float) $row['min_price'],
				'max'    => (float) $row['max_price'],
				'stock'  => (string) $row['stock_status'],
				'rating' => (int) round( (float) $row['average_rating'] ),
				'onsale' => 1 === (int) $row['onsale'],
				'disc'   => (int) $row['disc'],
			];
		}
		return $out;
	}

	/**
	 * `%d, %d, ...` for a list.
	 *
	 * @param array<mixed> $values Values.
	 */
	private function placeholders_d( array $values ): string {
		return implode( ', ', array_fill( 0, count( $values ), '%d' ) );
	}

	/**
	 * `%s, %s, ...` for a list.
	 *
	 * @param array<mixed> $values Values.
	 */
	private function placeholders_s( array $values ): string {
		return implode( ', ', array_fill( 0, count( $values ), '%s' ) );
	}
}
