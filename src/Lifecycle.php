<?php
/**
 * Activation / deactivation callbacks. Deactivation never deletes data or options.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core;

final class Lifecycle {

	private const MIN_PHP = '8.1';
	private const MIN_WP  = '7.1';

	public static function activate(): void {
		global $wp_version;

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			/* translators: %s: PHP version */
			wp_die( esc_html( sprintf( __( 'vextra-core потребує PHP %s або новішої версії.', 'vextra-core' ), self::MIN_PHP ) ) );
		}
		if ( version_compare( (string) $wp_version, self::MIN_WP, '<' ) ) {
			/* translators: %s: WordPress version */
			wp_die( esc_html( sprintf( __( 'vextra-core потребує WordPress %s або новішої версії.', 'vextra-core' ), self::MIN_WP ) ) );
		}

		update_option( 'vextra_core_version', VEXTRA_CORE_VERSION, false );
	}

	/**
	 * Clears only transient cache entries owned by the plugin. Options and business data stay.
	 */
	public static function deactivate(): void {
		global $wpdb;

		// No scheduled hooks yet. Future modules unschedule their own Action Scheduler hooks here.
		$value   = $wpdb->esc_like( '_transient_vx_core_' ) . '%';
		$timeout = $wpdb->esc_like( '_transient_timeout_vx_core_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $value, $timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
