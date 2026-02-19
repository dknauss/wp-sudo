# Codebase Concerns

**Analysis Date:** 2026-02-19

## Test Coverage Gaps — Integration Testing

**Full reauthentication workflow:**
- Issue: All tests mock WordPress functions via Brain\Monkey. Real `get_user_meta()`, `wp_check_password()`, transients, and cookies are never exercised.
- Files: `tests/Unit/GateTest.php`, `tests/Unit/ChallengeTest.php`, `tests/Unit/SudoSessionTest.php`
- Impact: Request stash serialization, session token binding via actual cookies, password verification with real bcrypt (WP 6.8+), and end-to-end flow from Gate → Challenge → Session → Stash → Replay have never been tested in a real WordPress environment.
- Fix approach: Scaffold integration test suite using WordPress test scaffolding (`wp scaffold plugin-tests` or `wordpress-develop`). Write prioritized integration tests: (1) full reauth flow, (2) REST API with real auth, (3) session token binding, (4) upgrader migrations, (5) Two Factor plugin interaction, (6) multisite session isolation.
- Priority: High — critical for security-sensitive code paths.

**Transient TTL enforcement:**
- Issue: Stash transient TTL (5 minutes) and 2FA pending transient TTL are mocked in all tests. Expiry behavior never verified.
- Files: `includes/class-request-stash.php` (TTL: 300s), `includes/class-sudo-session.php` (2FA pending transient handling)
- Impact: If transient cleanup fails or TTL is misconfigured, stashed requests could remain accessible longer than intended, creating a window for replay attacks.
- Fix approach: Integration test that (1) sets stash transient, (2) advances time past TTL, (3) verifies `get()` returns null.

**REST API with real application passwords:**
- Issue: No test sends a REST request with a real application password. AppPass auth is stubbed.
- Files: `tests/Unit/GateTest.php` (REST interception), `includes/class-gate.php`
- Impact: Per-application-password policy overrides (added in v2.3.0) are not verified to work in practice.
- Fix approach: Integration test that (1) creates app password, (2) sets per-password policy override in user meta, (3) sends REST request with AppPass, (4) verifies policy is applied correctly.

## Security Boundaries — 2FA Browser Binding Cookie Scope

**Challenge cookie scope on multisite:**
- Issue: The 2FA challenge cookie (`wp_sudo_challenge`) uses WordPress's default cookie settings. On multisite with multiple subdomains, cookie scope may be ambiguous.
- Files: `includes/class-sudo-session.php` (lines 60, 343, 405, 446, 464 set/read/delete the challenge cookie)
- Impact: If the cookie is set with too broad a domain (e.g., `.example.com`), a 2FA challenge started on `site-a.example.com` could be replayed on `site-b.example.com` if both share the same WordPress instance. Conversely, if too narrow, legitimate cross-subdomain operations fail.
- Current mitigation: The 2FA pending state is keyed by `hash( $_COOKIE['wp_sudo_challenge'] )`. Even if the cookie leaks across subdomains, the attacker must know the hash. Still, verification on multisite is needed.
- Fix approach: Integration test on multisite: (1) activate session on site A, (2) verify challenge cookie is readable on site B, (3) verify the hash lookup does not leak state across sites. Document cookie scope behavior in `docs/security-model.md`.

## Unfinished Integration Tests

**Two Factor plugin integration:**
- Issue: `Two_Factor_Core` is stubbed in `tests/bootstrap.php`. Real Two Factor plugin hooks (`two_factor_validate_two_factor()`, `two_factor_in_progress()`) are never called.
- Files: `tests/TestCase.php`, `tests/bootstrap.php`, `includes/class-challenge.php` (handles 2FA)
- Impact: `needs_two_factor()` detection and the 2FA pending state machine are not verified to work with the actual Two Factor plugin.
- Fix approach: Scaffold integration test with real Two Factor plugin installed. Test password step → 2FA required detection → TOTP code validation → session activation flow.

