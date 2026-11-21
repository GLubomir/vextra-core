<?php
/**
 * Tested-version matrix (`docs/versions.md`): table parser + version comparison. Pure logic, no WordPress calls.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Compat;

final class Matrix {

	/**
	 * Components the matrix must contain. `php-cli` is optional (WP-CLI usually runs a newer PHP than the web server).
	 */
	public const REQUIRED = [ 'wordpress', 'woocommerce', 'polylang', 'rank-math', 'php' ];

	/**
	 * Parse the "tested" table: a markdown table whose header contains `component` and `tested version`.
	 *
	 * @param string $markdown File content.
	 * @return array<string,string> Lowercase component => tested version.
	 * @throws \RuntimeException When the table or a required component is missing.
	 */
	public static function parse( string $markdown ): array {
		$found  = false;
		$in     = false;
		$tested = [];

		$lines = preg_split( '/\R/', $markdown );
		foreach ( false === $lines ? [] : $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '|' !== $line[0] ) {
				if ( $in ) {
					break;
				}
				continue;
			}
			$cells = array_map( [ self::class, 'cell' ], explode( '|', trim( $line, '|' ) ) );
			if ( ! $in ) {
				if ( isset( $cells[0], $cells[1] ) && 'component' === strtolower( $cells[0] ) && 'tested version' === strtolower( $cells[1] ) ) {
					$in    = true;
					$found = true;
				}
				continue;
			}
			if ( 1 === preg_match( '/^:?-{2,}:?$/', $cells[0] ) ) {
				continue;
			}
			if ( '' !== $cells[0] && '' !== ( $cells[1] ?? '' ) ) {
				$tested[ strtolower( $cells[0] ) ] = $cells[1];
			}
		}

		if ( ! $found ) {
			throw new \RuntimeException( 'Tested-version table not found (expected a markdown table with columns "component | tested version").' );
		}
		$missing = array_values( array_diff( self::REQUIRED, array_keys( $tested ) ) );
		if ( array() !== $missing ) {
			throw new \RuntimeException( 'Tested-version table has no row for: ' . esc_html( implode( ', ', $missing ) ) . '.' );
		}
		return $tested;
	}

	/**
	 * Compare an installed version with the tested one.
	 *
	 * @param string      $component Component key (`php` / `php-cli` compare major.minor only).
	 * @param string|null $installed Installed version, null/'' when not installed.
	 * @param string      $tested    Tested version.
	 * @return string `same` | `newer` | `older` | `missing`.
	 */
	public static function compare( string $component, ?string $installed, string $tested ): string {
		if ( null === $installed || '' === trim( $installed ) ) {
			return 'missing';
		}
		if ( 'php' === $component || 'php-cli' === $component ) {
			$installed = self::major_minor( $installed );
			$tested    = self::major_minor( $tested );
		}
		$result = version_compare( trim( $installed ), trim( $tested ) );
		if ( 0 === $result ) {
			return 'same';
		}
		return $result > 0 ? 'newer' : 'older';
	}

	/**
	 * `8.1.34` => `8.1`.
	 *
	 * @param string $version Version string.
	 */
	public static function major_minor( string $version ): string {
		return 1 === preg_match( '/^\s*(\d+)\.(\d+)/', $version, $m ) ? $m[1] . '.' . $m[2] : trim( $version );
	}

	/**
	 * Table cell without backticks / whitespace.
	 *
	 * @param string $cell Raw cell.
	 */
	private static function cell( string $cell ): string {
		return trim( str_replace( '`', '', $cell ) );
	}
}
