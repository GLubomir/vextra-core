<?php
/**
 * Turnstile wiring for the authentication forms (TASK-029b, D2). Registered ONLY when the adapter is available (both keys
 * set): otherwise this class adds no hook, so forms, scripts and requests are byte-identical to a site without Turnstile.
 *
 * Widget: wp-login.php (login, lost password, register) and the Woo my-account forms for logged-out visitors. The adapter
 * enqueues api.js from `render_widget()`, so the script exists exactly where a widget does.
 * Verification: interactive form POSTs only (never REST, XML-RPC or Application Passwords).
 * - login: `authenticate` priority 5, before the password is looked at. A failure removes core's password checks (like the
 *   lock in LoginThrottle) and is re-applied at priority 999; the IP login bucket counts it, the per-username bucket does not
 *   (an anonymous client must not be able to lock a real account).
 * - lost password: `lostpassword_post` (wp-login.php and Woo, incl. the neutral Woo path of LostPassword).
 * - registration: `registration_errors` (wp-login.php) and `woocommerce_registration_errors` (Woo register form).
 * The checkout form is wired in E6 through the same adapter calls (`render_widget( 'checkout' )`, `verify()`).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Guard\ClientIp;
use Vextra\Core\Guard\Failsafe;
use Vextra\Core\Guard\Request;
use Vextra\Core\Guard\Responder;
use Vextra\Core\Integrations\Turnstile\Turnstile;
use Vextra\Core\Plugin;

final class TurnstileForms {

	public const ERROR_CODE = 'vx_turnstile_failed';

	/**
	 * Error found at priority 5, re-applied at 999.
	 *
	 * @var \WP_Error|null
	 */
	private ?\WP_Error $failed = null;

	public function register(): void {
		$ts = $this->adapter();
		if ( null === $ts || ! $ts->is_available() ) {
			return;
		}

		// Widgets: wp-login.php.
		add_action( 'login_form', fn() => $this->widget( 'login', false ) );
		add_action( 'lostpassword_form', fn() => $this->widget( 'lostpassword', false ) );
		add_action( 'register_form', fn() => $this->widget( 'register', false ) );

		// Verification.
		add_filter( 'authenticate', [ $this, 'check_login' ], 5, 1 );
		add_filter( 'authenticate', [ $this, 'enforce_login' ], 999, 1 );
		add_action( 'lostpassword_post', [ $this, 'check_lost_password' ], 10, 1 );
		add_filter( 'registration_errors', [ $this, 'check_register' ], 10, 1 );

		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( null !== $woo && $woo->is_available() ) {
			add_action( 'woocommerce_login_form', fn() => $this->widget( 'login', true ) );
			add_action( 'woocommerce_register_form', fn() => $this->widget( 'register', true ) );
			add_action( 'woocommerce_lostpassword_form', fn() => $this->widget( 'lostpassword', true ) );
			add_filter( 'woocommerce_registration_errors', [ $this, 'check_register_woo' ], 10, 1 );
		}
	}

	/**
	 * Print the widget where it belongs: wp-login.php, or the Woo account page for a logged-out visitor.
	 *
	 * @param string $action Widget action.
	 * @param bool   $woo    True for a Woo form hook.
	 */
	private function widget( string $action, bool $woo ): void {
		$ts = $this->adapter();
		if ( null === $ts ) {
			return;
		}
		if ( $woo ) {
			if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || is_user_logged_in() ) {
				return;
			}
		} elseif ( ! $this->is_login_page() ) {
			return;
		}
		$ts->render_widget( $action );
	}

	/**
	 * `authenticate` priority 5: verify the widget before core looks at the password.
	 *
	 * @param mixed $user Result so far (an earlier refusal, such as a throttle lock, wins and is never replaced).
	 * @return mixed
	 */
	public function check_login( $user ) {
		$this->failed = null;
		if ( $user instanceof \WP_User || $user instanceof \WP_Error || ! $this->is_login_post() ) {
			return $user;
		}
		$error = $this->verify_request( 'login' );
		if ( null === $error ) {
			return $user;
		}

		$this->failed = $error;
		// A WP_Error at this priority does not stop core's password check: drop it for this request.
		remove_filter( 'authenticate', 'wp_authenticate_username_password', 20 );
		remove_filter( 'authenticate', 'wp_authenticate_email_password', 20 );
		return $error;
	}

	/**
	 * `authenticate` priority 999: nothing signs in a visitor who failed the challenge.
	 *
	 * @param mixed $user Result so far.
	 * @return mixed
	 */
	public function enforce_login( $user ) {
		return null === $this->failed ? $user : $this->failed;
	}

	/**
	 * `lostpassword_post`: add the challenge error to the collected errors (nothing is sent).
	 *
	 * @param mixed $errors WP_Error collecting validation problems.
	 */
	public function check_lost_password( $errors = null ): void {
		if ( ! $errors instanceof \WP_Error || 'POST' !== Request::method() || in_array( self::ERROR_CODE, $errors->get_error_codes(), true ) ) {
			return;
		}
		$error = $this->verify_request( 'lostpassword' );
		if ( null !== $error ) {
			$errors->add( self::ERROR_CODE, $error->get_error_message() );
		}
	}

	/**
	 * `registration_errors` (wp-login.php register form).
	 *
	 * @param mixed $errors WP_Error.
	 * @return mixed
	 */
	public function check_register( $errors ) {
		if ( ! $errors instanceof \WP_Error || 'POST' !== Request::method() || ! $this->is_login_page() ) {
			return $errors;
		}
		return $this->add_error( $errors );
	}

	/**
	 * `woocommerce_registration_errors` (Woo register form; programmatic customer creation does not post `register`).
	 *
	 * @param mixed $errors WP_Error.
	 * @return mixed
	 */
	public function check_register_woo( $errors ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check of the submit button; Woo verifies its own nonce.
		if ( ! $errors instanceof \WP_Error || 'POST' !== Request::method() || ! isset( $_POST['register'] ) ) {
			return $errors;
		}
		return $this->add_error( $errors );
	}

	/**
	 * Append the challenge error when the visitor failed it.
	 *
	 * @param \WP_Error $errors Collected errors.
	 */
	private function add_error( \WP_Error $errors ): \WP_Error {
		$error = $this->verify_request( 'register' );
		if ( null !== $error ) {
			$errors->add( self::ERROR_CODE, $error->get_error_message() );
		}
		return $errors;
	}

	/**
	 * Check the posted token. Null = passed (or the provider failed open); an error = refuse.
	 *
	 * @param string $route Short label for the log.
	 */
	private function verify_request( string $route ): ?\WP_Error {
		$ts = $this->adapter();
		if ( null === $ts || $this->is_api() ) {
			return null;
		}
		$token = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- the token is the proof, validated by Cloudflare; only a string is accepted, then unslashed and sanitised below.
		$raw = isset( $_POST[ Turnstile::FIELD ] ) && is_string( $_POST[ Turnstile::FIELD ] ) ? wp_unslash( $_POST[ Turnstile::FIELD ] ) : '';
		if ( '' !== $raw ) {
			$token = substr( sanitize_text_field( $raw ), 0, 2100 );
		}

		$verdict = Failsafe::run(
			'turnstile-verify',
			static fn(): array => $ts->verify( $token, ClientIp::get( false ) ),
			[
				'ok'     => true,
				'reason' => 'guard_failed',
			]
		);
		if ( ! empty( $verdict['ok'] ) ) {
			return null;
		}

		Responder::log( 'turnstile-' . (string) ( $verdict['reason'] ?? 'failed' ), $route, 'security' );
		return new \WP_Error( self::ERROR_CODE, __( 'Підтвердіть, що ви не робот і спробуйте ще раз.', 'vextra-core' ) );
	}

	/**
	 * Interactive login form POST: wp-login.php (`log` + `pwd`) or the Woo form (`username` + `password` + `login`).
	 */
	private function is_login_post(): bool {
		if ( 'POST' !== Request::method() || $this->is_api() ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- field presence only.
		if ( $this->is_login_page() ) {
			return isset( $_POST['log'], $_POST['pwd'] );
		}
		return isset( $_POST['username'], $_POST['password'], $_POST['login'] );
		// phpcs:enable
	}

	private function is_login_page(): bool {
		return isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'];
	}

	private function is_api(): bool {
		return ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST );
	}

	private function adapter(): ?Turnstile {
		$ts = Plugin::instance()->integration( 'turnstile' );
		return $ts instanceof Turnstile ? $ts : null;
	}
}
