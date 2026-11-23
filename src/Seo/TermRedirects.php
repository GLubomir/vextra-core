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
 * - ONE read of the store per edit, everything is indexed in memory (SEC-061); above `VX_SEO_REDIRECT_SYNC_MAX` rows (default 100)
 *   only the edited term is written at once and the descendants go to ONE Action Scheduler job per term (snapshot in option
 *   `vx_seo_redirect_jobs`, a newer edit merges into the pending one, destinations are recomputed from the live links);
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

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;
use Vextra\Core\Support\Log;

final class TermRedirects {

	public const OPTION = 'vx_seo_redirect_ids';

	private const MAX_SUBTREE = 500;

	/**
	 * Default synchronous row budget per edit, pending-job option, job hook and cap of remembered old URLs per term.
	 */
	private const SYNC_MAX = 100;
	private const MAX_OLDS = 50;
	public const JOBS      = 'vx_seo_redirect_jobs';
	public const JOB_HOOK  = 'vextra/seo/term_redirects_job';

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
		add_action( self::JOB_HOOK, [ $this, 'run_job' ], 10, 1 );
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
	 * After the update: flatten the store, then write descendant + pagination redirects for every URL that changed. A big subtree
	 * (descendants x languages x 2 rows > sync limit) only gets the edited term's part now; the rest is one deduplicated Action Scheduler job (SEC-061).
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
		$snap  = [];
		foreach ( $before as $id => $old ) {
			if ( isset( $after[ $id ] ) && untrailingslashit( $old ) !== untrailingslashit( $after[ $id ] ) ) {
				$snap[ $id ] = [
					'olds' => [ $old ],
					'new'  => $after[ $id ],
				];
			}
		}
		if ( [] === $snap ) {
			return;
		}

		if ( ! $this->rank_math->redirections_available() ) {
			if ( ! $this->warned ) {
				$this->warned = true;
				Log::warning( 'Category URL changed but Rank Math Redirections is unavailable: no 301 written.', [ 'term' => $term_id ], 'seo' );
			}
			return;
		}

		$rest = array_diff_key( $snap, [ $term_id => true ] );
		if ( count( $rest ) * self::language_count() * 2 > self::sync_max() && $this->can_defer() ) {
			$this->process( $term_id, array_intersect_key( $snap, [ $term_id => true ] ) );
			$this->defer( $term_id, $rest );
		} else {
			$this->process( $term_id, $snap );
		}

