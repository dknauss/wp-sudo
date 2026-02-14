<?php
/**
 * Modal reauthentication for AJAX/REST sudo retry flow.
 *
 * Serves two purposes:
 *
 * 1. **Intercept flow** — When the Gate blocks an AJAX or REST request
 *    with `sudo_required`, the browser JS (wp-sudo-intercept.js) catches
 *    the error, opens this modal for password + optional 2FA, and retries
 *    the original request after session activation.
 *
 * 2. **Keyboard shortcut** — Users can press Ctrl+Shift+S (Cmd+Shift+S
 *    on Mac) on any admin page to proactively activate a sudo session
 *    before triggering a gated operation.
 *
 * The modal and shortcut scripts load on ALL admin pages (for any
 * logged-in user without an active session). The intercept script
 * only loads on pages with gated AJAX/REST operations.
 *
 * The modal reuses the same AJAX handlers as the Challenge page
 * (wp_sudo_challenge_auth, wp_sudo_challenge_2fa) but does NOT
 * register its own — Challenge handles all AJAX auth endpoints.
 * Modal only renders the dialog HTML and loads its own CSS/JS.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Modal
 *
 * Renders the reauthentication dialog and enqueues the intercept
 * script (for sudo_required detection) and the keyboard shortcut
 * script (for proactive sudo activation via Ctrl+Shift+S).
 *
 * @since 2.0.0
 */
class Modal {

	/**
	 * Cached set of $pagenow values that have AJAX or REST gated rules.
	 *
	 * Built once per request from the Action_Registry. Only pages
	 * listed here need the intercept script loaded.
	 *
	 * @var array<string, true>|null
	 */
	private static ?array $gated_pages = null;

	/**
	 * Reset the static gated-pages cache.
	 *
	 * Used in tests to prevent cross-test contamination.
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$gated_pages = null;
	}

	/**
	 * Transient prefix for blocked-action fallback notices.
	 *
	 * When the Gate blocks an AJAX or REST request with sudo_required,
	 * it also sets a short-lived transient so the next page load can
	 * show a WordPress admin notice as a fallback — in case the JS
	 * intercept fails to catch the response and display the modal.
	 *
	 * @var string
	 */
	public const BLOCKED_TRANSIENT_PREFIX = '_wp_sudo_blocked_';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Render the modal dialog in admin footer.
		add_action( 'admin_footer', array( $this, 'render_modal' ) );

