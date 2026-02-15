_This exploratory plugin is not production-ready. Please test it and share your feedback on what works and what doesn't._

=== Sudo ===
Contributors:      dpknauss
Donate link:       https://dan.knauss.ca
Tags:              sudo, security, reauthentication, access control, admin protection
Requires at least: 6.2
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        2.2.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Action-gated reauthentication for WordPress. Potentially dangerous operations require password confirmation before they proceed — regardless of user role.

== Description ==

**Sudo** brings zero-trust reauthentication to WordPress. Potentially destructive operations are gated behind an identity challenge — no user is trusted by default, regardless of role. When any user — administrators, editors, or custom roles — attempts a dangerous action, they must confirm their identity before proceeding.

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

**Browser requests (admin UI):** The user sees an interstitial challenge page. After entering their password (and 2FA code if configured), the original request is replayed automatically. **AJAX and REST requests** receive a `sudo_required` error; an admin notice on the next page load links to the challenge page. The user authenticates, activates a sudo session, and retries the action.

**Non-interactive requests (WP-CLI, Cron, XML-RPC, Application Passwords):** Configurable per-surface policies with three modes: **Disabled** (shuts off the surface entirely), **Limited** (default — gated actions are blocked, non-gated work proceeds normally), and **Unrestricted** (everything passes through as if WP Sudo isn't installed).

= Security features =

* **Zero-trust architecture** — a valid login session is never sufficient on its own. Dangerous operations require explicit identity confirmation every time, with time-bounded sessions that cannot be extended.
* **Role-agnostic** — any user attempting a gated action is challenged, including administrators and compromised accounts. No role is implicitly trusted.
* **Full attack surface** — admin UI, AJAX, REST API, WP-CLI, Cron, XML-RPC, and Application Passwords are all covered.
* **Session binding** — sudo sessions are cryptographically bound to the browser via a secure httponly cookie token.
* **2FA browser binding** — the two-factor challenge is bound to the originating browser with a one-time challenge cookie, preventing cross-browser replay.
* **Rate limiting** — 5 failed password attempts trigger a 5-minute lockout.
* **Self-protection** — changes to WP Sudo settings require reauthentication.
* **Server-side enforcement** — gating decisions happen in PHP hooks before action handlers. JavaScript is for UX only.

= Recommended plugins =

Sudo works on its own, but these plugins add significant value:

* **[Two Factor](https://wordpress.org/plugins/two-factor/)** — Strongly recommended. When installed, the sudo challenge becomes a two-step process: password + verification code (TOTP, email, backup codes). This is the difference between "confirm you know the password" and "confirm you possess the device." Third-party 2FA plugins can integrate via the `wp_sudo_requires_two_factor`, `wp_sudo_validate_two_factor`, and `wp_sudo_render_two_factor_fields` hooks.

* **[WebAuthn Provider for Two Factor](https://wordpress.org/plugins/two-factor-provider-webauthn/)** — Recommended alongside Two Factor. Adds passkey and security key (FIDO2/WebAuthn) support. Administrators can reauthenticate with a hardware key or platform passkey instead of a TOTP code.

* **[WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/)** or **[Stream](https://wordpress.org/plugins/stream/)** — Recommended for audit visibility. Sudo fires 8 action hooks covering session lifecycle, gated actions, policy decisions, and lockouts. A logging plugin turns these hooks into a searchable audit trail so you can answer "who did what, and did they reauthenticate?"

= User experience =

* **Admin bar countdown** — a live M:SS timer shows remaining session time when active. Turns red in the final 60 seconds.
* **Countdown on 2FA step** — a visible timer shows how long the user has to enter their verification code.
* **Keyboard shortcut** — press Ctrl+Shift+S (Windows/Linux) or Cmd+Shift+S (Mac) to proactively start a sudo session without triggering a gated action first. When a session is already active, the shortcut flashes the admin bar timer.
* **Accessible** — screen-reader announcements, ARIA labels, focus management, and keyboard support throughout.
* **Contextual help** — 4 help tabs on the settings page cover how sudo works, settings, extending via filters, and audit hooks.

= MU-plugin for early loading =

An optional mu-plugin ensures gate hooks are registered before any other plugin loads. Install it with one click from the settings page, or manually copy the shim to `wp-content/mu-plugins/`. The mu-plugin is a thin stable shim that loads the current gate code from the main plugin directory, so it stays up to date automatically with regular plugin updates.

= Multisite =

Settings are network-wide — one configuration for the entire network, managed by super admins under **Network Admin → Settings → Sudo**. Sudo sessions are network-wide — authenticate once, valid across all sites. The action registry includes 8 additional network admin rules (theme enable/disable, site management, super admin grant/revoke, network settings). On uninstall, per-site data is cleaned per-site, and shared user meta is only removed when no remaining site has the plugin active.

== Installation ==

1. Upload the `wp-sudo` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings → Sudo** to configure session duration and entry point policies.
4. (Optional) Install the mu-plugin from the settings page for early hook registration.
5. (Recommended) Install the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin for two-step verification.

== Frequently Asked Questions ==

= How does sudo gating work? =

When a user attempts a gated action — for example, activating a plugin — Sudo intercepts the request at `admin_init` (before WordPress processes it). The original request is stashed in a transient, the user is redirected to a challenge page, and after successful reauthentication, the original request is replayed. For AJAX and REST requests, the browser receives a `sudo_required` error and an admin notice appears on the next page load linking to the challenge page. The user authenticates, activates a sudo session, and retries the action.

= Does this replace WordPress roles and capabilities? =

No. Sudo adds a reauthentication layer on top of the existing permission model. WordPress capability checks still run after the gate. A user who does not have the `activate_plugins` capability will still be denied after reauthenticating — Sudo does not grant any new permissions.

= Which operations are gated? =

See the full list in the Description section above. The settings page also includes a read-only Gated Actions table showing all registered rules and their covered surfaces (Admin, AJAX, REST). Note: the surfaces shown reflect WordPress's actual API coverage — not all operations have REST endpoints. However, all gated actions are protected on non-interactive entry points (WP-CLI, Cron, XML-RPC, Application Passwords) via the configurable policy settings, regardless of which interactive surfaces they have. Developers can add custom rules via the `wp_sudo_gated_actions` filter.

= What about REST API and Application Passwords? =

Cookie-authenticated REST requests (from the block editor, admin AJAX) receive a `sudo_required` error. An admin notice on the next page load links to the challenge page where the user can authenticate and activate a sudo session, then retry the action. Application Password and bearer-token REST requests are governed by a separate policy setting with three modes: Disabled (returns `sudo_disabled`), Limited (default — returns `sudo_blocked`), and Unrestricted (passes through with no checks).

= What about WP-CLI, Cron, and XML-RPC? =

Each has its own three-tier policy setting: Disabled, Limited (default), or Unrestricted. In Limited mode, gated actions are blocked and logged via audit hooks while non-gated commands work normally. When CLI is Limited or Unrestricted, `wp cron` subcommands still respect the Cron policy — if Cron is Disabled, those commands are blocked even when CLI allows other operations.

= How does session binding work? =

When sudo is activated, a cryptographic token is stored in a secure httponly cookie and its hash is saved in user meta. On every gated request, both must match. A stolen session cookie on a different browser will not have a valid sudo session.

= How does 2FA browser binding work? =

When the password step succeeds and 2FA is required, a one-time challenge cookie is set in the browser. The 2FA pending state is keyed by the hash of this cookie, not by user ID. An attacker who stole the WordPress session cookie but is on a different machine does not have the challenge cookie and cannot complete the 2FA step.

= Is there brute-force protection? =

Yes. After 5 failed password attempts on the reauthentication form, the user is locked out for 5 minutes. Lockout events fire the `wp_sudo_lockout` action hook for audit logging.

= How do I log sudo activity? =

Install [WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/) or [Stream](https://wordpress.org/plugins/stream/). Sudo fires these action hooks:

* `wp_sudo_activated( $user_id, $expires, $duration )` — session started.
* `wp_sudo_deactivated( $user_id )` — session ended.
* `wp_sudo_reauth_failed( $user_id, $attempts )` — wrong password.
* `wp_sudo_lockout( $user_id, $attempts )` — lockout triggered.
* `wp_sudo_action_gated( $user_id, $rule_id, $surface )` — intercepted, challenge shown.
* `wp_sudo_action_blocked( $user_id, $rule_id, $surface )` — denied by policy.
* `wp_sudo_action_allowed( $user_id, $rule_id, $surface )` — permitted by policy.
* `wp_sudo_action_replayed( $user_id, $rule_id )` — stashed request replayed after reauth.
* `wp_sudo_capability_tampered( $role, $capability )` — a removed capability was re-detected on a role (possible database tampering).

= Does it support two-factor authentication? =

Yes. If the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin is installed and the user has 2FA enabled, the sudo challenge becomes a two-step process: password first, then the configured 2FA method (TOTP, email code, backup codes, etc.). For passkey and security key support, add the [WebAuthn Provider for Two Factor](https://wordpress.org/plugins/two-factor-provider-webauthn/) plugin. A visible countdown timer shows how long the user has to enter their code. Third-party 2FA plugins can integrate via filter hooks.

= Does it work on multisite? =

Yes. Settings are network-wide (one configuration for all sites). Sudo sessions use user meta (shared across the network), so authenticating on one site covers all sites. The action registry includes network-specific rules for theme enable/disable, site management, super admin grant/revoke, and network settings. The settings page appears under **Network Admin → Settings → Sudo**. On uninstall, per-site data is cleaned per-site, and user meta is only removed when no remaining site has the plugin active.

= What is the mu-plugin and do I need it? =

The mu-plugin is optional. It ensures Sudo's gate hooks are registered before any other regular plugin loads, preventing another plugin from deregistering the hooks or processing dangerous actions before the gate fires. You can install it with one click from the settings page. The mu-plugin is a thin shim in `wp-content/mu-plugins/` that loads the gate code from the main plugin directory — it updates automatically with regular plugin updates.

= What happens if I deactivate the plugin? =

Any active sudo sessions expire naturally. All gated actions return to their normal, ungated behavior. No data is lost. The mu-plugin shim (if installed) safely detects the missing main plugin and does nothing.

= Can I extend the list of gated actions? =

Yes. Use the `wp_sudo_gated_actions` filter to add custom rules. Each rule defines matching criteria for admin UI (`pagenow`, actions, HTTP method), AJAX (action names), and REST (route patterns, HTTP methods). Custom rules appear in the Gated Actions table on the settings page. All rules — including custom rules — are automatically protected on non-interactive surfaces (WP-CLI, Cron, XML-RPC, Application Passwords) via the configurable policy settings, even if they don't define AJAX or REST criteria.

= Can I change the 2FA verification window? =

Yes. The default window is 10 minutes. Use the `wp_sudo_two_factor_window` filter to adjust it (value in seconds).

== Screenshots ==

1. Challenge page — reauthentication interstitial with password field. Triggered by a gated action or proactively via the keyboard shortcut.
2. Two-factor verification — after password confirmation, users with 2FA enabled enter their authentication code.
3. Settings page — configure session duration and entry point policies (REST, CLI, Cron, XML-RPC).
4. Gate notice (plugins) — when no sudo session is active, a persistent notice links to the challenge page. Action buttons are disabled.
5. Gate notice (themes) — the same gating notice appears on the themes page.
6. Gated actions — the settings page lists all gated operations with their categories and surfaces. The surfaces shown (Admin, AJAX, REST) reflect interactive entry points. All gated actions are also protected on non-interactive surfaces via policy settings.
7. Active sudo session — the admin bar shows a green countdown timer with the remaining minutes and seconds. Hover to see the deactivation link.

== Changelog ==

= 2.2.0 =
* **Three-tier entry point policies** — replaces the binary Block/Allow toggle with three modes per surface: Disabled (shuts off the protocol entirely), Limited (default — gated actions blocked, non-gated work proceeds normally), and Unrestricted (everything passes through).
* **Function-level gating for non-interactive surfaces** — WP-CLI, Cron, and XML-RPC now hook into WordPress function-level actions (`activate_plugin`, `delete_plugin`, `set_user_role`, etc.) instead of matching request parameters. This makes gating reliable regardless of how the operation is triggered.
* **CLI enforces Cron policy** — `wp cron` subcommands respect the Cron policy even when CLI is Limited or Unrestricted. If Cron is Disabled, `wp cron event run` is blocked.
* **REST API policy split** — Disabled returns `sudo_disabled` (surface is off), Limited returns `sudo_blocked` (gated action denied), clearly distinguishing the two rejection reasons.
* **Automatic upgrade migration** — existing `block` settings migrate to `limited`, `allow` to `unrestricted`. Multisite-aware.
* **Site Health updated** — Disabled is treated as valid hardening (Good status). Unrestricted triggers a Recommended notice.
* **Manual testing guide** — comprehensive step-by-step verification procedures in `tests/MANUAL-TESTING.md`.
* **327 unit tests, 752 assertions.**

= 2.1.0 =
* Removes the `unfiltered_html` capability from the Editor role. Editors can no longer embed scripts, iframes, or other non-whitelisted HTML — KSES sanitization is always active for editors. Administrators retain `unfiltered_html`. The capability is restored if the plugin is deactivated or uninstalled.
* Adds tamper detection: if `unfiltered_html` reappears on the Editor role (e.g. via database modification), it is stripped automatically and the `wp_sudo_capability_tampered` action fires for audit logging.
* Fixes admin bar deactivation redirect: clicking the countdown timer to end a session now keeps you on the current page instead of redirecting to the dashboard.
* Replaces WordPress core's confusing "user editing capabilities" error with a clearer message when a bulk role change skips the current user.

= 2.0.0 =
Complete rewrite. Action-gated reauthentication replaces role-based privilege escalation.

* **New model** — gates dangerous operations behind reauthentication for any user, regardless of role. No custom role, no capability escalation.
* **Full attack surface coverage** — admin UI (stash-challenge-replay), AJAX (error + admin notice + session activation), REST API (cookie-auth challenge, app-password policy), WP-CLI, Cron, XML-RPC.
* **Action Registry** — 20 gated rules across 7 categories (plugins, themes, users, editors, options, updates, tools), plus 8 multisite-specific rules. Extensible via `wp_sudo_gated_actions` filter.
* **Entry point policies** — three-tier Disabled/Limited/Unrestricted policies for REST Application Passwords, WP-CLI, Cron, and XML-RPC.
* **2FA browser binding** — challenge cookie prevents cross-browser 2FA replay.
* **2FA countdown timer** — visible countdown during the verification step; configurable window via `wp_sudo_two_factor_window` filter.
* **Self-protection** — WP Sudo settings changes are gated.
* **MU-plugin toggle** — one-click install/uninstall from the settings page. Stable shim + loader pattern keeps the mu-plugin up to date with regular plugin updates.
* **Multisite** — network-wide settings, network-wide sessions, 8 network admin rules, `get_site_option`/`set_site_transient` storage.
* **8 audit hooks** — full lifecycle and policy logging for integration with WP Activity Log, Stream, and similar plugins.
* **Contextual help** — 4 help tabs on the settings page.
* **Accessibility** — WCAG 2.1 AA throughout (ARIA labels, focus management, status announcements, keyboard support).
* **281 unit tests, 686 assertions.**

= 1.2.1 =
* In-place modal reauthentication; no full-page redirect.
* AJAX activation, accessibility improvements, expanded test suite.

= 1.2.0 =
* M:SS countdown timer, red bar at 60 seconds, accessibility improvements.
* Multisite-safe uninstall, contextual Help tab.

= 1.1.0 =
* 15-minute session cap, two-factor authentication support, `unfiltered_html` restriction.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.2.0 =
Entry point policies now have three modes: Disabled, Limited, Unrestricted. Existing Block/Allow settings are migrated automatically. Review Settings > Sudo after upgrading.

= 2.0.0 =
Major rewrite. The custom Site Manager role is removed — sudo now gates dangerous actions for all users via reauthentication. Review the new settings (entry point policies) after upgrading.

= 1.2.1 =
Modal reauthentication replaces the login redirect; activation happens in place with an accessible dialog and no-JS fallback.
