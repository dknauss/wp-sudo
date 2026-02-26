<?php
/**
 * Tests for Plugin::grant_session_on_login().
 *
 * Covers the feature: login grants sudo session (v2.6.0).
 * A successful WordPress form-based login is equivalent to reauthentication
 * — challenging the user again immediately is unnecessary friction.
 * This mirrors the behaviour of Unix sudo and GitHub's sudo mode.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Plugin;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

/**
 * @covers \WP_Sudo\Plugin::grant_session_on_login
 */
class LoginSudoGrantTest extends TestCase {

	// ── Hook registration ─────────────────────────────────────────────────

	/**
	 * Plugin::init() registers the wp_login action at priority 10 with 2 accepted args.
	 *
	 * WordPress fires wp_login after wp_signon() verifies credentials and before
	 * the redirect — headers are not yet sent, so setcookie() in activate() works.
	 * Crucially, this hook does NOT fire for Application Password or XML-RPC auth,
	 * so the grant is correctly scoped to browser-based form logins only.
	 */
	public function test_wp_login_hook_is_registered(): void {
		// Stub the only non-hook WP functions called during Plugin::init()
		// (with is_admin=false the upgrader, Admin, and Site_Health are skipped).
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );

		// Brain\Monkey tracks add_action calls natively (no Functions\when override).
		// Verify add_action( 'wp_login', callback, 10, 2 ) is called during init().
		Actions\expectAdded( 'wp_login' )
			->once()
			->with( \Mockery::type( 'array' ), 10, 2 );

		$plugin = new Plugin();
		$plugin->init();
	}

	// ── grant_session_on_login() ──────────────────────────────────────────

	/**
	 * grant_session_on_login() activates a sudo session for the logged-in user.
	 *
	 * Verified indirectly via the wp_sudo_activated action that
	 * Sudo_Session::activate() fires — its presence proves activate() was
	 * called with the correct user ID.
	 */
	public function test_grant_session_on_login_fires_wp_sudo_activated(): void {
		$user = new \WP_User( 7 );

		// Stub WP functions used internally by Sudo_Session::activate().
		Functions\when( 'get_option' )->justReturn( array( 'session_duration' => 10 ) );
		Functions\when( 'wp_generate_password' )->justReturn( 'grant-token-xyz' );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'update_user_meta' )->justReturn( true );

		// activate() fires wp_sudo_activated — its presence proves activate() ran with user 7.
		Actions\expectDone( 'wp_sudo_activated' )
			->once()
			->with( 7, \Mockery::type( 'int' ), \Mockery::any() );

		$plugin = new Plugin();
		$plugin->grant_session_on_login( 'test_user', $user );
	}

	/**
	 * grant_session_on_login() uses the WP_User object's ID, not the login name.
	 *
	 * The first argument ($user_login) is the username string; the user ID must
	 * be sourced from the WP_User object to avoid any lookup discrepancy.
	 */
	public function test_grant_session_on_login_uses_user_object_id(): void {
		$user = new \WP_User( 42 );

		Functions\when( 'get_option' )->justReturn( array( 'session_duration' => 5 ) );
		Functions\when( 'wp_generate_password' )->justReturn( 'token-42' );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'update_user_meta' )->justReturn( true );

		// Must fire with user ID 42 — not some value derived from the login string.
		Actions\expectDone( 'wp_sudo_activated' )
			->once()
			->with( 42, \Mockery::type( 'int' ), \Mockery::any() );

		$plugin = new Plugin();
		$plugin->grant_session_on_login( 'completely_different_login_name', $user );
	}
}