		// Enqueue assets on all admin pages (modal + shortcut globally,
		// intercept only on pages with gated AJAX/REST operations).
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Fallback admin notice when the modal JS fails to intercept.
		add_action( 'admin_notices', array( $this, 'render_fallback_notice' ) );
	}

	/**
	 * Render the hidden dialog element in the admin footer.
	 *
	 * Role-agnostic: outputs for any logged-in user who does NOT
	 * already have an active sudo session. When a session is active,
	 * the modal is unnecessary because gated requests pass through
	 * and the keyboard shortcut is silently unavailable.
	 *
	 * @return void
	 */
	public function render_modal(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		// Don't render if sudo is already active — requests will pass.
		if ( Sudo_Session::is_active( $user_id ) ) {
			return;
		}

		// Don't render on the challenge page — it has its own reauth UI.
		if ( $this->is_challenge_page() ) {
			return;
		}

		?>
		<dialog id="wp-sudo-modal" class="wp-sudo-modal" aria-labelledby="wp-sudo-modal-title">
			<div class="wp-sudo-modal-card">

				<!-- Password step -->
				<div id="wp-sudo-modal-password-step">
					<h1 id="wp-sudo-modal-title">
						<span class="dashicons dashicons-shield" aria-hidden="true"></span>
						<?php esc_html_e( 'Confirm Your Identity', 'wp-sudo' ); ?>
					</h1>
					<p class="description" id="wp-sudo-modal-action-label"></p>

					<div id="wp-sudo-modal-error" class="notice notice-error inline" role="alert" aria-atomic="true" hidden>
						<p></p>
					</div>

					<form id="wp-sudo-modal-password-form" method="post">
						<p>
							<label for="wp-sudo-modal-password">
								<?php esc_html_e( 'Password', 'wp-sudo' ); ?>
							</label><br />
							<input
								type="password"
								name="password"
								id="wp-sudo-modal-password"
								class="regular-text"
								autocomplete="current-password"
								aria-describedby="wp-sudo-modal-error"
								required
							/>
						</p>
						<p class="submit">
							<button type="submit" class="button button-primary" id="wp-sudo-modal-submit">
								<?php esc_html_e( 'Confirm & Continue', 'wp-sudo' ); ?>
							</button>
							<button type="button" class="button" id="wp-sudo-modal-cancel">
								<?php esc_html_e( 'Cancel', 'wp-sudo' ); ?>
							</button>
						</p>
					</form>
				</div>

				<!-- 2FA step (hidden until needed) -->
				<div id="wp-sudo-modal-2fa-step" hidden>
					<h1 id="wp-sudo-modal-2fa-title">
						<span class="dashicons dashicons-shield" aria-hidden="true"></span>
						<?php esc_html_e( 'Two-Factor Verification', 'wp-sudo' ); ?>
					</h1>
					<p class="description">
						<?php esc_html_e( 'Your password has been verified. Please complete two-factor authentication.', 'wp-sudo' ); ?>
					</p>

					<div id="wp-sudo-modal-2fa-error" class="notice notice-error inline" role="alert" aria-atomic="true" hidden>
						<p></p>
					</div>

					<form id="wp-sudo-modal-2fa-form" method="post" aria-describedby="wp-sudo-modal-2fa-error">
						<?php
						// Render 2FA provider fields if available.
						$user = get_userdata( $user_id );
						if ( $user && class_exists( '\\Two_Factor_Core' ) && \Two_Factor_Core::is_user_using_two_factor( $user_id ) ) {
							$provider = \Two_Factor_Core::get_primary_provider_for_user( $user );
							if ( $provider ) {
								$provider->authentication_page( $user );
							}
						}

						/**
						 * Render additional two-factor fields for the sudo modal.
						 *
						 * @since 2.0.0
						 *
						 * @param \WP_User $user The user authenticating.
						 */
						do_action( 'wp_sudo_render_two_factor_fields', $user ?? null );
						?>
						<p class="submit">
							<button type="submit" class="button button-primary" id="wp-sudo-modal-2fa-submit">
								<?php esc_html_e( 'Verify & Continue', 'wp-sudo' ); ?>
							</button>
							<button type="button" class="button" id="wp-sudo-modal-2fa-cancel">
								<?php esc_html_e( 'Cancel', 'wp-sudo' ); ?>
							</button>
						</p>
						<span id="wp-sudo-modal-2fa-timer" class="wp-sudo-2fa-timer" hidden aria-live="polite"></span>
					</form>
				</div>

				<!-- Loading overlay -->
				<div id="wp-sudo-modal-loading" class="wp-sudo-modal-loading" role="status" hidden>
					<span class="spinner is-active"></span>
					<span class="wp-sudo-sr-only"><?php esc_html_e( 'Verifying…', 'wp-sudo' ); ?></span>
				</div>

			</div>
		</dialog>
		<?php
	}

	/**
	 * Enqueue modal, shortcut, and intercept assets on admin pages.
	 *
	 * The modal CSS/JS and keyboard shortcut script load on ALL admin
	 * pages for any logged-in user without an active sudo session. The
	 * intercept script (which patches fetch/jQuery.ajax) only loads on
	 * pages with gated AJAX/REST operations.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		// Don't load assets if sudo is already active.
		if ( Sudo_Session::is_active( $user_id ) ) {
			return;
		}

		// Don't load modal/intercept on the challenge page — it has its own JS.
		// The intercept patches fetch() and would interfere with challenge AJAX.
		if ( $this->is_challenge_page() ) {
			return;
		}

		// ── Always-load: modal + shortcut (all admin pages) ──────────

		wp_enqueue_style(
			'wp-sudo-modal',
			WP_SUDO_PLUGIN_URL . 'admin/css/wp-sudo-modal.css',
			array(),
			WP_SUDO_VERSION
		);

		wp_enqueue_script(
			'wp-sudo-modal',
			WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-modal.js',
			array(),
			WP_SUDO_VERSION,
			true
		);

		wp_enqueue_script(
			'wp-sudo-shortcut',
			WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-shortcut.js',
			array( 'wp-sudo-modal' ),
			WP_SUDO_VERSION,
			true
		);

		wp_localize_script(
			'wp-sudo-modal',
			'wpSudoModal',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( Challenge::NONCE_ACTION ),
				'authAction' => Challenge::AJAX_AUTH_ACTION,
				'tfaAction'  => Challenge::AJAX_2FA_ACTION,
			)
		);

		// ── Conditional: intercept (gated-AJAX pages only) ───────────

		if ( $this->page_has_gated_ajax_rules() ) {
			wp_enqueue_script(
				'wp-sudo-intercept',
				WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-intercept.js',
				array( 'wp-sudo-modal' ),
				WP_SUDO_VERSION,
				true
			);
		}
	}

	/**
	 * Render a fallback admin notice when a gated AJAX/REST request was blocked.
	 *
	 * If the modal JS fails to intercept a sudo_required response (e.g.
	 * due to CSP, JS error, or conflict with another plugin), the Gate
	 * sets a short-lived transient. On the next admin page load, this
	 * notice tells the user how to activate a sudo session manually.
	 *
	 * @return void
	 */
	public function render_fallback_notice(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		// No fallback needed if sudo is already active.
		if ( Sudo_Session::is_active( $user_id ) ) {
			return;
		}

		$blocked = get_transient( self::BLOCKED_TRANSIENT_PREFIX . $user_id );

		if ( ! $blocked ) {
			return;
		}

		// Consume the transient — show only once.
		delete_transient( self::BLOCKED_TRANSIENT_PREFIX . $user_id );

		$label = is_array( $blocked ) && ! empty( $blocked['label'] )
			? $blocked['label']
			: __( 'a protected action', 'wp-sudo' );

		$is_mac   = isset( $_SERVER['HTTP_USER_AGENT'] )
			&& false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 'Mac' );
		$shortcut = $is_mac ? 'Cmd+Shift+S' : 'Ctrl+Shift+S';

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			sprintf(
				/* translators: 1: action label, 2: keyboard shortcut */
				esc_html__( 'Your recent action (%1$s) was blocked because it requires reauthentication. Press %2$s to confirm your identity, then try again.', 'wp-sudo' ),
				'<strong>' . esc_html( $label ) . '</strong>',
				'<kbd>' . esc_html( $shortcut ) . '</kbd>'
			)
		);
	}

	/**
	 * Check if the current admin page is the challenge page.
	 *
	 * The challenge page has its own reauth UI and JS controller.
	 * Loading the modal and intercept scripts there would interfere
	 * with the challenge flow — the intercept patches fetch() and
	 * can break the challenge page's direct AJAX calls.
	 *
	 * @return bool True if on the challenge page.
	 */
	private function is_challenge_page(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		return 'wp-sudo-challenge' === $page;
	}

	/**
	 * Additional admin pages where gated AJAX actions can originate.
	 *
	 * Some AJAX actions are triggered from a different page than the one
	 * listed in the rule's admin surface. For example, theme installation
	 * AJAX fires from theme-install.php, but the admin rule targets
	 * update.php (the non-JS fallback). The intercept script must also
	 * load on these origin pages to catch sudo_required responses.
	 *
	 * @var array<string, true>
	 */
	private const AJAX_ORIGIN_PAGES = array(
		'theme-install.php'  => true, // Fires install-theme AJAX.
		'plugin-install.php' => true, // Fires install-plugin AJAX.
		'update-core.php'    => true, // Fires update-plugin and update-theme AJAX.
	);

	/**
	 * Check if the current $pagenow has gated rules with AJAX or REST surfaces.
	 *
	 * The intercept script patches fetch()/jQuery.ajax() globally
	 * to catch sudo_required responses. This is only useful on admin pages
	 * where gated AJAX or REST operations can be triggered.
	 *
	 * @return bool True if the intercept script should load on this page.
	 */
	private function page_has_gated_ajax_rules(): bool {
		if ( null === self::$gated_pages ) {
			self::$gated_pages = self::AJAX_ORIGIN_PAGES;

			foreach ( Action_Registry::get_rules() as $rule ) {
				// Rules with AJAX or REST surfaces may trigger sudo_required.
				if ( empty( $rule['ajax'] ) && empty( $rule['rest'] ) ) {
					continue;
				}

				// Collect pagenow values from the admin surface of the same rule.
				if ( ! empty( $rule['admin']['pagenow'] ) ) {
					$pages = (array) $rule['admin']['pagenow'];
					foreach ( $pages as $page ) {
						self::$gated_pages[ $page ] = true;
					}
				}
			}
		}

		global $pagenow;

		return isset( self::$gated_pages[ $pagenow ] );
	}
}
