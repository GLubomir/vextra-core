<?php
/**
 * Catalog module (TASK-043b): the data side of the product listings (category, brand, search, sale page, shop root).
 *
 * Owns: filter / sort state normalisation (`Params`), the listing query (`Query`, one implementation of every filter), disjunctive
 * facet counts with a stale-while-revalidate cache (`Facets`, `Counts`), `_vx_discount_pct` (`DiscountMeta`), per-category settings
 * (`FilterAttributes`, `AccessoryRules`, admin fields), pills / accessory / shop-card data (`TermData`) and the request context the theme
 * reads through the public functions of `functions.php`. The theme renders; it never filters.
 * Kill switch: constant `VX_CATALOG_DISABLED` (or filter `vextra/core/catalog/enabled`): Woo's own archives work, the site stays 200.
 * Yields to a duplicate filter plugin (`Compat\Conflicts` rule `duplicate-filter-plugin`).
 *
 * Invalidation: product / variation save, stock and stock-status change, sale schedule, term edits and the discount recompute action
 * mark the count bundles stale (SWR) and the term data stale ONCE per request, at shutdown.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

use Vextra\Core\Admin\CategoryCatalogFields;
use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Plugin;
use Vextra\Core\Support\Cache;

final class Catalog implements ModuleInterface {

	/**
	 * Hooks that change what a listing or its counts show.
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
		'vextra/catalog/recompute_discount',
		'vextra/catalog/product_exposure_changed',
	];

	public const PURGE_HOOK = 'vextra/catalog/purge';

	/**
	 * A flush is queued for shutdown.
	 *
	 * @var bool
	 */
	private static bool $queued = false;

	public function id(): string {
		return 'catalog';
	}

	public function is_enabled(): bool {
		$woo     = Plugin::instance()->integration( 'woocommerce' );
		$enabled = null !== $woo && $woo->is_available() && ! ( defined( 'VX_CATALOG_DISABLED' ) && VX_CATALOG_DISABLED );

		/**
		 * Feature flag of the Catalog module.
		 *
		 * @param bool $enabled Default: WooCommerce available and VX_CATALOG_DISABLED not set.
		 */
		return (bool) apply_filters( 'vextra/core/catalog/enabled', $enabled );
	}

	public function register(): void {
		require_once __DIR__ . '/functions.php';

		Counts::register();
		add_action( 'action_scheduler_init', [ self::class, 'schedule_purge' ] );
		add_action( self::PURGE_HOOK, [ self::class, 'purge' ] );
		( new Query() )->register();
		( new Context() )->register();
		( new DiscountMeta() )->register();
		( new CategoryCatalogFields() )->register();

		foreach ( self::PRODUCT_HOOKS as $hook ) {
			add_action( $hook, [ self::class, 'invalidate' ] );
		}
		foreach ( [ 'created_term', 'edited_term', 'delete_term' ] as $hook ) {
			add_action( $hook, [ self::class, 'on_term' ], 10, 3 );
		}
		add_action( 'pll_save_term', [ self::class, 'invalidate' ] );
		foreach ( [ 'woocommerce_attribute_added', 'woocommerce_attribute_updated', 'woocommerce_attribute_deleted' ] as $hook ) {
			add_action( $hook, [ self::class, 'invalidate' ] );
		}
	}

	/**
	 * Daily Action Scheduler job: delete the expired transients (canonical bundles live a week, the rest 10 minutes).
	 */
	public static function schedule_purge(): void {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( $woo instanceof \Vextra\Core\Integrations\WooCommerce\WooCommerce ) {
			$woo->schedule_daily( self::PURGE_HOOK, 'vextra' );
		}
	}

	/**
	 * Purge expired transients (core function; no-op with an external object cache where TTLs expire on their own).
	 */
	public static function purge(): void {
		if ( ! wp_using_ext_object_cache() ) {
			delete_expired_transients( true );
		}
	}

	/**
	 * Mark the count bundles and the term data stale, once per request at shutdown (a bulk import calls this per product).
	 */
	public static function invalidate(): void {
		if ( self::$queued ) {
			return;
		}
		self::$queued = true;
		add_action( 'shutdown', [ self::class, 'flush_now' ], 5 );
	}

	/**
	 * A term changed: only the product taxonomies matter.
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $tt_id    Term taxonomy id.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public static function on_term( $term_id = 0, $tt_id = 0, $taxonomy = '' ): void {
		unset( $term_id, $tt_id );
		if ( is_string( $taxonomy ) && ( 'product_cat' === $taxonomy || 'product_brand' === $taxonomy || str_starts_with( $taxonomy, 'pa_' ) ) ) {
			self::invalidate();
		}
	}

	/**
	 * Flush now (shutdown callback; tests call it directly).
	 */
	public static function flush_now(): void {
		self::$queued = false;
		Cache::flush( Counts::GROUP );
		Cache::flush( Counts::BOUNDED_GROUP );
		TermData::flush();
	}
}
