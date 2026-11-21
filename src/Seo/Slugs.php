<?php
/**
 * Latin slugs from Cyrillic titles, at creation time only (ADR-002 s7-8).
 *
 * Fills the slug of NEW terms (`product_cat`, `product_tag`, `pa_*`) and posts (`post`, `page`, `product`) through the
 * transliteration table of the object's language. It acts only when the slug is empty (draft) or contains Cyrillic (WordPress
 * builds a percent-encoded slug from a Cyrillic title; a Cyrillic slug typed by an editor is treated the same way). A latin slug
 * is never touched, so existing URLs, imports and `wp vextra multilang sync-products` keep their slugs.
 *
 * Language (highest priority first):
 * 1. filter `vextra/seo/slug_language` ( string $lang, string $kind `term|post`, array $context ) - the explicit channel for
 *    importers/CLI that know the language (REQUIRED by the import contract when the object is inserted BEFORE Polylang is told
 *    its language, e.g. a RU title made only of letters both alphabets share);
 * 2. `lang` key of the insert arguments (`wp_insert_term( $name, $tax, [ 'lang' => 'ru' ] )`, `wp_insert_post( [ 'lang' => 'ru' ] )`);
 * 3. the admin request value of Polylang (`term_lang_choice` / `post_lang_choice`), whitelisted against the configured languages;
 * 4. the language already stored for the post (block editor auto-drafts);
 * 5. heuristic on the title: `ы э ё ъ` = ru, `і ї є ґ` = uk; 6. the default language.
 * The language is never inferred from the current front-end request.
 *
 * A `-2`/`-ru` style suffix produced by the uniqueness check is logged (Log::warning) so the editor can pick a distinct slug
 * before indexation (docs/seo/ru-category-slugs.md rule 2).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Support\Log;

final class Slugs {

	/**
	 * Post types handled by default.
	 */
	private const POST_TYPES = [ 'post', 'page', 'product' ];

	/**
	 * Taxonomies handled by default (plus every `pa_*`).
	 */
	private const TAXONOMIES = [ 'product_cat', 'product_tag' ];

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	/**
	 * Transliterator.
	 *
	 * @var Transliterator
	 */
	private Transliterator $translit;

	public function __construct( Polylang $polylang, Transliterator $translit ) {
		$this->polylang = $polylang;
		$this->translit = $translit;
	}

	public function register(): void {
		add_filter( 'wp_insert_term_data', [ $this, 'term_data' ], 20, 3 );
		add_filter( 'wp_insert_post_data', [ $this, 'post_data' ], 20, 4 );
	}

	/**
	 * `wp_insert_term_data`: runs for new terms only (updates use `wp_update_term_data`, never hooked).
	 *
	 * @param mixed $data     Term data (`name`, `slug`, `term_group`).
	 * @param mixed $taxonomy Taxonomy.
	 * @param mixed $args     Arguments passed to wp_insert_term().
	 * @return mixed
	 */
	public function term_data( $data, $taxonomy = '', $args = [] ) {
		if ( ! is_array( $data ) || ! is_string( $taxonomy ) || ! $this->handles_taxonomy( $taxonomy ) ) {
			return $data;
		}
		$args = is_array( $args ) ? $args : [];

		$slug = (string) ( $data['slug'] ?? '' );
		$name = (string) ( $data['name'] ?? '' );
		$text = $this->source( $slug, $name );
		if ( '' === $text ) {
			return $data;
		}

		$lang   = $this->language( 'term', $text, $args );
		$result = $this->translit->transliterate( $text, $lang );
		if ( '' === $result ) {
			return $data;
		}

		$unique = wp_unique_term_slug( $result, (object) $args );
		if ( $unique !== $result ) {
			Log::warning(
				'Term slug got a uniqueness suffix, review it before indexation.',
				[
					'taxonomy' => $taxonomy,
					'wanted'   => $result,
					'got'      => $unique,
				],
				'seo'
			);
		}
		$data['slug'] = $unique;
		return $data;
	}

	/**
	 * `wp_insert_post_data`.
	 *
	 * @param mixed $data    Sanitized post data.
	 * @param mixed $postarr Raw arguments of wp_insert_post().
	 * @return mixed
	 */
	public function post_data( $data, $postarr = [] ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$type   = (string) ( $data['post_type'] ?? '' );
		$status = (string) ( $data['post_status'] ?? '' );
		if ( ! in_array( $type, $this->post_types(), true ) || in_array( $status, [ 'auto-draft', 'inherit', 'trash' ], true ) ) {
			return $data;
		}
		$postarr = is_array( $postarr ) ? $postarr : [];

		$text = $this->source( (string) ( $data['post_name'] ?? '' ), (string) ( $data['post_title'] ?? '' ) );
		if ( '' === $text ) {
			return $data;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$lang    = $this->language( 'post', $text, $postarr, $post_id );
		$result  = $this->translit->transliterate( $text, $lang );
		if ( '' === $result ) {
			return $data;
		}

		$unique = wp_unique_post_slug( $result, $post_id, $status, $type, (int) ( $data['post_parent'] ?? 0 ) );
		if ( $unique !== $result ) {
			Log::warning(
				'Post slug got a uniqueness suffix, review it before indexation.',
				[
					'post_type' => $type,
					'wanted'    => $result,
					'got'       => $unique,
				],
				'seo'
			);
		}
		$data['post_name'] = $unique;
		return $data;
	}

	/**
	 * The text to transliterate, '' when the object must be left alone: an existing slug without Cyrillic is final; a slug with
	 * Cyrillic (percent-decoded) or an empty slug next to a Cyrillic title is converted.
	 *
	 * @param string $slug  Slug as WordPress computed it (may be percent-encoded).
	 * @param string $title Name/title.
	 */
	private function source( string $slug, string $title ): string {
		$decoded = rawurldecode( $slug );
		if ( '' !== $decoded ) {
			return $this->translit->has_cyrillic( $decoded ) ? $decoded : '';
		}
		return $this->translit->has_cyrillic( $title ) ? wp_strip_all_tags( $title ) : '';
	}

	/**
	 * Whether a taxonomy is handled.
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	private function handles_taxonomy( string $taxonomy ): bool {
		if ( str_starts_with( $taxonomy, 'pa_' ) ) {
			return true;
		}
		/**
		 * Filters the taxonomies whose NEW terms get a transliterated slug (every `pa_*` is always included).
		 *
		 * @param string[] $taxonomies Taxonomy names.
		 */
		return in_array( $taxonomy, (array) apply_filters( 'vextra/seo/slug_taxonomies', self::TAXONOMIES ), true );
	}

	/**
	 * Post types handled.
	 *
	 * @return string[]
	 */
	private function post_types(): array {
		/**
		 * Filters the post types whose NEW posts get a transliterated slug.
		 *
		 * @param string[] $types Post types.
		 */
		return array_map( 'strval', (array) apply_filters( 'vextra/seo/slug_post_types', self::POST_TYPES ) );
	}

	/**
	 * Language slug used to pick the transliteration table.
	 *
	 * @param string              $kind    `term` or `post`.
	 * @param string              $text    Source text.
	 * @param array<string,mixed> $args    Insert arguments.
	 * @param int                 $post_id Post ID (posts only).
	 */
	private function language( string $kind, string $text, array $args, int $post_id = 0 ): string {
		$known = $this->polylang->is_ready() ? $this->polylang->languages() : [];

		$explicit = '';
		/**
		 * Explicit language of the object being created (highest priority). Return a configured language slug.
		 *
		 * @param string              $lang    Language slug or ''.
		 * @param string              $kind    `term` or `post`.
		 * @param array<string,mixed> $context Insert arguments (+ `ID` for posts).
		 */
		$filtered = apply_filters( 'vextra/seo/slug_language', '', $kind, $args + [ 'ID' => $post_id ] );
		if ( is_string( $filtered ) && '' !== $filtered ) {
			$explicit = sanitize_key( $filtered );
		}

		$candidates = [
			$explicit,
			isset( $args['lang'] ) && is_string( $args['lang'] ) ? sanitize_key( $args['lang'] ) : '',
			$this->request_language( 'term' === $kind ? 'term_lang_choice' : 'post_lang_choice' ),
			$post_id > 0 ? $this->polylang->post_language( $post_id ) : '',
		];
		foreach ( $candidates as $candidate ) {
			if ( '' !== $candidate && ( [] === $known || in_array( $candidate, $known, true ) ) ) {
				return $candidate;
			}
		}

		$guess = $this->translit->guess_language( $text );
		if ( '' !== $guess ) {
			return $guess;
		}
		$default = $this->polylang->default_language();
		return '' !== $default ? $default : 'uk';
	}

	/**
	 * Language chosen in the Polylang admin form (value only selects a transliteration table; no write depends on it).
	 *
	 * @param string $field Request field.
	 */
	private function request_language( string $field ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only table choice, validated against the configured languages.
		$value = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_key( wp_unslash( $_POST[ $field ] ) ) : '';
		return $value;
	}
}
