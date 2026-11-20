<?php
/**
 * Rank Math adapter: availability + version only.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Integrations\RankMath;

use Vextra\Core\Integrations\AbstractIntegration;

final class RankMath extends AbstractIntegration {

	public function id(): string {
		return 'rankmath';
	}

	protected function detect(): bool {
		return class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
	}

	public function version(): ?string {
		return defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : null;
	}
}
