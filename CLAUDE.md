# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WP Sudo is a WordPress plugin that provides action-gated reauthentication. Dangerous operations (plugin activation, user deletion, critical settings changes, etc.) require password confirmation before they proceed — regardless of user role.

**Requirements:** WordPress 6.2+, PHP 8.0+

## Commands

```bash
composer install          # Install dev dependencies
composer test             # Run all unit tests
composer lint             # Run PHPCS (WordPress-Extra + WordPress-Docs + WordPressVIPMinimum)
composer lint:fix         # Auto-fix PHPCS violations
composer analyse          # Run PHPStan level 6 (use --memory-limit=1G if needed)
composer sbom             # Regenerate CycloneDX SBOM (bom.json)
./vendor/bin/phpunit tests/Unit/SudoSessionTest.php   # Run a single test file
./vendor/bin/phpunit --filter testMethodName           # Run a single test method
```

No build step. No production dependencies — only dev dependencies (PHPUnit 9.6, Brain\Monkey, Mockery, VIP WPCS, PHPStan, CycloneDX).

## Commit Practices

- Always run tests before committing.
- Use conventional commit format.

## Architecture

**Entry point:** `wp-sudo.php` — defines constants, registers an SPL autoloader (maps `WP_Sudo\Class_Name` to `includes/class-class-name.php`), and wires lifecycle hooks. The `wp_sudo()` function returns the singleton Plugin instance.

**Bootstrap sequence:** `plugins_loaded` → `Plugin::init()` → loads translations, runs upgrader, registers gate, sets up challenge page, initializes admin UI.

### Core Classes (all in `includes/`, namespace `WP_Sudo`)

- **Plugin** — Orchestrator. Creates and owns the component instances. Handles activation/deactivation hooks. Strips `unfiltered_html` from editors on activation and restores it on deactivation.
- **Gate** — Multi-surface interceptor. Matches incoming requests against the Action Registry and gates them via reauthentication (admin UI), error response (AJAX/REST), or policy (CLI/Cron/XML-RPC/App Passwords).
- **Action_Registry** — Defines all gated rules (28 rules across 7 categories + multisite). Extensible via `wp_sudo_gated_actions` filter.
- **Challenge** — Interstitial reauthentication page. Handles password verification, 2FA integration, request stash/replay.
- **Sudo_Session** — Session management. Cryptographic token (user meta + httponly cookie), rate limiting (5 attempts → 5-min lockout), session binding.
- **Request_Stash** — Stashes and replays intercepted admin requests using transients.
- **Admin** — Settings page at Settings → Sudo. Settings: session duration (1–15 min), entry point policies (Block/Allow for REST/CLI/Cron/XML-RPC). Option key: `wp_sudo_settings`.
- **Admin_Bar** — Live countdown timer in admin bar during active sessions.
- **Site_Health** — WordPress Site Health integration (status tests and debug info).
- **Upgrader** — Version-aware migration runner. Runs sequential upgrade routines when the stored version is older than the plugin version.

### Capability Restriction

On single-site, WP Sudo removes the `unfiltered_html` capability from the Editor role on activation. This ensures KSES content filtering is always active for editors. Administrators retain the capability. The capability is restored on deactivation or uninstall. On multisite, WordPress core already restricts `unfiltered_html` to Super Admins.

As a tamper-detection canary, `Plugin::enforce_editor_unfiltered_html()` runs at `init` priority 1 on every request. If the capability reappears on the Editor role (e.g. via direct `wp_user_roles` database modification), it is stripped and the `wp_sudo_capability_tampered` action fires for audit logging.

### Audit Hooks

The plugin fires 9 action hooks for external logging: `wp_sudo_activated`, `wp_sudo_deactivated`, `wp_sudo_reauth_failed`, `wp_sudo_lockout`, `wp_sudo_action_gated`, `wp_sudo_action_blocked`, `wp_sudo_action_allowed`, `wp_sudo_action_replayed`, `wp_sudo_capability_tampered`.

## Testing

Tests use **Brain\Monkey** to mock WordPress functions/hooks without loading WordPress, plus **Mockery** for object mocking and **Patchwork** for redefining `setcookie` and `header` (configured in `patchwork.json`).

- `tests/bootstrap.php` — Defines WordPress constants and minimal class stubs (`WP_User`, `WP_Role`, `WP_Admin_Bar`).
- `tests/TestCase.php` — Base class with Brain\Monkey setup/teardown and `make_user()`/`make_role()` helpers.
- Test files live in `tests/Unit/` and follow the `*Test.php` naming convention.

PHPUnit strict mode is enabled: tests must assert something, produce no output, and not trigger warnings.

## Uninstall

`uninstall.php` handles multisite-safe cleanup: restores `unfiltered_html` to editors, removes the v1 Site Manager role (if present), deletes `wp_sudo_settings` option, and cleans user meta (`_wp_sudo_*` keys) across all sites in a network.
