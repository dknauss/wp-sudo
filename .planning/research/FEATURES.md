# Feature Research

**Domain:** WordPress security plugin — integration test suite + WP 7.0 compatibility
**Researched:** 2026-02-19
**Confidence:** MEDIUM-HIGH

> Note on sources: WebSearch and WebFetch were unavailable during this research session.
> Findings draw from the project's own codebase (HIGH confidence), the existing roadmap
> assessment in `docs/roadmap-2026-02.md` (HIGH confidence — authored by the project
> maintainer), and the codebase mapping in `.planning/codebase/` (HIGH confidence).
> Claims about WordPress ecosystem norms are based on training data (MEDIUM confidence)
> and are flagged where verification against official sources is recommended.

---

## Feature Landscape

### Table Stakes (Users Expect These)

For an integration test suite on a security plugin, "users" are the plugin's maintainers
and contributors. These are the features without which the test suite is not credible —
i.e., the gaps it was built to fill have not been closed.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| **Full reauth flow (end-to-end)** | Core user journey — Gate intercepts → stashes → Challenge verifies → Session activates → Stash replays. Currently never tested as a whole; Mockery stubs hide interface mismatches across the five classes involved. | HIGH | Requires real WP DB, real transients, real user meta. This is the single highest-value integration test. |
| **Real `wp_check_password()` with bcrypt** | WP 6.8+ defaults to bcrypt; every existing test mocks this. An unverified password hash path is an untested security control. | MEDIUM | PHP's `password_verify()` is exercised under the hood. No test environment setup needed beyond a real WordPress install. WP 6.8 fact confirmed via CLAUDE.md context. |
| **Session token binding (real cookie + real meta)** | Token is stored in two places (user meta SHA-256 hash + httponly cookie). A mismatch that doesn't surface in unit tests could break all session validation silently. | MEDIUM | Requires a real HTTP response cycle or at minimum real `setcookie()` behavior — not the Patchwork stub. |
| **Transient TTL enforcement** | Stash TTL is 300s (5 min); 2FA pending TTL is configurable (default 10 min). The current unit tests mock `Request_Stash` entirely — expiry is never exercised. | MEDIUM | Can use WP's built-in transient infrastructure. Test pattern: set transient, advance time (or use a past timestamp), verify retrieval returns false. |
| **Upgrader migration chain against real DB** | `Upgrader::maybe_upgrade()` runs sequential routines (2.0.0 → 2.1.0 → 2.2.0). Logic is unit-tested but actual `update_option()` / `delete_option()` mutations are mocked. A regression in the migration that corrupts options would only surface in integration. | MEDIUM | Scaffold: insert "old version" options, run upgrader, assert final DB state. |
| **Test harness setup (WP_UnitTestCase)** | All integration tests depend on a real WordPress + database environment. Without the harness, no integration tests can run. This is the prerequisite infrastructure for the entire suite. | HIGH | Requires `wp scaffold plugin-tests` or equivalent (yoast/wp-test-utils as an alternative). Adds `lucatume/wp-browser` or the official `wordpress-develop` test scaffolding. **Must be first.** |
| **WP 7.0 functional verification (manual test guide)** | WP 7.0 Beta 1 shipped today (2026-02-19); GA is April 9. The manual testing guide (`tests/MANUAL-TESTING.md`) needs to be executed against 7.0-beta/RC to confirm no regressions before the "Tested up to" bump. | LOW | Not a new test to write — execution of the existing guide. Regression would be caught here before automated tests exist for it. |
| **"Tested up to" version bump** | WordPress.org plugin directory requires an accurate "Tested up to" value. Shipping a plugin that hasn't declared WP 7.0 compatibility creates unnecessary user friction. | LOW | Must happen by April 9, 2026. Requires successful manual test run first. |

### Differentiators (Competitive Advantage)

