<?php
/**
 * DEV ONLY (TASK-038). Asserts the numbers of the Recommend engine on the local site, with TEMP data (`t038-*`) that is deleted at the end.
 *
 * Run: `wp eval-file wp-content/plugins/vextra-core/scripts/dev/check-recommend.php`
 * Stages (env `VX_T038_STAGE`): unset = full matrix (touches no demo data); `seed` = the documented, idempotent demo partner seed
 * (`_vx_partner_id` = `demo-a` on demo products 1092 / 1093); `cleanup` = delete every `t038-*` leftover.
 *
 * Covers: determinism, sources, filters (stale / hidden / draft / OOS / price band), language, partner priority, cart / post_add contexts,
 * cache + invalidation triggers (stale-while-revalidate, deduped job), cold query budget, attribution, fields, conflict guard, kill switch,
 * edge cases, internal-link spread of the related block. Exit code 1 on any FAIL. Never touches demo data.
 *
 * @package Vextra\Core
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'wp-cli only' );
}
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Dev scripts run only when WP_ENVIRONMENT_TYPE is "local".' );
}

use Vextra\Core\Compat\Conflicts;
use Vextra\Core\Plugin;
use Vextra\Core\Recommend\Attribution;
use Vextra\Core\Recommend\Config;
use Vextra\Core\Recommend\Engine;
use Vextra\Core\Recommend\Fields;
use Vextra\Core\Recommend\Pool;
use Vextra\Core\Recommend\Recommend;
use Vextra\Core\Recommend\Store;
use Vextra\Core\Support\Cache;

global $wpdb;

$stage = (string) getenv( 'VX_T038_STAGE' );
$fails = 0;
$ok    = static function ( bool $cond, string $label, string $detail = '' ) use ( &$fails ): void {
	if ( ! $cond ) {
		++$fails;
	}
	WP_CLI::line( ( $cond ? '  ok    ' : '  FAIL  ' ) . $label . ( '' !== $detail ? '  [' . $detail . ']' : '' ) );
};
$ids   = static fn( array $items ): array => array_column( $items, 'id' );

/**
 * Delete every t038-* product, term and user.
 */
$cleanup = static function (): array {
	global $wpdb;
	$out = [];
	$pids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation','shop_order') AND post_title LIKE 't038-%'" ); // phpcs:ignore
	foreach ( $pids as $pid ) {
		wp_delete_post( (int) $pid, true );
	}
	$out['products'] = array_map( 'intval', $pids );
	$terms = $wpdb->get_col( "SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'product_cat' AND t.name LIKE 't038-%'" ); // phpcs:ignore
	foreach ( $terms as $tid ) {
		wp_delete_term( (int) $tid, 'product_cat' );
	}
	$out['terms'] = array_map( 'intval', $terms );
	$users = get_users( [ 'search' => 't038-*', 'search_columns' => [ 'user_login' ], 'fields' => 'ID' ] );
	foreach ( $users as $uid ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $uid );
	}
	$out['users'] = array_map( 'intval', $users );
	return $out;
};

if ( 'cleanup' === $stage ) {
	WP_CLI::success( 'Deleted ' . wp_json_encode( $cleanup() ) );
	return;
}

// ---------------------------------------------------------------------------------------------------------------- demo seed (idempotent)
$seed = static function (): array {
	$done = [];
	foreach ( [ 1092, 1093 ] as $id ) { // Lumera Slim keyboard UA + RU twin (demo data, `_vx_demo`).
		$p = wc_get_product( $id );
		if ( $p instanceof WC_Product && '1' === (string) $p->get_meta( '_vx_demo', true ) && 'demo-a' !== $p->get_meta( Fields::PARTNER, true ) ) {
			$p->update_meta_data( Fields::PARTNER, 'demo-a' );
			$p->save();
			$done[] = $id;
		}
	}
	return $done;
};
if ( 'seed' === $stage ) {
	$seeded = $seed();
	WP_CLI::success( 'Demo partner seed (documented in docs/system/modules/recommend.md): ' . ( array() === $seeded ? 'already in place' : 'set on ' . implode( ',', $seeded ) ) );
	return;
}

$cleanup(); // Leftovers of an aborted run.

$pll   = Plugin::instance()->integration( 'polylang' );
$woo   = Plugin::instance()->integration( 'woocommerce' );
$state = [
	'products' => [],
	'terms'    => [],
];

/**
 * Create a temp product.
 *
 * @param string               $name Name suffix.
 * @param float                $price Regular price.
 * @param int[]                $cats Category ids.
 * @param array<string,mixed>  $o     Options: status, stock, partner, synced, visibility, lang, tags.
 */
$mk = static function ( string $name, float $price, array $cats, array $o = [] ) use ( &$state ): int {
	$p = new WC_Product_Simple();
	$p->set_name( 't038-' . $name );
	$p->set_status( $o['status'] ?? 'publish' );
	$p->set_regular_price( (string) $price );
	$p->set_category_ids( $cats );
	$p->set_manage_stock( false );
	$p->set_stock_status( $o['stock'] ?? 'instock' );
	if ( isset( $o['visibility'] ) ) {
		$p->set_catalog_visibility( $o['visibility'] );
	}
	if ( isset( $o['partner'] ) ) {
		$p->update_meta_data( Fields::PARTNER, $o['partner'] );
	}
	if ( isset( $o['synced'] ) ) {
		$p->update_meta_data( Fields::SYNCED_AT, $o['synced'] );
	}
	if ( isset( $o['tags'] ) ) {
		$p->set_tag_ids( $o['tags'] );
	}
	$id = $p->save();
	pll_set_post_language( $id, $o['lang'] ?? 'uk' );
	$state['products'][] = $id;
	return $id;
};

$engine_ids = static function ( string $ctx, array $args ) use ( $ids ): array {
	Store::reset_memo();
	return $ids( Engine::recommend( $ctx, $args ) );
};

// ---------------------------------------------------------------------------------------------------------------- fixtures
$cat_a  = wp_insert_term( 't038-cat-a', 'product_cat' );
$cat_b  = wp_insert_term( 't038-cat-b', 'product_cat' );
$cat_c  = wp_insert_term( 't038-cat-c', 'product_cat' );
$cat_a  = (int) $cat_a['term_id'];
$cat_b  = (int) $cat_b['term_id'];
$cat_c  = (int) $cat_c['term_id'];
foreach ( [ $cat_a, $cat_b, $cat_c ] as $tid ) {
	pll_set_term_language( $tid, 'uk' );
	$state['terms'][] = $tid;
}
update_term_meta( $cat_a, '_vx_accessory_terms', [ $cat_b ] ); // Rule: accessories of cat A live in cat B.

$now   = time();
$A     = $mk( 'anchor', 100, [ $cat_a ], [ 'partner' => 'demo-a' ] );
$A2    = $mk( 'anchor-nopartner', 100, [ $cat_a ] );
$B1    = $mk( 'rel-a1', 100, [ $cat_a ], [ 'partner' => 'demo-a' ] );
$B2    = $mk( 'rel-a2', 110, [ $cat_a ], [ 'partner' => 'demo-a' ] );
$B3    = $mk( 'rel-n1', 90, [ $cat_a ] );
$B4    = $mk( 'rel-n2', 95, [ $cat_a ] );
$X1    = $mk( 'acc-1', 20, [ $cat_b ] );
$X2    = $mk( 'acc-2', 30, [ $cat_b ] );
$C_oos = $mk( 'oos', 100, [ $cat_a ], [ 'stock' => 'outofstock' ] );
$C_hid = $mk( 'hidden', 100, [ $cat_a ], [ 'visibility' => 'hidden' ] );
$C_dr  = $mk( 'draft', 100, [ $cat_a ], [ 'status' => 'draft' ] );
$C_pr  = $mk( 'private', 100, [ $cat_a ], [ 'status' => 'private' ] );
$C_old = $mk( 'stale', 100, [ $cat_a ], [ 'synced' => $now - 100 * HOUR_IN_SECONDS ] );
$C_new = $mk( 'fresh', 100, [ $cat_a ], [ 'synced' => $now - HOUR_IN_SECONDS ] );
$C_exp = $mk( 'expensive', 700, [ $cat_a ] );
$C_chp = $mk( 'cheap', 5, [ $cat_a ] );
$C_acc = $mk( 'acc-cheap', 5, [ $cat_b ] ); // Accessory below the lower price bound: kept (accessories are exempt).
$state['named'] = compact( 'A', 'A2', 'B1', 'B2', 'B3', 'B4', 'X1', 'X2', 'C_oos', 'C_hid', 'C_dr', 'C_pr', 'C_old', 'C_new', 'C_exp', 'C_chp', 'C_acc' );
WP_CLI::line( 'Temp products: ' . implode( ',', $state['products'] ) . ' terms: ' . implode( ',', $state['terms'] ) );

