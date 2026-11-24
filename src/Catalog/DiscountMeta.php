<?php
/**
 * `_vx_discount_pct` (TASK-043b): the integer discount percent (0-99) of a product, the data behind the sale sort and the
 * "Знижка" facet. Variable products carry the MAX over their variations.
 *
 * Recomputed on product save (`woocommerce_new_product` / `woocommerce_update_product`; Woo's scheduled-sales cron saves the products
 * whose sale starts or ends, so schedules are covered), on variation save (the parent is recomputed) and through the action
 * `vextra/catalog/recompute_discount( $product_id )` which the supplier sync (E7) fires after it writes prices. Idempotent: the meta
 * is written only when the value changes, a non-sale product that never had the meta gets none. Written through the product CRUD
 * (`update_meta_data` + `save_meta_data`, which fires no product save hook, so there is no recursion).
 * The module flag option `vx_catalog_discount_ready` is raised the first time a value > 0 exists: until then the discount sort and the
 * "Знижка" facet are not offered (backend flag 2 of the screen spec).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

final class DiscountMeta {

	public const META = '_vx_discount_pct';

	public const READY_OPTION = 'vx_catalog_discount_ready';

	/**
	 * Variations inspected per variable product (a safety cap, real products have a handful).
	 */
	private const MAX_VARIATIONS = 100;

	public function register(): void {
		add_action( 'woocommerce_new_product', [ $this, 'on_product' ], 40 );
		add_action( 'woocommerce_update_product', [ $this, 'on_product' ], 40 );
		add_action( 'woocommerce_save_product_variation', [ $this, 'on_variation' ], 40 );
		add_action( 'vextra/catalog/recompute_discount', [ self::class, 'recompute' ] );
	}

	/**
	 * Has any product a discount stored? (autoloaded option, no query).
	 */
	public static function ready(): bool {
		return (bool) get_option( self::READY_OPTION, 0 );
	}

	/**
	 * Product saved.
	 *
	 * @param mixed $product_id Product id.
	 */
	public function on_product( $product_id ): void {
		self::recompute( (int) $product_id );
	}

	/**
	 * Variation saved: recompute the parent.
	 *
	 * @param mixed $variation_id Variation id.
	 */
	public function on_variation( $variation_id ): void {
		$parent = (int) wp_get_post_parent_id( (int) $variation_id );
		if ( $parent > 0 ) {
			self::recompute( $parent );
		}
	}

	/**
	 * Recompute and store the meta of one product. Returns the stored value, or null when nothing was written.
	 *
	 * @param int  $product_id Product (or variation: its parent is used) id.
	 * @param bool $dry        Compute only.
	 */
	public static function recompute( int $product_id, bool $dry = false ): ?int {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		if ( $product instanceof \WC_Product_Variation ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$value   = self::compute( $product );
		$stored  = $product->get_meta( self::META, true );
		$has     = '' !== $stored && null !== $stored;
		$changed = $has ? (int) $stored !== $value : $value > 0;
		if ( ! $changed ) {
			return null;
		}
		if ( ! $dry ) {
			$product->update_meta_data( self::META, $value );
			$product->save_meta_data();
			if ( $value > 0 && ! self::ready() ) {
				update_option( self::READY_OPTION, 1, true );
			}
		}
		return $value;
	}

	/**
	 * Discount percent of a product (variable: max over variations). 0 = not on sale now.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function compute( \WC_Product $product ): int {
		if ( $product instanceof \WC_Product_Variable ) {
			$best = 0;
			foreach ( array_slice( (array) $product->get_children(), 0, self::MAX_VARIATIONS ) as $child_id ) {
				$child = wc_get_product( (int) $child_id );
				if ( $child instanceof \WC_Product && 'publish' === $child->get_status() ) {
					$best = max( $best, self::single( $child ) );
				}
			}
			return $best;
		}
		return self::single( $product );
	}

	/**
	 * Percent of one (simple or variation) product.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function single( \WC_Product $product ): int {
		if ( ! $product->is_on_sale() ) { // Honours the sale schedule: an expired sale is 0.
			return 0;
		}
		$regular = (float) $product->get_regular_price();
		$current = (float) $product->get_price();
		if ( $regular <= 0 || $current >= $regular || $current < 0 ) {
			return 0;
		}
		return max( 0, min( 99, (int) round( ( $regular - $current ) / $regular * 100 ) ) );
	}
}
