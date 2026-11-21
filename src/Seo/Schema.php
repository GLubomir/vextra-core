<?php
/**
 * Product JSON-LD: `name` is the product name (post title), not the SEO title (`... — купить в Украине | Vextra`).
 * Everything else (sku, offers, description) is left as Rank Math builds it. WebPage/ItemPage keep the SEO title.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Integrations\RankMath\RankMath;

final class Schema {

	/**
	 * Rank Math adapter.
	 *
	 * @var RankMath
	 */
	private RankMath $rank_math;

	public function __construct( RankMath $rank_math ) {
		$this->rank_math = $rank_math;
	}

	public function register(): void {
		$this->rank_math->filter_product_entity( [ $this, 'product' ] );
	}

	/**
	 * Product entity filter.
	 *
	 * @param mixed $entity Product JSON-LD node.
	 * @return mixed
	 */
	public function product( $entity ) {
		if ( ! is_array( $entity ) || ! is_singular( 'product' ) ) {
			return $entity;
		}
		$name = trim( wp_strip_all_tags( (string) get_post_field( 'post_title', (int) get_queried_object_id(), 'raw' ) ) );
		if ( '' !== $name ) {
			$entity['name'] = $name;
		}
		return $entity;
	}
}
