<?php
/**
 * Server-rendered `product-card` markup of the theme for REST answers (Recommend route, Cards route). One implementation of the
 * locale switch + card render (TASK-044a extracted it from `Recommend\Rest`): the REST request has no Polylang language context of its
 * own, so the list is rendered in the language it belongs to. Without the theme (`vx_component()` missing) the html is ''.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Support;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;

final class CardMarkup {

	/**
	 * Add `html` to every item (an item whose card cannot be shown, e.g. trashed since the list was built, is dropped).
	 *
	 * @param array<int,array<string,mixed>> $items Items with at least `id`.
	 * @param string                         $lang  Language slug (the markup is rendered in it).
	 * @param array<string,string>           $card  Optional card args: `variant` (grid|compact|list), `rec_src` (attribution field of the add-to-cart form).
	 * @return array<int,array<string,mixed>>
	 */
	public static function attach( array $items, string $lang, array $card = [] ): array {
		$out = [];
		foreach ( $items as $item ) {
			$out[] = $item + [ 'html' => '' ];
		}
		if ( array() === $out || ! function_exists( 'vx_component' ) ) {
			return $out;
		}

		$switched = self::switch_locale( $lang );
		if ( class_exists( '\Vextra\Theme\Woo\ProductCardData' ) ) {
			\Vextra\Theme\Woo\ProductCardData::prime_catalog( array_column( $out, 'id' ) );
		}
		$variant = isset( $card['variant'] ) && in_array( $card['variant'], [ 'grid', 'compact', 'list' ], true ) ? (string) $card['variant'] : 'grid';
		foreach ( $out as $i => $item ) {
			ob_start();
			vx_component(
				'product-card',
				[
					'product'    => $item['id'],
					'variant'    => $variant,
					'rec_src'    => isset( $card['rec_src'] ) && is_string( $card['rec_src'] ) ? $card['rec_src'] : '',
					'heading'    => 'h3',
					'image_size' => 'vx-product-card',
					'lazy'       => true,
					'cache'      => 'grid' === $variant, // The shared card-data cache holds the grid card only; other variants are built per request (the route answer is cached as a whole).
				]
			);
			$out[ $i ]['html'] = trim( (string) ob_get_clean() );
			if ( '' === $out[ $i ]['html'] ) {
				unset( $out[ $i ] );
			}
		}
		if ( $switched ) {
			restore_previous_locale();
		}
		return array_values( $out );
	}

	/**
	 * Render in the language of the list when the request locale differs.
	 *
	 * @param string $lang Language slug.
	 */
	private static function switch_locale( string $lang ): bool {
		$pll = Plugin::instance()->integration( 'polylang' );
		if ( '' === $lang || ! $pll instanceof Polylang ) {
			return false;
		}
		$locale = $pll->locale_of( $lang );
		return '' !== $locale && determine_locale() !== $locale && switch_to_locale( $locale );
	}
}