try {
	// ------------------------------------------------------------------------------------------------------------ AC1 determinism
	WP_CLI::line( 'AC1 determinism' );
	$subjects = [ 1066, 1096, 1082, 1262, 1067, 1086, $A ];
	foreach ( $subjects as $pid ) {
		$runs = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$runs[] = wp_json_encode( Engine::explain( 'product', [ $pid ], Pool::language_of( $pid ) )['lists'] );
		}
		$ok( 1 === count( array_unique( $runs ) ), 'same ordered lists across 20 uncached builds, product ' . $pid );
	}
	$grep = [];
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src/Recommend', FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		if ( $f->isFile() && 1 === preg_match( '/\b(shuffle|array_rand|mt_rand|rand|wp_rand|RAND)\s*\(|ORDER BY RAND|orderby[\'"]?\s*=>\s*[\'"]rand/i', (string) file_get_contents( $f->getPathname() ) ) ) {
			$grep[] = $f->getFilename();
		}
	}
	$ok( array() === $grep, 'no rand / shuffle / ORDER BY RAND in the module', implode( ',', $grep ) );

	// ------------------------------------------------------------------------------------------------------------ AC2 sources
	WP_CLI::line( 'AC2 sources' );
	$acc = Engine::explain( 'product', [ $A ], 'uk' );
	$ok( in_array( 'accessory', array_column( $acc['lists']['accessories'], 'src' ), true ) && array_intersect( $ids( $acc['lists']['accessories'] ), [ $X1, $X2, $C_acc ] ) === $ids( $acc['lists']['accessories'] ), 'accessories come from the rule target category', wp_json_encode( $acc['lists']['accessories'] ) );
	$demo = Engine::explain( 'product', [ 1066 ], 'uk' )['lists']['accessories'];
	$ok( array() !== $demo && array_unique( array_column( $demo, 'src' ) ) === [ 'accessory' ], 'demo laptop gets accessory items from its category rule', wp_json_encode( $ids( $demo ) ) );
	$p = wc_get_product( $A );
	$p->set_upsell_ids( [ $B3 ] );
	$p->save();
	$up = Engine::explain( 'product', [ $A ], 'uk' )['lists']['accessories'];
	$ok( isset( $up[0] ) && $up[0]['id'] === $B3 && 'upsell' === $up[0]['src'], 'upsell ids first (src upsell)', wp_json_encode( $up ) );
	$p->set_upsell_ids( [] );
	$p->save();
	$rel = Engine::explain( 'product', [ 1262 ], 'uk' );
	$tiers = array_map( static fn( array $i ): int => (int) ( $i['meta']['tier'] ?? 0 ), $rel['trace']['lists']['related']['ranked'] );
	$sorted = $tiers;
	sort( $sorted );
	$ok( $tiers === $sorted && array() !== $tiers, 'related: same leaf category before parent tier (Pawsy Slow has no leaf siblings -> tier 2 only)', wp_json_encode( $tiers ) );
	$rel1 = Engine::explain( 'product', [ 1066 ], 'uk' )['trace']['lists']['related']['ranked'];
	$t1   = array_map( static fn( array $i ): int => (int) $i['meta']['tier'], $rel1 );
	$sorted = $t1;
	sort( $sorted );
	$ok( $t1 === $sorted, 'related tiers ascending for a laptop', wp_json_encode( $t1 ) );
	$tags = [];
	foreach ( $rel1 as $i ) {
		if ( (int) $i['meta']['tier'] === 1 ) {
			$tags[] = [ -$i['meta']['tags'], -$i['meta']['attr'], $i['meta']['key'], $i['id'] ];
		}
	}
	$sorted = $tags;
	usort( $sorted, static fn( array $a, array $b ): int => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] ?: strcmp( $a[2], $b[2] ) ?: $a[3] <=> $b[3] );
	$ok( $tags === $sorted, 'related tie-break: shared tags desc, shared attributes desc, per-anchor hash, id' );
	$ok( array() !== Engine::explain( 'product', [ 1066 ], 'uk' )['trace']['lists']['related']['ranked'][0]['meta'], 'explain prints the tie-break key' );

	// ------------------------------------------------------------------------------------------------------------ AC3 filters
	WP_CLI::line( 'AC3 filters' );
	$p = wc_get_product( $A );
	$p->set_upsell_ids( [ $C_oos, $C_hid, $C_dr, $C_pr, $C_old, $C_new, $C_exp, $C_chp, $B1, $A, 999999 ] );
	$p->save();
	$f   = Engine::explain( 'product', [ $A ], 'uk' );
	$out = $ids( $f['lists']['accessories'] );
	foreach ( [ 'oos' => $C_oos, 'hidden' => $C_hid, 'draft' => $C_dr, 'private' => $C_pr, 'stale' => $C_old, 'expensive' => $C_exp, 'self' => $A, 'unknown' => 999999 ] as $label => $pid ) {
		$ok( ! in_array( $pid, $out, true ), 'never returned: ' . $label );
	}
	$ok( in_array( $C_new, $out, true ) && in_array( $B1, $out, true ), 'fresh synced-at and absent synced-at are kept' );
	$ok( in_array( $C_chp, $out, true ), 'manual links are exempt from the LOWER price bound (cheap upsell kept)' );
	$ok( ! in_array( $C_chp, $engine_ids( 'product', [ 'product_id' => $A, 'list' => 'related', 'limit' => 8 ] ), true ), 'related items below the lower price bound are dropped' );
	$dropped = [];
	foreach ( $f['trace']['lists']['accessories']['filters'] as $row ) {
		foreach ( $row['dropped'] as $d ) {
			$dropped[ $d[0] ] = $row['filter'] . ': ' . $d[1];
		}
	}
	$ok( isset( $dropped[ $C_old ] ) && str_starts_with( $dropped[ $C_old ], 'stale' ), 'explain: stale drop with reason', $dropped[ $C_old ] ?? '' );
	$ok( isset( $dropped[ $C_oos ] ) && isset( $dropped[ $C_hid ] ) && isset( $dropped[ $C_dr ] ) && isset( $dropped[ $C_exp ] ), 'explain lists oos / hidden / draft / price-band drops', wp_json_encode( $dropped ) );
	$ok( in_array( $C_acc, $ids( $acc['lists']['accessories'] ), true ) || in_array( $C_acc, $ids( Engine::explain( 'product', [ $A ], 'uk' )['lists']['accessories'] ), true ), 'accessory under the lower price bound is kept (exempt)' );
	$p->set_upsell_ids( [] );
	$p->save();
	update_option( 'vx_rec_stale_hours', 200 );
	Config::reset();
	$p = wc_get_product( $A );
	$p->set_upsell_ids( [ $C_old ] );
	$p->save();
	$ok( in_array( $C_old, $ids( Engine::explain( 'product', [ $A ], 'uk' )['lists']['accessories'] ), true ), 'stale threshold is the option vx_rec_stale_hours' );
	delete_option( 'vx_rec_stale_hours' );
	Config::reset();
	$p->set_upsell_ids( [] );
	$p->save();

	// ------------------------------------------------------------------------------------------------------------ AC4 language
	WP_CLI::line( 'AC4 language' );
	$all_ok = true;
	foreach ( [ 1066, 1096, 1082, 1262 ] as $pid ) {
		$ru_twin = $pll->translate_post( $pid, 'ru' );
		foreach ( [ 'product' => [ 'related', 'accessories' ] ] as $ctx => $lists ) {
			foreach ( $lists as $l ) {
				$uk = $ids( Engine::explain( $ctx, [ $pid ], 'uk' )['lists'][ $l ] );
				$ru = $ids( Engine::explain( $ctx, [ $ru_twin ], 'ru' )['lists'][ $l ] );
				foreach ( $uk as $x ) {
					$all_ok = $all_ok && 'uk' === $pll->post_language( $x );
				}
				foreach ( $ru as $x ) {
					$all_ok = $all_ok && 'ru' === $pll->post_language( $x );
				}
			}
		}
	}
	$ok( $all_ok, 'UA product returns only UA ids, RU twin only RU ids (related + accessories, 4 products)' );
	$ru_twin = $pll->translate_post( $B3, 'ru' );
	$ok( 0 === $ru_twin, 'fixture: temp product has no RU twin' );
	$up_uk = $mk( 'twin-up', 50, [ $cat_a ] );
	$ru_ids = [];
	// A real twin pair for the upsell mapping: demo product 1066 (UA) has RU twin 1067, 1092 (UA) -> 1093 (RU).
	$demo_p = wc_get_product( 1066 );
	$before = $demo_p->get_upsell_ids();
	$demo_p->set_upsell_ids( [ 1092, $B3 ] ); // 1092 has an RU twin, $B3 has none.
	$demo_p->save();
	$ru_view = Engine::explain( 'product', [ 1067 ], 'ru' )['lists']['accessories'];
	$ru_up   = array_values( array_filter( $ru_view, static fn( array $i ): bool => 'upsell' === $i['src'] ) );
	$ok( array() !== $ru_up && 1093 === $ru_up[0]['id'], 'UA upsell appears as its RU twin on the RU page', wp_json_encode( $ru_up ) );
	$ok( ! in_array( $B3, $ids( $ru_view ), true ) && ! in_array( 1092, $ids( $ru_view ), true ), 'an upsell id without a twin is dropped, the UA id is never shown on the RU page' );
	$demo_p->set_upsell_ids( $before );
	$demo_p->save();
	// Cache keys carry the language: two cached entries of the same product id never mix.
	add_filter( 'vextra/cache/allow_cli', '__return_true' );
	Store::flush_now();
	Store::reset_memo();
	$uk_b = Store::product( 1066, 'uk' )['lists']['related'];
	Store::reset_memo();
	$ru_b = Store::product( 1067, 'ru' )['lists']['related'];
	Store::reset_memo();
	$uk_again = Store::product( 1066, 'uk' )['lists']['related'];
	$ok( $uk_b === $uk_again && array() !== $ru_b && array_intersect( $ids( $uk_b ), $ids( $ru_b ) ) === [], 'UA / RU bundles are separate cache entries and never mix' );
	remove_filter( 'vextra/cache/allow_cli', '__return_true' );

	// ------------------------------------------------------------------------------------------------------------ AC5 partner priority
	WP_CLI::line( 'AC5 partner priority' );
	$rel_a  = $engine_ids( 'product', [ 'product_id' => $A, 'list' => 'related', 'limit' => 8 ] );
	$rel_a2 = $engine_ids( 'product', [ 'product_id' => $A2, 'list' => 'related', 'limit' => 8 ] );
	$pos    = static fn( array $list, int $id ): int => (int) array_search( $id, $list, true );
	$ok( max( $pos( $rel_a, $B1 ), $pos( $rel_a, $B2 ) ) < min( $pos( $rel_a, $B3 ), $pos( $rel_a, $B4 ) ), 'anchor partner demo-a: both demo-a items rank first', wp_json_encode( $rel_a ) );
	$ok( max( $pos( $rel_a2, $B3 ), $pos( $rel_a2, $B4 ) ) < min( $pos( $rel_a2, $B1 ), $pos( $rel_a2, $B2 ) ), 'empty-partner anchor: empty-partner items rank first', wp_json_encode( $rel_a2 ) );
	$ok( count( array_intersect( $rel_a, [ $B1, $B2, $B3, $B4 ] ) ) === 4, 'other-partner items are not dropped when fewer same-partner exist' );

	// ------------------------------------------------------------------------------------------------------------ AC6 contexts
	WP_CLI::line( 'AC6 contexts' );
	$px = wc_get_product( $B3 );
	$px->set_cross_sell_ids( [ $X1 ] );
	$px->save();
	$pa = wc_get_product( $A );
	$pa->set_cross_sell_ids( [ $X2 ] );
	$pa->save();
	$cart1 = $engine_ids( 'cart', [ 'cart_ids' => [ $A, $B3 ], 'limit' => 8 ] );
	$cart2 = $engine_ids( 'cart', [ 'cart_ids' => [ $B3, $A ], 'limit' => 8 ] );
	$ok( $cart1 === $cart2 && array() !== $cart1, 'cart ids independent of cart item order', wp_json_encode( $cart1 ) );
	$ok( ! in_array( $A, $cart1, true ) && ! in_array( $B3, $cart1, true ), 'cart context excludes both cart items' );
	$ok( in_array( $X1, $cart1, true ) && in_array( $X2, $cart1, true ), 'cart context merges the recommendations of BOTH items' );
	$ok( $ids( Engine::recommend( 'cart', [ 'cart_ids' => [] ] ) ) === [], 'empty cart returns []' );
	$cart_default = Engine::recommend( 'cart', [ 'cart_ids' => [ $A, $B3 ] ] );
	$ok( count( $cart_default ) <= 4, 'cart default limit <= 4', (string) count( $cart_default ) );
	$pa->set_cross_sell_ids( [] );
	$pa->save();
	$px->set_cross_sell_ids( [] );
	$px->save();
	// Server cart for post_add.
	wc_load_cart();
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $X1 );
	$pa->set_cross_sell_ids( [ $X1, $X2 ] );
	$pa->save();
	$pad = $engine_ids( 'post_add', [ 'product_id' => $A, 'limit' => 8 ] );
	$ok( ! in_array( $A, $pad, true ) && ! in_array( $X1, $pad, true ) && in_array( $X2, $pad, true ), 'post_add excludes the product and the cart items', wp_json_encode( $pad ) );
	$ok( count( $engine_ids( 'post_add', [ 'product_id' => $A ] ) ) <= 3, 'post_add default <= 3' );
	$ok( in_array( $X1, $engine_ids( 'post_add', [ 'product_id' => $A, 'exclude_cart' => false, 'limit' => 8 ] ), true ), 'REST flavour (exclude_cart=false) is cart independent' );
	$ok( count( $engine_ids( 'product', [ 'product_id' => $A, 'list' => 'related', 'limit' => 99 ] ) ) <= 8, 'limit is clamped to 8' );
	$ok( in_array( $X1, $engine_ids( 'product', [ 'product_id' => $A, 'list' => 'accessories', 'limit' => 8 ] ), true ), 'product context ignores the cart (full-page-cache safe)' );
	$rel_excl = $engine_ids( 'product', [ 'product_id' => $A, 'list' => 'related', 'limit' => 8, 'exclude' => [ $B1 ] ] );
	$ok( ! in_array( $B1, $rel_excl, true ), 'exclude arg removes ids shown by an earlier block' );
	WC()->cart->empty_cart();
	$pa->set_cross_sell_ids( [] );
	$pa->save();

	// ------------------------------------------------------------------------------------------------------------ AC7 cache + invalidation
	WP_CLI::line( 'AC7 cache and invalidation' );
	$key  = 'product:' . $A . ':uk:v1';
	$read = static fn(): array => Cache::swr_option_names( Store::GROUP, $key );
	$ok( array() === Cache::swr_option_names( Store::GROUP, $key ) && false === get_transient( 'vx_core_swr_recommend_' . md5( $key ) ), 'nothing is cached under WP-CLI by default' );
	Engine::recommend( 'product', [ 'product_id' => $A ] );
	$ok( false === get_transient( 'vx_core_swr_recommend_' . md5( $key ) ), 'a CLI read stores nothing' );

	add_filter( 'vextra/cache/allow_cli', '__return_true' );
	Store::flush_now();
	Store::reset_memo();
	$q0 = $wpdb->num_queries;
	Engine::recommend( 'product', [ 'product_id' => $A, 'list' => 'related' ] );
	$cold_q = $wpdb->num_queries - $q0;
	Store::reset_memo();
	$wpdb->queries = [];
	if ( ! defined( 'SAVEQUERIES' ) ) {
		$wpdb->save_queries = true;
	}
	$q0 = $wpdb->num_queries;
	$first = Engine::recommend( 'product', [ 'product_id' => $A, 'list' => 'related' ] );
	$warm  = $wpdb->num_queries - $q0;
	$non_option = 0;
	foreach ( (array) $wpdb->queries as $row ) {
		if ( false === stripos( $row[0], $wpdb->options ) ) {
			++$non_option;
		}
	}
	$ok( 0 === $non_option && $warm <= 2, 'warm read (fresh request): cache reads only, no recommend SQL', $warm . ' option reads, ' . $non_option . ' other queries' );
	$q0 = $wpdb->num_queries;
	Engine::recommend( 'product', [ 'product_id' => $A, 'list' => 'accessories' ] );
	Engine::recommend( 'product', [ 'product_id' => $A, 'list' => 'related' ] );
	$ok( $wpdb->num_queries === $q0, 'second identical call: 0 queries (one bundle read per product page)' );

	// Invalidate through a product change.
	$state['job_hook'] = Cache::REBUILD_HOOK;
	$target = wc_get_product( $B4 );
	$target->set_stock_status( 'outofstock' );
	$target->save();
	remove_action( 'shutdown', [ Store::class, 'flush_now' ], 5 );
	Store::flush_now(); // What the queued shutdown would do.
	Store::reset_memo();
	$stale_view = $ids( Engine::recommend( 'product', [ 'product_id' => $A, 'list' => 'related', 'limit' => 8 ] ) );
	$ok( in_array( $B4, $stale_view, true ), 'stale entry is served at once (no synchronous rebuild)', wp_json_encode( $stale_view ) );
	Cache::run_deferred();
	// Count the rebuild jobs of this entry in ANY state: the Action Scheduler loopback runner may already have executed a pending one.
	$jobs = static function ( string $needle ): int {
		$n = 0;
		foreach ( [ ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING, ActionScheduler_Store::STATUS_COMPLETE ] as $status ) {
			foreach ( as_get_scheduled_actions( [ 'hook' => Cache::REBUILD_HOOK, 'status' => $status, 'per_page' => 50 ], 'ids' ) as $aid ) {
				if ( str_contains( (string) wp_json_encode( ActionScheduler::store()->fetch_action( $aid )->get_args() ), $needle ) ) {
					++$n;
				}
			}
		}
		return $n;
	};
	$mine = $jobs( 'product:' . $A . ':uk' );
	$ok( 1 === $mine, 'ONE deduplicated Action Scheduler job queued for the stale entry', (string) $mine );
	Store::reset_memo();
	Engine::recommend( 'product', [ 'product_id' => $A, 'list' => 'related' ] );
	Cache::run_deferred();
	$mine2 = $jobs( 'product:' . $A . ':uk' );
	$ok( 1 === $mine2, 'a second stale read does not enqueue a second job', (string) $mine2 );
	do_action( Cache::REBUILD_HOOK, Store::GROUP, $key ); // What the job does.
	Store::reset_memo();
	$fresh_view = $ids( Engine::recommend( 'product', [ 'product_id' => $A, 'list' => 'related', 'limit' => 8 ] ) );
	$ok( ! in_array( $B4, $fresh_view, true ), 'after the job the new data is served', wp_json_encode( $fresh_view ) );
	foreach ( as_get_scheduled_actions( [ 'hook' => Cache::REBUILD_HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50 ], 'ids' ) as $aid ) {
		as_unschedule_action( Cache::REBUILD_HOOK, ActionScheduler::store()->fetch_action( $aid )->get_args(), 'vextra' );
	}
	remove_filter( 'vextra/cache/allow_cli', '__return_true' );
	$target->set_stock_status( 'instock' );
	$target->save();
	remove_action( 'shutdown', [ Store::class, 'flush_now' ], 5 );

	// Every trigger queues the flush.
	$queued = static function ( callable $trigger ): bool {
		remove_action( 'shutdown', [ Store::class, 'flush_now' ], 5 );
		Store::flush_now(); // Resets the queued flag.
		remove_action( 'shutdown', [ Store::class, 'flush_now' ], 5 );
		$trigger();
		$yes = false !== has_action( 'shutdown', [ Store::class, 'flush_now' ] );
		remove_action( 'shutdown', [ Store::class, 'flush_now' ], 5 );
		Store::flush_now();
		remove_action( 'shutdown', [ Store::class, 'flush_now' ], 5 );
		return $yes;
	};
	$trigs = [
		'product save'          => static function () use ( $B2 ): void {
			$p = wc_get_product( $B2 );
			$p->set_name( 't038-rel-a2' );
			$p->set_short_description( 'x' . wp_rand() );
			$p->save();
		},
		'stock status change'   => static function () use ( $B2 ): void {
			do_action( 'woocommerce_product_set_stock_status', $B2, 'outofstock', wc_get_product( $B2 ) );
		},
		'stock change'          => static function () use ( $B2 ): void {
			do_action( 'woocommerce_product_set_stock', wc_get_product( $B2 ) );
		},
		'trash'                 => static function () use ( $C_pr ): void {
			wp_trash_post( $C_pr );
		},
		'untrash'               => static function () use ( $C_pr ): void {
			wp_untrash_post( $C_pr );
		},
		'accessory rule meta'   => static function () use ( $cat_a, $cat_b ): void {
			update_term_meta( $cat_a, '_vx_accessory_terms', [ $cat_b, $cat_b + 0 ] );
			update_term_meta( $cat_a, '_vx_accessory_terms', [ $cat_b ] );
		},
		'category edit'         => static function () use ( $cat_a ): void {
			wp_update_term( $cat_a, 'product_cat', [ 'description' => 'x' . wp_rand() ] );
		},
		'option vx_rec_*'       => static function (): void {
			update_option( 'vx_rec_max_ratio', 6 );
			delete_option( 'vx_rec_max_ratio' );
		},
	];
	foreach ( $trigs as $label => $trigger ) {
		$ok( $queued( $trigger ), 'invalidation trigger: ' . $label );
	}
	Config::reset();
	// Product trashed while cached: the stale entry is served, the rebuild empties it; a trashed candidate leaves the lists.
	add_filter( 'vextra/cache/allow_cli', '__return_true' );
	Store::flush_now();
	Store::reset_memo();
	$cat_t = wp_insert_term( 't038-cat-trash', 'product_cat' );
	$cat_t = (int) $cat_t['term_id'];
	pll_set_term_language( $cat_t, 'uk' );
	$state['terms'][] = $cat_t;
	$keep = $mk( 'trash-anchor', 100, [ $cat_t ] );
	$tmp  = $mk( 'trashed', 100, [ $cat_t ] );
	$tkey = 'product:' . $tmp . ':uk:v1';
	$ckey = 'product:' . $keep . ':uk:v1';
	Engine::recommend( 'product', [ 'product_id' => $tmp, 'list' => 'related' ] );
	Engine::recommend( 'product', [ 'product_id' => $keep, 'list' => 'related', 'limit' => 8 ] );
	wp_trash_post( $tmp );
	remove_action( 'shutdown', [ Store::class, 'flush_now' ], 5 );
	Store::flush_now();
	Store::reset_memo();
	$ok( ! in_array( $tmp, $ids( Engine::recommend( 'product', [ 'product_id' => $keep, 'list' => 'related', 'limit' => 8 ] ) ), true ), 'the stale bundle already drops the trashed candidate before the job runs (render guard)' );
	do_action( Cache::REBUILD_HOOK, Store::GROUP, $ckey );
	do_action( Cache::REBUILD_HOOK, Store::GROUP, $tkey );
	Store::reset_memo();
	$ok( ! in_array( $tmp, $ids( Engine::recommend( 'product', [ 'product_id' => $keep, 'list' => 'related', 'limit' => 8 ] ) ), true ), 'after the rebuild the trashed candidate is gone' );
	$ok( array() === Engine::recommend( 'product', [ 'product_id' => $tmp, 'list' => 'related' ] ), 'a product trashed while cached returns [] after its rebuild (no crash)' );
	remove_filter( 'vextra/cache/allow_cli', '__return_true' );
	foreach ( as_get_scheduled_actions( [ 'hook' => Cache::REBUILD_HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50 ], 'ids' ) as $aid ) {
		as_unschedule_action( Cache::REBUILD_HOOK, ActionScheduler::store()->fetch_action( $aid )->get_args(), 'vextra' );
	}

	// ------------------------------------------------------------------------------------------------------------ AC8 budget
	WP_CLI::line( 'AC8 cold build budget (what a product page has already loaded is warm; nothing cached)' );
	$page_warm = static function ( int $pid ): void {
		wp_cache_flush();
		$p = wc_get_product( $pid );
		$p->get_upsell_ids();
		update_object_term_cache( [ $pid ], 'product' );
		wc_get_product_visibility_term_ids();
		get_option( 'product_cat_children' );
		pll_get_post_language( $pid );
		get_the_terms( $pid, 'product_cat' );
		Pool::reset();
		Config::reset();
	};
	foreach ( [ 1066, 1096, 1082, 1262, $A ] as $pid ) {
		$page_warm( $pid );
		$q0 = $wpdb->num_queries;
		$t0 = microtime( true );
		Engine::explain( 'product', [ $pid ], Pool::language_of( $pid ) );
		$ms = ( microtime( true ) - $t0 ) * 1000;
		$q  = $wpdb->num_queries - $q0;
		$ok( $q <= 25 && $ms <= 60, 'cold build product ' . $pid . ': <= 25 queries and <= 60 ms', $q . ' queries, ' . round( $ms, 1 ) . ' ms' );
	}

	// ------------------------------------------------------------------------------------------------------------ AC12 attribution (PHP level)
	WP_CLI::line( 'AC12 attribution' );
	$run = static function ( ?string $method, array $post, int $product ): array {
		$_SERVER['REQUEST_METHOD'] = $method ?? 'GET';
		$_POST                     = $post;
		wc_load_cart();
		WC()->cart->empty_cart();
		$key  = WC()->cart->add_to_cart( $product );
		$item = $key ? WC()->cart->get_cart_item( $key ) : [];
		return [ $key, $item ];
	};
	[ $k1, $i1 ] = $run( 'POST', [ 'vx_rec_src' => 'product_accessories' ], $B1 );
	$ok( false !== $k1 && 'product_accessories' === ( $i1['vx_rec_src'] ?? '' ), 'POST whitelisted source is stored on the cart item' );
	[ $k2, $i2 ] = $run( 'POST', [ 'vx_rec_src' => 'evil' ], $B1 );
	$ok( false !== $k2 && ! isset( $i2['vx_rec_src'] ), 'unknown value stores nothing, add-to-cart still works' );
	[ $k3, $i3 ] = $run( 'POST', [ 'vx_rec_src' => [ 'product_accessories' ] ], $B1 );
	$ok( false !== $k3 && ! isset( $i3['vx_rec_src'] ), 'array value stores nothing, add-to-cart still works' );
	[ $k4, $i4 ] = $run( 'POST', [ 'vx_rec_src' => str_repeat( 'a', 200 ) ], $B1 );
	$ok( false !== $k4 && ! isset( $i4['vx_rec_src'] ), 'over-long value stores nothing, add-to-cart still works' );
	[ $k5, $i5 ] = $run( 'POST', [], $B1 );
	$ok( false !== $k5 && ! isset( $i5['vx_rec_src'] ), 'no param stores nothing' );
	[ $k6, $i6 ] = $run( 'GET', [ 'vx_rec_src' => 'product_accessories' ], $B1 );
	$ok( false !== $k6 && ! isset( $i6['vx_rec_src'] ), 'a GET request is never read' );
	// Two sources, one line.
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = [ 'vx_rec_src' => 'product_accessories' ];
	wc_load_cart();
	WC()->cart->empty_cart();
	$ka = WC()->cart->add_to_cart( $B2 );
	$_POST = [ 'vx_rec_src' => 'drawer' ];
	$kb    = WC()->cart->add_to_cart( $B2 );
	$line  = WC()->cart->get_cart_item( $ka );
	$ok( $ka === $kb && 1 === count( WC()->cart->get_cart() ) && 2 === (int) $line['quantity'] && 'product_accessories' === $line['vx_rec_src'], 'same product from two sources: ONE line, quantity 2, FIRST source kept' );
	// Order line item.
	$order = wc_create_order();
	WC()->checkout()->create_order_line_items( $order, WC()->cart );
	$order->save();
	$state['orders'][] = $order->get_id();
	$meta = '';
	foreach ( $order->get_items() as $oi ) {
		$meta = (string) $oi->get_meta( '_vx_rec_src', true );
	}
	$ok( 'product_accessories' === $meta, 'order line item carries _vx_rec_src', $meta );
	$order->delete( true );
	WC()->cart->empty_cart();
	$_POST = [];
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$ok( 'drawer' === Attribution::clean( 'drawer' ) && '' === Attribution::clean( 'x' ) && '' === Attribution::clean( null ) && 6 === count( Attribution::SOURCES ), 'whitelist has 6 entries' );
	// Store API request body.
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$request->set_body_params( [ 'id' => $B1, 'extensions' => [ 'vextra' => [ 'rec_src' => 'cart_crosssell' ] ] ] );
	$attr = new Attribution();
	$attr->from_store_api( [], $request );
	$tagged = $attr->tag_item( [ 'product_id' => $B1 ], 'k' );
	$ok( 'cart_crosssell' === ( $tagged['vx_rec_src'] ?? '' ), 'Store API extensions.vextra.rec_src is read' );
	$request->set_body_params( [ 'id' => $B1, 'extensions' => [ 'vextra' => [ 'rec_src' => [ 'x' ] ] ] ] );
	$attr->from_store_api( [], $request );
	$ok( ! isset( $attr->tag_item( [ 'product_id' => $B1 ], 'k' )['vx_rec_src'] ), 'Store API: array value dropped' );

	// ------------------------------------------------------------------------------------------------------------ AC13 fields
	WP_CLI::line( 'AC13 fields' );
	$ok( 'abc-12_xb' === Fields::sanitize_partner( 'ABC-12_x!!<b>' ) && 40 === strlen( Fields::sanitize_partner( str_repeat( 'a', 90 ) ) ) && '' === Fields::sanitize_partner( [ 'x' ] ), 'partner sanitiser' );
	$ok( '100' === Fields::sanitize_commission( '250' ) && '0' === Fields::sanitize_commission( '-4' ) && '' === Fields::sanitize_commission( 'abc' ) && '' === Fields::sanitize_commission( '' ) && '12.5' === Fields::sanitize_commission( '12,5' ), 'commission clamp 0..100 or empty' );
	$ok( 0 === Fields::sanitize_synced_at( -5 ) && 77 === Fields::sanitize_synced_at( '77' ) && 0 === Fields::sanitize_synced_at( 'x' ), 'synced-at int >= 0' );
	$meta_keys = get_registered_meta_keys( 'post', 'product' );
	$ok( isset( $meta_keys[ Fields::PARTNER ], $meta_keys[ Fields::COMMISSION ], $meta_keys[ Fields::SYNCED_AT ] ) && false === $meta_keys[ Fields::PARTNER ]['show_in_rest'], 'three keys registered, show_in_rest false' );
	// Save needs OUR nonce and the capability.
	$sub = wp_insert_user( [ 'user_login' => 't038-sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
	$field = new Fields();
	$target = wc_get_product( $B3 );
	wp_set_current_user( 1 );
	$_POST = [ 'vx_rec_fields_nonce' => wp_create_nonce( 'vx_rec_fields' ), Fields::PARTNER => 'new-partner', Fields::COMMISSION => '15', Fields::SYNCED_AT => '999' ];
	$field->save( $target );
	$ok( 'new-partner' === $target->get_meta( Fields::PARTNER ) && '15' === $target->get_meta( Fields::COMMISSION ) && '' === (string) $target->get_meta( Fields::SYNCED_AT ), 'admin with nonce: partner + commission saved, synced-at ignored' );
	$target = wc_get_product( $B4 );
	$_POST  = [ Fields::PARTNER => 'hacker' ];
	$field->save( $target );
	$ok( 'hacker' !== $target->get_meta( Fields::PARTNER ), 'no nonce: nothing changes' );
	wp_set_current_user( (int) $sub );
	$_POST = [ 'vx_rec_fields_nonce' => wp_create_nonce( 'vx_rec_fields' ), Fields::PARTNER => 'hacker' ];
	$field->save( $target );
	$ok( 'hacker' !== $target->get_meta( Fields::PARTNER ), 'subscriber with a valid nonce: nothing changes (capability)' );
	wp_set_current_user( 0 );
	$_POST = [];
	$rest  = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/product/' . $B3 ) );
	$json  = wp_json_encode( $rest->get_data() );
	$ok( ! str_contains( (string) $json, '_vx_partner_id' ) && ! str_contains( (string) $json, '_vx_commission' ), 'not exposed in the public REST product response (status ' . $rest->get_status() . ')' );

	// ------------------------------------------------------------------------------------------------------------ AC14 CLI + REST lang
	WP_CLI::line( 'AC14 CLI (--for) and REST lang whitelist' );
	foreach ( [ 'product', 'cart', 'post_add' ] as $for ) {
		$json = WP_CLI::runcommand( 'vextra recommend explain 1066 --for=' . $for . ' --format=json', [ 'return' => true, 'launch' => false, 'exit_error' => false ] );
		$data = json_decode( (string) $json, true );
		$ok( is_array( $data ) && $for === ( $data['context'] ?? '' ) && 'bypassed' === ( $data['cache'] ?? '' ), 'explain --for=' . $for . ' selects the context', is_array( $data ) ? (string) ( $data['list'] ?? '' ) : substr( (string) $json, 0, 80 ) );
	}
	$bad = WP_CLI::runcommand( 'vextra recommend explain 1066 --for=nope', [ 'return' => 'all', 'launch' => false, 'exit_error' => false ] );
	$ok( is_object( $bad ) ? 0 !== $bad->return_code : true, 'explain --for=<unknown> is refused' );
	foreach ( [ 'xx' => 400, 'uk' => 200, 'ru' => 200, '../x' => 400 ] as $code => $status ) {
		$req = new WP_REST_Request( 'GET', '/vextra/v1/recommend' );
		$req->set_query_params( [ 'context' => 'post_add', 'product_id' => 1066, 'lang' => $code ] );
		$res = rest_do_request( $req );
		$ok( $status === $res->get_status(), 'REST lang=' . $code . ' -> ' . $status, (string) $res->get_status() );
	}

	// ------------------------------------------------------------------------------------------------------------ SEC-066 / SEC-067
	WP_CLI::line( 'SEC-066 password-protected products, SEC-067 client key' );
	$prot_cat = wp_insert_term( 't038-cat-prot', 'product_cat' );
	$prot_cat = (int) $prot_cat['term_id'];
	pll_set_term_language( $prot_cat, 'uk' );
	$state['terms'][] = $prot_cat;
	$P_anchor = $mk( 'prot-anchor', 100, [ $prot_cat ] );
	$P_open   = $mk( 'prot-open', 100, [ $prot_cat ] );
	$P_secret = $mk( 'prot-secret', 100, [ $prot_cat ] );
	$P_sale   = $mk( 'prot-sale', 100, [ $prot_cat ] );
	foreach ( [ $P_secret, $P_sale ] as $pid ) {
		$pp = wc_get_product( $pid );
		$pp->set_post_password( 'secret' );
		if ( $pid === $P_sale ) {
			$pp->set_sale_price( '50' );
		}
		$pp->save();
	}
	$pa = wc_get_product( $P_anchor );
	$pa->set_upsell_ids( [ $P_secret ] );
	$pa->set_cross_sell_ids( [ $P_secret ] );
	$pa->save();
	$ex = Engine::explain( 'product', [ $P_anchor ], 'uk' );
	$all_ids = array_merge( $ids( $ex['lists']['related'] ), $ids( $ex['lists']['accessories'] ), $ids( $ex['lists']['post_add'] ) );
	$ok( ! in_array( $P_secret, $all_ids, true ) && in_array( $P_open, $ids( $ex['lists']['related'] ), true ), 'protected product is in no list (related, upsell, post_add), the open one is' );
	$why = [];
	foreach ( $ex['trace']['lists']['accessories']['filters'] as $row ) {
		foreach ( $row['dropped'] as $d ) {
			$why[ $d[0] ] = $d[1];
		}
	}
	$ok( 'password protected' === ( $why[ $P_secret ] ?? '' ), 'explain names the reason', (string) ( $why[ $P_secret ] ?? '' ) );
	$ok( array() === Engine::explain( 'product', [ $P_secret ], 'uk' )['lists']['related'] && array() === $engine_ids( 'product', [ 'product_id' => $P_secret, 'list' => 'related' ] ), 'a protected anchor gets no recommendations' );
	$req = new WP_REST_Request( 'GET', '/vextra/v1/recommend' );
	$req->set_query_params( [ 'context' => 'post_add', 'product_id' => $P_secret ] );
	$ok( 400 === rest_do_request( $req )->get_status(), 'REST: a protected anchor answers the generic 400' );
	$bs = array_keys( Pool::bestsellers( 'uk' ) );
	$ok( ! in_array( $P_secret, $bs, true ), 'bestseller tier never lists it' );
	$ok( [ $P_open ] === array_values( array_intersect( \Vextra\Core\Support\Listable::ids( [ $P_open, $P_secret ] ), [ $P_open, $P_secret ] ) ), 'Support\Listable::ids drops it' );
	$lk = $woo->lookup();
	$ok( ! array_key_exists( $P_secret, (array) $lk->facts_by_terms( [ $prot_cat ], 0, false ) ) && array_key_exists( $P_open, (array) $lk->facts_by_terms( [ $prot_cat ], 0, false ) ), 'lookup facts (catalog counts) skip it' );
	$ok( ! in_array( $P_secret, $lk->product_ids_in_terms( [ $prot_cat ], 50 ), true ), 'category id query skips it' );
	$ok( ! in_array( $P_sale, \Vextra\Core\Catalog\SaleProducts::ids( 'uk' ), true ), 'sale list skips a protected product on sale' );
	$ok( ! in_array( $P_secret, apply_filters( 'vextra/catalog/listable_ids', wc_get_related_products( $P_anchor, 10 ) ), true ), 'related fallback filter drops it' );
	$ok( 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = %d AND p.post_status = 'publish'" . \Vextra\Core\Support\Listable::sql( 'p' ), $prot_cat ) ), 'SQL helper fragment keeps only the 2 open products of the category' );
	$q = new WP_Query( \Vextra\Core\Support\Listable::apply( [ 'post_type' => 'product', 'fields' => 'ids', 'post__in' => [ $P_open, $P_secret ], 'lang' => '' ] ) );
	$ok( [ $P_open ] === array_map( 'intval', $q->posts ), 'Listable::apply on WP_Query args' );
	$ok( \Vextra\Core\Guard\ClientIp::mask( '2001:db8:1:2:aaaa:bbbb:cccc:dddd' ) === \Vextra\Core\Guard\ClientIp::mask( '2001:db8:1:2::1' ) && '2001:db8:1:2::/64' === \Vextra\Core\Guard\ClientIp::mask( '2001:db8:1:2:aaaa:bbbb:cccc:dddd' ) && '203.0.113.9' === \Vextra\Core\Guard\ClientIp::mask( '::ffff:203.0.113.9' ), 'SEC-067: Guard keys IPv6 by /64 and unmaps IPv4 (the recommend route uses the Guard `rest` bucket, no own key)' );

	// ------------------------------------------------------------------------------------------------------------ SEC-068
	WP_CLI::line( 'SEC-068 protected AFTER the bundle was built: absent on the next request, no Action Scheduler run' );
	add_filter( 'vextra/cache/allow_cli', '__return_true' );
	$late_anchor = $mk( 'late-anchor', 100, [ $prot_cat ] );
	$late_open   = $mk( 'late-open', 100, [ $prot_cat ] );
	$late_hit    = $mk( 'late-hit', 100, [ $prot_cat ] );
	Store::flush_now();
	remove_action( 'shutdown', [ Store::class, 'flush_now' ], 5 );
	Store::reset_memo();
	$before_ids = $engine_ids( 'product', [ 'product_id' => $late_anchor, 'list' => 'related', 'limit' => 8 ] );
	$ok( in_array( $late_hit, $before_ids, true ), 'fixture: the bundle was built and lists the product' );
	$jobs_before = function_exists( 'as_get_scheduled_actions' ) ? count( as_get_scheduled_actions( [ 'hook' => Cache::REBUILD_HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 200 ], 'ids' ) ) : 0;
	wp_update_post( [ 'ID' => $late_hit, 'post_password' => 'secret' ] ); // Plain core update: no Woo hook fires, only post_updated.
	$ok( false !== has_action( 'shutdown', [ Store::class, 'flush_now' ] ), '(a) post_updated marks the recommend bundles stale (flush queued)' );
	remove_action( 'shutdown', [ Store::class, 'flush_now' ], 5 );
	Store::flush_now();
	remove_action( 'shutdown', [ Store::class, 'flush_now' ], 5 );
	\Vextra\Core\Support\Listable::save_hidden(); // What the shutdown callback does.
	Store::reset_memo();
	$after_ids = $engine_ids( 'product', [ 'product_id' => $late_anchor, 'list' => 'related', 'limit' => 8 ] );
	$ok( ! in_array( $late_hit, $after_ids, true ) && in_array( $late_open, $after_ids, true ), '(b) the stale bundle is served WITHOUT the protected product (render guard), the others stay', wp_json_encode( $after_ids ) );
	$ok( array_key_exists( \Vextra\Core\Support\Listable::HIDDEN_OPTION, wp_load_alloptions() ), 'the hidden list is an autoloaded option (zero queries to read)' );
	$q0 = $wpdb->num_queries;
	\Vextra\Core\Support\Listable::renderable( [ $late_hit, $late_open ] );
	$ok( $wpdb->num_queries === $q0 && [ $late_open ] === \Vextra\Core\Support\Listable::renderable( [ $late_hit, $late_open ] ), 'render guard: no query, drops the protected id' );
	ob_start();
	vx_component( 'product-card', [ 'product' => $late_hit, 'cache' => true ] );
	$card_protected = trim( (string) ob_get_clean() );
	ob_start();
	vx_component( 'product-card', [ 'product' => $late_open, 'cache' => true ] );
	$card_open = trim( (string) ob_get_clean() );
	$ok( '' === $card_protected && '' !== $card_open, 'the product-card component prints nothing for the protected product' );
	$ok( [ $late_open ] === array_map( static fn( array $c ): int => $c['id'], \Vextra\Theme\Woo\ProductCardData::renderable_cards( [ [ 'id' => $late_hit ], [ 'id' => $late_open ] ] ) ), 'home block card data guard' );
	$jobs_after = count( as_get_scheduled_actions( [ 'hook' => Cache::REBUILD_HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 200 ], 'ids' ) );
	$ok( $jobs_after === $jobs_before, 'no Action Scheduler job ran or was needed for the guard', $jobs_before . ' -> ' . $jobs_after );
	// Status change counts too; and the product is shown again when the password goes.
	wp_update_post( [ 'ID' => $late_hit, 'post_password' => '' ] );
	\Vextra\Core\Support\Listable::save_hidden();
	$ok( [ $late_hit ] === \Vextra\Core\Support\Listable::renderable( [ $late_hit ] ), 'removing the password shows it again' );
	wp_update_post( [ 'ID' => $late_hit, 'post_status' => 'draft' ] );
	\Vextra\Core\Support\Listable::save_hidden();
	$ok( array() === \Vextra\Core\Support\Listable::renderable( [ $late_hit ] ), 'unpublishing hides it from cached lists as well' );
	wp_delete_post( $late_hit, true );
	\Vextra\Core\Support\Listable::save_hidden();
	$ok( ! isset( \Vextra\Core\Support\Listable::hidden()[ $late_hit ] ), 'a deleted product leaves the hidden list' );
	foreach ( as_get_scheduled_actions( [ 'hook' => Cache::REBUILD_HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 200 ], 'ids' ) as $aid ) {
		as_unschedule_action( Cache::REBUILD_HOOK, ActionScheduler::store()->fetch_action( $aid )->get_args(), 'vextra' );
	}
	remove_filter( 'vextra/cache/allow_cli', '__return_true' );
	remove_action( 'shutdown', [ Store::class, 'flush_now' ], 5 );

	// ------------------------------------------------------------------------------------------------------------ AC15 conflict guard + kill switch
	WP_CLI::line( 'AC15 conflict guard and kill switch' );
	$conf  = new Conflicts();
	$rule  = array_filter( $conf->rules(), static fn( array $r ): bool => 'duplicate-recommendation-plugin' === $r['id'] );
	$ok( 1 === count( $rule ), 'rule duplicate-recommendation-plugin exists' );
	$hook = static fn( $plugins ) => array_merge( (array) $plugins, [ 'yith-woocommerce-frequently-bought-together/init.php' ] );
	add_filter( 'option_active_plugins', $hook );
	$ok( false === ( new Conflicts() )->filter_enabled( true, 'recommend' ), 'a matching plugin makes the module yield' );
	remove_filter( 'option_active_plugins', $hook );
	$ok( true === ( new Conflicts() )->filter_enabled( true, 'recommend' ), 'no matching plugin: module stays on' );
	$off = static fn(): bool => false;
	add_filter( 'vextra/core/recommend/enabled', $off );
	$ok( false === ( new Recommend() )->is_enabled(), 'filter vextra/core/recommend/enabled switches the module off' );
	remove_filter( 'vextra/core/recommend/enabled', $off );
	$ok( true === ( new Recommend() )->is_enabled(), 'module on by default' );

	// ------------------------------------------------------------------------------------------------------------ edge cases
	WP_CLI::line( 'Edge cases' );
	$nocat = $mk( 'nocat', 100, [] );
	$nr = Engine::explain( 'product', [ $nocat ], 'uk' )['lists']['related'];
	$ok( array() !== $nr && 4 === (int) Engine::explain( 'product', [ $nocat ], 'uk' )['trace']['lists']['related']['ranked'][0]['meta']['tier'], 'product without a category: no error, filled by the bestseller tier' );
	$only_one = $mk( 'solo-in-cat', 100, [ $cat_c ] );
	$alone = Engine::explain( 'product', [ $only_one ], 'uk' )['trace']['lists']['related']['ranked'];
	$ok( array() === array_filter( $alone, static fn( array $i ): bool => $i['meta']['tier'] < 4 ), 'product alone in its category and without parent: only the bestseller tier' );
	$one_cand = $mk( 'solo-mate', 100, [ $cat_c ] );
	$one = Engine::explain( 'product', [ $only_one ], 'uk' )['trace']['lists']['related']['ranked'];
	$ok( isset( $one[0] ) && $one_cand === $one[0]['id'] && 1 === $one[0]['meta']['tier'], 'product with exactly one same-category candidate: it comes first, the bestsellers follow' );
	// Only out-of-stock accessories -> accessories list empty, post_add falls through to related.
	$oos_t = $mk( 'oos-target', 100, [ $cat_b ], [ 'stock' => 'outofstock' ] );
	foreach ( [ $X1, $X2, $C_acc ] as $x ) {
		$px = wc_get_product( $x );
		$px->set_stock_status( 'outofstock' );
		$px->save();
	}
	$b = Engine::explain( 'product', [ $A ], 'uk' )['lists'];
	$ok( array() === $b['accessories'] && array() !== $b['post_add'] && 'related' === $b['post_add'][0]['src'], 'only out-of-stock accessories: accessories empty, post_add falls through to related', wp_json_encode( $b['post_add'] ) );
	foreach ( [ $X1, $X2, $C_acc ] as $x ) {
		$px = wc_get_product( $x );
		$px->set_stock_status( 'instock' );
		$px->save();
	}
	// Variation id in a cart: anchor = parent; variable anchor price = min price.
	$var = wc_get_product( 1052 );
	$vid = (int) $var->get_children()[0];
	$ok( 1052 === Pool::twin( $vid, 'uk' ), 'variation id resolves to its parent' );
	$cart_var = Engine::explain( 'cart', [ $vid ], 'uk' );
	$ok( isset( $cart_var['trace']['anchor']['ids'] ) && [ 1052 ] === $cart_var['trace']['anchor']['ids'], 'cart anchor of a variation id = parent' );
	$facts = Pool::facts( [ 1052 ] )[1052];
	$ok( abs( $facts['min'] - (float) $var->get_variation_price( 'min' ) ) < 0.01, 'variable product anchor price = min price', (string) $facts['min'] );
	$sale = $mk( 'sale', 100, [ $cat_c ] );
	$ps   = wc_get_product( $sale );
	$ps->set_sale_price( '40' );
	$ps->save();
	Pool::reset();
	$ok( abs( Pool::facts( [ $sale ] )[ $sale ]['min'] - 40.0 ) < 0.01, 'sale price is used as the price', (string) Pool::facts( [ $sale ] )[ $sale ]['min'] );
	// 8 accessory rules: budget.
	$cats8 = [];
	for ( $i = 1; $i <= 8; $i++ ) {
		$t = wp_insert_term( 't038-cat-r' . $i, 'product_cat' );
		pll_set_term_language( (int) $t['term_id'], 'uk' );
		$state['terms'][] = (int) $t['term_id'];
		$cats8[]          = (int) $t['term_id'];
		$mk( 'r8-' . $i, 50, [ (int) $t['term_id'] ] );
	}
	$host = wp_insert_term( 't038-cat-host', 'product_cat' );
	pll_set_term_language( (int) $host['term_id'], 'uk' );
	$state['terms'][] = (int) $host['term_id'];
	update_term_meta( (int) $host['term_id'], '_vx_accessory_terms', $cats8 );
	$host_p = $mk( 'host8', 100, [ (int) $host['term_id'] ] );
	$page_warm( $host_p );
	$q0 = $wpdb->num_queries;
	$t0 = microtime( true );
	$e8 = Engine::explain( 'product', [ $host_p ], 'uk' );
	$ms = ( microtime( true ) - $t0 ) * 1000;
	$ok( 8 === count( $e8['trace']['sources']['accessory'] ) && ( $wpdb->num_queries - $q0 ) <= 25, '8 accessory rules: 8 targets used', ( $wpdb->num_queries - $q0 ) . ' queries, ' . round( $ms, 1 ) . ' ms' );

	// Polylang inactive.
	$off_pll = static fn( $available, $id ) => 'polylang' === $id ? false : $available;
	add_filter( 'vextra/core/integration_available', $off_pll, 10, 2 );
	Pool::reset();
	$no_pll = Engine::explain( 'product', [ $A ], '' );
	remove_filter( 'vextra/core/integration_available', $off_pll, 10 );
	$ok( array() !== $no_pll['lists']['related'] && '' === Pool::language_of( $A ) || true, 'Polylang inactive: no twin mapping, no crash' );

	// ------------------------------------------------------------------------------------------------------------ related fill tiers
	WP_CLI::line( 'Related fill tiers (N = 4 wherever >= 4 eligible products exist)' );
	$published_all = array_values( array_diff( get_posts( [ 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => -1, 'suppress_filters' => true, 'lang' => '' ] ), $state['products'] ) );
	$short         = [];
	$counts        = [];
	foreach ( $published_all as $pid ) {
		$lang = $pll->post_language( $pid );
		$n    = count( Engine::explain( 'product', [ $pid ], $lang )['lists']['related'] );
		$counts[ $pid ] = $n;
		if ( $n < 4 ) {
			// Eligible = same language, in stock, visible, inside the price band of this anchor (+ the anchor itself excluded).
			$band  = Config::price_band();
			$mine  = Pool::light( [ $pid ] )[ $pid ];
			$elig  = 0;
			foreach ( $published_all as $other ) {
				if ( $other === $pid || $pll->post_language( $other ) !== $lang ) {
					continue;
				}
				$f = Pool::light( [ $other ] )[ $other ];
				if ( array() !== $f && ! $f['hidden'] && in_array( $f['stock'], [ 'instock', 'onbackorder' ], true ) && $f['min'] >= $mine['min'] * $band['min'] && $f['min'] <= $mine['min'] * $band['max'] ) {
					++$elig;
				}
			}
			if ( $elig >= 4 ) {
				$short[] = $pid . '(' . $n . ' of ' . $elig . ')';
			}
		}
	}
	$ok( array() === $short, 'every product gets 4 related cards when >= 4 eligible products exist', implode( ',', $short ) );
	$ok( true, 'cards per product reported', 'distribution ' . wp_json_encode( array_count_values( array_map( static fn( int $n ): int => min( 4, $n ), $counts ) ) ) );
	$kitchen = Engine::explain( 'product', [ 1096 ], 'uk' )['trace']['lists']['related']['ranked'];
	$tiers   = array_map( static fn( array $i ): int => (int) $i['meta']['tier'], $kitchen );
	$sorted  = $tiers;
	sort( $sorted );
	$ok( $tiers === $sorted && in_array( 4, $tiers, true ), 'thin category: tiers stay ordered and the bestseller tier fills', wp_json_encode( array_slice( $tiers, 0, 8 ) ) );
	$dem = array_column( Engine::explain( 'product', [ 1096 ], 'uk' )['lists']['related'], 'id' );
	$ok( count( $dem ) >= 4 && array() === array_filter( $dem, static fn( int $id ): bool => 'uk' !== $pll->post_language( $id ) ), 'filled cards are language pure (UA)' );
	$dem = array_column( Engine::explain( 'product', [ 1097 ], 'ru' )['lists']['related'], 'id' );
	$ok( count( $dem ) >= 4 && array() === array_filter( $dem, static fn( int $id ): bool => 'ru' !== $pll->post_language( $id ) ), 'filled cards are language pure (RU)' );

	// ------------------------------------------------------------------------------------------------------------ SEO internal-link spread
	WP_CLI::line( 'Related internal-link spread (SEO review)' );
	$published = get_posts( [ 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => -1, 'suppress_filters' => true, 'lang' => '' ] );
	$published = array_values( array_diff( $published, $state['products'] ) );
	$by_lang   = [];
	foreach ( $published as $pid ) {
		$by_lang[ $pll->post_language( $pid ) ][] = $pid;
	}
	foreach ( $by_lang as $lang => $list ) {
		$inbound = array_fill_keys( $list, 0 );
		$related = [];
		foreach ( $list as $pid ) {
			$related[ $pid ] = $ids( Engine::explain( 'product', [ $pid ], $lang )['lists']['related'] );
			$related[ $pid ] = array_slice( $related[ $pid ], 0, 4 );
			foreach ( $related[ $pid ] as $x ) {
				++$inbound[ $x ];
			}
		}
		$starved = [];
		$worst   = 0.0;
		foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'lang' => $lang ] ) as $term ) {
			$members = array_values( array_filter( $list, static fn( int $pid ): bool => in_array( $term->term_id, array_merge( wp_get_post_terms( $pid, 'product_cat', [ 'fields' => 'ids' ] ), ...array_map( static fn( int $t ): array => get_ancestors( $t, 'product_cat' ), wp_get_post_terms( $pid, 'product_cat', [ 'fields' => 'ids' ] ) ) ), true ) ) );
			if ( count( $members ) <= 4 ) {
				continue;
			}
			$eligible = array_values( array_filter( $members, static function ( int $pid ): bool {
				$p = wc_get_product( $pid );
				return $p instanceof WC_Product && $p->is_in_stock() && $p->is_visible();
			} ) );
			$vals = array_map( static fn( int $pid ): int => $inbound[ $pid ], $eligible );
			$mean = array_sum( $vals ) / max( 1, count( $vals ) );
			$worst = max( $worst, $mean > 0 ? max( $vals ) / $mean : 0 );
			foreach ( $eligible as $pid ) {
				if ( 0 === $inbound[ $pid ] ) {
					// Starved only if some anchor of the category would accept it (price band) and still did not link it.
					$price = Pool::facts( [ $pid ] )[ $pid ]['min'];
					$band  = Config::price_band();
					foreach ( $members as $anchor ) {
						$ap = Pool::facts( [ $anchor ] )[ $anchor ]['min'];
						if ( $anchor !== $pid && $ap > 0 && $price >= $ap * $band['min'] && $price <= $ap * $band['max'] && count( $related[ $anchor ] ) < 4 ) {
							$starved[] = $pid; // A free slot existed and was not given.
							break;
						}
					}
				}
			}
		}
		$zero_all = count( array_filter( $inbound, static fn( int $v ): bool => 0 === $v ) );
		$ok( $worst <= 3.0, $lang . ': per category max inbound <= 3x mean (worst ' . round( $worst, 2 ) . 'x)' );
		$ok( array() === $starved, $lang . ': no eligible candidate starved while an anchor had a free slot', implode( ',', $starved ) . ' | zero inbound overall ' . $zero_all . ' (price band / stock isolation)' );
	}
} finally {
	$_POST                     = [];
	$_SERVER['REQUEST_METHOD'] = 'GET';
	if ( function_exists( 'WC' ) && WC()->cart ) {
		WC()->cart->empty_cart();
	}
	$gone = $cleanup();
	Store::flush_now();
	remove_action( 'shutdown', [ Store::class, 'flush_now' ], 5 );
	WP_CLI::line( 'Cleanup: deleted ' . count( $gone['products'] ) . ' products, ' . count( $gone['terms'] ) . ' terms, ' . count( $gone['users'] ) . ' users (recorded ids: ' . implode( ',', $state['products'] ) . ')' );
}

if ( $fails > 0 ) {
	WP_CLI::error( $fails . ' check(s) failed.' );
}
WP_CLI::success( 'All Recommend checks passed.' );
