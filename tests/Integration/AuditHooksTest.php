<?php
/**
 * Integration tests for audit hook argument verification.
 *
 * Verifies that all do_action() hooks fire with the correct argument
 * values — user_id, timestamps, counts, role/capability names.
 *
 * REST gating hooks (wp_sudo_action_gated, wp_sudo_action_blocked) are
 * already argument-verified in RestGatingTest — no duplication here.
 *
 * @covers \WP_Sudo\Sudo_Session
 * @covers \WP_Sudo\Plugin::enforce_editor_unfiltered_html
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Admin;
use WP_Sudo\Sudo_Session;

class AuditHooksTest extends TestCase {

	/**
	 * Restore editor's unfiltered_html cap if a test stripped it.
	 */
	public function tear_down(): void {
		$editor = get_role( 'editor' );
		if ( $editor && empty( $editor->capabilities['unfiltered_html'] ) ) {
			$editor->add_cap( 'unfiltered_html' );
		}

		parent::tear_down();
	}

	/**
	 * SURF-04: wp_sudo_activated fires with (user_id, expires_timestamp, duration_minutes).
	 */
	public function test_activated_hook_receives_correct_arguments(): void {
		$user = $this->make_admin();

		$captured = array();
		add_action(
			'wp_sudo_activated',
			static function ( $uid, $expires, $duration ) use ( &$captured ) {
				$captured = array( $uid, $expires, $duration );
			},
			10,
			3
		);

		$before = time();
		Sudo_Session::activate( $user->ID );
		$after = time();

		$this->assertCount( 3, $captured, 'Hook should fire with 3 arguments.' );
		$this->assertSame( $user->ID, $captured[0], 'First arg should be user_id.' );

		// Expires should be approximately now + duration.
		$duration = (int) Admin::get( 'session_duration', 15 );
		$expected_min = $before + ( $duration * MINUTE_IN_SECONDS );
		$expected_max = $after + ( $duration * MINUTE_IN_SECONDS );
		$this->assertGreaterThanOrEqual( $expected_min, $captured[1] );
		$this->assertLessThanOrEqual( $expected_max, $captured[1] );

		$this->assertSame( $duration, $captured[2], 'Third arg should be duration in minutes.' );
	}

	/**
	 * SURF-04: wp_sudo_deactivated fires with (user_id).
	 */
	public function test_deactivated_hook_receives_user_id(): void {
		$user = $this->make_admin();
		Sudo_Session::activate( $user->ID );

		$captured_uid = null;
		add_action(
			'wp_sudo_deactivated',
			static function ( $uid ) use ( &$captured_uid ) {
				$captured_uid = $uid;
			}
		);

		Sudo_Session::deactivate( $user->ID );

		$this->assertSame( $user->ID, $captured_uid );
	}

	/**
	 * SURF-04: wp_sudo_reauth_failed fires with (user_id, incrementing_attempt_count).
	 *
	 * Two failed attempts → count goes 1 → 2.
	 */
	public function test_reauth_failed_hook_receives_user_id_and_attempt_count(): void {
		$user = $this->make_admin( 'correct-password' );
		wp_set_current_user( $user->ID );

		$captured_calls = array();
		add_action(
			'wp_sudo_reauth_failed',
			static function ( $uid, $attempts ) use ( &$captured_calls ) {
				$captured_calls[] = array( $uid, $attempts );
			},
			10,
			2
		);

		// Attempt 1: wrong password.
		Sudo_Session::attempt_activation( $user->ID, 'wrong-password' );
		// Attempt 2: wrong password again.
		Sudo_Session::attempt_activation( $user->ID, 'wrong-password' );

		$this->assertCount( 2, $captured_calls, 'Hook should fire twice.' );

		$this->assertSame( $user->ID, $captured_calls[0][0] );
		$this->assertSame( 1, $captured_calls[0][1], 'First attempt count should be 1.' );

		$this->assertSame( $user->ID, $captured_calls[1][0] );
		$this->assertSame( 2, $captured_calls[1][1], 'Second attempt count should be 2.' );
	}

	/**
	 * SURF-04: wp_sudo_lockout fires with (user_id, attempt_count=5) when
	 * the lockout threshold is reached.
	 *
	 * Pre-sets meta to 4 failed attempts, then one more triggers lockout.
	 * The progressive delay at attempt 5 is sleep(5), so this test is slow.
	 *
	 * @group slow
	 */
	public function test_lockout_hook_receives_user_id_and_count(): void {
		$user = $this->make_admin( 'correct-password' );
		wp_set_current_user( $user->ID );

		// Pre-set failed attempts to 4 (just below lockout threshold).
		update_user_meta( $user->ID, Sudo_Session::LOCKOUT_META_KEY, 4 );

		$captured = array();
		add_action(
			'wp_sudo_lockout',
			static function ( $uid, $attempts ) use ( &$captured ) {
				$captured = array( $uid, $attempts );
			},
			10,
			2
		);

		// Attempt 5: triggers lockout (and sleep(5) from progressive delay).
		Sudo_Session::attempt_activation( $user->ID, 'wrong-password' );

		$this->assertSame( $user->ID, $captured[0] ?? null, 'user_id should match.' );
		$this->assertSame( 5, $captured[1] ?? null, 'Attempt count should be 5.' );
	}

	/**
	 * SURF-04: wp_sudo_capability_tampered fires with ('editor', 'unfiltered_html').
	 *
	 * Skipped on multisite where enforce_editor_unfiltered_html() is a no-op.
	 */
	public function test_capability_tampered_hook_fires_with_role_and_cap(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'enforce_editor_unfiltered_html() is a no-op on multisite.' );
		}

		// Add unfiltered_html to editor (simulating DB tampering).
		$editor = get_role( 'editor' );
		$this->assertNotNull( $editor );
		$editor->add_cap( 'unfiltered_html' );

		$captured = array();
		add_action(
			'wp_sudo_capability_tampered',
			static function ( $role, $cap ) use ( &$captured ) {
				$captured = array( $role, $cap );
			},
			10,
			2
		);

		// Call the enforcement method.
		wp_sudo()->enforce_editor_unfiltered_html();

		$this->assertSame( 'editor', $captured[0] ?? null );
		$this->assertSame( 'unfiltered_html', $captured[1] ?? null );

		// Verify the capability was actually stripped.
		$editor = get_role( 'editor' );
		$this->assertEmpty(
			$editor->capabilities['unfiltered_html'] ?? false,
			'unfiltered_html should be stripped after enforcement.'
		);
	}
}
