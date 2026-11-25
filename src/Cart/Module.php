<?php
/**
 * Cart module (TASK-045a, E6): the server half of the cart experience that does not depend on the theme.
 *
 * - `ResourceHints`: strips WooCommerce's cart / checkout block `prefetch` and every `prerender` hint from every page
 *   (54 idle downloads per browsing page of a shopper with a non-empty cart, ADR-004 / ADR-005); the cart page exposes the stripped
 *   list through `vx_cart_checkout_hints()` so the theme can prefetch the checkout bundle on intent.
 * - `CountCookie`: display-only cookie `vx_cart=<n>` (item count) written on cart mutations, read by the header JS.
 * - `Cli`: `wp vextra cart apply-config` (Woo guest checkout / signup / coupons / pages / legal pages, idempotent).
 *
 * Cross-sells: `Recommend\CartAdapter` already answers `woocommerce_cart_crosssell_ids` with the engine (no second code path here).
 * Kill switch: constant `VX_CART_DISABLED` or filter `vextra/core/cart/enabled`. A duplicate cart plugin only warns
 * (`Compat\Conflicts` rule `duplicate-cart-plugin`). Docs: docs/system/modules/cart-checkout.md (theme repo).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Cart;

use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Plugin;

final class Module implements ModuleInterface {

	public function id(): string {
		return 'cart';
	}

	public function is_enabled(): bool {
		$woo     = Plugin::instance()->integration( 'woocommerce' );
		$enabled = null !== $woo && $woo->is_available() && ! ( defined( 'VX_CART_DISABLED' ) && VX_CART_DISABLED );

		/**
		 * Feature flag of the Cart module.
		 *
		 * @param bool $enabled Default: WooCommerce available and VX_CART_DISABLED not set.
		 */
		return (bool) apply_filters( 'vextra/core/cart/enabled', $enabled );
	}

	public function register(): void {
		require_once __DIR__ . '/functions.php';

		( new ResourceHints() )->register();
		( new CountCookie() )->register();
		( new VariationNames() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new Cli() )->register();
		}
	}
}
