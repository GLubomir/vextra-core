<?php
/**
 * Slug -> term_taxonomy_id map of the filterable taxonomies (TASK-043b): brands, attributes (`pa_*`) and product categories.
 *
 * ONE cache entry (group `catalog-terms`, flushed with the rest of the catalog data) answers three questions with a single read:
 * does this slug exist (unknown filter values are dropped before they reach a query), which taxonomy / slug pairs are valid, and which
 * `term_taxonomy_id`s a slug stands for (a category = itself + descendants). Querying by `term_taxonomy_id` keeps WordPress from
 * resolving every slug with two extra queries per filter on each filtered request.
 * Slugs are unique per language in this store (the RU twin slug differs), so one map serves both languages.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

use Vextra\Core\Support\Cache;

final class TermMap {

	/**
	 * Per-request memo.
	 *
	 * @var array<string,array<string,int[]>>|null
	 */
	private static ?array $memo = null;

	/**
	 * The map: taxonomy => slug => term_taxonomy_ids (categories include the descendants).
	 *
	 * @return array<string,array<string,int[]>>
	 */
	public static function get(): array {
		if ( null === self::$memo ) {
			$map        = Cache::remember( TermData::GROUP, 'map:v1', HOUR_IN_SECONDS, static fn(): array => self::build() );
			self::$memo = is_array( $map ) ? $map : self::build();
		}
		return self::$memo;
	}

	/**
	 * Forget the per-request memo (tests).
	 */
	public static function reset(): void {
		self::$memo = null;
	}

	/**
	 * Every slug of a taxonomy, null when the taxonomy is not a filterable one.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return string[]|null
	 */
	public static function slugs( string $taxonomy ): ?array {
		$map = self::get();
		return isset( $map[ $taxonomy ] ) ? array_map( 'strval', array_keys( $map[ $taxonomy ] ) ) : null;
	}

	/**
	 * Taxonomy-relationship ids (term_taxonomy_id) of slugs; unknown slugs are skipped.
	 *
	 * @param string   $taxonomy Taxonomy name.
	 * @param string[] $slugs    Slugs.
	 * @return int[]
	 */
	public static function tt_ids( string $taxonomy, array $slugs ): array {
		$map = self::get()[ $taxonomy ] ?? [];
		$out = [];
		foreach ( $slugs as $slug ) {
			foreach ( $map[ $slug ] ?? [] as $id ) {
				$out[ (int) $id ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Cold build.
	 *
	 * @return array<string,array<string,int[]>>
	 */
	private static function build(): array {
		$taxonomies = [ 'product_cat' ];
		if ( taxonomy_exists( 'product_brand' ) ) {
			$taxonomies[] = 'product_brand';
		}
		$taxonomies = array_merge( $taxonomies, array_keys( FilterAttributes::all() ) );

		$terms = get_terms(
			[
				'taxonomy'               => $taxonomies,
				'hide_empty'             => false,
				'lang'                   => '',
				'update_term_meta_cache' => false,
			]
		);

		$out     = [];
		$parents = [];
		$tt_of   = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$out[ $term->taxonomy ][ $term->slug ] = [ (int) $term->term_taxonomy_id ];
			if ( 'product_cat' === $term->taxonomy ) {
				$parents[ (int) $term->term_id ] = (int) $term->parent;
				$tt_of[ (int) $term->term_id ]   = (int) $term->term_taxonomy_id;
			}
		}
		foreach ( $taxonomies as $taxonomy ) {
			$out[ $taxonomy ] = $out[ $taxonomy ] ?? [];
		}

		// Categories stand for themselves + every descendant.
		$by_slug = [];
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term && 'product_cat' === $term->taxonomy ) {
				$by_slug[ (int) $term->term_id ] = $term->slug;
			}
		}
		foreach ( $parents as $id => $parent ) {
			$guard = 0;
			while ( $parent > 0 && isset( $by_slug[ $parent ] ) && $guard++ < 12 ) {
				$out['product_cat'][ $by_slug[ $parent ] ][] = $tt_of[ $id ];
				$parent                                      = $parents[ $parent ] ?? 0;
			}
		}
		return $out;
	}
}
