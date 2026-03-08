# Pitfalls Research

**Domain:** Adding Playwright E2E browser testing to an existing WordPress security plugin (zero Node.js baseline)
**Researched:** 2026-03-08
**Confidence:** HIGH (project codebase + Playwright architecture knowledge), MEDIUM (wp-env and CI specifics from training data — WebSearch/WebFetch unavailable; flag where external verification was not possible)

> Note on sources: WebSearch and WebFetch were unavailable for this research session. Findings draw
> from: (1) the WP Sudo codebase at HIGH confidence, (2) Playwright's documented architecture
> (training data, flagged MEDIUM), (3) wp-env and @wordpress/env documented behavior (training data,
> flagged MEDIUM), (4) the existing PHPUnit CI workflow in `.github/workflows/phpunit.yml` at HIGH
> confidence, and (5) the existing `PITFALLS.md` research for the PHPUnit integration test milestone
> at HIGH confidence. All LOW confidence findings are flagged explicitly.

---

## Critical Pitfalls

### Pitfall 1: wp-env Silently Uses Stale WordPress State Between Test Runs

**What goes wrong:**
`@wordpress/env` (wp-env) starts a Docker-based WordPress environment and persists the database between runs by default. If a test activates the MU-plugin, changes settings, or triggers a lockout, that state carries forward into subsequent test runs — even after the test suite finishes. The next run starts from a dirty state, and tests that assume a clean install fail intermittently.

**Why it happens:**
Developers assume wp-env behaves like a fresh install on every run because the `npx wp-env start` command output says "Starting" without clarifying whether it is resuming from the previous Docker volume or starting fresh. The volumes persist across `wp-env start`/`wp-env stop` cycles unless `wp-env destroy` or `wp-env clean` is called explicitly.

**How to avoid:**
Add `npx wp-env clean all` before the test run in the CI step. Alternatively, configure tests to always reset state explicitly in `beforeEach` via the WordPress REST API or WP-CLI inside the container (`npx wp-env run tests-cli wp option update ...`). Do NOT rely on implicit clean state. Define a `global-setup.ts` in Playwright that calls `wpEnv.reset()` or equivalent WP-CLI commands to create a known-good initial state before any test runs.

**Warning signs:**
- Tests pass on first CI run but fail on re-runs without code changes
- `_wp_sudo_lockout_until` user meta persisting across test classes
- MU-plugin status showing as "installed" when the test expects "not installed"
- Rate-limiting triggering on the first test attempt because a previous test run's failed attempts were never cleaned up

**Phase to address:**
Environment scaffolding phase (the first Playwright milestone). Before writing any behavioral tests, establish the wp-env reset protocol and verify it works with `npx wp-env clean all` in CI.

---

### Pitfall 2: AJAX-Driven Challenge Page Makes Naive "Wait for Navigation" Patterns Wrong

**What goes wrong:**
The challenge page (`admin/js/wp-sudo-challenge.js`) uses `fetch()` calls to `admin-ajax.php`, then on success either calls `window.location.href = ...` (GET redirect) or programmatically calls `HTMLFormElement.prototype.submit.call(form)` (POST replay). Neither of these triggers Playwright's standard `page.waitForNavigation()` at the time the AJAX call starts — navigation only happens after the JavaScript completes its async chain.

The naive pattern `await page.click('#wp-sudo-challenge-submit'); await page.waitForNavigation()` creates a race condition: `waitForNavigation` starts listening after `click`, but navigation may have already begun (or not yet begun) by the time the listener is registered.

**Why it happens:**
Playwright's `waitForNavigation` works reliably when navigation is synchronous (form submit, anchor click). AJAX-triggered navigation breaks this assumption. The fetch completes asynchronously; the navigation fires in the `.then()` handler. Playwright has no way to know the AJAX call will eventually trigger a navigation.

**How to avoid:**
Use the recommended Playwright pattern: start the navigation promise BEFORE the click, then await both in parallel:

```typescript
const [response] = await Promise.all([
  page.waitForNavigation({ waitUntil: 'networkidle' }),
  page.click('#wp-sudo-challenge-submit'),
]);
```

Or better, use `page.waitForURL()` with the expected post-challenge destination URL rather than waiting for any navigation. For POST replay, wait for the final destination URL after the self-submitting form fires. For session-only mode, wait for `cancelUrl`.

