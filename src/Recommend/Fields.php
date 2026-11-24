<?php
/**
 * Product data fields of the Recommend module (TASK-038): partner, commission, stock-synced-at.
 *
 * - `_vx_partner_id`: slug `[a-z0-9_-]{0,40}`; empty = the store's default partner (ADR-009). Same partner as the anchor ranks first.
 * - `_vx_commission`: decimal percent 0..100, empty allowed (Phase 2 margin weight; stored only now).
 * - `_vx_stock_synced_at`: unix time of the last supplier stock sync, written by the import (E7); read-only in the admin.
 * Registered with `register_post_meta` (sanitised, NOT in REST). Admin: a small "Vextra" block on the product General tab; it is saved
 * through Woo's `woocommerce_admin_process_product_object` (CRUD, no raw postmeta) after OUR nonce and the `edit_product` capability.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend;

final class Fields {

	public const PARTNER    = '_vx_partner_id';
	public const COMMISSION = '_vx_commission';
	public const SYNCED_AT  = '_vx_stock_synced_at';

	private const NONCE_ACTION = 'vx_rec_fields';
	private const NONCE_FIELD  = 'vx_rec_fields_nonce';

	public function register(): void {
		add_action( 'init', [ $this, 'register_meta' ] );
		add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save' ] );
	}

	/**
	 * Register the three keys (product post type, single, not exposed in REST).
	 */
	public function register_meta(): void {
		$common = [
			'single'        => true,
			'show_in_rest'  => false,
			'auth_callback' => static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
		];
		register_post_meta(
			'product',
			self::PARTNER,
			$common + [
				'type'              => 'string',
				'sanitize_callback' => [ self::class, 'sanitize_partner' ],
			]
		);
		register_post_meta(
			'product',
			self::COMMISSION,
			$common + [
				'type'              => 'string',
				'sanitize_callback' => [ self::class, 'sanitize_commission' ],
			]
		);
		register_post_meta(
			'product',
			self::SYNCED_AT,
			$common + [
				'type'              => 'integer',
				'sanitize_callback' => [ self::class, 'sanitize_synced_at' ],
			]
		);
	}

	/**
	 * Partner slug: `[a-z0-9_-]`, at most 40 characters, '' = default partner.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_partner( $value ): string {
		$value = is_scalar( $value ) ? strtolower( (string) $value ) : '';
		return substr( (string) preg_replace( '/[^a-z0-9_-]/', '', $value ), 0, 40 );
	}

	/**
	 * Commission percent: a number clamped to 0..100 (two decimals), '' when empty or not numeric.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_commission( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( str_replace( ',', '.', (string) $value ) );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}
		return (string) round( max( 0.0, min( 100.0, (float) $value ) ), 2 );
	}

	/**
	 * Unix time: integer >= 0.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_synced_at( $value ): int {
		return is_scalar( $value ) && is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Partner of a product ('' = default partner). Reads the post meta cache (primed by Pool::facts).
	 *
	 * @param int $product_id Product id.
	 */
	public static function partner( int $product_id ): string {
		return self::sanitize_partner( get_post_meta( $product_id, self::PARTNER, true ) );
	}

	/**
	 * Last stock sync unix time (0 = never / not synced by a feed).
	 *
	 * @param int $product_id Product id.
	 */
	public static function synced_at( int $product_id ): int {
		return self::sanitize_synced_at( get_post_meta( $product_id, self::SYNCED_AT, true ) );
	}

	/**
	 * Product General tab block. Render only (the helpers escape).
	 */
	public function render(): void {
		global $post;
		if ( ! $post instanceof \WP_Post || ! function_exists( 'woocommerce_wp_text_input' ) || ! current_user_can( 'edit_post', $post->ID ) ) { // edit_post maps to edit_product for the product post type.
			return;
		}
		$synced = self::synced_at( $post->ID );

		echo '<div class="options_group vx-rec-fields">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		woocommerce_wp_text_input(
			[
				'id'          => self::PARTNER,
				'label'       => __( 'Партнер (Vextra)', 'vextra-core' ),
				'description' => __( 'Слаг партнера: a-z, 0-9, - і _. Порожньо = партнер магазину за замовчуванням.', 'vextra-core' ),
				'desc_tip'    => true,
				'value'       => self::partner( $post->ID ),
				'type'        => 'text',
			]
		);
		woocommerce_wp_text_input(
			[
				'id'                => self::COMMISSION,
				'label'             => __( 'Комісія, % (Vextra)', 'vextra-core' ),
				'description'       => __( 'Від 0 до 100. Порожньо = не задано.', 'vextra-core' ),
				'desc_tip'          => true,
				'value'             => (string) get_post_meta( $post->ID, self::COMMISSION, true ),
				'type'              => 'text',
				'custom_attributes' => [ 'inputmode' => 'decimal' ],
			]
		);
		woocommerce_wp_text_input(
			[
				'id'                => self::SYNCED_AT,
				'label'             => __( 'Залишки синхронізовано (Vextra)', 'vextra-core' ),
				'description'       => __( 'Заповнюється імпортом постачальника. Лише для читання.', 'vextra-core' ),
				'desc_tip'          => true,
				'value'             => $synced > 0 ? wp_date( 'Y-m-d H:i', $synced ) : '',
				'type'              => 'text',
				'custom_attributes' => [
					'readonly' => 'readonly',
					'disabled' => 'disabled',
				],
			]
		);
		echo '</div>';
	}

	/**
	 * Save partner + commission onto the product object Woo is about to save. Needs OUR nonce and `edit_product`; synced-at is never
	 * taken from the form.
	 *
	 * @param mixed $product Product being saved.
	 */
	public function save( $product ): void {
		if ( ! $product instanceof \WC_Product || ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) );
		if ( false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $product->get_id() ) ) { // edit_post maps to edit_product for the product post type.
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$partner    = isset( $_POST[ self::PARTNER ] ) ? self::sanitize_partner( sanitize_text_field( wp_unslash( (string) $_POST[ self::PARTNER ] ) ) ) : '';
		$commission = isset( $_POST[ self::COMMISSION ] ) ? self::sanitize_commission( sanitize_text_field( wp_unslash( (string) $_POST[ self::COMMISSION ] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( self::PARTNER, $partner );
		$product->update_meta_data( self::COMMISSION, $commission );
	}
}