Features beyond what typical WordPress security plugins test. These represent higher-quality
coverage that demonstrates the test suite takes security properties seriously, not just
happy-path behavior.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| **REST API gating with real auth (cookie + app passwords)** | REST gating is a live security boundary. Cookie auth (browser-initiated REST) and application password auth have different code paths through the Gate. Testing both with real auth — not mocked — verifies the actual security boundary, not a simulation of it. | HIGH | App password tests require a real WordPress REST stack. The Studio dev environment strips Authorization headers (PHP built-in server limitation); use Local (nginx) for app password tests. Confirmed via `tests/MANUAL-TESTING.md`. |
| **Two Factor plugin interaction (real plugin installed)** | WP Sudo's 2FA integration uses `class_exists('Two_Factor_Core')` detection. The current unit tests use a minimal stub. Installing the real Two Factor plugin in the test harness and exercising `is_user_using_two_factor()`, `get_primary_provider_for_user()`, and the 2FA pending transient flow validates the real integration contract. | HIGH | Requires Two Factor plugin in the test harness (installable via Composer test fixtures or manual setup). Confirms method signatures haven't drifted from the stub. MEDIUM confidence on exact method names — must verify against live source before implementing. |
| **Multisite session isolation** | WP Sudo sessions are per-user, per-browser. The INTEGRATIONS.md note states sessions are "network-wide" (authenticating on one site covers all network sites) — but the `_wp_sudo_expires` and `_wp_sudo_token` meta are stored per-user. A cross-site session leak would be a security defect invisible to unit tests. | HIGH | Requires multisite test harness configuration. Test pattern: activate session on site A, attempt gated action on site B as same user. |
| **Audit hook firing verification** | The plugin fires 9 action hooks (`wp_sudo_activated`, `wp_sudo_reauth_failed`, `wp_sudo_lockout`, `wp_sudo_action_gated`, `wp_sudo_action_replayed`, etc.). These hooks are the public API for audit logging integrations. Integration tests can verify the hooks fire with correct arguments in real workflows — not just that `add_action()` was called. | MEDIUM | Extend the full reauth flow test with `did_action()` assertions. This verifies the audit surface is intact, not just that hooks were registered. |
| **Capability tamper detection (canary test)** | `Plugin::enforce_editor_unfiltered_html()` runs at `init` priority 1 and strips `unfiltered_html` if it reappears on the Editor role. This is a tamper-detection canary. Integration testing can grant the capability directly via `$role->add_cap()`, trigger the hook, and assert the capability was removed and `wp_sudo_capability_tampered` fired. | MEDIUM | Tests the tamper detection actually works in a real WP environment, not just that `get_role()` was called correctly. |
| **Rate limiting with real meta persistence** | Rate limiting (5 attempts → lockout) stores `_wp_sudo_failed_attempts` and `_wp_sudo_lockout_until` in user meta. Unit tests verify the logic but mock `update_user_meta()`. An integration test exercises the full attempt counter across multiple calls with real DB reads/writes. | MEDIUM | Tests that the counter persists correctly across calls within the same request simulation, and that lockout state is durable. |
| **WP 7.0 Abilities API surface assessment** | WP 7.0 adds an Abilities API with a new REST surface. Current state: only 3 read-only abilities exist. The roadmap assessment recommends monitoring for destructive abilities. A documented assessment (not code yet) that evaluates whether any WP 7.0 abilities trigger existing REST gating rules is a differentiating quality signal. | LOW | Can be a doc deliverable rather than a test. Feeds future gate surface additions. The roadmap assessment in `docs/roadmap-2026-02.md` is a strong starting point. |
| **Hook timing / priority verification** | The Gate registers at `admin_init` priority 1. The expected invariant is that WP Sudo runs before other plugin hooks at the default priority 10. An integration test can register a competing hook and verify the Gate fires first. | MEDIUM | Verifies the priority assumption holds in a real WP boot sequence, not just in isolation. |

### Anti-Features (Commonly Requested, Often Problematic)

