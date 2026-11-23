<?php
/**
 * The review rules, one place for every entry path (`wp-comments-post.php`, REST, programmatic `wp_new_comment`).
 *
 * `validate()` returns true or a WP_Error whose `status` data is the HTTP code. Users with `moderate_comments` (admin, shop
 * manager answering from wp-admin) are not subject to the rules. Messages: UA source, RU in the `.po`.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Reviews;

use Vextra\Core\Plugin;

final class Policy {

	/**
	 * Memo of the buyer check per user and product (one DB query per request at most).
	 *
	 * @var array<string,bool>
	 */
	private static array $bought = [];

	/**
	 * Full validation of a submitted review.
	 *
	 * @param array<string,mixed> $data    Comment data (`comment_content`, `comment_author_url`, `rating`, ...).
	 * @param int                 $post_id Product ID.
	 * @param int                 $user_id Author user ID (0 = visitor).
	 * @param string              $via     `form` (wp-comments-post.php) or `api`.
	 * @return true|\WP_Error
	 */
	public static function validate( array $data, int $post_id, int $user_id, string $via = 'form' ) {
		if ( self::is_moderator( $user_id ) ) {
			return true;
		}
		$access = self::validate_access( $post_id, $user_id );
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		$rating = $data['rating'] ?? null;
		if ( ! is_string( $rating ) && ! is_int( $rating ) ) {
			$rating = null;
		}
		if ( null === $rating || 1 !== preg_match( '/^[1-5]$/', (string) $rating ) ) {
			return self::error( 'vx_review_rating', __( 'Оберіть оцінку від 1 до 5.', 'vextra-core' ), 422 );
		}

		$content = $data['comment_content'] ?? '';
		if ( ! is_string( $content ) || ! mb_check_encoding( $content, 'UTF-8' ) ) {
			return self::error( 'vx_review_length', self::length_message(), 422 );
		}
		$length = mb_strlen( trim( wp_strip_all_tags( $content ) ) );
		if ( $length < Config::get( 'min_length' ) || $length > Config::get( 'max_length' ) ) {
			return self::error( 'vx_review_length', self::length_message(), 422 );
		}
		if ( 1 === preg_match( '#(?:https?:|ftp:)?//|www\.|<\s*a\b|\[url|mailto:#i', $content ) ) {
			return self::error( 'vx_review_links', __( 'Посилання у відгуках заборонені.', 'vextra-core' ), 422 );
		}
		unset( $via );
		return true;
	}

	/**
	 * Who may review which product: published product, signed-in verified buyer, one review per product.
	 *
	 * @param int $post_id Product ID.
	 * @param int $user_id User ID.
	 * @return true|\WP_Error
	 */
	public static function validate_access( int $post_id, int $user_id ) {
		if ( self::is_moderator( $user_id ) ) {
			return true;
		}
		if ( $user_id <= 0 ) {
			return self::error( 'vx_review_login', __( 'Щоб залишити відгук, увійдіть в особистий кабінет.', 'vextra-core' ), 403 );
		}
		if ( 'product' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return self::error( 'vx_review_closed', __( 'Відгуки до цього матеріалу закриті.', 'vextra-core' ), 403 );
		}
		if ( ! self::is_buyer( $user_id, $post_id ) ) {
			return self::error( 'vx_review_buyer', __( 'Відгук можуть залишити лише покупці цього товару.', 'vextra-core' ), 403 );
		}
		if ( self::has_review( $user_id, $post_id ) ) {
			return self::error( 'vx_review_duplicate', __( 'Ви вже залишили відгук на цей товар.', 'vextra-core' ), 409 );
		}
		return true;
	}

	/**
	 * May the user moderate comments (no review rules, no quota).
	 *
	 * @param int $user_id User ID.
	 */
	public static function is_moderator( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'moderate_comments' );
	}

	/**
	 * Did this account buy the product (Woo: paid order, `wc_customer_bought_product`, own account only).
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Product ID.
	 */
	public static function is_buyer( int $user_id, int $post_id ): bool {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( null === $woo || ! $woo->is_available() || ! function_exists( 'wc_customer_bought_product' ) ) {
			return false;
		}
		$key = $user_id . ':' . $post_id;
		if ( ! isset( self::$bought[ $key ] ) ) {
			$user                 = get_userdata( $user_id );
			self::$bought[ $key ] = $user instanceof \WP_User && wc_customer_bought_product( (string) $user->user_email, $user_id, $post_id );
		}
		return self::$bought[ $key ];
	}

	/**
	 * Does the user already have a live (approved or held) comment on the product.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Product ID.
	 */
	public static function has_review( int $user_id, int $post_id ): bool {
		$count = get_comments(
			[
				'post_id'  => $post_id,
				'user_id'  => $user_id,
				'status'   => 'all',
				'count'    => true,
				'type__in' => [ 'review', 'comment' ], // WooCommerce hides type `review` from queries without an explicit type.
			]
		);
		return (int) $count > 0;
	}

	/**
	 * Public author name: display name, else first name, never the login or an e-mail part (schema author, privacy).
	 *
	 * @param int $user_id User ID.
	 */
	public static function author_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return '';
		}
		$local = strtolower( (string) strtok( (string) $user->user_email, '@' ) );
		$name  = trim( (string) $user->display_name );
		if ( '' === $name || strtolower( $name ) === strtolower( (string) $user->user_login ) || strtolower( $name ) === $local || str_contains( $name, '@' ) ) {
			$name = trim( (string) $user->first_name );
		}
		if ( '' === $name || strtolower( $name ) === strtolower( (string) $user->user_login ) || strtolower( $name ) === $local ) {
			$name = __( 'Покупець', 'vextra-core' );
		}
		return $name;
	}

	/**
	 * Error with the HTTP status in the data.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	public static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}

	private static function length_message(): string {
		/* translators: 1: minimum, 2: maximum number of characters. */
		return sprintf( __( 'Відгук має містити від %1$d до %2$d символів.', 'vextra-core' ), Config::get( 'min_length' ), Config::get( 'max_length' ) );
	}
}
