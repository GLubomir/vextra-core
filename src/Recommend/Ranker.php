<?php
/**
 * Ranking stage (TASK-038): source priority (manual upsell / cross-sell > accessory > related), then the related tier (leaf, parent, top-level, bestsellers), then the anchor's partner first
 * (`_vx_partner_id`; empty = the store's default partner, one partner), then the in-source order; related items inside a (tier, partner) group use a relevance window (best 2 x limit by score, ties included) ordered by the per-anchor hash, which spreads the internal links (SEO). Stable (PHP 8 sort); never drops an
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
		$same_of       = [];
		$groups        = [];
		foreach ( $items as $i => $item ) {
			$same_of[ $i ]    = $partner_first && ( (string) ( $facts[ $item['id'] ]['partner'] ?? '' ) === $partner ) ? 0 : 1;
			$items[ $i ]['p'] = (string) ( $facts[ $item['id'] ]['partner'] ?? '' );
			if ( 'related' === $item['src'] ) {
				$groups[ (int) ( $item['meta']['tier'] ?? 0 ) . '|' . $same_of[ $i ] ][] = $i;
			}
		}

		// Related: relevance GATES, the per-anchor hash SPREADS. Per (tier, partner group) the window = the best `2 x limit` items by
		// score (shared tags, then attributes; ties at the edge included); inside it the hash orders the slots, the rest follows by score.
		$position = [];
		$size     = max( 1, 2 * Config::limit( 'related' ) );
		foreach ( $groups as $members ) {
			usort(
				$members,
				static fn( int $a, int $b ): int => [ 0 - $items[ $a ]['meta']['tags'], 0 - $items[ $a ]['meta']['attr'], $a ] <=> [ 0 - $items[ $b ]['meta']['tags'], 0 - $items[ $b ]['meta']['attr'], $b ]
			);
			$score = static fn( int $i ): array => [ $items[ $i ]['meta']['tags'], $items[ $i ]['meta']['attr'] ];
			$cut   = min( $size, count( $members ) );
			$total = count( $members );
			while ( $cut > 0 && $cut < $total && $score( $members[ $cut ] ) === $score( $members[ $cut - 1 ] ) ) {
				++$cut;
			}
			$window = array_slice( $members, 0, $cut );
			$rest   = array_slice( $members, $cut );
			usort(
				$window,
				static fn( int $a, int $b ): int => [ $items[ $a ]['meta']['key'], $items[ $a ]['id'] ] <=> [ $items[ $b ]['meta']['key'], $items[ $b ]['id'] ]
			);
			foreach ( array_merge( $window, $rest ) as $pos => $i ) {
				$position[ $i ] = $pos;
			}
			foreach ( $window as $i ) {
				$items[ $i ]['meta']['window'] = true;
			}
		}

		$index = [];
		foreach ( $items as $i => $item ) {
			$index[ $i ] = [ self::PRIORITY[ $item['src'] ] ?? 9, (int) ( $item['meta']['tier'] ?? 0 ), $same_of[ $i ], $position[ $i ] ?? $i, $i ];
		}
		uasort( $index, static fn( array $a, array $b ): int => $a <=> $b );

		$out = [];
		foreach ( array_keys( $index ) as $i ) {
			$out[] = $items[ $i ];
		}
		return $out;
	}
}
