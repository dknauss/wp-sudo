=== Sudo ===
Contributors:      danknauss
Tags:              sudo, privileges, roles, escalation, security
Requires at least: 6.2
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        1.1.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Sudo mode for WordPress! Site Managers with Editor capabilities can temporarily escalate their privileges to the Administrator level.

== Description ==

**Sudo** gives designated roles a safe, time-limited way to perform administrative tasks without permanently granting them the Administrator role.

Features:

* Adds a **Webmaster** user role with Editor capabilities plus curated admin powers (no self-escalation capabilities).
* **Sudo mode** — eligible users can temporarily escalate to full Administrator privileges via a one-click admin-bar button.
* **Reauthentication required** — users must enter their password before escalation is granted.
* **Scoped escalation** — escalated privileges apply only to admin panel page loads. REST API, XML-RPC, AJAX, Application Password, and Cron requests are explicitly blocked.
* **Session binding** — sudo sessions are cryptographically bound to the browser that activated them via a secure cookie token.
* **Rate limiting** — 5 failed password attempts trigger a 5-minute lockout.
* Configurable session duration (1–15 minutes, default 15).
* Choose which roles are allowed to activate sudo mode.
* **Two-factor authentication** — if the Two Factor plugin is active and the user has 2FA configured, a second verification step is required. Third-party 2FA plugins can integrate via the `wp_sudo_requires_two_factor`, `wp_sudo_validate_two_factor`, and `wp_sudo_render_two_factor_fields` hooks.
* Visual indicators: green admin bar and a warning notice while sudo is active.
* **Audit log hooks** — fires `wp_sudo_activated`, `wp_sudo_deactivated`, `wp_sudo_reauth_failed`, and `wp_sudo_lockout` actions for compatibility with Stream, WP Activity Log, and similar plugins.
* Role capabilities stay in sync automatically when the plugin is updated.

== Installation ==

1. Upload the `wp-sudo` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings → Sudo** to configure session duration and allowed roles.

== Frequently Asked Questions ==

= What can a Webmaster do? =

A Webmaster has all Editor capabilities plus: switch themes, edit theme options, activate plugins, list users, update core/plugins/themes, and import/export. Dangerous capabilities like `edit_users`, `promote_users`, and `manage_options` are only available during an active sudo session.

= How does sudo mode work? =

Eligible users see a **"🔒 Activate Sudo"** button in the admin bar. Clicking it redirects to a reauthentication page where they must enter their password. On success, the session activates and they are redirected back to where they started. The admin bar button turns green and shows a countdown. Clicking it again (or waiting for it to expire) reverts the user to their normal capabilities.

= Is sudo mode active on REST API or XML-RPC? =

No. Escalated privileges are strictly scoped to admin panel page loads. REST API, XML-RPC, AJAX, Application Password, Cron, and WP-CLI requests are never escalated, even during an active sudo session.

= How does session binding work? =

When sudo is activated, a cryptographic token is stored in a secure, httponly cookie and its hash is saved in user meta. On every request, both must match. A stolen session cookie on a different browser will not inherit escalated privileges.

= Is there brute-force protection? =

Yes. After 5 failed password attempts on the reauthentication form, the user is locked out for 5 minutes. Lockout events fire an action hook for audit logging.

= How do I log sudo activity? =

The plugin fires these action hooks:

* `wp_sudo_activated( $user_id, $expires, $duration, $role )` — session started.
* `wp_sudo_deactivated( $user_id, $role )` — session ended.
* `wp_sudo_reauth_failed( $user_id, $attempts )` — wrong password.
* `wp_sudo_lockout( $user_id, $attempts )` — lockout triggered.

Reporting plugins like **Stream** and **WP Activity Log** automatically capture `do_action()` calls when properly configured.

= Which roles can activate sudo? =

By default only the **Editor** role is allowed. You can change this under **Settings → Sudo**.

= Does it support two-factor authentication? =

Yes. If the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin is installed and the user has 2FA enabled, sudo reauthentication becomes a two-step process: password first, then the configured 2FA method (TOTP, email code, backup codes, etc.). Other 2FA plugins can integrate via the `wp_sudo_requires_two_factor`, `wp_sudo_validate_two_factor`, and `wp_sudo_render_two_factor_fields` hooks.

= What happens if I deactivate the plugin? =

The Webmaster role remains until you uninstall the plugin, so existing Webmaster users are not disrupted by a temporary deactivation. Any active sudo sessions expire naturally.

== Screenshots ==

1. Settings page.

== Changelog ==

= 1.1.0 =
* Maximum session duration capped at 15 minutes (matching standard Linux sudo behavior).
* Two-factor authentication support: integrates with the Two Factor plugin for an additional verification step.
* Removed `unfiltered_html` from default Editor and Webmaster capabilities — only available during sudo.
* Added filter hooks for third-party 2FA plugin integration.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Session max capped at 15 minutes. Two-factor authentication support added. `unfiltered_html` capability now requires sudo.