For the specific WP Sudo flow: after submitting the challenge form, the AJAX chain can take 200–800ms on a local Docker environment (bcrypt verify + meta write + response parse + `window.location.href` assignment). Do NOT use fixed `page.waitForTimeout()` — use URL or element assertions.

**Warning signs:**
- Tests pass locally but fail in CI (timing-sensitive)
- `page.waitForNavigation()` timing out even though the redirect happened
- Playwright capturing screenshots on the challenge page instead of the post-challenge destination
- Tests that work with `--headed` but fail with `--headless` (timing differs between modes)

**Phase to address:**
First behavioral tests phase. Establish the AJAX-navigation pattern as the standard for all challenge page interactions before writing any challenge flow tests.

---

### Pitfall 3: IP-Based Rate Limiting Triggers on Shared CI Runner IPs

**What goes wrong:**
`Sudo_Session` implements per-IP rate limiting using transients keyed by `wp_sudo_ip_failure_event_{ip}` and `wp_sudo_ip_lockout_until_{ip}`. GitHub Actions runners share IP address space and NAT — multiple parallel jobs in the same workflow run may appear to WordPress as coming from the same IP address. If the test suite's lockout tests (which intentionally submit wrong passwords 5+ times) run in parallel with other tests, the IP lockout fires and blocks all subsequent authentication attempts across jobs.

**Why it happens:**
The per-IP lockout was designed for real-world rate limiting of attacker IPs. In CI, all requests to the Docker-hosted wp-env come from the runner's loopback address (`127.0.0.1`) or the Docker bridge network. Every test job in the matrix appears as the same IP. After 5 failed attempts from that IP, all authentication tests fail for the rest of the matrix run.

**How to avoid:**
Design lockout tests to clean up after themselves: after triggering a lockout, explicitly clear the IP transient with a WP-CLI call (`wp transient delete wp_sudo_ip_lockout_until_{ip}`) before the next test. Alternatively, run lockout-triggering tests in a separate worker or browser context using Playwright's `project` configuration so they run serially, not in parallel with other auth tests. Do NOT use `test.only` or `--workers=1` globally — this eliminates parallelism for all tests.

Consider using a custom X-Forwarded-For header in lockout tests to use a unique fake IP per test (verify wp-env configuration accepts this). Document this in the test file with a comment.

**Warning signs:**
- Auth tests pass in isolation but fail when run after lockout tests
- `_wp_sudo_lockout_until` transients visible in the database when no lockout test ran
- `locked_out` error code appearing in tests that submit the correct password
- Matrix jobs where lockout tests ran before auth tests show 100% auth test failure

**Phase to address:**
Rate limiting test phase. Before writing any lockout tests, document and implement the IP transient cleanup protocol.

---

### Pitfall 4: Admin Bar Countdown Timer Causes Page Content to Change Every Second

**What goes wrong:**
`admin/js/wp-sudo-admin-bar.js` runs a `setInterval` that updates the admin bar countdown every second and triggers `window.location.reload()` when the timer reaches zero. Playwright visual snapshot tests taken during an active session will differ on every run — the countdown text changes each second, and the `wp-sudo-expiring` CSS class appears at the 60-second mark.

Additionally, the `window.location.reload()` at expiry can interrupt a test mid-flow if the session expires during a long test. Playwright will see an unexpected navigation event and tests using `waitForNavigation` will capture the reload instead of the intended navigation.

**Why it happens:**
The countdown timer runs as a side effect of the JavaScript loaded on any admin page where a session is active. Tests that authenticate successfully (activating a session) and then navigate to admin pages will see this timer. The test infrastructure has no way to freeze the timer unless the session is short-lived or the timer is explicitly bypassed.

**How to avoid:**
For tests that need stable admin page assertions with an active session, either:
1. Configure a maximum session duration of 1 minute (`session_duration = 1`) and run the relevant admin page assertions within the first 5 seconds after authentication — well before the `wp-sudo-expiring` class appears.
2. Use `page.addStyleTag({ content: '#wp-admin-bar-wp-sudo-active { display: none; }' })` after navigation to hide the countdown from visual snapshot comparisons.
3. Mask the admin bar element in Playwright's `toHaveScreenshot` mask configuration: `{ mask: [page.locator('#wp-admin-bar-wp-sudo-active')] }`.

