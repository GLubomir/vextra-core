<?php
/**
 * Rank Math term sitemap for `product_cat`: both languages, hierarchical emptiness rule, no duplicates.
 *
 * Root causes fixed here (verified, Rank Math 1.0.282 + Polylang 3.9.2):
 * 1. The term sitemap runs `get_terms( hide_empty => true )`, i.e. on the DIRECT-only `term_taxonomy.count`, so parents whose
 *    products sit in children never appear. During a sitemap request the query is widened (`hide_empty` off) and every entry is
 *    then filtered by `TermCounts::is_indexable()`.
 * 2. Polylang adds the current-language filter to `get_terms`; the sitemap request runs in the default language, so RU terms
 *    never appear. During a sitemap request `lang` is set to '' (all languages).
 * 3. The product sitemap lists the shop archive `/catalog/` first, the page sitemap lists the shop PAGE: the archive row is removed
 *    (the page, both languages, stays).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Catalog\SalePage;
use Vextra\Core\Catalog\SaleProducts;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;

final class Sitemap {

	/**
	 * Demo/noindex page and post ids, memoised per request.
	 *
	 * @var int[]|null
	 */
	private static ?array $excluded = null;

	/**
	 * Rank Math adapter.
	 *
	 * @var RankMath
	 */
	private RankMath $rank_math;

	/**
	 * Hierarchical counts.
	 *
	 * @var TermCounts
	 */
	private TermCounts $counts;

	/**
	 * Polylang adapter (front page translations stay listed).
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	public function __construct( RankMath $rank_math, TermCounts $counts, Polylang $polylang ) {
		$this->rank_math = $rank_math;
		$this->counts    = $counts;
		$this->polylang  = $polylang;
	}

	public function register(): void {
		add_filter( 'get_terms_args', [ $this, 'widen_term_query' ], 20, 2 );
		$this->rank_math->filter_sitemap_entry( [ $this, 'filter_entry' ] );
		$this->rank_math->filter_sitemap_archive_link( [ $this, 'filter_archive_link' ] );
		$this->rank_math->exclude_posts_from_sitemap( [ $this, 'excluded_ids' ] );
	}

	/**
	 * Demo PAGES/POSTS (meta `_vx_demo`, every language; the front page and its translations excepted) and pages/posts with a Rank Math `noindex` never reach a sitemap, even when
	 * Rank Math's own exclusion is bypassed (belt and braces). Products are deliberately NOT covered: the demo catalogue stays listed
	 * until it is replaced by the real import (launch checklist).
	 *
	 * @param mixed $ids Ids already excluded.
	 * @return int[]
	 */
	public function excluded_ids( $ids = [] ): array {
		if ( null === self::$excluded ) {
			$query = new \WP_Query(
				[
					'post_type'              => [ 'page', 'post' ],
					'post_status'            => 'any',
					'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded, memoised.
					'fields'                 => 'ids',
					'lang'                   => '',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'orderby'                => 'ID',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded (500), sitemap generation only, result memoised.
					'meta_query'             => [
						'relation' => 'OR',
						[
							'key'     => '_vx_demo',
							'compare' => 'EXISTS',
						],
						[
							'key'     => 'rank_math_robots',
							'value'   => 'noindex',
							'compare' => 'LIKE',
						],
					],
				]
			);
			// The front page of every language is demo content too, but it is THE indexable page: it always stays listed.
			$front          = (int) get_option( 'page_on_front' );
			$keep           = $front > 0 ? array_merge( [ $front ], array_values( $this->polylang->translations( $front ) ) ) : [];
			self::$excluded = array_values( array_diff( array_map( 'intval', (array) $query->posts ), array_map( 'intval', $keep ) ) );
		}
		return array_values( array_unique( array_merge( array_map( 'intval', is_array( $ids ) ? $ids : [] ), self::$excluded, $this->empty_sale_pages() ) ) );
	}

	/**
	 * Sale pages of the languages that have no product on sale now (they are noindex, see `Robots`, and must not be listed).
	 *
	 * @return int[]
	 */
	private function empty_sale_pages(): array {
		$out = [];
		foreach ( SalePage::map() as $lang => $page_id ) {
			if ( 0 === SaleProducts::count( (string) $lang ) ) {
				$out[] = $page_id;
			}
		}
		return $out;
	}

	/**
	 * `get_terms_args`: sitemap requests only, `product_cat` only.
	 *
	 * @param mixed $args       WP_Term_Query args.
	 * @param mixed $taxonomies Queried taxonomies.
	 * @return mixed
	 */
	public function widen_term_query( $args, $taxonomies ) {
		if ( ! is_array( $args ) || array( TermCounts::TAXONOMY ) !== array_values( (array) $taxonomies ) || ! $this->rank_math->is_sitemap_request() ) {
			return $args;
		}
		$args['hide_empty'] = false;
		$args['lang']       = '';
		return $args;
	}

	/**
	 * Drop terms that are not indexable by the hierarchical rule (empty, or manually noindex).
	 *
	 * @param mixed  $url    Sitemap entry.
	 * @param string $type   Entry type.
	 * @param mixed  $term   Term/post/user object.
	 * @return mixed
	 */
	public function filter_entry( $url, $type = '', $term = null ) {
		if ( 'term' !== $type || ! $term instanceof \WP_Term || TermCounts::TAXONOMY !== $term->taxonomy ) {
			return $url;
		}
		return $this->counts->is_indexable( $term->term_id ) ? $url : [];
	}

	/**
	 * Remove the `product` archive row when the shop page exists (it is listed by the page sitemap).
	 *
	 * @param mixed  $url       Archive URL.
	 * @param string $post_type Post type.
	 * @return mixed
	 */
	public function filter_archive_link( $url, $post_type = '' ) {
		if ( 'product' === $post_type && function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ) {
			return '';
		}
		return $url;
	}
}
