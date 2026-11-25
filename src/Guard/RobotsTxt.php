<?php
/**
 * Robots.txt rules (ADR-007 L1, SEO-approved list of TASK-028).
 *
 * Works on the final text of the core `robots_txt` filter at priority 20, i.e. AFTER Rank Math: whether Rank Math prints
 * its own editor content (its filter, priority 10) or the WordPress default, the rules are merged into the existing
 * `User-agent: *` group (never a per-bot group: a specific group would REPLACE `*`). Existing lines, `Allow:` lines and
 * every `Sitemap:` line stay; rules already present are not repeated. A site with `blog_public = 0` is left untouched.
 * The `robots_txt` filter is WordPress core, so no adapter is needed.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Guard;

final class RobotsTxt {

	/**
	 * The approved rule list (one source). Filter `vextra/core/guard/robots_rules` adjusts it.
	 */
	public const RULES = [
		'Disallow: /cart/',
		'Disallow: /checkout/',
		'Disallow: /my-account/',
		'Disallow: /ru/cart/',
		'Disallow: /ru/checkout/',
		'Disallow: /ru/my-account/',
		'Disallow: /track-order/',
		'Disallow: /ru/otslezhivanie-zakaza/',
		'Disallow: /*?*add-to-cart=',
		'Disallow: /*?*wc-ajax=',
		'Disallow: /*?s=',
		'Disallow: /*&s=',
		'Disallow: /*?*orderby=',
		'Disallow: /*?*filter_',
		'Disallow: /*?*min_price=',
		'Disallow: /*?*max_price=',
		'Disallow: /*?*rating_filter=',
		'Disallow: /*?*product_cat=',
		'Disallow: /search/',
		'Disallow: /ru/search/',
		'Disallow: /wp-json/wc/store/',
	];

	public function register(): void {
		add_filter( 'robots_txt', [ $this, 'filter' ], 20, 2 );
	}

	/**
	 * Merge the rules into the `*` group.
	 *
	 * @param mixed $output Current robots.txt text.
	 * @param mixed $is_public Value of `blog_public` (0 = private site).
	 * @return mixed
	 */
	public function filter( $output, $is_public = 1 ) {
		if ( ! is_string( $output ) || 0 === (int) $is_public ) {
			return $output;
		}

		return Failsafe::run(
			'robots',
			fn(): string => $this->merge( $output, $this->rules() ),
			$output
		);
	}

	/**
	 * Clean, filtered rule lines.
	 *
	 * @return string[]
	 */
	private function rules(): array {
		/**
		 * Filters the robots.txt rule lines added to the `User-agent: *` group.
		 *
		 * @param string[] $rules Lines such as `Disallow: /cart/`.
		 */
		$rules = apply_filters( 'vextra/core/guard/robots_rules', self::RULES );
		$out   = [];
		foreach ( (array) $rules as $rule ) {
			$rule = trim( preg_replace( '/[\r\n]+/', ' ', (string) $rule ) ?? '' );
			if ( 1 === preg_match( '/^(Disallow|Allow):\s*\S/i', $rule ) ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/**
	 * Insert missing rules after the last directive of the first `User-agent: *` group (or append a new group).
	 *
	 * @param string   $text  robots.txt text.
	 * @param string[] $rules Rule lines.
	 */
	private function merge( string $text, array $rules ): string {
		$lines = preg_split( '/\R/', $text );
		$lines = false === $lines ? [ $text ] : $lines;
		$start = null;
		foreach ( $lines as $i => $line ) {
			if ( 1 === preg_match( '/^\s*user-agent:\s*\*\s*$/i', $line ) ) {
				$start = $i;
				break;
			}
		}

		if ( null === $start ) {
			$block = array_merge( [ '', 'User-agent: *' ], $rules );
			return rtrim( $text ) . "\n" . implode( "\n", $block ) . "\n";
		}

		$last = $start;
		$have = [];
		$n    = count( $lines );
		for ( $i = $start + 1; $i < $n; $i++ ) {
			$line = trim( $lines[ $i ] );
			if ( '' === $line || 1 === preg_match( '/^(user-agent|sitemap):/i', $line ) ) {
				break;
			}
			$last   = $i;
			$have[] = strtolower( preg_replace( '/\s+/', ' ', $line ) ?? $line );
		}

		$add = [];
		foreach ( $rules as $rule ) {
			if ( ! in_array( strtolower( preg_replace( '/\s+/', ' ', $rule ) ?? $rule ), $have, true ) ) {
				$add[] = $rule;
			}
		}
		if ( array() === $add ) {
			return $text;
		}

		array_splice( $lines, $last + 1, 0, $add );
		return implode( "\n", $lines );
	}
}
