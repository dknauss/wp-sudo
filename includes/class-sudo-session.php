<?php
/**
 * Sudo session — temporary privilege escalation.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sudo_Session
 *
 * Manages time-limited Administrator-level privilege escalation for users
 * whose role is on the allowed list. The session is tracked in user meta
 * and enforced via the `user_has_cap` filter. An admin-bar button lets
 * eligible users activate and deactivate sudo mode.
 */
class Sudo_Session {

	/**
	 * User-meta key that stores the session expiry timestamp.
	 *
	 * @var string
	 */
	public const META_KEY = '_wp_sudo_expires';

	/**
	 * User-meta key that stores the session binding token.
	 *
	 * @var string
	 */
	public const TOKEN_META_KEY = '_wp_sudo_token';

	/**
	 * Cookie name for session binding.
	 *
	 * @var string
	 */
	public const TOKEN_COOKIE = 'wp_sudo_token';

	/**
	 * User-meta key for tracking failed reauth attempts.
	 *
	 * @var string
	 */
	public const LOCKOUT_META_KEY = '_wp_sudo_failed_attempts';

	/**
	 * User-meta key for lockout expiry timestamp.
	 *
	 * @var string
	 */
	public const LOCKOUT_UNTIL_META_KEY = '_wp_sudo_lockout_until';

	/**
	 * Maximum failed reauth attempts before lockout.
	 *
	 * @var int
	 */
	public const MAX_FAILED_ATTEMPTS = 5;

	/**
	 * Lockout duration in seconds (5 minutes).
	 *
	 * @var int
	 */
	public const LOCKOUT_DURATION = 300;

	/**
	 * Nonce action for toggling.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'wp_sudo_toggle';

	/**
	 * Nonce action for reauthentication.
	 *
	 * @var string
	 */
	public const REAUTH_NONCE = 'wp_sudo_reauth';

	/**
	 * Minimum capability a role must possess to be eligible for sudo.
	 *
	 * Roles without this capability (Author, Contributor, Subscriber)
	 * are never allowed, even if an admin selects them in settings.
	 * This maps to the WordPress trust boundary between Editors
	 * (who manage all content) and Authors (who manage only their own).
	 *
	 * @var string
	 */
	public const MIN_CAPABILITY = 'edit_others_posts';

	/**
	 * Query-string parameter used for the toggle action.
	 *
	 * @var string
	 */
	public const QUERY_PARAM = 'wp_sudo_action';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Capability escalation.
		add_filter( 'user_has_cap', [ $this, 'filter_user_capabilities' ], 10, 4 );

		// Gracefully redirect users whose sudo session just expired,
		// before WordPress checks page-level capabilities.
		add_action( 'admin_init', [ $this, 'handle_expired_session_redirect' ], 1 );

		// Admin-bar button.
		add_action( 'admin_bar_menu', [ $this, 'admin_bar_button' ], 999 );

		// Handle deactivate and reauth-redirect requests.
		add_action( 'admin_init', [ $this, 'handle_deactivate' ] );

		// Handle reauth form submission (must run on admin_init, before headers).
		add_action( 'admin_init', [ $this, 'handle_reauth_submission' ] );

		// Register the hidden reauth page.
		add_action( 'admin_menu', [ $this, 'register_reauth_page' ] );

		// Enqueue admin-bar styles on every admin (and front-end admin bar) page.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_bar_assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_admin_bar_assets' ] );

		// Enqueue reauth page styles.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_reauth_assets' ] );

		// Live countdown + auto-redirect script (runs after admin bar is rendered).
		add_action( 'admin_footer', [ $this, 'sudo_countdown_script' ] );
		add_action( 'wp_footer', [ $this, 'sudo_countdown_script' ] );

