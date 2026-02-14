<?php
/**
 * Tests for WP_Sudo\Upgrader (v2).
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Admin;
use WP_Sudo\Upgrader;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \WP_Sudo\Upgrader
 */
class UpgraderTest extends TestCase {

	// ── Framework ────────────────────────────────────────────────────

	public function test_skips_when_version_is_current(): void {
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\expect( 'update_option' )->never();

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_skips_when_version_is_newer(): void {
		Functions\when( 'get_option' )->justReturn( '99.0.0' );
		Functions\expect( 'update_option' )->never();

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_stamps_version_on_older_install(): void {
		Functions\when( 'get_option' )->justReturn( '0.0.0' );
		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( null );

		Functions\expect( 'update_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, WP_SUDO_VERSION );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	// ── 2.0.0 migration ─────────────────────────────────────────────

	public function test_200_removes_site_manager_role(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '1.2.1';
			}
			if ( Admin::OPTION_KEY === $key ) {
				return array( 'session_duration' => 15 );
			}
			return $default;
		} );

		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( null );

		Functions\expect( 'remove_role' )
			->once()
			->with( 'site_manager' );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_200_strips_allowed_roles_from_settings(): void {
		$old_settings = array(
			'session_duration' => 10,
			'allowed_roles'    => array( 'editor', 'site_manager' ),
		);

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $old_settings ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '1.2.1';
			}
			if ( Admin::OPTION_KEY === $key ) {
				return $old_settings;
			}
			return $default;
		} );

		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( null );

		// Should update settings without allowed_roles.
		Functions\expect( 'update_option' )
			->with(
				Admin::OPTION_KEY,
				\Mockery::on( function ( $settings ) {
					return isset( $settings['session_duration'] )
						&& ! isset( $settings['allowed_roles'] );
				} )
			)
			->once();

		// Also stamps the version.
		Functions\expect( 'update_option' )
			->with( Upgrader::VERSION_OPTION, WP_SUDO_VERSION )
			->once();

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_200_deletes_role_version_option(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '1.2.1';
			}
			if ( Admin::OPTION_KEY === $key ) {
				return array( 'session_duration' => 15 );
			}
			return $default;
		} );

		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( null );

		Functions\expect( 'delete_option' )
			->once()
			->with( 'wp_sudo_role_version' );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_200_skips_settings_update_when_no_allowed_roles(): void {
		$settings = array( 'session_duration' => 15 );

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $settings ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '1.2.1';
			}
			if ( Admin::OPTION_KEY === $key ) {
				return $settings;
			}
			return $default;
		} );

		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( null );

		// Should only update the version stamp, not the settings.
		Functions\expect( 'update_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, WP_SUDO_VERSION );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	// ── Constants ────────────────────────────────────────────────────

	public function test_version_option_constant(): void {
		$this->assertSame( 'wp_sudo_db_version', Upgrader::VERSION_OPTION );
	}

	// ── Multisite: site options ──────────────────────────────────────

	/**
	 * Test maybe_upgrade uses site options on multisite.
	 */
	public function test_uses_site_option_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Functions\expect( 'get_site_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, '0.0.0' )
			->andReturn( WP_SUDO_VERSION );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Should not call update_site_option since version is current.
		$this->assertTrue( true );
	}

	/**
	 * Test maybe_upgrade stamps version with site option on multisite.
	 */
	public function test_stamps_version_with_site_option_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Functions\expect( 'get_site_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, '0.0.0' )
			->andReturn( '0.0.0' );

		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'delete_option' )->justReturn( true );

		Functions\expect( 'update_site_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, WP_SUDO_VERSION );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	// ── 2.1.0 migration ─────────────────────────────────────────────

	public function test_210_strips_unfiltered_html_from_editor(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '2.0.0';
			}
			return $default;
		} );
		Functions\when( 'update_option' )->justReturn( true );

		$role = \Mockery::mock( 'WP_Role' );
		$role->shouldReceive( 'remove_cap' )
			->once()
			->with( 'unfiltered_html' );

		Functions\expect( 'get_role' )
			->once()
			->with( 'editor' )
			->andReturn( $role );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_210_skips_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Functions\expect( 'get_site_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, '0.0.0' )
			->andReturn( '2.0.0' );

		Functions\when( 'update_site_option' )->justReturn( true );

		// On multisite, strip_editor_unfiltered_html is a no-op,
		// so get_role should never be called.
		Functions\expect( 'get_role' )->never();

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}
}
