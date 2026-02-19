# Stack Research

**Domain:** WordPress plugin integration testing — WP_UnitTestCase alongside Brain\Monkey unit tests
**Project:** WP Sudo v2.3.2 — adding integration tests + WP 7.0 compatibility verification
**Researched:** 2026-02-19
**Confidence:** HIGH (all critical claims verified against official WordPress core source, phpunit-polyfills GitHub, PHPUnit release history)

---

## Context

WP Sudo already has a working unit test stack:
- PHPUnit 9.6 (locked)
- Brain\Monkey 2.7 (function/hook mocking, no WordPress loaded)
- Mockery 1.6 (object mocking)
- Patchwork (redefines `setcookie`/`header`)
- ~220 tests in `tests/Unit/`

This research covers **only what must be added** to support `WP_UnitTestCase`-based integration tests. Do not replace the existing unit test stack.

---

## Recommended Stack

### Core Integration Test Infrastructure

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| WordPress core test suite | trunk or tagged (fetched at CI time) | Provides `WP_UnitTestCase`, `WP_UnitTest_Factory`, transaction-based DB cleanup, spy REST server | The official test harness. `WP_UnitTestCase` is not on Packagist — it is fetched from `develop.svn.wordpress.org` or via `@wordpress/env`. No alternative provides the same depth of WordPress integration. |
| `yoast/phpunit-polyfills` | `^2.0` | Bridges the `WP_UnitTestCase` inheritance chain to PHPUnit 9.6 | WordPress core bootstrap explicitly declares it a requirement (`"The PHPUnit Polyfills are a requirement for the WP test suite."`). It loads `Yoast\PHPUnitPolyfills\Autoload` and enforces a minimum version of 1.1.0. Use `^2.0` because the 2.x series supports PHPUnit 5.7–10.x, which covers PHPUnit 9.6 cleanly. The 1.x series also works but is the older branch. |
| `install-wp-tests.sh` (from `wp-cli/scaffold-command`) | Current from scaffold-command main | Downloads WordPress test suite via SVN from `develop.svn.wordpress.org` and creates test database | Standard, battle-tested setup script used across the WordPress plugin ecosystem. Generates `wp-tests-config.php` from parameters. Runs at CI setup time, not as a Composer dependency. |

### Coexistence: Two Separate Bootstrap Files

PHPUnit 9.6 supports a single global bootstrap per configuration, but **two test suites can each declare their own bootstrap** via the `<testsuites>` structure or separate `phpunit.xml` files. The integration suite needs its own bootstrap because it loads real WordPress; the unit suite's bootstrap (`tests/bootstrap.php`) explicitly does not.

**Use two separate phpunit configuration files:**

| Config file | Suite | Bootstrap | Database required |
|-------------|-------|-----------|------------------|
| `phpunit.xml.dist` (existing) | Unit (`tests/Unit/`) | `tests/bootstrap.php` (existing, no WordPress) | No |
| `phpunit-integration.xml.dist` (new) | Integration (`tests/Integration/`) | `tests/integration-bootstrap.php` (new, loads WordPress) | Yes — MySQL via GitHub Actions service |

This is the **only architecture that cleanly separates** the Brain\Monkey fake-WordPress bootstrap from the real-WordPress integration bootstrap without risk of class redefinition conflicts.

### GitHub Actions: MySQL Service Container

| Service | Version | Why |
|---------|---------|-----|
| MySQL | 8.0 | WordPress 6.2+ requires MySQL 5.7+. MySQL 8.0 is the current standard used by managed hosts and WordPress core CI. MariaDB 10.6 is an equivalent alternative if the host mirrors it. |

GitHub Actions service container pattern (Ubuntu 24.04 runner):

```yaml
services:
  mysql:
    image: mysql:8.0
    env:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wordpress_test
    options: >-
      --health-cmd="mysqladmin ping"
      --health-interval=10s
      --health-timeout=5s
      --health-retries=5
    ports:
      - 3306:3306
```

No Docker Compose or `@wordpress/env` is needed for a pure-PHP plugin. The MySQL service container + `install-wp-tests.sh` is simpler and faster than `@wordpress/env` for plugins without JavaScript block development.

### Supporting Libraries (already in require-dev, no new additions needed)

| Library | Already present | Role in integration tests |
|---------|----------------|--------------------------|
| PHPUnit 9.6 | Yes (`^9.6`) | Test runner — same binary, different suite |
| Mockery 1.6 | Yes | Can still be used in integration tests for partial mocks; less needed when WordPress is real |

