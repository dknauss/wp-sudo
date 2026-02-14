<?php
/**
 * Challenge page — interstitial reauthentication for gated admin actions.
 *
 * When the Gate intercepts an admin UI request, it stashes the request
 * and redirects here. The user enters their password (+2FA if configured),
 * and on success the stashed request is replayed:
 *   - GET requests: wp_safe_redirect() to the original URL.
 *   - POST requests: self-submitting HTML form with stashed fields.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Challenge
 *
 * @since 2.0.0
 */
class Challenge {

	/**
	 * Nonce action for challenge verification.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'wp_sudo_challenge';

	/**
	 * AJAX action name for password step.
	 *
	 * @var string
	 */
	public const AJAX_AUTH_ACTION = 'wp_sudo_challenge_auth';

	/**
	 * AJAX action name for 2FA step.
	 *
	 * @var string
	 */
	public const AJAX_2FA_ACTION = 'wp_sudo_challenge_2fa';

	/**
	 * Request stash instance.
	 *
	 * @var Request_Stash
	 */
	private Request_Stash $stash;

	/**
	 * Constructor.
	 *
	 * @param Request_Stash $stash Request stash.
	 */
	public function __construct( Request_Stash $stash ) {
		$this->stash = $stash;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );

		// Register in network admin too — challenge page is needed in both contexts.
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'register_page' ) );
		}

		add_action( 'wp_ajax_' . self::AJAX_AUTH_ACTION, array( $this, 'handle_ajax_auth' ) );
		add_action( 'wp_ajax_' . self::AJAX_2FA_ACTION, array( $this, 'handle_ajax_2fa' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the hidden challenge admin page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'', // No parent — hidden page.
			__( 'Confirm Your Identity — Sudo', 'wp-sudo' ),
			'',
			'read',
			'wp-sudo-challenge',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue challenge page assets.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'wp-sudo-challenge' !== $current_page ) {
			return;
		}

		wp_enqueue_style(
			'wp-sudo-challenge',
			WP_SUDO_PLUGIN_URL . 'admin/css/wp-sudo-challenge.css',
			array(),
			WP_SUDO_VERSION
		);

		wp_enqueue_script(
			'wp-sudo-challenge',
			WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-challenge.js',
			array(),
			WP_SUDO_VERSION,
			true
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing data only.
		$stash_key = isset( $_GET['stash_key'] ) ? sanitize_text_field( wp_unslash( $_GET['stash_key'] ) ) : '';

		$cancel_url = is_network_admin() ? network_admin_url() : admin_url();

		wp_localize_script(
			'wp-sudo-challenge',
			'wpSudoChallenge',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
				'stashKey'   => $stash_key,
				'authAction' => self::AJAX_AUTH_ACTION,
				'tfaAction'  => self::AJAX_2FA_ACTION,
				'cancelUrl'  => $cancel_url,
			)
		);
	}

	/**
	 * Render the challenge page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			wp_die( esc_html__( 'You must be logged in.', 'wp-sudo' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing data only.
		$stash_key = isset( $_GET['stash_key'] ) ? sanitize_text_field( wp_unslash( $_GET['stash_key'] ) ) : '';
		$stash     = $this->stash->get( $stash_key, $user_id );

		if ( ! $stash ) {
			wp_die( esc_html__( 'Invalid or expired challenge. Please try again.', 'wp-sudo' ), 403 );
		}

		$action_label = $stash['label'] ?? $stash['rule_id'] ?? __( 'this action', 'wp-sudo' );
		$is_locked    = Sudo_Session::is_locked_out( $user_id );

		?>
		<div class="wrap">
			<div class="wp-sudo-challenge-card" id="wp-sudo-challenge-card">
				<h1>
					<span class="dashicons dashicons-shield" aria-hidden="true"></span>
					<?php esc_html_e( 'Confirm Your Identity', 'wp-sudo' ); ?>
				</h1>
				<p class="description">
					<?php
					printf(
						/* translators: %s: action label (e.g. "Activate plugin") */
						esc_html__( 'To continue: %s — please enter your password.', 'wp-sudo' ),
						'<strong>' . esc_html( $action_label ) . '</strong>'
					);
					?>
				</p>

				<ol class="wp-sudo-lecture">
					<li><?php esc_html_e( 'Respect the privacy of others.', 'wp-sudo' ); ?></li>
					<li><?php esc_html_e( 'Think before you type.', 'wp-sudo' ); ?></li>
					<li><?php esc_html_e( 'With great power comes great responsibility.', 'wp-sudo' ); ?></li>
				</ol>

				<!-- Password step -->
				<div id="wp-sudo-challenge-password-step">
					<?php if ( $is_locked ) : ?>
						<div class="notice notice-warning inline" role="alert">
							<p><?php esc_html_e( 'Too many failed attempts. The form is temporarily disabled. Please wait and try again.', 'wp-sudo' ); ?></p>
						</div>
					<?php endif; ?>

					<div class="notice notice-error inline" id="wp-sudo-challenge-error" hidden role="alert" aria-atomic="true">
						<p></p>
					</div>

					<form id="wp-sudo-challenge-password-form" method="post">
						<p>
							<label for="wp-sudo-challenge-password">
								<?php esc_html_e( 'Password', 'wp-sudo' ); ?>
							</label><br />
							<input
								type="password"
								id="wp-sudo-challenge-password"
								class="regular-text"
								autocomplete="current-password"
								aria-describedby="wp-sudo-challenge-error"
								required
								<?php echo $is_locked ? 'disabled' : 'autofocus'; ?>
							/>
						</p>
						<p class="submit">
							<button type="submit"
								class="button button-primary"
								id="wp-sudo-challenge-submit"
								<?php disabled( $is_locked ); ?>>
								<?php esc_html_e( 'Confirm & Continue', 'wp-sudo' ); ?>
							</button>
							<a href="<?php echo esc_url( is_network_admin() ? network_admin_url() : admin_url() ); ?>" class="button">
								<?php esc_html_e( 'Cancel', 'wp-sudo' ); ?>
							</a>
						</p>
					</form>
				</div>

				<!-- 2FA step (hidden by default) -->
				<div id="wp-sudo-challenge-2fa-step" hidden>
					<h2 id="wp-sudo-challenge-2fa-title">
						<?php esc_html_e( 'Two-Factor Verification', 'wp-sudo' ); ?>
					</h2>

					<div class="notice notice-error inline" id="wp-sudo-challenge-2fa-error" hidden role="alert" aria-atomic="true">
						<p></p>
					</div>

					<form id="wp-sudo-challenge-2fa-form" method="post" aria-describedby="wp-sudo-challenge-2fa-error">
						<?php
						$user = get_userdata( $user_id );
						if ( $user && class_exists( '\\Two_Factor_Core' ) ) {
							$provider = \Two_Factor_Core::get_primary_provider_for_user( $user );
							if ( $provider ) {
								$provider->authentication_page( $user );
							}
						}

						/**
						 * Render additional two-factor fields for challenge reauthentication.
						 *
						 * @since 2.0.0
						 *
						 * @param \WP_User $user The user authenticating.
						 */
						do_action( 'wp_sudo_render_two_factor_fields', $user );
						?>
						<p class="submit">
							<button type="submit"
								class="button button-primary"
								id="wp-sudo-challenge-2fa-submit">
								<?php esc_html_e( 'Verify & Continue', 'wp-sudo' ); ?>
							</button>
							<a href="<?php echo esc_url( is_network_admin() ? network_admin_url() : admin_url() ); ?>" class="button">
								<?php esc_html_e( 'Cancel', 'wp-sudo' ); ?>
							</a>
						</p>
						<span id="wp-sudo-challenge-2fa-timer" class="wp-sudo-2fa-timer" hidden aria-live="polite"></span>
					</form>
				</div>

				<!-- Loading overlay -->
				<div class="wp-sudo-challenge-loading" id="wp-sudo-challenge-loading" hidden role="status">
					<span class="spinner is-active"></span>
					<span class="wp-sudo-sr-only"><?php esc_html_e( 'Verifying…', 'wp-sudo' ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX password verification for the challenge page.
	 *
	 * @return void
	 */
	public function handle_ajax_auth(): void {
		check_ajax_referer( self::NONCE_ACTION );

		$user_id  = get_current_user_id();
		$password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be sanitized.

		if ( ! $user_id || ! $password ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'wp-sudo' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
		$stash_key = isset( $_POST['stash_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stash_key'] ) ) : '';

		// Verify the stash exists — only when a stash_key is provided (challenge page flow).
		// Modal auth sends no stash_key (session activation only, no replay).
		if ( $stash_key && ! $this->stash->exists( $stash_key, $user_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your challenge session has expired. Please try again.', 'wp-sudo' ) ),
				403
			);
		}

		$result = Sudo_Session::attempt_activation( $user_id, $password );

		switch ( $result['code'] ) {
			case 'success':
				if ( $stash_key ) {
					$this->replay_stash( $user_id, $stash_key );
				} else {
					// Modal flow — session is now active, intercept JS handles the retry.
					wp_send_json_success( array( 'code' => 'authenticated' ) );
				}
				break; // replay_stash / wp_send_json_success terminate the request.

			case '2fa_pending':
				wp_send_json_success(
					array(
						'code'       => '2fa_pending',
						'expires_at' => $result['expires_at'] ?? 0,
					)
				);
				break;

			case 'locked_out':
				wp_send_json_error(
					array(
						'message'   => sprintf(
							/* translators: %d: seconds remaining */
							__( 'Too many failed attempts. Please wait %d seconds.', 'wp-sudo' ),
							$result['remaining'] ?? 0
						),
						'code'      => 'locked_out',
						'remaining' => $result['remaining'] ?? 0,
					),
					429
				);
				break;

			case 'not_allowed':
				wp_send_json_error(
					array( 'message' => __( 'You are not allowed to perform this action.', 'wp-sudo' ) ),
					403
				);
				break;

			default:
				wp_send_json_error(
					array( 'message' => __( 'Incorrect password. Please try again.', 'wp-sudo' ) ),
					401
				);
		}
	}

	/**
	 * Handle AJAX 2FA verification for the challenge page.
	 *
	 * @return void
	 */
	public function handle_ajax_2fa(): void {
		check_ajax_referer( self::NONCE_ACTION );

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'wp-sudo' ) ), 400 );
		}

		// Verify 2FA pending state — browser-bound via challenge cookie.
		$pending = Sudo_Session::get_2fa_pending( $user_id );

		if ( ! $pending ) {
			wp_send_json_error(
				array( 'message' => __( 'Your verification session has expired. Please start over.', 'wp-sudo' ) ),
				403
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
		$stash_key = isset( $_POST['stash_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stash_key'] ) ) : '';

		$valid = false;

		// Built-in: Two Factor plugin validation.
		if ( class_exists( '\\Two_Factor_Core' ) ) {
			$provider = \Two_Factor_Core::get_primary_provider_for_user( $user );
			if ( $provider ) {
				if ( true === $provider->pre_process_authentication( $user ) ) {
					wp_send_json_success( array( 'code' => '2fa_resent' ) );
				}
				$valid = ( true === $provider->validate_authentication( $user ) );
			}
		}

		/**
		 * Filter whether the two-factor code is valid for sudo.
		 *
		 * @since 2.0.0
		 *
		 * @param bool     $valid Whether the 2FA code is valid.
		 * @param \WP_User $user  The user being authenticated.
		 */
		$valid = (bool) apply_filters( 'wp_sudo_validate_two_factor', $valid, $user );

		if ( ! $valid ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid verification code. Please try again.', 'wp-sudo' ) ),
				401
			);
		}

		Sudo_Session::clear_2fa_pending();
		Sudo_Session::activate( $user_id );

		if ( $stash_key ) {
			$this->replay_stash( $user_id, $stash_key );
		} else {
			// Modal flow — session is now active, intercept JS handles the retry.
			wp_send_json_success( array( 'code' => 'authenticated' ) );
		}
	}

	/**
	 * Prepare the stashed request for replay and send the JSON response.
	 *
	 * The browser JS receives the replay data and either:
	 *   - Redirects for GET requests.
	 *   - Builds and submits a hidden form for POST requests.
	 *
	 * @param int    $user_id   The user ID.
	 * @param string $stash_key The stash key.
	 * @return void
	 */
	private function replay_stash( int $user_id, string $stash_key ): void {
		$stash = $this->stash->get( $stash_key, $user_id );

		if ( ! $stash ) {
			$fallback_url = is_network_admin() ? network_admin_url() : admin_url();
			wp_send_json_success(
				array(
					'code'     => 'success',
					'redirect' => $fallback_url,
				)
			);
			return;
		}

		// Consume the stash (one-time use).
		$this->stash->delete( $stash_key );

		/**
		 * Fires when a stashed request is about to be replayed.
		 *
		 * @since 2.0.0
		 *
		 * @param int    $user_id The user who reauthenticated.
		 * @param string $rule_id The rule ID that was gated.
		 */
		do_action( 'wp_sudo_action_replayed', $user_id, $stash['rule_id'] ?? '' );

		if ( 'GET' === ( $stash['method'] ?? 'GET' ) ) {
			wp_send_json_success(
				array(
					'code'     => 'success',
					'redirect' => $stash['url'],
				)
			);
			return;
		}

		// POST replay: send the stashed data so JS can build a self-submitting form.
		wp_send_json_success(
			array(
				'code'      => 'success',
				'replay'    => true,
				'method'    => $stash['method'],
				'url'       => $stash['url'],
				'post_data' => $stash['post'] ?? array(),
				'get_data'  => $stash['get'] ?? array(),
			)
		);
	}
}
