<?php
/**
 * Admin settings page (v2).
 *
 * Simplified for v2: no allowed-roles setting (gate is role-agnostic),
 * no custom role references. Settings cover session duration and
 * entry-point policies. Also shows a read-only gated actions reference
 * and MU-plugin status.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 *
 * Handles the plugin settings page in WP Admin.
 *
 * @since 1.0.0
 * @since 2.0.0 Rewritten: removed allowed_roles, added entry-point policies.
 */
class Admin {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'wp_sudo_settings';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'wp-sudo-settings';

	/**
	 * AJAX action for installing the MU-plugin shim.
	 *
	 * @var string
	 */
	public const AJAX_MU_INSTALL = 'wp_sudo_mu_install';

	/**
	 * AJAX action for uninstalling the MU-plugin shim.
	 *
	 * @var string
	 */
	public const AJAX_MU_UNINSTALL = 'wp_sudo_mu_uninstall';

	/**
	 * Per-request cache for the full settings array.
	 *
	 * Prevents redundant is_multisite() + get_option/get_site_option
	 * calls when Admin::get() is invoked multiple times per request
	 * (e.g., session duration + policy lookups).
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cached_settings = null;

	/**
	 * Register admin hooks.
	 *
	 * On multisite, settings live under Network Admin → Settings and
	 * use site options (network-wide). On single-site, they use the
	 * standard Settings API under Settings → Sudo.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'add_network_settings_page' ) );
			add_action( 'network_admin_edit_wp_sudo_settings', array( $this, 'handle_network_settings_save' ) );
			// Register sections/fields so do_settings_sections() works on the network page.
			add_action( 'admin_init', array( $this, 'register_sections' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . WP_SUDO_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );

		// MU-plugin install/uninstall AJAX handlers.
		add_action( 'wp_ajax_' . self::AJAX_MU_INSTALL, array( $this, 'handle_mu_install' ) );
		add_action( 'wp_ajax_' . self::AJAX_MU_UNINSTALL, array( $this, 'handle_mu_uninstall' ) );
	}

	/**
	 * Add the settings page to the admin menu.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		$hook_suffix = add_options_page(
			__( 'Sudo Settings', 'wp-sudo' ),
			__( 'Sudo', 'wp-sudo' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);

		if ( $hook_suffix ) {
			add_action( 'load-' . $hook_suffix, array( $this, 'add_help_tabs' ) );
		}
	}

	/**
	 * Add the network settings page (multisite only).
	 *
	 * @return void
	 */
	public function add_network_settings_page(): void {
		$hook_suffix = add_submenu_page(
			'settings.php',
			__( 'Sudo Settings', 'wp-sudo' ),
			__( 'Sudo', 'wp-sudo' ),
			'manage_network_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);

		if ( $hook_suffix ) {
			add_action( 'load-' . $hook_suffix, array( $this, 'add_help_tabs' ) );
		}
	}

	/**
	 * Handle the network settings form submission.
	 *
	 * WordPress network admin settings pages POST to edit.php with
	 * `action={page_slug}`. This is the standard pattern used by
	 * WordPress core's own network settings.
	 *
	 * @return void
	 */
	public function handle_network_settings_save(): void {
		check_admin_referer( self::PAGE_SLUG . '-options' );

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-sudo' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitized via sanitize_settings().
		$input     = isset( $_POST[ self::OPTION_KEY ] ) ? wp_unslash( $_POST[ self::OPTION_KEY ] ) : array();
		$sanitized = $this->sanitize_settings( (array) $input );

		update_site_option( self::OPTION_KEY, $sanitized );
		self::reset_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => 'true',
				),
				network_admin_url( 'settings.php' )
			)
		);
		exit;
	}

	/**
	 * Register contextual help tabs on the settings page.
	 *
	 * @return void
	 */
	public function add_help_tabs(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-how-it-works',
				'title'   => __( 'How Sudo Works', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Action-Gated Reauthentication', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'WP Sudo gates dangerous operations behind a reauthentication step. When any user attempts a gated action (plugin activation, user deletion, etc.), they must re-enter their password before proceeding.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'This is role-agnostic: administrators, editors, and any custom role are all challenged equally. WordPress capability checks still run after the gate.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'Browser requests (admin UI, AJAX, REST with cookie auth) get an interactive challenge. Non-interactive entry points (WP-CLI, Cron, XML-RPC, App Passwords) are governed by configurable policies.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Two-Factor Authentication', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'WP Sudo is compatible with the Two Factor plugin. When a user has two-factor authentication enabled, the sudo challenge requires both a password and a second-factor verification code. All configured providers (TOTP, email, backup codes, etc.) are supported automatically.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Keyboard Shortcut', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Press Ctrl+Shift+S (Windows/Linux) or Cmd+Shift+S (Mac) to open the sudo challenge without triggering a gated action first. This is useful when you know you are about to perform several gated actions and want to authenticate once upfront. When a session is already active, the shortcut flashes the admin bar timer.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Recommended Plugins', 'wp-sudo' ) . '</h3>'
					. '<ul>'
					. '<li>' . __( '<strong>Two Factor</strong> &mdash; strongly recommended. Adds a second verification step (TOTP, email, backup codes) to the sudo challenge.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>WP Activity Log</strong> or <strong>Stream</strong> &mdash; recommended for audit visibility. These logging plugins capture the 8 action hooks WP Sudo fires for session lifecycle, policy decisions, and gated actions.', 'wp-sudo' ) . '</li>'
					. '</ul>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-settings-help',
				'title'   => __( 'Settings', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Session Duration', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'This setting controls how long the sudo window stays open after reauthentication. Once the session expires, the next gated action will require another challenge. The maximum duration is 15 minutes.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Entry Point Policies', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Each non-interactive entry point can be set to Block or Allow. When set to Block, all gated operations on that entry point are denied. WP-CLI in Allow mode requires the --sudo flag. All actions are logged regardless of policy.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'MU-Plugin', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'The optional mu-plugin ensures gate hooks are registered before any other plugin loads. Install or remove it with one click from the MU-Plugin Status section below. The mu-plugin is a stable shim that loads gate code from the main plugin directory, so it stays current with regular plugin updates.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Multisite', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'On multisite, settings are network-wide and the settings page appears under Network Admin &rarr; Settings &rarr; Sudo. Sudo sessions are also network-wide &mdash; authenticating on one site covers all sites in the network.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-extending',
				'title'   => __( 'Extending', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Custom Gated Actions', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Developers can add custom rules via the <code>wp_sudo_gated_actions</code> filter. Each rule defines matching criteria for admin UI, AJAX, and REST surfaces. Custom rules appear in the Gated Actions table and automatically get coverage on non-interactive surfaces (CLI, Cron, XML-RPC).', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( '2FA Verification Window', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'The default 2FA window is 10 minutes. Use the <code>wp_sudo_two_factor_window</code> filter to adjust it (value in seconds). A visible countdown timer is shown during the verification step.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Third-Party 2FA Integration', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Plugins other than Two Factor can integrate via the <code>wp_sudo_requires_two_factor</code>, <code>wp_sudo_validate_two_factor</code>, and <code>wp_sudo_render_two_factor_fields</code> hooks.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-audit-hooks',
				'title'   => __( 'Audit Hooks', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Available Hooks', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'All hooks are captured by logging plugins like WP Activity Log and Stream.', 'wp-sudo' ) . '</p>'
					. '<ul>'
					. '<li><code>wp_sudo_activated</code> — ' . __( 'Session started.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_deactivated</code> — ' . __( 'Session ended.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_reauth_failed</code> — ' . __( 'Wrong password.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_lockout</code> — ' . __( 'Too many failures.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_action_gated</code> — ' . __( 'Intercepted, challenge shown.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_action_blocked</code> — ' . __( 'Denied by policy.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_action_allowed</code> — ' . __( 'Permitted by policy.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_action_replayed</code> — ' . __( 'Stashed request replayed.', 'wp-sudo' ) . '</li>'
					. '</ul>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . __( 'For more information:', 'wp-sudo' ) . '</strong></p>'
			. '<p><a href="https://en.wikipedia.org/wiki/Sudo" target="_blank">' . __( 'About', 'wp-sudo' ) . '<code>sudo</code></a>' . __( ' (*nix command)', 'wp-sudo' ) . '</p>'
			. '<p><a href="https://wordpress.org/plugins/two-factor/" target="_blank">' . __( 'Two Factor plugin', 'wp-sudo' ) . '</a></p>'
			. '<p><a href="https://wordpress.org/plugins/wp-security-audit-log/" target="_blank">' . __( 'WP Activity Log', 'wp-sudo' ) . '</a></p>'
			. '<p><a href="https://wordpress.org/plugins/stream/" target="_blank">' . __( 'Stream', 'wp-sudo' ) . '</a></p>'
			. '<p><a href="https://developer.wordpress.org/plugins/users/roles-and-capabilities/" target="_blank">' . __( 'Roles &amp; Capabilities', 'wp-sudo' ) . '</a></p>'
		);
	}

	/**
	 * Register plugin settings, sections, and fields.
	 *
	 * Used on single-site only. Registers the setting with the Settings
	 * API so `options.php` handles validation and storage.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::PAGE_SLUG,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);

		$this->register_sections();
	}

	/**
	 * Register settings sections and fields.
	 *
	 * Separated from register_settings() so the sections/fields are
	 * available on multisite network admin pages where the Settings API
	 * (register_setting) is not used.
	 *
	 * @return void
	 */
	public function register_sections(): void {
		// Session section.
		add_settings_section(
			'wp_sudo_session',
			__( 'Session Settings', 'wp-sudo' ),
			array( $this, 'render_section_session' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'session_duration',
			__( 'Session Duration (minutes)', 'wp-sudo' ),
			array( $this, 'render_field_session_duration' ),
			self::PAGE_SLUG,
			'wp_sudo_session'
		);

		// Entry point policies section.
		add_settings_section(
			'wp_sudo_policies',
			__( 'Entry Point Policies', 'wp-sudo' ),
			array( $this, 'render_section_policies' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			Gate::SETTING_REST_APP_PASS_POLICY,
			__( 'REST API (App Passwords)', 'wp-sudo' ),
			array( $this, 'render_field_policy' ),
			self::PAGE_SLUG,
			'wp_sudo_policies',
			array(
				'key'         => Gate::SETTING_REST_APP_PASS_POLICY,
				'description' => __( 'Whether gated operations are permitted via Application Passwords and Bearer tokens.', 'wp-sudo' ),
			)
		);

		add_settings_field(
			Gate::SETTING_CLI_POLICY,
			__( 'WP-CLI', 'wp-sudo' ),
			array( $this, 'render_field_policy' ),
			self::PAGE_SLUG,
			'wp_sudo_policies',
			array(
				'key'         => Gate::SETTING_CLI_POLICY,
				'description' => __( 'Whether gated operations are permitted via WP-CLI. Allow mode requires the --sudo flag.', 'wp-sudo' ),
			)
		);

		add_settings_field(
			Gate::SETTING_CRON_POLICY,
			__( 'Cron', 'wp-sudo' ),
			array( $this, 'render_field_policy' ),
			self::PAGE_SLUG,
			'wp_sudo_policies',
			array(
				'key'         => Gate::SETTING_CRON_POLICY,
				'description' => __( 'Whether gated operations are permitted when triggered by WP-Cron.', 'wp-sudo' ),
			)
		);

		add_settings_field(
			Gate::SETTING_XMLRPC_POLICY,
			__( 'XML-RPC', 'wp-sudo' ),
			array( $this, 'render_field_policy' ),
			self::PAGE_SLUG,
			'wp_sudo_policies',
			array(
				'key'         => Gate::SETTING_XMLRPC_POLICY,
				'description' => __( 'Whether gated operations are permitted via XML-RPC.', 'wp-sudo' ),
			)
		);
	}

	/**
	 * Return default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'session_duration'         => 15,
			'rest_app_password_policy' => Gate::POLICY_BLOCK,
			'cli_policy'               => Gate::POLICY_BLOCK,
			'cron_policy'              => Gate::POLICY_BLOCK,
			'xmlrpc_policy'            => Gate::POLICY_BLOCK,
		);
	}

	/**
	 * Get a single setting value.
	 *
	 * On multisite, settings are stored as a network-wide site option.
	 * On single-site, they are a regular option.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default_value Fallback value.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default_value = null ): mixed {
		if ( null === self::$cached_settings ) {
			self::$cached_settings = is_multisite()
				? get_site_option( self::OPTION_KEY, self::defaults() )
				: get_option( self::OPTION_KEY, self::defaults() );
		}

		return self::$cached_settings[ $key ] ?? $default_value ?? self::defaults()[ $key ] ?? null;
	}

	/**
	 * Reset the settings cache.
	 *
	 * Called after settings are saved, and available for tests.
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cached_settings = null;
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array<string, mixed> $input Raw input from the settings form.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = array();

		// Session duration: 1–15 minutes.
		$sanitized['session_duration'] = absint( $input['session_duration'] ?? 15 );
		if ( $sanitized['session_duration'] < 1 || $sanitized['session_duration'] > 15 ) {
			$sanitized['session_duration'] = 15;
		}

		// Entry point policies: block or allow.
		$policy_keys = array(
			Gate::SETTING_REST_APP_PASS_POLICY,
			Gate::SETTING_CLI_POLICY,
			Gate::SETTING_CRON_POLICY,
			Gate::SETTING_XMLRPC_POLICY,
		);

		foreach ( $policy_keys as $key ) {
			$value             = sanitize_text_field( $input[ $key ] ?? Gate::POLICY_BLOCK );
			$sanitized[ $key ] = Gate::POLICY_ALLOW === $value ? Gate::POLICY_ALLOW : Gate::POLICY_BLOCK;
		}

		return $sanitized;
	}

	/**
	 * Enqueue admin CSS and JS on the plugin settings page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Single-site: 'settings_page_wp-sudo-settings'
		// Multisite network admin: 'settings_page_wp-sudo-settings'
		// Both produce the same hook suffix from add_options_page / add_submenu_page.
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wp-sudo-admin',
			WP_SUDO_PLUGIN_URL . 'admin/css/wp-sudo-admin.css',
			array(),
			WP_SUDO_VERSION
		);

		wp_enqueue_script(
			'wp-sudo-admin',
			WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-admin.js',
			array(),
			WP_SUDO_VERSION,
			true
		);

		wp_localize_script(
			'wp-sudo-admin',
			'wpSudoAdmin',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'wp_sudo_mu_plugin' ),
				'installAction'   => self::AJAX_MU_INSTALL,
				'uninstallAction' => self::AJAX_MU_UNINSTALL,
			)
		);
	}

	/**
	 * Add a "Settings" link on the Plugins list page.
	 *
	 * @param array<string> $links Existing action links.
	 * @return array<string>
	 */
	public function add_action_links( array $links ): array {
		$url = is_multisite()
			? network_admin_url( 'settings.php?page=' . self::PAGE_SLUG )
			: admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			__( 'Settings', 'wp-sudo' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	// -------------------------------------------------------------------------
	// Render callbacks
	// -------------------------------------------------------------------------

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';

		if ( ! current_user_can( $required_cap ) ) {
			return;
		}

		$is_network = is_multisite();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php if ( $is_network && isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'wp-sudo' ); ?></p>
				</div>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'WP Sudo adds a reauthentication step before dangerous operations like activating plugins, deleting users, or changing critical settings. Any user who attempts a gated action must re-enter their password — and complete two-factor authentication if enabled — before proceeding.', 'wp-sudo' ); ?>
			</p>
			<?php if ( $is_network ) : ?>
				<form action="<?php echo esc_url( network_admin_url( 'edit.php?action=wp_sudo_settings' ) ); ?>" method="post">
					<?php
					wp_nonce_field( self::PAGE_SLUG . '-options' );
					do_settings_sections( self::PAGE_SLUG );
					submit_button( __( 'Save Settings', 'wp-sudo' ) );
					?>
				</form>
			<?php else : ?>
				<form action="options.php" method="post">
					<?php
					settings_fields( self::PAGE_SLUG );
					do_settings_sections( self::PAGE_SLUG );
					submit_button( __( 'Save Settings', 'wp-sudo' ) );
					?>
				</form>
			<?php endif; ?>

			<?php $this->render_mu_plugin_status(); ?>

			<?php $this->render_gated_actions_table(); ?>
		</div>
		<?php
	}

	/**
	 * Render the read-only gated actions reference table.
	 *
	 * Lists all currently registered gated actions grouped by category,
	 * including any custom rules added via the wp_sudo_gated_actions filter.
	 *
	 * @return void
	 */
	public function render_gated_actions_table(): void {
		$categories = Action_Registry::get_categories();

		if ( empty( $categories ) ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'Gated Actions', 'wp-sudo' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The following actions require reauthentication before execution. Developers can add custom rules via the wp_sudo_gated_actions filter.', 'wp-sudo' ); ?>
		</p>
		<table class="widefat striped" role="presentation">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Category', 'wp-sudo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'wp-sudo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Surfaces', 'wp-sudo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $categories as $category ) : ?>
					<?php
					$rules = Action_Registry::get_rules_by_category( $category );
					$first = true;
					foreach ( $rules as $rule ) :
						$surfaces = array();
						if ( ! empty( $rule['admin'] ) ) {
							$surfaces[] = __( 'Admin', 'wp-sudo' );
						}
						if ( ! empty( $rule['ajax'] ) ) {
							$surfaces[] = __( 'AJAX', 'wp-sudo' );
						}
						if ( ! empty( $rule['rest'] ) ) {
							$surfaces[] = __( 'REST', 'wp-sudo' );
						}
						?>
						<tr>
							<td><?php echo $first ? esc_html( ucfirst( $category ) ) : ''; ?></td>
							<td>
								<?php echo esc_html( $rule['label'] ?? $rule['id'] ); ?>
								<code class="wp-sudo-rule-id"><?php echo esc_html( $rule['id'] ); ?></code>
							</td>
							<td><?php echo esc_html( implode( ', ', $surfaces ) ); ?></td>
						</tr>
						<?php
						$first = false;
					endforeach;
					?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the MU-plugin status section.
	 *
	 * Shows whether the MU-plugin shim is installed and provides
	 * a button to install or remove it.
	 *
	 * @return void
	 */
	public function render_mu_plugin_status(): void {
		$installed = defined( 'WP_SUDO_MU_LOADED' );
		?>
		<h2><?php esc_html_e( 'Early Gate (MU-Plugin)', 'wp-sudo' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The optional MU-plugin shim ensures gate hooks are registered before any regular plugin loads. This prevents other plugins from deregistering or bypassing the gate. The shim delegates to a loader inside the plugin directory, so it never needs updating — regular plugin updates handle it automatically.', 'wp-sudo' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'wp-sudo' ); ?></th>
					<td>
						<p id="wp-sudo-mu-status">
							<?php if ( $installed ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color: #00a32a;" aria-hidden="true"></span>
								<?php esc_html_e( 'Installed', 'wp-sudo' ); ?>
							<?php else : ?>
								<span class="dashicons dashicons-warning" style="color: #dba617;" aria-hidden="true"></span>
								<?php esc_html_e( 'Not installed', 'wp-sudo' ); ?>
							<?php endif; ?>
						</p>
						<?php if ( $installed ) : ?>
							<button type="button" class="button" id="wp-sudo-mu-uninstall">
								<?php esc_html_e( 'Remove MU-Plugin', 'wp-sudo' ); ?>
							</button>
						<?php else : ?>
							<button type="button" class="button button-primary" id="wp-sudo-mu-install">
								<?php esc_html_e( 'Install MU-Plugin', 'wp-sudo' ); ?>
							</button>
						<?php endif; ?>
						<span id="wp-sudo-mu-spinner" class="spinner" role="status" aria-label="<?php esc_attr_e( 'Processing…', 'wp-sudo' ); ?>"></span>
						<p id="wp-sudo-mu-message" class="description" role="status" aria-live="polite" tabindex="-1"></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Check if the MU-plugin shim is installed.
	 *
	 * @return bool True if the shim file exists in mu-plugins/.
	 */
	public static function is_mu_plugin_installed(): bool {
		$mu_dir = defined( 'WPMU_PLUGIN_DIR' )
			? WPMU_PLUGIN_DIR
			: ( WP_CONTENT_DIR . '/mu-plugins' );

		return file_exists( $mu_dir . '/wp-sudo-gate.php' );
	}

	/**
	 * Get the path to the MU-plugins directory.
	 *
	 * @return string Absolute path to the mu-plugins directory.
	 */
	private static function get_mu_plugin_dir(): string {
		return defined( 'WPMU_PLUGIN_DIR' )
			? WPMU_PLUGIN_DIR
			: ( WP_CONTENT_DIR . '/mu-plugins' );
	}

	/**
	 * Handle AJAX request to install the MU-plugin shim.
	 *
	 * Copies the stable shim file from wp-sudo/mu-plugin/wp-sudo-gate.php
	 * to wp-content/mu-plugins/wp-sudo-gate.php. Creates the mu-plugins
	 * directory if it does not exist.
	 *
	 * @return void
	 */
	public function handle_mu_install(): void {
		check_ajax_referer( 'wp_sudo_mu_plugin', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-sudo' ) ), 403 );
		}

		$source = WP_SUDO_PLUGIN_DIR . 'mu-plugin/wp-sudo-gate.php';
		$mu_dir = self::get_mu_plugin_dir();
		$dest   = $mu_dir . '/wp-sudo-gate.php';

		if ( ! file_exists( $source ) ) {
			wp_send_json_error( array( 'message' => __( 'Source shim file not found.', 'wp-sudo' ) ) );
		}

		// Create the mu-plugins directory if needed.
		if ( ! is_dir( $mu_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
			if ( ! mkdir( $mu_dir, 0755, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Could not create mu-plugins directory. Check file permissions.', 'wp-sudo' ) ) );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		$contents = file_get_contents( $source );
		if ( false === $contents ) {
			wp_send_json_error( array( 'message' => __( 'Could not read source shim file.', 'wp-sudo' ) ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		$written = file_put_contents( $dest, $contents );
		if ( false === $written ) {
			wp_send_json_error( array( 'message' => __( 'Could not write to mu-plugins directory. Check file permissions.', 'wp-sudo' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'MU-plugin installed. It will be active on the next page load.', 'wp-sudo' ) ) );
	}

	/**
	 * Handle AJAX request to uninstall the MU-plugin shim.
	 *
	 * Deletes wp-content/mu-plugins/wp-sudo-gate.php.
	 *
	 * @return void
	 */
	public function handle_mu_uninstall(): void {
		check_ajax_referer( 'wp_sudo_mu_plugin', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-sudo' ) ), 403 );
		}

		$mu_file = self::get_mu_plugin_dir() . '/wp-sudo-gate.php';

		if ( ! file_exists( $mu_file ) ) {
			wp_send_json_success( array( 'message' => __( 'MU-plugin is already removed.', 'wp-sudo' ) ) );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		if ( ! unlink( $mu_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not remove MU-plugin file. Check file permissions.', 'wp-sudo' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'MU-plugin removed. It will be inactive on the next page load.', 'wp-sudo' ) ) );
	}

	/**
	 * Render the session section description.
	 *
	 * @return void
	 */
	public function render_section_session(): void {
		echo '<p>' . esc_html__( 'Configure how long a sudo session lasts after reauthentication.', 'wp-sudo' ) . '</p>';
	}

	/**
	 * Render the policies section description.
	 *
	 * @return void
	 */
	public function render_section_policies(): void {
		echo '<p>' . esc_html__( 'Control whether gated operations are permitted on non-interactive entry points. Browser-based requests (admin UI, AJAX, REST with cookie auth) always get the reauthentication challenge. All actions are logged regardless of policy.', 'wp-sudo' ) . '</p>';
	}

	/**
	 * Render the session duration field.
	 *
	 * @return void
	 */
	public function render_field_session_duration(): void {
		$value = self::get( 'session_duration', 15 );
		printf(
			'<input type="number" id="session_duration" name="%s[session_duration]" value="%d" min="1" max="15" class="small-text" />',
			esc_attr( self::OPTION_KEY ),
			absint( $value )
		);
		echo '<p class="description">' . esc_html__( 'How long a sudo session lasts before automatically expiring (maximum 15 minutes).', 'wp-sudo' ) . '</p>';
	}

	/**
	 * Render a policy toggle field (Block / Allow).
	 *
	 * @param array<string, string> $args Field arguments (key, description).
	 * @return void
	 */
	public function render_field_policy( array $args ): void {
		$key   = $args['key'] ?? '';
		$value = self::get( $key, Gate::POLICY_BLOCK );

		printf(
			'<select id="%1$s" name="%2$s[%1$s]">',
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY )
		);
		printf(
			'<option value="block" %s>%s</option>',
			selected( $value, Gate::POLICY_BLOCK, false ),
			esc_html__( 'Block (default)', 'wp-sudo' )
		);
		printf(
			'<option value="allow" %s>%s</option>',
			selected( $value, Gate::POLICY_ALLOW, false ),
			esc_html__( 'Allow', 'wp-sudo' )
		);
		echo '</select>';

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}
}