Features that seem like reasonable test additions but should be explicitly rejected for this
milestone.

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| **Admin UI rendering tests** | "Let's make sure the settings page renders correctly" is a natural impulse after visual changes like WP 7.0's admin refresh. | HTML output testing in PHPUnit is brittle, prone to false failures on whitespace/markup changes, and provides low security value. The settings page output is already exercised more effectively by the manual testing guide. | Execute `tests/MANUAL-TESTING.md` against WP 7.0-beta. Flag visual regressions in the manual checklist. If automated visual testing is desired, create a separate Playwright milestone. |
| **JavaScript / admin bar countdown timer tests** | The admin bar countdown timer is user-visible and important. It would seem natural to test it. | JS unit testing requires a separate toolchain (Jest, Playwright). Adding it to this milestone dilutes focus and creates a build step that doesn't currently exist. The PHP side (time_remaining() output) is already unit tested. | Unit test the PHP API that feeds the timer. Create a separate E2E milestone for JS behavior. |
| **CSS / asset loading tests** | "Can I assert that assets are enqueued on the right pages?" is a common PHPUnit question. | Enqueue logic (`wp_enqueue_style()`, `wp_enqueue_script()`) is already covered by `AdminTest.php` and `ChallengeTest.php` unit tests using Brain\Monkey. Adding integration tests for this provides no additional safety — the hook registration is tested, and asset delivery is a server configuration concern, not a plugin logic concern. | Keep asset enqueuing in unit tests where it belongs. |
| **E2E browser tests (Playwright / Cypress)** | Full browser tests would cover the entire user flow including JavaScript interactions, accessibility checks, and visual states. | Playwright/Cypress tests require a separate toolchain, a running WordPress server, and significantly more setup time. This is valuable but is a separate milestone. Starting E2E testing while the integration harness doesn't exist yet is putting the cart before the horse. | Explicit out-of-scope in PROJECT.md. Create a dedicated E2E milestone after integration tests are stable. |
| **Performance / load testing** | "How does the Gate hold up under 1000 concurrent requests?" is a valid production concern. | Performance testing requires specialized tooling (k6, Locust) and representative infrastructure. It is entirely disconnected from the current milestone goals of closing security test gaps. | Not a WordPress plugin testing concern at this scale. If hosting providers raise concerns, address with infrastructure configuration guides. |
| **Negative WP 7.0 compatibility tests** | "Write a test that verifies the plugin fails gracefully on WP 6.9" (or below). | Backward compatibility is verified by the minimum version declaration in plugin metadata and by CI running against the declared minimum. Testing failure scenarios for unsupported versions does not add coverage value; it adds maintenance burden. | Rely on the declared `Requires at least: 6.2` in `readme.txt` and manual testing on the minimum supported version. |
| **Direct database SQL tests** | "Test that the right SQL runs for transient operations." | Transients use `set_transient()` / `get_transient()` — the plugin never writes raw SQL. Testing at the SQL level couples tests to WordPress's internal transient implementation, which can change. Testing behavior (does the stash expire?) is correct; testing implementation (what SQL ran?) is not. | Test transient behavior through the WordPress API as integration tests naturally do. |

---

## Feature Dependencies

```
[Test harness setup (WP_UnitTestCase)]
    └──required by──> [Full reauth flow test]
    └──required by──> [Real bcrypt verification]
    └──required by──> [Session token binding]
    └──required by──> [Transient TTL enforcement]
    └──required by──> [Upgrader migration chain]
    └──required by──> [REST API gating tests]
    └──required by──> [Two Factor plugin interaction]
    └──required by──> [Multisite session isolation]
    └──required by──> [Audit hook firing verification]
    └──required by──> [Capability tamper detection]
    └──required by──> [Rate limiting with real meta]
    └──required by──> [Hook timing / priority]

[Full reauth flow test]
    └──enhances──> [Audit hook firing verification]
        (add did_action() assertions to existing flow test)
    └──prerequisite for──> [REST API gating tests]
        (must understand the base flow before testing surface variants)

[WP 7.0 functional verification (manual)]
    └──gate for──> ["Tested up to" version bump]
        (cannot bump until manual verification passes)

[Multisite test harness]
    └──required by──> [Multisite session isolation]
        (harness must be configured for multisite before isolation test can run)

[Two Factor plugin installed in harness]
    └──required by──> [Two Factor plugin interaction]
```

### Dependency Notes

