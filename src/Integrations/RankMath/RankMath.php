<?php
/**
 * Rank Math adapter: availability, version, sitemap exclusion and robots reads.
 *
 * Verified against Rank Math 1.0.282: filter `rank_math/sitemap/posts_to_exclude` (array of ids),
 * robots stored in post meta `rank_math_robots` (array of directives).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Integrations\RankMath;

use Vextra\Core\Integrations\AbstractIntegration;

final class RankMath extends AbstractIntegration {

	public function id(): string {
		return 'rankmath';
	}

	protected function detect(): bool {
		return class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
	}

	public function version(): ?string {
		return defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : null;
	}

	/**
	 * Add post ids to the sitemap exclusion list. No-op when Rank Math is absent.
	 *
	 * @param callable $callback Receives the current id array, returns the extended array.
	 */
	public function exclude_posts_from_sitemap( callable $callback ): void {
		if ( $this->is_available() ) {
			add_filter( 'rank_math/sitemap/posts_to_exclude', $callback );
		}
	}

	/**
	 * Whether the post is explicitly set to noindex in Rank Math.
	 *
	 * @param int $post_id Post ID (a non-product post type; products use WC CRUD elsewhere).
	 */
	public function is_post_noindex( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		return is_array( $robots ) && in_array( 'noindex', $robots, true );
	}

	/**
	 * Mark a post noindex (keeps other directives).
	 *
	 * @param int $post_id Post ID of a page.
	 */
	public function set_post_noindex( int $post_id ): void {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		$robots = is_array( $robots ) ? $robots : [];
		if ( ! in_array( 'noindex', $robots, true ) ) {
			$robots[] = 'noindex';
			update_post_meta( $post_id, 'rank_math_robots', $robots );
		}
	}
}
