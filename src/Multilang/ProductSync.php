<?php
/**
 * Keeps language-independent product data identical across Polylang translation twins.
 *
 * Direction: the saved product is the source (any language, last write wins); the CLI uses the default-language
 * product as the authority. Synced: prices, sale dates, stock fields, SKU, global (`pa_*`) attributes + missing custom
 * attribute keys, dimensions/weight, tax, shipping class (term mapped per language), sold-individually, virtual/downloadable,
 * image + gallery (Polylang media translation is ON: the twin gets the translated attachment, created via Polylang when missing, never the UA attachment id), catalog visibility, featured flag.
 *
 * NEVER written: title, slug, description, excerpt, categories, tags, Rank Math fields, reviews. This class must not call
 * set_name / set_slug / set_description / set_short_description / set_category_ids / set_tag_ids / set_parent_id.
 *
 * Loop guard: a static flag around every twin write plus meta `_vx_ml_sync_hash` (hash of the source snapshot, stored on
 * every product of the group). A save that changed nothing exits early; a twin already equal is not saved at all, so its
 * `post_modified` stays untouched. Variable parents skip price/stock fields; variation stock is mirrored by StockSync.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Multilang;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Support\Log;

final class ProductSync {

	public const HASH_META = '_vx_ml_sync_hash';

	/**
	 * Scalar fields: key => getter `get_<key>('edit')` / setter `set_<key>()`.
	 */
	private const FIELDS = [
		'regular_price',
		'sale_price',
		'date_on_sale_from',
		'date_on_sale_to',
		'manage_stock',
		'stock_status',
		'backorders',
		'low_stock_amount',
		'sku',
		'weight',
		'length',
		'width',
		'height',
		'tax_status',
		'tax_class',
		'shipping_class_id',
		'sold_individually',
		'virtual',
		'downloadable',
		'image_id',
		'gallery_image_ids',
		'catalog_visibility',
		'featured',
	];

	/**
	 * Skipped for variable parents (price/stock derive from variations).
	 */
	private const VARIABLE_SKIP = [ 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'manage_stock', 'stock_status', 'backorders', 'low_stock_amount' ];

	/**
	 * Set while twins are being written (shared with StockSync).
	 *
	 * @var bool
	 */
	private static bool $running = false;

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	public function __construct( Polylang $polylang ) {
		$this->polylang = $polylang;
	}

	public function register(): void {
		add_action( 'woocommerce_new_product', [ $this, 'on_save' ], 20, 2 );
		add_action( 'woocommerce_update_product', [ $this, 'on_save' ], 20, 2 );
		add_action( 'pll_save_post', [ $this, 'on_translation_saved' ], 20, 3 );
		add_filter( 'wc_product_has_unique_sku', [ $this, 'allow_twin_sku' ], 10, 3 );
	}

	/**
	 * Is a twin write in progress.
	 */
	public static function is_running(): bool {
		return self::$running;
	}

	/**
	 * Run a callback with the loop guard raised.
	 *
	 * @param callable $callback Work that writes twins.
	 * @return mixed
	 */
	public static function guarded( callable $callback ) {
		if ( self::$running ) {
			return $callback();
		}
		self::$running = true;
		try {
			return $callback();
		} finally {
			self::$running = false;
		}
	}

	/**
	 * Product saved (CRUD hook).
	 *
	 * @param int              $product_id Product ID.
	 * @param \WC_Product|null $product  Product object.
	 */
	public function on_save( $product_id, $product = null ): void {
		$product_id = (int) $product_id;
		if ( self::$running || $product_id <= 0 || wp_is_post_autosave( $product_id ) || wp_is_post_revision( $product_id ) ) {
			return;
		}
		if ( ! $product instanceof \WC_Product ) {
			$product = wc_get_product( $product_id );
		}
		if ( ! $product instanceof \WC_Product || in_array( $product->get_status( 'edit' ), [ 'auto-draft', 'trash' ], true ) ) {
			return;
		}

		$this->sync_from( $product, false, false );
	}

	/**
	 * A translation was saved by Polylang. A brand-new twin (no hash yet) is aligned FROM the default-language product,
	 * so empty fields of a fresh twin never overwrite the authority.
	 *
	 * @param int               $post_id      Post ID.
	 * @param \WP_Post|null     $post         Post.
	 * @param array<string,int> $translations Translation group.
	 */
	public function on_translation_saved( $post_id, $post = null, $translations = [] ): void {
		$post_id = (int) $post_id;
		if ( self::$running || $post_id <= 0 || ! $post instanceof \WP_Post || 'product' !== $post->post_type || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$default = $this->polylang->default_language();
		$source  = is_array( $translations ) ? (int) ( $translations[ $default ] ?? 0 ) : 0;
		if ( $source <= 0 || $source === $post_id ) {
			return;
		}
		$twin = wc_get_product( $post_id );
		$base = wc_get_product( $source );
		if ( ! $twin instanceof \WC_Product || ! $base instanceof \WC_Product || '' !== (string) $twin->get_meta( self::HASH_META, true, 'edit' ) ) {
			return;
		}
		$this->sync_from( $base, true, false );
	}

	/**
	 * The same SKU is valid inside one translation group. `true` = a duplicate was found (Woo semantics).
	 *
	 * @param bool   $sku_found  Woo result: another product holds the SKU.
	 * @param int    $product_id Product being validated.
	 * @param string $sku        SKU.
	 * @return bool
	 */
	public function allow_twin_sku( $sku_found, $product_id, $sku ) {
		if ( ! $sku_found || (int) $product_id <= 0 || '' === (string) $sku ) {
			return $sku_found;
		}
		$group = $this->polylang->translations( (int) $product_id );
		if ( count( $group ) < 2 ) {
			return $sku_found;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only lookup, request-scoped and rare.
		$holders = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT posts.ID FROM {$wpdb->posts} AS posts INNER JOIN {$wpdb->wc_product_meta_lookup} AS lookup ON posts.ID = lookup.product_id WHERE posts.post_type IN ( 'product', 'product_variation' ) AND posts.post_status != 'trash' AND lookup.sku = %s AND lookup.product_id <> %d LIMIT 50",
				wp_slash( (string) $sku ),
				(int) $product_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $holders as $holder ) {
			if ( ! in_array( (int) $holder, $group, true ) ) {
				return $sku_found; // Held by a product outside the group: still a duplicate.
			}
		}
		return false;
	}

	/**
	 * Push the source's synced data to every twin.
	 *
	 * @param \WC_Product $source Source product.
	 * @param bool        $force  Ignore the "nothing changed since last sync" hash.
	 * @param bool        $dry    Report only, write nothing.
	 * @return array<int,string[]> Twin ID => changed field keys (empty array when already aligned).
	 */
	public function sync_from( \WC_Product $source, bool $force, bool $dry ): array {
		$group = $this->polylang->translations( $source->get_id() );
		unset( $group[ $this->polylang->post_language( $source->get_id() ) ] );
		$twin_ids = array_values( array_diff( $group, [ $source->get_id() ] ) );
		if ( array() === $twin_ids ) {
			return [];
		}

		$want = $this->snapshot( $source );
		$hash = md5( (string) wp_json_encode( $want ) );
		// Quantity is not part of the hash: a managed source is always compared (unless an order path owns the delta).
		if ( ! $force && ! $this->mirrors_quantity( $source ) && $hash === (string) $source->get_meta( self::HASH_META, true, 'edit' ) ) {
			return [];
		}

		$report = [];
		self::guarded(
			function () use ( $source, $twin_ids, $want, $hash, $dry, &$report ): void {
				foreach ( $twin_ids as $twin_id ) {
					$twin = wc_get_product( $twin_id );
					if ( ! $twin instanceof \WC_Product || $twin->get_type() !== $source->get_type() ) {
						continue;
					}
					$report[ $twin_id ] = $this->align( $source, $want, $twin, $hash, $dry );
				}
				if ( ! $dry && $hash !== (string) $source->get_meta( self::HASH_META, true, 'edit' ) ) {
					$source->update_meta_data( self::HASH_META, $hash );
					$source->save_meta_data();
				}
			}
		);

		return $report;
	}

	/**
	 * Bulk alignment from the default-language products (CLI).
	 *
	 * @param int[] $ids Default-language product IDs.
	 * @param bool  $dry Report only.
	 * @return array{checked:int,changed:int,twins:int}
	 */
	public function sync_ids( array $ids, bool $dry ): array {
		$stats = [
			'checked' => 0,
			'changed' => 0,
			'twins'   => 0,
		];
		foreach ( array_chunk( array_map( 'intval', $ids ), 50 ) as $batch ) {
			$products = wc_get_products(
				[
					'include' => $batch,
					'limit'   => 50,
					'status'  => [ 'publish', 'draft', 'pending', 'private' ],
					'orderby' => 'none',
				]
			);
			foreach ( $products as $product ) {
				++$stats['checked'];
				$report          = $this->sync_from( $product, true, $dry );
				$stats['twins'] += count( $report );
				foreach ( $report as $keys ) {
					if ( array() !== $keys ) {
						++$stats['changed'];
					}
				}
			}
		}
		return $stats;
	}

	/**
	 * Bring one twin in line. Saves only when something differs (no-op = no write, `post_modified` untouched).
	 *
	 * @param \WC_Product         $source Source.
	 * @param array<string,mixed> $want   Source snapshot.
	 * @param \WC_Product         $twin   Twin.
	 * @param string              $hash   Snapshot hash.
	 * @param bool                $dry    Report only.
	 * @return string[] Changed keys.
	 */
	private function align( \WC_Product $source, array $want, \WC_Product $twin, string $hash, bool $dry ): array {
		$have    = $this->snapshot( $twin );
		$lang    = $this->polylang->post_language( $twin->get_id() );
		$changed = [];

		foreach ( $this->keys_for( $source ) as $key ) {
			if ( ( $want[ $key ] ?? null ) === ( $have[ $key ] ?? null ) && ! $this->media_in_wrong_language( $twin, $key, $lang ) ) {
				continue;
			}
			$changed[] = $key;
			if ( ! $dry ) {
				$this->set_value( $twin, $key, $want[ $key ], $lang );
			}
		}

		// Stock quantity + derived status mirror in the same pass (one save per twin). A twin that flips to managed gets the
		// quantity here, not only when it was already managed. Order paths (pending delta) are moved by StockSync instead.
		if ( $this->mirrors_quantity( $source ) ) {
			$want_qty = $source->get_stock_quantity( 'edit' );
			if ( self::quantity_differs( $want_qty, $twin->get_stock_quantity( 'edit' ) ) ) {
				$changed[] = 'stock_quantity';
				if ( ! $dry ) {
					$twin->set_stock_quantity( $want_qty ?? 0 );
				}
			} elseif ( ! in_array( 'manage_stock', $changed, true ) && $twin->get_manage_stock( 'edit' ) && $twin->get_stock_status( 'edit' ) !== $source->get_stock_status( 'edit' ) ) {
				$changed[] = 'stock_status'; // Derived by Woo from quantity/backorders on save.
			}
		}

		$attributes = $this->merge_attributes( $source, $twin, $lang );
		if ( null !== $attributes ) {
			$changed[] = 'attributes';
			if ( ! $dry ) {
				$twin->set_attributes( $attributes );
			}
		}

		if ( $dry ) {
			return $changed;
		}

		if ( array() === $changed ) {
			if ( $hash !== (string) $twin->get_meta( self::HASH_META, true, 'edit' ) ) {
				$twin->update_meta_data( self::HASH_META, $hash );
				$twin->save_meta_data();
			}
			return $changed;
		}
		$twin->update_meta_data( self::HASH_META, $hash );

		try {
			$twin->save();
		} catch ( \Exception $e ) {
			Log::warning(
				'Twin sync failed: ' . $e->getMessage(),
				[
					'source' => $source->get_id(),
					'twin'   => $twin->get_id(),
				],
				'multilang'
			);
		}
		return $changed;
	}

	/**
	 * Should the quantity be mirrored from this source now (managed simple product, no atomic order delta pending for it).
	 *
	 * @param \WC_Product $source Source.
	 */
	private function mirrors_quantity( \WC_Product $source ): bool {
		return ! $source->is_type( 'variable' ) && (bool) $source->get_manage_stock( 'edit' ) && ! StockSync::has_pending( $source->get_id() );
	}

	/**
	 * Quantities differ (float compare; an empty managed quantity equals 0, as Woo treats it).
	 *
	 * @param mixed $a First.
	 * @param mixed $b Second.
	 */
	public static function quantity_differs( $a, $b ): bool {
		return (float) ( $a ?? 0 ) !== (float) ( $b ?? 0 );
	}

	/**
	 * Field keys applicable to the product type.
	 *
	 * @param \WC_Product $product Product.
	 * @return string[]
	 */
	private function keys_for( \WC_Product $product ): array {
		$keys = $product->is_type( 'variable' ) ? array_values( array_diff( self::FIELDS, self::VARIABLE_SKIP ) ) : self::FIELDS;
		// Quantity is mirrored in align() (not a snapshot key). With managed stock the status derives from it.
		if ( $product->get_manage_stock( 'edit' ) ) {
			$keys = array_values( array_diff( $keys, [ 'stock_status' ] ) );
		}
		return $keys;
	}

	/**
	 * Normalised, language-neutral values of the synced fields (+ attribute structure for the hash).
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,mixed>
	 */
	private function snapshot( \WC_Product $product ): array {
		$out = [];
		foreach ( $this->keys_for( $product ) as $key ) {
			$value = $product->{'get_' . $key}( 'edit' );
			if ( 'date_on_sale_from' === $key || 'date_on_sale_to' === $key ) {
				$value = $value instanceof \WC_DateTime ? $value->getTimestamp() : null;
			} elseif ( 'shipping_class_id' === $key ) {
				$value = $this->neutral_term( (int) $value );
			} elseif ( 'image_id' === $key ) {
				$value = $this->neutral_media( (int) $value );
			} elseif ( 'gallery_image_ids' === $key ) {
				$value = array_map( [ $this, 'neutral_media' ], array_map( 'intval', (array) $value ) );
			} elseif ( 'low_stock_amount' === $key ) {
				$value = ( null === $value || '' === $value ) ? null : (float) $value;
			}
			$out[ $key ] = $value;
		}

		$attrs = [];
		$names = [];
		foreach ( $product->get_attributes( 'edit' ) as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				$options                         = $this->neutral_options( $attribute );
				$attrs[ $attribute->get_name() ] = [ $options, $attribute->get_position(), $attribute->get_visible(), $attribute->get_variation() ];
			} else {
				$names[] = $attribute->get_name();
			}
		}
		ksort( $attrs );
		sort( $names );
		$out['_attrs']  = $attrs;
		$out['_custom'] = $names;

		return $out;
	}

	/**
	 * Apply one snapshot value to the twin.
	 *
	 * @param \WC_Product $twin  Twin.
	 * @param string      $key   Field key (from the FIELDS whitelist).
	 * @param mixed       $value Normalised value.
	 * @param string      $lang  Twin language.
	 */
	private function set_value( \WC_Product $twin, string $key, $value, string $lang ): void {
		if ( 'shipping_class_id' === $key ) {
			$value = (int) $value;
			$local = $value > 0 ? $this->polylang->translate_term( $value, $lang ) : 0;
			$value = $local > 0 ? $local : $value;
		}
		if ( 'image_id' === $key ) {
			$value = $this->polylang->media_in_language( (int) $value, $lang );
		} elseif ( 'gallery_image_ids' === $key ) {
			$value = array_map(
				fn( $id ): int => $this->polylang->media_in_language( (int) $id, $lang ),
				(array) $value
			);
		}
		$twin->{'set_' . $key}( $value );
	}

	/**
	 * Does the twin point at an attachment of another language (e.g. the UA image assigned by mistake).
	 *
	 * @param \WC_Product $twin Twin.
	 * @param string      $key  Field key.
	 * @param string      $lang Twin language.
	 */
	private function media_in_wrong_language( \WC_Product $twin, string $key, string $lang ): bool {
		if ( 'image_id' !== $key && 'gallery_image_ids' !== $key ) {
			return false;
		}
		$ids = 'image_id' === $key ? [ (int) $twin->get_image_id( 'edit' ) ] : array_map( 'intval', (array) $twin->get_gallery_image_ids( 'edit' ) );
		foreach ( $ids as $id ) {
			if ( $id > 0 && $this->polylang->post_language( $id ) !== $lang ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Default-language id of an attachment (media is translated: each language keeps its own alt/title), same id when untranslated.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function neutral_media( int $attachment_id ): int {
		if ( $attachment_id <= 0 ) {
			return 0;
		}
		$default = $this->polylang->translate_post( $attachment_id, $this->polylang->default_language() );
		return $default > 0 ? $default : $attachment_id;
	}

	/**
	 * Merged attribute list for the twin, or null when nothing differs.
	 *
	 * @param \WC_Product $source Source.
	 * @param \WC_Product $twin   Twin.
	 * @param string      $lang   Twin language (terms of translated `pa_*` taxonomies are mapped to it).
	 * @return array<string,\WC_Product_Attribute>|null
	 */
	private function merge_attributes( \WC_Product $source, \WC_Product $twin, string $lang ): ?array {
		$result  = [];
		$differs = false;

		$mine = [];
		foreach ( $twin->get_attributes( 'edit' ) as $key => $attribute ) {
			$mine[ (string) $key ] = $attribute;
		}

		// Custom attribute names are per language (`розмір` / `размер`): the structure is copied only into a twin that
		// has no custom attributes yet, otherwise a duplicate in the wrong language would appear.
		$twin_has_custom = false;
		foreach ( $mine as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && ! $attribute->is_taxonomy() ) {
				$twin_has_custom = true;
				break;
			}
		}

		$seen = [];
		foreach ( $source->get_attributes( 'edit' ) as $key => $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$key = (string) $key;
			if ( $attribute->is_taxonomy() ) {
				$seen[ $key ] = true;
				$current      = $mine[ $key ] ?? null;
				$wanted       = $this->localized( $attribute, $lang ); // Twin terms of the language: a twin still on the source terms differs.
				if ( ! $current instanceof \WC_Product_Attribute || $this->attribute_signature( $current ) !== $this->attribute_signature( $wanted ) ) {
					$differs = true;
				}
				$result[ $key ] = $wanted;
			} elseif ( isset( $mine[ $key ] ) ) {
				$result[ $key ] = $mine[ $key ]; // Custom attribute the twin already has: values stay per language.
				$seen[ $key ]   = true;
			} elseif ( ! $twin_has_custom ) {
				$seen[ $key ]   = true;
				$result[ $key ] = clone $attribute;
				$differs        = true;
			}
		}

		foreach ( $mine as $key => $attribute ) {
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			if ( $attribute instanceof \WC_Product_Attribute && $attribute->is_taxonomy() ) {
				$differs = true; // Global attribute removed on the source.
				continue;
			}
			$result[ $key ] = $attribute; // Custom attribute only the twin has: keep.
		}

		return $differs ? $result : null;
	}

	/**
	 * Comparable form of a taxonomy attribute.
	 *
	 * @param \WC_Product_Attribute $attribute Attribute.
	 * @return array<int,mixed>
	 */
	private function attribute_signature( \WC_Product_Attribute $attribute ): array {
		$options = array_map( 'intval', $attribute->get_options() );
		sort( $options );
		return [ $options, $attribute->get_position(), $attribute->get_visible(), $attribute->get_variation() ];
	}

	/**
	 * Default-language (neutral) sorted term ids of a taxonomy attribute: a twin pointing at the RU terms equals the source.
	 *
	 * @param \WC_Product_Attribute $attribute Attribute.
	 * @return int[]
	 */
	private function neutral_options( \WC_Product_Attribute $attribute ): array {
		$options = array_values( array_unique( array_map( [ $this, 'neutral_term' ], array_map( 'intval', $attribute->get_options() ) ) ) );
		sort( $options );
		return $options;
	}

	/**
	 * Copy of a source attribute whose terms are the twins of the language (a term without a twin, e.g. an untranslated taxonomy,
	 * keeps its id).
	 *
	 * @param \WC_Product_Attribute $attribute Source attribute.
	 * @param string                $lang      Twin language.
	 */
	private function localized( \WC_Product_Attribute $attribute, string $lang ): \WC_Product_Attribute {
		$copy    = clone $attribute;
		$options = [];
		foreach ( array_map( 'intval', $attribute->get_options() ) as $term_id ) {
			$local     = '' !== $lang && $term_id > 0 ? $this->polylang->translate_term( $term_id, $lang ) : 0;
			$options[] = $local > 0 ? $local : $term_id;
		}
		$copy->set_options( array_values( array_unique( $options ) ) );
		return $copy;
	}

	/**
	 * Default-language id of a term (shipping class), same id when untranslated.
	 *
	 * @param int $term_id Term ID.
	 */
	private function neutral_term( int $term_id ): int {
		if ( $term_id <= 0 ) {
			return 0;
		}
		$default = $this->polylang->translate_term( $term_id, $this->polylang->default_language() );
		return $default > 0 ? $default : $term_id;
	}
}
