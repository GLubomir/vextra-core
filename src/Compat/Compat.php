<?php
/**
 * Compat module (TASK-033, TASK-028b): update-safety tooling. CLI (`wp vextra compat`, `wp vextra smoke`) + admin conflict notices.
 *
 * Holds the required-API registry that mirrors `docs/compat/integrations.md` (theme repo). Filter `vextra/core/compat/registry`
 * lets tests append or change entries. Statuses: OK | MISSING (required, fails the run) | WARN (optional missing) |
 * UNVERIFIABLE (hook that cannot be proven at runtime; the smoke markers exercise it).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Compat;

use Vextra\Core\Admin\ConflictNotices;
use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Plugin;

final class Compat implements ModuleInterface {

	/**
	 * Registry plugin key => adapter id.
	 */
	private const ADAPTERS = [
		'woocommerce' => 'woocommerce',
		'polylang'    => 'polylang',
		'rankmath'    => 'rankmath',
		'twofactor'   => 'twofactor',
	];

	public function id(): string {
		return 'compat';
	}

	public function is_enabled(): bool {
		return true;
	}

	public function register(): void {
		if ( is_admin() ) {
			( new ConflictNotices( $this->conflicts() ) )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new Cli( $this ) )->register();
		}
	}

	/**
	 * Conflict rule registry (owned by the kernel so the yield filter exists before the module loop).
	 */
	public function conflicts(): Conflicts {
		return Plugin::instance()->conflicts();
	}

	/**
	 * Required-API registry.
	 *
	 * Entry: plugin, kind (function|class|const|method|hook|rest|option), name, required, used_by, fallback, class (method only).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function registry(): array {
		$e = static function ( string $plugin, string $kind, string $name, bool $required, string $used_by, string $fallback, string $owner = '' ): array {
			return [
				'plugin'   => $plugin,
				'kind'     => $kind,
				'name'     => $name,
				'required' => $required,
				'used_by'  => $used_by,
				'fallback' => $fallback,
				'class'    => $owner,
			];
		};

		$entries = [
			// WordPress core (non-obvious only).
			$e( 'wordpress', 'function', 'wp_get_environment_type', true, 'core Dev, Smoke', 'Dev module disabled' ),
			$e( 'wordpress', 'function', 'header_register_callback', false, 'core Dev', 'preview header not sent' ),
			$e( 'wordpress', 'function', 'rest_get_server', false, 'core Compat', 'route check unverifiable' ),
			$e( 'wordpress', 'function', 'wp_prime_option_caches', false, 'theme ProductCardData', 'one query per option' ),
			$e( 'wordpress', 'function', 'wp_using_ext_object_cache', true, 'theme ProductCardData, Support/Cache', 'none (core function)' ),
			$e( 'wordpress', 'function', 'rest_convert_error_to_response', true, 'core Guard StoreApi', 'none (core function)' ),
			$e( 'wordpress', 'function', 'wp_is_password_reset_allowed_for_user', false, 'core Security LostPassword', 'accounts that may not reset get a different message' ),
			$e( 'wordpress', 'function', 'wp_authenticate_username_password', false, 'core Security LoginThrottle (remove_filter on a locked request)', 'password evaluated, lock error still returned' ),
			$e( 'wordpress', 'function', 'wp_authenticate_email_password', false, 'core Security LoginThrottle (remove_filter on a locked request)', 'password evaluated, lock error still returned' ),
			$e( 'wordpress', 'rest', '/wp/v2/users', true, 'core Security UserEnumeration (blocked for anonymous)', 'users listable (SEC-006)' ),
			$e( 'wordpress', 'rest', '/wp-abilities/v1/abilities', false, 'core Security Abilities (listing closed for customers)', 'nothing to close' ),
			$e( 'wordpress', 'function', 'wp_is_application_passwords_available_for_user', false, 'core Security AppPasswords (filter of the same name)', 'every user may create Application Passwords (SEC-015)' ),
			$e( 'wordpress', 'class', 'WP_Application_Passwords', false, 'core Security Cli (owner-bot create / revoke / list / repair)', 'owner-bot commands fail with a PHP error' ),
			$e( 'wordpress', 'class', 'WP_Session_Tokens', false, 'core Security TwoFactorPolicy (session-scoped notice dismissal)', 'notice cannot be dismissed' ),

			// WooCommerce.
			$e( 'woocommerce', 'class', 'WooCommerce', true, 'core WooCommerce adapter, theme Speculation', 'Woo modules disabled' ),
			$e( 'woocommerce', 'const', 'WC_VERSION', false, 'core WooCommerce adapter', 'version shown empty' ),
			$e( 'woocommerce', 'class', 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil', true, 'core Plugin (HPOS + blocks declaration)', 'Woo shows an incompatibility notice' ),
			$e( 'woocommerce', 'class', 'WC_Product', true, 'core Multilang/Seo, theme ProductCardData', 'none (Woo core class)' ),
			$e( 'woocommerce', 'class', 'WC_Order', true, 'core Multilang OrderLanguage', 'order e-mails not translated' ),
			$e( 'woocommerce', 'class', 'WC_Product_Attribute', true, 'core Multilang ProductSync', 'twin attributes not synced' ),
			$e( 'woocommerce', 'function', 'WC', false, 'theme HeaderCart, header', 'cart count empty' ),
			$e( 'woocommerce', 'function', 'wc_get_product', true, 'core Multilang/Seo, theme ProductCardData', 'module disabled / card not rendered' ),
			$e( 'woocommerce', 'function', 'wc_get_products', true, 'core Multilang ProductSync', 'sync skipped' ),
			$e( 'woocommerce', 'function', 'wc_get_order', true, 'core Multilang OrderLanguage', 'order language not stored' ),
			$e( 'woocommerce', 'function', 'wc_get_page_id', true, 'core Seo, theme tiles/Speculation, Smoke', 'shop breadcrumb / sitemap entry skipped' ),
			$e( 'woocommerce', 'function', 'wc_get_page_permalink', true, 'theme Header/Data', 'link falls back to home' ),
			$e( 'woocommerce', 'function', 'wc_get_cart_url', true, 'theme Header/Data', 'link omitted' ),
			$e( 'woocommerce', 'function', 'wc_get_price_to_display', true, 'core Seo Templates, theme ProductCardData', 'price not shown' ),
			$e( 'woocommerce', 'function', 'wc_price', true, 'theme ProductCardData', 'price label empty' ),
			$e( 'woocommerce', 'function', 'wc_get_logger', false, 'core Support/Log', 'error_log fallback' ),
			$e( 'woocommerce', 'function', 'wc_recount_all_terms', false, 'core Seo Cli', 'command reports it missing' ),
			$e( 'woocommerce', 'function', 'wc_update_product_stock', true, 'core Multilang StockSync', 'stock sync off, twins drift' ),
			$e( 'woocommerce', 'function', 'woocommerce_content', true, 'theme woocommerce.php', 'template unused without Woo' ),
			$e( 'woocommerce', 'function', 'is_shop', true, 'core Seo Templates, theme header', 'treated as not shop' ),
			$e( 'woocommerce', 'function', 'is_cart', true, 'core Multilang Hreflang, theme header', 'hreflang printed on cart' ),
			$e( 'woocommerce', 'function', 'is_checkout', true, 'core Multilang Hreflang', 'hreflang printed on checkout' ),
			$e( 'woocommerce', 'function', 'is_account_page', true, 'core Multilang Hreflang, theme header', 'hreflang printed on account' ),
			$e( 'woocommerce', 'function', 'is_product_taxonomy', false, 'theme bottom-bar', 'state not highlighted' ),
			$e( 'woocommerce', 'rest', '/wc/store/v1/cart', true, 'theme header (cart count hydration)', 'cart count not shown' ),
			$e( 'woocommerce', 'option', 'woocommerce_shop_page_id', true, 'core Multilang WooPages, Smoke', 'shop page mapping skipped' ),
			$e( 'woocommerce', 'option', 'woocommerce_cart_page_id', true, 'core Multilang WooPages, Smoke', 'cart page mapping skipped' ),
			$e( 'woocommerce', 'option', 'woocommerce_myaccount_page_id', true, 'core Multilang WooPages, Smoke', 'account page mapping skipped' ),
			$e( 'woocommerce', 'hook', 'woocommerce_new_product', true, 'core Multilang ProductSync', 'no automatic twin' ),
			$e( 'woocommerce', 'hook', 'woocommerce_update_product', true, 'core Multilang ProductSync', 'twins drift' ),
			$e( 'woocommerce', 'hook', 'woocommerce_product_set_stock', true, 'core Multilang StockSync', 'stock drift' ),
			$e( 'woocommerce', 'hook', 'woocommerce_checkout_create_order', true, 'core Multilang OrderLanguage', 'order language missing' ),
			$e( 'woocommerce', 'hook', 'woocommerce_store_api_checkout_update_order_meta', true, 'core Multilang OrderLanguage', 'order language missing' ),
			$e( 'woocommerce', 'hook', 'woocommerce_enqueue_styles', false, 'theme Woo/Assets', 'Woo styles load next to ours' ),
			$e( 'woocommerce', 'function', 'wc_get_orders', false, 'core Guard DraftOrders', 'draft-order purge skipped' ),
			$e( 'woocommerce', 'function', 'as_schedule_recurring_action', false, 'core Guard DraftOrders (Action Scheduler bundled with Woo)', 'draft-order purge not scheduled' ),
			$e( 'woocommerce', 'function', 'as_has_scheduled_action', false, 'core Guard DraftOrders, Security LostPassword (pending reset job dedup)', 'draft-order purge not scheduled; duplicate reset jobs possible (bounded by the quotas)' ),
			$e( 'woocommerce', 'function', 'as_unschedule_all_actions', false, 'core Guard DraftOrders (deactivation)', 'schedule stays until Woo is removed' ),
			$e( 'woocommerce', 'function', 'as_enqueue_async_action', false, 'core Guard DraftOrders, Security LostPassword (reset mail job)', 'backlog drains one batch per day; reset mail sent synchronously, failure swallowed' ),
			$e( 'woocommerce', 'function', 'as_schedule_single_action', false, 'core Security LostPassword (single retry of a failed reset mail)', 'no retry' ),
			$e( 'woocommerce', 'hook', 'woocommerce_reset_password_notification', false, 'core Security LostPassword (Woo reset mail job)', 'Woo reset mail job fails (logged), visitor still sees the neutral page' ),
			$e( 'woocommerce', 'hook', 'woocommerce_store_api_rate_limit_options', true, 'core Guard StoreApi', 'Store API limiter off (wc-ajax, coupon, REST, search buckets keep working)' ),
			$e( 'woocommerce', 'hook', 'woocommerce_store_api_rate_limit_id', false, 'core Guard StoreApi', 'Woo keys anonymous clients by REMOTE_ADDR itself' ),
			$e( 'woocommerce', 'rest', '/wc/store/v1/products', false, 'core Guard StoreApi (blocked for anonymous)', 'nothing to block' ),
			$e( 'woocommerce', 'rest', '/wc/store/v1/cart/apply-coupon', false, 'core Guard StoreApi (coupon bucket)', 'coupon bucket unused for the Store API' ),
			$e( 'woocommerce', 'function', 'wc_add_notice', false, 'core Security LostPassword (neutral Woo path)', 'validator error not shown' ),
			$e( 'woocommerce', 'function', 'wc_get_account_endpoint_url', true, 'core Security LostPassword (neutral Woo path)', 'neutral Woo path unavailable' ),
			$e( 'woocommerce', 'hook', 'woocommerce_lost_password_confirmation_message', false, 'core Security LostPassword', 'Woo shows its own confirmation text' ),
			$e( 'woocommerce', 'option', 'woocommerce_enable_reviews', false, 'core Reviews Options (forced)', 'stock Woo review settings' ),
			$e( 'woocommerce', 'option', 'woocommerce_enable_review_rating', false, 'core Reviews Options (forced)', 'stock Woo review settings' ),
			$e( 'woocommerce', 'option', 'woocommerce_review_rating_required', false, 'core Reviews Options (forced)', 'stock Woo review settings' ),
			$e( 'woocommerce', 'option', 'woocommerce_review_rating_verification_required', false, 'core Reviews Options (forced)', 'stock Woo review settings' ),
			$e( 'woocommerce', 'option', 'woocommerce_review_rating_verification_label', false, 'core Reviews Options (forced)', 'stock Woo review settings' ),
			$e( 'woocommerce', 'hook', 'woocommerce_before_single_product', false, 'core Reviews Form', 'Woo notice stays English / plain' ),
			$e( 'woocommerce', 'hook', 'woocommerce_product_review_comment_form_args', false, 'core Reviews Form', 'form shown to a buyer who already reviewed (server refuses a second review)' ),
			$e( 'woocommerce', 'hook', 'woocommerce_rest_prepare_product_review', false, 'core Reviews Gate', 'reviewer e-mail visible only to Woo-permitted readers' ),
			$e( 'woocommerce', 'hook', 'woocommerce_order_status_changed', false, 'core Reviews Buyers', 'buyer set not refreshed on a status change (rebuilt on user-meta loss only)' ),
			$e( 'woocommerce', 'hook', 'woocommerce_before_delete_order', false, 'core Reviews Buyers', 'buyer set not refreshed on delete' ),
			$e( 'woocommerce', 'hook', 'woocommerce_before_trash_order', false, 'core Reviews Buyers', 'buyer set not refreshed on trash' ),
			$e( 'woocommerce', 'hook', 'woocommerce_untrash_order', false, 'core Reviews Buyers', 'buyer set not refreshed on untrash' ),
			$e( 'woocommerce', 'hook', 'woocommerce_order_refunded', false, 'core Reviews Buyers', 'buyer set not refreshed on a refund' ),
			$e( 'woocommerce', 'hook', 'woocommerce_before_order_object_save', false, 'core Reviews Buyers', 'customer reassignment not noticed (stale buyer set)' ),
			$e( 'woocommerce', 'hook', 'woocommerce_login_form', false, 'core Security TurnstileForms (only with Turnstile keys)', 'no widget on the Woo login form (keep Turnstile keys unset)' ),
			$e( 'woocommerce', 'hook', 'woocommerce_register_form', false, 'core Security TurnstileForms (only with Turnstile keys)', 'no widget on the Woo register form' ),
			$e( 'woocommerce', 'hook', 'woocommerce_lostpassword_form', false, 'core Security TurnstileForms (only with Turnstile keys)', 'no widget on the Woo lost-password form' ),
			$e( 'woocommerce', 'hook', 'woocommerce_registration_errors', false, 'core Security TurnstileForms (only with Turnstile keys)', 'registration not challenged (registration is off)' ),

			// Polylang.
			$e( 'polylang', 'const', 'POLYLANG_VERSION', true, 'core Polylang adapter', 'adapter unavailable' ),
			$e( 'polylang', 'function', 'PLL', true, 'core Polylang adapter', 'adapter not ready' ),
			$e( 'polylang', 'function', 'pll_current_language', true, 'core adapter, theme (direct)', 'default language behaviour' ),
			$e( 'polylang', 'function', 'pll_default_language', true, 'core adapter, theme (direct)', 'single language' ),
			$e( 'polylang', 'function', 'pll_languages_list', true, 'core adapter, theme (direct)', 'single language' ),
			$e( 'polylang', 'function', 'pll_home_url', true, 'core adapter, theme (direct)', 'home_url() fallback' ),
			$e( 'polylang', 'function', 'pll_get_post', true, 'core adapter, theme (direct)', 'untranslated id used' ),
			$e( 'polylang', 'function', 'pll_get_term', true, 'core adapter, theme (direct)', 'untranslated id used' ),
			$e( 'polylang', 'function', 'pll_get_post_language', true, 'core adapter', 'empty language' ),
			$e( 'polylang', 'function', 'pll_get_term_language', true, 'core adapter, theme CategoryMenuFields', 'empty language' ),
			$e( 'polylang', 'function', 'pll_get_post_translations', true, 'core adapter', 'empty group' ),
			$e( 'polylang', 'function', 'pll_get_term_translations', true, 'core adapter', 'empty group' ),
			$e( 'polylang', 'function', 'pll_set_post_language', true, 'core adapter', 'twin creation skipped' ),
			$e( 'polylang', 'function', 'pll_save_post_translations', true, 'core adapter', 'twin creation skipped' ),
			$e( 'polylang', 'function', 'pll_the_languages', true, 'theme Header/Data (direct)', 'no language switcher' ),
			$e( 'polylang', 'function', 'pll__', true, 'theme Header/Footer Customizer (direct)', 'source string shown' ),
			$e( 'polylang', 'function', 'pll_register_string', true, 'theme Header/Footer Customizer (direct)', 'strings not translatable' ),
			$e( 'polylang', 'hook', 'pll_rel_hreflang_attributes', true, 'core Multilang Hreflang', 'Polylang default hreflang' ),
			$e( 'polylang', 'hook', 'pll_save_post', true, 'core Multilang ProductSync, theme HeroData', 'twins drift' ),

			// Rank Math.
			$e( 'rankmath', 'const', 'RANK_MATH_VERSION', true, 'core RankMath adapter', 'adapter unavailable' ),
			$e( 'rankmath', 'class', 'RankMath\\Helper', false, 'core RankMath adapter', 'setting assumed default' ),
			$e( 'rankmath', 'class', 'RankMath\\Term', false, 'core RankMath adapter', 'treated as single term' ),
			$e( 'rankmath', 'class', 'RankMath\\Sitemap\\Cache', true, 'core RankMath adapter', 'stale sitemap until TTL' ),
			$e( 'rankmath', 'method', 'invalidate_storage', true, 'core RankMath adapter', 'stale sitemap until TTL', 'RankMath\\Sitemap\\Cache' ),
			$e( 'rankmath', 'method', 'get_settings', false, 'core RankMath adapter (setting(), conflict rules)', 'assumed off', 'RankMath\\Helper' ),
			$e( 'rankmath', 'method', 'is_multiple_terms_query', false, 'core RankMath adapter', 'treated as single term', 'RankMath\\Term' ),
			$e( 'rankmath', 'hook', 'rank_math/frontend/robots', true, 'core RankMath adapter', 'default robots' ),
			$e( 'rankmath', 'hook', 'rank_math/frontend/title', true, 'core RankMath adapter, Seo Templates', 'default titles' ),
			$e( 'rankmath', 'hook', 'rank_math/frontend/description', true, 'core RankMath adapter, Seo Templates', 'default descriptions' ),
			$e( 'rankmath', 'hook', 'rank_math/frontend/breadcrumb/items', true, 'core RankMath adapter, Seo Breadcrumbs', 'default trail' ),
			$e( 'rankmath', 'hook', 'rank_math/sitemap/entry', true, 'core RankMath adapter, Seo Sitemap', 'default entries' ),
			$e( 'rankmath', 'hook', 'rank_math/sitemap/posts_to_exclude', true, 'core RankMath adapter, Seo Sitemap', 'noindex pages listed' ),
			$e( 'rankmath', 'hook', 'rank_math/snippet/rich_snippet_product_entity', true, 'core RankMath adapter, Seo Schema', 'default schema' ),
			$e( 'rankmath', 'hook', 'rank_math/opengraph/slack_enhanced_data', false, 'core RankMath adapter, Security AuthorExposure (SEC-044)', 'author "Written by" pair stays in the twitter meta' ),

			// Two Factor (wordpress.org, optional: soft staff-2FA policy).
			$e( 'twofactor', 'class', 'Two_Factor_Core', false, 'core TwoFactor adapter', 'staff 2FA not offered; rule staff-without-2fa reports it' ),
			$e( 'twofactor', 'method', 'is_user_using_two_factor', false, 'core TwoFactor adapter', 'every staff user counted as without 2FA', 'Two_Factor_Core' ),
			$e( 'twofactor', 'const', 'TWO_FACTOR_VERSION', false, 'core TwoFactor adapter', 'version shown empty' ),
			$e( 'twofactor', 'hook', 'two_factor_providers', false, 'core Security TwoFactorPolicy (TOTP + Backup Codes only)', 'all providers (incl. e-mail codes) offered' ),
		];

		/**
		 * Filters the required-API registry (tests append e.g. a bogus required function).
		 *
		 * @param array<int,array<string,mixed>> $entries Registry entries.
		 */
		$filtered = apply_filters( 'vextra/core/compat/registry', $entries );
		return is_array( $filtered ) ? array_values( $filtered ) : $entries;
	}

	/**
	 * Run every registry check.
	 *
	 * @return array<int,array<string,mixed>> Entry fields + `status` + `note`.
	 */
	public function check_apis(): array {
		$rows = [];
		foreach ( $this->registry() as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['plugin'], $entry['kind'], $entry['name'] ) ) {
				continue;
			}
			$entry           = array_merge(
				[
					'required' => false,
					'used_by'  => '',
					'fallback' => '',
					'class'    => '',
				],
				$entry
			);
			$result          = $this->check_entry( $entry );
			$entry['status'] = $result[0];
			$entry['note']   = $result[1];
			$rows[]          = $entry;
		}
		return $rows;
	}

	/**
	 * Plugins whose adapter reports unavailable (reported once per run).
	 *
	 * @return string[] Registry plugin keys.
	 */
	public function unavailable_plugins(): array {
		$out = [];
		foreach ( array_keys( self::ADAPTERS ) as $plugin ) {
			if ( ! $this->plugin_available( $plugin ) ) {
				$out[] = $plugin;
			}
		}
		return $out;
	}

	/**
	 * Installed versions by matrix component key.
	 *
	 * @return array<string,string|null>
	 */
	public function installed_versions(): array {
		$kernel = Plugin::instance();
		$ver    = static function ( string $id ) use ( $kernel ): ?string {
			$integration = $kernel->integration( $id );
			return null !== $integration && $integration->is_available() ? $integration->version() : null;
		};
		return [
			'wordpress'   => get_bloginfo( 'version' ),
			'woocommerce' => $ver( 'woocommerce' ),
			'polylang'    => $ver( 'polylang' ),
			'rank-math'   => $ver( 'rankmath' ),
			'php'         => PHP_VERSION,
		];
	}

	/**
	 * Is the third-party plugin usable (adapter verdict; WordPress itself is always present).
	 *
	 * @param string $plugin Registry plugin key.
	 */
	private function plugin_available( string $plugin ): bool {
		if ( ! isset( self::ADAPTERS[ $plugin ] ) ) {
			return true;
		}
		$integration = Plugin::instance()->integration( self::ADAPTERS[ $plugin ] );
		return null !== $integration && $integration->is_available();
	}

	/**
	 * Check one entry.
	 *
	 * @param array<string,mixed> $entry Normalised entry.
	 * @return array{0:string,1:string} Status, note.
	 */
	private function check_entry( array $entry ): array {
		$plugin   = (string) $entry['plugin'];
		$kind     = (string) $entry['kind'];
		$name     = (string) $entry['name'];
		$required = (bool) $entry['required'];

		if ( ! $this->plugin_available( $plugin ) ) {
			return [ $required ? 'MISSING' : 'WARN', $plugin . ' not available' ];
		}

		switch ( $kind ) {
			case 'function':
				$present = function_exists( $name );
				break;
			case 'class':
				$present = class_exists( $name );
				break;
			case 'const':
				$present = defined( $name );
				break;
			case 'method':
				$present = class_exists( (string) $entry['class'] ) && method_exists( (string) $entry['class'], $name );
				break;
			case 'option':
				$value   = get_option( $name, null );
				$present = null !== $value && '' !== $value && '0' !== (string) $value;
				break;
			case 'rest':
				if ( ! function_exists( 'rest_get_server' ) ) {
					return [ 'UNVERIFIABLE', 'REST server unavailable' ];
				}
				$present = array_key_exists( $name, rest_get_server()->get_routes() );
				break;
			case 'hook':
				// A hook cannot be proven without reading third-party code (forbidden): attached = OK, otherwise unverifiable.
				return false !== has_filter( $name ) ? [ 'OK', 'attached' ] : [ 'UNVERIFIABLE', 'not attached in this context' ];
			default:
				return [ 'UNVERIFIABLE', 'unknown kind ' . $kind ];
		}

		if ( $present ) {
			return [ 'OK', '' ];
		}
		return [ $required ? 'MISSING' : 'WARN', 'not found' ];
	}
}
