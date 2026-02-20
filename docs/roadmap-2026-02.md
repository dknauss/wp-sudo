# Roadmap Assessment — Integration Tests, WP 7.0 Prep, Collaboration, and Context Collapse

*February 19, 2026*

> **Note:** This document reflects the project state at v2.3.2 (February 19, 2026).
> Current test counts and implemented work are tracked in [CHANGELOG.md](CHANGELOG.md).
> The integration test suite and CI pipeline described as "planned" here have since
> been completed and are shipping in v2.4.x.

## Context

Three strategic questions were assessed, plus a follow-up about mitigating LLM context
collapse and adopting TDD. This is a research synthesis and recommendation document
identifying what work lies ahead and in what order.

Current project state:
- **13,495 lines of PHP** (6,220 production, 7,275 tests — 54% test code)
- **~220 unit test methods** across 10 test files, all using Brain\Monkey mocks
- **Zero integration tests** — every WordPress function is stubbed
- **v2.3.2 tagged**, 3 doc commits past the tag
- WordPress 7.0 Beta 1 is **today** (Feb 19, 2026); GA is April 9, 2026

---

## 1. Integration Tests — Scope and Value

### What unit tests cover well (no integration gap)
- Request matching across all 6 surfaces (98 GateTest methods)
- Session state machine, token crypto, rate limiting
- Hook registration and filter application
- Policy enforcement (DISABLED/LIMITED/UNRESTRICTED)
- Upgrader migration logic
- Settings sanitization and defaults

### What unit tests cannot cover (real integration gaps)

| Gap | Why it matters |
|-----|---------------|
| **Cross-class workflows** (Gate → Challenge → Session → Stash) | The full reauth flow is never tested end-to-end. Mockery stubs hide interface mismatches. |
| **Request stash replay** | POST replay with nonces is security-critical but never tested with real data. |
| **Hook timing and priority** | Gate registers at `admin_init` priority 1. Other plugins at priority 10. No test verifies this ordering holds. |
| **Real `wp_check_password()`** | Mocked in every test. bcrypt (WP 6.8+) behavior never exercised. |
| **Cookie/header behavior** | `setcookie()` is patched via Patchwork. Actual httponly/samesite attributes never verified. |
| **Transient TTL enforcement** | Stash and 2FA pending use transients with TTL. Mocked — expiry never tested. |
| **Two Factor plugin interaction** | `Two_Factor_Core` is a custom stub. Real provider class methods never called. |
| **Database state after migrations** | Upgrader logic is tested but actual option/meta mutations are mocked. |
| **REST API with real auth** | No test sends a REST request with a real app password or cookie. |

### Recommendation: WordPress integration test suite

**Framework:** Use the official `wordpress-develop` test scaffolding (`WP_UnitTestCase`)
via `wp scaffold plugin-tests`. This gives a real WordPress + database environment
where `get_user_meta()`, `wp_check_password()`, and transients work for real.

**What to test (priority order):**

1. **Full reauth flow** — admin request → Gate intercept → stash → Challenge page →
   password verify → session activate → stash replay. This is the core user journey.

2. **REST API gating with real auth** — cookie auth request blocked, app password
   with per-password policy override allowed/blocked.

3. **Session token binding** — activate session, verify cookie matches meta hash,
   expire session, verify is_active returns false. Real `setcookie` + real meta.

4. **Upgrader migrations** — run the v2.0.0 → v2.2.0 migration chain against a real
   database, verify options and meta are transformed correctly.

5. **Two Factor plugin** — install the real Two Factor plugin in the test harness,
   verify `needs_two_factor()` detection and 2FA pending state.

6. **Multisite session isolation** — user has session on site A, verify it doesn't
   leak to site B.

**What NOT to integration-test:**
- Admin UI rendering (test manually or with Playwright/Cypress later)
- JavaScript countdown timer (unit test the PHP; E2E test the JS separately)
- CSS/asset loading (low risk, visual regression testing is a different discipline)

**Estimated effort:** 2–3 sessions to scaffold and write the first 3 priorities.
The test harness setup is the biggest upfront cost; individual tests are fast after that.

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

1. **Add TDD requirement to CLAUDE.md** — codify the "tests first" rule
2. **Install WP 7.0 Beta 1** on dev site, run manual testing guide
3. **Scaffold integration test harness** (`wp scaffold plugin-tests` or equivalent)
4. **Write first integration tests** (full reauth flow, REST gating, session binding)
5. **Visual review** of settings page / challenge page against 7.0 admin refresh
6. **Update "Tested up to"** when 7.0 ships (April 9)
7. **Monitor Abilities API** for destructive abilities that should be gated
8. **Plan environment diversity testing** (see section 5 below)

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
| **PHP version** | 8.2 only (Local's bundled PHP) | 8.0, 8.1, 8.3, 8.4 — the full supported range |
| **Database** | MySQL 8.0 (Local CI), SQLite (Studio) | MariaDB 10.x, MySQL 5.7 (legacy hosts) |
| **WordPress version** | 7.0-alpha (dev sites), latest + trunk (CI) | 6.2–6.9 backward compat (minimum supported) |
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
