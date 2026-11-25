<?php
/**
 * Smoke "state" checks: the site is not missing its home / admin / system pages, carries no test leftovers, serves WebP sub-sizes
 * and does not set cart cookies for anonymous visitors. Strictly read-only (never deletes or repairs anything).
 *
 * Rows use the smoke row shape; `page` = `state`, the check name is in the `status` column.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Compat;

use Vextra\Core\Cart\CountCookie;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Media\Formats;

final class StateChecks {

	/**
	 * Prefixes of throw-away test data (lower case).
	 */
	public const TEST_PREFIXES = [ 't0', 'qa-temp-', 'vxtmp', 'zz-', 't046-' ];

	/**
	 * Woo pages that must exist: key => required.
	 */
	private const WOO_PAGES = [
		'shop'      => true,
		'cart'      => true,
		'checkout'  => true,
		'myaccount' => true,
		'terms'     => false,
		'privacy'   => false,
	];

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	public function __construct( Polylang $polylang ) {
		$this->polylang = $polylang;
	}

	/**
	 * Run every check.
	 *
	 * @param bool     $preview Preview header allowed (informational, the fetcher already applies it).
	 * @param string[] $langs   Language slugs ('' / empty = single language).
	 * @param callable $fetch   Anonymous GET: returns the Smoke::request() shape.
	 * @param callable $allowed Whether the URL host is the site itself.
	 * @return array<int,array<string,mixed>>
	 */
	public function run( bool $preview, array $langs, callable $fetch, callable $allowed ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$langs = array() === $langs ? [ '' ] : array_values( $langs );
		$memo  = [];
		$raw   = $fetch;
		// One GET per URL: home-render and cart-cookie share the home.
		$fetch = static function ( string $url ) use ( &$memo, $raw ): array {
			if ( ! isset( $memo[ $url ] ) ) {
				$memo[ $url ] = $raw( $url );
			}
			return $memo[ $url ];
		};
		$rows  = [];
		foreach ( [ $this->front_page( $langs ), $this->home_render( $langs, $fetch, $allowed ) ] as $group ) {
			$rows = array_merge( $rows, $group );
		}
		$rows[] = $this->admin();
		$rows   = array_merge( $rows, $this->woo_pages( $langs ) );
		$rows[] = $this->leftovers();
		$rows[] = $this->webp();
		$rows[] = $this->cart_cookie( $langs[0], $fetch, $allowed );
		return $rows;
	}

	/**
	 * `show_on_front=page`, `page_on_front` published, its translations published.
	 *
	 * @param string[] $langs Languages.
	 * @return array<int,array<string,mixed>>
	 */
	private function front_page( array $langs ): array {
		$failed = [];
		$id     = (int) get_option( 'page_on_front' );
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			$failed[] = 'show_on_front!=page';
		}
		if ( $id <= 0 || 'page' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			$failed[] = 'page_on_front ' . $id . ' not a published page';
		} else {
			foreach ( $langs as $lang ) {
				if ( '' === $lang ) {
					continue;
				}
				$twin = $this->polylang->translate_post( $id, $lang );
				if ( $twin <= 0 || 'publish' !== get_post_status( $twin ) ) {
					$failed[] = 'front page missing/unpublished in ' . $lang;
				}
			}
		}
		return [ $this->result( '*', 'front-page', '', 'FAIL', $failed ) ];
	}

	/**
	 * Home HTML per language: hero block + exactly one H1.
	 *
	 * @param string[] $langs   Languages.
	 * @param callable $fetch   Fetcher.
	 * @param callable $allowed Host guard.
	 * @return array<int,array<string,mixed>>
	 */
	private function home_render( array $langs, callable $fetch, callable $allowed ): array {
		$rows = [];
		foreach ( $langs as $lang ) {
			$url    = $this->home_of( $lang );
			$failed = [];
			if ( ! $allowed( $url ) ) {
				$failed[] = 'foreign-host';
			} else {
				$res = $fetch( $url );
				if ( null !== $res['error'] ) {
					$failed[] = 'http-error: ' . $res['error'];
				} elseif ( 200 !== $res['code'] ) {
					$failed[] = 'status-' . $res['code'];
				} else {
					$failed = Markers::check(
						$res['body'],
						[
							'h1'   => 1,
							'hero' => true,
						]
					);
				}
			}
			$rows[] = $this->result( $lang, 'home-render', $url, 'FAIL', $failed );
		}
		return $rows;
	}

	/**
	 * Local: user 1 exists and is an administrator. Elsewhere: at least one administrator.
	 *
	 * @return array<string,mixed>
	 */
	private function admin(): array {
		$local = 'local' === wp_get_environment_type();
		$args  = [
			'role'   => 'administrator',
			'fields' => 'ID',
			'number' => 1,
		];
		if ( $local ) {
			$args['include'] = [ 1 ];
		}
		$found  = get_users( $args );
		$failed = [];
		if ( array() === $found ) {
			$failed[] = $local ? 'user 1 missing or not administrator' : 'no administrator';
		}
		return $this->result( '*', 'admin', '', 'FAIL', $failed );
	}

	/**
	 * Woo system pages and their translations.
	 *
	 * @param string[] $langs Languages.
	 * @return array<int,array<string,mixed>>
	 */
	private function woo_pages( array $langs ): array {
		$failed = [];
		$warn   = [];
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return [ $this->result( '*', 'woo-pages', '', 'FAIL', [ 'WooCommerce inactive' ] ) ];
		}
		foreach ( self::WOO_PAGES as $key => $required ) {
			$id = 'privacy' === $key ? (int) get_option( 'wp_page_for_privacy_policy' ) : (int) wc_get_page_id( $key ); // Woo has no privacy page id: the core privacy page is used.
			if ( $id <= 0 ) {
				if ( $required ) {
					$failed[] = $key . ' not configured';
				} else {
					$warn[] = $key . ' not configured';
				}
				continue;
			}
			if ( 'publish' !== get_post_status( $id ) ) {
				$failed[] = $key . ' #' . $id . ' not published';
				continue;
			}
			foreach ( $langs as $lang ) {
				if ( '' === $lang ) {
					continue;
				}
				$twin = $this->polylang->translate_post( $id, $lang );
				if ( $twin <= 0 || 'publish' !== get_post_status( $twin ) ) {
					$failed[] = $key . ' missing/unpublished in ' . $lang;
				}
			}
		}
		$row = $this->result( '*', 'woo-pages', '', 'FAIL', $failed );
		if ( 'PASS' === $row['result'] && array() !== $warn ) {
			$row['result'] = 'WARN';
			$row['failed'] = implode( ', ', $warn );
		}
		return [ $row ];
	}

	/**
	 * Orders / users / posts with a test prefix: WARN only.
	 *
	 * @return array<string,mixed>
	 */
	private function leftovers(): array {
		global $wpdb;
		$likes = [];
		foreach ( self::TEST_PREFIXES as $prefix ) {
			$likes[] = $wpdb->esc_like( $prefix ) . '%';
		}
		$found = [];

		$post_c = [];
		foreach ( $likes as $like ) {
			$post_c[] = $wpdb->prepare( 'post_title LIKE %s OR post_name LIKE %s', $like, $like ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','nav_menu_item','shop_order','shop_order_refund','customize_changeset') AND post_status <> 'auto-draft' AND (" . implode( ' OR ', $post_c ) . ') LIMIT 20' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- conditions are prepared above.
		if ( array() !== $ids ) {
			$found['posts'] = array_map( 'intval', $ids );
		}

		$user_c = [];
		foreach ( $likes as $like ) {
			$user_c[] = $wpdb->prepare( 'user_login LIKE %s', $like ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE ID > 1 AND (" . implode( ' OR ', $user_c ) . ') LIMIT 20' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		if ( array() !== $ids ) {
			$found['users'] = array_map( 'intval', $ids );
		}

		$ids = $this->leftover_orders( $likes );
		if ( array() !== $ids ) {
			$found['orders'] = $ids;
		}

		$msg = [];
		foreach ( $found as $kind => $list ) {
			$msg[] = sprintf( '%d %s (ids %s%s)', count( $list ), $kind, implode( ',', array_slice( $list, 0, 5 ) ), count( $list ) > 5 ? ',...' : '' );
		}
		return $this->result( '*', 'test-leftovers', '', 'WARN', $msg );
	}

	/**
	 * Order ids whose billing first name / e-mail starts with a test prefix (bounded, HPOS or legacy storage).
	 *
	 * @param string[] $likes Escaped LIKE patterns.
	 * @return int[]
	 */
	private function leftover_orders( array $likes ): array {
		global $wpdb;
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return [];
		}
		$conds = [];
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$table = $wpdb->prefix . 'wc_order_addresses';
			foreach ( $likes as $like ) {
				$conds[] = $wpdb->prepare( 'first_name LIKE %s OR email LIKE %s', $like, $like ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			$sql = "SELECT DISTINCT order_id FROM {$table} WHERE address_type = 'billing' AND (" . implode( ' OR ', $conds ) . ') LIMIT 20';
		} else {
			foreach ( $likes as $like ) {
				$conds[] = $wpdb->prepare( 'meta_value LIKE %s', $like ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			$sql = "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_billing_first_name','_billing_email') AND (" . implode( ' OR ', $conds ) . ') LIMIT 20';
		}
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- prepared above, bounded.
	}

	/**
	 * Newest image attachments carry sub-sizes in the target format on disk.
	 *
	 * @return array<string,mixed>
	 */
	private function webp(): array {
		if ( ! Formats::available() ) {
			return $this->result( '*', 'webp', '', 'FAIL', [ 'runtime cannot encode WebP/AVIF' ], 'SKIP' );
		}
		$ids = get_posts(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'image',
				'posts_per_page'         => 3,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery -- brand attachments (TASK-049a) have no sub-sizes by design.
					[
						'key'     => '_vx_brand_key',
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);
		if ( array() === $ids ) {
			return $this->result( '*', 'webp', '', 'FAIL', [ 'no image attachments' ], 'SKIP' );
		}
		$ext    = Formats::AVIF === Formats::target() ? 'avif' : 'webp';
		$failed = [];
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$path = (string) get_attached_file( $id );
			$meta = wp_get_attachment_metadata( $id );
			$size = is_array( $meta ) && isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : [];
			if ( '' === $path || array() === $size ) {
				$failed[] = '#' . $id . ' no sub-sizes';
				continue;
			}
			foreach ( $size as $item ) {
				$file = is_array( $item ) ? (string) ( $item['file'] ?? '' ) : '';
				if ( '' === $file || strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) !== $ext || ! is_file( dirname( $path ) . '/' . wp_basename( $file ) ) ) {
					$failed[] = '#' . $id . ' sub-size not ' . $ext . ' on disk';
					break;
				}
			}
		}
		return $this->result( '*', 'webp', '', 'WARN', $failed );
	}

	/**
	 * Anonymous GET of the first language home must not set cart / session cookies.
	 *
	 * @param string   $lang    Language slug.
	 * @param callable $fetch   Fetcher.
	 * @param callable $allowed Host guard.
	 * @return array<string,mixed>
	 */
	private function cart_cookie( string $lang, callable $fetch, callable $allowed ): array {
		$url = $this->home_of( $lang );
		if ( ! $allowed( $url ) ) {
			return $this->result( $lang, 'cart-cookie', $url, 'FAIL', [ 'foreign-host' ] );
		}
		$res = $fetch( $url );
		if ( null !== $res['error'] ) {
			return $this->result( $lang, 'cart-cookie', $url, 'FAIL', [ 'http-error: ' . $res['error'] ] );
		}
		$failed = [];
		foreach ( (array) ( $res['cookies'] ?? [] ) as $name ) {
			$name = (string) $name;
			if ( CountCookie::NAME === $name || 0 === strpos( $name, 'wp_woocommerce_session_' ) || 'woocommerce_items_in_cart' === $name ) {
				$failed[] = 'Set-Cookie ' . $name;
			}
		}
		return $this->result( $lang, 'cart-cookie', $url, 'FAIL', $failed );
	}

	/**
	 * Home URL of a language ('' = single language).
	 *
	 * @param string $lang Language slug.
	 */
	private function home_of( string $lang ): string {
		$home = '' !== $lang ? $this->polylang->home_url( $lang ) : '';
		return '' !== $home ? $home : home_url( '/' );
	}

	/**
	 * Build a row: empty `$failed` = PASS, else `$bad` (FAIL/WARN); `$force` = SKIP overrides.
	 *
	 * @param string   $lang   Language slug or '*'.
	 * @param string   $check  Check name.
	 * @param string   $url    URL or ''.
	 * @param string   $bad    Verdict when `$failed` is not empty.
	 * @param string[] $failed Messages.
	 * @param string   $force  Forced verdict ('' = derive).
	 * @return array<string,mixed>
	 */
	private function result( string $lang, string $check, string $url, string $bad, array $failed, string $force = '' ): array {
		$result = '' !== $force ? $force : ( array() === $failed ? 'PASS' : $bad );
		return [
			'lang'   => '' === $lang ? '-' : $lang,
			'page'   => 'state',
			'status' => $check,
			'ms'     => '',
			'result' => $result,
			'failed' => implode( ', ', $failed ),
			'url'    => $url,
		];
	}
}
