<?php
/**
 * Admin settings page.
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
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . WP_SUDO_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );
		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
		add_action( 'admin_head', array( $this, 'add_help_tabs' ) );
	}

	/**
	 * Add the settings page to the admin menu.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'Sudo Settings', 'wp-sudo' ),
			__( 'Sudo', 'wp-sudo' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings, sections, and fields.
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

		// General section.
		add_settings_section(
			'wp_sudo_general',
			__( 'General Settings', 'wp-sudo' ),
			array( $this, 'render_section_general' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'session_duration',
			__( 'Session Duration (minutes)', 'wp-sudo' ),
			array( $this, 'render_field_session_duration' ),
			self::PAGE_SLUG,
			'wp_sudo_general'
		);

		add_settings_field(
			'allowed_roles',
			__( 'Allowed Roles', 'wp-sudo' ),
			array( $this, 'render_field_allowed_roles' ),
			self::PAGE_SLUG,
			'wp_sudo_general'
		);
	}

	/**
	 * Return default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'session_duration' => 15,
			'allowed_roles'    => array( 'editor', 'site_manager' ),
		);
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default_value Fallback value.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default_value = null ): mixed {
		$settings = get_option( self::OPTION_KEY, self::defaults() );

		return $settings[ $key ] ?? $default_value ?? self::defaults()[ $key ] ?? null;
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array<string, mixed> $input Raw input from the settings form.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = array();

		$sanitized['session_duration'] = absint( $input['session_duration'] ?? 15 );
		if ( $sanitized['session_duration'] < 1 || $sanitized['session_duration'] > 15 ) {
			$sanitized['session_duration'] = 15;
		}

		$raw_roles = array_map( 'sanitize_text_field', (array) ( $input['allowed_roles'] ?? array() ) );

		// Strip roles that don't meet the minimum capability floor.
		$all_roles                  = wp_roles()->roles;
		$sanitized['allowed_roles'] = array_values(
			array_filter(
				$raw_roles,
				function ( string $slug ) use ( $all_roles ): bool {
					return isset( $all_roles[ $slug ] ) && ! empty( $all_roles[ $slug ]['capabilities'][ Sudo_Session::MIN_CAPABILITY ] );
				} 
			) 
		);

		return $sanitized;
	}

	/**
	 * Enqueue admin CSS and JS on the plugin settings page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wp-sudo-admin',
			WP_SUDO_PLUGIN_URL . 'admin/css/wp-sudo-admin.css',
			array(),
			WP_SUDO_VERSION
		);
	}

	/**
	 * Add a "Settings" link on the Plugins list page.
	 *
	 * @param array<string> $links Existing action links.
	 * @return array<string>
	 */
	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			__( 'Settings', 'wp-sudo' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Show a one-time notice after plugin activation.
	 *
	 * @return void
	 */
	public function activation_notice(): void {
		if ( ! get_option( 'wp_sudo_activated' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		delete_option( 'wp_sudo_activated' );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Sudo is active. A new Site Manager role has been created.', 'wp-sudo' ),
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Configure sudo settings', 'wp-sudo' )
		);
	}

	/**
	 * Add contextual help tabs to the plugin settings screen.
	 *
	 * @return void
	 */
	public function add_help_tabs(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'settings_page_' . self::PAGE_SLUG !== $screen->id ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-overview',
				'title'   => __( 'Overview', 'wp-sudo' ),
				'content' => '<p>' . __( 'The name "sudo" comes from a <a href="https://en.wikipedia.org/wiki/Sudo" target="_blank" rel="noopener">Unix command</a> that lets a trusted user temporarily act as the system administrator. This plugin applies the same concept to WordPress.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'Sudo gives designated roles a safe, time-limited way to perform administrative tasks without permanently granting them the Administrator role.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'Eligible users see an <strong>Activate Sudo</strong> button in the admin bar. Clicking it requires reauthentication (password and optional two-factor), after which the user receives full Administrator capabilities for the configured session duration.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'Escalated privileges apply only to admin panel page loads. REST API, XML-RPC, AJAX, and Cron requests are never escalated.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'The <code>unfiltered_html</code> capability is stripped from Editors and Site Managers outside of sudo. This prevents arbitrary HTML/JS injection without an active, reauthenticated session.', 'wp-sudo' ) . '</p>',
			) 
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-session-duration',
				'title'   => __( 'Session Duration', 'wp-sudo' ),
				'content' => '<p>' . __( '<strong>Session Duration</strong> controls how long a sudo session lasts before it automatically expires. The maximum is 15 minutes, matching the default Linux sudo timeout.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'When a session expires, the user is redirected to the dashboard and sees a one-time notice with a link to reactivate. Changes to this setting apply to new sessions only — active sessions expire at their original duration.', 'wp-sudo' ) . '</p>',
			) 
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-allowed-roles',
				'title'   => __( 'Allowed Roles', 'wp-sudo' ),
				'content' => '<p>' . __( '<strong>Allowed Roles</strong> determines which user roles may activate sudo mode. Administrators are excluded because they already have full privileges.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'By default, the <strong>Editor</strong> and <strong>Site Manager</strong> roles are allowed. The Site Manager role is a custom role created by this plugin — it has all Editor capabilities plus theme switching, plugin activation, updates, and import/export.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'Roles that lack the <code>edit_others_posts</code> capability (Author, Contributor, Subscriber) cannot be selected — the privilege gap between these roles and full Administrator is too large for safe escalation.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'If a user\'s role is removed from the allowed list while they have an active sudo session, their escalated privileges are revoked on the next page load.', 'wp-sudo' ) . '</p>',
			) 
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-developer-hooks',
				'title'   => __( 'Developer Hooks', 'wp-sudo' ),
				'content' => '<p>' . __( 'Sudo fires the following action hooks for audit logging and integration:', 'wp-sudo' ) . '</p>'
					. '<ul>'
					. '<li><code>wp_sudo_activated( $user_id, $expires, $duration, $role )</code></li>'
					. '<li><code>wp_sudo_deactivated( $user_id, $role )</code></li>'
					. '<li><code>wp_sudo_reauth_failed( $user_id, $attempts )</code></li>'
					. '<li><code>wp_sudo_lockout( $user_id, $attempts )</code></li>'
					. '</ul>'
					. '<p>' . __( 'These are compatible with Stream, WP Activity Log, and similar plugins that listen on <code>do_action()</code> calls.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'For two-factor integration, these filters are available:', 'wp-sudo' ) . '</p>'
					. '<ul>'
					. '<li><code>wp_sudo_requires_two_factor( $needs, $user_id )</code></li>'
					. '<li><code>wp_sudo_validate_two_factor( $valid, $user )</code></li>'
					. '<li><code>wp_sudo_render_two_factor_fields( $user )</code> — action hook</li>'
					. '</ul>',
			) 
		);

		$screen->set_help_sidebar(
			'<p><strong>' . __( 'For more information:', 'wp-sudo' ) . '</strong></p>'
			. '<p><a href="https://en.wikipedia.org/wiki/Sudo" target="_blank" rel="noopener">' . __( 'What is sudo?', 'wp-sudo' ) . '</a></p>'
			. '<p><a href="https://wordpress.org/plugins/two-factor/">' . __( 'Two Factor plugin', 'wp-sudo' ) . '</a></p>'
			. '<p><a href="https://developer.wordpress.org/plugins/users/roles-and-capabilities/">' . __( 'Roles and Capabilities', 'wp-sudo' ) . '</a></p>'
		);
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
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE_SLUG );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save Settings', 'wp-sudo' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the general section description.
	 *
	 * @return void
	 */
	public function render_section_general(): void {
		echo '<p>' . esc_html__( 'Configure how sudo privilege escalation works on this site.', 'wp-sudo' ) . '</p>';
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
		echo '<p class="description">' . esc_html__( 'Changes apply to new sessions only. Active sessions expire at their original duration.', 'wp-sudo' ) . '</p>';
	}

	/**
	 * Render the allowed roles field.
	 *
	 * @return void
	 */
	public function render_field_allowed_roles(): void {
		$allowed = (array) self::get( 'allowed_roles', array() );
		$roles   = wp_roles()->roles;

		// Sort by capability count (ascending) so the list runs from
		// least privileged to most privileged.
		uasort(
			$roles,
			function ( array $a, array $b ): int {
				return count( array_filter( $a['capabilities'] ) ) - count( array_filter( $b['capabilities'] ) );
			} 
		);

		foreach ( $roles as $slug => $role ) {
			// Skip administrator — they already have full privileges.
			if ( 'administrator' === $slug ) {
				continue;
			}

			// Check if this role meets the minimum capability floor.
			$has_floor_cap = ! empty( $role['capabilities'][ Sudo_Session::MIN_CAPABILITY ] );

			if ( $has_floor_cap ) {
				printf(
					'<label><input type="checkbox" name="%s[allowed_roles][]" value="%s" %s /> %s</label><br />',
					esc_attr( self::OPTION_KEY ),
					esc_attr( $slug ),
					checked( in_array( $slug, $allowed, true ), true, false ),
					esc_html( translate_user_role( $role['name'] ) )
				);
			} else {
				printf(
					'<label title="%s"><input type="checkbox" disabled /> <span class="wp-sudo-role-disabled">%s</span></label><br />',
					esc_attr__( 'This role cannot activate sudo — it lacks sufficient base privileges (requires edit_others_posts).', 'wp-sudo' ),
					esc_html( translate_user_role( $role['name'] ) )
				);
			}
		}
		echo '<p class="description">' . esc_html__( 'Select which roles may activate sudo mode. Roles below the Editor trust level are not eligible.', 'wp-sudo' ) . '</p>';
	}
}