For the `window.location.reload()` at expiry: set session duration to 1 minute and ensure all test flows complete within 30 seconds of activation, giving a 30-second safety margin before the timer fires.

**Warning signs:**
- Visual snapshot tests fail with a diff showing only the countdown text changed
- Tests fail with "Unexpected navigation" error during assertions
- The `wp-sudo-expiring` CSS class appearing inconsistently in test screenshots
- Tests that pass when run immediately after authentication but fail in slow CI environments

**Phase to address:**
Visual snapshot phase (if added). Admin bar assertions phase. Address in the test configuration from the start — not retroactively.

---

### Pitfall 5: wp-env Docker Environment Has a Cold-Start Latency That Breaks waitForSelector

**What goes wrong:**
`@wordpress/env` starts a Docker container with WordPress. On the first page load after `wp-env start`, WordPress initializes its file system cache, loads plugins, and may run database checks. This first-load latency can be 3–8 seconds on a cold container, 1–3 seconds on a warm one. Playwright's default timeout for `waitForSelector` is 30 seconds, which appears generous, but WordPress's loading behavior is not uniform — WordPress admin pages make multiple sub-requests (for `admin-ajax.php`, inline scripts, etc.) and the `networkidle` state can be delayed if any asset fails to load.

The specific risk for WP Sudo: the challenge page loads the `wp-sudo-challenge.js` script which reads `window.wpSudoChallenge` set by `wp_localize_script`. If the script loads before localization data is ready (which can happen when script caching plugins are active in the test environment), `config.ajaxUrl` will be undefined and the challenge form will silently fail without any error shown to the browser.

**Why it happens:**
Developers test against a local environment with a warm Docker cache. CI starts with a cold container on every run. The timing difference exposes race conditions that are invisible locally.

**How to avoid:**
In the global setup, add a "warm-up" step: after `wp-env start`, make a WP-CLI call (`npx wp-env run tests-cli wp core is-installed`) to verify WordPress is ready, then make a test HTTP request to `wp-admin/` before the test suite starts. Add a Playwright `globalSetup.ts` that navigates to the WordPress admin login page and waits for it to load before any tests run.

For the localization data race: write a test assertion that verifies `window.wpSudoChallenge.ajaxUrl` is defined before filling the password field. Use `page.waitForFunction(() => typeof window.wpSudoChallenge !== 'undefined')` at the top of challenge page tests.

**Warning signs:**
- Tests pass locally but fail on first CI run of a fresh wp-env container
- "Timeout waiting for selector" on elements that clearly exist in the DOM
- Challenge form submits but AJAX call goes to `undefined` instead of `admin-ajax.php`
- Tests pass on retry without code changes

**Phase to address:**
Environment scaffolding phase. The warm-up protocol belongs in `global-setup.ts` before the first test runs.

---

### Pitfall 6: WordPress Login Cookies Are Not Automatically Preserved Between Tests Without storageState

**What goes wrong:**
Each Playwright test runs in an isolated browser context by default. WordPress authentication relies on `wordpress_logged_in_{hash}`, `wordpress_sec_{hash}`, and `wordpress_{hash}` cookies. Without explicitly saving and restoring these cookies, every test must perform a full browser-based login through `wp-login.php`, adding 2–4 seconds per test. More critically, WP Sudo adds its own `wp_sudo_token` cookie for session binding — this cookie must be present AND match the `_wp_sudo_token` user meta for the session to be valid. If tests reuse saved `storageState` that includes a stale or expired WP Sudo token, session verification will fail even though the WordPress auth cookies are valid.

**Why it happens:**
Playwright's recommended pattern is to use `storageState` to persist auth cookies across tests. This works well for simple session cookies but breaks for WP Sudo because the plugin's session has its own time-bounded token. A `storageState` saved at T=0 with a 10-minute sudo session may be reloaded at T=600 when the sudo session has expired — the WordPress auth cookie is still valid, but `wp_sudo_token` is stale.

**How to avoid:**
Separate authentication concerns clearly:
1. **WordPress login state** (`wordpress_logged_in_*`): Save with `storageState` once in `global-setup.ts`. This should be stable for a wp-env test session lasting hours. Use separate state files per role (admin, editor, subscriber).
2. **Sudo session state** (`wp_sudo_token`): Never save in `storageState`. Each test that needs an active sudo session must acquire it by completing the challenge flow, or by setting it directly via WP-CLI before the test.

