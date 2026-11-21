<?php
/**
 * Cyrillic -> latin slug transliteration (pure, no WordPress calls).
 *
 * Two fixed tables (a slug is a permanent URL, ADR-002 s7-8; changing a table later needs a mass 301):
 *
 * UA = Cabinet of Ministers resolution No. 55 (2010):
 * - letters: а a, б b, в v, г h, ґ g, д d, е e, ж zh, з z, и y, і i, к k, л l, м m, н n, о o, п p, р r, с s, т t, у u, ф f,
 *   х kh, ц ts, ч ch, ш sh, щ shch; ь and apostrophes are dropped.
 * - positional: є ye|ie, ї yi|i, й y|i, ю yu|iu, я ya|ia (first form at the start of a word, second form inside it; a word
 *   starts at the beginning of the text or after any character that is not a letter, digit or apostrophe).
 * - the pair `зг` is written `zgh` (not `zh`).
 *
 * RU = the registry table of docs/seo/ru-category-slugs.md: ё e, й y, ы y, э e, ъ and ь dropped, х kh, ц ts, ч ch, ш sh,
 * щ shch, ю yu, я ya, ж zh, и i (no positional rules). Ukrainian-only letters met in a RU text fall back to the UA letters.
 *
 * Result: lowercase, `[a-z0-9-]` only, single hyphens, no leading/trailing hyphen, at most `$max` characters cut at a hyphen
 * boundary (a single word longer than `$max` is cut hard). Latin letters and digits are kept as typed (lowercased); every
 * other character is a word separator. Empty result = nothing usable in the input (the caller keeps WordPress' fallback).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

final class Transliterator {

	public const DEFAULT_MAX = 60;

	/**
	 * Letters shared by both tables (lowercase).
	 */
	private const COMMON = [
		'а' => 'a',
		'б' => 'b',
		'в' => 'v',
		'д' => 'd',
		'ж' => 'zh',
		'з' => 'z',
		'к' => 'k',
		'л' => 'l',
		'м' => 'm',
		'н' => 'n',
		'о' => 'o',
		'п' => 'p',
		'р' => 'r',
		'с' => 's',
		'т' => 't',
		'у' => 'u',
		'ф' => 'f',
		'х' => 'kh',
		'ц' => 'ts',
		'ч' => 'ch',
		'ш' => 'sh',
		'щ' => 'shch',
		'ь' => '',
		'ъ' => '',
		'’' => '',
		'ʼ' => '',
		"'" => '',
		'`' => '',
		'´' => '',
	];

	/**
	 * UA specific letters: [ inside a word, start of a word ].
	 */
	private const UA = [
		'г' => [ 'h', 'h' ],
		'ґ' => [ 'g', 'g' ],
		'е' => [ 'e', 'e' ],
		'є' => [ 'ie', 'ye' ],
		'и' => [ 'y', 'y' ],
		'і' => [ 'i', 'i' ],
		'ї' => [ 'i', 'yi' ],
		'й' => [ 'i', 'y' ],
		'ю' => [ 'iu', 'yu' ],
		'я' => [ 'ia', 'ya' ],
		'ы' => [ 'y', 'y' ],
		'э' => [ 'e', 'e' ],
		'ё' => [ 'e', 'e' ],
	];

	/**
	 * RU specific letters (no positional forms).
	 */
	private const RU = [
		'г' => 'g',
		'е' => 'e',
		'ё' => 'e',
		'и' => 'i',
		'й' => 'y',
		'ы' => 'y',
		'э' => 'e',
		'ю' => 'yu',
		'я' => 'ya',
		'і' => 'i',
		'ї' => 'yi',
		'є' => 'ye',
		'ґ' => 'g',
	];

	/**
	 * Transliterate text into a slug.
	 *
	 * @param string $text Source text (any case, may mix scripts).
	 * @param string $lang `ru` selects the RU table, anything else the UA table.
	 * @param int    $max  Maximum length of the slug.
	 */
	public function transliterate( string $text, string $lang = 'uk', int $max = self::DEFAULT_MAX ): string {
		$text = $this->lower( $text );
		if ( '' === $text ) {
			return '';
		}

		$ru     = 'ru' === strtolower( $lang );
		$chars  = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$chars  = false === $chars ? [] : $chars;
		$count  = count( $chars );
		$out    = '';
		$starts = true; // The next letter starts a word.

		for ( $i = 0; $i < $count; $i++ ) {
			$ch = $chars[ $i ];

			if ( ! $ru && 'з' === $ch && ( $chars[ $i + 1 ] ?? '' ) === 'г' ) {
				$out   .= 'zgh';
				$starts = false;
				++$i;
				continue;
			}

			if ( isset( self::COMMON[ $ch ] ) ) {
				$out .= self::COMMON[ $ch ];
				// An apostrophe sits inside a word: it neither ends it nor starts a new one.
				if ( ! in_array( $ch, [ "'", '’', 'ʼ', '`', '´' ], true ) ) {
					$starts = false;
				}
				continue;
			}

			if ( $ru && isset( self::RU[ $ch ] ) ) {
				$out   .= self::RU[ $ch ];
				$starts = false;
				continue;
			}

			if ( ! $ru && isset( self::UA[ $ch ] ) ) {
				$out   .= self::UA[ $ch ][ $starts ? 1 : 0 ];
				$starts = false;
				continue;
			}

			if ( 1 === preg_match( '/^[a-z0-9]$/', $ch ) ) {
				$out   .= $ch;
				$starts = false;
				continue;
			}

			// Anything else is a separator.
			$out   .= '-';
			$starts = true;
		}

		return $this->finish( $out, $max );
	}

	/**
	 * Whether the text contains at least one Cyrillic letter.
	 *
	 * @param string $text Text.
	 */
	public function has_cyrillic( string $text ): bool {
		return 1 === preg_match( '/\p{Cyrillic}/u', $text );
	}

	/**
	 * Guess the language of a text by letters that exist in one alphabet only: `ы э ё ъ` = ru, `і ї є ґ` = uk,
	 * '' when undecidable. Used only when no explicit language is known.
	 *
	 * @param string $text Text.
	 */
	public function guess_language( string $text ): string {
		$text = $this->lower( $text );
		if ( 1 === preg_match( '/[іїєґ]/u', $text ) ) {
			return 'uk';
		}
		if ( 1 === preg_match( '/[ыэёъ]/u', $text ) ) {
			return 'ru';
		}
		return '';
	}

	/**
	 * Unicode-aware lowercase.
	 *
	 * @param string $text Text.
	 */
	private function lower( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Collapse hyphens and enforce the length at a hyphen boundary.
	 *
	 * @param string $slug Raw result.
	 * @param int    $max  Maximum length.
	 */
	private function finish( string $slug, int $max ): string {
		$slug = trim( (string) preg_replace( '/-{2,}/', '-', $slug ), '-' );
		$max  = max( 1, $max );
		if ( strlen( $slug ) <= $max ) {
			return $slug;
		}

		$cut = substr( $slug, 0, $max );
		if ( '-' !== $slug[ $max ] ) {
			$pos = strrpos( $cut, '-' );
			if ( false !== $pos && $pos > 0 ) {
				$cut = substr( $cut, 0, $pos );
			}
		}
		return trim( $cut, '-' );
	}
}
