# Roadmap: WP Sudo v2.4

**Milestone:** Integration Tests & WP 7.0 Readiness
**Created:** 2026-02-19
**Depth:** Standard (5 phases)
**Deadline:** WP 7.0 GA — April 9, 2026 (Phase 5 time-gated)

---

## Phase 1: Integration Test Harness Scaffold

**Goal:** A working integration test infrastructure that runs an empty test suite against a real WordPress + MySQL environment, coexisting cleanly with the existing Brain\Monkey unit tests.

**Requirements:** HARN-01, HARN-02, HARN-03, HARN-04, HARN-05, HARN-06, HARN-07

**Pitfalls addressed:** #1 (Brain\Monkey isolation), #2 (Patchwork exclusion), #3 (cookie boundary documented), #4 (TTL boundary documented)

**Key deliverables:**
- `composer require --dev yoast/phpunit-polyfills:"^2.0"`
- `phpunit-integration.xml.dist` (PHPUnit 9.6 schema, integration bootstrap)
- `tests/integration/bootstrap.php` (loads real WP via `WP_TESTS_DIR`, registers plugin at `muplugins_loaded`)
- `tests/integration/TestCase.php` (extends `WP_UnitTestCase`, provides `make_admin()` and `activate_plugin()` helpers)
- `bin/install-wp-tests.sh` (from `wp-cli/scaffold-command` template)
- Updated `composer.json` scripts: `test:unit`, `test:integration`
- `.github/workflows/phpunit.yml` with separate unit + integration CI jobs
- Verify `composer test` (unit only) still passes

**Success criteria:**
- `composer test:unit` passes (existing ~220 tests, no regressions)
- `composer test:integration` passes (empty suite, 0 tests, 0 failures)
- GitHub Actions CI runs both jobs with MySQL 8.0 service
- `tests/integration/bootstrap.php` contains zero Brain\Monkey or Patchwork references

**Estimated complexity:** HIGH (7 requirements, infrastructure-heavy, must get right before any tests)

---

## Phase 2: Core Security Integration Tests

**Goal:** The 4 highest-value integration tests that exercise real security boundaries — password hashing, session binding, request stashing, and the full reauthentication flow.

**Requirements:** INTG-01, INTG-02, INTG-03, INTG-04

**Key deliverables:**
- `tests/integration/SudoSessionTest.php` — real bcrypt via `wp_check_password()`, session token binding (user meta + `$_COOKIE`), session expiry
- `tests/integration/RequestStashTest.php` — real `set_transient()`/`get_transient()`, stash write/read/delete lifecycle
- `tests/integration/ReauthFlowTest.php` — full Gate → Challenge → Session → Stash → Replay cross-class workflow

**Success criteria:**
- `composer test:integration` runs 4+ test methods, all passing
- `wp_check_password()` exercises real bcrypt (cost=5 in test env)
- Session token verified via both `get_user_meta()` and `$_COOKIE` superglobal
- Transient write/read/delete verified via real WordPress transient API
- Full reauth flow exercises 5 classes (Gate, Action_Registry, Challenge, Sudo_Session, Request_Stash) without mocks

**Estimated complexity:** HIGH (cross-class workflows, TDD cycle for each test file)

---

## Phase 3: Surface Coverage Tests

**Goal:** Integration tests for the upgrader migration chain, REST API gating, audit hook firing, and rate limiting — the P2 features that close the next tier of test gaps.

**Requirements:** SURF-01, SURF-02, SURF-03, SURF-04, SURF-05

**Key deliverables:**
- `tests/integration/UpgraderTest.php` — migration chain against real database (v2.0.0 → v2.3.2)
- `tests/integration/RestGatingTest.php` — REST gating with real cookie auth + real application passwords
- Extend `ReauthFlowTest.php` with `did_action()` assertions for audit hooks
- `tests/integration/RateLimitingTest.php` — failed attempt counter + lockout with real user meta

