<?php
/**
 * Version-aware upgrade routines.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Upgrader
 *
 * Runs sequential, one-time upgrade routines when the plugin version changes.
 *
 * Each routine targets a specific version and runs exactly once. The stored
 * version number is updated after all applicable routines have executed so
 * that a failed mid-sequence routine will be retried on the next page load.
 *
 * Role capability syncing is handled separately by Site_Manager_Role::maybe_sync_capabilities()
 * because it needs to run on every version bump regardless of whether there is
 * a data migration. This class handles everything else: option renames, schema
 * changes, one-time data fixes, etc.
 *
 * HOW TO ADD A NEW UPGRADE
 * ────────────────────────
 * 1. Add a private method named `upgrade_X_Y_Z()` where X.Y.Z is the version
 *    that introduces the change.
 * 2. Add the version string to the UPGRADES array, mapping it to the method name.
 * 3. The method will run exactly once for sites upgrading from an older version.
 *
 * Example:
 *
 *     private const UPGRADES = [
 *         '1.3.0' => 'upgrade_1_3_0',
 *         '2.0.0' => 'upgrade_2_0_0',
 *     ];
 *
 *     private function upgrade_1_3_0(): void {
 *         // Rename an option, migrate data, etc.
 *     }
 */
class Upgrader {

	/**
	 * Option key for the stored database/schema version.
	 *
	 * @var string
	 */
	public const VERSION_OPTION = 'wp_sudo_db_version';

	/**
	 * Ordered map of version → method name.
	 *
	 * Versions MUST be listed in ascending order. Each method runs once when
	 * upgrading from a version older than the key.
	 *
	 * @var array<string, string>
	 */
	private const UPGRADES = array(
		// No migrations yet. Add entries here as needed.
		// Example: '1.3.0' => 'upgrade_1_3_0'.
	);

	/**
	 * Run any pending upgrade routines.
	 *
	 * Compares the stored version against WP_SUDO_VERSION and sequentially
	 * executes every routine whose version is greater than the stored value.
	 *
	 * Safe to call on every request — returns immediately when no upgrade
	 * is needed (single option read, no writes).
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$stored = get_option( self::VERSION_OPTION, '0.0.0' );

		// Nothing to do if already current.
		if ( version_compare( $stored, WP_SUDO_VERSION, '>=' ) ) {
			return;
		}

		// Run each applicable routine in order.
		foreach ( self::UPGRADES as $version => $method ) {
			if ( version_compare( $stored, $version, '<' ) && is_callable( array( $this, $method ) ) ) {
				$this->{$method}();
			}
		}

		// Mark as current.
		update_option( self::VERSION_OPTION, WP_SUDO_VERSION );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Upgrade routines — add new private methods below, one per version.
	// See the class docblock above for a full example.
	// ─────────────────────────────────────────────────────────────────────
}
