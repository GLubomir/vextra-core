<?php
/**
 * Accessory categories source (TASK-038, ADR-010): consumes the 043b rule store `Catalog\AccessoryRules` (term meta
 * `_vx_accessory_terms`); it copies nothing and has no admin UI of its own.
 *
 * For every leaf category of the anchor the nearest category up the parent chain that has a rule is used; its targets (default-language
 * terms) are mapped to the cache language through `TermPair`. Products of a target (and its descendants) are ordered by total sales
 * desc then id asc; the targets are merged round-robin in admin order so one target does not take every slot.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend\Source;

use Vextra\Core\Catalog\AccessoryRules;
use Vextra\Core\Catalog\TermPair;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;
use Vextra\Core\Recommend\Anchor;
use Vextra\Core\Recommend\Pool;

final class Accessories implements SourceInterface {

	public function id(): string {
		return 'accessory';
	}

	public function candidates( Anchor $anchor ): array {
		$targets = $this->targets( $anchor );
		if ( array() === $targets ) {
			return [];
		}

		$columns = [];
		foreach ( $targets as $term_id ) {
			$ids = array_values( array_diff( Pool::ids_in_terms( [ $term_id ] ), [ $anchor->id ] ) );
			if ( array() !== $ids ) {
				$columns[ $term_id ] = $ids;
			}
		}
		$facts = Pool::light( array_merge( [], ...array_values( $columns ) ) ); // ONE statement for every target (sales order).
		foreach ( $columns as $term_id => $ids ) {
			usort(
				$ids,
				static fn( int $a, int $b ): int => [ 0 - (int) ( $facts[ $a ]['sales'] ?? 0 ), $a ] <=> [ 0 - (int) ( $facts[ $b ]['sales'] ?? 0 ), $b ]
			);
			$columns[ $term_id ] = $ids;
		}

		$out  = [];
		$seen = [];
		for ( $row = 0; $row < \Vextra\Core\Recommend\Config::MAX_LIMIT * 4; $row++ ) {
			$any = false;
			foreach ( $columns as $term_id => $ids ) {
				if ( ! isset( $ids[ $row ] ) ) {
					continue;
				}
				$any = true;
				$id  = $ids[ $row ];
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$out[]       = [
					'id'   => $id,
					'src'  => 'accessory',
					'meta' => [
						'target' => $term_id,
						'rank'   => $row + 1,
					],
				];
			}
			if ( ! $any ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Target terms (cache-language ids) of the anchor's rules, admin order, de-duplicated, at most AccessoryRules::MAX.
	 *
	 * @param Anchor $anchor Anchor.
	 * @return int[]
	 */
	public function targets( Anchor $anchor ): array {
		$leaves = Pool::leaves( Pool::categories( $anchor->id ) );
		if ( array() === $leaves ) {
			return [];
		}

		$chains = [];
		$all    = [];
		foreach ( $leaves as $leaf ) {
			$chain           = array_merge( [ $leaf ], array_map( 'intval', get_ancestors( $leaf, 'product_cat', 'taxonomy' ) ) );
			$chains[ $leaf ] = $chain;
			$all             = array_merge( $all, $chain );
		}
		$pll = Plugin::instance()->integration( 'polylang' );
		if ( $pll instanceof Polylang ) {
			$pll->prime_terms( $all ); // Language + translation groups of the chain in one read.
		}

		// Warm the rule targets (term objects + language + translation groups) before the per-term lookups below.
		$raw = [];
		foreach ( array_unique( array_map( [ TermPair::class, 'source' ], $all ) ) as $source ) {
			foreach ( (array) get_term_meta( $source, AccessoryRules::META, true ) as $target ) {
				$raw[] = absint( $target );
			}
		}
		$raw = array_values( array_unique( array_filter( $raw ) ) );
		if ( array() !== $raw ) {
			Pool::prime_terms( $raw );
			if ( $pll instanceof Polylang ) {
				$pll->prime_terms( $raw );
			}
		}

		$targets = [];
		foreach ( $chains as $chain ) {
			foreach ( $chain as $term_id ) {
				$own = AccessoryRules::own( TermPair::source( $term_id ) );
				if ( array() === $own ) {
					continue;
				}
				foreach ( $own as $target ) {
					$mapped = TermPair::in_language( $target, $anchor->lang );
					if ( $mapped > 0 && ! in_array( $mapped, $targets, true ) ) {
						$targets[] = $mapped;
					}
				}
				break; // The nearest rule of this leaf wins.
			}
		}
		return array_slice( $targets, 0, AccessoryRules::MAX );
	}
}
