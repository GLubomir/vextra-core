<?php
/**
 * Cart count cookie `vx_cart` (TASK-045a, design `screens/cart-checkout.md` s9): the header badge reads it, so a browsing page needs no
 * `GET /wc/store/v1/cart` and no session round trip.
 *
 * Contract:
 * - Value = integer item count only (`WC_Cart::get_cart_contents_count()`), no PII. Non-HttpOnly (the header JS reads it),
 *   `SameSite=Lax`, `Secure` on HTTPS, session lifetime, path `COOKIEPATH`. Count 0 expires the cookie.
 * - DISPLAY-ONLY: the server never reads it to decide anything (the cart lives in the Woo session). Page HTML never depends on it
 *   (full-page caches must not vary on it, they bypass on Woo's own cookies).
 * - Written on cart mutations: `woocommerce_add_to_cart`, `woocommerce_cart_item_removed`, `woocommerce_after_cart_item_quantity_update`,
 *   `woocommerce_cart_emptied`, `woocommerce_cart_item_restored` (these also fire inside Store API requests, so the response carries
 *   the header), `wp_login` (cart merge). Plus a self-heal for a SIGNED-IN visitor: when Woo has loaded the cart from the session and the cookie
 *   differs (a merge, another device), the cookie is corrected.
 * - NEVER on an anonymous GET / HEAD, never under WP-CLI, cron, admin screens or once headers are sent; never creates a Woo session
 *   (it only reads the cart object that already exists).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Cart;

use Vextra\Core\Guard\Request;

final class CountCookie {

	public const NAME = 'vx_cart';

	/**
	 * Last value written in this request (multiple events of one request write once per distinct count).
	 *
	 * @var int|null
	 */
	private static ?int $last = null;

	public function register(): void {
		foreach ( [ 'woocommerce_add_to_cart', 'woocommerce_cart_item_removed', 'woocommerce_after_cart_item_quantity_update', 'woocommerce_cart_emptied', 'woocommerce_cart_item_restored', 'wp_login' ] as $hook ) {
			add_action( $hook, [ $this, 'sync' ], 20 );
		}
		add_action( 'woocommerce_cart_loaded_from_session', [ $this, 'heal' ], 20 );
	}

	/**
	 * A cart mutation happened: write the count (any hook argument is ignored).
	 */
	public function sync(): void {
		if ( ! $this->may_write( false ) ) {
			return;
		}
		$count = $this->count();
		if ( null === $count ) {
			return;
		}
		$this->write( $count );
	}

	/**
	 * Woo loaded the cart from the session: a signed-in visitor's stale cookie is corrected (anonymous visitors never).
	 */
	public function heal(): void {
		if ( ! is_user_logged_in() || ! $this->may_write( true ) ) {
			return;
		}
		$count = $this->count();
		if ( null === $count ) {
			return;
		}
		$have = isset( $_COOKIE[ self::NAME ] ) && is_string( $_COOKIE[ self::NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::NAME ] ) ) : '';
		if ( ( 0 === $count && '' === $have ) || (string) $count === $have ) {
			return;
		}
		$this->write( $count );
	}

	/**
	 * Environment gate.
	 *
	 * @param bool $allow_get True lets a GET through (the signed-in self-heal).
	 */
	private function may_write( bool $allow_get ): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || ( is_admin() && ! wp_doing_ajax() ) || headers_sent() ) {
			return false;
		}
		if ( in_array( Request::method(), [ 'GET', 'HEAD', 'OPTIONS' ], true ) ) {
			return $allow_get;
		}
		return true;
	}

	/**
	 * Item count of the cart that already exists (null when Woo has no cart object: nothing is created here).
	 */
	private function count(): ?int {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$cart = WC()->cart ?? null;
		if ( ! $cart instanceof \WC_Cart ) {
			return null;
		}
		return max( 0, (int) $cart->get_cart_contents_count() );
	}

	/**
	 * Send (or expire) the cookie; a stale `vx_cart` Set-Cookie line of this request is replaced.
	 *
	 * @param int $count Item count.
	 */
	private function write( int $count ): void {
		if ( self::$last === $count ) {
			return;
		}
		if ( 0 === $count && ! isset( $_COOKIE[ self::NAME ] ) && null === self::$last ) {
			return; // Nothing to expire.
		}
		self::$last = $count;
		$this->drop_pending();

		setcookie(
			self::NAME,
			0 === $count ? '' : (string) $count,
			[
				'expires'  => 0 === $count ? time() - YEAR_IN_SECONDS : 0,
				'path'     => defined( 'COOKIEPATH' ) ? (string) COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			]
		);
	}

	/**
	 * Remove an earlier `vx_cart` Set-Cookie header of this request (PHP appends cookie headers; browsers keep the last, curl shows both).
	 */
	private function drop_pending(): void {
		$lines = headers_list();
		$found = false;
		foreach ( $lines as $line ) {
			if ( 0 === stripos( $line, 'Set-Cookie: ' . self::NAME . '=' ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return;
		}
		header_remove( 'Set-Cookie' );
		foreach ( $lines as $line ) {
			if ( 0 === stripos( $line, 'Set-Cookie:' ) && 0 !== stripos( $line, 'Set-Cookie: ' . self::NAME . '=' ) ) {
				header( $line, false );
			}
		}
	}
}
