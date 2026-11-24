<?php
/**
 * Local-only (TASK-043a): asserts the indexation matrix of the catalog URLs against the running site.
 *
 * Run: `wp eval-file wp-content/plugins/vextra-core/scripts/dev/check-indexation-matrix.php`
 *
 * Requests every URL over HTTP with the public-preview header (`VX_PUBLIC_PREVIEW_TOKEN` from wp-config, no host or token in this
 * file) and prints a PASS / FAIL table; exit code 1 on any FAIL. Four blocks:
 * 1. facet / sort / search / paged URLs: `noindex` + `follow`, no canonical, no hreflang, HTTP 200 (429 allowed) and (rows that carry a
 *    robots.txt rule) a matching `Disallow` of the generated robots.txt;
 * 2. controls that must stay `index, follow` with a canonical and hreflang;
 * 3. robots.txt: the three new rules are in the single `User-agent: *` group, no duplicates, clean URLs are not matched;
 * 4. `/page/N/` guard: 404 / 200 expectations.
 * Category slugs are looked up from the catalog (term `electronics` and its Polylang twin), nothing is hard-coded per machine.
 *
 * @package Vextra\Core
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'wp-cli only' );
}
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Dev scripts run only when WP_ENVIRONMENT_TYPE is "local".' );
}

$home  = untrailingslashit( home_url( '/' ) );
$token = defined( 'VX_PUBLIC_PREVIEW_TOKEN' ) && is_string( VX_PUBLIC_PREVIEW_TOKEN ) ? VX_PUBLIC_PREVIEW_TOKEN : '';
if ( '' === $token ) {
	WP_CLI::warning( 'VX_PUBLIC_PREVIEW_TOKEN is not defined: index assertions cannot hold on a private site.' );
}

// Catalog slugs (UA term and its RU twin).
$uk_path = '/catalog/electronics/';
$ru_path = '/ru/catalog/electronics/';
$term    = get_term_by( 'slug', 'electronics', 'product_cat' );
if ( $term instanceof WP_Term && function_exists( 'pll_get_term_translations' ) ) {
	$uk_path = (string) wp_parse_url( (string) get_term_link( $term ), PHP_URL_PATH );
	$twin    = (int) ( pll_get_term_translations( $term->term_id )['ru'] ?? 0 );
	if ( $twin > 0 ) {
		$ru_path = (string) wp_parse_url( (string) get_term_link( $twin, 'product_cat' ), PHP_URL_PATH );
	}
}
$uk_slug = basename( rtrim( $uk_path, '/' ) );
$ru_slug = basename( rtrim( $ru_path, '/' ) );

/**
 * HTTP GET following up to 3 redirects.
 *
 * @return array{status:int,body:string,hops:string[],headers:array<string,string>}
 */
