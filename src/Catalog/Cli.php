<?php
/**
 * WP-CLI: `wp vextra catalog sale-page` (TASK-043a). CLI only, no web entry point.
 *
 * Creates / repairs the sale page `/aktsii/` (UA) and `/ru/aktsii/` (RU) idempotently: a missing page is created (slug `aktsii`,
 * published, language set, content = `vextra/page-title` H1 + intro paragraph + `vextra/seo-text` bottom text), the two pages are
 * linked as Polylang translations, the option `vx_sale_pages` is rebuilt. The content of an EXISTING page is never touched (the
 * editor owns it). A second run creates nothing and prints `ok`.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Catalog;

use Vextra\Core\Integrations\Polylang\Polylang;

final class Cli {

	/**
	 * Title, intro and bottom SEO text per language slug (data, edited later in the editor).
	 */
	private const COPY = [
		'uk' => [
			'title' => 'Акції та знижки',
			'intro' => 'Товари Vextra зі зниженою ціною: електроніка, побутова техніка та речі для дому. Добірка оновлюється автоматично, щойно змінюються ціни.',
			'seo_h' => 'Акційні пропозиції в інтернет-магазині Vextra',
			'seo_p' => [
				'Тут ви знайдете актуальні знижки на популярні товари з каталогу. Ми показуємо лише ті позиції, на які знижка діє просто зараз, тому ціна в картці збігається з ціною в кошику.',
				'Купуйте за акцією в кілька кліків, оформлюйте замовлення онлайн і отримуйте його зручною службою доставки. Якщо потрібна інша категорія, скористайтеся каталогом.',
			],
		],
		'ru' => [
			'title' => 'Акции и скидки',
			'intro' => 'Товары Vextra по сниженной цене: электроника, бытовая техника и вещи для дома. Подборка обновляется автоматически, как только меняются цены.',
			'seo_h' => 'Акционные предложения в интернет-магазине Vextra',
			'seo_p' => [
				'Здесь вы найдёте актуальные скидки на популярные товары из каталога. Мы показываем только те позиции, на которые скидка действует прямо сейчас, поэтому цена в карточке совпадает с ценой в корзине.',
				'Покупайте по акции в несколько кликов, оформляйте заказ онлайн и получайте его удобной службой доставки. Если нужна другая категория, воспользуйтесь каталогом.',
			],
		],
	];

	/**
	 * Polylang adapter.
	 *
	 * @var Polylang
	 */
	private Polylang $polylang;

	public function __construct( Polylang $polylang ) {
		$this->polylang = $polylang;
	}

	public function register(): void {
		\WP_CLI::add_command(
			'vextra catalog sale-page',
			[ $this, 'sale_page' ],
			[ 'shortdesc' => 'Create / repair the sale pages /aktsii/ (UA) and /ru/aktsii/ (RU), link them as translations, rebuild option vx_sale_pages. Idempotent: existing content is never overwritten.' ]
		);
	}

	/**
	 * Command body.
	 */
	public function sale_page(): void {
		if ( ! $this->polylang->is_ready() ) {
			\WP_CLI::error( 'Polylang is not ready.' );
		}

		$changed = false;
		$group   = [];
		foreach ( $this->polylang->languages() as $lang ) {
			$copy = self::COPY[ $lang ] ?? null;
			if ( null === $copy ) {
				continue;
			}
			$id = $this->find( $lang );
			if ( $id <= 0 ) {
				$id = $this->create( $lang, $copy );
				\WP_CLI::log( sprintf( 'created %s page #%d (%s)', $lang, $id, get_permalink( $id ) ) );
				$changed = true;
			}
			$group[ $lang ] = $id;
		}

		if ( count( $group ) > 1 ) {
			$current = $this->polylang->translations( (int) reset( $group ) );
			$wanted  = $group;
			ksort( $current );
			ksort( $wanted );
			if ( $current !== $wanted ) {
				$this->polylang->save_post_translations( $group );
				\WP_CLI::log( 'linked translations: ' . wp_json_encode( $group ) );
				$changed = true;
			}
		}

		$stored = SalePage::map();
		ksort( $stored );
		ksort( $group );
		if ( $stored !== $group ) {
			update_option( SalePage::OPTION, $group );
			\WP_CLI::log( 'option ' . SalePage::OPTION . ' = ' . wp_json_encode( $group ) );
			$changed = true;
		}

		\WP_CLI::success( $changed ? 'repaired' : 'ok' );
	}

	/**
	 * Existing sale page of a language: the stored id when it is still a page of that language, else a page with the slug.
	 *
	 * @param string $lang Language slug.
	 */
	private function find( string $lang ): int {
		$stored = SalePage::page_id( $lang );
		if ( $stored > 0 && 'page' === get_post_type( $stored ) && 'trash' !== get_post_status( $stored ) && $this->polylang->post_language( $stored ) === $lang ) {
			return $stored;
		}

		$ids = get_posts(
			[
				'post_type'      => 'page',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'name'           => SalePage::SLUG,
				'lang'           => '',
				'fields'         => 'ids',
				'posts_per_page' => 10,
				'no_found_rows'  => true,
			]
		);
		foreach ( (array) $ids as $id ) {
			if ( $this->polylang->post_language( (int) $id ) === $lang ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Create a published page with the seed blocks and the shared slug.
	 *
	 * @param string              $lang Language slug.
	 * @param array<string,mixed> $copy Copy (title, intro, seo_h, seo_p).
	 */
	private function create( string $lang, array $copy ): int {
		$id = wp_insert_post(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => (string) $copy['title'],
				'post_name'    => SalePage::SLUG,
				'post_content' => $this->content( $copy ),
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			\WP_CLI::error( 'Cannot create the page: ' . $id->get_error_message() );
		}
		$id = (int) $id;

		$this->polylang->set_post_language( $id, $lang );
		// The language is known only now: re-save the slug so the shared `aktsii` is allowed (SalePage::share_slug).
		wp_update_post(
			[
				'ID'        => $id,
				'post_name' => SalePage::SLUG,
			]
		);
		return $id;
	}

	/**
	 * Block markup (core serializer, so attribute JSON is escaped correctly).
	 *
	 * @param array<string,mixed> $copy Copy.
	 */
	private function content( array $copy ): string {
		$paragraphs = array_map(
			static fn( string $text ): string => get_comment_delimited_block_content( 'core/paragraph', [], '<p>' . esc_html( $text ) . '</p>' ),
			(array) $copy['seo_p']
		);

		return implode(
			"\n\n",
			[
				get_comment_delimited_block_content(
					'vextra/page-title',
					[
						'text'           => (string) $copy['title'],
						'visuallyHidden' => false,
					],
					''
				),
				get_comment_delimited_block_content( 'core/paragraph', [], '<p>' . esc_html( (string) $copy['intro'] ) . '</p>' ),
				get_comment_delimited_block_content(
					'vextra/seo-text',
					[
						'title'        => (string) $copy['seo_h'],
						'collapsible'  => false,
						'headingLevel' => 2,
					],
					implode( "\n\n", $paragraphs )
				),
			]
		);
	}
}
