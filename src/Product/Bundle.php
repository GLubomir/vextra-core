<?php
/**
 * Variation / attribute / image / stock bundle of a product page (TASK-044a). ONE cache entry per product and language
 * (`Support\Cache::remember_swr`, group `vx_pdp`, key `p:<id>:<lang>:v1`): a variable page reads it once instead of calling
 * `wc_get_product()` per variation. Simple products have no variations: their bundle is built from the loaded product without a cache read.
 *
 * Shape (documented in `docs/system/modules/product-page.md`):
 * `{ v:1, product_id, type, attributes:[ { slug, name, label, taxonomy, renderer, options:[ { value, label, hex?, image?, available } ], default } ],
 *    variations:[ { id, a:{ attribute_x: value|'' }, price_now_html, price_old_html, saving_html, pct, status, qty, sku, purchasable, image } ],
 *    images:{ '<id>': { src, srcset, w, h, full, alt_suffix } }, more:bool, min_price_html }`.
 * `saving_text` ("Економія 3 000 ₴") is added AFTER the cache read (`finish()`): the cached data must not carry one language.
 * More than `inline_cap()` variations (60): `more:true`, `variations:[]`, every attribute is a select (no live price update; Woo resolves
 * the variation from the submitted attributes on POST).
 *
 * Invalidation: product / variation save, stock and stock-status change, sale schedule, `pa_*` term edit, exposure change, twin sync
 * (StockSync / ProductSync use the same Woo hooks). A flush marks every entry stale, the last value is kept and rebuilt by ONE deduped job.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Product;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;
use Vextra\Core\Support\Cache;

final class Bundle {

	public const GROUP   = 'vx_pdp';
	public const VERSION = 'v1';
	public const CAP     = 60;

	private const TTL = DAY_IN_SECONDS;

	private const HOOKS = [
		'woocommerce_new_product',
		'woocommerce_update_product',
		'woocommerce_delete_product',
		'woocommerce_trash_product',
		'woocommerce_save_product_variation',
		'woocommerce_new_product_variation',
		'woocommerce_update_product_variation',
		'woocommerce_delete_product_variation',
		'woocommerce_product_set_stock',
		'woocommerce_variation_set_stock',
		'woocommerce_product_set_stock_status',
		'woocommerce_variation_set_stock_status',
		'woocommerce_scheduled_sales',
		'vextra/catalog/product_exposure_changed',
	];

	/**
	 * Per-request memo.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $memo = [];

	/**
	 * A flush is queued for shutdown.
	 *
	 * @var bool
	 */
	private static bool $queued = false;

	public static function register(): void {
		Cache::boot_swr();
		Cache::register_rebuilder( self::GROUP, [ self::class, 'rebuild' ] );
		foreach ( self::HOOKS as $hook ) {
			add_action( $hook, [ self::class, 'invalidate' ] );
		}
		foreach ( [ 'created_term', 'edited_term', 'delete_term' ] as $hook ) {
			add_action( $hook, [ self::class, 'on_term' ], 10, 3 );
		}
		add_action( 'pll_save_term', [ self::class, 'invalidate' ] );
	}

	/**
	 * Mark every bundle stale, once per request at shutdown (an import saves thousands of products).
	 */
	public static function invalidate(): void {
		if ( self::$queued ) {
			return;
		}
		self::$queued = true;
		add_action( 'shutdown', [ self::class, 'flush_now' ], 5 );
	}

	/**
	 * Only attribute taxonomies matter.
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $tt_id    Term taxonomy id.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public static function on_term( $term_id = 0, $tt_id = 0, $taxonomy = '' ): void {
		unset( $term_id, $tt_id );
		if ( is_string( $taxonomy ) && str_starts_with( $taxonomy, 'pa_' ) ) {
			self::invalidate();
		}
	}

	/**
	 * Flush now (shutdown callback; checks call it directly).
	 */
	public static function flush_now(): void {
		self::$queued = false;
		self::$memo   = [];
		Cache::flush( self::GROUP );
	}

	/**
	 * Forget the per-request memo (checks).
	 */
	public static function reset(): void {
		self::$memo = [];
	}

	/**
	 * Inline variation cap (filter `vextra/pdp/inline_variations`).
	 */
	public static function inline_cap(): int {
		return max( 1, (int) apply_filters( 'vextra/pdp/inline_variations', self::CAP ) );
	}

	/**
	 * Cache key of a product in a language.
	 *
	 * @param int    $product_id Product id.
	 * @param string $lang       Language slug.
	 */
	public static function key( int $product_id, string $lang ): string {
		return 'p:' . $product_id . ':' . $lang . ':' . self::VERSION;
	}

	/**
	 * Bundle of a product (translated parts added, never stored).
	 *
	 * @param int $product_id Product id.
	 * @return array<string,mixed>
	 */
	public static function get( int $product_id ): array {
		if ( isset( self::$memo[ $product_id ] ) ) {
			return self::$memo[ $product_id ];
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			self::$memo[ $product_id ] = self::empty_bundle( $product_id, 'simple' );
			return self::$memo[ $product_id ];
		}
		if ( ! $product->is_type( 'variable' ) ) {
			self::$memo[ $product_id ] = self::empty_bundle( $product_id, $product->get_type() );
			return self::$memo[ $product_id ];
		}
		$pll  = Plugin::instance()->integration( 'polylang' );
		$lang = $pll instanceof Polylang ? $pll->post_language( $product_id ) : '';
		$data = Cache::remember_swr(
			self::GROUP,
			self::key( $product_id, $lang ),
			self::TTL,
			static fn(): array => self::build( $product_id ),
			[
				'id'   => $product_id,
				'lang' => $lang,
			]
		);
		if ( ! is_array( $data ) || ! isset( $data['v'] ) ) {
			$data = self::build( $product_id );
		}
		self::$memo[ $product_id ] = self::finish( $data );
		return self::$memo[ $product_id ];
	}

	/**
	 * SWR rebuilder (background job, no request language).
	 *
	 * @param array<string,mixed> $recipe `id`, `lang`.
	 * @return array<string,mixed>
	 */
	public static function rebuild( array $recipe ): array {
		return self::build( absint( $recipe['id'] ?? 0 ) );
	}

	/**
	 * Empty bundle.
	 *
	 * @param int    $product_id Product id.
	 * @param string $type       Product type.
	 * @return array<string,mixed>
	 */
	private static function empty_bundle( int $product_id, string $type ): array {
		return [
			'v'              => 1,
			'product_id'     => $product_id,
			'type'           => $type,
			'attributes'     => [],
			'variations'     => [],
			'images'         => [],
			'more'           => false,
			'min_price_html' => '',
		];
	}

	/**
	 * Translated parts, after the cache read (labels of global attributes, saving line).
	 *
	 * @param array<string,mixed> $data Cached bundle.
	 * @return array<string,mixed>
	 */
	private static function finish( array $data ): array {
		foreach ( $data['attributes'] as $i => $attribute ) {
			if ( '' !== (string) $attribute['taxonomy'] && function_exists( 'vx_catalog_attribute_label' ) ) {
				$data['attributes'][ $i ]['label'] = (string) vx_catalog_attribute_label( (string) $attribute['label'] );
			}
		}
		foreach ( $data['variations'] as $i => $variation ) {
			$data['variations'][ $i ]['saving_text'] = '' !== (string) $variation['saving_html']
				/* translators: %s: formatted amount saved on a sale price. */
				? sprintf( __( 'Економія %s', 'vextra-core' ), $variation['saving_html'] )
				: '';
		}
		return $data;
	}

	/**
	 * Cold build of a variable product bundle.
	 *
	 * @param int $product_id Product id.
	 * @return array<string,mixed>
	 */
	public static function build( int $product_id ): array {
		$product = wc_get_product( $product_id );
		$out     = self::empty_bundle( $product_id, 'variable' );
		if ( ! $product instanceof \WC_Product_Variable ) {
			return $out;
		}

		$children              = array_map( 'intval', $product->get_children() );
		$out['more']           = count( $children ) > self::inline_cap();
		$min                   = $product->get_variation_price( 'min' );
		$out['min_price_html'] = '' === (string) $min ? '' : self::money( (float) $min );

		$variations = [];
		if ( ! $out['more'] ) {
			foreach ( $children as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation instanceof \WC_Product_Variation && 'publish' === $variation->get_status() ) {
					$variations[] = $variation;
				}
			}
		}

		$names    = array_keys( (array) $product->get_variation_attributes() );
		$defaults = (array) $product->get_default_attributes();
		$items    = [];
		$images   = [];
		foreach ( $variations as $variation ) {
			$item = self::item( $variation, $names );
			if ( null !== $item['image'] ) {
				$images[ (int) $item['image'] ] = true;
			}
			$items[] = $item;
		}

		$attributes = [];
		foreach ( (array) $product->get_variation_attributes() as $name => $values ) {
			$attributes[] = self::attribute( $product, (string) $name, (array) $values, (string) ( $defaults[ sanitize_title( (string) $name ) ] ?? '' ), $items, $out['more'] );
		}

		$out['attributes'] = $attributes;
		$out['variations'] = $items;
		foreach ( array_keys( $images ) as $image_id ) {
			$data = self::image_data( (int) $image_id );
			if ( null !== $data ) {
				$out['images'][ (string) $image_id ] = $data;
			}
		}
		return $out;
	}

	/**
	 * One variation item.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @param string[]              $names     Attribute names of the parent.
	 * @return array<string,mixed>
	 */
	private static function item( \WC_Product_Variation $variation, array $names ): array {
		$own = (array) $variation->get_attributes( 'edit' );
		$a   = [];
		foreach ( $names as $name ) {
			$key                      = sanitize_title( (string) $name );
			$a[ 'attribute_' . $key ] = isset( $own[ $key ] ) ? (string) $own[ $key ] : '';
		}
		$now     = $variation->get_price();
		$regular = $variation->get_regular_price();
		$sale    = $variation->is_on_sale() && '' !== (string) $regular && '' !== (string) $now && (float) $regular > (float) $now;
		$status  = $variation->get_stock_status();
		$image   = (int) $variation->get_image_id( 'edit' );
		return [
			'id'             => $variation->get_id(),
			'a'              => $a,
			'price_now_html' => '' === (string) $now ? '' : self::money( (float) $now ),
			'price_old_html' => $sale ? self::money( (float) $regular ) : '',
			'saving_html'    => $sale ? self::money( (float) $regular - (float) $now ) : '',
			'pct'            => $sale ? (int) round( ( (float) $regular - (float) $now ) / (float) $regular * 100 ) : 0,
			'status'         => $status,
			'qty'            => self::public_qty( $variation ),
			'sku'            => (string) $variation->get_sku(),
			'purchasable'    => $variation->is_purchasable() && in_array( $status, [ 'instock', 'onbackorder' ], true ),
			'image'          => $image > 0 ? $image : null,
		];
	}

	/**
	 * Exact stock of a variation, ONLY when it is low (SEC-070): managed, positive and at most the low-stock threshold (the variation's own
	 * or its parent's, else the store's). Otherwise null: the page shows availability (`status`) only; Woo validates the quantity on POST.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 */
	private static function public_qty( \WC_Product_Variation $variation ): ?int {
		if ( ! $variation->managing_stock() ) {
			return null;
		}
		$qty = (int) $variation->get_stock_quantity();
		$own = $variation->get_low_stock_amount();
		$woo = Plugin::instance()->integration( 'woocommerce' );
		$max = '' !== $own && null !== $own ? (int) $own : ( $woo instanceof \Vextra\Core\Integrations\WooCommerce\WooCommerce ? $woo->low_stock_threshold() : 2 );
		return $qty > 0 && $qty <= $max ? $qty : null;
	}

	/**
	 * One attribute group with options and availability.
	 *
	 * @param \WC_Product_Variable           $product  Product.
	 * @param string                         $name     Attribute name (`pa_color` or the custom label).
	 * @param array<int,string>              $values   Values used by the variations.
	 * @param string                         $fallback Default value.
	 * @param array<int,array<string,mixed>> $items    Variation items.
	 * @param bool                           $more     More than the inline cap (select only).
	 * @return array<string,mixed>
	 */
	private static function attribute( \WC_Product_Variable $product, string $name, array $values, string $fallback, array $items, bool $more ): array {
		$is_tax  = taxonomy_exists( $name );
		$key     = 'attribute_' . sanitize_title( $name );
		$options = [];
		if ( $is_tax ) {
			$terms = wc_get_product_terms( $product->get_id(), $name, [ 'fields' => 'all' ] );
			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				if ( $term instanceof \WP_Term && in_array( $term->slug, $values, true ) ) {
					$options[] = [
						'value' => $term->slug,
						'label' => $term->name,
						'hex'   => Swatches::hex( (int) $term->term_id ),
						'image' => Swatches::image( (int) $term->term_id ),
					];
				}
			}
		} else {
			foreach ( $values as $value ) {
				$options[] = [
					'value' => (string) $value,
					'label' => (string) $value,
				];
			}
		}
		$label = (string) wc_attribute_label( $name, $product );
		if ( ! $is_tax && '' !== $label ) {
			$label = mb_strtoupper( mb_substr( $label, 0, 1 ) ) . mb_substr( $label, 1 );
		}
		$renderer = $more ? 'select' : Swatches::renderer( $is_tax ? $name : '', $label, $options );
		foreach ( $options as $i => $option ) {
			$available = $more;
			foreach ( $items as $item ) {
				if ( ! empty( $item['purchasable'] ) && ( '' === (string) $item['a'][ $key ] || self::same( (string) $item['a'][ $key ], (string) $option['value'] ) ) ) {
					$available = true;
					break;
				}
			}
			$options[ $i ]['available'] = $available;
			if ( 'color' !== $renderer ) {
				unset( $options[ $i ]['hex'] );
			}
			if ( 'image' !== $renderer ) {
				unset( $options[ $i ]['image'] );
			}
		}
		return [
			'slug'     => sanitize_title( $name ),
			'name'     => $key,
			'label'    => $label,
			'taxonomy' => $is_tax ? $name : '',
			'renderer' => $renderer,
			'options'  => $options,
			'default'  => $fallback,
		];
	}

	/**
	 * Formatted price as compact escaped text (`12 345 ₴`, NBSP, store currency settings): Woo's `wc_price()` markup is ~170 bytes per
	 * amount, too heavy for a per-variation JSON (SEO MINOR-6, 6 KB for 12 variations).
	 *
	 * @param float $value Amount.
	 */
	private static function money( float $value ): string {
		return esc_html( html_entity_decode( wp_strip_all_tags( wc_price( $value ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Same attribute value (Woo compares custom values case-insensitively).
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 */
	private static function same( string $a, string $b ): bool {
		return $a === $b || 0 === strcasecmp( $a, $b );
	}

	/**
	 * Data of one image: 720 px src, srcset of the three page sizes (never the 1440 px one), full URL, dimensions.
	 *
	 * @param int $id Attachment id.
	 * @return array<string,mixed>|null
	 */
	public static function image_data( int $id ): ?array {
		$main = wp_get_attachment_image_src( $id, 'vx-pdp' );
		if ( ! is_array( $main ) ) {
			return null;
		}
		$set = [];
		foreach ( [ 'vx-pdp-s', 'vx-pdp', 'vx-pdp-l' ] as $size ) {
			$row = wp_get_attachment_image_src( $id, $size );
			if ( is_array( $row ) && (int) $row[1] > 0 ) {
				$set[ (int) $row[1] ] = esc_url_raw( (string) $row[0] ) . ' ' . (int) $row[1] . 'w';
			}
		}
		ksort( $set );
		$full = wp_get_attachment_image_src( $id, 'full' );
		return [
			'src'        => esc_url_raw( (string) $main[0] ),
			'srcset'     => implode( ', ', $set ),
			'w'          => (int) $main[1],
			'h'          => (int) $main[2],
			'full'       => is_array( $full ) ? esc_url_raw( (string) $full[0] ) : esc_url_raw( (string) $main[0] ),
			'alt_suffix' => '',
		];
	}
}
