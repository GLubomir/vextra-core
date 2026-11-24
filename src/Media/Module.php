<?php
/**
 * Media module (TASK-009d): every generated image sub-size is served as WebP (AVIF opt-in), the uploaded original stays the fallback.
 *
 * Approach A (decision in docs/system/modules/media.md): core filter `image_editor_output_format` maps JPEG / PNG sub-sizes to WebP, so core's
 * own `srcset` / `src` printers (and every theme component, preload, JSON data) emit `.webp` URLs with unchanged markup. No `<picture>`, no
 * second file set. OG / Twitter images and the image sitemap keep using the original (`full`).
 *
 * Services: Formats (mapping + capability), Quality (per format / size), Regenerate (existing attachments, Action Scheduler), Rewriter
 * (hard-coded content URLs), Cleanup (sub-size files of a deleted attachment), Stats, Cli. Off with filter `vextra/core/media/enabled` or constant `VX_MEDIA_DISABLED`, and when the PHP runtime has
 * no WebP / AVIF encoder (site unchanged). Yields to format-converter plugins (`Compat\Conflicts` rule `duplicate-image-format-plugin`).
 * Rollback: disable the module, run `wp media regenerate --yes` once (sub-sizes return to JPEG / PNG).
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Media;

use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;

final class Module implements ModuleInterface {

	public function id(): string {
		return 'media';
	}

	public function is_enabled(): bool {
		if ( defined( 'VX_MEDIA_DISABLED' ) && VX_MEDIA_DISABLED ) {
			return false;
		}

		/**
		 * Switch the Media module off.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'vextra/core/media/enabled', true ) && Formats::available();
	}

	public function register(): void {
		add_filter( 'image_editor_output_format', [ Formats::class, 'map' ], 20, 3 );
		( new Quality() )->register();

		$regenerate = new Regenerate();
		$regenerate->register();
		( new Cleanup() )->register();

		$rank = Plugin::instance()->integration( 'rankmath' );
		if ( $rank instanceof RankMath ) {
			$rank->filter_og_image_sizes( [ $this, 'og_sizes' ] );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new Cli( $regenerate ) )->register();
		}
	}

	/**
	 * Open Graph / Twitter image candidates: the original only (JPEG / PNG as uploaded; social crawlers get no WebP sub-size).
	 *
	 * @param mixed $sizes Candidate size names of Rank Math.
	 * @return mixed
	 */
	public function og_sizes( $sizes ) {
		return is_array( $sizes ) ? [ 'full' ] : $sizes;
	}
}
