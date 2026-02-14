<?php
/**
 * Multi-surface interceptor for gated admin actions.
 *
 * Detects which surface a request enters through (admin UI, AJAX, REST,
 * CLI, Cron, XML-RPC) and either challenges, soft-blocks, or hard-blocks
 * depending on the surface and policy settings.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Gate
 *
 * The heart of WP Sudo v2. Role-agnostic: any logged-in user attempting
 * a gated action is intercepted, regardless of role. WordPress's own
 * capability checks still run after the gate.
 *
 * @since 2.0.0
 */
class Gate {

	/**
	 * Policy value: block all gated operations on this surface.
	 *
	 * @var string
	 */
	public const POLICY_BLOCK = 'block';

	/**
	 * Policy value: allow gated operations on this surface.
	 *
	 * @var string
	 */
	public const POLICY_ALLOW = 'allow';

	/**
	 * Settings key for WP-CLI policy.
	 *
	 * @var string
	 */
	public const SETTING_CLI_POLICY = 'cli_policy';

	/**
	 * Settings key for Cron policy.
	 *
	 * @var string
	 */
	public const SETTING_CRON_POLICY = 'cron_policy';

	/**
	 * Settings key for XML-RPC policy.
	 *
	 * @var string
	 */
	public const SETTING_XMLRPC_POLICY = 'xmlrpc_policy';

	/**
	 * Settings key for REST App Password policy.
	 *
	 * @var string
	 */
	public const SETTING_REST_APP_PASS_POLICY = 'rest_app_password_policy';

	/**
	 * Transient prefix for blocked-action fallback notices.
	 *
	 * When the Gate blocks an AJAX or REST request with `sudo_required`,
	 * it sets a short-lived transient so the next page load can show a
	 * WordPress admin notice — in case the user needs to activate a
	 * sudo session and retry the action.
	 *
	 * @var string
	 */
	public const BLOCKED_TRANSIENT_PREFIX = '_wp_sudo_blocked_';

	/**
	 * The sudo session instance.
	 *
	 * @var Sudo_Session
	 */
	private Sudo_Session $session;

	/**
	 * The request stash instance.
	 *
	 * @var Request_Stash
	 */
	private Request_Stash $stash;

	/**
	 * Constructor.
	 *
	 * @param Sudo_Session  $session Session manager.
	 * @param Request_Stash $stash   Request stash.
	 */
	public function __construct( Sudo_Session $session, Request_Stash $stash ) {
		$this->session = $session;
		$this->stash   = $stash;
	}

	/**
	 * Register all interception hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Admin UI + AJAX interception (admin-ajax.php also fires admin_init).
		add_action( 'admin_init', array( $this, 'intercept' ), 1 );

		// REST API interception — fires after route matching, before callbacks.
		add_filter( 'rest_request_before_callbacks', array( $this, 'intercept_rest' ), 10, 3 );

		// Fallback admin notice when a gated AJAX/REST request was blocked.
		add_action( 'admin_notices', array( $this, 'render_blocked_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'render_blocked_notice' ) );

		// Persistent gate notice on gated pages when no sudo session is active.
		add_action( 'admin_notices', array( $this, 'render_gate_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'render_gate_notice' ) );

		// PHP action link filters for server-rendered buttons (plugins list table).
		add_filter( 'plugin_action_links', array( $this, 'filter_plugin_action_links' ), 50, 2 );
		add_filter( 'theme_action_links', array( $this, 'filter_theme_action_links' ), 50, 2 );
	}

	/**
	 * Register early hooks for non-interactive surfaces.
	 *
	 * Called at `plugins_loaded` (or `muplugins_loaded` if the mu-plugin
	 * is installed) to block gated operations on CLI, Cron, and XML-RPC
	 * before any other plugin can process them.
	 *
	 * @return void
	 */
	public function register_early(): void {
		add_action( 'init', array( $this, 'gate_non_interactive' ), 0 );
	}

