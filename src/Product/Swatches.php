<?php
/**
 * Swatch data reader of the product page (TASK-044a): per attribute term a colour (`_vx_swatch_hex`, `#rrggbb`) or an image
 * (`_vx_swatch_image`, attachment id), per attribute a renderer `color|image|chips|select`.
 *
 * Renderer = the option `_vx_attr_renderer_<taxonomy>` when set (admin field, `Admin\SwatchFields`), else the auto rule of
 * `docs/design/components/variation-swatches.md` s2: colour label (Колір / Цвет / Color) and a hex on EVERY option -> `color`; an image on
 * every option -> `image`; at most 8 options and every label at most 24 characters -> `chips`; else `select` (the always-correct fallback).
 * A RU term without its own value reads the one of its default-language twin (Polylang).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Product;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;

final class Swatches {

	public const META_HEX        = '_vx_swatch_hex';
	public const META_IMAGE      = '_vx_swatch_image';
	public const OPTION_PREFIX   = '_vx_attr_renderer_';
	public const RENDERERS       = [ 'color', 'image', 'chips', 'select' ];
	public const COLOR_LABELS    = [ 'колір', 'цвет', 'color', 'colour' ];
	private const CHIPS_MAX      = 8;
	private const CHIP_LABEL_MAX = 24;

	/**
	 * Valid `#rrggbb` (lower case) or ''.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_hex( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : '';
	}

	/**
	 * Colour of a term: its own, else the default-language twin's.
	 *
	 * @param int $term_id Term id.
	 */
	public static function hex( int $term_id ): string {
		$own = self::sanitize_hex( get_term_meta( $term_id, self::META_HEX, true ) );
		if ( '' !== $own ) {
			return $own;
		}
		$twin = self::twin( $term_id );
		return $twin > 0 ? self::sanitize_hex( get_term_meta( $twin, self::META_HEX, true ) ) : '';
	}

	/**
	 * Swatch image (attachment id) of a term: its own, else the twin's.
	 *
	 * @param int $term_id Term id.
	 */
	public static function image( int $term_id ): int {
		$own = absint( get_term_meta( $term_id, self::META_IMAGE, true ) );
		if ( $own > 0 ) {
			return $own;
		}
		$twin = self::twin( $term_id );
		return $twin > 0 ? absint( get_term_meta( $twin, self::META_IMAGE, true ) ) : 0;
	}

	/**
	 * Default-language twin of a term (0 when none or it is the default-language term itself).
	 *
	 * @param int $term_id Term id.
	 */
	private static function twin( int $term_id ): int {
		$pll = Plugin::instance()->integration( 'polylang' );
		if ( ! $pll instanceof Polylang || ! $pll->is_ready() ) {
			return 0;
		}
		$twin = $pll->translate_term( $term_id, $pll->default_language() );
		return $twin !== $term_id ? $twin : 0;
	}

	/**
	 * Renderer chosen in the admin for an attribute taxonomy ('' = auto).
	 *
	 * @param string $taxonomy Attribute taxonomy (`pa_*`).
	 */
	public static function override( string $taxonomy ): string {
		if ( '' === $taxonomy || ! str_starts_with( $taxonomy, 'pa_' ) ) {
			return '';
		}
		$value = get_option( self::OPTION_PREFIX . $taxonomy, '' );
		return is_string( $value ) && in_array( $value, self::RENDERERS, true ) ? $value : '';
	}

	/**
	 * Renderer of an attribute.
	 *
	 * @param string                                                $taxonomy Taxonomy slug or '' for a custom (local) attribute.
	 * @param string                                                $label    Attribute label.
	 * @param array<int,array{label:string,hex?:string,image?:int}> $options  Options.
	 */
	public static function renderer( string $taxonomy, string $label, array $options ): string {
		$chosen = self::override( $taxonomy );
		if ( '' !== $chosen && self::fits( $chosen, $options ) ) {
			return $chosen;
		}
		if ( array() === $options ) {
			return 'select';
		}
		$is_color = in_array( mb_strtolower( trim( $label ) ), self::COLOR_LABELS, true );
		if ( $is_color && self::fits( 'color', $options ) ) {
			return 'color';
		}
		if ( self::fits( 'image', $options ) ) {
			return 'image';
		}
		return self::fits( 'chips', $options ) ? 'chips' : 'select';
	}

	/**
	 * Can the renderer show these options (data present / size limits)? `select` always can.
	 *
	 * @param string                                                $renderer Renderer.
	 * @param array<int,array{label:string,hex?:string,image?:int}> $options  Options.
	 */
	private static function fits( string $renderer, array $options ): bool {
		if ( 'select' === $renderer ) {
			return true;
		}
		if ( array() === $options ) {
			return false;
		}
		foreach ( $options as $option ) {
			if ( 'color' === $renderer && '' === (string) ( $option['hex'] ?? '' ) ) {
				return false;
			}
			if ( 'image' === $renderer && (int) ( $option['image'] ?? 0 ) <= 0 ) {
				return false;
			}
			if ( 'chips' === $renderer && mb_strlen( (string) $option['label'] ) > self::CHIP_LABEL_MAX ) {
				return false;
			}
		}
		return 'chips' !== $renderer || count( $options ) <= self::CHIPS_MAX;
	}
}