**No new Composer `require-dev` packages beyond `yoast/phpunit-polyfills ^2.0` are required.** Brain\Monkey is not used in integration tests (the whole point is that WordPress functions are real), but it does not conflict — it is simply not initialized in the integration bootstrap.

---

## Installation

```bash
# Only addition to composer.json require-dev
composer require --dev yoast/phpunit-polyfills:"^2.0"
```

```bash
# Scaffold integration test infrastructure (run once locally, outputs bin/install-wp-tests.sh)
wp scaffold plugin-tests --ci=github-actions
# Or manually copy install-wp-tests.sh from:
# https://github.com/wp-cli/scaffold-command/blob/main/templates/install-wp-tests.sh
```

```bash
# Run integration tests (requires WordPress test suite installed locally)
DB_NAME=wordpress_test DB_USER=root DB_PASS=root bash bin/install-wp-tests.sh wordpress_test root root localhost trunk
./vendor/bin/phpunit -c phpunit-integration.xml.dist
```

```bash
# Run unit tests (unchanged)
composer test
# or
./vendor/bin/phpunit -c phpunit.xml.dist
```

---

## Configuration Files

### `phpunit-integration.xml.dist` (new)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
    bootstrap="tests/integration-bootstrap.php"
    colors="true"
    beStrictAboutTestsThatDoNotTestAnything="true"
    beStrictAboutOutputDuringTests="false"
    failOnWarning="false"
>
    <testsuites>
        <testsuite name="Integration">
            <directory suffix="Test.php">tests/Integration</directory>
        </testsuite>
    </testsuites>

    <coverage>
        <include>
            <directory suffix=".php">includes</directory>
        </include>
    </coverage>
