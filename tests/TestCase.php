<?php
/**
 * Base test case for WP Sudo unit tests.
 *
 * Sets up and tears down Brain\Monkey for WordPress function mocking.
 *
 * @package WP_Sudo\Tests
 */

namespace WP_Sudo\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Default stubs for sanitization functions used throughout the plugin.
		// Individual tests can override these with specific expectations.
		Functions\stubs(
			array(
				'wp_unslash'          => static function ( $value ) {
					return $value;
				},
				'sanitize_text_field' => static function ( $str ) {
					return (string) $str;
				},
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Create a fake WP_User for testing.
	 *
	 * @param int      $id    User ID.
	 * @param string[] $roles User roles.
	 * @return \WP_User
	 */
	protected function make_user( int $id, array $roles = [ 'editor' ] ): \WP_User {
		$user = new \WP_User( $id, $roles );
		return $user;
	}

	/**
	 * Create a fake role object for testing.
	 *
	 * @param string             $name         Role name.
	 * @param array<string,bool> $capabilities Capabilities.
	 * @return \stdClass
	 */
	protected function make_role( string $name, array $capabilities = [] ): \stdClass {
		$role               = new \stdClass();
		$role->name         = $name;
		$role->capabilities = $capabilities;
		return $role;
	}
}
