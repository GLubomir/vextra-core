<?php
/**
 * Recommend configuration (TASK-038): the one place that knows every number. No admin UI in Phase 1.
 *
 * Resolution per key: option `vx_rec_<key>` (changing it flushes the cache) -> filter `vextra/recommend/config` -> default.
 * Price band filter: `vextra/recommend/price_band`. Kill switch: constant `VX_RECOMMEND_DISABLED` / filter `vextra/core/recommend/enabled`.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend;

final class Config {

	public const DEFAULTS = [
		'min_ratio'   => 0.1,  // Candidate price >= anchor price x this (accessories are exempt).
		'max_ratio'   => 5.0,  // Candidate price <= anchor price x this.
		'stale_hours' => 72,   // `_vx_stock_synced_at` older than this drops the candidate (absent meta = ignored).
		'partner'     => 1,    // Same partner as the anchor first (1 / 0).
		'scan'        => 500,  // Ids read per category query (safety cap; every scanned id is scored).
	];

	/**
	 * Result size per context / list.
	 */
	public const LIMITS = [
		'accessories' => 4,
		'related'     => 4,
		'cart'        => 4,
		'post_add'    => 3,
	];

	/**
	 * Hard ceiling of `$args['limit']` and the size of a cached list.
	 */
	public const MAX_LIMIT = 8;

	/**
	 * Option prefix whose change flushes the cache.
	 */
	public const OPTION_PREFIX = 'vx_rec_';

	/**
	 * Per-request memo of the resolved numbers.
	 *
	 * @var array<string,float|int>|null
	 */
	private static ?array $memo = null;

	/**
	 * Every resolved number (options primed with ONE query, cold builds only).
	 *
	 * @return array<string,float|int>
	 */
	public static function all(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$names = array_map( static fn( string $key ): string => self::OPTION_PREFIX . $key, array_keys( self::DEFAULTS ) );
		if ( function_exists( 'wp_prime_option_caches' ) ) {
			wp_prime_option_caches( $names );
		}
		$values = self::DEFAULTS;
		foreach ( self::DEFAULTS as $key => $default ) {
			$stored = get_option( self::OPTION_PREFIX . $key, null );
			if ( is_numeric( $stored ) ) {
				$values[ $key ] = $stored + 0;
			}
		}

		/**
		 * Filters every Recommend number.
		 *
		 * @param array<string,float|int> $values Key => value (see Config::DEFAULTS), options already applied.
		 */
		$filtered = apply_filters( 'vextra/recommend/config', $values );
		if ( is_array( $filtered ) ) {
			foreach ( $values as $key => $value ) {
				if ( isset( $filtered[ $key ] ) && is_numeric( $filtered[ $key ] ) ) {
					$values[ $key ] = $filtered[ $key ] + 0;
				}
			}
		}
		self::$memo = array_map( static fn( $v ) => max( 0, $v ), $values );
		return self::$memo;
	}

	/**
	 * Forget the memo (tests, option change).
	 */
	public static function reset(): void {
		self::$memo = null;
	}

	/**
	 * Resolved value of a key.
	 *
	 * @param string $key Key of self::DEFAULTS.
	 * @return float|int
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? 0;
	}

	/**
	 * Default result size of a context / list.
	 *
	 * @param string $name accessories|related|cart|post_add.
	 */
	public static function limit( string $name ): int {
		$limit = (int) apply_filters( 'vextra/recommend/limit', self::LIMITS[ $name ] ?? 4, $name );
		return max( 1, min( self::MAX_LIMIT, $limit ) );
	}

	/**
	 * Price band as multipliers of the anchor price.
	 *
	 * @return array{min:float,max:float}
	 */
	public static function price_band(): array {
		$band = [
			'min' => (float) self::get( 'min_ratio' ),
			'max' => (float) self::get( 'max_ratio' ),
		];

		/**
		 * Filters the price band.
		 *
		 * @param array{min:float,max:float} $band Multipliers of the anchor price.
		 */
		$filtered = apply_filters( 'vextra/recommend/price_band', $band );
		if ( is_array( $filtered ) && isset( $filtered['min'], $filtered['max'] ) && is_numeric( $filtered['min'] ) && is_numeric( $filtered['max'] ) ) {
			$band = [
				'min' => max( 0.0, (float) $filtered['min'] ),
				'max' => max( 0.0, (float) $filtered['max'] ),
			];
		}
		return $band;
	}
}
