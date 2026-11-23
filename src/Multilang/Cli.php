<?php
/**
 * WP-CLI: `wp vextra multilang pages [--dry-run]` and `wp vextra multilang sync-products [--ids=<ids>] [--dry-run]`.
 *
 * Production-safe seeder (no theme dev script): both commands are idempotent, CLI only (no web entry point).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Multilang;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;

final class Cli {

	/**
	 * RU page titles by Woo page key.
	 */
	private const RU_TITLES = [
		'shop'      => 'Каталог',
		'cart'      => 'Корзина',
		'checkout'  => 'Оформление заказа',
		'myaccount' => 'Мой аккаунт',
	];

	private const TARGET_LANG = 'ru';

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	/**
	 * Product sync service.
	 *
	 * @var ProductSync
	 */
	private ProductSync $sync;

	/**
	 * Stock sync service.
	 *
	 * @var StockSync
	 */
	private StockSync $stock;

	public function __construct( Polylang $polylang, ProductSync $sync, StockSync $stock ) {
		$this->polylang = $polylang;
		$this->sync     = $sync;
		$this->stock    = $stock;
	}

	public function register(): void {
		\WP_CLI::add_command(
			'vextra multilang pages',
			[ $this, 'pages' ],
			[
				'shortdesc' => 'Create the missing RU translations of the shop, cart, checkout and my-account pages (idempotent).',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Report what would be created, write nothing.',
						'optional'    => true,
					],
				],
			]
		);
		\WP_CLI::add_command(
			'vextra multilang sync-products',
			[ $this, 'sync_products' ],
			[
				'shortdesc' => 'Align translation twins from the default-language products (price, stock, SKU, dimensions, images...).',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'ids',
						'description' => 'Comma-separated default-language product IDs (default: all).',
						'optional'    => true,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Report only, write nothing.',
						'optional'    => true,
					],
				],
			]
		);
		\WP_CLI::add_command(
			'vextra multilang stock-audit',
			[ $this, 'stock_audit' ],
			[
				'shortdesc' => 'List stock mismatches between translation twins (read-only); --fix repairs them from the default-language product.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'fix',
						'description' => 'Repair the twins (default-language product wins).',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'format',
						'description' => 'table (default) or json.',
						'optional'    => true,
						'options'     => [ 'table', 'json' ],
					],
					[
						'type'        => 'assoc',
						'name'        => 'ids',
						'description' => 'Comma-separated default-language product IDs (default: all).',
						'optional'    => true,
					],
				],
			]
		);
	}

	/**
	 * Default-language product IDs (all, or the given list).
	 *
	 * @param array<string,string> $assoc Assoc args.
	 * @return int[]
	 */
	private function source_ids( array $assoc ): array {
		$default = $this->polylang->default_language();
		if ( '' === $default ) {
			\WP_CLI::error( 'Polylang default language is unknown.' );
		}
		if ( ! empty( $assoc['ids'] ) ) {
			return array_values( array_filter( array_map( 'absint', explode( ',', (string) $assoc['ids'] ) ) ) );
		}
		$ids      = [];
		$page     = 1;
		$per_page = 100;
		$fetched  = $per_page;
		while ( $fetched === $per_page ) {
			$batch   = get_posts(
				[
					'post_type'        => 'product',
					'post_status'      => [ 'publish', 'draft', 'pending', 'private' ],
					'lang'             => $default,
					'fields'           => 'ids',
					'posts_per_page'   => $per_page,
					'paged'            => $page,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'no_found_rows'    => true,
					'suppress_filters' => false,
				]
			);
			$ids     = array_merge( $ids, $batch );
			$fetched = count( $batch );
			++$page;
		}
		return $ids;
	}

	/**
	 * Load default-language products in batches.
	 *
	 * @param int[] $ids Product IDs.
	 * @return \Generator<int,\WC_Product>
	 */
	private function products( array $ids ): \Generator {
		foreach ( array_chunk( $ids, 50 ) as $batch ) {
			$products = wc_get_products(
				[
					'include' => $batch,
					'limit'   => 50,
					'status'  => [ 'publish', 'draft', 'pending', 'private' ],
					'orderby' => 'none',
				]
			);
			foreach ( $products as $product ) {
				yield $product;
			}
		}
	}

	/**
	 * Audit the stock fields of the groups.
	 *
	 * @param int[] $ids Default-language product IDs.
	 * @return array<int,array{product:int,twin:int,lang:string,field:string,source:string,value:string}>
	 */
	private function audit_ids( array $ids ): array {
		$rows = [];
		foreach ( $this->products( $ids ) as $product ) {
			$rows = array_merge( $rows, $this->stock->audit( $product ) );
		}
		return $rows;
	}

	/**
	 * Command: stock-audit.
	 *
	 * @param array<int,string>    $args  Positional args (unused).
	 * @param array<string,string> $assoc Assoc args / flags.
	 */
	public function stock_audit( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$fix    = ! empty( $assoc['fix'] );
		$format = 'json' === ( $assoc['format'] ?? 'table' ) ? 'json' : 'table';
		$ids    = $this->source_ids( $assoc );
		$fields = [ 'product', 'twin', 'lang', 'field', 'source', 'value' ];

		$rows = $this->audit_ids( $ids );
		if ( array() === $rows ) {
			if ( 'json' === $format ) {
				\WP_CLI::line( '[]' );
			}
			\WP_CLI::success( 'No stock mismatches.' );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
		if ( ! $fix ) {
			\WP_CLI::halt( 1 );
		}

		foreach ( $this->products( $ids ) as $product ) {
			if ( $this->has_row( $rows, $product ) ) {
				$this->stock->repair( $product, $this->sync );
			}
		}

		$after = $this->audit_ids( $ids );
		if ( array() !== $after ) {
			\WP_CLI\Utils\format_items( $format, $after, $fields );
			\WP_CLI::error( sprintf( '%d mismatches remain after --fix.', count( $after ) ) );
		}
		\WP_CLI::success( sprintf( 'Repaired %d mismatched rows; now 0.', count( $rows ) ) );
	}

	/**
	 * Does the audit list mention this product group (source product or one of its variations).
	 *
	 * @param array<int,array{product:int,twin:int,lang:string,field:string,source:string,value:string}> $rows    Rows.
	 * @param \WC_Product                                                                                $product Source product.
	 */
	private function has_row( array $rows, \WC_Product $product ): bool {
		$ids = array_merge( [ $product->get_id() ], $this->stock->children( $product->get_id() ) );
		foreach ( $rows as $row ) {
			if ( in_array( $row['product'], $ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Command: pages.
	 *
	 * @param array<int,string>    $args  Positional args (unused).
	 * @param array<string,string> $assoc Assoc args / flags.
	 */
	public function pages( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$dry     = ! empty( $assoc['dry-run'] );
		$default = $this->polylang->default_language();
		$target  = self::TARGET_LANG;
		if ( '' === $default || ! in_array( $target, $this->polylang->languages(), true ) ) {
			\WP_CLI::error( 'Polylang default language or the "ru" language is missing.' );
		}

		$rank    = Plugin::instance()->integration( 'rankmath' );
		$created = 0;
		$skipped = 0;
		$rows    = [];

		foreach ( self::RU_TITLES as $key => $title ) {
			$base = (int) get_option( 'woocommerce_' . $key . '_page_id' );
			$page = $base > 0 ? get_post( $base ) : null;
			if ( ! $page instanceof \WP_Post || 'publish' !== $page->post_status ) {
				\WP_CLI::warning( sprintf( '%s: the base page is missing or not published, skipped.', $key ) );
				continue;
			}

			$existing = $this->polylang->translate_post( $base, $target );
			if ( $existing > 0 && $existing !== $base ) {
				++$skipped;
				$rows[] = [ $key, 'skipped', (string) $existing, (string) get_post_field( 'post_name', $existing ) ];
				if ( (string) get_post_field( 'post_name', $existing ) !== $page->post_name ) {
					\WP_CLI::warning( sprintf( '%s: slug differs between languages (%s vs %s). Shared slug expected.', $key, $page->post_name, get_post_field( 'post_name', $existing ) ) );
				}
				if ( ! $dry && in_array( $key, WooPages::NOINDEX, true ) && $rank instanceof RankMath ) {
					$rank->set_post_noindex( $existing );
				}
				continue;
			}

			if ( $dry ) {
				++$created;
				$rows[] = [ $key, 'would create', '', $page->post_name ];
				continue;
			}

			$new_id = $this->create_translation( $key, $title, $page, $default, $target );
			if ( 0 === $new_id ) {
				\WP_CLI::warning( sprintf( '%s: creation failed.', $key ) );
				continue;
			}
			if ( in_array( $key, WooPages::NOINDEX, true ) && $rank instanceof RankMath ) {
				$rank->set_post_noindex( $new_id );
			}
			++$created;
			$rows[] = [ $key, 'created', (string) $new_id, (string) get_post_field( 'post_name', $new_id ) ];
		}

		if ( array() !== $rows ) {
			\WP_CLI\Utils\format_items( 'table', array_map( static fn( array $r ): array => array_combine( [ 'page', 'result', 'id', 'slug' ], $r ), $rows ), [ 'page', 'result', 'id', 'slug' ] );
		}
		\WP_CLI::success( sprintf( '%s: %d, skipped: %d.', $dry ? 'Would create' : 'Created', $created, $skipped ) );
	}

	/**
	 * Command: sync-products.
	 *
	 * @param array<int,string>    $args  Positional args (unused).
	 * @param array<string,string> $assoc Assoc args / flags.
	 */
	public function sync_products( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$dry     = ! empty( $assoc['dry-run'] );
		$default = $this->polylang->default_language();
		if ( '' === $default ) {
			\WP_CLI::error( 'Polylang default language is unknown.' );
		}

		if ( ! empty( $assoc['ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $assoc['ids'] ) ) ) );
		} else {
			$ids      = [];
			$page     = 1;
			$per_page = 100;
			$fetched  = $per_page;
			while ( $fetched === $per_page ) {
				$batch   = get_posts(
					[
						'post_type'        => 'product',
						'post_status'      => [ 'publish', 'draft', 'pending', 'private' ],
						'lang'             => $default,
						'fields'           => 'ids',
						'posts_per_page'   => $per_page,
						'paged'            => $page,
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'no_found_rows'    => true,
						'suppress_filters' => false,
					]
				);
				$ids     = array_merge( $ids, $batch );
				$fetched = count( $batch );
				++$page;
			}
		}

		$stats = $this->sync->sync_ids( $ids, $dry );
		\WP_CLI::success(
			sprintf(
				'%s: %d products checked, %d twins, %d twins %s.',
				$dry ? 'Dry run' : 'Done',
				$stats['checked'],
				$stats['twins'],
				$stats['changed'],
				$dry ? 'would change' : 'updated'
			)
		);
	}

	/**
	 * Create one RU page, link it, and give it the shared slug.
	 *
	 * @param string   $key     Woo page key.
	 * @param string   $title   RU title.
	 * @param \WP_Post $base    Default-language page.
	 * @param string   $default_lang Default language slug.
	 * @param string   $target  Target language slug.
	 * @return int New page ID or 0.
	 */
	private function create_translation( string $key, string $title, \WP_Post $base, string $default_lang, string $target ): int {
		$content = $base->post_content;
		if ( 'shop' === $key && '' !== trim( wp_strip_all_tags( do_blocks( $content ) ) ) ) {
			\WP_CLI::warning( 'shop: the UA page has text content; the RU page was created with a placeholder (needs RU text).' );
			$content = '<!-- wp:paragraph --><p>(needs RU text)</p><!-- /wp:paragraph -->';
		}

		$new_id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_name'    => $base->post_name . '-' . $target . '-tmp',
					'post_content' => $content,
					'post_parent'  => (int) $base->post_parent,
				]
			),
			true
		);
		if ( is_wp_error( $new_id ) || (int) $new_id <= 0 ) {
			return 0;
		}
		$new_id = (int) $new_id;

		if ( '' === $this->polylang->post_language( $base->ID ) ) {
			$this->polylang->set_post_language( $base->ID, $default_lang );
		}
		$this->polylang->set_post_language( $new_id, $target );

		$group                  = $this->polylang->translations( $base->ID );
		$group[ $default_lang ] = $base->ID;
		$group[ $target ]       = $new_id;
		$this->polylang->save_post_translations( $group );

		// Language is set now: the slug filter may grant the shared slug.
		wp_update_post(
			wp_slash(
				[
					'ID'        => $new_id,
					'post_name' => $base->post_name,
				]
			)
		);
		if ( (string) get_post_field( 'post_name', $new_id ) !== $base->post_name ) {
			\WP_CLI::warning( sprintf( '%s: shared slug "%s" was not granted (got "%s").', $key, $base->post_name, get_post_field( 'post_name', $new_id ) ) );
		}

		return $new_id;
	}
}
