<?php
/**
 * Breadcrumb data per language (Rank Math builds the visible trail and the BreadcrumbList JSON-LD from the same crumb array).
 *
 * - Home crumb: label `Головна` / `Главная`, URL = language home WITH trailing slash (`/`, `/ru/`).
 * - `Каталог` crumb (translated shop page title + URL) directly after home on category and product pages (ADR-002 s6);
 *   not added on the shop page itself, cart, checkout, info pages, home.
 * - Product trail category: Rank Math primary `product_cat` if set, else the deepest assigned category (tie: lowest term id),
 *   decided on the default-language product; a translation uses the translated term of that choice (same trail in every language).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;

final class Breadcrumbs {

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	/**
	 * Rank Math adapter.
	 *
	 * @var RankMath
	 */
	private RankMath $rank_math;

	/**
	 * Strings provider.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	public function __construct( Polylang $polylang, RankMath $rank_math, Templates $templates ) {
		$this->polylang  = $polylang;
		$this->rank_math = $rank_math;
		$this->templates = $templates;
	}

	public function register(): void {
		$this->rank_math->filter_breadcrumb_items( [ $this, 'items' ] );
		$this->rank_math->filter_breadcrumb_main_term( [ $this, 'main_term' ] );
	}

	/**
	 * Crumb array filter.
	 *
	 * @param mixed $crumbs Rank Math crumbs.
	 * @return mixed
	 */
	public function items( $crumbs ) {
		if ( ! is_array( $crumbs ) || array() === $crumbs ) {
			return $crumbs;
		}
		$lang = $this->polylang->current_language();
		$home = $this->polylang->home_url( $lang );
		if ( '' === $home ) {
			return $crumbs;
		}

		$crumbs = array_values( $crumbs );
		$first  = $crumbs[0];
		if ( ! is_array( $first ) || ! isset( $first[1] ) || ! $this->is_home_url( (string) $first[1], $home ) ) {
			return $crumbs;
		}
		$crumbs[0][0] = $this->templates->strings( $lang )['home_label'];
		$crumbs[0][1] = $home;

		if ( is_search() ) {
			// "Головна > Пошук" (TASK-043b): the query text is the H1, never part of the trail.
			return [
				$crumbs[0],
				[
					'ru' === $lang ? 'Поиск' : 'Пошук',
					'',
					'hide_in_schema' => false,
				],
			];
		}

		if ( ! is_tax( TermCounts::TAXONOMY ) && ! is_singular( 'product' ) ) {
			return $crumbs;
		}

		$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		$url     = $shop_id > 0 ? (string) get_permalink( $shop_id ) : '';
		if ( '' === $url ) {
			return $crumbs;
		}
		foreach ( $crumbs as $crumb ) {
			if ( is_array( $crumb ) && isset( $crumb[1] ) && trailingslashit( (string) $crumb[1] ) === trailingslashit( $url ) ) {
				return $crumbs;
			}
		}

		array_splice(
			$crumbs,
			1,
			0,
			[
				[
					wp_strip_all_tags( get_the_title( $shop_id ) ),
					$url,
					'hide_in_schema' => false,
				],
			]
		);
		return $crumbs;
	}

	/**
	 * Category of the product trail.
	 *
	 * @param mixed $term  Rank Math's choice.
	 * @param mixed $terms Terms assigned to the product.
	 * @return mixed
	 */
	public function main_term( $term, $terms = [] ) {
		if ( ! is_singular( 'product' ) || ! is_array( $terms ) || array() === $terms ) {
			return $term;
		}
		$post_id = (int) get_queried_object_id();
		$lang    = $this->polylang->post_language( $post_id );
		$default = $this->polylang->default_language();

		if ( '' !== $default && '' !== $lang && $lang !== $default ) {
			$base_id = $this->polylang->translate_post( $post_id, $default );
			if ( $base_id > 0 ) {
				$base_terms = get_the_terms( $base_id, TermCounts::TAXONOMY );
				$pick       = is_array( $base_terms ) ? $this->pick( $base_id, $base_terms ) : null;
				if ( null !== $pick ) {
					$translated = $this->polylang->translate_term( $pick->term_id, $lang );
					foreach ( $terms as $candidate ) {
						if ( $candidate instanceof \WP_Term && $candidate->term_id === $translated ) {
							return $candidate;
						}
					}
				}
			}
		}

		return $this->pick( $post_id, $terms ) ?? $term;
	}

	/**
	 * Primary term, else deepest, tie lowest id.
	 *
	 * @param int              $post_id Product ID.
	 * @param array<int,mixed> $terms   Assigned terms.
	 */
	private function pick( int $post_id, array $terms ): ?\WP_Term {
		$terms = array_values( array_filter( $terms, static fn( $t ): bool => $t instanceof \WP_Term ) );
		if ( array() === $terms ) {
			return null;
		}
		$primary = $this->rank_math->primary_term_id( $post_id, TermCounts::TAXONOMY );
		foreach ( $terms as $t ) {
			if ( 0 < $primary && $primary === $t->term_id ) {
				return $t;
			}
		}
		$best       = null;
		$best_depth = -1;
		foreach ( $terms as $t ) {
			$depth = count( get_ancestors( $t->term_id, TermCounts::TAXONOMY ) );
			if ( $depth > $best_depth || ( $depth === $best_depth && null !== $best && $t->term_id < $best->term_id ) ) {
				$best       = $t;
				$best_depth = $depth;
			}
		}
		return $best;
	}

	/**
	 * Whether a crumb URL is the site/language root.
	 *
	 * @param string $url  Crumb URL.
	 * @param string $home Language home with trailing slash.
	 */
	private function is_home_url( string $url, string $home ): bool {
		$url = untrailingslashit( $url );
		return in_array( $url, [ untrailingslashit( $home ), untrailingslashit( home_url() ) ], true );
	}
}
