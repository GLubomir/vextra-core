<?php
/**
 * Cache of the Recommend module (TASK-038, ADR-004): keys, bundles, invalidation.
 *
 * - Product bundle (group `recommend`, SWR): ONE entry per product and language holding every list (accessories, related, post_add,
 *   up to Config::MAX_LIMIT ranked items each, `[ id, src ]` pairs): one cache read per product page. Key `product:<id>:<lang>:v1`.
 *   Invalidation only MARKS the group stale; visitors keep getting the last value while ONE deduplicated Action Scheduler job
 *   (`vextra/cache/rebuild`) rebuilds the entry; only the first-ever build of a product is synchronous.
 * - Cart bundle (group `recommend_c`, bounded): keyed by the cart signature, so it is capped (rows + TTL) like the catalog's free-form
 *   states: bots cannot grow the options table by posting arbitrary carts.
 * - Nothing is cached under WP-CLI (shared Cache rule).
 * - Invalidation (once per request at `shutdown`): product / variation save, stock and stock status, delete / trash / restore,
 *   `product_cat` / `product_tag` / attribute term edits, `vextra/catalog/accessory_rules_changed`, any `vx_rec_*` option.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend;

use Vextra\Core\Support\Cache;

final class Store {

	public const GROUP      = 'recommend';
	public const CART_GROUP = 'recommend_c';

	private const VERSION   = 'v1';
	private const TTL       = 6 * HOUR_IN_SECONDS; // Stale-by-clock bound (the synced-at filter depends on time).
	private const CART_TTL  = 600;
	private const CART_ROWS = 300;

	/**
	 * Hooks that change what a list shows.
	 */
	private const PRODUCT_HOOKS = [
		'woocommerce_new_product',
		'woocommerce_update_product',
		'woocommerce_delete_product',
		'woocommerce_trash_product',
		'woocommerce_save_product_variation',
		'woocommerce_product_set_stock',
		'woocommerce_variation_set_stock',
		'woocommerce_product_set_stock_status',
		'woocommerce_variation_set_stock_status',
		'woocommerce_scheduled_sales',
		'vextra/catalog/accessory_rules_changed',
		'pll_save_term',
	];

	/**
	 * Per-request memo of bundles.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $memo = [];

	/**
	 * A flush is queued for shutdown.
	 *
	 * @var bool
	 */
	private static bool $queued = false;

	/**
	 * Hooks + the SWR rebuilder.
	 */
	public static function register(): void {
		Cache::boot_swr();
		Cache::register_rebuilder( self::GROUP, [ Engine::class, 'rebuild' ] );

		foreach ( self::PRODUCT_HOOKS as $hook ) {
			add_action( $hook, [ self::class, 'invalidate' ] );
		}
		foreach ( [ 'trashed_post', 'untrashed_post', 'deleted_post' ] as $hook ) {
			add_action( $hook, [ self::class, 'on_post' ] );
		}
		foreach ( [ 'created_term', 'edited_term', 'delete_term' ] as $hook ) {
			add_action( $hook, [ self::class, 'on_term' ], 10, 3 );
		}
		foreach ( [ 'added_option', 'updated_option', 'deleted_option' ] as $hook ) {
			add_action( $hook, [ self::class, 'on_option' ] );
		}
	}

	/**
	 * Cached bundle of a product (language scope).
	 *
	 * @param int    $id   Product id (cache language).
	 * @param string $lang Language slug.
	 * @return array<string,mixed> `lists` => name => [ [ id, src ], ... ].
	 */
	public static function product( int $id, string $lang ): array {
		$key = 'product:' . $id . ':' . $lang . ':' . self::VERSION;
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$data = Cache::remember_swr(
			self::GROUP,
			$key,
			self::TTL,
			static fn(): array => Engine::rebuild(
				[
					'ctx'  => 'product',
					'ids'  => [ $id ],
					'lang' => $lang,
				]
			),
			[
				'ctx'  => 'product',
				'ids'  => [ $id ],
				'lang' => $lang,
			]
		);
		if ( ! is_array( $data ) || ! isset( $data['lists'] ) ) {
			$data = Engine::rebuild(
				[
					'ctx'  => 'product',
					'ids'  => [ $id ],
					'lang' => $lang,
				]
			);
		}
		self::$memo[ $key ] = $data;
		return $data;
	}

	/**
	 * Cached bundle of a cart signature (bounded cache).
	 *
	 * @param int[]  $ids  Cart product ids in the cache language, ascending.
	 * @param string $lang Language slug.
	 * @return array<string,mixed>
	 */
	public static function cart( array $ids, string $lang ): array {
		$key = 'cart:' . Engine::signature( $ids ) . ':' . $lang . ':' . self::VERSION;
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$built = false;
		$data  = Cache::remember(
			self::CART_GROUP,
			$key,
			self::CART_TTL,
			static function () use ( $ids, $lang, &$built ): array {
				$built = true;
				return Engine::rebuild(
					[
						'ctx'  => 'cart',
						'ids'  => $ids,
						'lang' => $lang,
					]
				);
			}
		);
		if ( $built ) {
			Cache::track( self::CART_GROUP, $key, self::CART_ROWS );
		}
		if ( ! is_array( $data ) || ! isset( $data['lists'] ) ) {
			$data = Engine::rebuild(
				[
					'ctx'  => 'cart',
					'ids'  => $ids,
					'lang' => $lang,
				]
			);
		}
		self::$memo[ $key ] = $data;
		return $data;
	}

	/**
	 * Mark every bundle stale, once per request at shutdown (a bulk import calls this per product).
	 */
	public static function invalidate(): void {
		if ( self::$queued ) {
			return;
		}
		self::$queued = true;
		add_action( 'shutdown', [ self::class, 'flush_now' ], 5 );
	}

	/**
	 * A post changed state: only products matter.
	 *
	 * @param mixed $post_id Post id.
	 */
	public static function on_post( $post_id = 0 ): void {
		if ( 'product' === get_post_type( absint( $post_id ) ) ) {
			self::invalidate();
		}
	}

	/**
	 * A term changed: only categories, tags and attribute terms matter.
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $tt_id    Term taxonomy id.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public static function on_term( $term_id = 0, $tt_id = 0, $taxonomy = '' ): void {
		unset( $term_id, $tt_id );
		if ( is_string( $taxonomy ) && ( 'product_cat' === $taxonomy || 'product_tag' === $taxonomy || str_starts_with( $taxonomy, 'pa_' ) ) ) {
			self::invalidate();
		}
	}

	/**
	 * An option changed: only `vx_rec_*`.
	 *
	 * @param mixed $name Option name.
	 */
	public static function on_option( $name = '' ): void {
		if ( is_string( $name ) && str_starts_with( $name, Config::OPTION_PREFIX ) ) {
			Config::reset();
			self::invalidate();
		}
	}

	/**
	 * Forget the per-request memo (dev checks: simulate a new request).
	 */
	public static function reset_memo(): void {
		self::$memo = [];
		Pool::reset();
	}

	/**
	 * Flush now (shutdown callback; tests and the CLI call it directly).
	 */
	public static function flush_now(): void {
		self::$queued = false;
		self::$memo   = [];
		Pool::reset();
		Cache::flush( self::GROUP );
		Cache::flush( self::CART_GROUP );
	}
}
