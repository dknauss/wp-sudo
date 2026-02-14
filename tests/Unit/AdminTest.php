<?php
/**
 * Tests for WP_Sudo\Admin (v2).
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Admin;
use WP_Sudo\Gate;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

/**
 * @covers \WP_Sudo\Admin
 */
class AdminTest extends TestCase {

	// -----------------------------------------------------------------
	// defaults()
	// -----------------------------------------------------------------

	public function test_defaults_returns_expected_structure(): void {
		$defaults = Admin::defaults();

		$this->assertIsArray( $defaults );
		$this->assertArrayHasKey( 'session_duration', $defaults );
		$this->assertSame( 15, $defaults['session_duration'] );
	}

	public function test_defaults_include_all_policy_keys(): void {
		$defaults = Admin::defaults();

		$this->assertArrayHasKey( 'rest_app_password_policy', $defaults );
		$this->assertArrayHasKey( 'cli_policy', $defaults );
		$this->assertArrayHasKey( 'cron_policy', $defaults );
		$this->assertArrayHasKey( 'xmlrpc_policy', $defaults );
	}

	public function test_defaults_policies_are_block(): void {
		$defaults = Admin::defaults();

		$this->assertSame( Gate::POLICY_BLOCK, $defaults['rest_app_password_policy'] );
		$this->assertSame( Gate::POLICY_BLOCK, $defaults['cli_policy'] );
		$this->assertSame( Gate::POLICY_BLOCK, $defaults['cron_policy'] );
		$this->assertSame( Gate::POLICY_BLOCK, $defaults['xmlrpc_policy'] );
	}

	public function test_defaults_no_allowed_roles_key(): void {
		$defaults = Admin::defaults();

		$this->assertArrayNotHasKey( 'allowed_roles', $defaults );
	}

	// -----------------------------------------------------------------
	// get()
	// -----------------------------------------------------------------

