# Frequently Asked Questions

## How does sudo gating work?

When a user attempts a gated action — for example, activating a plugin — Sudo intercepts the request at `admin_init` (before WordPress processes it). The original request is stashed in a transient, the user is redirected to a challenge page, and after successful reauthentication, the original request is replayed. For AJAX and REST requests, the browser receives a `sudo_required` error and an admin notice appears on the next page load linking to the challenge page. The user authenticates, activates a sudo session, and retries the action.

## Does this replace WordPress roles and capabilities?

No. Sudo adds a reauthentication layer on top of the existing permission model. WordPress capability checks still run after the gate. A user who does not have the `activate_plugins` capability will still be denied after reauthenticating — Sudo does not grant any new permissions.

## Which operations are gated?

| Category | Operations |
|---|---|
| **Plugins** | Activate, deactivate, delete, install, update |
| **Themes** | Switch, delete, install, update |
| **Users** | Delete, change role, create new user, create application password |
| **File editors** | Plugin editor, theme editor |
| **Critical options** | `siteurl`, `home`, `admin_email`, `default_role`, `users_can_register` |
| **WordPress core** | Update, reinstall |
| **Site data export** | WXR export |
| **WP Sudo settings** | Self-protected — settings changes require reauthentication |
| **Multisite** | Network theme enable/disable, site delete/deactivate/archive/spam, super admin grant/revoke, network settings |

The settings page also includes a read-only Gated Actions table showing all registered rules and their covered surfaces (Admin, AJAX, REST). Note: the surfaces shown reflect WordPress's actual API coverage — not all operations have REST endpoints. However, all gated actions are protected on non-interactive entry points (WP-CLI, Cron, XML-RPC, Application Passwords) via the configurable policy settings. Developers can add custom rules via the `wp_sudo_gated_actions` filter.

## What about REST API and Application Passwords?

Cookie-authenticated REST requests (from the block editor, admin AJAX) receive a `sudo_required` error. An admin notice on the next page load links to the challenge page where the user can authenticate and activate a sudo session, then retry the action. Application Password and bearer-token REST requests are governed by a separate policy setting with three modes: Disabled (returns `sudo_disabled`), Limited (default — returns `sudo_blocked`), and Unrestricted (passes through with no checks). Individual application passwords can override the global policy from the user profile page — for example, a deployment pipeline password can be Unrestricted while an AI assistant password stays Limited.

## What about WP-CLI, Cron, and XML-RPC?

Each has its own three-tier policy setting: Disabled, Limited (default), or Unrestricted. In Limited mode, gated actions are blocked and logged via audit hooks while non-gated commands work normally. When CLI is Limited or Unrestricted, `wp cron` subcommands still respect the Cron policy — if Cron is Disabled, those commands are blocked even when CLI allows other operations.

## How does session binding work?

When sudo is activated, a cryptographic token is stored in a secure httponly cookie and its hash is saved in user meta. On every gated request, both must match. A stolen session cookie on a different browser will not have a valid sudo session. See [Security Model](security-model.md) for full details.

## How does 2FA browser binding work?

When the password step succeeds and 2FA is required, a one-time challenge cookie is set in the browser. The 2FA pending state is keyed by the hash of this cookie, not by user ID. An attacker who stole the WordPress session cookie but is on a different machine does not have the challenge cookie and cannot complete the 2FA step. See [Security Model](security-model.md) for full details.

## Is there brute-force protection?

Yes. After 5 failed password attempts on the reauthentication form, the user is locked out for 5 minutes. Lockout events fire the `wp_sudo_lockout` action hook for audit logging.

## How do I log sudo activity?

Install [WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/) or [Stream](https://wordpress.org/plugins/stream/). Sudo fires 9 action hooks covering session lifecycle, gated actions, policy decisions, lockouts, and tamper detection. See [Developer Reference](developer-reference.md) for hook signatures.

## Does it support two-factor authentication?

Yes. If the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin is installed and the user has 2FA enabled, the sudo challenge becomes a two-step process: password first, then the configured 2FA method (TOTP, email code, backup codes, etc.). For passkey and security key support, add the [WebAuthn Provider for Two Factor](https://wordpress.org/plugins/two-factor-provider-webauthn/) plugin. A visible countdown timer shows how long the user has to enter their code. Third-party 2FA plugins can integrate via filter hooks — see [Developer Reference](developer-reference.md).

## Does it work on multisite?

Yes. Settings are network-wide (one configuration for all sites). Sudo sessions use user meta (shared across the network), so authenticating on one site covers all sites. The action registry includes network-specific rules for theme enable/disable, site management, super admin grant/revoke, and network settings. The settings page appears under **Network Admin > Settings > Sudo**. On uninstall, per-site data is cleaned per-site, and user meta is only removed when no remaining site has the plugin active.

## What is gated on multisite subsites?

On multisite, WordPress core already removes the most dangerous General Settings fields (site URL, home URL, membership, default role) from subsite admin pages — only Super Admins can change those at the network level. The remaining subsite settings (site title, tagline, admin email, timezone, date/time formats) are low-risk and not gated. Changing admin email also requires email confirmation by WordPress core. Network-level operations — network settings, theme management, site creation/deletion, and Super Admin grants — are all gated.

## What is the mu-plugin and do I need it?

The mu-plugin is optional. It ensures Sudo's gate hooks are registered before any other regular plugin loads, preventing another plugin from deregistering the hooks or processing dangerous actions before the gate fires. You can install it with one click from the settings page. The mu-plugin is a thin shim in `wp-content/mu-plugins/` that loads the gate code from the main plugin directory — it updates automatically with regular plugin updates.

## What happens if I deactivate the plugin?

Any active sudo sessions expire naturally. All gated actions return to their normal, ungated behavior. No data is lost. The mu-plugin shim (if installed) safely detects the missing main plugin and does nothing.

## Can I extend the list of gated actions?

Yes. Use the `wp_sudo_gated_actions` filter to add custom rules. See [Developer Reference](developer-reference.md) for the rule structure and code examples.

## Can I change the 2FA verification window?

Yes. The default window is 5 minutes. Use the `wp_sudo_two_factor_window` filter to adjust it (value in seconds). See [Developer Reference](developer-reference.md).
