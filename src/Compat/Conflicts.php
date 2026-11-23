<?php
/**
 * Conflict rules (TASK-028b): detects plugins / plugin settings that collide with a vextra-core feature and either
 * makes the affected module YIELD (`yield`) or only reports it (`warn`, admin notice + `wp vextra compat`).
 *
 * Rule: id, title, module (affected module id), detector (callable: bool|string), action (`yield`|`warn`), blocking (bool),
 * fix_hint, scope (`plugins` = active-plugin list only, runs everywhere; `settings` = reads plugin settings, admin + CLI only).
 * `title` and `fix_hint` may be callables (translated lazily; the text domain is not loaded at `plugins_loaded`).
 * Rules live in ONE list (`rules()`), filter `vextra/core/compat/conflict_rules` adds / changes them. Docs: docs/system/modules/compat.md.
 *
 * Front-end cost: only the `vextra/core/module_enabled` filter runs (yield rules, active-plugin option, no queries).
 * Yield keeps no state: removing the conflicting plugin re-enables the module on the next request.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Compat;

use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;
use Vextra\Core\Security\TwoFactorPolicy;
use Vextra\Core\Support\Log;

final class Conflicts {

	public const ACTION_YIELD   = 'yield';
	public const ACTION_WARN    = 'warn';
	public const SCOPE_PLUGINS  = 'plugins';
	public const SCOPE_SETTINGS = 'settings';

	/**
	 * Plugin folders (slug) of competing plugins by group. Filter `vextra/core/compat/conflict_rules` can still add rules.
	 */
	public const PLUGINS = [
		'seo'       => [ 'wordpress-seo', 'wordpress-seo-premium', 'all-in-one-seo-pack', 'all-in-one-seo-pack-pro', 'wp-seopress', 'wp-seopress-pro', 'autodescription' ],
		'multilang' => [ 'sitepress-multilingual-cms', 'translatepress-multilingual', 'weglot', 'qtranslate-xt', 'multilingual-press' ],
		'redirect'  => [ 'redirection', 'safe-redirect-manager', 'simple-301-redirects' ],
		'wishlist'  => [ 'yith-woocommerce-wishlist', 'yith-woocommerce-wishlist-premium', 'ti-woocommerce-wishlist', 'ti-woocommerce-wishlist-premium' ],
		'security'  => [ 'wordfence', 'better-wp-security', 'all-in-one-wp-security-and-firewall' ],
		'login'     => [ 'limit-login-attempts-reloaded', 'loginizer', 'wps-limit-login', 'login-lockdown', 'wp-cerber' ],
		// Our own choice, `two-factor`, is never a conflict. Slugs verified on wordpress.org (2026-11).
		'twofactor' => [ 'wp-2fa', 'two-factor-authentication', 'miniorange-2-factor-authentication', 'google-authenticator', 'rublon' ],
		'turnstile' => [ 'simple-cloudflare-turnstile', 'kitgenix-captcha-for-cloudflare-turnstile', 'wppool-turnstile-captcha-spam-filter', 'hcaptcha-for-forms-and-more' ],
	];

	/**
	 * Modules that yielded in this request.
	 *
	 * @var string[]
	 */
	private array $yielded = [];

	/**
	 * Hook the module-enabled filter (priority 1). Called by the kernel BEFORE the module loop (module order matters).
	 */
	public function register(): void {
		add_filter( 'vextra/core/module_enabled', [ $this, 'filter_enabled' ], 1, 2 );
	}

	/**
	 * Yield rules: switch the module off while a conflicting plugin is active. Plugin-list detectors only.
	 *
	 * @param mixed $enabled Verdict of the module.
	 * @param mixed $id      Module id.
	 * @return mixed
	 */
	public function filter_enabled( $enabled, $id = '' ) {
		if ( true !== $enabled || ! is_string( $id ) ) {
			return $enabled;
		}
		foreach ( $this->rules() as $rule ) {
			if ( self::ACTION_YIELD !== $rule['action'] || self::SCOPE_PLUGINS !== $rule['scope'] || $id !== $rule['module'] ) {
				continue;
			}
			if ( false === $this->detect( $rule ) ) {
				continue;
			}
			if ( ! in_array( $id, $this->yielded, true ) ) {
				$this->yielded[] = $id;
				Log::warning(
					'Module yields to a conflicting plugin',
					[
						'module' => $id,
						'rule'   => $rule['id'],
					],
					'compat'
				);
			}
			return false;
		}
		return $enabled;
	}

	/**
	 * Module ids that yielded in this request.
	 *
	 * @return string[]
	 */
	public function yielded(): array {
		return $this->yielded;
	}

	/**
	 * Normalised rule list (built-ins + filter).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function rules(): array {
		$rules = [
			[
				'id'       => 'rankmath-post-redirect',
				'title'    => static fn(): string => __( 'Rank Math «Auto Post Redirect» увімкнено, а редиректи слагів веде vextra-core', 'vextra-core' ),
				'module'   => 'seo',
				'detector' => fn() => $this->detect_rankmath_post_redirect(),
				'action'   => self::ACTION_WARN,
				'blocking' => true,
				'fix_hint' => static fn(): string => __( 'Rank Math → Загальні налаштування → Редиректи → «Auto Post Redirect» вимкнути.', 'vextra-core' ),
				'scope'    => self::SCOPE_SETTINGS,
			],
			[
				'id'       => 'duplicate-seo-plugin',
				'title'    => static fn(): string => __( 'Активний другий SEO-плагін: підтримуваний лише Rank Math', 'vextra-core' ),
				'module'   => 'seo',
				'detector' => fn() => $this->detect_plugins( self::PLUGINS['seo'] ),
				'action'   => self::ACTION_WARN,
				'blocking' => true,
				'fix_hint' => static fn(): string => __( 'Деактивуйте плагін, що дублює Rank Math (title, schema, sitemap, редиректи).', 'vextra-core' ),
				'scope'    => self::SCOPE_PLUGINS,
			],
			[
				'id'       => 'duplicate-multilang-plugin',
				'title'    => static fn(): string => __( 'Активний інший мультимовний плагін: модуль multilang вимкнено', 'vextra-core' ),
				'module'   => 'multilang',
				'detector' => fn() => $this->detect_plugins( self::PLUGINS['multilang'] ),
				'action'   => self::ACTION_YIELD,
				'blocking' => false,
				'fix_hint' => static fn(): string => __( 'Підтримуваний мультимовний плагін — Polylang. Деактивуйте конфліктний плагін, модуль увімкнеться сам.', 'vextra-core' ),
				'scope'    => self::SCOPE_PLUGINS,
			],
			[
				'id'       => 'duplicate-redirect-plugin',
				'title'    => static fn(): string => __( 'Активний плагін редиректів поруч із редиректами vextra-core', 'vextra-core' ),
				'module'   => 'seo',
				'detector' => fn() => $this->module_active( 'seo' ) ? $this->detect_plugins( self::PLUGINS['redirect'] ) : false,
				'action'   => self::ACTION_WARN,
				'blocking' => false,
				'fix_hint' => static fn(): string => __( 'Редиректи слагів і термінів веде vextra-core (через Rank Math). Перевірте, що правила не дублюються.', 'vextra-core' ),
				'scope'    => self::SCOPE_PLUGINS,
			],
			[
				'id'       => 'duplicate-wishlist-plugin',
				'title'    => static fn(): string => __( 'Активний плагін списку бажань (власна функція планується)', 'vextra-core' ),
				'module'   => 'none',
				'detector' => fn() => $this->detect_plugins( self::PLUGINS['wishlist'] ),
				'action'   => self::ACTION_WARN,
				'blocking' => false,
				'fix_hint' => static fn(): string => __( 'Інформаційно: після появи власного списку бажань плагін доведеться вимкнути.', 'vextra-core' ),
				'scope'    => self::SCOPE_PLUGINS,
			],
			[
				'id'       => 'duplicate-guard-plugin',
				'title'    => static fn(): string => __( 'Активний плагін безпеки з лімітами запитів поруч із модулем guard', 'vextra-core' ),
				'module'   => 'guard',
				'detector' => fn() => $this->module_active( 'guard' ) ? $this->detect_plugins( self::PLUGINS['security'] ) : false,
				'action'   => self::ACTION_WARN,
				'blocking' => false,
				'fix_hint' => static fn(): string => __( 'Вимкніть у плагіні безпеки ліміти запитів і правила robots.txt, щоб не подвоювати 429/403.', 'vextra-core' ),
				'scope'    => self::SCOPE_PLUGINS,
			],
			[
				'id'       => 'duplicate-login-protection-plugin',
				'title'    => static fn(): string => __( 'Активний плагін захисту входу поряд із модулем security (ліміти спроб входу, XML-RPC, версії)', 'vextra-core' ),
				'module'   => 'security',
				'detector' => fn() => $this->module_active( 'security' ) ? $this->detect_plugins( array_merge( self::PLUGINS['login'], self::PLUGINS['security'] ) ) : false,
				'action'   => self::ACTION_WARN,
				'blocking' => false,
				'fix_hint' => static fn(): string => __( 'Задайте VX_SECURITY_LOGIN_IP_LIMIT і VX_SECURITY_LOGIN_USER_LIMIT = 0 у wp-config.php або вимкніть інший плагін, щоб не подвоювати блокування входу.', 'vextra-core' ),
				'scope'    => self::SCOPE_PLUGINS,
			],
			[
				'id'       => 'duplicate-2fa-plugin',
				'title'    => static fn(): string => __( 'Активний другий плагін двофакторної автентифікації поряд із «Two Factor»', 'vextra-core' ),
				'module'   => 'security',
				'detector' => fn() => $this->module_active( 'security' ) ? $this->detect_plugins( self::PLUGINS['twofactor'] ) : false,
				'action'   => self::ACTION_WARN,
				'blocking' => false,
				'fix_hint' => static fn(): string => __( 'Залиште один плагін 2FA (підтримуваний: «Two Factor»): два плагіни борються за хуки authenticate / wp_login.', 'vextra-core' ),
				'scope'    => self::SCOPE_PLUGINS,
			],
			[
				'id'       => 'duplicate-turnstile-plugin',
				'title'    => static fn(): string => __( 'Активний плагін капчі / Turnstile поряд із вбудованою перевіркою vextra-core', 'vextra-core' ),
				'module'   => 'security',
				'detector' => fn() => $this->module_active( 'security' ) ? $this->detect_plugins( self::PLUGINS['turnstile'] ) : false,
				'action'   => self::ACTION_WARN,
				'blocking' => false,
				'fix_hint' => static fn(): string => __( 'Вимкніть капчу на формах входу в іншому плагіні або деактивуйте його: інакше на формі буде два віджети.', 'vextra-core' ),
				'scope'    => self::SCOPE_PLUGINS,
			],
			[
				'id'       => 'staff-without-2fa',
				'title'    => static fn(): string => __( 'ЗАПУСК ЗАБЛОКОВАНО: персонал без двофакторної автентифікації', 'vextra-core' ),
				'module'   => 'security',
				'detector' => fn() => $this->detect_staff_without_two_factor(),
				'action'   => self::ACTION_WARN,
				'blocking' => false,
				'fix_hint' => static fn(): string => __( 'Активуйте плагін «Two Factor» і налаштуйте TOTP + резервні коди кожному користувачу з правами керування (профіль → Two-Factor Options). Обов’язково до запуску.', 'vextra-core' ),
				'scope'    => self::SCOPE_SETTINGS,
			],
			[
				'id'       => 'author-archives-enabled',
				'title'    => static fn(): string => __( 'У Rank Math не вимкнено архіви авторів: логін автора може потрапити в індекс', 'vextra-core' ),
				'module'   => 'security',
				'detector' => fn() => $this->detect_author_archives(),
				'action'   => self::ACTION_WARN,
				'blocking' => false,
				'fix_hint' => static fn(): string => __( 'Rank Math → Заголовки та мета → Автори → «Вимкнути архіви авторів» увімкнути.', 'vextra-core' ),
				'scope'    => self::SCOPE_SETTINGS,
			],
			[
				'id'       => 'rankmath-robots-editor',
				'title'    => static fn(): string => __( 'У Rank Math заповнено редактор robots.txt: він замінює базовий текст, у якому guard об’єднує свої правила', 'vextra-core' ),
				'module'   => 'guard',
				'detector' => fn() => $this->detect_rankmath_robots_editor(),
				'action'   => self::ACTION_WARN,
				'blocking' => false,
				'fix_hint' => static fn(): string => __( 'Залиште в редакторі рядок «Sitemap:» або очистіть редактор (Rank Math → Загальні налаштування → Редактор robots.txt).', 'vextra-core' ),
				'scope'    => self::SCOPE_SETTINGS,
			],
		];

		/**
		 * Filters the conflict rules (add own rules, tests).
		 *
		 * @param array<int,array<string,mixed>> $rules Rules (see class docblock).
		 */
		$filtered = apply_filters( 'vextra/core/compat/conflict_rules', $rules );
		$filtered = is_array( $filtered ) ? $filtered : $rules;

		$out = [];
		foreach ( $filtered as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['id'], $rule['detector'] ) || ! is_callable( $rule['detector'] ) ) {
				continue;
			}
			$action = isset( $rule['action'] ) && self::ACTION_YIELD === $rule['action'] ? self::ACTION_YIELD : self::ACTION_WARN;
			$out[]  = [
				'id'       => sanitize_key( (string) $rule['id'] ),
				'title'    => $rule['title'] ?? (string) $rule['id'],
				'module'   => isset( $rule['module'] ) ? sanitize_key( (string) $rule['module'] ) : 'none',
				'detector' => $rule['detector'],
				'action'   => $action,
				'blocking' => ! empty( $rule['blocking'] ),
				'fix_hint' => $rule['fix_hint'] ?? '',
				'scope'    => isset( $rule['scope'] ) && self::SCOPE_SETTINGS === $rule['scope'] ? self::SCOPE_SETTINGS : self::SCOPE_PLUGINS,
			];
		}
		return $out;
	}

	/**
	 * Evaluate every rule (admin / CLI). Settings rules are skipped on the front-end.
	 *
	 * @return array<int,array<string,mixed>> id, title, module, action, blocking, fix_hint, active, detail, yielded.
	 */
	public function evaluate(): array {
		$allow_settings = is_admin() || ( defined( 'WP_CLI' ) && WP_CLI );
		$rows           = [];
		foreach ( $this->rules() as $rule ) {
			if ( self::SCOPE_SETTINGS === $rule['scope'] && ! $allow_settings ) {
				continue;
			}
			$result = $this->detect( $rule );
			$active = false !== $result;
			$rows[] = [
				'id'       => $rule['id'],
				'title'    => $this->text( $rule['title'] ),
				'module'   => $rule['module'],
				'action'   => $rule['action'],
				'blocking' => $rule['blocking'],
				'fix_hint' => $this->text( $rule['fix_hint'] ),
				'active'   => $active,
				'detail'   => is_string( $result ) ? $result : '',
				'yielded'  => $active && self::ACTION_YIELD === $rule['action'] && in_array( $rule['module'], Plugin::instance()->yielded(), true ),
			];
		}
		return $rows;
	}

	/**
	 * Close a message with a full stop so message and hint read as separate sentences.
	 *
	 * @param string $text Message part.
	 */
	public static function sentence( string $text ): string {
		$text = rtrim( trim( $text ), ' 	' );
		if ( '' === $text ) {
			return '';
		}
		return 1 === preg_match( '/[.!?…]$/u', $text ) ? $text : $text . '.';
	}

	/**
	 * Active blocking conflicts.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function blocking(): array {
		return array_values(
			array_filter(
				$this->evaluate(),
				static fn( array $row ): bool => $row['active'] && $row['blocking']
			)
		);
	}

	/**
	 * Active plugin files (site + network) after filter `vextra/core/compat/active_plugins` (tests simulate a plugin here).
	 *
	 * @return string[]
	 */
	public function active_plugins(): array {
		$list = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$list = array_merge( $list, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}

		/**
		 * Filters the active plugin list used by conflict detection (default: site + network active plugins).
		 *
		 * @param string[] $list Plugin files like `wordpress-seo/wp-seo.php`.
		 */
		$filtered = apply_filters( 'vextra/core/compat/active_plugins', $list );
		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : array_values( array_filter( $list, 'is_string' ) );
	}

	/**
	 * Run a detector; any Throwable counts as "no conflict".
	 *
	 * @param array<string,mixed> $rule Normalised rule.
	 * @return false|string False = no conflict; string = detail ('' when none).
	 */
	private function detect( array $rule ) {
		try {
			$result = ( $rule['detector'] )();
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( is_string( $result ) ) {
			return $result;
		}
		return $result ? '' : false;
	}

	/**
	 * Resolve a string-or-callable text.
	 *
	 * @param mixed $text String or callable returning a string.
	 */
	private function text( $text ): string {
		if ( is_callable( $text ) ) {
			$text = $text();
		}
		return is_scalar( $text ) ? (string) $text : '';
	}

	/**
	 * Matching active plugin folders.
	 *
	 * @param string[] $slugs Plugin folder slugs.
	 * @return false|string Comma-separated matched slugs, false when none.
	 */
	private function detect_plugins( array $slugs ) {
		$found = [];
		foreach ( $this->active_plugins() as $file ) {
			$slug = str_contains( $file, '/' ) ? (string) strtok( $file, '/' ) : preg_replace( '/\.php$/', '', $file );
			if ( in_array( $slug, $slugs, true ) ) {
				$found[] = $slug;
			}
		}
		return array() === $found ? false : implode( ', ', array_unique( $found ) );
	}

	/**
	 * Our module runs (enabled and not yielded). Admin / CLI only callers.
	 *
	 * @param string $id Module id.
	 */
	private function module_active( string $id ): bool {
		$modules = Plugin::instance()->modules();
		return isset( $modules[ $id ] ) && $modules[ $id ]->is_enabled() && ! in_array( $id, $this->yielded, true );
	}

	/**
	 * R1: Rank Math creates its own redirects on slug change while TermRedirects owns them.
	 */
	private function detect_rankmath_post_redirect(): bool {
		$rank = Plugin::instance()->integration( 'rankmath' );
		if ( ! $rank instanceof RankMath || ! $rank->is_available() || ! $this->module_active( 'seo' ) ) {
			return false;
		}
		$value = $rank->setting( 'general.redirections_post_redirect', 'off' );
		return true === $value || in_array( (string) $value, [ 'on', '1', 'yes' ], true );
	}

	/**
	 * Staff 2FA launch gate: Two Factor is not active, or staff accounts have no second factor (detail = logins).
	 *
	 * @return false|string
	 */
	private function detect_staff_without_two_factor() {
		if ( ! $this->module_active( 'security' ) ) {
			return false;
		}
		$staff = TwoFactorPolicy::staff_without_two_factor();
		if ( null === $staff ) {
			return __( 'плагін «Two Factor» не активний', 'vextra-core' );
		}
		if ( array() === $staff ) {
			return false;
		}
		return implode( ', ', array_slice( $staff, 0, 10 ) ) . ( count( $staff ) > 10 ? '…' : '' );
	}

	/**
	 * Author archives are on in Rank Math while the Security module answers 404 for them (a login slug can still reach the index).
	 */
	private function detect_author_archives(): bool {
		$rank = Plugin::instance()->integration( 'rankmath' );
		if ( ! $rank instanceof RankMath || ! $rank->is_available() || ! $this->module_active( 'security' ) ) {
			return false;
		}
		$value = $rank->setting( 'titles.disable_author_archives', 'off' );
		return ! ( true === $value || in_array( (string) $value, [ 'on', '1', 'yes' ], true ) );
	}

	/**
	 * R7: Rank Math robots.txt editor content replaces the text the Guard rules are merged into.
	 */
	private function detect_rankmath_robots_editor(): bool {
		$rank = Plugin::instance()->integration( 'rankmath' );
		if ( ! $rank instanceof RankMath || ! $rank->is_available() || ! $this->module_active( 'guard' ) ) {
			return false;
		}
		$value = $rank->setting( 'general.robots_txt_content', '' );
		return is_string( $value ) && '' !== trim( $value );
	}
}
