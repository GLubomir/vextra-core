<?php
/**
 * Reviews view-model of the product page (TASK-044a): counts, average, histogram and the newest approved reviews (TASK-030 policy:
 * `PER_PAGE` = 50, all reviews on the product page, no pagination).
 *
 * - `count` / `avg` come from the Woo counters (`WC_Product::get_review_count()` / `get_average_rating()`), the very numbers Rank Math
 *   prints in the Product schema, so the visible and the structured numbers cannot differ; `histogram` is counted from the list when the list
 *   holds every review (fewer than the page size), else ONE grouped query on approved top-level reviews. No reviews = ZERO queries.
 * - `list`: newest 50 approved top-level reviews (one `get_comments()` query, its comment meta cache primed with one more), only
 *   `author` (display name), `date_iso`, `rating`, `text` (raw, escaped by the template). Never an e-mail, IP, user id or user agent.
 * - `can_review`: `buyer` (signed in, verified buyer or moderator, no review yet) | `login` (guest) | `none` (signed-in non-buyer or already
 *   reviewed). Read from `Reviews\Policy` (no new policy). Per-user: the only per-user field, empty for the anonymous cached HTML.
 * Reviews are never shared between the UA and the RU twin.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Product;

use Vextra\Core\Reviews\Config;
use Vextra\Core\Reviews\Policy;

final class ReviewsData {

	/**
	 * Reviews view-model of a product.
	 *
	 * @param int $product_id Product id.
	 * @return array{count:int,avg:float,histogram:array<int,int>,list:array<int,array<string,mixed>>,can_review:string,comments_open:bool}
	 */
	public static function get( int $product_id ): array {
		$out     = [
			'count'         => 0,
			'avg'           => 0.0,
			'histogram'     => [
				5 => 0,
				4 => 0,
				3 => 0,
				2 => 0,
				1 => 0,
			],
			'list'          => [],
			'can_review'    => self::can_review( $product_id ),
			'comments_open' => (bool) comments_open( $product_id ),
		];
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return $out;
		}
		$count = (int) $product->get_review_count();
		if ( $count <= 0 ) {
			return $out;
		}
		$out['count'] = $count;
		$out['avg']   = round( (float) $product->get_average_rating(), 1 );
		$out['list']  = self::newest( $product_id );
		// Every review is in the list when it holds fewer than the page size: count the stars in PHP (no grouped query).
		$out['histogram'] = count( $out['list'] ) < max( 1, Config::get( 'per_page' ) ) ? self::stars_of( $out['list'] ) : self::histogram( $product_id );
		return $out;
	}

	/**
	 * Histogram of a complete review list.
	 *
	 * @param array<int,array<string,mixed>> $reviews Reviews.
	 * @return array<int,int> Stars 5..1 => count.
	 */
	private static function stars_of( array $reviews ): array {
		$out = [
			5 => 0,
			4 => 0,
			3 => 0,
			2 => 0,
			1 => 0,
		];
		foreach ( $reviews as $review ) {
			$stars = (int) $review['rating'];
			if ( isset( $out[ $stars ] ) ) {
				++$out[ $stars ];
			}
		}
		return $out;
	}

	/**
	 * Rating histogram, one grouped query (products with a full page of reviews or more).
	 *
	 * @param int $product_id Product id.
	 * @return array<int,int> Stars 5..1 => count.
	 */
	private static function histogram( int $product_id ): array {
		global $wpdb;
		$out  = [
			5 => 0,
			4 => 0,
			3 => 0,
			2 => 0,
			1 => 0,
		];
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one grouped read of the histogram, not cacheable cheaper than the query.
			$wpdb->prepare(
				"SELECT m.meta_value AS stars, COUNT(*) AS total FROM {$wpdb->comments} c
				INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'rating'
				WHERE c.comment_post_ID = %d AND c.comment_approved = '1' AND c.comment_parent = 0 AND c.comment_type IN ( 'review', 'comment' )
				GROUP BY m.meta_value",
				$product_id
			),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$stars = (int) $row['stars'];
			if ( isset( $out[ $stars ] ) ) {
				$out[ $stars ] += (int) $row['total'];
			}
		}
		return $out;
	}

	/**
	 * Newest approved reviews.
	 *
	 * @param int $product_id Product id.
	 * @return array<int,array{author:string,date_iso:string,rating:int,text:string}>
	 */
	private static function newest( int $product_id ): array {
		$comments = get_comments(
			[
				'post_id'  => $product_id,
				'status'   => 'approve',
				'parent'   => 0,
				'number'   => max( 1, Config::get( 'per_page' ) ),
				'orderby'  => 'comment_date_gmt',
				'order'    => 'DESC',
				'type__in' => [ 'review', 'comment' ], // WooCommerce hides type `review` from queries without an explicit type.
			]
		);
		$out      = [];
		foreach ( is_array( $comments ) ? $comments : [] as $comment ) {
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}
			$out[] = [
				'author'   => (string) $comment->comment_author,
				'date_iso' => gmdate( 'c', (int) strtotime( (string) $comment->comment_date_gmt . ' UTC' ) ),
				'rating'   => (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true ),
				'text'     => (string) $comment->comment_content,
			];
		}
		return $out;
	}

	/**
	 * Who may write a review (per the existing policy).
	 *
	 * @param int $product_id Product id.
	 */
	private static function can_review( int $product_id ): string {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return 'login';
		}
		return true === Policy::validate_access( $product_id, $user_id ) ? 'buyer' : 'none';
	}
}
