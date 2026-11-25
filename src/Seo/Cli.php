<?php
/**
 * WP-CLI: `wp vextra seo status` (read-only), `wp vextra seo recount` (idempotent), `wp vextra seo transliterate` (pure) and
 * `wp vextra seo redirects` (read-only list of the redirects written by TermRedirects) and `wp vextra seo apply-config` (idempotent: the
 * three Rank Math redirect settings of ADR-011 + `titles.noindex_password_protected`). CLI only, no web entry point.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Compat\Conflicts;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;

final class Cli {

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
	 * Hierarchical counts.
	 *
	 * @var TermCounts
	 */
	private TermCounts $counts;

	/**
	 * Transliterator.
	 *
	 * @var Transliterator
	 */
	private Transliterator $translit;

	/**
	 * Index gate.
	 *
	 * @var LaunchPages
	 */
	private LaunchPages $launch;

	public function __construct( Polylang $polylang, RankMath $rank_math, TermCounts $counts, Transliterator $translit, LaunchPages $launch ) {
		$this->launch    = $launch;
		$this->polylang  = $polylang;
		$this->rank_math = $rank_math;
		$this->counts    = $counts;
		$this->translit  = $translit;
	}

	public function register(): void {
		\WP_CLI::add_command(
			'vextra seo status',
			[ $this, 'status' ],
			[
				'shortdesc' => 'Read-only table of every product category (all languages): direct and hierarchical product count, manual noindex, robots result, sitemap membership.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'format',
						'description' => 'table (default), json, csv.',
						'optional'    => true,
					],
				],
			]
		);
		\WP_CLI::add_command(
			'vextra seo recount',
			[ $this, 'recount' ],
			[
				'shortdesc' => 'Run the WooCommerce term recount and drop the SEO count cache (idempotent).',
			]
		);
		\WP_CLI::add_command(
			'vextra seo transliterate',
			[ $this, 'transliterate' ],
			[
				'shortdesc' => 'Print the slug the Seo module would create for a text (pure, writes nothing).',
				'synopsis'  => [
					[
						'type'     => 'positional',
						'name'     => 'text',
						'optional' => false,
					],
					[
						'type'     => 'assoc',
						'name'     => 'lang',
						'optional' => true,
						'default'  => 'uk',
						'options'  => [ 'uk', 'ru' ],
					],
					[
						'type'     => 'assoc',
						'name'     => 'max',
						'optional' => true,
						'default'  => (string) Transliterator::DEFAULT_MAX,
					],
				],
			]
		);
		\WP_CLI::add_command(
			'vextra seo apply-config',
			[ $this, 'apply_config' ],
			[
				'shortdesc' => 'Idempotent: Rank Math Auto Post Redirect ON, attachment redirect ON, attachment fallback = site home. Writes only these three keys.',
			]
		);
		\WP_CLI::add_command(
			'vextra seo redirects',
			[ $this, 'redirects' ],
			[
				'shortdesc' => 'Read-only list of the redirects written by the category move/re-slug handler (Rank Math Redirections).',
				'synopsis'  => [
					[
						'type'     => 'assoc',
						'name'     => 'format',
						'optional' => true,
					],
				],
			]
		);
		\WP_CLI::add_command(
			'vextra seo launch-pages',
			[ $this, 'launch_pages' ],
			[
				'shortdesc' => 'Index gate of the info pages: a UA + RU pair is set to index only when BOTH twins have enough real text and no owner placeholder; a failing pair is refused entirely (exit 1).',
				'synopsis'  => [
					[
						'type'      => 'positional',
						'name'      => 'page',
						'optional'  => true,
						'repeating' => true,
					],
					[
						'type'     => 'flag',
						'name'     => 'dry-run',
						'optional' => true,
					],
					[
						'type'     => 'assoc',
						'name'     => 'only',
						'optional' => true,
					],
				],
			]
		);
		\WP_CLI::add_command(
			'vextra seo audit-pages',
			[ $this, 'audit_pages' ],
			[
				'shortdesc' => 'Read-only: launch pages that are indexable with placeholder content, noindex with real content, or have no twin.',
			]
		);
	}

	/**
	 * Command: transliterate.
	 *
	 * @param array<int,string>    $args  Positional args (text).
	 * @param array<string,string> $assoc Assoc args (lang, max).
	 */
	public function transliterate( array $args, array $assoc ): void {
		$lang = isset( $assoc['lang'] ) ? sanitize_key( $assoc['lang'] ) : 'uk';
		$max  = isset( $assoc['max'] ) ? max( 1, (int) $assoc['max'] ) : Transliterator::DEFAULT_MAX;
		\WP_CLI::line( $this->translit->transliterate( (string) ( $args[0] ?? '' ), $lang, $max ) );
	}

	/**
	 * Command: apply-config. Exits non-zero when the Redirections module is off or the option cannot be written.
	 *
	 * @param array<int,string>    $args  Positional args (unused).
	 * @param array<string,string> $assoc Assoc args (unused).
	 */
	public function apply_config( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->rank_math->redirections_available() ) {
			\WP_CLI::error( 'Rank Math or its Redirections module is not active: nothing applied.' );
		}
		$home   = home_url( '/' );
		$values = [
			'redirections_post_redirect' => 'on',
			'attachment_redirect_urls'   => 'on',
		];
		// A fallback that already equals the home (scheme / trailing slash aside) is left as it is.
		if ( ! Conflicts::same_home( $this->rank_math->stored_general_setting( 'attachment_redirect_default' ), $home ) ) {
			$values['attachment_redirect_default'] = $home;
		}
		$changed = $this->rank_math->write_general_settings( $values );
		$titles  = $this->rank_math->write_titles_settings( [ 'noindex_password_protected' => 'on' ] );
		$sitemap = $this->rank_math->write_sitemap_settings( [ 'include_featured_image' => 'on' ] );
		if ( null === $changed || null === $titles || null === $sitemap ) {
			\WP_CLI::error( 'Could not write the Rank Math options; set the four settings by hand (docs/system/modules/seo.md).' );
		}
		foreach ( [ 'redirections_post_redirect', 'attachment_redirect_urls', 'attachment_redirect_default' ] as $key ) {
			\WP_CLI::log( sprintf( '%s = %s%s', $key, $this->rank_math->stored_general_setting( $key ), in_array( $key, $changed, true ) ? ' (changed)' : '' ) );
		}
		\WP_CLI::log( sprintf( 'titles.noindex_password_protected = %s%s', $this->rank_math->stored_titles_setting( 'noindex_password_protected' ), array() !== $titles ? ' (changed)' : '' ) );
		\WP_CLI::log( sprintf( 'sitemap.include_featured_image = %s%s', $this->rank_math->stored_sitemap_setting( 'include_featured_image' ), array() !== $sitemap ? ' (changed)' : '' ) );
		$total = count( $changed ) + count( $titles ) + count( $sitemap );
		\WP_CLI::success( 0 === $total ? 'Already applied, nothing changed.' : sprintf( 'Applied %d setting(s).', $total ) );
	}

	/**
	 * Command: redirects.
	 *
	 * @param array<int,string>    $args  Positional args (unused).
	 * @param array<string,string> $assoc Assoc args.
	 */
	public function redirects( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$rows = [];
		foreach ( TermRedirects::owned() as $id ) {
			$row = $this->rank_math->get_redirect( $id );
			if ( null === $row ) {
				continue;
			}
			$src    = $row['sources'][0] ?? [];
			$rows[] = [
				'id'         => $id,
				'type'       => $row['header_code'],
				'comparison' => (string) ( $src['comparison'] ?? '' ),
				'source'     => (string) ( $src['pattern'] ?? '' ),
				'to'         => $row['url_to'],
			];
		}
		$format = isset( $assoc['format'] ) ? (string) $assoc['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $rows, [ 'id', 'type', 'comparison', 'source', 'to' ] );
		\WP_CLI::log( sprintf( '%d redirect(s) owned by the Seo module.', count( $rows ) ) );
	}

	/**
	 * Command: status.
	 *
	 * @param array<int,string>    $args  Positional args (unused).
	 * @param array<string,string> $assoc Assoc args.
	 */
	public function status( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$ids = get_terms(
			[
				'taxonomy'   => TermCounts::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
				'lang'       => '',
				'orderby'    => 'id',
			]
		);
		if ( is_wp_error( $ids ) ) {
			\WP_CLI::error( $ids->get_error_message() );
		}

		$rows     = [];
		$by_lang  = [];
		$mismatch = 0;
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$term = get_term( $id, TermCounts::TAXONOMY );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$lang   = $this->polylang->term_language( $id );
			$manual = $this->rank_math->is_term_noindex( $id, TermCounts::TAXONOMY );
			$has    = $this->counts->has_products( $id );
			$index  = $has && ! $manual;
			$raw    = (int) $term->count;
			$direct = $this->counts->direct( $id );
			$total  = $this->counts->count( $id );
			$by_lang[ $lang ][ $index ? 'index' : 'noindex' ] = ( $by_lang[ $lang ][ $index ? 'index' : 'noindex' ] ?? 0 ) + 1;
			if ( $raw !== $direct ) {
				++$mismatch;
			}
			$rows[] = [
				'id'           => $id,
				'lang'         => $lang,
				'slug'         => $term->slug,
				'parent'       => (int) $term->parent,
				'wp_count'     => $raw,
				'direct'       => $direct,
				'hierarchical' => $total,
				'manual_noidx' => $manual ? 'yes' : 'no',
				'robots'       => $index ? 'index' : 'noindex',
				'in_sitemap'   => $index ? 'yes' : 'no',
			];
		}

		$format = isset( $assoc['format'] ) ? (string) $assoc['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $rows, array_keys( $rows[0] ?? [ 'id' => 0 ] ) );

		foreach ( $by_lang as $lang => $n ) {
			\WP_CLI::log( sprintf( '%s: indexable (in sitemap) %d, noindex %d', '' === $lang ? '(none)' : $lang, $n['index'] ?? 0, $n['noindex'] ?? 0 ) );
		}
		if ( $mismatch > 0 ) {
			\WP_CLI::warning( sprintf( '%d term(s) have term_taxonomy.count different from the real direct published count (run `wp vextra seo recount`); the Seo module ignores that column.', $mismatch ) );
		}
	}

	/**
	 * Command: recount.
	 *
	 * @param array<int,string>    $args  Positional args (unused).
	 * @param array<string,string> $assoc Assoc args (unused).
	 */
	public function recount( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! function_exists( 'wc_recount_all_terms' ) ) {
			\WP_CLI::error( 'WooCommerce term recount is not available.' );
		}
		wc_recount_all_terms();
		$this->counts->invalidate();
		\WP_CLI::success( 'WooCommerce term counts recalculated, SEO count cache dropped.' );
	}

	/**
	 * Command: launch-pages. Index gate: UA + RU twins are evaluated together; a pair is flipped to index only when BOTH pass.
	 *
	 * @param array<int,string>    $args  Slugs or ids (empty = all launch pages).
	 * @param array<string,string> $assoc `dry-run`, `only`.
	 */
	public function launch_pages( array $args, array $assoc ): void {
		$dry   = isset( $assoc['dry-run'] );
		$slugs = $this->launch->slugs();
		$want  = [];
		foreach ( $args as $arg ) {
			$arg = (string) $arg;
			if ( ctype_digit( $arg ) ) {
				$slug = $this->launch->launch_slug( (int) $arg );
				if ( '' === $slug ) {
					\WP_CLI::error( sprintf( 'Page %d is not a launch page (refused). Launch pages: %s.', (int) $arg, implode( ', ', $slugs ) ) );
				}
				$want[] = $slug;
			} else {
				if ( ! in_array( $arg, $slugs, true ) ) {
					\WP_CLI::error( sprintf( '"%s" is not a launch page (refused). Launch pages: %s.', $arg, implode( ', ', $slugs ) ) );
				}
				$want[] = $arg;
			}
		}
		if ( isset( $assoc['only'] ) ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) $assoc['only'] ) ) ) as $arg ) {
				if ( ! in_array( $arg, $slugs, true ) ) {
					\WP_CLI::error( sprintf( '"%s" is not a launch page (refused).', $arg ) );
				}
				$want[] = $arg;
			}
		}
		$want = array() === $want ? $slugs : array_values( array_unique( $want ) );

		$rows    = [];
		$refused = 0;
		$flipped = 0;
		$langs   = $this->polylang->languages();
		foreach ( $want as $slug ) {
			$group   = $this->launch->pair( $slug );
			$verdict = [];
			$missing = [];
			foreach ( $langs as $lang ) {
				if ( empty( $group[ $lang ] ) ) {
					$missing[] = (string) $lang;
					continue;
				}
				$verdict[ $lang ] = $this->launch->inspect( (int) $group[ $lang ] );
			}
			$pair_ok = array() === $missing && array() !== $verdict;
			foreach ( $verdict as $v ) {
				$pair_ok = $pair_ok && $v['ok'];
			}

			$was_noindex = [];
			foreach ( $group as $gid ) {
				$was_noindex[ (int) $gid ] = $this->rank_math->is_post_noindex( (int) $gid );
			}
			$changed = false;
			if ( $pair_ok && ! $dry ) {
				foreach ( $group as $id ) {
					$this->rank_math->clear_post_noindex( (int) $id );
					// A page with approved real text is no longer seeded demo content: the sitemap excludes `_vx_demo` pages (Seo\Sitemap).
					delete_post_meta( (int) $id, '_vx_demo' );
				}
				$changed = true;
				++$flipped;
			}
			if ( ! $pair_ok ) {
				++$refused;
			}

			foreach ( $verdict as $lang => $v ) {
				$id     = (int) $group[ $lang ];
				$rows[] = [
					'slug'        => $slug,
					'lang'        => $lang,
					'id'          => $id,
					'chars'       => $v['chars'],
					'placeholder' => 'placeholder' === $v['reason'] ? 'yes' : 'no',
					'robots'      => $was_noindex[ $id ] ? 'noindex' : 'index',
					'result'      => $pair_ok ? ( $dry ? 'would flip' : 'flipped' ) : 'REFUSED (' . ( '' !== $v['reason'] ? $v['reason'] . ( '' !== $v['snippet'] ? ': ' . $v['snippet'] : '' ) : 'twin: ' . ( array() !== $missing ? 'missing' : 'other twin failed' ) ) . ')',
				];
			}
			foreach ( $missing as $lang ) {
				$rows[] = [
					'slug'        => $slug,
					'lang'        => $lang,
					'id'          => 0,
					'chars'       => 0,
					'placeholder' => 'no',
					'robots'      => '-',
					'result'      => 'REFUSED (twin missing)',
				];
			}
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'slug', 'lang', 'id', 'chars', 'placeholder', 'robots', 'result' ] );
		if ( $flipped > 0 ) {
			$this->rank_math->invalidate_sitemap( 'page' );
		}
		\WP_CLI::log( sprintf( '%d pair(s) %s, %d refused%s.', $flipped, $dry ? 'checked (dry-run, nothing written)' : 'set to index', $refused, $flipped > 0 ? '; page sitemap cache dropped' : '' ) );
		if ( $refused > 0 ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Command: audit-pages (read-only): indexable pages with placeholder content, noindex pages with real content.
	 *
	 * @param array<int,string>    $args  Unused.
	 * @param array<string,string> $assoc Unused.
	 */
	public function audit_pages( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$rows = [];
		foreach ( $this->launch->slugs() as $slug ) {
			$group = $this->launch->pair( $slug );
			foreach ( $this->polylang->languages() as $lang ) {
				if ( empty( $group[ $lang ] ) ) {
					$rows[] = [
						'slug'    => $slug,
						'lang'    => $lang,
						'id'      => 0,
						'chars'   => 0,
						'noindex' => '-',
						'finding' => 'twin missing (pair cannot be flipped; no hreflang)',
					];
					continue;
				}
				$id      = (int) $group[ $lang ];
				$v       = $this->launch->inspect( $id );
				$noindex = $this->rank_math->is_post_noindex( $id );
				$finding = '';
				if ( ! $noindex && ! $v['ok'] ) {
					$finding = 'indexable by meta but ' . $v['reason'] . ' (the robots guard serves noindex)';
				} elseif ( $noindex && $v['ok'] ) {
					$finding = 'noindex with real content (ready for launch-pages)';
				}
				if ( '' !== $finding ) {
					$rows[] = [
						'slug'    => $slug,
						'lang'    => $lang,
						'id'      => $id,
						'chars'   => $v['chars'],
						'noindex' => $noindex ? 'yes' : 'no',
						'finding' => $finding,
					];
				}
			}
		}
		if ( array() === $rows ) {
			\WP_CLI::success( 'No mismatches: every launch page is noindex with placeholder content or indexable with real content.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'slug', 'lang', 'id', 'chars', 'noindex', 'finding' ] );
	}
}