**Hook timing and priority ordering:**
- Issue: Gate registers at `admin_init` priority 1. Other plugins register at priority 10. No test verifies this ordering holds in practice with real hooks.
- Files: `includes/class-gate.php` (line 130: `add_action( 'admin_init', ..., 1 )`)
- Impact: If a higher-priority hook modifies request globals before Gate runs, or a lower-priority hook interferes with Gate's stash logic, gating could fail silently.
- Fix approach: Integration test loading a dummy plugin at priority 5 that modifies `$_GET` / `$_POST`. Verify Gate correctly detects and gates actions despite the interference.

## Database Cleanup — Stale Session Data

**Expired session cleanup:**
- Issue: Sudo sessions store expiry timestamps in user meta (`_wp_sudo_expires`). When sessions expire, the meta key is not automatically deleted — only checked via `time() > expiry`.
- Files: `includes/class-sudo-session.php` (lines 35, 148–151, 192–199)
- Impact: After millions of session activations/expirations, accumulated expired user meta entries could degrade performance. WordPress garbage collection does not clean up expired user meta.
- Current approach: Sessions are checked for expiry on every `is_active()` call, so expired sessions are detected correctly. But the meta key persists.
- Fix approach: Add a cleanup routine in the Upgrader or as a scheduled action that periodically purges `_wp_sudo_expires` meta older than current time. Or update docs to recommend a `wp_loaded` hook that cleans up old user meta for power users who perform thousands of sudo activations.
- Priority: Low — expiration check is fast and sessions have short TTL (default 5 min).

**2FA pending transient cleanup:**
- Issue: 2FA pending state is stored in transients keyed by challenge cookie hash (`wp_sudo_2fa_pending_` . hash). Transients auto-expire, but the key naming is ephemeral per challenge.
- Files: `includes/class-sudo-session.php` (lines 315–325 set, 405–413 read, 432–446 clear)
- Impact: If transient cleanup is delayed, multiple 2FA pending transients could accumulate. Low risk due to auto-expiry, but unusual transient naming convention (`wp_sudo_2fa_pending_*` with dynamic hashes) could complicate monitoring.
- Fix approach: Document the transient key pattern in code comments for site operators using transient monitoring tools.
- Priority: Low.

## Request Stash — Sensitive Data in Transients

**POST parameters preserved verbatim:**
- Issue: Request stash stores all `$_POST` parameters verbatim in a transient, including passwords, nonces, and form fields.
- Files: `includes/class-request-stash.php` (lines 66, 204–211 sanitize_params comment)
- Impact: If an attacker gains database or object-cache access during the 5-minute stash TTL window, they can read form data. Passwords in `$_POST` are in plaintext in the database. However, this is justified: stashed requests must be replayed exactly as submitted, and the transient is short-lived and server-side only.
- Current mitigation: Stash TTL is 5 minutes. Stashes are retrieved only when the request matches the stashed user ID. No transient contents are sent to the client.
- Fix approach: No change needed. Document in `docs/security-model.md` under "Environmental Considerations" that site operators should ensure database and cache backends are access-controlled and that transients are stored on trusted infrastructure.
- Priority: Informational only.

## Multisite Considerations — Cross-Site Session Isolation

**Sudo session binding on multisite:**
- Issue: Sudo sessions store user meta `_wp_sudo_expires` and `_wp_sudo_token` globally on multisite (user meta is network-wide). The session token cookie is set with WordPress's default cookie settings.
- Files: `includes/class-sudo-session.php` (token cookie handling), `includes/class-plugin.php` (multisite activation)
- Impact: A user with a sudo session on site A could theoretically use the same token on site B if both sites share the same WordPress instance and user. However, the `is_active()` check compares the token hash, and the cookie is per-domain, so cross-site session leakage is prevented in practice.
- Current mitigation: Testing on multisite shows session isolation works correctly, but integration tests document this explicitly.
- Fix approach: Add integration test: "Multisite session isolation" — user activates session on site A, verify `is_active()` returns true on A and false on B, verify the token cookie doesn't leak.
- Priority: Medium — should be verified before major releases.

## Fragmentary Testing for Complex Scenarios

