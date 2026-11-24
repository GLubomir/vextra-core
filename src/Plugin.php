<?php
/**
 * Plugin kernel: module registry + integration accessor. The only singleton of the plugin.
 *
 * Adding a module: implement Contracts\ModuleInterface, append the class to Plugin::MODULES.
 * Adding an integration: extend Integrations\AbstractIntegration, append to Plugin::INTEGRATIONS (ADR-003).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Vextra\Core\Admin\Notices;
use Vextra\Core\Catalog\Catalog;
use Vextra\Core\Compat\Compat;
use Vextra\Core\Compat\Conflicts;
use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Dev\Dev;
use Vextra\Core\Guard\Guard;
use Vextra\Core\Health\Health;
use Vextra\Core\Integrations\AbstractIntegration;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Integrations\Turnstile\Turnstile;
use Vextra\Core\Integrations\TwoFactor\TwoFactor;
use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Media\Module as Media;
use Vextra\Core\Multilang\Multilang;
use Vextra\Core\Performance\OptionPriming;
use Vextra\Core\Performance\Performance;
use Vextra\Core\Product\Product;
use Vextra\Core\Recommend\Recommend;
use Vextra\Core\Reviews\Reviews;
use Vextra\Core\Security\Security;
use Vextra\Core\Seo\Seo;
use Vextra\Core\Seo\Urls;
use Vextra\Core\Support\Listable;

final class Plugin {

	/**
	 * Default module classes. Filter `vextra/core/modules` adjusts the list.
	 */
	private const MODULES = [
		Health::class,
		Multilang::class,
		Seo::class,
		Urls::class,
		Dev::class,
		Compat::class,
		Guard::class,
		Security::class,
		Reviews::class,
		Performance::class,
		Catalog::class,
		Recommend::class,
		Product::class,
		Media::class,
	];

	/**
	 * Third-party adapters (ADR-003).
	 */
	private const INTEGRATIONS = [
		WooCommerce::class,
		Polylang::class,
		RankMath::class,
		Turnstile::class,
		TwoFactor::class,
	];

	/**
	 * The single kernel instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Composer autoloader missing (shows a notice).
	 *
	 * @var bool
	 */
	private bool $autoload_missing;

	/**
	 * Adapters by id (lazy).
	 *
	 * @var array<string,AbstractIntegration>
	 */
	private array $integrations = [];

	/**
	 * Instantiated modules by id (enabled or not).
	 *
	 * @var array<string,ModuleInterface>
	 */
	private array $modules = [];

	/**
	 * Conflict rules (created before the module loop so the yield filter exists when modules are evaluated).
	 *
	 * @var Conflicts|null
	 */
	private ?Conflicts $conflicts = null;

	/**
	 * Module ids that yielded to a conflicting plugin in this request.
	 *
	 * @var string[]
	 */
	private array $yielded = [];

	private function __construct( bool $autoload_missing ) {
		$this->autoload_missing = $autoload_missing;
	}

	/**
	 * Called once from the bootstrap file.
	 *
	 * @param bool $autoload_missing True when vendor/autoload.php is absent (shows an admin notice).
	 */
	public static function boot( bool $autoload_missing = false ): void {
		if ( null !== self::$instance ) {
			return;
		}
		self::$instance = new self( $autoload_missing );

		add_action( 'init', [ self::$instance, 'load_textdomain' ] );
		add_action( 'before_woocommerce_init', [ self::$instance, 'declare_woo_compatibility' ] );
		add_action( 'plugins_loaded', [ self::$instance, 'register_modules' ], 20 );
		OptionPriming::boot(); // Early (plugins_loaded 0): must run before Woo / Polylang / Rank Math initialise.
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::boot();
		}
		return self::$instance;
	}

	/**
	 * Adapter accessor for modules. Check is_available() before use.
	 *
	 * @param string $id Integration id (`woocommerce`, `polylang`, `rankmath`, `turnstile`, `twofactor`).
	 */
	public function integration( string $id ): ?AbstractIntegration {
		return $this->integrations()[ $id ] ?? null;
	}

	/**
	 * All adapters by id.
	 *
	 * @return array<string,AbstractIntegration>
	 */
	public function integrations(): array {
		if ( array() === $this->integrations ) {
			foreach ( self::INTEGRATIONS as $class ) {
				$integration                              = new $class();
				$this->integrations[ $integration->id() ] = $integration;
			}
		}
		return $this->integrations;
	}

	/**
	 * All instantiated modules, enabled or not.
	 *
	 * @return array<string,ModuleInterface>
	 */
	public function modules(): array {
		return $this->modules;
	}

	/**
	 * Conflict registry (always available after `plugins_loaded` priority 20 started).
	 */
	public function conflicts(): Conflicts {
		if ( null === $this->conflicts ) {
			$this->conflicts = new Conflicts();
			$this->conflicts->register();
		}
		return $this->conflicts;
	}

	/**
	 * Module ids switched off by the `vextra/core/module_enabled` filter although their own check passed.
	 *
	 * @return string[]
	 */
	public function yielded(): array {
		return $this->yielded;
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'vextra-core', false, dirname( plugin_basename( VEXTRA_CORE_FILE ) ) . '/languages' );
	}

	public function declare_woo_compatibility(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', VEXTRA_CORE_FILE, true );
			FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', VEXTRA_CORE_FILE, true );
		}
	}

	/**
	 * Instantiate every module, register only the enabled ones.
	 */
	public function register_modules(): void {
		( new Notices( $this->autoload_missing, $this->integrations()['woocommerce'] ) )->register();
		$this->conflicts();
		Listable::register(); // SEC-066: protected products never appear in an automatic listing.

		/**
		 * Filters the module class list.
		 *
		 * @param string[] $classes Fully qualified ModuleInterface class names.
		 */
		$classes = apply_filters( 'vextra/core/modules', self::MODULES );

		foreach ( (array) $classes as $class ) {
			if ( ! is_string( $class ) || ! is_subclass_of( $class, ModuleInterface::class ) ) {
				continue;
			}
			$module                         = new $class();
			$this->modules[ $module->id() ] = $module;
			if ( ! $module->is_enabled() ) {
				continue;
			}

			/**
			 * Final say on a module that passed its own `is_enabled()` (conflict guard: yield to a duplicate plugin).
			 *
			 * @param bool   $enabled True.
			 * @param string $id      Module id.
			 */
			if ( ! (bool) apply_filters( 'vextra/core/module_enabled', true, $module->id() ) ) {
				$this->yielded[] = $module->id();
				continue;
			}
			$module->register();
		}
	}
}
