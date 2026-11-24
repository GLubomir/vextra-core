<?php
/**
 * Recommendation attribution (TASK-038): which block led to an add-to-cart, stored on the order line item as `_vx_rec_src`.
 *
 * Input: POST / body param `vx_rec_src` of the classic form / AJAX add-to-cart, or Store API `cart/add-item` body
 * `extensions.vextra.rec_src`. Only the whitelist below is accepted (a string of at most 40 characters); anything else is dropped
 * silently and never breaks the add-to-cart. GET is never read (ADR-007: `?add-to-cart=` stays refused by Guard).
 *
 * The value rides on the cart item OUTSIDE the cart item key: it is added in `woocommerce_add_cart_item` (the key is already
 * computed), so adding one product from two blocks stays ONE line. FIRST source wins: a second add only raises the quantity.
 * `woocommerce_checkout_create_order_line_item` copies it to the order item for both the classic and the Store API checkout.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend;

final class Attribution {

	public const SOURCES = [
		'product_accessories',
		'product_related',
		'product_upsell',
		'cart_crosssell',
		'drawer',
		'category_accessories',
	];

	public const PARAM    = 'vx_rec_src';
	public const ITEM_KEY = 'vx_rec_src';
	public const META     = '_vx_rec_src';
	private const MAX_LEN = 40;

	/**
	 * Source of the Store API add-to-cart being handled ('' = none); set by the route filter, read by the cart item filter.
	 *
	 * @var string|null
	 */
	private static ?string $store_api = null;

	public function register(): void {
		add_filter( 'woocommerce_store_api_add_to_cart_data', [ $this, 'from_store_api' ], 10, 2 );
		add_filter( 'woocommerce_add_cart_item', [ $this, 'tag_item' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'to_order_item' ], 10, 3 );
	}

	/**
	 * Whitelisted source or '' (non-strings, arrays, over-long values and unknown names are dropped).
	 *
	 * @param mixed $value Raw value.
	 */
	public static function clean( $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_LEN ) {
			return '';
		}
		return in_array( $value, self::SOURCES, true ) ? $value : '';
	}

	/**
	 * Store API `cart/add-item`: remember `extensions.vextra.rec_src` for the cart item filter of the same request.
	 *
	 * @param mixed $data    Add-to-cart data (returned unchanged: the source must NOT enter the cart item key).
	 * @param mixed $request REST request.
	 * @return mixed
	 */
	public function from_store_api( $data, $request = null ) {
		self::$store_api = '';
		if ( $request instanceof \WP_REST_Request ) {
			$extensions = $request->get_param( 'extensions' );
			if ( is_array( $extensions ) && is_array( $extensions['vextra'] ?? null ) ) {
				self::$store_api = self::clean( $extensions['vextra']['rec_src'] ?? null );
			}
		}
		return $data;
	}

	/**
	 * A NEW cart line is created: attach the source (classic POST param or the Store API value). The cart key stays untouched.
	 *
	 * @param mixed $item Cart item data.
	 * @param mixed $key  Cart item key.
	 * @return mixed
	 */
	public function tag_item( $item, $key = '' ) {
		unset( $key );
		if ( ! is_array( $item ) ) {
			return $item;
		}
		$src = self::$store_api;
		if ( null === $src ) {
			$src = self::from_post();
		}
		self::$store_api = null;
		if ( '' !== $src ) {
			$item[ self::ITEM_KEY ] = $src;
		}
		return $item;
	}

	/**
	 * Copy the source to the order line item (hidden meta).
	 *
	 * @param mixed $item          Order line item.
	 * @param mixed $cart_item_key Cart item key.
	 * @param mixed $values        Cart item values.
	 */
	public function to_order_item( $item, $cart_item_key = '', $values = [] ): void {
		unset( $cart_item_key );
		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) || ! is_array( $values ) ) {
			return;
		}
		$src = self::clean( $values[ self::ITEM_KEY ] ?? null );
		if ( '' !== $src ) {
			$item->add_meta_data( self::META, $src, true );
		}
	}

	/**
	 * Classic add-to-cart: POST only, never GET.
	 */
	private static function from_post(): string {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo's add-to-cart carries no nonce; the value is whitelisted and only labels a cart line.
		if ( 'POST' !== $method || ! isset( $_POST[ self::PARAM ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- whitelisted by clean().
		return self::clean( is_string( $_POST[ self::PARAM ] ) ? wp_unslash( $_POST[ self::PARAM ] ) : null );
	}
}
