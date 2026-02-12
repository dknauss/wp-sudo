# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WP Sudo is a WordPress plugin that provides safe, time-limited privilege escalation for designated users. It creates a "Site Manager" role (Editor + select admin capabilities) and lets eligible users temporarily gain full admin privileges after reauthentication.

**Requirements:** WordPress 6.2+, PHP 8.0+

## Commands

```bash
composer install          # Install dev dependencies
composer test             # Run all unit tests
composer lint             # Run PHPCS (WordPress-Extra + WordPress-Docs + WordPressVIPMinimum)
composer lint:fix         # Auto-fix PHPCS violations
./vendor/bin/phpunit tests/Unit/SudoSessionTest.php   # Run a single test file
./vendor/bin/phpunit --filter testMethodName           # Run a single test method
```

No build step. No production dependencies — only dev dependencies (PHPUnit 9.6, Brain\Monkey, Mockery, VIP WPCS).

## Commit Practices

- Always run tests before committing.
- Use conventional commit format.

## Architecture

**Entry point:** `wp-sudo.php` — defines constants, registers an SPL autoloader (maps `WP_Sudo\Class_Name` to `includes/class-class-name.php`), and wires lifecycle hooks. The `wp_sudo()` function returns the singleton Plugin instance.

**Bootstrap sequence:** `plugins_loaded` → `Plugin::init()` → loads translations, runs upgrader, registers role, sets up sudo session, initializes admin UI.

### Core Classes (all in `includes/`, namespace `WP_Sudo`)

- **Plugin** — Orchestrator. Creates and owns the four component instances. Handles activation/deactivation hooks.
- **Sudo_Session** — Core security logic. Manages reauthentication flow, session tokens (user meta + httponly cookies), rate limiting (5 attempts → 5-min lockout), and scoped escalation (admin panel only — blocks REST, XML-RPC, AJAX, Cron, CLI, App Passwords). The capability floor constant `MIN_CAPABILITY` (`edit_others_posts`) prevents low-privilege roles from being configured as eligible.
- **Site_Manager_Role** — Creates/syncs the `site_manager` role. Grants Editor caps plus `switch_themes`, `activate_plugins`, `list_users`, `update_*`, etc. Explicitly withholds `edit_users`, `promote_users`, `manage_options`, and `unfiltered_html` (only available during sudo).
- **Admin** — Settings page at Settings → Sudo. Two settings: session duration (1–15 min) and allowed roles. Option key: `wp_sudo_settings`.
- **Upgrader** — Version-aware migration runner (currently no migrations defined).

### Audit Hooks

The plugin fires actions for external logging: `wp_sudo_activated`, `wp_sudo_deactivated`, `wp_sudo_reauth_failed`, `wp_sudo_lockout`.

## Testing

Tests use **Brain\Monkey** to mock WordPress functions/hooks without loading WordPress, plus **Mockery** for object mocking and **Patchwork** for redefining `setcookie` and `header` (configured in `patchwork.json`).

- `tests/bootstrap.php` — Defines WordPress constants and minimal class stubs (`WP_User`, `WP_Role`, `WP_Admin_Bar`).
- `tests/TestCase.php` — Base class with Brain\Monkey setup/teardown and `make_user()`/`make_role()` helpers.
- Test files live in `tests/Unit/` and follow the `*Test.php` naming convention.

PHPUnit strict mode is enabled: tests must assert something, produce no output, and not trigger warnings.

## Uninstall

`uninstall.php` handles multisite-safe cleanup: removes the Site Manager role, deletes `wp_sudo_settings` option, and cleans user meta (`_wp_sudo_*` keys) across all sites in a network.