Explicitly exclude `wp_sudo_token` and `wp_sudo_challenge` from `storageState` by not writing those cookies to storage, or by clearing them in `beforeEach`.

**Warning signs:**
- Tests that previously worked start failing with "gate intercepting" unexpectedly
- Sudo session active but `is_active()` returns false (token mismatch)
- Tests depending on having NO active session fail because a stale token cookie is present
- `wp_sudo_token` appearing in saved storageState files in the repository

**Phase to address:**
Authentication scaffolding phase (early). Define the storageState boundary before writing any authenticated tests.

---

### Pitfall 7: The Challenge Page iframe-Break Causes Playwright to Lose the Page Reference

**What goes wrong:**
`admin/js/wp-sudo-challenge.js` contains an iframe-break at the top:

```javascript
if (window.top !== window.self) {
    window.top.location.href = window.location.href;
    return;
}
```

This fires if WordPress loads the challenge page inside an iframe (e.g., during plugin/theme update flows that use `wp_iframe()`). If a Playwright test navigates to a challenge page URL while another frame context is active, this script fires and navigates the top-level frame to the challenge URL — which may cause Playwright to lose its reference to the page it was tracking and throw `Frame was detached`.

**Why it happens:**
Playwright tests that navigate to the plugin update page, then expect to be redirected to the challenge page, may find themselves inside the iframe context that WordPress uses for the plugin update UI. The challenge page's iframe-break then navigates the top-level frame, and Playwright's tracked page reference becomes invalid.

**How to avoid:**
Always interact with the challenge page from the top-level frame context. In tests that trigger gated actions through the plugin update flow, use `page.mainFrame()` to ensure actions are directed at the top-level document. Before asserting elements on the challenge page, verify the page URL is at the expected challenge URL using `page.waitForURL(/wp-sudo-challenge/)`. If `Frame was detached` errors appear, use `page.on('framenavigated', ...)` to track the final navigation destination.

**Warning signs:**
- `Frame was detached` errors when asserting challenge page elements
- Playwright's page.url() showing an iframe sub-URL instead of the challenge page URL
- Tests that trigger plugin activation gating fail inconsistently

**Phase to address:**
Plugin activation gating test phase. Document the iframe-break in test comments and the `waitForURL` pattern.

---

### Pitfall 8: POST Request Replay Via Hidden Form Cannot Be Intercepted by Playwright's `route`

**What goes wrong:**
After successful authentication, `handleReplay()` in `wp-sudo-challenge.js` dynamically creates a hidden form and calls `HTMLFormElement.prototype.submit.call(form)`. Playwright's `page.route()` interception hooks intercept HTTP requests, but a programmatically submitted form's navigation does not fire network interception events in the same way that `fetch()` does — the form submit triggers a full page navigation (not an XHR/fetch). Tests that use `page.route()` to intercept and inspect the replayed POST request may miss it.

Additionally, the stashed POST data is reconstructed by the client-side script from `data.post_data` returned by the AJAX auth handler. If the test needs to verify what fields were replayed, there is no way to do this from the browser side — the form is created and submitted in the same JavaScript tick, and Playwright cannot inspect it before submission.

**How to avoid:**
To verify POST replay behavior in E2E tests, assert on the destination URL and the resulting page state (e.g., did the plugin actually activate? did the user actually get deleted?) rather than on the form fields. For detailed POST data verification, use Playwright's `page.on('request', handler)` listener attached BEFORE triggering the replay, which catches all network requests including form submissions. Alternatively, test the replay mechanism at the integration test level (PHPUnit + `WP_UnitTestCase`) where the `Request_Stash` data can be inspected directly.

**Warning signs:**
- Tests that try to use `page.route('/wp-admin/*', ...)` to catch the replayed POST receiving zero matching calls
- Assertions on the replayed form's fields that always pass vacuously
- `page.waitForRequest()` timing out when waiting for the replayed POST

**Phase to address:**
POST replay test phase. Define the "assert on destination state, not form content" pattern in the test style guide.

---

### Pitfall 9: wp-env WordPress Version Drift Causes Test Environment to Diverge from Production

