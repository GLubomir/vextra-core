<?php
/**
 * Marker contract of the smoke checks: HTML string in, list of failed markers out. Pure logic, no WordPress calls.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Compat;

final class Markers {

	/**
	 * Pages we want indexed (ADR-002); the others must carry noindex.
	 */
	private const INDEXABLE = [ 'home', 'shop', 'category', 'subcategory', 'product' ];

	/**
	 * Expectations of a page key.
	 *
	 * @param string $page      Page key: home, shop, category, subcategory, product, cart, account, search, 404.
	 * @param int    $languages Number of languages (hreflang count = languages + x-default when > 1).
	 * @param bool   $assert_index Assert index + canonical + hreflang on indexable pages (false = not asserted: a non-public site prints none of them).
	 * @param string $lang_attr Expected `<html lang>` (case-insensitive, `-`/`_` equivalent), '' to skip.
	 * @return array<string,mixed>
	 */
	public static function expectation( string $page, int $languages, bool $assert_index, string $lang_attr = '' ): array {
		$indexable = in_array( $page, self::INDEXABLE, true );
		$jsonld    = [];
		if ( 'home' === $page ) {
			$jsonld = [ 'WebSite' ];
		} elseif ( 'product' === $page ) {
			$jsonld = [ 'Product', 'BreadcrumbList' ];
		} elseif ( 'category' === $page || 'subcategory' === $page ) {
			$jsonld = [ 'BreadcrumbList' ];
		}
		return [
			'chrome'    => true,
			'h1'        => 1,
			'canonical' => $indexable && $assert_index ? 1 : 'max1',
			'robots'    => $indexable ? ( $assert_index ? 'index' : null ) : 'noindex',
			'hreflang'  => $indexable && $assert_index && $languages > 1 ? $languages + 1 : null,
			'jsonld'    => $jsonld,
			'lang'      => $lang_attr,
		];
	}

	/**
	 * Check an HTML document.
	 *
	 * @param string              $html   Response body.
	 * @param array<string,mixed> $expect See expectation().
	 * @return string[] Failed marker names (empty = pass).
	 */
	public static function check( string $html, array $expect ): array {
		$fail = [];

		if ( self::has_php_error( $html ) ) {
			$fail[] = 'php-notice';
		}
		if ( ! empty( $expect['chrome'] ) ) {
			if ( 1 !== preg_match( '/class=["\'][^"\']*(?<![\w-])vx-header(?![\w-])/i', $html ) ) {
				$fail[] = 'no-header';
			}
			if ( 1 !== preg_match( '/class=["\'][^"\']*(?<![\w-])vx-footer(?![\w-])/i', $html ) ) {
				$fail[] = 'no-footer';
			}
		}
		if ( isset( $expect['h1'] ) && (int) preg_match_all( '/<h1[\s>]/i', $html ) !== (int) $expect['h1'] ) {
			$fail[] = 'h1-count';
		}
		if ( isset( $expect['canonical'] ) ) {
			$count = (int) preg_match_all( '/<link\b[^>]*\brel=["\']canonical["\']/i', $html );
			if ( 'max1' === $expect['canonical'] ? $count > 1 : (int) $expect['canonical'] !== $count ) {
				$fail[] = 'canonical-count';
			}
		}
		if ( isset( $expect['robots'] ) && '' !== $expect['robots'] ) {
			$robots = self::robots( $html );
			if ( 'noindex' === $expect['robots'] && 'noindex' !== $robots ) {
				$fail[] = 'robots-noindex-expected';
			} elseif ( 'index' === $expect['robots'] && 'noindex' === $robots ) {
				$fail[] = 'robots-index-expected';
			}
		}
		if ( isset( $expect['hreflang'] ) && self::hreflang_count( $html ) !== (int) $expect['hreflang'] ) {
			$fail[] = 'hreflang-count';
		}
		if ( ! empty( $expect['jsonld'] ) ) {
			$types = self::jsonld_types( $html );
			foreach ( (array) $expect['jsonld'] as $type ) {
				if ( ! in_array( $type, $types, true ) ) {
					$fail[] = 'jsonld-' . $type;
				}
			}
		}
		if ( ! empty( $expect['lang'] ) ) {
			$lang = self::html_lang( $html );
			if ( self::normalize_lang( $lang ) !== self::normalize_lang( (string) $expect['lang'] ) ) {
				$fail[] = 'html-lang';
			}
		}
		return $fail;
	}

	/**
	 * PHP error text or the WordPress fatal-error screen in the body.
	 *
	 * @param string $html Response body.
	 */
	public static function has_php_error( string $html ): bool {
		return 1 === preg_match( '/(?:Fatal error|Parse error|Warning|Notice|Deprecated)(?:<\/b>)?:\s+.{1,400}?\bon line\b/is', $html )
			|| 1 === preg_match( '/\bUncaught\s+(?:\w+\\\\)*\w*(?:Error|Exception)\b/', $html )
			|| false !== stripos( $html, 'There has been a critical error' )
			|| false !== stripos( $html, 'На сайті сталася критична помилка' );
	}

	/**
	 * Robots verdict of the document: `noindex` | `index` | `none` (no robots meta).
	 *
	 * @param string $html Response body.
	 */
	public static function robots( string $html ): string {
		if ( 1 !== preg_match( '/<meta\b(?=[^>]*\bname=["\']robots["\'])[^>]*\bcontent=["\']([^"\']*)["\']/i', $html, $m ) ) {
			return 'none';
		}
		return false !== stripos( $m[1], 'noindex' ) ? 'noindex' : 'index';
	}

	/**
	 * Count of `<link rel="alternate" hreflang=...>`.
	 *
	 * @param string $html Response body.
	 */
	public static function hreflang_count( string $html ): int {
		return (int) preg_match_all( '/<link\b(?=[^>]*\brel=["\']alternate["\'])(?=[^>]*\bhreflang=)[^>]*>/i', $html );
	}

	/**
	 * `@type` values of all JSON-LD blocks (including `@graph`).
	 *
	 * @param string $html Response body.
	 * @return string[]
	 */
	public static function jsonld_types( string $html ): array {
		$types = [];
		if ( false === preg_match_all( '/<script\b[^>]*application\/ld\+json[^>]*>(.*?)<\/script>/is', $html, $blocks ) ) {
			return [];
		}
		foreach ( $blocks[1] as $raw ) {
			$data = json_decode( trim( html_entity_decode( $raw, ENT_QUOTES ) ), true );
			if ( is_array( $data ) ) {
				self::collect_types( $data, $types );
			}
		}
		return array_values( array_unique( $types ) );
	}

	/**
	 * Value of `<html lang>`.
	 *
	 * @param string $html Response body.
	 */
	public static function html_lang( string $html ): string {
		return 1 === preg_match( '/<html\b[^>]*\blang=["\']([^"\']+)["\']/i', $html, $m ) ? $m[1] : '';
	}

	/**
	 * Lowercase language tag with `-` separators.
	 *
	 * @param string $lang Tag or locale.
	 */
	private static function normalize_lang( string $lang ): string {
		return strtolower( str_replace( '_', '-', trim( $lang ) ) );
	}

	/**
	 * Recursively collect `@type` values.
	 *
	 * @param array<mixed> $node  JSON node.
	 * @param string[]     $types Output.
	 */
	private static function collect_types( array $node, array &$types ): void {
		foreach ( $node as $key => $value ) {
			if ( '@type' === $key ) {
				foreach ( (array) $value as $type ) {
					if ( is_string( $type ) ) {
						$types[] = $type;
					}
				}
			} elseif ( is_array( $value ) ) {
				self::collect_types( $value, $types );
			}
		}
	}
}
