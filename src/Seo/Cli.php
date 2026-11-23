<?php
/**
 * WP-CLI: `wp vextra seo status` (read-only), `wp vextra seo recount` (idempotent), `wp vextra seo transliterate` (pure) and
 * `wp vextra seo redirects` (read-only list of the redirects written by TermRedirects) and `wp vextra seo apply-config` (idempotent: the
 * three Rank Math redirect settings of ADR-011). CLI only, no web entry point.
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

	public function __construct( Polylang $polylang, RankMath $rank_math, TermCounts $counts, Transliterator $translit ) {
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
		if ( null === $changed ) {
			\WP_CLI::error( 'Could not write rank-math-options-general; set the three settings by hand (docs/system/modules/seo.md).' );
		}
		foreach ( [ 'redirections_post_redirect', 'attachment_redirect_urls', 'attachment_redirect_default' ] as $key ) {
			\WP_CLI::log( sprintf( '%s = %s%s', $key, $this->rank_math->stored_general_setting( $key ), in_array( $key, $changed, true ) ? ' (changed)' : '' ) );
		}
		\WP_CLI::success( [] === $changed ? 'Already applied, nothing changed.' : sprintf( 'Applied %d setting(s).', count( $changed ) ) );
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
}