$fetch = static function ( string $url, string $token ): array {
	$hops = [];
	for ( $i = 0; $i < 4; $i++ ) {
		$args = [
			'timeout'     => 30,
			'redirection' => 0,
			'headers'     => '' !== $token ? [ 'X-VX-Public-Preview' => $token ] : [],
		];
		$res  = wp_remote_get( $url, $args );
		if ( is_wp_error( $res ) ) {
			return [
				'status'  => 0,
				'body'    => $res->get_error_message(),
				'hops'    => $hops,
				'headers' => [],
			];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$loc  = (string) wp_remote_retrieve_header( $res, 'location' );
		if ( $code >= 300 && $code < 400 && '' !== $loc ) {
			$hops[] = $code;
			$url    = str_starts_with( $loc, 'http' ) ? $loc : home_url( $loc );
			continue;
		}
		return [
			'status'  => $code,
			'body'    => (string) wp_remote_retrieve_body( $res ),
			'hops'    => array_map( 'strval', $hops ),
			'headers' => [
				'retry-after' => (string) wp_remote_retrieve_header( $res, 'retry-after' ),
				'x-robots'    => (string) wp_remote_retrieve_header( $res, 'x-robots-tag' ),
			],
		];
	}
	return [
		'status'  => 0,
		'body'    => 'redirect loop',
		'hops'    => $hops,
		'headers' => [],
	];
};

// robots.txt `*` group Disallow lines.
$robots_res = $fetch( $home . '/robots.txt', $token );
$robots     = $robots_res['body'];
$disallow   = [];
$in_star    = false;
$group      = [];
foreach ( preg_split( '/\R/', $robots ) ?: [] as $line ) {
	$line = trim( $line );
	if ( 1 === preg_match( '/^user-agent:\s*(.*)$/i', $line, $m ) ) {
		$in_star = '*' === trim( $m[1] );
		continue;
	}
	if ( $in_star && 1 === preg_match( '/^disallow:\s*(\S.*)$/i', $line, $m ) ) {
		$disallow[] = trim( $m[1] );
		$group[]    = $line;
	}
}

/**
 * Google-style robots matching of `$path_query` against a Disallow pattern (`*` wildcard, `$` end anchor).
 */
$matches_rule = static function ( string $pattern, string $path_query ): bool {
	$anchored = str_ends_with( $pattern, '$' );
	$pattern  = $anchored ? substr( $pattern, 0, -1 ) : $pattern;
	$regex    = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . ( $anchored ? '$' : '' ) . '#';
	return 1 === preg_match( $regex, $path_query );
};
$disallowed   = static function ( string $path_query ) use ( $disallow, $matches_rule ): bool {
	foreach ( $disallow as $pattern ) {
		if ( $matches_rule( $pattern, $path_query ) ) {
			return true;
		}
	}
	return false;
};

$fail = 0;
$rows = [];
$add  = static function ( string $block, string $url, bool $ok, string $note ) use ( &$fail, &$rows ): void {
	if ( ! $ok ) {
		++$fail;
	}
	$rows[] = [
		'block' => $block,
		'url'   => $url,
		'res'   => $ok ? 'PASS' : 'FAIL',
		'note'  => $note,
	];
};

$robots_meta = static function ( string $html ): string {
	return 1 === preg_match( '#<meta\s+name=["\']robots["\']\s+content=["\']([^"\']*)["\']#i', $html, $m ) ? strtolower( $m[1] ) : '';
};
$has_canon   = static fn( string $html ): bool => 1 === preg_match( '#<link\s+rel=["\']canonical["\']#i', $html );
$hreflangs   = static fn( string $html ): int => (int) preg_match_all( '#<link\s+rel=["\']alternate["\'][^>]*hreflang=#i', $html );

// 1. noindex matrix. [ url, rule_required, allow_redirect ].
$noindex = [
	[ $uk_path . '?orderby=price', true ],
	[ $uk_path . '?filter_brand=x&min_price=10', true ],
	[ $uk_path . '?rating_filter=4', true ],
	[ $uk_path . '?filter_on_sale=1&filter_stock_status=instock', true ],
	[ $uk_path . '?filter_color=red&query_type_color=or', true ],
	[ '/catalog/?orderby=date', true ],
	[ '/?product_cat=' . $uk_slug . '&s=test', true ],
	[ $uk_path . '?s=test', true ],
	[ '/search/test/', true ],
	[ '/?post_type=product&product_cat=' . $uk_slug, true ],
	[ $ru_path . '?orderby=price', true ],
	[ '/ru/search/test/', true ],
	[ '/ru/?s=test', true ],
	[ '/aktsii/?orderby=discount', true ],
	[ '/aktsii/?filter_discount=20', true ],
	[ $uk_path . 'page/2/?orderby=price', true, true ],
	[ '/ru/?product_cat=' . $ru_slug . '&s=test', true ],
	[ '/ru/aktsii/?orderby=discount', true ],
	[ '/?s=test', true ],
	[ $uk_path . '?filter_brand[]=x', true ],
	[ $uk_path . '?utm_source=a&orderby=price', true ],
];
foreach ( $noindex as $row ) {
	$res  = $fetch( $home . $row[0], $token );
	$html = $res['body'];
	$meta = $robots_meta( $html );
	$why  = [];
	// Third element: the page may not exist (demo categories have < 24 products): a plain 404 is not an indexable duplicate either.
	$allowed = ! empty( $row[2] ) ? [ 200, 429, 404 ] : [ 200, 429 ];
	if ( ! in_array( $res['status'], $allowed, true ) ) {
		$why[] = 'status ' . $res['status'];
	}
	if ( 200 === $res['status'] ) {
		if ( ! str_contains( $meta, 'noindex' ) || ! str_contains( $meta, 'follow' ) || str_contains( $meta, 'nofollow' ) ) {
			$why[] = 'robots=' . ( '' === $meta ? '(none)' : $meta );
		}
		if ( $has_canon( $html ) ) {
			$why[] = 'canonical present';
		}
		if ( $hreflangs( $html ) > 0 ) {
			$why[] = 'hreflang present';
		}
	}
	if ( ! empty( $row[1] ) && ! $disallowed( $row[0] ) ) {
		$why[] = 'no Disallow match';
	}
	$add( 'noindex', $row[0], array() === $why, array() === $why ? 'HTTP ' . $res['status'] . ( array() !== $res['hops'] ? ' via ' . implode( '>', $res['hops'] ) : '' ) . ' ' . $meta : implode( '; ', $why ) );
}

// `?paged=2` as an argument: either a redirect to `/page/2/` or noindex (never an indexable duplicate).
$res  = wp_remote_get(
	$home . $uk_path . '?paged=2',
	[
		'timeout'     => 30,
		'redirection' => 0,
		'headers'     => '' !== $token ? [ 'X-VX-Public-Preview' => $token ] : [],
	]
);
$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
$meta = is_wp_error( $res ) ? '' : $robots_meta( (string) wp_remote_retrieve_body( $res ) );
$add( 'noindex', $uk_path . '?paged=2', ( $code >= 300 && $code < 400 ) || 404 === $code || ( 200 === $code && str_contains( $meta, 'noindex' ) && ! $has_canon( (string) wp_remote_retrieve_body( $res ) ) ), 'HTTP ' . $code . ' ' . $meta );

// Empty `?s=` behaves like no search: not a facet, so it is NOT asserted noindex here; printed for review.
$res  = $fetch( $home . '/?s=', $token );
$add( 'info', '/?s=', 200 === $res['status'] || 3 === (int) substr( (string) $res['status'], 0, 1 ), 'HTTP ' . $res['status'] . ' ' . $robots_meta( $res['body'] ) . ( $has_canon( $res['body'] ) ? ' canonical' : '' ) );

// 2. controls: stay index, follow, canonical, hreflang.
$controls = [ $uk_path, $ru_path, '/catalog/', '/ru/catalog/', '/aktsii/', '/ru/aktsii/', $uk_path . '?utm_source=a' ];
foreach ( $controls as $path ) {
	$res  = $fetch( $home . $path, $token );
	$meta = $robots_meta( $res['body'] );
	$why  = [];
	if ( 200 !== $res['status'] ) {
		$why[] = 'status ' . $res['status'];
	}
	if ( ! str_contains( $meta, 'index' ) || str_contains( $meta, 'noindex' ) || ! str_contains( $meta, 'follow' ) ) {
		$why[] = 'robots=' . ( '' === $meta ? '(none)' : $meta );
	}
	if ( ! $has_canon( $res['body'] ) ) {
		$why[] = 'canonical missing';
	}
	if ( $hreflangs( $res['body'] ) < 2 ) {
		$why[] = 'hreflang missing';
	}
	if ( $disallowed( $path ) ) {
		$why[] = 'matched by a Disallow rule';
	}
	$add( 'control', $path, array() === $why, array() === $why ? $meta : implode( '; ', $why ) );
}

// 3. robots.txt.
$new_rules = [ '/*?*product_cat=', '/search/', '/ru/search/' ];
foreach ( $new_rules as $rule ) {
	$count = count( array_keys( $disallow, $rule, true ) );
	$add( 'robots.txt', 'Disallow: ' . $rule, 1 === $count, $count . ' in the * group' );
}
$stars = (int) preg_match_all( '/^user-agent:\s*\*\s*$/mi', $robots );
$add( 'robots.txt', 'single User-agent: * group', 1 === $stars, $stars . ' groups' );
$add( 'robots.txt', 'Allow admin-ajax + Sitemap kept', str_contains( $robots, 'Allow: /wp-admin/admin-ajax.php' ) && str_contains( $robots, 'Sitemap:' ), '' );
foreach ( [ $uk_path, $ru_path, $uk_path . 'page/2/', $ru_path . 'page/2/', '/catalog/', '/ru/catalog/', '/aktsii/', '/aktsii/page/2/', '/ru/aktsii/', '/about/' ] as $path ) {
	$add( 'robots.txt', $path . ' crawlable', ! $disallowed( $path ), '' );
}

// 4. /page/N/ guard.
$about_ru = '/ru/o-nas/';
$checks   = [
	[ '/catalog/page/2/', 404 ],
	[ '/ru/catalog/page/2/', 404 ],
	[ '/catalog/page/99/', 404 ],
	[ '/about/page/2/', 404 ],
	[ '/contacts/page/2/', 404 ],
	[ '/page/2/', 404 ],
	[ '/ru/page/2/', 404 ],
	[ $about_ru . 'page/2/', 404 ],
	[ $uk_path . 'page/99/', 404 ],
	[ '/about/', 200 ],
	[ '/contacts/', 200 ],
	[ $about_ru, 200 ],
	[ '/aktsii/page/4/', 404 ],
];
foreach ( $checks as $check ) {
	$res = $fetch( $home . $check[0], $token );
	$add( 'paged', $check[0], $check[1] === $res['status'], 'HTTP ' . $res['status'] . ' (want ' . $check[1] . ')' );
}

foreach ( $rows as $r ) {
	printf( "%-10s %-4s %-62s %s\n", $r['block'], $r['res'], $r['url'], $r['note'] );
}
printf( "\n%d rows, %d FAIL\n", count( $rows ), $fail );
if ( $fail > 0 ) {
	WP_CLI::halt( 1 );
}
WP_CLI::success( 'Indexation matrix PASS.' );
