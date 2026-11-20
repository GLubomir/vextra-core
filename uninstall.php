<?php
/**
 * Uninstall: removes only the plugin's own bookkeeping options.
 *
 * Business data (product meta, mappings, logs) is NEVER deleted here. Opt-in deletion will be gated by the
 * constant VEXTRA_CORE_REMOVE_DATA once a module owns data; it is intentionally not implemented yet.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'vextra_core_version' );

// Cache group version options (Support\Cache).
$vextra_core_like = $wpdb->esc_like( 'vx_core_cache_ver_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $vextra_core_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Future: if ( defined( 'VEXTRA_CORE_REMOVE_DATA' ) && VEXTRA_CORE_REMOVE_DATA ) { ...module data removal... }.
