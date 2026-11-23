<?php
/**
 * Lost-password flow without an account-existence oracle (SEC-007) + its throttles.
 *
 * Core path (wp-login.php, `retrieve_password()`): `lostpassword_user_data` hands core a placeholder user for an unknown login / e-mail,
 * `lostpassword_errors` drops core's `invalid_email` error, core's `invalidcombo` is never reached, and
 * `send_retrieve_password_email` (false for the placeholder, an account that may not reset, or a per-account limit hit) ends the
 * request exactly like a successful one: same redirect `?checkemail=confirm`, same neutral text.
 * Woo path (`/my-account/lost-password/`): Woo has no hook between "user not found" and its error notice, so `wp_loaded` priority 15
 * (before Woo's handler at 20) answers unknown / not-allowed / limited requests with Woo's own success redirect; known requests
 * fall through to Woo untouched (it sends the mail).
 * Buckets: per IP (`reset_ip_*`, 429 + Retry-After) and per account (`reset_user_*`, silently no mail, neutral page).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Guard\ClientIp;
use Vextra\Core\Guard\Failsafe;
use Vextra\Core\Guard\RateLimiter;
use Vextra\Core\Guard\Request;
use Vextra\Core\Guard\Responder;
use Vextra\Core\Plugin;

final class LostPassword {

	/**
	 * Placeholder handed to core for an unknown login (ID 0).
	 *
	 * @var \WP_User|null
	 */
	private ?\WP_User $placeholder = null;

	public function register(): void {
		add_filter( 'lostpassword_user_data', [ $this, 'placeholder_user' ], 99, 2 );
		add_filter( 'lostpassword_errors', [ $this, 'strip_oracle' ], 99, 2 );
		add_filter( 'send_retrieve_password_email', [ $this, 'gate_mail' ], 99, 3 );
		add_action( 'login_form_lostpassword', [ $this, 'ip_limit' ] );
		add_action( 'login_form_retrievepassword', [ $this, 'ip_limit' ] );
		add_filter( 'wp_login_errors', [ $this, 'neutral_confirmation' ], 99, 1 );

		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( null !== $woo && $woo->is_available() ) {
			add_action( 'wp_loaded', [ $this, 'woo_intercept' ], 15 );
			add_filter( 'woocommerce_lost_password_confirmation_message', [ $this, 'neutral_text' ], 99 );
		}
	}

	/**
	 * Neutral confirmation (UA source / RU translation).
	 */
	public static function text(): string {
		return __( 'Якщо такий обліковий запис існує, ми надіслали лист із інструкціями.', 'vextra-core' );
	}

	/**
	 * `lostpassword_user_data`: unknown login / e-mail -> placeholder user so core continues as for a real one.
	 *
	 * @param mixed $user_data WP_User or false.
	 * @param mixed $errors    WP_Error of this request.
	 * @return mixed
	 */
	public function placeholder_user( $user_data, $errors = null ) {
		if ( false !== $user_data || ! $errors instanceof \WP_Error || in_array( 'empty_username', $errors->get_error_codes(), true ) ) {
			return $user_data;
		}
		$this->placeholder = new \WP_User();
		return $this->placeholder;
	}

	/**
	 * `lostpassword_errors`: remove the error that says "no such account".
	 *
	 * @param mixed $errors    WP_Error.
	 * @param mixed $user_data WP_User, placeholder or false.
	 * @return mixed
	 */
	public function strip_oracle( $errors, $user_data = false ) {
		if ( $errors instanceof \WP_Error && null !== $this->placeholder && $user_data === $this->placeholder ) {
			$errors->remove( 'invalid_email' );
		}
		return $errors;
	}

	/**
	 * `send_retrieve_password_email`: mail only for a real account that may reset and is inside its limit.
	 *
	 * @param mixed $send      Core default (true).
	 * @param mixed $user_login Login as typed.
	 * @param mixed $user_data WP_User.
	 * @return mixed
	 */
	public function gate_mail( $send, $user_login = '', $user_data = null ) {
		unset( $user_login );
		if ( ! $send ) {
			return $send;
		}
		if ( ! $user_data instanceof \WP_User || 0 === (int) $user_data->ID || ! wp_is_password_reset_allowed_for_user( $user_data ) ) {
			return false;
		}
		return Failsafe::run( 'lostpassword-user-limit', fn(): bool => $this->account_allowed( (int) $user_data->ID ), true );
	}

	/**
	 * `login_form_lostpassword` / `login_form_retrievepassword`: per-IP bucket for the POST.
	 */
	public function ip_limit(): void {
		if ( 'POST' !== Request::method() ) {
			return;
		}
		$retry = (int) Failsafe::run( 'lostpassword-ip-limit', fn(): int => $this->ip_hit(), 0 );
		if ( $retry > 0 ) {
			Responder::rate_limited( $retry, Responder::FORMAT_HTML, 'lostpassword', 'security' );
		}
	}

	/**
	 * `wp_login_errors`: the `?checkemail=confirm` page shows the same neutral sentence as the Woo page.
	 *
	 * @param mixed $errors WP_Error.
	 * @return mixed
	 */
	public function neutral_confirmation( $errors ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page state, compared with a constant.
		if ( ! $errors instanceof \WP_Error || ! isset( $_GET['checkemail'] ) || 'confirm' !== $_GET['checkemail'] || array() === $errors->get_error_messages( 'confirm' ) ) {
			return $errors;
		}
		$errors->remove( 'confirm' );
		$errors->add( 'confirm', esc_html( self::text() ), 'message' );
		return $errors;
	}

	/**
	 * `woocommerce_lost_password_confirmation_message`: neutral sentence instead of "has been sent to the address on file".
	 *
	 * @param mixed $message Woo text.
	 * @return mixed
	 */
	public function neutral_text( $message ) {
		unset( $message );
		return self::text();
	}

	/**
	 * `wp_loaded` priority 15: Woo lost-password POST.
	 */
	public function woo_intercept(): void {
		if ( 'POST' !== Request::method() || ! isset( $_POST['wc_reset_password'], $_POST['user_login'] ) || ! is_string( $_POST['user_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below, like Woo.
			return;
		}
		$nonce = '';
		foreach ( [ 'woocommerce-lost-password-nonce', '_wpnonce' ] as $field ) {
			if ( isset( $_REQUEST[ $field ] ) && is_string( $_REQUEST[ $field ] ) ) {
				$nonce = sanitize_text_field( wp_unslash( $_REQUEST[ $field ] ) );
				break;
			}
		}
		if ( ! wp_verify_nonce( $nonce, 'lost_password' ) ) {
			return; // Woo ignores the request too.
		}

		$retry = (int) Failsafe::run( 'lostpassword-ip-limit-woo', fn(): int => $this->ip_hit(), 0 );
		if ( $retry > 0 ) {
			Responder::rate_limited( $retry, Responder::FORMAT_HTML, 'lostpassword', 'security' );
		}

		$login = sanitize_user( wp_unslash( $_POST['user_login'] ) );
		if ( '' === $login ) {
			return; // Woo shows its "enter a username" notice, identical for everyone.
		}

		$user = get_user_by( 'login', $login );
		if ( ! $user && is_email( $login ) && apply_filters( 'woocommerce_get_username_from_email', true ) ) {
			$user = get_user_by( 'email', $login );
		}

		$neutral = ! $user instanceof \WP_User
			|| ! wp_is_password_reset_allowed_for_user( $user )
			|| ! Failsafe::run( 'lostpassword-user-limit-woo', fn(): bool => $this->account_allowed( (int) $user->ID ), true );
		if ( ! $neutral ) {
			return; // Real account inside its limit: Woo sends the mail.
		}

		// Plugins that validate the form (captcha, 2FA) still run, like for a real account.
		$errors = new \WP_Error();
		do_action( 'lostpassword_post', $errors, $user instanceof \WP_User ? $user : false );
		if ( $errors->get_error_code() ) {
			wc_add_notice( $errors->get_error_message(), 'error' );
			unset( $_POST['wc_reset_password'] ); // Woo's handler must not run a second time.
			return;
		}

		wp_safe_redirect( add_query_arg( 'reset-link-sent', 'true', wc_get_account_endpoint_url( 'lost-password' ) ) );
		exit;
	}

	/**
	 * Count a request against the IP bucket; seconds to wait when over the limit, else 0.
	 */
	private function ip_hit(): int {
		if ( Config::is_exempt() ) {
			return 0;
		}
		$hit = RateLimiter::hit( 'sec_reset_ip', Store::subject( ClientIp::get() ), Config::get( 'reset_ip_limit' ), Config::get( 'reset_ip_window' ) );
		return $hit['allowed'] ? 0 : $hit['retry_after'];
	}

	/**
	 * Count a request against the per-account bucket; false when the account is over its limit (no mail, neutral page).
	 *
	 * @param int $user_id Account.
	 */
	private function account_allowed( int $user_id ): bool {
		$hit = RateLimiter::hit( 'sec_reset_user', Store::subject( 'u' . $user_id ), Config::get( 'reset_user_limit' ), Config::get( 'reset_user_window' ) );
		if ( ! $hit['allowed'] ) {
			Responder::log( 'reset-suppressed', 'lostpassword', 'security' );
		}
		return $hit['allowed'];
	}
}
