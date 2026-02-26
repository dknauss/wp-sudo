# Changelog

## 2.5.0

- **WPGraphQL surface gating** — adds WPGraphQL as a fifth non-interactive surface alongside WP-CLI, Cron, XML-RPC, and Application Passwords. Three-tier policy (Disabled / Limited / Unrestricted); default is Limited. In Limited mode, GraphQL mutations are blocked without an active sudo session while read-only queries pass through. Fires the `wp_sudo_action_blocked` audit hook on block. The policy setting (`wpgraphql_policy`) is stored regardless of whether WPGraphQL is installed; the settings field is only shown when WPGraphQL is active.
- **Mutation detection heuristic** — Limited mode checks whether the POST body contains the word `mutation`. Intentionally blunt: cannot false-negative on actual mutations, may false-positive on queries mentioning "mutation" in a string argument. Documented in `docs/security-model.md`.
- **`wp_sudo_wpgraphql_route` filter** — allows the gated route to be overridden to match custom WPGraphQL endpoint configurations.
- **Site Health integration** — WPGraphQL policy included in the Entry Point Policies health check (flagged if set to Unrestricted).
- **364 unit tests, 887 assertions. 73 integration tests in CI.**

## 2.4.2

- **Documentation: roadmap consolidation** — Merged three separate roadmaps (`ROADMAP.md`, `ACCESSIBILITY-ROADMAP.md`, `docs/roadmap-2026-02.md`) into one unified `ROADMAP.md` at project root. Moved `CHANGELOG.md` and `FAQ.md` to root for prominence.
- **Planned Development Timeline** — Added comprehensive timeline at the top of ROADMAP.md showing immediate, short-term, medium-term, and deferred work phases. Provides quick reference for what will actually be implemented.
- **Table of Contents** — Added scannable TOC to ROADMAP.md linking to all 10 sections plus appendix.

## 2.4.1

- **AJAX gating integration tests** — 11 new tests covering the AJAX surface: rule matching for all 7 declared AJAX actions, full intercept flow via `wp_doing_ajax` filter, session bypass, non-gated pass-through, blocked transient lifecycle, admin notice fallback (`render_blocked_notice`), and `wp.updates` slug passthrough.
- **Action registry filter integration tests** — 3 new tests verifying custom rules added via `wp_sudo_gated_actions` are matched by the Gate in a real WordPress environment; including custom admin rules, custom AJAX rules, and filter-based removal of built-in rules.
- **Audit hook coverage** — `wp_sudo_action_blocked` now integration-tested for CLI, Cron, and XML-RPC surfaces (in addition to REST app-password). Documents that `wp_sudo_action_allowed` is intentionally absent from the production code path.
- **CI quality gate** — new GitHub Actions job runs PHPCS and PHPStan on every push and PR; Composer dependency cache added to unit and integration jobs; nightly scheduled run at 3 AM UTC catches WordPress trunk regressions.
- **MU-plugin manual install instructions** — fallback copy instructions added to the settings page UI (`<details>` disclosure) and help tab for environments where the one-click installer fails due to file permissions.
- **CONTRIBUTING.md** — new contributor guide covering prerequisites, local setup, unit vs integration test distinction, TDD workflow, and lint/analyse requirements.
- **349 unit tests, 863 assertions. 73 integration tests in CI.**

## 2.4.0

- **Integration test suite** — 55 integration tests running against a real WordPress + MySQL environment via `WP_UnitTestCase`. Covers sudo session lifecycle (bcrypt verification, token binding, rate limiting, expiry), request stash/replay with transient TTL, full reauth flow (5-class end-to-end), REST API gating with cookie auth and application passwords, upgrader migration chain, audit hook arguments, Two Factor plugin interaction, and multisite session isolation.
- **CI pipeline** — GitHub Actions workflow with unit tests across PHP 8.1–8.4 and integration tests against WordPress `latest` and `trunk` (including multisite variant). MySQL 8.0 service container with health checks.
- **Fix: multisite site-management gate gap** — Archive, Spam, Delete, and Deactivate site actions on Network Admin → Sites now correctly trigger the sudo challenge. WordPress core's `sites.php` sends `action=confirm` with the real action in `action2`; the Gate now checks both parameters.
- **Fix: admin bar timer width** — the countdown timer's red (expiring) state no longer stretches wider than the green (active) state. Defensive CSS constrains the background to content width regardless of WP core layout context.
- **Fix: WP 7.0 admin notice background** — restored white background on WP Sudo admin notices, which lost their background color in WP 7.0's admin visual refresh.
- **Fix: 2FA countdown advisory-only** — the two-factor verification window is now advisory (5 minutes, reduced from 10). Expired 2FA codes are still accepted if the underlying provider validates them, preventing false rejections for slow email delivery.
- **Fix: `setcookie()` headers-already-sent guard** — `Sudo_Session::activate()` now checks `headers_sent()` before calling `setcookie()`, preventing warnings in CLI and integration test contexts.
- **Verification requirements** — CLAUDE.md now mandates live source verification for all external code references, with documented verification commands. LLM lies log tracks 5 prior fabrications that were corrected.
- **WP 7.0 Beta 1 tested** — manual testing guide completed against WP 7.0 Beta 1 (15 sections, all PASS). Visual compatibility, help tabs, challenge page, and admin bar verified against the refreshed admin chrome.
- **349 unit tests, 863 assertions. 55 integration tests in CI.**