**Success criteria:**
- Upgrader test inserts old-version options, runs `Upgrader::maybe_upgrade()`, asserts correct final DB state
- REST test sends authenticated requests and verifies `sudo_required` error response
- App password test creates real app password with policy override and verifies enforcement
- Audit hooks `wp_sudo_action_gated`, `wp_sudo_action_allowed`, `wp_sudo_action_replayed` fire with correct args
- Rate limiting test verifies `_wp_sudo_failed_attempts` counter increments in real user meta

**Estimated complexity:** MEDIUM-HIGH (5 requirements, REST auth setup has nuance)

---

## Phase 4: Advanced Coverage — Two Factor & Multisite

**Goal:** Integration tests for the two highest-setup-cost features: real Two Factor plugin interaction and multisite session isolation.

**Requirements:** ADVN-01, ADVN-02, ADVN-03

**Key deliverables:**
- Two Factor plugin installed in test harness (Composer or CI download)
- `tests/integration/TwoFactorTest.php` — real `Two_Factor_Core::is_user_using_two_factor()`, 2FA pending state machine, password step → 2FA step → session activation
- `tests/integration/MultisiteTest.php` — session activated on site A, `is_active()` false on site B
- CI matrix variant with `WP_TESTS_MULTISITE=1`

**Success criteria:**
- Two Factor method signatures verified against live plugin source before implementation
- TwoFactorTest exercises real `Two_Factor_Core` class (not stubs)
- MultisiteTest uses `switch_to_blog()` and verifies session isolation
- CI runs multisite variant in separate matrix job
- No fabricated class/method names (verified per CLAUDE.md)

**Estimated complexity:** HIGH (Two Factor loading is complex; multisite needs separate WP config)

---

## Phase 5: WP 7.0 Readiness

**Goal:** Verify compatibility with WordPress 7.0, bump version declarations, and document the Abilities API assessment.

**Requirements:** WP70-01, WP70-02, WP70-03, WP70-04

**Time gate:** WP70-03 ("Tested up to" bump) must wait for WP 7.0 GA (April 9, 2026). WP70-01 and WP70-02 can run against beta/RC builds now.

**Key deliverables:**
- Execute `tests/MANUAL-TESTING.md` against WP 7.0 beta/RC — document results
- Verify settings page, challenge page, admin bar countdown under WP 7.0 admin visual refresh (DataViews/design tokens)
- Update `readme.txt` and plugin header: `Tested up to: 7.0`
- Add WP 7.0 visual check section to `tests/MANUAL-TESTING.md`
- `docs/abilities-api-assessment.md` — evaluates current Abilities API surface, gating strategy for future destructive abilities

**Success criteria:**
- Manual testing guide passes on WP 7.0 RC with zero regressions
- Plugin CSS renders correctly under admin visual refresh
- "Tested up to" bumped in `readme.txt` and `wp-sudo.php`
- Abilities API document covers: current 3 read-only abilities, `permission_callback` pattern, when to add `ability` surface type to Gate

**Estimated complexity:** LOW-MEDIUM (mostly manual verification + documentation; version bump is trivial)

---

## Phase Dependencies

```
Phase 1 (Harness) ──required by──> Phase 2 (Core Tests)
Phase 2 (Core Tests) ──required by──> Phase 3 (Surface Tests)
Phase 1 (Harness) ──required by──> Phase 4 (Advanced)
Phase 5 (WP 7.0) ──independent──> can run in parallel with Phases 2-4
```

Phase 5 is partially parallelizable — manual testing can happen during any phase since dev sites are already on WP 7.0-alpha. The "Tested up to" bump is time-gated to April 9.

## Requirement Coverage

All 23 v2.4 requirements are mapped:
- Phase 1: 7 requirements (HARN-01 through HARN-07)
- Phase 2: 4 requirements (INTG-01 through INTG-04)
- Phase 3: 5 requirements (SURF-01 through SURF-05)
- Phase 4: 3 requirements (ADVN-01 through ADVN-03)
- Phase 5: 4 requirements (WP70-01 through WP70-04)
- Unmapped: 0

---
*Roadmap created: 2026-02-19*
*Last updated: 2026-02-19 after initial creation*
