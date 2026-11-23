<?php
/**
 * Config-as-code for the comment / review options (SEC-012). Two layers:
 * 1. `pre_option_<name>` forces the value at read time, so an admin flip or a plugin `update_option` is ineffective.
 * 2. One-time reconcile of the stored values (admin / WP-CLI `init`, guarded by `_vx_reviews_policy_ver`), so the database,
 *    wp-admin screens and other plugins agree. `wp vextra reviews reconcile` repeats it on demand.
 * Avatars: no remote Gravatar request (e-mail md5 leak, SEC-011); markup renders without an avatar.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Reviews;

use Vextra\Core\Guard\Failsafe;

final class Options {

	private const VERSION_OPTION = '_vx_reviews_policy_ver';

	/**
	 * While true the filters step aside (raw database read / write).
	 *
	 * @var bool
	 */
	private static bool $bypass = false;

	public function register(): void {
		foreach ( array_keys( Config::forced_options() ) as $name ) {
			add_filter( 'pre_option_' . $name, [ $this, 'force' ], 1, 2 );
		}
		add_filter( 'pre_get_avatar_data', [ $this, 'no_avatar' ], 1 );
		add_action( 'init', [ $this, 'maybe_reconcile' ], 5 );
	}

	/**
	 * `pre_option_<name>`: the policy value.
	 *
	 * @param mixed $pre    Short-circuit value (false = read the database).
	 * @param mixed $option Option name.
	 * @return mixed
	 */
	public function force( $pre, $option = '' ) {
		if ( self::$bypass || ! is_string( $option ) ) {
			return $pre;
		}
		$forced = Config::forced_options();
		return $forced[ $option ] ?? $pre;
	}

	/**
	 * `pre_get_avatar_data`: no avatar URL, so `get_avatar()` prints nothing and REST carries no Gravatar host.
	 *
	 * @param mixed $args Avatar arguments.
	 * @return mixed
	 */
	public function no_avatar( $args ) {
		if ( is_array( $args ) ) {
			$args['url']          = false;
			$args['found_avatar'] = false;
		}
		return $args;
	}

	/**
	 * The stored (database) value of an option, ignoring the forced value.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	public static function stored( string $name ) {
		$was          = self::$bypass;
		self::$bypass = true;
		try {
			return get_option( $name, null );
		} finally {
			self::$bypass = $was;
		}
	}

	/**
	 * Options whose stored value differs from the policy.
	 *
	 * @return array<string,string> name => stored value (printable).
	 */
	public static function drift(): array {
		$out = [];
		foreach ( Config::forced_options() as $name => $value ) {
			$stored = self::stored( $name );
			if ( ! is_scalar( $stored ) || (string) $stored !== $value ) {
				$out[ $name ] = is_scalar( $stored ) ? (string) $stored : '(unset)';
			}
		}
		return $out;
	}

	/**
	 * Options whose EFFECTIVE value (after every filter) differs from the policy: something filters the policy away.
	 *
	 * @return array<string,string> name => effective value.
	 */
	public static function overridden(): array {
		$out = [];
		foreach ( Config::forced_options() as $name => $value ) {
			$effective = get_option( $name );
			if ( ! is_scalar( $effective ) || (string) $effective !== $value ) {
				$out[ $name ] = is_scalar( $effective ) ? (string) $effective : '(non-scalar)';
			}
		}
		return $out;
	}

	/**
	 * `init` 5, admin / CLI only: reconcile once per policy version.
	 */
	public function maybe_reconcile(): void {
		if ( ! ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
			return;
		}
		Failsafe::run(
			'reviews-reconcile',
			static function (): void {
				if ( Config::POLICY_VERSION === self::stored( self::VERSION_OPTION ) ) {
					return;
				}
				self::reconcile();
				Closed::close_legacy( false );
			}
		);
	}

	/**
	 * Write the policy values into the database where they differ and stamp the policy version.
	 *
	 * @return string[] Option names changed.
	 */
	public static function reconcile(): array {
		$changed = [];
		foreach ( array_keys( self::drift() ) as $name ) {
			$was          = self::$bypass;
			self::$bypass = true;
			try {
				update_option( $name, Config::forced_options()[ $name ] );
			} finally {
				self::$bypass = $was;
			}
			$changed[] = $name;
		}
		update_option( self::VERSION_OPTION, Config::POLICY_VERSION, false );
		return $changed;
	}
}
