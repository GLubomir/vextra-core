<?php
/**
 * Front-end search guard (SEC-016): bad-UA deny, per-IP bucket, min / max length, cached id list.
 *
 * - `parse_request` priority 1 (before the main query runs): UA deny, bucket (429 page with Retry-After, noindex, no-store),
 *   queries of 1 character are redirected (302) to the empty search form, queries over the max length are truncated.
 * - `posts_pre_query`: the main search query for anonymous visitors and customers is answered from an id list cached with
 *   `Support\Cache` (group `guard-search`, TTL `search_ttl`, key = language + normalised query + post type + page + page size).
 *   Skipped for editors / shop managers, previews, WP-CLI and result sets above `search_max_ids`. TTL is short, so product /
 *   stock saves do not flush the group.
 * Never applies to robots.txt or sitemaps; REST `search=` is bucketed in StoreApi.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;
use Vextra\Core\Support\Cache;

final class Search {

	private const CACHE_GROUP = 'guard-search';

	public function register(): void {
		add_action( 'parse_request', [ $this, 'on_parse_request' ], 1 );
		add_filter( 'posts_pre_query', [ $this, 'cached_results' ], 10, 2 );
	}

	/**
	 * Guard the main request before the query runs.
	 *
	 * @param \WP $wp Request object.
	 */
	public function on_parse_request( $wp ): void {
		if ( ! $wp instanceof \WP || is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_ajax() || wp_doing_cron() || Request::is_never_limited() ) {
			return;
		}
		Failsafe::run( 'search', fn() => $this->guard( $wp ) );
	}

	private function guard( \WP $wp ): void {
		$term = $wp->query_vars['s'] ?? null;
		if ( ! is_string( $term ) || '' === $term || ! in_array( Request::method(), [ 'GET', 'HEAD' ], true ) ) {
			return;
		}
		if ( Exempt::is_exempt() ) {
			return;
		}

		if ( UserAgents::is_bad() ) {
			Responder::forbidden( Responder::FORMAT_HTML, 'search-ua' );
		}

		$hit = RateLimiter::hit( 'search', ClientIp::get(), Config::get( 'search_limit' ), Config::get( 'search_window' ) );
		if ( ! $hit['allowed'] ) {
			Responder::rate_limited( $hit['retry_after'], Responder::FORMAT_HTML, 'search' );
		}

		$length = mb_strlen( trim( $term ) );
		$min    = Config::get( 'search_min' );
		if ( $min > 1 && $length < $min ) {
			Responder::log( 'redirect', 'search-short' );
			nocache_headers();
			header( 'X-Robots-Tag: noindex' );
			wp_safe_redirect( $this->home() . '?s=', 302 );
			exit;
		}

		$max = Config::get( 'search_max' );
		if ( $max > 0 && mb_strlen( $term ) > $max ) {
			$cut                 = mb_substr( $term, 0, $max );
			$wp->query_vars['s'] = $cut;
			$_GET['s']           = wp_slash( $cut ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_REQUEST['s']       = wp_slash( $cut );
		}
	}

	/**
	 * Cached id list for the main search query.
	 *
	 * @param mixed $posts Short-circuit value (null = run the query).
	 * @param mixed $query The query.
	 * @return mixed
	 */
	public function cached_results( $posts, $query ) {
		if ( null !== $posts || ! $query instanceof \WP_Query ) {
			return $posts;
		}
		return Failsafe::run(
			'search-cache',
			fn() => $this->cacheable( $query ) ? $this->from_cache( $query, $posts ) : $posts,
			$posts
		);
	}

	private function cacheable( \WP_Query $query ): bool {
		if ( ! $query->is_main_query() || ! $query->is_search() || is_admin() || $query->is_preview() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( Config::get( 'search_ttl' ) <= 0 || ! is_string( $query->get( 's' ) ) || '' === $query->get( 's' ) ) {
			return false;
		}
		return ! ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_woocommerce' ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown
	}

	/**
	 * Build or read the cached id list.
	 *
	 * @param \WP_Query $query Main search query.
	 * @param mixed     $posts Original short-circuit value (null).
	 * @return mixed
	 */
	private function from_cache( \WP_Query $query, $posts ) {
		$key   = $this->key( $query );
		$fresh = null;

		$data = Cache::remember(
			self::CACHE_GROUP,
			$key,
			Config::get( 'search_ttl' ),
			function () use ( $query, &$fresh ) {
				$fresh = $this->run( $query );
				return count( $fresh['ids'] ) > Config::get( 'search_max_ids' ) ? false : $fresh;
			}
		);
		if ( false === $data ) {
			$data = $fresh;
		}
		if ( ! is_array( $data ) || ! isset( $data['ids'], $data['found'], $data['pages'] ) ) {
			return $posts;
		}

		$query->found_posts   = (int) $data['found'];
		$query->max_num_pages = (int) $data['pages'];
		return $this->load( (array) $data['ids'] );
	}

	/**
	 * Run the real search for ids (a non-main query, so this filter ignores it).
	 *
	 * @param \WP_Query $query Main query to copy.
	 * @return array{ids:int[],found:int,pages:int}
	 */
	private function run( \WP_Query $query ): array {
		$args = array_merge(
			$query->query_vars,
			[
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);
		$q    = new \WP_Query( $args );
		return [
			'ids'   => array_map( 'intval', (array) $q->posts ),
			'found' => (int) $q->found_posts,
			'pages' => (int) $q->max_num_pages,
		];
	}

	/**
	 * Posts for an id list in one query, order kept.
	 *
	 * @param int[] $ids Ids.
	 * @return \WP_Post[]
	 */
	private function load( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return [];
		}
		$q = new \WP_Query(
			[
				'post__in'            => $ids,
				'orderby'             => 'post__in',
				'post_type'           => 'any',
				'post_status'         => 'publish',
				'posts_per_page'      => count( $ids ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'lang'                => '',
				'suppress_filters'    => true,
			]
		);
		return array_values( array_filter( (array) $q->posts, static fn( $p ): bool => $p instanceof \WP_Post ) );
	}

	private function key( \WP_Query $query ): string {
		$term = mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', (string) $query->get( 's' ) ) ) );
		$pll  = Plugin::instance()->integration( 'polylang' );
		$lang = $pll instanceof Polylang && $pll->is_ready() ? $pll->current_language() : (string) get_locale();

		return md5(
			implode(
				'|',
				[
					$lang,
					$term,
					wp_json_encode( $query->get( 'post_type' ) ),
					(string) max( 1, (int) $query->get( 'paged' ) ),
					(string) $query->get( 'posts_per_page' ),
				]
			)
		);
	}

	private function home(): string {
		$pll = Plugin::instance()->integration( 'polylang' );
		if ( $pll instanceof Polylang && $pll->is_ready() && '' !== $pll->current_language() ) {
			return $pll->home_url( $pll->current_language() );
		}
		return home_url( '/' );
	}
}
