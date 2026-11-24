<?php
/**
 * Facet engine (TASK-043b): disjunctive counts for the filter groups of a catalog scope, computed from two fact sets
 * (`Integrations\WooCommerce\Lookup` rows) in PHP.
 *
 * Pure: no queries, no cache, no globals (testable against an oracle). A product counts toward a group when it passes EVERY ACTIVE
 * filter except that group's own (so the group shows what OR-ing adds); a product failing exactly one filter counts only toward that
 * filter's group, a product failing none counts toward all of them and toward `found`. Semantics mirror `Lookup::apply_clauses()` and
 * the tax queries of `Query` (price = range overlap, rating = rounded average, OR inside a group, AND across groups).
 *
 * Groups (key, type): `cat` radio (search / sale only), `price` range, `stock` list, `brand` list, `attr:<slug>` list, `rating` rating,
 * `on_sale` list (one option), `discount` radio (sale only). A group is rendered only with >= 2 options of count > 0 or a selection
 * (`on_sale` / `discount`: >= 1; `price`: max > min). Zero-count options are dropped, selected ones kept. Labels of core options are
 * left empty with a `key` (the theme translates them); term options carry the term name.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

final class Facets {

	/**
	 * Rating presets: key (minimum) => list value.
	 */
	public const RATING_PRESETS = [
		'4' => [ 4, 5 ],
		'3' => [ 3, 4, 5 ],
	];

	/**
	 * Discount thresholds (min percent).
	 */
	public const DISCOUNTS = [ 10, 20, 30 ];

	/**
	 * Options kept per group.
	 */
	private const MAX_OPTIONS = 40;

	/**
	 * Compute the groups and the filtered result size.
	 *
	 * @param array<int,array<string,mixed>> $facts Product facts: id => [ min, max, stock, rating, onsale, disc ].
	 * @param array<int,array<string,int[]>> $tfact Term assignments: id => [ taxonomy => term ids ].
	 * @param array<int,array<string,mixed>> $terms Term rows: id => [ slug, name, tax, parent ].
	 * @param array<string,mixed>            $state Normalised state.
	 * @param array<string,mixed>            $spec  attr_taxes (string[]), brand (bool), cat (bool), on_sale (bool), discount (bool), cat_parents (id => parent), hidden_cats (int[]), labels (taxonomy => label).
	 * @return array{found:int,groups:array<int,array<string,mixed>>,cats:array<int,array<string,mixed>>}
	 */
	public static function compute( array $facts, array $tfact, array $terms, array $state, array $spec ): array {
		$slug_ids = [];
		foreach ( $terms as $id => $term ) {
			$slug_ids[ (string) $term['tax'] ][ (string) $term['slug'] ] = (int) $id;
		}
		$pick = static function ( string $taxonomy, array $slugs ) use ( $slug_ids ): array {
			$ids = [];
			foreach ( $slugs as $slug ) {
				$ids[] = $slug_ids[ $taxonomy ][ $slug ] ?? 0; // 0 = a slug without term: the filter matches nothing.
			}
			return $ids;
		};

		$sel_brand = $pick( 'product_brand', (array) $state['brand'] );
		$sel_cat   = '' !== (string) $state['cat'] ? $pick( 'product_cat', [ (string) $state['cat'] ] ) : [];
		$sel_attr  = [];
		foreach ( (array) $state['attrs'] as $slug => $slugs ) {
			$sel_attr[ (string) $slug ] = $pick( 'pa_' . $slug, (array) $slugs );
		}

		$cat_parents = (array) ( $spec['cat_parents'] ?? [] );
		$attr_slugs  = array_values( array_unique( array_merge( array_map( static fn( string $t ): string => substr( $t, 3 ), (array) $spec['attr_taxes'] ), array_keys( $sel_attr ) ) ) );

		$found   = 0;
		$tally   = [];
		$min_all = null;
		$max_all = null;

		foreach ( $facts as $id => $p ) {
			$min_all = null === $min_all ? $p['min'] : min( $min_all, $p['min'] );
			$max_all = null === $max_all ? $p['max'] : max( $max_all, $p['max'] );
			$tf      = $tfact[ $id ] ?? [];

			// Fails of the active filters.
			$fails = [];
			if ( ( null !== $state['min_price'] && $p['max'] < $state['min_price'] ) || ( null !== $state['max_price'] && $p['min'] > $state['max_price'] ) ) {
				$fails[] = 'price';
			}
			if ( array() !== $state['stock'] && ! in_array( $p['stock'], (array) $state['stock'], true ) ) {
				$fails[] = 'stock';
			}
			if ( array() !== $state['brand'] && array() === array_intersect( $tf['product_brand'] ?? [], $sel_brand ) ) {
				$fails[] = 'brand';
			}
			foreach ( $sel_attr as $slug => $ids ) {
				if ( array() === array_intersect( $tf[ 'pa_' . $slug ] ?? [], $ids ) ) {
					$fails[] = 'attr:' . $slug;
				}
			}
			if ( array() !== $state['rating'] && ! in_array( $p['rating'], (array) $state['rating'], true ) ) {
				$fails[] = 'rating';
			}
			if ( ! empty( $state['on_sale'] ) && ! $p['onsale'] ) {
				$fails[] = 'on_sale';
			}
			$cats = [];
			if ( ! empty( $spec['cat'] ) ) {
				foreach ( $tf['product_cat'] ?? [] as $cat_id ) {
					for ( $guard = 0; $cat_id > 0 && $guard < 12; ++$guard ) {
						$cats[ $cat_id ] = true;
						$cat_id          = (int) ( $cat_parents[ $cat_id ] ?? 0 );
					}
				}
				if ( array() !== $sel_cat && ! isset( $cats[ $sel_cat[0] ] ) ) {
					$fails[] = 'cat';
				}
			}
			if ( (int) $state['discount'] > 0 && $p['disc'] < (int) $state['discount'] ) {
				$fails[] = 'discount';
			}

			$n = count( $fails );
			if ( $n > 1 ) {
				continue;
			}
			if ( 0 === $n ) {
				++$found;
			}
			$only = 1 === $n ? $fails[0] : null;

			$keys = [
				'stock'    => [ (string) $p['stock'] ],
				'brand'    => $spec['brand'] ? array_map( 'strval', $tf['product_brand'] ?? [] ) : [],
				'rating'   => [],
				'on_sale'  => $p['onsale'] ? [ '1' ] : [],
				'cat'      => array_map( 'strval', array_keys( $cats ) ),
				'discount' => [],
			];
			foreach ( self::RATING_PRESETS as $min => $set ) {
				if ( $p['rating'] >= (int) $min ) {
					$keys['rating'][] = (string) $min;
				}
			}
			foreach ( self::DISCOUNTS as $pct ) {
				if ( $p['disc'] >= $pct ) {
					$keys['discount'][] = (string) $pct;
				}
			}
			foreach ( $attr_slugs as $slug ) {
				$keys[ 'attr:' . $slug ] = array_map( 'strval', $tf[ 'pa_' . $slug ] ?? [] );
			}

			foreach ( $keys as $group => $values ) {
				if ( null !== $only && $only !== $group ) {
					continue;
				}
				foreach ( $values as $value ) {
					$tally[ $group ][ $value ] = ( $tally[ $group ][ $value ] ?? 0 ) + 1;
				}
			}
		}

		return [
			'found'  => $found,
			'groups' => self::groups( $tally, $terms, $state, $spec, $min_all, $max_all, $attr_slugs ),
			'cats'   => self::cats( $tally['cat'] ?? [], $terms, (array) ( $spec['hidden_cats'] ?? [] ) ),
		];
	}

	/**
	 * Assemble the groups from the tally.
	 *
	 * @param array<string,array<string,int>> $tally      group => value => count.
	 * @param array<int,array<string,mixed>>  $terms      Term rows.
	 * @param array<string,mixed>             $state      State.
	 * @param array<string,mixed>             $spec       Spec.
	 * @param float|null                      $min_all    Scope minimum price.
	 * @param float|null                      $max_all    Scope maximum price.
	 * @param string[]                        $attr_slugs Attribute slugs with a predicate.
	 * @return array<int,array<string,mixed>>
	 */
	private static function groups( array $tally, array $terms, array $state, array $spec, ?float $min_all, ?float $max_all, array $attr_slugs ): array {
		$groups = [];

		if ( ! empty( $spec['cat'] ) ) {
			$options = [];
			foreach ( self::cats( $tally['cat'] ?? [], $terms, (array) ( $spec['hidden_cats'] ?? [] ), true ) as $row ) {
				$row['selected'] = $row['value'] === (string) $state['cat'];
				$options[]       = $row;
			}
			if ( '' !== (string) $state['cat'] && array() === array_filter( $options, static fn( array $o ): bool => ! empty( $o['selected'] ) ) ) {
				$options[] = [
					'value'    => (string) $state['cat'],
					'key'      => '',
					'label'    => self::label_of( (string) $state['cat'], $terms, 'product_cat' ),
					'count'    => 0,
					'selected' => true,
					'parent'   => 0,
				];
			}
			$groups[] = self::group( 'cat', 'radio', 'product_cat', $options, '' !== (string) $state['cat'], 2 );
		}

		if ( null !== $min_all && null !== $max_all ) {
			$low  = (int) floor( $min_all );
			$high = (int) ceil( $max_all );
			if ( $high > $low || null !== $state['min_price'] || null !== $state['max_price'] ) {
				$groups[] = [
					'key'      => 'price',
					'type'     => 'range',
					'param'    => 'min_price',
					'taxonomy' => '',
					'title'    => '',
					'options'  => [],
					'range'    => [
						'min'          => $low,
						'max'          => $high,
						'selected_min' => $state['min_price'],
						'selected_max' => $state['max_price'],
					],
					'selected' => null !== $state['min_price'] || null !== $state['max_price'],
				];
			}
		}

		$options = [];
		foreach ( [ 'instock', 'onbackorder' ] as $stock ) {
			$options[] = [
				'value'    => $stock,
				'key'      => $stock,
				'label'    => '',
				'count'    => (int) ( $tally['stock'][ $stock ] ?? 0 ),
				'selected' => in_array( $stock, (array) $state['stock'], true ),
			];
		}
		$groups[] = self::group( 'stock', 'list', '', $options, array() !== $state['stock'], 2, 'filter_stock_status' );

		if ( ! empty( $spec['brand'] ) ) {
			$groups[] = self::group( 'brand', 'list', 'product_brand', self::term_options( $tally['brand'] ?? [], $terms, 'product_brand', (array) $state['brand'] ), array() !== $state['brand'], 2, 'filter_brand' );
		}

		foreach ( $attr_slugs as $slug ) {
			$taxonomy = 'pa_' . $slug;
			$selected = (array) ( $state['attrs'][ $slug ] ?? [] );
			$group    = self::group( 'attr:' . $slug, 'list', $taxonomy, self::term_options( $tally[ 'attr:' . $slug ] ?? [], $terms, $taxonomy, $selected ), array() !== $selected, 2, 'filter_' . $slug );
			if ( array() !== $group ) {
				$group['title'] = (string) ( $spec['labels'][ $taxonomy ] ?? $slug );
			}
			$groups[] = $group;
		}

		$options = [];
		foreach ( self::RATING_PRESETS as $min => $set ) {
			$options[] = [
				'value'    => implode( ',', $set ),
				'key'      => (string) $min,
				'label'    => '',
				'count'    => (int) ( $tally['rating'][ $min ] ?? 0 ),
				'selected' => (array) $state['rating'] === $set,
			];
		}
		$rating = self::group( 'rating', 'rating', '', $options, array() !== $state['rating'], 2, 'rating_filter' );
		if ( array() !== $rating ) {
			$rating['selected_set'] = (array) $state['rating'];
		}
		$groups[] = $rating;

		if ( ! empty( $spec['on_sale'] ) ) {
			$groups[] = self::group(
				'on_sale',
				'list',
				'',
				[
					[
						'value'    => '1',
						'key'      => 'on_sale',
						'label'    => '',
						'count'    => (int) ( $tally['on_sale']['1'] ?? 0 ),
						'selected' => ! empty( $state['on_sale'] ),
					],
				],
				! empty( $state['on_sale'] ),
				1,
				'filter_on_sale'
			);
		}

		if ( ! empty( $spec['discount'] ) ) {
			$options = [];
			foreach ( self::DISCOUNTS as $pct ) {
				$options[] = [
					'value'    => (string) $pct,
					'key'      => 'discount-' . $pct,
					'label'    => '',
					'count'    => (int) ( $tally['discount'][ (string) $pct ] ?? 0 ),
					'selected' => (int) $state['discount'] === $pct,
				];
			}
			$groups[] = self::group( 'discount', 'radio', '', $options, (int) $state['discount'] > 0, 1, 'filter_discount' );
		}

		return array_values( array_filter( $groups ) );
	}

	/**
	 * One list-like group, or [] when it must not render (fewer than `$min_options` non-zero options and no selection).
	 *
	 * @param string                         $key         Group key.
	 * @param string                         $type        list|radio|rating.
	 * @param string                         $taxonomy    Taxonomy of the options ('' for core groups).
	 * @param array<int,array<string,mixed>> $options     Options (zero counts allowed).
	 * @param bool                           $has_select  Any selection in the group.
	 * @param int                            $min_options Non-zero options needed to render.
	 * @param string                         $param       Query param of the group.
	 * @return array<string,mixed>
	 */
	private static function group( string $key, string $type, string $taxonomy, array $options, bool $has_select, int $min_options, string $param = 'product_cat' ): array {
		$kept = array_values(
			array_filter(
				$options,
				static fn( array $o ): bool => $o['count'] > 0 || ! empty( $o['selected'] )
			)
		);
		$live = count( array_filter( $kept, static fn( array $o ): bool => $o['count'] > 0 ) );
		if ( $live < $min_options && ! $has_select ) {
			return [];
		}
		return [
			'key'      => $key,
			'type'     => $type,
			'param'    => $param,
			'taxonomy' => $taxonomy,
			'title'    => '',
			'options'  => array_slice( $kept, 0, self::MAX_OPTIONS ),
			'selected' => $has_select,
		];
	}

	/**
	 * Options of a term taxonomy: term ids with a count, selected slugs kept at count 0, sorted by count desc then name.
	 *
	 * @param array<string,int>              $counts   term id => count.
	 * @param array<int,array<string,mixed>> $terms    Term rows.
	 * @param string                         $taxonomy Taxonomy.
	 * @param string[]                       $selected Selected slugs.
	 * @return array<int,array<string,mixed>>
	 */
	private static function term_options( array $counts, array $terms, string $taxonomy, array $selected ): array {
		$options = [];
		$seen    = [];
		foreach ( $counts as $id => $count ) {
			$term = $terms[ (int) $id ] ?? null;
			if ( null === $term || $taxonomy !== $term['tax'] ) {
				continue;
			}
			$seen[ (string) $term['slug'] ] = true;
			$options[]                      = [
				'value'    => (string) $term['slug'],
				'key'      => '',
				'label'    => (string) $term['name'],
				'count'    => (int) $count,
				'selected' => in_array( $term['slug'], $selected, true ),
			];
		}
		foreach ( $selected as $slug ) {
			if ( ! isset( $seen[ $slug ] ) ) {
				$options[] = [
					'value'    => $slug,
					'key'      => '',
					'label'    => self::label_of( $slug, $terms, $taxonomy ),
					'count'    => 0,
					'selected' => true,
				];
			}
		}
		usort(
			$options,
			static fn( array $a, array $b ): int => [ $b['count'], $a['label'] ] <=> [ $a['count'], $b['label'] ]
		);
		return $options;
	}

	/**
	 * Name of a term by slug (falls back to the slug).
	 *
	 * @param string                         $slug     Slug.
	 * @param array<int,array<string,mixed>> $terms    Term rows.
	 * @param string                         $taxonomy Taxonomy.
	 */
	private static function label_of( string $slug, array $terms, string $taxonomy ): string {
		foreach ( $terms as $term ) {
			if ( $taxonomy === $term['tax'] && $slug === $term['slug'] ) {
				return (string) $term['name'];
			}
		}
		return $slug;
	}

	/**
	 * Category options / pills (search + sale): categories with a count (hierarchical), default category hidden, count desc then name.
	 *
	 * @param array<string,int>              $counts term id => count.
	 * @param array<int,array<string,mixed>> $terms  Term rows.
	 * @param int[]                          $hidden Term ids never offered.
	 * @param bool                           $all    Return every option (group) instead of the top 8 (pills).
	 * @return array<int,array<string,mixed>>
	 */
	private static function cats( array $counts, array $terms, array $hidden, bool $all = false ): array {
		$rows = [];
		foreach ( $counts as $id => $count ) {
			$term = $terms[ (int) $id ] ?? null;
			if ( null === $term || 'product_cat' !== $term['tax'] || in_array( (int) $id, $hidden, true ) || (int) $count <= 0 ) {
				continue;
			}
			$rows[] = [
				'value'    => (string) $term['slug'],
				'key'      => '',
				'label'    => (string) $term['name'],
				'count'    => (int) $count,
				'selected' => false,
				'id'       => (int) $id,
				'parent'   => (int) $term['parent'],
			];
		}
		usort( $rows, static fn( array $a, array $b ): int => [ $b['count'], $a['label'] ] <=> [ $a['count'], $b['label'] ] );
		return $all ? array_slice( $rows, 0, self::MAX_OPTIONS ) : array_slice( $rows, 0, 8 );
	}
}
