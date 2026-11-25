<?php
/**
 * Public functions of the Orders module for the theme (TASK-047a). The theme wraps every call in `function_exists`.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

use Vextra\Core\Orders\TrackOrder;

if ( ! function_exists( 'vx_track_order_state' ) ) {
	/**
	 * State of the order-tracking page for this request.
	 *
	 * `state`: `form` (GET / first visit), `not_found` (any mismatch: one generic answer), `throttled` (HTTP 429 already sent), `challenge` (Turnstile failed,
	 * nothing looked up) or `result`. `order` is set for `result` only: `number`, `status` (key), `status_label`, `created`, `paid`, `completed`,
	 * `total_html`, `items[ { name, qty, thumb_id } ]`. No address, phone, name, e-mail, payment method, notes or SKU. `retry_after` = seconds for `throttled`.
	 *
	 * @return array{state:string,retry_after:int,order:array<string,mixed>|null}
	 */
	function vx_track_order_state(): array {
		return TrackOrder::state();
	}
}

if ( ! function_exists( 'vx_track_order_turnstile_slot' ) ) {
	/**
	 * Print the Turnstile slot of the tracking form (action `track`, `interaction-only`, zero height until Cloudflare needs a click). Nothing without keys.
	 */
	function vx_track_order_turnstile_slot(): void {
		TrackOrder::turnstile_slot();
	}
}
