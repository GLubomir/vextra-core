<?php
/**
 * Checkout attempt throttle (TASK-045a, ADR-007 "throttled orders per IP / e-mail"). Reuses `RateLimiter`, `ClientIp`, `Config`; no second limiter.
 *
 * Two buckets, both counted for every POST that reaches the handler (Store API `POST /wc/store/v1/checkout`, also inside `/batch`;
 * classic `wc-ajax=checkout` / order-pay POST):
 * - IP (`checkout_limit` per `checkout_window`, default 10 / 10 min; IPv6 counted per /64 by `ClientIp`, trusted-proxy aware).
 * - Billing e-mail (`checkout_email_limit` per `checkout_email_window`, default 3 / hour). The key is `hash_hmac( sha256, lower-case e-mail, wp_salt )`:
 *   the address is never stored, logged or part of a cache key. It is counted AFTER the Turnstile check, so a client without a valid token
 *   cannot lock a victim's e-mail address (with Turnstile OFF the e-mail bucket is the only protection and can be abused: documented).
 * Exceeded = HTTP 429 + `Retry-After` + the Guard JSON error shape with a localised "try in N min" message; nothing is created.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;

final class CheckoutThrottle {

	/**
	 * One attempt of this client IP.
	 *
	 * @return array{allowed:bool,retry_after:int,remaining:int}
	 */
	public static function hit_ip(): array {
		return RateLimiter::hit( 'checkout', ClientIp::get(), Config::get( 'checkout_limit' ), Config::get( 'checkout_window' ) );
	}

	/**
	 * One attempt of this billing e-mail (an empty / malformed address is not counted: validation rejects it anyway).
	 *
	 * @param string $email Raw billing e-mail.
	 * @return array{allowed:bool,retry_after:int,remaining:int}
	 */
	public static function hit_email( string $email ): array {
		$email = self::normalise_email( $email );
		if ( '' === $email ) {
			return [
				'allowed'     => true,
				'retry_after' => 0,
				'remaining'   => PHP_INT_MAX,
			];
		}
		return RateLimiter::hit( 'checkout_mail', hash_hmac( 'sha256', $email, wp_salt( 'auth' ) ), Config::get( 'checkout_email_limit' ), Config::get( 'checkout_email_window' ) );
	}

	/**
	 * Mailbox key of an address (SEC-074): lower case, `+tag` removed, dots removed for Gmail (`googlemail.com` = `gmail.com`), so aliases of one inbox share one
	 * bucket. '' for a malformed address. Used for the e-mail bucket and the order lock; never stored or logged.
	 *
	 * @param string $email Raw billing e-mail.
	 */
	public static function normalise_email( string $email ): string {
		$email = strtolower( trim( sanitize_email( $email ) ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}
		$at     = (int) strrpos( $email, '@' );
		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );
		$plus   = strpos( $local, '+' );
		if ( false !== $plus && $plus > 0 ) {
			$local = substr( $local, 0, $plus );
		}
		if ( 'googlemail.com' === $domain ) {
			$domain = 'gmail.com';
		}
		if ( 'gmail.com' === $domain ) {
			$local = str_replace( '.', '', $local );
		}
		return $local . '@' . $domain;
	}

	/**
	 * Localised message "Забагато спроб. Спробуйте за N хв." in the shopper's language (Referer prefix, else the request language).
	 *
	 * @param int $retry Seconds until the bucket resets.
	 */
	public static function message( int $retry ): string {
		$minutes  = max( 1, (int) ceil( $retry / 60 ) );
		$switched = self::switch_locale();
		/* translators: %d: minutes. */
		$text = sprintf( __( 'Забагато спроб. Спробуйте за %d хв.', 'vextra-core' ), $minutes );
		if ( $switched ) {
			restore_previous_locale();
		}
		return $text;
	}

	/**
	 * Switch to the language of the page the request came from (REST requests have no Polylang context of their own).
	 */
	public static function switch_locale(): bool {
		$pll = Plugin::instance()->integration( 'polylang' );
		if ( ! $pll instanceof Polylang || ! $pll->is_ready() ) {
			return false;
		}
		$referer = isset( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$lang    = '' !== $referer ? $pll->language_from_url( $referer ) : '';
		$lang    = '' !== $lang ? $lang : $pll->current_language();
		$locale  = '' !== $lang ? $pll->locale_of( $lang ) : '';
		return '' !== $locale && determine_locale() !== $locale && switch_to_locale( $locale );
	}
}
