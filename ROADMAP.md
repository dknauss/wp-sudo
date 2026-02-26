# Roadmap: Past and Future Planning — Integration Tests, WP 7.0 Prep, Collaboration, TDD, and Core Design

*Updated February 20, 2026*

## Table of Contents

- **[Planned Development Timeline](#planned-development-timeline)** — Immediate, short-term, medium-term, and later work phases
- **[Context](#context)** — v2.4.1 state: 349 unit + 73 integration tests, CI matrix, WP 7.0 status
- **[1. Integration Tests](#1-integration-tests--scope-and-value)** — Complete ✓ (73 tests), coverage analysis, remaining gaps
- **[2. WordPress 7.0 Prep](#2-wordpress-70-prep-beta-1-today-ga-april-9)** — Verified changes, action plan
- **[3. Collaboration & Sudo](#3-collaboration-and-sudo--multi-user-editing-scenarios)** — Multi-user editing, conflict resolution
- **[4. Context Collapse & TDD](#4-context-collapse-and-tdd)** — LLM confabulation defense, test-driven development
- **[Recommended Next Steps](#recommended-next-steps-priority-order)** — Immediate, short-term, medium-term priorities
- **[5. Environment Diversity Testing](#5-environment-diversity-testing-future-milestone)** — Apache, PHP 8.0, MariaDB, backward compat
- **[6. Coverage Tooling](#6-coverage-tooling-deferred)** — Deferred until matrix stabilizes
- **[7. Mutation Testing](#7-mutation-testing-deferred-to-post-environment-diversity)** — Deferred until integration suite is fast enough
- **[8. Core Sudo Design](#8-core-sudo-design)** — Already achieved (15), to implement (6), to consider (4), discarded (6)
- **[9. Feature Backlog](#9-feature-backlog)** — WSAL sensor, IP+user rate limiting, dashboard widget, Gutenberg, network policy
- **[Appendix A: Accessibility](#appendix-a-accessibility-roadmap)** — 15 resolved WCAG items (v2.2.0–v2.3.1)

---

## Planned Development Timeline

### Immediate (Blocking WP 7.0 GA — April 9, 2026)
- **Update "Tested up to"** in readme files when WordPress 7.0 ships

### Short-term (v2.5.x / v2.6 — High Priority)

**Core Design Features:**
- **Login grants sudo session** — user just authenticated, no need to challenge again immediately
- **Gate `user.change_password`** — prevent session theft → password change → lockout attack
- **Grace period (two-tier expiry)** — prevent form failures when sudo expires mid-submission

**Monitoring:**
- Watch Abilities API (WP 7.0) for destructive abilities that should be gated

### Medium-term (v2.5–v2.6)

**Core Design:**
- Expire sudo session on password change
- WP-CLI `wp sudo` subcommands (status, revoke)
- Public `wp_sudo_check()` / `wp_sudo_require()` API for third-party plugins

**Feature Backlog (Open):**
- WSAL (WordPress Activity Log) sensor extension — high impact for enterprise
- Multi-dimensional rate limiting (IP + user combination)
- Session activity dashboard widget
- Gutenberg block editor integration
- Network policy hierarchy for multisite

### Later (v2.6+) — Deferred, Need Design Work

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
- Coverage tooling (after environment diversity milestone)
- Mutation testing (after environment diversity milestone)

---

## Context

This is a living document covering accumulated input and thinking about the strategic
challenges and priorities for WP Sudo. 

Current project state (as of v2.5.0):
- **366 unit tests**, 892 assertions, across 12 test files (Brain\Monkey mocks)
- **73 integration tests** across 11 test files (real WordPress + MySQL via `WP_UnitTestCase`)
- CI pipeline: PHP 8.1–8.4, WordPress latest + trunk, single-site + multisite
- WordPress 7.0 Beta 1 tested (February 19, 2026); GA is April 9, 2026

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

## 2. WordPress 7.0 Prep (Beta 1 today, GA April 9)

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

### What to do now

1. **Install WP 7.0 Beta 1** on Local or Studio dev site (available today)
2. **Run the manual testing guide** (`tests/MANUAL-TESTING.md`) against 7.0-beta
3. **Visual check:** settings page, help tabs, admin bar timer, challenge interstitial,
   admin notices — all against the refreshed admin chrome
4. **Run `composer test`** — unit tests should pass unchanged (no WP core dependency)
5. **Update version references** when 7.0 ships:
   - `docs/security-model.md` — "WordPress 6.2+" minimum
   - `readme.txt` / `readme.md` — "Tested up to" bump
   - Any docs mentioning "WordPress 6.9" as latest

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

> Steps 1–5 were completed in v2.4.0–v2.4.1. Remaining work:

1. ~~Add TDD requirement to CLAUDE.md~~ — done (v2.4.0)
2. ~~Install WP 7.0 Beta 1, run manual testing guide~~ — done (v2.4.0)
3. ~~Scaffold integration test harness~~ — done (v2.4.0, 55 tests)
4. ~~Write first integration tests~~ — done (v2.4.1, 73 tests)
5. ~~Visual review against 7.0 admin refresh~~ — done (v2.4.0)
6. **Update "Tested up to"** when 7.0 ships (April 9)
7. **Monitor Abilities API** for destructive abilities that should be gated
8. **Plan environment diversity testing** (see section 5)
9. **Core design features** — login=sudo, gate password changes, grace period (see section 8)

---

## 5. Environment Diversity Testing (Future Milestone)

The current integration test suite and manual testing guide both run against a single
environment stack: nginx + SQLite (Studio) or nginx + MySQL (Local) on macOS with a
single PHP version. This leaves significant gaps in confidence across the environments
real users run.

### Dimensions to cover

| Dimension | Current coverage | Gap |
|-----------|-----------------|-----|
| **Web server** | nginx only (Local + Studio) | Apache (mod_php, FastCGI, FPM) — the majority of WordPress hosting |
| **PHP version** | 8.1–8.4 (CI matrix), 8.2 (Local dev) | 8.0 — minimum declared but not in CI |
| **Database** | MySQL 8.0 (Local CI), SQLite (Studio) | MariaDB 10.x, MySQL 5.7 (legacy hosts) |
| **WordPress version** | latest + trunk (CI), 7.0-alpha (dev sites) | 6.2–6.9 backward compat (minimum declared but not in CI) |
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

## 6. Coverage Tooling (Deferred)

**Decision: do not add coverage measurement yet.**

Reasons:
- Xdebug/PCOV adds meaningful overhead to the integration matrix (8 jobs across
  PHP 8.1/8.3 × WP latest/trunk × single/multisite). The marginal CI cost is not
  justified until the matrix is stable.
- Coverage numbers from the unit suite would be misleading. Unit tests mock all
  WordPress functions via Brain\Monkey, so line coverage looks high while entire
  real code paths (bcrypt, transients, cookies) are untested. The integration suite
  provides better signal than a percentage badge.
- A coverage badge communicates to contributors that the suite is meaningful — that
  message is only accurate once the integration suite is comprehensive and the
  environment matrix is broad.

**When to revisit:** After the environment diversity milestone (Phase A CI matrix
expansion). At that point coverage adds signal: you can see which combinations of
PHP/WP versions hit paths the others miss.

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

## 8. Core Sudo Design

*February 20, 2026*

### Already achieved

The following areas from our initial design planning and input from others are
fully implemented in WP Sudo v2.3.x:

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
- WPGraphQL surface gating — three-tier policy for GraphQL mutations, mutation detection heuristic, `wp_sudo_wpgraphql_route` filter (v2.5.0)

### Features to implement

**High priority — target v2.5**

| Feature | Rationale | Effort |
|---------|-----------|--------|
| **Login grants sudo session** | User just authenticated; challenging again immediately is unnecessary friction. Hook `wp_login`, call `Sudo_Session::activate()`. Unix sudo, GitHub, and other sources all agree on this. | Small |
| **Gate `user.change_password`** | Session theft → silently change password → lock out user is a real attack chain. The document calls this out. `profile.php` action `update` with `pass1`/`pass2` in POST fits the existing rule pattern. | Small |
| **Grace period (two-tier expiry)** | Prevent form submissions failing when sudo expires during processing. Active = within (duration − 2 min), valid = within duration. Requires a second check in `is_active()` or a new `is_within_grace()` method. | Small |

**Medium priority — target v2.5 or v2.6**

| Feature | Rationale | Effort |
|---------|-----------|--------|
| **Expire sudo on password change** | Hook `after_password_reset` and `profile_update` (when password changes). Currently sessions expire only on timeout. | Small |
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

## 9. Feature Backlog

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