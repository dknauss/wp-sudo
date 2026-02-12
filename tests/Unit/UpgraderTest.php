<?php
/**
 * Tests for WP_Sudo\Upgrader.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Upgrader;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

class UpgraderTest extends TestCase {

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
		Functions\expect( 'update_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, WP_SUDO_VERSION );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}
}
