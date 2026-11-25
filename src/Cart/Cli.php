<?php
/**
 * WP-CLI `wp vextra cart apply-config [--dry-run]` (TASK-045a, F4 / F8): the WooCommerce settings and page content the cart / checkout
 * flow relies on. Idempotent, prints a diff (`key: from -> to`), a second real run changes nothing.
 *
 * - Options: guest checkout, login reminder, NO sign-up at checkout or thank-you (SEC-071), generated password, coupons OFF, phone required, no redirect to the cart
 *   after add, no shipping calculator. `woocommerce_ship_to_destination` (force shipping to billing) is left as it is while no shipping zone exists
 *   (it is reported when a zone exists and the value differs).
 * - Pages: cart / checkout must be block pages in every language (reported, never auto-converted); their content is normalised by `PageContent`
 *   (no cross-sells / "new in store", step numbers + step titles in UA and RU).
 * - Legal pages: the EXISTING published `/public-offer/` and `/privacy-policy/` pages (+ twins) are bound as Woo terms page / WordPress privacy page,
 *   never duplicated; a missing twin is created as a placeholder (noindex, "owner text confirmed before launch"). Placeholders stay noindex.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Cart;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;

final class Cli {

	/**
	 * Woo options and the value the flow needs.
	 */
	public const OPTIONS = [
		'woocommerce_enable_guest_checkout'           => 'yes',
		'woocommerce_enable_checkout_login_reminder'  => 'yes',
		'woocommerce_enable_signup_and_login_from_checkout' => 'no', // SEC-071: no account creation at checkout (the "e-mail already registered" answer is an enumeration oracle).
		'woocommerce_enable_delayed_account_creation' => 'no', // No account offer on the thank-you page until the verified post-order offer exists.
		'woocommerce_registration_generate_password'  => 'yes',
		'woocommerce_enable_coupons'                  => 'no',
		'woocommerce_checkout_phone_field'            => 'required',
		'woocommerce_cart_redirect_after_add'         => 'no',
		'woocommerce_enable_shipping_calc'            => 'no',
	];

	/**
	 * Slugs of the existing legal pages (default language) and their placeholder copy per language.
	 */
	private const LEGAL = [
		'terms'   => [
			'slug'  => 'public-offer',
			'title' => [
				'default' => 'Публічна оферта',
				'ru'      => 'Публичная оферта',
			],
		],
		'privacy' => [
			'slug'  => 'privacy-policy',
			'title' => [
				'default' => 'Політика конфіденційності',
				'ru'      => 'Политика конфиденциальности',
			],
		],
	];

	private const PLACEHOLDER = [
		'default' => 'Демонстраційний текст. Сторінка в розробці.',
		'ru'      => 'Демонстрационный текст. Страница в разработке.',
	];

	/**
	 * Planned changes of this run: `[ label, from, to ]`.
	 *
	 * @var array<int,array{0:string,1:string,2:string}>
	 */
	private array $changes = [];

	public function register(): void {
		\WP_CLI::add_command(
			'vextra cart apply-config',
			[ $this, 'apply_config' ],
			[
				'shortdesc' => 'Idempotent: guest checkout, sign-up, coupons OFF, phone required, cart / checkout block pages (UA + RU) content, legal pages bound as terms / privacy. --dry-run prints the diff only.',
				'synopsis'  => [
					[
						'type'     => 'flag',
						'name'     => 'dry-run',
						'optional' => true,
					],
				],
			]
		);
	}

	/**
	 * Command: apply-config.
	 *
	 * @param array<int,string>    $args  Positional args (unused).
	 * @param array<string,string> $assoc Flags.
	 */
	public function apply_config( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$dry = isset( $assoc['dry-run'] );
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			\WP_CLI::error( 'WooCommerce is not active: nothing applied.' );
		}
		$this->changes = [];

		$this->options( $dry );
		$this->pages( $dry );
		$this->legal( $dry );

		foreach ( $this->changes as $row ) {
			\WP_CLI::log( sprintf( '%s: %s -> %s%s', $row[0], $row[1], $row[2], $dry ? ' (dry run)' : '' ) );
		}
		$total = count( $this->changes );
		if ( 0 === $total ) {
			\WP_CLI::success( 'Already applied, nothing changed.' );
			return;
		}
		\WP_CLI::success( $dry ? sprintf( '%d change(s) would be applied.', $total ) : sprintf( 'Applied %d change(s).', $total ) );
	}

	/**
	 * Woo options.
	 *
	 * @param bool $dry Dry run.
	 */
	private function options( bool $dry ): void {
		foreach ( self::OPTIONS as $name => $want ) {
			$have = (string) get_option( $name, '' );
			if ( $have === $want ) {
				continue;
			}
			$this->changes[] = [ $name, '' === $have ? '(empty)' : $have, $want ];
			if ( ! $dry ) {
				update_option( $name, $want );
			}
		}

		// Force shipping to billing stays while no shipping zone exists.
		if ( class_exists( '\WC_Shipping_Zones' ) ) {
			$zones = \WC_Shipping_Zones::get_zones();
			if ( array() === $zones && 'billing' !== (string) get_option( 'woocommerce_ship_to_destination', '' ) ) {
				$this->changes[] = [ 'woocommerce_ship_to_destination', (string) get_option( 'woocommerce_ship_to_destination', '' ), 'billing' ];
				if ( ! $dry ) {
					update_option( 'woocommerce_ship_to_destination', 'billing' );
				}
			}
		}
	}

	/**
	 * Cart and checkout pages of every language: block check + content normalisation.
	 *
	 * @param bool $dry Dry run.
	 */
	private function pages( bool $dry ): void {
		$pll = Plugin::instance()->integration( 'polylang' );
		foreach ( [
			'cart'     => 'woocommerce/cart',
			'checkout' => 'woocommerce/checkout',
		] as $key => $block ) {
			$base = (int) get_option( 'woocommerce_' . $key . '_page_id', 0 );
			if ( $base <= 0 ) {
				\WP_CLI::warning( sprintf( 'No %s page is set in WooCommerce.', $key ) );
				continue;
			}
			$ids = [ '' => $base ];
			if ( $pll instanceof Polylang && $pll->is_ready() ) {
				$ids = [];
				foreach ( $pll->languages() as $lang ) {
					$id = $pll->translate_post( $base, $lang );
					if ( $id > 0 ) {
						$ids[ $lang ] = $id;
					} else {
						\WP_CLI::warning( sprintf( 'The %s page has no %s translation.', $key, $lang ) );
					}
				}
			}
			foreach ( $ids as $lang => $id ) {
				$post = get_post( $id );
				if ( ! $post instanceof \WP_Post || ! has_block( $block, $post ) ) {
					\WP_CLI::warning( sprintf( 'Page %d (%s %s) is not a %s block page: left untouched.', $id, $key, '' === $lang ? '-' : $lang, $block ) );
					continue;
				}
				$new = 'cart' === $key ? PageContent::cart( $post->post_content ) : PageContent::checkout( $post->post_content, (string) $lang );
				if ( $new === $post->post_content ) {
					continue;
				}
				$this->changes[] = [ sprintf( 'page %d (%s %s) content', $id, $key, '' === $lang ? '-' : $lang ), sprintf( '%d bytes', strlen( $post->post_content ) ), sprintf( '%d bytes', strlen( $new ) ) ];
				if ( ! $dry ) {
					$this->save_content( $id, $new );
				}
			}
		}
	}

	/**
	 * Legal pages: bind the existing pages, create only a missing twin.
	 *
	 * @param bool $dry Dry run.
	 */
	private function legal( bool $dry ): void {
		$pll  = Plugin::instance()->integration( 'polylang' );
		$rank = Plugin::instance()->integration( 'rankmath' );
		foreach ( self::LEGAL as $key => $def ) {
			$option = 'terms' === $key ? 'woocommerce_terms_page_id' : 'wp_page_for_privacy_policy';
			$base   = (int) get_option( $option, 0 );
			if ( $base <= 0 || ! $this->is_published_page( $base ) ) {
				$found = $this->find_page( $def['slug'] );
				if ( $found <= 0 ) {
					\WP_CLI::warning( sprintf( 'No published page "%s" found: %s not bound.', $def['slug'], $option ) );
					continue;
				}
				$this->changes[] = [ $option, 0 < $base ? (string) $base : '(empty)', (string) $found ];
				if ( ! $dry ) {
					update_option( $option, $found );
				}
				$base = $found;
			}

			if ( $pll instanceof Polylang && $pll->is_ready() ) {
				foreach ( $pll->languages() as $lang ) {
					if ( $pll->translate_post( $base, $lang ) > 0 ) {
						continue;
					}
					$this->changes[] = [ sprintf( 'legal twin (%s %s)', $key, $lang ), '(missing)', 'placeholder page' ];
					if ( ! $dry ) {
						$this->create_twin( $pll, $rank, $base, $lang, $def['title'][ $lang ] ?? $def['title']['default'], $key );
					}
				}
			}

			// A placeholder stays out of the index until the owner text replaces it (BOARD launch gate).
			if ( $rank instanceof RankMath && $rank->is_available() ) {
				$pages = [ $base ];
				if ( $pll instanceof Polylang && $pll->is_ready() ) {
					$pages = array_values( array_unique( array_merge( $pages, array_map( 'intval', array_values( $pll->translations( $base ) ) ) ) ) );
				}
				foreach ( $pages as $id ) {
					$post = get_post( $id );
					if ( $post instanceof \WP_Post && strlen( wp_strip_all_tags( $post->post_content ) ) < 400 && ! $rank->is_post_noindex( $id ) ) {
						$this->changes[] = [ sprintf( 'page %d (%s) robots', $id, $key ), 'index', 'noindex' ];
						if ( ! $dry ) {
							$rank->set_post_noindex( $id );
						}
					}
				}
			}
		}
	}

	/**
	 * Write post content (kses off for the CLI user-less context: the content is block markup built from the stored content).
	 *
	 * @param int    $id      Post id.
	 * @param string $content New content.
	 */
	private function save_content( int $id, string $content ): void {
		kses_remove_filters();
		$result = wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( $content ),
			],
			true
		);
		kses_init_filters();
		if ( is_wp_error( $result ) ) {
			\WP_CLI::warning( sprintf( 'Could not update page %d: %s', $id, $result->get_error_message() ) );
		}
	}

	/**
	 * Create the missing twin of a legal page (placeholder, noindex) and link it.
	 *
	 * @param Polylang      $pll   Polylang adapter.
	 * @param RankMath|null $rank  Rank Math adapter.
	 * @param int           $base  Base page id.
	 * @param string        $lang  Language slug of the new page.
	 * @param string        $title Title.
	 * @param string        $key   terms|privacy.
	 */
	private function create_twin( Polylang $pll, ?object $rank, int $base, string $lang, string $title, string $key ): void {
		$text = self::PLACEHOLDER[ $lang ] ?? self::PLACEHOLDER['default'];
		$id   = wp_insert_post(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => 'terms' === $key ? 'public-offer-' . $lang : 'privacy-policy-' . $lang,
				'post_content' => '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->',
			],
			true
		);
		if ( is_wp_error( $id ) || $id <= 0 ) {
			\WP_CLI::warning( 'Could not create the legal page twin.' );
			return;
		}
		$pll->set_post_language( (int) $id, $lang );
		$group          = array_map( 'intval', $pll->translations( $base ) );
		$group[ $lang ] = (int) $id;
		$pll->save_post_translations( $group );
		if ( $rank instanceof RankMath ) {
			$rank->set_post_noindex( (int) $id );
		}
	}

	private function is_published_page( int $id ): bool {
		$post = get_post( $id );
		return $post instanceof \WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status;
	}

	/**
	 * Published page by slug in the default language ('' when absent).
	 *
	 * @param string $slug Page slug.
	 */
	private function find_page( string $slug ): int {
		$ids = get_posts(
			[
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'name'             => $slug,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			]
		);
		return array() === $ids ? 0 : (int) $ids[0];
	}
}
