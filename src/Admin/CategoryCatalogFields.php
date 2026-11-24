<?php
/**
 * Category screen fields of the catalog module (TASK-043b): accessory categories (ADR-010 E4) and filterable attributes.
 *
 * Shown on the EDIT screen of a `product_cat` term of the DEFAULT language (term meta `_vx_accessory_terms`, `_vx_filter_attrs`). The
 * translation's screen shows the resolved value read-only (it comes through the Polylang term pair, there is no second entry).
 * Save: nonce + `manage_product_terms` + `edit_term`; ids are validated against existing terms (no foreign id is stored), the lists
 * are capped (8 accessories, 5 attributes). Admin UI strings are Ukrainian (admin side), no secrets, nothing echoed unescaped.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Admin;

use Vextra\Core\Catalog\AccessoryRules;
use Vextra\Core\Catalog\FilterAttributes;
use Vextra\Core\Catalog\TermData;
use Vextra\Core\Catalog\TermPair;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;

final class CategoryCatalogFields {

	private const NONCE_ACTION = 'vx_catalog_term_fields';

	private const NONCE_FIELD = 'vx_catalog_nonce';

	public const META_H1 = '_vx_h1';

	public const META_INTRO = '_vx_intro';

	public function register(): void {
		add_action( 'product_cat_edit_form_fields', [ $this, 'render' ], 20, 1 );
		add_action( 'product_cat_add_form_fields', [ $this, 'render_add' ], 20, 0 );
		add_action( 'edited_product_cat', [ $this, 'save' ], 10, 1 );
		add_action( 'created_product_cat', [ $this, 'save' ], 10, 1 );
	}

	/**
	 * Render the fields.
	 *
	 * @param mixed $term Term being edited.
	 */
	public function render( $term ): void {
		if ( ! $term instanceof \WP_Term || ! current_user_can( 'manage_product_terms' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return;
		}
		$term_id     = (int) $term->term_id;
		$translation = TermPair::is_translation( $term_id );
		$source      = TermPair::source( $term_id );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$this->render_text_fields( (string) get_term_meta( $term_id, self::META_H1, true ), (string) get_term_meta( $term_id, self::META_INTRO, true ), true );

		if ( $translation ) {
			$this->render_readonly( $term_id, $source );
			return;
		}

		$selected = AccessoryRules::own( $term_id );
		$choices  = $this->choices( $term_id, $selected );
		$attrs    = FilterAttributes::own( $term_id );
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Аксесуари категорії', 'vextra-core' ); ?></th>
			<td>
				<?php for ( $i = 0; $i < AccessoryRules::MAX; $i++ ) : ?>
					<p>
						<label class="screen-reader-text" for="vx-acc-<?php echo esc_attr( (string) $i ); ?>"><?php echo esc_html( sprintf( /* translators: %d: position */ __( 'Аксесуар %d', 'vextra-core' ), $i + 1 ) ); ?></label>
						<select name="vx_accessory_terms[]" id="vx-acc-<?php echo esc_attr( (string) $i ); ?>">
							<option value="0"><?php esc_html_e( '— не вибрано —', 'vextra-core' ); ?></option>
							<?php foreach ( $choices as $id => $name ) : ?>
								<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $selected[ $i ] ?? 0, $id ); ?>><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endfor; ?>
				<p class="description"><?php esc_html_e( 'До 8 категорій, що показуються блоком «Популярні аксесуари» на сторінці категорії. Порядок = порядок у списку. Порожні категорії не показуються. Російська версія бере значення з перекладу категорії.', 'vextra-core' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Фільтри за атрибутами', 'vextra-core' ); ?></th>
			<td>
				<?php foreach ( FilterAttributes::all() as $name => $label ) : ?>
					<label style="display:block"><input type="checkbox" name="vx_filter_attrs[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $attrs, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'До 5 атрибутів, що стають фільтрами цієї категорії та її підкатегорій. Нічого не вибрано = 3 найпоширеніші атрибути категорії.', 'vextra-core' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * "Add category" screen: only the per-language text fields (accessories / attributes need an existing term).
	 */
	public function render_add(): void {
		if ( ! current_user_can( 'manage_product_terms' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return;
		}
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$this->render_text_fields( '', '', false );
	}

	/**
	 * The H1 override and the short intro of a category (per language: Polylang twins are separate terms).
	 *
	 * @param string $h1    Stored H1.
	 * @param string $intro Stored intro.
	 * @param bool   $row   Edit screen (table rows) or add screen (div fields).
	 */
	private function render_text_fields( string $h1, string $intro, bool $row ): void {
		$fields = [
			[
				'name'  => 'vx_cat_h1',
				'label' => __( 'H1 категорії (необов’язково)', 'vextra-core' ),
				'help'  => __( 'Якщо порожньо, H1 = назва категорії. Не довше 120 символів.', 'vextra-core' ),
				'value' => $h1,
				'type'  => 'input',
			],
			[
				'name'  => 'vx_cat_intro',
				'label' => __( 'Короткий вступ над списком', 'vextra-core' ),
				'help'  => __( '1–2 речення над списком товарів (лише 1-ша сторінка). Дозволені посилання, <strong>, <em>. Довгий SEO-текст — в «Опис» нижче: він друкується під списком.', 'vextra-core' ),
				'value' => $intro,
				'type'  => 'textarea',
			],
		];
		foreach ( $fields as $f ) {
			$id = 'vx-' . $f['name'];
			echo $row ? '<tr class="form-field"><th scope="row">' : '<div class="form-field">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal markup.
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . '</label>';
			echo $row ? '</th><td>' : '';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal markup.
			if ( 'input' === $f['type'] ) {
				echo '<input type="text" maxlength="120" name="' . esc_attr( $f['name'] ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( $f['value'] ) . '" class="large-text">';
			} else {
				echo '<textarea rows="3" maxlength="600" name="' . esc_attr( $f['name'] ) . '" id="' . esc_attr( $id ) . '" class="large-text">' . esc_textarea( $f['value'] ) . '</textarea>';
			}
			echo '<p class="description">' . esc_html( $f['help'] ) . '</p>';
			echo $row ? '</td></tr>' : '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal markup.
		}
	}

	/**
	 * Read-only block on the translation screen.
	 *
	 * @param int $term_id Translation term id.
	 * @param int $source  Source term id.
	 */
	private function render_readonly( int $term_id, int $source ): void {
		$names = [];
		foreach ( AccessoryRules::own( $source ) as $target ) {
			$id   = TermPair::in_language( $target, $this->language_of( $term_id ) );
			$term = $id > 0 ? get_term( $id, 'product_cat' ) : null;
			if ( $term instanceof \WP_Term ) {
				$names[] = $term->name;
			}
		}
		$attrs  = FilterAttributes::all();
		$labels = array_map( static fn( string $name ): string => $attrs[ $name ] ?? $name, FilterAttributes::own( $source ) );
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Аксесуари та фільтри', 'vextra-core' ); ?></th>
			<td>
				<p><?php echo esc_html( array() === $names ? __( 'Аксесуари: не задано', 'vextra-core' ) : __( 'Аксесуари: ', 'vextra-core' ) . implode( ', ', $names ) ); ?></p>
				<p><?php echo esc_html( array() === $labels ? __( 'Атрибути фільтрів: за замовчуванням', 'vextra-core' ) : __( 'Атрибути фільтрів: ', 'vextra-core' ) . implode( ', ', $labels ) ); ?></p>
				<p class="description"><?php esc_html_e( 'Значення беруться з категорії основної мови (редагуються лише там).', 'vextra-core' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Language slug of a term ('' when unknown).
	 *
	 * @param int $term_id Term id.
	 */
	private function language_of( int $term_id ): string {
		$pll = Plugin::instance()->integration( 'polylang' );
		return $pll instanceof Polylang ? $pll->term_language( $term_id ) : '';
	}

	/**
	 * Selectable accessory targets: non-empty source-language categories plus the ones already stored.
	 *
	 * @param int   $term_id  Edited term.
	 * @param int[] $selected Stored ids.
	 * @return array<int,string>
	 */
	private function choices( int $term_id, array $selected ): array {
		$pll   = Plugin::instance()->integration( 'polylang' );
		$lang  = $pll instanceof Polylang ? $pll->default_language() : '';
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'lang'       => $lang,
				'orderby'    => 'name',
			]
		);
		$out   = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			if ( ! $term instanceof \WP_Term || (int) $term->term_id === $term_id ) {
				continue;
			}
			if ( in_array( (int) $term->term_id, $selected, true ) || TermData::counts()->count( (int) $term->term_id ) > 0 ) {
				$out[ (int) $term->term_id ] = $term->name;
			}
		}
		return $out;
	}

	/**
	 * Save (nonce + capability first; validated lists only).
	 *
	 * @param mixed $term_id Term id.
	 */
	public function save( $term_id ): void {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 || ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'manage_product_terms' ) || ! current_user_can( 'edit_term', $term_id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return;
		}
		// Per-language text fields (every term, translations included).
		$h1    = isset( $_POST['vx_cat_h1'] ) ? mb_substr( sanitize_text_field( wp_unslash( (string) $_POST['vx_cat_h1'] ) ), 0, 120 ) : null;
		$intro = isset( $_POST['vx_cat_intro'] ) ? mb_substr(
			wp_kses(
				wp_unslash( (string) $_POST['vx_cat_intro'] ),
				[
					'a'      => [ 'href' => true ],
					'strong' => [],
					'em'     => [],
					'br'     => [],
				]
			),
			0,
			600
		) : null;
		if ( null !== $h1 ) {
			'' === $h1 ? delete_term_meta( $term_id, self::META_H1 ) : update_term_meta( $term_id, self::META_H1, $h1 );
		}
		if ( null !== $intro ) {
			'' === trim( $intro ) ? delete_term_meta( $term_id, self::META_INTRO ) : update_term_meta( $term_id, self::META_INTRO, $intro );
		}
		if ( TermPair::is_translation( $term_id ) || ( ! isset( $_POST['vx_filter_attrs'] ) && ! isset( $_POST['vx_accessory_terms'] ) ) ) {
			return; // Only the source-language term stores accessories / attributes (the add screen has no such fields).
		}

		$raw_acc = isset( $_POST['vx_accessory_terms'] ) && is_array( $_POST['vx_accessory_terms'] )
			? array_map( 'absint', wp_unslash( $_POST['vx_accessory_terms'] ) )
			: [];
		$acc     = AccessoryRules::clean( array_filter( $raw_acc ), $term_id );

		$raw_attrs = isset( $_POST['vx_filter_attrs'] ) && is_array( $_POST['vx_filter_attrs'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['vx_filter_attrs'] ) )
			: [];
		$attrs     = FilterAttributes::clean( $raw_attrs );

		$this->store( $term_id, AccessoryRules::META, $acc );
		$this->store( $term_id, FilterAttributes::META, $attrs );
	}

	/**
	 * Store or delete a list meta.
	 *
	 * @param int          $term_id Term id.
	 * @param string       $key     Meta key.
	 * @param array<mixed> $value   List.
	 */
	private function store( int $term_id, string $key, array $value ): void {
		if ( array() === $value ) {
			delete_term_meta( $term_id, $key );
			return;
		}
		update_term_meta( $term_id, $key, $value );
	}
}
