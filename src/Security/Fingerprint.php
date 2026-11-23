<?php
/**
 * Generator / exact-version leaks (SEC-019).
 *
 * - No `<meta name="generator">` (WordPress, and WooCommerce through the same core filter), no generator element in feeds,
 *   no shortlink `<link>` / `Link: rel=shortlink` header.
 * - `?ver=` of core (`wp-includes`, `wp-admin` assets shown on wp-login.php) and plugin assets is replaced by an opaque, stable token
 *   `substr( hmac_sha256( version, wp_salt() ), 0, 10 )`: cache busting still changes with every version, the version itself is
 *   not readable. Theme assets (`dist/`, hash-named) are not touched; the parameter is never removed.
 * No output buffering, no string replacement on HTML, no plugin file edited.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

use Vextra\Core\Guard\Failsafe;

final class Fingerprint {

	private const GENERATOR_TYPES = [ 'html', 'xhtml', 'atom', 'rss2', 'rdf', 'comment', 'export' ];

	/**
	 * Asset URL prefixes (scheme-less) whose version is tokenised; built once per request.
	 *
	 * @var string[]|null
	 */
	private ?array $prefixes = null;

	public function register(): void {
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		add_filter( 'the_generator', '__return_empty_string', 99 );
		foreach ( self::GENERATOR_TYPES as $type ) {
			add_filter( 'get_the_generator_' . $type, '__return_empty_string', 99 );
		}

		add_filter( 'script_loader_src', [ $this, 'token_src' ], 99, 1 );
		add_filter( 'style_loader_src', [ $this, 'token_src' ], 99, 1 );
		add_filter( 'script_module_loader_src', [ $this, 'token_src' ], 99, 1 );
	}

	/**
	 * Opaque token of a version string.
	 *
	 * @param string $version Version as WordPress / the plugin registered it.
	 */
	public static function token( string $version ): string {
		return substr( hash_hmac( 'sha256', $version, wp_salt() ), 0, 10 );
	}

	/**
	 * `script_loader_src` / `style_loader_src` / `script_module_loader_src`.
	 *
	 * @param mixed $src Asset URL.
	 * @return mixed
	 */
	public function token_src( $src ) {
		if ( ! is_string( $src ) || ! str_contains( $src, 'ver=' ) ) {
			return $src;
		}
		return Failsafe::run(
			'ver-token',
			function () use ( $src ): string {
				$bare = (string) preg_replace( '#^https?:#i', '', $src );
				$ours = false;
				foreach ( $this->prefixes() as $prefix ) {
					if ( str_starts_with( $bare, $prefix ) ) {
						$ours = true;
						break;
					}
				}
				if ( ! $ours ) {
					return $src;
				}
				$query = wp_parse_url( $src, PHP_URL_QUERY );
				if ( ! is_string( $query ) ) {
					return $src;
				}
				parse_str( $query, $args );
				if ( ! isset( $args['ver'] ) || ! is_string( $args['ver'] ) || '' === $args['ver'] ) {
					return $src;
				}
				return add_query_arg( 'ver', self::token( $args['ver'] ), $src );
			},
			$src
		);
	}

	/**
	 * Scheme-less URL prefixes of core includes, core admin assets and plugin assets.
	 *
	 * @return string[]
	 */
	private function prefixes(): array {
		if ( null === $this->prefixes ) {
			$this->prefixes = [
				(string) preg_replace( '#^https?:#i', '', includes_url() ),
				(string) preg_replace( '#^https?:#i', '', plugins_url() . '/' ),
				(string) preg_replace( '#^https?:#i', '', admin_url() ), // wp-login.php loads wp-admin/css|js?ver=<WordPress version>.
			];
		}
		return $this->prefixes;
	}
}
