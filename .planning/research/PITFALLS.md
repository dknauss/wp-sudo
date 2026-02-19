# Pitfalls Research

**Domain:** WordPress security plugin — integration test suite + WP 7.0 compatibility
**Researched:** 2026-02-19
**Confidence:** HIGH (codebase), MEDIUM (WP ecosystem norms from official sources + training data)

> Note on sources: WebSearch was unavailable. WebFetch succeeded for a subset of official
> sources. Findings draw from: (1) the project codebase at HIGH confidence, (2) official
> `wordpress-develop` trunk files fetched directly at HIGH confidence, (3) the Yoast
> PHPUnit Polyfills README at HIGH confidence, and (4) the existing
> `docs/roadmap-2026-02.md` maintainer assessment at HIGH confidence. Training-data
> claims are flagged LOW.

---

## Critical Pitfalls

### Pitfall 1: PHPUnit Version Collision Between Plugin and wordpress-develop

**What goes wrong:**
WP Sudo has `phpunit/phpunit: ^9.6` locked in its own `composer.json`. The wordpress-develop
test harness references PHPUnit 9.2 in its `phpunit.xml.dist` schema but does **not** list
PHPUnit directly in its `composer.json` `require-dev` — it instead depends on
`yoast/phpunit-polyfills ^1.1.0`. If the integration test bootstrap loads both the
plugin's Composer autoloader (PHPUnit 9.6) and the WordPress test suite functions, there
are two possible conflicts:

1. The `vendor/phpunit/phpunit` used to run tests is the plugin's `^9.6`, which must also
   satisfy the polyfills. This is fine — PHPUnit 9.x is within the polyfills' supported
   range (7.5–12.x per the 4.x series).
2. The integration test suite cannot simply add `phpunit/phpunit: ^10` or higher to the
   plugin without also updating the `WP_TESTS_PHPUNIT_POLYFILLS_PATH` constant and the
   schema URI in `phpunit.xml.dist`. PHPUnit 10 removed many backward-compatible APIs
   that both the WP test suite and Brain\Monkey rely on.

The real trap: attempting to run integration tests from the same `phpunit.xml.dist` that
currently runs Brain\Monkey unit tests. These two test types require different bootstrap
files and different runtime contexts.

**Why it happens:**
Developers assume a single `phpunit.xml.dist` can drive both unit tests (Brain\Monkey,
no WP loaded) and integration tests (WP_UnitTestCase, full WP loaded). They are
fundamentally incompatible — Brain\Monkey tears down after every test expecting WP is
never bootstrapped; `WP_UnitTestCase` expects a fully running database.

**How to avoid:**
Create a separate `phpunit-integration.xml.dist` that references a separate integration
bootstrap (`tests/integration/bootstrap.php`). Never merge the two test suites into
one configuration file. Keep Brain\Monkey unit tests running with the existing
`phpunit.xml.dist`. Confirm both still pass before any commit.

**Warning signs:**
- `Brain\Monkey\setUp()` errors appearing in integration test runs
- `WP_UnitTestCase` class-not-found errors during `composer test`
- "Headers already sent" or "Cannot redeclare WP_User" errors when loading both
  bootstraps in the same process

**Phase to address:**
Integration test harness scaffolding phase (the first integration milestone). This is
the highest-priority pitfall to resolve before writing a single integration test.

---

### Pitfall 2: Brain\Monkey Contamination When Integration Harness Is Added

**What goes wrong:**
The existing unit tests use `Brain\Monkey` to mock WordPress functions globally. If
integration tests are added naively to the same test run or PHPUnit configuration,
the Brain\Monkey teardown/setup cycle interferes with the live WordPress functions
loaded by the WP test harness. Patchwork (used for `setcookie`, `header`,
`hash_equals`) intercepts at the PHP level and persists across test processes if
not properly isolated.

Specifically: `patchwork.json` redefines `setcookie` and `header` at the PHP
extension level. When integration tests need real header behavior (to test actual
cookie setting via `WP_UnitTestCase`), Patchwork may still intercept those calls
if the integration bootstrap does not handle it correctly.

