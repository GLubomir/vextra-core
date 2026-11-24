<?php
/**
 * Deterministic related products (TASK-038): replaces Woo's random-shuffle `wc_get_related_products()`.
 *
 * Tier 1 = products of the anchor's leaf categories, tier 2 = the rest of their parent categories (descendants included). Inside a tier:
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

	public function id(): string {
		return 'related';
	}

	public function candidates( Anchor $anchor ): array {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( ! $woo instanceof WooCommerce ) {
			return [];
		}

		$leaves = Pool::leaves( Pool::categories( $anchor->id ) );
		if ( array() === $leaves ) {
			return [];
		}
		$parents = [];
		foreach ( $leaves as $leaf ) {
			$term = get_term( $leaf, 'product_cat' );
			if ( $term instanceof \WP_Term && $term->parent > 0 ) {
				$parents[] = (int) $term->parent;
			}
		}

		$tier1 = array_values( array_diff( Pool::ids_in_terms( $leaves ), [ $anchor->id ] ) );
		$tier2 = array_values( array_diff( Pool::ids_in_terms( array_unique( $parents ) ), [ $anchor->id ], $tier1 ) );
		$all   = array_merge( $tier1, $tier2 );
		if ( array() === $all ) {
			return [];
		}

		$taxonomies = array_merge(
			[ 'product_tag' ],
			array_values( array_filter( get_object_taxonomies( 'product' ), static fn( string $tax ): bool => str_starts_with( $tax, 'pa_' ) ) )
		);
		$terms      = $woo->lookup()->term_facts( array_merge( [ $anchor->id ], $all ), $taxonomies );
		$mine       = $terms[ $anchor->id ] ?? [];

		$out = [];
		foreach ( [
			1 => $tier1,
			2 => $tier2,
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
						'tier' => $tier,
						'tags' => $tags,
						'attr' => $attrs,
						'key'  => Pool::spread_key( $anchor->id, $id ),
					],
				];
			}
			usort(
				$rows,
				static fn( array $a, array $b ): int => [ -$a['meta']['tags'], -$a['meta']['attr'], $a['meta']['key'], $a['id'] ] <=> [ -$b['meta']['tags'], -$b['meta']['attr'], $b['meta']['key'], $b['id'] ]
			);
			$out = array_merge( $out, $rows );
		}
		return $out;
	}
}
