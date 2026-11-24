<?php
/**
 * Cached term-level catalog data (TASK-043b): subcategory pills, back-to-parent, accessory links and the shop-root category cards.
 *
 * One cache entry per (term, language) in the group `catalog-terms` (`Support\Cache::remember`, key includes the language, never
 * cached under WP-CLI), flushed by `Catalog` on product / term / stock changes. Every link is a clean `get_term_link()` URL; only
 * non-empty categories (hierarchical `Seo\TermCounts`) are ever listed (empty ones are noindex and never linked). Counts are returned
 * as data, never put into anchor text (SEO MINOR-6).
 * Row shape: `[ id, name, url, count, thumb_id ]`.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;
use Vextra\Core\Seo\TermCounts;
use Vextra\Core\Support\Cache;

final class TermData {

	public const GROUP = 'catalog-terms';

	private const TTL = HOUR_IN_SECONDS;

	/**
	 * Hierarchical counts (memoised across calls).
	 *
	 * @var TermCounts|null
	 */
	private static ?TermCounts $counts = null;

	/**
	 * Shared hierarchical term counts.
	 */
	public static function counts(): TermCounts {
		if ( null === self::$counts ) {
			$rank         = Plugin::instance()->integration( 'rankmath' );
			self::$counts = new TermCounts( $rank instanceof RankMath ? $rank : new RankMath() );
			self::$counts->register();
		}
		return self::$counts;
	}

	/**
	 * Current language slug ('' without Polylang).
	 */
	private static function lang(): string {
		$pll = Plugin::instance()->integration( 'polylang' );
		return $pll instanceof Polylang ? $pll->current_language() : '';
	}

	/**
	 * Pills / parent / siblings / accessories of a `product_cat` term in the current language.
	 *
	 * @param int $term_id Term id.
	 * @return array{children:array,siblings:array,parent:?array,accessories:array}
	 */
	public static function bundle( int $term_id ): array {
		$data = Cache::remember(
			self::GROUP,
			'term:' . self::lang() . ':' . $term_id,
			self::TTL,
			fn(): array => self::build( $term_id )
		);
		return is_array( $data ) ? $data : self::build( $term_id );
	}

	/**
	 * Top-level categories of the current language for the shop root: non-empty, with image id, count and up to 4 sub links.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function shop_cards(): array {
		$data = Cache::remember( self::GROUP, 'shop:' . self::lang(), self::TTL, static fn(): array => self::build_shop() );
		return is_array( $data ) ? $data : self::build_shop();
	}

	/**
	 * Invalidate everything (language-wide).
	 */
	public static function flush(): void {
		Cache::flush( self::GROUP );
	}

	/**
	 * Row of a term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array{id:int,name:string,url:string,count:int,thumb_id:int}|null
	 */
	private static function row( \WP_Term $term ): ?array {
		$url = get_term_link( $term );
		if ( ! is_string( $url ) ) {
			return null;
		}
		return [
			'id'       => (int) $term->term_id,
			'name'     => $term->name,
			'url'      => $url,
			'count'    => self::counts()->count( (int) $term->term_id ),
			'thumb_id' => (int) get_term_meta( (int) $term->term_id, 'thumbnail_id', true ),
		];
	}

	/**
	 * Non-empty children of a parent id (0 = top level), default category excluded.
	 *
	 * @param int    $parent_id Parent term id.
	 * @param string $lang      Language slug of the build ('' = the current request language). A background rebuild (Action Scheduler) has no
	 *                          request language, so the shop root passes the language of its cache scope.
	 * @return array<int,array<string,mixed>>
	 */
	private static function children( int $parent_id, string $lang = '' ): array {
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'parent'     => $parent_id,
				'hide_empty' => false,
				'exclude'    => self::default_ids(),
				'orderby'    => 'name',
				// Explicit language: the SWR rebuild runs without a request language and listed both languages on the shop root (TASK-043c).
				'lang'       => '' !== $lang ? $lang : self::lang(),
			]
		);
		$out   = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			if ( $term instanceof \WP_Term && self::counts()->count( (int) $term->term_id ) > 0 ) {
				$row = self::row( $term );
				if ( null !== $row ) {
					$out[] = $row;
				}
			}
		}
		return $out;
	}

	/**
	 * The default category and its translations (never a pill or a card).
	 *
	 * @return int[]
	 */
	private static function default_ids(): array {
		$default = (int) get_option( 'default_product_cat' );
		$ids     = [ $default ];
		$pll     = Plugin::instance()->integration( 'polylang' );
		if ( $pll instanceof Polylang ) {
			$ids = array_merge( $ids, array_values( $pll->term_translations( $default ) ) );
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Cold build of a term bundle.
	 *
	 * @param int $term_id Term id.
	 * @return array{children:array,siblings:array,parent:?array,accessories:array}
	 */
	public static function build( int $term_id ): array {
		$term = get_term( $term_id, 'product_cat' );
		$out  = [
			'children'    => [],
			'siblings'    => [],
			'parent'      => null,
			'accessories' => [],
		];
		if ( ! $term instanceof \WP_Term ) {
			return $out;
		}

		$out['children'] = self::children( $term_id );
		if ( $term->parent > 0 ) {
			$parent = get_term( (int) $term->parent, 'product_cat' );
			if ( $parent instanceof \WP_Term ) {
				$out['parent'] = self::row( $parent );
			}
			if ( array() === $out['children'] ) {
				$out['siblings'] = self::children( (int) $term->parent );
			}
		}
		$out['accessories'] = AccessoryRules::for_term( $term_id, self::counts() );
		return $out;
	}

	/**
	 * Cold build of the shop-root cards.
	 *
	 * @param string $lang Language slug of the cache scope ('' = the current request language).
	 * @return array<int,array<string,mixed>>
	 */
	public static function build_shop( string $lang = '' ): array {
		$out = [];
		foreach ( self::children( 0, $lang ) as $row ) {
			$subs        = array_slice( self::children( (int) $row['id'] ), 0, 4 );
			$row['subs'] = array_map(
				static fn( array $sub ): array => [
					'id'   => $sub['id'],
					'name' => $sub['name'],
					'url'  => $sub['url'],
				],
				$subs
			);
			$out[]       = $row;
		}
		return $out;
	}
}
