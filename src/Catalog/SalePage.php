<?php
/**
 * The sale page `/aktsii/` (UA) and `/ru/aktsii/` (RU) (TASK-043a).
 *
 * The pages are ordinary WP pages (editor owns H1, intro and bottom SEO text), linked as Polylang translations and created /
 * repaired by `wp vextra catalog sale-page`. They are found BY ID (option `vx_sale_pages` = `[ lang => page_id ]`), never by
 * template name or slug, so a theme change or a slug edit does not break the SEO rules that depend on them. The RU twin shares
 * the UA slug: the same narrow slug-sharing as `Multilang\WooPages` (unique-slug filter + `pagename` -> `page_id` on request)
 * limited to these two pages.
 * Missing option / page: every helper answers false / 0 and nothing else changes.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;

final class SalePage {

	public const OPTION = 'vx_sale_pages';

	public const SLUG = 'aktsii';

	/**
	 * Polylang adapter (hooks only; the static helpers fetch the adapter from the kernel).
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

	public function __construct( Polylang $polylang, RankMath $rank_math ) {
		$this->polylang  = $polylang;
		$this->rank_math = $rank_math;
	}

	public function register(): void {
		$this->rank_math->filter_canonical( [ $this, 'canonical' ] );
		add_filter( 'wp_unique_post_slug', [ $this, 'share_slug' ], 10, 6 );
		add_filter( 'request', [ $this, 'resolve_shared_slug' ], 5 );
	}

	/**
	 * Stored map language slug => page id (only positive ids).
	 *
	 * @return array<string,int>
	 */
	public static function map(): array {
		$map = get_option( self::OPTION, [] );
		$out = [];
		foreach ( is_array( $map ) ? $map : [] as $lang => $id ) {
			if ( is_string( $lang ) && '' !== $lang && (int) $id > 0 ) {
				$out[ $lang ] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Page id of a language (current language when null), 0 when there is none.
	 *
	 * @param string|null $lang Language slug.
	 */
	public static function page_id( ?string $lang = null ): int {
		$map = self::map();
		if ( null === $lang || '' === $lang ) {
			$pll  = Plugin::instance()->integration( 'polylang' );
			$lang = $pll instanceof Polylang ? $pll->current_language() : '';
		}
		return $map[ $lang ] ?? 0;
	}

	/**
	 * Language slug of a sale page id, '' when the id is not a sale page.
	 *
	 * @param int $page_id Page id.
	 */
	public static function language_of( int $page_id ): string {
		$lang = array_search( $page_id, self::map(), true );
		return false === $lang ? '' : (string) $lang;
	}

	/**
	 * Is the main query the sale page of any language?
	 */
	public static function is_sale_page(): bool {
		if ( ! is_page() ) {
			return false;
		}
		$id = (int) get_queried_object_id();
		return $id > 0 && in_array( $id, self::map(), true );
	}

	/**
	 * Highest valid page number of the sale page (`vextra/catalog/sale_max_pages`, >= 1; TASK-043b supplies the real value).
	 */
	public static function max_pages(): int {
		/**
		 * Number of pages of the sale listing.
		 *
		 * @param int $pages Default 1 until the listing data layer reports it.
		 */
		return max( 1, (int) apply_filters( 'vextra/catalog/sale_max_pages', 1 ) );
	}

	/**
	 * Self canonical of page 2+ of the sale page (Rank Math prints the page permalink without `page/N/` for singular pages).
	 *
	 * @param mixed $url Canonical URL built by Rank Math.
	 * @return mixed
	 */
	public function canonical( $url ) {
		if ( ! is_string( $url ) || '' === $url || ! self::is_sale_page() ) {
			return $url;
		}
		$paged = (int) get_query_var( 'paged' );
		if ( $paged < 2 || $paged > self::max_pages() ) {
			return $url;
		}
		$link = get_permalink( (int) get_queried_object_id() );
		return is_string( $link ) && '' !== $link ? trailingslashit( $link ) . $GLOBALS['wp_rewrite']->pagination_base . '/' . $paged . '/' : $url;
	}

	/**
	 * Allow the shared slug inside the sale pages' translation group.
	 *
	 * @param string $slug          Slug after WordPress uniqueness handling.
	 * @param int    $post_id       Post ID.
	 * @param string $post_status   Status.
	 * @param string $post_type     Post type.
	 * @param int    $post_parent   Parent ID.
	 * @param string $original_slug Slug before uniqueness handling.
	 * @return string
	 */
	public function share_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$post_id = (int) $post_id;
		if ( 'page' !== $post_type || self::SLUG !== (string) $original_slug || $slug === $original_slug || $post_id <= 0 || 0 !== (int) $post_parent ) {
			return $slug;
		}

		$lang = $this->polylang->post_language( $post_id );
		if ( '' === $lang ) {
			return $slug;
		}

		// A page of the SAME language (or without one) that holds the slug keeps the suffix.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only lookup, rare (page save with this slug).
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'page' AND post_parent = 0 AND ID <> %d AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) LIMIT 20",
				$original_slug,
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $other ) {
			$other_lang = $this->polylang->post_language( (int) $other );
			if ( '' === $other_lang || $other_lang === $lang ) {
				return $slug;
			}
		}
		return (string) $original_slug;
	}

	/**
	 * `get_page_by_path()` is language-blind: `/ru/aktsii/` would resolve to the UA page. Replace `pagename` by the `page_id` of
	 * the sale page of the current language.
	 *
	 * @param mixed $vars Parsed query vars.
	 * @return mixed
	 */
	public function resolve_shared_slug( $vars ) {
		if ( ! is_array( $vars ) || empty( $vars['pagename'] ) || ! is_string( $vars['pagename'] ) || is_admin() ) {
			return $vars;
		}
		if ( self::SLUG !== trim( $vars['pagename'], '/' ) ) {
			return $vars;
		}
		$id = self::page_id( $this->polylang->current_language() );
		if ( $id > 0 ) {
			unset( $vars['pagename'], $vars['name'] );
			$vars['page_id'] = (string) $id;
		}
		return $vars;
	}
}
