<?php
/**
 * Tests for WP_Sudo\Sudo_Session.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Sudo_Session;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

class SudoSessionTest extends TestCase {

	// =================================================================
	// time_remaining()
	// =================================================================

	public function test_time_remaining_returns_zero_when_no_meta(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$this->assertSame( 0, Sudo_Session::time_remaining( 1 ) );
	}

	public function test_time_remaining_returns_positive_when_active(): void {
		$future = time() + 300;
		Functions\when( 'get_user_meta' )->justReturn( $future );

		$remaining = Sudo_Session::time_remaining( 1 );

		$this->assertGreaterThan( 0, $remaining );
		$this->assertLessThanOrEqual( 300, $remaining );
	}

	public function test_time_remaining_returns_zero_when_expired(): void {
		$past = time() - 60;
		Functions\when( 'get_user_meta' )->justReturn( $past );

		$this->assertSame( 0, Sudo_Session::time_remaining( 1 ) );
	}

	// =================================================================
	// user_is_allowed()
	// =================================================================

	public function test_user_is_allowed_rejects_null_user(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->assertFalse( Sudo_Session::user_is_allowed( 999 ) );
	}

	public function test_user_is_allowed_rejects_administrators(): void {
		$user = new \WP_User( 1, [ 'administrator' ] );
		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->assertFalse( Sudo_Session::user_is_allowed( 1 ) );
	}

	public function test_user_is_allowed_rejects_non_allowed_roles(): void {
		$user = new \WP_User( 2, [ 'subscriber' ] );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [
			'session_duration' => 15,
			'allowed_roles'    => [ 'editor', 'site_manager' ],
		] );

		$this->assertFalse( Sudo_Session::user_is_allowed( 2 ) );
	}

	public function test_user_is_allowed_rejects_role_without_min_capability(): void {
		$user = new \WP_User( 3, [ 'author' ] );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [
			'session_duration' => 15,
			'allowed_roles'    => [ 'author' ],
		] );

		$author_role               = new \stdClass();
		$author_role->capabilities = [ 'publish_posts' => true ];
		Functions\when( 'get_role' )->justReturn( $author_role );

		$this->assertFalse( Sudo_Session::user_is_allowed( 3 ) );
	}

	public function test_user_is_allowed_accepts_eligible_editor(): void {
		$user = new \WP_User( 4, [ 'editor' ] );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [
			'session_duration' => 15,
			'allowed_roles'    => [ 'editor', 'site_manager' ],
		] );

		$editor_role               = new \stdClass();
		$editor_role->capabilities = [ 'edit_others_posts' => true ];
		Functions\when( 'get_role' )->justReturn( $editor_role );

		$this->assertTrue( Sudo_Session::user_is_allowed( 4 ) );
	}

	public function test_user_is_allowed_accepts_site_manager(): void {
		$user = new \WP_User( 5, [ 'site_manager' ] );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [
			'session_duration' => 15,
			'allowed_roles'    => [ 'editor', 'site_manager' ],
		] );

		$sm_role               = new \stdClass();
		$sm_role->capabilities = [ 'edit_others_posts' => true, 'switch_themes' => true ];
		Functions\when( 'get_role' )->justReturn( $sm_role );

		$this->assertTrue( Sudo_Session::user_is_allowed( 5 ) );
	}

	// =================================================================
	// is_active()
	// =================================================================

	public function test_is_active_returns_false_when_no_expiry(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$this->assertFalse( Sudo_Session::is_active( 1 ) );
	}

	public function test_is_active_returns_false_when_expired(): void {
		$past = time() - 60;
		Functions\when( 'get_user_meta' )->justReturn( $past );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'setcookie' )->justReturn( true );

		$this->assertFalse( Sudo_Session::is_active( 1 ) );
	}

	public function test_is_active_returns_false_when_token_mismatch(): void {
		$future = time() + 300;

		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $future ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return $future;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', 'correct-token' );
			}
			return '';
		} );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );

		$this->assertFalse( Sudo_Session::is_active( 1 ) );
	}

	public function test_is_active_returns_false_when_role_changed(): void {
		$future = time() + 300;
		$token  = 'valid-token-123';

		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $future, $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return $future;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		// user_is_allowed returns false — role changed to subscriber.
		$user = new \WP_User( 1, [ 'subscriber' ] );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [ 'allowed_roles' => [ 'editor' ] ] );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'setcookie' )->justReturn( true );

		$this->assertFalse( Sudo_Session::is_active( 1 ) );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	public function test_is_active_returns_true_when_valid(): void {
		$future = time() + 300;
		$token  = 'valid-token-456';

		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $future, $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return $future;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		$user = new \WP_User( 1, [ 'editor' ] );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [
			'allowed_roles' => [ 'editor', 'site_manager' ],
		] );

		$editor_role               = new \stdClass();
		$editor_role->capabilities = [ 'edit_others_posts' => true ];
		Functions\when( 'get_role' )->justReturn( $editor_role );

		$this->assertTrue( Sudo_Session::is_active( 1 ) );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	// =================================================================
	// filter_user_capabilities()
	// =================================================================

	public function test_filter_strips_unfiltered_html_when_not_sudo(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$session = new Sudo_Session();
		$user    = new \WP_User( 1, [ 'editor' ] );
		$allcaps = [ 'edit_posts' => true, 'unfiltered_html' => true ];

		$result = $session->filter_user_capabilities( $allcaps, [], [], $user );

		$this->assertFalse( $result['unfiltered_html'] );
		$this->assertTrue( $result['edit_posts'] );
	}

	public function test_filter_preserves_unfiltered_html_for_admins(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$session = new Sudo_Session();
		$user    = new \WP_User( 1, [ 'administrator' ] );
		$allcaps = [ 'edit_posts' => true, 'unfiltered_html' => true ];

		$result = $session->filter_user_capabilities( $allcaps, [], [], $user );

		$this->assertTrue( $result['unfiltered_html'] );
	}

	public function test_filter_grants_admin_caps_during_sudo(): void {
		$future = time() + 300;
		$token  = 'active-token';

		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $future, $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return $future;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		$user = new \WP_User( 1, [ 'editor' ] );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [ 'allowed_roles' => [ 'editor' ] ] );

		$editor_role               = new \stdClass();
		$editor_role->capabilities = [ 'edit_others_posts' => true ];

		$admin_role               = new \stdClass();
		$admin_role->capabilities = [
			'manage_options'  => true,
			'edit_users'      => true,
			'install_plugins' => true,
		];

		Functions\when( 'get_role' )->alias( function ( $slug ) use ( $editor_role, $admin_role ) {
			if ( 'editor' === $slug ) {
				return $editor_role;
			}
			if ( 'administrator' === $slug ) {
				return $admin_role;
			}
			return null;
		} );

		// Simulate eligible admin page request.
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );

		$session = new Sudo_Session();
		$result  = $session->filter_user_capabilities( [ 'edit_posts' => true ], [], [], $user );

		$this->assertTrue( $result['manage_options'] );
		$this->assertTrue( $result['edit_users'] );
		$this->assertTrue( $result['install_plugins'] );
		$this->assertTrue( $result['edit_posts'] );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	public function test_filter_does_not_escalate_during_ajax(): void {
		$future = time() + 300;
		$token  = 'active-token';

		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $future, $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return $future;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		$user = new \WP_User( 1, [ 'editor' ] );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [ 'allowed_roles' => [ 'editor' ] ] );

		$editor_role               = new \stdClass();
		$editor_role->capabilities = [ 'edit_others_posts' => true ];
		Functions\when( 'get_role' )->justReturn( $editor_role );

		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );

		$session = new Sudo_Session();
		$result  = $session->filter_user_capabilities( [ 'edit_posts' => true ], [], [], $user );

		$this->assertArrayNotHasKey( 'manage_options', $result );
		$this->assertTrue( $result['edit_posts'] );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	public function test_filter_does_not_escalate_on_frontend(): void {
		$future = time() + 300;
		$token  = 'active-token';

		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $future, $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return $future;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		$user = new \WP_User( 1, [ 'editor' ] );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [ 'allowed_roles' => [ 'editor' ] ] );

		$editor_role               = new \stdClass();
		$editor_role->capabilities = [ 'edit_others_posts' => true ];
		Functions\when( 'get_role' )->justReturn( $editor_role );

		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );

		$session = new Sudo_Session();
		$result  = $session->filter_user_capabilities( [ 'edit_posts' => true ], [], [], $user );

		$this->assertArrayNotHasKey( 'manage_options', $result );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	// =================================================================
	// Rate limiting (private methods tested via Reflection)
	// =================================================================

	public function test_is_locked_out_returns_false_when_no_lockout(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$method = new \ReflectionMethod( Sudo_Session::class, 'is_locked_out' );


		$this->assertFalse( $method->invoke( null, 1 ) );
	}

	public function test_is_locked_out_returns_true_during_lockout(): void {
		$until = time() + 120;
		Functions\when( 'get_user_meta' )->justReturn( $until );

		$method = new \ReflectionMethod( Sudo_Session::class, 'is_locked_out' );


		$this->assertTrue( $method->invoke( null, 1 ) );
	}

	public function test_is_locked_out_returns_false_after_expiry(): void {
		$past = time() - 60;
		Functions\when( 'get_user_meta' )->justReturn( $past );
		Functions\when( 'delete_user_meta' )->justReturn( true );

		$method = new \ReflectionMethod( Sudo_Session::class, 'is_locked_out' );


		$this->assertFalse( $method->invoke( null, 1 ) );
	}

	public function test_lockout_remaining_returns_positive_during_lockout(): void {
		$until = time() + 200;
		Functions\when( 'get_user_meta' )->justReturn( $until );

		$method = new \ReflectionMethod( Sudo_Session::class, 'lockout_remaining' );


		$remaining = $method->invoke( null, 1 );

		$this->assertGreaterThan( 0, $remaining );
		$this->assertLessThanOrEqual( 200, $remaining );
	}

	public function test_lockout_remaining_returns_zero_after_expiry(): void {
		$past = time() - 10;
		Functions\when( 'get_user_meta' )->justReturn( $past );

		$method = new \ReflectionMethod( Sudo_Session::class, 'lockout_remaining' );


		$this->assertSame( 0, $method->invoke( null, 1 ) );
	}
}
