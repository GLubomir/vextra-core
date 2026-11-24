<?php
/**
 * Re-creates the image sub-sizes of existing attachments in the target format (TASK-009d). Idempotent, resumable, batched.
 *
 * Per attachment GROUP (all attachments that share one `_wp_attached_file`: Polylang media translations are processed together, once):
 *  1. the old sub-size list is remembered, the `sizes` meta is emptied so core sees every registered size as missing;
 *  2. core `wp_update_image_subsizes()` writes the new files (the mapping filter makes them WebP / AVIF; the original is never touched);
 *  3. the result is verified: every old size key exists again, every new file exists on disk. Otherwise the old meta is restored and the new
 *     files are removed (nothing is lost);
 *  4. twins get the new `sizes` meta; ONLY THEN the old files that are no longer referenced are deleted.
 * Skipped silently: no image meta, no sub-sizes (source smaller than every size), already in the target format, GIF / SVG / PDF.
 * Action Scheduler: hook `vextra/media/regenerate_batch` (args: CSV of attachment ids), group `vextra-media`; the last batch triggers
 * `vextra/media/regenerate_finalize` (content URL rewrite + fragment cache flush).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Media;

use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;
use Vextra\Core\Support\Log;

final class Regenerate {

	public const HOOK_BATCH    = 'vextra/media/regenerate_batch';
	public const HOOK_FINALIZE = 'vextra/media/regenerate_finalize';
	public const GROUP         = 'vextra-media';
	public const OPT_RUN       = '_vx_media_run';

	public function register(): void {
		add_action( self::HOOK_BATCH, [ $this, 'run_batch' ] );
		add_action( self::HOOK_FINALIZE, [ $this, 'finalize' ] );
	}

	/**
	 * Image attachments that are conversion candidates, grouped by their attached file (twins together).
	 *
	 * @param int[]|null $ids Restrict to these attachment ids (null = all).
	 * @return array<string,int[]> Attached file => attachment ids.
	 */
	public function groups( ?array $ids = null ): array {
		global $wpdb;
		$mimes = array_map( 'esc_sql', Formats::SOURCES );
		$sql   = "SELECT p.ID, f.meta_value AS file FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file' WHERE p.post_type = 'attachment' AND p.post_mime_type IN ('" . implode( "','", $mimes ) . "')";
		if ( null !== $ids ) {
			$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
			if ( array() === $ids ) {
				return [];
			}
			$sql .= ' AND p.ID IN (' . implode( ',', $ids ) . ')';
		}
		$sql .= ' ORDER BY p.ID ASC';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- integers and constant mimes only; one-off CLI / batch query.
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A );
		$out  = [];
		foreach ( $rows as $row ) {
			$out[ (string) $row['file'] ][] = (int) $row['ID'];
		}
		return $out;
	}

	/**
	 * Groups an id list belongs to (a twin of a listed id is pulled in).
	 *
	 * @param int[] $ids Attachment ids.
	 * @return array<string,int[]>
	 */
	public function groups_of( array $ids ): array {
		$files = [];
		foreach ( $ids as $id ) {
			$file = (string) get_post_meta( (int) $id, '_wp_attached_file', true );
			if ( '' !== $file ) {
				$files[ $file ] = true;
			}
		}
		$all = $this->groups();
		return array_intersect_key( $all, $files );
	}

	/**
	 * Dry-run numbers for a group list: nothing is written.
	 *
	 * @param array<string,int[]> $groups Groups.
	 * @return array<string,int> Keys: groups, attachments, todo, skipped_done, skipped_nosizes, files, bytes.
	 */
	public function plan( array $groups ): array {
		$out    = [
			'groups'          => count( $groups ),
			'attachments'     => 0,
			'todo'            => 0,
			'skipped_done'    => 0,
			'skipped_nosizes' => 0,
			'files'           => 0,
			'bytes'           => 0,
		];
		$target = Formats::target();
		foreach ( $groups as $ids ) {
			$out['attachments'] += count( $ids );
			$meta                = wp_get_attachment_metadata( $ids[0] );
			$sizes               = is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : [];
			if ( array() === $sizes ) {
				++$out['skipped_nosizes'];
				continue;
			}
			if ( $this->all_in( $sizes, $target ) ) {
				++$out['skipped_done'];
				continue;
			}
			++$out['todo'];
			$dir = dirname( (string) get_attached_file( $ids[0] ) );
			foreach ( $this->files_of( $sizes ) as $file ) {
				++$out['files'];
				$out['bytes'] += (int) @filesize( $dir . '/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file counts as 0.
			}
		}
		return $out;
	}

	/**
	 * Action Scheduler callback: one batch.
	 *
	 * @param mixed $csv CSV of attachment ids.
	 */
	public function run_batch( $csv = '' ): void {
		$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $csv ) ) ) );
		foreach ( $this->groups_of( $ids ) as $group ) {
			$this->process( $group );
		}
		$this->maybe_finalize();
	}

	/**
	 * Process one group. Never throws.
	 *
	 * @param int[] $ids Attachment ids sharing one file (first = primary).
	 * @return array<string,mixed> status (`done`|`already`|`nosizes`|`failed`|`missing`), files_old, files_new, bytes_old, bytes_new, reason.
	 */
	public function process( array $ids ): array {
		$res = [
			'status'    => 'nosizes',
			'files_old' => 0,
			'files_new' => 0,
			'bytes_old' => 0,
			'bytes_new' => 0,
			'reason'    => '',
		];
		if ( array() === $ids ) {
			return $res;
		}
		if ( ! function_exists( 'wp_update_image_subsizes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$primary = (int) $ids[0];
		$meta    = wp_get_attachment_metadata( $primary );
		$path    = (string) get_attached_file( $primary );
		if ( ! is_array( $meta ) || '' === $path || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return $res;
		}
		$target = Formats::target();
		if ( $this->all_in( $meta['sizes'], $target ) ) {
			$res['status'] = 'already';
			return $res;
		}
		if ( ! is_file( (string) wp_get_original_image_path( $primary ) ) ) {
			$res['status'] = 'missing';
			$res['reason'] = 'original file not found';
			return $res;
		}

		$dir       = dirname( $path );
		$old_files = $this->files_of( $meta['sizes'] );
		foreach ( $old_files as $file ) {
			$res['bytes_old'] += (int) @filesize( $dir . '/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file counts as 0.
		}
		$res['files_old'] = count( $old_files );

		$emptied          = $meta;
		$emptied['sizes'] = [];
		wp_update_attachment_metadata( $primary, $emptied );
		$new = wp_update_image_subsizes( $primary );

		$problem = '';
		if ( is_wp_error( $new ) ) {
			$problem = $new->get_error_message();
		} elseif ( ! is_array( $new ) || empty( $new['sizes'] ) || ! is_array( $new['sizes'] ) ) {
			$problem = 'no sizes generated';
		} else {
			$lost = array_diff( array_keys( $meta['sizes'] ), array_keys( $new['sizes'] ) );
			if ( array() !== $lost ) {
				$problem = 'sizes lost: ' . implode( ',', $lost );
			} else {
				foreach ( $this->files_of( $new['sizes'] ) as $file ) {
					if ( ! is_file( $dir . '/' . $file ) ) {
						$problem = 'new file missing: ' . $file;
						break;
					}
				}
			}
		}

		if ( '' !== $problem ) {
			// Roll back: old meta, drop the files the failed run created (never an old one).
			$created = is_array( $new ) && ! empty( $new['sizes'] ) && is_array( $new['sizes'] ) ? array_diff( $this->files_of( $new['sizes'] ), $old_files ) : [];
			wp_update_attachment_metadata( $primary, $meta );
			foreach ( $created as $file ) {
				wp_delete_file( $dir . '/' . $file );
			}
			$res['status'] = 'failed';
			$res['reason'] = $problem;
			Log::warning(
				'Media regenerate failed, old sub-sizes restored',
				[
					'attachment' => $primary,
					'reason'     => $problem,
				],
				'media'
			);
			return $res;
		}

		$new_files = $this->files_of( $new['sizes'] );
		foreach ( array_slice( $ids, 1 ) as $twin ) {
			$twin_meta = wp_get_attachment_metadata( (int) $twin );
			if ( is_array( $twin_meta ) ) {
				$twin_meta['sizes'] = $new['sizes'];
				wp_update_attachment_metadata( (int) $twin, $twin_meta );
			}
		}
		foreach ( array_diff( $old_files, $new_files ) as $file ) {
			wp_delete_file( $dir . '/' . $file );
		}
		foreach ( $new_files as $file ) {
			$res['bytes_new'] += (int) @filesize( $dir . '/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- existence verified above.
		}
		$res['files_new'] = count( $new_files );
		$res['status']    = 'done';
		return $res;
	}

	/**
	 * Called after every batch: queue the finalizer once no other batch is open.
	 */
	private function maybe_finalize(): void {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( ! $woo instanceof WooCommerce || ! $woo->has_action_scheduler() ) {
			return;
		}
		if ( $woo->open_actions( self::HOOK_BATCH, self::GROUP ) > 1 ) { // This batch is still "in progress" itself.
			return;
		}
		$woo->enqueue_async_action( self::HOOK_FINALIZE, [], self::GROUP );
	}

	/**
	 * Action Scheduler callback: content URL rewrite + fragment cache flush. Re-schedules itself while batches are open.
	 */
	public function finalize(): void {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( $woo instanceof WooCommerce && $woo->open_actions( self::HOOK_BATCH, self::GROUP ) > 0 ) {
			$woo->enqueue_single_action( self::HOOK_FINALIZE, [], self::GROUP, 60 );
			return;
		}
		$report = ( new Rewriter() )->run( false );
		self::flush_caches();
		Log::info( 'Media regenerate finalized', [ 'rewritten' => $report['total'] ], 'media' );
	}

	/**
	 * Drop the cached HTML fragments that embed image URLs (theme `vx_*` transients, Polylang-independent) and the object cache.
	 * Entries rebuild on the next request (stale-while-revalidate where the theme uses it).
	 */
	public static function flush_caches(): void {
		global $wpdb;
		$like_a = $wpdb->esc_like( '_transient_vx_' ) . '%';
		$like_b = $wpdb->esc_like( '_transient_timeout_vx_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like_a, $like_b ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- our own transients only.
		wp_cache_delete( 'alloptions', 'options' );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}
	}

	/**
	 * Whether every sub-size entry is already in the target mime.
	 *
	 * @param array<string,mixed> $sizes Meta `sizes`.
	 * @param string              $mime  Target mime.
	 */
	private function all_in( array $sizes, string $mime ): bool {
		foreach ( $sizes as $size ) {
			if ( ! is_array( $size ) || ( $size['mime-type'] ?? '' ) !== $mime ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Distinct file names of a `sizes` meta (several size names often share one file).
	 *
	 * @param array<string,mixed> $sizes Meta `sizes`.
	 * @return string[]
	 */
	private function files_of( array $sizes ): array {
		$files = [];
		foreach ( $sizes as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$files[ wp_basename( (string) $size['file'] ) ] = true;
			}
		}
		return array_keys( $files );
	}
}
