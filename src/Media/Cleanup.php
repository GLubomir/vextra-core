<?php
/**
 * Removes the sub-size files of a deleted attachment (TASK-009d, AC10).
 *
 * Why: Polylang (media support ON) hooks `wp_delete_file` and refuses to delete a file while ANY attachment meta still mentions it. Core calls
 * the deletion while the deleted attachment's own `_wp_attachment_metadata` row still exists, so every sub-size file stays on disk (measured on
 * the local site with the Media module OFF as well: 20 of 22 files left). Here the sub-size list is remembered on `delete_attachment` (meta
 * still readable) and the files are removed on `deleted_post` (meta rows gone), but only when no other attachment (a Polylang translation
 * shares the file) still references that file. The attached original is core's business and is not touched.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Media;

final class Cleanup {

	/**
	 * Pending removals: attachment id => [ dir, subdir, files ].
	 *
	 * @var array<int,array{dir:string,subdir:string,files:string[]}>
	 */
	private array $pending = [];

	public function register(): void {
		add_action( 'delete_attachment', [ $this, 'remember' ], 1 );
		add_action( 'deleted_post', [ $this, 'purge' ], 10, 1 );
	}

	/**
	 * Remember the sub-size files of an attachment about to be deleted.
	 *
	 * @param mixed $post_id Attachment id.
	 */
	public function remember( $post_id ): void {
		$post_id = absint( $post_id );
		$meta    = wp_get_attachment_metadata( $post_id );
		$path    = (string) get_attached_file( $post_id );
		if ( $post_id <= 0 || ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) || '' === $path ) {
			return;
		}
		$files = [];
		foreach ( $meta['sizes'] as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$files[ wp_basename( (string) $size['file'] ) ] = true;
			}
		}
		$rel                       = (string) get_post_meta( $post_id, '_wp_attached_file', true );
		$this->pending[ $post_id ] = [
			'dir'    => dirname( $path ),
			'subdir' => trim( (string) dirname( $rel ), './' ),
			'files'  => array_keys( $files ),
		];
	}

	/**
	 * Delete the remembered files that nothing references any more.
	 *
	 * @param mixed $post_id Deleted post id.
	 */
	public function purge( $post_id ): void {
		$post_id = absint( $post_id );
		if ( ! isset( $this->pending[ $post_id ] ) ) {
			return;
		}
		$job = $this->pending[ $post_id ];
		unset( $this->pending[ $post_id ] );
		foreach ( $job['files'] as $file ) {
			if ( $this->in_use( $job['subdir'], $file ) ) {
				continue;
			}
			wp_delete_file_from_directory( $job['dir'] . '/' . $file, $job['dir'] );
		}
	}

	/**
	 * Whether another attachment of the same folder still lists the file (twins share files).
	 *
	 * @param string $subdir Upload sub folder (`2026/11`, may be empty).
	 * @param string $file   File name.
	 */
	private function in_use( string $subdir, string $file ): bool {
		global $wpdb;
		$like_dir = '' === $subdir ? '%' : $wpdb->esc_like( $subdir ) . '/%';
		$count    = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reference check on the meta tables, run once per deleted attachment file.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} AS pm1 INNER JOIN {$wpdb->postmeta} AS pm2 ON pm1.post_id = pm2.post_id WHERE pm1.meta_key = '_wp_attached_file' AND pm1.meta_value LIKE %s AND pm2.meta_key IN ( '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ) AND pm2.meta_value LIKE %s",
				$like_dir,
				'%"' . $wpdb->esc_like( $file ) . '"%'
			)
		);
		return $count > 0;
	}
}
