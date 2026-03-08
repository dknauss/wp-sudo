# Stack Research

**Domain:** Playwright E2E browser testing for a WordPress plugin (zero Node.js baseline)
**Project:** WP Sudo — adding browser-level test infrastructure
**Researched:** 2026-03-08
**Confidence:** HIGH (all versions verified via npm registry as of research date; wp-env behavior verified from official Gutenberg monorepo README; CI runner capabilities verified from actions/runner-images Ubuntu2404-Readme.md; Playwright browser caching guidance verified from official Playwright CI docs)

---

## Context

WP Sudo currently has:
- PHPUnit 9.6 unit tests (Brain\Monkey, 496 tests)
- PHPUnit integration tests (WP_UnitTestCase + MySQL, 132 tests)
- Zero Node.js tooling — no `package.json`, no npm, no build step
- GitHub Actions CI on ubuntu-24.04 with PHP 8.1–8.4 / WP 6.7, latest, trunk matrix

This research covers **only what must be added** to support Playwright E2E browser tests. Do not alter the existing PHP test stack.

Five test scenarios are the immediate target:
1. Cookie attributes (httponly, samesite, secure flags — invisible to PHP assertions)
2. Admin bar JS countdown timer (JavaScript behavior)
3. MU-plugin AJAX challenge flow (browser redirect chain)
4. Block editor snackbar preparation (future Gutenberg integration)
5. Keyboard navigation / focus order (WCAG accessibility)

Plus visual regression baselines for WP 7.0's admin refresh (GA April 9, 2026).

---

## Recommended Stack

### Core Technologies

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| `@playwright/test` | `1.58.2` | Test runner, browser automation, built-in screenshot comparison | The standard for WordPress E2E testing. WordPress core, Gutenberg, and WooCommerce all use it. Built-in `toHaveScreenshot()` covers visual regression without additional packages. No external visual diff library needed. Ships its own test runner — no Jest/Mocha configuration required. |
| `@wordpress/env` | `11.1.0` | WordPress environment — Docker or Playground runtime | The standard WordPress plugin test environment. Reads `.wp-env.json` from the plugin root, mounts the plugin automatically, manages WordPress installation. Used by Two Factor, Jetpack, and the wider WordPress plugin ecosystem. Docker runtime uses MySQL (production-representative). Playground runtime uses SQLite (avoid for WP Sudo — session transients and rate-limit transients need MySQL behavior). |

### Supporting Tools (not npm packages)

| Tool | Version on ubuntu-24.04 | Purpose | Notes |
|------|------------------------|---------|-------|
| Docker | 28.0.4 (pre-installed) | Required by `@wordpress/env` Docker runtime | Already on ubuntu-24.04 GitHub Actions runners. No setup step needed. |
| Docker Compose v2 | 2.38.2 (pre-installed) | Used internally by `@wordpress/env` | Pre-installed. No setup step needed. |
| Node.js | 20.20.0 (pre-installed) | Runs Playwright and wp-env | Pre-installed on ubuntu-24.04. Node >=18 satisfies both Playwright (>=18) and wp-env (>=18.12.0) requirements. |