		// Show a one-time notice when a sudo session has just expired.
		add_action( 'admin_notices', [ $this, 'sudo_expired_notice' ] );
	}

	// -------------------------------------------------------------------------
	// Session helpers
	// -------------------------------------------------------------------------

	/**
	 * Check if a specific user currently has an active sudo session.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_active( int $user_id ): bool {
		$expires = (int) get_user_meta( $user_id, self::META_KEY, true );

		if ( ! $expires ) {
			return false;
		}

		if ( time() > $expires ) {
			// Expired — clean up and flag so we can redirect gracefully.
			self::clear_session_data( $user_id );
			set_transient( 'wp_sudo_just_expired_' . $user_id, true, 60 );
			return false;
		}

		// Verify the session is bound to this browser via cookie token.
		if ( ! self::verify_token( $user_id ) ) {
			return false;
		}

		// Verify the user's role is still eligible (e.g., an admin may
		// have changed their role while the session was active).
		if ( ! self::user_is_allowed( $user_id ) ) {
			self::clear_session_data( $user_id );
			return false;
		}

		return true;
	}

	/**
	 * Activate sudo mode for the current user.
	 *
	 * @param int $user_id User ID.
	 * @return bool True on success, false if user is not allowed.
	 */
	public static function activate( int $user_id ): bool {
		if ( ! self::user_is_allowed( $user_id ) ) {
			return false;
		}

		$duration = (int) Admin::get( 'session_duration', 15 );
		$expires  = time() + ( $duration * MINUTE_IN_SECONDS );

		update_user_meta( $user_id, self::META_KEY, $expires );

		// Bind session to this browser with a random token.
		self::set_token( $user_id );

		// Clear any failed-attempt counters on successful activation.
		self::reset_failed_attempts( $user_id );

		$user = get_userdata( $user_id );

		/**
		 * Fires when a sudo session is activated.
		 *
		 * Compatible with Stream, WP Activity Log, and similar plugins
		 * that listen on do_action() calls and inspect current user context.
		 *
		 * @param int    $user_id  The user who activated sudo.
		 * @param int    $expires  Unix timestamp when the session expires.
		 * @param int    $duration Session duration in minutes.
		 * @param string $role     The user's current role.
		 */
		do_action(
			'wp_sudo_activated',
			$user_id,
			$expires,
			$duration,
			$user ? $user->roles[0] ?? '' : ''
		);

		return true;
	}

	/**
	 * Deactivate sudo mode for the current user.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function deactivate( int $user_id ): void {
		self::clear_session_data( $user_id );

		$user = get_userdata( $user_id );

		/**
		 * Fires when a sudo session is deactivated.
		 *
		 * @param int    $user_id The user who deactivated sudo.
		 * @param string $role    The user's current role.
		 */
		do_action(
			'wp_sudo_deactivated',
			$user_id,
			$user ? $user->roles[0] ?? '' : ''
		);
	}

	/**
	 * Return the number of seconds remaining in the active session, or 0.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function time_remaining( int $user_id ): int {
		$expires = (int) get_user_meta( $user_id, self::META_KEY, true );

		if ( ! $expires ) {
			return 0;
		}

		$remaining = $expires - time();

		return max( 0, $remaining );
	}

	/**
	 * Determine whether a user is eligible to activate sudo.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_is_allowed( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		// Administrators already have full access — no sudo needed.
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return false;
		}

		$allowed_roles = (array) Admin::get( 'allowed_roles', [ 'editor' ] );

		if ( empty( array_intersect( (array) $user->roles, $allowed_roles ) ) ) {
			return false;
		}

		// Defense-in-depth: reject users who lack the minimum capability floor,
		// regardless of what roles are configured in settings.
		// Note: We check the role's stored capabilities directly instead of
		// using user_can(), because user_can() triggers the user_has_cap
		// filter — which calls is_active() → user_is_allowed() → infinite loop.
		foreach ( (array) $user->roles as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role && ! empty( $role->capabilities[ self::MIN_CAPABILITY ] ) ) {
				return true;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Capability escalation
	// -------------------------------------------------------------------------

	/**
	 * Dynamically grant Administrator capabilities while sudo is active.
	 *
	 * Security: Escalation is scoped to admin panel requests only.
	 * REST API, XML-RPC, AJAX, Application Password, and front-end
	 * requests are explicitly excluded.
	 *
	 * @param array<string, bool> $allcaps All capabilities for the user.
	 * @param array<string>       $caps    Required primitive capabilities.
	 * @param array<mixed>        $args    Arguments: [0] = requested cap, [1] = user ID.
	 * @param \WP_User            $user    The user object.
	 * @return array<string, bool>
	 */
	public function filter_user_capabilities( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		// Strip unfiltered_html from Editors and Site Managers outside of sudo.
		// WordPress grants this to Editors by default on single-site installs,
		// but it allows arbitrary HTML/JS injection and should require sudo.
		if ( ! self::is_active( $user->ID ) ) {
			if ( ! empty( $allcaps['unfiltered_html'] ) && ! $this->user_is_administrator( $user ) ) {
				$allcaps['unfiltered_html'] = false;
			}
			return $allcaps;
		}

		// Block escalation on non-admin request types.
		if ( ! self::is_eligible_request() ) {
			return $allcaps;
		}

		$admin_role = get_role( 'administrator' );

		if ( ! $admin_role ) {
			return $allcaps;
		}

		// Grant every Administrator capability.
		foreach ( $admin_role->capabilities as $cap => $granted ) {
			if ( $granted ) {
				$allcaps[ $cap ] = true;
			}
		}

		return $allcaps;
	}

	/**
	 * Check if a user has the Administrator role.
	 *
	 * Used to avoid stripping unfiltered_html from actual Administrators,
	 * who should always have it.
	 *
	 * @param \WP_User $user The user to check.
	 * @return bool
	 */
	private function user_is_administrator( \WP_User $user ): bool {
		return in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * Determine if the current request is eligible for privilege escalation.
	 *
	 * Only standard admin page loads are eligible. REST API, XML-RPC,
	 * AJAX, Cron, CLI, and Application Password requests are blocked.
	 *
	 * @return bool
	 */
	private static function is_eligible_request(): bool {
		// Block REST API requests.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		// Block XML-RPC requests.
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		// Block AJAX requests.
		if ( wp_doing_ajax() ) {
			return false;
		}

		// Block Cron requests.
		if ( wp_doing_cron() ) {
			return false;
		}

		// Block WP-CLI requests.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		// Block Application Password authenticated requests.
		if ( self::is_app_password_request() ) {
			return false;
		}

		// Only allow in the admin panel.
		if ( ! is_admin() ) {
			return false;
		}

		return true;
	}

	/**
	 * Detect if the current request was authenticated via an Application Password.
	 *
	 * @return bool
	 */
	private static function is_app_password_request(): bool {
		// WordPress >= 5.6 sets this global when an app password is used.
		global $wp_current_application_password_id;

		if ( ! empty( $wp_current_application_password_id ) ) {
			return true;
		}

		// Fall back: check for Basic Auth header (common app-password vector).
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && ! empty( $_SERVER['PHP_AUTH_PW'] ) ) {
			return true;
		}

		// Authorization header with Basic scheme.
		$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		if ( str_starts_with( strtolower( $auth_header ), 'basic ' ) ) {
			return true;
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Expired-session graceful redirect
	// -------------------------------------------------------------------------

	/**
	 * Redirect users whose sudo session just expired to the dashboard.
	 *
	 * Runs at admin_init priority 1 (before WordPress checks page-level
	 * capabilities) so the user sees a friendly notice instead of a
	 * white "access denied" screen.
	 *
	 * @return void
	 */
	public function handle_expired_session_redirect(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$transient_key = 'wp_sudo_just_expired_' . $user_id;

		if ( ! get_transient( $transient_key ) ) {
			return;
		}

		// Only redirect if we're NOT already on the dashboard or on
		// pages the user can access without sudo (like the reauth page).
		$current_page = $_GET['page'] ?? '';

		if ( 'wp-sudo-reauth' === $current_page ) {
			return;
		}

		// Check if we're on the main dashboard (index.php) — that's safe.
		global $pagenow;
		if ( 'index.php' === $pagenow && empty( $_GET['page'] ) ) {
			// Already on the dashboard — just let the notice show.
			return;
		}

		// Don't consume the transient yet — let the dashboard notice display it.
		wp_safe_redirect( admin_url( '?wp_sudo_expired=1' ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Admin-bar UI
	// -------------------------------------------------------------------------

	/**
	 * Add a Sudo button to the admin bar for eligible users.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public function admin_bar_button( \WP_Admin_Bar $wp_admin_bar ): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		// Only show for users who are allowed (or currently have an active session).
		if ( ! self::user_is_allowed( $user_id ) && ! self::is_active( $user_id ) ) {
			return;
		}

		$is_active = self::is_active( $user_id );

		if ( $is_active ) {
			$remaining = self::time_remaining( $user_id );
			$mins      = (int) floor( $remaining / MINUTE_IN_SECONDS );
			$secs      = $remaining % MINUTE_IN_SECONDS;
			$numeric   = sprintf( '%d:%02d', $mins, $secs );

			/* translators: %s: time remaining as M:SS */
			$sr_text = sprintf( __( 'Sudo active, %s remaining', 'wp-sudo' ), $numeric );

			$title = '<span class="ab-icon dashicons dashicons-unlock"></span>'
				. '<span class="ab-label">'
				. esc_html__( 'Sudo', 'wp-sudo' ) . ' ' . esc_html( $numeric )
				. '</span>'
				. '<span class="screen-reader-text">' . esc_html( $sr_text ) . '</span>';
			$href  = wp_nonce_url(
				add_query_arg( self::QUERY_PARAM, 'deactivate' ),
				self::NONCE_ACTION
			);
			$class = 'wp-sudo-active';
		} else {
			$title = '<span class="ab-icon dashicons dashicons-lock"></span><span class="ab-label">'
				. esc_html__( 'Activate Sudo', 'wp-sudo' )
				. '</span>';
			// Link to the reauth page via an intermediary that saves the
			// return URL in a transient (avoids URL-encoding issues).
			$href  = wp_nonce_url(
				add_query_arg( self::QUERY_PARAM, 'reauth' ),
				self::NONCE_ACTION
			);
			$class = 'wp-sudo-inactive';
		}

		$tooltip = $is_active
			? esc_attr__( 'Click to deactivate sudo mode', 'wp-sudo' )
			: esc_attr__( 'Click to reauthenticate and activate sudo mode', 'wp-sudo' );

		$wp_admin_bar->add_node( [
			'id'    => 'wp-sudo-toggle',
			'title' => $title,
			'href'  => $href,
			'meta'  => [
				'class' => $class,
				'title' => $tooltip,
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Deactivate handler
	// -------------------------------------------------------------------------

	/**
	 * Process sudo deactivate requests (no reauth needed)
	 * and reauth redirects.
	 *
	 * @return void
	 */
	public function handle_deactivate(): void {
		if ( ! isset( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], self::NONCE_ACTION ) ) {
			wp_die( __( 'Security check failed.', 'wp-sudo' ), 403 );
		}

		$user_id = get_current_user_id();
		$action  = sanitize_text_field( $_GET[ self::QUERY_PARAM ] );

		if ( 'deactivate' === $action ) {
			self::deactivate( $user_id );

			// Redirect to the dashboard — the user may not have access to
			// the current page without sudo privileges.
			wp_safe_redirect( admin_url() );
			exit;
		}

		if ( 'reauth' === $action ) {
			// Store the referring URL in a short-lived transient so we
			// never have to encode a full URL inside another URL.
			$referer = wp_get_referer();

			if ( ! $referer ) {
				$referer = admin_url();
			}

			$transient_key = 'wp_sudo_redirect_' . $user_id;
			set_transient( $transient_key, $referer, 5 * MINUTE_IN_SECONDS );

			wp_safe_redirect( admin_url( 'admin.php?page=wp-sudo-reauth' ) );
			exit;
		}
	}

	// -------------------------------------------------------------------------
	// Reauthentication page
	// -------------------------------------------------------------------------

	/**
	 * Register a hidden admin page for reauthentication.
	 *
	 * @return void
	 */
	public function register_reauth_page(): void {
		add_submenu_page(
			'', // No parent — hidden page.
			__( 'Confirm Identity — Sudo', 'wp-sudo' ),
			'',
			'read',
			'wp-sudo-reauth',
			[ $this, 'render_reauth_page' ]
		);
	}

	/**
	 * Handle the reauth form POST on admin_init (before headers are sent).
	 *
	 * This must not run inside the render callback because WordPress has
	 * already sent HTTP headers by that point, making wp_safe_redirect()
	 * impossible.
	 *
	 * @return void
	 */
	public function handle_reauth_submission(): void {
		// Only process on the reauth page.
		if ( ! isset( $_GET['page'] ) || 'wp-sudo-reauth' !== $_GET['page'] ) {
			return;
		}

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		// Route to 2FA handler if that's the form being submitted.
		if ( isset( $_POST['wp_sudo_2fa_submit'] ) ) {
			$this->handle_two_factor_submission();
			return;
		}

		if ( ! isset( $_POST['wp_sudo_password'] ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], self::REAUTH_NONCE ) ) {
			wp_die( __( 'Security check failed.', 'wp-sudo' ), 403 );
		}

		$user_id = get_current_user_id();

		// Enforce rate limiting — reject submissions during lockout.
		if ( self::is_locked_out( $user_id ) ) {
			set_transient( 'wp_sudo_reauth_error_' . $user_id, true, 60 );
			return;
		}

		$user          = get_userdata( $user_id );
		$password      = $_POST['wp_sudo_password'];
		$transient_key = 'wp_sudo_redirect_' . $user_id;
		$redirect_to   = get_transient( $transient_key ) ?: admin_url();

		if ( $user && wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			// Reset failed attempts on successful password verification.
			self::reset_failed_attempts( $user_id );

			// If the user has 2FA configured, require it before activation.
			if ( self::needs_two_factor( $user_id ) ) {
				set_transient( 'wp_sudo_2fa_pending_' . $user_id, true, 5 * MINUTE_IN_SECONDS );
				return; // Let render_reauth_page show the 2FA form.
			}

			self::activate( $user_id );
			delete_transient( $transient_key );
			wp_safe_redirect( $redirect_to );
			exit;
		}

		// Track failed attempt for rate limiting.
		self::record_failed_attempt( $user_id );

		/**
		 * Fires when a sudo reauth attempt fails.
		 *
		 * Compatible with Stream, WP Activity Log, and similar plugins.
		 *
		 * @param int $user_id The user who failed reauth.
		 * @param int $attempts Total failed attempts.
		 */
		do_action(
			'wp_sudo_reauth_failed',
			$user_id,
			self::get_failed_attempts( $user_id )
		);

		// Wrong password — store error in a transient so the render method
		// can display it after the redirect-to-self.
		set_transient( 'wp_sudo_reauth_error_' . $user_id, true, 60 );
	}

	/**
	 * Render the reauthentication form.
	 *
	 * @return void
	 */
	public function render_reauth_page(): void {
		$user_id = get_current_user_id();

		if ( ! self::user_is_allowed( $user_id ) ) {
			wp_die( __( 'You are not allowed to use sudo mode.', 'wp-sudo' ), 403 );
		}

		// If sudo is already active, redirect back.
		if ( self::is_active( $user_id ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}

		$user          = get_userdata( $user_id );
		$error         = '';
		$transient_key = 'wp_sudo_redirect_' . $user_id;
		$redirect_to   = get_transient( $transient_key ) ?: admin_url();
		$is_2fa_step   = (bool) get_transient( 'wp_sudo_2fa_pending_' . $user_id );

		// Check for lockout.
		$is_locked_out = self::is_locked_out( $user_id );

		// Check for error from handle_reauth_submission().
		$error_key = 'wp_sudo_reauth_error_' . $user_id;
		$error_val = get_transient( $error_key );
		if ( $error_val ) {
			delete_transient( $error_key );
			if ( $is_locked_out ) {
				$remaining_lockout = self::lockout_remaining( $user_id );
				$error = sprintf(
					/* translators: %d: seconds remaining */
					__( 'Too many failed attempts. Please wait %d seconds before trying again.', 'wp-sudo' ),
					$remaining_lockout
				);
			} elseif ( '2fa' === $error_val ) {
				$error = __( 'Invalid verification code. Please try again.', 'wp-sudo' );
			} else {
				$error = __( 'Incorrect password. Please try again.', 'wp-sudo' );
			}
		}

		// If the password step is complete and 2FA is needed, show the 2FA form.
		if ( $is_2fa_step && $user ) {
			$this->render_two_factor_step( $user, $redirect_to, $error );
			return;
		}

		?>
		<div class="wrap">
			<div class="wp-sudo-reauth-card">
				<h1><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Confirm Your Identity', 'wp-sudo' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'To activate sudo mode and gain temporary Administrator privileges, please enter your password.', 'wp-sudo' ); ?>
				</p>

				<ol class="wp-sudo-lecture">
					<li><?php esc_html_e( 'Respect the privacy of others.', 'wp-sudo' ); ?></li>
					<li><?php esc_html_e( 'Think before you type.', 'wp-sudo' ); ?></li>
					<li><?php esc_html_e( 'With great power comes great responsibility.', 'wp-sudo' ); ?></li>
				</ol>

				<?php if ( $error ) : ?>
					<div class="notice notice-error inline" id="wp-sudo-reauth-error" role="alert"><p><?php echo esc_html( $error ); ?></p></div>
				<?php endif; ?>

				<form method="post">
					<?php wp_nonce_field( self::REAUTH_NONCE ); ?>

					<p>
						<label for="wp_sudo_password"><?php esc_html_e( 'Password', 'wp-sudo' ); ?></label><br />
						<input
							type="password"
							name="wp_sudo_password"
							id="wp_sudo_password"
							class="regular-text"
							autocomplete="current-password"
							required
							<?php echo $error ? 'aria-describedby="wp-sudo-reauth-error"' : ''; ?>
							<?php echo $is_locked_out ? 'disabled' : 'autofocus'; ?>
						/>
					</p>

					<?php submit_button(
						__( 'Confirm &amp; Activate Sudo', 'wp-sudo' ),
						'primary',
						'submit',
						true,
						$is_locked_out ? [ 'disabled' => 'disabled' ] : []
					); ?>
				</form>

				<p class="wp-sudo-reauth-cancel">
					<a href="<?php echo esc_url( $redirect_to ); ?>"><?php esc_html_e( '← Cancel', 'wp-sudo' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Two-factor authentication
	// -------------------------------------------------------------------------

	/**
	 * Check if a user has two-factor authentication configured.
	 *
	 * Supports the Two Factor plugin (WordPress/two-factor) out of the box.
	 * Other 2FA plugins can hook into the `wp_sudo_requires_two_factor` filter.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function needs_two_factor( int $user_id ): bool {
		$needs = false;

		// Built-in: Two Factor plugin (wordpress.org/plugins/two-factor).
		if ( class_exists( '\\Two_Factor_Core' ) && \Two_Factor_Core::is_user_using_two_factor( $user_id ) ) {
			$needs = true;
		}

		/**
		 * Filter whether two-factor authentication is required for sudo.
		 *
		 * Third-party 2FA plugins can hook into this filter to require
		 * their own second factor during sudo reauthentication.
		 *
		 * @param bool $needs   Whether 2FA is required.
		 * @param int  $user_id The user ID.
		 */
		return (bool) apply_filters( 'wp_sudo_requires_two_factor', $needs, $user_id );
	}

	/**
	 * Handle the two-factor verification form submission.
	 *
	 * Called from handle_reauth_submission() when the 2FA form is posted.
	 *
	 * @return void
	 */
	private function handle_two_factor_submission(): void {
		// Verify nonce.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], self::REAUTH_NONCE ) ) {
			wp_die( __( 'Security check failed.', 'wp-sudo' ), 403 );
		}

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		// Verify that the password step was completed.
		$pending_key = 'wp_sudo_2fa_pending_' . $user_id;
		if ( ! get_transient( $pending_key ) ) {
			wp_die( __( 'Your verification session has expired. Please start over.', 'wp-sudo' ), 403 );
		}

		$valid = false;

		// Built-in: Two Factor plugin validation.
		if ( class_exists( '\\Two_Factor_Core' ) ) {
			$provider = \Two_Factor_Core::get_primary_provider_for_user( $user );
			if ( $provider ) {
				// Let the provider handle pre-processing (e.g., resend email codes).
				if ( true === $provider->pre_process_authentication( $user ) ) {
					return; // Provider handled it (e.g., resent code). Re-render the form.
				}
				$valid = ( true === $provider->validate_authentication( $user ) );
			}
		}

		/**
		 * Filter whether the two-factor code is valid for sudo.
		 *
		 * Third-party 2FA plugins can hook into this to validate
		 * their own second factor. If the Two Factor plugin already
		 * validated successfully, $valid will be true.
		 *
		 * @param bool     $valid Whether the 2FA code is valid.
		 * @param \WP_User $user  The user being authenticated.
		 */
		$valid = (bool) apply_filters( 'wp_sudo_validate_two_factor', $valid, $user );

		if ( $valid ) {
			delete_transient( $pending_key );
			self::activate( $user_id );

			$redirect_key = 'wp_sudo_redirect_' . $user_id;
			$redirect_to  = get_transient( $redirect_key ) ?: admin_url();
			delete_transient( $redirect_key );

			wp_safe_redirect( $redirect_to );
			exit;
		}

		// Invalid 2FA code.
		set_transient( 'wp_sudo_reauth_error_' . $user_id, '2fa', 60 );
	}

	/**
	 * Render the two-factor authentication step.
	 *
	 * @param \WP_User $user        The user authenticating.
	 * @param string   $redirect_to URL to redirect to on cancel.
	 * @param string   $error       Error message to display.
	 * @return void
	 */
	private function render_two_factor_step( \WP_User $user, string $redirect_to, string $error ): void {
		?>
		<div class="wrap">
			<div class="wp-sudo-reauth-card">
				<h1><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Two-Factor Verification', 'wp-sudo' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Your password has been verified. Please complete two-factor authentication to activate sudo mode.', 'wp-sudo' ); ?>
				</p>

				<?php if ( $error ) : ?>
					<div class="notice notice-error inline" role="alert"><p><?php echo esc_html( $error ); ?></p></div>
				<?php endif; ?>

				<form method="post">
					<?php wp_nonce_field( self::REAUTH_NONCE ); ?>
					<input type="hidden" name="wp_sudo_2fa_submit" value="1" />

					<?php
					// Built-in: Two Factor plugin form fields.
					if ( class_exists( '\\Two_Factor_Core' ) ) {
						$provider = \Two_Factor_Core::get_primary_provider_for_user( $user );
						if ( $provider ) {
							$provider->authentication_page( $user );
						}
					}

					/**
					 * Render additional two-factor fields for sudo reauthentication.
					 *
					 * Third-party 2FA plugins can hook into this to render
					 * their own form fields alongside or instead of the
					 * Two Factor plugin.
					 *
					 * @param \WP_User $user The user authenticating.
					 */
					do_action( 'wp_sudo_render_two_factor_fields', $user );
					?>

					<?php submit_button(
						__( 'Verify &amp; Activate Sudo', 'wp-sudo' ),
						'primary'
					); ?>
				</form>

				<p class="wp-sudo-reauth-cancel">
					<a href="<?php echo esc_url( $redirect_to ); ?>"><?php esc_html_e( '← Cancel', 'wp-sudo' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Assets & notices
	// -------------------------------------------------------------------------

	/**
	 * Enqueue admin-bar styles whenever the admin bar is showing.
	 *
	 * @return void
	 */
	public function enqueue_admin_bar_assets(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		if ( ! self::user_is_allowed( $user_id ) && ! self::is_active( $user_id ) ) {
			return;
		}

		wp_enqueue_style(
			'wp-sudo-admin-bar',
			WP_SUDO_PLUGIN_URL . 'admin/css/wp-sudo-admin-bar.css',
			[],
			WP_SUDO_VERSION
		);
	}

	/**
	 * Enqueue reauthentication page styles.
	 *
	 * @return void
	 */
	public function enqueue_reauth_assets(): void {
		$current_page = $_GET['page'] ?? '';

		if ( 'wp-sudo-reauth' !== $current_page ) {
			return;
		}

		wp_enqueue_style(
			'wp-sudo-reauth',
			WP_SUDO_PLUGIN_URL . 'admin/css/wp-sudo-reauth.css',
			[],
			WP_SUDO_VERSION
		);
	}

	/**
	 * Output inline JS that keeps the admin-bar countdown ticking
	 * and auto-redirects to the dashboard when the session expires.
	 *
	 * Hooked to `admin_footer` and `wp_footer` so it runs after the
	 * admin bar markup has been rendered.
	 *
	 * @return void
	 */
	public function sudo_countdown_script(): void {
		$user_id = get_current_user_id();

		if ( ! self::is_active( $user_id ) ) {
			return;
		}

		$expires       = (int) get_user_meta( $user_id, self::META_KEY, true );
		$dashboard_url = admin_url();

		$js_data = wp_json_encode( [
			'expiresAt'    => $expires,
			'serverNow'    => (int) time(),
			'dashboardUrl' => $dashboard_url,
			'i18n'         => [
				'sudo'          => __( 'Sudo', 'wp-sudo' ),
				/* translators: %s: time remaining as M:SS */
				'expiryWarning' => __( 'Sudo session expires in %s.', 'wp-sudo' ),
			],
		] );

		$js = <<<JS
		( function() {
			var data = {$js_data};
			var expiresAt = data.expiresAt;
			var dashboardUrl = data.dashboardUrl;
			var i18n = data.i18n;
			var barNode  = document.getElementById( 'wp-admin-bar-wp-sudo-toggle' );
			var barLabel = barNode ? barNode.querySelector( '.ab-label' ) : null;
			var barItem  = barNode ? barNode.querySelector( '.ab-item' ) : null;
			var warningShown = false;

			var offset = data.serverNow - Math.floor( Date.now() / 1000 );

			function getRemaining() {
				return expiresAt - Math.floor( Date.now() / 1000 ) - offset;
			}

			function formatNumeric( seconds ) {
				if ( seconds <= 0 ) return '0:00';
				var m = Math.floor( seconds / 60 );
				var s = seconds % 60;
				return m + ':' + ( s < 10 ? '0' : '' ) + s;
			}

			function tick() {
				var remaining = getRemaining();

				if ( remaining <= 0 ) {
					window.location.href = dashboardUrl;
					return;
				}

				var numeric = formatNumeric( remaining );

				// Update admin bar button label.
				if ( barLabel ) {
					barLabel.textContent = i18n.sudo + ' ' + numeric;
				}

				// Visual + audible warning 60 seconds before expiry.
				if ( remaining <= 60 && ! warningShown ) {
					warningShown = true;

					// Switch admin bar from green to red.
					if ( barItem ) {
						barItem.style.setProperty( 'background', '#d63638', 'important' );
					}

					// Announce to screen readers via a one-time aria-live region.
					var srAlert = document.createElement( 'div' );
					srAlert.setAttribute( 'role', 'status' );
					srAlert.setAttribute( 'aria-live', 'polite' );
					srAlert.className = 'screen-reader-text';
					srAlert.textContent = i18n.expiryWarning.replace( '%s', numeric );
					document.body.appendChild( srAlert );
				}

				setTimeout( tick, 1000 );
			}

			setTimeout( tick, 1000 );
		} )();
JS;

		wp_print_inline_script_tag( $js );
	}

	/**
	 * Show an informational notice when a sudo session has just expired.
	 *
	 * Fires on the dashboard after the server-side redirect, or on any
	 * page the user can still access with their base role.
	 *
	 * @return void
	 */
	public function sudo_expired_notice(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$transient_key = 'wp_sudo_just_expired_' . $user_id;

		if ( ! get_transient( $transient_key ) ) {
			return;
		}

		// Consume the transient so the notice appears only once.
		delete_transient( $transient_key );

		$reauth_url = wp_nonce_url(
			add_query_arg( self::QUERY_PARAM, 'reauth' ),
			self::NONCE_ACTION
		);

		printf(
			'<div class="notice notice-info is-dismissible wp-sudo-notice"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Your sudo session has expired and you have been returned to regular privileges.', 'wp-sudo' ),
			esc_url( $reauth_url ),
			esc_html__( 'Reactivate sudo', 'wp-sudo' )
		);
	}

	// -------------------------------------------------------------------------
	// Cookie token binding
	// -------------------------------------------------------------------------

	/**
	 * Generate and store a random token, set it in a cookie.
	 *
	 * This binds the sudo session to the browser that activated it,
	 * preventing a stolen session cookie on a different device from
	 * inheriting escalated privileges.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function set_token( int $user_id ): void {
		$token = wp_generate_password( 64, false );

		update_user_meta( $user_id, self::TOKEN_META_KEY, hash( 'sha256', $token ) );

		$duration = (int) Admin::get( 'session_duration', 15 );

		setcookie(
			self::TOKEN_COOKIE,
			$token,
			[
				'expires'  => time() + ( $duration * MINUTE_IN_SECONDS ),
				'path'     => ADMIN_COOKIE_PATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			]
		);

		// Also set in superglobal for the current request.
		$_COOKIE[ self::TOKEN_COOKIE ] = $token;
	}

	/**
	 * Verify the cookie token matches the stored hash.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function verify_token( int $user_id ): bool {
		$stored_hash = get_user_meta( $user_id, self::TOKEN_META_KEY, true );

		if ( ! $stored_hash ) {
			return false;
		}

		$cookie_token = $_COOKIE[ self::TOKEN_COOKIE ] ?? '';

		if ( ! $cookie_token ) {
			return false;
		}

		return hash_equals( $stored_hash, hash( 'sha256', $cookie_token ) );
	}

	/**
	 * Clear all session data for a user (meta + cookie).
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function clear_session_data( int $user_id ): void {
		delete_user_meta( $user_id, self::META_KEY );
		delete_user_meta( $user_id, self::TOKEN_META_KEY );

		// Expire the cookie.
		setcookie(
			self::TOKEN_COOKIE,
			'',
			[
				'expires'  => time() - YEAR_IN_SECONDS,
				'path'     => ADMIN_COOKIE_PATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			]
		);

		unset( $_COOKIE[ self::TOKEN_COOKIE ] );
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------

	/**
	 * Record a failed reauth attempt.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function record_failed_attempt( int $user_id ): void {
		$attempts = self::get_failed_attempts( $user_id ) + 1;

		update_user_meta( $user_id, self::LOCKOUT_META_KEY, $attempts );

		if ( $attempts >= self::MAX_FAILED_ATTEMPTS ) {
			update_user_meta(
				$user_id,
				self::LOCKOUT_UNTIL_META_KEY,
				time() + self::LOCKOUT_DURATION
			);

			/**
			 * Fires when a user is locked out from sudo reauth.
			 *
			 * @param int $user_id  The user who was locked out.
			 * @param int $attempts Total failed attempts.
			 */
			do_action( 'wp_sudo_lockout', $user_id, $attempts );
		}
	}

	/**
	 * Get the number of failed attempts for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private static function get_failed_attempts( int $user_id ): int {
		return (int) get_user_meta( $user_id, self::LOCKOUT_META_KEY, true );
	}

	/**
	 * Check if a user is currently locked out.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function is_locked_out( int $user_id ): bool {
		$until = (int) get_user_meta( $user_id, self::LOCKOUT_UNTIL_META_KEY, true );

		if ( ! $until ) {
			return false;
		}

		if ( time() > $until ) {
			// Lockout expired — reset.
			self::reset_failed_attempts( $user_id );
			return false;
		}

		return true;
	}

	/**
	 * Get remaining lockout seconds.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private static function lockout_remaining( int $user_id ): int {
		$until = (int) get_user_meta( $user_id, self::LOCKOUT_UNTIL_META_KEY, true );

		return max( 0, $until - time() );
	}

	/**
	 * Reset failed attempt counters.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function reset_failed_attempts( int $user_id ): void {
		delete_user_meta( $user_id, self::LOCKOUT_META_KEY );
		delete_user_meta( $user_id, self::LOCKOUT_UNTIL_META_KEY );
	}
}
