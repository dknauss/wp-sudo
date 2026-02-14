<?php
/**
 * WP Sudo — MU-Plugin Loader.
 *
 * This file lives inside the plugin directory (wp-sudo/mu-plugin/) and
 * is loaded by the stable shim at wp-content/mu-plugins/wp-sudo-gate.php.
 * It ships with regular plugin updates so constructor signatures, class
 * names, and autoloader paths can change freely without breaking the
 * shim in mu-plugins/.
 *
 * @package WP_Sudo
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the main plugin if not already bootstrapped. Check the constant
// first to avoid a file_exists() call on every page load when the main
// plugin has already been loaded via plugins_loaded.
if ( ! defined( 'WP_SUDO_PLUGIN_DIR' ) ) {
	$wp_sudo_plugin_dir = WP_CONTENT_DIR . '/plugins/wp-sudo/';

	if ( ! file_exists( $wp_sudo_plugin_dir . 'wp-sudo.php' ) ) {
		return;
	}

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