		$this->rank_math->invalidate_sitemap( TermCounts::TAXONOMY );
	}

	/**
	 * Row budget written synchronously before the rest of a subtree is deferred (constant `VX_SEO_REDIRECT_SYNC_MAX`, filter
	 * `vextra/seo/redirect_sync_max`).
	 */
	public static function sync_max(): int {
		$max = defined( 'VX_SEO_REDIRECT_SYNC_MAX' ) ? (int) VX_SEO_REDIRECT_SYNC_MAX : self::SYNC_MAX;
		/**
		 * Filters the synchronous row budget of one category edit.
		 *
		 * @param int $max Rows (descendants x 2: term + pagination).
		 */
		return max( 0, (int) apply_filters( 'vextra/seo/redirect_sync_max', $max ) );
	}

	/**
	 * Number of site languages (every language may carry its own twin subtree), at least 1.
	 */
	private static function language_count(): int {
		$pll = Plugin::instance()->integration( 'polylang' );
		return $pll instanceof Polylang ? max( 1, count( $pll->languages() ) ) : 1;
	}

	/**
	 * Action Scheduler is available.
	 */
	private function can_defer(): bool {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Store the snapshot of the deferred part (merged with a pending one: every old URL of every term is kept, the destination is
	 * recomputed from the live term link when the job runs) and enqueue ONE job per edited term.
	 *
	 * @param int                                        $term_id Edited term id.
	 * @param array<int,array{olds:string[],new:string}> $snap    Deferred part.
	 */
	private function defer( int $term_id, array $snap ): void {
		$jobs    = (array) get_option( self::JOBS, [] );
		$pending = isset( $jobs[ $term_id ] ) && is_array( $jobs[ $term_id ] ) ? $jobs[ $term_id ] : [];
		foreach ( $snap as $id => $entry ) {
			$olds = array_merge( (array) ( $pending[ $id ]['olds'] ?? [] ), $entry['olds'] );
			if ( count( $olds ) > self::MAX_OLDS ) {
				Log::warning( 'Too many pending old URLs for one category, oldest dropped.', [ 'term' => $id ], 'seo' );
				$olds = array_slice( $olds, -self::MAX_OLDS );
			}
			$pending[ $id ] = [
				'olds' => array_values( array_unique( array_map( 'strval', $olds ) ) ),
				'new'  => $entry['new'],
			];
		}
		$jobs[ $term_id ] = $pending;
		update_option( self::JOBS, $jobs, false );

		if ( ! as_has_scheduled_action( self::JOB_HOOK, [ $term_id ], 'vextra-seo' ) ) {
			as_enqueue_async_action( self::JOB_HOOK, [ $term_id ], 'vextra-seo' );
		}
	}

	/**
	 * Action Scheduler job: the deferred part of one edit. Idempotent (rows are only added when their source is absent, flatten
	 * recomputes from the store); destinations are the live term links at run time.
	 *
	 * @param mixed $term_id Edited term id.
	 */
	public function run_job( $term_id ): void {
		$term_id = (int) $term_id;
		$jobs    = (array) get_option( self::JOBS, [] );
		$read    = isset( $jobs[ $term_id ] ) && is_array( $jobs[ $term_id ] ) ? $jobs[ $term_id ] : [];
		if ( [] === $read || ! $this->rank_math->redirections_available() ) {
			return;
		}

		$snap = [];
		foreach ( $read as $id => $entry ) {
			$link = get_term_link( (int) $id, TermCounts::TAXONOMY );
			if ( is_string( $link ) && '' !== $link ) {
				$snap[ (int) $id ] = [
					'olds' => (array) $entry['olds'],
					'new'  => $link,
				];
			}
		}
		$this->process( $term_id, $snap );
		$this->rank_math->invalidate_sitemap( TermCounts::TAXONOMY );

		// A newer edit may have merged into the entry while this ran: keep it and make sure a job exists.
		$jobs = (array) get_option( self::JOBS, [] );
		if ( ( $jobs[ $term_id ] ?? null ) === $read ) {
			unset( $jobs[ $term_id ] );
			if ( [] === $jobs ) {
				delete_option( self::JOBS );
			} else {
				update_option( self::JOBS, $jobs, false );
			}
		} elseif ( $this->can_defer() && ! as_has_scheduled_action( self::JOB_HOOK, [ $term_id ], 'vextra-seo' ) ) {
			as_enqueue_async_action( self::JOB_HOOK, [ $term_id ], 'vextra-seo' );
		}
	}

	/**
	 * Flatten + write the redirects of one move with ONE read of the store (no query per URL): rows are indexed in memory by
	 * destination and by source, then written back once per touched row.
	 *
	 * @param int                                        $edited Edited term id (Rank Math owns its exact redirect).
	 * @param array<int,array{olds:string[],new:string}> $snap   Term id => old URLs + current URL.
	 */
	private function process( int $edited, array $snap ): void {
		if ( [] === $snap ) {
			return;
		}
		$rows    = $this->rank_math->all_redirects();
		$by_dest = [];
		$exact   = [];
		$regex   = [];
		foreach ( $rows as $rid => $row ) {
			$by_dest[ untrailingslashit( $row['url_to'] ) ][ $rid ] = true;
			foreach ( $row['sources'] as $src ) {
				$pattern = (string) ( $src['pattern'] ?? '' );
				if ( 'regex' === ( $src['comparison'] ?? 'exact' ) ) {
					$regex[ $pattern ][ $rid ] = true;
				} else {
					$exact[ trim( $pattern, '/' ) ][ $rid ] = true;
				}
			}
		}
		$dirty   = [];
		$deleted = [];

		// 1. Chains: ANY row (Rank Math's or ours) that pointed to an old URL of the subtree now points to the new URL.
		foreach ( $snap as $entry ) {
			$new = $entry['new'];
			foreach ( $entry['olds'] as $old ) {
				if ( untrailingslashit( $old ) === untrailingslashit( $new ) ) {
					continue;
				}
				foreach ( [
					[ $old, $new ],
					[ trailingslashit( $old ) . self::PAGE_SUFFIX, trailingslashit( $new ) . self::PAGE_SUFFIX ],
				] as [ $from, $to ] ) {
					$key = untrailingslashit( $from );
					foreach ( array_keys( $by_dest[ $key ] ?? [] ) as $rid ) {
						$rows[ $rid ]['url_to'] = $to;
						$dirty[ $rid ]          = true;
						unset( $by_dest[ $key ][ $rid ] );
						$by_dest[ untrailingslashit( $to ) ][ $rid ] = true;
					}
				}
			}
		}

		// 2. Loops: a live URL of the subtree is never a redirect source (the source leaves its row; the row goes when it was the
		// only one).
		$live_paths = [];
		foreach ( $snap as $entry ) {
			$path = $this->path( $entry['new'] );
			if ( '' === $path ) {
				continue;
			}
			$live_paths[ $path ] = true;
			foreach ( [
				[ $path, 'exact', $exact[ $path ] ?? [] ],
				[ $this->page_pattern( $path ), 'regex', $regex[ $this->page_pattern( $path ) ] ?? [] ],
			] as [ $source, $comparison, $ids ] ) {
				foreach ( array_keys( $ids ) as $rid ) {
					$kept = [];
					foreach ( $rows[ $rid ]['sources'] as $src ) {
						$same = ( $src['comparison'] ?? 'exact' ) === $comparison
							&& ( 'exact' === $comparison ? trim( (string) ( $src['pattern'] ?? '' ), '/' ) : (string) ( $src['pattern'] ?? '' ) ) === $source;
						if ( ! $same ) {
							$kept[] = $src;
						}
					}
					$rows[ $rid ]['sources'] = $kept;
					$dirty[ $rid ]           = true;
					if ( 'exact' === $comparison ) {
						unset( $exact[ $source ][ $rid ] );
					} else {
						unset( $regex[ $source ][ $rid ] );
					}
					if ( [] === $kept ) {
						$deleted[ $rid ] = true;
					}
				}
			}
		}

		// 3. New rows: descendants exact (the edited term is Rank Math's), every changed URL paginated; a source already in the
		// store (any owner) is never written twice.
		$adds = [];
		foreach ( $snap as $id => $entry ) {
			foreach ( $entry['olds'] as $old ) {
				$old_path = $this->path( $old );
				if ( '' === $old_path || isset( $live_paths[ $old_path ] ) ) {
					continue;
				}
				if ( $id !== $edited && empty( $exact[ $old_path ] ) ) {
					$adds[]                 = [ $old_path, 'exact', $entry['new'] ];
					$exact[ $old_path ][-1] = true;
				}
				$pattern = $this->page_pattern( $old_path );
				if ( '' !== $pattern && empty( $regex[ $pattern ] ) ) {
					$adds[]                = [ $pattern, 'regex', trailingslashit( $entry['new'] ) . self::PAGE_SUFFIX ];
					$regex[ $pattern ][-1] = true;
				}
			}
		}

		// 4. Write: one delete call, one update per touched row, one insert per new row.
		$owned = $this->owned();
		if ( [] !== $deleted ) {
			$this->rank_math->delete_redirects( array_keys( $deleted ) );
			$owned = array_values( array_diff( $owned, array_keys( $deleted ) ) );
		}
		foreach ( array_keys( array_diff_key( $dirty, $deleted ) ) as $rid ) {
			$this->rank_math->write_redirect( $rows[ $rid ] );
		}
		foreach ( $adds as [ $source, $comparison, $dest ] ) {
			$id = $this->rank_math->add_redirect( $source, $dest, 301, $comparison );
			if ( $id > 0 ) {
				$owned[] = $id;
			} else {
				Log::warning( 'Could not write a category redirect.', [ 'source' => $source ], 'seo' );
			}
		}
		$this->save_owned( $owned );
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

		// Prime the term and relationship caches in bulk: one query instead of one per term (SEC-061).
		get_terms(
			[
				'taxonomy'   => TermCounts::TAXONOMY,
				'include'    => $ids,
				'hide_empty' => false,
				'fields'     => 'ids',
				'lang'       => '',
			]
		);
		update_object_term_cache( $ids, 'term' );

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
