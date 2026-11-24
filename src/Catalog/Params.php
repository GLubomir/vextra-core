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
	 * Products per listing page (fixed, no per-page selector: the reference's `?per_page=` links are a crawl trap).
	 */
	public const PER_PAGE = 24;

	/**
	 * Sort values a visitor may send besides the context default.
	 */
	public const SORTS = [ 'popularity', 'date', 'price', 'price-desc', 'rating' ];

	/**
	 * Request limits (garbage input never grows the work): params read, values per list, value length, price ceiling.
	 */
	private const MAX_PARAMS = 12;
	private const MAX_VALUES = 20;
	private const MAX_LENGTH = 60;
	private const MAX_PRICE  = 10000000;

	/**
	 * Empty state (every filter off).
	 *
	 * @return array<string,mixed>
	 */
	public static function empty_state(): array {
		return [
			'orderby'   => '',
			'min_price' => null,
			'max_price' => null,
			'stock'     => [],
			'brand'     => [],
			'attrs'     => [],
			'rating'    => [],
			'on_sale'   => false,
			'discount'  => 0,
			'cat'       => '',
		];
	}

	/**
	 * Normalise a raw query array into the allow-listed filter / sort state of a context (screen s5.1). Nothing is echoed, unknown
	 * params are ignored, unknown or oversized values are dropped, nothing throws.
	 *
	 * Contexts: `category`, `shop`, `search`, `sale`. `search` / `sale` accept `product_cat`; `sale` accepts `filter_discount` and
	 * ignores `filter_on_sale`; `orderby` `relevance` (search) and `discount` (sale) are the context defaults and normalise to ''.
	 *
	 * @param array<mixed>  $get     Raw query array (usually `$_GET`; values are `wp_unslash`ed here).
	 * @param string        $context category|shop|search|sale.
	 * @param callable|null $known   fn( string $taxonomy ): ?string[] = every slug of the taxonomy, null when the taxonomy does not exist.
	 *                               Null callable = no validation against terms (tests, oracle).
	 * @return array<string,mixed>
	 */
	public static function normalise( array $get, string $context, ?callable $known = null ): array {
		$state = self::empty_state();
		$seen  = 0;

		$allowed_slugs = static function ( string $taxonomy, array $values ) use ( $known ): array {
			if ( null === $known ) {
				return $values;
			}
			$all = $known( $taxonomy );
			return is_array( $all ) ? array_values( array_intersect( $values, $all ) ) : [];
		};

		foreach ( $get as $raw_key => $raw ) {
			$key = (string) $raw_key;
			if ( ! self::is_catalog_key( $key ) ) {
				continue;
			}
			if ( ++$seen > self::MAX_PARAMS ) {
				break;
			}

			switch ( true ) {
				case 'orderby' === $key:
					$value = self::scalar( $raw );
					if ( in_array( $value, self::SORTS, true ) ) {
						$state['orderby'] = $value;
					}
					break;

				case 'min_price' === $key:
					$state['min_price'] = self::price( $raw );
					break;

				case 'max_price' === $key:
					$state['max_price'] = self::price( $raw );
					break;

				case 'filter_stock_status' === $key:
					$state['stock'] = array_values( array_intersect( [ 'instock', 'onbackorder' ], self::list( $raw ) ) );
					break;

				case 'filter_brand' === $key:
					$state['brand'] = $allowed_slugs( 'product_brand', self::list( $raw ) );
					break;

				case 'rating_filter' === $key:
					$ints = array_values( array_unique( array_filter( array_map( 'absint', self::list( $raw ) ), static fn( int $n ): bool => $n >= 1 && $n <= 5 ) ) );
					sort( $ints );
					$state['rating'] = $ints;
					break;

				case 'filter_on_sale' === $key:
					$state['on_sale'] = 'sale' !== $context && '1' === self::scalar( $raw );
					break;

				case 'filter_discount' === $key:
					$pct               = (int) self::scalar( $raw );
					$state['discount'] = 'sale' === $context && in_array( $pct, [ 10, 20, 30 ], true ) ? $pct : 0;
					break;

				case 'product_cat' === $key:
					$slug = sanitize_title( self::scalar( $raw ) );
					if ( in_array( $context, [ 'search', 'sale' ], true ) && '' !== $slug ) {
						$state['cat'] = array() === $allowed_slugs( 'product_cat', [ $slug ] ) ? '' : $slug;
					}
					break;

				default: // `filter_<attribute slug>`; every other key was excluded by is_catalog_key().
					$slug  = sanitize_title( substr( $key, 7 ) );
					$terms = self::list( $raw );
					if ( '' !== $slug && array() !== $terms ) {
						$terms = $allowed_slugs( 'pa_' . $slug, $terms );
						if ( array() !== $terms ) {
							$state['attrs'][ $slug ] = $terms;
						}
					}
			}
		}

		if ( null !== $state['min_price'] && null !== $state['max_price'] && $state['min_price'] > $state['max_price'] ) {
			[ $state['min_price'], $state['max_price'] ] = [ $state['max_price'], $state['min_price'] ];
		}
		ksort( $state['attrs'] );
		return $state;
	}

	/**
	 * Is the key one `normalise()` reads?
	 *
	 * @param string $key Query key.
	 */
	private static function is_catalog_key( string $key ): bool {
		return in_array( $key, [ 'orderby', 'min_price', 'max_price', 'rating_filter', 'product_cat' ], true ) || str_starts_with( $key, 'filter_' );
	}

	/**
	 * A query value as one trimmed string (arrays: first scalar element).
	 *
	 * @param mixed $raw Raw value.
	 */
	private static function scalar( $raw ): string {
		if ( is_array( $raw ) ) {
			$raw = reset( $raw );
		}
		return is_scalar( $raw ) ? substr( trim( (string) wp_unslash( $raw ) ), 0, self::MAX_LENGTH ) : '';
	}

	/**
	 * A list value (comma string or array) as unique slug-safe strings: at most MAX_VALUES, each at most MAX_LENGTH chars.
	 *
	 * @param mixed $raw Raw value.
	 * @return string[]
	 */
	private static function list( $raw ): array {
		$parts = [];
		foreach ( is_array( $raw ) ? $raw : [ $raw ] as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			foreach ( explode( ',', substr( (string) wp_unslash( $item ), 0, 1000 ) ) as $part ) {
				$part = sanitize_title( substr( trim( $part ), 0, self::MAX_LENGTH ) );
				if ( '' !== $part ) {
					$parts[ $part ] = true;
				}
				if ( count( $parts ) >= self::MAX_VALUES ) {
					break 2;
				}
			}
		}
		$out = array_map( 'strval', array_keys( $parts ) );
		sort( $out );
		return $out;
	}

	/**
	 * A price bound: a non-negative number clamped to 0..MAX_PRICE, floored to an integer; anything else = null.
	 *
	 * @param mixed $raw Raw value.
	 */
	private static function price( $raw ): ?int {
		$value = self::scalar( $raw );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return (int) max( 0, min( self::MAX_PRICE, floor( (float) $value ) ) );
	}

	/**
	 * Canonical query string of a state (no leading `?`): defaults omitted, keys sorted, comma lists sorted, the list separator is the encoded comma `%2C`. The same state always
	 * gives byte-identical output (the JS mirror of TASK-043c and the QA check compare it). `page` is NOT part of it (path segment).
	 *
	 * @param array<string,mixed> $state State from normalise().
	 */
	public static function serialise( array $state ): string {
		$pairs = [];
		$list  = static function ( array $values ): string {
			$values = array_map( 'strval', $values );
			sort( $values );
			// `%2C`, exactly as WordPress canonicalises the URL (redirect_canonical re-encodes a literal comma): no extra 301 hop.
			return implode( '%2C', array_map( static fn( string $v ): string => rawurlencode( $v ), $values ) );
		};

		if ( '' !== (string) ( $state['orderby'] ?? '' ) ) {
			$pairs['orderby'] = rawurlencode( (string) $state['orderby'] );
		}
		foreach ( [ 'min_price', 'max_price' ] as $bound ) {
			if ( isset( $state[ $bound ] ) ) {
				$pairs[ $bound ] = (string) (int) $state[ $bound ];
			}
		}
		if ( ! empty( $state['stock'] ) ) {
			$pairs['filter_stock_status'] = $list( (array) $state['stock'] );
		}
		if ( ! empty( $state['brand'] ) ) {
			$pairs['filter_brand'] = $list( (array) $state['brand'] );
		}
		foreach ( (array) ( $state['attrs'] ?? [] ) as $slug => $terms ) {
			if ( ! empty( $terms ) ) {
				$pairs[ 'filter_' . $slug ]     = $list( (array) $terms );
				$pairs[ 'query_type_' . $slug ] = 'or';
			}
		}
		if ( ! empty( $state['rating'] ) ) {
			$ints = array_map( 'intval', (array) $state['rating'] );
			sort( $ints );
			$pairs['rating_filter'] = implode( '%2C', $ints );
		}
		if ( ! empty( $state['on_sale'] ) ) {
			$pairs['filter_on_sale'] = '1';
		}
		if ( (int) ( $state['discount'] ?? 0 ) > 0 ) {
			$pairs['filter_discount'] = (string) (int) $state['discount'];
		}
		if ( '' !== (string) ( $state['cat'] ?? '' ) ) {
			$pairs['product_cat'] = rawurlencode( (string) $state['cat'] );
		}

		ksort( $pairs );
		$out = [];
		foreach ( $pairs as $key => $value ) {
			$out[] = $key . '=' . $value;
		}
		return implode( '&', $out );
	}

	/**
	 * Stable short hash of a state (cache keys).
	 *
	 * @param array<string,mixed> $state State.
	 */
	public static function hash( array $state ): string {
		return substr( md5( self::serialise( $state ) ), 0, 16 );
	}

	/**
	 * Number of active filter groups (price counts once; the sort is not a filter).
	 *
	 * @param array<string,mixed> $state State.
	 */
	public static function active_count( array $state ): int {
		$n = ( isset( $state['min_price'] ) || isset( $state['max_price'] ) ) ? 1 : 0;
		foreach ( [ 'stock', 'brand', 'rating' ] as $group ) {
			$n += empty( $state[ $group ] ) ? 0 : 1;
		}
		foreach ( (array) ( $state['attrs'] ?? [] ) as $terms ) {
			$n += empty( $terms ) ? 0 : 1;
		}
		$n += empty( $state['on_sale'] ) ? 0 : 1;
		$n += (int) ( $state['discount'] ?? 0 ) > 0 ? 1 : 0;
		$n += '' !== (string) ( $state['cat'] ?? '' ) ? 1 : 0;
		return $n;
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