	public function test_get_returns_stored_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'session_duration' => 10 ) );

		$this->assertSame( 10, Admin::get( 'session_duration' ) );
	}

	public function test_get_returns_default_for_missing_key(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 15, Admin::get( 'session_duration' ) );
	}

	public function test_get_returns_explicit_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'custom', Admin::get( 'nonexistent_key', 'custom' ) );
	}

	// -----------------------------------------------------------------
	// sanitize_settings()
	// -----------------------------------------------------------------

	public function test_sanitize_clamps_duration_below_range(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array( 'session_duration' => 0 ) );

		$this->assertSame( 15, $result['session_duration'] );
	}

	public function test_sanitize_clamps_duration_above_range(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array( 'session_duration' => 30 ) );

		$this->assertSame( 15, $result['session_duration'] );
	}

	public function test_sanitize_accepts_valid_duration(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array( 'session_duration' => 10 ) );

		$this->assertSame( 10, $result['session_duration'] );
	}

	public function test_sanitize_normalizes_valid_policy(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array(
			'session_duration'         => 15,
			'cli_policy'               => 'allow',
			'cron_policy'              => 'block',
			'xmlrpc_policy'            => 'allow',
			'rest_app_password_policy' => 'block',
		) );

		$this->assertSame( 'allow', $result['cli_policy'] );
		$this->assertSame( 'block', $result['cron_policy'] );
		$this->assertSame( 'allow', $result['xmlrpc_policy'] );
		$this->assertSame( 'block', $result['rest_app_password_policy'] );
	}

	public function test_sanitize_rejects_invalid_policy_values(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array(
			'session_duration' => 15,
			'cli_policy'       => 'invalid',
			'cron_policy'      => 'something',
		) );

		$this->assertSame( 'block', $result['cli_policy'] );
		$this->assertSame( 'block', $result['cron_policy'] );
	}

	public function test_sanitize_defaults_missing_policies_to_block(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array( 'session_duration' => 15 ) );

		$this->assertSame( 'block', $result['cli_policy'] );
		$this->assertSame( 'block', $result['cron_policy'] );
		$this->assertSame( 'block', $result['xmlrpc_policy'] );
		$this->assertSame( 'block', $result['rest_app_password_policy'] );
	}

	// -----------------------------------------------------------------
	// register()
	// -----------------------------------------------------------------

	public function test_register_hooks(): void {
		Actions\expectAdded( 'admin_menu' )
			->once();

		Actions\expectAdded( 'admin_init' )
			->once();

		Actions\expectAdded( 'admin_enqueue_scripts' )
			->once();

		Filters\expectAdded( 'plugin_action_links_' . WP_SUDO_PLUGIN_BASENAME )
			->once();

		$admin = new Admin();
		$admin->register();
	}

	// -----------------------------------------------------------------
	// Constants
	// -----------------------------------------------------------------

	public function test_option_key_constant(): void {
		$this->assertSame( 'wp_sudo_settings', Admin::OPTION_KEY );
	}

	public function test_page_slug_constant(): void {
		$this->assertSame( 'wp-sudo-settings', Admin::PAGE_SLUG );
	}

	// -----------------------------------------------------------------
	// add_settings_page() — help tab hook registration
	// -----------------------------------------------------------------

	public function test_add_settings_page_registers_load_hook(): void {
		Functions\when( '__' )->returnArg();

		Functions\expect( 'add_options_page' )
			->once()
			->andReturn( 'settings_page_wp-sudo-settings' );

		Actions\expectAdded( 'load-settings_page_wp-sudo-settings' )
			->once();

		$admin = new Admin();
		$admin->add_settings_page();
	}

	public function test_add_settings_page_skips_load_hook_when_no_suffix(): void {
		Functions\when( '__' )->returnArg();

		Functions\expect( 'add_options_page' )
			->once()
			->andReturn( false );

		// No load- hook should be added when add_options_page returns false.
		Actions\expectAdded( 'load-' )->never();

		$admin = new Admin();
		$admin->add_settings_page();

		$this->assertTrue( true );
	}

	// -----------------------------------------------------------------
	// add_help_tabs()
	// -----------------------------------------------------------------

	public function test_add_help_tabs_registers_four_tabs(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$this->assertCount( 4, $screen->get_help_tabs() );
	}

	public function test_add_help_tabs_has_expected_tab_ids(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs = $screen->get_help_tabs();
		$ids  = array_keys( $tabs );

		$this->assertContains( 'wp-sudo-how-it-works', $ids );
		$this->assertContains( 'wp-sudo-settings-help', $ids );
		$this->assertContains( 'wp-sudo-extending', $ids );
		$this->assertContains( 'wp-sudo-audit-hooks', $ids );
	}

	public function test_add_help_tabs_sets_sidebar(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$this->assertNotEmpty( $screen->get_help_sidebar() );
	}

	public function test_add_help_tabs_bails_when_no_screen(): void {
		Functions\when( 'get_current_screen' )->justReturn( null );

		$admin = new Admin();
		// Should not throw or error when screen is null.
		$admin->add_help_tabs();

		$this->assertTrue( true );
	}

	public function test_how_it_works_tab_mentions_two_factor(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-how-it-works']['content'] ?? '';

		$this->assertStringContainsString( 'Two-Factor Authentication', $content );
		$this->assertStringContainsString( 'Two Factor plugin', $content );
	}

	public function test_settings_tab_uses_full_sentences(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-settings-help']['content'] ?? '';

		// Must start with a full sentence, not a fragment.
		$this->assertStringContainsString( 'This setting controls', $content );
		$this->assertStringNotContainsString( 'How long the sudo window', $content );
	}

	// -----------------------------------------------------------------
	// render_settings_page()
	// -----------------------------------------------------------------

	public function test_render_settings_page_includes_introduction(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'do_settings_sections' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( '__' )->returnArg();

		$admin = new Admin();

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'reauthentication step', $output );
		$this->assertStringContainsString( 'two-factor authentication', $output );
	}

	// -----------------------------------------------------------------
	// Multisite: get()
	// -----------------------------------------------------------------

	public function test_get_uses_site_option_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Functions\expect( 'get_site_option' )
			->once()
			->with( Admin::OPTION_KEY, Admin::defaults() )
			->andReturn( array( 'session_duration' => 8 ) );

		$this->assertSame( 8, Admin::get( 'session_duration' ) );
	}

	public function test_get_uses_option_on_single_site(): void {
		Functions\when( 'is_multisite' )->justReturn( false );

		Functions\expect( 'get_option' )
			->once()
			->with( Admin::OPTION_KEY, Admin::defaults() )
			->andReturn( array( 'session_duration' => 12 ) );

		$this->assertSame( 12, Admin::get( 'session_duration' ) );
	}

	// -----------------------------------------------------------------
	// Multisite: register()
	// -----------------------------------------------------------------

	public function test_register_uses_network_admin_menu_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Actions\expectAdded( 'network_admin_menu' )->once();
		Actions\expectAdded( 'network_admin_edit_wp_sudo_settings' )->once();
		Actions\expectAdded( 'admin_init' )->once();
		Actions\expectAdded( 'admin_enqueue_scripts' )->once();

		Filters\expectAdded( 'plugin_action_links_' . WP_SUDO_PLUGIN_BASENAME )->once();

		$admin = new Admin();
		$admin->register();
	}

	// -----------------------------------------------------------------
	// Multisite: add_network_settings_page()
	// -----------------------------------------------------------------

	public function test_add_network_settings_page_registers_submenu(): void {
		Functions\when( '__' )->returnArg();

		Functions\expect( 'add_submenu_page' )
			->once()
			->with(
				'settings.php',
				\Mockery::type( 'string' ),
				\Mockery::type( 'string' ),
				'manage_network_options',
				Admin::PAGE_SLUG,
				\Mockery::type( 'array' )
			)
			->andReturn( 'settings_page_wp-sudo-settings' );

		Actions\expectAdded( 'load-settings_page_wp-sudo-settings' )->once();

		$admin = new Admin();
		$admin->add_network_settings_page();
	}

	// -----------------------------------------------------------------
	// render_gated_actions_table()
	// -----------------------------------------------------------------

	public function test_render_gated_actions_table_outputs_table(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$admin = new Admin();

		ob_start();
		$admin->render_gated_actions_table();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<table class="widefat striped"', $output );
		$this->assertStringContainsString( 'Gated Actions', $output );
		$this->assertStringContainsString( 'plugin.activate', $output );
		$this->assertStringContainsString( 'theme.switch', $output );
		$this->assertStringContainsString( 'user.delete', $output );
	}

	public function test_render_gated_actions_table_shows_surfaces(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$admin = new Admin();

		ob_start();
		$admin->render_gated_actions_table();
		$output = ob_get_clean();

		// plugin.activate has both Admin and REST surfaces.
		$this->assertStringContainsString( 'Admin', $output );
		$this->assertStringContainsString( 'REST', $output );
	}
}
