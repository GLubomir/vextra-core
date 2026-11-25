<?php
/**
 * Brand media import with an idempotency record (TASK-049a).
 *
 * Three theme files become attachments, once: record = option `vx_brand_media` (`key => attachment id`, autoload off) AND meta
 * `_vx_brand_key` + `_vx_brand_hash` (md5 of the source file) on the attachment. Same hash = reuse; changed hash = import the new file and
 * delete the old attachment we created; lost option = found again by `_vx_brand_key`. No Polylang language is assigned (neutral media).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Brand;

final class Media {

	public const OPTION    = 'vx_brand_media';
	public const META_KEY  = '_vx_brand_key';
	public const META_HASH = '_vx_brand_hash';

	/**
	 * Key => file in the brand directory (only these three are imported; SVG / ICO / small PNG stay theme files).
	 */
	public const FILES = [
		'site-icon'  => 'site-icon-512.png',
		'email-logo' => 'email-logo@2x.png',
		'og-default' => 'og-default-1200x630.png',
	];

	/**
	 * `intermediate_image_sizes_advanced`: no sub-sizes for brand attachments (also while importing, before the meta exists).
	 *
	 * @param mixed $sizes         Sizes.
	 * @param mixed $meta          Metadata (unused).
	 * @param mixed $attachment_id Attachment id.
	 * @return mixed
	 */
	public static function no_subsizes( $sizes, $meta = [], $attachment_id = 0 ) {
		unset( $meta );
		if ( ! empty( $GLOBALS['vx_brand_importing'] ) || ( absint( $attachment_id ) > 0 && '' !== (string) get_post_meta( absint( $attachment_id ), self::META_KEY, true ) ) ) {
			return [];
		}
		return $sizes;
	}

	/**
	 * Recorded attachment ids (only those that still exist as attachments).
	 *
	 * @return array<string,int>
	 */
	public function record(): array {
		$stored = get_option( self::OPTION, [] );
		$out    = [];
		foreach ( array_keys( self::FILES ) as $key ) {
			$id = is_array( $stored ) ? absint( $stored[ $key ] ?? 0 ) : 0;
			if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
				$id = $this->find_by_meta( $key );
			}
			if ( $id > 0 ) {
				$out[ $key ] = $id;
			}
		}
		return $out;
	}

	/**
	 * Attachment carrying `_vx_brand_key` = key (option lost).
	 *
	 * @param string $key Brand key.
	 */
	private function find_by_meta( string $key ): int {
		$ids = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'meta_key'         => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery -- one-off CLI lookup.
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			]
		);
		return $ids ? absint( $ids[0] ) : 0;
	}

	/**
	 * Plan for a directory: per key file, hash, existing id and state (`import` | `reuse` | `replace`).
	 *
	 * @param string $dir Source directory.
	 * @return array<string,array{file:string,path:string,hash:string,id:int,state:string}>
	 */
	public function plan( string $dir ): array {
		$record = $this->record();
		$plan   = [];
		foreach ( self::FILES as $key => $file ) {
			$path = rtrim( $dir, '/\\' ) . '/' . $file;
			$hash = is_readable( $path ) ? (string) md5_file( $path ) : '';
			$id   = $record[ $key ] ?? 0;
			if ( $id <= 0 ) {
				$state = 'import';
			} else {
				$state = (string) get_post_meta( $id, self::META_HASH, true ) === $hash ? 'reuse' : 'replace';
			}
			$plan[ $key ] = [
				'file'  => $file,
				'path'  => $path,
				'hash'  => $hash,
				'id'    => $id,
				'state' => $state,
			];
		}
		return $plan;
	}

	/**
	 * Missing source files (empty = ok).
	 *
	 * @param string $dir Source directory.
	 * @return string[]
	 */
	public function missing( string $dir ): array {
		$missing = [];
		foreach ( self::FILES as $file ) {
			if ( ! is_readable( rtrim( $dir, '/\\' ) . '/' . $file ) ) {
				$missing[] = $file;
			}
		}
		return $missing;
	}

	/**
	 * Import one file as an attachment from a temp copy (the theme file is never moved).
	 *
	 * @param string $key  Brand key.
	 * @param string $path Source file.
	 * @param string $hash md5 of the source file.
	 * @return int|\WP_Error New attachment id.
	 */
	public function import( string $key, string $path, string $hash ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( basename( $path ) );
		if ( ! $tmp || ! copy( $path, $tmp ) ) {
			return new \WP_Error( 'vx_brand_tmp', 'Could not create a temp copy of ' . basename( $path ) );
		}

		$name = (string) get_bloginfo( 'name' );

		$GLOBALS['vx_brand_importing'] = true;
		add_filter( 'intermediate_image_sizes_advanced', [ self::class, 'no_subsizes' ], 99, 3 );
		$id = media_handle_sideload(
			[
				'name'     => basename( $path ),
				'tmp_name' => $tmp,
			],
			0,
			null,
			[
				'post_title'   => $name . ' brand: ' . $key,
				'post_content' => '',
				'post_excerpt' => '',
			]
		);
		remove_filter( 'intermediate_image_sizes_advanced', [ self::class, 'no_subsizes' ], 99 );
		unset( $GLOBALS['vx_brand_importing'] );

		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $id;
		}

		update_post_meta( $id, self::META_KEY, $key );
		update_post_meta( $id, self::META_HASH, $hash );
		update_post_meta( $id, '_wp_attachment_image_alt', $name );
		return (int) $id;
	}

	/**
	 * Store the record (autoload off).
	 *
	 * @param array<string,int> $record Key => id.
	 */
	public function save( array $record ): void {
		update_option( self::OPTION, $record, false );
	}
}
