<?php
/**
 * Integration tests for Admin settings CRUD.
 *
 * Verifies settings persistence, sanitization, and cache behavior using
 * the real WordPress options API and database — no mocks.
 *
 * @covers \WP_Sudo\Admin
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Admin;
use WP_Sudo\Gate;

class AdminTest extends TestCase {

	/**
	 * Admin instance for calling sanitize_settings().
	 *
	 * @var Admin
	 */
	private Admin $admin;

	public function set_up(): void {
		parent::set_up();

		$this->admin = new Admin();

		// Ensure no stale option exists.
		delete_option( Admin::OPTION_KEY );
		if ( is_multisite() ) {
			delete_site_option( Admin::OPTION_KEY );
		}
		Admin::reset_cache();
	}

	// ── Default settings ─────────────────────────────────────────────

	/**
	 * Test Admin::get() returns default values when no option is stored.
	 */
	public function test_default_settings_returned_when_no_option_exists(): void {
		$this->assertSame( 15, Admin::get( 'session_duration' ) );
		$this->assertSame( Gate::POLICY_LIMITED, Admin::get( 'rest_app_password_policy' ) );
		$this->assertSame( Gate::POLICY_LIMITED, Admin::get( 'cli_policy' ) );
		$this->assertSame( Gate::POLICY_LIMITED, Admin::get( 'cron_policy' ) );
		$this->assertSame( Gate::POLICY_LIMITED, Admin::get( 'xmlrpc_policy' ) );
		$this->assertSame( Gate::POLICY_LIMITED, Admin::get( 'wpgraphql_policy' ) );
		$this->assertSame( array(), Admin::get( 'app_password_policies' ) );
	}

	// ── Settings persistence ─────────────────────────────────────────

	/**
	 * Test saved settings are returned by Admin::get() after cache reset.
	 */
	public function test_settings_persist_after_save(): void {
		$settings = array(
			'session_duration'         => 5,
			'rest_app_password_policy' => Gate::POLICY_UNRESTRICTED,
			'cli_policy'               => Gate::POLICY_DISABLED,
			'cron_policy'              => Gate::POLICY_UNRESTRICTED,
			'xmlrpc_policy'            => Gate::POLICY_DISABLED,
			'wpgraphql_policy'         => Gate::POLICY_UNRESTRICTED,
			'app_password_policies'    => array(),
		);

		$this->update_wp_sudo_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();

		$this->assertSame( 5, Admin::get( 'session_duration' ) );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, Admin::get( 'rest_app_password_policy' ) );
		$this->assertSame( Gate::POLICY_DISABLED, Admin::get( 'cli_policy' ) );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, Admin::get( 'cron_policy' ) );
		$this->assertSame( Gate::POLICY_DISABLED, Admin::get( 'xmlrpc_policy' ) );
	}

	/**
	 * Test Admin::get() reads from DB after cache is cleared.
	 */
	public function test_settings_survive_cache_reset(): void {
		$settings = Admin::defaults();
		$settings['session_duration'] = 7;

		$this->update_wp_sudo_option( Admin::OPTION_KEY, $settings );

		// First read populates cache.
		Admin::reset_cache();
		$this->assertSame( 7, Admin::get( 'session_duration' ) );

		// Reset cache again — should re-read from DB and still return 7.
		Admin::reset_cache();
		$this->assertSame( 7, Admin::get( 'session_duration' ) );
	}

	// ── Sanitization ─────────────────────────────────────────────────

	/**
	 * Test sanitize_settings clamps session_duration to 1–15 range.
	 *
	 * @dataProvider data_session_duration_clamping
	 *
	 * @param mixed $input    Raw input value for session_duration.
	 * @param int   $expected Expected sanitized value.
	 */
	public function test_session_duration_clamped_on_save( $input, int $expected ): void {
		$raw = array( 'session_duration' => $input );

		$sanitized = $this->admin->sanitize_settings( $raw );

		$this->assertSame( $expected, $sanitized['session_duration'] );
	}

	/**
	 * Data provider for session duration clamping.
	 *
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public function data_session_duration_clamping(): array {
		return array(
			'zero clamps to 15'     => array( 0, 15 ),
			'negative clamps to 15' => array( -5, 15 ),
			'above max clamps'      => array( 20, 15 ),
			'valid value preserved' => array( 10, 10 ),
			'minimum preserved'     => array( 1, 1 ),
			'maximum preserved'     => array( 15, 15 ),
		);
	}

	/**
	 * Test sanitize_settings replaces invalid policy values with 'limited'.
	 */
	public function test_invalid_policy_falls_back_to_limited(): void {
		$raw = array(
			'session_duration'         => 10,
			'rest_app_password_policy' => 'bogus',
			'cli_policy'               => 'hacked',
			'cron_policy'              => '',
			'xmlrpc_policy'            => 'disabled', // Valid — should survive.
			'wpgraphql_policy'         => 42,
		);

		$sanitized = $this->admin->sanitize_settings( $raw );

		$this->assertSame( Gate::POLICY_LIMITED, $sanitized['rest_app_password_policy'] );
		$this->assertSame( Gate::POLICY_LIMITED, $sanitized['cli_policy'] );
		$this->assertSame( Gate::POLICY_LIMITED, $sanitized['cron_policy'] );
		$this->assertSame( Gate::POLICY_DISABLED, $sanitized['xmlrpc_policy'] );
		$this->assertSame( Gate::POLICY_LIMITED, $sanitized['wpgraphql_policy'] );
	}

	// ── App-password per-password policy overrides ───────────────────

	/**
	 * Test per-app-password policy overrides persist through sanitize/save/load.
	 */
	public function test_per_app_password_policy_override_persists(): void {
		$uuid = '550e8400-e29b-41d4-a716-446655440000';

		$raw = array(
			'session_duration'      => 10,
			'app_password_policies' => array(
				$uuid => Gate::POLICY_UNRESTRICTED,
			),
		);

		$sanitized = $this->admin->sanitize_settings( $raw );

		// Verify sanitized output contains the override.
		$this->assertArrayHasKey( 'app_password_policies', $sanitized );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, $sanitized['app_password_policies'][ $uuid ] );

		// Save and re-read from DB.
		$this->update_wp_sudo_option( Admin::OPTION_KEY, $sanitized );
		Admin::reset_cache();

		$stored = Admin::get( 'app_password_policies' );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, $stored[ $uuid ] );
	}

	// ── Multisite ────────────────────────────────────────────────────

	/**
	 * Test Admin::get() reads from site_option on multisite.
	 *
	 * @group multisite
	 */
	public function test_multisite_settings_stored_as_site_option(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$settings = Admin::defaults();
		$settings['session_duration'] = 3;

		update_site_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();

		$this->assertSame( 3, Admin::get( 'session_duration' ) );

		// Verify it is NOT in the per-site option.
		delete_option( Admin::OPTION_KEY );
		Admin::reset_cache();

		// Should still return 3 from site_option.
		$this->assertSame( 3, Admin::get( 'session_duration' ) );
	}

	/**
	 * Test handle_network_settings_save updates site option.
	 *
	 * @group multisite
	 */
	public function test_network_settings_save_handler_updates_site_option(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		// Become a super admin.
		$user = $this->make_admin();
		grant_super_admin( $user->ID );
		wp_set_current_user( $user->ID );

		// Simulate a network admin form POST.
		$_POST[ Admin::OPTION_KEY ] = array(
			'session_duration'         => 8,
			'rest_app_password_policy' => Gate::POLICY_DISABLED,
			'cli_policy'               => Gate::POLICY_UNRESTRICTED,
			'cron_policy'              => Gate::POLICY_LIMITED,
			'xmlrpc_policy'            => Gate::POLICY_LIMITED,
			'wpgraphql_policy'         => Gate::POLICY_LIMITED,
		);

		// Set the nonce the handler expects.
		$_REQUEST['_wpnonce'] = wp_create_nonce( Admin::PAGE_SLUG . '-options' );

		// Trap the redirect so the handler doesn't exit.
		add_filter(
			'wp_redirect',
			function ( $location ) {
				throw new \Exception( 'redirect:' . $location );
			}
		);

		try {
			$this->admin->handle_network_settings_save();
			$this->fail( 'Expected redirect exception.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'redirect:', $e->getMessage() );
		}

		Admin::reset_cache();
		$this->assertSame( 8, Admin::get( 'session_duration' ) );
		$this->assertSame( Gate::POLICY_DISABLED, Admin::get( 'rest_app_password_policy' ) );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, Admin::get( 'cli_policy' ) );
	}
}
