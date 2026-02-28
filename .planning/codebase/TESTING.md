# Testing Patterns

**Analysis Date:** 2026-02-27 (revised; original 2026-02-19)

## Test Framework

**Runner:**
- PHPUnit 9.6
- Two separate configurations:
  - `phpunit.xml.dist` — unit tests (bootstrap: `tests/bootstrap.php`)
  - `phpunit-integration.xml.dist` — integration tests (bootstrap: `tests/Integration/bootstrap.php`)
- Unit tests: strict mode fully enabled — `beStrictAboutTestsThatDoNotTestAnything`, `beStrictAboutOutputDuringTests`, `failOnWarning`, `failOnRisky`
- Integration tests: strict mode partially enabled — `beStrictAboutTestsThatDoNotTestAnything` only (output and risky strictness disabled because WP test library produces output)

**WordPress Function Mocking (unit only):**
- Brain\Monkey 2.7+ — replaces WordPress functions with stubs/mocks; NOT loaded in integration tests
- Mockery 1.6+ (with Brain\Monkey integration) — object mocking; unit tests only
- Patchwork — redefines PHP internals not normally mockable

**Run Commands:**
```bash
composer test              # Alias for composer test:unit
composer test:unit         # Run unit tests (PHPUnit with phpunit.xml.dist)
composer test:integration  # Run integration tests (PHPUnit with phpunit-integration.xml.dist)
composer test:coverage     # Run unit tests with PCOV (generates coverage.xml + text summary)
./vendor/bin/phpunit tests/Unit/SudoSessionTest.php   # Run a single unit test file
./vendor/bin/phpunit --filter testMethodName           # Run a single test method
```

## Test File Organization

**Location:**
- Unit tests: `tests/Unit/` — one file per source class
- Integration tests: `tests/Integration/` — one file per feature area or cross-class flow
- Shared infrastructure: `tests/bootstrap.php` (unit), `tests/TestCase.php` (unit base class), `tests/Integration/bootstrap.php`, `tests/Integration/TestCase.php`

**Naming:**
- Test files: `{SourceClass}Test.php` or `{Feature}Test.php`
- Test classes: namespace `WP_Sudo\Tests\Unit` (unit) or `WP_Sudo\Tests\Integration` (integration)
- Test methods: `test_{feature}_{scenario}` → `test_is_active_returns_true_when_valid()`

**Structure:**
```
tests/
├── bootstrap.php                  # WordPress constants, class stubs, Composer autoloader
├── TestCase.php                   # Unit base class: Brain\Monkey setup, cache resets
├── MANUAL-TESTING.md              # UI/UX manual testing checklist (not automated)
├── testing-recommendations.md    # Test strategy notes and status tracking
├── Unit/
│   ├── ActionRegistryTest.php
│   ├── AdminBarTest.php
│   ├── AdminTest.php
│   ├── ChallengeTest.php
│   ├── GateTest.php
│   ├── LoginSudoGrantTest.php
│   ├── PasswordChangeExpiryTest.php
│   ├── PluginTest.php
│   ├── RequestStashTest.php
│   ├── SiteHealthTest.php
│   ├── SudoSessionTest.php
│   ├── UpgraderTest.php
│   └── WpGraphQLGatingTest.php
└── Integration/
    ├── bootstrap.php              # Loads real WP test environment + plugin; no Brain\Monkey
    ├── TestCase.php               # Integration base class: superglobal snapshots, helpers
    ├── ActionRegistryTest.php
    ├── AjaxGatingTest.php
    ├── AuditHooksTest.php
    ├── ChallengeTest.php
    ├── MultisiteTest.php
    ├── RateLimitingTest.php
    ├── ReauthFlowTest.php
    ├── RequestStashTest.php
    ├── RestGatingTest.php
    ├── SudoSessionTest.php
    ├── TwoFactorTest.php
    ├── UninstallTest.php
    ├── UpgraderTest.php
    └── WpGraphQLGatingTest.php
```

**Current counts (v2.9.2):** 397 unit tests, 944 assertions. ~80 integration tests.

## Test Types

### Unit Tests (`tests/Unit/`)

- Scope: Single class in isolation with all WordPress dependencies mocked
- Approach: Dependency injection of test doubles (Mockery mocks + Brain\Monkey function stubs + Patchwork for PHP internals)
- Speed: ~0.4 s for the full suite
- No WordPress loaded: tests use stubs and mocks from `tests/bootstrap.php`
- Base class: `tests/TestCase.php` (extends PHPUnit `TestCase`)
- Strict mode fully enabled: every test must assert something, produce no output, trigger no warnings

### Integration Tests (`tests/Integration/`)

