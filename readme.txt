_This exploratory plugin is not production-ready. Please test it and share your feedback on what works and what doesn't._

[Try it in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/wp-sudo/main/blueprint.json)

=== Sudo ===
Contributors:      dpknauss
Donate link:       https://dan.knauss.ca
Tags:              sudo, security, reauthentication, access control, admin protection
Requires at least: 6.2
Tested up to:      7.0-beta1
Requires PHP:      8.0
Stable tag:        2.5.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

WordPress security plugins guard the door. Sudo governs what can happen inside the house.

== Description ==

WordPress has rich access control — roles, capabilities, policies on who can do what. It has no native control over when those capabilities can be exercised within a session. Sudo fills that gap. By gating consequential actions behind re-verification, it lets site owners directly define the blast radius of any session compromise — regardless of how that compromise occurred, and regardless of the user's role. The attack surface becomes a policy decision.

This is not role-based escalation. Every logged-in user is treated the same: attempt a gated action, get challenged. Sessions are time-bounded and non-extendable, enforcing the zero-trust principle that trust must be continuously earned, never assumed. WordPress capability checks still run after the gate, so Sudo adds a security layer without changing the permission model.

= What gets gated? =

* **Plugins** — activate, deactivate, delete, install, update
* **Themes** — switch, delete, install, update
* **Users** — delete, change role, create new user, create application password
* **File editors** — plugin editor, theme editor
* **Critical options** — `siteurl`, `home`, `admin_email`, `default_role`, `users_can_register`
* **WordPress core** — update, reinstall
* **Site data export** — WXR export
* **WP Sudo settings** — settings changes are self-protected
* **Multisite** — network theme enable/disable, site delete/deactivate/archive/spam, super admin grant/revoke, network settings

Developers can add custom rules via the `wp_sudo_gated_actions` filter.

= How it works =

**Browser requests (admin UI):** The user sees an interstitial challenge page. After entering their password (and 2FA code if configured), the original request is replayed automatically. **AJAX and REST requests** receive a `sudo_required` error; an admin notice on the next page load links to the challenge page.

**Non-interactive requests (WP-CLI, Cron, XML-RPC, Application Passwords):** Configurable per-surface policies with three modes: **Disabled**, **Limited** (default), and **Unrestricted**.

= Security features =

* **Zero-trust architecture** — a valid login session is never sufficient on its own. Dangerous operations require explicit identity confirmation every time.
* **Role-agnostic** — any user attempting a gated action is challenged, including administrators.
* **Full attack surface** — admin UI, AJAX, REST API, WP-CLI, Cron, XML-RPC, Application Passwords, and WPGraphQL.
* **Session binding** — sudo sessions are cryptographically bound to the browser via a secure httponly cookie token.
* **2FA browser binding** — the two-factor challenge is bound to the originating browser with a one-time challenge cookie.
* **Rate limiting** — 5 failed password attempts trigger a 5-minute lockout.
* **Self-protection** — changes to WP Sudo settings require reauthentication.
* **Server-side enforcement** — gating decisions happen in PHP hooks before action handlers. JavaScript is for UX only.

= Recommended plugins =

