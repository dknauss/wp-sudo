<?php
/**
 * Tests for Challenge.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Challenge;
use WP_Sudo\Request_Stash;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

/**
 * @covers \WP_Sudo\Challenge
 */
class ChallengeTest extends TestCase {

	/**
	 * Challenge instance under test.
	 *
	 * @var Challenge
	 */
	private Challenge $challenge;

	/**
	 * Mock stash.
	 *
	 * @var Request_Stash|\Mockery\MockInterface
	 */
	private $stash;

	protected function setUp(): void {
		parent::setUp();
		$this->stash     = \Mockery::mock( Request_Stash::class );
		$this->challenge = new Challenge( $this->stash );
	}

	/**
	 * Test register hooks the correct actions.
	 */
	public function test_register_hooks(): void {
		Actions\expectAdded( 'admin_menu' )
			->once()
			->with( array( $this->challenge, 'register_page' ), \Mockery::any() );

		Actions\expectAdded( 'wp_ajax_' . Challenge::AJAX_AUTH_ACTION )
			->once()
			->with( array( $this->challenge, 'handle_ajax_auth' ), \Mockery::any() );

		Actions\expectAdded( 'wp_ajax_' . Challenge::AJAX_2FA_ACTION )
			->once()
			->with( array( $this->challenge, 'handle_ajax_2fa' ), \Mockery::any() );

		Actions\expectAdded( 'admin_enqueue_scripts' )
			->once()
			->with( array( $this->challenge, 'enqueue_assets' ), \Mockery::any() );

		$this->challenge->register();
	}

	/**
	 * Test NONCE_ACTION constant is defined.
	 */
	public function test_nonce_action_constant(): void {
		$this->assertSame( 'wp_sudo_challenge', Challenge::NONCE_ACTION );
	}

	/**
	 * Test AJAX action constants are defined.
	 */
	public function test_ajax_action_constants(): void {
		$this->assertSame( 'wp_sudo_challenge_auth', Challenge::AJAX_AUTH_ACTION );
		$this->assertSame( 'wp_sudo_challenge_2fa', Challenge::AJAX_2FA_ACTION );
	}

	/**
	 * Test enqueue_assets only runs on the challenge page.
	 */
	public function test_enqueue_assets_skips_other_pages(): void {
		$_GET['page'] = 'some-other-page';

		// wp_enqueue_style should never be called.
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();

		$this->challenge->enqueue_assets();

		unset( $_GET['page'] );
	}

	/**
	 * Test enqueue_assets loads on the challenge page.
	 */
	public function test_enqueue_assets_loads_on_challenge_page(): void {
		$_GET['page']      = 'wp-sudo-challenge';
		$_GET['stash_key'] = 'testkey123';

		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with( 'wp-sudo-challenge', \Mockery::type( 'string' ), array(), WP_SUDO_VERSION );

		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with(
				'wp-sudo-challenge',
				\Mockery::type( 'string' ),
				array(),
				WP_SUDO_VERSION,
				true
			);

		Functions\expect( 'admin_url' )
			->once()
			->with( 'admin-ajax.php' )
			->andReturn( 'https://example.com/wp-admin/admin-ajax.php' );

		Functions\expect( 'wp_create_nonce' )
			->once()
			->with( Challenge::NONCE_ACTION )
			->andReturn( 'test-nonce-123' );

		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				'wp-sudo-challenge',
				'wpSudoChallenge',
				\Mockery::on(
					function ( $data ) {
						return isset( $data['ajaxUrl'] )
							&& isset( $data['nonce'] )
							&& 'testkey123' === $data['stashKey']
							&& Challenge::AJAX_AUTH_ACTION === $data['authAction']
							&& Challenge::AJAX_2FA_ACTION === $data['tfaAction'];
					}
				)
			);

		$this->challenge->enqueue_assets();

