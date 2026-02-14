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

		// Enforce unfiltered_html restriction on every request (tamper detection).
		add_action( 'init', array( $this, 'enforce_editor_unfiltered_html' ), 1 );

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
				// add_query_arg() already encodes the URL, so no need for rawurlencode().
				'return_url' => $this->get_current_admin_url(),
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
	 * Enforce the unfiltered_html restriction on every request.
	 *
	 * Acts as a tamper-detection canary: if the Editor role has the
	 * unfiltered_html capability (e.g. because `wp_user_roles` was modified
	 * directly in the database), this method strips it and fires the
	 * `wp_sudo_capability_tampered` action so logging plugins like
	 * Stream or WP Activity Log can record the event.
	 *
	 * Hooked at `init` priority 1, before `kses_init` (priority 10),
	 * so KSES is always correctly configured.
	 *
	 * On multisite this is a no-op — WordPress core restricts
	 * unfiltered_html to Super Admins.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public function enforce_editor_unfiltered_html(): void {
		if ( is_multisite() ) {
			return;
		}

		$editor = get_role( 'editor' );

		if ( ! $editor ) {
			return;
		}

		// Check if the capability is present on the role.
		if ( empty( $editor->capabilities['unfiltered_html'] ) ) {
			return;
		}

		// Tamper detected — strip the capability and fire an audit hook.
		$editor->remove_cap( 'unfiltered_html' );

		/**
		 * Fires when a capability that should have been removed is detected
		 * on a role, indicating possible database tampering.
		 *
		 * @since 2.1.0
		 *
		 * @param string $role       The role slug (e.g. 'editor').
		 * @param string $capability The capability that was re-added (e.g. 'unfiltered_html').
		 */
		do_action( 'wp_sudo_capability_tampered', 'editor', 'unfiltered_html' );
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

		// Remove unfiltered_html from editors (single-site only).
		self::strip_editor_unfiltered_html();

		// Set a flag so we know the plugin has been activated.
		update_option( 'wp_sudo_activated', true );
	}

	/**
	 * Network-wide activation callback (multisite only).
	 *
	 * Settings and the version stamp are stored as network-wide options,
	 * so a single upgrader run covers all sites.
	 *
	 * WordPress core already restricts unfiltered_html to Super Admins on
	 * multisite, so no capability changes are needed here.
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
		// Restore unfiltered_html to editors (single-site only).
		self::restore_editor_unfiltered_html();

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
	 * Constructs the full URL from scheme + host + REQUEST_URI to ensure
	 * correct handling of admin pages (including network admin paths like
	 * /wp-admin/network/themes.php). Using home_url() with REQUEST_URI
	 * produces incorrect URLs because REQUEST_URI is already an absolute
	 * path from the server root, not relative to the WordPress home.
	 *
	 * @return string The current admin URL, or the admin root as fallback.
	 */
	private function get_current_admin_url(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return is_network_admin() ? network_admin_url() : admin_url();
		}

		$scheme = is_ssl() ? 'https' : 'http';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? 'localhost' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() on the full URL below.
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] );

		return esc_url_raw( $scheme . '://' . $host . $uri );
	}

	/**
	 * Remove the unfiltered_html capability from the Editor role.
	 *
	 * On single-site WordPress, editors have unfiltered_html by default,
	 * which lets them embed scripts, iframes, and other non-whitelisted
	 * HTML in post content. This method removes that capability so KSES
	 * content filtering is always active for editors.
	 *
	 * On multisite, WordPress core already restricts unfiltered_html to
	 * Super Admins, so this is a no-op.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public static function strip_editor_unfiltered_html(): void {
		if ( is_multisite() ) {
			return;
		}

		$editor = get_role( 'editor' );

		if ( $editor ) {
			$editor->remove_cap( 'unfiltered_html' );
		}
	}

	/**
	 * Restore the unfiltered_html capability to the Editor role.
	 *
	 * Called on plugin deactivation and uninstall to leave the site in
	 * its original state.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public static function restore_editor_unfiltered_html(): void {
		if ( is_multisite() ) {
			return;
		}

		$editor = get_role( 'editor' );

		if ( $editor ) {
			$editor->add_cap( 'unfiltered_html' );
		}
	}
}
