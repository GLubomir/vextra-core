<?php
/**
 * Entry guards of the review pipeline (SEC-012, decision D6).
 *
 * - `wp-comments-post.php` is the ONLY write path for buyers: `pre_comment_on_post` (Turnstile first, then flood bucket, login,
 *   verified buyer, one review per product, quotas) and `preprocess_comment` priority -10 (rating, length, links, author name; runs
 *   before WooCommerce's own rating check so the messages are ours). `pre_comment_approved`: everything is held for moderation.
 * - REST (`rest_pre_dispatch` 4; sub-requests of `/batch/v1` pass here too): every comment / review WRITE is closed for users
 *   without `moderate_comments` (401 anonymous, 403 others; attempts are counted in a REST bucket, 429 when flooded); anonymous
 *   `GET /wp/v2/comments*` = 401; private fields are dropped from responses of non-moderators.
 * Errors on the form path: `wp_die` with the status, no-cache, `X-Robots-Tag: noindex`. Never runs under WP-CLI.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Reviews;

use Vextra\Core\Guard\ClientIp;
use Vextra\Core\Guard\Exempt;
use Vextra\Core\Guard\Failsafe;
use Vextra\Core\Guard\RateLimiter;
use Vextra\Core\Guard\Responder;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\Turnstile\Turnstile;
use Vextra\Core\Plugin;
use Vextra\Core\Security\UserEnumeration;

final class Gate {

	private const COMMENTS_ROUTE = '#^/wp/v2/comments(?:/|$)#';
	private const REVIEWS_ROUTE  = '#^/wc/(?:v\d+|store(?:/v\d+)?)/products/(?:\d+/)?reviews(?:/|$)#';

	public function register(): void {
		add_action( 'pre_comment_on_post', [ $this, 'on_submit' ], 1, 1 );
		add_filter( 'preprocess_comment', [ $this, 'preprocess' ], -10, 1 );
		add_filter( 'pre_comment_approved', [ $this, 'hold' ], 99, 2 );
		add_filter( 'rest_pre_dispatch', [ $this, 'rest_guard' ], 4, 3 );
		add_filter( 'rest_prepare_comment', [ $this, 'rest_strip' ], 99, 1 );
		add_filter( 'woocommerce_rest_prepare_product_review', [ $this, 'rest_strip' ], 99, 1 );
	}

	/**
	 * `pre_comment_on_post`: gate of the form submit, before core looks at the login state.
	 *
	 * @param mixed $post_id Product ID (comment_post_ID).
	 */
	public function on_submit( $post_id = 0 ): void {
		$post_id = (int) $post_id;
		$user_id = get_current_user_id();
		if ( Policy::is_moderator( $user_id ) ) {
			return;
		}
		$this->speak( $post_id );

		$error = Failsafe::run( 'reviews-captcha', fn() => $this->captcha(), null );
		if ( $error instanceof \WP_Error ) {
			$this->refuse( $error );
		}

		$exempt = Failsafe::run( 'reviews-exempt', static fn(): bool => Exempt::is_exempt(), false );
		$ip     = ClientIp::get();
		if ( ! $exempt ) {
			$hit = RateLimiter::hit( 'review_try', $ip, Config::get( 'attempt_limit' ), Config::get( 'attempt_window' ) );
			if ( ! $hit['allowed'] ) {
				Responder::rate_limited( $hit['retry_after'], 'html', 'review', 'reviews' );
			}
		}

		$access = Policy::validate_access( $post_id, $user_id );
		if ( is_wp_error( $access ) ) {
			$this->refuse( $access );
		}

		if ( ! $exempt ) {
			$this->check_quota( $ip, $user_id, false );
		}
	}

	/**
	 * `wp-comments-post.php` has no language prefix: answer in the language of the product (Polylang), not the default one.
	 *
	 * @param int $post_id Product ID.
	 */
	private function speak( int $post_id ): void {
		$pll = Plugin::instance()->integration( 'polylang' );
		if ( ! $pll instanceof Polylang || ! $pll->is_ready() || $post_id <= 0 ) {
			return;
		}
		$lang = $pll->post_language( $post_id );
		if ( '' === $lang || $lang === $pll->current_language() ) {
			return;
		}
		$locale = $pll->locale_of( $lang );
		$file   = dirname( __DIR__, 2 ) . '/languages/vextra-core-' . $locale . '.mo';
		if ( 1 === preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale ) && is_readable( $file ) ) {
			unload_textdomain( 'vextra-core' );
			load_textdomain( 'vextra-core', $file, $locale );
		}
	}

	/**
	 * `preprocess_comment` priority -10: content rules, quota, public author name.
	 *
	 * @param mixed $data Comment data.
	 * @return mixed
	 */
	public function preprocess( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$user_id = (int) ( $data['user_id'] ?? 0 );
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( Policy::is_moderator( $user_id ) ) {
			return $data;
		}

		$type = isset( $data['comment_type'] ) && is_string( $data['comment_type'] ) ? $data['comment_type'] : '';
		if ( in_array( $type, [ 'pingback', 'trackback' ], true ) ) {
			$this->refuse( Policy::error( 'vx_review_closed', __( 'Відгуки до цього матеріалу закриті.', 'vextra-core' ), 403 ) );
		}

		$nonce = $this->posted_nonce();
		if ( ! in_array( wp_verify_nonce( $nonce, Form::NONCE_ACTION ), [ 1, 2 ], true ) ) {
			$this->refuse( Policy::error( 'vx_review_nonce', __( 'Форма застаріла. Оновіть сторінку й спробуйте ще раз.', 'vextra-core' ), 403 ) );
		}
		$data['comment_parent'] = 0;

		$check = $data;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the rating is read to be validated, never stored from here; core has no nonce on this form (CSRF: signed-in only + SameSite cookies + Turnstile).
		$check['rating'] = isset( $_POST['rating'] ) ? wp_unslash( $_POST['rating'] ) : ( $data['comment_meta']['rating'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated strictly by Policy (^[1-5]$, non-strings refused).
		$result          = Policy::validate( $check, (int) ( $data['comment_post_ID'] ?? 0 ), $user_id );
		if ( is_wp_error( $result ) ) {
			$this->refuse( $result );
		}

		if ( ! Exempt::is_exempt() ) {
			$this->check_quota( ClientIp::get(), $user_id, true );
		}

		$data['comment_author_url'] = '';
		$name                       = Policy::author_name( $user_id );
		if ( '' !== $name ) {
			$data['comment_author'] = $name;
		}
		return $data;
	}

	/**
	 * `pre_comment_approved` priority 99: nothing but spam is ever approved automatically.
	 *
	 * @param mixed $approved Verdict (1, 0, `spam`, `trash`).
	 * @param mixed $data     Comment data.
	 * @return mixed
	 */
	public function hold( $approved, $data = null ) {
		if ( 1 !== $approved && '1' !== $approved ) {
			return $approved;
		}
		$user_id = is_array( $data ) ? (int) ( $data['user_id'] ?? 0 ) : 0;
		return Policy::is_moderator( $user_id ) ? $approved : 0;
	}

	/**
	 * Posted review-form nonce (string only).
	 */
	private function posted_nonce(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- this IS the nonce, verified right after; only a string is accepted.
		return isset( $_POST[ Form::NONCE_FIELD ] ) && is_string( $_POST[ Form::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ Form::NONCE_FIELD ] ) ) : '';
	}

	/**
	 * Turnstile on the review form (skipped when no keys are set: degraded mode, the other rules still apply).
	 */
	private function captcha(): ?\WP_Error {
		$ts = Plugin::instance()->integration( 'turnstile' );
		if ( ! $ts instanceof Turnstile || ! $ts->is_available() ) {
			return null;
		}
		$token = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- the token is the proof, validated by Cloudflare; only a string is accepted, then unslashed and sanitised below.
		$raw = isset( $_POST[ Turnstile::FIELD ] ) && is_string( $_POST[ Turnstile::FIELD ] ) ? wp_unslash( $_POST[ Turnstile::FIELD ] ) : '';
		if ( '' !== $raw ) {
			$token = substr( sanitize_text_field( $raw ), 0, 2100 );
		}
		$verdict = $ts->verify( $token, ClientIp::get( false ), 'review' );
		if ( ! empty( $verdict['ok'] ) ) {
			return null;
		}
		Responder::log( 'turnstile-' . (string) ( $verdict['reason'] ?? 'failed' ), 'review', 'reviews' );
		return Policy::error( 'vx_turnstile_failed', __( 'Підтвердіть, що ви не робот і спробуйте ще раз.', 'vextra-core' ), 403 );
	}

	/**
	 * Quota of accepted reviews per IP and per user. `$count` false = only read, true = register this one.
	 *
	 * @param string $ip      Client IP.
	 * @param int    $user_id User ID.
	 * @param bool   $count   Register a hit.
	 */
	private function check_quota( string $ip, int $user_id, bool $count ): void {
		$buckets = [
			[ 'review_ip', $ip, Config::get( 'ip_limit' ), Config::get( 'ip_window' ) ],
			[ 'review_user', 'u' . $user_id, Config::get( 'user_limit' ), Config::get( 'user_window' ) ],
		];
		foreach ( $buckets as [ $bucket, $subject, $limit, $window ] ) {
			if ( $count ) {
				$hit = RateLimiter::hit( $bucket, $subject, $limit, $window );
				if ( ! $hit['allowed'] ) {
					Responder::rate_limited( $hit['retry_after'], 'html', 'review', 'reviews' );
				}
				continue;
			}
			$peek = RateLimiter::peek( $bucket, $subject, $limit );
			if ( $limit > 0 && $peek['remaining'] <= 0 ) {
				Responder::rate_limited( max( 1, $peek['retry_after'] ), 'html', 'review', 'reviews' );
			}
		}
	}

	/**
	 * Refuse a form submit: status, no cache, no index, message in the page language.
	 *
	 * @param \WP_Error $error Error with `status` data.
	 */
	private function refuse( \WP_Error $error ): never {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 403;
		Responder::log( (string) $status, 'review-' . $error->get_error_code(), 'reviews' );
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex' );
		}
		wp_die(
			esc_html( $error->get_error_message() ),
			esc_html__( 'Відгук не прийнято', 'vextra-core' ),
			[
				'response'  => $status, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer.
				'back_link' => true,
			]
		);
	}

	/**
	 * `rest_pre_dispatch` 4: comment / review writes closed for non-moderators.
	 *
	 * @param mixed $result  Short-circuit value (null = continue).
	 * @param mixed $server  REST server.
	 * @param mixed $request Request.
	 * @return mixed
	 */
	public function rest_guard( $result, $server = null, $request = null ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		return Failsafe::run(
			'reviews-rest',
			function () use ( $request, $result ) {
				$route    = UserEnumeration::normalised_route( $request );
				$comments = 1 === preg_match( self::COMMENTS_ROUTE, $route );
				if ( ! $comments && 1 !== preg_match( self::REVIEWS_ROUTE, $route ) ) {
					return $result;
				}
				if ( current_user_can( 'moderate_comments' ) ) {
					return $result;
				}
				$logged_in = is_user_logged_in();
				if ( ! in_array( strtoupper( $request->get_method() ), [ 'GET', 'HEAD', 'OPTIONS' ], true ) ) {
					if ( ! Exempt::is_exempt() ) {
						$hit = RateLimiter::hit( 'review_rest', ClientIp::get(), Config::get( 'rest_limit' ), Config::get( 'rest_window' ) );
						if ( ! $hit['allowed'] ) {
							Responder::log( '429', 'review-rest', 'reviews' );
							$response = UserEnumeration::error( 'vx_rate_limited', 'Too many requests. Please wait before trying again.', 429 );
							$response->header( 'Retry-After', (string) max( 1, $hit['retry_after'] ) );
							return $response;
						}
					}
					Responder::log( $logged_in ? '403' : '401', 'review-rest-write', 'reviews' );
					return UserEnumeration::error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', $logged_in ? 403 : 401 );
				}
				if ( $comments && ! $logged_in ) {
					Responder::log( '401', 'review-rest-read', 'reviews' );
					return UserEnumeration::error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', 401 );
				}
				return $result;
			},
			$result
		);
	}

	/**
	 * `rest_prepare_comment` / `woocommerce_rest_prepare_product_review`: no private author data for non-moderators.
	 *
	 * @param mixed $response REST response.
	 * @return mixed
	 */
	public function rest_strip( $response ) {
		if ( ! $response instanceof \WP_REST_Response || current_user_can( 'moderate_comments' ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) ) {
			unset( $data['author_email'], $data['author_ip'], $data['author_user_agent'], $data['author_avatar_urls'], $data['reviewer_email'], $data['reviewer_avatar_urls'] );
			$response->set_data( $data );
		}
		return $response;
	}
}
