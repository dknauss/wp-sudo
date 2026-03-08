# Research Summary

**Milestone:** v2.14 — Playwright E2E Test Infrastructure
**Synthesized:** 2026-03-08
**Sources:** 4 research docs (STACK.md, FEATURES.md, ARCHITECTURE.md, PITFALLS.md)
**Overall confidence:** HIGH — stack versions verified via npm registry, CI runner manifest verified, codebase analysis from live files

---

## Consensus Decisions

These findings are consistent across all 4 research docs and should be treated as settled.

### Stack

| Decision | Rationale | Confidence |
|----------|-----------|------------|
| `@playwright/test` 1.58.2 as test framework | WordPress ecosystem standard (Core, Gutenberg, WooCommerce). Built-in screenshot comparison, clock manipulation, test runner — no additional packages needed | HIGH |
| `@wordpress/env` 11.1.0 (Docker runtime) for test environment | Official WordPress plugin test environment. Handles DB setup, plugin mounting, WP installation. Used by Two Factor, Jetpack, Gutenberg | HIGH |
| Chromium only (not Firefox/WebKit) | ~300MB vs ~800MB. Admin interactions are browser-agnostic at the Chromium level. Add others only if browser-specific bugs found | HIGH |
| No `@wordpress/e2e-test-utils-playwright` | Gutenberg-specific heavy utility layer. WP Sudo needs simple page navigation and form submission, not block editor helpers | HIGH |
| No external visual regression SaaS (Argos, Percy) | Built-in `toHaveScreenshot()` stores baselines in repo. No external billing, no network dependency in CI | HIGH |
| No browser binary caching in CI | Playwright docs explicitly recommend against it — restore time ≈ download time | HIGH |
| `workers: 1` in Playwright config | wp-env exposes a single WordPress instance; parallel workers would share DB state | HIGH |
| Pin exact `@playwright/test` version (not `^`) | Playwright's screenshot comparison algorithm changes between minors, invalidating baselines | HIGH |

### Architecture

| Decision | Rationale | Confidence |
|----------|-----------|------------|
| `tests/e2e/` directory structure | Third test tier alongside Unit and Integration. Consistent with existing `tests/` layout | HIGH |
| `global-setup.ts` for login → `storageState` | Login once, reuse across all tests. Standard Gutenberg pattern. Saves 2-4s per test | HIGH |
| Separate CI workflow (`e2e.yml`) | Does not modify existing `phpunit.yml`. Independent Node.js toolchain, zero PHP test changes | HIGH |
| `.wp-env.json` at repo root | Declares plugin mount, PHP version, WP version. Standard wp-env configuration | HIGH |
| `package.json` at repo root (devDependencies only) | New Node.js root, separate from Composer. No production dependencies | HIGH |
| Separate WordPress login state from sudo session state | `storageState` saves WP auth cookies; sudo token is per-test (never persisted in storageState) | HIGH |

### Feature Priorities

| Priority | Requirements | Rationale |
|----------|-------------|-----------|
| P1 (must have) | Toolchain scaffold, login helper, cookie verification, admin bar timer, MU-plugin AJAX, gate UI, visual regression baselines | Closes 5 PHPUnit-uncoverable gaps + time-sensitive WP 7.0 baselines |
| P2 (should have) | Challenge stash-replay flow, keyboard navigation, keyboard shortcut, admin bar deactivation | Completes user journey and keyboard coverage |
| P3 (future) | ARIA live regions, rate limiting UI, responsive viewports, axe-core contrast, session-only mode | Accessibility depth, advanced UX edge cases |

---

## Critical Pitfalls (Encoded in Phases)

| # | Pitfall | Mitigation | Phase |
|---|---------|------------|-------|
| 1 | wp-env silently preserves state between runs | `wp-env clean all` before test run in CI; explicit state reset in global-setup | Phase 1 |
| 2 | AJAX challenge page breaks naive `waitForNavigation` | Use `Promise.all([waitForNavigation, click])` pattern or `waitForURL` | Phase 2 |
| 3 | IP rate limiting triggers on shared CI runner IPs (127.0.0.1) | Clean IP transients after lockout tests; run lockout tests in separate Playwright project | Phase 3 |
| 4 | Admin bar countdown changes DOM every second | Mask element in snapshots; use `page.clock.install()` for timer tests | Phase 2 |
| 5 | wp-env cold-start latency breaks waitForSelector | Warm-up step in global-setup.ts; verify WP is ready before first test | Phase 1 |
| 6 | storageState includes stale sudo token cookies | Never save sudo cookies in storageState; clear `wp_sudo_token` in beforeEach | Phase 1 |
| 7 | Challenge page iframe-break detaches Playwright page reference | Always interact from top-level frame; use `page.waitForURL` to track redirects | Phase 2 |
| 8 | Visual snapshots of elements with dynamic timestamps | Use `clip` to isolate specific elements; mask countdown timers in toHaveScreenshot | Phase 2 |
| 9 | wp-env port conflict with existing dev sites | Use port 8889 (wp-env tests default); document port allocation | Phase 1 |
| 10 | TypeScript configuration can bloat the project | Minimal `tsconfig.json` scoped to `tests/e2e/` only; no build step for plugin code | Phase 1 |

---

## Open Questions (Resolved During Phases)

| Question | Resolution Path | Phase |
|----------|----------------|-------|
| WP 7.0 GA date confirmed April 9? | Monitor make.wordpress.org/core schedule | Phase 2 |
| `page.clock.install()` API stability in 1.58.2? | Verify against Playwright 1.58 release notes | Phase 2 |
| Should snapshot baselines target WP 7.0-beta or GA? | Capture against latest wp-env default first; re-capture on GA | Phase 2 |
| Two Factor TOTP flow in E2E? | Mock AJAX response via `page.route()` — no real TOTP needed | Phase 3 (future) |

---

## Phase Structure (Derived)

Research converges on a 3-phase structure for Standard depth:

1. **E2E Infrastructure Scaffold** — All toolchain setup, wp-env config, CI job, global-setup, login helper, first smoke test. Addresses Pitfalls 1, 5, 6, 9, 10.
2. **Core E2E Tests** — Cookie verification, admin bar timer, MU-plugin AJAX, gate UI, challenge flow, visual regression baselines. Addresses Pitfalls 2, 4, 7, 8.
3. **Keyboard + Accessibility E2E** — Keyboard navigation, keyboard shortcut, admin bar deactivation, ARIA live regions. Addresses Pitfall 3 (lockout tests).

Phase 2 is partially time-sensitive: visual regression baselines should be captured before WP 7.0 GA (April 9, 2026).

---

## Key Files (From Architecture Research)

| New File | Purpose |
|----------|---------|
| `package.json` | Node.js manifest with devDependencies only |
| `package-lock.json` | Generated by npm install |
| `.wp-env.json` | wp-env plugin configuration |
| `.nvmrc` | Pin Node.js version ("20") |
| `playwright.config.ts` | Playwright project configuration |
| `tests/e2e/global-setup.ts` | One-time login → saves storage state |
| `tests/e2e/fixtures/test.ts` | Extended test with WP-specific fixtures |
| `tests/e2e/specs/` | Test spec files organized by feature area |
| `.github/workflows/e2e.yml` | Standalone Playwright CI job |

---

*Synthesis of: STACK.md, FEATURES.md, ARCHITECTURE.md, PITFALLS.md*
*Synthesized: 2026-03-08*
