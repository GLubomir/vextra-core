<?php
/**
 * No staff login / display name in the sharing meta (SEC-044).
 *
 * Rank Math prints "enhanced data" pairs as `twitter:label<N>` / `twitter:data<N>`; for a singular page (incl. a static front
 * page) one pair is "Written by" = the post author's display name, which on a default install is the login of an
 * administrator. This service removes ONLY that pair (and, on an author archive, the "Name" pair) through the public filter
 * `rank_math/opengraph/slack_enhanced_data`, via the RankMath adapter. `og:*`, the other `twitter:*` tags (card, title,
 * description, image, reading time), `article:author` and the JSON-LD are untouched. When a public blog byline is needed later
 * (display name != login, launch checklist) the same filter can let the pair through for `post` only.
 * Rank Math missing = nothing registered.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;

final class AuthorExposure {

	public function register(): void {
		$rank = Plugin::instance()->integration( 'rankmath' );
		if ( $rank instanceof RankMath && $rank->is_available() ) {
			$rank->filter_enhanced_data( [ $this, 'filter' ] );
		}
	}

	/**
	 * Drop the author pair(s): the pair labelled "Written by" (Rank Math's own, translated string) and any pair whose value is
	 * the login, nicename or display name of the author of the current page / author archive.
	 *
	 * @param mixed $data `label => value` pairs.
	 * @return mixed
	 */
	public function filter( $data ) {
		if ( ! is_array( $data ) || array() === $data ) {
			return $data;
		}
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Rank Math's own label, looked up in its text domain.
		$label  = __( 'Written by', 'seo-by-rank-math' );
		$names  = $this->author_names();
		$result = [];
		foreach ( $data as $key => $value ) {
			$text = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( (string) $key === $label || 'Written by' === (string) $key || ( '' !== $text && in_array( strtolower( $text ), $names, true ) ) ) {
				continue;
			}
			$result[ $key ] = $value;
		}
		return $result;
	}

	/**
	 * Lower-cased login, nicename and display name of the page author / queried author.
	 *
	 * @return string[]
	 */
	private function author_names(): array {
		$user = false;
		if ( is_author() ) {
			$object = get_queried_object();
			$user   = $object instanceof \WP_User ? $object : false;
		} elseif ( is_singular() ) {
			$user = get_userdata( (int) get_post_field( 'post_author', get_queried_object_id() ) );
		}
		if ( ! $user instanceof \WP_User ) {
			return [];
		}
		$names = [];
		foreach ( [ $user->user_login, $user->user_nicename, $user->display_name ] as $name ) {
			if ( is_string( $name ) && '' !== trim( $name ) ) {
				$names[] = strtolower( trim( $name ) );
			}
		}
		return array_values( array_unique( $names ) );
	}
}
