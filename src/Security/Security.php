<?php
/**
 * Security module (TASK-029a, ADR-008 §4/§5): authentication hardening and fingerprint reduction.
 *
 * Services (one class per concern, each registered inside Failsafe so a failure is logged once and the request continues):
 * XmlRpc (SEC-013), Fingerprint (SEC-019), GenericErrors + LostPassword (SEC-007), LoginThrottle + Registration (SEC-008),
 * UserEnumeration (SEC-006), Abilities (SEC-023); TASK-029b: OwnerBotRole + AppPasswords (SEC-015), TurnstileForms (SEC-008),
 * TwoFactorPolicy (SEC-008); TASK-029c: pair-based login lock, async neutral reset mail (LostPassword), AuthorExposure (SEC-044).
 *
 * Does not need WooCommerce, Polylang or Rank Math; Woo-specific hooks are added only when the Woo adapter is available.
 * Kill switch: constant `VX_SECURITY_DISABLED` (filter `vextra/core/security/enabled`). Request guards never run under WP-CLI.
 * Docs: docs/system/modules/security.md (theme repo).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Guard\Failsafe;

final class Security implements ModuleInterface {

	/**
	 * Request services in registration order (XmlRpc first: it may end the request).
	 */
	private const SERVICES = [
		XmlRpc::class,
		Fingerprint::class,
		GenericErrors::class,
		LoginThrottle::class,
		LostPassword::class,
		Registration::class,
		UserEnumeration::class,
		Abilities::class,
		OwnerBotRole::class,
		AppPasswords::class,
		TurnstileForms::class,
		TwoFactorPolicy::class,
		AuthorExposure::class,
	];

	public function id(): string {
		return 'security';
	}

	public function is_enabled(): bool {
		return Config::enabled();
	}

	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new Cli() )->register();
			// Reset-mail jobs must run when WP-CLI processes Action Scheduler (`wp action-scheduler run`); the guards below do not.
			Failsafe::run( 'register-reset-jobs', static fn() => ( new LostPassword() )->register_jobs() );
			return; // Request guards never run under WP-CLI.
		}

		foreach ( self::SERVICES as $class ) {
			Failsafe::run( 'register-' . $class, static fn() => ( new $class() )->register() );
		}
	}
}
