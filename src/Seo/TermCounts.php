<?php
/**
 * Hierarchical published-product counts of `product_cat` terms (the single source of "is this category empty").
 *
 * WordPress' `term_taxonomy.count` is DIRECT-only (a parent whose products sit in children has count 0) and Rank Math
 * reads it for its empty-taxonomy noindex, so nothing in the Seo module reads `$term->count`. One prepared SQL per cache
 * miss reads every `product_cat` term (all languages: ids are language-specific, so the map needs no language key) with
 * its parent and the number of PUBLISHED products assigned directly; the totals are rolled up through the parent chain in
 * PHP. Out-of-stock products are `publish` and count. The "total" is the sum of direct assignments of the subtree (a product
 * in two categories of the same subtree counts twice); only `> 0` is used for decisions, so this is exact where it matters.
 *
 * Cache: `Support\Cache` group `seo` (persistent object cache if present, else transients; never under WP-CLI) plus a
 * per-request memo. Flushed on product/term writes; at shutdown the set of indexable categories is hashed and the Rank Math
 * term sitemap is invalidated only when that set actually changed.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Support\Listable;

use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Support\Cache;

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

		add_action( 'set_object_terms', [ $this, 'on_object_terms' ], 10, 4 );
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
	 */
	public function on_object_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		unset( $object_id, $terms, $tt_ids );
		if ( self::TAXONOMY === $taxonomy ) {
			$this->invalidate();
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
	 * One prepared query + PHP roll-up.
	 *
	 * @return array{direct:array<int,int>,total:array<int,int>}
	 */
	private function build(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$sql  = $wpdb->prepare(
			"SELECT tt.term_id, tt.parent, COUNT( DISTINCT p.ID ) AS n
			FROM {$wpdb->term_taxonomy} tt
			LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = %s AND p.post_status = %s /*LISTABLE*/
			WHERE tt.taxonomy = %s
			GROUP BY tt.term_id, tt.parent",
			'product',
			'publish',
			self::TAXONOMY
		);
		$sql  = str_replace( '/*LISTABLE*/', Listable::sql( 'p' ), $sql ); // SEC-066: protected products are not counted.
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		$direct  = [];
		$parents = [];
		foreach ( (array) $rows as $row ) {
			$id             = (int) $row['term_id'];
			$direct[ $id ]  = (int) $row['n'];
			$parents[ $id ] = (int) $row['parent'];
		}

		$total = $direct;
		foreach ( $direct as $id => $n ) {
			if ( $n <= 0 ) {
				continue;
			}
			$seen = [ $id => true ];
			$up   = $parents[ $id ] ?? 0;
			while ( $up > 0 && isset( $total[ $up ] ) && ! isset( $seen[ $up ] ) ) {
				$total[ $up ] += $n;
				$seen[ $up ]   = true;
				$up            = $parents[ $up ] ?? 0;
			}
		}

		return [
			'direct' => $direct,
			'total'  => $total,
		];
	}
}
