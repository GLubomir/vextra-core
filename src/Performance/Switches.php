<?php
/**
 * Rollback switches of the Performance module (TASK-010).
 *
 * - Everything off: constant `VX_PERF_DISABLED` (wp-config.php) or filter `vextra/core/performance/enabled` returning false.
 * - One item off: token in constant `VX_PERF_OFF` (CSV) or in the autoloaded option `vx_perf_off` (CSV), or filter
 *   `vextra/core/performance/off` ( bool $off, string $token ).
 * Tokens: `optprime` (option priming), `blocktypes` (Woo block-type skip), `bloat` (emoji / oEmbed discovery).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Performance;

final class Switches {

	public const OPTION = 'vx_perf_off';

	/**
	 * Master switch.
	 */
	public static function disabled(): bool {
		if ( defined( 'VX_PERF_DISABLED' ) && VX_PERF_DISABLED ) {
			return true;
		}

		/**
		 * Filters the Performance module master switch.
		 *
		 * @param bool $enabled True unless VX_PERF_DISABLED.
		 */
		return ! (bool) apply_filters( 'vextra/core/performance/enabled', true );
	}

	/**
	 * Is one item switched off.
	 *
	 * @param string $token Item token.
	 */
	public static function off( string $token ): bool {
		if ( self::disabled() ) {
			return true;
		}

		$tokens = [];
		if ( defined( 'VX_PERF_OFF' ) ) {
			$tokens = array_merge( $tokens, explode( ',', (string) VX_PERF_OFF ) );
		}
		$tokens = array_merge( $tokens, explode( ',', (string) get_option( self::OPTION, '' ) ) );
		$tokens = array_map( 'trim', $tokens );
		$off    = in_array( $token, $tokens, true );

		/**
		 * Filters one item switch.
		 *
		 * @param bool   $off   Item is off.
		 * @param string $token Item token.
		 */
		return (bool) apply_filters( 'vextra/core/performance/off', $off, $token );
	}

	/**
	 * Create the switch option once (autoloaded, so reading it costs no query afterwards).
	 */
	public static function ensure_option(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, '', '', true );
		}
	}
}
