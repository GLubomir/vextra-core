<?php
/**
 * Output-format mapping and server capability detection of the Media module (TASK-009d, approach A).
 *
 * Core filter `image_editor_output_format` maps the generated SUB-SIZES of a JPEG / PNG upload to WebP (or AVIF, constant / filter opt-in,
 * ONE target format for the whole site so a `srcset` never mixes formats). Never mapped: the uploaded original (`full`), the `-scaled` copy,
 * rotated / edited copies (`-e<ts>`), GIF (animation), SVG, files that are already WebP / AVIF. The original stays the universal fallback and
 * the image-sitemap / OG / schema image.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Media;

final class Formats {

	public const WEBP = 'image/webp';
	public const AVIF = 'image/avif';

	/**
	 * Source mimes that are converted.
	 */
	public const SOURCES = [ 'image/jpeg', 'image/png' ];

	/**
	 * Sub-size file name: `name-480x480.jpg` (core suffix `<w>x<h>`).
	 */
	private const SUBSIZE_NAME = '/-\d+x\d+\.(?:jpe?g|png)$/i';

	/**
	 * Memoised capability probe.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $caps = null;

	/**
	 * Target mime for the whole site: `image/webp` (default) or `image/avif` (constant `VX_MEDIA_FORMAT` / filter `vextra/core/media/format`).
	 * An unsupported or unknown choice falls back to WebP.
	 */
	public static function target(): string {
		$wanted = defined( 'VX_MEDIA_FORMAT' ) ? (string) VX_MEDIA_FORMAT : 'webp';

		/**
		 * Filters the target format: `webp` or `avif`.
		 *
		 * @param string $wanted Format keyword.
		 */
		$wanted = (string) apply_filters( 'vextra/core/media/format', $wanted );
		$mime   = 'avif' === strtolower( $wanted ) ? self::AVIF : self::WEBP;
		$caps   = self::capabilities();
		if ( self::AVIF === $mime && ! $caps['avif'] ) {
			$mime = self::WEBP;
		}
		return $mime;
	}

	/**
	 * Cheap runtime probe (no image editor instantiation): GD functions / Imagick format list of THIS PHP runtime.
	 *
	 * @return array<string,mixed> Keys: gd, imagick, webp, avif, gd_webp, gd_avif, imagick_webp, imagick_avif, sapi, php.
	 */
	public static function capabilities(): array {
		if ( null !== self::$caps ) {
			return self::$caps;
		}
		$gd_webp = false;
		$gd_avif = false;
		if ( extension_loaded( 'gd' ) && function_exists( 'gd_info' ) ) {
			$info    = gd_info();
			$gd_webp = function_exists( 'imagewebp' ) && ! empty( $info['WebP Support'] );
			$gd_avif = function_exists( 'imageavif' ) && ! empty( $info['AVIF Support'] );
		}
		$im_webp = false;
		$im_avif = false;
		if ( extension_loaded( 'imagick' ) && class_exists( \Imagick::class ) ) {
			try {
				$formats = array_map( 'strtoupper', \Imagick::queryFormats() );
				$im_webp = in_array( 'WEBP', $formats, true );
				$im_avif = in_array( 'AVIF', $formats, true );
			} catch ( \Throwable $e ) {
				unset( $e ); // A broken Imagick install counts as "no support".
			}
		}
		self::$caps = [
			'gd'           => extension_loaded( 'gd' ),
			'imagick'      => extension_loaded( 'imagick' ),
			'webp'         => $gd_webp || $im_webp,
			'avif'         => $gd_avif || $im_avif,
			'gd_webp'      => $gd_webp,
			'gd_avif'      => $gd_avif,
			'imagick_webp' => $im_webp,
			'imagick_avif' => $im_avif,
			'sapi'         => PHP_SAPI,
			'php'          => PHP_VERSION,
		];
		return self::$caps;
	}

	/**
	 * Whether WordPress itself will pick an editor that can write the target mime (the editor core really uses).
	 *
	 * @param string $mime Target mime.
	 */
	public static function editor_supports( string $mime ): bool {
		return function_exists( 'wp_image_editor_supports' ) && (bool) wp_image_editor_supports( [ 'mime_type' => $mime ] );
	}

	/**
	 * Module precondition: the runtime can encode WebP or AVIF.
	 */
	public static function available(): bool {
		$caps = self::capabilities();
		return $caps['webp'] || $caps['avif'];
	}

	/**
	 * Filter callback of `image_editor_output_format`.
	 *
	 * @param mixed $formats   Mapping source mime => destination mime.
	 * @param mixed $filename  Path of the file being written (null while core derives a sub-size name).
	 * @param mixed $mime_type Source mime.
	 * @return mixed
	 */
	public static function map( $formats, $filename = null, $mime_type = '' ) {
		if ( ! is_array( $formats ) || ! is_string( $mime_type ) || ! in_array( $mime_type, self::SOURCES, true ) ) {
			return $formats;
		}
		// An explicit file name that is not a `-WxH` sub-size is the original, `-scaled`, a rotated / edited copy or a crop: keep its format.
		if ( is_string( $filename ) && '' !== $filename && 1 !== preg_match( self::SUBSIZE_NAME, $filename ) ) {
			return $formats;
		}
		$target = self::target();
		if ( ! self::editor_supports( $target ) ) {
			return $formats;
		}
		$formats[ $mime_type ] = $target;
		return $formats;
	}

	/**
	 * Whether an image mime is a conversion source.
	 *
	 * @param string $mime Mime type.
	 */
	public static function is_source( string $mime ): bool {
		return in_array( $mime, self::SOURCES, true );
	}

	/**
	 * Reset the memoised probe (tests).
	 */
	public static function reset(): void {
		self::$caps = null;
	}
}
