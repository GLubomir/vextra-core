<?php
/**
 * Guest order tracking (TASK-047a): the lookup behind `[vx_track_order]`.
 *
 * Contract: on `template_redirect` of the tracking page (the UA page `track-order` or any Polylang twin, found by id, never by URL text) the handler
 * runs once, sends `Cache-Control: private, no-store`, `X-Robots-Tag: noindex, follow` and answers a POST. The theme reads the verdict through
 * `vx_track_order_state()` (`state` + minimal `order`).
 *
 * Order of steps on a POST with both fields (nothing is written to the database, nothing goes to the URL, no redirect):
 * 1. client-IP bucket `track` (`track_limit` / `track_window`): over = state `throttled`, HTTP 429 + `Retry-After`;
 * 2. format: order = digits (optional `#`, <= 20), e-mail valid <= 100: anything else = `not_found`;
 * 3. Turnstile action `track` (keys set): failure = state `challenge`, never a lookup, reveals nothing about the order;
 * 4. per-order bucket (HMAC of the number, across all IPs, `track_order_limit` / `track_order_window`): over = `throttled`. Counted AFTER Turnstile,
 *    so a client without a token cannot lock another person's order number (Turnstile OFF: documented limitation, as for the checkout e-mail bucket);
 * 5. lookup: `wc_get_order()`; a real `shop_order` (not refund / draft / trash) whose billing e-mail equals the input (`hash_equals`, lower case).
 *    Every other outcome is ONE `not_found` (a dummy compare keeps the timing flat). No PII is logged (reasons only).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Orders;

use Vextra\Core\Guard\ClientIp;
use Vextra\Core\Guard\Config;
use Vextra\Core\Guard\Exempt;
use Vextra\Core\Guard\Failsafe;
use Vextra\Core\Guard\RateLimiter;
use Vextra\Core\Guard\Responder;
use Vextra\Core\Integrations\Turnstile\Turnstile;
use Vextra\Core\Plugin;
use Vextra\Core\Seo\LaunchPages;
use Vextra\Core\Support\Log;

final class TrackOrder {

	public const FIELD_ORDER = 'vx_track_order';
	public const FIELD_EMAIL = 'vx_track_email';

	/**
	 * Verdict of this request.
	 *
	 * @var array{state:string,retry_after:int,order:array<string,mixed>|null}
	 */
	private static array $state = [
		'state'       => 'form',
		'retry_after' => 0,
		'order'       => null,
	];

	/**
	 * Index gate (page identification), null = slug fallback.
	 *
	 * @var LaunchPages|null
	 */
	private ?LaunchPages $pages;

	public function __construct( ?LaunchPages $pages ) {
		$this->pages = $pages;
	}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'handle' ], 5 );
	}

	/**
	 * Verdict for the theme.
	 *
	 * @return array{state:string,retry_after:int,order:array<string,mixed>|null}
	 */
	public static function state(): array {
		return self::$state;
	}

	/**
	 * Turnstile slot of the form.
	 */
	public static function turnstile_slot(): void {
		$ts = Plugin::instance()->integration( 'turnstile' );
		if ( $ts instanceof Turnstile && ! Exempt::is_owner_bot() ) {
			$ts->render_widget(
				'track',
				[
					'appearance' => 'interaction-only',
					'reserve'    => false,
					'class'      => 'vx-ts',
				]
			);
		}
	}

	/**
	 * Is the main query the tracking page (UA original or a twin)?
	 */
	private function is_track_page(): bool {
		if ( ! is_page() ) {
			return false;
		}
		$id = (int) get_queried_object_id();
		if ( null !== $this->pages ) {
			return $this->pages->is_track_page( $id );
		}
		return LaunchPages::TRACK_SLUG === (string) get_post_field( 'post_name', $id );
	}

	/**
	 * `template_redirect`: headers + the POST.
	 */
	public function handle(): void {
		if ( ! $this->is_track_page() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex, follow' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- a read-only lookup: no state is changed; Turnstile + rate limits are the protection (docs).
		$number = isset( $_POST[ self::FIELD_ORDER ] ) && is_string( $_POST[ self::FIELD_ORDER ] ) ? substr( trim( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_ORDER ] ) ) ), 0, 40 ) : null;
		$email  = isset( $_POST[ self::FIELD_EMAIL ] ) && is_string( $_POST[ self::FIELD_EMAIL ] ) ? substr( trim( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_EMAIL ] ) ) ), 0, 200 ) : null;
		$token  = isset( $_POST[ Turnstile::FIELD ] ) && is_string( $_POST[ Turnstile::FIELD ] ) ? substr( sanitize_text_field( wp_unslash( $_POST[ Turnstile::FIELD ] ) ), 0, 2100 ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( 'POST' !== $method || null === $number || null === $email ) {
			return;
		}

		try {
			$this->lookup( $number, $email, $token );
		} catch ( \Throwable $e ) {
			Log::warning( 'Track order failed: ' . get_class( $e ), [], 'orders' );
			$this->set( 'not_found' );
		}
	}

	/**
	 * The lookup steps.
	 *
	 * @param string $raw_number Posted order number (sanitised, capped).
	 * @param string $raw_email  Posted e-mail (sanitised, capped).
	 * @param string $token      Turnstile token.
	 */
	private function lookup( string $raw_number, string $raw_email, string $token ): void {
		$ip = RateLimiter::hit( 'track', ClientIp::get(), Config::get( 'track_limit' ), Config::get( 'track_window' ) );
		if ( ! $ip['allowed'] ) {
			$this->throttle( $ip['retry_after'] );
			return;
		}

		$number = ltrim( $raw_number, '#' );
		$email  = strtolower( sanitize_email( $raw_email ) );
		$valid  = 1 === preg_match( '/^\d{1,20}$/', $number ) && strlen( $raw_email ) <= 100 && '' !== $email && is_email( $email );
		if ( ! $valid ) {
			$this->set( 'not_found' );
			return;
		}

		if ( ! $this->turnstile_passes( $token ) ) {
			$this->set( 'challenge' );
			return;
		}

		$bucket = RateLimiter::hit( 'track_order', hash_hmac( 'sha256', $number, wp_salt( 'auth' ) ), Config::get( 'track_order_limit' ), Config::get( 'track_order_window' ) );
		if ( ! $bucket['allowed'] ) {
			$this->throttle( $bucket['retry_after'] );
			return;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $number ) ) : false;
		$real  = $order instanceof \WC_Order
			&& 'shop_order' === $order->get_type()
			&& ! in_array( $order->get_status(), [ 'checkout-draft', 'trash', 'auto-draft' ], true );

		// Always compare: a missing order is compared against a throw-away value so the timing does not split.
		$stored = $order instanceof \WC_Order && $real ? strtolower( (string) $order->get_billing_email() ) : strtolower( wp_generate_password( 24, false ) );
		$match  = hash_equals( $stored, $email );

		if ( $order instanceof \WC_Order && $real && $match ) {
			self::$state = [
				'state'       => 'result',
				'retry_after' => 0,
				'order'       => $this->view( $order ),
			];
			Log::debug( 'Track order lookup', [ 'state' => 'result' ], 'orders' );
			return;
		}
		$this->set( 'not_found' );
	}

	/**
	 * Store a state without order data and log the reason (no PII).
	 *
	 * @param string $state `not_found` | `challenge`.
	 */
	private function set( string $state ): void {
		self::$state = [
			'state'       => $state,
			'retry_after' => 0,
			'order'       => null,
		];
		Log::debug( 'Track order lookup', [ 'state' => $state ], 'orders' );
	}

	/**
	 * State `throttled`: HTTP 429 + Retry-After (the theme still renders the page).
	 *
	 * @param int $retry Seconds until the bucket resets.
	 */
	private function throttle( int $retry ): void {
		self::$state = [
			'state'       => 'throttled',
			'retry_after' => max( 1, $retry ),
			'order'       => null,
		];
		Responder::log( '429', 'track', 'orders' );
		if ( ! headers_sent() ) {
			status_header( 429 );
			header( 'Retry-After: ' . max( 1, $retry ) );
		}
	}

	/**
	 * Turnstile verdict (no keys / owner bot = passes; the adapter owns the fail-open / closed policy).
	 *
	 * @param string $token Widget token.
	 */
	private function turnstile_passes( string $token ): bool {
		$ts = Plugin::instance()->integration( 'turnstile' );
		if ( ! $ts instanceof Turnstile || ! $ts->is_available() || Exempt::is_owner_bot() ) {
			return true;
		}
		$verdict = Failsafe::run(
			'turnstile-verify',
			static fn(): array => $ts->verify( $token, ClientIp::get( false ), 'track' ),
			[
				'ok'     => true,
				'reason' => 'guard_failed',
			]
		);
		if ( ! empty( $verdict['ok'] ) ) {
			return true;
		}
		Responder::log( 'turnstile-' . (string) ( $verdict['reason'] ?? 'failed' ), 'track', 'security' );
		return false;
	}

	/**
	 * Minimal non-PII view model: number, status, dates, total, items (name, quantity, thumbnail id).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private function view( \WC_Order $order ): array {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$thumb   = 0;
			if ( $product instanceof \WC_Product ) {
				$thumb = (int) $product->get_image_id();
				if ( 0 === $thumb && $product->get_parent_id() > 0 ) {
					$parent = wc_get_product( $product->get_parent_id() );
					$thumb  = $parent instanceof \WC_Product ? (int) $parent->get_image_id() : 0;
				}
			}
			$items[] = [
				'name'     => wp_strip_all_tags( $item->get_name() ),
				'qty'      => (int) $item->get_quantity(),
				'thumb_id' => $thumb,
			];
		}

		$date   = static fn( $d ): string => $d instanceof \WC_DateTime ? (string) wc_format_datetime( $d ) : '';
		$status = $order->get_status();

		return [
			'number'       => (string) $order->get_order_number(),
			'status'       => $status,
			'status_label' => (string) wc_get_order_status_name( $status ),
			'created'      => $date( $order->get_date_created() ),
			'paid'         => $date( $order->get_date_paid() ),
			'completed'    => $date( $order->get_date_completed() ),
			'total_html'   => wc_price( (float) $order->get_total(), [ 'currency' => $order->get_currency() ] ),
			'items'        => $items,
		];
	}
}
