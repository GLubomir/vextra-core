<?php
/**
 * `/page/N/` duplicate guard (TASK-043a, SEO review MAJOR-3).
 *
 * WordPress serves `/<page>/page/2/` (and `/catalog/page/2/`, the static front page `/page/2/`) with the content of page 1,
 * an indexable duplicate. On `template_redirect` priority 5 (before `redirect_canonical`, priority 10):
 * - shop ROOT with `paged > 1` -> 404 (the shop page is a categories page, it has no pagination);
 * - any singular page (and the static front page) with `paged > 1` and no `<!--nextpage-->` -> 404;
 * - the sale page is valid up to its own max page (filter `vextra/catalog/sale_max_pages`, default 1).
 * Archives (`product_cat`, tags, search) and the posts index (`is_home()`) are NOT touched: WordPress already 404s beyond range.
 * Fails open: an exception leaves the response as WordPress built it.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Catalog\SalePage;
use Vextra\Core\Guard\Failsafe;

final class PagedGuard {

	/**
	 * Highest page number answered at all (SEC-063).
	 */
	private const MAX_PAGED = 10000;

	/**
	 * The main query was cut by clamp() (the 404 status is sent in guard()).
	 *
	 * @var bool
	 */
	private bool $clamped = false;

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'guard' ], 5 );
		add_action( 'pre_get_posts', [ $this, 'clamp' ], 0 );
	}

	/**
	 * SEC-063: a page number above a sane ceiling (archives, search, `?paged=`) is a 404 BEFORE any query runs: `(paged - 1) * per_page`
	 * overflows for huge values and the query answered 200 with an empty list.
	 *
	 * @param mixed $query Query.
	 */
	public function clamp( $query ): void {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || is_admin() ) {
			return;
		}
		$paged = $query->get( 'paged' );
		if ( ! is_numeric( $paged ) || (float) $paged <= self::MAX_PAGED ) {
			return;
		}
		$this->clamped = true;
		$query->set_404();
		$query->set( 'paged', 1 );
		$query->set( 'no_found_rows', true );
		add_filter( 'posts_pre_query', static fn( $posts, $q ) => $q === $query ? [] : $posts, 10, 2 );
	}

	public function guard(): void {
		if ( $this->clamped ) {
			$this->not_found();
			return;
		}
		if ( is_admin() || is_404() ) {
			return;
		}
		Failsafe::run( 'paged-guard', fn() => $this->check() );
	}

	private function check(): void {
		$paged = (int) get_query_var( 'paged' );
		if ( $paged < 2 ) {
			return;
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			$this->not_found();
			return;
		}

		if ( ! is_singular() || is_home() ) {
			return;
		}

		if ( SalePage::is_sale_page() ) {
			if ( $paged > SalePage::max_pages() ) {
				$this->not_found();
			}
			return;
		}

		$post = get_queried_object();
		if ( $post instanceof \WP_Post && str_contains( (string) $post->post_content, '<!--nextpage-->' ) ) {
			return;
		}
		$this->not_found();
	}

	private function not_found(): void {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		// Core's canonical redirect sends a 404 that still carries page_id / p to that post's permalink (the front page: /page/2/ to /).
		$wp_query->set( 'page_id', 0 );
		$wp_query->set( 'p', 0 );
		$wp_query->set( 'page', 0 ); // Core also strips a non-existing `<!--nextpage-->` number from a 404 post (`/page/2/` on the static front page).
		// Core would 301 a 404 with a guessable slug (`/about/page/2/` -> `/about/`): the duplicate must answer 404.
		add_filter( 'do_redirect_guess_404_permalink', '__return_false' );
	}
}
