<?php
/**
 * Recount of the product review counters from APPROVED reviews only (TASK-044a fix round 1, SEO MAJOR: stored `_wc_review_count` / rating
 * meta without a single approved review comment made Rank Math print an AggregateRating and the page claim reviews that do not exist).
 *
 * Uses Woo's own helpers through the WooCommerce adapter, for every product including the RU twins (reviews are never shared between twins).
 * INTEGRITY RULE: nothing but this recount and Woo's comment hooks may write rating meta; the supplier import (E7) must never write
 * `_wc_review_count`, `_wc_average_rating` or `_wc_rating_count`.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Reviews;

use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;

final class Recount {

	/**
	 * Recompute the counters of every product.
	 *
	 * @param bool $dry Only report.
	 * @return array{checked:int,changed:array<int,array{id:int,before:string,after:string}>}
	 */
	public static function run( bool $dry ): array {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( ! $woo instanceof WooCommerce ) {
			return [
				'checked' => 0,
				'changed' => [],
			];
		}
		$ids     = get_posts(
			[
				'post_type'        => 'product',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'lang'             => '', // Polylang: both languages.
			]
		);
		$changed = [];
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$counters = $woo->review_counters( $id );
			if ( null === $counters || $counters['stored'] == $counters['fresh'] ) { // phpcs:ignore Universal.Operators.StrictComparisons -- array equality on scalars of the same shape.
				continue;
			}
			$changed[] = [
				'id'     => $id,
				'before' => $counters['stored']['count'] . ' / ' . $counters['stored']['avg'],
				'after'  => $counters['fresh']['count'] . ' / ' . $counters['fresh']['avg'],
			];
			if ( ! $dry ) {
				$woo->recount_reviews( $id );
			}
		}
		return [
			'checked' => count( $ids ),
			'changed' => $changed,
		];
	}
}
