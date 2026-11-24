<?php
/**
 * Content URL rewriter of the Media module (TASK-009d, SEO review MAJOR).
 *
 * After the sub-sizes of an attachment changed from `name-480x480.jpg` to `name-480x480.webp`, hard-coded URLs of the old files (the core image
 * block and the editor store the SIZED url in `post_content`, block attributes and option values) would 404. This service finds them and points
 * them at the new file. Stateless and idempotent: a URL `<dir>/<stem>-<w>x<h>.(jpg|jpeg|png)` is rewritten ONLY when the target file exists and
 * the old file no longer does; a second run changes nothing. A real file that merely looks like a size (an original called `x-300x200.jpg`) is
 * never touched because it still exists.
 *
 * Scanned: `post_content`, `post_excerpt`, term descriptions, post / term meta (except the attachment meta) and options (except transients).
 * Serialized values are unserialized (no classes), walked and serialized again (string lengths change). JSON-escaped slashes in block
 * attributes are handled. Polylang twins need nothing special: their rows are ordinary posts / terms of the same tables.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Media;

final class Rewriter {

	/**
	 * Rows per query.
	 */
	private const CHUNK = 200;

	/**
	 * Sized upload URL: `uploads/<dir>/<stem>-<w>x<h>.<ext>` (slashes may be JSON-escaped `\/`).
	 */
	private const URL = '#uploads((?:\\\\?/)(?:[\w.%\-]+(?:\\\\?/))*)([\w.%\-]*?-\d+x\d+)\.(jpe?g|png)(?![\w])#i';

	/**
	 * Meta keys that hold file lists of the attachments themselves.
	 */
	private const SKIP_META = [ '_wp_attachment_metadata', '_wp_attached_file', '_wp_attachment_backup_sizes' ];

	/**
	 * Uploads base directory.
	 *
	 * @var string
	 */
	private string $basedir = '';

	/**
	 * Existence cache `relative path => bool`.
	 *
	 * @var array<string,bool>
	 */
	private array $exists = [];

	/**
	 * Run the scan; writes only when `$dry` is false.
	 *
	 * @param bool $dry Report only.
	 * @return array{total:int,rows:int,by_source:array<string,int>,samples:array<int,array<string,string>>}
	 */
	public function run( bool $dry ): array {
		global $wpdb;
		$uploads       = wp_get_upload_dir();
		$this->basedir = rtrim( (string) $uploads['basedir'], '/\\' );
		$this->exists  = [];
		$report        = [
			'total'     => 0,
			'rows'      => 0,
			'by_source' => [],
			'samples'   => [],
		];

		$sources = [
			[ 'posts.post_content', $wpdb->posts, 'ID', 'post_content', '', 'post' ],
			[ 'posts.post_excerpt', $wpdb->posts, 'ID', 'post_excerpt', '', 'post' ],
			[ 'term_taxonomy.description', $wpdb->term_taxonomy, 'term_taxonomy_id', 'description', '', 'term' ],
			[ 'postmeta', $wpdb->postmeta, 'meta_id', 'meta_value', "AND meta_key NOT IN ('" . implode( "','", self::SKIP_META ) . "')", 'postmeta' ],
			[ 'termmeta', $wpdb->termmeta, 'meta_id', 'meta_value', '', 'termmeta' ],
			[ 'options', $wpdb->options, 'option_id', 'option_value', "AND option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'", 'option' ],
		];

		foreach ( $sources as [ $label, $table, $pk, $col, $extra, $kind ] ) {
			$last = 0;
			do {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- identifiers / constants from the list above; values prepared.
				$rows = (array) $wpdb->get_results(
					$wpdb->prepare( "SELECT {$pk} AS pk, {$col} AS val FROM {$table} WHERE {$pk} > %d AND {$col} LIKE %s AND {$col} REGEXP %s", $last, '%uploads%', '-[0-9]+x[0-9]+[.](jpe?g|png)' ) . " {$extra} ORDER BY {$pk} ASC LIMIT " . self::CHUNK,
					ARRAY_A
				);
				// phpcs:enable
				foreach ( $rows as $row ) {
					$last    = (int) $row['pk'];
					$changes = [];
					$new     = $this->rewrite_value( (string) $row['val'], $changes );
					if ( array() === $changes ) {
						continue;
					}
					++$report['rows'];
					$report['total']              += count( $changes );
					$report['by_source'][ $label ] = ( $report['by_source'][ $label ] ?? 0 ) + count( $changes );
					foreach ( $changes as $from => $to ) {
						if ( count( $report['samples'] ) < 12 ) {
							$report['samples'][] = [
								'source' => $label . '#' . $last,
								'from'   => $from,
								'to'     => $to,
							];
						}
					}
					if ( ! $dry ) {
						$this->save( $kind, $table, $pk, $col, $last, $new );
					}
				}
				$full = count( $rows ) === self::CHUNK;
			} while ( $full );
		}
		return $report;
	}

	/**
	 * Rewrite one stored value (plain string or serialized structure).
	 *
	 * @param string               $value   Raw DB value.
	 * @param array<string,string> $changes Collected `old URL part => new URL part` (by reference).
	 */
	public function rewrite_value( string $value, array &$changes ): string {
		if ( is_serialized( $value ) ) {
			$data = @unserialize( trim( $value ), [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- DB value, no classes allowed; false = leave the row alone.
			if ( false === $data && 'b:0;' !== trim( $value ) ) {
				return $value;
			}
			$before = count( $changes );
			$data   = $this->walk( $data, $changes );
			return count( $changes ) === $before ? $value : serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- same format back into the same column.
		}
		return $this->rewrite_string( $value, $changes );
	}

	/**
	 * Rewrite the sized upload URLs of a string.
	 *
	 * @param string               $text    Text.
	 * @param array<string,string> $changes Collected changes (by reference).
	 */
	public function rewrite_string( string $text, array &$changes ): string {
		if ( ! str_contains( $text, 'uploads' ) ) {
			return $text;
		}
		return (string) preg_replace_callback(
			self::URL,
			function ( array $m ) use ( &$changes ): string {
				$dir = str_replace( '\\/', '/', $m[1] );
				$rel = rawurldecode( ltrim( $dir, '/' ) . $m[2] );
				if ( $this->exists( $rel . '.' . $m[3] ) ) {
					return $m[0];
				}
				foreach ( [ 'webp', 'avif' ] as $ext ) {
					if ( $this->exists( $rel . '.' . $ext ) ) {
						$new              = 'uploads' . $m[1] . $m[2] . '.' . $ext;
						$changes[ $m[0] ] = $new;
						return $new;
					}
				}
				return $m[0];
			},
			$text
		);
	}

	/**
	 * Recursive walk over arrays / objects of a serialized value.
	 *
	 * @param mixed                $data    Value.
	 * @param array<string,string> $changes Collected changes (by reference).
	 * @return mixed
	 */
	private function walk( $data, array &$changes ) {
		if ( is_string( $data ) ) {
			return $this->rewrite_string( $data, $changes );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = $this->walk( $v, $changes );
			}
		}
		return $data;
	}

	/**
	 * Whether a file exists in uploads (memoised).
	 *
	 * @param string $rel Path relative to the uploads base.
	 */
	private function exists( string $rel ): bool {
		if ( ! isset( $this->exists[ $rel ] ) ) {
			$this->exists[ $rel ] = is_file( $this->basedir . '/' . $rel );
		}
		return $this->exists[ $rel ];
	}

	/**
	 * Store a rewritten value and clear the matching caches.
	 *
	 * @param string $kind  post | term | postmeta | termmeta | option.
	 * @param string $table Table.
	 * @param string $pk    Primary key column.
	 * @param string $col   Value column.
	 * @param int    $id    Primary key value.
	 * @param string $value New value.
	 */
	private function save( string $kind, string $table, string $pk, string $col, int $id, string $value ): void {
		global $wpdb;
		$wpdb->update( $table, [ $col => $value ], [ $pk => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- values from our own rewrite, caches cleared below.
		switch ( $kind ) {
			case 'post':
				clean_post_cache( $id );
				break;
			case 'term':
				$term = $wpdb->get_row( $wpdb->prepare( "SELECT term_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( $term ) {
					clean_term_cache( (int) $term->term_id, (string) $term->taxonomy );
				}
				break;
			case 'postmeta':
				$post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				wp_cache_delete( $post_id, 'post_meta' );
				break;
			case 'termmeta':
				$term_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				wp_cache_delete( $term_id, 'term_meta' );
				break;
			case 'option':
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				$name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				wp_cache_delete( $name, 'options' );
				break;
		}
	}
}
