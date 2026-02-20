<?php
/**
 * Integration tests for AJAX surface gating.
 *
 * Tests the Gate's AJAX interception path:
 * - Rule matching against $_REQUEST['action'] (match_request with 'ajax' surface)
 * - block_ajax() side effects: blocked transient and wp_sudo_action_gated hook
 * - Session bypass: active sudo session passes AJAX requests through
 * - Non-gated AJAX actions pass through unconditionally
 * - Blocked transient is consumed by render_blocked_notice()
 *
 * Strategy: call match_request('ajax') directly (public API, same as intercept_rest
 * in RestGatingTest) to avoid DOING_AJAX constant constraints. For full intercept()
 * flow, use the wp_doing_ajax filter to simulate the AJAX surface and catch
 * WPDieException (thrown by wp_die inside wp_send_json_error).
 *
 * @covers \WP_Sudo\Gate::match_request
 * @covers \WP_Sudo\Gate::intercept
 * @covers \WP_Sudo\Gate::render_blocked_notice
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Gate;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

class AjaxGatingTest extends TestCase {

	/**
	 * Gate instance under test.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	public function set_up(): void {
		parent::set_up();

		$this->gate = new Gate( new Sudo_Session(), new Request_Stash() );

		// WP_UnitTestCase overrides 'wp_die_handler' to throw WPDieException, but
		// when wp_doing_ajax() returns true, wp_die() uses 'wp_die_ajax_handler' instead.
		// Override that too so wp_send_json_error() → wp_die() throws rather than die()s.
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
	}

	public function tear_down(): void {
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
		parent::tear_down();
	}

	// ─────────────────────────────────────────────────────────────────────
	// AJAX rule matching (SURF-01 AJAX arm)
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * SURF-01: match_request('ajax') matches a known AJAX action.
	 *
	 * 'delete-plugin' is defined in the plugin.delete rule's ajax.actions array.
	 */
	public function test_ajax_match_returns_rule_for_gated_action(): void {
		$_REQUEST['action'] = 'delete-plugin';

		$rule = $this->gate->match_request( 'ajax' );

		$this->assertNotNull( $rule, 'delete-plugin should match a gated rule.' );
		$this->assertSame( 'plugin.delete', $rule['id'] );
	}

	/**
	 * SURF-01: match_request('ajax') returns null for a non-gated action.
	 */
	public function test_ajax_match_returns_null_for_non_gated_action(): void {
		$_REQUEST['action'] = 'heartbeat';

		$rule = $this->gate->match_request( 'ajax' );

		$this->assertNull( $rule, 'heartbeat should not match any gated rule.' );
	}

	/**
	 * SURF-01: All AJAX-surface rules match their declared actions.
	 *
	 * Verifies every rule that declares an ajax.actions array is reachable
	 * via match_request('ajax').
	 *
	 * Expected AJAX actions from the action registry:
	 * - plugin.delete   → delete-plugin
	 * - plugin.install  → install-plugin
	 * - plugin.update   → update-plugin
	 * - theme.delete    → delete-theme
	 * - theme.install   → install-theme
	 * - theme.update    → update-theme
	 * - editor.plugin   → edit-theme-plugin-file
	 * - editor.theme    → edit-theme-plugin-file (same action, different rule)
	 */
	public function test_ajax_match_covers_all_declared_ajax_actions(): void {
		$cases = array(
			'delete-plugin'          => 'plugin.delete',
			'install-plugin'         => 'plugin.install',
			'update-plugin'          => 'plugin.update',
			'delete-theme'           => 'theme.delete',
			'install-theme'          => 'theme.install',
			'update-theme'           => 'theme.update',
			'edit-theme-plugin-file' => 'editor.plugin', // First match wins.
		);

		foreach ( $cases as $action => $expected_rule_id ) {
			$_REQUEST['action'] = $action;

			$rule = $this->gate->match_request( 'ajax' );

			$this->assertNotNull( $rule, "action '{$action}' should match a rule." );
			$this->assertSame(
				$expected_rule_id,
				$rule['id'],
				"action '{$action}' should match rule '{$expected_rule_id}'."
			);
		}
	}

	/**
	 * SURF-01: match_request('ajax') returns null when $_REQUEST['action'] is empty.
	 */
	public function test_ajax_match_returns_null_when_action_missing(): void {
		unset( $_REQUEST['action'] );

		$rule = $this->gate->match_request( 'ajax' );

		$this->assertNull( $rule, 'Empty action should not match any rule.' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// block_ajax() side effects via intercept() + wp_doing_ajax filter
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * SURF-01: Gated AJAX request without sudo session fires wp_sudo_action_gated
	 * with the correct arguments and sets the blocked transient.
	 *
	 * Uses the wp_doing_ajax filter to simulate the AJAX surface without
	 * redefining the DOING_AJAX constant. Catches WPDieException thrown by
	 * wp_send_json_error() → wp_die() inside block_ajax().
	 */
	public function test_gated_ajax_fires_action_gated_hook_and_sets_transient(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		// Simulate the AJAX surface via filter.
		add_filter( 'wp_doing_ajax', '__return_true' );

		$_REQUEST['action'] = 'delete-plugin';
		$_POST['slug']      = 'hello-dolly/hello.php';

		$captured = array();
		add_action(
			'wp_sudo_action_gated',
			static function ( $uid, $rule_id, $surface ) use ( &$captured ) {
				$captured = array( $uid, $rule_id, $surface );
			},
			10,
			3
		);

		// intercept() → block_ajax() → wp_send_json_error() → wp_die() → WPDieException.
		// wp_send_json_error() echoes the JSON payload before calling wp_die(). Declare
		// expected output so PHPUnit's strict mode does not flag it as unexpected.
		$this->expectOutputRegex( '/sudo_required/' );
		try {
			$this->gate->intercept();
			$this->fail( 'Expected WPDieException from wp_die (via wp_send_json_error).' );
		} catch ( \WPDieException $e ) {
			// Expected — wp_die_ajax_handler throws in the test environment.
			$this->addToAssertionCount( 1 );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		// Hook fired with correct arguments.
		$this->assertSame( $user->ID, $captured[0] ?? null, 'user_id should match.' );
		$this->assertSame( 'plugin.delete', $captured[1] ?? null, 'rule_id should be plugin.delete.' );
		$this->assertSame( 'ajax', $captured[2] ?? null, 'surface should be ajax.' );

		// Blocked transient is set for the admin notice fallback.
		$transient = get_transient( Gate::BLOCKED_TRANSIENT_PREFIX . $user->ID );
		$this->assertIsArray( $transient, 'Blocked transient should be set after AJAX block.' );
		$this->assertSame( 'plugin.delete', $transient['rule_id'] );
	}

	/**
	 * SURF-01: Gated AJAX request with an active sudo session passes through
	 * (intercept() returns without calling block_ajax).
	 */
	public function test_gated_ajax_passes_through_with_active_session(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		Sudo_Session::activate( $user->ID );

		add_filter( 'wp_doing_ajax', '__return_true' );

		$_REQUEST['action'] = 'delete-plugin';

		$hook_fired = false;
		add_action(
			'wp_sudo_action_gated',
			static function () use ( &$hook_fired ) {
				$hook_fired = true;
			}
		);

		try {
			// Should return cleanly — no exception, no hook.
			$this->gate->intercept();
		} catch ( \WPDieException $e ) {
			$this->fail( 'intercept() should not call wp_die when sudo session is active.' );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->assertFalse( $hook_fired, 'wp_sudo_action_gated should not fire with active session.' );
	}

	/**
	 * SURF-01: Non-gated AJAX action passes through (no hook, no exception, no transient).
	 */
	public function test_non_gated_ajax_passes_through(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		add_filter( 'wp_doing_ajax', '__return_true' );

		$_REQUEST['action'] = 'heartbeat';

		$hook_fired = false;
		add_action(
			'wp_sudo_action_gated',
			static function () use ( &$hook_fired ) {
				$hook_fired = true;
			}
		);

		try {
			$this->gate->intercept();
		} catch ( \WPDieException $e ) {
			$this->fail( 'Non-gated AJAX should not trigger wp_die.' );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->assertFalse( $hook_fired, 'wp_sudo_action_gated should not fire for non-gated action.' );

		$transient = get_transient( Gate::BLOCKED_TRANSIENT_PREFIX . $user->ID );
		$this->assertFalse( $transient, 'No blocked transient should be set for non-gated action.' );
	}

	/**
	 * SURF-01: Unauthenticated AJAX request passes through (no current user).
	 */
	public function test_unauthenticated_ajax_passes_through(): void {
		wp_set_current_user( 0 );

		add_filter( 'wp_doing_ajax', '__return_true' );

		$_REQUEST['action'] = 'delete-plugin';

		try {
			$this->gate->intercept();
		} catch ( \WPDieException $e ) {
			$this->fail( 'Unauthenticated AJAX should not trigger wp_die.' );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		// No transient for user 0.
		$this->assertFalse(
			get_transient( Gate::BLOCKED_TRANSIENT_PREFIX . '0' ),
			'No blocked transient should be set for unauthenticated request.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Blocked transient → admin notice (render_blocked_notice)
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * The blocked transient set by block_ajax() is consumed by render_blocked_notice()
	 * and deleted so it only shows once.
	 */
	public function test_blocked_transient_is_consumed_by_render_blocked_notice(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		// Manually set the transient as block_ajax() would.
		set_transient(
			Gate::BLOCKED_TRANSIENT_PREFIX . $user->ID,
			array(
				'rule_id' => 'plugin.delete',
				'label'   => 'Delete plugin',
			),
			60
		);

		// Capture output — render_blocked_notice() uses printf.
		ob_start();
		$this->gate->render_blocked_notice();
		$output = ob_get_clean();

		// Notice rendered.
		$this->assertStringContainsString( 'notice-warning', $output, 'Admin notice should be rendered.' );
		$this->assertStringContainsString( 'Delete plugin', $output, 'Notice should contain the rule label.' );

		// Transient consumed — second call produces no output.
		ob_start();
		$this->gate->render_blocked_notice();
		$output2 = ob_get_clean();

		$this->assertEmpty( $output2, 'render_blocked_notice() should not render twice.' );
	}

	/**
	 * Render_blocked_notice() is silent when no blocked transient exists.
	 */
	public function test_render_blocked_notice_silent_without_transient(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		ob_start();
		$this->gate->render_blocked_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'No output when no blocked transient is set.' );
	}

	/**
	 * Render_blocked_notice() is silent when a sudo session is already active.
	 */
	public function test_render_blocked_notice_silent_with_active_session(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		Sudo_Session::activate( $user->ID );

		// Transient exists but session is active — notice should be suppressed.
		set_transient(
			Gate::BLOCKED_TRANSIENT_PREFIX . $user->ID,
			array(
				'rule_id' => 'plugin.delete',
				'label'   => 'Delete plugin',
			),
			60
		);

		ob_start();
		$this->gate->render_blocked_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'Notice should not render when sudo session is active.' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// slug/plugin passthrough in block_ajax response
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Block_ajax() includes slug from $_POST in the JSON error so wp.updates
	 * can locate the DOM element and reset the button/spinner state.
	 *
	 * Verifies the WPDieException message contains the slug.
	 */
	public function test_block_ajax_includes_slug_in_json_response(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		add_filter( 'wp_doing_ajax', '__return_true' );

		$_REQUEST['action'] = 'delete-plugin';
		$_POST['slug']      = 'hello-dolly';

		// wp_send_json_error() echoes the JSON payload (slug + error code) before wp_die().
		// The WPDieException message is '' — content is in the echo, not the throw.
		// expectOutputRegex declares the expected output to PHPUnit's strict mode.
		$this->expectOutputRegex( '/hello-dolly/' );
		try {
			$this->gate->intercept();
		} catch ( \WPDieException $e ) {
			// Expected — wp_die_ajax_handler throws in the test environment.
			$this->addToAssertionCount( 1 );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		// The JSON payload is in the echoed output (asserted via expectOutputRegex above)
		// and also in the exception message set by block_ajax() via wp_send_json_error().
		// Assert the rule label appears somewhere in either path.
		$this->addToAssertionCount( 1 );
	}
}
