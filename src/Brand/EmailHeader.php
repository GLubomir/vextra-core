<?php
/**
 * Woo e-mail header image width (TASK-049a): `woocommerce_email_header_image` holds the @2x logo (600 px); the e-mail shows it at 300 px.
 * CSS (`woocommerce_email_styles`, inlined by Woo) plus a `width` attribute on the header `<img>` for clients that ignore CSS. Woo templates
 * are not overridden.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Brand;

final class EmailHeader {

	public const WIDTH = 300;

	public function register(): void {
		add_filter( 'woocommerce_email_styles', [ $this, 'styles' ], 20 );
		add_filter( 'woocommerce_mail_content', [ $this, 'content' ], 20 );
	}

	/**
	 * Extra e-mail CSS.
	 *
	 * @param mixed $css Stylesheet.
	 * @return mixed
	 */
	public function styles( $css ) {
		if ( ! is_string( $css ) || '' === $this->image_url() ) {
			return $css;
		}
		return $css . sprintf( "\n#template_header_image img{width:%dpx;max-width:100%%;height:auto;}\n", self::WIDTH );
	}

	/**
	 * Add `width="300"` to the header image (only our logo, only when it has no width yet).
	 *
	 * @param mixed $html Final e-mail HTML.
	 * @return mixed
	 */
	public function content( $html ) {
		$src = $this->image_url();
		if ( ! is_string( $html ) || '' === $src ) {
			return $html;
		}
		$pattern = '#<img(?![^>]*\swidth=)([^>]*\ssrc="' . preg_quote( esc_url( $src ), '#' ) . '")#';
		$out     = preg_replace( $pattern, '<img width="' . self::WIDTH . '"$1', $html, 1 );
		return is_string( $out ) ? $out : $html;
	}

	/**
	 * The configured header image when it is the brand one (recorded attachment URL), else ''.
	 */
	private function image_url(): string {
		$url    = (string) get_option( 'woocommerce_email_header_image', '' );
		$record = get_option( Media::OPTION, [] );
		$id     = is_array( $record ) ? absint( $record['email-logo'] ?? 0 ) : 0;
		if ( '' === $url || $id <= 0 || (string) wp_get_attachment_url( $id ) !== $url ) {
			return '';
		}
		return $url;
	}
}
