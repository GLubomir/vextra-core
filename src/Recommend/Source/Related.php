<?php
/**
 * Deterministic related products (TASK-038): replaces Woo's random-shuffle `wc_get_related_products()`.
 *
 * Tier 1 = products of the anchor's leaf categories, tier 2 = the rest of their parent categories (descendants included, so the sibling
 * categories), tier 3 = the rest of the top-level category subtree, tier 4 = the top sellers of the language (fills thin categories; ordered by the hash inside that pool). Inside a tier:
 * shared `product_tag` count desc, shared `pa_*` term count desc, then a stable per-anchor hash `crc32( anchor:candidate )`, then id.
 * The hash is the final tie-break on purpose (SEO review): it is the same on every request for one product page but differs between
 * pages, so internal links spread over the whole category instead of always landing on the lowest ids. No rand / shuffle anywhere.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend\Source;

use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;
use Vextra\Core\Recommend\Anchor;
use Vextra\Core\Recommend\Pool;

final class Related implements SourceInterface {

	/**
	 * Candidates kept per tier (4 tiers: the Engine bounds the source at 4 x this).
	 */
	public const TIER_CAP = 30;

	public function id(): string {
		return 'related';
	}

	public function candidates( Anchor $anchor ): array {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( ! $woo instanceof WooCommerce ) {
			return [];
		}

		$leaves  = Pool::leaves( Pool::categories( $anchor->id ) ); // No category: tiers 1-3 are empty, the bestsellers still fill.
		$parents = [];
		$tops    = [];
		foreach ( $leaves as $leaf ) {
			$term = get_term( $leaf, 'product_cat' );
			if ( $term instanceof \WP_Term && $term->parent > 0 ) {
				$parents[] = (int) $term->parent;
				$chain     = get_ancestors( $leaf, 'product_cat', 'taxonomy' );
				$tops[]    = (int) end( $chain );
			}
		}

		// Tier 1 leaf category, 2 parent subtree (the sibling categories are inside it), 3 top-level subtree. Each tier skips the earlier ones.
		$tier1 = array_values( array_diff( Pool::ids_in_terms( $leaves ), [ $anchor->id ] ) );
		$tier2 = array_values( array_diff( Pool::ids_in_terms( array_unique( $parents ) ), [ $anchor->id ], $tier1 ) );
		$tier3 = array_values( array_diff( Pool::ids_in_terms( array_unique( $tops ) ), [ $anchor->id ], $tier1, $tier2 ) );
		$all   = array_merge( $tier1, $tier2, $tier3 );

		$taxonomies = array_merge(
			[ 'product_tag' ],
			array_values( array_filter( get_object_taxonomies( 'product' ), static fn( string $tax ): bool => str_starts_with( $tax, 'pa_' ) ) )
		);
		$terms      = array() === $all ? [] : $woo->lookup()->term_facts( array_merge( [ $anchor->id ], $all ), $taxonomies );
		$mine       = $terms[ $anchor->id ] ?? [];

		$out = [];
		foreach ( [
			1 => $tier1,
			2 => $tier2,
			3 => $tier3,
		] as $tier => $ids ) {
			$rows = [];
			foreach ( $ids as $id ) {
				$theirs = $terms[ $id ] ?? [];
				$tags   = count( array_intersect( $mine['product_tag'] ?? [], $theirs['product_tag'] ?? [] ) );
				$attrs  = 0;
				foreach ( $taxonomies as $tax ) {
					if ( 'product_tag' !== $tax ) {
						$attrs += count( array_intersect( $mine[ $tax ] ?? [], $theirs[ $tax ] ?? [] ) );
					}
				}
				$rows[] = [
					'id'   => $id,
					'src'  => 'related',
					'meta' => [
						'tier'  => $tier,
						'tags'  => $tags,
						'attr'  => $attrs,
						'sales' => 0,
						'key'   => Pool::spread_key( $anchor->id, $id ),
					],
				];
			}
			$out = array_merge( $out, self::sorted( $rows ) );
		}

		// Tier 4: store-wide bestsellers of the same language (fills thin categories); same hash tie-break.
		$seen = array_merge( [ $anchor->id ], $all );
		$rows = [];
		foreach ( Pool::bestsellers( $anchor->lang ) as $id => $sold ) {
			if ( in_array( $id, $seen, true ) ) {
				continue;
			}
			$rows[] = [
				'id'   => $id,
				'src'  => 'related',
				'meta' => [
					'tier'  => 4,
					'tags'  => 0,
					'attr'  => 0,
					'sales' => $sold,
					'key'   => Pool::spread_key( $anchor->id, $id ),
				],
			];
		}
		// The pool is the TIER_CAP best sellers; inside it the per-anchor hash orders the cards (not sales), so the top sellers do not
		// collect every thin category's link.
		usort(
			$rows,
			static fn( array $a, array $b ): int => [ 0 - $a['meta']['sales'], $a['id'] ] <=> [ 0 - $b['meta']['sales'], $b['id'] ]
		);
		$rows = array_slice( $rows, 0, self::TIER_CAP );
		usort(
			$rows,
			static fn( array $a, array $b ): int => [ $a['meta']['key'], $a['id'] ] <=> [ $b['meta']['key'], $b['id'] ]
		);
		return array_merge( $out, $rows );
	}

	/**
	 * Order one tier: shared tags desc, shared attributes desc, per-anchor hash, id; then bound it so a big
	 * tier cannot crowd out the later ones.
	 *
	 * @param array<int,array{id:int,src:string,meta:array<string,mixed>}> $rows Tier rows.
	 * @return array<int,array{id:int,src:string,meta:array<string,mixed>}>
	 */
	private static function sorted( array $rows ): array {
		usort(
			$rows,
			static fn( array $a, array $b ): int => [ 0 - $a['meta']['tags'], 0 - $a['meta']['attr'], 0 - $a['meta']['sales'], $a['meta']['key'], $a['id'] ] <=> [ 0 - $b['meta']['tags'], 0 - $b['meta']['attr'], 0 - $b['meta']['sales'], $b['meta']['key'], $b['id'] ]
		);
		return array_slice( $rows, 0, self::TIER_CAP );
	}
}
