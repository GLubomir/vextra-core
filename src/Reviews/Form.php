<?php
/**
 * Review form presentation rules (hooks only, no template override, ADR-003).
 *
 * - Visitors and non-buyers: the Reviews tab and every approved review stay in the HTML (`comments_open` is true for products);
 *   WooCommerce's "only buyers" notice becomes a plain text for signed-in non-buyers and a crawlable My Account link for guests
 *   (no `wp-login.php?redirect_to=`, no query string). Implemented with the `gettext` + `esc_html` filters on that ONE
 *   WooCommerce string, registered only while a single product renders.
 * - A buyer who already reviewed the product gets a notice instead of the fields (`woocommerce_product_review_comment_form_args`).
 * - A buyer with a free slot gets the Turnstile widget (action `review`) in front of the submit button; api.js is enqueued by the
 *   adapter only then, so no other page requests it.
 * - No author URL / cookie-consent fields; core's `must_log_in` is the same plain My Account link.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Reviews;

use Vextra\Core\Integrations\Turnstile\Turnstile;
use Vextra\Core\Plugin;

final class Form {

	public const NONCE_FIELD  = 'vx_review_nonce';
	public const NONCE_ACTION = 'vx_review';

	private const WOO_NOTICE = 'Only logged in customers who have purchased this product may leave a review.';

	/**
	 * The form is rendered with fields for the current visitor.
	 *
	 * @var bool
	 */
	private bool $visible = false;

	/**
	 * Link text waiting for its `esc_html` pass.
	 *
	 * @var string
	 */
	private string $link_text = '';

	public function register(): void {
		add_action( 'woocommerce_before_single_product', [ $this, 'hook_notice' ] );
		add_filter( 'woocommerce_product_review_comment_form_args', [ $this, 'form_args' ], 99, 1 );
		add_filter( 'comment_form_defaults', [ $this, 'defaults' ], 99, 1 );
		add_filter( 'comment_form_default_fields', [ $this, 'fields' ], 99, 1 );
		add_filter( 'comment_form_submit_field', [ $this, 'submit_field' ], 20, 1 );
	}

	/**
	 * Start translating WooCommerce's notice for this product page.
	 */
	public function hook_notice(): void {
		add_filter( 'gettext', [ $this, 'notice_text' ], 10, 3 );
	}

	/**
	 * `gettext`: our text for WooCommerce's notice (link text for guests, plain text for signed-in non-buyers).
	 *
	 * @param mixed $translation Translated text.
	 * @param mixed $text        Source text.
	 * @param mixed $domain      Text domain.
	 * @return mixed
	 */
	public function notice_text( $translation, $text = '', $domain = '' ) {
		if ( 'woocommerce' !== $domain || self::WOO_NOTICE !== $text ) {
			return $translation;
		}
		remove_filter( 'gettext', [ $this, 'notice_text' ], 10 );
		if ( is_user_logged_in() ) {
			return __( 'Відгук можуть залишити лише покупці цього товару.', 'vextra-core' );
		}
		$this->link_text = __( 'Увійдіть, щоб залишити відгук', 'vextra-core' );
		add_filter( 'esc_html', [ $this, 'notice_link' ], 10, 2 );
		return $this->link_text;
	}

	/**
	 * `esc_html`: wrap the escaped guest notice into a link to My Account.
	 *
	 * @param mixed $safe Escaped text.
	 * @param mixed $text Source text passed to esc_html().
	 * @return mixed
	 */
	public function notice_link( $safe, $text = '' ) {
		if ( '' === $this->link_text || $text !== $this->link_text ) {
			return $safe;
		}
		remove_filter( 'esc_html', [ $this, 'notice_link' ], 10 );
		$this->link_text = '';
		$url             = self::account_url();
		return '' === $url ? $safe : '<a href="' . esc_url( $url ) . '">' . $safe . '</a>';
	}

	/**
	 * `woocommerce_product_review_comment_form_args`: hide the fields of a buyer who already reviewed the product.
	 *
	 * @param mixed $args Form arguments.
	 * @return mixed
	 */
	public function form_args( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$user_id = get_current_user_id();
		$post_id = (int) get_the_ID();
		if ( Policy::is_moderator( $user_id ) ) {
			$this->visible = false;
			return $args;
		}
		if ( $user_id > 0 && $post_id > 0 && Policy::has_review( $user_id, $post_id ) ) {
			$this->visible             = false;
			$args['title_reply']       = esc_html__( 'Ви вже залишили відгук на цей товар.', 'vextra-core' );
			$args['comment_field']     = '';
			$args['fields']            = [];
			$args['submit_field']      = '';
			$args['submit_button']     = '';
			$args['must_log_in']       = '';
			$args['cancel_reply_link'] = '';
			return $args;
		}
		if ( $user_id > 0 && $post_id > 0 && ! Policy::is_buyer( $user_id, $post_id ) ) {
			// Woo counts processing orders and billing e-mails as "bought"; our rule is stricter (SEC-057): no form, same text as for any non-buyer.
			$this->visible         = false;
			$args['title_reply']   = esc_html__( 'Відгук можуть залишити лише покупці цього товару.', 'vextra-core' );
			$args['comment_field'] = '';
			$args['fields']        = [];
			$args['submit_field']  = '';
			$args['submit_button'] = '';
			return $args;
		}
		$this->visible = $user_id > 0;
		return $args;
	}

	/**
	 * `comment_form_defaults`: guests get the same plain My Account link as in the notice (core would link `wp-login.php?redirect_to=`).
	 *
	 * @param mixed $defaults Defaults.
	 * @return mixed
	 */
	public function defaults( $defaults ) {
		if ( ! is_array( $defaults ) ) {
			return $defaults;
		}
		$url = self::account_url();
		if ( '' !== $url ) {
			$defaults['must_log_in'] = '<p class="must-log-in"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Увійдіть, щоб залишити відгук', 'vextra-core' ) . '</a></p>';
		}
		return $defaults;
	}

	/**
	 * `comment_form_default_fields`: no website field, no cookie-consent box.
	 *
	 * @param mixed $fields Fields.
	 * @return mixed
	 */
	public function fields( $fields ) {
		if ( is_array( $fields ) ) {
			unset( $fields['url'], $fields['cookies'] );
		}
		return $fields;
	}

	/**
	 * `comment_form_submit_field`: Turnstile widget before the submit button of a buyer's form.
	 *
	 * @param mixed $submit Submit markup.
	 * @return mixed
	 */
	public function submit_field( $submit ) {
		if ( ! $this->visible || ! is_string( $submit ) || '' === $submit ) {
			return $submit;
		}
		$nonce = wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false, false );
		$ts    = Plugin::instance()->integration( 'turnstile' );
		if ( ! $ts instanceof Turnstile || ! $ts->is_available() ) {
			return $nonce . $submit;
		}
		ob_start();
		$ts->render_widget( 'review' );
		return (string) ob_get_clean() . $nonce . $submit;
	}

	/**
	 * My Account URL (language-aware through Polylang), '' without WooCommerce.
	 */
	private static function account_url(): string {
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return '';
		}
		$url = wc_get_page_permalink( 'myaccount' );
		return is_string( $url ) ? $url : '';
	}
}
