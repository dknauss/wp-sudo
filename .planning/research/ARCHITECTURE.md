# Architecture Research

**Domain:** WordPress plugin integration test suite alongside Brain\Monkey unit tests
**Researched:** 2026-02-19
**Confidence:** HIGH — All patterns verified against wordpress-develop trunk, Two Factor plugin source, yoast/phpunit-polyfills, and wp-cli/scaffold-command source.

---

## Standard Architecture

### System Overview

The target architecture separates the two test suites at every layer: bootstrap, base class, PHPUnit configuration, CI job, and directory. Unit tests continue to run in milliseconds without WordPress. Integration tests spin up a real WordPress + MySQL environment.

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          DEVELOPER / CI TRIGGER                          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  composer test:unit              composer test:integration               │
│  ./vendor/bin/phpunit            ./vendor/bin/phpunit                    │
│      --config phpunit.xml.dist       --config phpunit.integration.xml   │
│                                                                          │
├───────────────────────────┬─────────────────────────────────────────────┤
│  UNIT SUITE               │  INTEGRATION SUITE                          │
│  (no WordPress loaded)    │  (real WordPress + MySQL)                   │
│                           │                                             │
│  tests/bootstrap.php      │  tests/integration/bootstrap.php           │
│   └── defines ABSPATH     │   └── reads WP_TESTS_DIR env var           │
│   └── stubs WP classes    │   └── loads tests_add_filter()             │
│   └── stubs Two_Factor    │   └── registers plugin via                 │
│   └── Composer autoload   │        muplugins_loaded hook               │
│                           │   └── requires WP bootstrap.php            │
│  tests/TestCase.php       │        (boots real WP + DB)                │
│   └── PHPUnit\TestCase    │                                             │
│   └── Brain\Monkey setUp  │  tests/integration/TestCase.php            │
│   └── Brain\Monkey tearDn │   └── WP_UnitTestCase (real DB rollback)   │
│                           │   └── factory() for user/meta fixtures     │
│  tests/Unit/*.php         │   └── plugin activate helper               │
│   └── mocked WP fns       │                                             │
│   └── mocked objects      │  tests/integration/                        │
│   └── fast (<1s each)     │   └── SudoSessionIntegrationTest.php       │
│                           │   └── GateIntegrationTest.php              │
│                           │   └── ReauthFlowTest.php                   │
│                           │   └── RestGatingTest.php                   │
│                           │   └── UpgraderIntegrationTest.php          │
│                           │   └── MultisiteIsolationTest.php           │
│                           │   └── TwoFactorIntegrationTest.php         │
│                           │                                             │
├───────────────────────────┴─────────────────────────────────────────────┤
│                          SHARED INFRASTRUCTURE                          │
│                                                                          │
│  vendor/phpunit/phpunit 9.6   (same binary for both suites)            │
│  includes/                    (production code under test)              │
│  bin/install-wp-tests.sh      (one-time local DB + WP test lib setup)  │
│  WP_TESTS_DIR=/tmp/wordpress-tests-lib  (env var, CI sets this)        │
└─────────────────────────────────────────────────────────────────────────┘
```

### Component Responsibilities

| Component | Responsibility | Communicates With |
|-----------|----------------|-------------------|
| `phpunit.xml.dist` | Unit suite configuration (existing) | `tests/bootstrap.php`, `tests/Unit/` |
| `phpunit.integration.xml` | Integration suite configuration (new) | `tests/integration/bootstrap.php`, `tests/integration/` |
| `tests/bootstrap.php` | Unit bootstrap: constants, stubs, Brain\Monkey | PHPUnit runner, Brain\Monkey |
| `tests/integration/bootstrap.php` | Integration bootstrap: loads real WP via WP_TESTS_DIR | PHPUnit runner, WordPress test library |
| `tests/TestCase.php` | Unit base class: Brain\Monkey setup/teardown | Brain\Monkey, Mockery, PHPUnit |
| `tests/integration/TestCase.php` | Integration base class: WP_UnitTestCase + plugin activate | WP_UnitTestCase, real DB |
| `WP_UnitTestCase` | Real database, transaction rollback, factory, REST helpers | MySQL, WordPress core |
| `bin/install-wp-tests.sh` | One-time setup: downloads WP test lib, creates test DB | MySQL, SVN/wget, WordPress.org |
| `WP_TESTS_DIR` env var | Points both bootstrap and install script to test library | Integration bootstrap, shell |

---

## Recommended Project Structure

The integration test directory lives beside `Unit/` under `tests/`. Both are siblings — same parent, separate namespaces, separate bootstraps.

```
wp-sudo/
├── bin/
│   └── install-wp-tests.sh        # One-time dev+CI setup script
├── tests/
│   ├── bootstrap.php              # EXISTING — unit bootstrap (stubs, Brain\Monkey)
│   ├── TestCase.php               # EXISTING — unit base class (Brain\Monkey)
│   ├── MANUAL-TESTING.md          # EXISTING — UI checklist
│   ├── Unit/                      # EXISTING — 10 test files, no change
│   │   ├── ActionRegistryTest.php
│   │   ├── AdminTest.php
│   │   ├── AdminBarTest.php
│   │   ├── ChallengeTest.php
│   │   ├── GateTest.php
│   │   ├── PluginTest.php
│   │   ├── RequestStashTest.php
│   │   ├── SiteHealthTest.php
│   │   ├── SudoSessionTest.php
│   │   └── UpgraderTest.php
│   └── integration/               # NEW — real WP environment
│       ├── bootstrap.php          # Loads WP test lib, registers plugin
│       ├── TestCase.php           # Extends WP_UnitTestCase, adds helpers
│       ├── ReauthFlowTest.php     # Full stash→challenge→verify→replay
│       ├── SudoSessionTest.php    # Real token, real meta, real cookie header
│       ├── GateTest.php           # Real admin_init hook priority order
│       ├── RestGatingTest.php     # Real REST with cookie/app-password auth
│       ├── UpgraderTest.php       # Real DB option/meta mutations
│       ├── MultisiteTest.php      # Blog switching, session isolation
│       └── TwoFactorTest.php      # Real Two Factor plugin loaded
├── phpunit.xml.dist               # EXISTING — unit suite only
├── phpunit.integration.xml        # NEW — integration suite only
├── composer.json                  # Needs test:unit / test:integration scripts
└── ...
```

### Structure Rationale

- **`tests/integration/` as sibling to `tests/Unit/`:** Keeps both suites visually discoverable under `tests/`. Avoids deep nesting. Mirrors the pattern used by wordpress-importer and Two Factor plugin.
- **Separate bootstrap per suite:** The unit bootstrap defines `ABSPATH = '/tmp/fake-wordpress/'` and stubs WP classes. If that file runs before the WP test library loads, it will conflict. Each suite needs its own entry point. Source: inspection of `tests/bootstrap.php` and Two Factor's `tests/bootstrap.php` pattern.
- **Separate `phpunit.*.xml`:** PHPUnit 9.6 does not support per-directory bootstraps in a single config. Two configs is the standard solution. Brain\Monkey cannot coexist in the same process as real WordPress (both attempt to define the same function names). Source: Brain\Monkey 2.x documentation and architecture.
- **`bin/install-wp-tests.sh` at root level:** Standard location per `wp scaffold plugin-tests` output. CI runs this script before running the integration suite. Not needed by the unit suite.
- **Same `vendor/bin/phpunit` binary:** Both suites use the project's locked PHPUnit 9.6. This resolves the PHPUnit version conflict: the WP test library does not bundle its own PHPUnit — it bridges via `yoast/phpunit-polyfills`, which explicitly supports `^7.5 || ^8.0 || ^9.0 || ^11.0 || ^12.0`. Source: `yoast/phpunit-polyfills` `composer.json`.
- **`tests/integration/TestCase.php` extends `WP_UnitTestCase`:** Not `PHPUnit\Framework\TestCase`. `WP_UnitTestCase` provides `START TRANSACTION` / `ROLLBACK` per test, the `factory()` fixture builder, and `assertErrorResponse()` for REST. Source: `wordpress-develop/tests/phpunit/includes/abstract-testcase.php`.

---

## Architectural Patterns

### Pattern 1: Dual PHPUnit Configuration Files

**What:** Two `phpunit.*.xml` files — one per suite. The existing `phpunit.xml.dist` runs unit tests only. A new `phpunit.integration.xml` runs integration tests only. Each points to its own bootstrap file.

**When to use:** Required when unit and integration test bootstraps are mutually exclusive. Brain\Monkey and real WordPress cannot coexist in the same PHP process.

**Trade-offs:** Two config files to maintain. Offset by the fact that the integration config is smaller (fewer strictness flags needed because WP core itself produces notices that would fail strict mode).

**Example — `phpunit.integration.xml`:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
    bootstrap="tests/integration/bootstrap.php"
    colors="true"
    beStrictAboutTestsThatDoNotTestAnything="true"
>
    <testsuites>
        <testsuite name="Integration">
            <directory suffix="Test.php">tests/integration</directory>
        </testsuite>
    </testsuites>

    <coverage>
        <include>
            <directory suffix=".php">includes</directory>
        </include>
    </coverage>
</phpunit>
```

Note: `failOnWarning` and `beStrictAboutOutputDuringTests` are intentionally omitted. WordPress core produces deprecation notices and occasional output during bootstrap that would fail strict mode. The unit suite keeps these flags; the integration suite does not.

### Pattern 2: Integration Bootstrap via WP_TESTS_DIR

**What:** The integration bootstrap reads the `WP_TESTS_DIR` environment variable to locate the WordPress test library. It uses `tests_add_filter()` on `muplugins_loaded` to load the plugin before WordPress initializes. Then it calls the WP bootstrap to start the real environment.

**When to use:** Standard pattern for all WordPress plugin integration tests. Verified in: `wordpress-importer/phpunit/bootstrap.php`, `two-factor/tests/bootstrap.php`, `wp-cli/scaffold-command` template.

**Trade-offs:** Requires `install-wp-tests.sh` to be run once (or in CI) to place the test library at `WP_TESTS_DIR`. Adds one setup step. In return, you get a fully functional WordPress with a real database.

**Example — `tests/integration/bootstrap.php`:**
```php
<?php
/**
 * PHPUnit bootstrap for WP Sudo integration tests.
 *
 * Requires a real WordPress test environment. Run bin/install-wp-tests.sh first.
 * Set WP_TESTS_DIR env var to override the default location.
 *
 * @package WP_Sudo\Tests\Integration
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find {$_tests_dir}/includes/functions.php\n";
    echo "Run: bash bin/install-wp-tests.sh wordpress_test root '' localhost latest\n";
    exit( 1 );
}

// Give access to tests_add_filter() before WP boots.
require_once $_tests_dir . '/includes/functions.php';

// Load the plugin under test at muplugins_loaded (earliest safe hook).
tests_add_filter(
    'muplugins_loaded',
    static function () {
        require_once dirname( __DIR__, 2 ) . '/wp-sudo.php';
    }
);

// Composer autoloader for test classes.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Boot the real WordPress environment (loads DB, fires all init hooks).
require $_tests_dir . '/includes/bootstrap.php';
```

### Pattern 3: WP_UnitTestCase Integration Base Class

**What:** Integration test base class extends `WP_UnitTestCase` instead of `PHPUnit\Framework\TestCase`. Provides per-test database transaction rollback (no manual cleanup needed), the `factory()` fixture builder for users/posts/meta, and hook state backup/restore.

**When to use:** Every integration test file. Not the unit test files — those extend `WP_Sudo\Tests\TestCase` which extends `PHPUnit\Framework\TestCase`.

**Trade-offs:** Tests are slower (5–50ms each vs <1ms for unit tests) because of real DB transactions. This is the expected trade-off for integration coverage.

**Example — `tests/integration/TestCase.php`:**
```php
<?php
/**
 * Base integration test case for WP Sudo.
 *
 * Extends WP_UnitTestCase for real database + WordPress environment.
 * Each test runs in a database transaction that is rolled back in tearDown.
 *
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

class TestCase extends \WP_UnitTestCase {

    /**
     * Create an administrator user with a real password in the database.
     *
     * @param string $password Plain-text password (will be bcrypt-hashed by wp_create_user).
     * @return \WP_User
     */
    protected function make_admin( string $password = 'test-password' ): \WP_User {
        $user_id = self::factory()->user->create(
            array(
                'role'      => 'administrator',
                'user_pass' => $password,
            )
        );
        return get_user_by( 'id', $user_id );
    }

    /**
     * Activate the plugin explicitly (activation hook was not fired during bootstrap).
     *
     * Tests that verify activation side effects (unfiltered_html removal, etc.) should call this.
     */
    protected function activate_plugin(): void {
        do_action( 'activate_wp-sudo/wp-sudo.php' );
    }
}
```

Note: `WP_UnitTestCase` uses `setUp()` / `tearDown()` with snake_case names (not camelCase). PHPUnit 9.6 calls these via the polyfill layer. Source: `wordpress-develop/tests/phpunit/includes/abstract-testcase.php`.

### Pattern 4: PHPUnit 9.6 + WP Test Library Compatibility via Yoast Polyfills

**What:** The `wordpress-develop` test library does not bundle PHPUnit. It bridges to any supported PHPUnit version via `yoast/phpunit-polyfills`. WP Sudo already has PHPUnit 9.6 in `vendor/`. Adding `yoast/phpunit-polyfills` as a dev dependency allows the WP test library to use the project's existing PHPUnit binary.

**When to use:** Required for any plugin that wants to run integration tests without using WP's own PHPUnit. Verified: `yoast/phpunit-polyfills` `composer.json` declares `"phpunit/phpunit": "^7.5 || ^8.0 || ^9.0 || ^11.0 || ^12.0"`.

**Trade-offs:** One additional dev dependency. The alternative — using a separate system-installed PHPUnit — creates version conflicts and complicates CI.

**Installation:**
```bash
composer require --dev yoast/phpunit-polyfills:"^1.1"
```

The integration bootstrap must include the polyfills autoloader before loading the WP test library bootstrap:
```php
// After tests_add_filter() calls, before WP bootstrap:
require dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require $_tests_dir . '/includes/bootstrap.php';
```

### Pattern 5: Plugin Loading via muplugins_loaded Hook

**What:** The plugin is loaded by `tests_add_filter( 'muplugins_loaded', ... )` in the integration bootstrap, not by `require` at bootstrap time. This ensures the plugin sees real WP functions (not stubs) when it initializes, and fires in the correct hook order: `muplugins_loaded` → `plugins_loaded` → `init` → `admin_init`.

**When to use:** Required. Calling `require wp-sudo.php` directly in bootstrap would run before WordPress is initialized — plugin constants would be set but `add_action()` would fail.

**Trade-offs:** Slight indirection (the plugin loads during WP's boot sequence, not at bootstrap time), but this is the correct pattern verified across Two Factor and wordpress-importer.

### Pattern 6: Composer Scripts for Dual Test Runner

**What:** Extend `composer.json` scripts to support running each suite independently. Keep `composer test` pointing to the unit suite (no DB required, runs in any environment). Add `composer test:unit` and `composer test:integration` as named aliases.

**When to use:** Always. Developers without a configured test database can still run the unit suite. CI runs both in separate jobs.

**Example — `composer.json` scripts section:**
```json
"scripts": {
    "test": "phpunit",
    "test:unit": "phpunit --configuration phpunit.xml.dist",
    "test:integration": "phpunit --configuration phpunit.integration.xml",
    "lint": "phpcs",
    "lint:fix": "phpcbf",
    "analyse": "phpstan analyse",
    "sbom": "composer CycloneDX:make-sbom ..."
}
```

`composer test` (no argument) continues to run the unit suite only, preserving backward compatibility with CLAUDE.md instructions and pre-commit habits.

---

## Data Flow

### Integration Test Execution Flow

```
Developer runs: composer test:integration
    ↓
