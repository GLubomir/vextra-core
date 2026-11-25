<?php
/**
 * Variation attribute values of a cart line (TASK-045a fix round 4).
 *
 * Woo formats a cart line's variation (`attribute_pa_color = synii`) with `get_term_by( 'slug', ... )`. Polylang restricts that lookup to the
 * language of the request, so a UA product in a RU session printed the raw slug ("Цвет: synii / Память: 256-gb"), in the server-rendered cart
 * (preloaded Store API data), the classic cart / checkout item data and the order item display. A term slug is unique inside its taxonomy,
 * so a slug lookup of a `pa_*` attribute never needs the language filter: the value is shown with the term name of the term the slug belongs
 * to, i.e. in the language of the ITEM (a RU twin variation stores RU slugs, `sinii` -> "Синий"; a UA one stores UA slugs, `synii` -> "Синій").
 * Only `get_terms_args` of an attribute-slug query is touched (WP core filter); nothing else changes.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Cart;

final class VariationNames {

	public function register(): void {
		add_filter( 'get_terms_args', [ $this, 'all_languages' ], 20, 2 );
	}

	/**
	 * Slug lookup of an attribute term: every language.
	 *
	 * @param mixed $args       Query args.
	 * @param mixed $taxonomies Taxonomies.
	 * @return mixed
	 */
	public function all_languages( $args, $taxonomies = [] ) {
		if ( ! is_array( $args ) || is_admin() || empty( $args['slug'] ) || array_key_exists( 'lang', $args ) || ! is_array( $taxonomies ) || 1 !== count( $taxonomies ) ) {
			return $args;
		}
		$taxonomy = (string) reset( $taxonomies );
		if ( 0 !== strpos( $taxonomy, 'pa_' ) ) {
			return $args;
		}
		$args['lang'] = '';
		return $args;
	}
}
