<?php
/**
 * Checkout module (TASK-045a, E6): the server rules of the Woo block checkout.
 *
 * - `TurnstileCheckout` + `Gate`: Turnstile token (`extensions.vextra.turnstile`) and the billing e-mail throttle before an order can be created.
 * - `Phone`: Ukrainian phone normalisation / validation (Store API + classic). `Fields`: Ukrainian address locale (region / city / street, no postcode, no country).
 * - `Privacy`: no-store headers, masked e-mail, language-correct privacy page. `Strings`: UA / RU strings Woo does not translate.
 * - `Cli`: `wp vextra checkout check-phone`.
 * Kill switch: constant `VX_CHECKOUT_DISABLED` or filter `vextra/core/checkout/enabled` (Woo's own checkout works, site 200). The IP throttle of the checkout
 * lives in Guard (`CheckoutThrottle`) and follows the Guard kill switch. Docs: docs/system/modules/cart-checkout.md (theme repo).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Checkout;

use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Plugin;

final class Module implements ModuleInterface {

	public function id(): string {
		return 'checkout';
	}

	public function is_enabled(): bool {
		$woo     = Plugin::instance()->integration( 'woocommerce' );
		$enabled = null !== $woo && $woo->is_available() && ! ( defined( 'VX_CHECKOUT_DISABLED' ) && VX_CHECKOUT_DISABLED );

		/**
		 * Feature flag of the Checkout module.
		 *
		 * @param bool $enabled Default: WooCommerce available and VX_CHECKOUT_DISABLED not set.
		 */
		return (bool) apply_filters( 'vextra/core/checkout/enabled', $enabled );
	}

	public function register(): void {
		require_once __DIR__ . '/functions.php';

		$turnstile = new TurnstileCheckout();
		$turnstile->register();
		( new Gate( TurnstileCheckout::active() ? $turnstile : null ) )->register();
		( new Phone() )->register();
		( new Fields() )->register();
		( new Privacy() )->register();
		( new Strings() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new Cli() )->register();
		}
	}
}
