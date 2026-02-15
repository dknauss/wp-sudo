# WP Sudo — Project State & Development History

Generated: 2026-02-14
Current version: **2.2.0** (tagged, released on GitHub)
Tests: **328 tests, 756 assertions** (all passing)
Lint: Clean (1 pre-existing VIP warning: `HTTP_USER_AGENT` in class-gate.php)

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Architecture](#architecture)
3. [Current State](#current-state)
4. [Development History](#development-history)
5. [Git Log](#git-log)
6. [Codebase Metrics](#codebase-metrics)
7. [Dev Environments](#dev-environments)

**Appendices:**

- [A: Roadmap](#appendix-a-roadmap)
- [B: Accessibility Roadmap](#appendix-b-accessibility-roadmap)
- [C: Manual Testing Guide](#appendix-c-manual-testing-guide)

---

## Project Overview

WP Sudo is a WordPress plugin that provides action-gated reauthentication. Dangerous operations (plugin activation, user deletion, critical settings changes, etc.) require password confirmation before they proceed — regardless of user role.

**Requirements:** WordPress 6.2+, PHP 8.0+
**Dependencies:** None in production. Dev: PHPUnit 9.6, Brain\Monkey, Mockery, Patchwork, VIP WPCS.

### What Gets Gated

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

28 rules across 7 categories + multisite. Extensible via `wp_sudo_gated_actions` filter.

---

## Architecture

**Entry point:** `wp-sudo.php` — defines constants, registers an SPL autoloader (maps `WP_Sudo\Class_Name` to `includes/class-class-name.php`), and wires lifecycle hooks. The `wp_sudo()` function returns the singleton Plugin instance.

**Bootstrap sequence:** `plugins_loaded` → `Plugin::init()` → loads translations, runs upgrader, registers gate, sets up challenge page, initializes admin UI.

### Core Classes (all in `includes/`, namespace `WP_Sudo`)

| Class | Role |
|---|---|
| **Plugin** | Orchestrator. Creates and owns component instances. Handles activation/deactivation. Strips `unfiltered_html` from editors on activation, restores on deactivation. |
| **Gate** | Multi-surface interceptor. Matches requests against Action Registry. Gates via reauthentication (admin UI), error response (AJAX/REST), or policy (CLI/Cron/XML-RPC/App Passwords). |
| **Action_Registry** | Defines all gated rules. Extensible via `wp_sudo_gated_actions` filter. |
| **Challenge** | Interstitial reauthentication page. Password verification, 2FA integration, request stash/replay. |
| **Sudo_Session** | Session management. Cryptographic token (user meta + httponly cookie), rate limiting (5 attempts → 5-min lockout), session binding. |
| **Request_Stash** | Stashes and replays intercepted admin requests using transients. |
| **Admin** | Settings page at Settings → Sudo. Session duration (1–15 min), entry point policies. 8 help tabs. Option key: `wp_sudo_settings`. |
| **Admin_Bar** | Live countdown timer in admin bar during active sessions. |
| **Site_Health** | WordPress Site Health integration (status tests and debug info). |
| **Upgrader** | Version-aware migration runner. Sequential upgrade routines when stored version < plugin version. |

### Audit Hooks (9)

`wp_sudo_activated`, `wp_sudo_deactivated`, `wp_sudo_reauth_failed`, `wp_sudo_lockout`, `wp_sudo_action_gated`, `wp_sudo_action_blocked`, `wp_sudo_action_allowed`, `wp_sudo_action_replayed`, `wp_sudo_capability_tampered`.

### Testing Stack

Brain\Monkey (WordPress function/hook mocking), Mockery (object mocking), Patchwork (redefining `setcookie`/`header`). PHPUnit strict mode enabled.

---

## Current State

### v2.2.0 — Released 2026-02-14

**Major features in this release:**

- **Three-tier entry point policies** — replaces binary Block/Allow with Disabled/Limited/Unrestricted per surface
- **Function-level gating** — WP-CLI, Cron, XML-RPC hook into WordPress function-level actions instead of matching request parameters
- **CLI enforces Cron policy** — `wp cron` subcommands respect Cron policy even when CLI is Limited/Unrestricted
- **REST API policy split** — Disabled returns `sudo_disabled`, Limited returns `sudo_blocked`
- **Multisite gating fixes** — WP Sudo self-protection and Network Settings now correctly gated on multisite
- **Security Model documentation** — README sections and help tabs covering hook-based interception boundaries
- **Environment help tab** — separate tab for cookie/proxy requirements and multisite gating scope
- **Multisite subsite FAQ** — documents that subsite General Settings are intentionally ungated
- **Automatic upgrade migration** — `block` → `limited`, `allow` → `unrestricted` (multisite-aware)
- **Site Health updated** — Disabled = Good, Unrestricted = Recommended notice
- **Manual testing guide** — `tests/MANUAL-TESTING.md`

### Known Issues

- 1 pre-existing VIP WPCS warning about `HTTP_USER_AGENT` in class-gate.php (intentional — used for surface detection)

### Help Tabs (8)

1. How It Works
2. Security Features
3. Security Model
4. Environment
5. Recommended Plugins
6. Settings
7. Extending
8. Audit Hooks

---

## Development History

### v1.0.0 → v1.1.0 (2026-02-10)

Initial release. Role-based privilege escalation model with a custom "Site Manager" role (later renamed "Webmaster"). 15-minute session cap, 2FA support, `unfiltered_html` restriction.

### v1.2.0 (2026-02-10)

M:SS countdown timer, red bar at 60 seconds, accessibility improvements. Multisite-safe uninstall, contextual Help tab. Renamed Webmaster to Site Manager.

### v1.2.1 (2026-02-12)

In-place modal reauthentication (no full-page redirect). AJAX activation, accessibility improvements, expanded test suite. PHPCS with VIP WPCS ruleset.

### v2.0.0 (2026-02-13) — Complete Rewrite

Replaced role-based privilege escalation with action-gated reauthentication. Removed the custom Site Manager role entirely.

- **New model** — gates dangerous operations behind reauthentication for any user, regardless of role
- **Full attack surface** — admin UI (stash-challenge-replay), AJAX (error + admin notice), REST API (cookie-auth challenge, app-password policy), WP-CLI, Cron, XML-RPC
- **Action Registry** — 20 gated rules across 7 categories + 8 multisite rules
- **Entry point policies** — Disabled/Limited/Unrestricted per surface
- **2FA browser binding** — challenge cookie prevents cross-browser replay
- **Self-protection** — WP Sudo settings changes are gated
- **MU-plugin toggle** — one-click install/uninstall, stable shim + loader pattern
- **Multisite** — network-wide settings and sessions, 8 network admin rules
- **8 audit hooks** — full lifecycle and policy logging
- **Contextual help** — help tabs on settings page
- **Accessibility** — WCAG 2.1 AA throughout
- **281 tests, 686 assertions**

### v2.1.0 (2026-02-13)

- Removes `unfiltered_html` from Editor role with tamper detection canary
- Fixes admin bar deactivation redirect (stays on current page)
- Replaces core's confusing role-change error message

### v2.2.0 (2026-02-14)

- Three-tier entry point policies (replaces Block/Allow)
- Function-level gating for non-interactive surfaces
- CLI enforces Cron policy
- REST API policy split (sudo_disabled vs sudo_blocked)
- Multisite gating fixes (network settings, self-protection)
- Security Model documentation (README + help tabs)
- Environment help tab (cookies, proxies, multisite scope)
- Multisite subsite FAQ
- Automatic upgrade migration
- Site Health updates
- Manual testing guide
- **328 tests, 756 assertions**

---

## Git Log

```
de15aae 2026-02-14 23:06 docs: split Environment help tab, add multisite subsite FAQ
d90ea93 2026-02-14 22:41 fix: multisite gating for network settings and self-protection
2479f14 2026-02-14 21:45 docs: add Security Model section and help tab
584f690 2026-02-14 20:54 fix: gate notices missing on Network Admin plugins/themes screens
86759bb 2026-02-14 20:48 fix: replace false "reload the page" instruction in error messages
2e29b65 2026-02-14 20:39 fix: cancel button returns to originating page, not dashboard
1dd68a5 2026-02-14 17:53 feat: three-tier entry point policies (Disabled/Limited/Unrestricted)
63506b0 2026-02-14 11:32 docs: sync readme.txt with readme.md changes from Copilot commits
70ffd77 2026-02-14 02:02 docs: clarify that all gated actions have policy-based protection on non-interactive surfaces
616d7e0 2026-02-14 00:34 Add disclaimer about plugin not being production-ready
f9b0e0e 2026-02-13 23:02 Merge pull request #2 from dknauss/v2
1b72ad8 2026-02-13 23:00 docs: soften language to "potentially dangerous/destructive"
f68fff6 2026-02-13 22:54 fix: add h3 heading to Recommended Plugins help tab
077f24c 2026-02-13 22:51 refactor: split overloaded help tab into three focused tabs
be91a8b 2026-02-13 22:48 docs: add zero-trust framing, WebAuthn Provider, and ROADMAP.md
15ba9fd 2026-02-13 22:33 docs: update screenshots and descriptions for v2 UI
db6bf2e 2026-02-13 22:14 feat: replace core's confusing role error message on users.php
c97c936 2026-02-13 21:56 fix: stay on current page after admin bar session deactivation
8a721c7 2026-02-13 21:22 feat: strip unfiltered_html from editors with tamper detection
5c84e48 2026-02-13 20:42 feat: replace modal with session-only challenge page and gate UI
ee0a7e7 2026-02-13 19:06 Clarify this link is about the sudo *nix command and origin of the concept.
6179b03 2026-02-13 18:55 Revert "Clarify this sudo is about the *nix origin."
a1332e7 2026-02-13 18:49 Clarify this sudo is about the *nix origin.
e4abfd0 2026-02-13 18:48 fix: load intercept JS on theme/plugin install pages
9638972 2026-02-13 18:43 docs: document keyboard shortcut in readmes and help tab
a302903 2026-02-13 18:25 Merge pull request #1 from dknauss/v2
012a2ba 2026-02-13 17:49 docs: add accessibility roadmap for deferred WCAG items
eacb09c 2026-02-13 17:45 docs: rewrite readmes, expand help tabs, add Site Health settings link
082cf64 2026-02-13 17:30 fix: loader perf + MU-plugin toggle accessibility
31e44ec 2026-02-13 17:20 feat: add one-click MU-plugin install/uninstall toggle
de8095f 2026-02-13 17:07 feat: rewrite as action-gated reauthentication (v2)
0faad8c 2026-02-12 00:13 Update project title in README
29d8654 2026-02-12 00:07 Update readmes and version for 1.2.1
b5fa286 2026-02-11 23:20 feat: add modal reauthentication for sudo activation
0b5fc13 2026-02-11 22:10 feat: add PHPCS with VIP WPCS ruleset and fix all coding standards violations
601191c 2026-02-11 02:50 Add screenshots and update readme screenshot captions
c8d743e 2026-02-11 02:44 Update readme: fix contributor slug and polish description copy
a6adfa0 2026-02-11 02:27 Minor updates and fixes.
fc32172 2026-02-11 01:00 Rename Webmaster to Site Manager, add capability floor, upgrade framework, and documentation polish
8e00d3c 2026-02-10 23:32 Bump to v1.2.0: multisite-safe uninstall, accessibility, and admin UX improvements
b9aef18 2026-02-10 21:47 Cleanup minor issues in the codebase and update the readme file.
263e924 2026-02-10 18:44 Initial commit: WP Sudo v1.1.0
```

### Tags

| Tag | Commit | Date |
|-----|--------|------|
| v1.2.0 | 8e00d3c | 2026-02-10 |
| v1.2.1 | 29d8654 | 2026-02-12 |
| v2.0.0 | de8095f | 2026-02-13 |
| v2.1.0 | 8a721c7 | 2026-02-13 |
| v2.2.0 | de15aae | 2026-02-14 |

---

## Codebase Metrics

| Area | Lines |
|---|---|
| Plugin PHP (`includes/*.php`) | 5,468 |
| Entry point (`wp-sudo.php`) | ~100 |
| Tests (`tests/Unit/*.php`) | 6,368 |
| **Total test count** | 328 tests, 756 assertions |

---

## Dev Environments

| Name | Type | Path | Notes |
|---|---|---|---|
| **GitHub repo** | Source | `/Users/danknauss/Documents/GitHub/wp-sudo/` | Origin of truth |
| **Local multisite** | Multisite (subdirectory) | `/Users/danknauss/Local Sites/multisite-test/app/public/wp-content/plugins/wp-sudo/` | Port 10018, nginx, full app-password support |
| **Local single-site** | Single-site | `/Users/danknauss/Local Sites/wp-sudo-dev/app/public/wp-content/plugins/wp-sudo/` | |
| **Studio** | Single-site | `/Users/danknauss/Studio/development/wp-content/plugins/wp-sudo/` | Port 8883, PHP built-in server (app-password curl tests fail) |

**Do NOT sync to** `Studio/multisite-network` — gone.

**Sync command:**
```bash
rsync -av --delete --exclude='.git' --exclude='vendor' --exclude='node_modules' --exclude='.phpunit.result.cache' /Users/danknauss/Documents/GitHub/wp-sudo/ <target>/
```

**CLI access:**
- Local PHP: `/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.27+1/bin/darwin-arm64/bin/php`
- Local WP-CLI: `/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp`
- Studio CLI: `studio wp --path="/Users/danknauss/Studio/development" <command>`

---
---

## Appendix A: Roadmap

Remaining enhancements for future releases. Items marked with a checkmark were proposed in the original v2 roadmap and have been implemented.

### Completed (v2.0.0–v2.1.0)

- **Site Health integration** — MU-plugin status, session duration audit, entry point policy review, stale session cleanup.
- **Progressive rate limiting** — attempts 1–3 immediate, attempt 4 delayed 2s, attempt 5 delayed 5s, attempt 6+ locked out 5 min.
- **CSP-compatible asset loading** — all scripts are enqueued external files; no inline `<script>` blocks.
- **Lockout countdown timer** — remaining lockout seconds displayed on the challenge page.
- **Admin notice fallback for AJAX/REST gating** — transient-based notice with challenge link on next page load.
- **Gated actions reference table** — read-only table on the settings page showing all registered rules, categories, and surfaces.
- **Modal elimination (v2 architecture)** — the v1 modal + fetch/jQuery monkey-patching was replaced entirely by the stash-challenge-replay pattern.
- **Editor `unfiltered_html` restriction** — stripped from editors on activation, tamper detection canary at `init` priority 1.

### Open — Medium Effort

#### 1. WP Activity Log (WSAL) Sensor Extension

Optional WSAL sensor shipping as a single PHP file. Register event IDs in the 8900+ range, create a sensor class in the `WSAL\Plugin_Sensors` namespace, and map existing `wp_sudo_*` action hooks to WSAL alert triggers.

**Impact:** High — dramatically increases appeal to managed hosting and enterprise customers who already use WSAL.

#### 2. Multi-Dimensional Rate Limiting (IP + User)

Add per-IP tracking via transients alongside existing per-user tracking. Catches distributed attacks where multiple IPs target the same user, or one IP targets multiple users. Include IP in the `wp_sudo_lockout` audit hook for logging.

**Impact:** High — hardens brute-force protection against coordinated attacks.

#### 3. Session Activity Dashboard Widget

Admin dashboard widget showing:
- Active sudo sessions on the site (count + user list).
- Recent gated operations (last 24h from audit hooks).
- Policy summary.

On multisite, a network admin widget could show activity across all sites.

**Note:** Requires storing audit data — currently the hooks fire-and-forget with no persistence. A lightweight custom table or transient-based ring buffer would be needed.

**Impact:** Medium — useful visibility for site administrators, but not a security improvement.

### Open — High Effort

#### 4. Gutenberg Block Editor Integration

Detect block editor context and queue the reauthentication requirement instead of interrupting save. Show a snackbar-style notice using the `@wordpress/notices` API. Requires specific Gutenberg awareness and testing across WordPress versions.

**Impact:** Medium — improves UX for block editor users, but the current stash-replay pattern already works for most editor operations.

#### 5. Network Policy Hierarchy for Multisite

Super admins set minimum session duration and maximum allowed entry-point policies at the network level. Site admins can only tighten (not loosen) these constraints.

**Impact:** Medium — valuable for large multisite networks with delegated site administration. Not relevant for single-site installs.

### Declined

- **Session extension** — allowing users to extend an active session without reauthentication would undermine the time-bounded trust model. The keyboard shortcut makes re-authentication fast enough.
- **Passkey/WebAuthn reauthentication** — already works through the existing Two Factor plugin integration. WP Sudo's challenge page is provider-agnostic.

---

## Appendix B: Accessibility Roadmap

Issues deferred from the initial WCAG 2.1 AA audit. All Critical and High severity items have been resolved.

### Medium Priority

#### 1. Challenge page Escape key navigates without warning

**File:** `admin/js/wp-sudo-challenge.js` (line 270-273)
**WCAG:** 3.2.2 On Input

Pressing Escape immediately navigates to the cancel URL with no confirmation or screen reader announcement. Users who accidentally press Escape lose their challenge state. Add a confirmation step or at minimum an `aria-live` announcement before navigating.

#### 2. Challenge page step-change announcement

**File:** `admin/js/wp-sudo-challenge.js` (line 84-94)
**WCAG:** 4.1.3 Status Messages

Transitioning from the password step to the 2FA step has no screen reader announcement. Focus moves to the first 2FA input, but the context change is not communicated to AT users. Add a brief `aria-live` announcement such as "Password verified. Two-factor authentication required."

#### 3. Settings page label-input association audit

**File:** `includes/class-admin.php`
**WCAG:** 1.3.1 Info and Relationships

Verify that all settings fields (session duration, entry point policies) have proper `<label for="">` associations. WordPress Settings API typically handles this, but custom field callbacks should be audited.

#### 4. Replay form accessible context

**File:** `admin/js/wp-sudo-challenge.js` (`handleReplay()`, line 209)
**WCAG:** 4.1.3 Status Messages

The self-submitting hidden form for POST replay provides no indication to the user that the action is being replayed. Add a visible/announced "Replaying your action..." status message before form submission.

### Low Priority

#### 5. Admin bar countdown cleanup on page unload

**File:** `includes/class-admin-bar.php` (`countdown_script()`)
**WCAG:** Best practice (not a violation)

The `setInterval` is never cleared on page unload. While browsers handle this automatically, explicitly clearing the interval in a `beforeunload` handler is cleaner and prevents potential issues with bfcache.

#### 6. Settings page default value documentation

**File:** `includes/class-admin.php`
**WCAG:** 3.3.5 Help

Settings fields could include brief inline descriptions noting the default values and their implications. The help tabs provide this information, but inline context at the field level improves usability for all users.

### Already Addressed

- **Reduced motion preferences:** Both CSS files already include `@media (prefers-reduced-motion: reduce)` rules.
- **Focus-visible outlines:** Challenge CSS includes `:focus-visible` outlines with proper offset.

---

## Appendix C: Manual Testing Guide

Manual verification tests for WP Sudo v2.2.0+. These complement the automated PHPUnit suite (`composer test`) and should be run against a real WordPress environment before each release.

### Environments

| Name | Type | URL | Notes |
|------|------|-----|-------|
| Studio | Single-site | `http://localhost:8883` | PHP built-in server; app-password `curl` tests fail (server strips `Authorization` header) |
| Local | Multisite (subdirectory) | `http://localhost:10018` | nginx; full app-password support; use Local's **Open Site Shell** for WP-CLI |

> **Tip:** Studio is best for admin UI testing. Local is best for CLI, cron, and app-password `curl` tests. Run multisite-specific tests (network admin rules) only on Local.

### Prerequisites

- Plugin is active on the test site.
- A second test plugin is installed (e.g. Hello Dolly) for activate/deactivate/delete tests.
- An Application Password exists for `curl` tests (Users > Profile > Application Passwords).
- A second user account exists for role-change and delete-user tests.
- Browser DevTools are open to the Console and Network tabs.
- The user has a role of Administrator or Super Administrator (on Multisite).

### 1. Sudo Session Lifecycle

#### 1.1 Activate via Challenge Page

1. Ensure no sudo session is active (admin bar has no timer).
2. Navigate to Settings > Sudo.
3. Click Save Changes.
4. **Expected:** Redirected to the challenge page. Shows "Confirm Your Identity" with action label "Change WP Sudo settings."
5. Enter your password and click Confirm & Continue.
6. **Expected:** Redirected back to Settings > Sudo. Form is submitted. Admin bar shows countdown timer.

#### 1.2 Activate via Keyboard Shortcut

1. Ensure no sudo session is active.
2. Press Ctrl+Shift+S (Windows/Linux) or Cmd+Shift+S (Mac).
3. **Expected:** Challenge page in session-only mode (no stash key, label says "Activate sudo session").
4. Enter your password.
5. **Expected:** Session activates, redirected to referring admin page.

#### 1.3 Shortcut Flash During Active Session

1. With sudo active, press the keyboard shortcut.
2. **Expected:** Admin bar timer flashes briefly. No navigation.

#### 1.4 Session Expiry

1. Set session duration to 1 minute.
2. Activate sudo, wait for timer to reach zero.
3. **Expected:** Timer disappears. Next gated action triggers a challenge.

#### 1.5 Rate Limiting

1. Open the challenge page.
2. Enter an incorrect password 5 times.
3. **Expected:** After attempt 4, noticeable delay (~2s). After attempt 5, form disabled with lockout message (~5 minutes).

### 2. Admin UI Gating (Stash-Challenge-Replay)

**How it works:** Action links on plugin/theme screens are disabled (grayed out) when no sudo session is active. Activate sudo via the persistent notice or keyboard shortcut. Once active, links become operable. For form-based actions, submitting triggers a redirect to the challenge page, then the original POST is replayed after authentication.

#### 2.1 Activate Plugin

1. Go to Plugins (single-site or any multisite Plugins screen).
2. **Expected:** Activate links grayed out. Persistent notice visible.
3. Activate sudo via notice link or shortcut.
4. **Expected:** Redirected back. Activate links operable.
5. Click Activate.
6. **Expected:** Plugin activates immediately.

#### 2.2 Deactivate Plugin

Same flow as 2.1 but with Deactivate links.

#### 2.3 Delete Plugin

1. Deactivate a plugin (with sudo active), let session expire.
2. **Expected:** Delete links grayed out.
3. Activate sudo, click Delete.
4. **Expected:** Plugin deleted.

#### 2.4 Themes

**Multisite Network Admin:** Click Network Enable on a theme → challenge page → authenticate → theme enabled.

**Single-Site / Subsite:** Activate buttons grayed out → activate sudo → buttons operable → click Activate → theme activates.

#### 2.5 Delete User

1. Go to Users, hover over non-admin user, click Delete.
2. WordPress shows confirmation screen. Click Confirm Deletion.
3. **Expected:** Challenge page with "Delete user." Cancel returns to Users.
4. Authenticate.
5. **Expected:** User deleted.

#### 2.6 Change User Role (Bulk Action)

1. Select user checkbox, choose new role, click Change.
2. **Expected:** Challenge page with "Change user role."
3. Authenticate → role change replayed.

#### 2.7 Change User Role (Profile Page)

1. Edit another user, change Role dropdown, click Update User.
2. **Expected:** Challenge page with "Change user role."
3. Authenticate → profile update replayed.

#### 2.8 Create User

1. Users > Add New, fill form, click Add New User.
2. **Expected:** Challenge page with "Create new user."
3. Authenticate → user created.

#### 2.9 Change a Critical Site Setting

1. Settings > General, change a field, Save Changes.
2. **Expected:** Challenge page with "Change critical site settings."
3. Authenticate → settings saved.

#### 2.10 Change WP Sudo Settings (Self-Protection)

1. Settings > Sudo, change a value, Save Changes.
2. **Expected:** Challenge page with "Change WP Sudo settings."
3. Authenticate → settings saved.

#### 2.11 Export Site Data

1. Tools > Export, click Download Export File.
2. **Expected:** Challenge page with "Export site data."
3. Authenticate → export downloads.

#### 2.12 Edit Plugin/Theme File

1. Plugin/Theme File Editor, edit file, Update File.
2. **Expected:** Challenge page.
3. Authenticate → file edit replayed.

#### 2.13 Bypass Challenges with Active Session

1. Activate sudo.
2. Repeat any test above.
3. **Expected:** Actions proceed immediately with no challenge.

#### 2.14 Cancel Returns to Originating Page

1. Trigger any stash-challenge-replay flow.
2. Click Cancel on the challenge page.
3. **Expected:** Returned to the page you started from, not the dashboard.

### 3. AJAX Gating (Plugin and Theme Installers)

#### 3.1 Install Plugin via Search

1. No sudo active. Plugins > Add New. Search for a plugin.
2. **Expected:** Install Now buttons grayed out. Persistent notice visible.
3. Activate sudo, return.
4. **Expected:** Install Now buttons operable. Click one → plugin installs via AJAX.

#### 3.2 Delete Theme via AJAX

1. No sudo active. Appearance > Themes, locate inactive theme.
2. **Expected:** Delete links grayed out.
3. Activate sudo. Click Delete → theme deleted via AJAX.

#### 3.3 Update Plugin via AJAX

1. No sudo active. Plugin with update available.
2. **Expected:** Update buttons grayed out.
3. Activate sudo. Click Update Now → plugin updates via AJAX.

### 4. REST API — Cookie Auth (Browser)

#### 4.1 Create Application Password (Profile Page)

1. No sudo active. Users > Profile > Application Passwords.
2. Enter name, click Add New Application Password.
3. **Expected:** Error notice mentioning sudo and keyboard shortcut.
4. Press keyboard shortcut, authenticate, retry.
5. **Expected:** Password created.

#### 4.2 `curl` with Cookie Auth

Run against Local (localhost:10018), not Studio.

```bash
# 0. Clean up stale cookies
rm -f /tmp/wp-cookies.txt

# 1. Log in and capture cookies
curl -s -L -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -X POST "http://localhost:10018/wp-login.php" \
  -d 'log=USER&pwd=PASS&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F&testcookie=1' \
  -H "Cookie: wordpress_test_cookie=WP+Cookie+check" -o /dev/null

# 2. Get REST nonce
NONCE=$(curl -s -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  "http://localhost:10018/wp-admin/" \
  | grep -oE 'createNonceMiddleware\( "[a-f0-9]+" \)' \
  | grep -oE '[a-f0-9]{6,}')

# 3. Gated action WITHOUT sudo → sudo_required
curl -s -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -H "X-WP-Nonce: $NONCE" -H "Content-Type: application/json" \
  -X POST "http://localhost:10018/wp-json/wp/v2/users/me/application-passwords" \
  -d '{"name":"test"}'

# 4. Activate sudo via challenge AJAX
CNONCE=$(curl -s -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  "http://localhost:10018/wp-admin/admin.php?page=wp-sudo-challenge" \
  | grep -oE 'wpSudoChallenge = \{[^}]+\}' \
  | grep -oE '"nonce":"[^"]+"' | grep -oE '[a-f0-9]{6,}')
curl -s -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -X POST "http://localhost:10018/wp-admin/admin-ajax.php" \
  -d "action=wp_sudo_challenge_auth&_wpnonce=$CNONCE&password=PASS"

# 5. Retry WITH sudo → 200 with password data
curl -s -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -H "X-WP-Nonce: $NONCE" -H "Content-Type: application/json" \
  -X POST "http://localhost:10018/wp-json/wp/v2/users/me/application-passwords" \
  -d '{"name":"test-with-sudo"}'
```

### 5. REST API — App Password Policies

Replace `USER:APP_PASS` with Application Password credentials (strip spaces from the password).

#### 5.1 Non-Gated Endpoint (All Policies)

```bash
curl -s -u "USER:APP_PASS" "http://localhost:10018/wp-json/wp/v2/users/me"
```

**Expected:** 200 with user data.

#### 5.2 Limited (Default)

```bash
curl -s -u "USER:APP_PASS" -X POST \
  "http://localhost:10018/wp-json/wp/v2/users/me/application-passwords" \
  -H "Content-Type: application/json" -d '{"name":"test-limited"}'
```

**Expected:** `{"code":"sudo_blocked",...}`

#### 5.3 Disabled

Set REST policy to Disabled, then same command.
**Expected:** `{"code":"sudo_disabled",...}`

#### 5.4 Unrestricted

Set REST policy to Unrestricted.
**Expected:** 200 with password data.

### 6. XML-RPC Policies

#### 6.1 Limited (Default)

```bash
curl -s -X POST "http://localhost:10018/xmlrpc.php" \
  -H "Content-Type: text/xml" \
  -d '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>'
```

**Expected:** Valid XML listing methods.

#### 6.2 Disabled

**Expected:** XML-RPC fault response.

#### 6.3 Unrestricted

All methods pass through.

### 7. WP-CLI Policies

Use Local's Open Site Shell.

#### 7.1 Limited — Non-Gated

`wp option get blogname` → returns site title.

#### 7.2 Limited — Gated

`wp plugin deactivate hello-dolly` → error, blocked by WP Sudo.

#### 7.3 Disabled

`wp option get blogname` → all CLI operations killed.

#### 7.4 Unrestricted

`wp plugin deactivate hello-dolly` → succeeds, no gating.

#### 7.5 CLI Enforces Cron Policy

With Cron set to Disabled: `wp cron event list` → dies, WP-Cron is disabled.

### 8. Cron Policies

- **Limited:** Non-gated events run; gated operations silently blocked.
- **Disabled:** All cron execution killed at `init`.
- **Unrestricted:** Everything runs normally.

### 9. Settings Page

- Four policy dropdowns with three options each (Disabled/Limited/Unrestricted).
- Session duration validates to 1–15 minute range.
- Help tabs present and populated.
- Gated Actions table visible with categories and surfaces.

### 10. Site Health

- All Limited/Disabled → Good status.
- Any Unrestricted → Recommended notice.

### 11. Admin Bar Timer

- Timer appears on sudo activation, persists across pages.
- Hover shows deactivation link.
- Disappears on expiry.

### 12. Capability Restriction (Single-Site Only)

- Editor cannot use `unfiltered_html` when plugin is active.
- Tamper detection: manually re-adding the capability is auto-reversed.

### 13. Multisite-Specific (Local Only)

- Network Admin Themes: Network Enable → challenge page.
- Network Admin Plugins/Themes: persistent gate notice visible.
- Network Admin Sites: deactivate/archive/spam/delete → challenge page.
- Super Admin grant/revoke → challenge page.
- Network Settings save → challenge page.

### 14. Edge Cases

- **Expired stash:** Wait >5 min on challenge page → "session expired" error.
- **Multiple tabs:** Sudo activated in tab 1 works in tab 2 after reload.
- **Uninstall cleanup:** `wp_sudo_settings` option, `_wp_sudo_*` user meta, and Editor `unfiltered_html` all cleaned up.
