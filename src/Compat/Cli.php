<?php
/**
 * WP-CLI: `wp vextra compat` and `wp vextra smoke`. CLI only, no web entry point.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Compat;

use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;

final class Cli {

	/**
	 * Module (registry + checks).
	 *
	 * @var Compat
	 */
	private Compat $compat;

	public function __construct( Compat $compat ) {
		$this->compat = $compat;
	}

	public function register(): void {
		\WP_CLI::add_command(
			'vextra compat',
			[ $this, 'compat' ],
			[
				'shortdesc' => 'Installed vs tested versions (docs/versions.md) and the required third-party API checks. Plugin conflicts. Exit 1 when a REQUIRED API is missing or a BLOCKING conflict is active.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'format',
						'description' => 'table (default) or json.',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'matrix',
						'description' => 'Path of the tested-version matrix (default: <theme>/docs/versions.md).',
						'optional'    => true,
					],
				],
			]
		);
		\WP_CLI::add_command(
			'vextra smoke',
			[ $this, 'smoke' ],
			[
				'shortdesc' => 'HTTP smoke checks of every language: home, shop, categories, product, cart, account, search, 404, sitemap. Exit 1 on any FAIL.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'format',
						'description' => 'table (default) or json.',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'only',
						'description' => 'One page key: ' . implode( ', ', Smoke::PAGES ) . '.',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'lang',
						'description' => 'One language slug.',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'warn-ms',
						'description' => 'Warn threshold in ms (default ' . Smoke::WARN_MS . ').',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'fail-ms',
						'description' => 'Fail threshold in ms (default ' . Smoke::FAIL_MS . ').',
						'optional'    => true,
					],
					[
						'type'        => 'flag',
						'name'        => 'without-preview',
						'description' => 'Never send the local public-preview header (robots expectations of a non-public site are only reported).',
						'optional'    => true,
					],
					[
						'type'        => 'flag',
						'name'        => 'strict',
						'description' => 'Treat SKIP as failure.',
						'optional'    => true,
					],
				],
			]
		);
	}

	/**
	 * Command: compat.
	 *
	 * @param array<int,string>    $args  Positional args (unused).
	 * @param array<string,string> $assoc Assoc args.
	 */
	public function compat( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$format = $this->format( $assoc );
		$tested = $this->load_matrix( $assoc );

		$versions = [];
		$drift    = 0;
		foreach ( $this->compat->installed_versions() as $component => $installed ) {
			$key = 'php' === $component && 'cli' === PHP_SAPI && isset( $tested['php-cli'] ) ? 'php-cli' : $component;
			$was = $tested[ $key ];
			$st  = Matrix::compare( $key, $installed, $was );
			if ( 'same' !== $st ) {
				++$drift;
			}
			$versions[] = [
				'component' => $component,
				'installed' => (string) $installed,
				'tested'    => $key === $component ? $was : $was . ' (cli)',
				'status'    => $st,
			];
		}

		$apis     = $this->compat->check_apis();
		$missing  = [];
		$warnings = 0;
		foreach ( $apis as $row ) {
			if ( 'MISSING' === $row['status'] ) {
				$missing[] = $row;
			} elseif ( 'WARN' === $row['status'] ) {
				++$warnings;
			}
		}

		$messages = [];
		foreach ( $this->compat->unavailable_plugins() as $plugin ) {
			$messages[] = $plugin . ': not installed / not available';
		}
		foreach ( $missing as $row ) {
			$messages[] = sprintf(
				'MISSING [%s] %s %s — used by %s; fallback: %s',
				$row['plugin'],
				$row['kind'],
				$row['name'],
				$row['used_by'],
				$row['fallback']
			);
		}
		if ( $drift > 0 ) {
			$messages[] = 'WARNING: untested version, run smoke + update log (docs/versions.md).';
		}

		$conflicts = $this->compat->conflicts()->evaluate();
		$blocking  = 0;
		foreach ( $conflicts as $row ) {
			if ( $row['active'] && $row['blocking'] ) {
				++$blocking;
				$messages[] = sprintf( 'CONFLICT [%s] %s %s', $row['id'], Conflicts::sentence( (string) $row['title'] ), Conflicts::sentence( (string) $row['fix_hint'] ) );
			}
		}

		$ok = array() === $missing && 0 === $blocking;
		if ( 'json' === $format ) {
			\WP_CLI::line(
				(string) wp_json_encode(
					[
						'versions'  => $versions,
						'apis'      => array_map( [ $this, 'public_api_row' ], $apis ),
						'conflicts' => $conflicts,
						'summary'   => [
							'required_missing'   => count( $missing ),
							'conflicts_blocking' => $blocking,
							'warnings'           => $warnings,
							'version_drift'      => $drift,
							'ok'                 => $ok,
						],
						'messages'  => $messages,
					]
				)
			);
		} else {
			\WP_CLI::log( 'Versions (installed vs tested):' );
			\WP_CLI\Utils\format_items( 'table', $versions, [ 'component', 'installed', 'tested', 'status' ] );
			\WP_CLI::log( 'APIs:' );
			\WP_CLI\Utils\format_items(
				'table',
				array_map(
					static function ( array $row ): array {
						return [
							'plugin'   => $row['plugin'],
							'kind'     => $row['kind'],
							'name'     => $row['name'],
							'required' => $row['required'] ? 'yes' : 'no',
							'status'   => $row['status'],
						];
					},
					$apis
				),
				[ 'plugin', 'kind', 'name', 'required', 'status' ]
			);
			\WP_CLI::log( 'Conflicts:' );
			\WP_CLI\Utils\format_items(
				'table',
				array_map(
					static function ( array $row ): array {
						return [
							'id'       => $row['id'],
							'module'   => $row['module'],
							'action'   => $row['action'],
							'blocking' => $row['blocking'] ? 'yes' : 'no',
							'status'   => $row['active'] ? ( $row['yielded'] ? 'ACTIVE (yielded)' : 'ACTIVE' ) : 'ok',
							'hint'     => $row['active'] ? $row['fix_hint'] : '',
						];
					},
					$conflicts
				),
				[ 'id', 'module', 'action', 'blocking', 'status', 'hint' ]
			);
			foreach ( $messages as $message ) {
				\WP_CLI::log( $message );
			}
			\WP_CLI::log( sprintf( 'Required missing: %d | optional warnings: %d | version drift: %d | blocking conflicts: %d', count( $missing ), $warnings, $drift, $blocking ) );
		}

		if ( ! $ok ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Command: smoke.
	 *
	 * @param array<int,string>    $args  Positional args (unused).
	 * @param array<string,string> $assoc Assoc args.
	 */
	public function smoke( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$format   = $this->format( $assoc );
		$polylang = Plugin::instance()->integration( 'polylang' );
		if ( ! $polylang instanceof Polylang ) {
			\WP_CLI::error( 'Polylang adapter missing.' );
		}
		$smoke = new Smoke( $polylang );

		$only = isset( $assoc['only'] ) ? sanitize_key( (string) $assoc['only'] ) : '';
		if ( '' !== $only && ! in_array( $only, Smoke::PAGES, true ) ) {
			\WP_CLI::error( 'Unknown --only value. Allowed: ' . implode( ', ', Smoke::PAGES ) . '.' );
		}
		$lang = isset( $assoc['lang'] ) ? sanitize_key( (string) $assoc['lang'] ) : '';
		if ( '' !== $lang && ! in_array( $lang, $smoke->languages(), true ) ) {
			\WP_CLI::error( 'Unknown --lang value. Allowed: ' . implode( ', ', $smoke->languages() ) . '.' );
		}

		$result = $smoke->run(
			[
				'only'       => $only,
				'lang'       => $lang,
				'warn_ms'    => isset( $assoc['warn-ms'] ) ? absint( $assoc['warn-ms'] ) : 0,
				'fail_ms'    => isset( $assoc['fail-ms'] ) ? absint( $assoc['fail-ms'] ) : 0,
				'no_preview' => (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'without-preview', false ),
			]
		);

		$counts = [
			'pass' => 0,
			'fail' => 0,
			'skip' => 0,
			'warn' => 0,
		];
		foreach ( $result['rows'] as $row ) {
			if ( 'FAIL' === $row['result'] ) {
				++$counts['fail'];
			} elseif ( 'SKIP' === $row['result'] ) {
				++$counts['skip'];
			} else {
				++$counts['pass'];
				if ( 'WARN' === $row['result'] ) {
					++$counts['warn'];
				}
			}
		}
		$strict = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'strict', false );
		$failed = $counts['fail'] > 0 || ( $strict && $counts['skip'] > 0 );

		if ( 'json' === $format ) {
			\WP_CLI::line(
				(string) wp_json_encode(
					[
						'preview' => $result['preview'] ? 'on' : 'off',
						'rows'    => $result['rows'],
						'summary' => $counts,
						'ok'      => ! $failed,
					]
				)
			);
		} else {
			\WP_CLI::log( 'preview: ' . ( $result['preview'] ? 'on' : 'off' ) );
			\WP_CLI\Utils\format_items( 'table', $result['rows'], [ 'lang', 'page', 'status', 'ms', 'result', 'failed' ] );
			\WP_CLI::log( sprintf( '%d pass / %d fail / %d skip (%d slow warnings)', $counts['pass'], $counts['fail'], $counts['skip'], $counts['warn'] ) );
		}

		if ( $failed ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Registry row for JSON output.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	public function public_api_row( array $row ): array {
		return [
			'plugin'   => $row['plugin'],
			'kind'     => $row['kind'],
			'name'     => $row['name'],
			'required' => (bool) $row['required'],
			'status'   => $row['status'],
			'note'     => $row['note'],
			'used_by'  => $row['used_by'],
			'fallback' => $row['fallback'],
		];
	}

	/**
	 * Output format (table|json).
	 *
	 * @param array<string,string> $assoc Assoc args.
	 */
	private function format( array $assoc ): string {
		$format = isset( $assoc['format'] ) ? sanitize_key( (string) $assoc['format'] ) : 'table';
		if ( ! in_array( $format, [ 'table', 'json' ], true ) ) {
			\WP_CLI::error( 'Invalid --format. Allowed: table, json.' );
		}
		return $format;
	}

	/**
	 * Read + parse the matrix (read only, never included/evaluated). Errors out with exit 1.
	 *
	 * @param array<string,string> $assoc Assoc args.
	 * @return array<string,string>
	 */
	private function load_matrix( array $assoc ): array {
		if ( isset( $assoc['matrix'] ) && '' !== $assoc['matrix'] ) {
			$path = (string) $assoc['matrix'];
		} else {
			if ( 'vextra' !== get_template() ) {
				\WP_CLI::error( 'Theme "vextra" is not active: docs/versions.md cannot be located (pass --matrix=<path>).' );
			}
			$path = trailingslashit( get_template_directory() ) . 'docs/versions.md';
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			\WP_CLI::error( 'Version matrix not found or unreadable: ' . $path );
		}
		if ( filesize( $path ) > 1048576 ) {
			\WP_CLI::error( 'Version matrix is unexpectedly large (> 1 MB).' );
		}
		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read-only file.
		if ( false === $content ) {
			\WP_CLI::error( 'Version matrix cannot be read: ' . $path );
		}
		try {
			return Matrix::parse( $content );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( 'Version matrix invalid: ' . $e->getMessage() );
		}
	}
}
