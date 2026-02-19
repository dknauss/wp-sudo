# Changelog

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