**Why it happens:**
Patchwork's redefinitions are declared in `patchwork.json` and activated at
bootstrap time. If both unit and integration test bootstraps load the same
Composer autoloader, Patchwork activates for both. Integration tests expecting
real `setcookie()` behavior will silently get the Patchwork stub.

**How to avoid:**
Do NOT reference `patchwork.json` from the integration test configuration. The
integration test bootstrap should not require `vendor/antecedent/patchwork` and
should not call `\Patchwork\redefine()` for core functions. Use separate
Composer scripts: `composer test` for unit tests (with Patchwork), `composer
test:integration` for integration tests (without Patchwork, with WP loaded).

**Warning signs:**
- Integration tests for `setcookie` behavior always pass regardless of code changes
- `header()` calls in integration tests produce no real output
- `hash_equals` returning unexpected values in integration tests

**Phase to address:**
Integration test harness scaffolding phase. Must be resolved before testing
cookie/session behavior.

---

### Pitfall 3: WP_UnitTestCase Cannot Test Real HTTP Headers or Cookies

**What goes wrong:**
`WP_UnitTestCase` runs in a CLI process via PHPUnit. PHP's `setcookie()` function
requires headers to be sent before any output — in a CLI context with PHPUnit's
output buffering, calling `setcookie()` will either silently fail or throw
"Cannot modify header information — headers already sent."

The WP Sudo session system (`Sudo_Session::activate()`, `set_token()`,
`clear_session_data()`) calls `setcookie()` extensively. These calls cannot be
verified at the HTTP level in a CLI integration test.

**Why it happens:**
Developers try to write integration tests that assert `$_COOKIE` was populated
after calling `Sudo_Session::activate()`, not realizing that the actual HTTP cookie
is never set in the CLI context. The `$_COOKIE` superglobal mutation (which WP Sudo
does explicitly with `$_COOKIE[self::TOKEN_COOKIE] = $token`) IS testable, but the
actual browser-visible cookie attributes (httponly, samesite, secure, path) are not.

**How to avoid:**
Integration tests for session behavior should verify user meta state (`_wp_sudo_token`,
`_wp_sudo_expires`) and `$_COOKIE` superglobal mutations, NOT actual HTTP header
emission. Accept this limitation by design and document it explicitly in the integration
test bootstrap. Save full cookie attribute verification for manual testing against
`tests/MANUAL-TESTING.md` or a future E2E layer (Playwright/Cypress).

**Warning signs:**
- Test assertions checking `headers_list()` after a `Sudo_Session::activate()` call
- Assertions about cookie `httponly` or `samesite` attributes in PHPUnit tests
- "Cannot modify header information" failures during integration test runs

**Phase to address:**
Integration test harness scaffolding phase and the session binding integration test
phase. Explicitly document the cookie testing boundary in the integration bootstrap.

---

### Pitfall 4: Transient TTL Is Not Testable Without Time Manipulation

**What goes wrong:**
`Request_Stash` uses 5-minute transient TTL (`TTL = 300`). `Sudo_Session`'s 2FA
pending state also uses a transient with TTL. In integration tests, `set_transient()`
works against the real WordPress database, but the TTL is controlled by wall-clock
time — tests cannot simply "advance time" without either sleeping (slow, brittle) or
replacing the clock.

