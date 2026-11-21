<?php
/**
 * Admin notices for active plugin conflicts (TASK-028b). `manage_options` only, no input, no remote calls, nothing stored:
 * a notice disappears as soon as the conflict is gone.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Admin;

use Vextra\Core\Compat\Conflicts;

final class ConflictNotices {

	/**
	 * Rule registry.
	 *
	 * @var Conflicts
	 */
	private Conflicts $conflicts;

	public function __construct( Conflicts $conflicts ) {
		$this->conflicts = $conflicts;
	}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( $this->conflicts->evaluate() as $row ) {
			if ( empty( $row['active'] ) ) {
				continue;
			}
			$parts = [ (string) $row['title'] ];
			if ( '' !== (string) $row['detail'] ) {
				$parts[] = '(' . (string) $row['detail'] . ')';
			}
			if ( ! empty( $row['yielded'] ) ) {
				/* translators: %s: module id */
				$parts[] = sprintf( __( 'Модуль «%s» вимкнено.', 'vextra-core' ), (string) $row['module'] );
			}
			if ( '' !== (string) $row['fix_hint'] ) {
				$parts[] = (string) $row['fix_hint'];
			}
			printf(
				'<div class="notice notice-%1$s"><p><strong>vextra-core:</strong> %2$s <code>%3$s</code></p></div>',
				esc_attr( ! empty( $row['blocking'] ) ? 'error' : 'warning' ),
				esc_html( implode( ' ', $parts ) ),
				esc_html( (string) $row['id'] )
			);
		}
	}
}
