<?php
/**
 * Ranking stage (TASK-038): source priority (manual upsell / cross-sell > accessory > related), then the anchor's partner first
 * (`_vx_partner_id`; empty = the store's default partner, one partner), then the in-source order. Stable (PHP 8 sort); never drops an
 * item. Margin weight is NOT applied (Phase 2).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend;

final class Ranker {

	/**
	 * Source id => priority (lower first).
	 */
	public const PRIORITY = [
		'upsell'    => 0,
		'cross'     => 0,
		'accessory' => 1,
		'related'   => 2,
	];

	/**
	 * Rank items.
	 *
	 * @param array<int,array{id:int,src:string,meta?:array}> $items  Filtered candidates in source order.
	 * @param string                                          $partner Anchor partner ('' = default partner).
	 * @return array<int,array{id:int,src:string,meta?:array}>
	 */
	public static function rank( array $items, string $partner ): array {
		$partner_first = (int) Config::get( 'partner' ) > 0;
		$facts         = Pool::facts( array_column( $items, 'id' ) );
		$index         = [];
		foreach ( $items as $i => $item ) {
			$same             = $partner_first && ( (string) ( $facts[ $item['id'] ]['partner'] ?? '' ) === $partner ) ? 0 : 1;
			$index[ $i ]      = [ self::PRIORITY[ $item['src'] ] ?? 9, $same, $i ];
			$items[ $i ]['p'] = (string) ( $facts[ $item['id'] ]['partner'] ?? '' );
		}
		uasort( $index, static fn( array $a, array $b ): int => $a <=> $b );

		$out = [];
		foreach ( array_keys( $index ) as $i ) {
			$out[] = $items[ $i ];
		}
		return $out;
	}
}