### What NOT to Add

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| `pixelmatch` / `pngjs` | Playwright's built-in `toHaveScreenshot()` already does pixel-level comparison with configurable thresholds — no external image diff library is needed | Built-in `expect(page).toHaveScreenshot()` |
| `@argos-ci/playwright` (6.4.2) | External SaaS for visual regression — adds billing dependency, network calls in CI, and account management overhead. Unnecessary for a plugin with a small visual regression surface | Built-in `toHaveScreenshot()` snapshots committed to the repo |
| `@percy/playwright` (1.0.10) | Same problem as Argos — external SaaS, stale (last release Nov 2025), Percy account required | Built-in `toHaveScreenshot()` |
| `@wordpress/e2e-test-utils-playwright` (1.41.0) | Heavy Gutenberg-specific utility layer (WP Core `RequestUtils`, `Admin`, `Editor` helpers). Peer-requires `@playwright/test>=1` and `@types/node@^20`. Built for Core/Gutenberg development, not plugin testing. WP Sudo needs simple page navigation and form submission — not block editor helpers | Direct `@playwright/test` page API |
| `@wp-playground/cli` (3.1.4) as standalone env | WASM PHP (not real PHP binary). Uses SQLite, not MySQL. `wp-env run` command unavailable in Playground runtime. WP Sudo's transient-based rate limiting and session management need real MySQL semantics | `@wordpress/env` with Docker runtime |
| `wp-env --runtime=playground` | Experimental runtime, SQLite only (no MySQL), no `wp-env run` command. Rate-limit transients, session transients, and `wp_check_password()` behavior must be validated against MySQL. Playground is fine for block editor testing, not for security plugin testing | `@wordpress/env` with default Docker runtime |
| `@wordpress/scripts` | Build tooling for block-editor JS. WP Sudo has no JavaScript that requires compilation | Not applicable |
| `actions/setup-node` in the E2E CI job | Node.js 20.20.0 is already pre-installed on ubuntu-24.04 runners. Adding setup-node wastes ~15 seconds and introduces an unnecessary dependency | Use pre-installed node; add `node-version` pin only if a specific version is required |
| Browser binary caching in GitHub Actions | Playwright's own documentation explicitly states: "Caching browser binaries is not recommended, since the amount of time it takes to restore the cache is comparable to the time it takes to download the binaries." | Run `npx playwright install --with-deps chromium` on every CI run — it is the official recommendation |
| Docker Compose custom `docker-compose.yml` | Manual Docker Compose requires maintaining image versions, health checks, volume mounts, and wp-config. `@wordpress/env` encapsulates all of this and is maintained by the WordPress project | `@wordpress/env` with `.wp-env.json` |
| Full Playwright browser install (Chromium + Firefox + WebKit) | ~800MB download. WordPress admin works in all browsers but the interaction patterns tested (form submission, cookie inspection, DOM state) are browser-agnostic at the Chromium level. CI time scales with download size | `npx playwright install --with-deps chromium` (~300MB, covers the tested surface) |

---

## Installation

```bash
# Initialize package.json (one-time, at repo root)
npm init -y

# Core E2E dependencies
npm install --save-dev @playwright/test@1.58.2 @wordpress/env@11.1.0

# Install Playwright browser (Chromium only — run after npm install)
npx playwright install --with-deps chromium
```

```bash
# Start the test environment (requires Docker running)
npx wp-env start

# Run E2E tests
npx playwright test

# Update visual regression snapshots (run when WP admin UI changes)
npx playwright test --update-snapshots
```

---

## Configuration Files

### `package.json` (minimal — avoids tooling bloat)

```json
{
  "name": "wp-sudo",
  "private": true,
  "scripts": {
    "env:start": "wp-env start",
    "env:stop": "wp-env stop",
    "env:clean": "wp-env destroy",
    "test:e2e": "playwright test",
    "test:e2e:update-snapshots": "playwright test --update-snapshots"
  },
  "devDependencies": {
    "@playwright/test": "1.58.2",
    "@wordpress/env": "11.1.0"
  }
}
```

### `.wp-env.json` (plugin root)

```json
{
  "core": null,
  "plugins": ["."],
  "phpVersion": "8.2",
  "port": 8888,
  "config": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true,
    "SCRIPT_DEBUG": true
  }
}
```

Notes:
- `"plugins": ["."]` mounts the current directory as the plugin under test. wp-env auto-installs and activates it.
- `"core": null` uses the latest production WordPress release. For WP 7.0 baseline testing, set to `"https://wordpress.org/wordpress-7.0.zip"` once it ships.
- `"phpVersion": "8.2"` is concrete and matches the CI PHP version used for integration tests. Do not use `null` (wp-env default) — pin a version for reproducible baselines.
- No `testsEnvironment` — it is deprecated. Use `--config` with a separate `.wp-env.json` file if a separate test environment is needed.

### `playwright.config.js` (minimal)

