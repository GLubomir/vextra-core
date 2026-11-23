<?php
/**
 * Lost-password flow without an account-existence oracle (SEC-007, SEC-041, SEC-042) + its throttles.
 *
 * Core path (wp-login.php, `retrieve_password()`): `lostpassword_user_data` hands core a placeholder user for an unknown login / e-mail,
 * `lostpassword_errors` drops core's `invalid_email` error, core's `invalidcombo` is never reached, and
 * `send_retrieve_password_email` is ALWAYS false for the request thread: core ends the request exactly like a successful one
 * (same redirect `?checkemail=confirm`, same neutral text) whatever happens to the mail.
 * Known account: the whole mail work (reset key + `wp_mail`) is one Action Scheduler async action (hook `vextra/security/send_reset_mail`,
 * group `vextra-security`, args = [ user ID ] only: the key is generated inside the job and never stored in the AS table), so
 * known and unknown logins cost the request thread the same and a mail failure has no user-visible effect (hashed log line,
 * one retry after 5 minutes). Without Action Scheduler the mail is sent synchronously and a failure is swallowed.
 * Woo path (`/my-account/lost-password/`): Woo has no hook between "user not found" and its error notice, so `wp_loaded` priority 15
 * (before Woo's handler at 20) answers every request itself with Woo's own success redirect and queues job
 * `vextra/security/send_reset_mail_woo` (Woo's own mail template) for a known account. Without Action Scheduler a known account
 * falls through to Woo (sync, the failure is swallowed by Woo).
 * Buckets: per IP (`reset_ip_*`, 429 + Retry-After); per (account, IP) pair (`reset_user_limit`) and per account across all IPs
 * (`reset_user_global_limit`), both silently "no job, neutral page" (SEC-043: an attacker cannot burn the owner's quota).
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
use Vextra\Core\Support\Log;

final class LostPassword {

	public const JOB_CORE = 'vextra/security/send_reset_mail';
	public const JOB_WOO  = 'vextra/security/send_reset_mail_woo';
	public const GROUP    = 'vextra-security';

	/**
	 * A reset mail job is running in this request (form validators such as Turnstile must stand aside).
	 *
	 * @var bool
	 */
	private static bool $job = false;

	/**
	 * Placeholder handed to core for an unknown login (ID 0).
	 *
	 * @var \WP_User|null
	 */
	private ?\WP_User $placeholder = null;

	/**
	 * Is a queued reset mail being sent right now.
	 */
	public static function in_job(): bool {
		return self::$job;
	}

	/**
	 * Job handlers (registered under WP-CLI too, where Action Scheduler runs queued actions).
	 */
	public function register_jobs(): void {
		add_action( self::JOB_CORE, [ $this, 'job_core' ], 10, 1 );
		add_action( self::JOB_WOO, [ $this, 'job_woo' ], 10, 1 );
	}

	public function register(): void {
		$this->register_jobs();
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
	 * `send_retrieve_password_email`: core never sends from the request thread. A real account that may reset and is inside its
	 * quotas gets a background job instead; unknown / forbidden / limited requests do the same bookkeeping and queue nothing.
	 *
	 * @param mixed $send       Core default (true).
	 * @param mixed $user_login Login as typed.
	 * @param mixed $user_data  WP_User.
	 * @return mixed
	 */
	public function gate_mail( $send, $user_login = '', $user_data = null ) {
		if ( ! $send || self::$job ) {
			return $send; // Our own job calls retrieve_password(): that call goes through.
		}
		$real  = $user_data instanceof \WP_User && (int) $user_data->ID > 0;
		$ident = $real ? 'u' . (int) $user_data->ID : Store::identity( is_string( $user_login ) ? $user_login : '' );
		$ok    = (bool) Failsafe::run( 'lostpassword-quota', fn(): bool => $this->quota( $ident ), true );
		if ( $real && $ok && wp_is_password_reset_allowed_for_user( $user_data ) ) {
			$id = (int) $user_data->ID;
			Failsafe::run( 'lostpassword-queue', fn() => $this->dispatch( $id, self::JOB_CORE ) );
		}
		return false;
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

		$ident = $user instanceof \WP_User ? 'u' . (int) $user->ID : Store::identity( $login );
		$ok    = (bool) Failsafe::run( 'lostpassword-quota-woo', fn(): bool => $this->quota( $ident ), true );
		$send  = $user instanceof \WP_User && $ok && wp_is_password_reset_allowed_for_user( $user );
		if ( $send && ! function_exists( 'as_enqueue_async_action' ) ) {
			return; // No queue: Woo sends the mail itself (synchronously; Woo ignores a failed send).
		}

		// Plugins that validate the form (captcha, 2FA) still run, like for a real account.
		$errors = new \WP_Error();
		do_action( 'lostpassword_post', $errors, $user instanceof \WP_User ? $user : false );
		if ( $errors->get_error_code() ) {
			wc_add_notice( $errors->get_error_message(), 'error' );
			unset( $_POST['wc_reset_password'] ); // Woo's handler must not run a second time.
			return;
		}

		if ( $send && $user instanceof \WP_User ) {
			$id = (int) $user->ID;
			Failsafe::run( 'lostpassword-queue-woo', fn() => $this->dispatch( $id, self::JOB_WOO ) );
		}
		wp_safe_redirect( add_query_arg( 'reset-link-sent', 'true', wc_get_account_endpoint_url( 'lost-password' ) ) );
		exit;
	}

	/**
	 * Job `vextra/security/send_reset_mail`: wp-login.php style mail.
	 *
	 * @param mixed $user_id User ID (the only argument).
	 */
	public function job_core( $user_id = 0 ): void {
		$this->run_job( is_numeric( $user_id ) ? (int) $user_id : 0, self::JOB_CORE );
	}

	/**
	 * Job `vextra/security/send_reset_mail_woo`: WooCommerce mail template.
	 *
	 * @param mixed $user_id User ID (the only argument).
	 */
	public function job_woo( $user_id = 0 ): void {
		$this->run_job( is_numeric( $user_id ) ? (int) $user_id : 0, self::JOB_WOO );
	}

	/**
	 * `retrieve_password_message` (job only): core would print the address of the background request ("originated from the IP
	 * address ..."), which is the server itself and misleading: drop that sentence.
	 *
	 * @param mixed $message Mail body.
	 * @return mixed
	 */
	public function strip_requester_ip( $message ) {
		if ( ! is_string( $message ) ) {
			return $message;
		}
		$addr = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $addr ) {
			return $message;
		}
		/* translators: %s: IP address of password reset requester. */
		$line = sprintf( __( 'This password reset request originated from the IP address %s.' ), $addr ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own string, translated by core's domain.
		return str_replace( $line . "\r\n", '', $message );
	}

	/**
	 * Queue the mail, or send it right now without Action Scheduler. Never throws, never reports the outcome to the visitor.
	 *
	 * @param int    $user_id Account.
	 * @param string $hook    Job hook.
	 */
	private function dispatch( int $user_id, string $hook ): void {
		if ( function_exists( 'as_enqueue_async_action' ) && (int) as_enqueue_async_action( $hook, [ $user_id ], self::GROUP ) > 0 ) {
			return;
		}
		$this->run_job( $user_id, $hook, false );
	}

	/**
	 * Generate the reset key and send the mail. A failure is logged once (hashed) and retried once after 5 minutes.
	 *
	 * @param int    $user_id Account.
	 * @param string $hook    Job hook (selects the mail template).
	 * @param bool   $retry   Allow the single retry.
	 */
	private function run_job( int $user_id, string $hook, bool $retry = true ): void {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$failed  = false;
		$capture = static function () use ( &$failed ): void {
			$failed = true;
		};

		self::$job = true;
		add_action( 'wp_mail_failed', $capture );
		add_filter( 'retrieve_password_message', [ $this, 'strip_requester_ip' ], 99, 1 );
		try {
			if ( self::JOB_WOO === $hook ) {
				$failed = ! $this->send_woo( $user ) || $failed;
			} else {
				$result = retrieve_password( '' !== (string) $user->user_email ? (string) $user->user_email : (string) $user->user_login );
				$failed = is_wp_error( $result ) || $failed;
			}
		} catch ( \Throwable $e ) {
			unset( $e );
			$failed = true;
		} finally {
			remove_filter( 'retrieve_password_message', [ $this, 'strip_requester_ip' ], 99 );
			remove_action( 'wp_mail_failed', $capture );
			self::$job = false;
		}

		if ( ! $failed ) {
			return;
		}
		Log::warning( 'Reset mail not sent', [ 'account' => substr( wp_hash( 'vx-reset|' . $user_id ), 0, 12 ) ], 'security' );
		if ( $retry && function_exists( 'as_schedule_single_action' ) && RateLimiter::hit( 'sec_reset_retry', Store::subject( 'u' . $user_id ), 1, 900 )['allowed'] ) {
			as_schedule_single_action( time() + 300, $hook, [ $user_id ], self::GROUP );
		}
	}

	/**
	 * Woo flow of `WC_Shortcode_My_Account::retrieve_password()` after the validation: key + Woo mail. False = could not start.
	 *
	 * @param \WP_User $user Account.
	 */
	private function send_woo( \WP_User $user ): bool {
		if ( ! function_exists( 'WC' ) || ! wp_is_password_reset_allowed_for_user( $user ) ) {
			return false;
		}
		do_action( 'retrieve_password', $user->user_login );
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return false;
		}
		WC()->mailer(); // Loads the email classes (they hook the notification).
		do_action( 'woocommerce_reset_password_notification', $user->user_login, $key );
		return true;
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
	 * Count a request against the (account, IP) pair bucket and, while that allows it, the per-account ceiling. Identical work for
	 * known and unknown logins (an unknown name is keyed by the hash of its text). False = no job (neutral page).
	 *
	 * @param string $ident Store::identity().
	 */
	private function quota( string $ident ): bool {
		if ( '' === $ident ) {
			return false;
		}
		$window = Config::get( 'reset_user_window' );
		$ok     = RateLimiter::hit( 'sec_reset_pair', Store::subject( Store::pair( $ident ) ), Config::get( 'reset_user_limit' ), $window )['allowed'];
		if ( $ok ) {
			$ok = RateLimiter::hit( 'sec_reset_user', Store::subject( $ident ), Config::get( 'reset_user_global_limit' ), $window )['allowed'];
		}
		if ( ! $ok ) {
			Responder::log( 'reset-suppressed', 'lostpassword', 'security' );
		}
		return $ok;
	}
}