- **Test harness is the absolute prerequisite:** Every integration test depends on it. The harness setup is the entire first phase of integration test work. No other integration feature can start until it exists.
- **Full reauth flow unlocks audit hook verification:** Rather than writing audit hook tests in isolation, extend the reauth flow test with `did_action()` / `do_action()` assertions. This approach tests both the workflow and the audit surface together.
- **Multisite requires a separate harness configuration:** A single-site harness cannot test multisite behavior. Either the harness supports both modes (e.g., via test groups), or a separate multisite-configured harness is needed. This is the highest-setup-cost differentiator.
- **Two Factor method names must be verified before implementation:** The unit test bootstrap stubs `Two_Factor_Core::is_user_using_two_factor()` and `Two_Factor_Core::get_primary_provider_for_user()`. Before writing integration tests against the real plugin, verify these method signatures against the live Two Factor plugin source (`https://raw.githubusercontent.com/WordPress/two-factor/master/class-two-factor-core.php`). Documented in `CLAUDE.md` verification requirements.
- **"Tested up to" bump depends on WP 7.0 GA date:** GA is April 9, 2026. The bump cannot ship before GA; functional verification should run against RC builds.

---

## MVP Definition

This is a subsequent milestone (v2.4), not a greenfield MVP. "MVP" here means: what is the minimum viable integration test suite that closes the most critical security test gaps?

### Launch With (v2.4.0 — Integration Core)

The test harness plus the tests that cover real security boundaries not reachable by mocks:

- [ ] **Test harness setup** — WP_UnitTestCase scaffold, PHPUnit configuration, `tests/Integration/` directory structure, integration-specific bootstrap — *everything else requires this*
- [ ] **Full reauth flow test** — Gate intercept → stash → Challenge verify → Session activate → Stash replay, with real WordPress functions throughout — *the core user journey, highest risk if broken*
- [ ] **Real bcrypt verification test** — `wp_check_password()` with an actual bcrypt hash, correct and incorrect password paths — *WP 6.8+ default behavior, never exercised*
- [ ] **Session token binding test** — activate session, read cookie + meta, verify match; expire session, verify `is_active()` returns false — *cryptographic binding, the key security property*
- [ ] **Transient TTL enforcement test** — stash a request, advance time past TTL, verify retrieval returns false — *security property: stale stashes must not be replayable*
- [ ] **WP 7.0 functional verification** — execute `tests/MANUAL-TESTING.md` against WP 7.0 Beta 1 (today) through RC — *required for compatibility declaration*

### Add After Core Is Stable (v2.4.x)

- [ ] **Upgrader migration chain** — *Important but lower risk than the five security boundaries above; migration logic is already unit-tested*
- [ ] **REST API gating (cookie auth + app passwords)** — *High value but requires careful harness setup for app password auth; add once the core harness is solid*
- [ ] **Audit hook firing verification** — *Extend the reauth flow test; add when that test exists*
- [ ] **"Tested up to" version bump** — *Trigger: WP 7.0 GA (April 9, 2026) + successful manual verification*
- [ ] **Rate limiting with real meta persistence** — *Medium priority; unit tests cover the logic*

### Future Consideration (v2.5+)

- [ ] **Two Factor plugin interaction** — *High value but requires Two Factor plugin in the harness; higher setup cost; defer until core harness is proven*
- [ ] **Multisite session isolation** — *Critical security property but highest setup cost; needs multisite harness config*
- [ ] **Capability tamper detection** — *Niche but interesting; defer until higher-value tests are complete*
- [ ] **Hook timing / priority verification** — *Nice to have; defer*
- [ ] **WP 7.0 Abilities API assessment document** — *Valuable for future planning but no code required; write when Abilities API stabilizes post-GA*

---

## Feature Prioritization Matrix

