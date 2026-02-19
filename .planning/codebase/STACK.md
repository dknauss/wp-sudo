# Technology Stack

**Analysis Date:** 2026-02-19

## Languages

**Primary:**
- PHP 8.0+ - All plugin code, hooks, and WordPress integration

**Secondary:**
- JavaScript (ES6) - Frontend UI, admin bar countdown timer, challenge page interactivity
- CSS - Admin UI styling, countdown timer visual feedback

## Runtime

**Environment:**
- WordPress 6.2+ - Core platform
- PHP 8.0+ (minimum), 8.3+ recommended

**Package Manager:**
- Composer - PHP dependency management
- Lockfile: `composer.lock` present

## Frameworks

**Core:**
- WordPress hooks API - Action and filter system for all extension points
- WordPress REST API - REST request interception via `rest_request_before_callbacks`
- WordPress options API - Settings persistence via `wp_sudo_settings` option

**Testing:**
- PHPUnit 9.6 - Unit test runner
- Brain\Monkey 2.7 - WordPress function/hook mocking without loading WordPress
- Mockery 1.6 - Object mocking for isolated testing
- Patchwork 2.2.3 - Internal function redefinition (`setcookie`, `header`, `hash_equals`)

**Development/Analysis:**
- PHPStan 2.0 (level 6) - Static analysis and type checking
- PHPCS with VIP WPCS 3.0 - Code style enforcement (WordPress-Extra, WordPress-Docs, WordPressVIPMinimum)

**Security/Deployment:**
- CycloneDX 6.2 - Software Bill of Materials (SBOM) generation in JSON format

## Key Dependencies

**Production:**
- None. Zero production dependencies. Plugin is self-contained.

**Development Only (all in composer.json require-dev):**
- `automattic/vipwpcs` 3.0 - WordPress.org VIP coding standards
- `brain/monkey` 2.7 - Mock WordPress functions and hooks in unit tests
- `mockery/mockery` 1.6 - Create mock objects for testing
- `phpstan/phpstan` 2.0 - Static type analysis at level 6
- `phpunit/phpunit` 9.6 - Test runner with strict mode enabled
- `szepeviktor/phpstan-wordpress` 2.0 - PHPStan WordPress extension
- `cyclonedx/cyclonedx-php-composer` 6.2 - SBOM generation from composer dependencies

## Configuration

**Environment:**
- WordPress constants and configuration via `wp-config.php` (typical WordPress setup)
- Plugin activation hook wired via `register_activation_hook()`
- Plugin deactivation hook wired via `register_deactivation_hook()`
- No `.env` file used; all configuration is WordPress option-based

**Build/Development:**
- `phpunit.xml.dist` - PHPUnit strict mode enabled; tests bootstrap via `tests/bootstrap.php`
- `phpstan.neon` - PHPStan configuration with WordPress extension, level 6 analysis
- `phpcs.xml.dist` - PHPCS ruleset enforcing WordPress-Extra + Docs + VIP standards
- `patchwork.json` - Patchwork redefinable internals: `setcookie`, `header`, `hash_equals`
- `composer.json` - Scripts: `lint`, `lint:fix`, `test`, `analyse`, `sbom`

**Platform Settings:**
- `config.platform.php` set to 8.1.99 in composer.json for consistent lockfile resolution across PHP versions

## Platform Requirements

**Development:**
- PHP 8.0+ (tested via PHPCS `testVersion`)
- WordPress 6.2+ (tested via PHPCS `minimum_wp_version`)
- Composer for dependency management
- Optional: mu-plugin installation for early hook registration

**Production:**
- WordPress 6.2+
- PHP 8.0+ (8.3+ recommended)
- No external services or APIs required
- No database schema modifications (uses WordPress options and user meta only)

---

*Stack analysis: 2026-02-19*
