<?php
/**
 * Content of the Cart and Checkout block pages (TASK-045a, flag F8): pure block-tree transforms used by `wp vextra cart apply-config`.
 *
 * Cart page: no cross-sells product collection, no "new in store" pattern / spacer / product row inside the empty cart. (Woo 11.3's Cart
 * block has no shipping-calculator attribute: shipping is calculated at checkout, option `woocommerce_enable_shipping_calc` is forced off by the CLI.)
 * Checkout page: `showFormStepNumbers` on, own step titles per language (page content attributes, not gettext). Idempotent: running a
 * transform on its own output returns the same string.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Cart;

final class PageContent {

	/**
	 * Step titles per language slug (`default` = Ukrainian, the msgid language of the project).
	 */
	private const TITLES = [
		'default' => [
			'contact'  => 'Контактна інформація',
			'address'  => 'Одержувач і адреса',
			'delivery' => 'Спосіб доставки',
			'payment'  => 'Спосіб оплати',
		],
		'ru'      => [
			'contact'  => 'Контактная информация',
			'address'  => 'Получатель и адрес',
			'delivery' => 'Способ доставки',
			'payment'  => 'Способ оплаты',
		],
	];

	private const STEP_BLOCKS = [
		'woocommerce/checkout-contact-information-block' => 'contact',
		'woocommerce/checkout-shipping-address-block'    => 'address',
		'woocommerce/checkout-billing-address-block'     => 'address',
		'woocommerce/checkout-shipping-method-block'     => 'delivery',
		'woocommerce/checkout-payment-block'             => 'payment',
	];

	/**
	 * Cart page content.
	 *
	 * @param string $content Stored post content.
	 * @return string
	 */
	public static function cart( string $content ): string {
		$blocks = parse_blocks( $content );
		$blocks = self::strip( $blocks, [ self::class, 'is_cart_extra' ] );
		return serialize_blocks( $blocks );
	}

	/**
	 * Checkout page content.
	 *
	 * @param string $content Stored post content.
	 * @param string $lang    Language slug of the page ('' / unknown = Ukrainian).
	 * @return string
	 */
	public static function checkout( string $content, string $lang ): string {
		$titles = self::TITLES[ $lang ] ?? self::TITLES['default'];
		$blocks = parse_blocks( $content );
		$blocks = self::walk(
			$blocks,
			static function ( array $block ) use ( $titles ): array {
				$name = (string) ( $block['blockName'] ?? '' );
				if ( 'woocommerce/checkout' === $name ) {
					$block['attrs']['showFormStepNumbers'] = true;
				}
				if ( isset( self::STEP_BLOCKS[ $name ] ) ) {
					$block['attrs']['title']       = $titles[ self::STEP_BLOCKS[ $name ] ];
					$block['attrs']['description'] = '';
				}
				return $block;
			}
		);
		return serialize_blocks( $blocks );
	}

	/**
	 * Blocks the cart page must not carry: the cross-sells collection, the "new in store" pattern, the spacer next to it and the "new" product row.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 */
	public static function is_cart_extra( array $block ): bool {
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		if ( 'woocommerce/product-collection' === $name ) {
			return 'woocommerce/product-collection/cross-sells' === ( $attrs['collection'] ?? '' );
		}
		if ( 'core/pattern' === $name ) {
			return 'woocommerce/cart-new-in-store-message' === ( $attrs['slug'] ?? '' );
		}
		return 'woocommerce/product-new' === $name;
	}

	/**
	 * Remove matching blocks (recursively), keeping `innerContent` aligned. A `core/spacer` right before a removed block goes too.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param callable                       $drop   Predicate: true = remove the block.
	 * @return array<int,array<string,mixed>>
	 */
	private static function strip( array $blocks, callable $drop ): array {
		return self::strip_list( $blocks, $drop )['blocks'];
	}

	/**
	 * Strip one sibling list.
	 *
	 * @param array<int,array<string,mixed>> $blocks Siblings.
	 * @param callable                       $drop   Predicate.
	 * @return array{blocks:array<int,array<string,mixed>>,kept:array<int,bool>} `kept[i]` tells whether original child i survived.
	 */
	private static function strip_list( array $blocks, callable $drop ): array {
		$out  = [];
		$at   = [];
		$kept = [];
		foreach ( array_values( $blocks ) as $i => $block ) {
			if ( ! is_array( $block ) ) {
				$kept[ $i ] = false;
				continue;
			}
			if ( $drop( $block ) ) {
				$kept[ $i ] = false;
				$last       = array() === $out ? null : array_key_last( $out );
				if ( null !== $last && 'core/spacer' === ( $out[ $last ]['blockName'] ?? '' ) ) {
					unset( $out[ $last ] );
					$kept[ $at[ $last ] ] = false;
					unset( $at[ $last ] );
				}
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block = self::strip_inner( $block, $drop );
			}
			$out[]                        = $block;
			$at[ array_key_last( $out ) ] = $i;
			$kept[ $i ]                   = true;
		}
		return [
			'blocks' => array_values( $out ),
			'kept'   => $kept,
		];
	}

	/**
	 * Strip inside one block and drop the matching `null` slots of `innerContent`.
	 *
	 * @param array<string,mixed> $block Parent block.
	 * @param callable            $drop  Predicate.
	 * @return array<string,mixed>
	 */
	private static function strip_inner( array $block, callable $drop ): array {
		$result  = self::strip_list( (array) $block['innerBlocks'], $drop );
		$content = [];
		$index   = 0;
		foreach ( (array) $block['innerContent'] as $piece ) {
			if ( null === $piece ) {
				if ( true === ( $result['kept'][ $index ] ?? false ) ) {
					$content[] = null;
				}
				++$index;
				continue;
			}
			$content[] = $piece;
		}
		$block['innerBlocks']  = $result['blocks'];
		$block['innerContent'] = $content;
		return $block;
	}

	/**
	 * Apply a per-block mapper to the whole tree.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param callable                       $callback Receives and returns one block (children are visited first).
	 * @return array<int,array<string,mixed>>
	 */
	private static function walk( array $blocks, callable $callback ): array {
		foreach ( $blocks as $i => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::walk( $block['innerBlocks'], $callback );
			}
			$blocks[ $i ] = $callback( $block );
		}
		return $blocks;
	}
}
