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

use Vextra\Core\Integrations\RankMath\RankMath;

final class Sitemap {

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

	public function __construct( RankMath $rank_math, TermCounts $counts ) {
		$this->rank_math = $rank_math;
		$this->counts    = $counts;
	}

	public function register(): void {
		add_filter( 'get_terms_args', [ $this, 'widen_term_query' ], 20, 2 );
		$this->rank_math->filter_sitemap_entry( [ $this, 'filter_entry' ] );
		$this->rank_math->filter_sitemap_archive_link( [ $this, 'filter_archive_link' ] );
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
