<?php
/**
 * Per-format / per-size quality of the WebP / AVIF sub-sizes (TASK-009d). JPEG / PNG quality is not touched.
 *
 * Core passes the resized dimensions as the third argument of `wp_editor_set_quality` (and calls it again per sub-size), so the size class is
 * recognised by an exact match with a registered size: hero / banner crops (`vx-hero-*`, `vx-banner*`) are smoother at a lower quality
 * (large flat areas, bytes dominate LCP); thumbs / tiles (`vx-thumb`, `vx-tile`) are tiny and need more quality to stay crisp. A crop that was
 * clipped to a smaller source has other dimensions and gets the default of its format. Filter `vextra/core/media/quality` has the last word.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Media;

final class Quality {

	public const DEFAULTS = [
		Formats::WEBP => [
			'default' => 78,
			'hero'    => 72,
			'thumb'   => 80,
		],
		Formats::AVIF => [
			'default' => 55,
			'hero'    => 50,
			'thumb'   => 58,
		],
	];

	/**
	 * `"<w>x<h>"` => size class (`hero` | `thumb`), built lazily from the registered sizes.
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $classes = null;

	public function register(): void {
		add_filter( 'wp_editor_set_quality', [ $this, 'filter' ], 20, 3 );
	}

	/**
	 * Filter callback.
	 *
	 * @param mixed $quality Quality chosen so far.
	 * @param mixed $mime    Destination mime.
	 * @param mixed $dims    Dimensions `[ 'width' => , 'height' => ]` of the image being saved.
	 * @return mixed
	 */
	public function filter( $quality, $mime = '', $dims = [] ) {
		if ( ! is_string( $mime ) || ! isset( self::DEFAULTS[ $mime ] ) ) {
			return $quality;
		}
		$class = 'default';
		if ( is_array( $dims ) && isset( $dims['width'], $dims['height'] ) ) {
			$classes = self::classes();
			$class   = $classes[ (int) $dims['width'] . 'x' . (int) $dims['height'] ] ?? 'default';
		}
		$value = self::DEFAULTS[ $mime ][ $class ];

		/**
		 * Filters the WebP / AVIF quality (1-100).
		 *
		 * @param int    $value Quality.
		 * @param string $mime  Destination mime.
		 * @param string $class `default` | `hero` | `thumb`.
		 */
		$value = (int) apply_filters( 'vextra/core/media/quality', $value, $mime, $class );
		return max( 1, min( 100, $value ) );
	}

	/**
	 * Size classes by dimensions.
	 *
	 * @return array<string,string>
	 */
	private static function classes(): array {
		if ( null !== self::$classes ) {
			return self::$classes;
		}
		$out = [];
		if ( function_exists( 'wp_get_registered_image_subsizes' ) ) {
			foreach ( wp_get_registered_image_subsizes() as $name => $size ) {
				$key = (int) $size['width'] . 'x' . (int) $size['height'];
				if ( 1 === preg_match( '/^vx-(?:hero|banner)/', (string) $name ) ) {
					$out[ $key ] = 'hero';
				} elseif ( in_array( $name, [ 'vx-thumb', 'vx-tile' ], true ) && ! isset( $out[ $key ] ) ) {
					$out[ $key ] = 'thumb';
				}
			}
		}
		self::$classes = $out;
		return $out;
	}
}
