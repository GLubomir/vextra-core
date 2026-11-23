<?php
/**
 * Login throttling (SEC-008): wp-login.php, Woo my-account form (both go through `authenticate`) and REST Basic auth with an
 * Application Password.
 *
 * - Counters: failures per client IP and per login identity (Store::identity(): the account, or the lower-cased text of a
 *   nonexistent name, so lock behaviour does not reveal whether an account exists). Storage: Guard\RateLimiter.
 * - Reaching a limit sets a progressive lock (Store): first `lock_base`, doubling per repeat inside `strike_window`, capped at
 *   `lock_max`. While locked the attempt fails at `authenticate` priority 1 before any password is checked, and a final
 *   priority-999 filter re-applies the lock so no other filter can sign a locked client in.
 * - Anti-DoS of a real user: the per-username lock does not apply to an IP that already signed in as that user (user meta
 *   `_vx_login_known_ips`, last N HMAC-hashed /64-masked IPs). The per-IP lock applies to everyone.
 * - A successful login resets the username counter only (never the IP counter).
 * Reading lock state can fail open (Failsafe); a lock that was read is always enforced.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Guard\ClientIp;
use Vextra\Core\Guard\Failsafe;
use Vextra\Core\Guard\RateLimiter;
use Vextra\Core\Guard\Responder;

final class LoginThrottle {

	public const META_KNOWN_IPS = '_vx_login_known_ips';

	/**
	 * Seconds of the lock found for the current login attempt (null = none).
	 *
	 * @var int|null
	 */
	private ?int $locked = null;

	/**
	 * The REST 429 was already sent (shutdown code may ask for the current user again after `exit`).
	 *
	 * @var bool
	 */
	private bool $refused = false;

	public function register(): void {
		add_filter( 'authenticate', [ $this, 'check_lock' ], 1, 2 );
		add_filter( 'authenticate', [ $this, 'enforce_lock' ], 999, 1 );
		add_action( 'wp_login_failed', [ $this, 'on_failed' ], 10, 2 );
		add_action( 'wp_login', [ $this, 'on_success' ], 10, 2 );
		add_filter( 'determine_current_user', [ $this, 'rest_basic_lock' ], 1 );
		add_action( 'application_password_failed_authentication', [ $this, 'on_rest_failed' ], 10, 1 );
	}

	/**
	 * `authenticate` priority 1: refuse the attempt while the client IP or the login identity is locked.
	 *
	 * @param mixed $user     Null at this priority.
	 * @param mixed $username Login or e-mail (already sanitised by wp_authenticate).
	 * @return mixed
	 */
	public function check_lock( $user, $username = '' ) {
		$this->locked = null;
		if ( $user instanceof \WP_User ) {
			return $user;
		}
		$retry = (int) Failsafe::run( 'login-lock-read', fn(): int => $this->retry_after( is_string( $username ) ? $username : '' ), 0 );
		if ( $retry <= 0 ) {
			return $user;
		}

		$this->locked = $retry;
		$this->send_headers( $retry );
		Responder::log( 'lock', 'login', 'security' );
		// The password must never be evaluated for a locked client: drop core's credential checks for this request.
		remove_filter( 'authenticate', 'wp_authenticate_username_password', 20 );
		remove_filter( 'authenticate', 'wp_authenticate_email_password', 20 );
		return $this->locked_error( $retry );
	}

	/**
	 * `authenticate` priority 999: a lock found at priority 1 wins over everything, even a user object from another filter.
	 *
	 * @param mixed $user Result so far.
	 * @return mixed
	 */
	public function enforce_lock( $user ) {
		if ( null === $this->locked ) {
			return $user;
		}
		if ( is_wp_error( $user ) && in_array( 'vx_login_locked', $user->get_error_codes(), true ) ) {
			return $user;
		}
		return $this->locked_error( $this->locked );
	}

	/**
	 * `wp_login_failed`: count one failure (not our own lock errors, so a lock is not extended by retries).
	 *
	 * @param mixed $username Login or e-mail.
	 * @param mixed $error    WP_Error of the failure.
	 */
	public function on_failed( $username = '', $error = null ): void {
		if ( $error instanceof \WP_Error && in_array( 'vx_login_locked', $error->get_error_codes(), true ) ) {
			return;
		}
		// A missing / failed Turnstile token proves nothing about the account: it counts for the IP only (an anonymous client
		// must not be able to lock a real user out through the per-username bucket).
		$ip_only = $error instanceof \WP_Error && in_array( TurnstileForms::ERROR_CODE, $error->get_error_codes(), true );
		Failsafe::run( 'login-count', fn() => $this->record_failure( is_string( $username ) ? $username : '', $ip_only ) );
	}

	/**
	 * `application_password_failed_authentication`: a wrong Application Password counts like a wrong password.
	 *
	 * @param mixed $error WP_Error (unused).
	 */
	public function on_rest_failed( $error = null ): void {
		unset( $error );
		Failsafe::run( 'login-count-rest', fn() => $this->record_failure( $this->basic_username() ) );
	}

	/**
	 * `wp_login`: remember the (hashed) IP for this account and reset its username counter.
	 *
	 * @param mixed $user_login Login.
	 * @param mixed $user       WP_User.
	 */
	public function on_success( $user_login = '', $user = null ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		Failsafe::run(
			'login-success',
			function () use ( $user ): void {
				$token = ClientIp::hash( ClientIp::get() );
				$known = (array) get_user_meta( $user->ID, self::META_KNOWN_IPS, true );
				if ( ( $known[0] ?? '' ) !== $token ) {
					$known = array_slice( array_values( array_unique( array_merge( [ $token ], array_filter( $known, 'is_string' ) ) ) ), 0, max( 1, Config::get( 'known_ips' ) ) );
					update_user_meta( $user->ID, self::META_KNOWN_IPS, $known );
				}
				RateLimiter::reset( 'sec_login_user', Store::subject( 'u' . $user->ID ) );
			}
		);
	}

	/**
	 * `determine_current_user` priority 1 (before core validates the Application Password at 20): a locked client gets 429 JSON.
	 *
	 * @param mixed $user_id User id found so far (false at this priority).
	 * @return mixed
	 */
	public function rest_basic_lock( $user_id ) {
		if ( $this->refused || ! empty( $user_id ) || ! $this->is_api_request() || ! isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			return $user_id;
		}
		$retry = (int) Failsafe::run( 'login-lock-read-rest', fn(): int => $this->retry_after( $this->basic_username() ), 0 );
		if ( $retry > 0 ) {
			$this->refused = true;
			Responder::rate_limited( $retry, Responder::FORMAT_JSON, 'login-rest', 'security' );
		}
		return $user_id;
	}

	/**
	 * Lock seconds that apply to this client and login (0 = free).
	 *
	 * @param string $username Login or e-mail as typed.
	 */
	public function retry_after( string $username ): int {
		if ( Config::is_exempt() ) {
			return 0;
		}
		$retry = 0;
		if ( Config::get( 'login_ip_limit' ) > 0 ) {
			$retry = Store::lock_remaining( 'ip', ClientIp::get() );
		}
		if ( Config::get( 'login_user_limit' ) > 0 && '' !== trim( $username ) ) {
			$user = Store::find_user( $username );
			// SEC-043: the owner bot has no per-username lock (anonymous failures must never lock its Application Password out);
			// only the per-IP lock applies, so the bot still works from every other address.
			if ( ! OwnerBotRole::is_bot( $user ) ) {
				$ident  = null !== $user ? 'u' . $user->ID : Store::identity( $username );
				$remain = Store::lock_remaining( 'user', $ident );
				if ( $remain > 0 && ! $this->is_known_ip( $user ) ) {
					$retry = max( $retry, $remain );
				}
			}
		}
		return $retry;
	}

	/**
	 * Count one failed attempt against the IP and the identity; lock a subject that reached its limit.
	 *
	 * @param string $username Login or e-mail as typed.
	 * @param bool   $ip_only  Count the IP bucket only.
	 */
	private function record_failure( string $username, bool $ip_only = false ): void {
		if ( Config::is_exempt() ) {
			return;
		}
		$window = Config::get( 'login_window' );
		$this->count( 'ip', 'sec_login_ip', ClientIp::get(), Config::get( 'login_ip_limit' ), $window );
		if ( $ip_only || OwnerBotRole::is_bot( Store::find_user( $username ) ) ) {
			return;
		}
		$ident = Store::identity( $username );
		if ( '' !== $ident ) {
			$this->count( 'user', 'sec_login_user', $ident, Config::get( 'login_user_limit' ), $window );
		}
	}

	/**
	 * One counter step; lock when the limit is reached (the next attempt is refused).
	 *
	 * @param string $kind   `ip` or `user`.
	 * @param string $bucket RateLimiter bucket.
	 * @param string $value  IP key / identity.
	 * @param int    $limit  Failures allowed (0 = bucket off).
	 * @param int    $window Window seconds.
	 */
	private function count( string $kind, string $bucket, string $value, int $limit, int $window ): void {
		if ( $limit <= 0 ) {
			return;
		}
		$subject = Store::subject( $value );
		$hit     = RateLimiter::hit( $bucket, $subject, $limit, $window );
		if ( $hit['remaining'] > 0 || Store::lock_remaining( $kind, $value ) > 0 ) {
			return;
		}

		$strikes = RateLimiter::hit( 'sec_strike', Store::subject( $kind . ':' . $value ), 1000, max( 60, Config::get( 'strike_window' ) ) );
		$count   = max( 1, min( 20, 1000 - $strikes['remaining'] ) );
		$seconds = (int) min( max( 60, Config::get( 'lock_max' ) ), max( 1, Config::get( 'lock_base' ) ) * ( 2 ** ( $count - 1 ) ) );

		Store::lock( $kind, $value, $seconds );
		RateLimiter::reset( $bucket, $subject );
		Responder::log( 'lock-set', 'login-' . $kind, 'security' );
	}

	/**
	 * Has this account signed in from the current (hashed, /64-masked) IP before.
	 *
	 * @param \WP_User|null $user Account.
	 */
	private function is_known_ip( ?\WP_User $user ): bool {
		if ( null === $user ) {
			return false;
		}
		$known = get_user_meta( $user->ID, self::META_KNOWN_IPS, true );
		return is_array( $known ) && in_array( ClientIp::hash( ClientIp::get() ), $known, true );
	}

	private function is_api_request(): bool {
		return ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST );
	}

	/**
	 * User name of the HTTP Basic credentials (sanitised like core does for logins).
	 */
	private function basic_username(): string {
		if ( ! isset( $_SERVER['PHP_AUTH_USER'] ) || ! is_string( $_SERVER['PHP_AUTH_USER'] ) ) {
			return '';
		}
		return sanitize_user( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) );
	}

	private function locked_error( int $retry ): \WP_Error {
		return new \WP_Error(
			'vx_login_locked',
			sprintf(
				/* translators: %d: minutes until the next attempt is allowed. */
				__( 'Забагато спроб входу. Спробуйте за %d хв.', 'vextra-core' ),
				max( 1, (int) ceil( $retry / 60 ) )
			)
		);
	}

	/**
	 * Retry-After + no-store (+ HTTP 429 on wp-login.php; the Woo form renders as 200).
	 *
	 * @param int $retry Seconds.
	 */
	private function send_headers( int $retry ): void {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Retry-After: ' . max( 1, $retry ) );
		header( 'X-Robots-Tag: noindex' );
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			status_header( 429 );
		}
	}
}
