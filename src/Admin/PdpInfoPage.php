<?php
/**
 * Admin page "WooCommerce > Інфо-рядки товару" (TASK-044a): edits the option `vx_pdp_info` (see Product\InfoRows) per language.
 *
 * Settings API: `options.php` verifies the nonce (`settings_fields`) and the capability (`option_page_capability_*` = `manage_woocommerce`).
 * The sanitize callback keeps only known languages and keys, `sanitize_textarea_field` texts, `absint` page ids of REAL pages,
 * threshold 0..1_000_000. Saving flushes the read cache (InfoRows::flush on the option hooks).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Admin;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;
use Vextra\Core\Product\InfoRows;

final class PdpInfoPage {

	public const SLUG = 'vx-pdp-info';

	private const NAMES = [
		'uk' => 'Українська',
		'ru' => 'Русский',
	];

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'settings' ] );
		add_filter( 'option_page_capability_' . self::SLUG, static fn(): string => 'manage_woocommerce' );
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Інфо-рядки товару', 'vextra-core' ),
			__( 'Інфо-рядки товару', 'vextra-core' ),
			'manage_woocommerce', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function settings(): void {
		register_setting(
			self::SLUG,
			InfoRows::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	/**
	 * Active language slugs ('' slot = no Polylang).
	 *
	 * @return string[]
	 */
	private function slots(): array {
		$pll = Plugin::instance()->integration( 'polylang' );
		$all = $pll instanceof Polylang && $pll->is_ready() ? $pll->languages() : [];
		return array() === $all ? [ 'default' ] : array_values( $all );
	}

	/**
	 * Sanitize the whole option.
	 *
	 * @param mixed $input Posted value.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			$old = get_option( InfoRows::OPTION, [] );
			return is_array( $old ) ? $old : [];
		}
		$input = is_array( $input ) ? wp_unslash( $input ) : [];
		$out   = [];
		foreach ( $this->slots() as $slot ) {
			$entry = is_array( $input[ $slot ] ?? null ) ? $input[ $slot ] : [];
			$rows  = [];
			foreach ( InfoRows::KEYS as $key ) {
				$row     = is_array( $entry['rows'][ $key ] ?? null ) ? $entry['rows'][ $key ] : [];
				$page_id = absint( $row['page_id'] ?? 0 );
				if ( $page_id > 0 && 'page' !== get_post_type( $page_id ) ) {
					$page_id = 0;
				}
				$rows[ $key ] = [
					'text'    => sanitize_textarea_field( (string) ( $row['text'] ?? '' ) ),
					'page_id' => $page_id,
				];
			}
			$out[ $slot ] = [
				'rows'                    => $rows,
				'free_shipping_threshold' => min( 1000000, absint( $entry['free_shipping_threshold'] ?? 0 ) ),
			];
		}
		return $out;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			wp_die( esc_html__( 'Недостатньо прав.', 'vextra-core' ), '', [ 'response' => 403 ] );
		}
		$saved  = get_option( InfoRows::OPTION, [] );
		$saved  = is_array( $saved ) ? $saved : [];
		$labels = [
			'delivery' => __( 'Доставка', 'vextra-core' ),
			'payment'  => __( 'Оплата', 'vextra-core' ),
			'warranty' => __( 'Гарантія / повернення', 'vextra-core' ),
		];
		echo '<div class="wrap"><h1>' . esc_html__( 'Інфо-рядки товару', 'vextra-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Короткі рядки під кнопкою «Купити». Поки мова не збережена, показується нейтральний текст за замовчуванням. Збережена мова більше не використовує його: порожній рядок ховається. Посилання з’являється лише на опубліковану й індексовану сторінку.', 'vextra-core' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::SLUG );
		foreach ( $this->slots() as $slot ) {
			$entry = is_array( $saved[ $slot ] ?? null ) ? $saved[ $slot ] : [];
			echo '<h2>' . esc_html( self::NAMES[ $slot ] ?? strtoupper( $slot ) ) . '</h2><table class="form-table" role="presentation">';
			foreach ( InfoRows::KEYS as $key ) {
				$row  = is_array( $entry['rows'][ $key ] ?? null ) ? $entry['rows'][ $key ] : [];
				$base = InfoRows::OPTION . '[' . $slot . '][rows][' . $key . ']';
				echo '<tr><th scope="row">' . esc_html( $labels[ $key ] ) . '</th><td>';
				echo '<textarea class="large-text" rows="2" name="' . esc_attr( $base . '[text]' ) . '">' . esc_textarea( (string) ( $row['text'] ?? '' ) ) . '</textarea><br>';
				echo '<label>' . esc_html__( 'Сторінка', 'vextra-core' ) . ' ';
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- 'echo' => 0: the markup is returned and passed through wp_kses() below.
				$dropdown = wp_dropdown_pages(
					[
						'name'              => $base . '[page_id]',
						'selected'          => absint( $row['page_id'] ?? 0 ),
						'show_option_none'  => __( '— без посилання —', 'vextra-core' ),
						'option_none_value' => '0',
						'post_status'       => 'publish',
						'echo'              => 0,
					]
				);
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				echo wp_kses(
					(string) $dropdown,
					[
						'select' => [
							'name'  => true,
							'id'    => true,
							'class' => true,
						],
						'option' => [
							'value'    => true,
							'selected' => true,
							'class'    => true,
						],
					]
				);
				echo '</label></td></tr>';
			}
			echo '<tr><th scope="row">' . esc_html__( 'Безкоштовна доставка від, ₴', 'vextra-core' ) . '</th><td>';
			echo '<input type="number" min="0" max="1000000" step="1" name="' . esc_attr( InfoRows::OPTION . '[' . $slot . '][free_shipping_threshold]' ) . '" value="' . esc_attr( (string) absint( $entry['free_shipping_threshold'] ?? 0 ) ) . '"> ';
			echo '<span class="description">' . esc_html__( '0 = рядок не показується', 'vextra-core' ) . '</span></td></tr></table>';
		}
		submit_button();
		echo '</form></div>';
	}
}
