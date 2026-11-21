<?php
/**
 * Rank Math adapter: availability, version, sitemap exclusion and robots reads.
 *
 * Verified against Rank Math 1.0.282: filter `rank_math/sitemap/posts_to_exclude` (array of ids),
 * robots stored in post/term meta `rank_math_robots` (array of directives), robots filter `rank_math/frontend/robots`,
 * sitemap filters `rank_math/sitemap/entry` and `rank_math/sitemap/post_type_archive_link`, cache API
 * `RankMath\Sitemap\Cache::invalidate_storage( $type )`. ALL Rank Math hook names of the plugin live in this class.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Integrations\RankMath;

use Vextra\Core\Integrations\AbstractIntegration;

final class RankMath extends AbstractIntegration {

	public function id(): string {
		return 'rankmath';
	}

	protected function detect(): bool {
		return class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
	}

	public function version(): ?string {
		return defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : null;
	}

	/**
	 * Add post ids to the sitemap exclusion list. No-op when Rank Math is absent.
	 *
	 * @param callable $callback Receives the current id array, returns the extended array.
	 */
	public function exclude_posts_from_sitemap( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/sitemap/posts_to_exclude', $callback );
		}
	}

	/**
	 * Whether the post is explicitly set to noindex in Rank Math.
	 *
	 * @param int $post_id Post ID (a non-product post type; products use WC CRUD elsewhere).
	 */
	public function is_post_noindex( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		return is_array( $robots ) && in_array( 'noindex', $robots, true );
	}

	/**
	 * Mark a post noindex (keeps other directives).
	 *
	 * @param int $post_id Post ID of a page.
	 */
	public function set_post_noindex( int $post_id ): void {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		$robots = is_array( $robots ) ? $robots : [];
		if ( ! in_array( 'noindex', $robots, true ) ) {
			$robots[] = 'noindex';
			update_post_meta( $post_id, 'rank_math_robots', $robots );
		}
	}

	/**
	 * Hook a robots filter (receives and returns the directive array, e.g. `[ 'index' => 'noindex', 'follow' => 'follow' ]`).
	 * No-op when Rank Math is absent.
	 *
	 * @param callable $callback Filter callback.
	 */
	public function filter_robots( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/frontend/robots', $callback, 20 );
		}
	}

	/**
	 * Hook a sitemap entry filter: `( array $url, string $type, object $object )`; returning an empty value drops the entry.
	 *
	 * @param callable $callback Filter callback.
	 */
	public function filter_sitemap_entry( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/sitemap/entry', $callback, 20, 3 );
		}
	}

	/**
	 * Hook the filter for the post-type archive URL listed first in a post-type sitemap: `( string $url, string $post_type )`;
	 * returning '' removes it.
	 *
	 * @param callable $callback Filter callback.
	 */
	public function filter_sitemap_archive_link( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/sitemap/post_type_archive_link', $callback, 20, 2 );
		}
	}

	/**
	 * Whether a Rank Math sitemap is being generated in this request (query var `sitemap`).
	 */
	public function is_sitemap_request(): bool {
		return $this->is_available() && isset( $GLOBALS['wp_query'] ) && '' !== (string) get_query_var( 'sitemap' );
	}

	/**
	 * Delete the stored sitemap files of one type (`product_cat`, `product`, ...). No-op when Rank Math is absent.
	 *
	 * @param string $type Sitemap type.
	 */
	public function invalidate_sitemap( string $type ): void {
		if ( $this->is_available() && class_exists( '\RankMath\Sitemap\Cache' ) && method_exists( '\RankMath\Sitemap\Cache', 'invalidate_storage' ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage( $type );
		}
	}

	/**
	 * Whether a term is explicitly noindex: its own SEO meta says `noindex`, or the taxonomy has custom robots with `noindex`.
	 * This is a CONFIGURATION check (not the empty-term automation), safe to call outside the term's own page.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function is_term_noindex( int $term_id, string $taxonomy ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		$robots = get_term_meta( $term_id, 'rank_math_robots', true );
		if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
			return true;
		}
		if ( $this->is_available() && class_exists( '\RankMath\Helper' ) ) {
			if ( \RankMath\Helper::get_settings( 'titles.tax_' . $taxonomy . '_custom_robots' ) ) {
				return in_array( 'noindex', (array) \RankMath\Helper::get_settings( 'titles.tax_' . $taxonomy . '_robots' ), true );
			}
		}
		return false;
	}

	/**
	 * Whether the current request is a multi-term archive (Rank Math noindexes those; automation must not undo it).
	 */
	public function is_multiple_terms_query(): bool {
		return $this->is_available() && class_exists( '\RankMath\Term' ) && \RankMath\Term::is_multiple_terms_query();
	}

	/**
	 * Whether Rank Math is configured to noindex page 2+ of archives (the Vextra rule is the opposite: self-canonical, indexed).
	 */
	public function noindexes_subpages(): bool {
		return $this->is_available() && class_exists( '\RankMath\Helper' ) && (bool) \RankMath\Helper::get_settings( 'titles.noindex_archive_subpages' );
	}

	/**
	 * Hook the final SEO title filter (receives the title string before escaping).
	 *
	 * @param callable $callback Filter callback.
	 */
	public function filter_title( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/frontend/title', $callback, 20 );
		}
	}

	/**
	 * Hook the final meta description filter (receives the description string before escaping).
	 *
	 * @param callable $callback Filter callback.
	 */
	public function filter_description( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/frontend/description', $callback, 20 );
		}
	}

	/**
	 * Hook the breadcrumb trail filter. Items are `[ name, url, 'hide_in_schema' => bool ]`; the visible trail and the
	 * BreadcrumbList JSON-LD are both built from this array.
	 *
	 * @param callable $callback Filter callback `( array $crumbs )`.
	 */
	public function filter_breadcrumb_items( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/frontend/breadcrumb/items', $callback, 20 );
		}
	}

	/**
	 * Hook the choice of the category used in a product's breadcrumb trail `( WP_Term $term, array $terms )`.
	 *
	 * @param callable $callback Filter callback.
	 */
	public function filter_breadcrumb_main_term( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/frontend/breadcrumb/main_term', $callback, 20, 2 );
		}
	}

	/**
	 * Hook the Product schema entity filter (array of the Product JSON-LD node).
	 *
	 * @param callable $callback Filter callback.
	 */
	public function filter_product_entity( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/snippet/rich_snippet_product_entity', $callback, 20 );
		}
	}

	/**
	 * Whether a post has a manually written SEO field (`title`, `description`).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Field without the `rank_math_` prefix.
	 */
	public function post_has_manual( int $post_id, string $key ): bool {
		return $post_id > 0 && '' !== trim( (string) get_post_meta( $post_id, 'rank_math_' . $key, true ) );
	}

	/**
	 * Whether a term has a manually written SEO field (`title`, `description`).
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Field without the `rank_math_` prefix.
	 */
	public function term_has_manual( int $term_id, string $key ): bool {
		return $term_id > 0 && '' !== trim( (string) get_term_meta( $term_id, 'rank_math_' . $key, true ) );
	}

	/**
	 * Primary term id chosen in the Rank Math editor (0 when none).
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function primary_term_id( int $post_id, string $taxonomy ): int {
		return $post_id > 0 ? (int) get_post_meta( $post_id, 'rank_math_primary_' . $taxonomy, true ) : 0;
	}
}
