# Coding Conventions

**Analysis Date:** 2026-02-19

## Naming Patterns

**Files:**
- Class files use `class-` prefix followed by snake_case class name: `class-plugin.php`, `class-sudo-session.php`, `class-request-stash.php`
- Test files use `*Test.php` suffix: `SudoSessionTest.php`, `GateTest.php`, `ChallengeTest.php`
- Namespace mapping: `WP_Sudo\Class_Name` maps to `includes/class-class-name.php` via SPL autoloader in `wp-sudo.php` (lines 39-56)

**Functions:**
- Class methods use snake_case: `register()`, `activate()`, `deactivate()`, `is_active()`, `time_remaining()`
- Public factory functions use snake_case: `wp_sudo()` returns the singleton Plugin instance
- Callback functions are written as static closures or method arrays passed to hooks
- Helper closures use arrow function syntax: `static fn($val) => abs((int) $val)` (see `AdminTest.php` line 85)

**Variables:**
- Instance variables use snake_case: `$session`, `$stash`, `$gate`, `$challenge`, `$admin_bar`
- Private properties are prefixed with nothing, only private modifier: `private Gate $gate = null`
- Loop/temp variables use short descriptive names: `$uid`, `$key`, `$user`, `$rules`, `$rule`

**Types:**
- Class constants use UPPER_SNAKE_CASE: `META_KEY`, `TOKEN_META_KEY`, `POLICY_LIMITED`, `AJAX_AUTH_ACTION`
- Type hints are explicit: `int`, `string`, `array<string, mixed>`, `?Gate`, nullable types use `?Type`
- Array types use generic syntax: `array<int, array<string, mixed>>` in docblocks, PSR-12 in code

## Code Style

**Formatting:**
- Tool: PHPCS with WordPress-Extra, WordPress-Docs, WordPressVIPMinimum rulesets (see `phpcs.xml.dist`)
- Indentation: tabs (WordPress standard)
- Line breaks: Unix (LF)
- Max line length: Enforced by WordPress-VIPMinimum sniff for security rules, otherwise flexible
- One blank line between class methods, two blank lines between major sections

**Linting:**
- Tool: PHPCS (WordPress rulesets) + PHPStan level 6 (see `phpstan.neon`)
- All production code must pass `composer lint` and `composer analyse` before commit
- PHPStan ignores: unreachable code after `wp_die()` (defensive coding, see `phpstan.neon` line 16), `Gate::$session` property-only-written (future extensibility)

**Code structure:**
- Sections within classes are marked with visual dividers: `// ─────────────────────────────────────` with descriptive headings
- Example: `// =================================================================` followed by `// constants` (see `SudoSessionTest.php` line 30)

## Import Organization

**Order:**
1. File guard: `if ( ! defined( 'ABSPATH' ) ) { exit; }`
2. Namespace declaration: `namespace WP_Sudo;`
3. No explicit `use` statements (no class imports in this codebase)
4. Comment block with purpose
5. Class definition

**Example from `class-plugin.php` (lines 14-27):**
```php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

namespace WP_Sudo;

/**
 * Class Plugin
 * ...
 */
class Plugin {
```

**Test imports (PSR-4 namespace):**
Tests use full namespace imports in docblock: `@covers \WP_Sudo\Gate` (see `GateTest.php` line 19)

## Error Handling

**Patterns:**
- Admin UI responses: Use `wp_die()` with HTTP status code as second argument: `wp_die( esc_html__( 'Message', 'wp-sudo' ), 403 )`
- AJAX responses: Use `wp_send_json_success()` and `wp_send_json_error()` with optional HTTP status: `wp_send_json_error( array( 'message' => ... ), 400 )`
- No explicit exceptions thrown (WordPress-style procedural error handling)
- Defensive coding: Code after `wp_die()` or `exit` is intentional (for clarity) and PHPStan ignores via config

