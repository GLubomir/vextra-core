<?php
/**
 * Brand module (TASK-049a): installs the non-markup brand surfaces (Site Icon, default social image, Woo e-mail header) from the files of
 * the active theme (`assets/brand/`, filter `vextra/brand/dir`) with the idempotent command `wp vextra brand apply`.
 *
 * Head tags and `/favicon.ico` are presented by the theme (`Vextra\Theme\Brand\Favicons`); they yield through the conflict rule
 * `favicon-manager` (pseudo module id `brand-icons`, asked by the theme with the core filter `vextra/core/module_enabled`).
 * Docs: docs/system/modules/brand.md (theme repo).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Brand;

use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;

final class Brand implements ModuleInterface {

	public function id(): string {
		return 'brand';
	}

	public function is_enabled(): bool {
		/**
		 * Switch the Brand module off.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'vextra/core/brand/enabled', true );
	}

	public function register(): void {
		( new EmailHeader() )->register();

		// Brand attachments keep their single original file: no resized (WebP) sub-sizes, also on a later regeneration.
		add_filter( 'intermediate_image_sizes_advanced', [ Media::class, 'no_subsizes' ], 99, 3 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$rank = Plugin::instance()->integration( 'rankmath' );
			( new Cli( new Media(), $rank instanceof RankMath ? $rank : null ) )->register();
		}
	}
}
