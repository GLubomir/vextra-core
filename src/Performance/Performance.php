<?php
/**
 * Performance module (TASK-010): query / request diet that must survive a theme switch and touches third-party internals only
 * through the adapters (Woo block-type list, option priming, emoji / oEmbed discovery).
 *
 * Services: OptionPriming (early, started by the kernel; not under WP-CLI / admin), BlockTypes (its save hooks also run under WP-CLI, so content created by a script or import is scanned), Bloat (front only). Each has its own rollback token (see Switches);
 * the whole module is off with constant `VX_PERF_DISABLED` or the filter `vextra/core/performance/enabled`.
 * Docs: docs/system/modules/performance.md (theme repo).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Performance;

use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Guard\Failsafe;

final class Performance implements ModuleInterface {

	public function id(): string {
		return 'performance';
	}

	public function is_enabled(): bool {
		return ! Switches::disabled();
	}

	public function register(): void {
		Switches::ensure_option();

		Failsafe::run( 'register-performance-bloat', static fn() => ( new Bloat() )->register() );

		Failsafe::run( 'register-performance-blocktypes', static fn() => ( new BlockTypes() )->register() );
	}
}