```js
// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/E2E',
  timeout: 30_000,
  retries: process.env.CI ? 2 : 0,
  workers: 1,          // wp-env has one WordPress instance; parallel workers would conflict
  reporter: [
    ['list'],
    ['html', { open: 'never' }],
  ],
  use: {
    baseURL: 'http://localhost:8888',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  // No webServer block — wp-env is started as a separate CI step, not via Playwright
});
```

Notes:
- `workers: 1` is mandatory. wp-env exposes a single WordPress instance. Parallel Playwright workers would share the same database and session state, causing intermittent failures.
- `retries: 2` in CI handles the transient timing issues that appear in browser tests against a Docker container. Do not use retries locally.
- No `webServer` block — wp-env has its own lifecycle (`wp-env start` / `wp-env stop`) and is not a simple dev server that Playwright can manage.

---

## GitHub Actions CI Integration

The E2E job runs as a separate job after the existing `integration-tests` job. It does not replace or modify any existing jobs.

```yaml
e2e-tests:
  name: "E2E Tests (WP ${{ matrix.wp }})"
  runs-on: ubuntu-24.04
  strategy:
    fail-fast: false
    matrix:
      wp:
        - null        # latest production release (wp-env default)
        - "https://wordpress.org/wordpress-7.0.zip"  # add after WP 7.0 GA

  steps:
    - name: Checkout
      uses: actions/checkout@v6

    # Node.js 20.20.0 is pre-installed on ubuntu-24.04 — no setup-node needed

    - name: Install npm dependencies
      run: npm ci

    - name: Install Playwright browsers
      run: npx playwright install --with-deps chromium
      # NOT cached — Playwright docs explicitly recommend against browser caching

    - name: Start WordPress environment
      run: npx wp-env start
      env:
        WP_ENV_CORE: ${{ matrix.wp }}
        # null matrix value means wp-env uses its default (latest WP)

    - name: Run E2E tests
      run: npx playwright test

    - name: Upload test report on failure
      if: failure()
      uses: actions/upload-artifact@v7
      with:
        name: playwright-report-${{ matrix.wp }}
        path: playwright-report/
        retention-days: 7

    - name: Stop WordPress environment
      if: always()
      run: npx wp-env stop
```

Key integration points with the existing `phpunit.yml`:
- The E2E job is a sibling job, not part of the existing `unit-tests`, `integration-tests`, or `code-quality` jobs.
- The `notify-on-failure` job in `phpunit.yml` should list `e2e-tests` in its `needs:` array once E2E is stable.
- No PHP version matrix for E2E — browser behavior is PHP-version-agnostic. One PHP version (8.2, same as wp-env default) is sufficient.
- `fail-fast: false` matches the existing pattern in `phpunit.yml`.

---

## Alternatives Considered

| Category | Recommended | Alternative | Why Not |
|----------|-------------|-------------|---------|
| Test environment | `@wordpress/env` (Docker) | Docker Compose (custom) | Custom Docker Compose requires maintaining `docker-compose.yml`, WordPress image versions, volume mounts, database setup, and wp-config management. wp-env encapsulates all of this. WordPress core itself uses custom Docker Compose, but core is the reference implementation — plugins should use the higher-level tool. |
| Test environment | `@wordpress/env` (Docker) | `@wordpress/env --runtime=playground` | Playground runtime uses SQLite, not MySQL. WP Sudo's rate-limiting and session transients depend on MySQL semantics. Playground is experimental and lacks `wp-env run`. Use Docker. |
| Visual regression | Built-in `toHaveScreenshot()` | Argos CI / Percy | Both are SaaS tools with external billing and account management. Built-in `toHaveScreenshot()` stores baseline PNGs in the repo alongside the tests — no external service, no network dependency in CI, no API keys. Sufficient for WP 7.0 admin UI regression tracking. |
| Browser coverage | Chromium only | Chromium + Firefox + WebKit | WordPress admin works in all modern browsers but the test scenarios (cookie flags, admin bar timer, challenge form) are not browser-specific. Full install adds ~500MB download and ~3 minutes CI time for no material coverage gain. Add Firefox/WebKit only if browser-specific bugs are found. |
| Test runner | `@playwright/test` | Cypress | Cypress uses a different architecture (Electron wrapper, iFrame-based) with known issues testing cookie attributes and multi-tab flows. Playwright is the emerging standard for WordPress plugin testing (WordPress core migrated from Puppeteer to Playwright in WP 6.3). Playwright has better TypeScript support and first-class CI docs. |
| Test runner | `@playwright/test` | Puppeteer | Puppeteer is a browser automation library, not a full test framework. Requires Jest or Mocha as an additional test runner. Playwright includes the test runner, fixtures, assertions, and screenshot comparison in one package. WordPress core migrated away from Puppeteer. |
| `webServer` configuration | No (separate wp-env lifecycle) | Playwright `webServer:` block | Playwright's `webServer` block manages simple dev servers (`npm start`, `vite`, etc.). wp-env is a multi-container Docker environment that cannot be started with a simple command and health-checked as a URL in under 5 seconds. Start wp-env as a separate CI step before Playwright runs. |

