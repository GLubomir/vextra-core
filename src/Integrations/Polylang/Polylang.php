<?php
/**
 * Polylang adapter: availability, version and readiness.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Integrations\Polylang;

use Vextra\Core\Integrations\AbstractIntegration;

final class Polylang extends AbstractIntegration {

	public function id(): string {
		return 'polylang';
	}

	protected function detect(): bool {
		return defined( 'POLYLANG_VERSION' ) || function_exists( 'pll_current_language' );
	}

	public function version(): ?string {
		return defined( 'POLYLANG_VERSION' ) ? (string) POLYLANG_VERSION : null;
	}

	/**
	 * True once Polylang's API is usable (after `pll_init`). Modules needing languages must hook later
	 * themselves; do not call Polylang functions from `register()`.
	 */
	public function is_ready(): bool {
		return $this->is_available() && function_exists( 'pll_current_language' ) && function_exists( 'PLL' );
	}

	/**
	 * Current front-end language slug, or '' when none is defined (WP-CLI, early hooks, Polylang absent).
	 */
	public function current_language(): string {
		if ( ! $this->is_ready() ) {
			return '';
		}
		$lang = pll_current_language();
		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * Default language slug, '' when unknown.
	 */
	public function default_language(): string {
		if ( ! $this->is_ready() || ! function_exists( 'pll_default_language' ) ) {
			return '';
		}
		$lang = pll_default_language();
		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * All language slugs.
	 *
	 * @return string[]
	 */
	public function languages(): array {
		if ( ! $this->is_ready() || ! function_exists( 'pll_languages_list' ) ) {
			return [];
		}
		return array_map( 'strval', (array) pll_languages_list() );
	}

	/**
	 * Language slug from a value posted by Polylang admin forms: the slug itself or the numeric language term id
	 * (the "add term" dropdown posts `term_id`). Public API only (`pll_languages_list` field maps); '' when unknown.
	 *
	 * @param string $value Slug or numeric id.
	 */
	public function slug_from_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || ! $this->is_ready() || ! function_exists( 'pll_languages_list' ) ) {
			return '';
		}
		$slugs = array_map( 'strval', (array) pll_languages_list( [ 'fields' => 'slug' ] ) );
		if ( in_array( $value, $slugs, true ) ) {
			return $value;
		}
		if ( ctype_digit( $value ) ) {
			$ids = array_map( 'strval', array_values( (array) pll_languages_list( [ 'fields' => 'term_id' ] ) ) );
			$pos = array_search( $value, $ids, true );
			if ( false !== $pos && isset( $slugs[ $pos ] ) ) {
				return $slugs[ $pos ];
			}
		}
		return '';
	}
	/**
	 * Language slug by the term id of the `language` taxonomy (the value `term_relationships` stores for a post): `[ 12 => 'uk', 15 => 'ru' ]`.
	 * Public API only (`pll_languages_list` field maps).
	 *
	 * @return array<int,string>
	 */
	public function language_term_map(): array {
		if ( ! $this->is_ready() || ! function_exists( 'pll_languages_list' ) ) {
			return [];
		}
		$slugs = array_values( array_map( 'strval', (array) pll_languages_list( [ 'fields' => 'slug' ] ) ) );
		$terms = array_values( array_map( 'intval', (array) pll_languages_list( [ 'fields' => 'term_id' ] ) ) );
		$map   = [];
		foreach ( $slugs as $i => $slug ) {
			if ( isset( $terms[ $i ] ) && $terms[ $i ] > 0 ) {
				$map[ $terms[ $i ] ] = $slug;
			}
		}
		return $map;
	}

	/**
	 * Hreflang base code (language part of the locale, e.g. `ru`) by language slug.
	 *
	 * @return array<string,string>
	 */
	public function locale_codes(): array {
		if ( ! $this->is_ready() || ! function_exists( 'pll_languages_list' ) ) {
			return [];
		}
		$slugs   = (array) pll_languages_list( [ 'fields' => 'slug' ] );
		$locales = (array) pll_languages_list( [ 'fields' => 'locale' ] );
		$out     = [];
		foreach ( array_values( $slugs ) as $i => $slug ) {
			$parts                 = explode( '-', str_replace( '_', '-', (string) ( $locales[ $i ] ?? $slug ) ) );
			$out[ (string) $slug ] = strtolower( (string) reset( $parts ) );
		}
		return $out;
	}

	/**
	 * Language slug of a post, '' when none.
	 *
	 * @param int $post_id Post ID.
	 */
	public function post_language( int $post_id ): string {
		if ( ! $this->is_ready() || ! function_exists( 'pll_get_post_language' ) ) {
			return '';
		}
		$lang = pll_get_post_language( $post_id );
		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * Translation group of a post: language slug => post ID (includes the post itself).
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,int>
	 */
	public function translations( int $post_id ): array {
		if ( ! $this->is_ready() || ! function_exists( 'pll_get_post_translations' ) ) {
			return [];
		}
		return array_map( 'intval', (array) pll_get_post_translations( $post_id ) );
	}

	/**
	 * Warm the WordPress post and object-term caches of a batch of posts in one go (TASK-035). Polylang reads post
	 * languages and translation groups through these core caches, so later lookups of the same posts cost no query.
	 * Core cache API only: should Polylang change its storage, the call merely loses its effect. Only ids not yet
	 * cached are fetched.
	 *
	 * @param int[]  $post_ids    Post IDs.
	 * @param string $object_type Object type whose taxonomies are warmed (post type).
	 */
	public function prime_posts( array $post_ids, string $object_type = 'page' ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		if ( [] === $ids || ! $this->is_ready() ) {
			return;
		}
		_prime_post_caches( $ids, false, false );
		update_object_term_cache( $ids, $object_type );
	}

	/**
	 * Warm the language and translation-group caches of a batch of terms in one go (core `update_object_term_cache()` on the object type
	 * `term`, where Polylang stores both): later `term_language()` / `translate_term()` / `TermPair` lookups of those terms cost no query.
	 * Core cache API only; should Polylang change its storage the call merely loses its effect.
	 *
	 * @param int[] $term_ids Term IDs.
	 */
	public function prime_terms( array $term_ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );
		if ( [] !== $ids && $this->is_ready() ) {
			update_object_term_cache( $ids, 'term' );
		}
	}

	/**
	 * Translation of a post in a language (0 when none; the post itself for its own language).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language slug.
	 */
	public function translate_post( int $post_id, string $lang ): int {
		if ( ! $this->is_ready() || '' === $lang || ! function_exists( 'pll_get_post' ) ) {
			return 0;
		}
		return (int) pll_get_post( $post_id, $lang );
	}

	/**
	 * Translation of a term in a language (0 when none).
	 *
	 * @param int    $term_id Term ID.
	 * @param string $lang    Language slug.
	 */
	public function translate_term( int $term_id, string $lang ): int {
		if ( ! $this->is_ready() || '' === $lang || ! function_exists( 'pll_get_term' ) ) {
			return 0;
		}
		return (int) pll_get_term( $term_id, $lang );
	}

	/**
	 * Language slug of a term, '' when none.
	 *
	 * @param int $term_id Term ID.
	 */
	public function term_language( int $term_id ): string {
		if ( ! $this->is_ready() || $term_id <= 0 || ! function_exists( 'pll_get_term_language' ) ) {
			return '';
		}
		$lang = pll_get_term_language( $term_id );
		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * Translation group of a term: language slug => term ID (includes the term itself).
	 *
	 * @param int $term_id Term ID.
	 * @return array<string,int>
	 */
	public function term_translations( int $term_id ): array {
		if ( ! $this->is_ready() || $term_id <= 0 || ! function_exists( 'pll_get_term_translations' ) ) {
			return [];
		}
		return array_map( 'intval', (array) pll_get_term_translations( $term_id ) );
	}

	/**
	 * Home URL of a language WITH trailing slash (`/`, `/ru/`), '' when unknown.
	 *
	 * @param string $lang Language slug.
	 */
	public function home_url( string $lang ): string {
		if ( ! $this->is_ready() || '' === $lang || ! function_exists( 'pll_home_url' ) ) {
			return '';
		}
		return trailingslashit( (string) pll_home_url( $lang ) );
	}

	/**
	 * Set the language of a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language slug.
	 */
	public function set_post_language( int $post_id, string $lang ): void {
		if ( $this->is_ready() && function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $post_id, $lang );
		}
	}

	/**
	 * Link posts as translations of each other.
	 *
	 * @param array<string,int> $group Language slug => post ID.
	 */
	public function save_post_translations( array $group ): void {
		if ( $this->is_ready() && function_exists( 'pll_save_post_translations' ) ) {
			pll_save_post_translations( $group );
		}
	}

	/**
	 * WordPress locale (e.g. `ru_RU`) of a language slug, '' when unknown.
	 *
	 * @param string $lang Language slug.
	 */
	public function locale_of( string $lang ): string {
		if ( ! $this->is_ready() || '' === $lang || ! function_exists( 'pll_languages_list' ) ) {
			return '';
		}
		$slugs   = array_values( (array) pll_languages_list( [ 'fields' => 'slug' ] ) );
		$locales = array_values( (array) pll_languages_list( [ 'fields' => 'locale' ] ) );
		$index   = array_search( $lang, $slugs, true );
		return false === $index ? '' : (string) ( $locales[ $index ] ?? '' );
	}

	/**
	 * Language slug a front-end URL belongs to ('' when it cannot be told, e.g. hidden default prefix).
	 *
	 * @param string $url Absolute URL.
	 */
	public function language_from_url( string $url ): string {
		if ( ! $this->is_ready() || '' === $url || ! function_exists( 'PLL' ) ) {
			return '';
		}
		$pll = PLL();
		if ( ! is_object( $pll ) || ! isset( $pll->links_model ) || ! method_exists( $pll->links_model, 'get_language_from_url' ) ) {
			return '';
		}
		$lang = $pll->links_model->get_language_from_url( $url );
		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * Attachment in a language: the existing media translation, else a new one made through Polylang
	 * (copies file, metadata and alt text as-is). Returns the original id when Polylang cannot translate it.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $lang          Target language slug.
	 */
	public function media_in_language( int $attachment_id, string $lang ): int {
		if ( $attachment_id <= 0 || ! $this->is_ready() || '' === $lang ) {
			return $attachment_id;
		}
		$existing = $this->translate_post( $attachment_id, $lang );
		if ( $existing > 0 ) {
			return $existing;
		}
		$pll = PLL();
		if ( is_object( $pll ) && isset( $pll->model->post ) && method_exists( $pll->model->post, 'create_media_translation' ) ) {
			$created = (int) $pll->model->post->create_media_translation( $attachment_id, $lang );
			if ( $created > 0 ) {
				return $created;
			}
		}
		return $attachment_id;
	}

	/**
	 * Register a translatable string (Polylang "Strings translations" screen).
	 *
	 * @param string $name  Label of the string in the admin list.
	 * @param string $value Source string.
	 * @param string $group Group title.
	 */
	public function register_string( string $name, string $value, string $group ): void {
		if ( $this->is_ready() && function_exists( 'pll_register_string' ) ) {
			pll_register_string( $name, $value, $group );
		}
	}

	/**
	 * Translation of a registered string in the current language (the source string when Polylang is not ready).
	 *
	 * @param string $value Source string.
	 */
	public function translate_string( string $value ): string {
		return $this->is_ready() && function_exists( 'pll__' ) ? (string) pll__( $value ) : $value;
	}
}
