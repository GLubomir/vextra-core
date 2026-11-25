<?php
/**
 * Orders module (TASK-047a): the guest order-tracking lookup (`/track-order/` + RU twin).
 *
 * The theme renders the page and the form; this module answers the POST (throttle, Turnstile, lookup by number + billing e-mail) and hands the
 * theme a minimal non-PII view model through the public function `vx_track_order_state()`. Kill switch: constant `VX_ORDERS_DISABLED` or filter
 * `vextra/core/orders/enabled`. Docs: docs/system/modules/pages.md (theme repo).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Orders;

use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;
use Vextra\Core\Seo\LaunchPages;

final class Module implements ModuleInterface {

	public function id(): string {
		return 'orders';
	}

	public function is_enabled(): bool {
		$woo     = Plugin::instance()->integration( 'woocommerce' );
		$enabled = null !== $woo && $woo->is_available() && ! ( defined( 'VX_ORDERS_DISABLED' ) && VX_ORDERS_DISABLED );

		/**
		 * Feature flag of the Orders module.
		 *
		 * @param bool $enabled Default: WooCommerce available and VX_ORDERS_DISABLED not set.
		 */
		return (bool) apply_filters( 'vextra/core/orders/enabled', $enabled );
	}

	public function register(): void {
		require_once __DIR__ . '/functions.php';

		$pll = Plugin::instance()->integration( 'polylang' );
		( new TrackOrder( $pll instanceof Polylang ? new LaunchPages( $pll ) : null ) )->register();
	}
}
