<?php
/**
 * WooCommerce block-type skip (TASK-010 item 8, token `blocktypes`).
 *
 * Woo registers ~100 block type classes on `init` of EVERY request (139-146 ms measured). The documented filter
 * `woocommerce_get_block_types` lets us keep only what a front page can need:
 * - always: every type whose name contains Cart, Checkout, OrderConfirmation, Classic or PageContentWrapper (cart, checkout,
 *   mini-cart, order confirmation, classic shortcode / template wrappers) and `AllProducts` (its stylesheet is shared by the
 *   product grids, e.g. the "New in store" grid of the empty cart);
 * - plus every `woocommerce/*` block name found in stored content (posts of any type incl. templates / patterns / revisions,
 *   `widget_block`), kept in the autoloaded option `vx_perf_woo_blocks` (grows on `save_post`, full scan once when missing).
 * The filter runs on `init`, before the main query exists, so the decision cannot depend on the queried post.
 *
 * Applied ONLY to plain front HTML GET / HEAD requests. Never on admin, AJAX (`admin-ajax`, `wc-ajax`), cron, WP-CLI, REST
 * (incl. the Store API: `/wp-json/`, `?rest_route=`), JSON requests, feeds, XML-RPC, previews / Customizer, or non-GET methods.
 * Fail safe: when a found block name cannot be mapped to a type class, the full list is kept.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Performance;

use Vextra\Core\Plugin;

final class BlockTypes {

	public const OPTION = 'vx_perf_woo_blocks';

	/**
	 * Name fragments of the families that always stay registered.
	 */
	private const KEEP = '/(Cart|Checkout|OrderConfirmation|Classic|PageContentWrapper|^AllProducts$)/';

	public function register(): void {
		$woo = Plugin::instance()->integration( 'woocommerce' );
		if ( null === $woo || ! $woo->is_available() || ! method_exists( $woo, 'on_block_types' ) ) {
			return;
		}

		$woo->on_block_types( [ $this, 'filter' ] );
		add_action( 'save_post', [ $this, 'on_save' ], 10, 2 );
		add_action( 'update_option_widget_block', [ $this, 'on_widget_update' ], 10, 2 );
	}

	/**
	 * `woocommerce_get_block_types`.
	 *
	 * @param mixed $types Block type class names.
	 * @return mixed
	 */
	public function filter( $types ) {
		if ( ! is_array( $types ) || Switches::off( 'blocktypes' ) || ! self::plain_front_request() ) {
			return $types;
		}

		$found = $this->found_names();
		$keep  = [];
		$used  = [];
		foreach ( $types as $type ) {
			if ( ! is_string( $type ) ) {
				return $types;
			}
			if ( 1 === preg_match( self::KEEP, $type ) ) {
				$keep[] = $type;
				continue;
			}
			foreach ( self::kebab_candidates( $type ) as $candidate ) {
				if ( in_array( $candidate, $found, true ) ) {
					$keep[] = $type;
					$used[] = $candidate;
					break;
				}
			}
		}

		// A stored block name that matches no type class: keep everything (never break content because of a naming surprise).
		foreach ( $found as $name ) {
			if ( ! in_array( $name, $used, true ) && ! $this->name_in_kept_family( $name ) ) {
				return $types;
			}
		}

		return $keep;
	}

	/**
	 * Request classifier: a normal visitor page view (HTML, GET / HEAD).
	 */
	public static function plain_front_request(): bool {
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_ajax() || wp_doing_cron() || defined( 'XMLRPC_REQUEST' ) || defined( 'REST_REQUEST' ) ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
			return false;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( false !== strpos( $path, '/' . rest_get_url_prefix() . '/' ) || false !== strpos( $path, '/feed' ) || false !== stripos( $path, 'wp-login.php' ) || false !== stripos( $path, 'xmlrpc.php' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request classification, nothing is processed.
		foreach ( [ 'rest_route', 'wc-ajax', 'preview', 'customize_changeset_uuid', 'feed', 'elementor-preview', 'wc-api' ] as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return false;
			}
		}

		return ! wp_is_json_request();
	}

	/**
	 * Names (`product-collection`) of the Woo blocks present in stored content.
	 *
	 * @return string[]
	 */
	private function found_names(): array {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			$stored = $this->scan();
			update_option( self::OPTION, $stored, true );
		}
		return array_values( array_filter( $stored, 'is_string' ) );
	}

	/**
	 * Full scan of stored content (once, when the option is missing).
	 *
	 * @return string[]
	 */
	private function scan(): array {
		global $wpdb;

		$names = [];
		$like  = '%' . $wpdb->esc_like( 'wp:woocommerce/' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time scan, result stored in an option.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE post_content LIKE %s", $like ) );
		foreach ( (array) $rows as $content ) {
			$names = array_merge( $names, self::names_in( (string) $content ) );
		}
		$names = array_merge( $names, self::names_in( (string) maybe_serialize( get_option( 'widget_block', [] ) ) ) );

		return array_values( array_unique( $names ) );
	}

	/**
	 * `save_post`: add names of the saved content (incl. autosaves / revisions, so previews work). The list only grows.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save( $post_id, $post ): void {
		unset( $post_id );
		if ( ! $post instanceof \WP_Post || false === strpos( (string) $post->post_content, 'wp:woocommerce/' ) ) {
			return;
		}
		$this->add( self::names_in( (string) $post->post_content ) );
	}

	/**
	 * `update_option_widget_block`.
	 *
	 * @param mixed $old   Old value.
	 * @param mixed $value New value.
	 */
	public function on_widget_update( $old, $value ): void {
		unset( $old );
		$this->add( self::names_in( (string) maybe_serialize( $value ) ) );
	}

	/**
	 * Merge names into the stored list.
	 *
	 * @param string[] $names New names.
	 */
	private function add( array $names ): void {
		if ( array() === $names ) {
			return;
		}
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			return; // Not scanned yet: the first front request scans everything.
		}
		$merged = array_values( array_unique( array_merge( $stored, $names ) ) );
		if ( count( $merged ) !== count( $stored ) ) {
			update_option( self::OPTION, $merged, true );
		}
	}

	/**
	 * Woo block names (`product-collection`) found in raw content.
	 *
	 * @param string $content Raw content.
	 * @return string[]
	 */
	private static function names_in( string $content ): array {
		if ( 1 > preg_match_all( '/wp:woocommerce\/([a-z0-9-]+)/', $content, $m ) ) {
			return [];
		}
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * Could a block name belong to an always-kept family (cart / checkout / order confirmation / classic).
	 *
	 * @param string $name Block name without namespace.
	 */
	private function name_in_kept_family( string $name ): bool {
		return 1 === preg_match( '/(cart|checkout|order-confirmation|classic|page-content-wrapper)/', $name );
	}

	/**
	 * `ProductCollection` => product-collection; `OrderConfirmation\Status` => order-confirmation-status, status and order-confirmation; `ProductCollection\Controller` => product-collection (first segment).
	 *
	 * @param string $type Block type class name.
	 * @return string[]
	 */
	private static function kebab_candidates( string $type ): array {
		$kebab = static fn( string $s ): string => strtolower( (string) preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '-', $s ) );
		$parts = explode( chr( 92 ), $type );
		return array_values( array_unique( [ $kebab( implode( '', $parts ) ), $kebab( implode( '-', $parts ) ), $kebab( (string) end( $parts ) ), $kebab( (string) reset( $parts ) ) ] ) );
	}
}
