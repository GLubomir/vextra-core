<?php
/**
 * WooCommerce adapter: availability + version, options read on every request, block-type list filter.
 *
 * Performance (TASK-010): `primed_options()` is the list of Woo options that are read one by one on every front request
 * (verified on Woo 11.3.0 with the query log: custom orders table sync, feature flags, catalog, gateway, brands); the
 * Performance module reads them with one `wp_prime_option_caches()` call. `on_block_types()` hooks the documented filter
 * `woocommerce_get_block_types`. Both are registered in docs/compat/integrations.md; a changed name only means a miss.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Integrations\WooCommerce;

use Vextra\Core\Integrations\AbstractIntegration;

final class WooCommerce extends AbstractIntegration {

	/**
	 * Options Woo reads singly on every request (non-autoloaded or missing). Order irrelevant.
	 */
	private const PRIMED_OPTIONS = [
		'pickup_location_pickup_locations',
		'wc_brands_show_description',
		'wc_feature_woocommerce_additional_variation_images_enabled',
		'wc_feature_woocommerce_brands_enabled',
		'wc_pending_batch_processes',
		'woocommerce_address_autocomplete_provider',
		'woocommerce_bacs_settings',
		'woocommerce_brand_permalink',
		'woocommerce_catalog_columns',
		'woocommerce_catalog_rows',
		'woocommerce_custom_orders_table_background_sync_mode',
		'woocommerce_custom_orders_table_data_sync_enabled',
		'woocommerce_default_catalog_orderby',
		'woocommerce_demo_store',
		'woocommerce_enable_delayed_account_creation',
		'woocommerce_enable_guest_checkout',
		'woocommerce_enable_myaccount_registration',
		'woocommerce_hooked_blocks_version',
		'woocommerce_notify_low_stock_amount',
		'woocommerce_registration_generate_password',
		'woocommerce_show_marketplace_suggestions',
		'woocommerce_tax_total_display',
		'woocommerce_thumbnail_cropping',
	];

	public function id(): string {
		return 'woocommerce';
	}

	protected function detect(): bool {
		return class_exists( 'WooCommerce' );
	}

	public function version(): ?string {
		return defined( 'WC_VERSION' ) ? (string) WC_VERSION : null;
	}

	/**
	 * Options Woo reads singly on every front request.
	 *
	 * @return string[]
	 */
	public function primed_options(): array {
		return self::PRIMED_OPTIONS;
	}

	/**
	 * Filter the Woo block type class list (runs when Woo registers its blocks, on `init`).
	 *
	 * @param callable $callback Receives and returns the list of block type class names (e.g. `ProductCollection`).
	 */
	public function on_block_types( callable $callback ): void {
		add_filter( 'woocommerce_get_block_types', $callback, 99 );
	}

	/**
	 * Ids of the products on sale (`wc_get_product_ids_on_sale`, cached by Woo in a transient). Includes variable parents with
	 * sale children and the ids of every language. Fallback: [].
	 *
	 * @return int[]
	 */
	public function sale_product_ids(): array {
		if ( ! $this->is_available() || ! function_exists( 'wc_get_product_ids_on_sale' ) ) {
			return [];
		}
		return array_values( array_filter( array_map( 'absint', (array) wc_get_product_ids_on_sale() ) ) );
	}

	/**
	 * Whether Action Scheduler (bundled with Woo) can enqueue jobs. Fallback: false.
	 */
	public function has_action_scheduler(): bool {
		return $this->is_available() && function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Enqueue ONE async Action Scheduler job: no new job while an identical one is pending. True also when it is already pending.
	 * Fallback: false.
	 *
	 * @param string            $hook  Action hook.
	 * @param array<int,string> $args  Job args (scalars only).
	 * @param string            $group Action Scheduler group.
	 */
	public function enqueue_async_action( string $hook, array $args, string $group ): bool {
		if ( ! $this->has_action_scheduler() ) {
			return false;
		}
		if ( as_has_scheduled_action( $hook, $args, $group ) ) {
			return true;
		}
		return 0 !== (int) as_enqueue_async_action( $hook, $args, $group );
	}

	/**
	 * Drop Woo's `the_posts` priming filter of the variable-product transients: the catalog template reads them for exactly its cards
	 * (`ProductCardData::prime`), so Woo's pass would repeat the query (its thumbnail priming stays: it runs first and the card images need it). Public WP hook API on
	 * Woo's query object; absent object / callbacks = nothing removed (the repeat costs queries, nothing breaks).
	 */
	public function drop_loop_priming(): void {
		if ( ! $this->is_available() || ! function_exists( 'WC' ) ) {
			return;
		}
		$query = WC()->query ?? null;
		if ( is_object( $query ) ) {
			remove_filter( 'the_posts', [ $query, 'prime_variable_products_transients' ], 10 );
		}
	}

	/**
	 * Register a daily recurring Action Scheduler job once (no-op when it exists or Action Scheduler is missing).
	 *
	 * @param string $hook  Action hook.
	 * @param string $group Action Scheduler group.
	 */
	public function schedule_daily( string $hook, string $group ): void {
		if ( ! $this->has_action_scheduler() || ! function_exists( 'as_schedule_recurring_action' ) || as_has_scheduled_action( $hook, [], $group ) ) {
			return;
		}
		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, $hook, [], $group );
	}

	/**
	 * Guarded SQL adapter over Woo's product lookup table (the only direct SQL of the catalog module). Never null; every method
	 * answers an empty value when the table is missing.
	 */
	public function lookup(): Lookup {
		return new Lookup();
	}

	/**
	 * The ids of a list that are on sale RIGHT NOW (`WC_Product::is_on_sale()` honours the sale schedule, so a sale whose end
	 * date has passed but whose lookup row is stale is dropped). Products are loaded through CRUD (caches primed in one query per 200). Fallback: [].
	 *
	 * @param int[] $ids Product ids.
	 * @return int[]
	 */
	public function on_sale_now( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( array() === $ids || ! $this->is_available() || ! function_exists( 'wc_get_product' ) ) {
			return [];
		}
		$out = [];
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			// One query primes the post and meta caches of the chunk. NOT wc_get_products(): Polylang rewrites `include` / `post__in`
			// to the translations in the CURRENT language, which would swap the ids.
			new \WP_Query(
				[
					'post__in'            => $chunk,
					'post_type'           => 'product',
					'post_status'         => 'publish',
					'posts_per_page'      => count( $chunk ),
					'orderby'             => 'none',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
					'lang'                => '',
					'suppress_filters'    => true,
				]
			);
			foreach ( $chunk as $id ) {
				$product = wc_get_product( $id );
				if ( $product instanceof \WC_Product && 'publish' === $product->get_status() && $product->is_on_sale() ) {
					$out[] = (int) $product->get_id();
				}
			}
		}
		return $out;
	}
}
