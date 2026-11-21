<?php
/**
 * Automatic 301 when a `product_cat` is re-slugged or moved (ADR-002 s7-8).
 *
 * `edit_terms` fires BEFORE the update: the URLs of the term and of every descendant are captured (`get_term_link()`, so each
 * language keeps its own `/ru/catalog/...` path). `edited_product_cat` fires AFTER: the URLs are read again and every URL that
 * changed gets a single-hop 301 to its new canonical URL, plus a regex row that carries `/page/N/` along.
 *
 * Rules enforced here:
 * - source = path relative to the site root, destination = absolute term link on this host (the adapter refuses other hosts);
 * - chains collapse: redirects (ours) that already pointed to the old URL are re-pointed to the new one (A->B, then B->C gives A->C);
 * - loops never exist: before writing, every redirect (ours) whose SOURCE is a live URL of the subtree is deleted;
 * - a no-op save (same slug, same parent) writes nothing; the subtree is capped (MAX_SUBTREE) so mass saves cannot flood the table;
 * - runs only for users who may manage product terms, WP-CLI and cron (imports); never from anonymous requests;
 * - redirects written by this module are remembered in option `vx_seo_redirect_ids` (not autoloaded) so only they are ever
 *   modified or removed, and `wp vextra seo redirects` can list them. Rank Math's own term watcher stays off
 *   (`general.redirections_post_redirect`); if an editor enables it, it only duplicates a source that this class then finds.
 *
 * Out of scope: term DELETE (import-era task).
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
	 * After the update: write redirects for every URL that changed.
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

		$this->apply( $pairs, array_values( $after ) );

		$this->rank_math->invalidate_sitemap( TermCounts::TAXONOMY );
	}

	/**
	 * Write the redirects of one move.
	 *
	 * @param array<int,array{0:string,1:string}> $pairs Term id => [ old URL, new URL ].
	 * @param string[]                            $live  Every live URL of the subtree after the change.
	 */
	private function apply( array $pairs, array $live ): void {
		$owned = $this->owned();

		// 1. A live URL must never be a redirect source (no loops, no 301 from a canonical URL).
		foreach ( $live as $url ) {
			$path = $this->path( $url );
			foreach ( [ $this->find_owned( $path, 'exact', $owned ), $this->find_owned( $this->page_pattern( $path ), 'regex', $owned ) ] as $rows ) {
				foreach ( $rows as $row ) {
					$this->rank_math->delete_redirect( $row['id'] );
					$owned = array_values( array_diff( $owned, [ $row['id'] ] ) );
				}
			}
		}

		foreach ( $pairs as [ $old, $new ] ) {
			// 2. Collapse chains: our redirects that pointed to the old URL now point to the new one.
			foreach ( [ $old, trailingslashit( $old ) . self::PAGE_SUFFIX ] as $target ) {
				$to = $target === $old ? $new : trailingslashit( $new ) . self::PAGE_SUFFIX;
				foreach ( $this->rank_math->find_redirects_to( $target ) as $row ) {
					if ( in_array( $row['id'], $owned, true ) ) {
						$this->rank_math->update_redirect_destination( $row['id'], $to );
					}
				}
			}

			// 3. old -> new (single hop), and the paginated variant.
			$old_path = $this->path( $old );
			if ( '' === $old_path ) {
				continue;
			}
			$owned = $this->upsert( $old_path, 'exact', $new, $owned );
			$owned = $this->upsert( $this->page_pattern( $old_path ), 'regex', trailingslashit( $new ) . self::PAGE_SUFFIX, $owned );
		}

		$this->save_owned( $owned );
	}

	/**
	 * Create the redirect or re-point our existing one with the same source.
	 *
	 * @param string $source     Source pattern.
	 * @param string $comparison `exact` or `regex`.
	 * @param string $dest       Destination URL.
	 * @param int[]  $owned      Ids written by this module.
	 * @return int[] Updated id list.
	 */
	private function upsert( string $source, string $comparison, string $dest, array $owned ): array {
		if ( '' === $source ) {
			return $owned;
		}
		$existing = $this->find_owned( $source, $comparison, $owned );
		if ( [] !== $existing ) {
			$this->rank_math->update_redirect_destination( $existing[0]['id'], $dest );
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
	 * Our redirects with this source.
	 *
	 * @param string $source     Source pattern.
	 * @param string $comparison `exact` or `regex`.
	 * @param int[]  $owned      Ids written by this module.
	 * @return array<int,array{id:int,sources:array<int,array<string,string>>,url_to:string,header_code:int}>
	 */
	private function find_owned( string $source, string $comparison, array $owned ): array {
		if ( '' === $source ) {
			return [];
		}
		return array_values(
			array_filter(
				$this->rank_math->find_redirects_from( $source, $comparison ),
				static fn( array $row ): bool => in_array( $row['id'], $owned, true )
			)
		);
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
