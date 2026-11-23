<?php
/**
 * XML-RPC fully off (SEC-013, decision D3).
 *
 * - A request that reaches `xmlrpc.php` (constant `XMLRPC_REQUEST`) is answered 403 with no body, whatever the method
 *   (`system.listMethods`, `wp.getUsersBlogs`, `pingback.ping`, GET, HEAD, ...).
 * - `xmlrpc_enabled` false, `xmlrpc_methods` empty, no `X-Pingback` header, no RSD / Windows Live Writer links in `<head>`.
 * The server-level deny of `xmlrpc.php` stays a hosting task (defence in depth).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Security;

final class XmlRpc {

	public function register(): void {
		add_filter( 'xmlrpc_enabled', '__return_false', 99 );
		add_filter( 'xmlrpc_methods', '__return_empty_array', 99 );
		add_filter( 'wp_headers', [ $this, 'drop_pingback_header' ], 99 );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			$this->deny();
		}
	}

	/**
	 * `wp_headers`: no `X-Pingback`.
	 *
	 * @param mixed $headers Response headers.
	 * @return mixed
	 */
	public function drop_pingback_header( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	/**
	 * 403, no detail, stop.
	 */
	private function deny(): never {
		if ( ! headers_sent() ) {
			status_header( 403 );
			nocache_headers();
			header( 'X-Robots-Tag: noindex' );
		}
		exit;
	}
}
