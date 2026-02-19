<?php
/**
 * Integration tests for rate limiting — real user meta in the database.
 *
 * Tests 1–3 use only attempts 1–3 (zero sleep() penalty).
 * Tests 4–6 directly set lockout meta to simulate state without
 * triggering record_failed_attempt().
 *
 * The wp_sudo_lockout hook test with real attempt 5 (5s sleep) lives
 * in AuditHooksTest (@group slow) — one test only.
 *
 * @covers \WP_Sudo\Sudo_Session
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Sudo_Session;

class RateLimitingTest extends TestCase {

	/**
	 * SURF-05: Failed attempts increment user meta 1 → 2 → 3.
	 *
	 * Three wrong-password calls via attempt_activation(). Each increments
	 * the _wp_sudo_failed_attempts user meta by 1. No sleep penalty for
	 * attempts 1–3 (PROGRESSIVE_DELAYS only kicks in at 4).
	 */
	public function test_failed_attempts_increment_user_meta(): void {
		$user = $this->make_admin( 'correct-password' );
		wp_set_current_user( $user->ID );

		Sudo_Session::attempt_activation( $user->ID, 'wrong' );
		$this->assertSame(
			'1',
			get_user_meta( $user->ID, Sudo_Session::LOCKOUT_META_KEY, true ),
			'After 1 failed attempt, meta should be 1.'
		);

		Sudo_Session::attempt_activation( $user->ID, 'wrong' );
		$this->assertSame(
			'2',
			get_user_meta( $user->ID, Sudo_Session::LOCKOUT_META_KEY, true ),
			'After 2 failed attempts, meta should be 2.'
		);

		Sudo_Session::attempt_activation( $user->ID, 'wrong' );
		$this->assertSame(
			'3',
			get_user_meta( $user->ID, Sudo_Session::LOCKOUT_META_KEY, true ),
			'After 3 failed attempts, meta should be 3.'
		);
	}

	/**
	 * SURF-05: attempt_activation() with wrong password returns 'invalid_password'.
	 */
	public function test_failed_attempt_returns_invalid_password(): void {
		$user = $this->make_admin( 'correct-password' );
		wp_set_current_user( $user->ID );

		$result = Sudo_Session::attempt_activation( $user->ID, 'wrong-password' );

		$this->assertSame( 'invalid_password', $result['code'] );
	}

	/**
	 * SURF-05: Successful activation resets failed attempt counter.
	 *
	 * 2 failed attempts → 1 success → meta cleared.
	 */
	public function test_successful_activation_resets_failed_attempts(): void {
		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		// 2 failed attempts.
		Sudo_Session::attempt_activation( $user->ID, 'wrong' );
		Sudo_Session::attempt_activation( $user->ID, 'wrong' );
		$this->assertSame(
			'2',
			get_user_meta( $user->ID, Sudo_Session::LOCKOUT_META_KEY, true )
		);

		// Successful activation.
		$result = Sudo_Session::attempt_activation( $user->ID, $password );
		$this->assertSame( 'success', $result['code'] );

		// Counter should be reset.
		$this->assertEmpty(
			get_user_meta( $user->ID, Sudo_Session::LOCKOUT_META_KEY, true ),
			'Failed attempts meta should be cleared after successful activation.'
		);
	}

	/**
	 * SURF-05: is_locked_out() returns true when lockout meta is set to the future.
	 *
	 * Directly sets _wp_sudo_lockout_until meta to simulate lockout state
	 * without triggering sleep() in record_failed_attempt().
	 */
	public function test_is_locked_out_with_simulated_lockout_meta(): void {
		$user = $this->make_admin();

		// Simulate an active lockout — 5 minutes in the future.
		update_user_meta(
			$user->ID,
			Sudo_Session::LOCKOUT_UNTIL_META_KEY,
			time() + Sudo_Session::LOCKOUT_DURATION
		);

		$this->assertTrue(
			Sudo_Session::is_locked_out( $user->ID ),
			'is_locked_out() should return true with future lockout timestamp.'
		);
	}

	/**
	 * SURF-05: Expired lockout returns false and cleans up meta.
	 *
	 * Sets lockout timestamp in the past. is_locked_out() should return false
	 * and reset the failed attempt counters.
	 */
	public function test_expired_lockout_returns_false_and_resets(): void {
		$user = $this->make_admin();

		// Simulate an expired lockout (1 minute in the past).
		update_user_meta( $user->ID, Sudo_Session::LOCKOUT_UNTIL_META_KEY, time() - 60 );
		update_user_meta( $user->ID, Sudo_Session::LOCKOUT_META_KEY, 5 );

		$this->assertFalse(
			Sudo_Session::is_locked_out( $user->ID ),
			'is_locked_out() should return false for expired lockout.'
		);

		// Meta should be cleaned up.
		$this->assertEmpty(
			get_user_meta( $user->ID, Sudo_Session::LOCKOUT_META_KEY, true ),
			'Failed attempts meta should be reset after expired lockout.'
		);
		$this->assertEmpty(
			get_user_meta( $user->ID, Sudo_Session::LOCKOUT_UNTIL_META_KEY, true ),
			'Lockout-until meta should be reset after expired lockout.'
		);
	}

	/**
	 * SURF-05: attempt_activation() returns 'locked_out' with remaining seconds
	 * when user is in lockout state.
	 */
	public function test_attempt_activation_returns_locked_out(): void {
		$user = $this->make_admin( 'correct-password' );
		wp_set_current_user( $user->ID );

		// Simulate active lockout (5 minutes in the future).
		$lockout_until = time() + Sudo_Session::LOCKOUT_DURATION;
		update_user_meta( $user->ID, Sudo_Session::LOCKOUT_UNTIL_META_KEY, $lockout_until );

		$result = Sudo_Session::attempt_activation( $user->ID, 'correct-password' );

		$this->assertSame( 'locked_out', $result['code'] );
		$this->assertArrayHasKey( 'remaining', $result );
		$this->assertGreaterThan( 0, $result['remaining'], 'Remaining seconds should be positive.' );
		$this->assertLessThanOrEqual(
			Sudo_Session::LOCKOUT_DURATION,
			$result['remaining'],
			'Remaining should not exceed lockout duration.'
		);
	}
}
