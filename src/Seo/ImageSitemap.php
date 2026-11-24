<?php
/**
 * Image sitemap of the product pages (TASK-044a, SEO review MAJOR): every product entry of the Rank Math product sitemap lists its gallery.
 *
 * Replaces Rank Math's image list of a product (filter `rank_math/sitemap/urlimages`, priority 20, through the adapter) with: the featured image
 * first, then the gallery images in order, full-size URLs, the alt text as title, at most 20, no duplicates, never a variation image, a
 * placeholder or a thumb size. A password-protected product has no images in the sitemap. Every language twin is a separate entry with its own
 * images (the filter runs per post). A gallery change invalidates the stored product sitemap (`RankMath::invalidate_sitemap( 'product' )`).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Integrations\RankMath\RankMath;

final class ImageSitemap {

	public const CAP = 20;

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
		$this->rank_math->filter_sitemap_images( [ $this, 'images' ] );
		add_action( 'woocommerce_update_product', [ $this, 'on_product' ] );
		add_action( 'woocommerce_new_product', [ $this, 'on_product' ] );
	}

	/**
	 * The images of a product sitemap entry.
	 *
	 * @param mixed $images  Images collected by Rank Math.
	 * @param mixed $post_id Post id.
	 * @return mixed
	 */
	public function images( $images, $post_id = 0 ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || 'product' !== get_post_type( $post_id ) || ! function_exists( 'wc_get_product' ) ) {
			return $images;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof \WC_Product ) {
			return $images;
		}
		if ( '' !== (string) get_post_field( 'post_password', $post_id ) ) {
			return [];
		}
		$ids  = array_merge( [ (int) $product->get_image_id() ], array_map( 'intval', $product->get_gallery_image_ids() ) );
		$out  = [];
		$seen = [];
		foreach ( $ids as $id ) {
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$src         = wp_get_attachment_image_src( $id, 'full' );
			if ( ! is_array( $src ) || '' === (string) $src[0] ) {
				continue;
			}
			$alt   = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			$out[] = [
				'src'   => esc_url_raw( (string) $src[0] ),
				'title' => '' !== $alt ? $alt : $product->get_name(),
				'alt'   => '' !== $alt ? $alt : $product->get_name(),
			];
			if ( count( $out ) >= self::CAP ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A product (and so maybe its gallery) was saved: drop the stored product sitemap so the new images show without a manual flush.
	 */
	public function on_product(): void {
		$this->rank_math->invalidate_sitemap( 'product' );
	}
}
