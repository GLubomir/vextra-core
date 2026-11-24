<?php
/**
 * Multilang module: Polylang (free) glue for WooCommerce, replacing the paid "Polylang for WooCommerce" (ADR-002 s11).
 *
 * Services: WooPages (page ids + shared slugs), ProductSync, StockSync, OrderLanguage, Hreflang, Cli.
 * Disabled when WooCommerce or Polylang is missing; the feature flag filter `vextra/core/multilang/enabled` can switch it off.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Multilang;

use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;
use Vextra\Core\Seo\TermCounts;

final class Multilang implements ModuleInterface {

	public function id(): string {
		return 'multilang';
	}

	public function is_enabled(): bool {
		$plugin = Plugin::instance();
		$woo    = $plugin->integration( 'woocommerce' );
		$pll    = $plugin->integration( 'polylang' );

		$enabled = null !== $woo && $woo->is_available() && $pll instanceof Polylang && $pll->is_ready();

		/**
		 * Feature flag of the Multilang module.
		 *
		 * @param bool $enabled Default: WooCommerce available and Polylang ready.
		 */
		return (bool) apply_filters( 'vextra/core/multilang/enabled', $enabled );
	}

	public function register(): void {
		$plugin = Plugin::instance();
		$pll    = $plugin->integration( 'polylang' );
		$rank   = $plugin->integration( 'rankmath' );
		if ( ! $pll instanceof Polylang || ! $rank instanceof RankMath ) {
			return;
		}

		$sync = new ProductSync( $pll );

		( new WooPages( $pll, $rank ) )->register();
		$sync->register();
		$stock = new StockSync( $pll );
		$stock->register();
		( new OrderLanguage( $pll ) )->register();
		$counts = new TermCounts( $rank );
		$counts->register();
		( new Hreflang( $pll, $rank, $counts ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new Cli( $pll, $sync, $stock ) )->register();
		}
	}
}
