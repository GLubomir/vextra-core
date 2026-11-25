<?php
/**
 * WP-CLI `wp vextra checkout check-phone` (TASK-045a, AC8): runs the phone normaliser against fixed accept / reject cases and exits non-zero on a
 * mismatch. `wp vextra checkout check-phone <value>` prints the result for one value.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Checkout;

final class Cli {

	private const ACCEPT = [
		'0501234567'          => '+380501234567',
		'380501234567'        => '+380501234567',
		'+380 50 123 45 67'   => '+380501234567',
		'050-123-45-67'       => '+380501234567',
		'(050) 123 45 67'     => '+380501234567',
		'+38 (050) 123-45-67' => '+380501234567',
		' 050 123 45 67 '     => '+380501234567',
	];

	private const REJECT = [ '12345', '+48501234567', '05012345678', '050abc4567', '', '+0501234567', '0051234567', '3805012345678', '++380501234567', '380 50 123 45 6', '+380501234567x' ];

	public function register(): void {
		\WP_CLI::add_command(
			'vextra checkout check-phone',
			[ $this, 'check_phone' ],
			[ 'shortdesc' => 'Run the Ukrainian phone normaliser on the fixed accept / reject cases (or on one given value).' ]
		);
	}

	/**
	 * Command: check-phone.
	 *
	 * @param array<int,string>    $args  Optional single value.
	 * @param array<string,string> $assoc Flags (unused).
	 */
	public function check_phone( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( isset( $args[0] ) ) {
			$out = Phone::normalise( (string) $args[0] );
			\WP_CLI::line( null === $out ? 'invalid' : $out );
			return;
		}
		$fails = 0;
		foreach ( self::ACCEPT as $raw => $want ) {
			$got = Phone::normalise( (string) $raw );
			if ( $got !== $want ) {
				++$fails;
				\WP_CLI::warning( sprintf( 'FAIL accept %s: got %s', wp_json_encode( $raw ), wp_json_encode( $got ) ) );
			}
		}
		foreach ( self::REJECT as $raw ) {
			if ( null !== Phone::normalise( $raw ) ) {
				++$fails;
				\WP_CLI::warning( sprintf( 'FAIL reject %s', wp_json_encode( $raw ) ) );
			}
		}
		if ( $fails > 0 ) {
			\WP_CLI::error( sprintf( '%d phone case(s) failed.', $fails ) );
		}
		\WP_CLI::success( sprintf( '%d accept + %d reject cases passed.', count( self::ACCEPT ), count( self::REJECT ) ) );
	}
}
