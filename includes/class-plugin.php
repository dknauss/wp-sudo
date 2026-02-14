<?php
/**
 * Main plugin orchestrator (v2).
 *
 * Bootstraps all components for action-gated reauthentication.
 * No custom roles — gating is role-agnostic and covers every entry point.
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
 * Creates and wires all plugin components.
 *
 * @since 1.0.0
 * @since 2.0.0 Rewritten: removed Site_Manager_Role and Modal_Reauth;
 *              added Gate, Challenge, Admin_Bar.
 */
class Plugin {

	/**
	 * Gate (multi-surface interceptor) instance.
	 *
	 * @var Gate|null
	 */
	private ?Gate $gate = null;

	/**
	 * Challenge (interstitial reauth page) instance.
	 *
	 * @var Challenge|null
	 */
	private ?Challenge $challenge = null;

	/**
	 * Admin bar (countdown UI) instance.
	 *
	 * @var Admin_Bar|null
	 */
	private ?Admin_Bar $admin_bar = null;

	/**
	 * Site Health integration instance.
	 *
	 * @var Site_Health|null
	 */
	private ?Site_Health $site_health = null;

	/**
	 * Admin settings instance.
	 *
	 * @var Admin|null
	 */
	private ?Admin $admin = null;

	/**
	 * Upgrader instance.
	 *
	 * @var Upgrader|null
	 */
	private ?Upgrader $upgrader = null;

	/**
	 * Initialize the plugin and register hooks.
	 *
	 * Called at `plugins_loaded`. All interactive gating hooks (admin_init,
	 * rest_request_before_callbacks) are registered here. Non-interactive
	 * early hooks (CLI, Cron, XML-RPC) are also registered unless the
	 * mu-plugin has already claimed them.
	 *
	 * @return void
	 */
	public function init(): void {
		// Load translations.
		load_plugin_textdomain( 'wp-sudo', false, dirname( WP_SUDO_PLUGIN_BASENAME ) . '/languages' );

		// Run any pending upgrade routines (must run before other components).
		// Only on admin/CLI requests — front-end visitors never trigger migrations.
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->upgrader = new Upgrader();
			$this->upgrader->maybe_upgrade();
		}

		// Shared dependencies used by multiple components.
		$session = new Sudo_Session();
		$stash   = new Request_Stash();

		// Gate: intercepts gated operations on all surfaces.
		$this->gate = new Gate( $session, $stash );
		$this->gate->register();

		// Register early hooks only if the mu-plugin has not already done so.
		if ( ! defined( 'WP_SUDO_MU_LOADED' ) ) {
			$this->gate->register_early();
		}

		// Challenge: interstitial page for admin UI reauthentication.
		$this->challenge = new Challenge( $stash );
		$this->challenge->register();

		// Admin bar: countdown UI when session is active.
		$this->admin_bar = new Admin_Bar();
		$this->admin_bar->register();

		// Keyboard shortcut: enqueue on admin pages when no active session.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_shortcut' ) );

		// Gate UI: disable action buttons on gated pages when no session.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_gate_ui' ) );

		// Admin settings page (admin-only).
		if ( is_admin() ) {
			$this->admin = new Admin();
			$this->admin->register();

			$this->site_health = new Site_Health();
			$this->site_health->register();
		}
	}

	/**
	 * Enqueue the keyboard shortcut script on admin pages.
	 *
	 * The shortcut (Ctrl+Shift+S / Cmd+Shift+S) navigates to the
	 * challenge page in session-only mode for proactive sudo activation.
	 * Only loads when no sudo session is active and not on the challenge
	 * page itself.
	 *
	 * @return void
	 */
	public function enqueue_shortcut(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		// Don't load if sudo is already active.
		if ( Sudo_Session::is_active( $user_id ) ) {
			return;
		}

		// Don't load on the challenge page — it has its own JS.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'wp-sudo-challenge' === $page ) {
			return;
		}

		wp_enqueue_script(
			'wp-sudo-shortcut',
			WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-shortcut.js',
			array(),
			WP_SUDO_VERSION,
			true
		);

		$challenge_url = add_query_arg(
			array(
				'page'       => 'wp-sudo-challenge',
				'return_url' => rawurlencode( $this->get_current_admin_url() ),
			),
			is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
		);

		wp_localize_script(
			'wp-sudo-shortcut',
			'wpSudoShortcut',
			array(
				'challengeUrl' => $challenge_url,
			)
		);
	}

	/**
	 * Enqueue the gate UI script on gated admin pages.
	 *
	 * Disables Install, Activate, Update, and Delete buttons on theme
	 * and plugin pages when no sudo session is active. Also renders a
	 * persistent admin notice with a link to the challenge page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_gate_ui( string $hook_suffix = '' ): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		// Don't disable buttons when a sudo session is active.
		if ( Sudo_Session::is_active( $user_id ) ) {
			return;
		}

		// Map admin page hook suffixes to page identifiers.
		$page_map = array(
			'theme-install.php'  => 'theme-install',
			'themes.php'         => 'themes',
			'plugin-install.php' => 'plugin-install',
			'plugins.php'        => 'plugins',
		);

		$page = $page_map[ $hook_suffix ] ?? null;

		if ( ! $page ) {
			return;
		}

		wp_enqueue_script(
			'wp-sudo-gate-ui',
			WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-gate-ui.js',
			array(),
			WP_SUDO_VERSION,
			true
		);

		wp_localize_script(
			'wp-sudo-gate-ui',
			'wpSudoGateUi',
			array(
				'page' => $page,
			)
		);
	}

	/**
	 * Plugin activation callback.
	 *
	 * @return void
	 */
	public function activate(): void {
		// Run the upgrader to stamp the version on fresh installs.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Set a flag so we know the plugin has been activated.
		update_option( 'wp_sudo_activated', true );
	}

	/**
	 * Network-wide activation callback (multisite only).
	 *
	 * Settings and the version stamp are stored as network-wide options,
	 * so a single upgrader run covers all sites.
	 *
	 * @return void
	 */
	public function activate_network(): void {
		// Run the upgrader to stamp the version as a network option.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Set a flag so we know the plugin has been network-activated.
		update_site_option( 'wp_sudo_activated', true );
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		if ( is_multisite() ) {
			delete_site_option( 'wp_sudo_activated' );
		} else {
			delete_option( 'wp_sudo_activated' );
		}
	}

	/**
	 * Get the Gate instance.
	 *
	 * @return Gate|null
	 */
	public function gate(): ?Gate {
		return $this->gate;
	}

	/**
	 * Get the Challenge instance.
	 *
	 * @return Challenge|null
	 */
	public function challenge(): ?Challenge {
		return $this->challenge;
	}

	/**
	 * Get the Admin_Bar instance.
	 *
	 * @return Admin_Bar|null
	 */
	public function admin_bar(): ?Admin_Bar {
		return $this->admin_bar;
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
	 * Build the current admin page URL from the request URI.
	 *
	 * Used to pass a return_url to the challenge page so the user
	 * is redirected back to where they were after authentication.
	 *
	 * @return string The current admin URL, or the admin root as fallback.
	 */
	private function get_current_admin_url(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return is_network_admin() ? network_admin_url() : admin_url();
		}

		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		return home_url( $request_uri );
	}
}
