<?php
/**
 * Polylang term pair helper of the catalog module (TASK-043b).
 *
 * Admin-edited term settings (accessory rules, filterable attributes) live on the default-language term only; the other language
 * resolves them through the Polylang translation pair, so there is no second admin entry. Without Polylang every term is its own source.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;

final class TermPair {

	/**
	 * Polylang adapter or null.
	 */
	private static function polylang(): ?Polylang {
		$pll = Plugin::instance()->integration( 'polylang' );
		return $pll instanceof Polylang && $pll->is_ready() ? $pll : null;
	}

	/**
	 * Term id in the default (source) language: the term itself when it is already the source or has no pair.
	 *
	 * @param int $term_id Term id of any language.
	 */
	public static function source( int $term_id ): int {
		$pll = self::polylang();
		if ( null === $pll || $term_id <= 0 ) {
			return $term_id;
		}
		$default = $pll->default_language();
		if ( '' === $default || $pll->term_language( $term_id ) === $default ) {
			return $term_id;
		}
		$source = $pll->translate_term( $term_id, $default );
		return $source > 0 ? $source : $term_id;
	}

	/**
	 * Is the term a non-source translation (read-only admin screen)?
	 *
	 * @param int $term_id Term id.
	 */
	public static function is_translation( int $term_id ): bool {
		return self::source( $term_id ) !== $term_id;
	}

	/**
	 * A source-language term mapped to a language (0 when it has no translation there).
	 *
	 * @param int    $term_id Source term id.
	 * @param string $lang    Language slug ('' = current language of the request).
	 */
	public static function in_language( int $term_id, string $lang = '' ): int {
		$pll = self::polylang();
		if ( null === $pll ) {
			return $term_id;
		}
		$lang = '' === $lang ? $pll->current_language() : $lang;
		if ( '' === $lang || $pll->term_language( $term_id ) === $lang ) {
			return $term_id;
		}
		return $pll->translate_term( $term_id, $lang );
	}
}
