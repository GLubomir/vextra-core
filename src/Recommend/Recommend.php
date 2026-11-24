<?php
/**
 * Recommend module (TASK-038, ADR-010): ONE recommendation engine for the product page, the cart / post-add drawer and the category
 * (Phase 1: manual links, accessory rules, deterministic related; co-purchase affinity and margin weight come later).
 *
 * Pipeline `Sources -> Candidates -> Filters -> Ranker -> Cache -> Presenters`: see `Engine`. Presenters: PHP `vx_recommend()` /
 * `vx_recommend_ids()`, REST `GET /vextra/v1/recommend`, the `woocommerce_cart_crosssell_ids` adapter. Also owns the product fields
 * (partner, commission, stock-synced-at) and the order-line attribution `_vx_rec_src`.
 * Kill switch: constant `VX_RECOMMEND_DISABLED` or filter `vextra/core/recommend/enabled`: Woo's own related products / cross-sells
 * work, the site stays 200. Yields to a duplicate recommendation plugin (`Compat\Conflicts` rule `duplicate-recommendation-plugin`).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend;

use Vextra\Core\Catalog\AccessoryRules;
use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;

final class Recommend implements ModuleInterface {

	public function id(): string {
		return 'recommend';
	}

	public function is_enabled(): bool {
		$woo     = Plugin::instance()->integration( 'woocommerce' );
		$enabled = null !== $woo && $woo->is_available() && ! ( defined( 'VX_RECOMMEND_DISABLED' ) && VX_RECOMMEND_DISABLED );

		/**
		 * Feature flag of the Recommend module.
		 *
		 * @param bool $enabled Default: WooCommerce available and VX_RECOMMEND_DISABLED not set.
		 */
		return (bool) apply_filters( 'vextra/core/recommend/enabled', $enabled );
	}

	public function register(): void {
		require_once __DIR__ . '/functions.php';

		Store::register();
		AccessoryRules::register();
		( new Fields() )->register();
		( new Attribution() )->register();
		( new Rest() )->register();
		( new CartAdapter() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$pll = Plugin::instance()->integration( 'polylang' );
			if ( $pll instanceof Polylang ) {
				( new Cli( $pll ) )->register();
			}
		}
	}
}
