<?php
/**
 * hreflang set: `uk` (default language), `ru-UA` (other languages get `<code>-UA`) and `x-default` = the default-language URL.
 *
 * Polylang (verified 3.9.2: `src/frontend/frontend-filters-links.php`) prints the set from `wp_head` only on translated,
 * non-paged pages and passes it through filter `pll_rel_hreflang_attributes` (array code => URL). This class rewrites
 * that array; it never prints anything itself. Dropped (empty array = nothing printed): search, 404, feeds, cart/checkout/
 * account pages, posts set to noindex in Rank Math, and any language whose translation is not published.
 * `<html lang>` is untouched (`uk` / `ru-RU`).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Multilang;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;

final class Hreflang {

	/**
	 * Region appended to non-default language codes.
	 */
	private const REGION = 'UA';

	private Polylang $polylang;

	private RankMath $rank_math;

	public function __construct( Polylang $polylang, RankMath $rank_math ) {
		$this->polylang  = $polylang;
		$this->rank_math = $rank_math;
	}

	public function register(): void {
		add_filter( 'pll_rel_hreflang_attributes', [ $this, 'filter' ] );
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
			$parts                                = explode( '-', (string) $code );
			$by_code[ strtolower( (string) reset( $parts ) ) ] = $url;
		}

		$default = $this->polylang->default_language();
		$codes   = $this->polylang->locale_codes();
		$allowed = $this->published_languages();
		$out     = [];
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
		return false;
	}

	/**
	 * For singular views: languages whose translation is published (null = not a singular view, no restriction).
	 *
	 * @return string[]|null
	 */
	private function published_languages(): ?array {
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
