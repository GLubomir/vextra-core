<?php
/**
 * Data access of the Recommend pipeline (TASK-038): the only place that queries candidates and reads their facts.
 *
 * - `ids_in_terms()`: ONE ID-only statement through the Lookup adapter (descendants included; Polylang never rewrites it; language purity
 *   comes from the language-specific term ids plus the `language` filter stage).
 * - `light()`: ONE lookup-table statement (status, price, stock, sales, hidden). `facts()`: light + ONE post-meta cache read + ONE
 *   language term read for a candidate set; both memoised per request. No `wc_get_product()` per candidate.
 * - `twin()`: product id mapped to its translation in a language (Polylang adapter; identity without Polylang).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;

final class Pool {

	/**
	 * Per-request memos: product id => light / full facts (empty array = no such product), product id => category ids.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $light = [];

	/**
	 * Full facts memo.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $facts = [];

	/**
	 * Category memo.
	 *
	 * @var array<int,int[]>
	 */
	private static array $cats = [];

	/**
	 * Forget the memos (tests, cache flush).
	 */
	public static function reset(): void {
		self::$light = [];
		self::$facts = [];
		self::$cats  = [];
	}

	private static function polylang(): ?Polylang {
		$pll = Plugin::instance()->integration( 'polylang' );
		return $pll instanceof Polylang && $pll->is_ready() ? $pll : null;
	}

	private static function woo(): ?WooCommerce {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		return $woo instanceof WooCommerce && $woo->is_available() ? $woo : null;
	}

	/**
	 * Language slug of the request: the current front-end language, the default language outside a language context (REST, CLI),
	 * '' without Polylang. Never '' while Polylang runs, so a list can never mix languages.
	 */
	public static function current_language(): string {
		$pll = self::polylang();
		if ( null === $pll ) {
			return '';
		}
		$lang = $pll->current_language();
		return '' !== $lang ? $lang : $pll->default_language();
	}

	/**
	 * Language slug of a product ('' without Polylang).
	 *
	 * @param int $id Product id.
	 */
	public static function language_of( int $id ): string {
		$pll = self::polylang();
		return null === $pll ? '' : $pll->post_language( $id );
	}

	/**
	 * Product id in a language: itself when it already is, its translation otherwise, 0 when there is none.
	 * Variations resolve to the parent first. Identity without Polylang or without a language.
	 *
	 * @param int    $id   Product or variation id.
	 * @param string $lang Language slug.
	 */
	public static function twin( int $id, string $lang ): int {
		$woo = self::woo();
		if ( $id <= 0 || null === $woo ) {
			return 0;
		}
		$id  = $woo->parent_id( $id );
		$pll = self::polylang();
		if ( null === $pll || '' === $lang || $pll->post_language( $id ) === $lang ) {
			return $id;
		}
		return $pll->translate_post( $id, $lang );
	}

	/**
	 * Translation of a product in the default language (itself without Polylang).
	 *
	 * @param int $id Product id.
	 */
	public static function default_twin( int $id ): int {
		$pll = self::polylang();
		return null === $pll ? $id : max( 0, $pll->translate_post( $id, $pll->default_language() ) );
	}

	/**
	 * Is the language the default one (or Polylang absent)?
	 *
	 * @param string $lang Language slug.
	 */
	public static function is_default_language( string $lang ): bool {
		$pll = self::polylang();
		return null === $pll || '' === $lang || $pll->default_language() === $lang;
	}

	/**
	 * Load term objects (and queue their term meta for one lazy read) of many ids in ONE query. `get_terms()` is avoided on purpose:
	 * Woo's `get_terms` filter writes a transient. Guarded core helper `_prime_term_caches()` (INTERNAL, docs/compat/integrations.md);
	 * without it the terms are read one by one on first use.
	 *
	 * @param int[] $term_ids Term ids.
	 */
	public static function prime_terms( array $term_ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
		if ( array() !== $ids && function_exists( '_prime_term_caches' ) ) {
			_prime_term_caches( $ids );
		}
	}

	/**
	 * Published product ids of a set of `product_cat` terms (and their descendants), ascending by id, capped.
	 *
	 * @param int[] $term_ids Term ids.
	 * @return int[]
	 */
	public static function ids_in_terms( array $term_ids ): array {
		$woo = self::woo();
		if ( null === $woo ) {
			return [];
		}
		$all = [];
		foreach ( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) as $term_id ) {
			$all[] = $term_id;
			$kids  = get_term_children( $term_id, 'product_cat' );
			if ( is_array( $kids ) ) {
				$all = array_merge( $all, array_map( 'intval', $kids ) );
			}
		}
		return $woo->lookup()->product_ids_in_terms( $all, max( 1, (int) Config::get( 'scan' ) ) );
	}

	/**
	 * Lookup facts only (memoised): status, min / max price, stock, sales, hidden flag, `has_row`. Unknown id = empty array.
	 *
	 * @param int[] $ids Product ids.
	 * @return array<int,array<string,mixed>>
	 */
	public static function light( array $ids ): array {
		$ids  = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$need = array_values( array_filter( $ids, static fn( int $id ): bool => ! isset( self::$light[ $id ] ) ) );
		if ( array() !== $need ) {
			$woo  = self::woo();
			$rows = null === $woo ? [] : (array) $woo->lookup()->candidate_facts( $need, $woo->hidden_term_tt() );
			foreach ( $need as $id ) {
				self::$light[ $id ] = $rows[ $id ] ?? [];
			}
		}
		$out = [];
		foreach ( $ids as $id ) {
			$out[ $id ] = self::$light[ $id ];
		}
		return $out;
	}

	/**
	 * Full facts of products (memoised): light facts + partner, synced-at (one post-meta cache read) + language (one term read).
	 *
	 * @param int[] $ids Product ids.
	 * @return array<int,array<string,mixed>>
	 */
	public static function facts( array $ids ): array {
		$ids  = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$base = self::light( $ids );
		$need = array_values( array_filter( $ids, static fn( int $id ): bool => array() !== $base[ $id ] && ! isset( self::$facts[ $id ] ) ) );
		if ( array() !== $need ) {
			$woo = self::woo();
			$pll = self::polylang();
			$map = null === $pll ? [] : $pll->language_term_map();
			$tf  = array() === $map || null === $woo ? [] : $woo->lookup()->term_facts( $need, [ 'language' ] );
			update_meta_cache( 'post', $need );
			foreach ( $need as $id ) {
				$lang = '';
				foreach ( $tf[ $id ]['language'] ?? [] as $term_id ) {
					$lang = $map[ $term_id ] ?? $lang;
				}
				self::$facts[ $id ] = $base[ $id ] + [
					'partner' => Fields::partner( $id ),
					'synced'  => Fields::synced_at( $id ),
					'lang'    => $lang,
				];
			}
		}
		$out = [];
		foreach ( $ids as $id ) {
			$out[ $id ] = array() === $base[ $id ] ? [] : self::$facts[ $id ];
		}
		return $out;
	}

	/**
	 * Assigned `product_cat` term ids of a product.
	 *
	 * @param int $id Product id.
	 * @return int[]
	 */
	public static function categories( int $id ): array {
		if ( ! isset( self::$cats[ $id ] ) ) {
			$terms             = get_the_terms( $id, 'product_cat' ); // Object term cache: free on a product page.
			self::$cats[ $id ] = is_array( $terms ) ? array_values( array_map( static fn( \WP_Term $t ): int => (int) $t->term_id, $terms ) ) : [];
		}
		return self::$cats[ $id ];
	}

	/**
	 * Leaf categories of an assigned set: those that are not an ancestor of another assigned term. Ascending by id.
	 *
	 * @param int[] $term_ids Assigned term ids.
	 * @return int[]
	 */
	public static function leaves( array $term_ids ): array {
		$ancestors = [];
		foreach ( $term_ids as $id ) {
			foreach ( get_ancestors( $id, 'product_cat', 'taxonomy' ) as $parent ) {
				$ancestors[ (int) $parent ] = true;
			}
		}
		$leaves = array_values( array_filter( $term_ids, static fn( int $id ): bool => ! isset( $ancestors[ $id ] ) ) );
		sort( $leaves );
		return $leaves;
	}

	/**
	 * Stable per-anchor spread key: deterministic for one anchor, different between anchors (internal-link spread, SEO review).
	 *
	 * `k` + 8 hex digits (CRC32), compared as text: integer `crc32()` is signed on 32-bit PHP (the CLI here) and unsigned
	 * on 64-bit (the web), which would order the same candidates differently per SAPI.
	 *
	 * @param int $anchor_id    Anchor product id.
	 * @param int $candidate_id Candidate product id.
	 */
	public static function spread_key( int $anchor_id, int $candidate_id ): string {
		return 'k' . hash( 'crc32b', $anchor_id . ':' . $candidate_id ); // Letter prefix: never a numeric string, so comparison is always textual.
	}
}
