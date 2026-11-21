<?php
/**
 * WP-CLI: `wp vextra seo status` (read-only) and `wp vextra seo recount` (idempotent). CLI only, no web entry point.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

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

	public function __construct( Polylang $polylang, RankMath $rank_math, TermCounts $counts ) {
		$this->polylang  = $polylang;
		$this->rank_math = $rank_math;
		$this->counts    = $counts;
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