	/**
	 * Gate non-interactive surfaces (CLI, Cron, XML-RPC) at init.
	 *
	 * Runs at `init` priority 0 so it fires after WordPress core is
	 * fully loaded (roles, options, etc.) but before plugins handle
	 * any gated operations.
	 *
	 * @return void
	 */
	public function gate_non_interactive(): void {
		$surface = $this->detect_surface();

		if ( 'cli' === $surface ) {
			$this->gate_cli();
		} elseif ( 'cron' === $surface ) {
			$this->gate_cron();
		} elseif ( 'xmlrpc' === $surface ) {
			$this->gate_xmlrpc();
		}
	}

	/**
	 * Gate WP-CLI operations.
	 *
	 * Policy "block" (default): deny all gated operations.
	 * Policy "allow": permit only with --sudo flag.
	 *
	 * @return void
	 */
	public function gate_cli(): void {
		$policy = $this->get_policy( self::SETTING_CLI_POLICY );

		if ( self::POLICY_ALLOW === $policy ) {
			// Allow mode: only permit if --sudo flag is present.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- CLI argv is a string array; no user input.
			$argv     = $_SERVER['argv'] ?? array();
			$has_flag = in_array( '--sudo', (array) $argv, true );

			if ( $has_flag ) {
				/**
				 * Fires when a gated action is allowed by policy on a non-interactive surface.
				 *
				 * @since 2.0.0
				 *
				 * @param int    $user_id Always 0 for CLI/Cron.
				 * @param string $rule_id Empty string — CLI blocks all gated ops generically.
				 * @param string $surface The surface: 'cli'.
				 */
				do_action( 'wp_sudo_action_allowed', 0, '', 'cli' );
				return;
			}
		}

		// Block: register a pre-command hook to deny gated actions.
		add_action(
			'admin_init',
			function () {
				$matched = $this->match_request( 'admin' );
				if ( $matched ) {
					/**
					 * Fires when a gated action is blocked by policy.
					 *
					 * @since 2.0.0
					 *
					 * @param int    $user_id Always 0 for CLI.
					 * @param string $rule_id The rule ID that matched.
					 * @param string $surface Always 'cli'.
					 */
					do_action( 'wp_sudo_action_blocked', 0, $matched['id'], 'cli' );
					wp_die(
						esc_html(
							sprintf(
								/* translators: %s: action label */
								__( 'This operation (%s) requires sudo. Use the admin UI or pass --sudo.', 'wp-sudo' ),
								$matched['label'] ?? $matched['id']
							)
						),
						'',
						array( 'response' => 403 )
					);
				}
			},
			0
		);
	}

	/**
	 * Gate Cron operations.
	 *
	 * Policy "block" (default): silently deny gated operations.
	 * Policy "allow": let them through.
	 *
	 * @return void
	 */
	public function gate_cron(): void {
		$policy = $this->get_policy( self::SETTING_CRON_POLICY );

		if ( self::POLICY_ALLOW === $policy ) {
			/**
			 * Fires when gated actions are allowed by policy on the cron surface.
			 *
			 * @since 2.0.0
			 *
			 * @param int    $user_id Always 0 for Cron.
			 * @param string $rule_id Empty — cron allows all.
			 * @param string $surface Always 'cron'.
			 */
			do_action( 'wp_sudo_action_allowed', 0, '', 'cron' );
			return;
		}

		// Block: silently prevent gated operations.
		add_action(
			'admin_init',
			function () {
				$matched = $this->match_request( 'admin' );
				if ( $matched ) {
					/** This action is documented in includes/class-gate.php */
					do_action( 'wp_sudo_action_blocked', 0, $matched['id'], 'cron' );
					// Silently exit — cron jobs shouldn't produce visible errors.
					exit;
				}
			},
			0
		);
	}