**What goes wrong:**
`@wordpress/env` downloads WordPress from wordpress.org on each `wp-env start` when using `"core": null` or `"core": "trunk"`. If the `wp-env` configuration file does not pin a specific WordPress version, the test environment will silently upgrade WordPress when a new release ships. A breaking change in WordPress admin HTML structure (e.g., the WP 7.0 admin refresh that changed CSS class names) will cause all CSS selector-based Playwright tests to fail overnight without any change to the plugin code.

This project is already tracking WP 7.0 prep (the admin refresh). The challenge page CSS selectors, admin bar selectors, and settings page selectors are all at risk of changing between WP versions.

**Why it happens:**
The Playwright test file uses CSS selectors derived from the current WordPress admin HTML. When WordPress updates its admin HTML (as it does on major releases), selectors that worked against WP 6.x fail against WP 7.x. With an unpinned `"core"` in `.wp-env.json`, this happens automatically and silently.

**How to avoid:**
Pin the WordPress version in `.wp-env.json`: `"core": "WordPress/WordPress#6.9"` for the stable matrix. Add a separate test profile (or matrix job) for the current trunk version. Update the pinned version deliberately when running WP compatibility checks, not automatically.

Use Playwright's `data-testid` attributes or role-based selectors (`page.getByRole('button', { name: 'Submit' })`) for WP Sudo's own UI elements rather than WordPress admin CSS classes. WP Sudo controls its own HTML and can add stable `data-testid` attributes. WordPress admin chrome selectors should be treated as fragile and avoided where possible.

**Warning signs:**
- Tests start failing overnight without any commit
- Selector `#wp-admin-bar-wp-sudo-active` still exists but `.ab-label` child is gone or renamed
- The challenge page's `.wp-sudo-challenge-card` exists but the WordPress admin page wrapper changed structure

**Phase to address:**
Environment scaffolding phase. Pin WordPress version on day one. Add the selector stability rule to the test authoring guide.

---

### Pitfall 10: Node.js and npm Version Incompatibilities with the PHP-Centric Project

**What goes wrong:**
This project has no `package.json` today. When Playwright is added, the project acquires Node.js, npm, and a `node_modules` directory. The existing CI workflow (`phpunit.yml`) uses `ubuntu-24.04` runners with PHP but does NOT set up Node.js. Adding a new `playwright.yml` workflow that installs Node.js independently can create version inconsistencies: GitHub Actions runners ship with a system Node.js (typically the LTS version at runner build time, which may lag behind what Playwright requires).

Playwright's minimum Node.js version changes with each Playwright release. Playwright 1.x requires Node.js 18+. Using the system Node.js on an older runner can fail silently by installing a broken Playwright binary rather than reporting an explicit version error.

**Why it happens:**
PHP developers adding Playwright for the first time often write `npm install playwright` without specifying a Playwright version constraint in `package.json`, and without explicitly specifying the Node.js version in the CI workflow. The runner's system Node.js may be 16.x (too old for current Playwright) or the npm cache from a previous run may have cached an incompatible version.

**How to avoid:**
Add `package.json` with a pinned Playwright version (`"@playwright/test": "^1.41.0"` or later). Add a `.nvmrc` or `engines` field in `package.json` specifying the minimum Node.js version. In the Playwright CI workflow, use `actions/setup-node@v4` with an explicit `node-version` rather than relying on the runner's system Node.js. Add `playwright.config.ts` with a `webServer` block or explicit `baseURL` so configuration is self-documenting and portable.

**Warning signs:**
- `npx playwright install` failing with "GLIBC" or Node.js version errors
- CI failing on `npm install` with peer dependency errors
- Playwright tests running but browser binary not found (install step silently skipped)
- Different Playwright behavior between local development and CI due to version mismatch

**Phase to address:**
Environment scaffolding phase. `package.json`, `.nvmrc`, and `playwright.config.ts` must be established as part of the initial scaffolding, not added piecemeal.

---

## Technical Debt Patterns

