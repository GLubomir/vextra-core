<?php
/**
 * Admin notices: WooCommerce missing, Composer autoloader missing. Shown to `manage_options` only.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Admin;

use Vextra\Core\Integrations\AbstractIntegration;

final class Notices {

	/**
	 * Composer autoloader missing.
	 *
	 * @var bool
	 */
	private bool $autoload_missing;

	/**
	 * WooCommerce adapter.
	 *
	 * @var AbstractIntegration
	 */
	private AbstractIntegration $woocommerce;

	public function __construct( bool $autoload_missing, AbstractIntegration $woocommerce ) {
		$this->autoload_missing = $autoload_missing;
		$this->woocommerce      = $woocommerce;
	}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->woocommerce->is_available() ) {
			$this->notice( __( 'vextra-core потребує WooCommerce', 'vextra-core' ), 'error' );
		}
		if ( $this->autoload_missing ) {
			$this->notice( __( 'vextra-core: не знайдено vendor/autoload.php. Виконайте composer install у теці плагіна.', 'vextra-core' ), 'warning' );
		}
	}

	private function notice( string $text, string $type ): void {
		printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}
}
