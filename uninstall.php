<?php
/**
 * Runs when the plugin is uninstalled via the WordPress admin.
 *
 * On a standard single-site install, all plugin data is removed:
 * the Site Manager role, plugin options, and user-meta session data.
 *
 * On multisite, per-site data (role, options) is cleaned for every
 * site that had the plugin active. Network-wide user meta is only
 * deleted when no remaining site still has the plugin active.
 *
 * @package WP_Sudo
 */

// Abort if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up all per-site data: role and options.
 *
 * @return void
 */
function wp_sudo_cleanup_site(): void {
	remove_role( 'site_manager' );

	delete_option( 'wp_sudo_settings' );
	delete_option( 'wp_sudo_activated' );
	delete_option( 'wp_sudo_role_version' );
	delete_option( 'wp_sudo_db_version' );
}

/**
 * Remove all sudo-related user meta from the network.
 *
 * @return void
 */
function wp_sudo_cleanup_user_meta(): void {
	delete_metadata( 'user', 0, '_wp_sudo_expires', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_token', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_failed_attempts', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_lockout_until', '', true );
}

if ( is_multisite() ) {
	// Get every site in the network.
	$site_ids = get_sites( [
		'fields'     => 'ids',
		'number'     => 0,
		'network_id' => get_current_network_id(),
	] );

	$plugin_basename = plugin_basename( __DIR__ . '/wp-sudo.php' );

	// Check if the plugin is network-activated (active across all sites).
	// If so, only clean per-site data for the current site; preserve user meta.
	$network_plugins = (array) get_site_option( 'active_sitewide_plugins', [] );
	if ( isset( $network_plugins[ $plugin_basename ] ) ) {
		wp_sudo_cleanup_site();
		return;
	}

	$other_site_active = false;

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );

		$active_plugins = (array) get_option( 'active_plugins', [] );

		if ( in_array( $plugin_basename, $active_plugins, true ) ) {
			// Plugin is still active on this site — don't touch it,
			// but note that user meta must be preserved.
			$other_site_active = true;
		} else {
			// Plugin is not active here — clean up per-site data.
			wp_sudo_cleanup_site();
		}

		restore_current_blog();
	}

	// Only delete network-wide user meta when no site still has
	// the plugin active. User meta is stored in a shared table,
	// so removing it would break sudo on any remaining sites.
	if ( ! $other_site_active ) {
		wp_sudo_cleanup_user_meta();
	}
} else {
	// Single-site: clean up everything.
	wp_sudo_cleanup_site();
	wp_sudo_cleanup_user_meta();
}
