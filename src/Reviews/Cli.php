<?php
/**
 * WP-CLI: `wp vextra reviews status|reconcile|close-legacy [--dry-run]|recount [--dry-run]` (TASK-030). CLI only, no web entry point.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Reviews;

use Vextra\Core\Integrations\Turnstile\Turnstile;
use Vextra\Core\Plugin;

final class Cli {

	public function register(): void {
		\WP_CLI::add_command(
			'vextra reviews status',
			[ $this, 'status' ],
			[ 'shortdesc' => 'Reviews policy state: option drift, comment counts, still-open legacy posts, Turnstile mode. Read-only.' ]
		);
		\WP_CLI::add_command(
			'vextra reviews reconcile',
			[ $this, 'reconcile' ],
			[ 'shortdesc' => 'Write the policy values of the comment / review options into the database (the effective value is forced anyway).' ]
		);
		\WP_CLI::add_command(
			'vextra reviews recount',
			[ $this, 'recount' ],
			[
				'shortdesc' => 'Recompute _wc_review_count / _wc_rating_count / _wc_average_rating of every product (all languages) from APPROVED reviews only.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Only report the products that would change.',
						'optional'    => true,
					],
				],
			]
		);
		\WP_CLI::add_command(
			'vextra reviews close-legacy',
			[ $this, 'close_legacy' ],
			[
				'shortdesc' => 'Close comments and pings of every existing non-product post / page (idempotent).',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Only count, change nothing.',
						'optional'    => true,
					],
				],
			]
		);
	}

	/**
	 * `wp vextra reviews status`.
	 */
	public function status(): void {
		$counts = (array) get_comment_count();
		\WP_CLI::log( 'Comments (all posts): approved ' . (int) ( $counts['approved'] ?? 0 ) . ', held ' . (int) ( $counts['awaiting_moderation'] ?? 0 ) . ', spam ' . (int) ( $counts['spam'] ?? 0 ) . ', trash ' . (int) ( $counts['trash'] ?? 0 ) . ' (demo comments are NOT removed by this module).' );
		$drift = Options::drift();
		\WP_CLI::log( 'Option drift (stored value differs from policy): ' . ( array() === $drift ? 'none' : wp_json_encode( $drift ) ) );
		\WP_CLI::log( 'Policy version stored: ' . (string) Options::stored( '_vx_reviews_policy_ver' ) . ' (current ' . Config::POLICY_VERSION . ')' );
		\WP_CLI::log( 'Legacy posts still open: ' . Closed::close_legacy( true ) );
		$ts = Plugin::instance()->integration( 'turnstile' );
		\WP_CLI::log( 'Turnstile on the review form: ' . ( $ts instanceof Turnstile && $ts->is_available() ? 'on' : 'off (no keys: rate limit + verified-buyer rule still apply)' ) );
		foreach ( [ 'ip_limit', 'ip_window', 'user_limit', 'user_window', 'attempt_limit', 'rest_limit', 'min_length', 'max_length' ] as $key ) {
			\WP_CLI::log( '  ' . $key . ' = ' . Config::get( $key ) );
		}
	}

	/**
	 * `wp vextra reviews reconcile`.
	 */
	public function reconcile(): void {
		$changed = Options::reconcile();
		\WP_CLI::success( array() === $changed ? 'Options already match the policy.' : 'Restored: ' . implode( ', ', $changed ) );
	}

	/**
	 * `wp vextra reviews recount [--dry-run]`.
	 *
	 * @param array<int,string>    $args  Positional args.
	 * @param array<string,string> $assoc Flags.
	 */
	public function recount( $args = [], $assoc = [] ): void {
		unset( $args );
		$dry    = isset( $assoc['dry-run'] );
		$result = Recount::run( $dry );
		foreach ( $result['changed'] as $row ) {
			\WP_CLI::log( sprintf( '#%d  count / avg  %s  ->  %s', $row['id'], $row['before'], $row['after'] ) );
		}
		\WP_CLI::success( sprintf( '%s %d of %d product(s).', $dry ? 'Would change' : 'Changed', count( $result['changed'] ), $result['checked'] ) );
	}

	/**
	 * `wp vextra reviews close-legacy [--dry-run]`.
	 *
	 * @param array<int,string>    $args  Positional args.
	 * @param array<string,string> $assoc Flags.
	 */
	public function close_legacy( $args = [], $assoc = [] ): void {
		unset( $args );
		$dry = isset( $assoc['dry-run'] );
		$n   = Closed::close_legacy( $dry );
		\WP_CLI::success( ( $dry ? 'Would close ' : 'Closed ' ) . $n . ' post(s).' );
	}
}
