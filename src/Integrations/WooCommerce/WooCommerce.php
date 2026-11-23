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
}
