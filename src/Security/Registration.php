<?php
/**
 * Per-IP bucket for registration submits (wp-login.php?action=register and the Woo my-account register form), so enabling
 * registration later does not open an unthrottled e-mail-exists oracle. Registration itself stays OFF (`users_can_register=0`,
 * Woo registration off); Woo's "e-mail already registered" message is a known, accepted trade-off (docs/system/modules/security.md).
 * Only POSTs are counted; over the limit: 429 + Retry-After.
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

final class Registration {

	public function register(): void {
		add_action( 'login_form_register', [ $this, 'limit' ] );

		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( null !== $woo && $woo->is_available() ) {
			add_action( 'wp_loaded', [ $this, 'limit_woo' ], 15 );
		}
	}

	/**
	 * `wp_loaded` priority 15 (before Woo's handler at 20): the Woo register form posts a field named `register`.
	 */
	public function limit_woo(): void {
		if ( 'POST' === Request::method() && isset( $_POST['register'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check; Woo verifies its own nonce.
			$this->limit();
		}
	}

	/**
	 * Count a registration submit.
	 */
	public function limit(): void {
		if ( 'POST' !== Request::method() ) {
			return;
		}
		$retry = (int) Failsafe::run(
			'register-ip-limit',
			static function (): int {
				if ( Config::is_exempt() ) {
					return 0;
				}
				$hit = RateLimiter::hit( 'sec_register_ip', Store::subject( ClientIp::get() ), Config::get( 'register_ip_limit' ), Config::get( 'register_ip_window' ) );
				return $hit['allowed'] ? 0 : $hit['retry_after'];
			},
			0
		);
		if ( $retry > 0 ) {
			Responder::rate_limited( $retry, Responder::FORMAT_HTML, 'register', 'security' );
		}
	}
}