## 2.3.2

- **Fix: admin bar sr-only text leak** — screen-reader-only milestone text no longer renders in the dashboard canvas. The admin bar `<li>` node now establishes a containing block (`position: relative`) and sr-only elements use `clip-path: inset(50%)` alongside the legacy `clip` property.
- **Documentation overhaul** — readmes slimmed to storefront length. Full content extracted to `docs/`: [security model](security-model.md), [developer reference](developer-reference.md), [FAQ](FAQ.md), and this changelog. [Manual testing guide](../tests/MANUAL-TESTING.md) rewritten for v2.3.1+ with per-app-password testing, MU-plugin toggle, and iframe edge case coverage.
- **Composer lock compatibility** — `config.platform.php` set to `8.1.99` so the lock file resolves packages compatible with PHP 8.1+ regardless of the local PHP version. Fixes Copilot coding agent CI failure (`doctrine/instantiator` 2.1.0 requiring PHP 8.4+).
- **Housekeeping** — removed stale `WP-SUDO-PROJECT-STATE.md`; added `@since 2.0.0` to Upgrader class; updated CLAUDE.md and `.github/copilot-instructions.md` with docs/ file listings.
- **343 unit tests, 853 assertions.**

## 2.3.1

- **Fix: Unicode escape rendering** — localized JS strings using bare `\uXXXX` escapes (not valid PHP Unicode syntax) now use actual UTF-8 characters, fixing visible backslash-escape text during challenge replay.
- **Fix: screen-reader-only text flash** — the sr-only "Verifying..." span no longer flashes visible fragments inside the flex container during challenge replay.
- **CycloneDX SBOM** — `bom.json` shipped in the repo for supply chain transparency. Regenerate with `composer sbom`.
- **Help tabs** — per-application-password policy section added to the Settings help tab. Help tab count corrected from 4 to 8 across readmes.
- **Copilot coding agent** — `.github/copilot-instructions.md` and `copilot-setup-steps.yml` added for GitHub Copilot integration.
- **Accessibility roadmap complete** — all items (critical through low priority) verified resolved and documented.
- **343 unit tests, 853 assertions.**

## 2.3.0

- **Per-application-password sudo policies** — individual Application Password credentials can now have their own Disabled, Limited, or Unrestricted policy override, independent of the global REST API (App Passwords) policy. Configure per-password policies from the Application Passwords section on the user profile page.
- **Challenge page iframe fix** — the reauthentication challenge page now breaks out of WordPress's `wp_iframe()` context, fixing a nested-frame display issue during plugin and theme updates.
- **Accessibility improvements** — admin bar countdown timer cleans up on page unload; lockout countdown screen reader announcements throttled to 30-second intervals; settings fields display default values.
- **PHPStan level 6 static analysis** — full codebase passes PHPStan level 6 with zero errors.
- **Documentation** — new [AI and agentic tool guidance](ai-agentic-guidance.md) and [UI/UX testing prompts](ui-ux-testing-prompts.md).
- **343 unit tests, 853 assertions.**

## 2.2.1

- **Security hardening** — stashed redirect URLs are now validated with `wp_validate_redirect()` before replay.
- **Accessibility** — ARIA `role="alert"` and `role="status"` added to gate notices; disabled-action text color improved to 4.6:1 contrast ratio (WCAG AA).
- **2FA ecosystem documentation** — new [integration guide](two-factor-integration.md) and [ecosystem survey](two-factor-ecosystem.md) covering 7 major 2FA plugins with bridge patterns.
- **WP 2FA bridge** — drop-in bridge for WP 2FA by Melapress supporting TOTP, email OTP, and backup codes ([`bridges/wp-sudo-wp2fa-bridge.php`](../bridges/wp-sudo-wp2fa-bridge.php)).
- **Help tabs** — Settings tab moved to 2nd position; all four 2FA hooks documented; Security Model heading added.
- **334 unit tests, 792 assertions.**

