<?php
/**
 * Test-only mu-plugin for E2E coverage of wp_sudo_require().
 *
 * @package WP_Sudo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_init',
	static function (): void {
		if ( ! is_admin() || ! isset( $_GET['wp_sudo_require_test'] ) ) {
			return;
		}

		if (
			! wp_sudo_require(
				array(
					'rule_id'    => 'e2e.public_api.require',
					'return_url' => admin_url( '?wp_sudo_require_test=1' ),
				)
			)
		) {
			return;
		}

		add_action(
			'admin_notices',
			static function (): void {
				echo '<div id="wp-sudo-e2e-public-api-ok" class="notice notice-success"><p>Public API require passed.</p></div>';
			}
		);
	}
);
