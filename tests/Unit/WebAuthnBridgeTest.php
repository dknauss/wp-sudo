<?php
/**
 * Tests for the WebAuthn gating bridge.
 *
 * Verifies that the bridge's wp_sudo_gated_actions filter callback
 * injects the correct AJAX rules when the WebAuthn Provider plugin
 * is active, and that the rules are skipped when it is not.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers bridges/wp-sudo-webauthn-bridge.php
 */
class WebAuthnBridgeTest extends TestCase {

	/**
	 * Load the bridge and capture the filter callback.
	 *
	 * @return callable The bridge's wp_sudo_gated_actions callback.
	 */
	private function load_bridge(): callable {
		$captured = null;

		Functions\when( 'add_filter' )->alias(
			function ( string $hook, callable $callback ) use ( &$captured ): bool {
				if ( 'wp_sudo_gated_actions' === $hook ) {
					$captured = $callback;
				}
				return true;
			}
		);

		require __DIR__ . '/../../bridges/wp-sudo-webauthn-bridge.php';

		$this->assertNotNull( $captured, 'Bridge did not register a wp_sudo_gated_actions callback.' );

		return $captured;
	}

	// -----------------------------------------------------------------
	// Class-existence guard — inactive (no WebAuthn Provider)
	// -----------------------------------------------------------------

	/**
	 * Test bridge returns rules unchanged when WebAuthn Provider is not active.
	 *
	 * This MUST run before any test that defines the dummy class,
	 * because class definitions persist across tests in the same process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bridge_skips_rules_when_webauthn_inactive(): void {
		// Bootstrap Brain\Monkey manually (separate process has no parent setUp).
		\Brain\Monkey\setUp();
		Functions\when( '__' )->returnArg();

		$captured = null;
		Functions\when( 'add_filter' )->alias(
			function ( string $hook, callable $callback ) use ( &$captured ): bool {
				if ( 'wp_sudo_gated_actions' === $hook ) {
					$captured = $callback;
				}
				return true;
			}
		);

		require __DIR__ . '/../../bridges/wp-sudo-webauthn-bridge.php';

		$this->assertNotNull( $captured );

		$existing = array(
			array( 'id' => 'existing.rule', 'label' => 'Existing', 'category' => 'test' ),
		);

		$result = $captured( $existing );

		$this->assertCount( 1, $result, 'Bridge should not add rules when WebAuthn Provider is inactive.' );
		$this->assertSame( 'existing.rule', $result[0]['id'] );

		\Brain\Monkey\tearDown();
	}

	// -----------------------------------------------------------------
	// Class-existence guard — active (WebAuthn Provider present)
	// -----------------------------------------------------------------

	/**
	 * Test bridge adds rules when WebAuthn Provider plugin is active.
	 */
	public function test_bridge_adds_rules_when_webauthn_active(): void {
		Functions\when( '__' )->returnArg();
		$this->ensure_webauthn_class_exists();

		$callback = $this->load_bridge();
		$rules    = $callback( array() );

		$ids = array_column( $rules, 'id' );
		$this->assertContains( 'auth.webauthn_register', $ids );
		$this->assertContains( 'auth.webauthn_delete', $ids );
	}

	// -----------------------------------------------------------------
	// Rule structure: auth.webauthn_register
	// -----------------------------------------------------------------

	/**
	 * Test webauthn_register rule has correct structure.
	 */
	public function test_register_rule_structure(): void {
		Functions\when( '__' )->returnArg();
		$this->ensure_webauthn_class_exists();

		$callback = $this->load_bridge();
		$rule     = $this->find_rule( $callback( array() ), 'auth.webauthn_register' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'users', $rule['category'] );
		$this->assertNull( $rule['admin'] );
		$this->assertNull( $rule['rest'] );
		$this->assertIsArray( $rule['ajax'] );
		$this->assertContains( 'webauthn_preregister', $rule['ajax']['actions'] );
		$this->assertContains( 'webauthn_register', $rule['ajax']['actions'] );
	}

	// -----------------------------------------------------------------
	// Rule structure: auth.webauthn_delete
	// -----------------------------------------------------------------

	/**
	 * Test webauthn_delete rule has correct structure.
	 */
	public function test_delete_rule_structure(): void {
		Functions\when( '__' )->returnArg();
		$this->ensure_webauthn_class_exists();

		$callback = $this->load_bridge();
		$rule     = $this->find_rule( $callback( array() ), 'auth.webauthn_delete' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'users', $rule['category'] );
		$this->assertNull( $rule['admin'] );
		$this->assertNull( $rule['rest'] );
		$this->assertIsArray( $rule['ajax'] );
		$this->assertContains( 'webauthn_delete_key', $rule['ajax']['actions'] );
	}

	// -----------------------------------------------------------------
	// Rename is NOT gated
	// -----------------------------------------------------------------

	/**
	 * Test rename action is NOT gated (not security-sensitive).
	 */
	public function test_rename_action_is_not_gated(): void {
		Functions\when( '__' )->returnArg();
		$this->ensure_webauthn_class_exists();

		$callback    = $this->load_bridge();
		$rules       = $callback( array() );
		$all_actions = array();

		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['ajax']['actions'] ) ) {
				$all_actions = array_merge( $all_actions, $rule['ajax']['actions'] );
			}
		}

		$this->assertNotContains( 'webauthn_rename_key', $all_actions );
	}

	// -----------------------------------------------------------------
	// Preserves existing rules
	// -----------------------------------------------------------------

	/**
	 * Test bridge appends rules without removing existing ones.
	 */
	public function test_bridge_preserves_existing_rules(): void {
		Functions\when( '__' )->returnArg();
		$this->ensure_webauthn_class_exists();

		$callback = $this->load_bridge();
		$existing = array(
			array( 'id' => 'plugin.activate', 'label' => 'Activate plugin', 'category' => 'plugins' ),
			array( 'id' => 'auth.app_password', 'label' => 'Create app password', 'category' => 'users' ),
		);

		$result = $callback( $existing );
		$ids    = array_column( $result, 'id' );

		$this->assertContains( 'plugin.activate', $ids );
		$this->assertContains( 'auth.app_password', $ids );
		$this->assertContains( 'auth.webauthn_register', $ids );
		$this->assertContains( 'auth.webauthn_delete', $ids );
		$this->assertCount( 4, $result );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Define the dummy WebAuthn class if not yet defined.
	 */
	private function ensure_webauthn_class_exists(): void {
		if ( ! class_exists( 'WildWolf\WordPress\TwoFactorWebAuthn\Plugin', false ) ) {
			// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
			eval( 'namespace WildWolf\WordPress\TwoFactorWebAuthn; class Plugin {}' );
		}
	}

	/**
	 * Find a rule by ID in a rules array.
	 *
	 * @param array<int, array<string, mixed>> $rules Rules array.
	 * @param string                           $id    Rule ID to find.
	 * @return array<string, mixed>|null
	 */
	private function find_rule( array $rules, string $id ): ?array {
		foreach ( $rules as $rule ) {
			if ( ( $rule['id'] ?? '' ) === $id ) {
				return $rule;
			}
		}
		return null;
	}
}