**Application password policies with overrides:**
- Issue: Per-application-password policy overrides were added in v2.3.0. Only 3 unit test methods cover this (`AdminTest` lines testing user profile page).
- Files: `includes/class-admin.php` (app password UI), `tests/Unit/AdminTest.php`
- Impact: Per-password policies could silently fail to apply if the override meta key (`_wp_sudo_rest_app_password_policy_*`) is not set or read correctly.
- Fix approach: Integration test sending REST requests with different app passwords, each with different policy overrides, and verifying the correct policy is enforced.
- Priority: High for REST users.

**Upgrader migration chain:**
- Issue: Upgrader runs sequential migrations from v2.0.0 → v2.2.0 → v2.3.0. Migrations are mocked in tests.
- Files: `includes/class-upgrader.php`, `tests/Unit/UpgraderTest.php`
- Impact: If a migration step is broken or database state changes unexpectedly, users upgrading across multiple versions could end up with corrupted settings or options.
- Fix approach: Integration test that runs full migration chain against a real database, verifies each step transforms data correctly.
- Priority: Medium — upgrades are infrequent but security-critical.

## Performance Bottlenecks — Per-Request Caching

**Is_active() caching:**
- Issue: `Sudo_Session::is_active()` is called 3–5 times per page load (from Gate, Admin_Bar). Results are cached per request in a static array.
- Files: `includes/class-sudo-session.php` (lines 104–112, 127–151)
- Impact: Without per-request caching, each call would execute `get_user_meta()` and `hash_equals()`. With caching, only the first call pays the cost. Cache is invalidated on activate/deactivate.
- Current approach: Cache is correctly invalidated via `reset_cache()` in tests and on activate/deactivate.
- Risk: None identified. Cache is scoped per-request and invalidated correctly.

**Settings caching:**
- Issue: `Admin::get()` is called multiple times per request (for session duration, policy lookups). Results are cached in a static array.
- Files: `includes/class-admin.php` (lines 59–67)
- Impact: Each uncached call fetches `get_option()` or `get_site_option()`. With caching, only the first call executes. Cache is invalidated when settings change.
- Risk: If settings are updated outside WordPress (direct database modification), the in-memory cache could return stale values. Mitigation: cache is per-request, so only impacts one page load.

## Fragile Areas — Complex Admin UI Logic

**Action link filtering on plugins/themes pages:**
- Issue: Plugin and theme action links (Activate, Deactivate, Delete) are filtered via `plugin_action_links` and `theme_action_links` hooks to disable buttons when no sudo session.
- Files: `includes/class-gate.php` (lines 365–412 for plugins/themes filtering)
- Impact: If WordPress changes the HTML structure of action links or adds new link types, the button-disabling logic could break. The current implementation uses regex to match link patterns.
- Current approach: Links are re-enabled via JavaScript on the front-end if no session is detected, so UX degrades gracefully.
- Fix approach: Monitor WordPress plugin/theme list page structure across major releases. Add comment documenting the expected link HTML structure.
- Priority: Low — button disabling is UX enhancement, not security-critical.

**Gated actions reference table on settings page:**
- Issue: Settings page renders a read-only table of all 28+ gated rules with categories and surfaces. Table structure is generated via `Action_Registry::rules()`.
- Files: `includes/class-admin.php` (render_gated_actions_table method), `includes/class-action-registry.php`
- Impact: If a rule is added to the registry but the table rendering logic is not updated, the table could become misaligned or miss columns.
- Current approach: Table rendering is generic over the rules array, so new rules appear automatically.
- Fix approach: None needed — table is well-designed.

## Potential Behavioral Gaps — Nonce Handling

**Nonce replay in stash-replay flow:**
- Issue: When a POST request is stashed and replayed, the original nonce is included verbatim. The replay happens on the same site with the same nonce, so it should succeed. However, nonces have a 12-hour lifetime by default. If the stash TTL (5 min) is exceeded before replay, or if the nonce is consumed before being stashed, replay could fail.
- Files: `includes/class-request-stash.php` (stores `$_POST` with nonce), `includes/class-challenge.php` (replays stash)
- Impact: If a user takes longer than 5 minutes to authenticate, the stashed POST nonce expires and replay fails. UX is poor but not a security issue.
- Current mitigation: TTL is 5 minutes, much shorter than typical nonce lifetime (12 hours).
- Fix approach: Document in user guide that sudo challenge must be completed within 5 minutes.
- Priority: Low.

