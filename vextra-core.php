<?php
/**
 * Plugin Name:       Vextra Core
 * Description:       Business logic for the Vextra WooCommerce store: modules, guarded integrations, shared support code.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Vextra
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vextra-core
 * Domain Path:       /languages
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'VEXTRA_CORE_VERSION', '0.1.0' );
define( 'VEXTRA_CORE_FILE', __FILE__ );
define( 'VEXTRA_CORE_DIR', plugin_dir_path( __FILE__ ) );

/*
 * Autoloading. Composer's autoloader is preferred; when `composer install` was not run we register a tiny
 * PSR-4 fallback for Vextra\Core\ so the site never white-screens, and Plugin shows an admin notice.
 */
$vextra_core_autoload   = VEXTRA_CORE_DIR . 'vendor/autoload.php';
$vextra_core_has_vendor = is_readable( $vextra_core_autoload );

if ( $vextra_core_has_vendor ) {
	require_once $vextra_core_autoload;
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'Vextra\\Core\\';
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}
			$file = VEXTRA_CORE_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook( __FILE__, [ \Vextra\Core\Lifecycle::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Vextra\Core\Lifecycle::class, 'deactivate' ] );

\Vextra\Core\Plugin::boot( ! $vextra_core_has_vendor );

unset( $vextra_core_autoload, $vextra_core_has_vendor );
