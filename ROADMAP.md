# Roadmap: Past and Future Planning — Integration Tests, WP 7.0 Prep, Collaboration, TDD, and Core Design

*Updated February 28, 2026*

## Table of Contents

- **[Planned Development Timeline](#planned-development-timeline)** — Immediate, short-term, medium-term, and later work phases
- **[Context](#context)** — v2.8.0 state: 391 unit + 73 integration tests, CI matrix, WP 7.0 status
- **[1. Integration Tests](#1-integration-tests--scope-and-value)** — Complete ✓ (80 tests), coverage analysis, remaining gaps
- **[2. WordPress 7.0 Prep](#2-wordpress-70-prep-ga-april-9-2026)** — Beta 1 tested ✓, one task remaining: "Tested up to" bump on GA day
- **[3. Collaboration & Sudo](#3-collaboration-and-sudo--multi-user-editing-scenarios)** — Multi-user editing, conflict resolution
- **[4. Context Collapse & TDD](#4-context-collapse-and-tdd)** — LLM confabulation defense, test-driven development
- **[Recommended Next Steps](#recommended-next-steps-priority-order)** — Immediate, short-term, medium-term priorities
- **[5. Environment Diversity Testing](#5-environment-diversity-testing-future-milestone)** — Apache, PHP 8.0, MariaDB, backward compat
- **[6. Coverage Tooling](#6-coverage-tooling-baseline-established)** — PCOV baseline established, full matrix deferred
- **[7. Mutation Testing](#7-mutation-testing-deferred-to-post-environment-diversity)** — Deferred until integration suite is fast enough
- **[8. Exit Path Testing](#8-exit-path-testing)** — `@runInSeparateProcess` for security-critical exit/die paths
- **[9. Code Review Findings](#9-code-review-findings-gpt-53-codex-verified-addendum)** — Triaged findings from line-verified code review
- **[10. Core Sudo Design](#10-core-sudo-design)** — Already achieved (13), to implement (6), to consider (4), discarded (5)
- **[11. Feature Backlog](#11-feature-backlog)** — WSAL sensor, IP+user rate limiting, dashboard widget, Gutenberg, network policy
- **[Appendix A: Accessibility](#appendix-a-accessibility-roadmap)** — 15 resolved WCAG items (v2.2.0–v2.3.1)

---

## Planned Development Timeline

### Immediate (Blocking WP 7.0 GA — April 9, 2026)
- **Update "Tested up to"** in readme files when WordPress 7.0 ships

### ✓ Completed in v2.8.0

- ~~Expire sudo session on password change~~ — shipped v2.8.0: hooks `after_password_reset` and `profile_update`; meta-existence guard prevents phantom audit events
- ~~WPGraphQL conditional display~~ — shipped v2.8.0: settings, help tab, and Site Health adapt when WPGraphQL is inactive
- ~~Apache dev testing~~ — v2.8.0 verified on Apache (Local by Flywheel): single-site + multisite subdomain, REST API gating, Application Password auth with `HTTP_AUTHORIZATION` passthrough confirmed working

### ✓ Completed in v2.6.0

- ~~Login grants sudo session~~ — shipped v2.6.0: `wp_login` hook calls `Sudo_Session::activate()`; mirrors Unix sudo / GitHub sudo mode
- ~~Gate `user.change_password`~~ — shipped v2.6.0: closes session-theft → password change → lockout attack chain
- ~~Grace period (two-tier expiry)~~ — shipped v2.6.0: 120 s grace window after expiry; session-token-verified, deferred cleanup

### ✓ Completed in v2.5.x

- ~~WPGraphQL surface gating (Disabled / Limited / Unrestricted)~~ — shipped v2.5.0, fixed v2.5.1–v2.5.2
- ~~Cross-origin headless mutation bypass~~ — fixed v2.5.2 (SvelteKit testing revealed unauthenticated mutations passed through)
- ~~Per-app-password policy dropdown~~ — fixed v2.5.2 (was silently broken since v2.3)
- ~~Security hardening (Opus audit)~~ — fixed v2.5.2: MU-plugin AJAX, app-password AJAX, user.promote rule
- ~~WPGraphQL headless authentication boundary~~ — documented v2.5.2
- ~~Abilities API (WordPress 6.9+)~~ — documented v2.5.1: covered by existing REST API (App Passwords) policy

### Medium-term (v2.9+)

**Core Design:**
- WP-CLI `wp sudo` subcommands (status, revoke)
- Public `wp_sudo_check()` / `wp_sudo_require()` API for third-party plugins

**Feature Backlog (Open):**
- WSAL (WordPress Activity Log) sensor extension — high impact for enterprise
- Multi-dimensional rate limiting (IP + user combination)
- Session activity dashboard widget
- Gutenberg block editor integration
- Network policy hierarchy for multisite

### Later (v2.9+) — Deferred, Need Design Work

**Major Features (require architectural design first):**
- Client-side modal challenge (UX like GitHub) — significant complexity
- Per-session sudo isolation (per-device/per-browser state)
- REST API sudo grant endpoint for headless clients
- SSO/SAML/OIDC provider framework

**Testing Improvements:**
- **Phase A:** Expand CI matrix (PHP 8.0, WordPress 6.2–6.9 backward compat)
- **Phase B:** Apache + MariaDB CI job
- **Phase C:** Manual testing checklist for managed hosts
- **Phase D:** Docker Compose with switchable stacks
- Exit path testing — `@runInSeparateProcess` for 5–8 security-critical exit/die paths (see [section 8](#8-exit-path-testing))
- Coverage tooling expansion (baseline established; full matrix after environment diversity milestone)
- Mutation testing (after environment diversity milestone)

---

## Context

This is a living document covering accumulated input and thinking about the strategic
challenges and priorities for WP Sudo. 

Current project state (as of v2.9.1):
- **397 unit tests**, 944 assertions, across 15 test files (Brain\Monkey mocks)
- **80 integration tests** across 13 test files (real WordPress + MySQL via `WP_UnitTestCase`)
- CI pipeline: PHP 8.1–8.4, WordPress latest + trunk, single-site + multisite + PCOV coverage job
- WordPress 7.0 Beta 2 tested (February 27, 2026); GA is April 9, 2026

---

## 1. Integration Tests — Scope and Value

> **Status: Complete.** The integration test suite shipped in v2.4.0 (55 tests) and
> expanded in v2.4.1 (73 tests). CI runs against PHP 8.1–8.4, WordPress latest +
> trunk, single-site + multisite. The analysis below is preserved for context on
> what drove the test design.

### What unit tests cover well (no integration gap)
- Request matching across all 6 surfaces (98 GateTest methods)
- Session state machine, token crypto, rate limiting
- Hook registration and filter application
- Policy enforcement (DISABLED/LIMITED/UNRESTRICTED)
- Upgrader migration logic
- Settings sanitization and defaults

### What unit tests cannot cover (real integration gaps)

These gaps have been closed by the integration suite:

| Gap | Integration coverage (v2.4.1) |
|-----|-------------------------------|
| **Cross-class workflows** (Gate → Challenge → Session → Stash) | `ReauthFlowTest` — 4 end-to-end tests |
| **Request stash replay** | `RequestStashTest` — 7 tests including transient TTL |
| **Real `wp_check_password()`** | `SudoSessionTest` — 10 tests with real bcrypt |
| **Transient TTL enforcement** | `RequestStashTest`, `SudoSessionTest` |
| **Two Factor plugin interaction** | `TwoFactorTest` — 7 tests with real provider |
| **Database state after migrations** | `UpgraderTest` — 4 tests against real options/meta |
| **REST API with real auth** | `RestGatingTest` — 7 tests with cookie and app password auth |
| **AJAX gating** | `AjaxGatingTest` — 12 tests covering all 7 declared AJAX actions |
| **Audit hooks** | `AuditHooksTest` — 8 tests across CLI, Cron, XML-RPC, REST |
| **Rate limiting** | `RateLimitingTest` — 6 tests with real user meta |
| **Multisite isolation** | `MultisiteTest` — 5 tests |

### Remaining integration gaps

- **Cookie/header behavior** — `setcookie()` still guarded by `headers_sent()` check.
  Real httponly/SameSite attributes require browser-level testing (Playwright/Cypress).
- **Hook timing and priority** — no automated test verifies `admin_init` priority 1
  ordering relative to other plugins. Covered by manual testing guide.
- **Admin UI rendering** — visual correctness tested manually, not automated.

---

## 2. WordPress 7.0 Prep (GA April 9, 2026)

> **Status:** WP 7.0 Beta 1 manually tested February 19, 2026 — all sections PASS. One task remains: bump "Tested up to" in readme files when 7.0 GA ships.

### Verified changes that affect WP Sudo

| Change | Impact | Action needed |
|--------|--------|---------------|
| **PHP minimum raised to 7.4** (dropping 7.2/7.3) | WP Sudo requires PHP 8.0+. No impact. | None. Already ahead of the curve. |
| **Always-iframed post editor** | All blocks render in iframe. WP Sudo's admin UI gating does not touch the block editor — it intercepts `admin_init` actions, not editor saves. | **Low risk.** Verify the challenge page CSS still works inside the admin chrome. |
| **Admin visual refresh** (DataViews, design tokens, Trac #64308) | Settings → Sudo page uses standard `settings_fields()` / `do_settings_sections()`. If WP 7.0 reskins these, our page gets the new look for free. | **Test visually** on a 7.0-beta site. Check help tabs, gated-actions table, admin notices. |
| **Fragment Notes + @ mentions** | Extends 6.9 Notes (block-level comments). No auth surface — notes are post meta. | No impact on WP Sudo. |
| **Abilities API expansion** | New REST surface for AI agents. Abilities use `permission_callback` (typically `current_user_can()`). Not gated by WP Sudo. | **Future consideration:** should destructive abilities trigger sudo? Not for 7.0 — monitor. |
| **WP AI Client merge proposal** | Provider-agnostic AI API. Includes REST/JS layer. | No immediate impact. If merged, AI model calls are a new admin action surface. Monitor. |
| **WordPress MCP Adapter** | Adapts Abilities to MCP tools for AI agents (Claude, Cursor, etc.). | Same consideration as Abilities API — a new surface for privileged operations. |
| **Viewport-based block visibility** | Editor-only. No auth surface. | No impact. |
| **Trac #64690 — Bulk role-change error message** ([ticket](https://core.trac.wordpress.org/ticket/64690)) | Core will replace the confusing "user editing capabilities" notice with a clear message when bulk role change skips the current user. Our workaround in `Admin::handle_err_admin_role()` (`class-admin.php`) does the same thing and can be **removed** once 7.0 ships. | **After 7.0 GA:** remove `handle_err_admin_role()` and its `admin_notices` hook; delete the corresponding unit tests in `AdminTest.php`. |

### What to do now

1. ~~**Install WP 7.0 Beta 1** on Local or Studio dev site~~ — done (February 19, 2026)
2. ~~**Run the manual testing guide** against 7.0-beta~~ — done; all 15 sections PASS
3. ~~**Visual check:** settings page, help tabs, admin bar timer, challenge interstitial, admin notices~~ — done; all pass against refreshed admin chrome
4. ~~**Run `composer test`**~~ — passing on WP 7.0-alpha / 7.0-beta; CI covers WP trunk
5. **Update version references** when 7.0 ships (April 9):
   - `readme.txt` / `readme.md` — "Tested up to" bump
   - Any docs still referencing "WordPress 6.9" as latest
6. **Remove `handle_err_admin_role()` workaround** once WP 7.0 GA ships (Trac #64690 lands in core — see table row above).

### Abilities API: the longer-range question

The Abilities API is the first new admin action surface WordPress has added since
Application Passwords (WP 5.6). Currently, abilities use `permission_callback` for
access control — there's no reauth step. If an ability does something destructive
(e.g., a future `core/delete-plugin` ability), WP Sudo would need to intercept it.

**Recommended approach:** Add a new surface type `ability` to the Gate's surface
detection. This is not urgent for 7.0 (only 3 read-only core abilities exist), but
should be on the roadmap for when destructive abilities appear.

---

## 3. Collaboration and Sudo — Multi-User Editing Scenarios

### What collaboration looks like in WP 7.0

**Notes (confirmed, shipping):** Asynchronous block-level and fragment-level comments.
These are post meta. They don't trigger admin actions. **No sudo impact.**

**Real-time co-editing (experimental/uncertain):** If it ships, multiple users edit
the same post simultaneously with presence cursors. WordPress VIP already has this
as a managed feature. For core, the technical challenge is making it work without
WebSocket infrastructure on shared hosting.

### The scenario: User A and User B editing the same content, User A activates sudo

WP Sudo's session is **per-user, per-browser**. It does not lock content or create
cross-user state. So:

1. **User A triggers a gated action** (e.g., activating a plugin from the Plugins page
   while User B is editing a post). User A gets the challenge interstitial. User B
   is completely unaffected — they're on a different admin page.

2. **Both users are in the post editor, User A triggers sudo from the admin bar
   shortcut (Ctrl+Shift+S).** User A sees the challenge modal. User B sees nothing.
   Sudo session tokens are bound to User A's user meta and browser cookie. No
   cross-user interference.

3. **The real edge case: collaborative editing of a gated resource.** Today, WP Sudo
   gates *admin actions* (plugin activate, user delete, settings changes), not
   *content saves*. Post editing is not a gated action. So even with real-time
   co-editing, WP Sudo doesn't intercept content saves and no conflict arises.

4. **Future edge case: if WP Sudo ever gates content actions** (e.g., publishing,
   trashing, or deleting posts). In a co-editing scenario, User A publishes →
   sudo challenge → User B sees... what? The post is in a "pending publish" state
   from User B's perspective. This is a UX design question, not a technical one.
   The answer is probably: don't gate content saves. Gate the *destructive* actions
   around content (trash, delete, capability changes) where concurrent editing isn't
   relevant.

### Bottom line

Collaboration features in WP 7.0 don't create conflicts with WP Sudo because:
- Notes are post meta, not admin actions
- Co-editing is content editing, not admin actions
- Sudo sessions are per-user, not per-resource
- The plugin gates admin operations, not content operations

Monitor for: destructive abilities exposed via REST, and any future core features
that let one user's admin action affect another user's in-progress work.

---

## 4. Context Collapse and TDD

### The problem at 13.5k lines

WP Sudo is already past 13k lines of PHP. In an LLM-assisted workflow, context
collapse means: the model can't hold the full codebase in context, starts making
changes that conflict with code it hasn't read, invents function signatures instead
of looking them up, and introduces subtle regressions.

This is exactly how the confabulation errors happened — training-data guesses
substituted for verifiable facts. The same failure mode applies to the plugin's
own code as it grows.

### What helps (in order of effectiveness)

**1. TDD — the single most effective mitigation**

A "tests first, always" rule works because:
- It forces reading the existing code before writing new code (you need to know
  what to assert against)
- It catches regressions immediately, even when the LLM can't hold the full
  codebase in context
- It creates a machine-verifiable contract — the tests don't care whether the
  code was written by a human or an LLM
- It prevents the "lazy shortcut" failure where generated code looks right but
  doesn't actually work

**2. CLAUDE.md as the architectural single source of truth**

Already started. The Architecture section in CLAUDE.md is the most important
defense against context collapse — it tells the LLM what exists without requiring
it to read every file. Keep expanding it as new classes/surfaces are added.

**3. Small, focused commits**

Already practiced. Each commit should touch one concern. This limits the amount
of context needed per change and makes `git diff` reviewable.

**4. PHPStan level 6 as a guardrail**

Already in place. Static analysis catches type mismatches, undefined methods, and
wrong argument counts — exactly the errors an LLM makes when it invents function
signatures.

**5. Pre-commit test gate**

Already in Commit Practices ("Always run tests before committing"). Could be
enforced with a git pre-commit hook, but the CLAUDE.md instruction is sufficient
for LLM-assisted work since Claude follows it.

### What about Amp, Beads, etc.?

These are workflow tools for managing LLM context across sessions:
- **Amp** — context management for Claude Code sessions
- **Beads** — structured context passing between LLM calls

They address a real problem (session continuity), but they're additive tooling,
not fundamental mitigations. TDD + CLAUDE.md + PHPStan address the root cause:
the LLM writes code it can't verify. Tests verify it mechanically. Static analysis
catches type-level errors. CLAUDE.md provides architectural context without needing
to read every file.

If the project grows to 30k+ lines, context management tools become more valuable.
At 13k lines, the bottleneck is verification (tests, linting, static analysis),
not context retrieval.

---

## Recommended Next Steps (Priority Order)

> Steps 1–5 completed in v2.4.0–v2.4.1. Steps 6–7 completed in v2.5.0–v2.5.2. Remaining work:

1. ~~Add TDD requirement to CLAUDE.md~~ — done (v2.4.0)
2. ~~Install WP 7.0 Beta 1, run manual testing guide~~ — done (v2.4.0)
3. ~~Scaffold integration test harness~~ — done (v2.4.0, 55 tests)
4. ~~Write first integration tests~~ — done (v2.4.1, 73 tests)
5. ~~Visual review against 7.0 admin refresh~~ — done (v2.4.0)
6. ~~WPGraphQL surface gating~~ — done (v2.5.0–v2.5.2)
7. ~~Abilities API coverage documented~~ — done (v2.5.1)
8. **Update "Tested up to"** when WP 7.0 ships (April 9, 2026)
9. ~~**Core design features** — login=sudo, gate password changes, grace period~~ — done (v2.6.0)
10. **Plan environment diversity testing** (see section 5)

---

## 5. Environment Diversity Testing (Future Milestone)

The integration test suite and manual testing guide run against multiple local
environment stacks: nginx + SQLite (Studio), nginx + MySQL (Local), and Apache + MySQL
(Local) on macOS with a single PHP version. Apache coverage was added in v2.8.0 via
Local by Flywheel sites. Gaps remain in CI and broader hosting diversity.

### Dimensions to cover

| Dimension | Current coverage | Gap |
|-----------|-----------------|-----|
| **Web server** | nginx (Studio, Local multisite-subdirectory) + Apache (Local single-site, Local multisite-subdomain) | Apache in CI (mod_php, FastCGI, FPM variants) |
| **PHP version** | 8.1–8.4 (CI matrix), 8.2 (Local dev) | 8.0 — minimum declared but not in CI |
| **Database** | MySQL 8.0 (Local CI), SQLite (Studio) | MariaDB 10.x, MySQL 5.7 (legacy hosts) |
| **WordPress version** | latest + trunk (CI), 6.9.1–7.0-beta2 (dev sites) | 6.2–6.8 backward compat (minimum declared but not in CI) |
| **OS** | macOS (dev), Ubuntu 24.04 (CI) | Windows (if any WP-CLI or path handling is OS-sensitive) |
| **Hosting stack** | Bare local dev | Shared hosting (cPanel), managed WP (Pressable, WP Engine, Cloudways), containerized (Docker, Kubernetes) |

### Why this matters for WP Sudo specifically

- **Apache `mod_rewrite` vs nginx `try_files`:** The challenge page redirect and
  request replay depend on WordPress rewrite rules. Apache's `.htaccess` and nginx
  configs handle these differently. The REST API `Authorization` header handling
  also differs (Apache may strip it unless `CGIPassAuth` or `.htaccess` rules are
  in place).
- **PHP version differences:** `password_verify()` behavior, `setcookie()` signature
  changes (PHP 8.0 named params), `session_*` function availability,
  `json_validate()` (8.3+), readonly properties (8.2+).
- **Database engine:** MariaDB and MySQL have subtle JSON and collation differences.
  The upgrader migration chain and option serialization could behave differently.
- **Backward compat:** The plugin declares WordPress 6.2+ minimum. There are no
  automated tests verifying it actually works on 6.2, 6.5, or 6.7. The CI matrix
  tests `latest` and `trunk` only.

### Recommended approach

**Phase A: Expand CI matrix (low effort, high value)**

Add WordPress version and PHP version dimensions to the GitHub Actions matrix:

```yaml
strategy:
  matrix:
    php: [8.0, 8.1, 8.3, 8.4]
    wp: [latest, trunk, '6.7', '6.5', '6.2']
    exclude:
      # Skip combinations that don't exist
      - { php: 8.4, wp: '6.2' }
```

This covers PHP + WP backward compat with zero infrastructure changes. The existing
`install-wp-tests.sh` already supports arbitrary WP versions.

**Phase B: Apache + MariaDB CI job (medium effort)**

Add a separate CI job that runs on an Apache + MariaDB container instead of the
default nginx + MySQL. This catches `.htaccess`-dependent behavior and MariaDB
query differences.

**Phase C: Manual testing matrix (low effort, recurring)**

Extend `tests/MANUAL-TESTING.md` with an environment checklist section. Before each
release, run the manual guide on at least:
- One Apache environment (DDEV, MAMP, or a staging host)
- One managed WordPress host (Pressable, WP Engine, or Cloudways free trial)
- The minimum supported WordPress version (currently 6.2)

**Phase D: Docker-based local testing (medium effort)**

Create a `docker-compose.yml` with switchable profiles:
- `apache-mysql` (the classic LAMP stack)
- `nginx-mariadb` (alternative)
- `apache-sqlite` (WP 6.4+ SQLite support)

This lets any contributor reproduce the full matrix locally.

### Priority

This is a **post-v2.4 milestone** concern. The current v2.4 milestone focuses on
integration test coverage and WP 7.0 readiness. Environment diversity testing
should be scoped as a v2.5 or v2.6 milestone, with Phase A (CI matrix expansion)
as the first deliverable since it requires no new infrastructure.

---

## 6. Coverage Tooling (Baseline Established)

**Status:** A single PCOV coverage CI job runs against the unit test suite (PHP 8.3).
This establishes a baseline without adding overhead to the full integration matrix.

**What's in place:**
- `composer test:coverage` — runs unit tests with PCOV, generates `coverage.xml` + text summary
- CI job `unit-tests-coverage` — runs on every push/PR, uploads `coverage.xml` as artifact
- No failure threshold yet — the first run establishes the baseline

**What's deferred:**
- Coverage across the full integration matrix (8 jobs across PHP 8.1/8.3 ×
  WP latest/trunk × single/multisite). The marginal CI cost is not justified
  until the matrix is stable.
- Coverage badge. Unit tests mock WordPress functions via Brain\Monkey, so line
  coverage looks high while entire real code paths (bcrypt, transients, cookies)
  are untested. A badge communicates accuracy only once the integration suite
  is comprehensive and the environment matrix is broad.

**When to expand:** After the environment diversity milestone (Phase A CI matrix
expansion). At that point per-matrix-entry coverage adds signal: you can see
which combinations of PHP/WP versions hit paths the others miss.

---

## 7. Mutation Testing (Deferred to Post-Environment-Diversity)

**Decision: add mutation testing (Infection PHP) after the environment diversity milestone.**

Mutation testing validates that tests actually detect failures by introducing small
code changes (mutations) and verifying the test suite catches them. This is the
right tool for a security plugin — it directly answers "would our tests catch a
regression in the session token comparison or rate limiting logic?"

**Why not now:**
- Infection re-runs the full test suite for every mutant. With the current suite
  (349 unit + 55 integration tests), a full Infection run would take 10–30 minutes
  locally. That's acceptable for a pre-release check, not for CI on every push.
- The more valuable immediate gap is environment diversity: knowing the tests pass
  on Apache/MariaDB and WP 6.2–6.9 is higher confidence signal than mutation score
  on a single stack.
- Mutation testing against mocked unit tests (Brain\Monkey) produces limited signal —
  mutations in production code are hidden by the mock boundary. Infection is most
  useful against the integration suite where real code runs.

**Recommended approach when the time comes:**

1. Run Infection against the integration suite only (`--test-framework-options="--config=phpunit-integration.xml.dist"`).
2. Configure a minimum mutation score indicator (MSI) of 80% as a pre-release gate,
   not a per-push CI gate.
3. Focus mutation scope on security-critical classes: `Sudo_Session`, `Gate`,
   `Challenge` — not `Admin`, `Admin_Bar`, or `Site_Health`.
4. Add a `composer mutation` script for local runs; keep it out of the standard CI
   matrix until the integration suite runs fast enough to justify the overhead.

---

## 8. Exit Path Testing

**Status:** Not started. Blocked by nothing — can be done independently.

The 76 `exit`/`die` paths in the codebase (mostly `wp_send_json()` + `exit` in the Gate) are the biggest remaining testing blind spot. PHPUnit's `@runInSeparateProcess` annotation allows testing code that calls `exit()` by running the test in a child process.

**Scope:** Don't cover all 76 — most follow the same pattern. Target the 5–8 most security-critical exit paths:
- REST API gating: blocked mutation returns `403` with correct error shape
- AJAX gating: blocked action returns `wp_send_json_error()` with correct code
- WPGraphQL gating: blocked mutation returns GraphQL error response
- Gate interception: redirect to challenge page with correct query params
- Challenge submission: successful auth returns correct JSON + session cookie

**Guidelines:**
- Use `@runInSeparateProcess` and `@preserveGlobalState disabled` annotations
- Integration tests only — `@runInSeparateProcess` is not useful for unit tests
- Focus assertions on the HTTP response shape (status code, JSON structure, headers), not on internal state
- Accept the execution cost (~1s per subprocess test) — these run in the integration matrix, not on every keystroke

**When:** After the environment diversity milestone (section 5). The subprocess tests are sensitive to environment differences (output buffering, header handling), so they benefit from running across multiple stacks.

---

## 9. Code Review Findings (GPT 5.3 Codex Verified Addendum)

**Source:** Line-verified code review in `.planning/research.md` (addendum, lines 567–914). All findings reference actual line numbers in the codebase, verified against `composer test:unit`, `composer lint`, and PHPStan. This supersedes the Zen Trinity review in the same file, which contains fabricated code snippets and statistics.

### High Priority

**Grace window scope broader than documented intent**

- Gate permits any matched gated action when the user is within the 120s grace window (`class-gate.php:617-619`, `828-830`, `960-961`).
- `is_within_grace()` only checks time window + token validity, not whether the request was in-flight when the session expired (`class-sudo-session.php:192-211`).
- `docs/security-model.md:153` implies grace should only cover in-flight requests, not grant new gated access.
- **Impact:** Effective policy is "full gated access for 120 seconds after expiry with valid token" — stronger than documented intent, may weaken audit expectations.
- **Action:** Decide on intended semantics. Options: (a) tighten grace to in-flight-only with a stash marker, (b) accept current behavior and update docs to match. Either way, add tests asserting the chosen contract.

**MU-plugin makes deactivation non-authoritative**

- MU shim loads the plugin whenever files exist, regardless of `active_plugins` option (`mu-plugin/wp-sudo-gate.php:20-24`, `mu-plugin/wp-sudo-loader.php:22-30`).
- Plugin deactivation callback does not remove the MU shim (`class-plugin.php:430-438`).
- FAQ states deactivation returns ungated behavior (`FAQ.md:129-132`), but that's only true if the shim is absent.
- **Impact:** If MU shim is installed, deactivating from Plugins screen doesn't reliably disable runtime behavior.
- **Action:** Decide and document intended behavior. If "deactivate means off," remove MU shim on deactivation or add an early bail when plugin is inactive.

### Medium Priority

**`return_url` double-encoding**

- Source side pre-encodes `return_url` before `add_query_arg` (`class-plugin.php:205-209`, `class-gate.php:1118-1120`, `1264-1267`, `1331-1334`).
- Destination side reads value directly without decoding (`class-challenge.php:133-136`, `191-194`).
- **Impact:** Cancel/shortcut return behavior can fall back to dashboard instead of the originating page.
- **Action:** Pass raw URL to `add_query_arg` (let WordPress encode once), or decode before validation on the read path. Add round-trip tests for complex URLs.

**Site Health stale-session scan capped at 100 users**

- `find_stale_sessions()` queries `get_users(... 'number' => 100)` (`class-site-health.php:256-263`).
- **Impact:** Large sites can report "no stale sessions" while stale records exist beyond user 100.
- **Action:** Paginate through all matching users or maintain a cleanup cursor.

**REST cookie-auth detection only checks `X-WP-Nonce` header**

- Cookie-auth classifier in Gate checks only for `X-WP-Nonce` header (`class-gate.php:833-835`).
- No fallback check for `_wpnonce` request param.
- **Impact:** Some legitimate cookie-auth clients may be misclassified as headless and routed to app-password policy.
- **Action:** Add conservative fallback detection for `_wpnonce` request params. Add tests for mixed cookie-auth request shapes.

### Low Priority

**~~Version constant drift between runtime and test/static bootstrap~~** ✅ Fixed

- ~~Runtime: `2.9.1` (`wp-sudo.php:6`, `:25`). Bootstraps: `2.8.0` (`phpstan-bootstrap.php:13`, `tests/bootstrap.php:18`).~~
- ~~**Action:** Synchronize in release process. Consider extracting version to a single source read by all bootstraps.~~
- Fixed: both bootstraps updated to `2.9.1`. Add to release checklist.

**~~2FA default window documentation mismatch~~** ✅ Fixed

- ~~Actual default: 5 minutes (`class-sudo-session.php:370`). Admin help text: 10 minutes (`class-admin.php:323`).~~
- ~~**Action:** Align help text to actual default.~~
- Fixed: help text now reads "5 minutes". Note: this is the **2FA verification window** (how long to enter a 2FA code), not the sudo session duration (15 min default). Two distinct timers.

**2FA window bounds not enforced in code**

- FAQ claims 1–15 minute bounds (`FAQ.md:139`). Code trusts filter return without clamping (`class-sudo-session.php:370-371`).
- **Action:** Clamp filter result to documented min/max, or remove hard-bound language from docs.

**Request stash stores raw POST payloads**

- Stash stores verbatim request arrays (`class-request-stash.php:65-67`, `205-212`).
- **Impact:** If transient storage is exposed (DB or object-cache compromise), sensitive form data has additional exposure surface.
- **Action:** Consider redacting known-secret keys on stash write with denylist. Document tradeoff in security docs.

**Progressive delay uses blocking `sleep()`**

- `sleep($delay)` during failed auth attempts (`class-sudo-session.php:718`).
- **Impact:** Under heavy abuse, blocked PHP-FPM workers reduce throughput.
- **Action:** Consider non-blocking rate limiting (timestamp-only checks) if this becomes operationally relevant.

**App-password admin JS has hardcoded English strings**

- Hardcoded UI strings in `admin/js/wp-sudo-app-passwords.js` (lines 31, 142, 195).
- **Action:** Move to `wp_localize_script()` for localization.

### Findings Already Addressed

| Finding | Status |
|---------|--------|
| Uninstall path has no tests | ✅ Fixed v2.9.1 — `tests/Integration/UninstallTest.php` (2 tests) |
| Multisite uninstall network-active branch can under-clean | ✅ Tested — `UninstallTest::test_multisite_uninstall_cleans_user_meta()` covers the cleanup path |
| Version constant drift (bootstraps at 2.8.0 vs runtime 2.9.1) | ✅ Fixed — `phpstan-bootstrap.php` and `tests/bootstrap.php` updated to `2.9.1` |
| 2FA default window help text says 10 min, code is 5 min | ✅ Fixed — `class-admin.php:323` now reads "5 minutes" |

---

## 10. Core Sudo Design

*February 26, 2026*

### Already achieved

The following areas from our initial design planning and input from others are
fully implemented in WP Sudo (through v2.5.2):

- All five threat model scenarios (XSS→RCE, session theft, device compromise,
  device loss, undetected persistence)
- Post-POST interception with request stash and replay
- Multi-surface coverage with per-surface policy (three-tier model exceeds the
  document's binary treatment)
- Cryptographic token binding (cookie + SHA-256 in user meta)
- Rate limiting and progressive lockout
- Two Factor plugin integration with browser-bound challenge cookies
- GET request gating (theme switch, network site operations, data export)
- Network-wide multisite sessions and 8 multisite-specific rules
- Per-application-password policy overrides
- 9 audit hooks for external logging
- Proactive session-only authentication (no pending action required)
- `unfiltered_html` capability tamper detection
- WPGraphQL surface gating — three-tier policy for GraphQL mutations (Disabled / Limited / Unrestricted), mutation detection heuristic, headless authentication boundary documented (v2.5.0–v2.5.2)

### Features to implement

**Medium priority — target v2.9+**

| Feature | Rationale | Effort |
|---------|-----------|--------|
| **WP-CLI `wp sudo` subcommands** | `wp sudo status`, `wp sudo revoke [--user=<id>]`, `wp sudo revoke --all`. No tooling exists for operators to inspect or manage sudo state from the command line. | Medium |
| **Public `wp_sudo_check()` / `wp_sudo_require()` API** | Let third-party plugins require sudo for their own actions without registering a full Gate rule. WP Crontrol's PHP cron events are the motivating example. Needs design for the challenge trigger path when called outside the Gate flow. | Medium |

### Features to consider (need design work)

These are high-value but architecturally complex. Each needs its own design phase
before implementation.

**Client-side modal challenge**
The ideal UX: `.needs-sudo` CSS class on forms, JS intercepts
submit, inline password prompt, original form submitted with sudo token. Preserves
form state, handles AJAX saves, matches GitHub/Silverstripe. An
iframe + postMessage architecture would support extensibility with 2FA/SSO providers. Major UX improvement
over redirect-to-challenge, but significant complexity: nonce handling, file uploads
(`$_FILES` not stashable in current model), modal-in-modal for the plugin file editor,
fallback server-side flow. Likely a milestone unto itself.

**Per-session sudo isolation**
Current model: one sudo token per user in user meta. The ideal is
"different devices, different sessions, different sudo mode state." Integration
with `WP_Session_Tokens` would provide per-browser isolation — Device A's sudo
would not affect Device B. Architecturally significant; also interacts with nonce
validity if the session layer changes.

**REST API sudo grant endpoint**
A `POST /wp/v2/sudo` endpoint for headless clients to enter sudo mode by providing
credentials. Currently headless clients can only be blocked or allowed by policy.
Threat model needs careful thought — the endpoint must require the credential itself,
not just a valid session, because XSS can obtain both `rest_nonce` and the auth cookie.

**SSO / SAML / OIDC provider framework**
SSO protocols support `IsPassive=true` (SAML) and `prompt=none` (OIDC) as
silent reauthentication mechanisms. Currently there is no formal registration or
dispatch for SSO providers in the challenge flow. Would need a provider interface
(register, render, validate) parallel to the existing 2FA hooks.

### Discarded ideas

| Idea | Reason discarded |
|------|-----------------|
| IP binding as default | Too many false positives (mobile, IPv6 rotation, proxies, CDNs). Acceptable as an opt-in constant/filter, wrong as a default. |
| `sudo-{$cap}` capability wrapper | Backward-incompatible on older WP versions, conflates authentication state with role capabilities. |
| Process-scoped tokens (UAC style) | No session persistence per HTTP request. The session model is correct for web. |
| Assumed roles / role-switching | Facilitating Editor-by-default → assume-Admin is a different product concern, closer to User Switching. Out of scope. |
| Require sudo to access Network Admin | Low value relative to complexity. Gate specific destructive actions instead. |

### Relationship to other roadmap sections

- **Section 1 (Integration Tests):** New features should have integration tests from
  the start — "login grants sudo" and "grace period" are both testable against real
  bcrypt and `wp_login` hooks.
- **Section 2 (WP 7.0 Prep):** The Abilities API (section 2) and the REST sudo grant
  endpoint (above) are complementary — both expand WP Sudo's coverage of non-browser
  surfaces. Monitor together.
- **Section 5 (Environment Diversity):** The modal challenge (if implemented) is the
  feature most sensitive to environment differences — JavaScript, nonce handling, and
  cookie behavior vary across caching layers, reverse proxies, and hosting stacks.

---

## 11. Feature Backlog

Items carried forward from the pre-v2.4 roadmap. Features completed in v2.0.0–v2.3.1
(Site Health integration, progressive rate limiting, CSP-compatible assets, lockout
countdown, admin notice fallback, gated actions table, v2 architecture, editor
`unfiltered_html` restriction, per-app-password policies, PHPStan level 6, CycloneDX
SBOM, accessibility roadmap) are documented in the [CHANGELOG](CHANGELOG.md).

### Open — Medium Effort

**WP Activity Log (WSAL) Sensor Extension**

Optional WSAL sensor shipping as a single PHP file. Register event IDs in the
8900+ range, create a sensor class in the `WSAL\Plugin_Sensors` namespace, and
map existing `wp_sudo_*` action hooks to WSAL alert triggers.

*Impact:* High — dramatically increases appeal to managed hosting and enterprise
customers who already use WSAL.

**Multi-Dimensional Rate Limiting (IP + User)**

Add per-IP tracking via transients alongside existing per-user tracking. Catches
distributed attacks where multiple IPs target the same user, or one IP targets
multiple users. Include IP in the `wp_sudo_lockout` audit hook for logging.

*Impact:* High — hardens brute-force protection against coordinated attacks.

**Session Activity Dashboard Widget**

Admin dashboard widget showing active sudo sessions (count + user list), recent
gated operations (last 24 h from audit hooks), and policy summary. On multisite,
a network admin widget could show activity across all sites.

Requires storing audit data — currently the hooks fire-and-forget with no
persistence. A lightweight custom table or transient-based ring buffer would
be needed.

*Impact:* Medium — useful visibility for site administrators, but not a security
improvement.

### Open — High Effort

**Gutenberg Block Editor Integration**

Detect block editor context and queue the reauthentication requirement instead of
interrupting save. Show a snackbar-style notice using the `@wordpress/notices`
API. Expected to require extracting challenge rendering from `class-challenge.php`
into a reusable component. The snackbar flow needs a different UI surface but the
same auth verification and stash-replay machinery. This is also the natural moment
to add Playwright E2E tests covering both the existing challenge page and the new
editor integration.

*Impact:* Medium — improves UX for block editor users, but the current
stash-replay pattern already works for most editor operations.

**Network Policy Hierarchy for Multisite**

Super admins set minimum session duration and maximum allowed entry-point policies
at the network level. Site admins can only tighten (not loosen) these constraints.
Expected to require extracting a `Settings` or `Policy` class from `class-admin.php`.
The current direct `get_site_option()` access would need to merge network-level
floors with per-site overrides and enforce the "can only tighten" constraint.

*Impact:* Medium — valuable for large multisite networks with delegated site
administration. Not relevant for single-site installs.

### Possible Features

**SBOM Enhancements**

The CycloneDX SBOM (`bom.json`) currently reflects only the PHP/Composer dependency
graph (zero production dependencies). Options:
- GitHub Action for CI-generated SBOMs on every release tag.
- JS dependency tracking if Gutenberg integration introduces an npm build step.
- Whole-site SBOM tooling references in security documentation.

**JS Testing with Playwright**

No JS tests exist today. The vanilla JS files have no build step and limited
surface area, so the cost-benefit of Jest + JSDOM mocks is low. The natural
trigger is Gutenberg integration, which would require browser-level testing anyway.

### Declined

| Feature | Reason |
|---------|--------|
| Session extension (extend without reauth) | Undermines the time-bounded trust model and violates zero-trust principles. The keyboard shortcut (`Cmd+Shift+S` / `Ctrl+Shift+S`) makes re-authentication fast enough. |
| Passkey/WebAuthn reauthentication | Already works through the existing Two Factor plugin integration. The challenge page is provider-agnostic — it renders whatever the active 2FA provider outputs, including WebAuthn's `navigator.credentials.get()` ceremony. No WP Sudo changes needed. |

---

## Appendix A: Accessibility Roadmap

> **Status: Complete.** All items resolved in v2.2.0–v2.3.1. Retained for reference.

All Critical, High, Medium, and Low severity items from the WCAG 2.1 AA audit and
WCAG 2.2 AA follow-up audit have been addressed:

- **Escape key guard (WCAG 3.2.2):** `aria-live` announcement with 600 ms delay
  before navigating away from the challenge page.
- **Step-change announcement (WCAG 4.1.3):** Password → 2FA transition announced
  via `wp.a11y.speak()`.
- **Settings label-input association (WCAG 1.3.1):** All `add_settings_field()`
  calls include `label_for` matching the rendered input `id`.
- **Replay status message (WCAG 4.1.3):** Visible "Replaying your action..." message
  and `wp.a11y.speak()` announcement before form submission.
- **Localized JavaScript strings (i18n):** All user-facing strings passed through
  `wp_localize_script()`.
- **Session expiry handling (WCAG 2.2.1):** "Start over" button replaces automatic
  reload.
- **Reduced motion preferences:** `@media (prefers-reduced-motion: reduce)` rules
  in both CSS files.
- **Focus-visible outlines:** `:focus-visible` outlines with proper offset.
- **Gated actions table semantics:** Native table semantics with `<caption>` element
  (replaced `role="presentation"` in v2.2.0).
- **Disabled link contrast:** Changed to `#787c82` (4.6:1 ratio, WCAG AA).
- **Admin notice ARIA roles:** `role="alert"` on blocked-action notice,
  `role="status"` on gate notice.
- **MU-plugin message area:** `role="status"` and `aria-live="polite"`.
- **Admin bar countdown cleanup:** `pagehide` listener clears interval, prevents
  bfcache issues.
- **Settings default value documentation (WCAG 3.3.5):** Inline `<p class="description">`
  text on all fields.
- **Lockout countdown SR throttling (WCAG 4.1.3):** `aria-live="off"` with
  30-second and 10-second `announce()` intervals.