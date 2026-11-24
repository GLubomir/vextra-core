<?php
/**
 * Sub-size statistics of the Media module (TASK-009d): per-size totals, missing sub-sizes, orphaned files, baseline for the storage delta.
 *
 * Read-only except `store_baseline()` (option `_vx_media_baseline`, written once before the first real regeneration).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Media;

final class Stats {

	public const OPT_BASELINE = '_vx_media_baseline';

	/**
	 * Sub-size file name pattern (also matches the WebP / AVIF names).
	 */
	private const SUBSIZE = '/-\d+x\d+\.(?:jpe?g|png|webp|avif)$/i';

	/**
	 * Snapshot of every image attachment.
	 *
	 * @return array{attachments:int,files:int,bytes:int,original_bytes:int,by_size:array<string,array{count:int,bytes:int}>,by_ext:array<string,int>,missing:int[],referenced:array<string,bool>}
	 */
	public function snapshot(): array {
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' ORDER BY ID" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- constant query.
		if ( ! function_exists( 'wp_get_missing_image_subsizes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$out        = [
			'attachments'    => count( $ids ),
			'files'          => 0,
			'bytes'          => 0,
			'original_bytes' => 0,
			'by_size'        => [],
			'by_ext'         => [],
			'missing'        => [],
			'referenced'     => [],
		];
		$seen_files = [];
		$seen_size  = [];
		foreach ( $ids as $id ) {
			$meta = wp_get_attachment_metadata( $id );
			$path = (string) get_attached_file( $id );
			if ( ! is_array( $meta ) || '' === $path ) {
				continue;
			}
			$dir = dirname( $path );
			$out['referenced'][ wp_normalize_path( $path ) ] = true;
			if ( ! empty( $meta['original_image'] ) ) {
				$out['referenced'][ wp_normalize_path( $dir . '/' . $meta['original_image'] ) ] = true;
			}
			if ( ! isset( $seen_files[ wp_normalize_path( $path ) ] ) ) {
				$seen_files[ wp_normalize_path( $path ) ] = true;
				$out['original_bytes']                   += (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file = 0.
			}
			$sizes = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : [];
			foreach ( $sizes as $name => $size ) {
				if ( ! is_array( $size ) || empty( $size['file'] ) ) {
					continue;
				}
				$file                       = wp_normalize_path( $dir . '/' . wp_basename( (string) $size['file'] ) );
				$out['referenced'][ $file ] = true;
				if ( isset( $seen_size[ $name . '|' . $file ] ) ) {
					continue;
				}
				$seen_size[ $name . '|' . $file ] = true;
				$bytes                            = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file = 0.
				$out['by_size'][ $name ]          = [
					'count' => ( $out['by_size'][ $name ]['count'] ?? 0 ) + 1,
					'bytes' => ( $out['by_size'][ $name ]['bytes'] ?? 0 ) + $bytes,
				];
				if ( ! isset( $seen_files[ $file ] ) ) {
					$seen_files[ $file ]   = true;
					$out['files']         += 1;
					$out['bytes']         += $bytes;
					$ext                   = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
					$out['by_ext'][ $ext ] = ( $out['by_ext'][ $ext ] ?? 0 ) + 1;
				}
			}
			$backup = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
			if ( is_array( $backup ) ) {
				foreach ( $backup as $b ) {
					if ( is_array( $b ) && ! empty( $b['file'] ) ) {
						$out['referenced'][ wp_normalize_path( $dir . '/' . $b['file'] ) ] = true;
					}
				}
			}
			if ( array() !== wp_get_missing_image_subsizes( $id ) ) {
				$out['missing'][] = $id;
			} else {
				foreach ( $sizes as $size ) {
					if ( is_array( $size ) && ! empty( $size['file'] ) && ! is_file( $dir . '/' . wp_basename( (string) $size['file'] ) ) ) {
						$out['missing'][] = $id;
						break;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Files in the dated upload folders that look like a sub-size but no attachment references (leftovers of a regeneration).
	 *
	 * @param array<string,bool> $referenced Normalised paths referenced by attachment meta.
	 * @return string[] Paths relative to the uploads base.
	 */
	public function orphans( array $referenced ): array {
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( rtrim( (string) $uploads['basedir'], '/\\' ) );
		$out     = [];
		foreach ( (array) glob( $base . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]/*', GLOB_NOSORT ) as $file ) {
			$file = wp_normalize_path( (string) $file );
			if ( 1 === preg_match( self::SUBSIZE, $file ) && ! isset( $referenced[ $file ] ) && is_file( $file ) ) {
				$out[] = substr( $file, strlen( $base ) + 1 );
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Store the baseline once (before the first real regeneration).
	 *
	 * @param array<string,mixed> $snapshot Result of snapshot().
	 */
	public function store_baseline( array $snapshot ): bool {
		if ( false !== get_option( self::OPT_BASELINE, false ) ) {
			return false;
		}
		unset( $snapshot['referenced'], $snapshot['missing'] );
		$snapshot['time'] = gmdate( 'c' );
		return add_option( self::OPT_BASELINE, $snapshot, '', false );
	}

	/**
	 * The stored baseline.
	 *
	 * @return array<string,mixed>|null
	 */
	public function baseline(): ?array {
		$b = get_option( self::OPT_BASELINE, null );
		return is_array( $b ) ? $b : null;
	}
}
