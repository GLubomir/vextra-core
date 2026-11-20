<?php
/**
 * Base class of third-party adapters (ADR-003).
 *
 * Rule: calls into a third-party plugin live ONLY in adapter classes, each guarded by detect() so the plugin
 * is never touched when absent. Modules ask `Plugin::integration( $id )` and check is_available() first.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Integrations;

abstract class AbstractIntegration {

	/**
	 * Stable machine id (e.g. `polylang`).
	 */
	abstract public function id(): string;

	/**
	 * Installed version, or null when unavailable / unknown.
	 */
	abstract public function version(): ?string;

	/**
	 * Guard: function_exists / class_exists / defined() checks only.
	 */
	abstract protected function detect(): bool;

	/**
	 * Is the third-party plugin usable. Filterable so tests can simulate an absent plugin.
	 */
	final public function is_available(): bool {
		/**
		 * Filters adapter availability.
		 *
		 * @param bool   $available Result of detect().
		 * @param string $id        Integration id.
		 */
		return (bool) apply_filters( 'vextra/core/integration_available', $this->detect(), $this->id() );
	}
}