| Feature | Security Value | Implementation Cost | Priority |
|---------|----------------|---------------------|----------|
| Test harness setup | HIGH (enabler) | HIGH | P1 |
| Full reauth flow | HIGH | HIGH | P1 |
| Real bcrypt verification | HIGH | LOW | P1 |
| Session token binding | HIGH | MEDIUM | P1 |
| Transient TTL enforcement | HIGH | MEDIUM | P1 |
| WP 7.0 functional verification | HIGH (compat) | LOW | P1 |
| Upgrader migration chain | MEDIUM | MEDIUM | P2 |
| REST API gating | HIGH | HIGH | P2 |
| Audit hook firing | MEDIUM | LOW | P2 |
| "Tested up to" bump | MEDIUM (compat) | LOW | P2 |
| Rate limiting (real meta) | MEDIUM | MEDIUM | P2 |
| Two Factor interaction | HIGH | HIGH | P3 |
| Multisite session isolation | HIGH | HIGH | P3 |
| Capability tamper detection | MEDIUM | MEDIUM | P3 |
| Hook timing / priority | LOW | MEDIUM | P3 |
| Abilities API assessment doc | LOW | LOW | P3 |

**Priority key:**
- P1: Must have for v2.4.0 launch — closes the most critical security test gaps
- P2: Should have, add in v2.4.x — important but less urgent than P1
- P3: Nice to have, future milestone (v2.5+) — valuable but high setup cost or lower risk

---

## Competitor / Reference Analysis

Integration test patterns from mature WordPress security plugins (MEDIUM confidence —
based on training data; WebFetch unavailable to verify against live sources):

| Coverage Area | Typical Security Plugins | WP Sudo Today | WP Sudo v2.4 Target |
|---------------|--------------------------|---------------|---------------------|
| Password verification | Real `wp_check_password()` | Mocked (Patchwork) | Real bcrypt via WP_UnitTestCase |
| Transient expiry | Real TTL with time manipulation | Mocked | Real transient TTL enforcement |
| REST auth | Real application passwords | Mocked via `rest_get_authenticated_app_password()` | Real cookie + app password flows |
| Database state | Real `update_option()` / `get_user_meta()` | Mocked via Brain\Monkey | Real DB reads/writes |
| Hook firing | `did_action()` assertions | Not tested in integration | Extend reauth flow test |
| 2FA plugin | Real plugin installed in harness | Custom stub class | Real Two Factor plugin (v2.5) |
| Multisite | Separate multisite test matrix | Not tested | v2.5 scope |

**Key observation:** The gap between WP Sudo's current test suite and mature security
plugin test suites is not in unit test breadth (where WP Sudo is strong at ~220 methods)
but in the use of real WordPress infrastructure for security-critical code paths. The
Brain\Monkey approach is excellent for logic isolation but systematically prevents testing
the actual security boundaries (real password hashing, real cookie attributes, real transient
expiry). That is the gap this milestone closes.

---

## Sources

- `docs/roadmap-2026-02.md` — Maintainer's integration test gap analysis (HIGH confidence); authored 2026-02-19
- `tests/MANUAL-TESTING.md` — Current manual testing scope (HIGH confidence)
- `.planning/codebase/TESTING.md` — Codebase testing pattern analysis (HIGH confidence)
- `.planning/codebase/INTEGRATIONS.md` — Data storage and auth integration audit (HIGH confidence)
- `.planning/PROJECT.md` — Active milestone scope and out-of-scope declarations (HIGH confidence)
- `tests/bootstrap.php` — Current unit test stubs, revealing what is and isn't mocked (HIGH confidence)
- `patchwork.json` — Patchwork-redefined PHP internals (`setcookie`, `header`, `hash_equals`) (HIGH confidence)
- `composer.json` — Current dev dependencies (no WP_UnitTestCase scaffolding present) (HIGH confidence)
- WordPress core password hashing (bcrypt default since WP 6.8) — confirmed via CLAUDE.md project context
- WP 7.0 Beta 1 release date (2026-02-19) — confirmed via `docs/roadmap-2026-02.md`
- WP 7.0 GA date (April 9, 2026) — confirmed via `docs/roadmap-2026-02.md`
- WordPress plugin integration testing norms (WP_UnitTestCase, `wp scaffold plugin-tests`) — MEDIUM confidence, training data; verify official docs before implementing harness

---

*Feature research for: WP Sudo v2.4 — Integration Tests & WP 7.0 Readiness*
*Researched: 2026-02-19*
