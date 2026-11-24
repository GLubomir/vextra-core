<?php
/**
 * The ONE rule that keeps password-protected products out of every automatic listing (SEC-066): recommendations, catalog archives /
 * search / sale page, facet counts, category counts, home product rows and the related fallback.
 *
 * A protected product stays reachable by its own URL (WordPress shows the password form); it is just never advertised: no title, price,
 * image or link in a list, no number in a count.
 *
 * Three entry points over the same condition (`post_password = ''`):
 * - `sql( $alias )`: fragment for the prepared SQL of the lookup adapter and the term counts;
 * - `apply( $args )`: sets core `has_password => false` on `WP_Query` args built by our own code (background builders run in cron /
 *   Action Scheduler where the hook below is off);
 * - the front-end `pre_get_posts` net: every product LISTING query of a request (main or secondary, e.g. `wc_get_products()` of the home
 *   blocks) gets `has_password => false`. Not applied to a single-product lookup (`p`, `name`, singular), the admin, WP-CLI, cron, nor a
 *   REST request of a user who can edit posts (editor pickers need them).
 * The filters `vextra/catalog/listable_ids` (ids -> ids; Woo's related list, the sale id list) and `vextra/catalog/listable_args`
 * (`wc_get_products()` / `WP_Query` args; the home blocks build in Action Scheduler where the net is off) are applied by the theme with
 * `apply_filters()`, so it also works when this plugin is absent.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Support;

final class Listable {

	public const FILTER = 'vextra/catalog/listable_ids';

	public const ARGS_FILTER = 'vextra/catalog/listable_args';

	/**
	 * Hook the listing net and the id filter (idempotent).
	 */
	public static function register(): void {
		if ( false === has_action( 'pre_get_posts', [ self::class, 'query' ] ) ) {
			add_action( 'pre_get_posts', [ self::class, 'query' ], 99 );
			add_filter( self::FILTER, [ self::class, 'ids' ] );
			add_filter( self::ARGS_FILTER, [ self::class, 'apply' ] );
		}
	}

	/**
	 * SQL fragment for a prepared statement over `{$wpdb->posts}` aliased as `$alias`.
	 *
	 * @param string $alias Table alias (`p` by default; only `[a-z_]` is kept).
	 */
	public static function sql( string $alias = 'p' ): string {
		$alias = preg_replace( '/[^a-z_]/', '', strtolower( $alias ) );
		return " AND {$alias}.post_password = '' ";
	}

	/**
	 * Add the condition to the args of a `WP_Query` built by our own code.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return array<string,mixed>
	 */
	public static function apply( array $args ): array {
		$args['has_password'] = false;
		return $args;
	}

	/**
	 * `pre_get_posts`: listing queries of products never match a protected post.
	 *
	 * @param mixed $query Query.
	 */
	public static function query( $query ): void {
		if ( ! $query instanceof \WP_Query || is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		if ( $query->is_singular() || '' !== (string) $query->get( 'name' ) || absint( $query->get( 'p' ) ) > 0 || absint( $query->get( 'page_id' ) ) > 0 ) {
			return; // A single product lookup: the page (and its password form) must stay reachable.
		}
		if ( self::is_product_query( $query ) ) {
			$query->set( 'has_password', false );
		}
	}

	/**
	 * Does the query list products (post type `product`, the product archive or a product taxonomy)?
	 *
	 * @param \WP_Query $query Query.
	 */
	private static function is_product_query( \WP_Query $query ): bool {
		$type = $query->get( 'post_type' );
		if ( 'product' === $type || ( is_array( $type ) && in_array( 'product', $type, true ) ) ) {
			return true;
		}
		if ( $query->is_post_type_archive( 'product' ) ) {
			return true;
		}
		$taxonomies = isset( $query->tax_query->queried_terms ) && is_array( $query->tax_query->queried_terms ) ? array_keys( $query->tax_query->queried_terms ) : [];
		return array() !== array_intersect( $taxonomies, get_object_taxonomies( 'product' ) );
	}

	/**
	 * Filter an id list: keep only the ids that are not password protected (one statement; unknown ids are kept out too).
	 *
	 * @param mixed $ids Product ids.
	 * @return int[]
	 */
	public static function ids( $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : [] ) ) ) );
		if ( array() === $ids ) {
			return [];
		}
		$open = [];
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$sql   = 'SELECT ID FROM ' . $wpdb->posts . " WHERE post_password = '' AND ID IN ( " . implode( ', ', array_fill( 0, count( $chunk ), '%d' ) ) . ' )';
			$found = $wpdb->get_col( $wpdb->prepare( $sql, $chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$open  = array_merge( $open, array_map( 'intval', (array) $found ) );
		}
		return array_values( array_filter( $ids, static fn( int $id ): bool => in_array( $id, $open, true ) ) ); // Order kept.
	}
}
