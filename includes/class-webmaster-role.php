<?php
/**
 * Webmaster custom role.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Webmaster_Role
 *
 * Registers and manages the "Webmaster" custom user role. The Webmaster role
 * inherits all Editor capabilities and adds a curated set of administrative
 * capabilities so the user can manage most day-to-day site operations without
 * having the full Administrator role.
 */
class Webmaster_Role {

	/**
	 * Role slug.
	 *
	 * @var string
	 */
	public const ROLE_SLUG = 'webmaster';

	/**
	 * Role display name.
	 *
	 * @var string
	 */
	public const ROLE_NAME = 'Webmaster';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// The role is added on activation, but we hook init to ensure
		// capabilities stay in sync if they are updated in a future version.
		add_action( 'init', [ $this, 'maybe_sync_capabilities' ] );
	}

	/**
	 * Add the Webmaster role to WordPress.
	 *
	 * Should be called on plugin activation.
	 *
	 * @return void
	 */
	public function add_role(): void {
		add_role( self::ROLE_SLUG, self::ROLE_NAME, self::capabilities() );
	}

	/**
	 * Remove the Webmaster role from WordPress.
	 *
	 * Should be called on plugin deactivation / uninstall.
	 *
	 * @return void
	 */
	public static function remove_role(): void {
		remove_role( self::ROLE_SLUG );
	}

	/**
	 * Synchronise capabilities when the plugin's capability list is updated.
	 *
	 * Compares a stored version number against the current plugin version
	 * and re-applies capabilities if they differ.
	 *
	 * @return void
	 */
	public function maybe_sync_capabilities(): void {
		$stored = get_option( 'wp_sudo_role_version', '' );

		if ( $stored === WP_SUDO_VERSION ) {
			return;
		}

		// Remove and re-add to pick up any capability changes.
		remove_role( self::ROLE_SLUG );
		add_role( self::ROLE_SLUG, self::ROLE_NAME, self::capabilities() );

		update_option( 'wp_sudo_role_version', WP_SUDO_VERSION );
	}

	/**
	 * Return the full set of capabilities for the Webmaster role.
	 *
	 * Starts with all Editor capabilities and layers on additional
	 * administrative capabilities.
	 *
	 * @return array<string, bool>
	 */
	public static function capabilities(): array {
		// Start with every Editor capability.
		$editor = get_role( 'editor' );
		$caps   = $editor ? $editor->capabilities : [];

		// Remove unfiltered_html — it allows arbitrary HTML/JS injection
		// and should only be available during an active sudo session.
		$caps['unfiltered_html'] = false;

		// Additional administrative capabilities for the Webmaster.
		// NOTE: Dangerous capabilities like edit_users, promote_users, and
		// manage_options are intentionally omitted. They are only available
		// during an active sudo session to prevent permanent self-escalation.
		$extra = [
			// Theme management (switch, but not install/edit).
			'switch_themes'          => true,
			'edit_theme_options'     => true,

			// Plugin management (activate/deactivate, but not install/edit).
			'activate_plugins'       => true,

			// User management (read-only).
			'list_users'             => true,

			// Update core / plugins / themes.
			'update_core'            => true,
			'update_plugins'         => true,
			'update_themes'          => true,

			// Import / export.
			'import'                 => true,
			'export'                 => true,
		];

		return array_merge( $caps, $extra );
	}
}