	/**
	 * Gate XML-RPC operations.
	 *
	 * Policy "block" (default): return XML-RPC error for gated operations.
	 * Policy "allow": let them through.
	 *
	 * @return void
	 */
	public function gate_xmlrpc(): void {
		$policy = $this->get_policy( self::SETTING_XMLRPC_POLICY );

		if ( self::POLICY_ALLOW === $policy ) {
			/**
			 * Fires when gated actions are allowed by policy on the XML-RPC surface.
			 *
			 * @since 2.0.0
			 *
			 * @param int    $user_id Always 0 for XML-RPC (at init time).
			 * @param string $rule_id Empty — xmlrpc allows all.
			 * @param string $surface Always 'xmlrpc'.
			 */
			do_action( 'wp_sudo_action_allowed', 0, '', 'xmlrpc' );
			return;
		}

		// Block: intercept at admin_init and return error.
		add_action(
			'admin_init',
			function () {
				$matched = $this->match_request( 'admin' );
				if ( $matched ) {
					/** This action is documented in includes/class-gate.php */
					do_action( 'wp_sudo_action_blocked', 0, $matched['id'], 'xmlrpc' );
					wp_die(
						esc_html(
							sprintf(
								/* translators: %s: action label */
								__( 'This operation (%s) requires sudo and cannot be performed via XML-RPC.', 'wp-sudo' ),
								$matched['label'] ?? $matched['id']
							)
						),
						'',
						array( 'response' => 403 )
					);
				}
			},
			0
		);
	}

	/**
	 * Get a policy setting value.
	 *
	 * @param string $key The policy setting key.
	 * @return string The policy value ('block' or 'allow').
	 */
	public function get_policy( string $key ): string {
		$policy = Admin::get( $key, self::POLICY_BLOCK );

		// Ensure valid value.
		if ( self::POLICY_ALLOW !== $policy ) {
			return self::POLICY_BLOCK;
		}

		return self::POLICY_ALLOW;
	}

	/**
	 * Main interception entry point at admin_init priority 1.
	 *
	 * Determines the surface (admin UI vs AJAX) and routes accordingly.
	 *
	 * @return void
	 */
	public function intercept(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		// Determine current surface.
		$surface = $this->detect_surface();

		if ( 'admin' !== $surface && 'ajax' !== $surface ) {
			return;
		}

		// Match the current request against the action registry.
		$matched_rule = $this->match_request( $surface );

		if ( ! $matched_rule ) {
			return;
		}

		// If a sudo session is active, let the request through.
		if ( Sudo_Session::is_active( $user_id ) ) {
			return;
		}

		/**
		 * Fires when a gated action is intercepted.
		 *
		 * @since 2.0.0
		 *
		 * @param int    $user_id The user who triggered the action.
		 * @param string $rule_id The rule ID that matched.
		 * @param string $surface The surface: 'admin' or 'ajax'.
		 */
		do_action( 'wp_sudo_action_gated', $user_id, $matched_rule['id'], $surface );

		if ( 'ajax' === $surface ) {
			$this->block_ajax( $matched_rule );
			return;
		}

		// Admin UI: stash-challenge-replay.
		$this->challenge_admin( $user_id, $matched_rule );
	}

	/**
	 * Detect which surface the current request is on.
	 *
	 * @return string One of: 'admin', 'ajax', 'rest', 'cli', 'cron', 'xmlrpc', 'unknown'.
	 */
	public function detect_surface(): string {
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}

		if ( wp_doing_cron() ) {
			return 'cron';
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		if ( is_admin() ) {
			return 'admin';
		}

		return 'unknown';
	}

