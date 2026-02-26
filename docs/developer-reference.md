# Developer Reference

## Gated Action Rule Structure

Use the `wp_sudo_gated_actions` filter to add custom rules. Each rule defines matching criteria for admin UI (`pagenow`, actions, HTTP method), AJAX (action names), and REST (route patterns, HTTP methods). Custom rules appear in the Gated Actions table on the settings page.

All rules — including custom rules — are automatically protected on non-interactive surfaces (WP-CLI, Cron, XML-RPC, Application Passwords) via the configurable policy settings, even if they don't define AJAX or REST criteria.

```php
add_filter( 'wp_sudo_gated_actions', function ( array $rules ): array {
    $rules[] = array(
        'id'       => 'custom.my_action',
        'label'    => 'My dangerous action',
        'category' => 'custom',
        'admin'    => array(
            'pagenow'  => 'admin.php',
            'actions'  => array( 'my_dangerous_action' ),
            'method'   => 'POST',
            'callback' => function (): bool {
                return some_extra_condition();
            },
        ),
        'ajax'     => array(
            'actions' => array( 'my_ajax_action' ),
        ),
        'rest'     => array(
            'route'   => '#^/my-namespace/v1/dangerous#',
            'methods' => array( 'POST', 'DELETE' ),
        ),
    );
    return $rules;
} );
```

## Audit Hook Signatures

Sudo fires 9 action hooks for external logging integration with [WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/), [Stream](https://wordpress.org/plugins/stream/), and similar plugins.

```php
// Session lifecycle.
do_action( 'wp_sudo_activated', int $user_id, int $expires, int $duration );
do_action( 'wp_sudo_deactivated', int $user_id );

// Authentication failures.
do_action( 'wp_sudo_reauth_failed', int $user_id, int $attempts );
do_action( 'wp_sudo_lockout', int $user_id, int $attempts );

// Action gating.
// $surface values: 'admin', 'ajax', 'rest_app_password', 'cli', 'cron', 'xmlrpc', 'wpgraphql'
do_action( 'wp_sudo_action_gated', int $user_id, string $rule_id, string $surface );
do_action( 'wp_sudo_action_blocked', int $user_id, string $rule_id, string $surface );
do_action( 'wp_sudo_action_allowed', int $user_id, string $rule_id, string $surface );
do_action( 'wp_sudo_action_replayed', int $user_id, string $rule_id );

// Tamper detection.
do_action( 'wp_sudo_capability_tampered', string $role, string $capability );
```

## Filters

| Filter | Description |
|---|---|
| `wp_sudo_gated_actions` | Add or modify gated action rules. |
| `wp_sudo_two_factor_window` | 2FA verification window in seconds (default: 300). |
| `wp_sudo_requires_two_factor` | Whether a user needs 2FA for sudo (for third-party 2FA plugins). |
| `wp_sudo_validate_two_factor` | Validate a 2FA code (for third-party 2FA plugins). |
| `wp_sudo_render_two_factor_fields` | Render 2FA input fields (for third-party 2FA plugins). |

## Testing

Two test environments are used deliberately — choose based on what you are testing:

**Unit tests** (`tests/Unit/`) use Brain\Monkey to mock all WordPress functions. Fast (~0.3s total). Run with `composer test:unit`. Use for: request matching logic, session state machine, policy enforcement, hook registration, settings sanitization.

**Integration tests** (`tests/Integration/`) load real WordPress against a MySQL database via `WP_UnitTestCase`. Run with `composer test:integration` (requires one-time setup — see [CONTRIBUTING.md](../CONTRIBUTING.md)). Use for: full reauth flows, real bcrypt verification, transient TTL and cookie behavior, REST and AJAX gating, Two Factor interaction, multisite session isolation, upgrader migrations.

When in doubt: if the test needs a real database, real crypto, or calls that cross class boundaries in production, write an integration test.

Static analysis: `composer analyse` runs PHPStan level 6 (use `--memory-limit=1G` if needed). Zero errors required.

Code style: `composer lint` (PHPCS, WordPress-Extra + WordPress-Docs + WordPressVIPMinimum rulesets). Auto-fix with `composer lint:fix`.

Manual testing: see [`tests/MANUAL-TESTING.md`](../tests/MANUAL-TESTING.md) for step-by-step verification procedures against a real WordPress environment.
