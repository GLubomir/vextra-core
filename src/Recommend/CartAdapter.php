<?php
/**
 * Woo cross-sell adapter (TASK-038): `woocommerce_cart_crosssell_ids` answers with the engine's `cart` context, so Woo's own cross-sell
 * block (and the E6 drawer) show the ranked, language-correct, in-stock list. Module off = this filter is not registered and Woo's
 * default behaviour is untouched.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend;

use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;

final class CartAdapter {

	public function register(): void {
		add_filter( 'woocommerce_cart_crosssell_ids', [ $this, 'ids' ], 20, 2 );
	}

	/**
	 * Replace Woo's cross-sell id list by the engine's cart list (at most the `cart` limit, never a cart item).
	 *
	 * @param mixed $ids  Woo's ids.
	 * @param mixed $cart The cart.
	 * @return mixed
	 */
	public function ids( $ids, $cart = null ) {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( ! $woo instanceof WooCommerce || ! is_object( $cart ) ) {
			return $ids;
		}
		$products = $woo->cart_product_ids( $cart );
		if ( array() === $products ) {
			return [];
		}
		return array_column( Engine::recommend( 'cart', [ 'cart_ids' => $products ] ), 'id' );
	}
}
