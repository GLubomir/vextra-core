<?php
/**
 * Robots of `product_cat` archives: noindex iff the category has no published product in itself or any descendant, or it
 * is explicitly noindex (term SEO meta / taxonomy setting). Replaces Rank Math's empty-term automation, which reads the
 * DIRECT-only `$term->count` (parents look empty).
 *
 * Scope: the main queried `product_cat` term only. `product_tag`, `pa_*`, search, shop archive, singular and everything else
 * pass through untouched. Never turns a noindex into index when the site is not public (`blog_public` = 0), for
 * multi-term (filter) archives, or for paged archives when Rank Math is configured to noindex sub-pages.
 * Fails open: any error leaves Rank Math's own directives.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Support\Log;

final class Robots {

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
	 * A failure was already logged this request.
	 *
	 * @var bool
	 */
	private bool $warned = false;

	public function __construct( RankMath $rank_math, TermCounts $counts ) {
		$this->rank_math = $rank_math;
		$this->counts    = $counts;
	}

	public function register(): void {
		$this->rank_math->filter_robots( [ $this, 'filter' ] );
	}

	/**
	 * Rank Math robots filter.
	 *
	 * @param mixed $robots Directive map, e.g. `[ 'index' => 'noindex', 'follow' => 'follow' ]`.
	 * @return mixed
	 */
	public function filter( $robots ) {
		if ( ! is_array( $robots ) || ! is_tax( TermCounts::TAXONOMY ) ) {
			return $robots;
		}

		try {
			$term = get_queried_object();
			if ( ! $term instanceof \WP_Term || TermCounts::TAXONOMY !== $term->taxonomy ) {
				return $robots;
			}

			// Private site (blog_public = 0) and multi-term archives stay exactly as Rank Math decided.
			if ( 0 === absint( get_option( 'blog_public' ) ) || $this->rank_math->is_multiple_terms_query() ) {
				return $robots;
			}

			if ( $this->rank_math->is_term_noindex( $term->term_id, $term->taxonomy ) || ! $this->counts->has_products( $term->term_id ) ) {
				$robots['index'] = 'noindex';
				return $robots;
			}

			if ( is_paged() && $this->rank_math->noindexes_subpages() ) {
				return $robots;
			}

			$robots['index'] = 'index';
		} catch ( \Throwable $e ) {
			if ( ! $this->warned ) {
				$this->warned = true;
				Log::warning( 'Category robots filter failed, Rank Math directives kept: ' . $e->getMessage(), [], 'seo' );
			}
		}

		return $robots;
	}
}
