<?php
/**
 * Integration tests for Request_Stash — real transient write/read/delete lifecycle.
 *
 * @covers \WP_Sudo\Request_Stash
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Request_Stash;

class RequestStashTest extends TestCase {

	/**
	 * INTG-04: save() stores a transient via real set_transient().
	 */
	public function test_save_stores_transient(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertSame( 16, strlen( $key ), 'Stash key should be 16 characters.' );

		// Verify via raw transient API.
		$raw = get_transient( Request_Stash::TRANSIENT_PREFIX . $key );
		$this->assertIsArray( $raw );
		$this->assertSame( $user->ID, $raw['user_id'] );
		$this->assertSame( 'plugin.activate', $raw['rule_id'] );
	}

	/**
	 * INTG-04: get() retrieves the stash for the correct user.
	 */
	public function test_get_retrieves_for_correct_user(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key  = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );
		$data = $stash->get( $key, $user->ID );

		$this->assertIsArray( $data );
		$this->assertSame( 'plugin.activate', $data['rule_id'] );
	}

	/**
	 * INTG-04: get() returns null for the wrong user.
	 */
	public function test_get_returns_null_for_wrong_user(): void {
		$stash  = new Request_Stash();
		$user_a = $this->make_admin();
		$user_b = $this->make_admin( 'other-password' );

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user_a->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertNull( $stash->get( $key, $user_b->ID ) );
	}

	/**
	 * INTG-04: delete() removes the transient.
	 */
	public function test_delete_removes_transient(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		// Transient exists before delete.
		$this->assertIsArray( get_transient( Request_Stash::TRANSIENT_PREFIX . $key ) );

		$stash->delete( $key );

		// Transient gone after delete.
		$this->assertFalse( get_transient( Request_Stash::TRANSIENT_PREFIX . $key ) );
	}

	/**
	 * INTG-04: exists() returns true then false after delete.
	 */
	public function test_exists_true_then_false_after_delete(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertTrue( $stash->exists( $key, $user->ID ) );

		$stash->delete( $key );

		$this->assertFalse( $stash->exists( $key, $user->ID ) );
	}

	/**
	 * INTG-04: Stash preserves the full request structure including all 8 fields.
	 */
	public function test_stash_preserves_request_structure(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request(
			'plugins.php',
			'activate',
			'POST',
			array( 'plugin' => 'hello.php' ),
			array( '_wpnonce' => 'abc123' )
		);

		$key  = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );
		$data = $stash->get( $key, $user->ID );

		$this->assertSame( $user->ID, $data['user_id'] );
		$this->assertSame( 'plugin.activate', $data['rule_id'] );
		$this->assertSame( 'Activate plugin', $data['label'] );
		$this->assertSame( 'POST', $data['method'] );
		$this->assertArrayHasKey( 'url', $data );
		$this->assertArrayHasKey( 'get', $data );
		$this->assertArrayHasKey( 'post', $data );
		$this->assertArrayHasKey( 'created', $data );
		$this->assertEqualsWithDelta( time(), $data['created'], 2, 'Created timestamp should be within 2 seconds.' );
	}

	/**
	 * INTG-04: $_POST data (including passwords) is preserved for replay.
	 */
	public function test_save_captures_post_data(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request(
			'users.php',
			'createuser',
			'POST',
			array(),
			array(
				'user_login' => 'newuser',
				'pass1'      => 'secret-password',
				'role'       => 'subscriber',
			)
		);

		$key  = $stash->save( $user->ID, array( 'id' => 'user.create', 'label' => 'Create user' ) );
		$data = $stash->get( $key, $user->ID );

		// POST data preserved for replay (passwords NOT sanitized — needed for replay).
		$this->assertSame( 'newuser', $data['post']['user_login'] );
		$this->assertSame( 'secret-password', $data['post']['pass1'] );
	}
}
