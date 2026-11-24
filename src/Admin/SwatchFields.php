<?php
/**
 * Admin wiring of the swatch data (TASK-044a): a colour (`#rrggbb`) and an image (attachment id) field on every `pa_*` attribute term
 * add / edit screen (term meta `_vx_swatch_hex` / `_vx_swatch_image`, read by `Product\Swatches`), and a renderer select on the WooCommerce
 * attribute add / edit screen (option `_vx_attr_renderer_<taxonomy>`; "automatic" = no option, the auto rule decides).
 *
 * Every write: own nonce + capability (`edit_term` for terms, `manage_woocommerce` for the attribute screen) on top of the
 * checks WordPress / WooCommerce already did; values sanitized early (strict hex, `absint` of an image attachment, renderer whitelist).
 * A Polylang RU term left empty reads the hex / image of its Ukrainian twin (the reader), so one value per colour is enough.
 * Saving marks the variation bundles stale (`Product\Bundle` hooks `edited_term` / `created_term` for `pa_*`).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Admin;

use Vextra\Core\Product\Bundle;
use Vextra\Core\Product\Swatches;

final class SwatchFields {

	private const TERM_NONCE = 'vx_swatch_term';
	private const ATTR_NONCE = 'vx_swatch_attr';

	public function register(): void {
		add_action( 'admin_init', [ $this, 'hook_taxonomies' ] );
		add_action( 'woocommerce_after_add_attribute_fields', [ $this, 'render_attribute_field' ] );
		add_action( 'woocommerce_after_edit_attribute_fields', [ $this, 'render_attribute_field' ] );
		add_action( 'woocommerce_attribute_added', [ $this, 'save_attribute' ], 10, 2 );
		add_action( 'woocommerce_attribute_updated', [ $this, 'save_attribute' ], 10, 2 );
	}

	/**
	 * Term screens of every attribute taxonomy.
	 */
	public function hook_taxonomies(): void {
		if ( ! function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
			return;
		}
		foreach ( (array) wc_get_attribute_taxonomy_names() as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', [ $this, 'render_add' ] );
			add_action( $taxonomy . '_edit_form_fields', [ $this, 'render_edit' ] );
			add_action( 'created_' . $taxonomy, [ $this, 'save_term' ] );
			add_action( 'edited_' . $taxonomy, [ $this, 'save_term' ] );
		}
	}

	/**
	 * Add-term form fields.
	 */
	public function render_add(): void {
		wp_nonce_field( self::TERM_NONCE, self::TERM_NONCE );
		echo '<div class="form-field"><label for="vx_swatch_hex">' . esc_html__( 'Колір зразка (#rrggbb)', 'vextra-core' ) . '</label>';
		echo '<input type="text" id="vx_swatch_hex" name="vx_swatch_hex" value="" maxlength="7" placeholder="#000000" pattern="#[0-9a-fA-F]{6}" />';
		echo '<p>' . esc_html__( 'Для атрибута «Колір»: кружок кольору на сторінці товару. Порожньо для російського терміна = береться колір українського.', 'vextra-core' ) . '</p></div>';
		echo '<div class="form-field"><label for="vx_swatch_image">' . esc_html__( 'ID зображення зразка', 'vextra-core' ) . '</label>';
		echo '<input type="number" id="vx_swatch_image" name="vx_swatch_image" value="" min="0" step="1" /></div>';
	}

	/**
	 * Edit-term form fields.
	 *
	 * @param mixed $term Term.
	 */
	public function render_edit( $term = null ): void {
		$id    = $term instanceof \WP_Term ? (int) $term->term_id : 0;
		$hex   = $id > 0 ? (string) get_term_meta( $id, Swatches::META_HEX, true ) : '';
		$image = $id > 0 ? absint( get_term_meta( $id, Swatches::META_IMAGE, true ) ) : 0;
		wp_nonce_field( self::TERM_NONCE, self::TERM_NONCE );
		echo '<tr class="form-field"><th scope="row"><label for="vx_swatch_hex">' . esc_html__( 'Колір зразка (#rrggbb)', 'vextra-core' ) . '</label></th><td>';
		echo '<input type="text" id="vx_swatch_hex" name="vx_swatch_hex" value="' . esc_attr( Swatches::sanitize_hex( $hex ) ) . '" maxlength="7" placeholder="#000000" pattern="#[0-9a-fA-F]{6}" />';
		echo '<p class="description">' . esc_html__( 'Порожньо для російського терміна = береться колір українського.', 'vextra-core' ) . '</p></td></tr>';
		echo '<tr class="form-field"><th scope="row"><label for="vx_swatch_image">' . esc_html__( 'ID зображення зразка', 'vextra-core' ) . '</label></th><td>';
		echo '<input type="number" id="vx_swatch_image" name="vx_swatch_image" value="' . esc_attr( (string) $image ) . '" min="0" step="1" /></td></tr>';
	}

	/**
	 * Save the term meta.
	 *
	 * @param mixed $term_id Term id.
	 */
	public function save_term( $term_id = 0 ): void {
		$term_id = absint( $term_id );
		if ( $term_id <= 0 || ! isset( $_POST[ self::TERM_NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::TERM_NONCE ] ) ), self::TERM_NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		$hex = Swatches::sanitize_hex( isset( $_POST['vx_swatch_hex'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['vx_swatch_hex'] ) ) : '' );
		if ( '' === $hex ) {
			delete_term_meta( $term_id, Swatches::META_HEX );
		} else {
			update_term_meta( $term_id, Swatches::META_HEX, $hex );
		}
		$image = isset( $_POST['vx_swatch_image'] ) ? absint( wp_unslash( (string) $_POST['vx_swatch_image'] ) ) : 0;
		if ( $image > 0 && 'attachment' === get_post_type( $image ) && wp_attachment_is_image( $image ) ) {
			update_term_meta( $term_id, Swatches::META_IMAGE, $image );
		} else {
			delete_term_meta( $term_id, Swatches::META_IMAGE );
		}
	}

	/**
	 * Renderer select on the attribute add / edit screen.
	 */
	public function render_attribute_field(): void {
		$taxonomy = '';
		$edit     = isset( $_GET['edit'] ) ? absint( wp_unslash( (string) $_GET['edit'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen state.
		if ( $edit > 0 && function_exists( 'wc_attribute_taxonomy_name_by_id' ) ) {
			$taxonomy = (string) wc_attribute_taxonomy_name_by_id( $edit );
		}
		$current = '' !== $taxonomy ? Swatches::override( $taxonomy ) : '';
		$labels  = [
			''       => __( 'Автоматично (за даними термінів)', 'vextra-core' ),
			'color'  => __( 'Кольорові кружки', 'vextra-core' ),
			'image'  => __( 'Зображення', 'vextra-core' ),
			'chips'  => __( 'Кнопки', 'vextra-core' ),
			'select' => __( 'Випадаючий список', 'vextra-core' ),
		];
		wp_nonce_field( self::ATTR_NONCE, self::ATTR_NONCE );
		echo '<tr class="form-field"><th scope="row"><label for="vx_attr_renderer">' . esc_html__( 'Вигляд на сторінці товару', 'vextra-core' ) . '</label></th><td><select name="vx_attr_renderer" id="vx_attr_renderer">';
		foreach ( $labels as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Якщо даних для обраного вигляду бракує (немає кольору чи зображення в усіх термінів), показується випадаючий список.', 'vextra-core' ) . '</p></td></tr>';
	}

	/**
	 * Save the renderer choice of an attribute.
	 *
	 * @param mixed $id   Attribute id.
	 * @param mixed $data Attribute data (`attribute_name` = slug without `pa_`).
	 */
	public function save_attribute( $id = 0, $data = [] ): void {
		unset( $id );
		if ( ! isset( $_POST[ self::ATTR_NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::ATTR_NONCE ] ) ), self::ATTR_NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! is_array( $data ) || empty( $data['attribute_name'] ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return;
		}
		$taxonomy = 'pa_' . sanitize_title( (string) $data['attribute_name'] );
		$value    = isset( $_POST['vx_attr_renderer'] ) ? sanitize_key( wp_unslash( (string) $_POST['vx_attr_renderer'] ) ) : '';
		if ( in_array( $value, Swatches::RENDERERS, true ) ) {
			update_option( Swatches::OPTION_PREFIX . $taxonomy, $value, false );
		} else {
			delete_option( Swatches::OPTION_PREFIX . $taxonomy );
		}
		Bundle::invalidate();
	}
}