If a test writes a transient and immediately reads it, TTL is irrelevant. But tests
that need to verify expiry behavior (e.g., "stash expires after 5 minutes and returns
null") will either require real sleep or a fake-clock mechanism.

**Why it happens:**
Developers assume they can test TTL expiry in integration tests the same way they
test it in unit tests (by mocking `time()`). In a real WP environment, `time()` is
a PHP built-in — it cannot be mocked without Patchwork, which is intentionally
excluded from integration tests (see Pitfall 2).

**How to avoid:**
Split TTL-expiry tests into two categories:
1. **Unit tests (Brain\Monkey):** Mock `time()` via Patchwork to test expiry logic.
   These already exist and should remain unit tests.
2. **Integration tests:** Only test the happy path (transient written, transient read
   before expiry). Test the deletion path (explicit `delete_transient`) separately.
   Do NOT write integration tests that assert on TTL expiry behavior unless you
   accept using `sleep()`.

If future requirements demand expiry integration tests, introduce a `Clock` interface
wrapper around `time()` that can be substituted in tests. This is a larger refactor
that should be scoped as its own task, not assumed to be free.

**Warning signs:**
- Integration tests using `sleep(301)` to test transient expiry
- Flaky transient tests that pass locally but fail in CI due to timing
- Attempts to Patchwork `time()` in the integration test bootstrap

**Phase to address:**
Integration test harness scaffolding (document the boundary). Session binding and
stash integration test phases (enforce the pattern).

---

### Pitfall 5: Two Factor Plugin Integration Test Requires Loading a Real Plugin

**What goes wrong:**
The unit tests use a custom `Two_Factor_Core` stub in `tests/bootstrap.php`. Integration
tests that test the real 2FA flow need the actual Two Factor plugin loaded. Loading a
second plugin during integration tests requires: (1) the plugin file to be present on
disk in the test WordPress installation's `wp-content/plugins/` directory, (2) the plugin
to be activated in the test database, or (3) manually `require_once`-ing the plugin
file in the integration bootstrap.

If the integration bootstrap naively does `require_once WP_PLUGIN_DIR . '/two-factor/two-factor.php'`
with the stub `Two_Factor_Core` already defined from the unit test bootstrap, PHP will throw
a fatal "Cannot redeclare class Two_Factor_Core" error.

**Why it happens:**
The unit test bootstrap defines `Two_Factor_Core` and `Two_Factor_Provider` as global class
stubs. If any part of the integration test environment loads those stubs before loading the
real plugin, the fatal redeclaration occurs. The integration bootstrap must NOT load the
unit test `bootstrap.php` — it is a completely separate entry point.

**How to avoid:**
The integration test bootstrap must be written from scratch — it cannot include or
`require_once` the unit test `tests/bootstrap.php`. The integration bootstrap should:
1. Define `WP_TESTS_PHPUNIT_POLYFILLS_PATH` constant pointing to the plugin's
   `vendor/yoast/phpunit-polyfills` directory.
2. Load the WP test suite's `bootstrap.php` (not the plugin's unit test bootstrap).
3. Activate WP Sudo and Two Factor plugins using WordPress's `activate_plugin()` or
   by adding them to the `$active_plugins` option before tests run.
4. Never define the stub `Two_Factor_Core` class — the real class must be used.

**Warning signs:**
- "Cannot redeclare class Two_Factor_Core" fatal errors
- Two Factor plugin features silently returning stub behavior during integration tests
- `Two_Factor_Core::is_user_using_two_factor()` always returning the stub's value

**Phase to address:**
Two Factor integration test phase. This should be the last integration test written,
after simpler session/stash tests are working, because the plugin loading complexity
is the highest risk item.

---

### Pitfall 6: Multisite Integration Tests Require a Separate WordPress Installation Configuration

**What goes wrong:**
Multisite integration tests require WordPress to be installed in network mode — a different
database state and different `wp-config.php` constants (`MULTISITE`, `SUBDOMAIN_INSTALL`,
etc.) than single-site. You cannot test both single-site and multisite behavior in the same
PHPUnit run with a single WP installation.

The WP test harness supports a `WP_TESTS_MULTISITE` environment variable that switches the
installation mode. However, single-site tests and multisite tests cannot share the same
database state — the multisite mode adds `wp_blogs`, `wp_sitemeta`, and network-level tables
that don't exist in single-site.

**Why it happens:**
Developers set up a single test database, write both single-site and multisite tests, and
run them together. Multisite tests that rely on `is_multisite()` returning `true` fail because
the installation is single-site, or worse, silently pass because `Request_Stash` falls back
to single-site transients when `is_multisite()` returns false.

**How to avoid:**
Define multisite integration tests in a separate PHPUnit test suite (`phpunit-integration.xml.dist`
with a `multisite` suite group) and document that they require running with
`WP_TESTS_MULTISITE=1` in the environment. CI should run two matrix jobs: one for single-site
and one for multisite. Locally, the multisite tests should be opt-in (`composer test:integration:ms`).

**Warning signs:**
- `get_site_transient()` returning false in multisite integration tests
- `is_multisite()` returning `false` in tests tagged `@group ms-required`
- `network_admin_url()` resolving to the wrong path during multisite tests

