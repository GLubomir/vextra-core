<?php
/**
 * Urls module: slug hygiene that does not depend on Rank Math (latin transliterated slugs for NEW content).
 *
 * Separate from the `Seo` module on purpose: it must keep working when Polylang, Rank Math or WooCommerce is missing
 * (the default UA table is used when no language is known). Feature flag filter `vextra/core/seo/slugs_enabled`.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Seo;

use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;

final class Urls implements ModuleInterface {

	public function id(): string {
		return 'seo-urls';
	}

	public function is_enabled(): bool {
		/**
		 * Feature flag of the slug transliteration.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'vextra/core/seo/slugs_enabled', true );
	}

	public function register(): void {
		$pll = Plugin::instance()->integration( 'polylang' );
		if ( ! $pll instanceof Polylang ) {
			return;
		}
		( new Slugs( $pll, new Transliterator() ) )->register();
	}
}