- Scope: Cross-class flows with real WordPress, real MySQL, real bcrypt
- Approach: `WP_UnitTestCase` with real database transactions (rolled back after each test)
- Use for: real password verification, session token binding, transient TTL, REST/AJAX gating, Two Factor interaction, multisite isolation, uninstall cleanup
- **No Brain\Monkey in integration tests** — real WordPress functions only
- Each test gets a clean DB state via transaction rollback from `WP_UnitTestCase`
- Requires one-time setup via `bash bin/install-wp-tests.sh` (see CONTRIBUTING.md)

### E2E Tests

- Not automated
- Manual testing checklist: `tests/MANUAL-TESTING.md` (UI/UX prompts, 19 sections)
- No Playwright/Cypress/WebDriver tests planned

## Test Structure

**Unit test class setup (from `GateTest.php`):**
```php
class GateTest extends TestCase {
    private Gate $gate;

    protected function setUp(): void {
        parent::setUp(); // Starts Brain\Monkey
        $this->session = \Mockery::mock( Sudo_Session::class );
        $this->stash   = \Mockery::mock( Request_Stash::class );
        $this->gate    = new Gate( $this->session, $this->stash );
    }

    protected function tearDown(): void {
        unset( $_REQUEST['action'] ); // Clear test globals
        parent::tearDown(); // Tears down Brain\Monkey, verifies Mockery
    }
}
```

**Section organization within test class:**
```php
// -----------------------------------------------------------------
// defaults()
// -----------------------------------------------------------------

public function test_defaults_returns_expected_structure(): void {
```
Tests grouped by method under test, separated by comment blocks.

**Assertion style:** One logical assertion per test (may involve multiple `assert*()` calls for a single behavior). PHPUnit strict mode enforces at least one assertion per test.

## Base Classes

### `tests/TestCase.php` — Unit base class

- Calls `Monkey\setUp()` in `setUp()`, `Monkey\tearDown()` in `tearDown()`
- Uses `MockeryPHPUnitIntegration` trait — auto-verifies mock expectations at teardown
- Default function stubs registered in `setUp()`:
  - `wp_unslash` — returns value unchanged
  - `sanitize_text_field` — casts to string
  - `rest_get_authenticated_app_password` — returns `null` (not app-password auth)
  - `is_multisite` — returns `false`
  - `is_network_admin` — returns `false`
  - `network_admin_url` — returns `https://example.com/wp-admin/network/{path}`
- `tearDown()` clears:
  - `$_COOKIE[Sudo_Session::CHALLENGE_COOKIE]`
  - Static caches: `Action_Registry::reset_cache()`, `Sudo_Session::reset_cache()`, `Admin::reset_cache()`

### `tests/Integration/TestCase.php` — Integration base class

Extends `WP_UnitTestCase`. Key additions:

