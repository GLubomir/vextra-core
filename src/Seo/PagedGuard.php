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

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'guard' ], 5 );
	}

	public function guard(): void {
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
