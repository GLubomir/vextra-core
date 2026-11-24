<?php
/**
 * WP-CLI: `wp vextra recommend explain <product_id> [--for=product|cart|post_add] [--list=accessories|related] [--lang=uk|ru]
 * [--format=table|json]` and `wp vextra recommend flush` (TASK-038). CLI only, no web entry point.
 *
 * `explain` always builds fresh (nothing is cached under WP-CLI) and prints per source the raw candidates, per filter the ids it
 * dropped with the reason, and the final ranked list with source, partner and the tie-break key.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend;

use Vextra\Core\Integrations\Polylang\Polylang;

final class Cli {

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	public function __construct( Polylang $polylang ) {
		$this->polylang = $polylang;
	}

	public function register(): void {
		\WP_CLI::add_command(
			'vextra recommend explain',
			[ $this, 'explain' ],
			[ 'shortdesc' => 'Explain a recommendation list: raw candidates per source, ids dropped per filter with the reason, final ranked list. Always built fresh (cache bypassed). Cart context: comma-separated product ids.' ]
		);
		\WP_CLI::add_command(
			'vextra recommend flush',
			[ $this, 'flush' ],
			[ 'shortdesc' => 'Mark every recommendation bundle stale (stale-while-revalidate: the next request rebuilds in the background).' ]
		);
	}

	/**
	 * `wp vextra recommend explain <product_id> [--for=...] [--list=...] [--lang=...] [--format=...]`.
	 *
	 * @param array<int,string>   $args  Positional args: product id (cart: comma list).
	 * @param array<string,mixed> $assoc Flags.
	 */
	public function explain( $args = [], $assoc = [] ): void {
		$context = (string) ( $assoc['for'] ?? 'product' ); // NOT `--context`: that is a WP-CLI global (admin|frontend|cli|auto).
		$list    = (string) ( $assoc['list'] ?? 'related' );
		$format  = (string) ( $assoc['format'] ?? 'table' );
		$lang    = (string) ( $assoc['lang'] ?? '' );
		if ( ! in_array( $context, [ 'product', 'cart', 'post_add' ], true ) || ! in_array( $list, [ 'accessories', 'related' ], true ) || ! in_array( $format, [ 'table', 'json' ], true ) ) {
			\WP_CLI::error( 'Invalid --for / --list / --format.' );
		}

		$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $args[0] ?? '' ) ) ) ) );
		if ( array() === $ids ) {
			\WP_CLI::error( 'Product id required.' );
		}
		foreach ( $ids as $id ) {
			if ( 'product' !== get_post_type( $id ) ) {
				\WP_CLI::error( 'Product ' . $id . ' not found.' );
			}
		}
		if ( '' === $lang ) {
			$lang = $this->polylang->post_language( $ids[0] );
		}
		if ( '' !== $lang && $this->polylang->is_ready() && ! in_array( $lang, $this->polylang->languages(), true ) ) {
			\WP_CLI::error( 'Unknown language ' . $lang . '.' );
		}

		$built = Engine::explain( $context, $ids, $lang );
		$name  = 'cart' === $context ? 'cart' : ( 'post_add' === $context ? 'post_add' : $list );
		$trace = $built['trace'];
		if ( array() === $trace ) {
			\WP_CLI::error( 'Nothing to explain: the product is not published or has no price row.' );
		}

		if ( 'json' === $format ) {
			\WP_CLI::print_value(
				[
					'cache'   => 'bypassed',
					'context' => $context,
					'list'    => $name,
					'anchor'  => $trace['anchor'],
					'sources' => $trace['sources'],
					'trace'   => $trace['lists'][ $name ] ?? [],
					'final'   => $built['lists'][ $name ] ?? [],
				],
				[ 'format' => 'json' ]
			);
			return;
		}

		\WP_CLI::log( 'Cache: bypassed (fresh build, nothing is cached under WP-CLI).' );
		\WP_CLI::log( 'Anchor: ' . wp_json_encode( $trace['anchor'] ) );
		foreach ( (array) $trace['sources'] as $source => $items ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Source ' . $source . ':' );
			$flat = array() === $items || isset( $items[0]['id'] );
			foreach ( $flat ? [ '' => $items ] : (array) $items as $label => $candidates ) {
				\WP_CLI::log( '  ' . ( $flat ? '' : $label . ': ' ) . $this->describe( (array) $candidates ) );
			}
		}
		$list_trace = $trace['lists'][ $name ] ?? [];
		\WP_CLI::log( '' );
		\WP_CLI::log( 'List ' . $name . ': ' . (int) ( $list_trace['candidates'] ?? 0 ) . ' candidates after dedupe.' );
		foreach ( (array) ( $list_trace['filters'] ?? [] ) as $filter ) {
			$dropped = array_map( static fn( array $d ): string => $d[0] . ' (' . $d[1] . ')', (array) $filter['dropped'] );
			\WP_CLI::log( sprintf( '  filter %-12s dropped %d%s', $filter['filter'], count( $dropped ), array() === $dropped ? '' : ': ' . implode( ', ', $dropped ) ) );
		}
		\WP_CLI::log( '  ranked (source, partner, tie-break):' );
		foreach ( (array) ( $list_trace['ranked'] ?? [] ) as $pos => $item ) {
			$partner = (string) ( $item['p'] ?? '' );
			$partner = '' === $partner ? '(default)' : $partner;
			$extra   = isset( $item['meta'] ) ? ' ' . wp_json_encode( $item['meta'] ) : '';
			\WP_CLI::log( sprintf( '    %d. #%d %-9s partner=%s%s', $pos + 1, $item['id'], $item['src'], $partner, $extra ) );
		}
		\WP_CLI::log( 'Final: ' . wp_json_encode( $built['lists'][ $name ] ?? [] ) );
	}

	/**
	 * `wp vextra recommend flush`.
	 */
	public function flush(): void {
		Store::flush_now();
		\WP_CLI::success( 'Recommendation bundles marked stale.' );
	}

	/**
	 * One line per candidate list.
	 *
	 * @param array<int,array<string,mixed>> $candidates Candidates.
	 */
	private function describe( array $candidates ): string {
		if ( array() === $candidates ) {
			return '(none)';
		}
		return implode(
			' ',
			array_map(
				static fn( array $c ): string => '#' . $c['id'] . ( isset( $c['meta'] ) ? wp_json_encode( $c['meta'] ) : '' ),
				$candidates
			)
		);
	}
}