---

## Version Compatibility

| Package | Version | Compatible With | Notes |
|---------|---------|----------------|-------|
| `@playwright/test` | `1.58.2` (latest stable 2026-03-08) | Node >=18 | Next alpha: `1.59.0-alpha-2026-03-08`. Lock exact version (`1.58.2`) to prevent snapshot hash drift when Playwright updates its internal comparison algorithm. |
| `@wordpress/env` | `11.1.0` (2026-03-04) | Node >=18.12.0, Docker required for default runtime | WordPress 6.7 tagged as `wp-6.7: 10.8.1`. Latest `11.1.0` supports WP trunk/7.0. |
| Node.js (runtime) | 20.20.0 (pre-installed on ubuntu-24.04) | Playwright >=18, wp-env >=18.12.0 | No `actions/setup-node` step needed. |
| Docker | 28.0.4 (pre-installed) | `@wordpress/env` 11.x | Docker Compose v2 2.38.2 also pre-installed. |
| PHP (in wp-env container) | 8.2 (pinned in `.wp-env.json`) | WordPress 6.7–7.0 | Pin `phpVersion` explicitly. wp-env `null` default tracks the WordPress-bundled default, which can change without notice. |

**Snapshot baseline stability:** Pin `@playwright/test` to an exact version (not `^`) in `package.json`. Playwright's internal screenshot comparison algorithm changes between minor versions, which invalidates all stored baselines. Lock the version and update it intentionally with a corresponding `--update-snapshots` run.

---

## Stack Patterns by Scenario

**For visual regression against WP 7.0 admin refresh (April 9, 2026 GA):**
- Capture baselines on WP 6.9 (current) first: `npx playwright test --update-snapshots`
- Add `wp: "https://wordpress.org/wordpress-7.0.zip"` to matrix after GA
- Run `--update-snapshots` on the 7.0 run to establish new 7.0 baselines
- The two sets of snapshots coexist in the repo under different snapshot subdirectories (configured via `snapshotPathTemplate` in `playwright.config.js`)

**For cookie attribute verification:**
- Use `context.cookies()` to inspect cookie flags: `secure`, `httpOnly`, `sameSite`
- No screenshot needed — assert programmatically: `expect(cookie.httpOnly).toBe(true)`
- This is the primary reason integration tests cannot cover this scenario

**For admin bar JS countdown timer:**
- Wait for selector: `await page.waitForSelector('#wp-admin-bar-wp-sudo-timer')`
- Assert text changes: `await expect(page.locator('#wp-admin-bar-wp-sudo-timer')).not.toHaveText('--:--')`
- Use `page.evaluate()` for timing assertions

**For keyboard navigation:**
- Use `page.keyboard.press('Tab')` to traverse focus order
- Assert: `await expect(page.locator(':focus')).toHaveAttribute('id', 'expected-id')`

**For running locally without Docker:**
- Use `wp-env start --runtime=playground` for quick iteration on non-session tests
- For session/cookie/transient tests, Docker is required — do not use Playground

---