**Phase to address:**
Multisite integration test phase. Do not attempt multisite tests until single-site
integration tests are passing and stable.

---

## Technical Debt Patterns

Shortcuts that seem reasonable but create long-term problems.

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Putting integration tests in same PHPUnit config as unit tests | Single `composer test` command | Brain\Monkey and WP_UnitTestCase are mutually incompatible; one or both will fail | Never |
| Copying unit test stubs (Two_Factor_Core, WP_User) into integration bootstrap | Faster initial setup | Fatal redeclaration errors when real plugins are loaded | Never |
| Using `sleep()` to test transient TTL expiry | Tests expiry behavior end-to-end | Slow test suite; flaky in CI under load | Only as a last resort for a single documented test, with a `@group slow` tag |
| Omitting `WP_TESTS_PHPUNIT_POLYFILLS_PATH` from integration bootstrap | One less constant to define | WP core bootstrap cannot find polyfills; fatal error in CI | Never |
| Running integration tests against production database | No separate DB setup needed | Destroys production data; unacceptable | Never |
| Testing WP 7.0 compatibility only against the live trunk | Exercises real WP code | Trunk is unstable; test failures from WP bugs, not plugin bugs | Only in a separate CI matrix job that is allowed to fail |

---

## Integration Gotchas

Common mistakes when connecting to external services or test infrastructure.

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| wordpress-develop test suite | Loading WP bootstrap before defining `WP_TESTS_PHPUNIT_POLYFILLS_PATH` | Define the constant in your integration `bootstrap.php` BEFORE calling the WP bootstrap file |
| wordpress-develop test suite | Using `wp scaffold plugin-tests` output verbatim — it generates an outdated `install-wp-tests.sh` that assumes PHPUnit 5–7 | Scaffold as a starting point, but update for PHPUnit polyfills and your PHPUnit 9.6 constraint |
| Two Factor plugin | Loading the plugin by path without first checking whether the unit test stub class is already declared | Write the integration bootstrap from scratch; never include unit test bootstrap |
| MySQL test database | Using the same DB name for unit tests and integration tests | Use a distinct DB (`wordpress_test` vs `wordpress_integration`) and document in README |
| WP 7.0 beta | Running tests against trunk without a fallback plan | Pin a specific WP version for the stable CI matrix; let trunk be a separate allowed-failure job |
| LLM-generated test code | Accepting fabricated method names for WP_UnitTestCase assertions without verification | Every assertion method must be verified against the phpunit-polyfills docs or PHPUnit 9.6 docs before committing |

---

## Performance Traps

Patterns that work at small scale but fail as usage grows.

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| Integration tests that hit the real DB for every assertion | Tests pass but take 30+ seconds | Use transactions: `WP_UnitTestCase` wraps each test in a DB transaction and rolls back — rely on this | When test suite grows to 50+ integration tests |
| Integration tests that create real users with `wp_insert_user()` without cleanup | User table grows; tests pollute each other | Use `$this->factory->user->create()` from WP_UnitTestCase's factory — it auto-cleans | After 20+ test runs |
| Loading all plugin files in integration bootstrap including admin pages | Admin page hooks fire during CLI bootstrap; wp_die() may be called | Activate plugin via `activate_plugins()` in bootstrap; individual test classes load admin dependencies as needed | Immediately on first CI run |
| Progressive delay `sleep()` in `Sudo_Session::record_failed_attempt()` | Integration tests for rate limiting take 5+ seconds per failed-attempt cycle | Mock or bypass the delay in tests via a test-only filter or by testing at the unit level | Any test that exercises 4+ failed attempts |

---

## Security Mistakes

Domain-specific security issues in testing that can produce false confidence.

