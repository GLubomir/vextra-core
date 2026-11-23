<?php
/**
 * Guard module (TASK-028, ADR-007 L1 + L2): robots.txt rules, GET add-to-cart refusal, rate limits for Store API / wc-ajax /
 * coupons / search / anonymous REST, anonymous catalog-export block, bad-UA deny-list, draft-order purge.
 *
 * Enabled when WooCommerce is available and `VX_GUARD_DISABLED` is not set (filter `vextra/core/guard/enabled`).
 * Fail-open everywhere (see Failsafe). Numbers: Config. Docs: docs/system/modules/guard.md (theme repo).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Plugin;

final class Guard implements ModuleInterface {

	public function id(): string {
		return 'guard';
	}

	public function is_enabled(): bool {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		return null !== $woo && $woo->is_available() && Config::enabled();
	}

	public function register(): void {
		( new RobotsTxt() )->register();
		( new DraftOrders() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command(
				'vextra guard purge-drafts',
				static function (): void {
					\WP_CLI::success( sprintf( 'Deleted %d draft orders.', ( new DraftOrders() )->run() ) );
				},
				[ 'shortdesc' => 'Delete one batch of stale checkout-draft orders now.' ]
			);
			return; // Request guards never run under WP-CLI.
		}

		( new AddToCart() )->register();
		( new StoreApi() )->register();
		( new TermEdits() )->register();
		( new WcAjax() )->register();
		( new Search() )->register();
		( new UserAgents() )->register();
	}
}