## CI Runner Requirements

| Requirement | Status on ubuntu-24.04 | Notes |
|-------------|----------------------|-------|
| Docker | Pre-installed (28.0.4) | Required by `@wordpress/env`. No setup step needed. |
| Docker Compose v2 | Pre-installed (2.38.2) | Used internally by `@wordpress/env`. No setup step needed. |
| Node.js >=18 | Pre-installed (20.20.0) | Satisfies both Playwright and wp-env requirements. |
| Chromium deps (libnss3, etc.) | Installed by `--with-deps` flag | `npx playwright install --with-deps chromium` handles OS-level dependencies. |
| SVN | Already in existing CI (installed manually) | Not needed for Playwright E2E jobs. |

**Estimated CI time addition:** 3–5 minutes per E2E job (wp-env start: ~60s, browser install: ~90s, test run: 60–120s depending on test count).

---

## What This Does NOT Change

- `composer.json` — no changes
- `phpunit.xml.dist` — no changes
- `phpunit-integration.xml.dist` — no changes
- `tests/Unit/` — no changes
- `tests/Integration/` — no changes
- Any existing `phpunit.yml` jobs — not modified, E2E is a new sibling job
- Production plugin code — no JavaScript assets, no build step, no new PHP dependencies

---

## Sources

- **npm registry: `@playwright/test`** — `npm view @playwright/test version` → `1.58.2` (2026-03-08). Dist-tags confirm latest stable. Next alpha `1.59.0-alpha-2026-03-08` active. (HIGH confidence)
- **npm registry: `@wordpress/env`** — `npm view @wordpress/env version` → `11.1.0` (2026-03-04). Requires Node >=18.12.0, depends on `docker-compose` and `@wp-playground/cli`. (HIGH confidence)
- **wp-env README** — `https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/env/README.md` — Verified: Docker required for default runtime; Playground runtime is experimental, uses SQLite (not MySQL), lacks `wp-env run` command. Confirmed `.wp-env.json` schema fields: `core`, `plugins`, `phpVersion`, `port`, `config`, `mappings`. (HIGH confidence)
- **GitHub Actions ubuntu-24.04 runner image** — `https://raw.githubusercontent.com/actions/runner-images/main/images/ubuntu/Ubuntu2404-Readme.md` — Verified: Docker 28.0.4, Docker Compose v2 2.38.2, Node.js 20.20.0 pre-installed. (HIGH confidence)
- **Playwright CI docs** — `https://raw.githubusercontent.com/microsoft/playwright/main/docs/src/ci.md` — Verified: "Caching browser binaries is not recommended, since the amount of time it takes to restore the cache is comparable to the time it takes to download the binaries." (HIGH confidence — official documentation)
- **WordPress core E2E workflow** — `https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/.github/workflows/reusable-end-to-end-tests.yml` — Verified: uses `npx playwright install --with-deps chromium` (Chromium only), Docker Compose for WP environment. Confirmed `@playwright/test` 1.56.1 in WordPress core `package.json` as of March 2026. (HIGH confidence)
- **Two Factor plugin `package.json`** — `https://raw.githubusercontent.com/WordPress/two-factor/master/package.json` — Verified: uses `@wordpress/env: ^10.30.0` for test environment. Confirms wp-env is the standard plugin test environment. (HIGH confidence)
- **`@wordpress/e2e-test-utils-playwright` npm** — `npm view @wordpress/e2e-test-utils-playwright` → `1.41.0`, peerDeps `@playwright/test>=1, @types/node@^20`. Confirmed it is Gutenberg-specific. (HIGH confidence)
- **`@argos-ci/playwright` npm** — `npm view @argos-ci/playwright` → `6.4.2` (2026-02-20). SaaS service confirmed. (HIGH confidence)
- **`pixelmatch` npm** — `npm view pixelmatch version` → `7.1.0`. Confirmed not needed given Playwright's built-in comparison. (HIGH confidence)

---

*Stack research for: Playwright E2E browser testing — WordPress plugin (zero Node.js baseline)*
*Researched: 2026-03-08*