| Mistake | Risk | Prevention |
|---------|------|------------|
| Testing `wp_check_password()` with a plaintext password stored in test DB via `wp_insert_user()` without bcrypt | Test passes with MD5 hash; integration test does not exercise bcrypt (WP 6.8+) behavior | Create test users with `wp_create_user()` which goes through the full password hashing pipeline, NOT by directly inserting a known hash |
| Asserting session is active by checking only user meta, not cookie token | Integration test verifies session was stored but misses the cookie binding — the most important security property | Always assert both: (1) user meta `_wp_sudo_token` exists, and (2) `$_COOKIE[Sudo_Session::TOKEN_COOKIE]` matches |
| Integration tests that disable nonce verification globally | Tests pass but nonce bypass is never caught | Never add `define('DOING_AJAX', true)` or similar globals that disable nonce checks; test nonce behavior explicitly |
| LLM-generated test assertions using fabricated hook names or filter names | Test passes because the fabricated hook never fires (no-op), not because behavior is correct | Verify every `do_action`/`apply_filters` name against the plugin's own `includes/` source before writing assertions |
| Writing WP 7.0 "compatibility tests" that only check `is_wp_version_compatible()` | Returns true but does not exercise the actual changed behavior | Write tests that exercise the specific WP 7.0 feature (admin refresh CSS, Abilities API) if the plugin interacts with it |

---

## UX Pitfalls

