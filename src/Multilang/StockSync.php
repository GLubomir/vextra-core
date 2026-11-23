<?php
/**
 * Stock mirroring between translation twins (SEC-029: no oversell race).
 *
 * Two paths, one owner each:
 * - Order paths (`wc_update_product_stock` with `decrease` / `increase`): the signed quantity is captured from the atomic
 *   SQL through filter `woocommerce_update_product_stock_query` and applied to every managed twin with the same atomic
 *   `wc_update_product_stock( $twin, $qty, 'decrease'|'increase' )` on `woocommerce_*_set_stock`. No absolute value from
 *   memory is ever written. While a delta is pending for a product, the save-time mirror skips its quantity (no double move).
 * - Manual edits / REST / CLI (product save): `ProductSync::align()` mirrors manage flag, status, backorders, low-stock AND
 *   quantity in ONE twin save (hook order: `woocommerce_product_set_stock` fires inside the save, `woocommerce_update_product`
 *   after it, so the flip "unmanaged -> managed" can only be completed by the later save-time pass).
 *   Variations: `woocommerce_(new|update)_product_variation` mirrors the variation stock fields to the mapped twin variation.
 *
 * Variation mapping (never guessed): equal SKU, else equal set of translated global (`pa_*`) attribute terms, else same
 * position when both parents have the same number of variations. Anything else is skipped and logged.
 *
 * Everything under the shared ProductSync loop guard.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Multilang;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Support\Log;

final class StockSync {

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	/**
	 * Signed quantity captured per product id, consumed by propagate().
	 *
	 * @var array<int,float>
	 */
	private static array $pending = [];

	public function __construct( Polylang $polylang ) {
		$this->polylang = $polylang;
	}

	public function register(): void {
		add_filter( 'woocommerce_update_product_stock_query', [ $this, 'capture_delta' ], 10, 4 );
		add_action( 'woocommerce_product_set_stock', [ $this, 'propagate' ] );
		add_action( 'woocommerce_variation_set_stock', [ $this, 'propagate' ] );
		add_action( 'woocommerce_new_product_variation', [ $this, 'on_variation_save' ], 20, 2 );
		add_action( 'woocommerce_update_product_variation', [ $this, 'on_variation_save' ], 20, 2 );
	}

	/**
	 * Is an atomic order delta pending for this product (its save-time quantity mirror must stay quiet).
	 *
	 * @param int $product_id Product (or variation) ID.
	 */
	public static function has_pending( int $product_id ): bool {
		return isset( self::$pending[ $product_id ] );
	}

	/**
	 * Remember the signed quantity of an atomic increase/decrease (read-only filter, SQL is returned untouched).
	 *
	 * @param mixed  $sql        Stock UPDATE statement.
	 * @param int    $product_id Product holding the stock.
	 * @param mixed  $new_stock  Computed new stock (unused).
	 * @param string $operation  set|increase|decrease.
	 * @return mixed
	 */
	public function capture_delta( $sql, $product_id, $new_stock, $operation ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ProductSync::is_running() || 'set' === $operation || ! is_string( $sql ) ) {
			return $sql;
		}
		if ( 1 === preg_match( '/meta_value\s*([+-])\s*([0-9]+(?:\.[0-9]+)?)\s+WHERE/', $sql, $m ) ) {
			$delta                              = (float) $m[2] * ( '-' === $m[1] ? -1 : 1 );
			self::$pending[ (int) $product_id ] = ( self::$pending[ (int) $product_id ] ?? 0.0 ) + $delta;
		}
		return $sql;
	}

	/**
	 * Order paths: move the captured delta to the managed twins (atomic, one `wc_update_product_stock` each).
	 * Manual edits carry no delta and are mirrored by the save-time pass.
	 *
	 * @param mixed $product The product whose stock changed.
	 */
	public function propagate( $product ): void {
		if ( ProductSync::is_running() || ! $product instanceof \WC_Product ) {
			return;
		}

		$id    = $product->get_id();
		$delta = self::$pending[ $id ] ?? null;
		unset( self::$pending[ $id ] );
		if ( null === $delta || 0.0 === $delta ) {
			return;
		}

		if ( $product->is_type( 'variation' ) ) {
			$twin_ids = $this->variation_twins( $product );
		} else {
			$twin_ids = array_values( array_diff( $this->polylang->translations( $id ), [ $id ] ) );
		}
		if ( array() === $twin_ids ) {
			return;
		}

		ProductSync::guarded(
			function () use ( $twin_ids, $delta ): void {
				foreach ( $twin_ids as $twin_id ) {
					$twin = wc_get_product( $twin_id );
					if ( ! $twin instanceof \WC_Product || ! $twin->get_manage_stock( 'edit' ) ) {
						continue;
					}
					try {
						wc_update_product_stock( $twin, abs( $delta ), $delta < 0 ? 'decrease' : 'increase' );
					} catch ( \Exception $e ) {
						Log::warning( 'Stock sync failed: ' . $e->getMessage(), [ 'twin' => $twin_id ], 'multilang' );
					}
				}
			}
		);
	}

	/**
	 * A variation was saved: mirror its stock fields to the mapped twin variations (one save each).
	 *
	 * @param int                   $variation_id Variation ID.
	 * @param \WC_Product_Variation $variation    Variation (optional).
	 */
	public function on_variation_save( $variation_id, $variation = null ): void {
		$variation_id = (int) $variation_id;
		if ( ProductSync::is_running() || $variation_id <= 0 ) {
			return;
		}
		if ( ! $variation instanceof \WC_Product ) {
			$variation = wc_get_product( $variation_id );
		}
		if ( ! $variation instanceof \WC_Product || 'trash' === $variation->get_status( 'edit' ) ) {
			return;
		}
		$twin_ids = $this->variation_twins( $variation );
		if ( array() === $twin_ids ) {
			return;
		}
		$with_qty = ! self::has_pending( $variation_id );
		ProductSync::guarded(
			function () use ( $variation, $twin_ids, $with_qty ): void {
				foreach ( $twin_ids as $twin_id ) {
					$twin = wc_get_product( $twin_id );
					if ( $twin instanceof \WC_Product ) {
						$this->mirror_variation( $variation, $twin, false, $with_qty );
					}
				}
			}
		);
	}

	/**
	 * Bring one twin variation in line with the source variation (single save, only when something differs).
	 *
	 * @param \WC_Product $source   Source variation.
	 * @param \WC_Product $twin     Twin variation.
	 * @param bool        $dry      Report only.
	 * @param bool        $with_qty Mirror the quantity too.
	 * @return string[] Changed field keys.
	 */
	public function mirror_variation( \WC_Product $source, \WC_Product $twin, bool $dry, bool $with_qty = true ): array {
		$changed = [];
		$managed = (bool) $source->get_manage_stock( 'edit' );

		if ( $managed !== (bool) $twin->get_manage_stock( 'edit' ) ) {
			$changed[] = 'manage_stock';
			if ( ! $dry ) {
				$twin->set_manage_stock( $managed );
			}
		}
		if ( $managed && $source->get_backorders( 'edit' ) !== $twin->get_backorders( 'edit' ) ) {
			$changed[] = 'backorders';
			if ( ! $dry ) {
				$twin->set_backorders( $source->get_backorders( 'edit' ) );
			}
		}
		if ( $managed ) {
			$qty = $source->get_stock_quantity( 'edit' );
			if ( $with_qty && ProductSync::quantity_differs( $qty, $twin->get_stock_quantity( 'edit' ) ) ) {
				$changed[] = 'stock_quantity';
				if ( ! $dry ) {
					$twin->set_stock_quantity( $qty ?? 0 );
				}
			} elseif ( ! in_array( 'manage_stock', $changed, true ) && $with_qty && $twin->get_stock_status( 'edit' ) !== $source->get_stock_status( 'edit' ) ) {
				$changed[] = 'stock_status'; // Derived by Woo on save.
			}
		} elseif ( $source->get_stock_status( 'edit' ) !== $twin->get_stock_status( 'edit' ) ) {
			$changed[] = 'stock_status';
			if ( ! $dry ) {
				$twin->set_stock_status( $source->get_stock_status( 'edit' ) );
			}
		}

		if ( array() !== $changed && ! $dry ) {
			try {
				$twin->save();
			} catch ( \Exception $e ) {
				Log::warning( 'Variation stock sync failed: ' . $e->getMessage(), [ 'twin' => $twin->get_id() ], 'multilang' );
			}
		}
		return $changed;
	}

	/**
	 * Twin variation IDs of a variation (one per translated parent), by SKU, translated global attributes, then position.
	 *
	 * @param \WC_Product $variation Variation.
	 * @return int[]
	 */
	public function variation_twins( \WC_Product $variation ): array {
		$parent_id = $variation->get_parent_id();
		if ( $parent_id <= 0 ) {
			return [];
		}
		$twin_parents = array_values( array_diff( $this->polylang->translations( $parent_id ), [ $parent_id ] ) );
		if ( array() === $twin_parents ) {
			return [];
		}
		$source_children = $this->children( $parent_id );
		$out             = [];
		foreach ( $twin_parents as $twin_parent ) {
			$match = $this->match_variation( $variation, $source_children, $this->children( (int) $twin_parent ) );
			if ( $match > 0 ) {
				$out[] = $match;
			} else {
				Log::warning( 'Variation twin not mapped (SKU / attributes / position).', [ 'variation' => $variation->get_id() ], 'multilang' );
			}
		}
		return $out;
	}

	/**
	 * Child variation IDs of a variable product (menu order, ID).
	 *
	 * @param int $parent_id Parent ID.
	 * @return int[]
	 */
	public function children( int $parent_id ): array {
		$parent = wc_get_product( $parent_id );
		return $parent instanceof \WC_Product_Variable ? array_map( 'intval', $parent->get_children() ) : [];
	}

	/**
	 * Pick the twin of one variation among the children of the twin parent.
	 *
	 * @param \WC_Product $variation       Source variation.
	 * @param int[]       $source_children Source parent's children.
	 * @param int[]       $twin_children   Twin parent's children.
	 */
	private function match_variation( \WC_Product $variation, array $source_children, array $twin_children ): int {
		if ( array() === $twin_children ) {
			return 0;
		}
		$id = $variation->get_id();

		$sku = (string) $variation->get_sku( 'edit' );
		if ( '' !== $sku ) {
			foreach ( $twin_children as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child instanceof \WC_Product && (string) $child->get_sku( 'edit' ) === $sku ) {
					return $child_id;
				}
			}
		}

		$signature = $this->attribute_signature( $variation );
		if ( null !== $signature ) {
			$found = [];
			foreach ( $twin_children as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child instanceof \WC_Product && $this->attribute_signature( $child ) === $signature ) {
					$found[] = $child_id;
				}
			}
			if ( 1 === count( $found ) ) {
				return $found[0];
			}
		}

		$index = array_search( $id, $source_children, true );
		if ( false !== $index && count( $source_children ) === count( $twin_children ) ) {
			return (int) ( $twin_children[ $index ] ?? 0 );
		}
		return 0;
	}

	/**
	 * Language-neutral signature of a variation made only of global attributes, null when any attribute is custom/empty.
	 *
	 * @param \WC_Product $variation Variation.
	 */
	private function attribute_signature( \WC_Product $variation ): ?string {
		$attributes = (array) $variation->get_attributes( 'edit' );
		if ( array() === $attributes ) {
			return null;
		}
		$default = $this->polylang->default_language();
		$parts   = [];
		foreach ( $attributes as $taxonomy => $slug ) {
			$taxonomy = (string) $taxonomy;
			if ( 0 !== strpos( $taxonomy, 'pa_' ) || '' === (string) $slug ) {
				return null;
			}
			$term = get_term_by( 'slug', (string) $slug, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				return null;
			}
			$neutral = $this->polylang->translate_term( (int) $term->term_id, $default );
			$parts[] = $taxonomy . '=' . ( $neutral > 0 ? $neutral : (int) $term->term_id );
		}
		sort( $parts );
		return implode( '|', $parts );
	}

	/**
	 * Stock mismatches of one default-language product against its twins (read-only).
	 *
	 * @param \WC_Product $source Authority product.
	 * @return array<int,array{product:int,twin:int,lang:string,field:string,source:string,value:string}>
	 */
	public function audit( \WC_Product $source ): array {
		$group = $this->polylang->translations( $source->get_id() );
		$rows  = [];
		foreach ( array_diff( $group, [ $source->get_id() ] ) as $twin_id ) {
			$twin = wc_get_product( $twin_id );
			if ( ! $twin instanceof \WC_Product || $twin->get_type() !== $source->get_type() ) {
				continue;
			}
			$lang = $this->polylang->post_language( (int) $twin_id );
			if ( $source->is_type( 'variable' ) ) {
				$rows = array_merge( $rows, $this->audit_pair( $source, $twin, $lang, [ 'stock_status' ] ) );
				foreach ( $this->children( $source->get_id() ) as $child_id ) {
					$child = wc_get_product( $child_id );
					if ( ! $child instanceof \WC_Product ) {
						continue;
					}
					$match = $this->match_variation( $child, $this->children( $source->get_id() ), $this->children( (int) $twin_id ) );
					$other = $match > 0 ? wc_get_product( $match ) : null;
					if ( $other instanceof \WC_Product ) {
						$rows = array_merge( $rows, $this->audit_pair( $child, $other, $lang, [ 'manage_stock', 'stock_quantity', 'stock_status', 'backorders' ] ) );
					} else {
						$rows[] = $this->row( $child, $twin_id, $lang, 'variation_twin', 'present', 'unmapped' );
					}
				}
			} else {
				$rows = array_merge( $rows, $this->audit_pair( $source, $twin, $lang, [ 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount' ] ) );
			}
		}
		return $rows;
	}

	/**
	 * Repair the twins of a default-language product: simple + parent fields through ProductSync, variations here.
	 *
	 * @param \WC_Product $source Authority product.
	 * @param ProductSync $sync   Product sync service.
	 */
	public function repair( \WC_Product $source, ProductSync $sync ): void {
		$sync->sync_from( $source, true, false );
		if ( ! $source->is_type( 'variable' ) ) {
			return;
		}
		$source_children = $this->children( $source->get_id() );
		foreach ( array_diff( $this->polylang->translations( $source->get_id() ), [ $source->get_id() ] ) as $twin_id ) {
			$twin_children = $this->children( (int) $twin_id );
			ProductSync::guarded(
				function () use ( $source_children, $twin_children ): void {
					foreach ( $source_children as $child_id ) {
						$child = wc_get_product( $child_id );
						if ( ! $child instanceof \WC_Product ) {
							continue;
						}
						$match = $this->match_variation( $child, $source_children, $twin_children );
						$other = $match > 0 ? wc_get_product( $match ) : null;
						if ( $other instanceof \WC_Product ) {
							$this->mirror_variation( $child, $other, false );
						}
					}
				}
			);
			\WC_Product_Variable::sync( (int) $twin_id );
		}
	}

	/**
	 * Compare the named stock fields of two products.
	 *
	 * @param \WC_Product $source Source.
	 * @param \WC_Product $twin   Twin.
	 * @param string      $lang   Twin language.
	 * @param string[]    $fields Fields.
	 * @return array<int,array{product:int,twin:int,lang:string,field:string,source:string,value:string}>
	 */
	private function audit_pair( \WC_Product $source, \WC_Product $twin, string $lang, array $fields ): array {
		$rows    = [];
		$managed = (bool) $source->get_manage_stock( 'edit' );
		foreach ( $fields as $field ) {
			if ( ! $managed && in_array( $field, [ 'stock_quantity', 'backorders', 'low_stock_amount' ], true ) ) {
				continue; // Not applicable without stock management (Woo clears them).
			}
			$a = $this->field( $source, $field );
			$b = $this->field( $twin, $field );
			if ( 'stock_quantity' === $field || 'low_stock_amount' === $field ? ProductSync::quantity_differs( $a, $b ) : $a !== $b ) {
				$rows[] = $this->row( $source, $twin->get_id(), $lang, $field, (string) $a, (string) $b );
			}
		}
		return $rows;
	}

	/**
	 * Normalised field value.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $field   Field.
	 * @return string|float|null
	 */
	private function field( \WC_Product $product, string $field ) {
		$value = $product->{'get_' . $field}( 'edit' );
		if ( 'manage_stock' === $field ) {
			return $value ? 'yes' : 'no';
		}
		return ( 'stock_quantity' === $field || 'low_stock_amount' === $field ) ? ( ( null === $value || '' === $value ) ? null : (float) $value ) : $value;
	}

	/**
	 * Audit row.
	 *
	 * @param \WC_Product $source Source.
	 * @param int         $twin   Twin ID.
	 * @param string      $lang   Language.
	 * @param string      $field  Field.
	 * @param string      $a      Source value.
	 * @param string      $b      Twin value.
	 * @return array{product:int,twin:int,lang:string,field:string,source:string,value:string}
	 */
	private function row( \WC_Product $source, int $twin, string $lang, string $field, string $a, string $b ): array {
		return [
			'product' => $source->get_id(),
			'twin'    => $twin,
			'lang'    => $lang,
			'field'   => $field,
			'source'  => $a,
			'value'   => $b,
		];
	}
}
