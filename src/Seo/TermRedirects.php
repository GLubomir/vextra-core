<?php
/**
 * Descendant-category 301 + chain/loop hygiene for `product_cat` edits (ADR-002 s7-8, ADR-011).
 *
 * Rank Math (Auto Post Redirect ON) owns the redirect of the EDITED term itself (`edited_term`, one record per object, all old
 * sources in it). It cannot know that the URLs of the DESCENDANT categories (`/catalog/<parent>/<child>/`) changed too: this class
 * does. `edit_terms` fires BEFORE the update: URLs of the term and of every descendant are captured (`get_term_link()`, so each
 * language keeps its own `/ru/catalog/...` path). `edited_product_cat` (priority 20, after Rank Math's `edited_term`) reads them
 * again and, for the changed URLs:
 * - FLATTENS the store on every edit, whatever the owner of a row: a row whose destination is an old URL of the subtree is
 *   re-pointed to the new URL (A->B, then B->C gives A->C); a row whose source is a live URL of the subtree loses that source (the
 *   row is deleted when it was its only one: no self-loop on a flip-back, no 301 from a canonical URL). Scope = exact URLs of the
 *   edited subtree (and their `/page/N/` regex variant); unrelated rows are never touched;
 * - writes `old -> new` rows for the DESCENDANTS only, plus the `/page/N/` regex row of every changed URL including the edited
 *   term (Rank Math does not carry pagination). A source already present in the store (any owner) is never written twice;
 * - never writes for a no-op save; the subtree is capped (MAX_SUBTREE);
 * - runs only for users who may manage product terms, WP-CLI and cron (imports); never from anonymous requests;
 * - rows written here are remembered in option `vx_seo_redirect_ids` (not autoloaded); `wp vextra seo redirects` lists them.
 *   Rows written before TASK-036 (including the edited term's own exact rows) stay valid, no migration.
 *
 * Out of scope: term DELETE.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Support\Log;

final class TermRedirects {

	public const OPTION = 'vx_seo_redirect_ids';

	private const MAX_SUBTREE = 500;

	/**
	 * Suffix of the regex row that carries pagination.
	 */
	private const PAGE_SUFFIX = 'page/$1/';

	/**
	 * Rank Math adapter.
	 *
	 * @var RankMath
	 */
	private RankMath $rank_math;

	/**
	 * URLs captured before the update: term id => [ term id => url ].
	 *
	 * @var array<int,array<int,string>>
	 */
	private array $before = [];

	/**
	 * "Redirections unavailable" already logged this request.
	 *
	 * @var bool
	 */
	private bool $warned = false;

	public function __construct( RankMath $rank_math ) {
		$this->rank_math = $rank_math;
	}

	public function register(): void {
		add_action( 'edit_terms', [ $this, 'capture' ], 5, 2 );
		add_action( 'edited_' . TermCounts::TAXONOMY, [ $this, 'compare' ], 20, 1 );
	}

	/**
	 * Before the update: URLs of the term and its descendants.
	 *
	 * @param mixed $term_id  Term ID.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public function capture( $term_id, $taxonomy = '' ): void {
		if ( TermCounts::TAXONOMY !== $taxonomy || ! $this->allowed() ) {
			return;
		}
		$term_id = (int) $term_id;
		if ( $term_id > 0 ) {
			$this->before[ $term_id ] = $this->subtree_urls( $term_id );
		}
	}

	/**
	 * After the update: flatten the store, then write descendant + pagination redirects for every URL that changed.
	 *
	 * @param mixed $term_id Term ID.
	 */
	public function compare( $term_id ): void {
		$term_id = (int) $term_id;
		if ( ! isset( $this->before[ $term_id ] ) ) {
			return;
		}
		$before = $this->before[ $term_id ];
		unset( $this->before[ $term_id ] );

		$after = $this->subtree_urls( $term_id );
		$pairs = [];
		foreach ( $before as $id => $old ) {
			if ( isset( $after[ $id ] ) && untrailingslashit( $old ) !== untrailingslashit( $after[ $id ] ) ) {
				$pairs[ $id ] = [ $old, $after[ $id ] ];
			}
		}
		if ( [] === $pairs ) {
			return;
		}

		if ( ! $this->rank_math->redirections_available() ) {
			if ( ! $this->warned ) {
				$this->warned = true;
				Log::warning( 'Category URL changed but Rank Math Redirections is unavailable: no 301 written.', [ 'term' => $term_id ], 'seo' );
			}
			return;
		}

		$this->apply( $term_id, $pairs, array_values( $after ) );

		$this->rank_math->invalidate_sitemap( TermCounts::TAXONOMY );
	}

	/**
	 * Flatten + write the redirects of one move.
	 *
	 * @param int                                 $edited Edited term id (Rank Math owns its exact redirect).
	 * @param array<int,array{0:string,1:string}> $pairs  Term id => [ old URL, new URL ].
	 * @param string[]                            $live   Every live URL of the subtree after the change.
	 */
	private function apply( int $edited, array $pairs, array $live ): void {
		$owned = $this->owned();

		// 1. Chains: ANY row (Rank Math's or ours) that pointed to an old URL of the subtree now points to the new one.
		foreach ( $pairs as [ $old, $new ] ) {
			foreach ( [ $old, trailingslashit( $old ) . self::PAGE_SUFFIX ] as $target ) {
				$to = $target === $old ? $new : trailingslashit( $new ) . self::PAGE_SUFFIX;
				foreach ( $this->rank_math->find_redirects_to( $target ) as $row ) {
					$this->rank_math->update_redirect_destination( $row['id'], $to );
				}
			}
		}

		// 2. Loops: a live URL of the subtree is never a redirect source (the source is removed from the row, the row is deleted
		// when it was the only one).
		$live_paths = [];
		foreach ( $live as $url ) {
			$path = $this->path( $url );
			if ( '' === $path ) {
				continue;
			}
			$live_paths[ $path ] = true;
			foreach ( [ [ $path, 'exact' ], [ $this->page_pattern( $path ), 'regex' ] ] as [ $source, $comparison ] ) {
				foreach ( $this->rank_math->find_redirects_from( $source, $comparison ) as $row ) {
					if ( 'deleted' === $this->rank_math->remove_source( $row['id'], $source, $comparison ) ) {
						$owned = array_values( array_diff( $owned, [ $row['id'] ] ) );
					}
				}
			}
		}

		// 3. New rows: descendants exact (the edited term is Rank Math's), every changed URL paginated.
		foreach ( $pairs as $id => [ $old, $new ] ) {
			$old_path = $this->path( $old );
			if ( '' === $old_path || isset( $live_paths[ $old_path ] ) ) {
				continue;
			}
			if ( $id !== $edited ) {
				$owned = $this->add_if_absent( $old_path, 'exact', $new, $owned );
			}
			$owned = $this->add_if_absent( $this->page_pattern( $old_path ), 'regex', trailingslashit( $new ) . self::PAGE_SUFFIX, $owned );
		}

		$this->save_owned( $owned );
	}

	/**
	 * Create the redirect unless the source already exists in the store (any owner).
	 *
	 * @param string $source     Source pattern.
	 * @param string $comparison `exact` or `regex`.
	 * @param string $dest       Destination URL.
	 * @param int[]  $owned      Ids written by this module.
	 * @return int[] Updated id list.
	 */
	private function add_if_absent( string $source, string $comparison, string $dest, array $owned ): array {
		if ( '' === $source || [] !== $this->rank_math->find_redirects_from( $source, $comparison ) ) {
			return $owned;
		}
		$id = $this->rank_math->add_redirect( $source, $dest, 301, $comparison );
		if ( $id > 0 ) {
			$owned[] = $id;
		} else {
			Log::warning( 'Could not write a category redirect.', [ 'source' => $source ], 'seo' );
		}
		return $owned;
	}

	/**
	 * Regex source carrying pagination of a term path. Rank Math runs `stripslashes()` over regex sources, so the pattern must not
	 * contain a backslash: digits are `[0-9]+` and only paths made of slug characters (`a-z 0-9 _ % - /`, never regex syntax) qualify.
	 *
	 * @param string $path Relative path.
	 */
	private function page_pattern( string $path ): string {
		return 1 === preg_match( '#^[A-Za-z0-9_%/-]+$#', $path ) ? '^' . $path . '/page/([0-9]+)/?$' : '';
	}

	/**
	 * Path of a URL relative to the site root, no leading/trailing slash ('' when not on this site).
	 *
	 * @param string $url Absolute URL.
	 */
	private function path( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = trim( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ), '/' );
		$path = trim( rawurldecode( $path ), '/' );
		if ( '' !== $base && ( $path === $base || str_starts_with( $path, $base . '/' ) ) ) {
			$path = trim( substr( $path, strlen( $base ) ), '/' );
		}
		return $path;
	}

	/**
	 * Term link of the term and all its descendants.
	 *
	 * @param int $term_id Term ID.
	 * @return array<int,string> Term id => URL.
	 */
	private function subtree_urls( int $term_id ): array {
		$ids = get_term_children( $term_id, TermCounts::TAXONOMY );
		$ids = is_wp_error( $ids ) ? [] : array_map( 'intval', $ids );
		array_unshift( $ids, $term_id );
		$ids = array_slice( array_unique( $ids ), 0, self::MAX_SUBTREE );

		$out = [];
		foreach ( $ids as $id ) {
			$link = get_term_link( $id, TermCounts::TAXONOMY );
			if ( is_string( $link ) && '' !== $link ) {
				$out[ $id ] = $link;
			}
		}
		return $out;
	}

	/**
	 * Capability gate: editors who manage product terms, WP-CLI (imports) and cron. Never anonymous/front-end.
	 */
	private function allowed(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return true;
		}
		return current_user_can( 'manage_product_terms' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
	}

	/**
	 * Ids of redirects written by this module.
	 *
	 * @return int[]
	 */
	public static function owned(): array {
		return array_values( array_unique( array_map( 'intval', (array) get_option( self::OPTION, [] ) ) ) );
	}

	/**
	 * Persist the owned id list (not autoloaded).
	 *
	 * @param int[] $ids Ids.
	 */
	private function save_owned( array $ids ): void {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( [] === $ids ) {
			delete_option( self::OPTION ); // No index row lingers once the last redirect is gone.
			return;
		}
		update_option( self::OPTION, $ids, false );
	}
}
