<?php
/**
 * The product a recommendation list is built for (TASK-038): id in the cache language, its price (min price, sale price included),
 * partner and the language of the cache scope. Cart lists use one Anchor per cart item.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend;

final class Anchor {

	public function __construct(
		public readonly int $id,
		public readonly string $lang,
		public readonly float $price,
		public readonly string $partner
	) {}
}