**Example from `class-challenge.php` (lines 180, 341):**
```php
wp_die( esc_html__( 'You must be logged in.', 'wp-sudo' ), 403 );

wp_send_json_error( array( 'message' => __( 'Invalid request.', 'wp-sudo' ) ), 400 );
```

## Logging

**Framework:** Native WordPress actions used as event hooks, not logging framework

**Patterns:**
- Fire audit actions that allow external listeners to log: `do_action( 'wp_sudo_action_gated', $user_id, $action_id )`
- 9 audit hooks defined: `wp_sudo_activated`, `wp_sudo_deactivated`, `wp_sudo_reauth_failed`, `wp_sudo_lockout`, `wp_sudo_action_gated`, `wp_sudo_action_blocked`, `wp_sudo_action_allowed`, `wp_sudo_action_replayed`, `wp_sudo_capability_tampered`
- Use `error_log()` for debugging only (not production logging)

## Comments

**When to Comment:**
- Class docblocks required for every class: file-level `@package WP_Sudo`, class-level docstring with `@since` version
- Method docblocks required: describe purpose, parameters with `@param Type $name`, return with `@return Type`
- Section dividers in class files: `// ─────────────────────────────` followed by section name
- Inline comments for non-obvious logic only (e.g., "Unreachable code is intentional defensive coding")

**JSDoc/TSDoc:**
- Full PHPDoc format: `/**`, `*` prefix, closing `*/`
- Type annotations in docblocks: `@param array<string, mixed> $rules`
- Since version tracking: `@since 2.0.0` for v2 classes, `@since 1.0.0` for legacy classes
- Breaking changes noted in docblocks: `@since 2.0.0 Rewritten: removed Site_Manager_Role...` (see `class-plugin.php` line 24)

## Function Design

**Size:** Keep methods under 50 lines where possible; methods accessing class state should be focused helpers

**Parameters:**
- Type hints required for all parameters: `function register( Request_Stash $stash ): void`
- Use dependency injection in constructors rather than singletons within methods
- No more than 3-4 parameters; use array destructuring for config objects

**Return Values:**
- Type hints required: `: void`, `: bool`, `: array`, `: ?string` (nullable)
- Void methods preferred for hooks (side effects)
- Early returns to avoid deep nesting: check guards first, return early

**Example from `class-sudo-session.php` (lines 105-140):**
```php
public static function is_active( int $user_id ): bool {
	$expiry = (int) get_user_meta( $user_id, self::META_KEY, true );
	if ( $expiry <= time() ) {
		// Cleanup on expiry
		delete_user_meta( $user_id, self::META_KEY );
		self::clear_session( $user_id );
		return false;
	}
	// Validate token binding
	return self::token_bound( $user_id );
}
```

## Module Design

**Exports:**
- Classes are the primary export (autoloaded)
- Public constants define configuration: `Gate::POLICY_LIMITED`, `Sudo_Session::META_KEY`
- Factory function `wp_sudo()` returns singleton for convenience

**Barrel Files:**
- No barrel files (`index.php`) in this codebase
- Each class is independent; Plugin class orchestrates component wiring

**Cross-cutting concerns:**

**Multisite awareness:**
- Check `is_multisite()` before branching to multisite-specific code
- Use `get_site_option()` vs `get_option()` appropriately
- Network Admin hooks: `network_admin_menu`, `network_admin_edit_{action}`

**Capability handling:**
- Gate is role-agnostic: checks only `is_user_logged_in()`, not role
- WordPress's own capability checks still run after gate clears
- Editor role has `unfiltered_html` stripped on activation (see `class-plugin.php` enforcement)

**2FA integration:**
- Check `class_exists( 'Two_Factor_Core' )` before calling 2FA hooks
- Two Factor plugin detected via: `Two_Factor_Core::is_user_using_two_factor( $user_id )`
- Conditional integration: plugin works with or without Two Factor installed

---

*Convention analysis: 2026-02-19*
