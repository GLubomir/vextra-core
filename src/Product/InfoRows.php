<?php
/**
 * Info rows of the product page buy box (delivery / payment / warranty / free shipping) (TASK-044a).
 *
 * Option `vx_pdp_info` per language slug: `{ rows: { delivery|payment|warranty: { text, page_id } }, free_shipping_threshold: int }`
 * (admin page `Admin\PdpInfoPage`). A language WITHOUT a saved entry shows the neutral default copy of this class (no numbers, terms,
 * prices or promises, SEO MINOR-5; the owner confirms the final texts before launch); a language WITH a saved entry never falls back to
 * the defaults: an empty row is hidden, so no Ukrainian string can appear in the Russian DOM. Only complete rows (text present) are returned.
 * A row links to its page only when the page (its twin in the language) is published, not password protected and not noindex
 * (`RankMath::is_post_noindex`); otherwise the row shows the text without a link. `free_shipping_threshold` 0 = no row.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Product;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;
use Vextra\Core\Support\Cache;

final class InfoRows {

	public const OPTION = 'vx_pdp_info';
	public const GROUP  = 'vx_pdp_info';
	public const KEYS   = [ 'delivery', 'payment', 'warranty' ];

	/**
	 * Neutral default copy per language slug (literal strings on purpose: never translated at read time, never the wrong language).
	 */
	private const DEFAULTS = [
		'ru'      => [
			'delivery' => 'Доставка по Украине: условия и сроки уточняются при оформлении заказа',
			'payment'  => 'Способы оплаты доступны при оформлении заказа',
			'warranty' => 'Условия гарантии и возврата описаны на отдельной странице',
		],
		'default' => [
			'delivery' => 'Доставка по Україні: умови й терміни уточнюються під час оформлення замовлення',
			'payment'  => 'Способи оплати доступні під час оформлення замовлення',
			'warranty' => 'Умови гарантії та повернення описані на окремій сторінці',
		],
	];

	private const FREE_SHIPPING = [
		'ru'      => 'Бесплатная доставка от %s',
		'default' => 'Безкоштовна доставка від %s',
	];

	/**
	 * Per-request memo.
	 *
	 * @var array<string,array<int,array<string,string>>>
	 */
	private static array $memo = [];

	public static function register(): void {
		add_action( 'add_option_' . self::OPTION, [ self::class, 'flush' ] );
		add_action( 'update_option_' . self::OPTION, [ self::class, 'flush' ] );
		add_action( 'delete_option_' . self::OPTION, [ self::class, 'flush' ] );
		add_action( 'post_updated', [ self::class, 'on_post' ], 10, 1 );
		add_action( 'updated_post_meta', [ self::class, 'on_meta' ], 10, 3 );
		add_action( 'added_post_meta', [ self::class, 'on_meta' ], 10, 3 );
	}

	/**
	 * Drop the read cache.
	 */
	public static function flush(): void {
		self::$memo = [];
		Cache::flush( self::GROUP );
	}

	/**
	 * A page changed (status, password).
	 *
	 * @param mixed $post_id Post id.
	 */
	public static function on_post( $post_id = 0 ): void {
		if ( 'page' === get_post_type( absint( $post_id ) ) ) {
			self::flush();
		}
	}

	/**
	 * A Rank Math robots flag changed.
	 *
	 * @param mixed $meta_id  Meta id.
	 * @param mixed $post_id  Post id.
	 * @param mixed $meta_key Meta key.
	 */
	public static function on_meta( $meta_id = 0, $post_id = 0, $meta_key = '' ): void {
		unset( $meta_id );
		if ( 'rank_math_robots' === $meta_key && 'page' === get_post_type( absint( $post_id ) ) ) {
			self::flush();
		}
	}

	/**
	 * Storage key of a language ('' without Polylang).
	 *
	 * @param string $lang Language slug.
	 */
	public static function slot( string $lang ): string {
		return '' === $lang ? 'default' : $lang;
	}

	/**
	 * Complete rows of a language: `[ [ key, text, url ] ]` (`url` '' = no link).
	 *
	 * @param string $lang Language slug ('' = the current one).
	 * @return array<int,array{key:string,text:string,url:string}>
	 */
	public static function for_language( string $lang = '' ): array {
		if ( '' === $lang ) {
			$pll  = Plugin::instance()->integration( 'polylang' );
			$lang = $pll instanceof Polylang ? $pll->current_language() : '';
		}
		$slot = self::slot( $lang );
		if ( isset( self::$memo[ $slot ] ) ) {
			return self::$memo[ $slot ];
		}
		$rows                = Cache::remember( self::GROUP, 'rows:' . $slot, HOUR_IN_SECONDS, static fn(): array => self::build( $lang ) );
		self::$memo[ $slot ] = is_array( $rows ) ? $rows : self::build( $lang );
		return self::$memo[ $slot ];
	}

	/**
	 * Build the rows of a language.
	 *
	 * @param string $lang Language slug.
	 * @return array<int,array{key:string,text:string,url:string}>
	 */
	private static function build( string $lang ): array {
		$slot  = self::slot( $lang );
		$saved = get_option( self::OPTION, [] );
		$saved = is_array( $saved ) ? $saved : [];
		$out   = [];
		if ( isset( $saved[ $slot ] ) && is_array( $saved[ $slot ] ) ) {
			$entry     = $saved[ $slot ];
			$threshold = absint( $entry['free_shipping_threshold'] ?? 0 );
			foreach ( self::KEYS as $key ) {
				$row  = is_array( $entry['rows'][ $key ] ?? null ) ? $entry['rows'][ $key ] : [];
				$text = trim( (string) ( $row['text'] ?? '' ) );
				if ( '' !== $text ) {
					$out[] = [
						'key'  => $key,
						'text' => $text,
						'url'  => self::page_url( absint( $row['page_id'] ?? 0 ), $lang ),
					];
				}
			}
		} else {
			$copy      = self::DEFAULTS[ $slot ] ?? self::DEFAULTS['default'];
			$threshold = 0;
			foreach ( self::KEYS as $key ) {
				$out[] = [
					'key'  => $key,
					'text' => $copy[ $key ],
					'url'  => '',
				];
			}
		}
		if ( $threshold > 0 && function_exists( 'wc_price' ) ) {
			$amount = html_entity_decode( wp_strip_all_tags( wc_price( $threshold ) ), ENT_QUOTES, 'UTF-8' );
			$format = self::FREE_SHIPPING[ $slot ] ?? self::FREE_SHIPPING['default'];
			array_unshift(
				$out,
				[
					'key'  => 'free_shipping',
					'text' => sprintf( $format, $amount ),
					'url'  => '',
				]
			);
		}
		return $out;
	}

	/**
	 * Link of a row: the page (or its twin in the language) when it is published, public and indexable.
	 *
	 * @param int    $page_id Page id.
	 * @param string $lang    Language slug.
	 */
	private static function page_url( int $page_id, string $lang ): string {
		if ( $page_id <= 0 ) {
			return '';
		}
		$plugin = Plugin::instance();
		$pll    = $plugin->integration( 'polylang' );
		if ( $pll instanceof Polylang && '' !== $lang ) {
			$twin = $pll->translate_post( $page_id, $lang );
			if ( $twin <= 0 ) {
				return '';
			}
			$page_id = $twin;
		}
		$post = get_post( $page_id );
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return '';
		}
		$rank = $plugin->integration( 'rankmath' );
		if ( $rank instanceof RankMath && $rank->is_post_noindex( $page_id ) ) {
			return '';
		}
		$url = get_permalink( $page_id );
		return is_string( $url ) ? $url : '';
	}
}
