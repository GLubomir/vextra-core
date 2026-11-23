<?php
/**
 * Option priming (TASK-010 item 7a, token `optprime`): one `wp_prime_option_caches()` for the options that WooCommerce,
 * Rank Math and WordPress read one by one on every front request (28 single queries, many for options that do not exist).
 *
 * Runs while this plugin file is included (kernel boot), i.e. BEFORE WooCommerce is included: Woo reads several of these options
 * at include time, earlier than `plugins_loaded`. For that reason the names are taken from every adapter WITHOUT its availability
 * check (`primed_options()` is plain data; priming an option of a plugin that is not installed reads nothing harmful).
 * Read-only cache warm: a wrong or renamed option only costs the old single query. WP-CLI and admin are not primed.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Performance;

use Vextra\Core\Plugin;
use Vextra\Core\Security\OwnerBotRole;

final class OptionPriming {

	/**
	 * Core and own options read on every request (not autoloaded).
	 */
	private const OWN = [ 'site_logo', OwnerBotRole::OPTION ];

	/**
	 * Called from the kernel boot (plugin file include time).
	 */
	public static function boot(): void {
		self::run();
	}

	public static function run(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || is_admin() || ! function_exists( 'wp_prime_option_caches' ) || Switches::off( 'optprime' ) ) {
			return;
		}

		wp_prime_option_caches( self::names() );
	}

	/**
	 * All names to prime (adapters + own list).
	 *
	 * @return string[]
	 */
	public static function names(): array {
		$names = self::OWN;
		foreach ( Plugin::instance()->integrations() as $integration ) {
			$names = array_merge( $names, $integration->primed_options() );
		}

		/**
		 * Filters the option names primed on every front request.
		 *
		 * @param string[] $names Option names.
		 */
		$names = apply_filters( 'vextra/core/performance/primed_options', $names );
		return array_values( array_unique( array_filter( (array) $names, 'is_string' ) ) );
	}
}