		unset( $_GET['page'], $_GET['stash_key'] );
	}

	/**
	 * Test register_page adds a submenu page.
	 */
	public function test_register_page_adds_submenu(): void {
		Functions\when( '__' )->returnArg();

		Functions\expect( 'add_submenu_page' )
			->once()
			->with(
				'',
				\Mockery::type( 'string' ),
				'',
				'read',
				'wp-sudo-challenge',
				\Mockery::type( 'array' )
			);

		$this->challenge->register_page();
	}

	// -----------------------------------------------------------------
	// handle_ajax_auth — modal flow (no stash_key)
	// -----------------------------------------------------------------

	/**
	 * Test handle_ajax_auth succeeds without stash_key (modal flow).
	 *
	 * When the modal triggers auth, no stash_key is sent. The handler
	 * should skip stash validation and return {code: 'authenticated'}.
	 */
	public function test_handle_ajax_auth_succeeds_without_stash_key(): void {
		$_POST['password'] = 'correct-horse';
		// No stash_key in $_POST — modal flow.

		Functions\expect( 'check_ajax_referer' )->once();
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( '__' )->returnArg();

		// attempt_activation internals: not locked out, password correct, no 2FA.
		$user            = new \WP_User( 42 );
		$user->user_pass = 'hashed';

		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\expect( 'get_userdata' )->andReturn( $user );
		Functions\expect( 'wp_check_password' )->once()->andReturn( true );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// activate() internals: token + cookie.
		Functions\when( 'wp_generate_password' )->justReturn( 'test-token-abc' );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'setcookie' )->justReturn( true );

		// Modal success should return 'authenticated' code (not replay).
		Functions\expect( 'wp_send_json_success' )
			->once()
			->with( \Mockery::on( function ( $data ) {
				return is_array( $data )
					&& isset( $data['code'] )
					&& 'authenticated' === $data['code'];
			} ) );

		$this->stash->shouldNotReceive( 'exists' );
		$this->stash->shouldNotReceive( 'get' );

		$this->challenge->handle_ajax_auth();

		unset( $_POST['password'] );
	}

	/**
	 * Test handle_ajax_auth validates stash_key when provided (challenge flow).
	 *
	 * When a stash_key is sent, the handler must verify it exists.
	 * If the stash is expired/invalid, return 403.
	 */
	public function test_handle_ajax_auth_validates_stash_key_when_provided(): void {
		$_POST['password']  = 'correct-horse';
		$_POST['stash_key'] = 'expired-key';

		Functions\expect( 'check_ajax_referer' )->once();
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( '__' )->returnArg();

		// Stash does not exist — expired.
		$this->stash->shouldReceive( 'exists' )
			->once()
			->with( 'expired-key', 42 )
			->andReturn( false );

		// wp_send_json_error is called for the 403 (our primary assertion).
		// In real WP this dies; in tests execution continues to attempt_activation.
		// Stub everything attempt_activation and replay_stash may touch.
		$user            = new \WP_User( 42 );
		$user->user_pass = 'hashed';
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'wp_check_password' )->justReturn( true );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_generate_password' )->justReturn( 'test-token-abc' );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'setcookie' )->justReturn( true );

		// replay_stash will be called (stash_key is set); stash returns null → redirect.
		$this->stash->shouldReceive( 'get' )->andReturn( null );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'wp_send_json_success' )->justReturn( null );

		// Primary assertion: first wp_send_json_error call has 403.
		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::type( 'array' ), 403 );

		$this->challenge->handle_ajax_auth();

		unset( $_POST['password'], $_POST['stash_key'] );
	}

	/**
	 * Test handle_ajax_2fa succeeds without stash_key (modal flow).
	 */
	public function test_handle_ajax_2fa_succeeds_without_stash_key(): void {
		// No stash_key in $_POST — modal flow.

		// Set challenge cookie to bind the 2FA lookup.
		$challenge_nonce = 'test-challenge-nonce-2fa';
		$challenge_hash  = hash( 'sha256', $challenge_nonce );
		$_COOKIE[ \WP_Sudo\Sudo_Session::CHALLENGE_COOKIE ] = $challenge_nonce;

		Functions\expect( 'check_ajax_referer' )->once();
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\expect( 'get_userdata' )->once()->andReturn( new \WP_User( 42 ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'sanitize_text_field' )->returnArg();

		// get_2fa_pending() looks up transient by challenge hash.
		Functions\expect( 'get_transient' )
			->once()
			->with( 'wp_sudo_2fa_pending_' . $challenge_hash )
			->andReturn( array(
				'user_id'    => 42,
				'expires_at' => time() + 600,
			) );

		// Two_Factor_Core not loaded — filter validates.
		Functions\when( 'apply_filters' )->justReturn( true );

		// clear_2fa_pending() deletes transient + expires cookie.
		Functions\expect( 'delete_transient' )->once()->with( 'wp_sudo_2fa_pending_' . $challenge_hash );

		// activate() stubs.
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'wp_generate_password' )->justReturn( 'test-token-abc' );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'wp_send_json_error' )->justReturn( null );

		// Should return authenticated (not replay).
		Functions\expect( 'wp_send_json_success' )
			->once()
			->with( \Mockery::on( function ( $data ) {
				return is_array( $data )
					&& isset( $data['code'] )
					&& 'authenticated' === $data['code'];
			} ) );

		$this->stash->shouldNotReceive( 'get' );

		$this->challenge->handle_ajax_2fa();
	}

	/**
	 * Test handle_ajax_2fa rejects an expired timestamp even if transient still exists.
	 */
	public function test_handle_ajax_2fa_rejects_expired_timestamp(): void {
		// Set challenge cookie — the transient will have an expired timestamp.
		$challenge_nonce = 'expired-challenge-nonce';
		$challenge_hash  = hash( 'sha256', $challenge_nonce );
		$_COOKIE[ \WP_Sudo\Sudo_Session::CHALLENGE_COOKIE ] = $challenge_nonce;

		Functions\expect( 'check_ajax_referer' )->once();
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\expect( 'get_userdata' )->once()->andReturn( new \WP_User( 42 ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// Transient exists but stores a past timestamp (expired 60 seconds ago).
		Functions\expect( 'get_transient' )
			->once()
			->with( 'wp_sudo_2fa_pending_' . $challenge_hash )
			->andReturn( array(
				'user_id'    => 42,
				'expires_at' => time() - 60,
			) );

		// Capture wp_send_json_error calls to verify the expired message.
		$error_calls = array();
		Functions\expect( 'wp_send_json_error' )
			->atLeast()
			->once()
			->andReturnUsing( function ( $data, $status = 200 ) use ( &$error_calls ) {
				$error_calls[] = array( 'data' => $data, 'status' => $status );
			} );

		// Fallthrough stubs — wp_send_json_error doesn't die in tests.
		Functions\when( 'apply_filters' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'wp_generate_password' )->justReturn( 'test-token-abc' );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'wp_send_json_success' )->justReturn( null );

		$this->challenge->handle_ajax_2fa();

		// First error call should be the expired session message with 403 status.
		$this->assertNotEmpty( $error_calls, 'wp_send_json_error should have been called.' );
		$this->assertSame( 403, $error_calls[0]['status'] );
		$this->assertStringContainsString( 'expired', $error_calls[0]['data']['message'] );
	}

	/**
	 * Test handle_ajax_2fa rejects when no challenge cookie is present.
	 *
	 * This simulates an attacker who stole the WordPress session cookie
	 * but does not have the challenge cookie (set in the legitimate browser).
	 */
	public function test_handle_ajax_2fa_rejects_without_challenge_cookie(): void {
		// No challenge cookie — simulating cross-browser attack.
		unset( $_COOKIE[ \WP_Sudo\Sudo_Session::CHALLENGE_COOKIE ] );

		Functions\expect( 'check_ajax_referer' )->once();
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\expect( 'get_userdata' )->once()->andReturn( new \WP_User( 42 ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// Capture wp_send_json_error calls.
		$error_calls = array();
		Functions\expect( 'wp_send_json_error' )
			->atLeast()
			->once()
			->andReturnUsing( function ( $data, $status = 200 ) use ( &$error_calls ) {
				$error_calls[] = array( 'data' => $data, 'status' => $status );
			} );

		// Fallthrough stubs.
		Functions\when( 'apply_filters' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'wp_generate_password' )->justReturn( 'test-token-abc' );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'wp_send_json_success' )->justReturn( null );

		$this->challenge->handle_ajax_2fa();

		// Should get "expired" 403 because get_2fa_pending() returns null.
		$this->assertNotEmpty( $error_calls, 'wp_send_json_error should have been called.' );
		$this->assertSame( 403, $error_calls[0]['status'] );
		$this->assertStringContainsString( 'expired', $error_calls[0]['data']['message'] );
	}

	// -----------------------------------------------------------------
	// Multisite: register() adds network admin menu
	// -----------------------------------------------------------------

	/**
	 * Test register adds network_admin_menu on multisite.
	 */
	public function test_register_adds_network_admin_menu_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Actions\expectAdded( 'admin_menu' )
			->once()
			->with( array( $this->challenge, 'register_page' ), \Mockery::any() );

		Actions\expectAdded( 'network_admin_menu' )
			->once()
			->with( array( $this->challenge, 'register_page' ), \Mockery::any() );

		Actions\expectAdded( 'wp_ajax_' . Challenge::AJAX_AUTH_ACTION )
			->once();

		Actions\expectAdded( 'wp_ajax_' . Challenge::AJAX_2FA_ACTION )
			->once();

		Actions\expectAdded( 'admin_enqueue_scripts' )
			->once();

		$this->challenge->register();
	}
}
