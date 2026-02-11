<?php
/**
 * Plugin Name:       Sudo
 * Plugin URI:        https://github.com/dknauss/wp-sudo
 * Description:       Sudo mode for WordPress! Site Managers with Editor capabilities can temporarily escalate their privileges to the Administrator level.
 * Version:           1.2.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Dan Knauss
 * Author URI:        https://profiles.wordpress.org/danknauss/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-sudo
 * Domain Path:       /languages
 *
 * @package WP_Sudo
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin version.
define( 'WP_SUDO_VERSION', '1.2.0' );

// Plugin directory path.
define( 'WP_SUDO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Plugin directory URL.
define( 'WP_SUDO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Plugin basename.
define( 'WP_SUDO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload plugin classes.
 */
spl_autoload_register( function ( string $class ) {
	$prefix    = 'WP_Sudo\\';
	$base_dir  = WP_SUDO_PLUGIN_DIR . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file           = $base_dir . 'class-' . strtolower( str_replace( [ '\\', '_' ], [ '/', '-' ], $relative_class ) ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
});

/**
 * Initialize the plugin.
 *
 * @return WP_Sudo\Plugin Main plugin instance.
 */
function wp_sudo(): WP_Sudo\Plugin {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new WP_Sudo\Plugin();
	}

	return $instance;
}

// Boot the plugin.
add_action( 'plugins_loaded', static function () {
	wp_sudo()->init();
});

// Register activation hook.
register_activation_hook( __FILE__, static function () {
	wp_sudo()->activate();
});

// Register deactivation hook.
register_deactivation_hook( __FILE__, static function () {
	wp_sudo()->deactivate();
});
