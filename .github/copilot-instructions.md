# Copilot Instructions

## Project Overview

WP Sudo is a WordPress plugin that provides action-gated reauthentication. Dangerous operations (plugin activation, user deletion, critical settings changes, etc.) require password confirmation before they proceed — regardless of user role.

- **Requirements:** WordPress 6.2+, PHP 8.0+
- **Repository Type:** WordPress Plugin
- **Primary Language:** PHP, JavaScript
- **Frameworks:** WordPress, WordPress Block Editor (Gutenberg), @wordpress/scripts
- **Target Runtime:** WordPress 6.8+, PHP 8.1+

## Critical Build Instructions

### Environment Requirements

- **PHP:** 8.1 or higher (plugin requires PHP 8.1+)
- **Composer:** 2.8.12 or higher

### Dependency Versions

#### npm Packages
- **@wordpress/scripts:** 30.26.0 or higher

#### Composer Packages
- **wp-coding-standards/wpcs:** 3.0 or higher

### Dependency Installation

**ALWAYS install dependencies in this exact order before any build operations:**

1. **npm dependencies (REQUIRED FIRST):**
   ```bash
   npm install
   ```
   - Takes approximately 60 seconds on first install
   - May show deprecation warnings (these are non-critical)
   - May show 2 moderate severity vulnerabilities (these are from dev dependencies and are non-critical)
   - Creates `node_modules/` directory (ignored by git)

2. **Composer dependencies (for PHP linting only):**
   ```bash
   composer install —no-interaction
   ```
   - Takes approximately 30-60 seconds
   - May prompt for GitHub OAuth token if run interactively; use `—no-interaction` flag to avoid this
   - Falls back to cloning from git cache if GitHub API rate limits are hit
   - Creates `vendor/` directory (ignored by git)
   - Installs WordPress Coding Standards (WPCS) for PHP linting

#### Composer Commands

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

### Build Process

**ALWAYS run build after making JavaScript/CSS changes:**

```bash
npm run build
```
- Takes approximately 1-2 seconds
- Uses webpack via @wordpress/scripts
- Generates files in `build/` directory (ignored by git, but required for plugin to function)
- Creates `build/blocks-manifest.php` (automatically generated, do NOT edit manually)
- Creates minified JavaScript, CSS, and asset dependency files in `build/wp-sudo/`
- Build output includes RTL CSS variants automatically

**For development with hot reload:**
```bash
npm run start
```
- Watches for file changes and rebuilds automatically
- Generates unminified source maps for debugging
- Use Ctrl+C to stop the watch process

**To clean and rebuild:**
```bash
rm -rf build/
npm run build
```

### Linting and Code Quality

**JavaScript/JSX Linting:**
```bash
npm run lint:js
```
- Uses ESLint with WordPress coding standards
- Checks all `.js` files in `src/`
- Must pass with no errors before committing
- Some warnings are acceptable

**CSS/SCSS Linting:**
```bash
npm run lint:css
```
- Uses stylelint with WordPress standards
- Checks all `.scss` files in `src/`
- Must pass with no errors before committing

**Auto-formatting:**
```bash
npm run format
```
- Uses Prettier to auto-format JavaScript, JSON, CSS/SCSS
- **ALWAYS run this before linting if you get Prettier errors**
- Automatically fixes most lint issues related to formatting
- Safe to run on all files

**PHP Linting:**
```bash
./vendor/bin/phpcs wp-sudo.php
```
- Uses PHP_CodeSniffer with WordPress Coding Standards
- Available coding standards: WordPress, WordPress-Core, WordPress-Docs, WordPress-Extra
- The main plugin file may have known PHPCS warnings (tabs vs spaces, line length) - these may be acceptable per project style
- Auto-fix many PHP issues with: `./vendor/bin/phpcbf wp-sudo.php`

**NOTE:** This project uses **tabs for indentation** (not spaces) per WordPress coding standards, as specified in `.editorconfig`. The PHPCS errors about “spaces must be used” are using the wrong standard and can be ignored.

## Repository Structure

