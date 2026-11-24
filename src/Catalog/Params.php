<?php
/**
 * The ONE facet-parameter contract of the catalog (docs/design/screens/catalog.md s5.1, ADR-002 amendment 2026-11-24).
 *
 * A request is "facet-ish" when its query string carries a catalog filter, a sort, a search, a `product_cat` argument, a
 * `post_type` argument or a `paged` argument. Such URLs are never indexed (`Seo\Robots`), carry no hreflang
 * (`Multilang\Hreflang`), match a robots.txt rule (`Guard\RobotsTxt`) and are rate limited per IP (`Guard\Facets`).
 * Pure and static, no WordPress calls, no side effects: only the KEYS of the query array are trusted, never the values.
 * PHP query keys are case-sensitive, so `?ORDERBY=` is not special-cased (documented in docs/system/modules/seo.md).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

final class Params {

	/**
	 * Exact query keys that make a URL a facet URL (any value, an empty value included).
	 */
	public const FACET_KEYS = [ 'orderby', 'min_price', 'max_price', 'rating_filter', 'product_cat', 'paged' ];

	/**
	 * Key prefixes that make a URL a facet URL (`filter_brand`, `filter_on_sale`, `query_type_color`, ...).
	 */
	public const FACET_PREFIXES = [ 'filter_', 'query_type_' ];

	/**
	 * Other keys that count only with a meaningful value: `s` (non-empty search) and `post_type`.
	 */
	private const SEARCH_KEY = 's';

	/**
	 * Does the query array (usually `$_GET`) carry a facet / sort / search / paged parameter?
	 *
	 * @param array<mixed> $query Query array; only its keys (and for `s` its emptiness) are read.
	 */
	public static function has_facet( array $query ): bool {
		foreach ( $query as $key => $value ) {
			$key = (string) $key;

			if ( self::SEARCH_KEY === $key ) {
				if ( self::is_filled( $value ) ) {
					return true;
				}
				continue;
			}

			if ( 'post_type' === $key || in_array( $key, self::FACET_KEYS, true ) ) {
				return true;
			}

			foreach ( self::FACET_PREFIXES as $prefix ) {
				if ( str_starts_with( $key, $prefix ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * A non-empty scalar, or a non-empty array (`?s[]=x`).
	 *
	 * @param mixed $value Raw query value.
	 */
	private static function is_filled( $value ): bool {
		if ( is_array( $value ) ) {
			return array() !== $value;
		}
		return is_scalar( $value ) && '' !== trim( (string) $value );
	}
}