Shortcuts that seem reasonable but create long-term problems.

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Using `page.waitForTimeout(2000)` instead of proper wait conditions | Simple to write; hides timing issues | Slow test suite; still flaky on slow CI; masks the real synchronization problem | Never — use `waitForSelector`, `waitForURL`, or `waitForFunction` instead |
| Logging in on every test via the browser login form | No setup complexity | 2–4 seconds per test; suite becomes slow as tests grow | Only for the very first test verifying login itself works |
| Storing sudo token in `storageState` | Authentication state reused | Token expires during long test runs; causes mid-suite auth failures | Never — sudo token must be fresh per test session |
| Testing selector-based UI without `data-testid` anchors | No code changes needed in the plugin | Fragile across WordPress major versions; WP 7.0 admin refresh is a known risk | Only for WordPress core elements that WP Sudo does not control |
| Leaving `headless: false` in `playwright.config.ts` | Easier debugging | CI runner has no display; tests fail if config is committed headed | Local override only via `--headed` flag; config must stay headless |
| Using a single wp-env state for all tests | Simple setup | Rate-limiting state, MU-plugin state, and session state bleed between test files | Never — establish reset protocol from day one |
| Running E2E tests in the same CI job as unit/integration tests | One workflow file | E2E tests are slow (minutes); they should not block fast PHP feedback cycles | Never — E2E tests belong in a separate workflow |

---

## Integration Gotchas

Common mistakes when connecting Playwright to the WordPress environment.

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| wp-env + Docker | Assuming `wp-env start` means WordPress is ready for requests | Add a warm-up check: `npx wp-env run tests-cli wp core is-installed` before the test suite starts |
| wp-env port | Hardcoding `http://localhost:8888` in test URLs | Read port from wp-env or define `baseURL` in `playwright.config.ts` from `process.env.WP_ENV_PORT`; default is 8888 but can conflict |
| wp-env HTTPS | Assuming HTTPS is available | wp-env uses HTTP only by default; tests using `https://` will fail; use `http://localhost:8888` |
| WordPress nonces | Extracting and reusing nonces between tests | Nonces are tied to sessions and expire; extract fresh nonces in each test that needs them from the loaded page |
| AJAX admin-ajax.php | Calling `admin-ajax.php` directly via `page.request.post()` without cookies | Must include WordPress auth cookies; use `page.context().request` to inherit cookies from the authenticated browser context |
| Docker-in-Docker | Running wp-env Docker from within a GitHub Actions job that already uses Docker | GitHub Actions `ubuntu-24.04` supports Docker natively without DinD; do NOT use the `docker` service container for wp-env — wp-env manages its own Docker |
| Two Factor plugin in wp-env | Manually installing via SFTP | Use `.wp-env.json` `plugins` array to include the Two Factor plugin by WP.org slug or GitHub URL; this is idempotent and reproducible |
| Rate limiting in parallel tests | Two Playwright workers both failing authentication simultaneously | They share the same IP (loopback); one worker's lockout blocks the other; run lockout tests in `project: { workers: 1 }` context |

---

## Performance Traps

Patterns that work with a small test suite but fail as the suite grows.

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| Browser login on every test | Test suite grows from 5 to 50 tests; suite time goes from 30s to 5 min | Use `storageState` for WordPress auth cookies; only login once in `global-setup.ts` | After 10+ authenticated tests |
| `workers: 1` for all tests | Correct for lockout tests; catastrophic for all others | Use Playwright `projects` to run lockout tests serially; run the rest in parallel | As soon as more than 5 tests exist |
| Running wp-env `clean all` between every test | Guarantees clean state | Each `clean all` takes 30–60 seconds; 50 tests × 45s = 37+ minutes | After 3 tests |
| Screenshotting the full page including admin bar | Stable now | Admin bar countdown changes every second when session is active | First test run with an active session |
| Not caching Playwright browsers in CI | Playwright re-downloads Chromium/Firefox/WebKit on every run | Cache `~/.cache/ms-playwright` keyed on Playwright version; saves 200–400MB download per run | Every CI run without caching |

---

## Security Mistakes

Security-relevant testing mistakes specific to this domain.

| Mistake | Risk | Prevention |
|---------|------|------------|
| Committing `storageState.json` to the repository | Admin credentials and session tokens visible in version history | Add `tests/e2e/.auth/*.json` and `storageState.json` to `.gitignore` immediately |
| Hardcoding test admin passwords in test fixtures | Password visible in repository; used to test lockout behavior | Use environment variables or wp-env's default admin credentials (documented, not committed) |
| Tests that disable the gate for convenience (`update_option('wp_sudo_settings', ...)`) | Creates a test-only bypass path that could be cargo-culted into production code | Test the gate as it runs; if a test needs to bypass the gate, use the session activation flow, not a settings override |
| Using the production wp-env database URL in CI | Writes test data (failed attempts, lockout records) to a database shared with other CI jobs | Always use `wp-env`'s isolated test database; never configure Playwright to point at a live site |
| Testing with WP_DEBUG=true enabled in wp-env | PHP notices and warnings appear in page content, breaking text assertions | Use `"config": { "WP_DEBUG": false }` in `.wp-env.json` for the test environment, or mask debug output in assertions |

