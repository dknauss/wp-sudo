=== Sudo ===
Contributors:      dpknauss
Donate link:       https://dan.knauss.ca
Tags:              sudo, security, user roles, capabilities, access control
Requires at least: 6.2
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        1.2.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Sudo mode for WordPress. Designated roles can temporarily escalate their privileges to the Administrator level.

== Description ==

**Sudo** gives trusted WordPress users a safe, time-limited way to perform administrative tasks without making them Administrators.

**Features:**

* Adds a **Site Manager** user role with Editor capabilities.
* **Sudo mode** — eligible users can temporarily escalate to full Administrator privileges by reauthenticating via a one-click admin-bar button.
* **Reauthentication required** — users must enter their password before escalation is granted.
* **`unfiltered_html` restricted** — the `unfiltered_html` capability is stripped from Editors and Site Managers outside of sudo. This prevents arbitrary HTML/JS injection without an active, reauthenticated session.
* **Scoped escalation** — escalated privileges apply only to admin panel page loads. REST API, XML-RPC, AJAX, Application Password, and Cron requests are explicitly blocked.
* **Session binding** — sudo sessions are cryptographically bound to the browser that activated them via a secure cookie token.
* **Rate limiting** — 5 failed password attempts trigger a 5-minute lockout.
* Configurable sudo session duration. (1–15 minutes, default 15.)
* Choose which roles are allowed to activate sudo mode.
* **Two-factor authentication** — if the Two Factor plugin is active and the user has 2FA configured, a second verification step is required. Third-party 2FA plugins can integrate via the `wp_sudo_requires_two_factor`, `wp_sudo_validate_two_factor`, and `wp_sudo_render_two_factor_fields` hooks.
* **Admin bar countdown** — a live M:SS timer in the admin bar shows remaining session time. The bar turns red in the final 60 seconds.
* **Accessible** — screen-reader announcements for session state, `role="alert"` on errors, and a polite warning before session expiry.
* **Audit log hooks** — fires `wp_sudo_activated`, `wp_sudo_deactivated`, `wp_sudo_reauth_failed`, and `wp_sudo_lockout` actions for compatibility with Stream, WP Activity Log, and similar plugins.
* **Multisite safe** — per-site roles and options are cleaned on uninstall; shared user meta is only removed when no remaining site has the plugin active.
* Role capabilities stay in sync automatically when the plugin is updated.
* **Contextual help** — a Help tab on the settings page provides quick documentation for session duration, allowed roles, and developer hooks.

== Installation ==

1. Upload the `wp-sudo` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings → Sudo** to configure session duration and allowed roles.

== Frequently Asked Questions ==

= What can a Site Manager do? =

A Site Manager has all Editor capabilities plus: switch themes, edit theme options, activate plugins, list users, update core/plugins/themes, and import/export. Dangerous capabilities like `edit_users`, `promote_users`, and `manage_options` are only available during an active sudo session.

= How does sudo mode work? =

Eligible users see an **Activate Sudo** button in the admin bar. Clicking it redirects to a reauthentication page where they must enter their password. On success, the session activates and they are redirected back to where they started. The admin bar button turns green and shows a live countdown. Clicking it again (or waiting for it to expire) reverts the user to their normal capabilities.

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

By default the **Editor** and **Site Manager** roles are allowed. You can change this under **Settings → Sudo**. Roles below the Editor trust level (Author, Contributor, Subscriber) are not eligible — they lack the `edit_others_posts` capability, and the privilege gap between these roles and full Administrator is too large for safe escalation.

= Why is `unfiltered_html` restricted? =

WordPress grants the `unfiltered_html` capability to Editors on single-site installs by default. This allows inserting arbitrary HTML and JavaScript into posts and pages, which is a cross-site scripting (XSS) risk. Sudo strips this capability from all non-Administrator users unless they have an active sudo session. This means Editors and Site Managers must reauthenticate before they can use unfiltered HTML, adding an intentional friction point that reduces the risk of compromised accounts injecting malicious scripts.

= Does it support two-factor authentication? =

Yes. If the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin is installed and the user has 2FA enabled, sudo reauthentication becomes a two-step process: password first, then the configured 2FA method (TOTP, email code, backup codes, etc.). Other 2FA plugins can integrate via the `wp_sudo_requires_two_factor`, `wp_sudo_validate_two_factor`, and `wp_sudo_render_two_factor_fields` hooks.

= Does it work on multisite? =

Yes. The Site Manager role, settings, and version data are stored per-site. Sudo session data (user meta) is stored in the shared users table. On uninstall, per-site data is cleaned for each site, and user meta is only removed when no remaining site in the network still has the plugin active.

= What happens if I deactivate the plugin? =

The Site Manager role remains until you uninstall the plugin, so existing Site Manager users are not disrupted by a temporary deactivation. Any active sudo sessions expire naturally.

= What happens if my role changes during an active session? =

Sudo immediately deactivates. Every request re-verifies that the user's role is still on the allowed list. If an admin changes your role mid-session, your escalated privileges end on the next page load.

== Screenshots ==

1. Settings page with contextual Help tab.

== Changelog ==

= 1.2.0 =
* Admin bar countdown now uses a numeric M:SS timer instead of a text-based countdown.
* Removed the active-session admin notice banner — the admin bar provides the countdown and expiry warning.
* Admin bar turns red in the final 60 seconds with a screen-reader announcement.
* Improved accessibility: `role="alert"` on reauth errors, `aria-describedby` on the password field, and a polite screen-reader warning near session expiry.
* Inline JS now uses `wp_print_inline_script_tag()` for Content Security Policy nonce support.
* Tooltip strings use `esc_attr__()` for defensive escaping.
* Role eligibility is re-verified on every request — if a user's role is changed mid-session, sudo deactivates immediately.
* Multisite-safe uninstall: per-site data is cleaned per-site, and shared user meta is only removed when no other site has the plugin active.
* Added contextual Help tab on the Settings page.
* Settings page notes that duration changes apply to new sessions only.

= 1.1.0 =
* Maximum session duration capped at 15 minutes (matching standard Linux sudo behavior).
* Two-factor authentication support: integrates with the Two Factor plugin for an additional verification step.
* Removed `unfiltered_html` from default Editor and Site Manager capabilities — only available during sudo.
* Added filter hooks for third-party 2FA plugin integration.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
Multisite-safe uninstall. Improved admin bar countdown and accessibility. Role changes now immediately revoke sudo.

= 1.1.0 =
Session max capped at 15 minutes. Two-factor authentication support added. `unfiltered_html` capability now requires sudo.
