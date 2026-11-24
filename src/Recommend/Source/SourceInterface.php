<?php
/**
 * A candidate source of the Recommend pipeline (TASK-038). Phase 2 adds `Affinity` as one more implementation.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend\Source;

use Vextra\Core\Recommend\Anchor;

interface SourceInterface {

	/**
	 * Source id shown by `explain` (`upsell`, `cross`, `accessory`, `related`).
	 */
	public function id(): string;

	/**
	 * Raw candidates of one anchor, in the source's own order, product ids of the anchor's language, before any filter.
	 * `meta` is explain-only data (tier, scores, tie-break key); it never reaches the cache.
	 *
	 * @param Anchor $anchor Anchor.
	 * @return array<int,array{id:int,src:string,meta?:array<string,mixed>}>
	 */
	public function candidates( Anchor $anchor ): array;
}
