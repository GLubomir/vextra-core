<?php
/**
 * Owner-bot role `vextra_owner_bot` (TASK-029b, D13): the least-privilege account of the owner's own automation.
 *
 * - Caps = exactly CAPS (filter `vextra/core/security/owner_bot_caps`, then stripped of everything forbidden). The marker cap
 *   `vx_owner_bot` is what Guard\Exempt, Abilities and Application Passwords check; the role slug is only a convenience.
 * - `ensure()` creates / reconciles the role (activation, CLI, and on `init` whenever the stored version differs, the role is
 *   gone or its caps drifted). Deactivation keeps the role.
 * - The bot never signs in interactively: wp-login / Woo login refuse it with the generic `vx_invalid_login` error at
 *   `authenticate` priority 1 and again at 999; it has no password reset. Only an Application Password over REST works.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Guard\Failsafe;

final class OwnerBotRole {

	public const ROLE    = 'vextra_owner_bot';
	public const MARKER  = 'vx_owner_bot';
	public const VERSION = '1';
	public const OPTION  = 'vx_security_role_version';

	/**
	 * Exact capability list. Everything else (delete_*, manage_options, manage_woocommerce, edit_shop_orders, user
	 * management, plugin / theme caps, unfiltered_html, blog edit_posts / publish_posts, Rank Math caps) is NOT granted.
	 */
	public const CAPS = [
		'read',
		self::MARKER,
		'upload_files',
		'edit_products',
		'edit_others_products',
		'edit_published_products',
		'edit_private_products',
		'publish_products',
		'read_private_products',
		'assign_product_terms',
		'edit_product_terms',
		'manage_product_terms',
		'edit_pages',
		'edit_published_pages',
		'edit_others_pages',
		'read_private_pages',
		'read_private_shop_orders',
		'view_woocommerce_reports',
	];

	/**
	 * Capabilities no filter may add (defence in depth for the filter).
	 */
	private const FORBIDDEN = [
		'manage_options',
		'manage_woocommerce',
		'edit_shop_orders',
		'list_users',
		'edit_users',
		'create_users',
		'promote_users',
		'remove_users',
		'unfiltered_html',
		'edit_plugins',
		'edit_themes',
		'edit_posts',
		'publish_posts',
		'export',
		'import',
		'manage_categories',
		'switch_themes',
		'customize',
	];

	public function register(): void {
		add_action( 'init', [ $this, 'reconcile' ], 1 );
		add_filter( 'authenticate', [ $this, 'refuse' ], 1, 2 );
		add_filter( 'authenticate', [ $this, 'enforce' ], 999, 1 );
		add_filter( 'allow_password_reset', [ $this, 'no_reset' ], 99, 2 );
	}

	/**
	 * The effective capability list.
	 *
	 * @return string[]
	 */
	public static function caps(): array {
		/**
		 * Filters the owner-bot capabilities. Forbidden capabilities (user management, options, plugins, deletion) are
		 * removed afterwards whatever the filter returns.
		 *
		 * @param string[] $caps Default: OwnerBotRole::CAPS.
		 */
		$caps = apply_filters( 'vextra/core/security/owner_bot_caps', self::CAPS );
		$caps = is_array( $caps ) ? array_values( array_unique( array_filter( array_map( 'strval', $caps ), 'strlen' ) ) ) : self::CAPS;
		$caps = array_values(
			array_filter(
				$caps,
				static fn( string $cap ): bool => ! in_array( $cap, self::FORBIDDEN, true )
					&& 1 !== preg_match( '/^(delete_|install_|activate_|update_|deactivate_|rank_math_)/', $cap )
			)
		);
		return array_values( array_unique( array_merge( [ 'read', self::MARKER ], $caps ) ) );
	}

	/**
	 * Is the account an owner bot (the marker capability decides).
	 *
	 * @param \WP_User|null $user Account.
	 */
	public static function is_bot( ?\WP_User $user ): bool {
		return null !== $user && $user->exists() && user_can( $user, self::MARKER );
	}

	/**
	 * Create the role or bring it back to the exact capability list. Idempotent.
	 */
	public static function ensure(): void {
		$caps = self::caps();
		$role = get_role( self::ROLE );
		if ( null === $role ) {
			add_role( self::ROLE, __( 'Vextra: власний агент', 'vextra-core' ), array_fill_keys( $caps, true ) );
		} else {
			foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
				if ( ! in_array( $cap, $caps, true ) ) {
					$role->remove_cap( (string) $cap );
				}
			}
			foreach ( $caps as $cap ) {
				if ( empty( $role->capabilities[ $cap ] ) ) {
					$role->add_cap( $cap, true );
				}
			}
		}
		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Capabilities held by the role that are not on the list (empty = clean). `null` when the role does not exist.
	 *
	 * @return string[]|null
	 */
	public static function extra_caps(): ?array {
		$role = get_role( self::ROLE );
		if ( null === $role ) {
			return null;
		}
		return array_values( array_diff( array_keys( array_filter( (array) $role->capabilities ) ), self::caps() ) );
	}

	/**
	 * `init`: reconcile when the version changed, the role vanished or its capabilities drifted (in-memory comparison).
	 */
	public function reconcile(): void {
		Failsafe::run(
			'owner-bot-role',
			static function (): void {
				$role = get_role( self::ROLE );
				if ( null === $role || get_option( self::OPTION ) !== self::VERSION ) {
					self::ensure();
					return;
				}
				$have = array_keys( array_filter( (array) $role->capabilities ) );
				$want = self::caps();
				if ( array() !== array_diff( $have, $want ) || array() !== array_diff( $want, $have ) ) {
					self::ensure();
				}
			}
		);
	}

	/**
	 * `authenticate` priority 1: the owner bot cannot sign in with a password, whatever the password is.
	 *
	 * @param mixed $user     Null at this priority.
	 * @param mixed $username Login or e-mail.
	 * @return mixed
	 */
	public function refuse( $user, $username = '' ) {
		if ( $user instanceof \WP_User || $user instanceof \WP_Error || ! is_string( $username ) || '' === trim( $username ) || $this->is_api() ) {
			return $user;
		}
		$account = Failsafe::run( 'owner-bot-find', static fn(): ?\WP_User => Store::find_user( $username ) );
		if ( ! $account instanceof \WP_User || ! self::is_bot( $account ) ) {
			return $user;
		}

		// Never evaluate the password; spend one hash so the answer takes about as long as a wrong password.
		remove_filter( 'authenticate', 'wp_authenticate_username_password', 20 );
		remove_filter( 'authenticate', 'wp_authenticate_email_password', 20 );
		wp_hash_password( wp_generate_password( 24, true, true ) );
		return $this->generic_error();
	}

	/**
	 * `authenticate` priority 999: no other filter may hand an owner bot a session.
	 *
	 * @param mixed $user Result so far.
	 * @return mixed
	 */
	public function enforce( $user ) {
		if ( $user instanceof \WP_User && ! $this->is_api() && self::is_bot( $user ) ) {
			return $this->generic_error();
		}
		return $user;
	}

	/**
	 * `allow_password_reset`: no reset link for the owner bot (its only credential is an Application Password).
	 *
	 * @param mixed $allow   Verdict so far.
	 * @param mixed $user_id User id.
	 * @return mixed
	 */
	public function no_reset( $allow, $user_id = 0 ) {
		$user = is_numeric( $user_id ) ? get_userdata( (int) $user_id ) : false;
		return $user instanceof \WP_User && self::is_bot( $user ) ? false : $allow;
	}

	private function generic_error(): \WP_Error {
		return new \WP_Error( 'vx_invalid_login', __( 'Невірний логін або пароль.', 'vextra-core' ) );
	}

	/**
	 * REST / XML-RPC: Application Password authentication is the one allowed way in (it does not use this error).
	 */
	private function is_api(): bool {
		return ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST );
	}
}
