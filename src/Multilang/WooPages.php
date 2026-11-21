<?php
/**
 * Woo pages per language: page-id filters + shared slugs for the four base pages.
 *
 * Page ids: `woocommerce_get_<page>_page_id` maps the base (default-language) id to its translation in the
 * current language (request memo, falls back to the original id). Before a language is defined (early hooks,
 * WP-CLI) the original id is returned, so rewrite rules stay on the default language.
 *
 * Shared slugs (Polylang Pro feature, narrow reimplementation): the RU twin of shop/cart/checkout/my-account keeps the
 * UA slug (`/ru/catalog/`); `wp_unique_post_slug` is told to ignore collisions with other languages inside the
 * translation group only. Do not rename the four base slugs: UA and RU slugs are shared by this filter, not linked.
 * A rename = both pages plus a manual Rank Math redirect in both languages.
 *
 * Sitemap: all translations of cart/checkout/my-account are excluded through the RankMath adapter.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Multilang;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;

final class WooPages {

	/**
	 * Woo page keys mapped per language (option `woocommerce_<key>_page_id`).
	 */
	public const MAPPED = [ 'shop', 'cart', 'checkout', 'myaccount', 'terms', 'refund_returns' ];

	/**
	 * Pages whose RU twin shares the UA slug.
	 */
	public const SHARED_SLUG = [ 'shop', 'cart', 'checkout', 'myaccount' ];

	/**
	 * Transactional pages: noindex + out of the sitemap.
	 */
	public const NOINDEX = [ 'cart', 'checkout', 'myaccount' ];

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	/**
	 * Rank Math adapter.
	 *
	 * @var RankMath
	 */
	private RankMath $rank_math;

	/**
	 * Request memo: "<base id>:<lang>" => id.
	 *
	 * @var array<string,int>
	 */
	private array $memo = [];

	/**
	 * Woo pages and their translations warmed (once per request).
	 *
	 * @var bool
	 */
	private bool $primed = false;

	public function __construct( Polylang $polylang, RankMath $rank_math ) {
		$this->polylang  = $polylang;
		$this->rank_math = $rank_math;
	}

	public function register(): void {
		foreach ( self::MAPPED as $key ) {
			add_filter( 'woocommerce_get_' . $key . '_page_id', [ $this, 'map_page_id' ] );
		}
		add_filter( 'wp_unique_post_slug', [ $this, 'share_slug' ], 10, 6 );
		add_filter( 'request', [ $this, 'resolve_shared_slug' ], 5 );
		$this->rank_math->exclude_posts_from_sitemap( [ $this, 'excluded_post_ids' ] );
	}

	/**
	 * Page id of the current language.
	 *
	 * @param mixed $page_id Base page id (Woo option value).
	 * @return mixed
	 */
	public function map_page_id( $page_id ) {
		$base = (int) $page_id;
		if ( $base <= 0 || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $page_id;
		}

		$lang = $this->polylang->current_language();
		if ( '' === $lang ) {
			return $page_id;
		}

		if ( ! $this->primed ) {
			$this->primed = true;
			$this->prime_pages();
		}

		$memo = $base . ':' . $lang;
		if ( ! isset( $this->memo[ $memo ] ) ) {
			$translated          = $this->polylang->translate_post( $base, $lang );
			$this->memo[ $memo ] = $translated > 0 ? $translated : $base;
		}

		return $this->memo[ $memo ];
	}

	/**
	 * Warm the page-id options, the mapped Woo pages and all their translations in a few batch queries (TASK-035):
	 * without it every page id read, translation lookup and translated permalink (header, footer, speculation rules)
	 * costs its own query on every front request.
	 */
	private function prime_pages(): void {
		$options = [];
		foreach ( self::MAPPED as $key ) {
			$options[] = 'woocommerce_' . $key . '_page_id';
		}
		wp_prime_option_caches( $options );

		$base = [];
		foreach ( $options as $option ) {
			$id = (int) get_option( $option );
			if ( $id > 0 ) {
				$base[] = $id;
			}
		}
		$this->polylang->prime_posts( $base );

		$all = $base;
		foreach ( $base as $id ) {
			foreach ( $this->polylang->translations( $id ) as $translated ) {
				$all[] = (int) $translated;
			}
		}
		$this->polylang->prime_posts( $all );
	}

	/**
	 * `get_page_by_path()` is language-blind: with shared slugs `/ru/cart/` would resolve to the UA page. Replace
	 * `pagename` by the `page_id` of the current-language page (what Polylang Pro's slug sharing does).
	 *
	 * @param mixed $vars Parsed query vars.
	 * @return mixed
	 */
	public function resolve_shared_slug( $vars ) {
		if ( ! is_array( $vars ) || empty( $vars['pagename'] ) || ! is_string( $vars['pagename'] ) || is_admin() ) {
			return $vars;
		}
		$lang = $this->polylang->current_language();
		if ( '' === $lang ) {
			return $vars;
		}
		$slug = trim( $vars['pagename'], '/' );
		foreach ( self::SHARED_SLUG as $key ) {
			$base = (int) get_option( 'woocommerce_' . $key . '_page_id' );
			if ( $base <= 0 || (string) get_post_field( 'post_name', $base ) !== $slug ) {
				continue;
			}
			$page = $this->polylang->translate_post( $base, $lang );
			if ( $page > 0 ) {
				unset( $vars['pagename'], $vars['name'] );
				$vars['page_id'] = (string) $page;
			}
			break;
		}
		return $vars;
	}

	/**
	 * Allow the same slug inside the translation group of a shared-slug Woo page.
	 *
	 * @param string $slug          Slug after WordPress uniqueness handling.
	 * @param int    $post_id       Post ID.
	 * @param string $post_status   Status.
	 * @param string $post_type     Post type.
	 * @param int    $post_parent   Parent ID.
	 * @param string $original_slug Slug before uniqueness handling.
	 * @return string
	 */
	public function share_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$post_id = (int) $post_id;
		if ( 'page' !== $post_type || $post_id <= 0 || $slug === $original_slug || '' === (string) $original_slug ) {
			return $slug;
		}

		$lang = $this->polylang->post_language( $post_id );
		if ( '' === $lang || ! $this->in_shared_group( $post_id, (string) $original_slug ) ) {
			return $slug;
		}

		// A page of the SAME language (or without language) holding the slug keeps the suffix.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only lookup, request-scoped and rare.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'page' AND post_parent = %d AND ID <> %d AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) LIMIT 20",
				$original_slug,
				(int) $post_parent,
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $other ) {
			$other_lang = $this->polylang->post_language( (int) $other );
			if ( '' === $other_lang || $other_lang === $lang ) {
				return $slug;
			}
		}

		return (string) $original_slug;
	}

	/**
	 * Ids of every translation of cart/checkout/my-account (sitemap exclusion).
	 *
	 * @param mixed $ids Existing excluded ids.
	 * @return int[]
	 */
	public function excluded_post_ids( $ids = [] ): array {
		$out = array_map( 'intval', is_array( $ids ) ? $ids : [] );
		foreach ( self::NOINDEX as $key ) {
			$base = (int) get_option( 'woocommerce_' . $key . '_page_id' );
			if ( $base > 0 ) {
				$out[] = $base;
				foreach ( $this->polylang->translations( $base ) as $id ) {
					$out[] = $id;
				}
			}
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * Is the post a member of the translation group of a shared-slug base page with that slug.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Original slug.
	 */
	private function in_shared_group( int $post_id, string $slug ): bool {
		foreach ( self::SHARED_SLUG as $key ) {
			$base = (int) get_option( 'woocommerce_' . $key . '_page_id' );
			if ( $base <= 0 ) {
				continue;
			}
			if ( $post_id !== $base && ! in_array( $post_id, $this->polylang->translations( $base ), true ) ) {
				continue;
			}
			if ( (string) get_post_field( 'post_name', $base ) === $slug ) {
				return true;
			}
		}
		return false;
	}
}
