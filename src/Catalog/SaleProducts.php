<?php
/**
 * Products on sale NOW, per language (TASK-043a). Feeds the robots / sitemap rules of the sale page and (TASK-043b) the sale listing.
 *
 * `ids( $lang )`: Woo's on-sale id list (every language, variable parents included) -> filtered to the language -> products whose
 * sale already ended are dropped (`is_on_sale()` honours the schedule). Cached with `Support\Cache` (group `sale`, key = language,
 * TTL <= 1 h so a sale ending between events still disappears) and flushed on product / variation save, the scheduled-sales cron
 * and product delete / trash. A visitor read is one cache hit (0 queries). Under WP-CLI the cache is bypassed (shared Cache rule).
 * Filter `vextra/catalog/sale_count` overrides the count (tests, e.g. force 0).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;
use Vextra\Core\Support\Cache;
use Vextra\Core\Support\Listable;

final class SaleProducts {

	public const GROUP = 'sale';

	private const TTL = 1800;

	public function register(): void {
		foreach ( [ 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_trash_product', 'woocommerce_delete_product', 'woocommerce_save_product_variation', 'woocommerce_scheduled_sales', 'vextra/catalog/product_exposure_changed' ] as $hook ) {
			add_action( $hook, [ self::class, 'flush' ] );
		}
	}

	/**
	 * Invalidate every language.
	 */
	public static function flush(): void {
		Cache::flush( self::GROUP );
	}

	/**
	 * Ids of the products on sale now in a language (current language when null/empty).
	 *
	 * @param string|null $lang Language slug.
	 * @return int[]
	 */
	public static function ids( ?string $lang = null ): array {
		$lang = self::lang( $lang );
		$ids  = Cache::remember( self::GROUP, 'ids:' . $lang, self::TTL, fn(): array => self::build( $lang ) );
		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : [];
	}

	/**
	 * Number of products on sale now in a language.
	 *
	 * @param string|null $lang Language slug.
	 */
	public static function count( ?string $lang = null ): int {
		$lang  = self::lang( $lang );
		$count = count( self::ids( $lang ) );

		/**
		 * Overrides the sale product count of a language (tests).
		 *
		 * @param int    $count Real count.
		 * @param string $lang  Language slug.
		 */
		return max( 0, (int) apply_filters( 'vextra/catalog/sale_count', $count, $lang ) );
	}

	/**
	 * Resolve the language slug.
	 *
	 * @param string|null $lang Requested slug.
	 */
	private static function lang( ?string $lang ): string {
		if ( null !== $lang && '' !== $lang ) {
			return $lang;
		}
		$pll = Plugin::instance()->integration( 'polylang' );
		return $pll instanceof Polylang ? $pll->current_language() : '';
	}

	/**
	 * Cold build (cache miss).
	 *
	 * @param string $lang Language slug.
	 * @return int[]
	 */
	private static function build( string $lang ): array {
		$plugin = Plugin::instance();
		$woo    = $plugin->integration( 'woocommerce' );
		$pll    = $plugin->integration( 'polylang' );
		if ( ! $woo instanceof WooCommerce || ! $pll instanceof Polylang ) {
			return [];
		}

		$ids = $woo->sale_product_ids();
		if ( array() === $ids ) {
			return [];
		}

		if ( '' !== $lang ) {
			$pll->prime_posts( $ids, 'product' );
			$ids = array_values( array_filter( $ids, static fn( int $id ): bool => $pll->post_language( $id ) === $lang ) );
		}

		$ids = Listable::ids( $woo->on_sale_now( $ids ) ); // SEC-066: protected products are not advertised.
		sort( $ids );
		return $ids;
	}
}