User experience mistakes in this domain (the plugin developer's experience running tests).

| Pitfall | Developer Impact | Better Approach |
|---------|-----------------|-----------------|
| Integration test suite with no separation between slow and fast tests | Developers stop running tests locally because they take too long | Tag DB-heavy tests `@group integration`; keep unit tests in `composer test` (fast); run integration separately |
| Integration tests that output debug info to STDOUT | PHPUnit's strict output mode (`beStrictAboutOutputDuringTests`) fails the test | Never `echo` or `var_dump` in integration tests; use PHPUnit assertions and WP's `$this->fail()` |
| No clear error message when WP test environment is not installed | Cryptic "bootstrap.php not found" fatal errors | Add an explicit check at the top of integration bootstrap with a human-readable error: "Run composer run install-wp-tests first" |
| WP 7.0 visual check buried in manual testing guide | Visual regression from admin refresh goes undetected until after release | Add a specific WP 7.0 visual check section to `tests/MANUAL-TESTING.md` |

---

## "Looks Done But Isn't" Checklist

Things that appear complete but are missing critical pieces.

- [ ] **Integration test bootstrap:** Often missing `define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', ...)` — verify the constant is defined before loading the WP test suite bootstrap, or polyfill autoloading will fail silently or fatally.
- [ ] **Cookie/session integration tests:** Often missing the cookie token assertion — verify tests assert `$_COOKIE[Sudo_Session::TOKEN_COOKIE]` was populated, not just user meta.
- [ ] **Two Factor plugin loading:** Often missing class-existence guard — verify the integration bootstrap never loads the unit test stub `Two_Factor_Core` before loading the real plugin.
- [ ] **Multisite transient path:** Often missing the `set_site_transient` branch test — verify integration tests cover the multisite transient path in `Request_Stash`, not just the single-site path.
- [ ] **WP 7.0 "Tested up to" bump:** Often deferred — verify `readme.txt`, `readme.md`, and `docs/security-model.md` all have WP 7.0 in their version references before the GA release (April 9, 2026).
- [ ] **PHPUnit schema version match:** Often left as the old version — verify `phpunit-integration.xml.dist` uses the correct schema URI for PHPUnit 9.x (`http://schema.phpunit.de/9.6/phpunit.xsd`) not the one from unit tests or a newer version.
- [ ] **LLM-generated method names:** After any LLM-assisted test writing, verify every `WP_UnitTestCase` method, WordPress function, and Third-party class method against its live source before committing.

---

## Recovery Strategies

When pitfalls occur despite prevention, how to recover.

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| PHPUnit version collision | MEDIUM | Separate the configs into two files; restore unit tests to original config; start integration config fresh |
| Brain\Monkey contamination in integration tests | MEDIUM | Grep for `Brain\Monkey` in integration bootstrap and remove; ensure integration bootstrap has its own setup/teardown |
| Patchwork intercepting real `setcookie` in integration tests | LOW | Remove Patchwork require from integration bootstrap; rewrite cookie assertions to check `$_COOKIE` superglobal mutation instead of header output |
| "Cannot redeclare class Two_Factor_Core" fatal | LOW | Remove all stub class definitions from integration bootstrap; load real plugin after WP bootstrap completes |
| Multisite tests polluting single-site test DB | HIGH | Drop and recreate test DB; separate DB names in CI matrix; document the separation in README |
| LLM-fabricated assertion method breaking test run | LOW | Check PHPUnit 9.6 docs for the real method name; add to `llm_lies_log.txt` |
| WP 7.0 admin refresh breaks plugin CSS | MEDIUM | Run visual diff against WP 6.9 and WP 7.0 on manual testing guide; identify specific selectors affected; fix and add to manual check |

---

## Pitfall-to-Phase Mapping

How roadmap phases should address these pitfalls.

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| PHPUnit version collision (Pitfall 1) | Integration harness scaffolding | `composer test` (unit) and `composer test:integration` both pass independently |
| Brain\Monkey contamination (Pitfall 2) | Integration harness scaffolding | No Brain\Monkey imports in integration bootstrap; both suites pass |
| WP_UnitTestCase cannot test real cookies (Pitfall 3) | Integration harness scaffolding + session binding tests | Cookie tests verify user meta + `$_COOKIE` superglobal only; documented boundary in bootstrap |
| Transient TTL not testable (Pitfall 4) | Integration harness scaffolding | No `sleep()` calls in integration tests; TTL expiry covered by existing unit tests |
| Two Factor plugin loading (Pitfall 5) | Two Factor integration test phase | No stub class redeclaration errors; real `Two_Factor_Core::is_user_using_two_factor()` called |
| Multisite separate config (Pitfall 6) | Multisite integration test phase | `WP_TESTS_MULTISITE=1` matrix job documented; multisite tests tagged `@group ms-required` |
| WP 7.0 visual regression (admin refresh) | WP 7.0 compatibility check phase | Manual testing guide has WP 7.0 section; all admin pages verified on Beta 1 and RC |
| LLM method confabulation (all phases) | Every phase | Every new external API reference in tests verified against live source before merge; added to `llm_lies_log.txt` if fabricated |

---

## Sources

- `wordpress-develop` trunk `composer.json` (fetched 2026-02-19) — confirms WP 7.0.0 target; PHPUnit not in require-dev; `yoast/phpunit-polyfills ^1.1.0` is the bridge
  - URL: `https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/composer.json`
- `wordpress-develop` trunk `phpunit.xml.dist` (fetched 2026-02-19) — schema references PHPUnit 9.2; bootstrap at `tests/phpunit/includes/bootstrap.php`
  - URL: `https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/phpunit.xml.dist`
- `wordpress-develop` trunk `tests/phpunit/includes/bootstrap.php` (fetched 2026-02-19) — confirms `WP_TESTS_PHPUNIT_POLYFILLS_PATH` constant pattern; minimum PHPUnit 5.7.21; polyfills minimum 1.1.0
  - URL: `https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/tests/phpunit/includes/bootstrap.php`
- Yoast PHPUnit Polyfills README (fetched 2026-02-19) — confirms 4.x series supports PHPUnit 7.5–12.x; 1.x series supports 4.8–9.x
  - URL: `https://raw.githubusercontent.com/yoast/phpunit-polyfills/main/README.md`
- WP Sudo `composer.json` — confirms PHPUnit 9.6 constraint; Brain\Monkey 2.7; Patchwork via Brain\Monkey
- WP Sudo `patchwork.json` — confirms `setcookie`, `header`, `hash_equals` are redefined at PHP level
- WP Sudo `tests/bootstrap.php` — confirms Two_Factor_Core and Two_Factor_Provider stubs are globally declared
- WP Sudo `tests/TestCase.php` — confirms Brain\Monkey setup/teardown pattern; static cache reset on teardown
- WP Sudo `includes/class-sudo-session.php` — confirms `setcookie()` calls, `$_COOKIE` superglobal mutations, transient TTL usage, `sleep()` in `record_failed_attempt()`
- WP Sudo `includes/class-request-stash.php` — confirms `set_transient`/`set_site_transient` branching, 300-second TTL
- WP Sudo `docs/roadmap-2026-02.md` — maintainer assessment of integration test gaps and WP 7.0 impact
- WP Sudo `llm_lies_log.txt` — documented history of fabricated class names, method names, and meta keys; informs LLM-confabulation pitfall severity

---
*Pitfalls research for: WordPress security plugin integration tests and WP 7.0 compatibility*
*Researched: 2026-02-19*
