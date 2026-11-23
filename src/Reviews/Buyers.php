<?php
/**
 * Verified-buyer product set per user (SEC-057, ADR-004): the product IDs (parents and variations) found in the user's COMPLETED
 * orders whose `customer_id` is the user (no billing-e-mail match). Built once, stored in user meta `_vx_reviews_bought`, so the
 * check on a product page is one array lookup (the user meta of the signed-in user is already in the object cache).
 *
 * Invalidation (the meta is deleted, the next check rebuilds it): order status change, trash / untrash / delete, refund, and any
 * order save that moves the order to another customer (old and new customer).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Reviews;

final class Buyers {

	private const META  = '_vx_reviews_bought';
	private const BATCH = 50;
	private const TTL   = 12 * HOUR_IN_SECONDS; // Safety net: rebuild even when no hook fired.

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_id' ], 10, 1 );
		add_action( 'woocommerce_before_delete_order', [ $this, 'on_order_id' ], 10, 1 );
		add_action( 'woocommerce_before_trash_order', [ $this, 'on_order_id' ], 10, 1 );
		add_action( 'woocommerce_untrash_order', [ $this, 'on_order_id' ], 10, 1 );
		add_action( 'woocommerce_order_refunded', [ $this, 'on_order_id' ], 10, 1 );
		add_action( 'woocommerce_before_order_object_save', [ $this, 'on_save' ], 10, 1 );
		add_action( 'woocommerce_saved_order_items', [ $this, 'on_order_id' ], 10, 1 );
		add_action( 'woocommerce_new_order_item', [ $this, 'on_item' ], 10, 3 );
		add_action( 'woocommerce_update_order_item', [ $this, 'on_item' ], 10, 3 );
		add_action( 'woocommerce_before_delete_order_item', [ $this, 'on_item' ], 10, 1 );
	}

	/**
	 * Does the user own the product (or a variation of it) through a completed order.
	 *
	 * @param int $user_id    User ID.
	 * @param int $product_id Product or variation ID.
	 */
	public static function has( int $user_id, int $product_id ): bool {
		if ( $user_id <= 0 || $product_id <= 0 ) {
			return false;
		}
		$stored = get_user_meta( $user_id, self::META, true );
		if ( ! is_array( $stored ) || ! isset( $stored['ids'], $stored['built_at'] ) || ! is_array( $stored['ids'] ) || (int) $stored['built_at'] < time() - self::TTL ) {
			$stored = [
				'ids'      => self::build( $user_id ),
				'built_at' => time(),
			];
			update_user_meta( $user_id, self::META, $stored );
		}
		return isset( $stored['ids'][ $product_id ] );
	}

	/**
	 * Forget the stored set of a user.
	 *
	 * @param int $user_id User ID.
	 */
	public static function forget( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::META );
		}
	}

	/**
	 * Any order hook that passes the order ID first.
	 *
	 * @param mixed $order_id Order ID.
	 */
	public function on_order_id( $order_id = 0 ): void {
		if ( ! function_exists( 'wc_get_order' ) || ! is_numeric( $order_id ) ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			self::forget( (int) $order->get_customer_id() );
		}
	}

	/**
	 * Order item added / changed / about to be deleted: the order's customer loses or gains products.
	 *
	 * @param mixed $item_id  Order item ID.
	 * @param mixed $item     Item object (unused).
	 * @param mixed $order_id Order ID (new / update hooks; the delete hook has none, it is resolved from the item).
	 */
	public function on_item( $item_id = 0, $item = null, $order_id = 0 ): void {
		unset( $item );
		if ( ! is_numeric( $order_id ) || (int) $order_id <= 0 ) {
			$order_id = is_numeric( $item_id ) && function_exists( 'wc_get_order_id_by_order_item_id' ) ? wc_get_order_id_by_order_item_id( (int) $item_id ) : 0;
		}
		$this->on_order_id( $order_id );
	}

	/**
	 * `woocommerce_before_order_object_save`: customer reassignment (the stored data still holds the old customer).
	 *
	 * @param mixed $order Order being saved.
	 */
	public function on_save( $order = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$changes = $order->get_changes();
		if ( isset( $changes['customer_id'] ) ) {
			self::forget( (int) ( $order->get_data()['customer_id'] ?? 0 ) );
			self::forget( (int) $changes['customer_id'] );
		}
	}

	/**
	 * Walk the completed orders of the user in batches.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,int> product / variation ID => 1.
	 */
	private static function build( int $user_id ): array {
		$set = [];
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
			return $set;
		}
		for ( $page = 1; $page <= 400; $page++ ) {
			$ids = wc_get_orders(
				[
					'customer_id' => $user_id,
					'status'      => [ 'completed' ],
					'limit'       => self::BATCH,
					'paged'       => $page,
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'return'      => 'ids',
				]
			);
			foreach ( (array) $ids as $id ) {
				$order = wc_get_order( (int) $id );
				if ( ! $order instanceof \WC_Order || (int) $order->get_customer_id() !== $user_id ) {
					continue;
				}
				foreach ( $order->get_items() as $item ) {
					if ( ! is_callable( [ $item, 'get_product_id' ] ) ) {
						continue;
					}
					$set[ (int) $item->get_product_id() ] = 1;
					if ( is_callable( [ $item, 'get_variation_id' ] ) && (int) $item->get_variation_id() > 0 ) {
						$set[ (int) $item->get_variation_id() ] = 1;
					}
				}
			}
			if ( count( (array) $ids ) < self::BATCH ) {
				break;
			}
		}
		unset( $set[0] );
		return $set;
	}
}