## 2.2.0

- **Three-tier entry point policies** — replaces the binary Block/Allow toggle with three modes per surface: Disabled (shuts off the protocol entirely), Limited (default — gated actions blocked, non-gated work proceeds normally), and Unrestricted (everything passes through).
- **Function-level gating for non-interactive surfaces** — WP-CLI, Cron, and XML-RPC now hook into WordPress function-level actions (`activate_plugin`, `delete_plugin`, `set_user_role`, etc.) instead of matching request parameters. This makes gating reliable regardless of how the operation is triggered.
- **CLI enforces Cron policy** — `wp cron` subcommands respect the Cron policy even when CLI is Limited or Unrestricted. If Cron is Disabled, `wp cron event run` is blocked.
- **REST API policy split** — Disabled returns `sudo_disabled` (surface is off), Limited returns `sudo_blocked` (gated action denied), clearly distinguishing the two rejection reasons.
- **Automatic upgrade migration** — existing `block` settings migrate to `limited`, `allow` to `unrestricted`. Multisite-aware.
- **Site Health updated** — Disabled is treated as valid hardening (Good status). Unrestricted triggers a Recommended notice.
- **Manual testing guide** — comprehensive step-by-step verification procedures in `tests/MANUAL-TESTING.md`.
- **327 unit tests, 752 assertions.**

## 2.1.0

- Removes the `unfiltered_html` capability from the Editor role. Editors can no longer embed scripts, iframes, or other non-whitelisted HTML — KSES sanitization is always active for editors. Administrators retain `unfiltered_html`. The capability is restored if the plugin is deactivated or uninstalled.
- Adds tamper detection: if `unfiltered_html` reappears on the Editor role (e.g. via database modification), it is stripped automatically and the `wp_sudo_capability_tampered` action fires for audit logging.
- Fixes admin bar deactivation redirect: clicking the countdown timer to end a session now keeps you on the current page instead of redirecting to the dashboard.
- Replaces WordPress core's confusing "user editing capabilities" error with a clearer message when a bulk role change skips the current user.

## 2.0.0

Complete rewrite. Action-gated reauthentication replaces role-based privilege escalation.

- **New model** — gates dangerous operations behind reauthentication for any user, regardless of role. No custom role, no capability escalation.
- **Full attack surface coverage** — admin UI (stash-challenge-replay), AJAX (error + admin notice + session activation), REST API (cookie-auth challenge, app-password policy), WP-CLI, Cron, XML-RPC.
- **Action Registry** — 20 gated rules across 7 categories (plugins, themes, users, editors, options, updates, tools), plus 8 multisite-specific rules. Extensible via `wp_sudo_gated_actions` filter.
- **Entry point policies** — three-tier Disabled/Limited/Unrestricted policies for REST Application Passwords, WP-CLI, Cron, and XML-RPC.
- **2FA browser binding** — challenge cookie prevents cross-browser 2FA replay.
- **2FA countdown timer** — visible countdown during the verification step; configurable window via `wp_sudo_two_factor_window` filter.
- **Self-protection** — WP Sudo settings changes are gated.
- **MU-plugin toggle** — one-click install/uninstall from the settings page. Stable shim + loader pattern keeps the mu-plugin up to date with regular plugin updates.
- **Multisite** — network-wide settings, network-wide sessions, 8 network admin rules, `get_site_option`/`set_site_transient` storage.
- **8 audit hooks** — full lifecycle and policy logging for integration with WP Activity Log, Stream, and similar plugins.
- **Contextual help** — 8 help tabs on the settings page.
- **Accessibility** — WCAG 2.1 AA throughout (ARIA labels, focus management, status announcements, keyboard support).
- **281 unit tests, 686 assertions.**

## 1.2.1

- In-place modal reauthentication; no full-page redirect.
- AJAX activation, accessibility improvements, expanded test suite.

## 1.2.0

- M:SS countdown timer, red bar at 60 seconds, accessibility improvements.
- Multisite-safe uninstall, contextual Help tab.

## 1.1.0

- 15-minute session cap, two-factor authentication support, `unfiltered_html` restriction.

## 1.0.0

- Initial release.
