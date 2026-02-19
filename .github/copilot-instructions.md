# Copilot Instructions

## Project Overview

WP Sudo is a WordPress plugin that provides action-gated reauthentication. Dangerous operations (plugin activation, user deletion, critical settings changes, etc.) require password confirmation before they proceed — regardless of user role.

**Requirements:** WordPress 6.2+, PHP 8.0+

## Commands

```bash
composer install          # Install dev dependencies
composer test             # Run all unit tests (PHPUnit 9.6)
composer lint             # Run PHPCS (WordPress-Extra + WordPress-Docs + WordPressVIPMinimum)
composer lint:fix         # Auto-fix PHPCS violations
composer analyse          # Run PHPStan level 6 (use --memory-limit=1G if needed)
composer sbom             # Regenerate CycloneDX SBOM (bom.json)
```

No build step. No npm. No production dependencies — only dev dependencies.

Always run `composer test` and `composer analyse` before committing.

## Repository Structure

- `wp-sudo.php` — Plugin entry point, autoloader, lifecycle hooks.
- `includes/` — Core PHP classes (namespace `WP_Sudo`). Key classes: Plugin, Gate, Action_Registry, Challenge, Sudo_Session, Request_Stash, Admin, Admin_Bar, Site_Health, Upgrader.
- `admin/js/` — Vanilla JS for challenge page and admin bar timer. No build step.
- `admin/css/` — Stylesheets for challenge page and admin bar.
- `tests/Unit/` — PHPUnit tests using Brain\Monkey (no WordPress loaded).
- `bridges/` — Drop-in 2FA bridge files for third-party plugins.
- `docs/` — Documentation suite:
  - `security-model.md` — Threat model, boundaries, environmental considerations.
  - `developer-reference.md` — Hook signatures, filters, custom rule structure.
  - `FAQ.md` — All frequently asked questions.
  - `CHANGELOG.md` — Full version history.
  - `ai-agentic-guidance.md` — AI and agentic tool integration guidance.
  - `two-factor-integration.md` — 2FA plugin integration guide.
  - `two-factor-ecosystem.md` — 2FA plugin ecosystem survey.
  - `ui-ux-testing-prompts.md` — Structured UI/UX testing prompts.
- `bom.json` — CycloneDX SBOM (regenerate with `composer sbom`).

## Architecture

**Bootstrap:** `plugins_loaded` → `Plugin::init()` → loads translations, runs upgrader, registers gate, sets up challenge page, initializes admin UI.

**Gate pattern:** Multi-surface interceptor matches incoming requests against the Action Registry (28 rules across 7 categories). Admin requests get the stash-challenge-replay flow. AJAX/REST get error responses. CLI/Cron/XML-RPC follow per-surface policies (Disabled, Limited, Unrestricted).

**Sessions:** Cryptographic token stored in user meta + httponly cookie. Progressive rate limiting (5 attempts → 5-min lockout).

## Coding Standards

- WordPress Coding Standards (WPCS) enforced via PHPCS.
- PHPStan level 6 with `szepeviktor/phpstan-wordpress`.
- Conventional commit messages.
- WCAG 2.1 AA accessibility throughout (ARIA labels, focus management, screen reader announcements).
- No inline `<script>` blocks — all JS is enqueued as external files (CSP-compatible).

## Testing

Tests use Brain\Monkey to mock WordPress functions/hooks without loading WordPress, plus Mockery for object mocking and Patchwork for redefining `setcookie` and `header`.

PHPUnit strict mode: tests must assert something, produce no output, and not trigger warnings.
