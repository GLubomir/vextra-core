<?php
/**
 * No account-existence oracle at login (SEC-007).
 *
 * `authenticate` priority 100 replaces the WP_Error codes `invalid_username`, `invalid_email` and `incorrect_password` with ONE
 * error `vx_invalid_login` (same text for an unknown login, an unknown e-mail and a wrong password; no "Lost your password?"
 * link). Other codes (`empty_*`, `vx_login_locked`, other plugins') are kept untouched.
 * For an unknown login one password hash is computed so known and unknown logins take comparable time (measured, not guaranteed).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Guard\Failsafe;

final class GenericErrors {

	private const REPLACED = [ 'invalid_username', 'invalid_email', 'incorrect_password' ];

	public function register(): void {
		add_filter( 'authenticate', [ $this, 'replace' ], 100, 1 );
		// REST Basic auth with an Application Password does not run `authenticate`: its own errors name the problem.
		add_filter( 'rest_authentication_errors', [ $this, 'replace_rest' ], 100, 1 );
	}

	/**
	 * Replace the three existence-revealing errors.
	 *
	 * @param mixed $user WP_User, WP_Error or null.
	 * @return mixed
	 */
	public function replace( $user ) {
		return $this->convert( $user, [] );
	}

	/**
	 * Same replacement for the error of a failed REST Basic (Application Password) authentication, status 401.
	 *
	 * @param mixed $result WP_Error, true or null.
	 * @return mixed
	 */
	public function replace_rest( $result ) {
		return $this->convert( $result, [ 'status' => 401 ] );
	}

	/**
	 * Replace the existence-revealing codes of a WP_Error.
	 *
	 * @param mixed               $user Value of the filter.
	 * @param array<string,mixed> $data Error data (REST status).
	 * @return mixed
	 */
	private function convert( $user, array $data ) {
		if ( ! $user instanceof \WP_Error ) {
			return $user;
		}
		return Failsafe::run(
			'generic-login-error',
			function () use ( $user, $data ): \WP_Error {
				$codes = $user->get_error_codes();
				$hit   = array_values( array_intersect( $codes, self::REPLACED ) );
				if ( array() === $hit ) {
					return $user;
				}

				// Unknown login or e-mail: core skipped the password hash, so spend one here (best-effort timing parity).
				if ( array() !== array_intersect( $hit, [ 'invalid_username', 'invalid_email' ] ) ) {
					wp_hash_password( wp_generate_password( 24, true, true ) );
				}

				$error = new \WP_Error( 'vx_invalid_login', __( 'Невірний логін або пароль.', 'vextra-core' ), $data );
				if ( array() === array_diff( $codes, self::REPLACED ) ) {
					return $error;
				}
				// Mixed codes: keep the foreign ones, drop ours, append the generic message.
				foreach ( self::REPLACED as $code ) {
					$user->remove( $code );
				}
				$user->add( 'vx_invalid_login', $error->get_error_message(), $data );
				return $user;
			},
			$user
		);
	}
}
