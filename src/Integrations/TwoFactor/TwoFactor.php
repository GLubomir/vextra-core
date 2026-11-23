<?php
/**
 * "Two Factor" adapter (wordpress.org plugin `two-factor`, TASK-029b, ADR-003).
 *
 * The only place that touches the plugin: class `Two_Factor_Core` (guarded by class_exists), its public static method
 * `is_user_using_two_factor()` and the filter `two_factor_providers` (registered by Security\TwoFactorPolicy).
 * Everything degrades to "not available / no 2FA" when the plugin is absent; the site never depends on it.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Integrations\TwoFactor;

use Vextra\Core\Integrations\AbstractIntegration;

final class TwoFactor extends AbstractIntegration {

	/**
	 * Provider classes kept by policy: authenticator app (TOTP) and backup codes. E-mail codes share the channel of the
	 * password reset; the Dummy provider is for debugging only.
	 */
	public const ALLOWED_PROVIDERS = [ 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ];

	public function id(): string {
		return 'twofactor';
	}

	protected function detect(): bool {
		return class_exists( 'Two_Factor_Core' );
	}

	public function version(): ?string {
		return defined( 'TWO_FACTOR_VERSION' ) ? (string) TWO_FACTOR_VERSION : null;
	}

	/**
	 * Does the user have at least one configured second factor.
	 *
	 * @param \WP_User $user Account.
	 */
	public function user_has_two_factor( \WP_User $user ): bool {
		if ( ! $this->is_available() || ! method_exists( 'Two_Factor_Core', 'is_user_using_two_factor' ) ) {
			return false;
		}
		try {
			return (bool) \Two_Factor_Core::is_user_using_two_factor( $user );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Provider map (class => file) reduced to the allowed providers. Used as the `two_factor_providers` filter.
	 *
	 * @param mixed $providers Class name => file path.
	 * @return mixed
	 */
	public function restrict_providers( $providers ) {
		if ( ! is_array( $providers ) ) {
			return $providers;
		}
		return array_intersect_key( $providers, array_flip( self::ALLOWED_PROVIDERS ) );
	}

	/**
	 * Profile screen anchor of the Two Factor section.
	 *
	 * @param int $user_id User id.
	 */
	public function settings_url( int $user_id ): string {
		return get_edit_profile_url( $user_id ) . '#two-factor-options';
	}
}