PHPUnit 9.6 reads phpunit.integration.xml
    ↓
Loads tests/integration/bootstrap.php
    ↓
    ├── Reads WP_TESTS_DIR env var → /tmp/wordpress-tests-lib
    ├── Requires functions.php (tests_add_filter available)
    ├── Registers muplugins_loaded → load wp-sudo.php
    ├── Requires phpunitpolyfills-autoload.php
    └── Requires wordpress-tests-lib/includes/bootstrap.php
            ↓
            Connects to test MySQL database
            Installs WordPress tables (first run)
            Fires: muplugins_loaded → wp-sudo.php loads
            Fires: plugins_loaded → Plugin::init()
            Fires: init → Gate registers at priority 1
            Fires: admin_init → Gate::intercept() wired
    ↓
PHPUnit discovers tests/integration/*Test.php
    ↓
For each test method:
    ├── WP_UnitTestCase::set_up() runs
    │       START TRANSACTION (DB rollback isolation)
    │       Backup hooks
    ├── test_*() runs against real WP + real DB
    └── WP_UnitTestCase::tear_down() runs
            ROLLBACK (undo all DB changes)
            Restore hooks
            Reset WP_Query, $wp globals
```

### Real Authentication Flow (Integration Target)

```
test creates admin user via factory()
    ↓
test sets $_REQUEST['action'] = 'activate-plugin'
    ↓
Gate::intercept() fires at admin_init priority 1
    ↓
Gate calls Action_Registry::get_rules() → real rules (not mocked)
    ↓
Gate matches rule → calls Request_Stash::store()
    → real set_transient() with real TTL
    ↓
Gate calls wp_safe_redirect() → intercepted / asserted
    ↓
test loads Challenge page directly
    ↓
Challenge::verify_password() calls real wp_check_password()
    → real bcrypt (cost=5 in test env, per WP_UnitTestCase::wp_hash_password_options)
    ↓
Challenge calls Sudo_Session::activate()
    → real update_user_meta(), real setcookie() header
    ↓
Challenge calls Request_Stash::replay()
    → real get_transient() + delete_transient()
    ↓
test asserts session is active, meta exists, transient deleted
```

### Key Data Flows

1. **Session token flow:** `Sudo_Session::activate()` writes a hash to `_wp_sudo_session_token` user meta and sets a cookie header. Integration tests verify both the meta value (via `get_user_meta()`) and that the cookie was emitted (via output buffer capture or `headers_list()`).

2. **Request stash flow:** `Request_Stash::store()` writes a transient with a 10-minute TTL. Integration tests verify the transient exists (via `get_transient()`), that replay retrieves and deletes it (transient gone after replay), and that expired transients are not replayed (requires `sleep()` or mock time — flag this as needing investigation).

3. **Multisite isolation flow:** `switch_to_blog( $site_b_id )` → assert `Sudo_Session::is_active()` returns false for a session activated on site A. Restore with `restore_current_blog()`. WP_UnitTestCase handles blog switching cleanup in `tear_down()`.

---

## Scaling Considerations

Integration tests don't scale to users — they scale to test count and test time.

| Scale | Architecture Adjustments |
|-------|--------------------------|
| 0–20 integration tests | Single integration job in CI, no parallelism needed |
| 20–100 tests | Consider splitting by feature group (session, gate, REST, multisite) using PHPUnit `--group` annotations |
| 100+ tests | PHPUnit parallel runner (`--process-isolation`) or split into multiple CI jobs by group |

### Scaling Priorities

1. **First bottleneck — test DB setup time:** `install-wp-tests.sh` downloads WordPress and creates the database. In CI, cache the downloaded WordPress core (not the database). The test DB is recreated per job run. GitHub Actions cache key: `${{ runner.os }}-wp-tests-${{ env.WP_VERSION }}`.

2. **Second bottleneck — slow transient TTL tests:** Tests that need transients to expire cannot use `sleep()` in CI (too slow). Use `WP_Temporary_Tables` or mock the transient expiry differently. Flag: the Request_Stash expiry test likely needs a different approach. See PITFALLS.md.

---

## Anti-Patterns

### Anti-Pattern 1: Shared Bootstrap Between Unit and Integration Suites

**What people do:** Add integration tests to `tests/Unit/` and extend the existing `tests/bootstrap.php`.

**Why it's wrong:** The unit bootstrap defines `ABSPATH = '/tmp/fake-wordpress/'` and stubs `WP_User`, `WP_REST_Request`, and `Two_Factor_Core`. If the real WordPress loads in the same process, PHP throws "class already defined" fatal errors. Brain\Monkey's function registry also conflicts with real WordPress functions. The two environments are mutually exclusive.

**Do this instead:** Separate directories, separate bootstraps, separate PHPUnit config files. Unit tests stay in `tests/Unit/`. Integration tests go in `tests/integration/`. Never merge their bootstraps.

### Anti-Pattern 2: Using `require tests/bootstrap.php` in the Integration Bootstrap

**What people do:** Call `require_once __DIR__ . '/../bootstrap.php'` at the top of the integration bootstrap to get the Composer autoloader.

**Why it's wrong:** The unit bootstrap also defines `ABSPATH`, `WP_CONTENT_DIR`, and WP class stubs. The WP test library's own bootstrap will then try to define the same constants → PHP notices or fatal errors depending on whether `define()` is used with `defined()` guards.

**Do this instead:** Require `vendor/autoload.php` directly in the integration bootstrap. The Composer autoloader is independent of the unit bootstrap stubs.

```php
// Correct: require autoloader directly
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Wrong: pulls in unit-test stubs
require_once dirname( __DIR__ ) . '/bootstrap.php';
```

### Anti-Pattern 3: Strict Output Mode in Integration Config

**What people do:** Copy `phpunit.xml.dist` verbatim and add it as `phpunit.integration.xml`, keeping `beStrictAboutOutputDuringTests="true"`.

**Why it's wrong:** WordPress core, and the WP test library bootstrap itself, emit output (deprecation notices, debug info) during initialization. This output causes the entire test run to fail before a single test method executes.

**Do this instead:** Omit `beStrictAboutOutputDuringTests` from the integration config. Keep `beStrictAboutTestsThatDoNotTestAnything="true"` to ensure tests still assert something.

### Anti-Pattern 4: Loading the Plugin with direct `require` Before WordPress Boots

**What people do:** `require_once '/path/to/wp-sudo.php'` at the top of the integration bootstrap, before calling the WP bootstrap.

**Why it's wrong:** `wp-sudo.php` calls `add_action()`, `register_activation_hook()`, and `plugins_loaded`. None of these WordPress functions exist yet — PHP fatal error.

**Do this instead:** Use `tests_add_filter( 'muplugins_loaded', function() { require ...; } )`. The WP bootstrap fires `muplugins_loaded` after setting up the core function library but before `plugins_loaded`, which is the correct insertion point.

### Anti-Pattern 5: One Composer Script Named `test` That Runs Both Suites

**What people do:** Change `"test": "phpunit"` to `"test": "phpunit --config phpunit.xml.dist && phpunit --config phpunit.integration.xml"`.

**Why it's wrong:** Developers without a configured test database (most contributors) will have every `composer test` invocation fail on the integration suite, blocking their unit test feedback loop.

**Do this instead:** Keep `composer test` running unit tests only. Name the combined run `composer test:all` and document that it requires `WP_TESTS_DIR` and a MySQL database.

---

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|---------------------|-------|
| MySQL test database | `install-wp-tests.sh` creates `wordpress_test` DB | Credentials passed as env vars in CI |
| WordPress test library (SVN) | `install-wp-tests.sh` fetches via SVN or wget | Cached by WP version in CI |
| Two Factor plugin | Loaded in `TwoFactorTest.php` bootstrap via separate config or `tests_add_filter` | Requires Two Factor plugin files in test WP install |
| GitHub Actions MySQL | `services: mysql:` block in workflow YAML | Standard Actions pattern, documented below |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| Unit tests ↔ Integration tests | None — fully isolated by separate bootstraps | No shared test base classes |
| Integration tests ↔ WP test library | `WP_UnitTestCase` inheritance | WP test library must be at `WP_TESTS_DIR` |
| Integration tests ↔ WP Sudo production code | `require wp-sudo.php` via `muplugins_loaded` | Same code path as real plugin activation |
| Integration bootstrap ↔ PHPUnit 9.6 | `yoast/phpunit-polyfills` bridge | Polyfills adapt WP_UnitTestCase to PHPUnit 9.x API |
| `bin/install-wp-tests.sh` ↔ CI | Environment variables: `WP_TESTS_DIR`, `DB_NAME`, `DB_USER`, `DB_PASS`, `WP_VERSION` | CI sets these; script reads them |

---

## CI Pipeline Structure

The integration suite requires MySQL. Unit tests do not. Use two separate GitHub Actions jobs.

```yaml
# .github/workflows/phpunit.yml

name: PHPUnit

on: [push, pull_request]

jobs:
  unit-tests:
    name: Unit Tests (PHP ${{ matrix.php }})
    runs-on: ubuntu-24.04
    strategy:
      matrix:
        php: ['8.1', '8.2', '8.3', '8.4']
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          tools: composer:v2
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run unit tests
        run: composer test:unit

  integration-tests:
    name: Integration Tests (PHP ${{ matrix.php }}, WP ${{ matrix.wp }})
    runs-on: ubuntu-24.04
    strategy:
      matrix:
        php: ['8.1', '8.3']       # Subset — integration tests are slower
        wp: ['latest', 'trunk']
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wordpress_test
        ports:
          - 3306:3306
        options: --health-cmd="mysqladmin ping" --health-timeout=10s --health-retries=5
    env:
      WP_TESTS_DIR: /tmp/wordpress-tests-lib
      WP_VERSION: ${{ matrix.wp }}
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          tools: composer:v2
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Cache WP test library
        uses: actions/cache@v4
        with:
          path: /tmp/wordpress-tests-lib
          key: ${{ runner.os }}-wp-tests-${{ matrix.wp }}-${{ hashFiles('bin/install-wp-tests.sh') }}

      - name: Install WP test library
        run: bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 ${{ matrix.wp }}

      - name: Run integration tests
        run: composer test:integration
```

### CI Build Order Implications

1. `unit-tests` job has no dependencies — runs immediately.
2. `integration-tests` job has no dependency on `unit-tests` — runs in parallel.
3. Both jobs must pass for the PR check to pass.
4. `install-wp-tests.sh` must be scaffolded before the workflow can run. **This is the first build artifact to create.**
5. `phpunit.integration.xml` and `tests/integration/bootstrap.php` and `tests/integration/TestCase.php` must exist before any integration test files can be added.
6. Integration test files are written after the scaffold exists — following TDD, each test file is written before the production code that makes it pass.

### Scaffold Build Order (for roadmap phase planning)

```
Phase scaffold work:
  1. Add yoast/phpunit-polyfills to composer.json require-dev
  2. Create bin/install-wp-tests.sh (from wp-cli scaffold template)
  3. Create phpunit.integration.xml
  4. Create tests/integration/bootstrap.php
  5. Create tests/integration/TestCase.php
  6. Add composer scripts (test:unit, test:integration)
  7. Create .github/workflows/phpunit.yml

Then per test file (TDD cycle):
  8. Write failing integration test (e.g., ReauthFlowTest.php)
  9. Verify it fails for the right reason against real WP
  10. Confirm production code makes it pass (no production changes needed initially — the tests cover existing behavior)
```

---

## Sources

- `wordpress-develop` `composer.json` (trunk, fetched 2026-02-19): confirms WP 7.0.0, `yoast/phpunit-polyfills ^1.1.0` as dev dependency, no bundled PHPUnit — https://github.com/WordPress/wordpress-develop
- `wordpress-develop` `phpunit.xml.dist` (trunk, fetched 2026-02-19): PHPUnit schema `http://schema.phpunit.de/9.2/phpunit.xsd` — confirms PHPUnit 9.x is the current WP test suite target
- `wordpress-develop` `tests/phpunit/includes/abstract-testcase.php` (trunk, fetched 2026-02-19): `start_transaction()` / `ROLLBACK` pattern, `factory()`, `setUp()` snake_case — HIGH confidence
- `wordpress-develop` `tests/phpunit/includes/bootstrap.php` (trunk, fetched 2026-02-19): `WP_TESTS_DIR` env var pattern, polyfills inclusion — HIGH confidence
- `WordPress/two-factor` `tests/bootstrap.php` (master, fetched 2026-02-19): `tests_add_filter( 'muplugins_loaded', ... )` plugin loading pattern — HIGH confidence
- `WordPress/two-factor` `class-two-factor-core.php` (master, fetched 2026-02-19): verified method signatures (`is_user_using_two_factor`, `get_primary_provider_for_user`) — HIGH confidence
- `WordPress/wordpress-importer` `phpunit/bootstrap.php` (master, fetched 2026-02-19): `WP_TESTS_DIR` fallback chain, `tests_add_filter( 'plugins_loaded', ... )` pattern — HIGH confidence
- `yoast/phpunit-polyfills` `composer.json` (main, fetched 2026-02-19): `"phpunit/phpunit": "^7.5 || ^8.0 || ^9.0 || ^11.0 || ^12.0"` — HIGH confidence, resolves PHPUnit 9.6 compatibility question
- `wp-cli/scaffold-command` `templates/install-wp-tests.sh` (fetched 2026-02-19): database setup, WP_TESTS_TAG derivation, SVN checkout pattern — HIGH confidence
- WP Sudo `tests/bootstrap.php` and `tests/TestCase.php` (local, 2026-02-19): unit bootstrap uses `ABSPATH = '/tmp/fake-wordpress/'` and class stubs — these must not run in integration context
- WP Sudo `.planning/codebase/TESTING.md` and `STACK.md` (local, 2026-02-19): PHPUnit 9.6 locked, Brain\Monkey 2.7, static cache `reset_cache()` pattern

---

*Architecture research for: WP Sudo integration test suite alongside Brain\Monkey unit tests*
*Researched: 2026-02-19*
