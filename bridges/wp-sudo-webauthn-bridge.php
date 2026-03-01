<?php
/**
 * WP Sudo ↔ Two Factor WebAuthn Provider Bridge
 *
 * Gates WebAuthn security key registration and deletion behind WP Sudo's
 * reauthentication. Without this bridge, an attacker with a hijacked
 * session could silently register their own security key for persistent
 * access — the same risk class as ungated Application Password creation.
 *
 * Drop this file into wp-content/mu-plugins/.
 *
 * Requirements:
 *   - WP Sudo 2.0+
 *   - Two Factor Provider for WebAuthn 2.0+ (by Volodymyr Kolesnykov)
 *
 * Gated actions:
 *   - webauthn_preregister / webauthn_register — start and complete key registration
 *   - webauthn_delete_key — delete a registered security key
 *
 * Not gated:
 *   - webauthn_rename_key — renaming a key is not security-sensitive
 *
 * @package    WP_Sudo_Bridges
 * @version    1.0.0
 * @license    GPL-2.0-or-later
 * @see        https://wordpress.org/plugins/two-factor-provider-webauthn/
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register gated actions for WebAuthn security key management.
 *
 * Uses the wp_sudo_gated_actions filter to inject AJAX rules that match
 * the Two Factor WebAuthn Provider's endpoints. Rules are only added when
 * the WebAuthn Provider plugin is active (class-existence guard).
 *
 * @see \WP_Sudo\Action_Registry::get_rules() for the filter definition.
 */
add_filter(
	'wp_sudo_gated_actions',
	static function ( array $rules ): array {
		// Only add rules when the WebAuthn Provider plugin is active.
		if ( ! class_exists( 'WildWolf\WordPress\TwoFactorWebAuthn\Plugin' ) ) {
			return $rules;
		}

		// Gate security key registration (two-step AJAX ceremony).
		$rules[] = array(
			'id'       => 'auth.webauthn_register',
			'label'    => __( 'Register security key (WebAuthn)', 'wp-sudo' ),
			'category' => 'users',
			'admin'    => null,
			'ajax'     => array(
				'actions' => array( 'webauthn_preregister', 'webauthn_register' ),
			),
			'rest'     => null,
		);

		// Gate security key deletion.
		$rules[] = array(
			'id'       => 'auth.webauthn_delete',
			'label'    => __( 'Delete security key (WebAuthn)', 'wp-sudo' ),
			'category' => 'users',
			'admin'    => null,
			'ajax'     => array(
				'actions' => array( 'webauthn_delete_key' ),
			),
			'rest'     => null,
		);

		return $rules;
	}
);
