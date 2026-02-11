<?php
/**
 * Runs when the plugin is uninstalled via the WordPress admin.
 *
 * @package WP_Sudo
 */

// Abort if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove the Webmaster role.
remove_role( 'webmaster' );

// Remove plugin options.
delete_option( 'wp_sudo_settings' );
delete_option( 'wp_sudo_activated' );
delete_option( 'wp_sudo_role_version' );

// Clean up any lingering sudo session meta from all users.
delete_metadata( 'user', 0, '_wp_sudo_expires', '', true );
delete_metadata( 'user', 0, '_wp_sudo_token', '', true );
delete_metadata( 'user', 0, '_wp_sudo_failed_attempts', '', true );
delete_metadata( 'user', 0, '_wp_sudo_lockout_until', '', true );
