<?php
/**
 * Integration tests for Two Factor plugin interaction — real plugin, real DB.
 *
 * Tests verify that Sudo_Session correctly detects Two Factor configuration,
 * enters the 2FA pending state machine, and manages challenge cookies/transients.
 *
 * Self-guarding: each test skips when Two Factor plugin is not installed.
 *
 * @group two-factor
 * @covers \WP_Sudo\Sudo_Session
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Sudo_Session;

class TwoFactorTest extends TestCase {

	/**
	 * Skip if Two Factor plugin is not loaded.
	 */
	private function require_two_factor(): void {
		if ( ! class_exists( '\\Two_Factor_Core' ) ) {
			$this->markTestSkipped( 'Two Factor plugin not installed.' );
		}
	}

	/**
	 * Configure TOTP for a user so Two_Factor_Core::is_user_using_two_factor() returns true.
	 *
	 * Sets the 3 user meta keys that the Two Factor plugin checks:
	 * - _two_factor_enabled_providers: list of enabled provider class names
	 * - _two_factor_provider: primary provider class name
	 * - _two_factor_totp_key: Base32 TOTP secret (needed for is_available_for_user)
	 *
	 * @param int $user_id User ID.
	 */
	private function configure_totp_for_user( int $user_id ): void {
		update_user_meta( $user_id, '_two_factor_enabled_providers', array( 'Two_Factor_Totp' ) );
		update_user_meta( $user_id, '_two_factor_provider', 'Two_Factor_Totp' );
		update_user_meta( $user_id, '_two_factor_totp_key', 'JBSWY3DPEHPK3PXP' );
	}

	/**
	 * ADVN-01: needs_two_factor() returns false for user without 2FA providers.
	 */
	public function test_needs_two_factor_false_without_providers(): void {
		$this->require_two_factor();

		$user = $this->make_admin();

		$this->assertFalse(
			Sudo_Session::needs_two_factor( $user->ID ),
			'needs_two_factor() should return false without 2FA providers configured.'
		);
	}

	/**
	 * ADVN-01: needs_two_factor() returns true with TOTP configured.
	 */
	public function test_needs_two_factor_true_with_totp_configured(): void {
		$this->require_two_factor();

		$user = $this->make_admin();
		$this->configure_totp_for_user( $user->ID );

		$this->assertTrue(
			Sudo_Session::needs_two_factor( $user->ID ),
			'needs_two_factor() should return true with TOTP configured.'
		);
	}

	/**
	 * ADVN-01: attempt_activation() returns '2fa_pending' when password is correct
	 * and user has 2FA configured.
	 */
	public function test_attempt_activation_returns_2fa_pending(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		$result = Sudo_Session::attempt_activation( $user->ID, $password );

		$this->assertSame( '2fa_pending', $result['code'] );
		$this->assertArrayHasKey( 'expires_at', $result );
		$this->assertGreaterThan( time(), $result['expires_at'] );
	}

	/**
	 * ADVN-01: 2fa_pending path sets CHALLENGE_COOKIE in $_COOKIE superglobal.
	 */
	public function test_2fa_pending_sets_challenge_cookie(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		Sudo_Session::attempt_activation( $user->ID, $password );

		$this->assertNotEmpty(
			$_COOKIE[ Sudo_Session::CHALLENGE_COOKIE ] ?? '',
			'CHALLENGE_COOKIE should be set in $_COOKIE after 2fa_pending.'
		);
	}

	/**
	 * ADVN-01: get_2fa_pending() reads the challenge transient correctly.
	 *
	 * After attempt_activation() returns 2fa_pending, get_2fa_pending() should
	 * return the stored pending data with matching user_id and future expires_at.
	 */
	public function test_get_2fa_pending_reads_challenge_transient(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		$result = Sudo_Session::attempt_activation( $user->ID, $password );
		$this->assertSame( '2fa_pending', $result['code'] );

		$pending = Sudo_Session::get_2fa_pending( $user->ID );

		$this->assertIsArray( $pending );
		$this->assertSame( $user->ID, $pending['user_id'] );
		$this->assertSame( $result['expires_at'], $pending['expires_at'] );
	}

	/**
	 * ADVN-01: clear_2fa_pending() deletes the transient and unsets CHALLENGE_COOKIE.
	 */
	public function test_clear_2fa_pending_deletes_transient_and_cookie(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		Sudo_Session::attempt_activation( $user->ID, $password );

		// Verify pending state exists before clearing.
		$this->assertNotNull( Sudo_Session::get_2fa_pending( $user->ID ) );

		Sudo_Session::clear_2fa_pending();

		// Transient should be gone.
		$this->assertNull(
			Sudo_Session::get_2fa_pending( $user->ID ),
			'get_2fa_pending() should return null after clear_2fa_pending().'
		);

		// Cookie should be unset from superglobal.
		$this->assertArrayNotHasKey(
			Sudo_Session::CHALLENGE_COOKIE,
			$_COOKIE,
			'CHALLENGE_COOKIE should be unset from $_COOKIE after clear_2fa_pending().'
		);
	}

	/**
	 * ADVN-01: wp_sudo_requires_two_factor filter overrides detection.
	 *
	 * Even without Two Factor plugin meta, a filter returning true should
	 * make needs_two_factor() return true.
	 */
	public function test_needs_two_factor_filter_overrides(): void {
		$user = $this->make_admin();

		// No 2FA meta, no Two Factor plugin class needed — filter forces true.
		add_filter( 'wp_sudo_requires_two_factor', '__return_true' );

		$this->assertTrue(
			Sudo_Session::needs_two_factor( $user->ID ),
			'wp_sudo_requires_two_factor filter should override detection to true.'
		);

		remove_filter( 'wp_sudo_requires_two_factor', '__return_true' );
	}
}
