<?php
/**
 * Public functions of the Checkout module for the theme (TASK-045a). The theme wraps every call in `function_exists`.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

use Vextra\Core\Checkout\Privacy;
use Vextra\Core\Checkout\TurnstileCheckout;
use Vextra\Core\Guard\Exempt;
use Vextra\Core\Integrations\Turnstile\Turnstile;
use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;

if ( ! function_exists( 'vx_mask_email' ) ) {
	/**
	 * Masked e-mail for the thank-you page: `olena@example.com` -> `o***@example.com`, `a@x.ua` -> `a***@x.ua`; anything that is not an address -> ''.
	 * The only form of the customer's e-mail the thank-you markup may print.
	 *
	 * @param string $email E-mail address.
	 */
	function vx_mask_email( string $email ): string {
		return Privacy::mask_email( $email );
	}
}

if ( ! function_exists( 'vx_checkout_turnstile_config' ) ) {
	/**
	 * What the checkout script needs for the Turnstile widget: `enabled` is true ONLY on the checkout form page (not the cart, the thank-you or the
	 * order-pay page) while both keys are set and the visitor is not the owner bot. `site_key` is public by design; the secret never leaves PHP.
	 * The token goes to Woo as `extensions.vextra.turnstile` (`wp.data.dispatch( 'wc/store/checkout' ).setExtensionData( 'vextra', { turnstile: token } )`).
	 *
	 * @return array{enabled:bool,site_key:string,action:string,language:string,field:string,extension:array{namespace:string,property:string}}
	 */
	function vx_checkout_turnstile_config(): array {
		$off    = [
			'enabled'   => false,
			'site_key'  => '',
			'action'    => 'checkout',
			'language'  => 'auto',
			'field'     => Turnstile::FIELD,
			'extension' => [
				'namespace' => TurnstileCheckout::NAMESPACE,
				'property'  => TurnstileCheckout::PROPERTY,
			],
		];
		$plugin = Plugin::instance();
		$ts     = $plugin->integration( 'turnstile' );
		$woo    = $plugin->integration( 'woocommerce' );
		if ( ! $ts instanceof Turnstile || ! $woo instanceof WooCommerce || ! $ts->is_available() || ! $woo->is_checkout_form_page() || Exempt::is_owner_bot() ) {
			return $off;
		}
		return array_merge(
			$off,
			[
				'enabled'  => true,
				'site_key' => $ts->site_key(),
				'language' => $ts->widget_language(),
			]
		);
	}
}

if ( ! function_exists( 'vx_checkout_turnstile_slot' ) ) {
	/**
	 * Print the Turnstile slot (`.vx-turnstile`, `interaction-only`, zero height until Cloudflare needs a click). Nothing when `vx_checkout_turnstile_config()`
	 * is not enabled. `api.js` is enqueued by the Checkout module on the checkout form page.
	 */
	function vx_checkout_turnstile_slot(): void {
		$ts = Plugin::instance()->integration( 'turnstile' );
		if ( ! $ts instanceof Turnstile || ! vx_checkout_turnstile_config()['enabled'] ) {
			return;
		}
		$ts->render_widget(
			'checkout',
			[
				'appearance' => 'interaction-only',
				'reserve'    => false,
				'class'      => 'vx-turnstile',
			]
		);
	}
}
