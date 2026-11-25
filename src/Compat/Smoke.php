<?php
/**
 * Smoke runner: resolves the URLs of every language dynamically and checks them over HTTP (`wp_remote_get`).
 *
 * All requests go to the host of `home_url()` (the site itself; foreign hosts are never contacted). On a `local` environment with
 * `VX_PUBLIC_PREVIEW_TOKEN` defined, the per-request public preview header (TASK-032) is sent so robots / hreflang / sitemaps behave as in
 * production without touching `blog_public`. The token is never printed or logged.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Compat;

use Vextra\Core\Integrations\Polylang\Polylang;

final class Smoke {

	public const WARN_MS = 1500;
	public const FAIL_MS = 4000;

	/**
	 * Page keys in run order.
	 */
	public const PAGES = [ 'home', 'shop', 'category', 'subcategory', 'product', 'cart', 'account', 'search', '404', 'sitemap', 'state' ];

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	/**
	 * Warn threshold in ms.
	 *
	 * @var int
	 */
	private int $warn_ms = self::WARN_MS;

	/**
	 * Fail threshold in ms.
	 *
	 * @var int
	 */
	private int $fail_ms = self::FAIL_MS;

	public function __construct( Polylang $polylang ) {
		$this->polylang = $polylang;
	}

	/**
	 * Whether the preview header will be sent (local environment + token constant of a sane length).
	 */
	public function preview_enabled(): bool {
		return 'local' === wp_get_environment_type()
			&& defined( 'VX_PUBLIC_PREVIEW_TOKEN' )
			&& is_string( VX_PUBLIC_PREVIEW_TOKEN )
			&& strlen( VX_PUBLIC_PREVIEW_TOKEN ) >= 32;
	}

	/**
	 * Language slugs (a single empty slug when Polylang is not usable).
	 *
	 * @return string[]
	 */
	public function languages(): array {
		$langs = $this->polylang->languages();
		return array() === $langs ? [ '' ] : array_values( $langs );
	}

	/**
	 * Run the smoke checks.
	 *
	 * @param array{only?:string,lang?:string,warn_ms?:int,fail_ms?:int,no_preview?:bool} $opts Options (`no_preview` never sends the preview header).
	 * @return array{preview:bool,rows:array<int,array<string,mixed>>}
	 */
	public function run( array $opts = [] ): array {
		$this->warn_ms = isset( $opts['warn_ms'] ) && $opts['warn_ms'] > 0 ? (int) $opts['warn_ms'] : self::WARN_MS;
		$this->fail_ms = isset( $opts['fail_ms'] ) && $opts['fail_ms'] > 0 ? (int) $opts['fail_ms'] : self::FAIL_MS;
		$only          = (string) ( $opts['only'] ?? '' );
		$only_lang     = (string) ( $opts['lang'] ?? '' );

		$preview      = empty( $opts['no_preview'] ) && $this->preview_enabled();
		$assert_index = $preview || (bool) get_option( 'blog_public' );
		$langs        = $this->languages();
		$rows         = [];

		foreach ( $langs as $lang ) {
			if ( '' !== $only_lang && $lang !== $only_lang ) {
				continue;
			}
			foreach ( $this->targets( $lang, count( $langs ), $assert_index ) as $target ) {
				if ( '' !== $only && $target['key'] !== $only ) {
					continue;
				}
				$rows[] = $this->evaluate( $lang, $target, $preview );
			}
		}

		if ( ( '' === $only || 'sitemap' === $only ) && '' === $only_lang ) {
			$rows[] = $this->evaluate_sitemap( $langs, $preview );
		}
		if ( '' === $only || 'state' === $only ) {
			// With --lang the per-language checks (front-page twin, home-render, woo-pages twins) cover that language only; the global ones always run.
			$state = new StateChecks( $this->polylang );
			$rows  = array_merge(
				$rows,
				$state->run(
					$preview,
					'' !== $only_lang ? [ $only_lang ] : $langs,
					function ( string $url ) use ( $preview ): array {
						return $this->request( $url, $preview );
					},
					fn( string $url ): bool => $this->same_host( $url )
				)
			);
		}
		return [
			'preview' => $preview,
			'rows'    => $rows,
		];
	}

	/**
	 * Targets of one language (everything but the sitemap).
	 *
	 * @param string $lang         Language slug ('' = single language).
	 * @param int    $lang_count   Number of languages.
	 * @param bool   $assert_index Assert `index` on indexable pages.
	 * @return array<int,array<string,mixed>> Each: key, url, status, expect, skip.
	 */
	private function targets( string $lang, int $lang_count, bool $assert_index ): array {
		$home      = '' !== $lang ? $this->polylang->home_url( $lang ) : '';
		$home      = '' !== $home ? $home : home_url( '/' );
		$lang_attr = '' !== $lang ? $this->polylang->locale_of( $lang ) : '';
		$expect    = static function ( string $page ) use ( $lang_count, $assert_index, $lang_attr ): array {
			return Markers::expectation( $page, $lang_count, $assert_index, $lang_attr );
		};
		$target    = static function ( string $key, string $url, array $expectation, int $status = 200, string $skip = '' ): array {
			return [
				'key'    => $key,
				'url'    => $url,
				'status' => $status,
				'expect' => $expectation,
				'skip'   => $skip,
			];
		};

		$out   = [];
		$out[] = $target( 'home', $home, $expect( 'home' ) );

		$shop  = $this->page_url( 'shop', $lang );
		$out[] = $target( 'shop', $shop, $expect( 'shop' ), 200, '' === $shop ? 'shop page not found' : '' );

		list( $parent, $child ) = $this->categories( $lang );
		$parent_url             = $parent instanceof \WP_Term ? get_term_link( $parent ) : '';
		$child_url              = $child instanceof \WP_Term ? get_term_link( $child ) : '';
		$parent_url             = is_string( $parent_url ) ? $parent_url : '';
		$child_url              = is_string( $child_url ) ? $child_url : '';
		$out[]                  = $target( 'category', $parent_url, $expect( 'category' ), 200, '' === $parent_url ? 'no non-empty product category in this language' : '' );
		$out[]                  = $target( 'subcategory', $child_url, $expect( 'subcategory' ), 200, '' === $child_url ? 'no non-empty child category in this language' : '' );

		$product_id  = $this->first_product( $lang, $lang_count );
		$product_url = $product_id > 0 ? (string) get_permalink( $product_id ) : '';
		$out[]       = $target( 'product', $product_url, $expect( 'product' ), 200, '' === $product_url ? 'no published product in this language' : '' );

		$cart    = $this->page_url( 'cart', $lang );
		$account = $this->page_url( 'myaccount', $lang );
		$out[]   = $target( 'cart', $cart, $expect( 'cart' ), 200, '' === $cart ? 'cart page not found' : '' );
		$out[]   = $target( 'account', $account, $expect( 'account' ), 200, '' === $account ? 'my-account page not found' : '' );

		$word = '';
		if ( $product_id > 0 ) {
			$parts = preg_split( '/[\s\p{P}]+/u', (string) get_the_title( $product_id ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( false === $parts ? [] : $parts as $part ) {
				if ( mb_strlen( $part ) >= 3 ) {
					$word = $part;
					break;
				}
			}
		}
		$out[] = $target(
			'search',
			'' !== $word ? add_query_arg( 's', rawurlencode( $word ), $home ) : '',
			$expect( 'search' ),
			200,
			'' === $word ? 'no product title word to search for' : ''
		);
		$out[] = $target( '404', trailingslashit( $home ) . 'vx-smoke-404-' . wp_rand() . '/', $expect( '404' ), 404 );
		return $out;
	}

	/**
	 * Permalink of a WooCommerce page in a language ('' when missing).
	 *
	 * @param string $page Woo page key: shop | cart | myaccount.
	 * @param string $lang Language slug.
	 */
	private function page_url( string $page, string $lang ): string {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return '';
		}
		$id = (int) wc_get_page_id( $page );
		if ( $id <= 0 ) {
			return '';
		}
		if ( '' !== $lang ) {
			$translated = $this->polylang->translate_post( $id, $lang );
			$id         = $translated > 0 ? $translated : 0;
		}
		return $id > 0 && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
	}

	/**
	 * First top-level product category with a non-empty child, else the first non-empty top-level one.
	 *
	 * @param string $lang Language slug.
	 * @return array{0:?\WP_Term,1:?\WP_Term}
	 */
	private function categories( string $lang ): array {
		$args = [
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'parent'     => 0,
			'number'     => 12,
			'orderby'    => 'term_id',
		];
		if ( '' !== $lang ) {
			$args['lang'] = $lang;
		}
		$parents = get_terms( $args );
		if ( is_wp_error( $parents ) || array() === $parents ) {
			return [ null, null ];
		}
		foreach ( $parents as $parent ) {
			if ( ! $parent instanceof \WP_Term ) {
				continue;
			}
			$kids = get_terms(
				array_merge(
					$args,
					[
						'parent' => $parent->term_id,
						'number' => 1,
					]
				)
			);
			if ( ! is_wp_error( $kids ) && isset( $kids[0] ) && $kids[0] instanceof \WP_Term ) {
				return [ $parent, $kids[0] ];
			}
		}
		return [ $parents[0] instanceof \WP_Term ? $parents[0] : null, null ];
	}

	/**
	 * First published product of a language that exists in every language (a product without translations has no hreflang
	 * set, so it would make the markers meaningless); falls back to the first product of the language. 0 when none.
	 *
	 * @param string $lang       Language slug.
	 * @param int    $lang_count Number of languages.
	 */
	private function first_product( string $lang, int $lang_count ): int {
		$args = [
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => 20,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];
		if ( '' !== $lang ) {
			$args['lang'] = $lang;
		}
		$ids      = array_map( 'intval', ( new \WP_Query( $args ) )->posts );
		$fallback = 0;
		foreach ( $ids as $id ) {
			if ( '' !== $lang && $this->polylang->post_language( $id ) !== $lang ) {
				continue;
			}
			if ( 0 === $fallback ) {
				$fallback = $id;
			}
			if ( $lang_count < 2 || count( $this->polylang->translations( $id ) ) >= $lang_count ) {
				return $id;
			}
		}
		return $fallback;
	}

	/**
	 * Fetch + evaluate one target.
	 *
	 * @param string              $lang    Language slug.
	 * @param array<string,mixed> $target  Target.
	 * @param bool                $preview Preview header allowed.
	 * @return array<string,mixed> Row.
	 */
	private function evaluate( string $lang, array $target, bool $preview ): array {
		$row = $this->row( $lang, (string) $target['key'], (string) $target['url'] );
		if ( '' !== $target['skip'] ) {
			return $this->finish( $row, 'SKIP', [], (string) $target['skip'] );
		}
		if ( ! $this->same_host( (string) $target['url'] ) ) {
			return $this->finish( $row, 'FAIL', [ 'foreign-host' ] );
		}

		$this->request( (string) $target['url'], $preview ); // Warm-up, the second hit is timed.
		$res = $this->request( (string) $target['url'], $preview );
		if ( null !== $res['error'] ) {
			return $this->finish( $row, 'FAIL', [ 'http-error: ' . $res['error'] ] );
		}
		$row['status'] = $res['code'];
		$row['ms']     = $res['ms'];

		$failed = [];
		if ( $res['code'] >= 300 && $res['code'] < 400 ) {
			$failed[] = 'redirect -> ' . $res['location'];
		} elseif ( (int) $target['status'] !== $res['code'] ) {
			$failed[] = 'status-' . $res['code'] . ' (expected ' . $target['status'] . ')';
		}
		if ( array() === $failed ) {
			$failed = Markers::check( $res['body'], (array) $target['expect'] );
		}
		return $this->finish( $row, '', $failed );
	}

	/**
	 * Sitemap index + per-language presence in the product_cat sitemap.
	 *
	 * @param string[] $langs   Language slugs.
	 * @param bool     $preview Preview header allowed.
	 * @return array<string,mixed> Row.
	 */
	private function evaluate_sitemap( array $langs, bool $preview ): array {
		$url = home_url( '/sitemap_index.xml' );
		$row = $this->row( '*', 'sitemap', $url );
		if ( ! $this->same_host( $url ) ) {
			return $this->finish( $row, 'FAIL', [ 'foreign-host' ] );
		}
		$this->request( $url, $preview );
		$res = $this->request( $url, $preview );
		if ( null !== $res['error'] ) {
			return $this->finish( $row, 'FAIL', [ 'http-error: ' . $res['error'] ] );
		}
		$row['status'] = $res['code'];
		$row['ms']     = $res['ms'];

		$failed = [];
		if ( $res['code'] >= 300 && $res['code'] < 400 ) {
			$failed[] = 'redirect -> ' . $res['location'];
		} elseif ( 200 !== $res['code'] ) {
			$failed[] = 'status-' . $res['code'] . ' (expected 200)';
		} else {
			$body = $res['body'];
			if ( false === strpos( $body, '<sitemapindex' ) ) {
				$failed[] = 'no-sitemapindex';
			}
			if ( Markers::has_php_error( $body ) ) {
				$failed[] = 'php-notice';
			}
			preg_match_all( '/<loc>\s*([^<\s]+)\s*<\/loc>/i', $body, $locs );
			$product_sitemap = '';
			$cat_sitemap     = '';
			foreach ( $locs[1] as $loc ) {
				if ( '' === $product_sitemap && false !== strpos( $loc, 'product-sitemap' ) ) {
					$product_sitemap = $loc;
				}
				if ( '' === $cat_sitemap && false !== strpos( $loc, 'product_cat-sitemap' ) ) {
					$cat_sitemap = $loc;
				}
			}
			if ( '' === $product_sitemap ) {
				$failed[] = 'no-product-sitemap';
			}
			if ( '' === $cat_sitemap ) {
				$failed[] = 'no-category-sitemap';
			} elseif ( $this->same_host( $cat_sitemap ) ) {
				$child = $this->request( $cat_sitemap, $preview );
				if ( null !== $child['error'] || 200 !== $child['code'] ) {
					$failed[] = 'category-sitemap-unreachable';
				} else {
					foreach ( $langs as $lang ) {
						$home = '' !== $lang ? $this->polylang->home_url( $lang ) : '';
						if ( '' !== $lang && '' !== $home && $lang !== $this->polylang->default_language() && false === strpos( $child['body'], $home ) ) {
							$failed[] = 'sitemap-lang-' . $lang;
						}
					}
				}
			}
		}
		return $this->finish( $row, '', $failed );
	}

	/**
	 * Base row.
	 *
	 * @param string $lang Language slug.
	 * @param string $page Page key.
	 * @param string $url  URL.
	 * @return array<string,mixed>
	 */
	private function row( string $lang, string $page, string $url ): array {
		return [
			'lang'   => '' === $lang ? '-' : $lang,
			'page'   => $page,
			'status' => '',
			'ms'     => '',
			'result' => '',
			'failed' => '',
			'url'    => $url,
		];
	}

	/**
	 * Apply the response-time thresholds and set the verdict.
	 *
	 * @param array<string,mixed> $row      Row.
	 * @param string              $forced   Forced result (SKIP/FAIL) or '' to derive.
	 * @param string[]            $failed   Failed markers.
	 * @param string              $reason   Skip reason.
	 * @return array<string,mixed>
	 */
	private function finish( array $row, string $forced, array $failed, string $reason = '' ): array {
		$ms = is_int( $row['ms'] ) ? $row['ms'] : 0;
		if ( 'SKIP' === $forced ) {
			$row['result'] = 'SKIP';
			$row['failed'] = $reason;
			return $row;
		}
		if ( $ms > $this->fail_ms ) {
			$failed[] = 'slow>' . $this->fail_ms . 'ms';
		}
		if ( array() !== $failed || 'FAIL' === $forced ) {
			$row['result'] = 'FAIL';
		} elseif ( $ms > $this->warn_ms ) {
			$row['result'] = 'WARN';
			$failed[]      = 'slow>' . $this->warn_ms . 'ms';
		} else {
			$row['result'] = 'PASS';
		}
		$row['failed'] = implode( ', ', $failed );
		return $row;
	}

	/**
	 * Only the site itself is ever requested.
	 *
	 * @param string $url URL.
	 */
	private function same_host( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$mine = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) && is_string( $mine ) && strtolower( $host ) === strtolower( $mine );
	}

	/**
	 * One GET without following redirects.
	 *
	 * @param string $url     Absolute URL on the site host.
	 * @param bool   $preview Send the preview header (the caller already checked the host).
	 * @return array{error:?string,code:int,ms:int,body:string,location:string,cookies:string[]}
	 */
	private function request( string $url, bool $preview ): array {
		$args = [
			'timeout'     => 30,
			'redirection' => 0,
			'sslverify'   => 'local' !== wp_get_environment_type(),
			'user-agent'  => 'vextra-smoke',
			'headers'     => [],
		];
		if ( $preview && $this->same_host( $url ) ) {
			$args['headers']['X-VX-Public-Preview'] = (string) VX_PUBLIC_PREVIEW_TOKEN;
		}

		$start = microtime( true );
		$res   = wp_remote_get( $url, $args );
		if ( is_wp_error( $res ) ) { // One retry: a local server may reset a connection once.
			$start = microtime( true );
			$res   = wp_remote_get( $url, $args );
		}
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $res ) ) {
			return [
				'error'    => $res->get_error_message(),
				'code'     => 0,
				'ms'       => $ms,
				'body'     => '',
				'location' => '',
				'cookies'  => [],
			];
		}
		return [
			'error'    => null,
			'code'     => (int) wp_remote_retrieve_response_code( $res ),
			'ms'       => $ms,
			'body'     => (string) wp_remote_retrieve_body( $res ),
			'location' => (string) wp_remote_retrieve_header( $res, 'location' ),
			'cookies'  => array_values(
				array_map(
					static fn( $cookie ): string => $cookie instanceof \WP_Http_Cookie ? (string) $cookie->name : '',
					(array) wp_remote_retrieve_cookies( $res )
				)
			),
		];
	}
}
