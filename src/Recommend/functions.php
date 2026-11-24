<?php
/**
 * Public functions of the Recommend module for the theme (TASK-038). The theme wraps every call in `function_exists`, so a theme
 * without this plugin (or with the module switched off, `VX_RECOMMEND_DISABLED`) falls back to Woo's own related products.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

use Vextra\Core\Recommend\Engine;

if ( ! function_exists( 'vx_recommend' ) ) {
	/**
	 * Deterministic, language-correct, cached recommendations.
	 *
	 * `$context`: `product` (args `product_id`, `list` = `accessories|related`), `post_add` (`product_id` = the just-added product),
	 * `cart` (the server cart). Other args: `limit` (1..8), `exclude` (ids already shown by an earlier block).
	 *
	 * Also accepted: one array with a `context` key (`vx_recommend( [ 'context' => 'product', 'product_id' => 5 ] )`).
	 *
	 * @param string|array<string,mixed> $context product|post_add|cart, or the whole argument array.
	 * @param array<string,mixed>        $args    Arguments.
	 * @return array<int,array{id:int,src:string}> `src` = upsell|cross|accessory|related.
	 */
	function vx_recommend( $context, array $args = [] ): array {
		if ( is_array( $context ) ) {
			$args    = $context;
			$context = (string) ( $args['context'] ?? '' );
		}
		return Engine::recommend( is_string( $context ) ? $context : '', $args );
	}
}

if ( ! function_exists( 'vx_recommend_ids' ) ) {
	/**
	 * Same as vx_recommend(), product ids only.
	 *
	 * @param string|array<string,mixed> $context product|post_add|cart, or the whole argument array.
	 * @param array<string,mixed>        $args    Arguments.
	 * @return int[]
	 */
	function vx_recommend_ids( $context, array $args = [] ): array {
		return array_column( vx_recommend( $context, $args ), 'id' );
	}
}
