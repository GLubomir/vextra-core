<?php
/**
 * Order privacy (TASK-045a): no order data in any cached output, masked e-mail on the thank-you page, noindex answers.
 *
 * - Cart, checkout, order-received, order-pay and the account pages answer `Cache-Control: private, no-store, max-age=0` (+ `nocache_headers()`, `DONOTCACHEPAGE`),
 *   and `X-Robots-Tag: noindex` when no such header is set yet (Rank Math prints the robots META only, never the header: checked, no duplicate output).
 * - Woo's `place-order-debug` log (full order data) is switched off through `woocommerce_order_step_logging_enabled` (filter `vextra/core/checkout/order_step_logging` re-enables it).
 * - `vx_mask_email()` (functions.php): the only form of the customer's e-mail the thank-you markup may print (`o***@example.com`).
 * - Order-received with a missing / wrong order key: HTTP 200, no order data (verified); the sentence says "order not found" (`woocommerce_thankyou_order_received_text`).
 *   It also keeps the Woo legal pages (terms / privacy) language-correct: `woocommerce_privacy_policy_page_id` is mapped to the page of the current language.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Checkout;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;

final class Privacy {

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'headers' ], 20 );
		add_filter( 'woocommerce_privacy_policy_page_id', [ $this, 'privacy_page' ] );
		add_filter( 'woocommerce_order_step_logging_enabled', [ $this, 'order_step_logging' ] );
		add_filter( 'woocommerce_thankyou_order_received_text', [ $this, 'not_found_text' ], 20, 2 );
	}

	/**
	 * Order-received page without a valid order (wrong / missing key): a plain "not found" sentence instead of Woo's "Thank you, your order has been
	 * received" (HTTP 200, `noindex`, no order data on the page).
	 *
	 * @param mixed $text  Woo's text.
	 * @param mixed $order The order (false / null when the key is invalid).
	 * @return mixed
	 */
	public function not_found_text( $text, $order = null ) {
		if ( $order instanceof \WC_Order ) {
			return $text;
		}
		// A valid key opened by a visitor Woo cannot identify gets Woo's "verify the e-mail" form below this sentence (no order data before that).
		$order_id = function_exists( 'get_query_var' ) ? absint( get_query_var( 'order-received' ) ) : 0;
		$key      = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compared with hash_equals, never output.
		$real     = $order_id > 0 && '' !== $key ? wc_get_order( $order_id ) : false;
		if ( $real instanceof \WC_Order && is_string( $key ) && hash_equals( (string) $real->get_order_key(), $key ) ) {
			return __( 'Підтвердіть e-mail замовлення, щоб побачити його деталі.', 'vextra-core' );
		}
		return __( 'Замовлення не знайдено. Перевірте посилання з листа або зверніться до нас.', 'vextra-core' );
	}

	/**
	 * WooCommerce's place-order step logger writes the whole order (billing e-mail, phone, name) to `wc-logs/place-order-debug-*`: off by default
	 * (no PII in logs). Filter `vextra/core/checkout/order_step_logging` switches it back on for a debugging session.
	 *
	 * @param mixed $enabled Woo's default (true).
	 */
	public function order_step_logging( $enabled ): bool {
		unset( $enabled );

		/**
		 * Filters whether Woo's place-order step logging (contains customer data) stays on.
		 *
		 * @param bool $on Default false.
		 */
		return (bool) apply_filters( 'vextra/core/checkout/order_step_logging', false );
	}

	/**
	 * `o***@example.com` / `a***@x.ua`: first character of the local part + `***` + the domain; not an address: ''.
	 *
	 * @param string $email E-mail.
	 */
	public static function mask_email( string $email ): string {
		$email = trim( $email );
		$at    = strrpos( $email, '@' );
		if ( false === $at || 1 > $at || strlen( $email ) - 1 === $at ) {
			return '';
		}
		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );
		if ( 1 !== preg_match( '/^[^\s@]+$/', $local ) || 1 !== preg_match( '/^[^\s@]+\.[^\s@]+$/', $domain ) ) {
			return '';
		}
		$first = function_exists( 'mb_substr' ) ? mb_substr( $local, 0, 1 ) : substr( $local, 0, 1 );
		return $first . '***@' . $domain;
	}

	/**
	 * Private, no-store headers for the money pages.
	 */
	public function headers(): void {
		if ( headers_sent() || ! $this->is_private_page() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- de-facto standard constant read by page caches.
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		foreach ( headers_list() as $line ) {
			if ( 0 === stripos( $line, 'X-Robots-Tag:' ) ) {
				return;
			}
		}
		header( 'X-Robots-Tag: noindex' );
	}

	/**
	 * Privacy page of the current language (WordPress stores one id: the default-language page).
	 *
	 * @param mixed $page_id Page id.
	 * @return mixed
	 */
	public function privacy_page( $page_id ) {
		$id  = (int) $page_id;
		$pll = Plugin::instance()->integration( 'polylang' );
		if ( $id <= 0 || ! $pll instanceof Polylang || ! $pll->is_ready() || is_admin() ) {
			return $page_id;
		}
		$lang = $pll->current_language();
		if ( '' === $lang ) {
			return $page_id;
		}
		$twin = $pll->translate_post( $id, $lang );
		return $twin > 0 ? $twin : $page_id;
	}

	private function is_private_page(): bool {
		return ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );
	}
}
