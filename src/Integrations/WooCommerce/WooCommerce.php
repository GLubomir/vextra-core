<?php
/**
 * WooCommerce adapter: availability + version only.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Integrations\WooCommerce;

use Vextra\Core\Integrations\AbstractIntegration;

final class WooCommerce extends AbstractIntegration {

	public function id(): string {
		return 'woocommerce';
	}

	protected function detect(): bool {
		return class_exists( 'WooCommerce' );
	}

	public function version(): ?string {
		return defined( 'WC_VERSION' ) ? (string) WC_VERSION : null;
	}
}
