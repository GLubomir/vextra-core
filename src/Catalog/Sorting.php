<?php
/**
 * Sort options per catalog context and the effective order key (TASK-043b, screen s1.11).
 *
 * Options carry machine keys only (labels are presentation, the theme translates them). `value` is the `orderby` query value,
 * '' = the context default (param omitted). Contexts:
 * - category / shop: default (menu order, in stock first, popularity), popularity, date, price, price-desc, rating.
 * - search: first option `relevance` (default, WordPress relevance), then the five above.
 * - sale: first option `discount` (default, only while `_vx_discount_pct` exists), then the five above; without the meta the sale
 *   default is the normal default order and the discount option is not offered.
 * Out-of-stock products are last in the default order only.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

final class Sorting {

	/**
	 * Options of a context: list of [ 'key' => label key, 'value' => orderby value ('' = default), 'default' => bool ].
	 *
	 * @param string $context category|shop|search|sale.
	 * @return array<int,array{key:string,value:string,default:bool}>
	 */
	public static function options( string $context ): array {
		$first = match ( $context ) {
			'search' => [ 'relevance', true ],
			'sale'   => DiscountMeta::ready() ? [ 'discount', true ] : [ 'default', true ],
			default  => [ 'default', true ],
		};

		$out = [
			[
				'key'     => $first[0],
				'value'   => '',
				'default' => true,
			],
		];
		if ( 'sale' === $context && ! DiscountMeta::ready() ) {
			$out[0]['key'] = 'default';
		}
		foreach ( Params::SORTS as $sort ) {
			$out[] = [
				'key'     => $sort,
				'value'   => $sort,
				'default' => false,
			];
		}
		return $out;
	}

	/**
	 * The order key the SQL layer applies.
	 *
	 * @param string $context category|shop|search|sale.
	 * @param string $orderby Normalised `orderby` ('' = default).
	 */
	public static function order_key( string $context, string $orderby ): string {
		if ( '' !== $orderby && in_array( $orderby, Params::SORTS, true ) ) {
			return $orderby;
		}
		return match ( $context ) {
			'search' => 'relevance',
			'sale'   => DiscountMeta::ready() ? 'discount' : 'default',
			default  => 'default',
		};
	}
}
