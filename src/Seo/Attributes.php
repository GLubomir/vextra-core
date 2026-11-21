<?php
/**
 * Product attribute archives (`pa_*`): never indexable, never in a sitemap, for every current and future attribute.
 *
 * WooCommerce archives of an attribute are OFF by default (the attribute's "Enable archives" box), so the URL answers 404.
 * When an editor enables it, this class makes the archive `noindex, follow` (the safety net of ADR-002 s12) with no per-attribute
 * option: the rule is "taxonomy name starts with `pa_`". Layered-nav / filter URLs are untouched (they never rely on the archive).
 * Fails open: an error leaves Rank Math's directives.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Integrations\RankMath\RankMath;

final class Attributes {

	private const PREFIX = 'pa_';

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
		$this->rank_math->filter_robots( [ $this, 'filter_robots' ] );
		$this->rank_math->filter_sitemap_entry( [ $this, 'filter_entry' ] );
	}

	/**
	 * Whether the taxonomy is a product attribute.
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	public static function is_attribute_taxonomy( string $taxonomy ): bool {
		return str_starts_with( $taxonomy, self::PREFIX );
	}

	/**
	 * Rank Math robots filter: `noindex, follow` on an attribute archive.
	 *
	 * @param mixed $robots Directive map.
	 * @return mixed
	 */
	public function filter_robots( $robots ) {
		if ( ! is_array( $robots ) || ! is_tax() ) {
			return $robots;
		}
		$term = get_queried_object();
		if ( $term instanceof \WP_Term && self::is_attribute_taxonomy( $term->taxonomy ) ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'follow';
		}
		return $robots;
	}

	/**
	 * Sitemap entry filter: drop terms of attribute taxonomies.
	 *
	 * @param mixed  $url  Sitemap entry.
	 * @param string $type Entry type.
	 * @param mixed  $term Term object.
	 * @return mixed
	 */
	public function filter_entry( $url, $type = '', $term = null ) {
		if ( 'term' === $type && $term instanceof \WP_Term && self::is_attribute_taxonomy( $term->taxonomy ) ) {
			return [];
		}
		return $url;
	}
}
