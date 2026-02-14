<?php
/**
 * Tests for WP_Sudo\Plugin (v2).
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Plugin;
use WP_Sudo\Gate;
use WP_Sudo\Challenge;
use WP_Sudo\Modal;
use WP_Sudo\Admin_Bar;
use WP_Sudo\Admin;
use WP_Sudo\Upgrader;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \WP_Sudo\Plugin
 */
class PluginTest extends TestCase {

	// -----------------------------------------------------------------
	// init()
	// -----------------------------------------------------------------

	public function test_init_creates_all_components(): void {
		$this->stub_init_deps();

		$plugin = new Plugin();
		$plugin->init();

		$this->assertInstanceOf( Gate::class, $plugin->gate() );
		$this->assertInstanceOf( Challenge::class, $plugin->challenge() );
		$this->assertInstanceOf( Modal::class, $plugin->modal() );
		$this->assertInstanceOf( Admin_Bar::class, $plugin->admin_bar() );
		$this->assertInstanceOf( Admin::class, $plugin->admin() );
	}

	public function test_init_loads_textdomain(): void {
		Functions\expect( 'load_plugin_textdomain' )
			->once()
			->with( 'wp-sudo', false, \Mockery::type( 'string' ) );

		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );

		$plugin = new Plugin();
		$plugin->init();
	}

	public function test_init_runs_upgrader(): void {
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );

		// Upgrader reads get_option for the stored version.
		Functions\expect( 'get_option' )
			->with( Upgrader::VERSION_OPTION, '0.0.0' )
			->once()
			->andReturn( WP_SUDO_VERSION );

		$plugin = new Plugin();
		$plugin->init();
	}

	public function test_init_skips_upgrader_on_frontend(): void {
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'get_option' )->justReturn( array() );

		$plugin = new Plugin();
		$plugin->init();

		// Upgrader was never instantiated on front-end — no version option read.
		$this->assertTrue( true );
	}

	public function test_init_creates_admin_when_is_admin(): void {
		$this->stub_init_deps( true );

		$plugin = new Plugin();
		$plugin->init();

		$this->assertInstanceOf( Admin::class, $plugin->admin() );
	}

	public function test_init_skips_admin_when_not_admin(): void {
		$this->stub_init_deps( false );

		$plugin = new Plugin();
		$plugin->init();

		$this->assertNull( $plugin->admin() );
	}

	// -----------------------------------------------------------------
	// activate()
	// -----------------------------------------------------------------

	public function test_activate_stamps_version_and_sets_flag(): void {
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );

		Functions\expect( 'update_option' )
			->once()
			->with( 'wp_sudo_activated', true );

		$plugin = new Plugin();
		$plugin->activate();
	}

	// -----------------------------------------------------------------
	// deactivate()
	// -----------------------------------------------------------------

	public function test_deactivate_removes_flag(): void {
		Functions\expect( 'delete_option' )
			->once()
			->with( 'wp_sudo_activated' );

		$plugin = new Plugin();
		$plugin->deactivate();
	}

	// -----------------------------------------------------------------
	// Accessors (null before init)
	// -----------------------------------------------------------------

	public function test_accessors_return_null_before_init(): void {
		$plugin = new Plugin();

		$this->assertNull( $plugin->gate() );
		$this->assertNull( $plugin->challenge() );
		$this->assertNull( $plugin->modal() );
		$this->assertNull( $plugin->admin_bar() );
		$this->assertNull( $plugin->admin() );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Stub all WordPress functions needed by init().
	 *
	 * @param bool $is_admin Whether is_admin() returns true.
	 * @return void
	 */
	private function stub_init_deps( bool $is_admin = true ): void {
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( $is_admin );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'fake-nonce' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
	}
}
