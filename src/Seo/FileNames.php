<?php
/**
 * Latin file names for NEW uploads and sideloads (ADR-011 s2).
 *
 * Filter `sanitize_file_name` (priority 20, after WordPress): only a name whose base contains Cyrillic is changed; a pure ASCII name
 * passes through byte-identical. The extension is kept as WordPress gave it, the base goes through the slug transliteration table of
 * the file's language (never inferred from the front-end request):
 * 1. filter `vextra/seo/slug_language` ( string $lang, 'file', array $context ) - explicit channel for importers/CLI;
 * 2. letters that exist in one alphabet only (`ы э ё ъ` = ru, `і ї є ґ` = uk);
 * 3. the Polylang default language, `uk` without Polylang.
 * An empty result keeps WordPress's own name. Existing attachments are never renamed (`_wp_attached_file`, thumbnails untouched);
 * `wp_unique_filename()` still adds the `-1` suffix on a clash. Importers must match supplier images by source URL meta, never by
 * file name.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Integrations\Polylang\Polylang;

final class FileNames {

	private const MAX_BASE = 100;

	/**
	 * Transliterator.
	 *
	 * @var Transliterator
	 */
	private Transliterator $translit;

	/**
	 * Polylang adapter (null when the integration is missing).
	 *
	 * @var Polylang|null
	 */
	private ?Polylang $polylang;

	public function __construct( Transliterator $translit, ?Polylang $polylang = null ) {
		$this->translit = $translit;
		$this->polylang = $polylang;
	}

	public function register(): void {
		add_filter( 'sanitize_file_name', [ $this, 'filter' ], 20, 2 );
	}

	/**
	 * `sanitize_file_name` callback.
	 *
	 * @param mixed $filename     File name sanitized by WordPress.
	 * @param mixed $filename_raw Name before sanitizing.
	 * @return mixed
	 */
	public function filter( $filename, $filename_raw = '' ) {
		if ( ! is_string( $filename ) || '' === $filename || ! $this->translit->has_cyrillic( $filename ) ) {
			return $filename;
		}
		$dot  = strrpos( $filename, '.' );
		$ext  = false === $dot || 0 === $dot ? '' : substr( $filename, $dot ); // With the dot, as WordPress gave it.
		$base = '' === $ext ? $filename : substr( $filename, 0, (int) $dot );
		if ( $this->translit->has_cyrillic( $ext ) ) { // A Cyrillic "extension" is part of the name.
			$base = $filename;
			$ext  = '';
		}

		$result = $this->translit->transliterate( $base, $this->language( $base, is_string( $filename_raw ) ? $filename_raw : '' ), self::MAX_BASE );
		return '' === $result ? $filename : $result . strtolower( $ext );
	}

	/**
	 * Language slug that selects the transliteration table.
	 *
	 * @param string $base Base name.
	 * @param string $raw  Raw file name.
	 */
	private function language( string $base, string $raw ): string {
		/**
		 * Explicit language of the file being stored (highest priority). Return a language slug (`uk`, `ru`).
		 *
		 * @param string              $lang    Language slug or ''.
		 * @param string              $kind    Always `file`.
		 * @param array<string,mixed> $context `name` (base) and `raw` (raw file name).
		 */
		$filtered = apply_filters(
			'vextra/seo/slug_language',
			'',
			'file',
			[
				'name' => $base,
				'raw'  => $raw,
			]
		);
		$filtered = is_string( $filtered ) ? sanitize_key( $filtered ) : '';
		if ( '' !== $filtered ) {
			return $filtered;
		}
		$guess = $this->translit->guess_language( $base );
		if ( '' !== $guess ) {
			return $guess;
		}
		$default = null !== $this->polylang ? $this->polylang->default_language() : '';
		return '' !== $default ? $default : 'uk';
	}
}
