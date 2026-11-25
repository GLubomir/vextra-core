<?php
/**
 * WP-CLI: `wp vextra brand apply [--dry-run] [--force] [--dir=<path>]` (TASK-049a). CLI only, no web entry point.
 *
 * Idempotent: imports the three brand files once (`Media`), then sets five settings, each only when empty or still pointing at an
 * attachment we recorded (admin values are kept unless `--force`): WP `site_icon` (+ attachment context), Woo
 * `woocommerce_email_header_image`, Rank Math `titles.open_graph_image` + `open_graph_image_id`.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Brand;

use Vextra\Core\Integrations\RankMath\RankMath;
use Vextra\Core\Plugin;

final class Cli {

	/**
	 * Media service.
	 *
	 * @var Media
	 */
	private Media $media;

	/**
	 * Rank Math adapter (null: not available).
	 *
	 * @var RankMath|null
	 */
	private ?RankMath $rank_math;

	/**
	 * Changes counted in this run.
	 *
	 * @var int
	 */
	private int $changes = 0;

	public function __construct( Media $media, ?RankMath $rank_math ) {
		$this->media     = $media;
		$this->rank_math = $rank_math;
	}

	public function register(): void {
		\WP_CLI::add_command(
			'vextra brand apply',
			[ $this, 'apply' ],
			[
				'shortdesc' => 'Idempotent: imports the brand media (Site Icon, e-mail logo, default OG image) and sets Site Icon, Woo e-mail header image and the Rank Math default social image. Admin values are kept unless --force.',
				'synopsis'  => [
					[
						'type'     => 'flag',
						'name'     => 'dry-run',
						'optional' => true,
					],
					[
						'type'     => 'flag',
						'name'     => 'force',
						'optional' => true,
					],
					[
						'type'     => 'assoc',
						'name'     => 'dir',
						'optional' => true,
					],
				],
			]
		);
	}

	/**
	 * Command: apply.
	 *
	 * @param array<int,string>    $args  Positional args (unused).
	 * @param array<string,string> $assoc Assoc args (dry-run, force, dir).
	 */
	public function apply( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$dry   = ! empty( $assoc['dry-run'] );
		$force = ! empty( $assoc['force'] );

		/**
		 * Directory with the brand files (default: `assets/brand` of the active theme).
		 *
		 * @param string $dir Directory.
		 */
		$dir = isset( $assoc['dir'] ) ? (string) $assoc['dir'] : (string) apply_filters( 'vextra/brand/dir', get_template_directory() . '/assets/brand' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			\WP_CLI::error( 'Brand directory not found: nothing applied.' );
		}
		$missing = $this->media->missing( $dir );
		if ( [] !== $missing ) {
			\WP_CLI::error( 'Missing brand file(s): ' . implode( ', ', $missing ) . '. Nothing applied.' );
		}
		if ( null === $this->rank_math || ! $this->rank_math->is_available() ) {
			\WP_CLI::warning( 'Rank Math is not active: the default social image is skipped.' );
		}
		foreach ( Plugin::instance()->conflicts()->evaluate() as $row ) {
			if ( 'favicon-manager' === $row['id'] && $row['active'] ) {
				\WP_CLI::warning( 'A favicon plugin is active: the theme icon tags and /favicon.ico yield to it (rule favicon-manager).' );
			}
		}

		$plan = $this->media->plan( $dir );
		foreach ( $plan as $key => $row ) {
			\WP_CLI::log( sprintf( 'media %s (%s): %s%s', $key, $row['file'], $row['state'], 'reuse' === $row['state'] ? ' (attachment ' . $row['id'] . ')' : '' ) );
		}

		$owned_ids  = $this->media->record();
		$owned_urls = [];
		foreach ( $owned_ids as $id ) {
			$url = (string) wp_get_attachment_url( $id );
			if ( '' !== $url ) {
				$owned_urls[] = $url;
			}
		}

		// 1. Media.
		$ids      = [];
		$obsolete = [];
		foreach ( $plan as $key => $row ) {
			if ( 'reuse' === $row['state'] ) {
				$ids[ $key ] = $row['id'];
				continue;
			}
			++$this->changes;
			if ( $dry ) {
				$ids[ $key ] = $row['id'];
				continue;
			}
			$new = $this->media->import( $key, $row['path'], $row['hash'] );
			if ( is_wp_error( $new ) ) {
				\WP_CLI::error( sprintf( 'Import of %s failed: %s', $row['file'], $new->get_error_message() ) );
			}
			$ids[ $key ] = $new;
			if ( 'replace' === $row['state'] ) {
				$obsolete[] = $row['id'];
			}
			\WP_CLI::log( sprintf( 'imported %s = attachment %d (changed)', $key, $new ) );
		}

		// 2. Settings.
		$icon      = (int) ( $ids['site-icon'] ?? 0 );
		$email     = (int) ( $ids['email-logo'] ?? 0 );
		$og        = (int) ( $ids['og-default'] ?? 0 );
		$email_url = $dry && 0 === $email ? '(new attachment)' : (string) wp_get_attachment_url( $email );
		$og_url    = $dry && 0 === $og ? '(new attachment)' : (string) wp_get_attachment_url( $og );

		$this->set_site_icon( $icon, $owned_ids['site-icon'] ?? 0, $force, $dry );
		$this->set_email_header( $email_url, $owned_urls, $force, $dry );
		$this->set_social_image( $og, $og_url, $owned_ids['og-default'] ?? 0, $owned_urls, $force, $dry );

		// 3. Record, then drop replaced attachments (settings already point to the new ones).
		if ( ! $dry ) {
			$stored = get_option( Media::OPTION, [] );
			if ( ! is_array( $stored ) || $stored !== $ids ) {
				$this->media->save( $ids );
			}
			foreach ( $obsolete as $old ) {
				wp_delete_attachment( $old, true );
				\WP_CLI::log( sprintf( 'deleted replaced attachment %d (changed)', $old ) );
			}
		}

		if ( $dry ) {
			\WP_CLI::success( sprintf( 'Dry run: %d change(s) planned, nothing written.', $this->changes ) );
			return;
		}
		\WP_CLI::success( 0 === $this->changes ? 'Already applied, nothing changed.' : sprintf( 'Applied %d change(s).', $this->changes ) );
	}

	/**
	 * Site Icon option + attachment context.
	 *
	 * @param int  $id     New (or reused) attachment id.
	 * @param int  $owned  Recorded attachment id before this run.
	 * @param bool $force  Replace an admin value.
	 * @param bool $dry    Dry run.
	 */
	private function set_site_icon( int $id, int $owned, bool $force, bool $dry ): void {
		$current = absint( get_option( 'site_icon', 0 ) );
		if ( $current === $id && $id > 0 ) {
			$this->context( $id, $dry );
			\WP_CLI::log( sprintf( 'site_icon = %d', $id ) );
			return;
		}
		if ( ! $force && $current > 0 && $current !== $owned ) {
			\WP_CLI::log( sprintf( 'site_icon = %d skipped (admin value kept)', $current ) );
			return;
		}
		++$this->changes;
		if ( ! $dry ) {
			update_option( 'site_icon', $id );
		}
		$this->context( $id, $dry );
		\WP_CLI::log( sprintf( 'site_icon = %s (changed)', $id > 0 ? (string) $id : '(new attachment)' ) );
	}

	/**
	 * Set the core Site Icon context exactly as the cropper does.
	 *
	 * @param int  $id  Attachment id.
	 * @param bool $dry Dry run.
	 */
	private function context( int $id, bool $dry ): void {
		if ( ( $id <= 0 && ! $dry ) || ( $id > 0 && 'site-icon' === get_post_meta( $id, '_wp_attachment_context', true ) ) ) {
			return;
		}
		++$this->changes;
		if ( ! $dry ) {
			update_post_meta( $id, '_wp_attachment_context', 'site-icon' );
		}
		\WP_CLI::log( sprintf( 'attachment %s context = site-icon (changed)', $id > 0 ? (string) $id : '(new)' ) );
	}

	/**
	 * Woo e-mail header image.
	 *
	 * @param string   $url        New URL.
	 * @param string[] $owned_urls URLs of the attachments we recorded.
	 * @param bool     $force      Replace an admin value.
	 * @param bool     $dry        Dry run.
	 */
	private function set_email_header( string $url, array $owned_urls, bool $force, bool $dry ): void {
		$current = (string) get_option( 'woocommerce_email_header_image', '' );
		if ( $current === $url ) {
			\WP_CLI::log( 'woocommerce_email_header_image = ' . $url );
			return;
		}
		if ( ! $force && '' !== $current && ! in_array( $current, $owned_urls, true ) ) {
			\WP_CLI::log( 'woocommerce_email_header_image = ' . $current . ' skipped (admin value kept)' );
			return;
		}
		++$this->changes;
		if ( ! $dry ) {
			update_option( 'woocommerce_email_header_image', esc_url_raw( $url ) );
		}
		\WP_CLI::log( 'woocommerce_email_header_image = ' . $url . ' (changed)' );
	}

	/**
	 * Rank Math default social image (URL + attachment id).
	 *
	 * @param int      $id         New attachment id.
	 * @param string   $url        New URL.
	 * @param int      $owned_id   Recorded id before this run.
	 * @param string[] $owned_urls URLs of the recorded attachments.
	 * @param bool     $force      Replace an admin value.
	 * @param bool     $dry        Dry run.
	 */
	private function set_social_image( int $id, string $url, int $owned_id, array $owned_urls, bool $force, bool $dry ): void {
		if ( null === $this->rank_math || ! $this->rank_math->is_available() ) {
			return;
		}
		$cur_url = $this->rank_math->stored_titles_setting( 'open_graph_image' );
		$cur_id  = absint( $this->rank_math->stored_titles_setting( 'open_graph_image_id' ) );
		if ( $cur_url === $url && $cur_id === $id && $id > 0 ) {
			\WP_CLI::log( 'titles.open_graph_image = ' . $url );
			\WP_CLI::log( 'titles.open_graph_image_id = ' . $id );
			return;
		}
		$admin_url = '' !== $cur_url && ! in_array( $cur_url, $owned_urls, true );
		$admin_id  = $cur_id > 0 && $cur_id !== $owned_id;
		if ( ! $force && ( $admin_url || $admin_id ) ) {
			\WP_CLI::log( 'titles.open_graph_image = ' . $cur_url . ' skipped (admin value kept)' );
			return;
		}
		$changed = [ 'open_graph_image', 'open_graph_image_id' ];
		if ( ! $dry ) {
			$written = $this->rank_math->write_titles_settings(
				[
					'open_graph_image'    => esc_url_raw( $url ),
					'open_graph_image_id' => (string) $id,
				]
			);
			if ( null === $written ) {
				\WP_CLI::error( 'Could not write the Rank Math options; set the default social image by hand (Rank Math > Titles & Meta > Global Meta).' );
			}
			$changed = $written;
		}
		$this->changes += count( $changed );
		foreach ( $changed as $key ) {
			\WP_CLI::log( sprintf( 'titles.%s = %s (changed)', $key, 'open_graph_image' === $key ? $url : (string) $id ) );
		}
	}
}
