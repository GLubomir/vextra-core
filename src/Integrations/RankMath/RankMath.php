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

	/**
	 * Rank Math general options option name and the only keys this plugin may write (TASK-036 `apply-config`).
	 */
	public const GENERAL_OPTION   = 'rank-math-options-general';
	public const WRITABLE_GENERAL = [ 'redirections_post_redirect', 'attachment_redirect_urls', 'attachment_redirect_default' ];

	/**
	 * Rank Math sitemap options option name and its writable key (TASK-044a `apply-config`: the featured image is listed in the image sitemap).
	 */
	public const SITEMAP_OPTION   = 'rank-math-options-sitemap';
	public const WRITABLE_SITEMAP = [ 'include_featured_image' ];

	/**
	 * Rank Math titles options option name and its writable keys (`apply-config`: password-protected pages / products are `noindex`).
	 */
	public const TITLES_OPTION   = 'rank-math-options-titles';
	public const WRITABLE_TITLES = [ 'noindex_password_protected' ];

	public function id(): string {
		return 'rankmath';
	}

	protected function detect(): bool {
		return class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
	}

	/**
	 * Rank Math options read on every front request and usually missing (Tracking reads the connect data for the user e-mail).
	 *
	 * @return string[]
	 */
	public function primed_options(): array {
		return [ 'rank_math_connect_data' ];
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
	 * Clear the noindex of a post (keeps other directives; writes `index` when the list ends up empty so the page is explicitly indexable).
	 * Mirror of set_post_noindex(). Used by `wp vextra seo launch-pages`.
	 *
	 * @param int $post_id Post ID of a page.
	 */
	public function clear_post_noindex( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( ! is_array( $robots ) || ! in_array( 'noindex', $robots, true ) ) {
			return;
		}
		$robots = array_values( array_diff( $robots, [ 'noindex' ] ) );
		if ( array() === $robots ) {
			$robots = [ 'index' ];
		}
		update_post_meta( $post_id, 'rank_math_robots', $robots );
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
	 * Hook the canonical URL filter (`rank_math/frontend/canonical`: receives and returns the canonical URL string). No-op when
	 * Rank Math is absent.
	 *
	 * @param callable $callback Filter callback.
	 */
	public function filter_canonical( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/frontend/canonical', $callback, 20 );
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
	 * Hook the filter of the image list of one sitemap entry: `( array $images, int $post_id )`, items `{ src, title, alt }`. Runs after Rank Math's
	 * own WooCommerce gallery filter (priority 10), so it can replace the list.
	 *
	 * @param callable $callback Filter callback.
	 */
	public function filter_sitemap_images( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/sitemap/urlimages', $callback, 20, 2 );
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
	 * A Rank Math setting by its dotted path (e.g. `general.redirections_post_redirect`) through the public
	 * `Helper::get_settings()`. Returns `$fallback` when Rank Math or the API is absent or the call fails.
	 *
	 * @param string $path    Dotted path `<group>.<key>`.
	 * @param mixed  $fallback Value when unavailable.
	 * @return mixed
	 */
	public function setting( string $path, $fallback = null ) {
		if ( ! $this->is_available() || ! class_exists( '\RankMath\Helper' ) || ! method_exists( '\RankMath\Helper', 'get_settings' ) ) {
			return $fallback;
		}
		try {
			return \RankMath\Helper::get_settings( $path, $fallback );
		} catch ( \Throwable $e ) {
			return $fallback;
		}
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
	 * Hook the global JSON-LD graph filter `( array $data, JsonLD $jsonld )`: array of nodes keyed by Rank Math ids
	 * (`publisher` = Organization, `WebSite`, `WebPage`, `richSnippet`, ...).
	 *
	 * @param callable $callback Filter callback.
	 */
	public function filter_json_ld( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/json_ld', $callback, 100, 2 );
		}
	}

	/**
	 * Hook the candidate size list of the Open Graph / Twitter image (`rank_math/opengraph/image_sizes`, default `full`, `large`, `medium_large`):
	 * the Media module restricts it to `full` so no WebP / AVIF sub-size becomes a social image.
	 *
	 * @param callable $callback Filter callback `( array $sizes )`.
	 */
	public function filter_og_image_sizes( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/opengraph/image_sizes', $callback, 20 );
		}
	}

	/**
	 * Hook the "enhanced data" pairs printed as `twitter:label<N>` / `twitter:data<N>` (Slack / X sharing): array of
	 * `label => value` before Rank Math prints them (it numbers the tags itself, so removing one renumbers the rest).
	 *
	 * @param callable $callback Filter callback `( array $data )`.
	 */
	public function filter_enhanced_data( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/opengraph/slack_enhanced_data', $callback, 20 );
		}
	}

	/**
	 * Whether the Redirections module of Rank Math is active and its storage classes are present.
	 * INTERNAL API (docs/compat/integrations.md): `RankMath\Redirections\DB` has no public documentation; every call below is
	 * guarded and a failure degrades to "no redirect written" (the caller logs once).
	 */
	public function redirections_available(): bool {
		return $this->is_available()
			&& class_exists( '\RankMath\Redirections\DB' )
			&& class_exists( '\RankMath\Helper' )
			&& method_exists( '\RankMath\Helper', 'is_module_active' )
			&& \RankMath\Helper::is_module_active( 'redirections' );
	}

	/**
	 * Create a redirect. `$source` is a path relative to the site root without leading/trailing slash (`ru/catalog/a/b`) for
	 * `exact`, or a regex without delimiters for `regex`. The destination MUST be on this site (open redirects are refused).
	 *
	 * @param string $source      Source pattern.
	 * @param string $destination Absolute destination URL on this host (regex replacement tokens like `$1` allowed).
	 * @param int    $code        HTTP code (301 or 308 only).
	 * @param string $comparison  `exact` or `regex`.
	 * @return int Redirect id, 0 on failure.
	 */
	public function add_redirect( string $source, string $destination, int $code = 301, string $comparison = 'exact' ): int {
		if ( ! $this->redirections_available() || ! in_array( $code, [ 301, 308 ], true ) || ! in_array( $comparison, [ 'exact', 'regex' ], true ) ) {
			return 0;
		}
		$source      = $this->clean_pattern( $source, $comparison );
		$destination = $this->clean_destination( $destination );
		if ( '' === $source || '' === $destination ) {
			return 0;
		}
		try {
			$id = \RankMath\Redirections\DB::add(
				[
					'sources'     => [
						[
							'ignore'     => '',
							'pattern'    => $source,
							'comparison' => $comparison,
						],
					],
					'url_to'      => $destination,
					'header_code' => (string) $code,
				]
			);
		} catch ( \Throwable $e ) {
			return 0;
		}
		return is_numeric( $id ) ? max( 0, (int) $id ) : 0;
	}

	/**
	 * Point an existing redirect to another destination (same host only).
	 *
	 * @param int    $id          Redirect id.
	 * @param string $destination Absolute destination URL.
	 */
	public function update_redirect_destination( int $id, string $destination ): bool {
		$destination = $this->clean_destination( $destination );
		$row         = $this->get_redirect( $id );
		if ( null === $row || '' === $destination ) {
			return false;
		}
		try {
			\RankMath\Redirections\DB::update(
				[
					'id'          => $id,
					'sources'     => $row['sources'],
					'url_to'      => $destination,
					'header_code' => (string) $row['header_code'],
					'status'      => 'active',
				]
			);
		} catch ( \Throwable $e ) {
			return false;
		}
		return true;
	}

	/**
	 * One redirect by id (not trashed), normalised: `id`, `sources` (list of `pattern`/`comparison`/`ignore`), `url_to`, `header_code`.
	 *
	 * @param int $id Redirect id.
	 * @return array{id:int,sources:array<int,array<string,string>>,url_to:string,header_code:int}|null
	 */
	public function get_redirect( int $id ): ?array {
		if ( $id <= 0 || ! $this->redirections_available() ) {
			return null;
		}
		try {
			$row = \RankMath\Redirections\DB::get_redirection_by_id( $id );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_array( $row ) ? $this->normalise_redirect( $row ) : null;
	}

	/**
	 * Redirects whose destination equals the URL (trailing slash insensitive).
	 *
	 * @param string $url Destination URL.
	 * @return array<int,array{id:int,sources:array<int,array<string,string>>,url_to:string,header_code:int}>
	 */
	public function find_redirects_to( string $url ): array {
		$out = [];
		foreach ( $this->search_redirects( $url ) as $row ) {
			if ( untrailingslashit( $row['url_to'] ) === untrailingslashit( $url ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Redirects having this exact source pattern.
	 *
	 * @param string $source     Source pattern (see add_redirect()).
	 * @param string $comparison `exact` or `regex`.
	 * @return array<int,array{id:int,sources:array<int,array<string,string>>,url_to:string,header_code:int}>
	 */
	public function find_redirects_from( string $source, string $comparison = 'exact' ): array {
		$source = $this->clean_pattern( $source, $comparison );
		$out    = [];
		if ( '' === $source ) {
			return $out;
		}
		foreach ( $this->search_redirects( $source ) as $row ) {
			foreach ( $row['sources'] as $src ) {
				if ( ( $src['comparison'] ?? 'exact' ) === $comparison && trim( (string) ( $src['pattern'] ?? '' ), '/' ) === $source ) {
					$out[] = $row;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Delete a redirect (and Rank Math's lookup cache of it).
	 *
	 * @param int $id Redirect id.
	 */
	public function delete_redirect( int $id ): bool {
		if ( $id <= 0 || ! $this->redirections_available() ) {
			return false;
		}
		try {
			\RankMath\Redirections\DB::delete( [ $id ] );
		} catch ( \Throwable $e ) {
			return false;
		}
		return true;
	}

	/**
	 * The whole redirect store in ONE read (trashed rows excluded), for in-memory indexing by callers that touch many rows
	 * (TermRedirects, SEC-061). Bounded by `$limit`.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array{id:int,sources:array<int,array<string,string>>,url_to:string,header_code:int}> Keyed by id.
	 */
	public function all_redirects( int $limit = 20000 ): array {
		if ( ! $this->redirections_available() ) {
			return [];
		}
		try {
			$res = \RankMath\Redirections\DB::get_redirections(
				[
					'limit'  => max( 1, $limit ),
					'status' => 'any',
				]
			);
		} catch ( \Throwable $e ) {
			return [];
		}
		$out = [];
		foreach ( (array) ( $res['redirections'] ?? [] ) as $row ) {
			if ( is_array( $row ) && 'trashed' !== ( $row['status'] ?? '' ) ) {
				$norm               = $this->normalise_redirect( $row );
				$out[ $norm['id'] ] = $norm;
			}
		}
		return $out;
	}

	/**
	 * Write sources + destination of an existing row without re-reading it (same-host destination only).
	 *
	 * @param array{id:int,sources:array<int,array<string,string>>,url_to:string,header_code:int} $row Normalised row.
	 */
	public function write_redirect( array $row ): bool {
		$destination = $this->clean_destination( $row['url_to'] );
		if ( $row['id'] <= 0 || '' === $destination || [] === $row['sources'] || ! $this->redirections_available() ) {
			return false;
		}
		try {
			\RankMath\Redirections\DB::update(
				[
					'id'          => $row['id'],
					'sources'     => array_values( $row['sources'] ),
					'url_to'      => $destination,
					'header_code' => (string) $row['header_code'],
					'status'      => 'active',
				]
			);
		} catch ( \Throwable $e ) {
			return false;
		}
		return true;
	}

	/**
	 * Delete several rows in one call.
	 *
	 * @param int[] $ids Redirect ids.
	 */
	public function delete_redirects( array $ids ): bool {
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( [] === $ids || ! $this->redirections_available() ) {
			return false;
		}
		try {
			\RankMath\Redirections\DB::delete( $ids );
		} catch ( \Throwable $e ) {
			return false;
		}
		return true;
	}

	/**
	 * Rows whose sources or destination contain the needle (LIKE search, bounded to 200, trashed ones excluded). Callers compare
	 * exactly afterwards.
	 *
	 * @param string $needle Search string.
	 * @return array<int,array{id:int,sources:array<int,array<string,string>>,url_to:string,header_code:int}>
	 */
	public function find_redirects_matching( string $needle ): array {
		return $this->search_redirects( $needle );
	}

	/**
	 * Remove ONE source (pattern + comparison) from a redirect. The row is deleted when it has no other source.
	 *
	 * @param int    $id         Redirect id.
	 * @param string $pattern    Source pattern.
	 * @param string $comparison `exact` or `regex`.
	 * @return string `deleted`, `updated` or `none` (source not found / failure).
	 */
	public function remove_source( int $id, string $pattern, string $comparison = 'exact' ): string {
		$row     = $this->get_redirect( $id );
		$pattern = $this->clean_pattern( $pattern, $comparison );
		if ( null === $row || '' === $pattern ) {
			return 'none';
		}
		$keep  = [];
		$found = false;
		foreach ( $row['sources'] as $src ) {
			if ( ( $src['comparison'] ?? 'exact' ) === $comparison && trim( (string) ( $src['pattern'] ?? '' ), '/' ) === $pattern ) {
				$found = true;
				continue;
			}
			$keep[] = $src;
		}
		if ( ! $found ) {
			return 'none';
		}
		if ( [] === $keep ) {
			return $this->delete_redirect( $id ) ? 'deleted' : 'none';
		}
		try {
			\RankMath\Redirections\DB::update(
				[
					'id'          => $id,
					'sources'     => $keep,
					'url_to'      => $row['url_to'],
					'header_code' => (string) $row['header_code'],
					'status'      => 'active',
				]
			);
		} catch ( \Throwable $e ) {
			return 'none';
		}
		return 'updated';
	}

	/**
	 * Write values of `rank-math-options-general` for an explicit whitelist of keys, keeping every other key untouched.
	 * INTERNAL (docs/compat/integrations.md): Rank Math has no public setter; the option is its own documented storage and the
	 * caller verifies the result through the compat rules (drift is reported, never silent).
	 *
	 * @param array<string,string> $values Key => value (only keys in WRITABLE_GENERAL are accepted).
	 * @return string[]|null Keys that changed, null when the write is impossible.
	 */
	public function write_general_settings( array $values ): ?array {
		return $this->write_settings( self::GENERAL_OPTION, self::WRITABLE_GENERAL, $values );
	}

	/**
	 * Same for `rank-math-options-sitemap` (whitelist WRITABLE_SITEMAP).
	 *
	 * @param array<string,string> $values Key => value.
	 * @return string[]|null Keys that changed, null when the write is impossible.
	 */
	public function write_sitemap_settings( array $values ): ?array {
		return $this->write_settings( self::SITEMAP_OPTION, self::WRITABLE_SITEMAP, $values );
	}

	/**
	 * Same for `rank-math-options-titles` (whitelist WRITABLE_TITLES).
	 *
	 * @param array<string,string> $values Key => value.
	 * @return string[]|null Keys that changed, null when the write is impossible.
	 */
	public function write_titles_settings( array $values ): ?array {
		return $this->write_settings( self::TITLES_OPTION, self::WRITABLE_TITLES, $values );
	}

	/**
	 * Write whitelisted keys of one Rank Math option, every other key untouched.
	 *
	 * @param string               $option    Option name.
	 * @param string[]             $whitelist Writable keys.
	 * @param array<string,string> $values    Key => value.
	 * @return string[]|null
	 */
	private function write_settings( string $option, array $whitelist, array $values ): ?array {
		if ( ! $this->is_available() ) {
			return null;
		}
		$values  = array_intersect_key( $values, array_flip( $whitelist ) );
		$current = get_option( $option, [] );
		$current = is_array( $current ) ? $current : [];
		$changed = [];
		foreach ( $values as $key => $value ) {
			$value = (string) $value;
			if ( ! isset( $current[ $key ] ) || (string) $current[ $key ] !== $value ) {
				$current[ $key ] = $value;
				$changed[]       = $key;
			}
		}
		if ( [] === $changed ) {
			return [];
		}
		if ( ! update_option( $option, $current ) ) {
			return null;
		}
		return $changed;
	}

	/**
	 * Stored value of one general setting straight from the option (the Helper API caches per request, so a value changed by
	 * `write_general_settings()` in the same request is only visible here).
	 *
	 * @param string $key Option key.
	 */
	public function stored_general_setting( string $key ): string {
		return $this->stored_setting( self::GENERAL_OPTION, $key );
	}

	/**
	 * Stored value of one titles setting straight from the option.
	 *
	 * @param string $key Option key.
	 */
	public function stored_titles_setting( string $key ): string {
		return $this->stored_setting( self::TITLES_OPTION, $key );
	}

	/**
	 * Stored value of one sitemap setting straight from the option.
	 *
	 * @param string $key Option key.
	 */
	public function stored_sitemap_setting( string $key ): string {
		return $this->stored_setting( self::SITEMAP_OPTION, $key );
	}

	/**
	 * Stored value of one key of a Rank Math option.
	 *
	 * @param string $option Option name.
	 * @param string $key    Key.
	 */
	private function stored_setting( string $option, string $key ): string {
		$current = get_option( $option, [] );
		return is_array( $current ) && isset( $current[ $key ] ) && is_scalar( $current[ $key ] ) ? (string) $current[ $key ] : '';
	}

	/**
	 * Candidate rows for a LIKE search over sources and destinations (bounded to 200, trashed ones excluded).
	 *
	 * @param string $needle Search string.
	 * @return array<int,array{id:int,sources:array<int,array<string,string>>,url_to:string,header_code:int}>
	 */
	private function search_redirects( string $needle ): array {
		if ( '' === $needle || ! $this->redirections_available() ) {
			return [];
		}
		try {
			$res = \RankMath\Redirections\DB::get_redirections(
				[
					'search' => $needle,
					'limit'  => 200,
					'status' => 'any',
				]
			);
		} catch ( \Throwable $e ) {
			return [];
		}
		$out = [];
		foreach ( (array) ( $res['redirections'] ?? [] ) as $row ) {
			if ( is_array( $row ) && 'trashed' !== ( $row['status'] ?? '' ) ) {
				$out[] = $this->normalise_redirect( $row );
			}
		}
		return $out;
	}

	/**
	 * Normalise a redirections row (sources may be serialized).
	 *
	 * @param array<string,mixed> $row DB row.
	 * @return array{id:int,sources:array<int,array<string,string>>,url_to:string,header_code:int}
	 */
	private function normalise_redirect( array $row ): array {
		$sources = $row['sources'] ?? [];
		$sources = is_string( $sources ) ? maybe_unserialize( $sources ) : $sources;
		return [
			'id'          => (int) ( $row['id'] ?? 0 ),
			'sources'     => is_array( $sources ) ? array_values( $sources ) : [],
			'url_to'      => (string) ( $row['url_to'] ?? '' ),
			'header_code' => (int) ( $row['header_code'] ?? 301 ),
		];
	}

	/**
	 * Redirect source pattern: no tags/control characters, no leading/trailing slash for `exact`, no `..` segments.
	 *
	 * @param string $pattern    Pattern.
	 * @param string $comparison `exact` or `regex`.
	 */
	private function clean_pattern( string $pattern, string $comparison ): string {
		$pattern = trim( wp_strip_all_tags( $pattern ) );
		$pattern = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $pattern );
		if ( 'exact' === $comparison ) {
			$pattern = trim( rawurldecode( $pattern ), '/' );
			if ( str_contains( $pattern, '..' ) || str_contains( $pattern, '://' ) || str_contains( $pattern, '?' ) ) {
				return '';
			}
		}
		return $pattern;
	}

	/**
	 * Destination must be an http(s) URL on this site's host.
	 *
	 * @param string $url URL.
	 */
	private function clean_destination( string $url ): string {
		$url  = esc_url_raw( trim( $url ), [ 'http', 'https' ] );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$mine = wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST );
		if ( '' === $url || ! is_string( $host ) || ! is_string( $mine ) || strtolower( $host ) !== strtolower( $mine ) ) {
			return '';
		}
		return $url;
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
