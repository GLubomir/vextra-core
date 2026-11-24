<?php
/**
 * Recommend pipeline orchestration (TASK-038, ADR-010): Sources -> Candidates -> Filters -> Ranker -> Cache -> Presenters.
 *
 * Contexts: `product` (list `accessories` = upsell + accessories, `related`), `post_add` (cross-sells + accessories of the just-added
 * product, related fills the rest) and `cart` (cross-sells + accessories of every cart item merged round-robin, related fills the rest).
 * A build produces up to Config::MAX_LIMIT ranked items per list; the cache (`Store`) keeps them, `recommend()` applies the cart /
 * `exclude` ids and the limit at read time (so the cached bundle never depends on a visitor).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend;

use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;
use Vextra\Core\Recommend\Source\Accessories;
use Vextra\Core\Recommend\Source\Related;
use Vextra\Core\Recommend\Source\WooLinks;

final class Engine {

	/**
	 * Raw candidates kept per source before the filters (bounded work for big categories).
	 */
	private const SOURCE_CAP = 40;

	/**
	 * Context => list names a bundle holds.
	 */
	public const LISTS = [ 'accessories', 'related', 'post_add' ];

	/**
	 * Cached, language-correct recommendations. Never throws; `[]` when nothing fits.
	 *
	 * Args: `product_id` (product / post_add), `list` (`accessories|related`, product only, default related), `limit` (1..8), `exclude`
	 * (ids already shown by an earlier block), `exclude_cart` (post_add: false = ignore the server cart, REST uses it for the public
	 * cache), `cart_ids` (cart context: ids the SERVER supplies, e.g. the Woo cart filter; default = the Woo cart).
	 *
	 * @param string              $context product|post_add|cart.
	 * @param array<string,mixed> $args    Arguments.
	 * @return array<int,array{id:int,src:string}>
	 */
	public static function recommend( string $context, array $args = [] ): array {
		if ( ! in_array( $context, [ 'product', 'post_add', 'cart' ], true ) ) {
			return [];
		}
		$lang    = isset( $args['lang'] ) && is_string( $args['lang'] ) ? $args['lang'] : Pool::current_language();
		$exclude = array_map( 'absint', (array) ( $args['exclude'] ?? [] ) );
		$woo     = Plugin::instance()->integration( 'woocommerce' );

		if ( 'cart' === $context ) {
			$ids   = isset( $args['cart_ids'] ) ? (array) $args['cart_ids'] : ( $woo instanceof WooCommerce ? $woo->cart_product_ids() : [] );
			$ids   = self::map_ids( $ids, $lang );
			$list  = [] === $ids ? [] : Store::cart( $ids, $lang )['lists']['cart'] ?? [];
			$limit = self::limit( $args, 'cart' );
		} else {
			$id = Pool::twin( absint( $args['product_id'] ?? 0 ), $lang );
			if ( $id <= 0 ) {
				return [];
			}
			$bundle = Store::product( $id, $lang );
			if ( 'post_add' === $context ) {
				$list  = $bundle['lists']['post_add'] ?? [];
				$limit = self::limit( $args, 'post_add' );
				if ( false !== ( $args['exclude_cart'] ?? true ) && $woo instanceof WooCommerce ) {
					$exclude = array_merge( $exclude, $woo->cart_product_ids() );
				}
			} else {
				$name  = 'accessories' === ( $args['list'] ?? 'related' ) ? 'accessories' : 'related';
				$list  = $bundle['lists'][ $name ] ?? [];
				$limit = self::limit( $args, $name );
			}
			$exclude[] = $id;
		}

		$out = [];
		foreach ( $list as $item ) {
			if ( in_array( $item['id'], $exclude, true ) ) {
				continue;
			}
			$out[] = [
				'id'  => (int) $item['id'],
				'src' => (string) $item['src'],
			];
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Result size: `limit` arg clamped to 1..MAX_LIMIT, else the context default.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @param string              $name Limit name.
	 */
	private static function limit( array $args, string $name ): int {
		if ( isset( $args['limit'] ) && is_numeric( $args['limit'] ) ) {
			return max( 1, min( Config::MAX_LIMIT, (int) $args['limit'] ) );
		}
		return Config::limit( $name );
	}

	/**
	 * Product ids -> parent-resolved twins in a language, unique, ascending (cart signature is order independent).
	 *
	 * @param array<int,mixed> $ids  Ids.
	 * @param string           $lang Language.
	 * @return int[]
	 */
	public static function map_ids( array $ids, string $lang ): array {
		$out = [];
		foreach ( $ids as $id ) {
			$twin = Pool::twin( absint( $id ), $lang );
			if ( $twin > 0 ) {
				$out[ $twin ] = $twin;
			}
		}
		$out = array_values( $out );
		sort( $out );
		return $out;
	}

	/**
	 * Cart signature: md5 of the sorted product ids.
	 *
	 * @param int[] $ids Sorted ids.
	 */
	public static function signature( array $ids ): string {
		sort( $ids );
		return md5( implode( ',', $ids ) );
	}

	/**
	 * SWR rebuilder callback (Action Scheduler job, no request language: the language comes from the recipe).
	 *
	 * @param array<string,mixed> $recipe ctx (`product`|`cart`), ids, lang.
	 * @return array<string,mixed>
	 */
	public static function rebuild( array $recipe ): array {
		$ids  = array_map( 'absint', (array) ( $recipe['ids'] ?? [] ) );
		$lang = (string) ( $recipe['lang'] ?? '' );
		Pool::reset();
		$built = 'cart' === ( $recipe['ctx'] ?? '' ) ? self::build_cart( $ids, $lang ) : self::build_product( (int) ( $ids[0] ?? 0 ), $lang );
		return [
			'v'     => 1,
			'lists' => $built['lists'],
		];
	}

	/**
	 * Fresh, uncached build with the full trace (`wp vextra recommend explain`).
	 *
	 * @param string $context product|post_add|cart.
	 * @param int[]  $ids     Product id (cart: every cart product id).
	 * @param string $lang    Language slug.
	 * @return array{lists:array<string,array>,trace:array<string,mixed>}
	 */
	public static function explain( string $context, array $ids, string $lang ): array {
		Pool::reset();
		$ids = self::map_ids( $ids, $lang );
		return 'cart' === $context ? self::build_cart( $ids, $lang ) : self::build_product( (int) ( $ids[0] ?? 0 ), $lang );
	}

	/**
	 * Anchor of a product id in a language, null when it is not a published product with a price row.
	 *
	 * @param int    $id   Product id (cache language).
	 * @param string $lang Language slug.
	 */
	private static function anchor( int $id, string $lang ): ?Anchor {
		if ( $id <= 0 ) {
			return null;
		}
		$facts = Pool::light( [ $id ] )[ $id ] ?? [];
		if ( array() === $facts || 'publish' !== $facts['status'] ) {
			return null;
		}
		return new Anchor( $id, $lang, (float) $facts['min'], Fields::partner( $id ) );
	}

	/**
	 * Empty bundle.
	 *
	 * @return array{lists:array<string,array>,trace:array<string,mixed>}
	 */
	private static function empty_bundle(): array {
		return [
			'lists' => [
				'accessories' => [],
				'related'     => [],
				'post_add'    => [],
				'cart'        => [],
			],
			'trace' => [],
		];
	}

	/**
	 * Build the bundle of one product: the accessories, related and post_add lists from ONE round of source calls.
	 *
	 * @param int    $id   Product id (cache language).
	 * @param string $lang Language slug.
	 * @return array{lists:array<string,array>,trace:array<string,mixed>}
	 */
	private static function build_product( int $id, string $lang ): array {
		$anchor = self::anchor( $id, $lang );
		if ( null === $anchor ) {
			return self::empty_bundle();
		}

		$raw = [
			'upsell'    => self::cap( ( new WooLinks( 'upsell' ) )->candidates( $anchor ) ),
			'cross'     => self::cap( ( new WooLinks( 'cross' ) )->candidates( $anchor ) ),
			'accessory' => self::cap( ( new Accessories() )->candidates( $anchor ) ),
			'related'   => self::cap( ( new Related() )->candidates( $anchor ) ),
		];

		Pool::facts( array_merge( array_column( $raw['upsell'], 'id' ), array_column( $raw['cross'], 'id' ), array_column( $raw['accessory'], 'id' ), array_column( $raw['related'], 'id' ) ) ); // ONE read for every list.

		$lists = [
			'accessories' => array_merge( $raw['upsell'], $raw['accessory'] ),
			'related'     => $raw['related'],
			'post_add'    => array_merge( $raw['cross'], $raw['accessory'], $raw['related'] ),
		];
		$out   = self::empty_bundle();
		foreach ( $lists as $name => $items ) {
			$done                           = self::finish( $items, [ $id ], $anchor->price, $anchor->partner, $lang );
			$out['lists'][ $name ]          = $done['items'];
			$out['trace']['lists'][ $name ] = $done['trace'];
		}
		$out['trace']['sources'] = $raw;
		$out['trace']['anchor']  = [
			'id'      => $id,
			'lang'    => $lang,
			'price'   => $anchor->price,
			'partner' => $anchor->partner,
		];
		return $out;
	}

	/**
	 * Build the cart bundle: per cart item cross-sells + accessories (round-robin), then related (round-robin).
	 *
	 * @param int[]  $ids  Cart product ids (cache language, ascending).
	 * @param string $lang Language slug.
	 * @return array{lists:array<string,array>,trace:array<string,mixed>}
	 */
	private static function build_cart( array $ids, string $lang ): array {
		$anchors = [];
		foreach ( $ids as $id ) {
			$anchor = self::anchor( $id, $lang );
			if ( null !== $anchor ) {
				$anchors[] = $anchor;
			}
		}
		if ( array() === $anchors ) {
			return self::empty_bundle();
		}

		$manual  = [];
		$related = [];
		$raw     = [];
		foreach ( $anchors as $anchor ) {
			$cross     = self::cap( ( new WooLinks( 'cross' ) )->candidates( $anchor ) );
			$accessory = self::cap( ( new Accessories() )->candidates( $anchor ) );
			$rel       = self::cap( ( new Related() )->candidates( $anchor ) );

			$manual[]                     = array_merge( $cross, $accessory );
			$related[]                    = $rel;
			$raw[ 'item ' . $anchor->id ] = [
				'cross'     => $cross,
				'accessory' => $accessory,
				'related'   => $rel,
			];
		}

		foreach ( $manual as $list ) {
			Pool::facts( array_column( $list, 'id' ) );
		}
		foreach ( $related as $list ) {
			Pool::facts( array_column( $list, 'id' ) );
		}
		$price = 0.0;
		foreach ( $anchors as $anchor ) {
			$price = max( $price, $anchor->price );
		}
		$items = array_merge( self::round_robin( $manual ), self::round_robin( $related ) );
		$done  = self::finish( $items, array_map( static fn( Anchor $a ): int => $a->id, $anchors ), $price, $anchors[0]->partner, $lang );

		$out                           = self::empty_bundle();
		$out['lists']['cart']          = $done['items'];
		$out['trace']['lists']['cart'] = $done['trace'];
		$out['trace']['sources']       = $raw;
		$out['trace']['anchor']        = [
			'ids'     => array_map( static fn( Anchor $a ): int => $a->id, $anchors ),
			'lang'    => $lang,
			'price'   => $price,
			'partner' => $anchors[0]->partner,
		];
		return $out;
	}

	/**
	 * Interleave lists: first of each, second of each, ...
	 *
	 * @param array<int,array<int,array>> $lists Lists.
	 * @return array<int,array>
	 */
	private static function round_robin( array $lists ): array {
		$out = [];
		$max = 0;
		foreach ( $lists as $list ) {
			$max = max( $max, count( $list ) );
		}
		for ( $row = 0; $row < $max; $row++ ) {
			foreach ( $lists as $list ) {
				if ( isset( $list[ $row ] ) ) {
					$out[] = $list[ $row ];
				}
			}
		}
		return $out;
	}

	/**
	 * Bound a source's candidates.
	 *
	 * @param array<int,array> $items Candidates.
	 * @return array<int,array>
	 */
	private static function cap( array $items ): array {
		return array_slice( $items, 0, self::SOURCE_CAP );
	}

	/**
	 * Dedupe -> Filters -> Ranker -> top MAX_LIMIT.
	 *
	 * @param array<int,array{id:int,src:string,meta?:array}> $items   Candidates in source priority order.
	 * @param int[]                                           $anchor_ids Anchor product ids.
	 * @param float                                           $price   Anchor price (0 = no price band).
	 * @param string                                          $partner Anchor partner.
	 * @param string                                          $lang    Language slug.
	 * @return array{items:array<int,array{id:int,src:string}>,trace:array<string,mixed>}
	 */
	private static function finish( array $items, array $anchor_ids, float $price, string $partner, string $lang ): array {
		$unique = [];
		foreach ( $items as $item ) {
			if ( ! isset( $unique[ $item['id'] ] ) ) {
				$unique[ $item['id'] ] = $item;
			}
		}
		$unique   = array_values( $unique );
		$filtered = Filters::apply(
			$unique,
			[
				'self'    => $anchor_ids,
				'exclude' => [],
				'lang'    => $lang,
				'price'   => $price,
				'now'     => time(),
			]
		);
		$ranked   = Ranker::rank( $filtered['items'], $partner );
		$final    = array_slice( $ranked, 0, Config::MAX_LIMIT );

		return [
			'items' => array_map(
				static fn( array $item ): array => [
					'id'  => $item['id'],
					'src' => $item['src'],
				],
				$final
			),
			'trace' => [
				'candidates' => count( $unique ),
				'filters'    => $filtered['dropped'],
				'ranked'     => $ranked,
			],
		];
	}
}