## Known Limitations — Architecture Constraints

**No session extension:**
- Issue: Sudo sessions are non-extendable by design. A session expires after its configured duration (1–15 minutes) and cannot be extended without reauthentication.
- Files: No code — it's a design decision. Documented in `ROADMAP.md` under "Declined".
- Impact: Users must periodically reauthenticate. Keyboard shortcut (`Ctrl+Shift+S`) mitigates by making re-authentication fast.
- Rationale: Extending sessions would violate zero-trust principles. Sessions are time-bounded; trust must be continuously earned.
- Status: Intentional. No change needed.

**No Gutenberg block editor integration:**
- Issue: WP Sudo gates admin operations but does not integrate with the Gutenberg block editor. Editor saves are not gated.
- Files: Not implemented. Listed in `ROADMAP.md` as "Open — High Effort" item 8.
- Impact: Users can save block content without sudo session. This is intentional — gating content saves would degrade UX. Gating destructive content actions (publish, delete, change capabilities) is a future consideration.
- Status: Deferred. Not a bug, a design choice.

## Documentation Gaps

**Transient backend requirements:**
- Issue: Docs don't specify whether custom transient backends (e.g., Redis with non-persistent storage) are compatible.
- Files: `docs/security-model.md`
- Impact: If transient backend is misconfigured, stash and 2FA pending transients could disappear, breaking reauth flow.
- Fix approach: Add note to "Environmental Considerations" section: "Transient backends must persist data for at least the configured TTL (5 minutes for stash). Volatile in-memory backends are not recommended."
- Priority: Low.

**WP 7.0 compatibility status:**
- Issue: WordPress 7.0 Beta 1 is available (as of Feb 19, 2026). Visual refresh and Abilities API are new. Compatibility is not yet verified.
- Files: `ROADMAP.md` item 2, `docs/roadmap-2026-02.md` item 2
- Impact: Settings page CSS may not render correctly under WP 7.0 admin refresh. Abilities API (new REST surface) is not gated.
- Fix approach: Install WP 7.0 Beta, run manual testing guide, verify settings page and challenge page styling. Monitor Abilities API for destructive abilities.
- Priority: Medium — WP 7.0 GA is April 9, 2026.

## LLM Context Risks — Confabulation Potential

**Fabrication history:**
- Issue: `llm_lies_log.txt` documents 5 instances of LLM-generated fabrications in the 2FA ecosystem project (external classes, meta keys, counts).
- Files: `llm_lies_log.txt`
- Impact: This codebase is less affected (no external third-party integrations), but the risk is present if future integrations are added.
- Mitigations in place: TDD requirement (CLAUDE.md), PHPStan level 6, pre-commit tests.
- Recommendation: Enforce CLAUDE.md verification requirements strictly for any new external integrations. Query authoritative APIs (WordPress.org, GitHub) instead of training data.

## Scaling Limits — Transient Storage

**Transient accumulation on high-volume sites:**
- Issue: Request stashes and 2FA pending transients accumulate at rate of 1 per user action. On a site with 100 users performing 10 gated actions/hour, 1,000 transients are created per hour. With 5-minute TTL, ~80 stale transients exist at any time.
- Files: `includes/class-request-stash.php`, `includes/class-sudo-session.php`
- Impact: If transient backend is a database table, row accumulation could impact query performance. Most managed WordPress hosts handle this transparently.
- Current approach: Transient TTL handles cleanup. No explicit cleanup routine needed on standard WordPress.
- Risk: High-volume sites with custom transient backends (e.g., non-persistent Redis) could see transient churn.
- Recommendation: For high-volume sites, verify transient backend can handle sustained 1k+ transients/hour.
- Priority: Low — affects high-volume sites only.

---

*Concerns audit: 2026-02-19*
