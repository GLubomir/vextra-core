<?php
/**
 * Product JSON-LD: `name` is the product name (post title), not the SEO title (`... — купить в Украине | Vextra`).
 * Everything else (sku, offers, description) is left as Rank Math builds it. WebPage/ItemPage keep the SEO title.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Catalog\SalePage;
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
		$this->rank_math->filter_json_ld( [ $this, 'home_graph' ] );
		$this->rank_math->filter_json_ld( [ $this, 'sale_graph' ] );
	}

	/**
	 * Home page (every language) is a plain WebPage: Rank Math's default page schema (Article + Person author with the admin's
	 * Gravatar and `sameAs` = site URL) is removed. Organization, WebSite, WebPage and the breadcrumbs stay.
	 *
	 * @param mixed $graph JSON-LD nodes keyed by Rank Math.
	 * @return mixed
	 */
	public function home_graph( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}

		// WebSite.url of every language ends with a slash (`/ru/`), like the canonical (Rank Math prints `/ru`).
		foreach ( $graph as $key => $node ) {
			if ( is_array( $node ) && in_array( 'WebSite', (array) ( $node['@type'] ?? [] ), true ) && ! empty( $node['url'] ) && is_string( $node['url'] ) ) {
				$graph[ $key ]['url'] = trailingslashit( $node['url'] );
			}
		}

		if ( ! is_front_page() ) {
			return $graph;
		}
		foreach ( $graph as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$types = array_map( 'strval', (array) ( $node['@type'] ?? [] ) );
			$id    = (string) ( $node['@id'] ?? '' );
			if ( array_intersect( $types, [ 'Article', 'BlogPosting', 'NewsArticle' ] ) || ( in_array( 'Person', $types, true ) && str_ends_with( $id, '#author' ) ) ) {
				unset( $graph[ $key ] );
			}
		}
		return $graph;
	}

	/**
	 * Sale pages (`vx_sale_pages`, UA + RU) are listing pages: Rank Math's default page schema (Article + Person author) is removed and the
	 * WebPage node becomes a CollectionPage. Organization, WebSite and the breadcrumbs stay.
	 *
	 * @param mixed $graph JSON-LD nodes keyed by Rank Math.
	 * @return mixed
	 */
	public function sale_graph( $graph ) {
		if ( ! is_array( $graph ) || ! class_exists( SalePage::class ) || ! SalePage::is_sale_page() ) {
			return $graph;
		}
		foreach ( $graph as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$types = array_map( 'strval', (array) ( $node['@type'] ?? [] ) );
			$id    = (string) ( $node['@id'] ?? '' );
			if ( array_intersect( $types, [ 'Article', 'BlogPosting', 'NewsArticle' ] ) || ( in_array( 'Person', $types, true ) && str_ends_with( $id, '#author' ) ) ) {
				unset( $graph[ $key ] );
			} elseif ( in_array( 'WebPage', $types, true ) ) {
				$graph[ $key ]['@type'] = 'CollectionPage';
			}
		}
		return $graph;
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
