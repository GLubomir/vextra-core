<?php
/**
 * Product module (TASK-044a): the data side of the single product page. The theme renders; it never builds variations, reads swatch data
 * or decides info rows.
 *
 * Owns: the variation / attribute / image / stock bundle (`Bundle`, SWR cache group `vx_pdp`), swatch term data + renderer rule (`Swatches`,
 * admin fields `Admin\SwatchFields`), info rows per language (`InfoRows`, admin page `Admin\PdpInfoPage`), the reviews view-model
 * (`ReviewsData`), the cards REST route (`CardsRest`) and the product image sitemap (`Seo\ImageSitemap`). Public functions for the theme
 * are in `functions.php` (`vx_pdp_context()`, `vx_pdp_bundle()`, `vx_pdp_info()`, `vx_pdp_reviews()`).
 * Kill switch: constant `VX_PRODUCT_DISABLED` (or filter `vextra/core/product/enabled`): Woo's own product page renders, the site stays 200.
 * Yields to a duplicate swatch / gallery / sticky-cart plugin (`Compat\Conflicts` rule `duplicate-pdp-plugin`).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Product;

use Vextra\Core\Admin\PdpInfoPage;
use Vextra\Core\Admin\SwatchFields;
use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;
use Vextra\Core\Seo\ImageSitemap;

final class Product implements ModuleInterface {

	public function id(): string {
		return 'product';
	}

	public function is_enabled(): bool {
		$woo     = Plugin::instance()->integration( 'woocommerce' );
		$enabled = null !== $woo && $woo->is_available() && ! ( defined( 'VX_PRODUCT_DISABLED' ) && VX_PRODUCT_DISABLED );

		/**
		 * Feature flag of the Product module.
		 *
		 * @param bool $enabled Default: WooCommerce available and VX_PRODUCT_DISABLED not set.
		 */
		return (bool) apply_filters( 'vextra/core/product/enabled', $enabled );
	}

	public function register(): void {
		require_once __DIR__ . '/functions.php';

		Bundle::register();
		InfoRows::register();
		( new CardsRest() )->register();
		( new SwatchFields() )->register();
		( new PdpInfoPage() )->register();

		$rank = Plugin::instance()->integration( 'rankmath' );
		if ( $rank instanceof RankMath ) {
			( new ImageSitemap( $rank ) )->register();
		}
	}
}
