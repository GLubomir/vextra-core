<?php
/**
 * Generic "not enough stock" messages (TASK-044a fix round 3, SEC-070): Woo's cart messages print the exact remaining stock ("залишилось 8"),
 * which would undo the rule that exact stock is public only when low. Above the low-stock threshold (the product's own, else the store's)
 * the message carries no number; at or below it Woo's text with the number stays.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Product;

use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;

final class StockMessages {

	public function register(): void {
		add_filter( 'woocommerce_cart_product_not_enough_stock_message', [ $this, 'filter' ], 20, 3 );
		add_filter( 'woocommerce_cart_product_not_enough_stock_already_in_cart_message', [ $this, 'filter' ], 20, 3 );
	}

	/**
	 * Replace the message when the remaining stock is above the low-stock threshold.
	 *
	 * @param mixed $message Woo message.
	 * @param mixed $product Product.
	 * @param mixed $stock   Remaining quantity.
	 * @return mixed
	 */
	public function filter( $message, $product = null, $stock = 0 ) {
		if ( ! $product instanceof \WC_Product ) {
			return $message;
		}
		$own = $product->get_low_stock_amount();
		$woo = Plugin::instance()->integration( 'woocommerce' );
		$max = '' !== $own && null !== $own ? (int) $own : ( $woo instanceof WooCommerce ? $woo->low_stock_threshold() : 2 );
		if ( (int) $stock <= $max ) {
			return $message;
		}
		return __( 'На складі недостатньо цього товару. Зменште кількість і спробуйте ще раз.', 'vextra-core' );
	}
}
