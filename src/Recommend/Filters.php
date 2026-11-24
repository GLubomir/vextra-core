<?php
/**
 * Filter stage of the Recommend pipeline (TASK-038). Pure functions over the bulk facts of `Pool::facts()`: no query per candidate.
 *
 * Order (each stage reports what it dropped and why, `wp vextra recommend explain` prints it):
 * self -> excluded (cart items / caller `exclude`) -> status (published product) -> language -> visibility (`exclude-from-catalog`) ->
 * stock (`instock` / `onbackorder`, has a lookup row) -> purchasable (has a price) -> stale (`_vx_stock_synced_at` older than
 * `vx_rec_stale_hours`; absent = ignored) -> price band (candidate min price vs anchor price; the LOWER bound applies to related items only: accessories and manual
 * upsell / cross-sell links are cheaper by nature, the upper bound applies to every source).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend;

final class Filters {

	/**
	 * Filter names in run order.
	 */
	public const ORDER = [ 'self', 'excluded', 'status', 'language', 'visibility', 'stock', 'purchasable', 'stale', 'price_band' ];

	/**
	 * Run every filter.
	 *
	 * @param array<int,array{id:int,src:string,meta?:array}>                             $items Candidates (deduplicated, priority order).
	 * @param array{self:int[],exclude:array<int,string>,lang:string,price:float,now:int} $ctx Context: anchor product ids, id => reason of
	 *        excluded ids, cache language, anchor price (0 = no band), current unix time.
	 * @return array{items:array<int,array{id:int,src:string,meta?:array}>,dropped:array<int,array{filter:string,dropped:array<int,array{0:int,1:string}>}>}
	 */
	public static function apply( array $items, array $ctx ): array {
		$facts   = Pool::facts( array_column( $items, 'id' ) );
		$band    = Config::price_band();
		$hours   = (float) Config::get( 'stale_hours' );
		$dropped = [];
		foreach ( self::ORDER as $name ) {
			$dropped[ $name ] = [];
		}

		$kept = [];
		foreach ( $items as $item ) {
			$id     = $item['id'];
			$f      = $facts[ $id ] ?? [];
			$reason = self::reject( $id, $item['src'], $f, $ctx, $band, $hours );
			if ( null === $reason ) {
				$kept[] = $item;
				continue;
			}
			$dropped[ $reason[0] ][] = [ $id, $reason[1] ];
		}

		$trace = [];
		foreach ( self::ORDER as $name ) {
			$trace[] = [
				'filter'  => $name,
				'dropped' => $dropped[ $name ],
			];
		}
		return [
			'items'   => $kept,
			'dropped' => $trace,
		];
	}

	/**
	 * First failing filter of a candidate: [ filter, reason ] or null when it passes.
	 *
	 * @param int                        $id    Candidate id.
	 * @param string                     $src   Source of the candidate.
	 * @param array<string,mixed>        $f     Facts (empty = unknown product).
	 * @param array<string,mixed>        $ctx   Context.
	 * @param array{min:float,max:float} $band  Price band.
	 * @param float                      $hours Stale threshold in hours.
	 * @return array{0:string,1:string}|null
	 */
	private static function reject( int $id, string $src, array $f, array $ctx, array $band, float $hours ): ?array {
		if ( in_array( $id, $ctx['self'], true ) ) {
			return [ 'self', 'anchor product' ];
		}
		if ( isset( $ctx['exclude'][ $id ] ) ) {
			return [ 'excluded', (string) $ctx['exclude'][ $id ] ];
		}
		if ( array() === $f ) {
			return [ 'status', 'not a product' ];
		}
		if ( 'publish' !== $f['status'] ) {
			return [ 'status', 'status ' . $f['status'] ];
		}
		if ( '' !== $ctx['lang'] && '' !== $f['lang'] && $f['lang'] !== $ctx['lang'] ) {
			return [ 'language', 'language ' . $f['lang'] ];
		}
		if ( ! empty( $f['hidden'] ) ) {
			return [ 'visibility', 'exclude-from-catalog' ];
		}
		if ( empty( $f['has_row'] ) ) {
			return [ 'stock', 'no lookup row' ];
		}
		if ( ! in_array( $f['stock'], [ 'instock', 'onbackorder' ], true ) ) {
			return [ 'stock', 'stock ' . $f['stock'] ];
		}
		if ( $f['min'] <= 0 && $f['max'] <= 0 ) {
			return [ 'purchasable', 'no price' ];
		}
		if ( $f['synced'] > 0 && $hours > 0 && ( $ctx['now'] - $f['synced'] ) > $hours * HOUR_IN_SECONDS ) {
			return [ 'stale', 'synced ' . (int) ( ( $ctx['now'] - $f['synced'] ) / HOUR_IN_SECONDS ) . ' h ago' ];
		}
		if ( $ctx['price'] > 0 ) {
			$price = (float) $f['min'];
			if ( 'related' === $src && $band['min'] > 0 && $price < $ctx['price'] * $band['min'] ) {
				return [ 'price_band', 'price ' . $price . ' < ' . round( $ctx['price'] * $band['min'], 2 ) ];
			}
			if ( $band['max'] > 0 && $price > $ctx['price'] * $band['max'] ) {
				return [ 'price_band', 'price ' . $price . ' > ' . round( $ctx['price'] * $band['max'], 2 ) ];
			}
		}
		return null;
	}
}
