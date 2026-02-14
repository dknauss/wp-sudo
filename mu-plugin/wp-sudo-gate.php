<?php
/**
 * WP Sudo — MU-Plugin Shim.
 *
 * Stable shim that delegates to the loader inside the plugin directory.
 * This file is copied to wp-content/mu-plugins/ and should never need
 * updating — the loader it requires ships with the regular plugin and
 * is updated via the standard WordPress update mechanism.
 *
 * @package WP_Sudo
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_SUDO_MU_LOADED', true );

$wp_sudo_loader = WP_CONTENT_DIR . '/plugins/wp-sudo/mu-plugin/wp-sudo-loader.php';

if ( file_exists( $wp_sudo_loader ) ) {
	require_once $wp_sudo_loader; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
}
