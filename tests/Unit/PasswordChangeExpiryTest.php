<?php
/**
 * Tests for Plugin password-change session expiry (v2.8.0).
 *
 * Covers the feature: an active sudo session is invalidated when the user's
 * password changes, whether via the lost-password flow or a direct profile save.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Plugin;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

/**
 * @covers \WP_Sudo\Plugin::deactivate_session_on_password_reset
 * @covers \WP_Sudo\Plugin::deactivate_session_on_profile_update
 */
class PasswordChangeExpiryTest extends TestCase {

	// ── Hook registration ─────────────────────────────────────────────────

	/**
	 * Plugin::init() registers after_password_reset at priority 10 with 2 accepted args.
	 *
	 * after_password_reset fires after the lost-password reset flow completes,
	 * before the user is redirected. It receives the WP_User object and the new
	 * plaintext password.
	 */
	public function test_after_password_reset_hook_is_registered(): void {
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );

		Actions\expectAdded( 'after_password_reset' )
			->once()
			->with( \Mockery::type( 'array' ), 10, 2 );

		$plugin = new Plugin();
		$plugin->init();
	}

	/**
	 * Plugin::init() registers profile_update at priority 10 with 3 accepted args.
	 *
	 * profile_update fires after any profile save (profile.php, user-edit.php, REST API).
	 * Three args: user ID, old WP_User object, and the raw $userdata array.
	 */
	public function test_profile_update_hook_is_registered(): void {
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );

		Actions\expectAdded( 'profile_update' )
			->once()
			->with( \Mockery::type( 'array' ), 10, 3 );

		$plugin = new Plugin();
		$plugin->init();
	}

	// ── deactivate_session_on_password_reset() ────────────────────────────

	/**
	 * Lost-password reset deactivates the sudo session for the affected user.
	 *
	 * Verified via the wp_sudo_deactivated action that Sudo_Session::deactivate()
	 * fires — its presence proves deactivate() was called with the correct user ID.
	 */
	public function test_after_password_reset_deactivates_session(): void {
		$user = new \WP_User( 7 );

		// Stubs required by Sudo_Session::deactivate() internals.
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( true ); // skip setcookie block.

		Actions\expectDone( 'wp_sudo_deactivated' )
			->once()
			->with( 7 );

		$plugin = new Plugin();
		$plugin->deactivate_session_on_password_reset( $user, 'new-password-string' );
	}

	// ── deactivate_session_on_profile_update() ────────────────────────────

	/**
	 * A profile save that includes a password change deactivates the sudo session.
	 *
	 * The hashes differ ↔ password changed ↔ session must be invalidated.
	 */
	public function test_profile_update_with_password_change_deactivates_session(): void {
		$old_user            = new \WP_User( 9 );
		$old_user->user_pass = 'old-hash';

		// Stubs required by Sudo_Session::deactivate() internals.
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( true ); // skip setcookie block.

		Actions\expectDone( 'wp_sudo_deactivated' )
			->once()
			->with( 9 );

		$plugin = new Plugin();
		$plugin->deactivate_session_on_profile_update( 9, $old_user, array( 'user_pass' => 'new-hash' ) );
	}

	/**
	 * A profile save that does NOT change the password leaves the session intact.
	 *
	 * The hashes match ↔ password unchanged ↔ session must not be touched.
	 */
	public function test_profile_update_without_password_change_keeps_session(): void {
		$old_user            = new \WP_User( 9 );
		$old_user->user_pass = 'same-hash';

		// wp_sudo_deactivated must not fire when password is unchanged.
		Actions\expectDone( 'wp_sudo_deactivated' )->never();

		$plugin = new Plugin();
		$plugin->deactivate_session_on_profile_update( 9, $old_user, array( 'user_pass' => 'same-hash' ) );
	}
}
