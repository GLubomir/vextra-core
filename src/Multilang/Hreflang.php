<?php
/**
 * Hreflang set: `uk` (default language), `ru-UA` (other languages get `<code>-UA`) and `x-default` = the default-language URL.
 *
 * Polylang (verified 3.9.2: `src/frontend/frontend-filters-links.php`) prints the set from `wp_head` only on translated,
 * non-paged pages and passes it through filter `pll_rel_hreflang_attributes` (array code => URL). This class rewrites
 * that array; it never prints anything itself. Dropped (empty array = nothing printed): search, 404, feeds, cart/checkout/
 * account pages, posts set to noindex in Rank Math, any language whose translation is not published, and (`product_cat`
 * archives) any language whose category twin is not indexable (no products in itself or descendants, or manual noindex).
 * `<html lang>` is untouched (`uk` / `ru-RU`).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Multilang;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Seo\TermCounts;

final class Hreflang {

	/**
	 * Region appended to non-default language codes.
	 */
	private const REGION = 'UA';

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	/**
	 * Rank Math adapter.
	 *
	 * @var RankMath
	 */
	private RankMath $rank_math;

	/**
	 * Hierarchical category counts.
	 *
	 * @var TermCounts
	 */
	private TermCounts $counts;

	public function __construct( Polylang $polylang, RankMath $rank_math, TermCounts $counts ) {
		$this->polylang  = $polylang;
		$this->rank_math = $rank_math;
		$this->counts    = $counts;
	}

	public function register(): void {
		add_filter( 'pll_rel_hreflang_attributes', [ $this, 'filter' ] );
		add_action( 'wp_head', [ $this, 'print_paged' ], 2 );
	}

	/**
	 * Hreflang of PAGINATED catalogue archives (`/catalog/page/N/` <-> `/ru/catalog/page/N/`, same for `product_cat`).
	 *
	 * Polylang prints nothing on paged archives and its translation URLs ignore the page number, so the set is built here from
	 * the translation group (shop page / category twins) plus `page/N/`. Page N of a twin is assumed to exist when it exists in
	 * the current language (twins are kept 1:1 by ProductSync; a category whose twin is not indexable is dropped by filter()).
	 * Only shop and `product_cat` archives; singular pages, search and other archives print nothing. Same output rules as filter().
	 */
	public function print_paged(): void {
		if ( ! is_paged() || is_singular() ) {
			return;
		}
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		global $wp_query;
		if ( $paged < 2 || $paged > max( 1, (int) ( $wp_query->max_num_pages ?? 1 ) ) ) {
			return;
		}

		if ( is_tax( TermCounts::TAXONOMY ) ) {
			$group = [];
			foreach ( $this->polylang->term_translations( (int) get_queried_object_id() ) as $lang => $id ) {
				$link = get_term_link( (int) $id, TermCounts::TAXONOMY );
				if ( is_string( $link ) && '' !== $link ) {
					$group[ (string) $lang ] = $link;
				}
			}
		} elseif ( function_exists( 'is_shop' ) && is_shop() && function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ) {
			$group = [];
			foreach ( $this->polylang->translations( (int) wc_get_page_id( 'shop' ) ) as $lang => $id ) {
				$link = get_permalink( (int) $id );
				if ( is_string( $link ) && '' !== $link && 'publish' === get_post_status( (int) $id ) ) {
					$group[ (string) $lang ] = $link;
				}
			}
		} else {
			return;
		}

		$base = (string) $GLOBALS['wp_rewrite']->pagination_base;
		$map  = [];
		foreach ( $this->polylang->locale_codes() as $slug => $code ) {
			if ( isset( $group[ $slug ] ) ) {
				$map[ $code ] = trailingslashit( $group[ $slug ] ) . $base . '/' . $paged . '/';
			}
		}

		foreach ( $this->filter( $map ) as $code => $url ) {
			printf( '<link rel="alternate" href="%s" hreflang="%s" />' . "\n", esc_url( $url ), esc_attr( (string) $code ) );
		}
	}

	/**
	 * Rewrite Polylang's hreflang map.
	 *
	 * @param mixed $hreflangs Map code => URL as built by Polylang.
	 * @return array<string,string>
	 */
	public function filter( $hreflangs ): array {
		if ( ! is_array( $hreflangs ) || count( $hreflangs ) < 2 || $this->is_suppressed() ) {
			return [];
		}

		// Polylang keys are locale-derived (`uk`, `ru`); index them by language part.
		$by_code = [];
		foreach ( $hreflangs as $code => $url ) {
			if ( ! is_string( $url ) || '' === $url || 'x-default' === $code ) {
				continue;
			}
			$parts = explode( '-', (string) $code );
			$by_code[ strtolower( (string) reset( $parts ) ) ] = $url;
		}

		$default     = $this->polylang->default_language();
		$codes       = $this->polylang->locale_codes();
		$allowed     = $this->published_languages();
		$out         = [];
		$default_url = '';

		foreach ( $codes as $slug => $code ) {
			if ( ! isset( $by_code[ $code ] ) || ( null !== $allowed && ! in_array( $slug, $allowed, true ) ) ) {
				continue;
			}
			$url = $by_code[ $code ];
			if ( $slug === $default ) {
				$out[ $code ] = $url;
				$default_url  = $url;
			} else {
				$out[ $code . '-' . self::REGION ] = $url;
			}
		}

		if ( count( $out ) < 2 || '' === $default_url ) {
			return [];
		}

		$out['x-default'] = $default_url;

		/**
		 * Filters the final hreflang map.
		 *
		 * @param array<string,string> $out Code => absolute URL.
		 */
		return (array) apply_filters( 'vextra/core/multilang/hreflang', $out );
	}

	/**
	 * Pages that must not carry alternates.
	 */
	private function is_suppressed(): bool {
		if ( is_search() || is_404() || is_feed() ) {
			return true;
		}
		if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
			return true;
		}
		if ( is_singular() ) {
			return $this->rank_math->is_post_noindex( (int) get_queried_object_id() );
		}
		if ( is_tax( TermCounts::TAXONOMY ) ) {
			return ! $this->counts->is_indexable( (int) get_queried_object_id() );
		}
		return false;
	}

	/**
	 * For singular views: languages whose translation is published; for product categories: languages whose twin is indexable (null = no restriction).
	 *
	 * @return string[]|null
	 */
	private function published_languages(): ?array {
		if ( is_tax( TermCounts::TAXONOMY ) ) {
			$out = [];
			foreach ( $this->polylang->term_translations( (int) get_queried_object_id() ) as $lang => $id ) {
				if ( $this->counts->is_indexable( $id ) ) {
					$out[] = (string) $lang;
				}
			}
			return $out;
		}
		if ( ! is_singular() ) {
			return null;
		}
		$out = [];
		foreach ( $this->polylang->translations( (int) get_queried_object_id() ) as $lang => $id ) {
			if ( 'publish' === get_post_status( $id ) ) {
				$out[] = (string) $lang;
			}
		}
		return $out;
	}
}
