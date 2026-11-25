<?php
/**
 * Resource hints of the cart / checkout blocks (TASK-045a, design `screens/cart-checkout.md` s9, ADR-004 / ADR-005).
 *
 * WooCommerce's `AssetsController::add_resource_hints` (filter `wp_resource_hints`, priority 10) prints `<link rel="prefetch">` for
 * every cart / checkout block script (and the WordPress script dependencies they need) whenever the cart is not empty, plus a
 * `prerender` of the checkout page on the cart page: 54 idle downloads on every browsing page. This class is instance-free (a filter at
 * priority 99, never `remove_filter` on Woo's controller, which needs its container instance):
 *
 * - `prerender`: every entry dropped on every page (checkout is never prerendered, ADR-005).
 * - `prefetch`: when ANY entry lives under `/plugins/woocommerce/assets/client/blocks/` the whole Woo list is dropped, i.e. those entries AND the
 *   `/plugins/woocommerce/assets/client/` helper scripts (admin/sanitize) and `/wp-includes/js/dist/` dependency entries Woo adds next to them (no other plugin on
 *   this site prints prefetch hints; a prefetch list without a Woo block entry is never touched). `preconnect` / `dns-prefetch` are never touched.
 * - On the cart page the dropped prefetch list is kept (this request only) for `vx_cart_checkout_hints()`: the theme injects it
 *   on intent (hover / focus of the checkout button). Empty on every other page.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Cart;

final class ResourceHints {

	public const BLOCKS_PATH  = '/plugins/woocommerce/assets/client/blocks/';
	private const CLIENT_PATH = '/plugins/woocommerce/assets/client/';
	private const DEPS_PATH   = '/wp-includes/js/dist/';

	/**
	 * Prefetch entries stripped on the cart page of this request: `[ [ href, as ] ]`.
	 *
	 * @var array<int,array{href:string,as:string}>
	 */
	private static array $cart_hints = [];

	public function register(): void {
		add_filter( 'wp_resource_hints', [ $this, 'filter' ], 99, 2 );
	}

	/**
	 * Hints captured on the cart page (empty elsewhere).
	 *
	 * @return array<int,array{href:string,as:string}>
	 */
	public static function cart_hints(): array {
		return self::$cart_hints;
	}

	/**
	 * `wp_resource_hints` at priority 99.
	 *
	 * @param mixed $urls          Hint entries (URL strings or attribute arrays).
	 * @param mixed $relation_type `dns-prefetch`, `preconnect`, `prefetch` or `prerender`.
	 * @return mixed
	 */
	public function filter( $urls, $relation_type = '' ) {
		if ( ! is_array( $urls ) || ! is_string( $relation_type ) || is_admin() ) {
			return $urls;
		}
		if ( 'prerender' === $relation_type ) {
			return [];
		}
		if ( 'prefetch' !== $relation_type || array() === $urls ) {
			return $urls;
		}

		$has_blocks = false;
		foreach ( $urls as $entry ) {
			if ( str_contains( self::href( $entry ), self::BLOCKS_PATH ) ) {
				$has_blocks = true;
				break;
			}
		}
		if ( ! $has_blocks ) {
			return $urls;
		}

		$keep    = [];
		$dropped = [];
		foreach ( $urls as $entry ) {
			$href = self::href( $entry );
			if ( str_contains( $href, self::CLIENT_PATH ) || str_contains( $href, self::DEPS_PATH ) ) {
				$dropped[] = [
					'href' => $href,
					'as'   => self::as_of( $entry, $href ),
				];
				continue;
			}
			$keep[] = $entry;
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			self::$cart_hints = $dropped;
		}
		return $keep;
	}

	/**
	 * Href of a hint entry (string or attribute array).
	 *
	 * @param mixed $entry Entry.
	 */
	private static function href( $entry ): string {
		if ( is_array( $entry ) ) {
			return isset( $entry['href'] ) && is_string( $entry['href'] ) ? $entry['href'] : '';
		}
		return is_string( $entry ) ? $entry : '';
	}

	/**
	 * `as` of an entry: its own attribute, else guessed from the file extension.
	 *
	 * @param mixed  $entry Entry.
	 * @param string $href  Its href.
	 */
	private static function as_of( $entry, string $href ): string {
		if ( is_array( $entry ) && isset( $entry['as'] ) && is_string( $entry['as'] ) && '' !== $entry['as'] ) {
			return sanitize_key( $entry['as'] );
		}
		$path = (string) wp_parse_url( $href, PHP_URL_PATH );
		return str_ends_with( $path, '.css' ) ? 'style' : 'script';
	}
}
