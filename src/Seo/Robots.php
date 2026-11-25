<?php
/**
 * Robots of `product_cat` archives: noindex iff the category has no published product in itself or any descendant, or it
 * is explicitly noindex (term SEO meta / taxonomy setting). Replaces Rank Math's empty-term automation, which reads the
 * DIRECT-only `$term->count` (parents look empty).
 *
 * Facet branch (TASK-043a, runs on ANY template): a request whose query string carries a facet / sort / search / `product_cat` /
 * `post_type` / `paged` parameter (`Catalog\Params::has_facet()`), a search, a 404 (the paged-duplicate guard answers 404 on a page Rank Math still thinks is indexable), or a sale page without products on sale answers
 * `noindex, follow`. Rank Math prints no canonical on noindex pages and Polylang no hreflang (`Multilang\Hreflang`).
 *
 * Category branch scope: the main queried `product_cat` term only. `product_tag`, `pa_*`, search, shop archive, singular and everything else
 * pass through untouched. Never turns a noindex into index when the site is not public (`blog_public` = 0), for
 * multi-term (filter) archives, or for paged archives when Rank Math is configured to noindex sub-pages.
 * Fails open: any error leaves Rank Math's own directives.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Catalog\Params;
use Vextra\Core\Catalog\SalePage;
use Vextra\Core\Catalog\SaleProducts;
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

	/**
	 * Index gate of the info pages (null = guard off).
	 *
	 * @var LaunchPages|null
	 */
	private ?LaunchPages $launch;

	public function __construct( RankMath $rank_math, TermCounts $counts, ?LaunchPages $launch = null ) {
		$this->rank_math = $rank_math;
		$this->counts    = $counts;
		$this->launch    = $launch;
	}

	public function register(): void {
		$this->rank_math->filter_robots( [ $this, 'filter' ] );
	}

	/**
	 * Facet / search / paged-argument URLs and an empty sale page: `noindex, follow` (never touches a private site's directives).
	 *
	 * @param array<string,mixed> $robots Directive map.
	 * @return array<string,mixed>|null The forced directives, null when the URL is not affected.
	 */
	private function noindex_urls( array $robots ): ?array {
		try {
			if ( 0 === absint( get_option( 'blog_public' ) ) ) {
				return null;
			}

			$force = Params::has_facet( $_GET ) || is_search() || is_404(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- keys only, nothing is read or output.
			if ( ! $force && SalePage::is_sale_page() ) {
				$force = 0 === SaleProducts::count( SalePage::language_of( (int) get_queried_object_id() ) );
			}
			if ( $force ) {
				$robots['index']  = 'noindex';
				$robots['follow'] = 'follow';
				return $robots;
			}
		} catch ( \Throwable $e ) {
			$this->warn( $e );
		}
		return $this->noindex_pages( $robots );
	}

	/**
	 * Info pages (TASK-047a): the order-tracking page is always `noindex, follow`; a launch page that fails the gate (too short or still holding an owner
	 * placeholder) is `noindex, follow` even when Rank Math's meta says index. Fails CLOSED: an error keeps the page out of the index.
	 *
	 * @param array<string,mixed> $robots Directive map.
	 * @return array<string,mixed>|null The forced directives, null when the page is not affected.
	 */
	private function noindex_pages( array $robots ): ?array {
		if ( null === $this->launch || ! is_page() || 0 === absint( get_option( 'blog_public' ) ) ) {
			return null;
		}
		$force = false;
		try {
			$id = (int) get_queried_object_id();
			if ( $this->launch->is_track_page( $id ) ) {
				$force = true;
			} elseif ( '' !== $this->launch->launch_slug( $id ) ) {
				$force = ! $this->launch->inspect( $id )['ok'];
			}
		} catch ( \Throwable $e ) {
			$this->warn( $e );
			$force = true;
		}
		if ( ! $force ) {
			return null;
		}
		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';
		return $robots;
	}

	/**
	 * Log a failure once per request.
	 *
	 * @param \Throwable $e Error.
	 */
	private function warn( \Throwable $e ): void {
		if ( ! $this->warned ) {
			$this->warned = true;
			Log::warning( 'Robots filter failed, Rank Math directives kept: ' . $e->getMessage(), [], 'seo' );
		}
	}

	/**
	 * Rank Math robots filter.
	 *
	 * @param mixed $robots Directive map, e.g. `[ 'index' => 'noindex', 'follow' => 'follow' ]`.
	 * @return mixed
	 */
	public function filter( $robots ) {
		if ( ! is_array( $robots ) ) {
			return $robots;
		}

		// Forced noindex is final: the category branch below must never turn it back into `index`.
		$forced = $this->noindex_urls( $robots );
		if ( null !== $forced ) {
			return $forced;
		}
		if ( ! is_tax( TermCounts::TAXONOMY ) ) {
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
			$this->warn( $e );
		}

		return $robots;
	}
}
