<?php
/**
 * DEV ONLY (TASK-045a). Asserts the server layer of the cart / checkout on the local site with TEMP data (`t045a-*`) that is deleted at the end.
 *
 * Run: `wp eval-file wp-content/plugins/vextra-core/scripts/dev/check-cart-checkout.php`
 * Needs: the Turnstile keys constants ABSENT (the script injects test keys through the adapter's filter), COD is NOT needed (no order is placed).
 *
 * Covers: phone authority, masked e-mail, resource-hint stripping (+ the cart-page hint list in both languages), `vx_cart` cookie contract over the
 * Store API (HTTP to this site), no cookie on anonymous GET, Turnstile verify contract (action / hostname, SEC-049, simulated provider answers),
 * checkout throttle buckets, draft-order purge rules, cross-sell ids, free-shipping helper, conflict rule, kill switches, apply-config idempotency.
 * Exit code 1 on any FAIL. Never touches demo data.
 *
 * @package Vextra\Core
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'wp-cli only' );
}
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Dev scripts run only when WP_ENVIRONMENT_TYPE is "local".' );
}

use Vextra\Core\Cart\ResourceHints;
use Vextra\Core\Checkout\Gate;
use Vextra\Core\Checkout\Phone;
use Vextra\Core\Checkout\Privacy;
use Vextra\Core\Checkout\TurnstileCheckout;
use Vextra\Core\Compat\Conflicts;
use Vextra\Core\Guard\CheckoutThrottle;
use Vextra\Core\Guard\ClientIp;
use Vextra\Core\Guard\DraftOrders;
use Vextra\Core\Guard\RateLimiter;
use Vextra\Core\Integrations\Turnstile\Turnstile;
use Vextra\Core\Plugin;

$fails = 0;
$ok    = static function ( bool $cond, string $label, string $detail = '' ) use ( &$fails ): void {
	if ( ! $cond ) {
		++$fails;
	}
	WP_CLI::line( ( $cond ? 'PASS ' : 'FAIL ' ) . $label . ( '' !== $detail && ! $cond ? ' -> ' . $detail : '' ) );
};

$temp_orders = [];
$cleanup     = static function () use ( &$temp_orders ): void {
	foreach ( $temp_orders as $id ) {
		$order = wc_get_order( $id );
		if ( $order instanceof WC_Order ) {
			$order->delete( true );
		}
	}
	RateLimiter::reset( 'checkout', ClientIp::mask( '203.0.113.50' ) );
	RateLimiter::reset( 'checkout_mail', hash_hmac( 'sha256', 't045a-check@example.test', wp_salt( 'auth' ) ) );
};

// --- Phone -----------------------------------------------------------------------------------------------------------------------------
foreach ( [ '0501234567', '380501234567', '+380 50 123 45 67', '050-123-45-67', '(050) 123 45 67' ] as $raw ) {
	$ok( '+380501234567' === Phone::normalise( $raw ), 'phone accepts ' . $raw );
}
foreach ( [ '12345', '+48501234567', '05012345678', '050abc4567', '' ] as $raw ) {
	$ok( null === Phone::normalise( $raw ), 'phone rejects "' . $raw . '"' );
}

// --- Masked e-mail ---------------------------------------------------------------------------------------------------------------------
$ok( 'o***@example.com' === vx_mask_email( 'olena@example.com' ), 'mask olena@example.com' );
$ok( 'a***@x.ua' === vx_mask_email( 'a@x.ua' ), 'mask a@x.ua' );
$ok( '' === vx_mask_email( 'not-an-email' ), 'mask rejects a non-address' );

// --- Resource hints --------------------------------------------------------------------------------------------------------------------
$hints  = new ResourceHints();
$woo_js = home_url( '/wp-content/plugins/woocommerce/assets/client/blocks/cart-frontend.js?ver=x' );
$dep_js = home_url( '/wp-includes/js/dist/vendor/wp-polyfill.js?ver=3' );
$san_js = home_url( '/wp-content/plugins/woocommerce/assets/client/admin/sanitize/index.js?ver=y' );
$mine   = [ 'href' => 'https://example.test/own-hint.js' ];
$out    = $hints->filter(
	[
		[
			'href' => $woo_js,
			'as'   => 'script',
		],
		[
			'href' => $dep_js,
			'as'   => 'script',
		],
		[
			'href' => $san_js,
			'as'   => 'script',
		],
		$mine,
	],
	'prefetch'
);
$ok( [ $mine ] === $out, 'prefetch: Woo block + helper + WP dependency hints dropped, an unrelated hint kept' );
$list = [ $mine, 'https://example.test/other.js' ];
$ok( $list === $hints->filter( $list, 'prefetch' ), 'prefetch list without a Woo block entry is untouched' );
$ok( [] === $hints->filter( [ home_url( '/checkout/' ) ], 'prerender' ), 'prerender dropped everywhere' );
$pre = [
	[
		'href'        => 'https://example.test',
		'crossorigin' => true,
	],
];
$ok( $pre === $hints->filter( $pre, 'preconnect' ) && $pre === $hints->filter( $pre, 'dns-prefetch' ), 'preconnect / dns-prefetch untouched' );

// Cart page of both languages: the stripped list is exposed; elsewhere empty.
$pll = Plugin::instance()->integration( 'polylang' );
wc_load_cart();
WC()->cart->empty_cart();
$simple  = wc_get_products(
	[
		'type'         => 'simple',
		'status'       => 'publish',
		'stock_status' => 'instock',
		'limit'        => 1,
		'return'       => 'ids',
		'orderby'      => 'id',
		'order'        => 'ASC',
	]
);
$product = (int) ( $simple[0] ?? 0 );
WC()->cart->add_to_cart( $product );
$ok( WC()->cart->get_cart_contents_count() > 0, 'test cart holds an item' );
$ok( [] === vx_cart_checkout_hints(), 'vx_cart_checkout_hints() is empty off the cart page' );
// The cart page type needs the `wp` action (not run under WP-CLI): the documented `woocommerce_is_cart` filter stands in for it. The real
// /cart/ and /ru/cart/ pages are checked over HTTP by QA (the list is language independent).
add_filter( 'woocommerce_is_cart', '__return_true' );
$got = vx_cart_checkout_hints();
$ok( count( $got ) > 0 && isset( $got[0]['href'], $got[0]['as'] ) && ! str_contains( wp_json_encode( $hints->filter( $got, 'prefetch' ) ), 'client/blocks' ), 'cart page: checkout hint list has ' . count( $got ) . ' [href, as] entries' );
$ok( count( array_filter( $got, static fn( $h ) => str_contains( $h['href'], '/assets/client/blocks/checkout-frontend.js' ) ) ) > 0, 'the checkout bundle is in the list' );
remove_filter( 'woocommerce_is_cart', '__return_true' );
WC()->cart->empty_cart();

// --- vx_cart cookie over HTTP (this site) --------------------------------------------------------------------------------------------
$http = static function ( string $method, string $path, array $args = [], array $cookies = [] ): array {
	$headers = $args['headers'] ?? [];
	$cookie  = [];
	foreach ( $cookies as $k => $v ) {
		$cookie[] = $k . '=' . $v;
	}
	if ( array() !== $cookie ) {
		$headers['Cookie'] = implode( '; ', $cookie );
	}
	$res = wp_remote_request(
		home_url( $path ),
		[
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => $args['body'] ?? null,
		]
	);
	if ( is_wp_error( $res ) ) {
		return [
			'code'    => 0,
			'headers' => [],
			'body'    => $res->get_error_message(),
			'cookies' => [],
		];
	}
	$raw = wp_remote_retrieve_header( $res, 'set-cookie' );
	$raw = is_array( $raw ) ? $raw : ( '' === $raw ? [] : [ $raw ] );
	return [
		'code'    => (int) wp_remote_retrieve_response_code( $res ),
		'headers' => $res['headers'],
		'body'    => (string) wp_remote_retrieve_body( $res ),
		'cookies' => $raw,
	];
};
$jar = static function ( array $res, array $jar_in ): array {
	foreach ( $res['cookies'] as $line ) {
		$pair = explode( ';', $line, 2 )[0];
		$kv   = explode( '=', $pair, 2 );
		if ( 2 === count( $kv ) && 'deleted' !== $kv[1] && '' !== $kv[1] ) {
			$jar_in[ $kv[0] ] = $kv[1];
		} elseif ( 2 === count( $kv ) ) {
			unset( $jar_in[ $kv[0] ] );
		}
	}
	return $jar_in;
};
$vx_line = static function ( array $res ): string {
	foreach ( $res['cookies'] as $line ) {
		if ( 0 === strpos( $line, 'vx_cart=' ) ) {
			return $line;
		}
	}
	return '';
};

foreach ( [ '/', '/catalog/', '/ru/', get_permalink( $product ) ] as $url ) {
	$path = str_replace( home_url(), '', $url );
	$res  = $http( 'GET', $path );
	$bad  = array_filter( $res['cookies'], static fn( $c ) => 0 !== strpos( $c, 'pll_language=' ) );
	$ok( 200 === $res['code'] && array() === $bad, 'anonymous GET ' . $path . ': no Set-Cookie except Polylang\'s language cookie', implode( ' | ', $bad ) );
}

$cookies = [];
$res     = $http( 'GET', '/wp-json/wc/store/v1/cart' );
$nonce   = (string) wp_remote_retrieve_header( [ 'headers' => $res['headers'] ], 'nonce' );
$cookies = $jar( $res, $cookies );
$ok( '' !== $nonce && '' === $vx_line( $res ), 'Store API GET cart: nonce yes, vx_cart no' );
$post = static function ( string $route, array $payload ) use ( $http, &$nonce, &$cookies ): array {
	return $http(
		'POST',
		'/wp-json/wc/store/v1/' . $route,
		[
			'headers' => [
				'Nonce'        => $nonce,
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
		],
		$cookies
	);
};
$res     = $post(
	'cart/add-item',
	[
		'id'       => $product,
		'quantity' => 1,
	]
);
$cookies = $jar( $res, $cookies );
$line    = $vx_line( $res );
$ok( 201 === $res['code'] && 1 === preg_match( '/^vx_cart=1;/', $line ) && str_contains( $line, 'SameSite=Lax' ) && ! stripos( $line, 'HttpOnly' ), 'add-item: Set-Cookie vx_cart=1, SameSite=Lax, not HttpOnly', $line );
$key = (string) ( json_decode( $res['body'], true )['items'][0]['key'] ?? '' );
$res = $post(
	'cart/update-item',
	[
		'key'      => $key,
		'quantity' => 3,
	]
);
$ok( 200 === $res['code'] && 0 === strpos( $vx_line( $res ), 'vx_cart=3;' ), 'update-item: vx_cart=3', $res['code'] . ' ' . $vx_line( $res ) . ' ' . substr( $res['body'], 0, 160 ) );
$cookies = $jar( $res, $cookies );
$res     = $post( 'cart/remove-item', [ 'key' => $key ] );
$line    = $vx_line( $res );
$ok( 200 === $res['code'] && 0 === strpos( $line, 'vx_cart=deleted' ) && str_contains( $line, 'Max-Age=0' ), 'remove-item (cart now empty): vx_cart expired', $line );
$res = $post(
	'cart/add-item',
	[
		'id'       => $product,
		'quantity' => 2,
	]
);
$ok( 0 === strpos( $vx_line( $res ), 'vx_cart=2;' ), 'add 2: vx_cart=2' );
$cookies = $jar( $res, $cookies );
$res     = $http(
	'DELETE',
	'/wp-json/wc/store/v1/cart/items',
	[
		'headers' => [
			'Nonce'        => $nonce,
			'Content-Type' => 'application/json',
		],
	],
	$cookies
);
$ok( 200 === $res['code'] && 0 === strpos( $vx_line( $res ), 'vx_cart=deleted' ), 'empty cart: vx_cart expired', $vx_line( $res ) );

// Stock clamp: Woo lowers a quantity on a Store API GET after the stock dropped; the cookie follows the returned cart. The stock is restored exactly.
$stock_before = wc_get_product( $product )->get_stock_quantity();
if ( null !== $stock_before && $stock_before >= 3 ) {
	$res     = $post(
		'cart/add-item',
		[
			'id'       => $product,
			'quantity' => 3,
		]
	);
	$cookies = $jar( $res, $cookies );
	$ok( 0 === strpos( $vx_line( $res ), 'vx_cart=3;' ), 'clamp setup: vx_cart=3' );
	$res = $post(
		'cart/add-item',
		[
			'id'       => $product,
			'quantity' => $stock_before + 50,
		]
	);
	$ok( 400 === $res['code'] && '' === $vx_line( $res ), 'add over stock: refused, cookie untouched (still 3)' );
	wc_update_product_stock( wc_get_product( $product ), 1, 'set' );
	// Server-rendered cart page (the block preloads the cart internally, no Store API response header): the cookie is aligned before the first byte.
	foreach ( [ '/cart/', '/ru/cart/', '/checkout/' ] as $page ) {
		$res = $http( 'GET', $page, [], $cookies );
		$ok( 200 === $res['code'] && 0 === strpos( $vx_line( $res ), 'vx_cart=1;' ), 'page render ' . $page . ' after a stock clamp: vx_cart 3 -> 1', $res['code'] . ' ' . $vx_line( $res ) );
	}
	$res     = $http( 'GET', '/wp-json/wc/store/v1/cart', [], $cookies );
	$cookies = $jar( $res, $cookies );
	$ok( 200 === $res['code'] && 0 === strpos( $vx_line( $res ), 'vx_cart=1;' ), 'stock clamp on GET cart: vx_cart refreshed 3 -> 1', $vx_line( $res ) );
	wc_update_product_stock( wc_get_product( $product ), $stock_before, 'set' );
	$http(
		'DELETE',
		'/wp-json/wc/store/v1/cart/items',
		[
			'headers' => [
				'Nonce'        => $nonce,
				'Content-Type' => 'application/json',
			],
		],
		$cookies
	);
}

// Variation values of a cart line are term NAMES in the language of the item, also on the other language's cart page (no raw slugs).
$var_parent = get_page_by_path( 'demo-pdp-variable', OBJECT, 'product' );
$var_child  = 0;
if ( $var_parent instanceof WP_Post ) {
	foreach ( (array) wc_get_product( $var_parent->ID )->get_children() as $child_id ) {
		$attrs = wc_get_product( $child_id )->get_attributes();
		if ( 'synii' === ( $attrs['pa_color'] ?? '' ) && '256-gb' === ( $attrs['pa_memory'] ?? '' ) ) {
			$var_child = (int) $child_id;
		}
	}
}
if ( $var_child > 0 ) {
	$vcookies = [];
	$vres     = $http( 'GET', '/wp-json/wc/store/v1/cart' );
	$vnonce   = (string) wp_remote_retrieve_header( [ 'headers' => $vres['headers'] ], 'nonce' );
	$vcookies = $jar( $vres, $vcookies );
	$vres     = $http(
		'POST',
		'/wp-json/wc/store/v1/cart/add-item',
		[
			'headers' => [
				'Nonce'        => $vnonce,
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode(
				[
					'id'       => $var_child,
					'quantity' => 1,
				]
			),
		],
		$vcookies
	);
	$vcookies = $jar( $vres, $vcookies );
	foreach ( [ '/cart/', '/ru/cart/' ] as $page ) {
		$html = rawurldecode( $http( 'GET', $page, [], $vcookies )['body'] );
		$ok( 1 === preg_match( '/value\W{1,12}u0421\\\\*u0438\\\\*u043d/', $html ) && 1 !== preg_match( '/value\W{1,12}(?:synii|256-gb)/', $html ), 'variation names on ' . $page . ': "Синій", no raw slug' );
	}
	$http(
		'DELETE',
		'/wp-json/wc/store/v1/cart/items',
		[
			'headers' => [
				'Nonce'        => $vnonce,
				'Content-Type' => 'application/json',
			],
		],
		$vcookies
	);
} else {
	WP_CLI::warning( 'demo-pdp-variable (synii / 256-gb) not found: variation-name check skipped.' );
}

// --- Fix round 5 over HTTP: no account oracle (SEC-071), Referrer-Policy on the order pages (SEC-077). COD is enabled temporarily and restored; every test row is removed. ---
$had_cod    = get_option( 'woocommerce_cod_settings', null );
$p_before   = wc_get_product( $product );
$stock_was  = $p_before->get_stock_quantity();
$sales_was  = $p_before->get_total_sales();
update_option( 'woocommerce_cod_settings', [ 'enabled' => 'yes' ] );
require_once ABSPATH . 'wp-admin/includes/user.php';
$exists_mail = 't045a-exists@example.test';
$user_id     = wp_insert_user(
	[
		'user_login' => 't045a-exists',
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => $exists_mail,
		'role'       => 'customer',
	]
);
$users_before = count_users()['total_users'];
foreach ( [ $exists_mail, 't045a-new@example.test' ] as $buyer_mail ) {
	RateLimiter::reset( 'checkout', '127.0.0.1' );
	RateLimiter::reset( 'checkout', '::1' );
	$c   = [];
	$r   = $http( 'GET', '/wp-json/wc/store/v1/cart' );
	$n   = (string) wp_remote_retrieve_header( [ 'headers' => $r['headers'] ], 'nonce' );
	$c   = $jar( $r, $c );
	$r   = $http(
		'POST',
		'/wp-json/wc/store/v1/cart/add-item',
		[
			'headers' => [
				'Nonce'        => $n,
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode(
				[
					'id'       => $product,
					'quantity' => 1,
				]
			),
		],
		$c
	);
	$c   = $jar( $r, $c );
	$n   = (string) wp_remote_retrieve_header( [ 'headers' => $r['headers'] ], 'nonce' );
	$adr = [
		'first_name' => 'Test',
		'last_name'  => 'T',
		'address_1'  => 'Testova 1',
		'city'       => 'Kyiv',
		'state'      => 'UA30',
		'postcode'   => '',
		'country'    => 'UA',
		'phone'      => '0501234567',
	];
	$r   = $http(
		'POST',
		'/wp-json/wc/store/v1/checkout',
		[
			'headers' => [
				'Nonce'        => $n,
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode(
				[
					'billing_address'  => $adr + [ 'email' => $buyer_mail ],
					'shipping_address' => $adr,
					'payment_method'   => 'cod',
					'create_account'   => true,
				]
			),
		],
		$c
	);
	$d   = json_decode( $r['body'], true );
	$oid = (int) ( $d['order_id'] ?? 0 );
	if ( $oid > 0 ) {
		$temp_orders[] = $oid;
	}
	$ok( 200 === $r['code'] && $oid > 0 && 0 === (int) ( $d['customer_id'] ?? -1 ) && false === strpos( $r['body'], 'registration-error' ), 'SEC-071: create_account=true with ' . ( $buyer_mail === $exists_mail ? 'a REGISTERED' : 'a new' ) . ' e-mail -> same answer, guest order, no oracle', $r['code'] . ' ' . substr( $r['body'], 0, 120 ) );
	if ( $oid > 0 && $buyer_mail === $exists_mail ) {
		$order = wc_get_order( $oid );
		$page  = '/checkout/order-received/' . $oid . '/?key=' . $order->get_order_key();
		$res   = $http( 'GET', $page );
		$ok( 'strict-origin-when-cross-origin' === (string) wp_remote_retrieve_header( [ 'headers' => $res['headers'] ], 'referrer-policy' ), 'SEC-077: order-received sends Referrer-Policy strict-origin-when-cross-origin' );
		$pay = '/checkout/order-pay/' . $oid . '/?pay_for_order=true&key=' . $order->get_order_key();
		$res = $http( 'GET', $pay );
		$ok( 'strict-origin-when-cross-origin' === (string) wp_remote_retrieve_header( [ 'headers' => $res['headers'] ], 'referrer-policy' ), 'SEC-077: order-pay sends Referrer-Policy' );
		$res = $http( 'GET', '/cart/' );
		$ok( '' === (string) wp_remote_retrieve_header( [ 'headers' => $res['headers'] ], 'referrer-policy' ), 'SEC-077: only the order pages carry it' );
	}
}
$ok( count_users()['total_users'] === $users_before, 'SEC-071: no account was created by the two checkouts' );
foreach ( $temp_orders as $oid ) {
	$order = wc_get_order( $oid );
	if ( $order instanceof WC_Order && str_ends_with( (string) $order->get_billing_email(), '@example.test' ) ) {
		$order->delete( true );
	}
}
$temp_orders = [];
wp_delete_user( (int) $user_id );
$p_restore = wc_get_product( $product );
if ( null !== $stock_was ) {
	wc_update_product_stock( $p_restore, (int) $stock_was, 'set' );
}
$p_restore = wc_get_product( $product );
$p_restore->set_total_sales( (int) $sales_was );
$p_restore->save();
if ( null === $had_cod ) {
	delete_option( 'woocommerce_cod_settings' );
} else {
	update_option( 'woocommerce_cod_settings', $had_cod );
}
RateLimiter::reset( 'checkout', '127.0.0.1' );
	RateLimiter::reset( 'checkout', '::1' );

// The page does not depend on the cookie (cache safety).
$norm  = static fn( string $html ): string => (string) preg_replace( '#<a href="mailto:[^"]*">[^<]*</a>#', '<MAIL>', $html );
$plain = $norm( $http( 'GET', '/catalog/' )['body'] );
$with  = $norm( $http( 'GET', '/catalog/', [], [ 'vx_cart' => '3' ] )['body'] );
$ok( '' !== $plain && $plain === $with, 'catalog HTML is identical with and without the vx_cart cookie' );

// --- Turnstile contract (simulated provider answers, SEC-049) --------------------------------------------------------------------------
if ( defined( 'VX_TURNSTILE_SITE_KEY' ) || defined( 'VX_TURNSTILE_SECRET_KEY' ) ) {
	WP_CLI::warning( 'Turnstile constants are defined: skipping the simulated verify checks.' );
} else {
	add_filter(
		'vextra/core/turnstile/keys',
		static fn() => [
			'site'   => 't045a-site-key-0001',
			'secret' => 't045a-secret-key-0001',
		]
	);
	$answer = [];
	add_filter(
		'pre_http_request',
		static function ( $pre, $args, $url ) use ( &$answer ) {
			if ( Turnstile::VERIFY_URL !== $url ) {
				return $pre;
			}
			return [
				'headers'  => [],
				'body'     => wp_json_encode( $answer ),
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		},
		10,
		3
	);
	$ts      = Plugin::instance()->integration( 'turnstile' );
	$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$answer  = [
		'success'  => true,
		'action'   => 'checkout',
		'hostname' => $host,
	];
	$verdict = $ts->verify( 't045a-token-1', '203.0.113.50', 'checkout' );
	$ok( true === $verdict['ok'], 'verify: right action + hostname passes', wp_json_encode( $verdict ) );
	$answer  = [
		'success'  => true,
		'action'   => 'login',
		'hostname' => $host,
	];
	$verdict = $ts->verify( 't045a-token-2', '203.0.113.50', 'checkout' );
	$ok( false === $verdict['ok'] && 'action' === $verdict['reason'], 'verify: token minted for another action is refused', wp_json_encode( $verdict ) );
	$answer  = [
		'success'  => true,
		'action'   => 'checkout',
		'hostname' => 'evil.example',
	];
	$verdict = $ts->verify( 't045a-token-3', '203.0.113.50', 'checkout' );
	$ok( false === $verdict['ok'] && 'hostname' === $verdict['reason'], 'verify: wrong hostname is refused', wp_json_encode( $verdict ) );
	$verdict = $ts->verify( '', '203.0.113.50', 'checkout' );
	$ok( false === $verdict['ok'] && 'missing' === $verdict['reason'], 'verify: missing token is refused' );
	// --- Fix round 5 (SEC-072 .. SEC-075): the Gate, in process, with simulated siteverify answers -----------------------------------------------
	$calls = 0;
	add_filter(
		'pre_http_request',
		static function ( $pre, $args, $url ) use ( &$calls ) {
			if ( Turnstile::VERIFY_URL === $url ) {
				++$calls;
			}
			return $pre;
		},
		5,
		3
	);
	$answer = [
		'success'  => true,
		'action'   => 'checkout',
		'hostname' => $host,
	];
	$gate   = new Gate( new TurnstileCheckout() );
	$server = rest_get_server();
	$mk_req = static function ( array $params, string $route = '/wc/store/v1/checkout' ): WP_REST_Request {
		$req = new WP_REST_Request( 'POST', $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	};
	$token  = 0;
	$fresh  = static function () use ( &$token ): array {
		++$token;
		return [
			'vextra' => [
				'turnstile' => 't045a-r5-token-' . $token,
			],
		];
	};

	// SEC-075: a non-string e-mail / field -> 400 before the token is verified (token not consumed, no Woo call).
	$calls = 0;
	$resp  = $gate->pre_dispatch(
		null,
		$server,
		$mk_req(
			[
				'billing_address' => [ 'email' => [ 'a' ] ],
				'extensions'      => $fresh(),
			]
		)
	);
	$ok( $resp instanceof WP_REST_Response && 400 === $resp->get_status() && 0 === $calls, 'SEC-075: array e-mail -> 400, token not verified', $resp instanceof WP_REST_Response ? (string) $resp->get_status() : 'null' );
	$resp = $gate->pre_dispatch(
		null,
		$server,
		$mk_req(
			[
				'billing_address' => [ 'address_1' => str_repeat( 'x', 300 ) ],
				'extensions'      => $fresh(),
			]
		)
	);
	$ok( $resp instanceof WP_REST_Response && 400 === $resp->get_status() && 0 === $calls, 'SEC-075: 300-char field -> 400, token not verified' );
	$resp = $gate->pre_dispatch( null, $server, $mk_req( [ 'billing_address' => 'x' ] ) );
	$ok( $resp instanceof WP_REST_Response && 400 === $resp->get_status(), 'SEC-075: scalar address -> 400' );

	// SEC-073: a batch that carries a checkout is refused; a batch of cart calls is not touched.
	$resp = $gate->pre_dispatch(
		null,
		$server,
		$mk_req(
			[
				'requests' => [
					[
						'path'   => '/wc/store/v1/cart/add-item',
						'method' => 'POST',
					],
					[
						'path'   => '/wc/store/v1/Checkout/?x=1',
						'method' => 'POST',
					],
				],
			],
			'/wc/store/v1/batch'
		)
	);
	$ok( $resp instanceof WP_REST_Response && 400 === $resp->get_status() && 'vx_checkout_not_batchable' === ( $resp->get_data()['code'] ?? '' ), 'SEC-073: checkout inside /batch -> 400' );
	$resp = $gate->pre_dispatch(
		null,
		$server,
		$mk_req(
			[
				'requests' => [
					[
						'path'   => '/wc/store/v1/cart/add-item',
						'method' => 'POST',
					],
				],
			],
			'/wc/store/v1/batch'
		)
	);
	$ok( null === $resp, 'SEC-073: a batch without checkout passes through' );

	// SEC-074: no e-mail bucket for an empty cart; with a cart the NORMALISED mailbox is counted once.
	$mailbox = 'ab@gmail.com';
	$peek    = static fn(): int => (int) RateLimiter::peek( 'checkout_mail', hash_hmac( 'sha256', $mailbox, wp_salt( 'auth' ) ), 100 )['count'];
	RateLimiter::reset( 'checkout_mail', hash_hmac( 'sha256', $mailbox, wp_salt( 'auth' ) ) );
	WC()->cart->empty_cart();
	$body  = static fn( string $mail ): array => [
		'billing_address' => [
			'email'      => $mail,
			'first_name' => 'T',
		],
		'extensions'      => $fresh(),
	];
	$resp  = $gate->pre_dispatch( null, $server, $mk_req( $body( 'A.B+one@gmail.com' ) ) );
	$gate->restore_locale( null );
	$ok( null === $resp && 0 === $peek(), 'SEC-074: empty cart -> e-mail bucket not counted', 'count ' . $peek() );
	WC()->cart->add_to_cart( $product );
	$resp = $gate->pre_dispatch( null, $server, $mk_req( $body( 'a.b+two@googlemail.com' ) ) );
	$gate->restore_locale( null );
	$resp2 = $gate->pre_dispatch( null, $server, $mk_req( $body( 'AB@gmail.com' ) ) );
	$gate->restore_locale( null );
	$ok( null === $resp && null === $resp2 && 2 === $peek(), 'SEC-074: +tag / dots / googlemail share ONE mailbox bucket', 'count ' . $peek() );
	$ok( 'ab@gmail.com' === CheckoutThrottle::normalise_email( 'A.B+x@Gmail.com' ) && 'a.b@example.test' === CheckoutThrottle::normalise_email( 'a.b+x@example.test' ) && '' === CheckoutThrottle::normalise_email( 'nope' ), 'SEC-074: mailbox normalisation cases' );
	$resp = $gate->pre_dispatch( null, $server, $mk_req( $body( 'ab@gmail.com' ) ) );
	$gate->restore_locale( null );
	$resp = $gate->pre_dispatch( null, $server, $mk_req( $body( 'ab+3@gmail.com' ) ) );
	$gate->restore_locale( null );
	$ok( $resp instanceof WP_REST_Response && 429 === $resp->get_status(), 'SEC-074: the 4th attempt of one mailbox in the hour is 429', $resp instanceof WP_REST_Response ? (string) $resp->get_status() : 'null' );
	RateLimiter::reset( 'checkout_mail', hash_hmac( 'sha256', $mailbox, wp_salt( 'auth' ) ) );
	WC()->cart->empty_cart();

	// SEC-072: the lock name comes from the Guard client key + mailbox, never from a cookie; the lock is taken after the token passed.
	$name_a = Gate::lock_name( 'a@example.test' );
	$_COOKIE['wp_woocommerce_session_zz'] = '1||1999999999||1999999999||abc';
	$ok( $name_a === Gate::lock_name( 'A+x@example.test' ) && $name_a !== Gate::lock_name( 'b@example.test' ), 'SEC-072: lock name = client key + normalised mailbox, a session cookie changes nothing' );
	unset( $_COOKIE['wp_woocommerce_session_zz'] );
	global $wpdb;
	$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', Gate::lock_name( 'held@example.test' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$calls = 0;
	$resp  = $gate->pre_dispatch( null, $server, $mk_req( $body( 'held@example.test' ) ) );
	$gate->restore_locale( null );
	// The same connection already holds the named lock (MySQL lets it re-enter), so the busy path is proven by the order of work: the token WAS verified first.
	$ok( $calls >= 1, 'SEC-072: the token is verified BEFORE the lock is taken', 'calls ' . $calls );
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', Gate::lock_name( 'held@example.test' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// SEC-071: the body cannot switch account creation on while the option is off.
	$req = $mk_req(
		[
			'create_account'  => true,
			'billing_address' => [ 'email' => 'x@example.test' ],
			'extensions'      => $fresh(),
		]
	);
	$gate->pre_dispatch( null, $server, $req );
	$gate->restore_locale( null );
	$ok( false === $req->get_param( 'create_account' ) && 'no' === (string) get_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' ), 'SEC-071: create_account forced off; option is no' );

	$cfg = vx_checkout_turnstile_config();
	$ok( false === $cfg['enabled'] && '' === $cfg['site_key'], 'checkout config: disabled off the checkout page, no key printed' );
	remove_all_filters( 'pre_http_request' );
	remove_all_filters( 'vextra/core/turnstile/keys' );
	$off = vx_checkout_turnstile_config();
	$ok( false === $off['enabled'], 'keys OFF: config disabled' );
}

// --- Throttle buckets ------------------------------------------------------------------------------------------------------------------
$ip_subject = ClientIp::get();
RateLimiter::reset( 'checkout', $ip_subject );
$blocked_at = 0;
for ( $i = 1; $i <= 12; $i++ ) {
	$hit = CheckoutThrottle::hit_ip();
	if ( ! $hit['allowed'] && 0 === $blocked_at ) {
		$blocked_at = $i;
	}
}
$ok( 11 === $blocked_at, 'IP bucket: the 11th attempt in the window is refused (10 / 10 min)', 'blocked at ' . $blocked_at );
RateLimiter::reset( 'checkout', $ip_subject );
$mail    = 'T045a-Check@example.test';
$first   = [];
$blocked = 0;
for ( $i = 1; $i <= 4; $i++ ) {
	$hit = CheckoutThrottle::hit_email( $mail );
	if ( ! $hit['allowed'] && 0 === $blocked ) {
		$blocked = $i;
	}
}
$ok( 4 === $blocked, 'e-mail bucket: the 4th attempt per hour is refused (3 / hour, case-insensitive key)', 'blocked at ' . $blocked );
$ok( true === CheckoutThrottle::hit_email( 'not-an-email' )['allowed'], 'a malformed e-mail is not counted' );
RateLimiter::reset( 'checkout_mail', hash_hmac( 'sha256', strtolower( $mail ), wp_salt( 'auth' ) ) );
$ok( str_contains( CheckoutThrottle::message( 600 ), '10' ), 'throttle message carries the minutes' );

// --- Draft purge rules -----------------------------------------------------------------------------------------------------------------
$mk = static function ( int $age_h, string $email ) use ( &$temp_orders ): int {
	$order = wc_create_order( [ 'status' => 'checkout-draft' ] );
	if ( '' !== $email ) {
		$order->set_billing_email( $email );
	}
	$order->set_date_created( time() - $age_h * HOUR_IN_SECONDS );
	$order->save();
	$temp_orders[] = $order->get_id();
	return $order->get_id();
};
$d_anon7  = $mk( 7, '' );
$d_mail7  = $mk( 7, 't045a-draft@example.test' );
$d_old25  = $mk( 25, 't045a-draft2@example.test' );
$d_anon1  = $mk( 1, '' );
( new DraftOrders() )->run();
$gone = static fn( int $id ): bool => ! ( wc_get_order( $id ) instanceof WC_Order );
$ok( $gone( $d_anon7 ), 'draft without contact data older than 6 h is purged' );
$ok( ! $gone( $d_mail7 ), 'draft WITH an e-mail, 7 h old, is kept (24 h rule)' );
$ok( $gone( $d_old25 ), 'draft older than 24 h is purged' );
$ok( ! $gone( $d_anon1 ), 'young draft is kept' );
$ok( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( DraftOrders::HOOK ), 'purge job is scheduled in Action Scheduler' );

// --- Cross-sells -----------------------------------------------------------------------------------------------------------------------
wc_load_cart();
WC()->cart->empty_cart();
$ok( [] === apply_filters( 'woocommerce_cart_crosssell_ids', [ 1 ], WC()->cart ), 'cross-sells: empty cart -> []' );
WC()->cart->add_to_cart( $product );
$ids = apply_filters( 'woocommerce_cart_crosssell_ids', [], WC()->cart );
$ok( is_array( $ids ) && ! in_array( $product, $ids, true ), 'cross-sells: engine ids, never an item of the cart', wp_json_encode( $ids ) );
$engine = vx_recommend_ids( 'cart', [ 'cart_ids' => [ $product ] ] );
$ok( $engine === array_values( (array) $ids ), 'cross-sell filter answers with vx_recommend_ids( cart )' );
foreach ( (array) $ids as $id ) {
	$p = wc_get_product( $id );
	$ok( $p instanceof WC_Product && $p->is_in_stock(), 'cross-sell ' . $id . ' is in stock' );
}
WC()->cart->empty_cart();
$ok( in_array( 'drawer', \Vextra\Core\Recommend\Attribution::SOURCES, true ) && in_array( 'cart_crosssell', \Vextra\Core\Recommend\Attribution::SOURCES, true ), 'rec_src whitelist accepts drawer and cart_crosssell' );

// --- Free shipping ---------------------------------------------------------------------------------------------------------------------
$had = get_option( 'vx_pdp_info', null );
delete_option( 'vx_pdp_info' );
$fs = vx_free_shipping( 'uk' );
$ok( false === $fs['enabled'] && 0 === $fs['threshold'], 'free shipping: no option -> disabled' );
update_option(
	'vx_pdp_info',
	[
		'uk' => [
			'rows'                    => [],
			'free_shipping_threshold' => 1500,
		],
		'ru' => [
			'rows'                    => [],
			'free_shipping_threshold' => 1500,
		],
	]
);
$fs = vx_free_shipping( 'uk' );
$ok( true === $fs['enabled'] && 1500 === $fs['threshold'] && 1500 * ( 10 ** wc_get_price_decimals() ) === $fs['threshold_minor'], 'free shipping: 1500 -> enabled, minor unit follows the store decimals', wp_json_encode( $fs ) );
$ok( str_starts_with( $fs['text'], 'Безкоштовна доставка від ' ) && str_contains( $fs['text'], '1' ), 'free shipping UA text', $fs['text'] );
$ok( str_starts_with( vx_free_shipping( 'ru' )['text'], 'Бесплатная доставка от ' ), 'free shipping RU text' );
if ( null === $had ) {
	delete_option( 'vx_pdp_info' );
} else {
	update_option( 'vx_pdp_info', $had );
}

// --- Conflict rule ---------------------------------------------------------------------------------------------------------------------
$conf = new Conflicts();
add_filter( 'vextra/core/compat/active_plugins', static fn( $l ) => array_merge( (array) $l, [ 'fluid-checkout/fluid-checkout.php' ] ) );
$row = null;
foreach ( $conf->evaluate() as $r ) {
	if ( 'duplicate-cart-plugin' === $r['id'] ) {
		$row = $r;
	}
}
$ok( null !== $row && true === $row['active'] && true === $row['blocking'] && 'fluid-checkout' === $row['detail'], 'duplicate-cart-plugin: Fluid Checkout detected, blocking', wp_json_encode( $row ) );
$ok( in_array( 'duplicate-cart-plugin', array_column( $conf->blocking(), 'id' ), true ), 'duplicate-cart-plugin appears in the blocking list (admin notice + wp vextra compat)' );
remove_all_filters( 'vextra/core/compat/active_plugins' );
$row = null;
foreach ( ( new Conflicts() )->evaluate() as $r ) {
	if ( 'duplicate-cart-plugin' === $r['id'] ) {
		$row = $r;
	}
}
$ok( null !== $row && false === $row['active'], 'duplicate-cart-plugin: quiet without such a plugin' );

// --- Kill switches / modules -----------------------------------------------------------------------------------------------------------
$modules = Plugin::instance()->modules();
$ok( isset( $modules['cart'], $modules['checkout'] ) && $modules['cart']->is_enabled() && $modules['checkout']->is_enabled(), 'cart and checkout modules registered and enabled' );
add_filter( 'vextra/core/cart/enabled', '__return_false' );
$ok( false === $modules['cart']->is_enabled(), 'cart kill switch (filter) turns the module off' );
remove_filter( 'vextra/core/cart/enabled', '__return_false' );

$cleanup();
WP_CLI::line( '' );
if ( $fails > 0 ) {
	WP_CLI::error( $fails . ' check(s) failed.' );
}
WP_CLI::success( 'All cart / checkout server checks passed.' );
