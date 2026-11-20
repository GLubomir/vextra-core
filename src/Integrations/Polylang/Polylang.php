<?php
/**
 * Polylang adapter: availability, version and readiness.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Integrations\Polylang;

use Vextra\Core\Integrations\AbstractIntegration;

final class Polylang extends AbstractIntegration {

	public function id(): string {
		return 'polylang';
	}

	protected function detect(): bool {
		return defined( 'POLYLANG_VERSION' ) || function_exists( 'pll_current_language' );
	}

	public function version(): ?string {
		return defined( 'POLYLANG_VERSION' ) ? (string) POLYLANG_VERSION : null;
	}

	/**
	 * True once Polylang's API is usable (after `pll_init`). Modules needing languages must hook later
	 * themselves; do not call Polylang functions from `register()`.
	 */
	public function is_ready(): bool {
		return $this->is_available() && function_exists( 'pll_current_language' ) && function_exists( 'PLL' );
	}
}
