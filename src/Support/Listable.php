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
 * Cached lists keep a product that turned protected / unpublished until their rebuild: `post_updated` records it in an autoloaded option and
 * `renderable()` (filter `vextra/catalog/renderable_ids`) drops it at render time without a query; the same change marks the caches stale
 * (action `vextra/catalog/product_exposure_changed`).
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
	 * Render-time filter (ids -> ids, no query) and the action fired when a product becomes (or stops being) listable.
	 */
	public const RENDER_FILTER = 'vextra/catalog/renderable_ids';

	public const CHANGED = 'vextra/catalog/product_exposure_changed';

	/**
	 * Autoloaded option: product id => unix time of the last change that made it NOT listable (password set, unpublished, trashed).
	 * Cached lists (stale-while-revalidate bundles, home block card data) may still hold such an id until their background rebuild;
	 * `renderable()` removes it at render time with ZERO queries. Capped; the oldest entries fall out (their caches are rebuilt long before).
	 */
	public const HIDDEN_OPTION = 'vx_hidden_products';

	private const HIDDEN_CAP = 1000;

	/**
	 * Changes of this request, written once at shutdown: id => true (hide) / false (show again).
	 *
	 * @var array<int,bool>
	 */
	private static array $pending = [];

	/**
	 * Hook the listing net and the id filter (idempotent).
	 */
	public static function register(): void {
		if ( false === has_action( 'pre_get_posts', [ self::class, 'query' ] ) ) {
			add_action( 'pre_get_posts', [ self::class, 'query' ], 99 );
			add_filter( self::FILTER, [ self::class, 'ids' ] );
			add_filter( self::ARGS_FILTER, [ self::class, 'apply' ] );
			add_filter( self::RENDER_FILTER, [ self::class, 'renderable' ] );
			add_action( 'post_updated', [ self::class, 'on_post_updated' ], 10, 3 );
			add_action( 'deleted_post', [ self::class, 'on_deleted_post' ] );
		}
	}

	/**
	 * `post_updated`: a product turned (un)listable (password set / removed, status changed): remember it for the render guard and
	 * announce it so every cache that may hold the product is marked stale (`vextra/catalog/product_exposure_changed`).
	 *
	 * @param mixed $post_id Post id.
	 * @param mixed $after   Post after the update.
	 * @param mixed $before  Post before the update.
	 */
	public static function on_post_updated( $post_id = 0, $after = null, $before = null ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || 'product' !== $after->post_type ) {
			return;
		}
		$was = self::is_public( $before );
		$is  = self::is_public( $after );
		if ( $was === $is ) {
			return;
		}
		self::remember( absint( $post_id ), ! $is );

		/**
		 * A product became, or stopped being, visible in automatic listings. Invalidation hook for recommend bundles, catalog and term
		 * counts, card data and the home blocks.
		 *
		 * @param int $post_id Product id.
		 */
		do_action( self::CHANGED, absint( $post_id ) );
	}

	/**
	 * A deleted product needs no entry.
	 *
	 * @param mixed $post_id Post id.
	 */
	public static function on_deleted_post( $post_id = 0 ): void {
		if ( isset( self::hidden()[ absint( $post_id ) ] ) ) {
			self::remember( absint( $post_id ), false );
		}
	}

	/**
	 * Published and not password protected.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function is_public( \WP_Post $post ): bool {
		return 'publish' === $post->post_status && '' === (string) $post->post_password;
	}

	/**
	 * Queue a change of the hidden list; one option write at shutdown.
	 *
	 * @param int  $id   Product id.
	 * @param bool $hide True = hide, false = show again.
	 */
	private static function remember( int $id, bool $hide ): void {
		if ( $id <= 0 ) {
			return;
		}
		if ( array() === self::$pending ) {
			add_action( 'shutdown', [ self::class, 'save_hidden' ], 4 );
		}
		self::$pending[ $id ] = $hide;
	}

	/**
	 * Write the queued changes (shutdown; tests call it directly).
	 */
	public static function save_hidden(): void {
		if ( array() === self::$pending ) {
			return;
		}
		$list = self::hidden();
		foreach ( self::$pending as $id => $hide ) {
			unset( $list[ $id ] );
			if ( $hide ) {
				$list[ $id ] = time();
			}
		}
		self::$pending = [];
		asort( $list );
		$list = array_slice( $list, -self::HIDDEN_CAP, null, true );
		if ( false === get_option( self::HIDDEN_OPTION, false ) ) {
			add_option( self::HIDDEN_OPTION, $list, '', true );
		} else {
			update_option( self::HIDDEN_OPTION, $list, true );
		}
	}

	/**
	 * Products that turned unlisted: id => time (autoloaded option, no query), with this request's unsaved changes applied.
	 *
	 * @return array<int,int>
	 */
	public static function hidden(): array {
		$list = get_option( self::HIDDEN_OPTION, [] );
		$list = is_array( $list ) ? array_map( 'intval', $list ) : [];
		foreach ( self::$pending as $id => $hide ) {
			unset( $list[ $id ] );
			if ( $hide ) {
				$list[ $id ] = time();
			}
		}
		return $list;
	}

	/**
	 * Render-time guard: drop the ids of products that turned unlisted since the list was cached. No query, no post object.
	 *
	 * @param mixed $ids Product ids.
	 * @return int[]
	 */
	public static function renderable( $ids ): array {
		$ids    = array_map( 'absint', is_array( $ids ) ? $ids : [] );
		$hidden = self::hidden();
		return array() === $hidden ? array_values( $ids ) : array_values( array_filter( $ids, static fn( int $id ): bool => ! isset( $hidden[ $id ] ) ) );
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
