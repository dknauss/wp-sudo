<?php
/**
 * Tests for WP_Sudo\Modal_Reauth.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Modal_Reauth;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

class ModalReauthTest extends TestCase {

	// =================================================================
	// render_modal_template()
	// =================================================================

	public function test_modal_not_rendered_for_ineligible_user(): void {
		$user = new \WP_User( 1, [ 'subscriber' ] );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [
			'allowed_roles' => [ 'editor' ],
		] );

		$modal = new Modal_Reauth();

		ob_start();
		$modal->render_modal_template();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	public function test_modal_not_rendered_for_administrator(): void {
		$user = new \WP_User( 1, [ 'administrator' ] );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [
			'allowed_roles' => [ 'editor' ],
		] );

		$modal = new Modal_Reauth();

		ob_start();
		$modal->render_modal_template();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	public function test_modal_not_rendered_when_sudo_active(): void {
		$future = time() + 300;
		$token  = 'valid-token';

		$user = new \WP_User( 1, [ 'editor' ] );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [
			'allowed_roles' => [ 'editor' ],
		] );

		$editor_role               = new \stdClass();
		$editor_role->capabilities = [ 'edit_others_posts' => true ];
		Functions\when( 'get_role' )->justReturn( $editor_role );

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

		$modal = new Modal_Reauth();

		ob_start();
		$modal->render_modal_template();
		$output = ob_get_clean();

		$this->assertEmpty( $output );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	public function test_modal_rendered_for_eligible_inactive_user(): void {
		$user = new \WP_User( 1, [ 'editor' ] );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [
			'allowed_roles' => [ 'editor' ],
		] );

		$editor_role               = new \stdClass();
		$editor_role->capabilities = [ 'edit_others_posts' => true ];
		Functions\when( 'get_role' )->justReturn( $editor_role );

		// No active session.
		Functions\when( 'get_user_meta' )->justReturn( '' );

		// Stub functions called during render.
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) {
			echo $text;
		} );

		$modal = new Modal_Reauth();

		ob_start();
		$modal->render_modal_template();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<dialog', $output );
		$this->assertStringContainsString( 'wp-sudo-modal', $output );
		$this->assertStringContainsString( 'wp-sudo-modal-password-form', $output );
		$this->assertStringContainsString( 'Confirm Your Identity', $output );
	}

	// =================================================================
	// enqueue_assets()
	// =================================================================

	public function test_assets_not_enqueued_for_ineligible_user(): void {
		$user = new \WP_User( 1, [ 'subscriber' ] );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [
			'allowed_roles' => [ 'editor' ],
		] );

		// These functions should NOT be called.
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();

		$modal = new Modal_Reauth();
		$modal->enqueue_assets();
	}

	public function test_assets_enqueued_for_eligible_inactive_user(): void {
		$user = new \WP_User( 1, [ 'editor' ] );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'get_option' )->justReturn( [
			'allowed_roles' => [ 'editor' ],
		] );

		$editor_role               = new \stdClass();
		$editor_role->capabilities = [ 'edit_others_posts' => true ];
		Functions\when( 'get_role' )->justReturn( $editor_role );

		// No active session.
		Functions\when( 'get_user_meta' )->justReturn( '' );

		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with( 'wp-sudo-modal', \Mockery::type( 'string' ), \Mockery::type( 'array' ), \Mockery::type( 'string' ) );

		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with( 'wp-sudo-modal', \Mockery::type( 'string' ), \Mockery::type( 'array' ), \Mockery::type( 'string' ), true );

		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );

		$modal = new Modal_Reauth();
		$modal->enqueue_assets();
	}
}
