<?php
/**
 * Modal reauthentication for sudo activation.
 *
 * Provides a dialog overlay that opens when users click
 * "Activate Sudo" in the admin bar, so they can enter
 * their password without navigating away from the current page.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Modal_Reauth
 *
 * Handles AJAX-based password validation and renders the
 * HTML5 dialog modal template in the page footer.
 */
class Modal_Reauth {

	/**
	 * Nonce action for modal AJAX requests.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'wp_sudo_modal_nonce';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// AJAX handlers.
		add_action( 'wp_ajax_wp_sudo_modal_auth', array( $this, 'handle_ajax_auth' ) );
		add_action( 'wp_ajax_wp_sudo_modal_2fa', array( $this, 'handle_ajax_2fa' ) );

		// Render modal template in footer.
		add_action( 'admin_footer', array( $this, 'render_modal_template' ) );
		add_action( 'wp_footer', array( $this, 'render_modal_template' ) );

		// Enqueue assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * AJAX handler for password validation.
	 *
	 * @return void
	 */
	public function handle_ajax_auth(): void {
		// Verify nonce.
		if ( ! check_ajax_referer( self::NONCE_ACTION, '_wpnonce', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __( 'Security check failed. Please reload the page and try again.', 'wp-sudo' ),
				),
				403
			);
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			wp_send_json_error(
				array(
					'code'    => 'not_logged_in',
					'message' => __( 'You must be logged in.', 'wp-sudo' ),
				),
				403
			);
		}

		if ( ! isset( $_POST['password'] ) ) {
			wp_send_json_error(
				array(
					'code'    => 'missing_password',
					'message' => __( 'Password is required.', 'wp-sudo' ),
				),
				400
			);
		}

		$password = wp_unslash( $_POST['password'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be sanitized.

		$result = Sudo_Session::attempt_activation( $user_id, $password );

		switch ( $result['code'] ) {
			case 'success':
				wp_send_json_success( array( 'code' => 'success' ) );
				break;

			case '2fa_pending':
				wp_send_json_success( array( 'code' => '2fa_pending' ) );
				break;

			case 'locked_out':
				wp_send_json_error(
					array(
						'code'      => 'locked_out',
						'remaining' => $result['remaining'] ?? 0,
						/* translators: %d: seconds remaining */
						'message'   => sprintf( __( 'Too many failed attempts. Please wait %d seconds.', 'wp-sudo' ), $result['remaining'] ?? 0 ),
					)
				);
				break;

			case 'invalid_password':
				wp_send_json_error(
					array(
						'code'    => 'invalid_password',
						'message' => __( 'Incorrect password. Please try again.', 'wp-sudo' ),
					)
				);
				break;

			case 'not_allowed':
			default:
				wp_send_json_error(
					array(
						'code'    => 'not_allowed',
						'message' => __( 'You are not allowed to use sudo mode.', 'wp-sudo' ),
					),
					403
				);
				break;
		}
	}

	/**
	 * AJAX handler for two-factor validation.
	 *
	 * @return void
	 */
	public function handle_ajax_2fa(): void {
		// Verify nonce.
		if ( ! check_ajax_referer( self::NONCE_ACTION, '_wpnonce', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __( 'Security check failed. Please reload the page and try again.', 'wp-sudo' ),
				),
				403
			);
		}

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			wp_send_json_error(
				array(
					'code'    => 'not_logged_in',
					'message' => __( 'You must be logged in.', 'wp-sudo' ),
				),
				403
			);
		}

		// Verify that the password step was completed.
		$pending_key = 'wp_sudo_2fa_pending_' . $user_id;
		if ( ! get_transient( $pending_key ) ) {
			wp_send_json_error(
				array(
					'code'    => 'session_expired',
					'message' => __( 'Your verification session has expired. Please start over.', 'wp-sudo' ),
				),
				403
			);
		}

		$valid = false;

		// Built-in: Two Factor plugin validation.
		if ( class_exists( '\\Two_Factor_Core' ) ) {
			$provider = \Two_Factor_Core::get_primary_provider_for_user( $user );
			if ( $provider ) {
				if ( true === $provider->pre_process_authentication( $user ) ) {
					// Provider handled it (e.g., resent code).
					wp_send_json_success( array( 'code' => '2fa_resent' ) );
				}
				$valid = ( true === $provider->validate_authentication( $user ) );
			}
		}

		/** This filter is documented in includes/class-sudo-session.php */
		$valid = (bool) apply_filters( 'wp_sudo_validate_two_factor', $valid, $user );

		if ( $valid ) {
			delete_transient( $pending_key );
			Sudo_Session::activate( $user_id );
			wp_send_json_success( array( 'code' => 'success' ) );
		}

		wp_send_json_error(
			array(
				'code'    => 'invalid_2fa',
				'message' => __( 'Invalid verification code. Please try again.', 'wp-sudo' ),
			)
		);
	}

	/**
	 * Render the hidden dialog element in the page footer.
	 *
	 * Only outputs for eligible users who don't have an active session.
	 *
	 * @return void
	 */
	public function render_modal_template(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id || ! Sudo_Session::user_is_allowed( $user_id ) ) {
			return;
		}

		// Don't render the modal if sudo is already active.
		if ( Sudo_Session::is_active( $user_id ) ) {
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
					<p class="description">
						<?php esc_html_e( 'Enter your password to activate sudo mode and gain temporary Administrator privileges.', 'wp-sudo' ); ?>
					</p>

					<ol class="wp-sudo-lecture">
						<li><?php esc_html_e( 'Respect the privacy of others.', 'wp-sudo' ); ?></li>
						<li><?php esc_html_e( 'Think before you type.', 'wp-sudo' ); ?></li>
						<li><?php esc_html_e( 'With great power comes great responsibility.', 'wp-sudo' ); ?></li>
					</ol>

					<div id="wp-sudo-modal-error" class="notice notice-error inline" role="alert" hidden>
						<p></p>
					</div>

					<form id="wp-sudo-modal-password-form" method="post">
						<p>
							<label for="wp-sudo-modal-password"><?php esc_html_e( 'Password', 'wp-sudo' ); ?></label><br />
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
								<?php esc_html_e( 'Confirm & Activate Sudo', 'wp-sudo' ); ?>
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

					<div id="wp-sudo-modal-2fa-error" class="notice notice-error inline" role="alert" hidden>
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
						 * @param \WP_User $user The user authenticating.
						 */
						do_action( 'wp_sudo_render_two_factor_fields', $user );
						?>
						<p class="submit">
							<button type="submit" class="button button-primary" id="wp-sudo-modal-2fa-submit">
								<?php esc_html_e( 'Verify & Activate Sudo', 'wp-sudo' ); ?>
							</button>
							<button type="button" class="button" id="wp-sudo-modal-2fa-cancel">
								<?php esc_html_e( 'Cancel', 'wp-sudo' ); ?>
							</button>
						</p>
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
	 * Enqueue modal assets for eligible users.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id || ! Sudo_Session::user_is_allowed( $user_id ) ) {
			return;
		}

		// Don't load assets if sudo is already active.
		if ( Sudo_Session::is_active( $user_id ) ) {
			return;
		}

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

		wp_localize_script(
			'wp-sudo-modal',
			'wpSudoModal',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			)
		);
	}
}
