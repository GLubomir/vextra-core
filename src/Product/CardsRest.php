<?php
/**
 * Read-only REST route `GET /wp-json/vextra/v1/cards?ids=1,2,3[&lang=ru]` (TASK-044a): server-rendered `product-card` markup of the given
 * products for the "recently viewed" strip (ids live in the visitor's localStorage).
 *
 * - `ids`: 1 to 12 positive integers (regex), de-duplicated and sorted ascending; 13 or malformed ids answer a generic 400.
 * - Every id is mapped to the twin of the REQUEST language (`lang`, whitelisted against Polylang, default the current one); a product without
 *   that twin is skipped. Unknown, trashed, draft, password-protected and hidden-visibility products are skipped silently (never leaked) and
 *   `Listable::renderable` drops products that turned unlisted since the last cache rebuild.
 * - Response `{ items: [ { id, html } ], lang }`, html = the grid `product-card` (same renderer as the Recommend route: `Support\CardMarkup`);
 *   no `add-to-cart=` URL, no attribution field.
 * - `Cache-Control: public, max-age=60` on 200 (set late on `rest_post_dispatch`, after core's nocache headers), `no-store` on errors. Core adds
 *   `X-Robots-Tag: noindex`. Rate limit: the Guard `rest` bucket already counts every anonymous REST route (429 + `Retry-After`).
 * - The route string appears in no HTML, sitemap or robots.txt line.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Product;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;
use Vextra\Core\Recommend\Pool;
use Vextra\Core\Support\CardMarkup;
use Vextra\Core\Support\Listable;

final class CardsRest {

	public const NAMESPACE = 'vextra/v1';
	public const ROUTE     = '/cards';
	public const MAX_IDS   = 12;

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
		add_filter( 'rest_post_dispatch', [ $this, 'headers' ], 999, 3 );
	}

	public function register_route(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true', // Public read: card markup of published, listable products, nothing personal.
				'args'                => [
					'ids'  => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,9}(,[1-9][0-9]{0,9}){0,11}$/', $value ),
					],
					'lang' => [
						'type'              => 'string',
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && 1 === preg_match( '/^[a-z]{2,3}(-[a-z]{2,4})?$/', $value ),
					],
				],
			]
		);
	}

	/**
	 * Cache headers of this route: public on 200, never cached on errors.
	 *
	 * @param mixed $response Response.
	 * @param mixed $server   Server.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public function headers( $response, $server = null, $request = null ) {
		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request || self::NAMESPACE . self::ROUTE !== ltrim( $request->get_route(), '/' ) ) {
			return $response;
		}
		$response->header( 'Cache-Control', 200 === $response->get_status() ? 'public, max-age=60' : 'no-store' );
		return $response;
	}

	/**
	 * Handle a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$pll  = Plugin::instance()->integration( 'polylang' );
		$lang = Pool::current_language();
		$ask  = $request->get_param( 'lang' );
		if ( null !== $ask ) {
			if ( ! is_string( $ask ) || ! $pll instanceof Polylang || ! $pll->is_ready() || ! in_array( $ask, $pll->languages(), true ) ) {
				return new \WP_Error( 'vx_cards_bad_request', 'Invalid request.', [ 'status' => 400 ] );
			}
			$lang = $ask;
		}

		$ids = array_values( array_unique( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) ) );
		sort( $ids );
		if ( array() === $ids || count( $ids ) > self::MAX_IDS ) {
			return new \WP_Error( 'vx_cards_bad_request', 'Invalid request.', [ 'status' => 400 ] );
		}

		$mapped = [];
		foreach ( $ids as $id ) {
			$twin = Pool::twin( $id, $lang );
			if ( $twin > 0 ) {
				$mapped[ $twin ] = $twin;
			}
		}
		$mapped = array_values( $mapped );
		$facts  = Pool::light( $mapped );
		$keep   = [];
		foreach ( $mapped as $id ) {
			$row = $facts[ $id ] ?? [];
			if ( array() !== $row && 'publish' === $row['status'] && empty( $row['hidden'] ) && empty( $row['protected'] ) ) {
				$keep[] = $id;
			}
		}
		$keep  = Listable::renderable( $keep );
		$items = array_map( static fn( int $id ): array => [ 'id' => $id ], $keep );

		$response = new \WP_REST_Response(
			[
				'items' => CardMarkup::attach( $items, $lang ),
				'lang'  => $lang,
			]
		);
		return $response;
	}
}
