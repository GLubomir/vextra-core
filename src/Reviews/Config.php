<?php
/**
 * Reviews policy configuration: numbers, forced options, kill switch.
 *
 * Numbers: constant `VX_REVIEWS_<KEY>` (wp-config.php) -> filter `vextra/core/reviews/limits` -> default.
 * Master switch: constant `VX_REVIEWS_DISABLED` or filter `vextra/core/reviews/enabled`.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Reviews;

final class Config {

	/**
	 * Policy revision: bump to re-run the one-time option reconcile and the legacy comment closing.
	 */
	public const POLICY_VERSION = '1';

	/**
	 * Default numbers. `*_limit` per `*_window` seconds; 0 disables a bucket.
	 */
	public const DEFAULTS = [
		'ip_limit'       => 5,    // Accepted review submits per client IP.
		'ip_window'      => 3600,
		'user_limit'     => 3,    // Accepted review submits per user.
		'user_window'    => 3600,
		'attempt_limit'  => 30,   // Any submit attempt per client IP (flood ceiling; typos never reach the quotas above).
		'attempt_window' => 3600,
		'rest_limit'     => 5,    // Review / comment REST write attempts (all refused) per client IP.
		'rest_window'    => 3600,
		'min_length'     => 10,
		'max_length'     => 2000,
		'per_page'       => 50,   // `comments_per_page` (all reviews sit on one product page, `page_comments` is off).
	];

	/**
	 * Options forced by the policy: name => value (strings, as stored).
	 */
	public const FORCED_OPTIONS = [
		'woocommerce_review_rating_verification_required' => 'yes',
		'woocommerce_review_rating_verification_label'    => 'yes',
		'woocommerce_enable_reviews'                      => 'yes',
		'woocommerce_enable_review_rating'                => 'yes',
		'woocommerce_review_rating_required'              => 'yes',
		'comment_registration'                            => '1',
		'comment_moderation'                              => '1',
		'comment_previously_approved'                     => '0',
		'comment_max_links'                               => '0',
		'default_comment_status'                          => 'closed',
		'default_ping_status'                             => 'closed',
		'default_pingback_flag'                           => '0',
		'thread_comments'                                 => '0',
		'page_comments'                                   => '0',
		'comment_order'                                   => 'desc',
		'show_avatars'                                    => '0',
	];

	/**
	 * Forced options with the numeric `comments_per_page` added.
	 *
	 * @return array<string,string>
	 */
	public static function forced_options(): array {
		return self::FORCED_OPTIONS + [ 'comments_per_page' => (string) max( 10, self::get( 'per_page' ) ) ];
	}

	/**
	 * Resolved integer for a key (never negative).
	 *
	 * @param string $key Key of self::DEFAULTS.
	 */
	public static function get( string $key ): int {
		$value = self::DEFAULTS[ $key ] ?? 0;

		/**
		 * Filters every Reviews number.
		 *
		 * @param array<string,int> $limits Key => value (see Config::DEFAULTS).
		 */
		$limits = apply_filters( 'vextra/core/reviews/limits', self::DEFAULTS );
		if ( is_array( $limits ) && isset( $limits[ $key ] ) && is_numeric( $limits[ $key ] ) ) {
			$value = (int) $limits[ $key ];
		}

		$const = 'VX_REVIEWS_' . strtoupper( $key );
		if ( defined( $const ) && is_numeric( constant( $const ) ) ) {
			$value = (int) constant( $const );
		}

		return max( 0, $value );
	}

	/**
	 * Master kill switch.
	 */
	public static function enabled(): bool {
		$on = ! ( defined( 'VX_REVIEWS_DISABLED' ) && VX_REVIEWS_DISABLED );

		/**
		 * Filters whether the Reviews module is active.
		 *
		 * @param bool $on Default: true unless VX_REVIEWS_DISABLED is truthy.
		 */
		return (bool) apply_filters( 'vextra/core/reviews/enabled', $on );
	}
}
