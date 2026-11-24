<?php
/**
 * Seo module: indexation, sitemaps, per-language titles, breadcrumbs and Product schema through guarded Rank Math adapters.
 *
 * Rank Math stays the only emitter of `<head>` and JSON-LD; this module only filters its data (ADR-002 s1/s4).
 * Disabled when Rank Math, Polylang or WooCommerce is missing (feature flag filter `vextra/core/seo/enabled`).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Catalog\Cli as CatalogCli;
use Vextra\Core\Catalog\SalePage;
use Vextra\Core\Catalog\SaleProducts;
use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;

final class Seo implements ModuleInterface {

	public function id(): string {
		return 'seo';
	}

	public function is_enabled(): bool {
		$plugin = Plugin::instance();
		$woo    = $plugin->integration( 'woocommerce' );
		$pll    = $plugin->integration( 'polylang' );
		$rank   = $plugin->integration( 'rankmath' );

		$enabled = null !== $woo && $woo->is_available()
			&& $pll instanceof Polylang && $pll->is_ready()
			&& $rank instanceof RankMath && $rank->is_available();

		/**
		 * Feature flag of the Seo module.
		 *
		 * @param bool $enabled Default: WooCommerce, Polylang and Rank Math available.
		 */
		return (bool) apply_filters( 'vextra/core/seo/enabled', $enabled );
	}

	public function register(): void {
		$plugin = Plugin::instance();
		$pll    = $plugin->integration( 'polylang' );
		$rank   = $plugin->integration( 'rankmath' );
		if ( ! $pll instanceof Polylang || ! $rank instanceof RankMath ) {
			return;
		}

		$counts = new TermCounts( $rank );
		$counts->register();

		( new Robots( $rank, $counts ) )->register();
		( new Sitemap( $rank, $counts, $pll ) )->register();

		$templates = new Templates( $pll, $rank );
		$templates->register();
		( new Breadcrumbs( $pll, $rank, $templates ) )->register();
		( new Schema( $rank ) )->register();
		( new Organization( $rank, $pll ) )->register();
		( new Attributes( $rank ) )->register();
		( new TermRedirects( $rank ) )->register();
		( new PagedGuard() )->register();
		( new SalePage( $pll, $rank ) )->register();
		( new SaleProducts() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new Cli( $pll, $rank, $counts, new Transliterator() ) )->register();
			( new CatalogCli( $pll ) )->register();
		}
	}
}
