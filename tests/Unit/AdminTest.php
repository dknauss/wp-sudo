<?php
/**
 * Tests for WP_Sudo\Admin.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Admin;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

class AdminTest extends TestCase {

	// -----------------------------------------------------------------
	// defaults()
	// -----------------------------------------------------------------

	public function test_defaults_returns_expected_structure(): void {
		$defaults = Admin::defaults();

		$this->assertIsArray( $defaults );
		$this->assertArrayHasKey( 'session_duration', $defaults );
		$this->assertArrayHasKey( 'allowed_roles', $defaults );
		$this->assertSame( 15, $defaults['session_duration'] );
		$this->assertSame( [ 'editor', 'site_manager' ], $defaults['allowed_roles'] );
	}

	// -----------------------------------------------------------------
	// sanitize_settings()
	// -----------------------------------------------------------------

	public function test_sanitize_clamps_duration_below_range(): void {
		$this->set_up_sanitize_mocks();

		$admin  = new Admin();
		$result = $admin->sanitize_settings( [ 'session_duration' => 0 ] );

		$this->assertSame( 15, $result['session_duration'] );
	}

	public function test_sanitize_clamps_duration_above_range(): void {
		$this->set_up_sanitize_mocks();

		$admin  = new Admin();
		$result = $admin->sanitize_settings( [ 'session_duration' => 30 ] );

		$this->assertSame( 15, $result['session_duration'] );
	}

	public function test_sanitize_accepts_valid_duration(): void {
		$this->set_up_sanitize_mocks();

		$admin  = new Admin();
		$result = $admin->sanitize_settings( [ 'session_duration' => 10 ] );

		$this->assertSame( 10, $result['session_duration'] );
	}

	public function test_sanitize_strips_ineligible_roles(): void {
		$roles_object        = new \stdClass();
		$roles_object->roles = [
			'editor'       => [
				'name'         => 'Editor',
				'capabilities' => [ 'edit_others_posts' => true, 'publish_posts' => true ],
			],
			'author'       => [
				'name'         => 'Author',
				'capabilities' => [ 'publish_posts' => true ],
			],
			'site_manager' => [
				'name'         => 'Site Manager',
				'capabilities' => [ 'edit_others_posts' => true, 'switch_themes' => true ],
			],
		];

		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_roles' )->justReturn( $roles_object );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( [
			'session_duration' => 10,
			'allowed_roles'    => [ 'editor', 'author', 'site_manager' ],
		] );

		$this->assertSame( [ 'editor', 'site_manager' ], $result['allowed_roles'] );
	}

	public function test_sanitize_strips_nonexistent_roles(): void {
		$roles_object        = new \stdClass();
		$roles_object->roles = [
			'editor' => [
				'name'         => 'Editor',
				'capabilities' => [ 'edit_others_posts' => true ],
			],
		];

		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_roles' )->justReturn( $roles_object );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( [
			'session_duration' => 5,
			'allowed_roles'    => [ 'editor', 'fake_role' ],
		] );

		$this->assertSame( [ 'editor' ], $result['allowed_roles'] );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	private function set_up_sanitize_mocks(): void {
		$roles_object        = new \stdClass();
		$roles_object->roles = [
			'editor' => [
				'name'         => 'Editor',
				'capabilities' => [ 'edit_others_posts' => true ],
			],
		];

		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_roles' )->justReturn( $roles_object );
	}
}
