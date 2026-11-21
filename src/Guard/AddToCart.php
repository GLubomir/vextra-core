<?php
/**
 * SEC-010: GET `?add-to-cart=` (also HEAD, any key case, array value) never touches the cart.
 *
 * `wp_loaded` priority 5 runs before WooCommerce's form handler (priority 20). The request is answered with a 302 to the clean
 * product permalink in the current language (unknown / unpublished id: the language home), `no-store`, no query string.
 * A GET carrying a valid `woocommerce-add-to-cart` nonce in `_wpnonce` passes through (no code of ours creates one; it keeps
 * the door open for signed links). POST add-to-cart (no-JS form) and the Store API are not affected.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;

final class AddToCart {

	public const NONCE_ACTION = 'woocommerce-add-to-cart';

	public function register(): void {
		add_action( 'wp_loaded', [ $this, 'handle' ], 5 );
	}

	public function handle(): void {
		Failsafe::run( 'add-to-cart-post', fn() => $this->limit_post() );
		$target = Failsafe::run( 'add-to-cart', fn(): string => $this->target(), '' );
		if ( '' === $target ) {
			return;
		}
		Responder::log( 'redirect', 'add-to-cart' );
		nocache_headers();
		wp_safe_redirect( $target, 302 );
		exit;
	}

	/**
	 * POST add-to-cart (Woo's own form has no nonce): per-IP bucket, 429 + Retry-After on excess.
	 */
	private function limit_post(): void {
		if ( in_array( Request::method(), [ 'GET', 'HEAD' ], true ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! array_key_exists( 'add-to-cart', $_REQUEST ) || Exempt::is_exempt() ) {
			return;
		}
		$hit = RateLimiter::hit( 'cartpost', ClientIp::get(), Config::get( 'cartpost_limit' ), Config::get( 'cartpost_window' ) );
		if ( ! $hit['allowed'] ) {
			Responder::rate_limited( $hit['retry_after'], Responder::FORMAT_HTML, 'add-to-cart-post' );
		}
	}

	/**
	 * Redirect target, or '' when the request is not a refused GET add-to-cart.
	 */
	private function target(): string {
		if ( ! in_array( Request::method(), [ 'GET', 'HEAD' ], true ) ) {
			return '';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- this IS the check: unsigned GET is refused.
		$value = null;
		foreach ( $_GET as $key => $given ) {
			if ( 'add-to-cart' === strtolower( (string) $key ) ) {
				$value = $given;
				break;
			}
		}
		if ( null === $value ) {
			return '';
		}

		if ( isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] )
			&& false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			return '';
		}
		// phpcs:enable

		$id = is_string( $value ) ? absint( wp_unslash( $value ) ) : 0;
		return $this->permalink( $id );
	}

	/**
	 * Permalink of the product in the current language, else the language home.
	 *
	 * @param int $id Product id from the request (0 = unknown).
	 */
	private function permalink( int $id ): string {
		$pll  = Plugin::instance()->integration( 'polylang' );
		$lang = $pll instanceof Polylang && $pll->is_ready() ? $pll->current_language() : '';

		if ( $id > 0 && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $id );
			if ( $product instanceof \WC_Product ) {
				if ( $product->get_parent_id() > 0 ) {
					$id = $product->get_parent_id();
				}
				if ( '' !== $lang ) {
					$translated = $pll->translate_post( $id, $lang );
					$id         = $translated > 0 ? $translated : $id;
				}
				if ( 'publish' === get_post_status( $id ) ) {
					$url = get_permalink( $id );
					if ( is_string( $url ) && '' !== $url ) {
						return $url;
					}
				}
			}
		}

		return $pll instanceof Polylang && '' !== $lang ? $pll->home_url( $lang ) : home_url( '/' );
	}
}
