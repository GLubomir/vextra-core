<?php
/**
 * Health module: WP-CLI `wp vextra status` (versions, modules, integrations). Read-only, always exit 0.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Health;

use Vextra\Core\Contracts\ModuleInterface;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;

final class Health implements ModuleInterface {

	public function id(): string {
		return 'health';
	}

	public function is_enabled(): bool {
		return true;
	}

	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command(
				'vextra status',
				[ $this, 'status' ],
				[
					'shortdesc' => 'Show vextra-core versions, modules and integrations.',
					'synopsis'  => [
						[
							'type'        => 'assoc',
							'name'        => 'format',
							'description' => 'Output format.',
							'optional'    => true,
							'default'     => 'table',
							'options'     => [ 'table', 'json' ],
						],
					],
				]
			);
		}
	}

	/**
	 * Command callback.
	 *
	 * @param array<int,string>    $args  Positional args (unused).
	 * @param array<string,string> $assoc Assoc args.
	 */
	public function status( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$format = $assoc['format'] ?? 'table';
		$data   = $this->collect( Plugin::instance() );

		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $data ) );
			return;
		}

		\WP_CLI::log( 'vextra-core ' . $data['plugin_version'] . ' | PHP ' . $data['php'] . ' | WP ' . $data['wp'] . ' | WC ' . ( $data['wc'] ?? 'n/a' ) );
		\WP_CLI::log( 'Modules:' );
		\WP_CLI\Utils\format_items( 'table', array_map( [ $this, 'yes_no' ], $data['modules'] ), [ 'id', 'enabled', 'yielded' ] );
		\WP_CLI::log( 'Integrations:' );
		\WP_CLI\Utils\format_items( 'table', array_map( [ $this, 'yes_no' ], $data['integrations'] ), [ 'id', 'available', 'version', 'ready' ] );
	}

	/**
	 * Table rendering: booleans as yes/no, null as empty.
	 *
	 * @param array<string,mixed> $row Row with real booleans.
	 * @return array<string,mixed>
	 */
	public function yes_no( array $row ): array {
		foreach ( $row as $k => $v ) {
			if ( is_bool( $v ) ) {
				$row[ $k ] = $v ? 'yes' : 'no';
			} elseif ( null === $v ) {
				$row[ $k ] = '';
			}
		}
		return $row;
	}

	/**
	 * Gather the report data.
	 *
	 * @param Plugin $plugin Kernel.
	 * @return array<string,mixed>
	 */
	private function collect( Plugin $plugin ): array {
		$modules = [];
		foreach ( $plugin->modules() as $module ) {
			$yielded   = in_array( $module->id(), $plugin->yielded(), true );
			$modules[] = [
				'id'      => $module->id(),
				'enabled' => $module->is_enabled() && ! $yielded,
				'yielded' => $yielded,
			];
		}

		$integrations = [];
		foreach ( $plugin->integrations() as $integration ) {
			$integrations[] = [
				'id'        => $integration->id(),
				'available' => $integration->is_available(),
				'version'   => $integration->version() ?? '',
				'ready'     => $integration instanceof Polylang ? $integration->is_ready() : null,
			];
		}

		$woo = $plugin->integration( 'woocommerce' );

		return [
			'plugin_version' => VEXTRA_CORE_VERSION,
			'php'            => PHP_VERSION,
			'wp'             => get_bloginfo( 'version' ),
			'wc'             => null !== $woo && $woo->is_available() ? $woo->version() : null,
			'modules'        => $modules,
			'integrations'   => $integrations,
		];
	}
}
