<?php
/**
 * PHPUnit bootstrap file for WP Sudo tests.
 *
 * Defines WordPress constants and class stubs so plugin classes
 * can be loaded without a full WordPress environment.
 *
 * @package WP_Sudo\Tests
 */

// ── WordPress core constant (guards in every class file check this) ──
define( 'ABSPATH', '/tmp/fake-wordpress/' );

// ── Plugin constants (normally defined in wp-sudo.php) ───────────────
define( 'WP_SUDO_VERSION', '1.2.1' );
define( 'WP_SUDO_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WP_SUDO_PLUGIN_URL', 'https://example.com/wp-content/plugins/wp-sudo/' );
define( 'WP_SUDO_PLUGIN_BASENAME', 'wp-sudo/wp-sudo.php' );

// ── WordPress time constants ─────────────────────────────────────────
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );

// ── WordPress cookie constants ───────────────────────────────────────
define( 'ADMIN_COOKIE_PATH', '/wp-admin' );
define( 'COOKIE_DOMAIN', '' );

// ── Minimal WordPress class stubs ────────────────────────────────────
// Must be defined before the autoloader loads plugin classes that use
// these as type hints in method signatures.

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int    $ID        = 0;
		public array  $roles     = [];
		public array  $caps      = [];
		public string $user_pass = '';
		public array  $allcaps   = [];

		public function __construct( int $id = 0, array $roles = [] ) {
			$this->ID    = $id;
			$this->roles = $roles;
		}
	}
}

if ( ! class_exists( 'WP_Role' ) ) {
	class WP_Role {
		public string $name         = '';
		public array  $capabilities = [];
	}
}

if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	class WP_Admin_Bar {
		public function add_node( array $args ): void {}
	}
}

// ── Composer autoloader ──────────────────────────────────────────────
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