* **[Two Factor](https://wordpress.org/plugins/two-factor/)** — Strongly recommended. Makes the sudo challenge a two-step process: password + verification code (TOTP, email, backup codes). Add **[WebAuthn Provider for Two Factor](https://wordpress.org/plugins/two-factor-provider-webauthn/)** for passkey and security key support.
* **[WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/)** or **[Stream](https://wordpress.org/plugins/stream/)** — Recommended for audit visibility. Sudo fires 9 action hooks covering session lifecycle, gated actions, policy decisions, and lockouts.

= User experience =

* **Admin bar countdown** — a live M:SS timer shows remaining session time. Turns red in the final 60 seconds.
* **Keyboard shortcut** — press Ctrl+Shift+S (Windows/Linux) or Cmd+Shift+S (Mac) to proactively start a sudo session.
* **Accessible** — WCAG 2.1 AA throughout (screen-reader announcements, ARIA labels, focus management, keyboard support).
* **Contextual help** — 8 help tabs on the settings page.

= MU-plugin for early loading =

An optional mu-plugin ensures gate hooks are registered before any other plugin loads. Install it with one click from the settings page.

= Multisite =

Settings and sessions are network-wide. The action registry includes 8 additional network admin rules. Settings page appears under **Network Admin → Settings → Sudo**.

== Installation ==

1. Upload the `wp-sudo` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings → Sudo** to configure session duration and entry point policies.
4. (Optional) Install the mu-plugin from the settings page for early hook registration.
5. (Recommended) Install the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin for two-step verification.

== Frequently Asked Questions ==

= How does sudo gating work? =

When a user attempts a gated action, Sudo intercepts the request at `admin_init`. The original request is stashed, the user is redirected to a challenge page, and after successful reauthentication, the original request is replayed. For AJAX and REST requests, the browser receives a `sudo_required` error and an admin notice links to the challenge page.

= Does this replace WordPress roles and capabilities? =

No. Sudo adds a reauthentication layer on top of the existing permission model. WordPress capability checks still run after the gate.

= What about REST API and Application Passwords? =

Cookie-authenticated REST requests receive a `sudo_required` error. Application Password requests are governed by a separate policy (Disabled, Limited, or Unrestricted). Individual application passwords can override the global policy from the user profile page.

= What about WP-CLI, Cron, and XML-RPC? =

Each has its own three-tier policy: Disabled, Limited (default), or Unrestricted. In Limited mode, gated actions are blocked while non-gated commands work normally.

= Does it support two-factor authentication? =

Yes. With the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin, the sudo challenge becomes a two-step process: password + verification code. Third-party 2FA plugins can integrate via filter hooks.

= Does it work on multisite? =

Yes. Settings and sessions are network-wide. The action registry includes network-specific rules. See the Multisite section above.

== For Developers ==

WP Sudo is built for correctness and contributor legibility, not just functionality.

Architecture: a single SPL autoloader maps the WP_Sudo\* namespace to includes/class-*.php. The Gate class detects the entry surface (admin UI, AJAX, REST, WP-CLI, Cron, XML-RPC, Application Passwords), matches the incoming request against a registry of 28+ rules, and challenges, soft-blocks, or hard-blocks based on surface and policy. All gating decisions happen server-side in PHP hooks — JavaScript is used only for UX.

Testing: the suite is split into two tiers. Unit tests (364 tests, 887 assertions) use Brain\Monkey to mock WordPress functions and run in ~0.4s. Integration tests (73 tests, 210 assertions) run against real WordPress + MySQL and cover full reauth flows, AJAX and REST gating, Two Factor interaction, multisite isolation, and all 9 audit hooks.

CI: GitHub Actions runs PHPStan level 6 and PHPCS on every push and PR, the full test matrix across PHP 8.1-8.4 and WordPress latest + trunk, and a nightly scheduled run against WordPress trunk.

Extensibility: the action registry is filterable via wp_sudo_gated_actions. Nine audit hooks cover session lifecycle, gated actions, policy decisions, and lockouts. See the GitHub repository for hook reference, CONTRIBUTING.md, and the full developer documentation.

== Screenshots ==

1. Challenge page — reauthentication interstitial with password field.
2. Two-factor verification — after password confirmation, users with 2FA enabled enter their authentication code.
3. Settings page — configure session duration and entry point policies.
4. Gate notice (plugins) — when no sudo session is active, a persistent notice links to the challenge page.
5. Gate notice (themes) — the same gating notice appears on the themes page.
6. Gated actions — the settings page lists all gated operations with their categories and surfaces.
7. Active sudo session — the admin bar shows a green countdown timer.

== Changelog ==

= 2.5.0 =
* **WPGraphQL surface gating** — adds WPGraphQL as a fifth non-interactive surface. Three-tier policy (Disabled / Limited / Unrestricted); default is Limited. Mutations are blocked without a sudo session; queries pass through. Fires wp_sudo_action_blocked on block.
* **wp_sudo_wpgraphql_route filter** — allows the gated endpoint to be overridden for custom WPGraphQL configurations.
* **Site Health** — WPGraphQL policy included in Entry Point Policies health check.
* **364 unit tests, 887 assertions. 73 integration tests in CI.**

= 2.4.1 =
* **AJAX gating integration tests** — 11 new tests covering the AJAX surface: rule matching for all 7 declared AJAX actions, full intercept flow, session bypass, non-gated pass-through, blocked transient lifecycle, admin notice fallback, and wp.updates slug passthrough.
* **Action registry filter integration tests** — 3 new tests verifying custom rules added via wp_sudo_gated_actions are matched by the Gate in a real WordPress environment.
* **Audit hook coverage** — wp_sudo_action_blocked now integration-tested for CLI, Cron, and XML-RPC surfaces (in addition to REST app-password).
* **CI quality gate** — new GitHub Actions job runs PHPCS and PHPStan on every push and PR; Composer dependency cache added; nightly scheduled run against WP trunk.
* **MU-plugin manual install instructions** — fallback copy instructions added to the settings page UI and help tab.
* **CONTRIBUTING.md** — new contributor guide covering local setup, test strategy, TDD workflow, and code style requirements.
* **349 unit tests, 863 assertions. 73 integration tests in CI.**

= 2.4.0 =
* **Integration test suite** — 55 tests against real WordPress + MySQL (session lifecycle, request stash/replay, full reauth flow, REST gating, upgrader migrations, Two Factor interaction, multisite isolation).
* **CI pipeline** — GitHub Actions with unit tests across PHP 8.1–8.4 and integration tests against WordPress latest + trunk.
* **Fix: multisite site-management gate gap** — Archive, Spam, Delete, Deactivate site actions now correctly trigger the sudo challenge.
* **Fix: admin bar timer width** — expiring (red) state no longer stretches wider than active (green) state.
* **Fix: WP 7.0 admin notice background** — restored white background lost in WP 7.0's admin visual refresh.
* **Fix: 2FA countdown advisory-only** — window reduced to 5 minutes; expired codes accepted if provider validates.
* **WP 7.0 Beta 1 tested** — full manual testing guide completed, all 15 sections PASS.
* **349 unit tests, 863 assertions. 55 integration tests in CI.**

= 2.3.2 =
* **Fix: admin bar sr-only text leak** — screen-reader-only milestone text no longer renders in the dashboard canvas when the admin bar node lacks a containing block.
* **Documentation overhaul** — readmes slimmed; security model, developer reference, FAQ, and full changelog extracted to `docs/`. Manual testing guide rewritten for v2.3.1+.
* **Composer lock compatibility** — `config.platform.php` set to `8.1.99` so the lock file resolves for PHP 8.1+ regardless of local version.
* **Housekeeping** — removed stale project state file and outdated manual testing guide; added `@since` tags; updated CLAUDE.md and Copilot instructions with docs/ references.
* **343 unit tests, 853 assertions.**

= 2.3.1 =
* **Fix: Unicode escape rendering** — localized JS strings now use actual UTF-8 characters, fixing visible backslash-escape text during challenge replay.
* **Fix: screen-reader-only text flash** — the sr-only span no longer flashes visible fragments during replay.
* **CycloneDX SBOM** — `bom.json` shipped for supply chain transparency.
* **Help tabs** — per-application-password policy section added. Count corrected to 8.
* **Copilot coding agent** — GitHub Copilot configuration added.
* **Accessibility roadmap complete** — all items verified resolved.
* **343 unit tests, 853 assertions.**

= 2.3.0 =
* **Per-application-password sudo policies** — individual Application Password credentials can override the global REST API policy.
* **Challenge page iframe fix** — breaks out of `wp_iframe()` context.
* **Accessibility improvements** — admin bar cleanup on page unload; lockout countdown SR throttling; settings field defaults.
* **PHPStan level 6 static analysis** — zero errors.
* **Documentation** — AI and agentic tool guidance and UI/UX testing prompts.
* **343 unit tests, 853 assertions.**

See the plugin's `CHANGELOG.md` for all versions.

== Upgrade Notice ==

= 2.4.0 =
Integration test suite, CI pipeline, multisite gate fix, admin bar CSS fix, WP 7.0 compatibility. No settings migration required.

= 2.3.2 =
Admin bar CSS fix, documentation overhaul, Composer lock compatibility. No settings changes required.

= 2.3.1 =
Bug fixes (Unicode escapes, sr-only text flash), CycloneDX SBOM, accessibility roadmap complete. No settings changes required.

= 2.3.0 =
Per-application-password sudo policies, challenge page iframe fix, accessibility improvements, PHPStan level 6 static analysis. No settings migration required.

= 2.2.0 =
Entry point policies now have three modes: Disabled, Limited, Unrestricted. Existing Block/Allow settings are migrated automatically. Review Settings > Sudo after upgrading.

= 2.0.0 =
Major rewrite. The custom Site Manager role is removed — sudo now gates dangerous actions for all users via reauthentication. Review the new settings (entry point policies) after upgrading.
