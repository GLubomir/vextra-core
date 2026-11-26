<?php
/**
 * Hierarchical published-product counts of `product_cat` terms (the single source of "is this category empty").
 *
 * WordPress' `term_taxonomy.count` is DIRECT-only (a parent whose products sit in children has count 0) and Rank Math
 * reads it for its empty-taxonomy noindex, so nothing in the Seo module reads `$term->count`. One prepared SQL per cache
 * miss reads every `product_cat` term (all languages: ids are language-specific, so the map needs no language key) with
 * its parent and its listable PUBLISHED products (not password protected, not catalog-hidden); the totals are rolled up through
 * the parent chain in PHP as the number of DISTINCT products of the subtree (TASK-050: a product in a parent and its child, or in
 * two sibling children, counts once, so the number equals the category page "Found N"). Out-of-stock products are `publish` and
 * count. "direct" = distinct products assigned directly. Decisions (`has_products`, noindex, sitemap) use `> 0`, which the union
 * keeps identical to the old sum; the only change is a category whose products are ALL catalog-hidden: its page lists nothing, so it
 * is now empty (noindex) like any other empty category.
 *
 * Cache: `Support\Cache` group `seo` (persistent object cache if present, else transients; never under WP-CLI) plus a
 * per-request memo. Flushed on product/term writes; at shutdown the set of indexable categories is hashed and the Rank Math
 * term sitemap is invalidated only when that set actually changed.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;
use Vextra\Core\Support\Cache;
use Vextra\Core\Support\Listable;

final class TermCounts {

	public const TAXONOMY = 'product_cat';

	private const GROUP = 'seo';

	private const KEY = 'product_cat_counts';

	private const HASH_OPTION = 'vx_core_seo_cat_index_hash';

	/**
	 * Per-request memo: `[ 'direct' => [ id => n ], 'total' => [ id => n ] ]`.
	 *
	 * @var array{direct:array<int,int>,total:array<int,int>}|null
	 */
	private static ?array $memo = null;

	/**
	 * Flush hooks registered (once per request, whichever module asks first).
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * A flush happened and the shutdown re-check is queued.
	 *
	 * @var bool
	 */
	private static bool $shutdown_queued = false;

	/**
	 * Rank Math adapter (manual noindex lookup + sitemap invalidation).
	 *
	 * @var RankMath
	 */
	private RankMath $rank_math;

	public function __construct( RankMath $rank_math ) {
		$this->rank_math = $rank_math;
	}

	/**
	 * Register the invalidation hooks (idempotent).
	 */
	public function register(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		add_action( 'set_object_terms', [ $this, 'on_object_terms' ], 10, 6 );
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'on_deleted_post' ] );
		add_action( 'created_' . self::TAXONOMY, [ $this, 'invalidate' ] );
		add_action( 'edited_' . self::TAXONOMY, [ $this, 'invalidate' ] );
		add_action( 'delete_' . self::TAXONOMY, [ $this, 'invalidate' ] );
		add_action( 'pll_save_term', [ $this, 'invalidate' ] );
		add_action( 'vextra/catalog/product_exposure_changed', [ $this, 'invalidate' ] );
	}

	/**
	 * Hierarchical published product count of a term (0 when unknown).
	 *
	 * @param int $term_id Term ID.
	 */
	public function count( int $term_id ): int {
		return $this->data()['total'][ $term_id ] ?? 0;
	}

	/**
	 * Published products assigned directly to a term.
	 *
	 * @param int $term_id Term ID.
	 */
	public function direct( int $term_id ): int {
		return $this->data()['direct'][ $term_id ] ?? 0;
	}

	/**
	 * Whether the term or any descendant has a published product.
	 *
	 * @param int $term_id Term ID.
	 */
	public function has_products( int $term_id ): bool {
		return $this->count( $term_id ) > 0;
	}

	/**
	 * Indexable = has products AND not explicitly noindex (term SEO meta / taxonomy setting).
	 *
	 * @param int $term_id Term ID.
	 */
	public function is_indexable( int $term_id ): bool {
		return $this->has_products( $term_id ) && ! $this->rank_math->is_term_noindex( $term_id, self::TAXONOMY );
	}

	/**
	 * Ids of every term (all languages) that has products.
	 *
	 * @return int[]
	 */
	public function populated_ids(): array {
		$ids = array_keys( array_filter( $this->data()['total'] ) );
		sort( $ids );
		return array_map( 'intval', $ids );
	}

	/**
	 * Ids of every indexable term (all languages), ascending.
	 *
	 * @return int[]
	 */
	public function indexable_ids(): array {
		$ids = $this->populated_ids();
		if ( array() === $ids ) {
			return [];
		}
		update_termmeta_cache( $ids );
		return array_values( array_filter( $ids, fn( int $id ): bool => ! $this->rank_math->is_term_noindex( $id, self::TAXONOMY ) ) );
	}

	/**
	 * Drop the cached map and queue the shutdown sitemap check.
	 */
	public function invalidate(): void {
		self::$memo = null;
		Cache::flush( self::GROUP );
		if ( ! self::$shutdown_queued ) {
			self::$shutdown_queued = true;
			add_action( 'shutdown', [ $this, 'sync_sitemap' ] );
		}
	}

	/**
	 * Hook: `set_object_terms`.
	 *
	 * @param int    $object_id Object ID (unused).
	 * @param mixed  $terms     Terms (unused).
	 * @param mixed  $tt_ids    Term taxonomy ids (unused).
	 * @param string $taxonomy  Taxonomy.
	 * @param mixed  $append    Append flag (unused).
	 * @param mixed  $old_tt_ids Previous term taxonomy ids.
	 */
	public function on_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = [] ): void {
		unset( $object_id, $terms, $append );
		if ( self::TAXONOMY === $taxonomy ) {
			$this->invalidate();
			return;
		}
		// Catalog visibility (hidden products are not counted): flush only when the `exclude-from-catalog` flag itself flipped, not on every stock change.
		if ( 'product_visibility' === $taxonomy ) {
			$woo    = Plugin::instance()->integration( 'woocommerce' );
			$hidden = $woo instanceof WooCommerce ? $woo->hidden_term_tt() : 0;
			if ( $hidden > 0 && in_array( $hidden, array_map( 'intval', (array) $tt_ids ), true ) !== in_array( $hidden, array_map( 'intval', (array) $old_tt_ids ), true ) ) {
				$this->invalidate();
			}
		}
	}

	/**
	 * Hook: `transition_post_status`.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status && $post instanceof \WP_Post && 'product' === $post->post_type ) {
			$this->invalidate();
		}
	}

	/**
	 * Hook: `deleted_post`.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_deleted_post( $post_id ): void {
		if ( 'product' === get_post_type( (int) $post_id ) ) {
			$this->invalidate();
		}
	}

	/**
	 * Shutdown: invalidate the Rank Math term sitemap only when the indexable set changed.
	 */
	public function sync_sitemap(): void {
		$hash = md5( implode( ',', $this->indexable_ids() ) );
		if ( get_option( self::HASH_OPTION, '' ) === $hash ) {
			return;
		}
		if ( false === get_option( self::HASH_OPTION, false ) ) {
			add_option( self::HASH_OPTION, $hash, '', 'no' );
		} else {
			update_option( self::HASH_OPTION, $hash, false );
		}
		$this->rank_math->invalidate_sitemap( self::TAXONOMY );
	}

	/**
	 * Memoised map (per request), cache-backed.
	 *
	 * @return array{direct:array<int,int>,total:array<int,int>}
	 */
	private function data(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$data       = Cache::remember( self::GROUP, self::KEY, DAY_IN_SECONDS, fn(): array => $this->build() );
		self::$memo = is_array( $data ) ? $data : $this->build();
		return self::$memo;
	}

	/**
	 * One prepared query + PHP union roll-up.
	 *
	 * Rows are `term, parent, product` ordered by product id (terms without a listable product come with a NULL product). For every
	 * product the set of its terms plus all their ancestors is marked once, so `total` is the number of DISTINCT products of the
	 * subtree: a product assigned to a parent AND its child (Woo does this) or to two sibling children counts once, exactly like the
	 * category page (`found_posts` of an `include_children` tax query). Streaming by product keeps memory flat (no per-term id sets).
	 * Products with catalog visibility "hidden" (`exclude-from-catalog`) are excluded like the category listing excludes them.
	 *
	 * @return array{direct:array<int,int>,total:array<int,int>}
	 */
	private function build(): array {
		global $wpdb;

		$hidden = 0;
		$woo    = Plugin::instance()->integration( 'woocommerce' );
		if ( $woo instanceof WooCommerce ) {
			$hidden = $woo->hidden_term_tt();
		}
		$not_hidden = $hidden > 0
			? $wpdb->prepare( " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} hv WHERE hv.object_id = p.ID AND hv.term_taxonomy_id = %d ) ", $hidden ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			: '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$sql  = $wpdb->prepare(
			"SELECT tt.term_id, tt.parent, p.ID
			FROM {$wpdb->term_taxonomy} tt
			LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = %s AND p.post_status = %s /*LISTABLE*/ /*HIDDEN*/
			WHERE tt.taxonomy = %s
			ORDER BY p.ID",
			'product',
			'publish',
			self::TAXONOMY
		);
		$sql  = str_replace( [ '/*LISTABLE*/', '/*HIDDEN*/' ], [ Listable::sql( 'p' ), $not_hidden ], $sql ); // SEC-066: protected products are not counted.
		$rows = $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->flush(); // Do not keep a second copy of the result set in `$wpdb->last_result`.

		$direct  = [];
		$parents = [];
		foreach ( (array) $rows as $row ) {
			$id             = (int) $row[0];
			$parents[ $id ] = (int) $row[1];
			if ( ! isset( $direct[ $id ] ) ) {
				$direct[ $id ] = 0;
			}
			if ( null !== $row[2] ) {
				++$direct[ $id ];
			}
		}

		// Term + ancestors, computed once per term (cycle-safe, stops at a parent that is not in the result).
		$chains = [];
		$chain  = static function ( int $id ) use ( &$chains, $parents ): array {
			if ( isset( $chains[ $id ] ) ) {
				return $chains[ $id ];
			}
			$list = [ $id ];
			$seen = [ $id => true ];
			$up   = $parents[ $id ] ?? 0;
			while ( $up > 0 && isset( $parents[ $up ] ) && ! isset( $seen[ $up ] ) ) {
				$list[]      = $up;
				$seen[ $up ] = true;
				$up          = $parents[ $up ];
			}
			$chains[ $id ] = $list;
			return $list;
		};

		$total   = array_fill_keys( array_keys( $direct ), 0 );
		$current = null;
		$marked  = [];
		foreach ( (array) $rows as $row ) {
			if ( null === $row[2] ) {
				continue; // ORDER BY puts the product-less terms first.
			}
			$pid = (int) $row[2];
			if ( $pid !== $current ) {
				foreach ( $marked as $tid => $_ ) {
					++$total[ $tid ];
				}
				$marked  = [];
				$current = $pid;
			}
			foreach ( $chain( (int) $row[0] ) as $tid ) {
				$marked[ $tid ] = true;
			}
		}
		foreach ( $marked as $tid => $_ ) {
			++$total[ $tid ];
		}

		return [
			'direct' => $direct,
			'total'  => $total,
		];
	}
}