</phpunit>
```

Note: `beStrictAboutOutputDuringTests="false"` and `failOnWarning="false"` are needed because WordPress itself emits output and deprecation notices during bootstrap. The unit suite keeps strict mode because it never loads WordPress.

### `tests/integration-bootstrap.php` (new)

```php
<?php
/**
 * Integration test bootstrap.
 *
 * Loads the real WordPress test suite so WP_UnitTestCase is available.
 * Requires: install-wp-tests.sh to have been run first.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "$_tests_dir/includes/functions.php" ) ) {
    echo "ERROR: WordPress test suite not found at $_tests_dir.\n";
    echo "Run: bash bin/install-wp-tests.sh wordpress_test root root localhost trunk\n";
    exit( 1 );
}

// Register plugin activation before WordPress loads.
function _manually_load_plugin() {
    require dirname( dirname( __FILE__ ) ) . '/wp-sudo.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require "$_tests_dir/includes/bootstrap.php";

// phpunit-polyfills is required by the WP bootstrap above; Composer autoload handles it.
```

### GitHub Actions workflow step (new job or existing job extension)

```yaml
test-integration:
  name: Integration Tests (PHP ${{ matrix.php }}, WP ${{ matrix.wp }})
  runs-on: ubuntu-24.04

  strategy:
    matrix:
      php: ['8.1', '8.2', '8.3']
      wp: ['latest', 'trunk']

  services:
    mysql:
      image: mysql:8.0
      env:
        MYSQL_ROOT_PASSWORD: root
        MYSQL_DATABASE: wordpress_test
      options: >-
        --health-cmd="mysqladmin ping"
        --health-interval=10s
        --health-timeout=5s
        --health-retries=5
      ports:
        - 3306:3306

  steps:
    - name: Checkout
      uses: actions/checkout@v4

    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: ${{ matrix.php }}
        tools: composer:v2, svn
        extensions: mysqli
        coverage: none

    - name: Install Composer dependencies
      run: composer install --no-interaction --prefer-dist

    - name: Install WordPress test suite
      run: bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 ${{ matrix.wp }}

    - name: Run integration tests
      run: ./vendor/bin/phpunit -c phpunit-integration.xml.dist
      env:
        WP_TESTS_DIR: /tmp/wordpress-tests-lib
```

---

## Alternatives Considered

| Category | Recommended | Alternative | Why Not |
|----------|-------------|-------------|---------|
| WordPress test harness delivery | `install-wp-tests.sh` + SVN | `@wordpress/env` (wp-env) | wp-env requires Docker + Node.js. WP Sudo has no JavaScript, no block editor code. Docker overhead adds 60–90 seconds to CI. The SVN approach is standard for pure-PHP plugins and runs in 15–20 seconds. |
| Database for CI | GitHub Actions MySQL service container | SQLite (via SQLite Database Integration plugin) | SQLite integration is experimental for WordPress and not representative of production. `wp_check_password()` behavior is identical, but transient TTL behavior and some `$wpdb->query()` edge cases differ. MySQL is what the plugin will run against in production. |
| PHPUnit version upgrade | Stay on PHPUnit 9.6 | Upgrade to PHPUnit 11.x | PHPUnit 11 requires PHP 8.2+. WP Sudo supports PHP 8.0+. More importantly, WordPress core itself uses PHPUnit 9.x schema (`phpunit.xsd` at schema version 9.2 as of Feb 2026). Upgrading PHPUnit would require rewriting ~220 unit tests. No benefit justifies this. |
| phpunit-polyfills version | `^2.0` | `^1.1` | WordPress core requires minimum 1.1.0. Both 1.x and 2.x work. Use 2.x because it will support PHPUnit 10.x when the project eventually upgrades. WordPress core uses `^1.1.0` for backward compatibility with older installations; a plugin targeting PHP 8.0+ can use 2.x freely. |
| Integration test framework | `WP_UnitTestCase` (core harness) | `wp-browser` (Codeception-based) | wp-browser is a good tool for E2E + integration in one framework. WP Sudo's need is narrow: real `$wpdb`, real transients, real `wp_check_password()`. `WP_UnitTestCase` covers exactly this. Adding Codeception would add a new test paradigm alongside the existing PHPUnit unit tests, complicating the suite for no material gain. |
| Multisite testing | Add `WP_TESTS_MULTISITE=1` variant to matrix | Separate phpunit-multisite.xml.dist | A CI matrix variable is simpler. `WP_UnitTestCase` reads the `WP_TESTS_MULTISITE` env var; no separate config file needed. |

---

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| Brain\Monkey in integration tests | Brain\Monkey replaces WordPress function stubs with Mockery expectations. If Brain\Monkey is initialized alongside a real WordPress install, WordPress functions get double-defined and PHP throws fatal errors. Never call `Brain\Monkey\setUp()` in integration test `set_up()`. | Real WordPress functions — that's the entire point of integration tests. |
| Patchwork in integration tests | Patchwork patches are applied globally. Redefining `setcookie` or `header` while WordPress is bootstrapped can corrupt session and redirect behavior under test. | Assert on side effects after the fact (e.g., check `headers_list()`, check meta values). For cookie testing, use `WP_UnitTestCase`'s `tearDown` + direct `$_COOKIE` manipulation. |
| `WP_UnitTestCase` in `tests/Unit/` (or vice versa) | Mixing the two base classes in the same suite causes the Brain\Monkey bootstrap to conflict with the WordPress bootstrap. | Keep `tests/Unit/` → extends `WP_Sudo\Tests\TestCase` (Brain\Monkey base). Keep `tests/Integration/` → extends `WP_UnitTestCase`. |
| `--no-interaction` flag omission in CI | Without it, Composer prompts for plugin allow-list confirmation on fresh runs. | Always use `composer install --no-interaction` in CI. |
| Hardcoding `127.0.0.1` as DB host locally | GitHub Actions MySQL service containers bind to `127.0.0.1` (not `localhost`). `localhost` causes MySQL to attempt a Unix socket connection, which fails in CI. | Use `127.0.0.1` for the CI `install-wp-tests.sh` call. Use `localhost` only for local development where a socket is available. |
| PHP 7.x in integration test matrix | WP Sudo requires PHP 8.0+. Testing on 7.x wastes CI minutes and tests code paths that can't exist in production. | Matrix against PHP 8.1, 8.2, 8.3 (8.0 is EOL; 8.4 optional stretch goal). |

---

## Version Compatibility

| Package | Version in use | Compatible with | Notes |
|---------|---------------|----------------|-------|
| `phpunit/phpunit` | `^9.6` (9.6.34 latest, still maintained) | PHP 7.3–8.5 | Do not upgrade to 10 or 11 — WordPress core test harness targets 9.x schema. |
| `yoast/phpunit-polyfills` | `^2.0` (2.0.5 latest as of Aug 2025) | PHPUnit 5.7–10.x | WordPress core bootstrap enforces minimum 1.1.0. 2.x satisfies this. |
| WordPress test suite | trunk or version-tagged | WordPress 6.2–7.0 | Use `trunk` for nightly/7.0 testing. Use `latest` for stable 6.9 testing. |
| MySQL service | 8.0 | WordPress 6.2–7.0 | WordPress 7.0 still supports MySQL 5.7+. Use 8.0 because it is what most managed hosts have standardized on. |
| `shivammathur/setup-php` | `@v2` | All major PHP 8.x | Current version; `v2` is a floating tag tracking latest stable. Verify `svn` is in `tools:` — it is needed by `install-wp-tests.sh`. |

---

## Stack Patterns by Variant

**If testing against WP 7.0 nightly (WP_ENV_CORE = trunk):**
- Set `wp` matrix value to `trunk` in `install-wp-tests.sh` call
- Pin a separate CI job that fails non-blocking (`continue-on-error: true`) so trunk regressions surface without blocking releases
- `install-wp-tests.sh wordpress_test root root 127.0.0.1 trunk`

**If testing multisite session isolation:**
- Add `WP_TESTS_MULTISITE=1` to the step's `env:` block
- No separate configuration file needed — `WP_UnitTestCase` reads this env var automatically
- Use the same `phpunit-integration.xml.dist`

**If testing with the real Two Factor plugin present:**
- Add a step before `install-wp-tests.sh` to download the Two Factor plugin into the test WordPress install's plugins directory
- Use `tests_add_filter( 'muplugins_loaded', ... )` in the integration bootstrap to activate it alongside WP Sudo

---

## Sources

- **WordPress core test bootstrap** — `https://github.com/WordPress/wordpress-develop/blob/trunk/tests/phpunit/includes/bootstrap.php` — Verified: phpunit-polyfills is a mandatory requirement; minimum version 1.1.0 enforced; loads `Yoast\PHPUnitPolyfills\Autoload`. (HIGH confidence)
- **WordPress core phpunit-adapter-testcase** — `https://github.com/WordPress/wordpress-develop/blob/trunk/tests/phpunit/includes/phpunit-adapter-testcase.php` — Verified: extends `Yoast\PHPUnitPolyfills\TestCase`; bridges PHPUnit 4.8–9.6 to WordPress abstractions. (HIGH confidence)
- **WordPress core phpunit.xml.dist** — Schema references PHPUnit 9.2; updated 2025-09-13. WordPress core still uses PHPUnit 9.x as of Feb 2026. (HIGH confidence)
- **phpunit-polyfills README** — `https://github.com/yoast/phpunit-polyfills/blob/main/README.md` — Verified: 1.x supports PHPUnit 4.8–9.x; 2.x supports PHPUnit 5.7–10.x; latest stable 2.0.5 (Aug 2025). (HIGH confidence)
- **PHPUnit 9.6 ChangeLog** — `https://github.com/sebastianbergmann/phpunit/blob/9.6/ChangeLog-9.6.md` — Verified: 9.6.34 released Jan 2026; actively maintained, PHP 8.4/8.5 compatible. (HIGH confidence)
- **PHPUnit 11.5 composer.json** — Requires PHP `>=8.2`. Confirms upgrading from 9.6 to 11.x is blocked by WP Sudo's PHP 8.0 minimum. (HIGH confidence)
- **WP_UnitTestCase_Base source** — `https://github.com/WordPress/wordpress-develop/blob/trunk/tests/phpunit/includes/abstract-testcase.php` — Verified: transaction rollback on teardown, factory pattern, key helper methods. (HIGH confidence)
- **WP_UnitTest_Factory source** — `https://github.com/WordPress/wordpress-develop/blob/trunk/tests/phpunit/includes/factory/class-wp-unittest-factory.php` — Verified: `$user`, `$post`, `$comment`, `$term`, `$blog`, `$network` factories. (HIGH confidence)
- **WordPress/two-factor CI workflow** — `https://github.com/WordPress/two-factor/blob/master/.github/workflows/test.yml` — Uses `@wordpress/env` via Docker; PHP 7.2–8.5 matrix. Confirms Docker-based approach is viable but adds Node.js dependency. (HIGH confidence — inspected raw YAML via GitHub API)
- **wp-cli/scaffold-command** — `https://github.com/wp-cli/scaffold-command` — Confirmed `install-wp-tests.sh` is still actively maintained, uses SVN from `develop.svn.wordpress.org`. (MEDIUM confidence — README inspected, exact script implementation verified via description)
- **GitHub Actions MySQL service pattern** — Official GHA docs + wordpress-develop CI — Standard `services:` block with health check. (HIGH confidence)
- **WP_Test_REST_TestCase** — `https://github.com/WordPress/wordpress-develop/blob/trunk/tests/phpunit/includes/testcase-rest-api.php` — Provides `assertErrorResponse()` for REST integration tests. (HIGH confidence)

---

*Stack research for: WordPress plugin integration testing (WP_UnitTestCase + Brain\Monkey coexistence)*
*Researched: 2026-02-19*
