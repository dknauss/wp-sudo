<?php
/**
 * Base integration test case for WP Sudo.
 *
 * Extends WP_UnitTestCase for real database + WordPress environment.
 * Each test runs in a database transaction that is rolled back in tear_down().
 *
 * Do NOT use Brain\Monkey here. Do NOT call Monkey\setUp().
 * Integration tests use real WordPress functions, not mocks.
 *
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

/**
 * Base class for WP Sudo integration tests.
 */
class TestCase extends \WP_UnitTestCase {

	/**
	 * Create an administrator user with a real bcrypt-hashed password in the database.
	 *
	 * Uses the factory so the created user is auto-cleaned up in tear_down().
	 * wp_hash_password() uses cost=5 in test environments (WP_UnitTestCase default).
	 *
	 * @param string $password Plain-text password for verification tests.
	 * @return \WP_User
	 */
	protected function make_admin( string $password = 'test-password' ): \WP_User {
		$user_id = self::factory()->user->create(
			array(
				'role'      => 'administrator',
				'user_pass' => $password,
			)
		);
		return get_user_by( 'id', $user_id );
	}

	/**
	 * Trigger the plugin's activation hook explicitly.
	 *
	 * The plugin is loaded via muplugins_loaded in the bootstrap, which does not
	 * fire the activation hook. Tests that verify activation side effects
	 * (unfiltered_html removal, option creation) must call this method.
	 */
	protected function activate_plugin(): void {
		do_action( 'activate_wp-sudo/wp-sudo.php' );
	}
}
