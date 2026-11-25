<?php
/**
 * Index gate of the info / legal pages (TASK-047a).
 *
 * ONE list of launch pages (UA slugs; RU twins through Polylang), ONE text-measuring function used by the robots guard, the CLI
 * (`wp vextra seo launch-pages`, `audit-pages`) and the sitemap exclusion: a page is publishable when its visible text is long enough
 * and carries no owner placeholder (`[ВЛАСНИК: ...]`, any bracket group that starts with a letter, or the "page in development" phrases).
 * Real text with citations such as `[1]` passes (a placeholder starts with a letter). `track-order` is NOT a launch page: it is always noindex.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Integrations\Polylang\Polylang;

final class LaunchPages {

	/**
	 * UA slugs of the pages that may go to the index.
	 */
	public const SLUGS = [ 'delivery', 'warranty', 'about', 'contacts', 'faq', 'public-offer', 'privacy-policy', 'partners' ];

	/**
	 * UA slug of the order-tracking page (always noindex, never in the sitemap).
	 */
	public const TRACK_SLUG = 'track-order';

	/**
	 * Minimum visible characters.
	 */
	public const MIN_CHARS = 400;

	/**
	 * Per-slug minimum (contacts is mostly a dynamic block).
	 */
	public const MIN_OVERRIDES = [ 'contacts' => 150 ];

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	/**
	 * Verdicts by post id (one request).
	 *
	 * @var array<int,array{ok:bool,chars:int,reason:string,snippet:string}>
	 */
	private array $memo = [];

	/**
	 * UA slug of a post id (memo), '' when the page is not a launch page.
	 *
	 * @var array<int,string>
	 */
	private array $slug_memo = [];

	/**
	 * Track-order page ids (UA + twins).
	 *
	 * @var int[]|null
	 */
	private ?array $track_ids = null;

	public function __construct( Polylang $polylang ) {
		$this->polylang = $polylang;
	}

	/**
	 * Launch slugs (filter `vextra/core/seo/launch_pages`).
	 *
	 * @return string[]
	 */
	public function slugs(): array {
		$slugs = apply_filters( 'vextra/core/seo/launch_pages', self::SLUGS );
		return is_array( $slugs ) ? array_values( array_filter( array_map( 'sanitize_title', array_map( 'strval', $slugs ) ) ) ) : self::SLUGS;
	}

	/**
	 * Visible text of a page body: block comments, shortcodes, tags removed (closed `details` content counts), whitespace collapsed.
	 * The single measuring function of the gate.
	 *
	 * @param string $content Raw `post_content`.
	 */
	public static function visible_text( string $content ): string {
		$text = (string) preg_replace( '/<!--.*?-->/s', ' ', $content );
		// Shortcodes have ASCII lower-case names; an owner placeholder starts with a letter of any script after the bracket and has no such name + space/close shape.
		$text = (string) preg_replace( '/\[\/?[a-z][a-z0-9_\-]*(?:\s[^\[\]]*)?\]/', ' ', $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * First owner placeholder / "in development" phrase in a visible text ('' = none).
	 *
	 * @param string $text Output of visible_text().
	 */
	public static function find_placeholder( string $text ): string {
		if ( 1 === preg_match( '/\[\p{L}[^\[\]]{2,119}\]/u', $text, $m ) ) {
			return $m[0];
		}
		if ( 1 === preg_match( '/Сторінка в розробці|Страница в разработке/ui', $text, $m ) ) {
			return $m[0];
		}
		return '';
	}

	/**
	 * Threshold of a UA slug.
	 *
	 * @param string $slug UA slug.
	 */
	public static function min_chars( string $slug ): int {
		return self::MIN_OVERRIDES[ $slug ] ?? self::MIN_CHARS;
	}

	/**
	 * Verdict for text and a threshold (pure; the unit-style check feeds samples here).
	 *
	 * @param string $content Raw content.
	 * @param int    $min     Minimum characters.
	 * @return array{ok:bool,chars:int,reason:string,snippet:string}
	 */
	public static function judge( string $content, int $min ): array {
		$text  = self::visible_text( $content );
		$chars = mb_strlen( $text );
		$found = self::find_placeholder( $text );
		if ( '' !== $found ) {
			return [
				'ok'      => false,
				'chars'   => $chars,
				'reason'  => 'placeholder',
				'snippet' => mb_substr( $found, 0, 60 ),
			];
		}
		if ( $chars < $min ) {
			return [
				'ok'      => false,
				'chars'   => $chars,
				'reason'  => 'length',
				'snippet' => '',
			];
		}
		return [
			'ok'      => true,
			'chars'   => $chars,
			'reason'  => '',
			'snippet' => '',
		];
	}

	/**
	 * UA slug of the launch group a page belongs to ('' = not a launch page).
	 *
	 * @param int $post_id Page id (any language).
	 */
	public function launch_slug( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		if ( isset( $this->slug_memo[ $post_id ] ) ) {
			return $this->slug_memo[ $post_id ];
		}
		$source = $this->source_id( $post_id );
		$slug   = $source > 0 ? (string) get_post_field( 'post_name', $source ) : '';
		$slug   = in_array( $slug, $this->slugs(), true ) && 'page' === get_post_type( $source ) ? $slug : '';

		$this->slug_memo[ $post_id ] = $slug;
		return $slug;
	}

	/**
	 * Id of the default-language page of a translation group (the page itself without Polylang).
	 *
	 * @param int $post_id Page id.
	 */
	public function source_id( int $post_id ): int {
		$group = $this->polylang->translations( $post_id );
		$def   = $this->polylang->default_language();
		return isset( $group[ $def ] ) && $group[ $def ] > 0 ? (int) $group[ $def ] : $post_id;
	}

	/**
	 * Inspect one page.
	 *
	 * @param int $post_id Page id.
	 * @return array{ok:bool,chars:int,reason:string,snippet:string} `reason`: '' | `placeholder` | `length` | `missing` | `not_launch`.
	 */
	public function inspect( int $post_id ): array {
		if ( isset( $this->memo[ $post_id ] ) ) {
			return $this->memo[ $post_id ];
		}
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		$slug = $this->launch_slug( $post_id );
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
			$verdict = [
				'ok'      => false,
				'chars'   => 0,
				'reason'  => 'missing',
				'snippet' => '',
			];
		} elseif ( '' === $slug ) {
			$verdict = [
				'ok'      => false,
				'chars'   => 0,
				'reason'  => 'not_launch',
				'snippet' => '',
			];
		} else {
			$verdict = self::judge( (string) $post->post_content, self::min_chars( $slug ) );
		}
		$this->memo[ $post_id ] = $verdict;
		return $verdict;
	}

	/**
	 * Page ids of the order-tracking page (UA + twins).
	 *
	 * @return int[]
	 */
	public function track_ids(): array {
		if ( null !== $this->track_ids ) {
			return $this->track_ids;
		}
		$ids  = [];
		$base = $this->find( self::TRACK_SLUG );
		if ( $base > 0 ) {
			$ids = array_values( array_unique( array_merge( [ $base ], array_values( $this->polylang->translations( $base ) ) ) ) );
		}
		$this->track_ids = array_map( 'intval', $ids );
		return $this->track_ids;
	}

	/**
	 * Whether a page is the order-tracking page (any language).
	 *
	 * @param int $post_id Page id.
	 */
	public function is_track_page( int $post_id ): bool {
		return $post_id > 0 && in_array( $post_id, $this->track_ids(), true );
	}

	/**
	 * Translation group of a UA slug: language => page id (empty when the UA page is missing).
	 *
	 * @param string $slug UA slug.
	 * @return array<string,int>
	 */
	public function pair( string $slug ): array {
		$id = $this->find( $slug );
		if ( $id <= 0 ) {
			return [];
		}
		$group = $this->polylang->translations( $id );
		return array() !== $group ? $group : [ $this->polylang->default_language() => $id ];
	}

	/**
	 * Page id by UA slug (default language, any status), 0 when absent.
	 *
	 * @param string $slug Page slug.
	 */
	public function find( string $slug ): int {
		$ids = get_posts(
			[
				'post_type'              => 'page',
				'name'                   => $slug,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'lang'                   => $this->polylang->default_language(),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);
		return array() !== $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Sitemap exclusion: launch pages failing the gate and the tracking page, in every language.
	 *
	 * @param mixed $ids Ids already excluded.
	 * @return int[]
	 */
	public function sitemap_excluded( $ids = [] ): array {
		$out = is_array( $ids ) ? array_map( 'intval', $ids ) : [];
		try {
			foreach ( $this->slugs() as $slug ) {
				foreach ( $this->pair( $slug ) as $id ) {
					if ( ! $this->inspect( (int) $id )['ok'] ) {
						$out[] = (int) $id;
					}
				}
			}
			$out = array_merge( $out, $this->track_ids() );
		} catch ( \Throwable $e ) {
			unset( $e ); // The sitemap keeps Rank Math's own list; the robots guard fails closed.
		}
		return array_values( array_unique( $out ) );
	}
}
