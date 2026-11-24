<?php
/**
 * Accessory rules store (TASK-043b, ADR-010 E4 slice): term meta `_vx_accessory_terms` on `product_cat` = list of target category ids
 * (default-language side, at most 8, admin order). The language twin resolves them through the Polylang term pair (no second admin
 * entry). Consumed by `Recommend\Source\Accessories` (TASK-038); a change of the term meta fires `vextra/catalog/accessory_rules_changed`.
 *
 * `for_term( $term_id )` answers `[ [ term_id, name, url, count, thumb_id ], ... ]` in the current language: only non-empty targets
 * (hierarchical `Seo\TermCounts`), admin order, at most 8, empty rule = []. Cached per language in the `catalog-terms` group by
 * `TermData`, flushed on term and product changes.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

use Vextra\Core\Seo\TermCounts;

final class AccessoryRules {

	public const META = '_vx_accessory_terms';

	public const MAX = 8;

	/**
	 * Fire `vextra/catalog/accessory_rules_changed` when the rule meta of a term is added, updated or deleted (core term meta hooks).
	 */
	public static function register(): void {
		foreach ( [ 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ] as $hook ) {
			add_action( $hook, [ self::class, 'on_meta' ], 10, 4 );
		}
	}

	/**
	 * Term meta changed.
	 *
	 * @param mixed $meta_id  Meta id(s).
	 * @param mixed $term_id  Term id.
	 * @param mixed $meta_key Meta key.
	 */
	public static function on_meta( $meta_id = 0, $term_id = 0, $meta_key = '' ): void {
		unset( $meta_id );
		if ( self::META === $meta_key ) {
			/**
			 * An accessory rule changed (cache invalidation trigger).
			 *
			 * @param int $term_id Source term id.
			 */
			do_action( 'vextra/catalog/accessory_rules_changed', absint( $term_id ) );
		}
	}

	/**
	 * Own target ids of a source term (admin field), validated: existing `product_cat` terms, not the term itself, unique, max 8.
	 *
	 * @param int $term_id Source term id.
	 * @return int[]
	 */
	public static function own( int $term_id ): array {
		return self::clean( get_term_meta( $term_id, self::META, true ), $term_id );
	}

	/**
	 * Validate a raw id list.
	 *
	 * @param mixed $raw     Raw value.
	 * @param int   $exclude Term id that cannot be its own accessory.
	 * @return int[]
	 */
	public static function clean( $raw, int $exclude = 0 ): array {
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $id ) {
			$id = absint( $id );
			if ( $id > 0 && $id !== $exclude && ! in_array( $id, $out, true ) && get_term( $id, 'product_cat' ) instanceof \WP_Term ) {
				$out[] = $id;
			}
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Accessory categories of a term in the current language.
	 *
	 * @param int        $term_id Term id (any language).
	 * @param TermCounts $counts  Hierarchical counts.
	 * @param string     $lang    Language slug ('' = the current request language; a background rebuild passes its cache scope language).
	 * @return array<int,array{term_id:int,name:string,url:string,count:int,thumb_id:int}>
	 */
	public static function for_term( int $term_id, TermCounts $counts, string $lang = '' ): array {
		$out = [];
		foreach ( self::own( TermPair::source( $term_id ) ) as $target ) {
			$id = TermPair::in_language( $target, $lang );
			if ( $id <= 0 || $counts->count( $id ) <= 0 ) {
				continue;
			}
			$term = get_term( $id, 'product_cat' );
			$url  = $term instanceof \WP_Term ? get_term_link( $term ) : '';
			if ( ! $term instanceof \WP_Term || ! is_string( $url ) ) {
				continue;
			}
			$out[] = [
				'term_id'  => $id,
				'name'     => $term->name,
				'url'      => $url,
				'count'    => $counts->count( $id ),
				'thumb_id' => (int) get_term_meta( $id, 'thumbnail_id', true ),
			];
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}
}