---

## UX Pitfalls

Developer experience mistakes that make the test suite hard to maintain.

| Pitfall | Developer Impact | Better Approach |
|---------|-----------------|-----------------|
| Tests named after implementation details (`test('AJAX handler returns 200')`) | Unclear what the test validates; hard to update when implementation changes | Name tests after user-observable behavior: `test('admin activating a session sees countdown in admin bar')` |
| Putting wp-env setup in `package.json` scripts only | PHP developers unfamiliar with npm cannot run E2E tests | Add a `composer test:e2e` script that delegates to `npm run test:e2e`; document in README |
| Using `page.screenshot()` for every assertion | Screenshot files accumulate; diffs are noisy | Reserve screenshots for failure artifacts (`--screenshot=only-on-failure`) and explicit visual snapshot tests |
| No `test.describe` grouping | 50+ flat tests in one file; hard to run subsets | Group by feature area: `challenge flow`, `admin bar`, `MU-plugin install`, `rate limiting` |
| Missing Playwright trace artifacts on CI failure | "The test failed" with no evidence | Configure `trace: 'on-first-retry'` so CI captures a `.zip` trace file on failure; upload as a workflow artifact |

---

## "Looks Done But Isn't" Checklist

Things that appear complete but are missing critical pieces.

- [ ] **wp-env clean protocol:** Often set up but not verified — confirm `npx wp-env clean all` actually drops the sudo-specific user meta and transients, not just the WP options. Run `npx wp-env run tests-cli wp user meta list 1` after clean to verify no `_wp_sudo_*` keys remain.
- [ ] **storageState excludes sudo cookies:** After saving auth state in `global-setup.ts`, inspect the saved JSON and confirm `wp_sudo_token` and `wp_sudo_challenge` are NOT present. These cookies must never be reused.
- [ ] **Playwright browser cache in CI:** After adding `actions/cache` for `~/.cache/ms-playwright`, verify the cache hits on second run (check "Cache restored" in GitHub Actions output). A misconfigured cache key will silently re-download browsers on every run.
- [ ] **wp-env version pinned:** After writing `.wp-env.json`, confirm the `"core"` field references a specific WP tag (`"WordPress/WordPress#6.9"`) not `null` or `"trunk"`. Run `npx wp-env start` and check `npx wp-env run tests-cli wp core version` to verify the expected version.
- [ ] **Countdown timer masked in visual snapshots:** Any `toHaveScreenshot` call on an admin page after session activation must include `{ mask: [page.locator('#wp-admin-bar-wp-sudo-active')] }`. Check all snapshot assertions for this mask.
- [ ] **Node.js version pinned:** After adding `.nvmrc` and `engines` in `package.json`, verify GitHub Actions uses `actions/setup-node@v4` with the same version, not the runner's system Node.js.
- [ ] **E2E tests isolated from PHP test suite in CI:** After adding `playwright.yml`, confirm `phpunit.yml` does NOT include any Playwright steps. The PHP and E2E workflows should be completely separate files with no shared jobs.

---

## Recovery Strategies

When pitfalls occur despite prevention, how to recover.

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Stale wp-env state causing test failures | LOW | Run `npx wp-env clean all && npx wp-env start`; re-run tests |
| Race condition: `waitForNavigation` after AJAX challenge | MEDIUM | Replace all `waitForNavigation()` calls with `waitForURL(expectedPattern)`; audit all challenge page tests |
| IP lockout bleeding across parallel test workers | LOW | Clear IP transients via `wpCli wp transient delete --regex wp_sudo_ip_*`; redesign lockout tests to use `project: { workers: 1 }` |
| Admin bar countdown causing snapshot drift | LOW | Add countdown timer mask to all affected `toHaveScreenshot` calls; regenerate baselines with `npx playwright test --update-snapshots` |
| storageState containing stale sudo token | LOW | Delete the saved state file; regenerate with `npx playwright test --global-setup`; add sudo cookies to exclusion filter |
| WordPress version auto-upgraded in wp-env | MEDIUM | Pin version in `.wp-env.json`; regenerate all snapshots after verifying selectors still work on pinned version |
| Node.js version mismatch in CI | LOW | Add `actions/setup-node@v4` with explicit `node-version: '20'` to Playwright CI workflow |
| `Frame was detached` on challenge page | MEDIUM | Add `page.waitForURL(/wp-sudo-challenge/)` before any challenge page assertions; ensure tests navigate to challenge URL from top-level frame |

