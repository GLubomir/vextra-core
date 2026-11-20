<?php
/**
 * Stock propagation between translation twins.
 *
 * Woo changes stock on the ordered product only (checkout reduction, cancel/refund restore, admin edit). The generic hook
 * `woocommerce_product_set_stock` (verified in WC 11.3.0: wc-stock-functions.php and the CPT data store) fires after every
 * stock change; the new quantity + status are pushed to the twins with `wc_update_product_stock( $twin, $qty, 'set' )`,
 * under the shared ProductSync loop guard so nothing re-triggers.
 *
 * Variations (`woocommerce_variation_set_stock`) are out of scope: variation twins have no mapping yet (follow-up task).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Multilang;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Support\Log;

final class StockSync {

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	public function __construct( Polylang $polylang ) {
		$this->polylang = $polylang;
	}

	public function register(): void {
		add_action( 'woocommerce_product_set_stock', [ $this, 'propagate' ] );
		add_action( 'woocommerce_variation_set_stock', [ $this, 'propagate' ] );
	}

	/**
	 * Push quantity + status of the changed product to its twins.
	 *
	 * @param mixed $product The product whose stock changed.
	 */
	public function propagate( $product ): void {
		if ( ProductSync::is_running() || ! $product instanceof \WC_Product || $product->is_type( 'variation' ) ) {
			return;
		}

		$twin_ids = array_values( array_diff( $this->polylang->translations( $product->get_id() ), [ $product->get_id() ] ) );
		if ( array() === $twin_ids ) {
			return;
		}

		$quantity = $product->get_stock_quantity( 'edit' );
		$status   = $product->get_stock_status( 'edit' );
		$managed  = $product->get_manage_stock( 'edit' );

		ProductSync::guarded(
			function () use ( $twin_ids, $quantity, $status, $managed ): void {
				foreach ( $twin_ids as $twin_id ) {
					$twin = wc_get_product( $twin_id );
					if ( ! $twin instanceof \WC_Product || $twin->is_type( 'variable' ) ) {
						continue;
					}
					try {
						if ( $managed && $twin->get_manage_stock( 'edit' ) && null !== $quantity ) {
							if ( $twin->get_stock_quantity( 'edit' ) !== $quantity ) {
								wc_update_product_stock( $twin, $quantity, 'set' );
							}
						} elseif ( $twin->get_stock_status( 'edit' ) !== $status ) {
							$twin->set_stock_status( $status );
							$twin->save();
						}
					} catch ( \Exception $e ) {
						Log::warning( 'Stock sync failed: ' . $e->getMessage(), [ 'twin' => $twin_id ], 'multilang' );
					}
				}
			}
		);
	}
}