	/**
	 * Match the current request against the action registry for a given surface.
	 *
	 * @param string                $surface The surface to match against ('admin', 'ajax', or 'rest').
	 * @param \WP_REST_Request|null $request REST request object (required for 'rest' surface).
	 * @return array<string, mixed>|null The matched rule, or null.
	 */
	public function match_request( string $surface, ?\WP_REST_Request $request = null ): ?array {
		$rules = Action_Registry::get_rules();

		// Hoist sanitization of request params above the loop so each
		// rule iteration reuses the same sanitized values instead of
		// calling sanitize_text_field() up to 28 times per request.
		$request_action = '';
		$request_method = '';

		if ( 'admin' === $surface || 'ajax' === $surface ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Gate routing, not data processing.
			$request_action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
		}

		if ( 'admin' === $surface ) {
			$request_method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
		}

		foreach ( $rules as $rule ) {
			if ( 'admin' === $surface && $this->matches_admin( $rule, $request_action, $request_method ) ) {
				return $rule;
			}

			if ( 'ajax' === $surface && $this->matches_ajax( $rule, $request_action ) ) {
				return $rule;
			}

			if ( 'rest' === $surface && null !== $request && $this->matches_rest( $rule, $request ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Check if the current admin request matches a rule's admin criteria.
	 *
	 * @param array<string, mixed> $rule           A gated action rule.
	 * @param string               $request_action Pre-sanitized $_REQUEST['action'] value.
	 * @param string               $request_method Pre-sanitized $_SERVER['REQUEST_METHOD'] value.
	 * @return bool
	 */
	private function matches_admin( array $rule, string $request_action, string $request_method ): bool {
		if ( empty( $rule['admin'] ) ) {
			return false;
		}

		$admin = $rule['admin'];
		global $pagenow;

		// Match pagenow.
		$pagenow_list = (array) ( $admin['pagenow'] ?? array() );
		if ( ! in_array( $pagenow, $pagenow_list, true ) ) {
			return false;
		}

		// Match action parameter.
		$actions = (array) ( $admin['actions'] ?? array() );

		if ( ! in_array( $request_action, $actions, true ) ) {
			return false;
		}

		// Match HTTP method.
		$method = $admin['method'] ?? 'ANY';
		if ( 'ANY' !== $method && $request_method !== $method ) {
			return false;
		}

		// Optional callback for extra conditions.
		if ( isset( $admin['callback'] ) && is_callable( $admin['callback'] ) ) {
			if ( ! call_user_func( $admin['callback'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if the current AJAX request matches a rule's ajax criteria.
	 *
	 * @param array<string, mixed> $rule           A gated action rule.
	 * @param string               $request_action Pre-sanitized $_REQUEST['action'] value.
	 * @return bool
	 */
	private function matches_ajax( array $rule, string $request_action ): bool {
		if ( empty( $rule['ajax'] ) ) {
			return false;
		}

		$ajax = $rule['ajax'];

		$actions = (array) ( $ajax['actions'] ?? array() );

		return in_array( $request_action, $actions, true );
	}

	/**
	 * REST API interception via rest_request_before_callbacks filter.
	 *
	 * Returns a WP_Error to short-circuit the request when a gated
	 * action is attempted without an active sudo session.
	 *
	 * Cookie-auth (browser) requests get a soft block (sudo_required).
	 * An admin notice on the next page load links to the challenge page.
	 *
	 * @param mixed            $response Response to replace the requested response.
	 * @param array            $handler  Route handler info.
	 * @param \WP_REST_Request $request  REST request object.
	 * @return mixed|\WP_Error Original response or WP_Error to block.
	 */
	public function intercept_rest( $response, $handler, \WP_REST_Request $request ) {
		// If already an error, don't override.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return $response;
		}

		$matched_rule = $this->match_request( 'rest', $request );

		if ( ! $matched_rule ) {
			return $response;
		}

		if ( Sudo_Session::is_active( $user_id ) ) {
			return $response;
		}

		// Distinguish cookie-auth (browser) from app-password/bearer (headless).
		// Cookie-auth REST requests pass nonce in X-WP-Nonce header.
		$nonce          = $request->get_header( 'X-WP-Nonce' );
		$is_cookie_auth = $nonce && wp_verify_nonce( $nonce, 'wp_rest' );

		if ( ! $is_cookie_auth ) {
			// Non-browser auth (app-password, bearer, etc.) — check policy.
			$policy = $this->get_policy( self::SETTING_REST_APP_PASS_POLICY );

			if ( self::POLICY_ALLOW === $policy ) {
				/** This action is documented in includes/class-gate.php */
				do_action( 'wp_sudo_action_allowed', $user_id, $matched_rule['id'], 'rest_app_password' );
				return $response;
			}

			/** This action is documented in includes/class-gate.php */
			do_action( 'wp_sudo_action_blocked', $user_id, $matched_rule['id'], 'rest_app_password' );
			return new \WP_Error(
				'sudo_blocked',
				__( 'This operation requires sudo and cannot be performed via Application Passwords.', 'wp-sudo' ),
				array( 'status' => 403 )
			);
		}

		// Cookie-auth browser request — return sudo_required error and set admin notice.

		/**
		 * Fires when a gated action is intercepted on the REST surface.
		 *
		 * @since 2.0.0
		 *
		 * @param int    $user_id The user who triggered the action.
		 * @param string $rule_id The rule ID that matched.
		 * @param string $surface Always 'rest'.
		 */
		do_action( 'wp_sudo_action_gated', $user_id, $matched_rule['id'], 'rest' );

		return $this->block_rest( $matched_rule );
	}

	/**
	 * Check if a REST request matches a rule's rest criteria.
	 *
	 * @param array<string, mixed> $rule    A gated action rule.
	 * @param \WP_REST_Request     $request REST request object.
	 * @return bool
	 */
	private function matches_rest( array $rule, \WP_REST_Request $request ): bool {
		if ( empty( $rule['rest'] ) ) {
			return false;
		}

		$rest = $rule['rest'];

		// Match route pattern (regex).
		$route = $request->get_route();
		if ( ! preg_match( $rest['route'], $route ) ) {
			return false;
		}

		// Match HTTP method.
		$methods = (array) ( $rest['methods'] ?? array() );
		if ( ! in_array( $request->get_method(), $methods, true ) ) {
			return false;
		}

		// Optional callback for extra conditions.
		if ( isset( $rest['callback'] ) && is_callable( $rest['callback'] ) ) {
			if ( ! call_user_func( $rest['callback'], $request ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return a WP_Error to block a REST request that requires sudo.
	 *
	 * Also sets a short-lived transient for the admin notice fallback.
	 *
	 * @param array<string, mixed> $matched_rule The rule that matched.
	 * @return \WP_Error
	 */
	private function block_rest( array $matched_rule ): \WP_Error {
		$this->set_blocked_transient( $matched_rule );

		return new \WP_Error(
			'sudo_required',
			sprintf(
				/* translators: %s: action label (e.g. "Delete plugin") */
				__( 'This action (%s) requires reauthentication. Please confirm your identity.', 'wp-sudo' ),
				$matched_rule['label'] ?? $matched_rule['id']
			),
			array(
				'status'  => 403,
				'rule_id' => $matched_rule['id'],
			)
		);
	}

	/**
	 * Admin UI interception: stash the request and redirect to challenge page.
	 *
	 * @param int                  $user_id      Current user ID.
	 * @param array<string, mixed> $matched_rule The rule that matched.
	 * @return void
	 */
	private function challenge_admin( int $user_id, array $matched_rule ): void {
		$stash_key = $this->stash->save( $user_id, $matched_rule );

		// Use the correct admin URL for the current context.
		$base_url = is_network_admin()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );

		$challenge_url = add_query_arg(
			array(
				'page'      => 'wp-sudo-challenge',
				'stash_key' => $stash_key,
			),
			$base_url
		);

		wp_safe_redirect( $challenge_url );
		exit;
	}

	/**
	 * AJAX interception: return a JSON error.
	 *
	 * Also sets a short-lived transient so the admin notice fallback
	 * can alert the user on the next page load with a link to the
	 * challenge page for session activation.
	 *
	 * @param array<string, mixed> $matched_rule The rule that matched.
	 * @return void
	 */
	private function block_ajax( array $matched_rule ): void {
		$this->set_blocked_transient( $matched_rule );

		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- UI hint only.
		$is_mac = isset( $_SERVER['HTTP_USER_AGENT'] )
			&& false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 'Mac' );
		// phpcs:enable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__

		$shortcut = $is_mac ? 'Cmd+Shift+S' : 'Ctrl+Shift+S';

		$message = sprintf(
			/* translators: 1: action label, 2: keyboard shortcut */
			__( 'This action (%1$s) requires reauthentication. Press %2$s or reload the page to confirm your identity.', 'wp-sudo' ),
			$matched_rule['label'] ?? $matched_rule['id'],
			$shortcut
		);

		/*
		 * Build a response compatible with WordPress core's wp.updates JS.
		 *
		 * 1. HTTP 200 with success=false — so wp.ajax.send() parses the
		 *    response through .done() → rejectWith( this, [response.data] ).
		 *    A non-200 status causes jQuery to route through .fail(), which
		 *    passes the raw jqXHR object and bypasses JSON parsing entirely.
		 *
		 * 2. Include slug/plugin from $_POST — wp.updates error handlers
		 *    (installThemeError, updatePluginError, etc.) use response.slug
		 *    to locate the DOM element and reset the button/spinner state.
		 *    Without slug, the handler can't find the button and the spinner
		 *    spins forever.
		 *
		 * 3. errorMessage is plain text, not HTML. wp.updates appends it
		 *    inside theme/plugin cards whose click handlers intercept anchor
		 *    clicks and navigate to the preview screen. The admin notice on
		 *    page reload (via set_blocked_transient) already provides a
		 *    clickable link to the challenge page.
		 */
		$data = array(
			'code'         => 'sudo_required',
			'message'      => $message,
			'rule_id'      => $matched_rule['id'],
			'errorCode'    => 'sudo_required',
			'errorMessage' => $message,
		);

		// Pass through slug/plugin so wp.updates can locate the DOM element.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Read-only; nonce checked by wp.updates before dispatch.
		if ( ! empty( $_POST['slug'] ) ) {
			$data['slug'] = sanitize_key( wp_unslash( $_POST['slug'] ) );
		}
		if ( ! empty( $_POST['plugin'] ) ) {
			$data['plugin'] = sanitize_text_field( wp_unslash( $_POST['plugin'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_send_json_error( $data );
	}

	/**
	 * Store a short-lived transient so the admin notice fallback can
	 * alert the user on the next page load.
	 *
	 * @param array<string, mixed> $matched_rule The rule that was blocked.
	 * @return void
	 */
	private function set_blocked_transient( array $matched_rule ): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		set_transient(
			self::BLOCKED_TRANSIENT_PREFIX . $user_id,
			array(
				'rule_id' => $matched_rule['id'],
				'label'   => $matched_rule['label'] ?? $matched_rule['id'],
			),
			60 // 1 minute — enough for the next page load.
		);
	}

	/**
	 * Render a fallback admin notice when a gated AJAX/REST request was blocked.
	 *
	 * When the Gate blocks an AJAX or REST request with `sudo_required`,
	 * it sets a short-lived transient. On the next admin page load, this
	 * notice tells the user how to activate a sudo session manually via
	 * the challenge page or the keyboard shortcut.
	 *
	 * @return void
	 */
	public function render_blocked_notice(): void {
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

		$current_url = '';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$scheme = is_ssl() ? 'https' : 'http';
			$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? 'localhost' ) );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() on the full URL below.
			$uri         = wp_unslash( $_SERVER['REQUEST_URI'] );
			$current_url = esc_url_raw( $scheme . '://' . $host . $uri );
		}

		$query_args = array( 'page' => 'wp-sudo-challenge' );
		if ( $current_url ) {
			// add_query_arg() already encodes the URL, so no need for rawurlencode().
			$query_args['return_url'] = $current_url;
		}

		$challenge_url = add_query_arg(
			$query_args,
			is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
		);

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			sprintf(
				/* translators: 1: action label, 2: challenge page link, 3: keyboard shortcut */
				esc_html__( 'Your recent action (%1$s) was blocked because it requires reauthentication. %2$s to activate a sudo session, then try again. You can also press %3$s.', 'wp-sudo' ),
				'<strong>' . esc_html( $label ) . '</strong>',
				'<a href="' . esc_url( $challenge_url ) . '">' . esc_html__( 'Confirm your identity', 'wp-sudo' ) . '</a>',
				'<kbd>' . esc_html( $shortcut ) . '</kbd>'
			)
		);
	}

	/**
	 * Render a persistent gate notice on gated admin pages.
	 *
	 * Unlike render_blocked_notice() (transient-based, one-time), this notice
	 * appears every time the user loads a gated page without an active sudo
	 * session. It replaces the need to click a button and fail first.
	 *
	 * @return void
	 */
	public function render_gate_notice(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		if ( Sudo_Session::is_active( $user_id ) ) {
			return;
		}

		// Only show on gated pages.
		$gated_pages = array(
			'themes.php',
			'theme-install.php',
			'plugins.php',
			'plugin-install.php',
		);

		global $pagenow;

		if ( ! in_array( $pagenow, $gated_pages, true ) ) {
			return;
		}

		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- UI hint only.
		$is_mac = isset( $_SERVER['HTTP_USER_AGENT'] )
			&& false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 'Mac' );
		// phpcs:enable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__

		$shortcut = $is_mac ? 'Cmd+Shift+S' : 'Ctrl+Shift+S';

		$current_url = '';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$scheme = is_ssl() ? 'https' : 'http';
			$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? 'localhost' ) );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() on the full URL below.
			$uri         = wp_unslash( $_SERVER['REQUEST_URI'] );
			$current_url = esc_url_raw( $scheme . '://' . $host . $uri );
		}

		$query_args = array( 'page' => 'wp-sudo-challenge' );
		if ( $current_url ) {
			// add_query_arg() already encodes the URL, so no need for rawurlencode().
			$query_args['return_url'] = $current_url;
		}

		$challenge_url = add_query_arg(
			$query_args,
			is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
		);

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			sprintf(
				/* translators: 1: challenge page link, 2: keyboard shortcut */
				esc_html__( 'Installing, activating, updating, and deleting themes and plugins requires an active sudo session. %1$s or press %2$s to start one.', 'wp-sudo' ),
				'<a href="' . esc_url( $challenge_url ) . '">' . esc_html__( 'Confirm your identity', 'wp-sudo' ) . '</a>',
				'<kbd>' . esc_html( $shortcut ) . '</kbd>'
			)
		);
	}

	/**
	 * Filter plugin action links to disable gated actions.
	 *
	 * Replaces Activate, Deactivate, and Delete links with disabled
	 * span elements when no sudo session is active. Only runs on
	 * the plugins.php list table.
	 *
	 * @param string[] $actions     Action links for the plugin row.
	 * @param string   $plugin_file Plugin file path (unused; required by filter).
	 * @return string[]
	 */
	public function filter_plugin_action_links( array $actions, string $plugin_file ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return $actions;
		}

		if ( Sudo_Session::is_active( $user_id ) ) {
			return $actions;
		}

		$gated_keys = array( 'activate', 'deactivate', 'delete' );

		foreach ( $gated_keys as $key ) {
			if ( isset( $actions[ $key ] ) ) {
				/*
				 * Extract the visible link text and replace the anchor with a
				 * disabled span. wp_strip_all_tags() safely removes the <a> wrapper.
				 */
				$text            = wp_strip_all_tags( $actions[ $key ] );
				$actions[ $key ] = '<span class="wp-sudo-disabled" aria-disabled="true" style="color:#a7aaad;cursor:default">'
					. esc_html( $text )
					. '</span>';
			}
		}

		return $actions;
	}

	/**
	 * Filter theme action links to disable gated actions.
	 *
	 * Covers the old themes list-table (non-JS fallback) on themes.php.
	 *
	 * @param string[] $actions Action links for the theme row.
	 * @param object   $theme   WP_Theme instance (unused; required by filter).
	 * @return string[]
	 */
	public function filter_theme_action_links( array $actions, $theme ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return $actions;
		}

		if ( Sudo_Session::is_active( $user_id ) ) {
			return $actions;
		}

		$gated_keys = array( 'activate', 'delete' );

		foreach ( $gated_keys as $key ) {
			if ( isset( $actions[ $key ] ) ) {
				$text            = wp_strip_all_tags( $actions[ $key ] );
				$actions[ $key ] = '<span class="wp-sudo-disabled" aria-disabled="true" style="color:#a7aaad;cursor:default">'
					. esc_html( $text )
					. '</span>';
			}
		}

		return $actions;
	}
}
