<?php
/**
 * Comment / ping channels closed outside products, comment feeds gone, no comment pagination or threading URLs.
 *
 * - `comments_open`: true for EVERY product (SEO: WooCommerce prints the Reviews tab, with the approved reviews, only when
 *   comments are open; the form is hidden by `Form`, never here), false for everything else. `pings_open`: false everywhere.
 * - `wp_insert_post_data`: new non-product posts are closed on insert; new products start open; pings are always closed.
 *   `close_legacy()` closes the posts that already exist (CLI `close-legacy`, once automatically per policy version).
 * - Comment feeds (`/comments/feed/`, `?feed=comments-rss2`, `<post>/feed/`): hard 404 at `wp` priority 1; no `feed_links_extra`.
 *   `X-Pingback` / RSD / WLW links stay owned by `Security\XmlRpc` (no second filter here).
 * - `?replytocom=` and `/comment-page-N/`: one 301 hop to the clean URL (GET / HEAD, other parameters kept).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Reviews;

use Vextra\Core\Guard\Request;
use Vextra\Core\Guard\Responder;

final class Closed {

	/**
	 * Post types the policy never touches.
	 */
	private const SKIP_TYPES = [ 'product', 'product_variation', 'revision', 'nav_menu_item', 'shop_order', 'shop_order_refund', 'shop_coupon' ];

	/**
	 * Hooks that must work everywhere (also under WP-CLI: `wp post create`, `wp eval comments_open()`).
	 */
	public function register_data(): void {
		add_filter( 'comments_open', [ $this, 'comments_open' ], 99, 2 );
		add_filter( 'pings_open', '__return_false', 99 );
		add_filter( 'wp_insert_post_data', [ $this, 'insert_post_data' ], 99, 4 );
	}

	/**
	 * Front-end request hooks (never under WP-CLI).
	 */
	public function register_front(): void {
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		add_action( 'wp', [ $this, 'comment_feed_404' ], 1 );
		add_action( 'template_redirect', [ $this, 'clean_redirect' ], 1 );
	}

	/**
	 * `comments_open` 99: reviews open on every product, closed on everything else.
	 *
	 * @param mixed $open    Core verdict.
	 * @param mixed $post_id Post ID.
	 * @return mixed
	 */
	public function comments_open( $open, $post_id = 0 ) {
		return 'product' === get_post_type( (int) $post_id );
	}

	/**
	 * `wp_insert_post_data` 99.
	 *
	 * @param mixed $data    Post data to store.
	 * @param mixed $postarr Raw post array.
	 * @param mixed $raw     Unsanitised post array.
	 * @param mixed $update  Whether this is an update.
	 * @return mixed
	 */
	public function insert_post_data( $data, $postarr = null, $raw = null, $update = false ) {
		unset( $postarr, $raw );
		if ( ! is_array( $data ) || ! isset( $data['post_type'] ) ) {
			return $data;
		}
		$type = (string) $data['post_type'];
		if ( 'product' === $type ) {
			$data['ping_status'] = 'closed';
			if ( ! $update ) {
				$data['comment_status'] = 'open';
			}
			return $data;
		}
		if ( ! in_array( $type, self::SKIP_TYPES, true ) ) {
			$data['comment_status'] = 'closed';
			$data['ping_status']    = 'closed';
		}
		return $data;
	}

	/**
	 * Close comments and pings of every existing non-product post (idempotent, batched).
	 *
	 * @param bool $dry_run Count only.
	 * @return int Posts changed (or that would change).
	 */
	public static function close_legacy( bool $dry_run ): int {
		global $wpdb;
		$holders = implode( ', ', array_fill( 0, count( self::SKIP_TYPES ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $holders is a list of %s built from a constant array.
		$where = "post_type NOT IN ($holders) AND ( comment_status <> 'closed' OR ping_status <> 'closed' )";
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where", self::SKIP_TYPES ) );
		if ( $dry_run || 0 === $total ) {
			if ( ! $dry_run ) {
				update_option( '_vx_reviews_legacy_closed', Config::POLICY_VERSION, false );
			}
			return $total;
		}

		$done = 0;
		for ( $i = 0; $i < 200; $i++ ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE $where ORDER BY ID LIMIT 200", self::SKIP_TYPES ) );
			if ( ! is_array( $ids ) || array() === $ids ) {
				break;
			}
			$ids = array_map( 'intval', $ids );
			$in  = implode( ',', $ids ); // Integers only.
			$wpdb->query( "UPDATE {$wpdb->posts} SET comment_status = 'closed', ping_status = 'closed' WHERE ID IN ($in)" );
			foreach ( $ids as $id ) {
				clean_post_cache( $id );
			}
			$done += count( $ids );
		}
		// phpcs:enable
		update_option( '_vx_reviews_legacy_closed', Config::POLICY_VERSION, false );
		return $done;
	}

	/**
	 * `wp` priority 1: comment feeds do not exist.
	 */
	public function comment_feed_404(): void {
		if ( ! is_comment_feed() ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		// A 404 would otherwise be "fixed" into a guessed permalink (`<product>/feed/feed/`).
		remove_action( 'template_redirect', 'redirect_canonical' );
		remove_action( 'template_redirect', 'wp_old_slug_redirect' );
		Responder::log( '404', 'comment-feed', 'reviews' );
	}

	/**
	 * `template_redirect` priority 1 (before `redirect_canonical` and Rank Math): `?replytocom=` and `/comment-page-N/` -> 301.
	 */
	public function clean_redirect(): void {
		if ( ! in_array( Request::method(), [ 'GET', 'HEAD' ], true ) || ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$uri   = (string) wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed, rebuilt from its own parts, validated by wp_safe_redirect.
		$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );

		$new_path = (string) preg_replace( '#/comment-page-\d+/?$#i', '/', $path );
		$kept     = [];
		foreach ( '' === $query ? [] : explode( '&', $query ) as $pair ) {
			if ( 'replytocom' !== strtolower( rawurldecode( (string) strtok( $pair, '=' ) ) ) ) {
				$kept[] = $pair;
			}
		}
		$new_query = implode( '&', $kept );
		if ( $new_path === $path && $new_query === $query ) {
			return;
		}

		$home   = (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$port   = wp_parse_url( home_url( '/' ), PHP_URL_PORT );
		$target = $home . ( $port ? ':' . $port : '' ) . $new_path . ( '' !== $new_query ? '?' . $new_query : '' );
		Responder::log( '301', 'comment-url', 'reviews' );
		if ( wp_safe_redirect( $target, 301, 'Vextra' ) ) {
			exit;
		}
	}
}
