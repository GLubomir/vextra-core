<?php
/**
 * Manual links of a product (TASK-038): Woo `upsell_ids` or `cross_sell_ids`, mapped to the language of the cache scope.
 *
 * The anchor's own list is used; a translation without one reads the default-language product's list (links are edited once, in
 * Woo). Every id goes through the Polylang twin map; an id without a twin is dropped, never shown in the other language.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Recommend\Source;

use Vextra\Core\Integrations\WooCommerce\WooCommerce;
use Vextra\Core\Plugin;
use Vextra\Core\Recommend\Anchor;
use Vextra\Core\Recommend\Pool;

final class WooLinks implements SourceInterface {

	/**
	 * `upsell` or `cross`.
	 *
	 * @var string
	 */
	private string $kind;

	public function __construct( string $kind ) {
		$this->kind = 'cross' === $kind ? 'cross' : 'upsell';
	}

	public function id(): string {
		return $this->kind;
	}

	public function candidates( Anchor $anchor ): array {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( ! $woo instanceof WooCommerce ) {
			return [];
		}
		$ids = $woo->manual_links( $anchor->id )[ $this->kind ];
		if ( array() === $ids && ! Pool::is_default_language( $anchor->lang ) ) {
			$source = Pool::default_twin( $anchor->id );
			if ( $source > 0 && $source !== $anchor->id ) {
				$ids = $woo->manual_links( $source )[ $this->kind ];
			}
		}
		$out = [];
		foreach ( $ids as $id ) {
			$twin = Pool::twin( $id, $anchor->lang );
			if ( $twin > 0 && ! isset( $out[ $twin ] ) ) {
				$out[ $twin ] = [
					'id'  => $twin,
					'src' => $this->kind,
				];
			}
		}
		return array_values( $out );
	}
}
