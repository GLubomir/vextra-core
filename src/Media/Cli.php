<?php
/**
 * WP-CLI: `wp vextra media status|regenerate|report|rewrite-content` (TASK-009d). CLI only, no web entry point.
 *
 * Fallback without our commands: plain `wp media regenerate --yes` works too (the output-format filter is active in every
 * request), but it does not rewrite content URLs, keeps no baseline and prints no report.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Media;

use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;

final class Cli {

	/**
	 * Share of the current JPEG / PNG sub-size bytes the WebP files take (measured on the demo catalogue; dry-run estimate only).
	 */
	private const ESTIMATE_RATIO = 0.45;

	/**
	 * Regeneration service.
	 *
	 * @var Regenerate
	 */
	private Regenerate $regenerate;

	public function __construct( Regenerate $regenerate ) {
		$this->regenerate = $regenerate;
	}

	public function register(): void {
		\WP_CLI::add_command(
			'vextra media status',
			[ $this, 'status' ],
			[ 'shortdesc' => 'Encoder support of this PHP runtime (GD / Imagick, WebP / AVIF), the editor WordPress picks, the active target format. NOTE: CLI and web (Apache) PHP can differ.' ]
		);
		\WP_CLI::add_command(
			'vextra media regenerate',
			[ $this, 'regenerate' ],
			[ 'shortdesc' => 'Re-create the image sub-sizes in the target format (idempotent, originals untouched), then rewrite hard-coded content URLs. Flags: --ids=<csv>, --dry-run, --batch=<n> (default 20), --sync (run now instead of Action Scheduler).' ]
		);
		\WP_CLI::add_command(
			'vextra media report',
			[ $this, 'report' ],
			[ 'shortdesc' => 'Per-size totals (count, bytes, average), attachments missing sub-sizes, orphaned sub-size files, storage delta against the baseline.' ]
		);
		\WP_CLI::add_command(
			'vextra media prune-orphans',
			[ $this, 'prune_orphans' ],
			[ 'shortdesc' => 'Delete sub-size files (name-WxH.jpg|png|webp|avif in the dated upload folders) that no attachment references (leftovers of earlier registered sizes). Originals are never matched. Flag: --dry-run.' ]
		);
		\WP_CLI::add_command(
			'vextra media rewrite-content',
			[ $this, 'rewrite_content' ],
			[ 'shortdesc' => 'Point hard-coded URLs of replaced sub-size files (post content, block attributes, term descriptions, meta, options) at the new files. Flag: --dry-run.' ]
		);
	}

	/**
	 * `wp vextra media status`.
	 *
	 * @param array<int,string>   $args  Positional args (unused).
	 * @param array<string,mixed> $assoc Flags (unused).
	 */
	public function status( $args = [], $assoc = [] ): void {
		unset( $args, $assoc );
		$caps   = Formats::capabilities();
		$yn     = static fn( bool $v ): string => $v ? 'yes' : 'no';
		$target = Formats::target();
		\WP_CLI::log( sprintf( 'Runtime: PHP %s (%s)', $caps['php'], $caps['sapi'] ) );
		\WP_CLI::log( sprintf( 'GD: %s | WebP %s | AVIF %s', $yn( (bool) $caps['gd'] ), $yn( (bool) $caps['gd_webp'] ), $yn( (bool) $caps['gd_avif'] ) ) );
		\WP_CLI::log( sprintf( 'Imagick: %s | WebP %s | AVIF %s', $yn( (bool) $caps['imagick'] ), $yn( (bool) $caps['imagick_webp'] ), $yn( (bool) $caps['imagick_avif'] ) ) );
		\WP_CLI::log( sprintf( 'WordPress editor can write: WebP %s | AVIF %s', $yn( Formats::editor_supports( Formats::WEBP ) ), $yn( Formats::editor_supports( Formats::AVIF ) ) ) );
		\WP_CLI::log( 'Active target format: ' . $target . ' (sub-sizes of JPEG / PNG uploads; the original, -scaled, GIF, SVG stay as uploaded)' );
		$modules = Plugin::instance()->modules();
		\WP_CLI::log( 'Module media: ' . ( isset( $modules['media'] ) && ! in_array( 'media', Plugin::instance()->yielded(), true ) && $modules['media']->is_enabled() ? 'active' : 'inactive / yielded' ) );
		\WP_CLI::success( 'status printed' );
	}

	/**
	 * `wp vextra media regenerate [--ids=<csv>] [--dry-run] [--batch=<n>] [--sync]`.
	 *
	 * @param array<int,string>   $args  Positional args (unused).
	 * @param array<string,mixed> $assoc Flags.
	 */
	public function regenerate( $args = [], $assoc = [] ): void {
		unset( $args );
		$dry   = ! empty( $assoc['dry-run'] );
		$sync  = ! empty( $assoc['sync'] );
		$batch = max( 1, min( 200, (int) ( $assoc['batch'] ?? 20 ) ) );
		$ids   = null;
		if ( isset( $assoc['ids'] ) && '' !== (string) $assoc['ids'] ) {
			$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $assoc['ids'] ) ) ) );
		}
		$groups = $this->regenerate->groups( $ids );
		$plan   = $this->regenerate->plan( $groups );
		$target = Formats::target();
		\WP_CLI::log( sprintf( 'Target format: %s | file groups: %d | attachments: %d', $target, $plan['groups'], $plan['attachments'] ) );
		\WP_CLI::log( sprintf( 'To convert: %d | already in target: %d | no sub-sizes: %d', $plan['todo'], $plan['skipped_done'], $plan['skipped_nosizes'] ) );
		\WP_CLI::log( sprintf( 'Sub-size files to replace: %d (%s)', $plan['files'], size_format( $plan['bytes'], 2 ) ) );
		if ( $plan['bytes'] > 0 ) {
			$est = (int) round( $plan['bytes'] * self::ESTIMATE_RATIO );
			\WP_CLI::log( sprintf( 'Estimated after conversion: ~%s (delta ~-%s, ~-%d%%), estimate only', size_format( $est, 2 ), size_format( $plan['bytes'] - $est, 2 ), (int) round( 100 * ( 1 - self::ESTIMATE_RATIO ) ) ) );
		}
		$rewrite = ( new Rewriter() )->run( true );
		\WP_CLI::log( sprintf( 'Content URL rewrite now (dry): %d URL(s) in %d row(s) (the rest follows after the files are replaced)', $rewrite['total'], $rewrite['rows'] ) );

		if ( $dry ) {
			\WP_CLI::success( 'dry-run: nothing written' );
			return;
		}
		if ( 0 === $plan['todo'] ) {
			$this->finish();
			\WP_CLI::success( 'nothing to convert' );
			return;
		}

		$stats = new Stats();
		if ( $stats->store_baseline( $stats->snapshot() ) ) {
			\WP_CLI::log( 'Baseline stored in option ' . Stats::OPT_BASELINE );
		}

		$todo = [];
		foreach ( $groups as $file => $group_ids ) {
			$todo[ $file ] = $group_ids;
		}

		if ( $sync ) {
			$done   = 0;
			$failed = 0;
			$bytes  = [ 0, 0 ];
			foreach ( $todo as $file => $group_ids ) {
				$res = $this->regenerate->process( $group_ids );
				if ( 'done' === $res['status'] ) {
					++$done;
					$bytes[0] += $res['bytes_old'];
					$bytes[1] += $res['bytes_new'];
				} elseif ( 'failed' === $res['status'] || 'missing' === $res['status'] ) {
					++$failed;
					\WP_CLI::warning( sprintf( '%s: %s (%s)', $file, $res['status'], $res['reason'] ) );
				}
			}
			\WP_CLI::log( sprintf( 'Converted %d group(s), failed %d, sub-size bytes %s -> %s', $done, $failed, size_format( $bytes[0], 2 ), size_format( $bytes[1], 2 ) ) );
			$this->finish();
			if ( $failed > 0 ) {
				\WP_CLI::error( 'some attachments failed (old sub-sizes restored); see the warnings' );
			}
			\WP_CLI::success( 'regenerated' );
			return;
		}

		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( ! $woo instanceof WooCommerce || ! $woo->has_action_scheduler() ) {
			\WP_CLI::error( 'Action Scheduler is not available: run with --sync.' );
		}
		$all_ids = [];
		foreach ( $todo as $group_ids ) {
			foreach ( $group_ids as $one ) {
				$all_ids[] = $one;
			}
		}
		$jobs = 0;
		foreach ( array_chunk( $all_ids, $batch ) as $chunk ) {
			if ( $woo->enqueue_async_action( Regenerate::HOOK_BATCH, [ implode( ',', $chunk ) ], Regenerate::GROUP ) ) {
				++$jobs;
			}
		}
		\WP_CLI::success( sprintf( '%d batch job(s) queued in group %s. Run them: wp action-scheduler run --group=%s (the last batch queues the content rewrite + cache flush).', $jobs, Regenerate::GROUP, Regenerate::GROUP ) );
	}

	/**
	 * Synchronous finish: content rewrite + cache flush.
	 */
	private function finish(): void {
		$report = ( new Rewriter() )->run( false );
		Regenerate::flush_caches();
		\WP_CLI::log( sprintf( 'Content URLs rewritten: %d in %d row(s); fragment caches flushed', $report['total'], $report['rows'] ) );
	}

	/**
	 * `wp vextra media report`.
	 *
	 * @param array<int,string>   $args  Positional args (unused).
	 * @param array<string,mixed> $assoc Flags (unused).
	 */
	public function report( $args = [], $assoc = [] ): void {
		unset( $args, $assoc );
		$stats = new Stats();
		$snap  = $stats->snapshot();
		$rows  = [];
		foreach ( $snap['by_size'] as $name => $row ) {
			$rows[] = [
				'size'   => $name,
				'files'  => $row['count'],
				'bytes'  => $row['bytes'],
				'avg_kb' => round( $row['bytes'] / max( 1, $row['count'] ) / 1024, 1 ),
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'size', 'files', 'bytes', 'avg_kb' ] );
		\WP_CLI::log( sprintf( 'Attachments: %d | distinct sub-size files: %d | sub-size bytes: %s | originals: %s', $snap['attachments'], $snap['files'], size_format( $snap['bytes'], 2 ), size_format( $snap['original_bytes'], 2 ) ) );
		\WP_CLI::log( 'Sub-size files by extension: ' . wp_json_encode( $snap['by_ext'] ) );
		\WP_CLI::log( sprintf( 'Attachments missing sub-sizes: %d %s', count( $snap['missing'] ), array() === $snap['missing'] ? '' : '(' . implode( ',', array_slice( $snap['missing'], 0, 20 ) ) . ')' ) );
		$orphans = $stats->orphans( $snap['referenced'] );
		\WP_CLI::log( sprintf( 'Orphaned sub-size files: %d %s', count( $orphans ), array() === $orphans ? '' : '(' . implode( ', ', array_slice( $orphans, 0, 10 ) ) . ')' ) );
		$base = $stats->baseline();
		if ( null === $base ) {
			\WP_CLI::log( 'Baseline: none yet (stored before the first real regeneration).' );
		} else {
			$delta = (int) $snap['bytes'] - (int) $base['bytes'];
			\WP_CLI::log( sprintf( 'Baseline (%s): %s in %d files', (string) ( $base['time'] ?? '' ), size_format( (int) $base['bytes'], 2 ), (int) $base['files'] ) );
			\WP_CLI::log( sprintf( 'Storage delta: %s%s (%+.1f%%)', $delta < 0 ? '-' : '+', size_format( abs( $delta ), 2 ), 100 * $delta / max( 1, (int) $base['bytes'] ) ) );
		}
		\WP_CLI::success( 'report printed' );
	}

	/**
	 * `wp vextra media prune-orphans [--dry-run]`.
	 *
	 * @param array<int,string>   $args  Positional args (unused).
	 * @param array<string,mixed> $assoc Flags.
	 */
	public function prune_orphans( $args = [], $assoc = [] ): void {
		unset( $args );
		$dry     = ! empty( $assoc['dry-run'] );
		$stats   = new Stats();
		$snap    = $stats->snapshot();
		$orphans = $stats->orphans( $snap['referenced'] );
		$uploads = wp_get_upload_dir();
		$bytes   = 0;
		foreach ( $orphans as $rel ) {
			$path   = rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . $rel;
			$bytes += (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file = 0.
			if ( ! $dry ) {
				wp_delete_file( $path );
			}
		}
		\WP_CLI::success( sprintf( '%s: %d orphaned sub-size file(s), %s', $dry ? 'dry-run (nothing deleted)' : 'deleted', count( $orphans ), size_format( $bytes, 2 ) ) );
	}

	/**
	 * `wp vextra media rewrite-content [--dry-run]`.
	 *
	 * @param array<int,string>   $args  Positional args (unused).
	 * @param array<string,mixed> $assoc Flags.
	 */
	public function rewrite_content( $args = [], $assoc = [] ): void {
		unset( $args );
		$dry    = ! empty( $assoc['dry-run'] );
		$report = ( new Rewriter() )->run( $dry );
		foreach ( $report['by_source'] as $source => $n ) {
			\WP_CLI::log( sprintf( '%s: %d URL(s)', $source, $n ) );
		}
		foreach ( $report['samples'] as $s ) {
			\WP_CLI::log( sprintf( '  %s  %s -> %s', $s['source'], $s['from'], $s['to'] ) );
		}
		if ( ! $dry ) {
			Regenerate::flush_caches();
		}
		\WP_CLI::success( sprintf( '%s: %d URL(s) in %d row(s)', $dry ? 'dry-run (nothing written)' : 'rewritten', $report['total'], $report['rows'] ) );
	}
}
