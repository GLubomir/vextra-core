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
use Vextra\Core\Checkout\Phone;
use Vextra\Core\Checkout\Privacy;
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
