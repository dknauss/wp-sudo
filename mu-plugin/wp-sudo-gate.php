<?php
/**
 * WP Sudo — MU-Plugin Early Gate Loader.
 *
 * Optional drop-in for wp-content/mu-plugins/. Copy this file there to
 * guarantee the gate hooks are registered before any regular plugin loads.
 *
 * When installed, the main plugin detects WP_SUDO_MU_LOADED and skips
 * its own early hook registration to avoid duplicate hooks.
 *
 * @package WP_Sudo
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Mark that the mu-plugin is handling early hooks.
define( 'WP_SUDO_MU_LOADED', true );

// Path to the main plugin directory.
$wp_sudo_plugin_dir = WP_CONTENT_DIR . '/plugins/wp-sudo/';

// Only proceed if the main plugin is present.
if ( ! file_exists( $wp_sudo_plugin_dir . 'wp-sudo.php' ) ) {
	return;
}

// Load the autoloader if constants are not yet defined.
if ( ! defined( 'WP_SUDO_PLUGIN_DIR' ) ) {
	require_once $wp_sudo_plugin_dir . 'wp-sudo.php'; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
}

// Register the early non-interactive gate hooks at muplugins_loaded.
add_action(
	'muplugins_loaded',
	static function () {
		$gate = new WP_Sudo\Gate(
			new WP_Sudo\Sudo_Session(),
			new WP_Sudo\Request_Stash()
		);
		$gate->register_early();
	}
);
