<?php
/**
 * Translation of WooCommerce global attributes (TASK-043b fix round 4): the `pa_*` taxonomies become Polylang-translatable (terms get
 * language twins, products point at the twin of their language) and the attribute LABELS (a Woo setting, not a term) are Polylang strings
 * in the group "Vextra attributes".
 *
 * Owner of attribute translation = this class (config as code: no manual "Custom post types and Taxonomies" checkbox). Only public
 * Polylang API: filter `pll_get_taxonomies`, `pll_register_string()`, `pll__()` (through the adapter).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Multilang;

use Vextra\Core\Integrations\Polylang\Polylang;

final class AttributeTranslation {

	public const GROUP = 'Vextra attributes';

	/**
	 * Adapter of the module instance (static helpers need it outside the hook callbacks).
	 *
	 * @var Polylang|null
	 */
	private static ?Polylang $pll = null;

	/**
	 * Constructor.
	 *
	 * @param Polylang $polylang Polylang adapter.
	 */
	public function __construct( Polylang $polylang ) {
		self::$pll = $polylang;
	}

	public function register(): void {
		add_filter( 'pll_get_taxonomies', [ $this, 'taxonomies' ], 10, 2 );
		add_action( 'init', [ $this, 'register_strings' ], 20 );
		add_filter( 'woocommerce_attribute_label', [ $this, 'filter_label' ], 10, 1 );
	}

	/**
	 * Every global attribute taxonomy is translatable (forced on, hidden from the Polylang settings list).
	 *
	 * @param mixed $taxonomies Taxonomy slugs (key = value).
	 * @param mixed $is_settings True when the list is built for the settings screen.
	 * @return mixed
	 */
	public function taxonomies( $taxonomies, $is_settings = false ) {
		if ( ! is_array( $taxonomies ) || $is_settings || ! function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
			return $taxonomies;
		}
		foreach ( (array) wc_get_attribute_taxonomy_names() as $name ) {
			$taxonomies[ $name ] = $name;
		}
		return $taxonomies;
	}

	/**
	 * One Polylang string per attribute label (source = the label saved in Woo, Ukrainian).
	 */
	public function register_strings(): void {
		if ( null === self::$pll || ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}
		foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
			$label = (string) $attribute->attribute_label;
			if ( '' !== $label ) {
				self::$pll->register_string( 'attribute: ' . $attribute->attribute_name, $label, self::GROUP );
			}
		}
	}

	/**
	 * Front-end label of an attribute (Woo `wc_attribute_label()`); the admin keeps the source label.
	 *
	 * @param mixed $label Label.
	 * @return mixed
	 */
	public function filter_label( $label ) {
		return is_string( $label ) && ! is_admin() ? self::label( $label ) : $label;
	}

	/**
	 * Label in the current language. Called at render time: the cached facet bundle keeps the source label (a background rebuild has
	 * no request language).
	 *
	 * @param string $label Source label.
	 */
	public static function label( string $label ): string {
		return null === self::$pll || '' === $label ? $label : self::$pll->translate_string( $label );
	}
}