- `wp-sudo.php` — Plugin entry point, autoloader, lifecycle hooks.
- `includes/` — Core PHP classes (namespace `WP_Sudo`). Key classes: Plugin, Gate, Action_Registry, Challenge, Sudo_Session, Request_Stash, Admin, Admin_Bar, Site_Health, Upgrader.
- `admin/js/` — Vanilla JS for challenge page and admin bar timer. No build step.
- `admin/css/` — Stylesheets for challenge page and admin bar.
- `tests/Unit/` — PHPUnit tests using Brain\Monkey (no WordPress loaded).
- `bridges/` — Drop-in 2FA bridge files for third-party plugins.
- `ROADMAP.md` — Unified roadmap: integration tests, WP 7.0 prep, TDD strategy, core design features, feature backlog, accessibility appendix.
- `CHANGELOG.md` — Full version history.
- `FAQ.md` — All frequently asked questions.
- `docs/` — Documentation suite:
  - `security-model.md` — Threat model, boundaries, environmental considerations.
  - `developer-reference.md` — Hook signatures, filters, custom rule structure.
  - `ai-agentic-guidance.md` — AI and agentic tool integration guidance.
  - `two-factor-integration.md` — 2FA plugin integration guide.
  - `two-factor-ecosystem.md` — 2FA plugin ecosystem survey.
  - `ui-ux-testing-prompts.md` — Structured UI/UX testing prompts.
- `bom.json` — CycloneDX SBOM (regenerate with `composer sbom`).

## Architecture

**Bootstrap:** `plugins_loaded` → `Plugin::init()` → loads translations, runs upgrader, registers gate, sets up challenge page, initializes admin UI.

**Gate pattern:** Multi-surface interceptor matches incoming requests against the Action Registry (29 rules across 7 categories + 8 multisite rules). Admin requests get the stash-challenge-replay flow. AJAX/REST get error responses. CLI/Cron/XML-RPC follow per-surface policies (Disabled, Limited, Unrestricted).

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

### Plugin Distribution

**To create a distributable ZIP file:**
```bash
npm run plugin-zip
```
- Creates `wp-sudo.zip` in the repository root (ignored by git)
- Includes only necessary files: `wp-sudo.php`, `build/` directory contents
- Excludes: `src/`, `node_modules/`, `vendor/`, development files
- Takes approximately 1-2 seconds

## Project Architecture

### Directory Structure

### Build Output Structure

### Key Files

## WordPress Coding Guidelines

### Hook Registration

**CRITICAL:** Hook registration must ALWAYS come before the callback function definition.

This ensures that the code is clear and follows WordPress best practices. The hook registration tells WordPress what function to call, so it makes logical sense to define the hook first, then define the function it will call.

**Correct pattern:**
```php
add_action( ‘init’, ‘my_function’ );
function my_function() {
	// function content here
}
```

## Validation Workflow

**Before committing any code changes, ALWAYS run in this order:**

1. Format code: `npm run format`
2. Lint JavaScript: `npm run lint:js`
3. Lint CSS: `npm run lint:css`
4. Build: `npm run build`
5. Verify build output exists in `build/wp-sudo/`

**For PHP changes only:**
1. Format and lint as above (if any JS/CSS was touched)
2. Check PHP: `./vendor/bin/phpcs wp-sudo.php` (warnings acceptable)
3. Build: `npm run build`

## Common Issues and Solutions

**Issue:** `npm run build` fails with “Cannot find module”
- **Solution:** Run `npm install` first - dependencies not installed

**Issue:** Lint errors about Prettier formatting
- **Solution:** Run `npm run format` first, then lint again

**Issue:** `./vendor/bin/phpcs: No such file or directory`
- **Solution:** Run `composer install —no-interaction`

**Issue:** Composer hangs asking for GitHub token
- **Solution:** Use `composer install —no-interaction` or let it clone from cache (slower but works)

**Issue:** Build directory is empty after `npm run build`
- **Solution:** Check for errors in console; ensure `src/wp-sudo/` files exist

**Issue:** Plugin not working in WordPress after changes
- **Solution:** ALWAYS run `npm run build` after changing any file in `src/`

## Important Notes

- Never edit files in `build/` directly - they are auto-generated
- The plugin uses WordPress 6.8+ block registration API with blocks-manifest.php for improved performance
- All source files are in `src/wp-sudo/`, all build outputs go to `build/wp-sudo/`
- This project follows WordPress coding standards, which use TABS for indentation
- Do not generate additional files beyond what is required for the assigned task (e.g., summary or documentation files) unless explicitly requested

## Trust These Instructions

These instructions have been thoroughly tested and validated. Only perform additional searches or exploration if:
- The information here is incomplete for your specific task
- You encounter an error not documented in “Common Issues”
- You are adding new functionality not covered by existing patterns

For routine code changes, trust this documentation and avoid unnecessary exploration.
```
