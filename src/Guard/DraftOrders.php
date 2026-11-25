<?php
/**
 * Daily purge of abandoned `checkout-draft` orders (SEC-009: anonymous Store API traffic creates them without bound).
 *
 * Action Scheduler recurring action `vextra_guard_purge_drafts` (group `vextra-guard`), scheduled on activation or on the first
 * `init` after it is missing (the "scheduled" flag is an autoloaded option, so no query per request). Each run deletes at most
 * `draft_batch` orders older than `draft_age` seconds through `wc_get_orders()` + `WC_Order::delete()`; a full batch queues
 * one more async run, so the backlog drains in batches. No raw SQL. Deactivation unschedules the hook.
 * A draft without contact data (no billing e-mail, no customer id) is purged after `draft_age_anon` (6 h); one with contact data after `draft_age` (24 h).
 * The block checkout creates `checkout-draft` orders (status `wc-checkout-draft`), the same ones this job removes.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

use Vextra\Core\Support\Log;

final class DraftOrders {

	public const HOOK  = 'vextra_guard_purge_drafts';
	public const GROUP = 'vextra-guard';
	public const FLAG  = 'vx_guard_purge_scheduled';

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'init', [ __CLASS__, 'ensure_scheduled' ], 20 );
	}

	/**
	 * Schedule the recurring job once (idempotent). Called on activation and from `init`.
	 */
	public static function ensure_scheduled(): void {
		if ( '1' === (string) get_option( self::FLAG, '' ) ) {
			return;
		}
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		Failsafe::run(
			'draft-schedule',
			static function (): void {
				if ( ! as_has_scheduled_action( self::HOOK, [], self::GROUP ) ) {
					as_schedule_recurring_action( time() + 300, DAY_IN_SECONDS, self::HOOK, [], self::GROUP, true );
				}
				update_option( self::FLAG, '1', true );
			}
		);
	}

	/**
	 * Remove the schedule (deactivation). Safe when Action Scheduler is absent.
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], self::GROUP );
		}
		delete_option( self::FLAG );
	}

	/**
	 * Delete one batch of stale drafts.
	 *
	 * @return int Deleted orders.
	 */
	public function run(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$deleted = (int) Failsafe::run(
			'draft-purge',
			static function (): int {
				$batch = max( 1, Config::get( 'draft_batch' ) );

				/**
				 * Filters the minimum age (seconds) of a draft order before it is purged.
				 *
				 * @param int $age Seconds, default 24 h (drafts with a billing e-mail or a customer; contact-less drafts use `draft_age_anon`).
				 */
				$age = (int) apply_filters( 'vextra/core/guard/draft_age', Config::get( 'draft_age' ) );

				// TASK-045a: a draft WITHOUT contact data (no billing e-mail, no customer) is bot noise: shorter age (`draft_age_anon`, default 6 h).
				$age_anon = min( max( 60, $age ), max( 60, Config::get( 'draft_age_anon' ) ) );
				$age      = max( 60, $age );

				$orders = wc_get_orders(
					[
						'status'       => 'checkout-draft',
						'date_created' => '<' . ( time() - $age_anon ),
						'limit'        => $batch,
						'orderby'      => 'date',
						'order'        => 'ASC',
						'return'       => 'objects',
					]
				);

				$count = 0;
				foreach ( (array) $orders as $order ) {
					if ( ! $order instanceof \WC_Order || 'checkout-draft' !== $order->get_status() ) {
						continue;
					}
					$created     = $order->get_date_created();
					$has_contact = '' !== (string) $order->get_billing_email() || $order->get_customer_id() > 0;
					$limit       = $has_contact ? $age : $age_anon;
					if ( $created instanceof \WC_DateTime && $created->getTimestamp() > time() - $limit ) {
						continue; // Contact data present: keep until the longer age.
					}
					$order->delete( true );
					++$count;
				}

				if ( $count >= $batch && function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action( self::HOOK, [], self::GROUP, true );
				}
				if ( $count > 0 ) {
					Log::info( 'Purged draft orders: ' . $count, [], 'guard' );
				}
				return $count;
			},
			0
		);

		return $deleted;
	}
}
