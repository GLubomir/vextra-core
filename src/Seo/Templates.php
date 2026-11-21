<?php
/**
 * Per-language SEO title / description templates (data, not `.po` strings) applied through Rank Math's final title and
 * description filters. Stored Rank Math settings are never rewritten: they hold the Ukrainian values, this class overrides
 * them in code by the Polylang language of the request. A manually written title/description (post or term SEO meta) always wins.
 *
 * Covered: product title + description (price), category title (short form for long names), shop title + fallback description, front-page title + description, shop description, and the
 * `Сторінка N` / `Страница N` suffix on page 2+ of the shop and category archives (title always, description while <= 160 chars).
 * All strings live in `Templates::STRINGS`, adjustable with filter `vextra/seo/templates` (language slug => key => string).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;

final class Templates {

	/**
	 * Placeholders: %title% / %term% (object name), %sitename%, %d (page number, only in `page`).
	 */
	public const STRINGS = [
		'uk' => [
			'product_title'       => '%title% | %sitename%',
			'product_description' => 'Купити %title% за %price% ₴ у Vextra — доставка по Україні.',
			'term_title'          => '%term% — купити в Україні | %sitename%',
			'term_title_long'     => '%term% | %sitename%',
			'shop_title'          => 'Каталог товарів — електроніка, техніка, дім | %sitename%',
			'term_description'    => '%term% — великий вибір в інтернет-магазині Vextra. Каталог, фото та ціни.',
			'home_title'          => 'Інтернет-магазин електроніки та техніки в Україні | %sitename%',
			'home_description'    => 'Vextra — інтернет-магазин електроніки, побутової техніки та товарів для дому в Україні. Каталог з вибором за категоріями.',
			'shop_description'    => 'Каталог Vextra: електроніка, побутова техніка, дім і сад, дитячі товари, краса і здоров\'я та інші категорії. Оберіть потрібний розділ.',
			'page'                => 'Сторінка %d',
			'home_label'          => 'Головна',
		],
		'ru' => [
			'product_title'       => '%title% | %sitename%',
			'product_description' => 'Купить %title% за %price% ₴ в Vextra — доставка по Украине.',
			'term_title'          => '%term% — купить в Украине | %sitename%',
			'term_title_long'     => '%term% | %sitename%',
			'shop_title'          => 'Каталог товаров — электроника, техника, дом | %sitename%',
			'term_description'    => '%term% — большой выбор в интернет-магазине Vextra. Каталог, фото и цены.',
			'home_title'          => 'Интернет-магазин электроники и техники в Украине | %sitename%',
			'home_description'    => 'Vextra — интернет-магазин электроники, бытовой техники и товаров для дома в Украине. Каталог с выбором по категориям.',
			'shop_description'    => 'Каталог Vextra: электроника, бытовая техника, дом и сад, детские товары, красота и здоровье и другие категории. Выберите нужный раздел.',
			'page'                => 'Страница %d',
			'home_label'          => 'Главная',
		],
	];

	private const DESCRIPTION_MAX = 160;

	/**
	 * Category names longer than this use the short `%term% | Site` title (the suffix would be cut in the SERP).
	 */
	private const TERM_NAME_LONG = 28;

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

	public function __construct( Polylang $polylang, RankMath $rank_math ) {
		$this->polylang  = $polylang;
		$this->rank_math = $rank_math;
	}

	public function register(): void {
		$this->rank_math->filter_title( [ $this, 'title' ] );
		$this->rank_math->filter_description( [ $this, 'description' ] );
	}

	/**
	 * Strings of one language (falls back to the default language, then to Ukrainian).
	 *
	 * @param string $lang Language slug, '' = current request language.
	 * @return array<string,string>
	 */
	public function strings( string $lang = '' ): array {
		$all = (array) apply_filters( 'vextra/seo/templates', self::STRINGS );
		if ( '' === $lang ) {
			$lang = $this->polylang->current_language();
		}
		foreach ( [ $lang, $this->polylang->default_language(), 'uk' ] as $candidate ) {
			if ( '' !== $candidate && isset( $all[ $candidate ] ) && is_array( $all[ $candidate ] ) ) {
				return $all[ $candidate ] + self::STRINGS['uk'];
			}
		}
		return self::STRINGS['uk'];
	}

	/**
	 * Rank Math title filter.
	 *
	 * @param mixed $title Title built by Rank Math.
	 * @return mixed
	 */
	public function title( $title ) {
		if ( ! is_string( $title ) ) {
			return $title;
		}
		$t    = $this->strings();
		$site = (string) get_bloginfo( 'name' );

		if ( is_singular( 'product' ) ) {
			$id = (int) get_queried_object_id();
			return $this->rank_math->post_has_manual( $id, 'title' ) ? $title : $this->fill( $t['product_title'], [ '%title%' => wp_strip_all_tags( (string) get_post_field( 'post_title', $id, 'raw' ) ) ], $site );
		}

		if ( is_tax( TermCounts::TAXONOMY ) ) {
			$term = get_queried_object();
			if ( ! $term instanceof \WP_Term ) {
				return $title;
			}
			if ( ! $this->rank_math->term_has_manual( $term->term_id, 'title' ) ) {
				$name  = wp_strip_all_tags( $term->name );
				$title = $this->fill( $t[ mb_strlen( $name ) > self::TERM_NAME_LONG ? 'term_title_long' : 'term_title' ], [ '%term%' => $name ], $site );
			}
			return $this->with_page_title( $title, $t, $site );
		}

		if ( $this->is_front() ) {
			return $this->rank_math->post_has_manual( (int) get_queried_object_id(), 'title' ) ? $title : $this->fill( $t['home_title'], [], $site );
		}

		if ( $this->is_shop() ) {
			if ( ! $this->rank_math->post_has_manual( $this->shop_page_id(), 'title' ) ) {
				$title = $this->fill( $t['shop_title'], [], $site );
			}
			return $this->with_page_title( $title, $t, $site );
		}

		return $title;
	}

	/**
	 * Rank Math description filter.
	 *
	 * @param mixed $description Description built by Rank Math.
	 * @return mixed
	 */
	public function description( $description ) {
		if ( ! is_string( $description ) ) {
			return $description;
		}
		$t = $this->strings();

		if ( is_singular( 'product' ) ) {
			$id    = (int) get_queried_object_id();
			$price = $this->price( $id );
			if ( '' === $price || $this->rank_math->post_has_manual( $id, 'description' ) ) {
				return $description;
			}
			return $this->fill(
				$t['product_description'],
				[
					'%title%' => wp_strip_all_tags( (string) get_post_field( 'post_title', $id, 'raw' ) ),
					'%price%' => $price,
				],
				''
			);
		}

		if ( is_tax( TermCounts::TAXONOMY ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term && '' === trim( $description ) && ! $this->rank_math->term_has_manual( $term->term_id, 'description' ) ) {
				$description = $this->fill( $t['term_description'], [ '%term%' => wp_strip_all_tags( $term->name ) ], '' );
			}
			return $this->with_page_description( $description, $t );
		}

		if ( $this->is_front() ) {
			return $this->rank_math->post_has_manual( (int) get_queried_object_id(), 'description' ) ? $description : $t['home_description'];
		}

		if ( $this->is_shop() ) {
			if ( ! $this->rank_math->post_has_manual( $this->shop_page_id(), 'description' ) ) {
				$description = $t['shop_description'];
			}
			return $this->with_page_description( $description, $t );
		}

		return $description;
	}

	/**
	 * Display price of a product (lowest for variable products), '' when unknown/zero.
	 *
	 * @param int $product_id Product ID.
	 */
	private function price( int $product_id ): string {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}
		$value = (float) wc_get_price_to_display( $product );
		if ( $value <= 0 ) {
			return '';
		}
		$decimals = floor( $value ) === $value ? 0 : 2;
		return number_format( $value, $decimals, ',', "\u{00A0}" );
	}

	/**
	 * Static front page of the current language.
	 */
	private function is_front(): bool {
		return is_front_page() && ! is_home() && is_page();
	}

	private function is_shop(): bool {
		return function_exists( 'is_shop' ) && is_shop();
	}

	/**
	 * Shop page id of the current language (Woo option, mapped to the translation by `Multilang\WooPages`).
	 */
	private function shop_page_id(): int {
		return function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
	}

	/**
	 * Current page number (1 when not paged).
	 */
	private function page_number(): int {
		return max( 1, (int) get_query_var( 'paged' ) );
	}

	/**
	 * Replace placeholders.
	 *
	 * @param string               $template Template.
	 * @param array<string,string> $vars     Placeholder => value.
	 * @param string               $site     Site name.
	 */
	private function fill( string $template, array $vars, string $site ): string {
		return strtr( $template, $vars + [ '%sitename%' => $site ] );
	}

	/**
	 * Insert the page label before the ` | Site` suffix, or append it.
	 *
	 * @param string               $title Title.
	 * @param array<string,string> $t     Strings.
	 * @param string               $site  Site name.
	 */
	private function with_page_title( string $title, array $t, string $site ): string {
		$n = $this->page_number();
		if ( $n < 2 ) {
			return $title;
		}
		$label  = sprintf( $t['page'], $n );
		$suffix = ' | ' . $site;
		if ( '' !== $site && str_ends_with( $title, $suffix ) ) {
			return substr( $title, 0, -strlen( $suffix ) ) . ' — ' . $label . $suffix;
		}
		return $title . ' — ' . $label;
	}

	/**
	 * Add the page label to a description while it stays within 160 characters.
	 *
	 * @param string               $description Description.
	 * @param array<string,string> $t           Strings.
	 */
	private function with_page_description( string $description, array $t ): string {
		$n = $this->page_number();
		if ( $n < 2 || '' === trim( $description ) ) {
			return $description;
		}
		$candidate = $description . ' ' . sprintf( $t['page'], $n ) . '.';
		return mb_strlen( $candidate ) <= self::DESCRIPTION_MAX ? $candidate : $description;
	}
}