---

## Pitfall-to-Phase Mapping

How roadmap phases should address these pitfalls.

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| Stale wp-env state (Pitfall 1) | Environment scaffolding | `npx wp-env clean all` in CI confirmed working; `_wp_sudo_*` user meta absent after clean |
| AJAX navigation race condition (Pitfall 2) | First behavioral tests | Zero `waitForNavigation()` calls in test files; all challenge interactions use `waitForURL` or `Promise.all` pattern |
| IP rate limiting across parallel workers (Pitfall 3) | Rate limiting test phase | Lockout tests tagged to run serially; IP transient cleanup in `afterEach` |
| Countdown timer in snapshots (Pitfall 4) | Admin bar test phase | All `toHaveScreenshot` calls on admin pages include countdown mask; timer-related tests use `session_duration = 1` |
| wp-env cold-start latency (Pitfall 5) | Environment scaffolding | `global-setup.ts` includes warm-up request; tests do not fail on first CI run of a fresh container |
| storageState sudo token contamination (Pitfall 6) | Authentication scaffolding | `storageState.json` inspected and confirmed free of `wp_sudo_*` cookies |
| iframe-break `Frame was detached` (Pitfall 7) | Plugin activation gating tests | `waitForURL(/wp-sudo-challenge/)` present in all plugin-update-triggered challenge tests |
| POST replay untestable via `route()` (Pitfall 8) | POST replay test phase | Tests assert destination page state, not form fields; PHPUnit integration tests cover stash data integrity |
| WordPress version drift (Pitfall 9) | Environment scaffolding | `.wp-env.json` pins specific WP version; CI job checks `wp core version` against expected value |
| Node.js version incompatibility (Pitfall 10) | Environment scaffolding | `.nvmrc` committed; `package.json` has `engines` field; CI uses `actions/setup-node@v4` with explicit version |

---

## Sources

- WP Sudo `admin/js/wp-sudo-challenge.js` — AJAX-driven navigation pattern, iframe-break behavior, POST replay via `HTMLFormElement.prototype.submit.call()`, loading overlay state machine (HIGH confidence)
- WP Sudo `admin/js/wp-sudo-admin-bar.js` — `setInterval` countdown, `window.location.reload()` at expiry, `wp-sudo-expiring` CSS class timing (HIGH confidence)
- WP Sudo `includes/class-sudo-session.php` — `MAX_FAILED_ATTEMPTS = 5`, `LOCKOUT_DURATION = 300`, `PROGRESSIVE_DELAYS`, `GRACE_SECONDS = 120`, per-IP transient prefix constants (HIGH confidence)
- WP Sudo `includes/class-challenge.php` — AJAX actions, challenge page slug, nonce, stash key parameter (HIGH confidence)
- WP Sudo `includes/class-admin.php` — MU-plugin AJAX install/uninstall actions (HIGH confidence)
- WP Sudo `.github/workflows/phpunit.yml` — Current CI structure: separate unit, integration, and code-quality jobs; `ubuntu-24.04` runner; no Node.js setup (HIGH confidence)
- WP Sudo `composer.json` — No Node.js tooling currently; confirms zero-Node.js baseline (HIGH confidence)
- Playwright architecture: AJAX navigation patterns, `storageState`, `waitForNavigation` race conditions, `Promise.all` pattern (MEDIUM confidence — training data, not verified against current official docs)
- `@wordpress/env` (wp-env) behavior: Docker volume persistence, `wp-env clean`, port defaults, HTTP-only (MEDIUM confidence — training data; verify against current `@wordpress/env` docs before implementation)
- GitHub Actions Docker networking: loopback IP sharing across parallel jobs (MEDIUM confidence — training data; verify with GitHub Actions docs if IP-based tests prove problematic)

---
*Pitfalls research for: Adding Playwright E2E testing to WP Sudo (zero Node.js baseline)*
*Researched: 2026-03-08*
