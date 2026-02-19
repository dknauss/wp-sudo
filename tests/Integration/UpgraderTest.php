<?php
/**
 * Integration tests for Upgrader — migration chain with real DB.
 *
 * @covers \WP_Sudo\Upgrader
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Admin;
use WP_Sudo\Gate;
use WP_Sudo\Upgrader;

class UpgraderTest extends TestCase {

	/**
	 * Defensively clean up in-memory state that DB rollback cannot revert.
	 *
	 * WP_Roles is a singleton loaded once into memory. remove_role() modifies
	 * both the DB and the singleton, but transaction rollback only restores the
	 * DB — the singleton retains whatever state tests left it in. We defensively
	 * clean up here so tests are isolated.
	 *
	 * Similarly, the editor role's capabilities are an in-memory array. If a
	 * test removed unfiltered_html, we restore it here.
	 */
	public function tear_down(): void {
		// Remove the site_manager role if it was added during the test.
		remove_role( 'site_manager' );

		// Restore editor's unfiltered_html capability if it was stripped.
		$editor = get_role( 'editor' );
		if ( $editor && empty( $editor->capabilities['unfiltered_html'] ) ) {
			$editor->add_cap( 'unfiltered_html' );
		}

		parent::tear_down();
	}

	/**
	 * SURF-01: Full migration chain from v1.9.0 — all 3 routines run.
	 *
	 * Verifies:
	 * - site_manager role is removed (2.0.0)
	 * - allowed_roles stripped from settings (2.0.0)
	 * - wp_sudo_role_version deleted (2.0.0)
	 * - editor unfiltered_html stripped (2.1.0)
	 * - old binary policies migrated to three-tier (2.2.0)
	 * - version stamp updated to WP_SUDO_VERSION
	 */
	public function test_full_migration_chain_from_v1(): void {
		// Arrange: simulate v1 state.
		update_option( Upgrader::VERSION_OPTION, '1.9.0' );
		add_role( 'site_manager', 'Site Manager', array( 'read' => true ) );
		update_option(
			Admin::OPTION_KEY,
			array(
				'session_duration'          => 15,
				'allowed_roles'             => array( 'administrator', 'site_manager' ),
				'rest_app_password_policy'  => 'block',
				'cli_policy'                => 'allow',
				'cron_policy'               => 'block',
				'xmlrpc_policy'             => 'allow',
			)
		);
		update_option( 'wp_sudo_role_version', '1.0.0' );

		// Add unfiltered_html to editor so 2.1.0 migration can strip it.
		$editor = get_role( 'editor' );
		$this->assertNotNull( $editor );
		$editor->add_cap( 'unfiltered_html' );

		Admin::reset_cache();

		// Act.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Assert: version updated.
		$this->assertSame( WP_SUDO_VERSION, get_option( Upgrader::VERSION_OPTION ) );

		// Assert: 2.0.0 — site_manager role removed.
		$this->assertNull( get_role( 'site_manager' ) );

		// Assert: 2.0.0 — allowed_roles stripped.
		$settings = get_option( Admin::OPTION_KEY );
		$this->assertArrayNotHasKey( 'allowed_roles', $settings );

		// Assert: 2.0.0 — role version option deleted.
		$this->assertFalse( get_option( 'wp_sudo_role_version' ) );

		// Assert: 2.1.0 — editor unfiltered_html removed.
		$editor = get_role( 'editor' );
		$this->assertEmpty(
			$editor->capabilities['unfiltered_html'] ?? false,
			'Editor should not have unfiltered_html after 2.1.0 migration.'
		);

		// Assert: 2.2.0 — policies migrated to three-tier.
		Admin::reset_cache();
		$this->assertSame( Gate::POLICY_LIMITED, Admin::get( 'rest_app_password_policy' ) );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, Admin::get( 'cli_policy' ) );
		$this->assertSame( Gate::POLICY_LIMITED, Admin::get( 'cron_policy' ) );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, Admin::get( 'xmlrpc_policy' ) );
	}

	/**
	 * SURF-01: Upgrade skipped when already at current version.
	 *
	 * Verifies that no migrations run and pre-existing state survives.
	 */
	public function test_upgrade_skipped_when_already_current(): void {
		// Arrange: version is already current, site_manager exists (should survive).
		update_option( Upgrader::VERSION_OPTION, WP_SUDO_VERSION );
		add_role( 'site_manager', 'Site Manager', array( 'read' => true ) );

		// Act.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Assert: site_manager role survives — 2.0.0 didn't run.
		$this->assertNotNull(
			get_role( 'site_manager' ),
			'site_manager role should survive when no upgrade runs.'
		);
	}

	/**
	 * SURF-01: Partial migration from v2.0.0 — only 2.1.0 and 2.2.0 run.
	 *
	 * Verifies:
	 * - 2.0.0 routine is skipped (site_manager survives if present).
	 * - editor unfiltered_html stripped (2.1.0).
	 * - policies migrated (2.2.0).
	 */
	public function test_partial_migration_from_v2_0_0(): void {
		// Arrange: already past 2.0.0.
		update_option( Upgrader::VERSION_OPTION, '2.0.0' );
		update_option(
			Admin::OPTION_KEY,
			array(
				'session_duration'          => 15,
				'rest_app_password_policy'  => 'block',
				'cli_policy'                => 'allow',
			)
		);

		// Add unfiltered_html to editor so 2.1.0 can strip it.
		$editor = get_role( 'editor' );
		$this->assertNotNull( $editor );
		$editor->add_cap( 'unfiltered_html' );

		Admin::reset_cache();

		// Act.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Assert: version updated.
		$this->assertSame( WP_SUDO_VERSION, get_option( Upgrader::VERSION_OPTION ) );

		// Assert: 2.1.0 ran — editor unfiltered_html stripped.
		$editor = get_role( 'editor' );
		$this->assertEmpty(
			$editor->capabilities['unfiltered_html'] ?? false,
			'Editor unfiltered_html should be stripped by 2.1.0 migration.'
		);

		// Assert: 2.2.0 ran — policies migrated.
		Admin::reset_cache();
		$this->assertSame( Gate::POLICY_LIMITED, Admin::get( 'rest_app_password_policy' ) );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, Admin::get( 'cli_policy' ) );
	}

	/**
	 * SURF-01: 2.2.0 preserves already-valid three-tier policy values.
	 *
	 * Verifies that 'disabled', 'limited', 'unrestricted' values survive
	 * the migration unchanged.
	 */
	public function test_upgrade_2_2_0_preserves_valid_policy_values(): void {
		// Arrange: version at 2.1.0, already-valid values.
		update_option( Upgrader::VERSION_OPTION, '2.1.0' );
		update_option(
			Admin::OPTION_KEY,
			array(
				'rest_app_password_policy' => Gate::POLICY_DISABLED,
				'cli_policy'               => Gate::POLICY_LIMITED,
				'cron_policy'              => Gate::POLICY_UNRESTRICTED,
				'xmlrpc_policy'            => Gate::POLICY_DISABLED,
			)
		);

		Admin::reset_cache();

		// Act.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Assert: values unchanged.
		Admin::reset_cache();
		$this->assertSame( Gate::POLICY_DISABLED, Admin::get( 'rest_app_password_policy' ) );
		$this->assertSame( Gate::POLICY_LIMITED, Admin::get( 'cli_policy' ) );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, Admin::get( 'cron_policy' ) );
		$this->assertSame( Gate::POLICY_DISABLED, Admin::get( 'xmlrpc_policy' ) );
	}
}
