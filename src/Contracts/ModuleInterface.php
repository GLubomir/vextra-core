<?php
/**
 * A feature module of the plugin.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Contracts;

interface ModuleInterface {

	/**
	 * Stable machine id, lowercase (e.g. `health`).
	 */
	public function id(): string;

	/**
	 * Whether the module may run now. Decide from integrations (e.g. Woo missing => false); never fatal.
	 */
	public function is_enabled(): bool;

	/**
	 * Add hooks. Called once on `plugins_loaded` priority 20, only when enabled. No heavy work here.
	 */
	public function register(): void;
}
