<?php
/**
 * Organization JSON-LD: real `sameAs`, `contactPoint`, `url` and `logo` only, identical in every language.
 *
 * Rank Math builds ONE Organization node (`publisher`, `@id` = `<home>/#organization`) on every page; this class enriches that
 * node, it never adds a second one. The data comes from filter `vextra/seo/organization_data` (the plugin stays theme-agnostic;
 * the active theme answers it, an empty array adds nothing):
 *
 *   [ 'same_as' => string[] profile URLs, 'telephone' => string E.164, 'email' => string, 'logo' => string image URL ]
 *
 * Everything is sanitized again here: `sameAs` http(s) only and de-duplicated against the profiles already configured in Rank Math
 * (`viber://` and other schemes are dropped), telephone must be E.164, email a valid address. Phone and email go inside one
 * `ContactPoint` (`customer service`, `areaServed` UA, `availableLanguage` = configured languages). Logo falls back to the site
 * icon (core). Demo values are excluded by the provider (theme), never reaching this class.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;

final class Organization {

	/**
	 * Rank Math adapter.
	 *
	 * @var RankMath
	 */
	private RankMath $rank_math;

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	/**
	 * Provider answer, memoised per request.
	 *
	 * @var array{same_as:string[],telephone:string,email:string,logo:string}|null
	 */
	private ?array $data = null;

	public function __construct( RankMath $rank_math, Polylang $polylang ) {
		$this->rank_math = $rank_math;
		$this->polylang  = $polylang;
	}

	public function register(): void {
		$this->rank_math->filter_json_ld( [ $this, 'filter' ] );
	}

	/**
	 * `rank_math/json_ld`: enrich `$data['publisher']` when it is an Organization.
	 *
	 * @param mixed $graph JSON-LD nodes keyed by Rank Math.
	 * @return mixed
	 */
	public function filter( $graph ) {
		if ( ! is_array( $graph ) || ! isset( $graph['publisher'] ) || ! is_array( $graph['publisher'] ) || ! $this->is_organization( $graph['publisher'] ) ) {
			return $graph;
		}

		$org  = $graph['publisher'];
		$data = $this->data();
		$home = trailingslashit( (string) get_option( 'home' ) );

		if ( empty( $org['url'] ) ) {
			$org['url'] = $home;
		}

		$same_as = [];
		foreach ( array_merge( (array) ( $org['sameAs'] ?? [] ), $data['same_as'] ) as $url ) {
			$url = is_string( $url ) ? esc_url_raw( $url, [ 'https', 'http' ] ) : '';
			if ( '' !== $url ) {
				$same_as[ strtolower( untrailingslashit( $url ) ) ] = $url;
			}
		}
		if ( [] !== $same_as ) {
			$org['sameAs'] = array_values( $same_as );
		} else {
			unset( $org['sameAs'] );
		}

		if ( '' !== $data['telephone'] || '' !== $data['email'] ) {
			$points = isset( $org['contactPoint'] ) && is_array( $org['contactPoint'] ) ? $org['contactPoint'] : [];
			$points = isset( $points['@type'] ) ? [ $points ] : array_values( $points );
			if ( ! $this->has_phone( $points, $data['telephone'] ) ) {
				$point = [
					'@type'       => 'ContactPoint',
					'contactType' => 'customer service',
					'areaServed'  => 'UA',
				];
				if ( '' !== $data['telephone'] ) {
					$point['telephone'] = $data['telephone'];
				}
				if ( '' !== $data['email'] ) {
					$point['email'] = $data['email'];
				}
				$languages = array_values( array_unique( array_values( $this->polylang->locale_codes() ) ) );
				if ( [] !== $languages ) {
					$point['availableLanguage'] = $languages;
				}
				$points[] = $point;
			}
			$org['contactPoint'] = $points;
		}

		if ( empty( $org['logo'] ) && '' !== $data['logo'] ) {
			$org['logo'] = [
				'@type' => 'ImageObject',
				'@id'   => $home . '#logo',
				'url'   => $data['logo'],
			];
		}

		$graph['publisher'] = $org;
		return $graph;
	}

	/**
	 * Whether the node is an Organization (type string or list).
	 *
	 * @param array<string,mixed> $node JSON-LD node.
	 */
	private function is_organization( array $node ): bool {
		$types = (array) ( $node['@type'] ?? [] );
		return in_array( 'Organization', $types, true );
	}

	/**
	 * Whether a contact point list already carries the telephone.
	 *
	 * @param array<int,mixed> $points    Contact points.
	 * @param string           $telephone Telephone ('' = nothing to compare, matched when any point has an email only).
	 */
	private function has_phone( array $points, string $telephone ): bool {
		if ( '' === $telephone ) {
			return false;
		}
		$digits = preg_replace( '/\D+/', '', $telephone );
		foreach ( $points as $point ) {
			if ( is_array( $point ) && isset( $point['telephone'] ) && preg_replace( '/\D+/', '', (string) $point['telephone'] ) === $digits ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sanitized provider data.
	 *
	 * @return array{same_as:string[],telephone:string,email:string,logo:string}
	 */
	private function data(): array {
		if ( null !== $this->data ) {
			return $this->data;
		}

		/**
		 * Real public data of the organization (provided by the theme). Demo/placeholder values must NOT be returned.
		 *
		 * @param array $data { same_as: string[], telephone: string (E.164), email: string, logo: string (image URL) }.
		 */
		$raw = apply_filters( 'vextra/seo/organization_data', [] );
		$raw = is_array( $raw ) ? $raw : [];

		$same_as = [];
		foreach ( (array) ( $raw['same_as'] ?? [] ) as $url ) {
			$url = is_string( $url ) ? esc_url_raw( $url, [ 'https', 'http' ] ) : '';
			if ( '' !== $url ) {
				$same_as[] = $url;
			}
		}

		$phone = isset( $raw['telephone'] ) && is_string( $raw['telephone'] ) ? trim( $raw['telephone'] ) : '';
		$phone = 1 === preg_match( '/^\+[1-9][0-9]{6,14}$/', $phone ) ? $phone : '';

		$email = isset( $raw['email'] ) && is_string( $raw['email'] ) ? sanitize_email( $raw['email'] ) : '';

		$logo = isset( $raw['logo'] ) && is_string( $raw['logo'] ) ? esc_url_raw( $raw['logo'], [ 'https', 'http' ] ) : '';
		if ( '' === $logo && function_exists( 'get_site_icon_url' ) ) {
			$logo = esc_url_raw( (string) get_site_icon_url( 512 ), [ 'https', 'http' ] );
		}

		$this->data = [
			'same_as'   => $same_as,
			'telephone' => $phone,
			'email'     => $email,
			'logo'      => $logo,
		];
		return $this->data;
	}
}
