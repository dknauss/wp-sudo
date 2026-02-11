<?php
/**
 * Main plugin class.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 *
 * Bootstraps all plugin components.
 */
class Plugin {

	/**
	 * Admin settings instance.
	 *
	 * @var Admin|null
	 */
	private ?Admin $admin = null;

	/**
	 * Webmaster role instance.
	 *
	 * @var Webmaster_Role|null
	 */
	private ?Webmaster_Role $role = null;

	/**
	 * Sudo session instance.
	 *
	 * @var Sudo_Session|null
	 */
	private ?Sudo_Session $sudo = null;

	/**
	 * Initialize the plugin and register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// Load translations.
		load_plugin_textdomain( 'wp-sudo', false, dirname( WP_SUDO_PLUGIN_BASENAME ) . '/languages' );

		// Initialize the Webmaster role.
		$this->role = new Webmaster_Role();
		$this->role->register();

		// Initialize sudo session handling (runs on front-end and admin).
		$this->sudo = new Sudo_Session();
		$this->sudo->register();

		// Initialize admin settings (admin-only).
		if ( is_admin() ) {
			$this->admin = new Admin();
			$this->admin->register();
		}
	}

	/**
	 * Plugin activation callback.
	 *
	 * @return void
	 */
	public function activate(): void {
		// Add the Webmaster role.
		$role = new Webmaster_Role();
		$role->add_role();

		// Set a flag so we know the plugin has been activated.
		update_option( 'wp_sudo_activated', true );
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		delete_option( 'wp_sudo_activated' );
	}

	/**
	 * Get the Admin instance.
	 *
	 * @return Admin|null
	 */
	public function admin(): ?Admin {
		return $this->admin;
	}

	/**
	 * Get the Webmaster_Role instance.
	 *
	 * @return Webmaster_Role|null
	 */
	public function role(): ?Webmaster_Role {
		return $this->role;
	}

	/**
	 * Get the Sudo_Session instance.
	 *
	 * @return Sudo_Session|null
	 */
	public function sudo(): ?Sudo_Session {
		return $this->sudo;
	}
}
