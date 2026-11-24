<?php
/**
 * Filterable attributes per category (TASK-043b): term meta `_vx_filter_attrs` on `product_cat` = list of attribute taxonomy names
 * (`pa_color`, ...), at most 5, inherited from the nearest ancestor that has a selection. The selection is edited on the category
 * screen of the DEFAULT language (`Admin\CategoryCatalogFields`) and resolved for other languages through the Polylang term pair.
 * Nothing configured: `for_term()` returns [] and the facet engine falls back to the 3 attributes with the most products in the scope.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

final class FilterAttributes {

	public const META = '_vx_filter_attrs';

	public const MAX = 5;

	/**
	 * Attribute taxonomy names chosen for a category (own or inherited), validated against the registered taxonomies.
	 *
	 * @param int $term_id `product_cat` term id (any language).
	 * @return string[]
	 */
	public static function for_term( int $term_id ): array {
		$source = TermPair::source( $term_id );
		$chain  = array_merge( [ $source ], array_map( 'intval', (array) get_ancestors( $source, 'product_cat', 'taxonomy' ) ) );
		foreach ( $chain as $id ) {
			$list = self::clean( get_term_meta( (int) $id, self::META, true ) );
			if ( array() !== $list ) {
				return $list;
			}
		}
		return [];
	}

	/**
	 * Own (non-inherited) selection of a source term, for the admin field.
	 *
	 * @param int $term_id Source term id.
	 * @return string[]
	 */
	public static function own( int $term_id ): array {
		return self::clean( get_term_meta( $term_id, self::META, true ) );
	}

	/**
	 * Validate a raw list: strings that are registered `pa_*` taxonomies, unique, at most MAX.
	 *
	 * @param mixed $raw Raw value.
	 * @return string[]
	 */
	public static function clean( $raw ): array {
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $name ) {
			$name = is_string( $name ) ? sanitize_key( $name ) : '';
			if ( str_starts_with( $name, 'pa_' ) && taxonomy_exists( $name ) && ! in_array( $name, $out, true ) ) {
				$out[] = $name;
			}
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Every registered attribute taxonomy: name => label.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		$out = [];
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
				$name = 'pa_' . $attribute->attribute_name;
				if ( taxonomy_exists( $name ) ) {
					$out[ $name ] = (string) $attribute->attribute_label;
				}
			}
		}
		return $out;
	}
}