- **Superglobal snapshot/restore** — `$_SERVER`, `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE` snapshotted in `set_up()`, restored in `tear_down()`
- **Static cache resets** in `tear_down()`: `Sudo_Session::reset_cache()`, `Action_Registry::reset_cache()`, `Admin::reset_cache()`; also `unset( $GLOBALS['pagenow'] )`
- **`make_admin(string $password = 'test-password'): WP_User`** — creates administrator via factory (auto-cleaned by DB rollback)
- **`activate_plugin(): void`** — fires `activate_wp-sudo/wp-sudo.php` explicitly (muplugins_loaded bootstrap doesn't fire activation hook)
- **`update_wp_sudo_option(string $option, mixed $value): void`** — routes to `update_site_option()` on multisite, `update_option()` otherwise
- **`get_wp_sudo_option(string $option, mixed $default): mixed`** — matching getter
- **`simulate_admin_request(string $pagenow, string $action, string $method, array $get, array $post): void`** — sets `$GLOBALS['pagenow']`, `$_SERVER['REQUEST_METHOD']`, `$_SERVER['HTTP_HOST']`, `$_SERVER['REQUEST_URI']`, `$_GET`, `$_POST`, `$_REQUEST` for Gate request matching and Request_Stash URL building

## Mocking

### WordPress Function Mocking (Brain\Monkey — unit tests only)

From `TestCase.php` — predefined stubs via `Functions\stubs()`:
```php
Functions\stubs([
    'wp_unslash'          => static fn($v) => $v,
    'sanitize_text_field' => static fn($s) => (string) $s,
]);
```

- **`Functions\stubs()`** — define default return behavior for functions
- **`Functions\when()`** — define conditional behavior, overridable per test
- **`Functions\expect()`** — assert a function is called with specific args

Typical per-test override:
```php
Functions\when( 'get_user_meta' )->alias(function ( $uid, $key, $single ) use ( $future, $token ) {
    if ( Sudo_Session::META_KEY === $key ) return $future;
    if ( Sudo_Session::TOKEN_META_KEY === $key ) return hash( 'sha256', $token );
    return '';
});
```

### Object Mocking (Mockery — unit tests only)

```php
$this->session = \Mockery::mock( Sudo_Session::class );
$this->stash   = \Mockery::mock( Request_Stash::class );
```

Automatically verified at teardown via `MockeryPHPUnitIntegration` trait.

### Hook Mocking (Brain\Monkey — unit tests only)

```php
// Assert action was registered:
Actions\expectAdded( 'admin_menu' )
    ->once()
    ->with( array( $this->challenge, 'register_page' ), \Mockery::any() );

// Assert action fired:
Actions\expectFired( 'wp_sudo_activated' )->once();
```

### What to Mock

- WordPress built-in functions: `get_option()`, `get_user_meta()`, `wp_die()`, etc.
- External dependencies (Request_Stash, Sudo_Session) when testing other components
- HTTP operations: `setcookie()`, `header()` (via Patchwork)
- Hooks: assert registration / firing, not the hook system itself

### What NOT to Mock

- The class under test (SUT) — test the real implementation
- Pure helper logic — test the real implementation
- Constants and class properties — read the actual values

### Integration Tests — No Mocking

Integration tests call real WordPress functions. Don't add `Functions\when()`, `\Mockery::mock()`, or `Monkey\setUp()` to integration tests. Use the `did_action()` delta pattern to verify audit hooks fire:

```php
$before = did_action('wp_sudo_activated');
Sudo_Session::attempt_activation($user->ID, $password);
$this->assertSame($before + 1, did_action('wp_sudo_activated'));
```

## Fixtures and Factories

**Unit tests — inline test data:**
```php
public function test_is_active_returns_true_when_valid(): void {
    $future = time() + 300;
    $token  = 'valid-token-456';

    Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $future, $token ) {
        if ( Sudo_Session::META_KEY === $key )       return $future;
        if ( Sudo_Session::TOKEN_META_KEY === $key ) return hash( 'sha256', $token );
        return '';
    });
    // ...
}
```

**Integration tests — WP_UnitTestCase factory:**
```php
$user = $this->make_admin('known-password'); // uses self::factory()->user->create()
```
Factory-created users and options are rolled back automatically after each test.

**WordPress class stubs (unit bootstrap — `tests/bootstrap.php`):**

| Class | Key members |
|-------|-------------|
| `WP_User` | `$ID`, `$roles`, `$caps`, `$user_pass`, `$allcaps` |
| `WP_Admin_Bar` | `add_node()`, `get_nodes()` |
| `WP_Error` | `get_error_code()`, `get_error_message()`, `get_error_data()` |
| `WP_REST_Request` | `get_method()`, `get_route()`, `get_params()`, `get_header()`, `set_header()`, `get_body()`, `set_body()` |
| `WP_Screen` | `add_help_tab()`, `set_help_sidebar()`, `get_help_tabs()`, `get_help_sidebar()` |
| `Two_Factor_Provider` | `authentication_page()`, `pre_process_authentication()`, `validate_authentication()` |
| `Two_Factor_Core` | `static $mock_provider`, `is_user_using_two_factor()`, `get_primary_provider_for_user()` |

`Two_Factor_Core::$mock_provider` is set per test to simulate Two Factor plugin availability:
```php
Two_Factor_Core::$mock_provider = new Two_Factor_Provider(); // simulate TF active
Two_Factor_Core::$mock_provider = null;                      // simulate TF absent
```

## Coverage

**Requirements:** Not gated — no minimum threshold enforced anywhere.

**Coverage target:** `includes/` directory only (production code).

**View coverage locally:**
```bash
composer test:coverage
# Generates: coverage.xml (Clover format) + stdout text summary
```

**CI coverage job:** `unit-tests-coverage` in `.github/workflows/phpunit.yml`
- Runs PHP 8.3 + PCOV (unit tests only — not integration matrix)
- Uploads `coverage.xml` as a GitHub Actions artifact after each run
- No failure threshold — baseline established in v2.9.1; ratchet up once measured

**Why PCOV on one PHP version only:** Adding coverage to all 4 PHP versions × 2 WP versions × 2 multisite combos multiplies CI time without improving coverage signal. One baseline is enough to identify gaps.

## Patchwork for Low-Level Functions

**`patchwork.json`** declares which PHP internals can be redefined in tests:

```json
{
    "redefinable-internals": [
        "setcookie",
        "header",
        "hash_equals",
        "headers_sent",
        "function_exists",
        "file_get_contents"
    ]
}
```

Use via Brain\Monkey `Functions\when()` in unit tests:
```php
Functions\when( 'setcookie' )->justReturn( true );
```

- `setcookie` / `header` — verify HTTP cookies and redirects without sending real headers
- `hash_equals` — verify token validation logic (timing-safe comparison)
- `headers_sent` — control redirect guard behavior
- `function_exists` — simulate presence/absence of Two Factor or WPGraphQL
- `file_get_contents` — stub remote file reads (MU-plugin install)

## Static Cache Reset

From `tests/TestCase.php` (unit tearDown):
```php
protected function tearDown(): void {
    unset( $_COOKIE[ \WP_Sudo\Sudo_Session::CHALLENGE_COOKIE ] );

    \WP_Sudo\Action_Registry::reset_cache();
    \WP_Sudo\Sudo_Session::reset_cache();
    \WP_Sudo\Admin::reset_cache();

    Monkey\tearDown();
    parent::tearDown();
}
```

Each class implements `reset_cache()` to prevent cross-test contamination:
- `Action_Registry::reset_cache()` — clears cached gated rules array
- `Sudo_Session::reset_cache()` — clears cached session-state checks
- `Admin::reset_cache()` — clears cached settings array

Integration tests call the same three resets in `tear_down()` plus `unset( $GLOBALS['pagenow'] )`.

## CI Pipeline

Four jobs in `.github/workflows/phpunit.yml`, triggered on push, PR, and nightly cron (3 AM UTC):

| Job | Matrix | Key detail |
|-----|--------|------------|
| `unit-tests` | PHP 8.1 / 8.2 / 8.3 / 8.4 | `coverage: none`; runs `composer test:unit` |
| `unit-tests-coverage` | PHP 8.3 only | `coverage: pcov`; runs `composer test:coverage`; uploads `coverage.xml` artifact |
| `integration-tests` | PHP 8.1 × 8.3 / WP latest × trunk / single-site × multisite (8 combos) | MySQL 8.0 service; installs WP test suite via `bin/install-wp-tests.sh`; runs `composer test:integration` |
| `code-quality` | PHP 8.3 only | Runs `composer lint` (PHPCS) then `composer analyse` (PHPStan level 6) |

**Nightly failure notification:** Creates a GitHub issue + Slack message when any job fails in the scheduled run.

## Common Patterns

### Hook Registration Testing (unit)

```php
public function test_register_hooks_the_correct_actions(): void {
    Actions\expectAdded( 'admin_menu' )
        ->once()
        ->with( array( $this->challenge, 'register_page' ), \Mockery::any() );

    $this->challenge->register();
}
```

### Hook Firing Testing (integration — `did_action()` delta)

```php
$before = did_action('wp_sudo_reauth_failed');
$result = Sudo_Session::attempt_activation($user->ID, 'wrong-password');
$this->assertSame($before + 1, did_action('wp_sudo_reauth_failed'));
```

### Exit/Die Testing (unit — `wp_send_json_error` mocking)

```php
public function test_soft_block_returns_json_error_for_ajax(): void {
    Functions\expect( 'wp_send_json_error' )
        ->once()
        ->with( \Mockery::any(), 401 );

    $this->gate->soft_block_request();
}
```

### Rate Limiting Constants (unit)

```php
public function test_lockout_constants(): void {
    $this->assertSame( '_wp_sudo_failed_attempts', Sudo_Session::LOCKOUT_META_KEY );
    $this->assertSame( '_wp_sudo_lockout_until', Sudo_Session::LOCKOUT_UNTIL_META_KEY );
    $this->assertSame( 5, Sudo_Session::MAX_FAILED_ATTEMPTS );
    $this->assertSame( 300, Sudo_Session::LOCKOUT_DURATION );
}
```

### Token Binding (integration — SHA-256 cookie ↔ meta)

```php
Sudo_Session::activate($user->ID);
$cookie_token = $_COOKIE[ Sudo_Session::TOKEN_COOKIE ];
$stored_hash  = get_user_meta($user->ID, Sudo_Session::TOKEN_META_KEY, true);
$this->assertSame(hash('sha256', $cookie_token), $stored_hash);
```

### Multisite Isolation (integration)

`MultisiteTest.php` runs the same suite with `WP_MULTISITE=1`. Tests guard with `$this->skipUnlessMultisite()` (from `WP_UnitTestCase`) where single-site-only.

## Test Database / Transients

**Integration tests — real DB:**
- Each test runs in a MySQL transaction rolled back by `WP_UnitTestCase::tear_down()`
- User meta, options, transients written during a test don't persist to the next test
- Transients tested directly (not mocked): `Request_Stash` reads/writes real `set_transient()` / `get_transient()`

**Unit tests — no DB:**
- Options and meta mocked via `Functions\when('get_user_meta')->alias(...)`, etc.
- `Request_Stash` dependency injected as a Mockery mock in tests for other classes
- No fixtures for users, options, or metadata — all inline via closures

---

*Testing analysis revised: 2026-02-27*
