<?php
/**
 * Guard configuration: the one place that knows every number.
 *
 * Resolution per key: constant `VX_GUARD_<KEY>` (wp-config.php) -> filter `vextra/core/guard/limits` -> default.
 * Master switch: constant `VX_GUARD_DISABLED` or filter `vextra/core/guard/enabled`.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

final class Config {

	/**
	 * Default numbers. `*_limit` = requests allowed per `*_window` seconds and client IP.
	 */
	public const DEFAULTS = [
		'store_limit'           => 25,    // Store API (Woo built-in limiter), all routes.
		'store_window'          => 10,
		'ajax_limit'            => 30,    // ?wc-ajax= endpoints.
		'ajax_window'           => 10,
		'coupon_limit'          => 10,    // Coupon apply guesses (Store API + wc-ajax).
		'coupon_window'         => 600,
		'rest_limit'            => 60,    // Any other anonymous REST route.
		'rest_window'           => 60,
		'search_limit'          => 15,    // Front-end search + REST `search=`.
		'search_window'         => 60,
		'search_min'            => 2,     // Characters; shorter queries are redirected.
		'search_max'            => 100,   // Characters; longer queries are truncated.
		'search_ttl'            => 120,   // Seconds a search id list is cached.
		'search_max_ids'        => 500,   // Larger result sets are never cached.
		'facet_limit'           => 120,   // Facet / sort / search / paged URLs (query-string params), per IP. Generous: CGNAT.
		'facet_window'          => 60,
		'cartpost_limit'        => 20,    // POST add-to-cart (no-JS form), per IP.
		'cartpost_window'       => 60,
		'per_page_cap'          => 20,    // Anonymous wp/v2 product listings.
		'termedit_limit'        => 30,    // Owner-bot category edits (each rewrites redirect rows), per user.
		'termedit_window'       => 600,
		'draft_age'             => 86400, // Seconds before a checkout-draft order is purged.
		'draft_age_anon'        => 21600, // Same for a draft WITHOUT customer contact data (no e-mail, no user): bot-created, 6 h (TASK-045a).
		'draft_batch'           => 100,
		'checkout_limit'        => 10,   // Checkout POST attempts per client IP (CGNAT-safe, IPv6 /64) (TASK-045a).
		'checkout_window'       => 600,
		'checkout_email_limit'  => 3,    // Checkout POST attempts per billing e-mail (hashed), counted after the Turnstile check.
		'checkout_email_window' => 3600,
	];

	/**
	 * Resolved integer for a key (never negative; 0 disables the matching limiter).
	 *
	 * @param string $key Key of self::DEFAULTS.
	 */
	public static function get( string $key ): int {
		$value = self::DEFAULTS[ $key ] ?? 0;

		/**
		 * Filters every Guard number.
		 *
		 * @param array<string,int> $limits Key => value (see Config::DEFAULTS).
		 */
		$limits = apply_filters( 'vextra/core/guard/limits', self::DEFAULTS );
		if ( is_array( $limits ) && isset( $limits[ $key ] ) && is_numeric( $limits[ $key ] ) ) {
			$value = (int) $limits[ $key ];
		}

		$const = 'VX_GUARD_' . strtoupper( $key );
		if ( defined( $const ) && is_numeric( constant( $const ) ) ) {
			$value = (int) constant( $const );
		}

		return max( 0, $value );
	}

	/**
	 * Master kill switch.
	 */
	public static function enabled(): bool {
		$on = ! ( defined( 'VX_GUARD_DISABLED' ) && VX_GUARD_DISABLED );

		/**
		 * Filters whether the Guard module is active.
		 *
		 * @param bool $on Default: true unless VX_GUARD_DISABLED is truthy.
		 */
		return (bool) apply_filters( 'vextra/core/guard/enabled', $on );
	}
}
