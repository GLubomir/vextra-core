<?php
/**
 * Dev module: per-request public SEO preview (TASK-032).
 *
 * A request carrying a valid `X-VX-Public-Preview` header behaves as if `blog_public=1` (index robots, canonical,
 * sitemaps, robots.txt Sitemap line) without writing any option. Inert unless the environment is `local` and
 * `VX_PUBLIC_PREVIEW_TOKEN` (>= 32 chars) is defined in wp-config.php. Never define the constant in production.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Dev;

use Vextra\Core\Contracts\ModuleInterface;

final class Dev implements ModuleInterface {

	private const MIN_TOKEN_LENGTH  = 32;
	private const MAX_HEADER_LENGTH = 128;

	/**
	 * Environment type resolver (test hook only).
	 *
	 * @var callable|null
	 */
	private $env;

	/**
	 * Match result cached per request.
	 *
	 * @var bool|null
	 */
	private ?bool $matched = null;

	/**
	 * Optional environment resolver.
	 *
	 * @param callable|null $env Returns the environment type; defaults to wp_get_environment_type (tests only).
	 */
	public function __construct( ?callable $env = null ) {
		$this->env = $env;
	}

	public function id(): string {
		return 'dev';
	}

	public function is_enabled(): bool {
		$env = null === $this->env ? wp_get_environment_type() : ( $this->env )();
		if ( 'local' !== $env ) {
			return false;
		}
		return defined( 'VX_PUBLIC_PREVIEW_TOKEN' )
			&& is_string( VX_PUBLIC_PREVIEW_TOKEN )
			&& strlen( VX_PUBLIC_PREVIEW_TOKEN ) >= self::MIN_TOKEN_LENGTH;
	}

	public function register(): void {
		if ( ! $this->is_enabled() || ! $this->request_matches() ) {
			return;
		}

		add_filter( 'pre_option_blog_public', [ $this, 'public_option' ] );
		// Rank Math memoises this per request: the filter must exist before its first sitemap code runs.
		add_filter( 'rank_math/sitemap/enable_caching', '__return_false' );
		header_register_callback( [ $this, 'send_headers' ] );
	}

	/**
	 * Forced value of the `blog_public` option for this request.
	 *
	 * @return int
	 */
	public function public_option() {
		return 1;
	}

	public function send_headers(): void {
		header( 'X-VX-Public-Preview: active', true );
		header( 'Cache-Control: no-store, max-age=0', true );
		header( 'Pragma: no-cache', true );
	}

	/**
	 * Valid header on a GET/HEAD front-end request (not admin, not CLI).
	 */
	private function request_matches(): bool {
		if ( null !== $this->matched ) {
			return $this->matched;
		}
		$this->matched = false;

		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || is_admin() || ! isset( $_SERVER['HTTP_X_VX_PUBLIC_PREVIEW'], $_SERVER['REQUEST_METHOD'] ) ) {
			return false;
		}
		if ( ! in_array( $_SERVER['REQUEST_METHOD'], [ 'GET', 'HEAD' ], true ) ) {
			return false;
		}
		$given = wp_unslash( $_SERVER['HTTP_X_VX_PUBLIC_PREVIEW'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never output.
		if ( ! is_string( $given ) || '' === $given || strlen( $given ) > self::MAX_HEADER_LENGTH ) {
			return false;
		}
		$this->matched = hash_equals( (string) VX_PUBLIC_PREVIEW_TOKEN, $given );
		return $this->matched;
	}
}
