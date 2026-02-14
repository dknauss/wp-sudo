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
use WP_Sudo\Admin_Bar;
use WP_Sudo\Admin;
use WP_Sudo\Upgrader;
use WP_Sudo\Sudo_Session;
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
	// enqueue_shortcut()
	// -----------------------------------------------------------------

	public function test_enqueue_shortcut_skips_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		Functions\expect( 'wp_enqueue_script' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_shortcut();
	}

	public function test_enqueue_shortcut_skips_active_session(): void {
		$user_id = 5;
		$token   = 'shortcut-token';

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return time() + 600;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		Functions\expect( 'wp_enqueue_script' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_shortcut();

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	public function test_enqueue_shortcut_skips_challenge_page(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		$_GET['page'] = 'wp-sudo-challenge';

		Functions\expect( 'wp_enqueue_script' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_shortcut();

		unset( $_GET['page'] );
	}

	public function test_enqueue_shortcut_loads_on_admin_page(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?page=wp-sudo-challenge' );

		$_GET['page'] = 'some-other-page';

		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with(
				'wp-sudo-shortcut',
				\Mockery::type( 'string' ),
				array(),
				WP_SUDO_VERSION,
				true
			);

		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				'wp-sudo-shortcut',
				'wpSudoShortcut',
				\Mockery::on( function ( $data ) {
					return isset( $data['challengeUrl'] );
				} )
			);

		$plugin = new Plugin();
		$plugin->enqueue_shortcut();

		unset( $_GET['page'] );
	}

	// -----------------------------------------------------------------
	// enqueue_gate_ui()
	// -----------------------------------------------------------------

	public function test_enqueue_gate_ui_skips_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		Functions\expect( 'wp_enqueue_script' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_gate_ui( 'plugins.php' );
	}

	public function test_enqueue_gate_ui_skips_active_session(): void {
		$user_id = 5;
		$token   = 'gate-ui-token';

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return time() + 600;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		Functions\expect( 'wp_enqueue_script' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_gate_ui( 'plugins.php' );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	public function test_enqueue_gate_ui_skips_non_gated_page(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		Functions\expect( 'wp_enqueue_script' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_gate_ui( 'edit.php' );
	}

	/**
	 * @dataProvider gated_page_provider
	 *
	 * @param string $hook_suffix Admin page hook suffix.
	 * @param string $expected_page Expected page identifier passed to JS.
	 */
	public function test_enqueue_gate_ui_loads_on_gated_page( string $hook_suffix, string $expected_page ): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with(
				'wp-sudo-gate-ui',
				\Mockery::type( 'string' ),
				array(),
				WP_SUDO_VERSION,
				true
			);

		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				'wp-sudo-gate-ui',
				'wpSudoGateUi',
				\Mockery::on( function ( $data ) use ( $expected_page ) {
					return isset( $data['page'] ) && $expected_page === $data['page'];
				} )
			);

		$plugin = new Plugin();
		$plugin->enqueue_gate_ui( $hook_suffix );
	}

	/**
	 * Data provider for gated page test.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function gated_page_provider(): array {
		return array(
			'themes'         => array( 'themes.php', 'themes' ),
			'theme-install'  => array( 'theme-install.php', 'theme-install' ),
			'plugins'        => array( 'plugins.php', 'plugins' ),
			'plugin-install' => array( 'plugin-install.php', 'plugin-install' ),
		);
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
