<?php
/**
 * Fail-open wrapper: a guard that throws must never take the store down.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

use Vextra\Core\Support\Log;

final class Failsafe {

	/**
	 * Places already reported during this request.
	 *
	 * @var array<string,bool>
	 */
	private static array $reported = [];

	/**
	 * Run a guard callback; on any Throwable log once per place and return the default.
	 *
	 * @param string   $where   Short place name for the log line.
	 * @param callable $cb      Guard logic.
	 * @param mixed    $fallback Value returned when the guard failed.
	 * @return mixed
	 */
	public static function run( string $where, callable $cb, $fallback = null ) {
		try {
			return $cb();
		} catch ( \Throwable $e ) {
			if ( ! isset( self::$reported[ $where ] ) ) {
				self::$reported[ $where ] = true;
				Log::error( 'Guard failed open at ' . $where . ': ' . $e->getMessage(), [], 'guard' );
			}
			return $fallback;
		}
	}
}
