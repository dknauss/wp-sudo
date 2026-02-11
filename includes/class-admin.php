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
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'plugin_action_links_' . WP_SUDO_PLUGIN_BASENAME, [ $this, 'add_action_links' ] );
		add_action( 'admin_notices', [ $this, 'activation_notice' ] );
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
			[ $this, 'render_settings_page' ]
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
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => self::defaults(),
			]
		);

		// General section.
		add_settings_section(
			'wp_sudo_general',
			__( 'General Settings', 'wp-sudo' ),
			[ $this, 'render_section_general' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			'session_duration',
			__( 'Session Duration (minutes)', 'wp-sudo' ),
			[ $this, 'render_field_session_duration' ],
			self::PAGE_SLUG,
			'wp_sudo_general'
		);

		add_settings_field(
			'allowed_roles',
			__( 'Allowed Roles', 'wp-sudo' ),
			[ $this, 'render_field_allowed_roles' ],
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
		return [
			'session_duration' => 15,
			'allowed_roles'   => [ 'editor', 'webmaster' ],
		];
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$settings = get_option( self::OPTION_KEY, self::defaults() );

		return $settings[ $key ] ?? $default ?? self::defaults()[ $key ] ?? null;
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array<string, mixed> $input Raw input from the settings form.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = [];

		$sanitized['session_duration'] = absint( $input['session_duration'] ?? 15 );
		if ( $sanitized['session_duration'] < 1 || $sanitized['session_duration'] > 15 ) {
			$sanitized['session_duration'] = 15;
		}

		$sanitized['allowed_roles'] = array_map( 'sanitize_text_field', (array) ( $input['allowed_roles'] ?? [] ) );

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
			[],
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
			esc_html__( 'Sudo is active. A new Webmaster role has been created.', 'wp-sudo' ),
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Configure sudo settings', 'wp-sudo' )
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
	}

	/**
	 * Render the allowed roles field.
	 *
	 * @return void
	 */
	public function render_field_allowed_roles(): void {
		$allowed = (array) self::get( 'allowed_roles', [] );
		$roles   = wp_roles()->roles;

		foreach ( $roles as $slug => $role ) {
			// Skip administrator — they already have full privileges.
			if ( 'administrator' === $slug ) {
				continue;
			}

			printf(
				'<label><input type="checkbox" name="%s[allowed_roles][]" value="%s" %s /> %s</label><br />',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $slug ),
				checked( in_array( $slug, $allowed, true ), true, false ),
				esc_html( translate_user_role( $role['name'] ) )
			);
		}
		echo '<p class="description">' . esc_html__( 'Select which roles may activate sudo mode.', 'wp-sudo' ) . '</p>';
	}
}
