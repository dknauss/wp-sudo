# Research Summary

**Milestone:** WP Sudo v2.4 — Integration Tests & WP 7.0 Readiness
**Synthesized:** 2026-02-19
**Sources:** 4 research docs (STACK.md, FEATURES.md, ARCHITECTURE.md, PITFALLS.md)
**Overall confidence:** HIGH — critical claims verified against live wordpress-develop trunk, phpunit-polyfills source, and WP Sudo codebase

---

## Consensus Decisions

These findings are consistent across all 4 research docs and should be treated as settled.

### Stack

| Decision | Rationale | Confidence |
|----------|-----------|------------|
| Add `yoast/phpunit-polyfills ^2.0` as only new dependency | WP core bootstrap mandates it; 2.x supports PHPUnit 5.7–10.x covering our locked 9.6 | HIGH |
| Keep PHPUnit 9.6 (do not upgrade) | WP core `phpunit.xml.dist` targets 9.2 schema; PHPUnit 11 requires PHP 8.2+ (above our 8.0 min) | HIGH |
| Use `install-wp-tests.sh` (SVN) not `@wordpress/env` | No Docker/Node.js needed; 15–20s setup vs 60–90s; standard for pure-PHP plugins | HIGH |
| GitHub Actions MySQL 8.0 service container | Standard pattern; use `127.0.0.1` not `localhost` to avoid socket failures in CI | HIGH |
| WP bcrypt cost=5 in test environment | `WP_UnitTestCase::wp_hash_password_options()` automatically reduces cost; real bcrypt but fast | HIGH |

### Architecture

| Decision | Rationale | Confidence |
|----------|-----------|------------|
| Two separate PHPUnit config files | Brain\Monkey and real WP are mutually exclusive; cannot share a bootstrap | HIGH |
| `tests/integration/` as sibling to `tests/Unit/` | Standard WP plugin pattern (Two Factor, wordpress-importer); discoverable | HIGH |
| Plugin loaded via `tests_add_filter('muplugins_loaded', ...)` | Correct hook order: runs after WP core functions exist, before `plugins_loaded` | HIGH |
| `WP_UnitTestCase` provides per-test transaction rollback | No manual cleanup; `START TRANSACTION` / `ROLLBACK` in setUp/tearDown | HIGH |
| Composer `test` = unit only; add `test:unit` + `test:integration` | Devs without MySQL can still run `composer test`; integration is opt-in | HIGH |

### Feature Priorities

| Priority | Requirements | Rationale |
|----------|-------------|-----------|
| P1 (must have) | Harness scaffold, full reauth flow, bcrypt verification, session binding, transient behavior, WP 7.0 verification | Closes the critical security gaps that mocks cannot reach |
| P2 (should have) | Upgrader migration, REST API gating, audit hooks, "Tested up to" bump, rate limiting | Important but lower risk; unit tests already cover logic |
| P3 (future) | Two Factor interaction, multisite isolation, capability tamper, hook timing, Abilities API doc | Highest setup cost; defer until core harness is proven |

---

## Critical Pitfalls (Encoded in Phases)

| # | Pitfall | Mitigation | Phase |
|---|---------|------------|-------|
| 1 | Brain\Monkey + real WP cannot coexist | Separate bootstraps, configs, base classes | Phase 1 |
| 2 | Patchwork intercepts real `setcookie`/`header` | Exclude Patchwork from integration context entirely | Phase 1 |
| 3 | `setcookie()` doesn't emit headers in CLI | Test `$_COOKIE` superglobal + user meta, not HTTP headers | Phase 1, 2 |
| 4 | Transient TTL not testable without `sleep()` | Keep TTL expiry tests in unit suite; integration tests happy-path only | Phase 1, 2 |
| 5 | Two Factor plugin loading creates class redeclaration | Separate bootstrap; never load unit test stubs in integration context | Phase 4 |
| 6 | Multisite needs different WP install config | `WP_TESTS_MULTISITE=1` CI matrix variant; separate test group | Phase 4 |

---

## Open Questions (Resolved During Phases)

| Question | Resolution Path | Phase |
|----------|----------------|-------|
| `yoast/phpunit-polyfills` ^1.1 vs ^2.0? | Use ^2.0 — future-proofs for PHPUnit 10; ^1.1 also works | Phase 1 |
| How to install Two Factor in test harness? | Composer path repo, git submodule, or CI download step — investigate during phase | Phase 4 |
| Multisite harness config details? | `install-wp-tests.sh` with `WP_TESTS_MULTISITE=1` env var — standard pattern | Phase 4 |
| WP 7.0 Beta 1 changelog for admin UI? | Beta 1 may have just tagged (2026-02-19); monitor make.wordpress.org/core | Phase 5 |
| `sleep()` in `record_failed_attempt()`? | Progressive delays (2s, 5s) make rate-limiting integration tests slow; consider test-only bypass filter | Phase 3 |

---

## Phase Structure (Derived)

Research converges on a 5-phase structure matching Standard depth:

1. **Harness Scaffold** — All infrastructure, zero tests. Addresses Pitfalls 1-4.
2. **Core Security Tests** — The 4 highest-value integration tests (reauth flow, bcrypt, session binding, transient behavior).
3. **Surface Coverage** — Upgrader, REST gating, audit hooks, rate limiting.
4. **Advanced Coverage** — Two Factor + multisite (highest setup cost, deferred risk).
5. **WP 7.0 Readiness** — Manual verification, version bumps, Abilities API doc.

Phase 5 is partially time-gated: "Tested up to" bump requires WP 7.0 GA (April 9, 2026).

---

## Key Files (From Architecture Research)

| New File | Purpose |
|----------|---------|
| `phpunit-integration.xml.dist` | Integration suite PHPUnit config |
| `tests/integration/bootstrap.php` | Loads real WP + registers plugin |
| `tests/integration/TestCase.php` | Base class extending `WP_UnitTestCase` |
| `bin/install-wp-tests.sh` | One-time WP test library + DB setup |
| `.github/workflows/phpunit.yml` | CI with separate unit + integration jobs |

---

*Synthesis of: STACK.md, FEATURES.md, ARCHITECTURE.md, PITFALLS.md*
*Synthesized: 2026-02-19*
