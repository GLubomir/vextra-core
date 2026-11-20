<?php
/**
 * Stock propagation between translation twins (SEC-029: no oversell race).
 *
 * Woo changes stock on the ordered product only. Two paths:
 * - Order paths (`wc_update_product_stock` with `decrease` / `increase`): the signed quantity is captured from the atomic
 *   SQL through filter `woocommerce_update_product_stock_query` and applied to every twin with the same atomic
 *   `wc_update_product_stock( $twin, $qty, 'decrease'|'increase' )`. No absolute value from memory is ever written.
 * - Manual edits (`set` semantics, product save): after `woocommerce_product_set_stock` the source `_stock` is re-read
 *   from the database and written to the twins with `set`.
 *
 * Everything under the shared ProductSync loop guard. Variations are out of scope (no variation mapping yet).
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

	/**
	 * Signed quantity captured per product id, consumed by propagate().
	 *
	 * @var array<int,float>
	 */
	private array $pending = [];

	public function __construct( Polylang $polylang ) {
		$this->polylang = $polylang;
	}

	public function register(): void {
		add_filter( 'woocommerce_update_product_stock_query', [ $this, 'capture_delta' ], 10, 4 );
		add_action( 'woocommerce_product_set_stock', [ $this, 'propagate' ] );
		add_action( 'woocommerce_variation_set_stock', [ $this, 'propagate' ] );
	}

	/**
	 * Remember the signed quantity of an atomic increase/decrease (read-only filter, SQL is returned untouched).
	 *
	 * @param mixed  $sql        Stock UPDATE statement.
	 * @param int    $product_id Product holding the stock.
	 * @param mixed  $new_stock  Computed new stock (unused).
	 * @param string $operation  set|increase|decrease.
	 * @return mixed
	 */
	public function capture_delta( $sql, $product_id, $new_stock, $operation ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ProductSync::is_running() || 'set' === $operation || ! is_string( $sql ) ) {
			return $sql;
		}
		if ( 1 === preg_match( '/meta_value\s*([+-])\s*([0-9]+(?:\.[0-9]+)?)\s+WHERE/', $sql, $m ) ) {
			$delta                              = (float) $m[2] * ( '-' === $m[1] ? -1 : 1 );
			$this->pending[ (int) $product_id ] = ( $this->pending[ (int) $product_id ] ?? 0.0 ) + $delta;
		}
		return $sql;
	}

	/**
	 * Push the change of the product to its twins.
	 *
	 * @param mixed $product The product whose stock changed.
	 */
	public function propagate( $product ): void {
		if ( ProductSync::is_running() || ! $product instanceof \WC_Product || $product->is_type( 'variation' ) ) {
			return;
		}

		$id       = $product->get_id();
		$twin_ids = array_values( array_diff( $this->polylang->translations( $id ), [ $id ] ) );
		$delta    = $this->pending[ $id ] ?? null;
		unset( $this->pending[ $id ] );
		if ( array() === $twin_ids ) {
			return;
		}

		$managed = $product->get_manage_stock( 'edit' );
		$fresh   = $managed && null === $delta ? $this->stored_stock( $id ) : null;

		ProductSync::guarded(
			function () use ( $twin_ids, $managed, $delta, $fresh, $product ): void {
				foreach ( $twin_ids as $twin_id ) {
					$twin = wc_get_product( $twin_id );
					if ( ! $twin instanceof \WC_Product || $twin->is_type( 'variable' ) ) {
						continue;
					}
					try {
						if ( $managed && $twin->get_manage_stock( 'edit' ) ) {
							if ( null !== $delta ) {
								if ( 0.0 !== $delta ) {
									wc_update_product_stock( $twin, abs( $delta ), $delta < 0 ? 'decrease' : 'increase' );
								}
							} elseif ( null !== $fresh ) {
								wc_update_product_stock( $twin, $fresh, 'set' );
							}
						} elseif ( $twin->get_stock_status( 'edit' ) !== $product->get_stock_status( 'edit' ) ) {
							$twin->set_stock_status( $product->get_stock_status( 'edit' ) );
							$twin->save();
						}
					} catch ( \Exception $e ) {
						Log::warning( 'Stock sync failed: ' . $e->getMessage(), [ 'twin' => $twin_id ], 'multilang' );
					}
				}
			}
		);
	}

	/**
	 * Current stock straight from the database (not the in-memory object).
	 *
	 * @param int $product_id Product ID.
	 */
	private function stored_stock( int $product_id ): ?float {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- fresh read on purpose, the object cache may be stale.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'", $product_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		return null === $value ? null : (float) $value;
	}
}
