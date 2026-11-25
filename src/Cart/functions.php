<?php
/**
 * Public functions of the Cart module for the theme (TASK-045a). The theme wraps every call in `function_exists`.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

use Vextra\Core\Cart\ResourceHints;

if ( ! function_exists( 'vx_cart_checkout_hints' ) ) {
	/**
	 * Prefetch entries of the checkout bundle for the cart page (the list WooCommerce prints on every page and the Cart module strips):
	 * `[ [ href, as ] ]`, empty on every other page and for an empty cart. The theme injects `<link rel="prefetch">` on intent
	 * (pointer enter / focus of the checkout button), never at load.
	 *
	 * @return array<int,array{href:string,as:string}>
	 */
	function vx_cart_checkout_hints(): array {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return [];
		}
		if ( array() === ResourceHints::cart_hints() ) {
			// Hints not printed yet (called before wp_head): run the filter chain once, Woo's list passes through ours and is captured.
			apply_filters( 'wp_resource_hints', [], 'prefetch' );
		}
		return ResourceHints::cart_hints();
	}
}
