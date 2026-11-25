<?php
/**
 * Cart / checkout strings that WooCommerce does not (or wrongly) translate (TASK-045a, F5 / F10). msgids are Ukrainian (`vextra-core` text domain),
 * the Russian text lives in `languages/vextra-core-ru_RU.po`.
 *
 * - PHP side: `gettext` (domain `woocommerce`, front end only) for the few strings the server prints: the empty-cart title (stored block pattern text),
 *   the classic buttons, the legal link labels of the terms line.
 * - Block (JS) side: Woo's cart / checkout blocks read their text from `wp.i18n` (domain `woocommerce`). A short `setLocaleData()` snippet is attached
 *   BEFORE the frontend entry script of the cart / checkout page only (so it overrides Woo's JSON translations): totals labels ("Разом без доставки" on the
 *   cart, "До сплати" at checkout), "Економія", the guest note, the no-rate shipping line, the CTA labels. The same words as the design docs.
 * Nothing runs on browsing pages (the snippet is enqueued on the cart / checkout only).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Checkout;

final class Strings {

	/**
	 * Handles already given the snippet in this request.
	 *
	 * @var string[]
	 */
	private array $done = [];

	public function register(): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		add_filter( 'gettext', [ $this, 'gettext' ], 20, 3 );
		add_filter( 'woocommerce_get_privacy_policy_text', [ $this, 'privacy_text' ], 20, 2 );
		add_filter( 'woocommerce_get_terms_and_conditions_checkbox_text', [ $this, 'terms_checkbox_text' ], 20 );
		// Woo registers + enqueues the block frontend scripts while the block renders (after wp_enqueue_scripts): attach the override right after.
		add_filter( 'render_block_woocommerce/cart', [ $this, 'after_cart_block' ], 20 );
		add_filter( 'render_block_woocommerce/checkout', [ $this, 'after_checkout_block' ], 20 );
	}

	/**
	 * PHP strings of domain `woocommerce` (exact msgid match, no regex, one array lookup).
	 *
	 * @param mixed $translation Current translation.
	 * @param mixed $text        Source text.
	 * @param mixed $domain      Text domain.
	 * @return mixed
	 */
	public function gettext( $translation, $text = '', $domain = '' ) {
		if ( 'woocommerce' !== $domain || ! is_string( $text ) ) {
			return $translation;
		}
		switch ( $text ) {
			case 'Your cart is empty':
				return __( 'Ваш кошик порожній', 'vextra-core' );
			case 'terms and conditions':
				return __( 'публічною офертою', 'vextra-core' );
			case 'privacy policy':
				return __( 'політикою конфіденційності', 'vextra-core' );
			case 'Proceed to checkout':
				return __( 'Перейти до оформлення', 'vextra-core' );
			case 'Place order':
				return __( 'Підтвердити замовлення', 'vextra-core' );
		}
		return $translation;
	}

	/**
	 * Privacy sentence of the checkout / order-pay / registration forms: the stored option holds Woo's English default, so it is swapped for the
	 * translated sentence as long as nobody edited it (an owner-written text is never touched).
	 *
	 * @param mixed $text Text from the option.
	 * @param mixed $type `checkout` or `registration`.
	 * @return mixed
	 */
	public function privacy_text( $text, $type = '' ) {
		if ( ! is_string( $text ) ) {
			return $text;
		}
		$english = [
			'checkout'     => 'Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our [privacy_policy].',
			'registration' => 'Your personal data will be used to support your experience throughout this website, to manage access to your account, and for other purposes described in our [privacy_policy].',
		];
		if ( ! is_string( $type ) || ! isset( $english[ $type ] ) || trim( $text ) !== $english[ $type ] ) {
			return $text;
		}
		if ( 'checkout' === $type ) {
			return __( 'Ваші персональні дані використовуватимуться для обробки замовлення та роботи сайту. Ознайомтеся з [privacy_policy].', 'vextra-core' );
		}
		return __( 'Ваші персональні дані використовуватимуться для роботи сайту та керування доступом до облікового запису. Ознайомтеся з [privacy_policy].', 'vextra-core' );
	}

	/**
	 * Terms checkbox label of the classic checkout / order-pay form: "I have read and agree to the website [terms]" -> "Я прочитав(ла) і погоджуюся з [terms]"
	 * (the link label is the instrumental "публічною офертою"), only while the owner has not saved a text of their own.
	 *
	 * @param mixed $text Text (option or Woo default).
	 * @return mixed
	 */
	public function terms_checkbox_text( $text ) {
		if ( ! is_string( $text ) || false !== get_option( 'woocommerce_checkout_terms_and_conditions_checkbox_text', false ) ) {
			return $text;
		}
		return __( 'Я прочитав(ла) і погоджуюся з [terms]', 'vextra-core' );
	}

	/**
	 * Cart block rendered: override the strings of its frontend script.
	 *
	 * @param mixed $html Block HTML (returned unchanged).
	 * @return mixed
	 */
	public function after_cart_block( $html ) {
		$this->attach( 'wc-cart-block-frontend' );
		return $html;
	}

	/**
	 * Checkout block rendered: override the strings of its frontend script.
	 *
	 * @param mixed $html Block HTML (returned unchanged).
	 * @return mixed
	 */
	public function after_checkout_block( $html ) {
		$this->attach( 'wc-checkout-block-frontend' );
		return $html;
	}

	/**
	 * Attach the `setLocaleData()` snippet BEFORE a frontend entry script (once per handle and request).
	 *
	 * @param string $handle Script handle.
	 */
	private function attach( string $handle ): void {
		if ( in_array( $handle, $this->done, true ) || ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}
		$json = wp_json_encode( self::block_strings(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
		if ( false === $json ) {
			return;
		}
		$this->done[] = $handle;
		wp_add_inline_script( $handle, 'window.wp&&wp.i18n&&wp.i18n.setLocaleData(' . $json . ',"woocommerce");', 'before' );
	}

	/**
	 * Jed entries for the block scripts (translated for the current locale).
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function block_strings(): array {
		return [
			// Totals labels (cart: delivery unknown; checkout: final).
			'Estimated total'                            => [ __( 'Разом без доставки', 'vextra-core' ) ],
			'Total'                                      => [ __( 'До сплати', 'vextra-core' ) ],
			// Savings text of a sale line ("Зберегти" reads as "save a file").
			// translators: %s: saved amount.
			'Save %s'                                    => [ __( 'Економія %s', 'vextra-core' ) ],
			// Guest checkout note (Woo: "You are currently checking out as a guest.").
			'You are currently checking out as a guest.' => [ __( 'Реєстрація не потрібна — оформте замовлення як гість.', 'vextra-core' ) ],
			// Shipping row while no carrier rate exists.
			'Calculated at checkout'                     => [ __( 'за тарифами перевізника', 'vextra-core' ) ],
			// Terms line (link-only, no checkbox): "Підтверджуючи замовлення, ви погоджуєтесь з Публічною офертою та Політикою конфіденційності.".
			// translators: 1: terms page link, 2: privacy page link.
			'By proceeding with your purchase you agree to our %1$s and %2$s' => [ __( 'Підтверджуючи замовлення, ви погоджуєтесь з %1$s та %2$s.', 'vextra-core' ) ],
			'Terms and Conditions'                       => [ __( 'Публічною офертою', 'vextra-core' ) ],
			'Privacy Policy'                             => [ __( 'Політикою конфіденційності', 'vextra-core' ) ],
			// Buttons.
			'Proceed to Checkout'                        => [ __( 'Перейти до оформлення', 'vextra-core' ) ],
			'Place Order'                                => [ __( 'Підтвердити замовлення', 'vextra-core' ) ],
			'Your cart is currently empty!'              => [ __( 'Ваш кошик порожній', 'vextra-core' ) ],
		];
	}
}
