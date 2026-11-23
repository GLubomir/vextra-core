<?php
/**
 * Reviews module (TASK-030, SEC-012, decision D6): product reviews only from signed-in verified buyers, always held for
 * moderation; every other comment / ping channel closed. Content policy lives here, authentication stays in `Security`.
 *
 * Services (each inside Failsafe): Options (forced options, avatars off), Closed (channels closed, feeds, redirects), Gate
 * (form + REST guards), Form (what visitors see). Under WP-CLI only Options, the data filters of Closed and the commands run.
 * Kill switch: constant `VX_REVIEWS_DISABLED` (filter `vextra/core/reviews/enabled`): stock WordPress / WooCommerce behaviour.
 * Docs: docs/system/modules/reviews.md (theme repo).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Reviews;

use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Guard\Failsafe;

final class Reviews implements ModuleInterface {

	public function id(): string {
		return 'reviews';
	}

	public function is_enabled(): bool {
		return Config::enabled();
	}

	public function register(): void {
		Failsafe::run( 'register-reviews-options', static fn() => ( new Options() )->register() );
		Failsafe::run( 'register-reviews-buyers', static fn() => ( new Buyers() )->register() );
		Failsafe::run( 'register-reviews-closed-data', static fn() => ( new Closed() )->register_data() );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new Cli() )->register();
			return; // Request guards never run under WP-CLI.
		}

		Failsafe::run( 'register-reviews-closed-front', static fn() => ( new Closed() )->register_front() );
		Failsafe::run( 'register-reviews-gate', static fn() => ( new Gate() )->register() );
		Failsafe::run( 'register-reviews-form', static fn() => ( new Form() )->register() );
	}
}
