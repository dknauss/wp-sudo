# WP Sudo Manual Testing Guide

Manual verification tests for WP Sudo v2.2.0+. These complement the
automated PHPUnit suite (`composer test`) and should be run against a
real WordPress environment before each release.

## Environments

| Name | Type | URL | Notes |
|------|------|-----|-------|
| Studio | Single-site | `http://localhost:8883` | PHP built-in server; app-password `curl` tests fail (server strips `Authorization` header) |
| Local | Multisite (subdirectory) | `http://localhost:10018` | nginx; full app-password support; use Local's **Open Site Shell** for WP-CLI |

> **Tip:** Studio is best for admin UI testing. Local is best for CLI,
> cron, and app-password `curl` tests. Run multisite-specific tests
> (network admin rules) only on Local.

---

## Prerequisites

- [ ] Plugin is active on the test site.
- [ ] A second test plugin is installed (e.g. Hello Dolly) for
      activate/deactivate/delete tests.
- [ ] An Application Password exists for `curl` tests (Users > Profile >
      Application Passwords). Note the username and password.
- [ ] A second user account exists for role-change and delete-user tests.
- [ ] Browser DevTools are open to the **Console** and **Network** tabs
      for observing errors and REST responses.
- [ ] The user conducting the tests has a role of Administrator or Super
      Administrator (on Multisite).

---

## 1. Sudo Session Lifecycle

### 1.1 Activate via Challenge Page

1. Ensure no sudo session is active (admin bar has no timer).
2. Navigate to **Settings > Sudo**.
3. Click **Save Changes**.
4. **Expected:** Redirected to the challenge page. The page shows
   "Confirm Your Identity" with the action label "Change WP Sudo
   settings."
5. Enter your password and click **Confirm & Continue**.
6. **Expected:** Redirected back to Settings > Sudo. The form is
   submitted. The admin bar shows a countdown timer.

### 1.2 Activate via Keyboard Shortcut

1. Ensure no sudo session is active.
2. Press **Ctrl+Shift+S** (Windows/Linux) or **Cmd+Shift+S** (Mac).
3. **Expected:** Navigated to the challenge page in session-only mode
   (no stash key, action label says "Activate sudo session").
4. Enter your password.
5. **Expected:** Session activates, redirected to the referring admin
   page (or the dashboard if there is no referrer).

### 1.3 Shortcut Flash During Active Session

1. With a sudo session active, press **Ctrl+Shift+S** / **Cmd+Shift+S**.
2. **Expected:** The admin bar timer flashes briefly. No navigation
   occurs.

### 1.4 Session Expiry

1. Set session duration to 1 minute in Settings > Sudo.
2. Activate sudo.
3. Wait for the timer to count down to zero.
4. **Expected:** Timer disappears from admin bar. The next gated action
   triggers a challenge.

### 1.5 Rate Limiting

1. Open the challenge page.
2. Enter an incorrect password 5 times.
3. **Expected:** After attempt 4 there is a noticeable delay (~2s).
   After attempt 5, the form is disabled and shows a lockout message
   with a countdown (~5 minutes).

---

## 2. Admin UI Gating (Stash-Challenge-Replay)

These tests verify the stash-challenge-replay flow for admin UI
requests. For each test below, log in with an Administrator account and
ensure **no sudo session is active** when you start.

**How it works:** When action links on plugin/theme screens are
*disabled* (grayed out), you cannot click them directly. You must first
activate a sudo session via the persistent notice link or the keyboard
shortcut. Once sudo is active, the links become operable and actions
proceed without further challenges until the session expires.

For form-based actions (user operations, settings changes, exports),
submitting the form triggers a redirect to the challenge page. After
authentication, the original POST request is replayed automatically.

### 2.1 Activate Plugin

1. Go to **Plugins** on a single-site install, or any Plugins screen on
   a multisite network (subsite or Network Admin).
2. **Expected:** Activate links on inactive plugins are grayed out
   (disabled). They cannot be clicked.
3. A persistent notice is visible at the top of the screen with a link
   to the challenge page and the keyboard shortcut for starting a sudo
   session.
4. Use the notice link or keyboard shortcut to activate a sudo session.
5. Authenticate on the challenge page (password + 2FA if active).
6. **Expected:** Redirected back to the originating Plugins screen.
   Activate links are now operable.
7. Click **Activate** (or **Network Activate**) on a plugin.
8. **Expected:** Plugin activates immediately with no further challenge.

### 2.2 Deactivate Plugin

1. Go to **Plugins** on a single-site install, or any Plugins screen on
   a multisite network (subsite or Network Admin).
2. **Expected:** Deactivate links on active plugins are grayed out
   (disabled). They cannot be clicked.
3. A persistent notice is visible at the top of the screen.
4. Activate a sudo session via the notice link or keyboard shortcut.
5. Authenticate on the challenge page.
6. **Expected:** Redirected back to the originating Plugins screen.
   Deactivate links are now operable.
7. Click **Deactivate** (or **Network Deactivate**) on a plugin.
8. **Expected:** Plugin deactivates immediately with no further
   challenge.

### 2.3 Delete Plugin

1. Deactivate a plugin first (with sudo active), then let the session
   expire.
2. Go to **Plugins** on a single-site install, or the Plugins screen
   on the network root in a multisite network.
3. **Expected:** Delete links on inactive plugins are grayed out
   (disabled). They cannot be clicked.
4. Activate a sudo session via the persistent notice link or keyboard
   shortcut.
5. Authenticate on the challenge page.
6. **Expected:** Redirected back to the originating Plugins screen.
   Delete links are now operable.
7. Click **Delete** on an inactive plugin.
8. **Expected:** Plugin is deleted with no further challenge.

### 2.4 Themes

#### Multisite Network Admin

1. Go to **Appearance > Themes** in **Network Admin**.
2. Click **Network Enable** on a network-disabled theme.
3. **Expected:** Redirected to the challenge page. The action label
   shows "Network enable theme." Clicking Cancel returns to the
   Network Admin Themes screen.
4. Authenticate (password + 2FA if active).
5. **Expected:** Theme is network-enabled. You are redirected back to
   the Network Admin Themes screen. While sudo is active, you can
   freely enable and disable network themes.

#### Single-Site or Subsite

1. Go to **Appearance > Themes** on a single-site install or any
   subsite in a multisite network.
2. **Expected:** The **Activate** button on inactive themes is grayed
   out (disabled). It cannot be clicked. A persistent notice is visible
   at the top of the screen.
3. Activate a sudo session via the notice link or keyboard shortcut.
4. Authenticate on the challenge page.
5. **Expected:** Redirected back to the originating Themes screen.
   The Activate button is now operable.
6. Click **Activate** on an inactive theme.
7. **Expected:** Theme activates with no further challenge.

### 2.5 Delete User

1. Go to **Users** on a single-site install, a multisite subsite, or
   Network Admin > Users.
2. Hover over a non-admin user and click **Delete**.
3. **Expected:** WordPress shows a confirmation screen ("Delete Users"
   with content reassignment options).
4. Click **Confirm Deletion**.
5. **Expected:** Redirected to the challenge page. The action label
   shows "Delete user." Clicking Cancel returns to the originating
   Users screen.
6. Authenticate (password + 2FA if active).
7. **Expected:** The delete operation is replayed. User is deleted. You
   are redirected back to the Users screen.

### 2.6 Change User Role (Bulk Action)

1. Go to **Users** on a single-site install or a subsite within a
   multisite network.
2. Select a user via checkbox, choose a new role from the "Change role
   to" dropdown, and click **Change**.
3. **Expected:** Redirected to the challenge page. The action label
   shows "Change user role." Clicking Cancel returns to the originating
   Users screen.
4. Authenticate.
5. **Expected:** The role change is replayed. You are redirected back
   to the Users screen with a confirmation notice.

> **Note:** You cannot change your own role if the result would demote
> you to a role that cannot re-promote itself.

### 2.7 Change User Role (Profile Page)

1. Go to **Users**, then click **Edit** on another user to open their
   profile page.
2. Change the **Role** dropdown and click **Update User**.
3. **Expected:** Redirected to the challenge page. The action label
   shows "Change user role." Clicking Cancel returns to the user's
   profile page.
4. Authenticate.
5. **Expected:** The profile update is replayed. You are redirected
   back to the user's profile page with a confirmation notice.

### 2.8 Create User

1. Go to **Users > Add New**.
2. Fill in the form and click **Add New User**.
3. **Expected:** Redirected to the challenge page. The action label
   shows "Create new user." Clicking Cancel returns to the Add New
   User page.
4. Authenticate.
5. **Expected:** The user creation is replayed. You are redirected back
   to the Users screen with a confirmation notice.

### 2.9 Change a Critical Site Setting

1. Go to **Settings > General**.
2. Change the **Administration Email Address** (or any other field)
   and click **Save Changes**.
3. **Expected:** Redirected to the challenge page. The action label
   shows "Change critical site settings." Clicking Cancel returns to
   the Settings page.
4. Authenticate.
5. **Expected:** The settings save is replayed. You are redirected
   back to the Settings page with a confirmation notice.

### 2.10 Change WP Sudo Settings (Self-Protection)

1. Go to **Settings > Sudo** and change any value. Click **Save
   Changes**.
2. **Expected:** Redirected to the challenge page. The action label
   shows "Change WP Sudo settings." Clicking Cancel returns to the
   Sudo Settings page.
3. Authenticate.
4. **Expected:** The settings save is replayed. You are redirected
   back to the Sudo Settings page with a confirmation notice.

### 2.11 Export Site Data

1. Go to **Tools > Export**.
2. Click **Download Export File**.
3. **Expected:** Redirected to the challenge page. The action label
   shows "Export site data." Clicking Cancel returns to the Export
   page.
4. Authenticate.
5. **Expected:** The export is replayed. The export file downloads.

### 2.12 Edit Plugin/Theme File

1. Go to **Plugins > Plugin File Editor** (or **Appearance > Theme
   File Editor**), if available. This feature is often (and should be)
   disabled.
2. Edit a file and click **Update File**.
3. **Expected:** Redirected to the challenge page. Clicking Cancel
   returns to the file editor.
4. Authenticate.
5. **Expected:** The file edit is replayed. You are redirected back to
   the file editor.

### 2.13 Bypass Challenges with Active Session

1. Activate a sudo session by any method.
2. Repeat any test above.
3. **Expected:** Actions proceed immediately with no challenge as long
   as you have time remaining in an active sudo session.

### 2.14 Cancel Returns to Originating Page

1. Trigger a stash-challenge-replay flow from any page (e.g. save
   Settings > General, delete a user from Users, change a role from a
   user profile page).
2. On the challenge page, click **Cancel**.
3. **Expected:** You are returned to the page you started from, not
   the main dashboard.

---

## 3. AJAX Gating (Plugin and Theme Installers)

These tests verify AJAX-based gating for the plugin/theme installer
screens. Buttons are disabled when no sudo session is active. If an
AJAX request is somehow sent, it receives a JSON error and the button
resets.

### 3.1 Install Plugin via Search

1. Ensure no sudo session is active.
2. Go to **Plugins > Add New** (on single-site, subsite, or Network
   Admin).
3. Search for a plugin (e.g. "Classic Editor").
4. **Expected:** All **Install Now** buttons are grayed out and cannot
   be clicked.
5. A persistent notice at the top of the screen reads: "Installing,
   activating, updating, and deleting themes and plugins requires an
   active sudo session. Confirm your identity or press Cmd+Shift+S to
   start one."
6. Use the notice link or keyboard shortcut to activate a sudo session.
7. Authenticate on the challenge page.
8. **Expected:** Redirected back to the plugin search screen. **Install
   Now** buttons are now operable.
9. Click **Install Now** on a plugin.
10. **Expected:** Plugin installs successfully via AJAX with a success
    message.

### 3.2 Delete Theme via AJAX

1. Ensure no sudo session is active.
2. Go to **Appearance > Themes** (on single-site, subsite, or Network
   Admin) and locate an inactive theme.
3. **Expected:** **Delete** links are grayed out and cannot be clicked.
4. A persistent notice at the top of the screen provides a link and
   keyboard shortcut for starting a sudo session.
5. Activate a sudo session.
6. Authenticate on the challenge page.
7. **Expected:** Redirected back to the Themes screen. **Delete** links
   are now operable.
8. Click **Delete** on an inactive theme.
9. **Expected:** Theme is deleted via AJAX with a success confirmation.

### 3.3 Update Plugin via AJAX

1. Ensure no sudo session is active and a plugin has an available
   update.
2. Go to **Plugins** or **Dashboard > Updates**.
3. **Expected:** Update links/buttons are grayed out and cannot be
   clicked.
4. Activate a sudo session, authenticate, and return.
5. Click **Update Now** on a plugin.
6. **Expected:** Plugin updates successfully via AJAX.

---

## 4. REST API — Cookie Auth (Browser)

These test the cookie-authenticated REST path, which is how the admin
UI communicates with the REST API.

### 4.1 Create Application Password (Profile Page)

1. Ensure no sudo session is active.
2. Go to **Users > Profile**, scroll to Application Passwords.
3. Enter a name, click **Add New Application Password**.
4. **Expected:** Error notice: "This action (Create application
   password) requires reauthentication. Press Cmd+Shift+S to start a
   sudo session, then try again."
5. Press **Cmd+Shift+S** (or Ctrl+Shift+S on Windows/Linux) to open
   the challenge page. Authenticate.
6. Return to the profile page and retry step 3.
7. **Expected:** Password is created successfully. The new password is
   displayed.

> **Note:** The profile page has no persistent gate notice and no
> disabled buttons. The REST error message is the only indication that
> sudo is required. The keyboard shortcut is the primary way to
> activate sudo from this page.

### 4.2 `curl` with Cookie Auth

> **Note:** Run this test against **Local** (`localhost:10018`), not
> Studio. Studio's PHP built-in server handles cookies and redirects
> inconsistently across sequential `curl` requests, leading to spurious
> `rest_not_logged_in` errors. Local's nginx stack works reliably.

```bash
# 0. Clean up stale cookies
rm -f /tmp/wp-cookies.txt

# 1. Log in and capture cookies (follow the redirect)
curl -s -L -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -X POST "http://localhost:10018/wp-login.php" \
  -d 'log=YOUR_USERNAME&pwd=YOUR_PASSWORD&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F&testcookie=1' \
  -H "Cookie: wordpress_test_cookie=WP+Cookie+check" -o /dev/null

# 2. Get the REST nonce from wp.apiFetch.createNonceMiddleware()
NONCE=$(curl -s -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  "http://localhost:10018/wp-admin/" \
  | grep -oE 'createNonceMiddleware\( "[a-f0-9]+" \)' \
  | grep -oE '[a-f0-9]{6,}')
echo "Nonce: $NONCE"  # Verify this is not empty

# 3. Test gated action WITHOUT sudo (should fail)
curl -s -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -H "X-WP-Nonce: $NONCE" \
  -H "Content-Type: application/json" \
  -X POST "http://localhost:10018/wp-json/wp/v2/users/me/application-passwords" \
  -d '{"name":"test"}'
# Expected: {"code":"sudo_required", ...}

# 4. Activate sudo via challenge AJAX
CNONCE=$(curl -s -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  "http://localhost:10018/wp-admin/admin.php?page=wp-sudo-challenge" \
  | grep -oE 'wpSudoChallenge = \{[^}]+\}' \
  | grep -oE '"nonce":"[^"]+"' | grep -oE '[a-f0-9]{6,}')
curl -s -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -X POST "http://localhost:10018/wp-admin/admin-ajax.php" \
  -d "action=wp_sudo_challenge_auth&_wpnonce=$CNONCE&password=YOUR_PASSWORD"
# Expected: {"success":true,"data":{"code":"authenticated"}}

# 5. Retry gated action WITH sudo (should succeed)
curl -s -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -H "X-WP-Nonce: $NONCE" \
  -H "Content-Type: application/json" \
  -X POST "http://localhost:10018/wp-json/wp/v2/users/me/application-passwords" \
  -d '{"name":"test-with-sudo"}'
# Expected: 200 with password data
```

---

## 5. REST API — App Password Policies

These tests require `curl` against a site that supports app-password
auth (Local, not Studio). Replace `USER:APP_PASS` with your
Application Password credentials (username and app password separated
by a colon). Remove the spaces from the app password — WordPress
displays them in groups for readability, but `curl` Basic auth needs
them stripped (e.g. `test:2U28adQDxMf8mIzcVWzaxuMd`).

### 5.1 Non-Gated Endpoint (All Policies)

```bash
curl -s -u "USER:APP_PASS" \
  "http://localhost:10018/wp-json/wp/v2/users/me"
```

**Expected for all policies:** 200 with user data. Non-gated endpoints
are never blocked.

### 5.2 Limited (Default)

```bash
curl -s -u "USER:APP_PASS" \
  -X POST "http://localhost:10018/wp-json/wp/v2/users/me/application-passwords" \
  -H "Content-Type: application/json" \
  -d '{"name":"test-limited"}'
```

**Expected:**
```json
{"code":"sudo_blocked","message":"This operation requires sudo and cannot be performed via Application Passwords.","data":{"status":403}}
```

### 5.3 Disabled

Set REST API policy to Disabled in Settings > Sudo, then:

```bash
curl -s -u "USER:APP_PASS" \
  -X POST "http://localhost:10018/wp-json/wp/v2/users/me/application-passwords" \
  -H "Content-Type: application/json" \
  -d '{"name":"test-disabled"}'
```

**Expected:**
```json
{"code":"sudo_disabled","message":"This REST API operation is disabled by WP Sudo policy.","data":{"status":403}}
```

### 5.4 Unrestricted

Set REST API policy to Unrestricted, then:

```bash
curl -s -u "USER:APP_PASS" \
  -X POST "http://localhost:10018/wp-json/wp/v2/users/me/application-passwords" \
  -H "Content-Type: application/json" \
  -d '{"name":"test-unrestricted"}'
```

**Expected:** 200 with password data. The action passes through with no
checks.

> **Cleanup:** Delete any test app passwords and restore the policy to
> Limited after testing.

---

## 6. XML-RPC Policies

### 6.1 Limited (Default)

```bash
curl -s -X POST "http://localhost:10018/xmlrpc.php" \
  -H "Content-Type: text/xml" \
  -d '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>'
```

**Expected:** A valid XML response listing available methods.
Non-gated methods work normally under Limited.

### 6.2 Disabled

Set XML-RPC policy to Disabled, then:

```bash
curl -s -X POST "http://localhost:10018/xmlrpc.php" \
  -H "Content-Type: text/xml" \
  -d '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>'
```

**Expected:** XML-RPC fault response (`<fault>`) indicating the service
is disabled.

### 6.3 Unrestricted

Set XML-RPC policy to Unrestricted. All methods pass through without
gating.

---

## 7. WP-CLI Policies

Use Local's **Open Site Shell** (right-click site in Local app).

### 7.1 Limited (Default) — Non-Gated Command

```bash
wp option get blogname
```

**Expected:** Returns the site title. Non-gated commands work normally.

### 7.2 Limited — Gated Command

```bash
wp plugin deactivate hello-dolly
```

**Expected:** Error: dies with a message indicating the action is
blocked by WP Sudo.

### 7.3 Disabled

Set CLI policy to Disabled, then:

```bash
wp option get blogname
```

**Expected:** Command exits immediately with an error message. All CLI
operations are killed.

### 7.4 Unrestricted

Set CLI policy to Unrestricted, then:

```bash
wp plugin deactivate hello-dolly
```

**Expected:** Plugin deactivated successfully. No gating checks.

### 7.5 CLI Enforces Cron Policy

With CLI set to any value and Cron set to Disabled:

```bash
wp cron event list
```

**Expected:** Dies immediately with a message that WP-Cron is disabled.
The CLI gate enforces the Cron policy for `wp cron` subcommands.

---

## 8. Cron Policies

### 8.1 Limited (Default)

Non-gated scheduled events run normally. Any gated operation triggered
by a cron event is silently blocked (the event exits without producing
an error).

### 8.2 Disabled

Set Cron policy to Disabled. All cron execution is killed immediately
at `init`. This covers both WP-Cron (page-load trigger) and server-
level cron jobs hitting `wp-cron.php` directly.

### 8.3 Unrestricted

Set Cron policy to Unrestricted. All scheduled events run as if WP
Sudo is not installed.

---

## 9. Settings Page

### 9.1 Three-Option Policy Dropdowns

1. Go to **Settings > Sudo**.
2. **Expected:** Four policy fields, each with three options: Disabled,
   Limited (default), Unrestricted.
3. Change a policy, save, reload.
4. **Expected:** The saved value persists.

### 9.2 Session Duration

1. Set session duration to a valid value (1-15 minutes). Save.
2. **Expected:** Saved successfully.
3. Try setting it to 0 or 99.
4. **Expected:** Sanitized back to the valid range.

### 9.3 Help Tabs

1. Click the **Help** button (top-right of Settings > Sudo).
2. **Expected:** Tabs appear for Settings, Extending, and Audit Hooks.
   The Settings tab describes the three policy modes. No references to
   `--sudo` flag.

### 9.4 Gated Actions Table

1. Scroll down on Settings > Sudo.
2. **Expected:** A table listing all gated actions grouped by category,
   showing which surfaces (Admin, AJAX, REST) each action covers.

---

## 10. Site Health

1. Go to **Tools > Site Health**.
2. **Expected:** A "WP Sudo Entry Point Policies" test result.

### 10.1 All Limited or Disabled

- Set all four policies to Limited (or Disabled).
- **Expected:** Status is **Good** (green/blue shield).

### 10.2 Any Unrestricted

- Set one or more policies to Unrestricted.
- **Expected:** Status is **Recommended** (orange shield) with a
  message identifying which policies are unrestricted.

---

## 11. Admin Bar Timer

1. Activate sudo.
2. **Expected:** A countdown timer appears in the admin bar showing
   remaining seconds.
3. Navigate between admin pages.
4. **Expected:** Timer persists and updates.
5. Hover over the timer.
6. **Expected:** A deactivation link is visible.
7. Let the session expire.
8. **Expected:** Timer disappears.

---

## 12. Capability Restriction (Single-Site)

> Skip on multisite — WordPress core already restricts
> `unfiltered_html` to Super Admins.

### 12.1 Editor Role on Activation

1. Activate WP Sudo.
2. Log in as an Editor.
3. Create a post with HTML like `<script>alert(1)</script>`.
4. **Expected:** KSES strips the script tag. Editors do not have
   `unfiltered_html`.

### 12.2 Tamper Detection

1. Manually add `unfiltered_html` back to the Editor role (via a
   snippet or direct DB edit on `wp_user_roles`).
2. Load any admin page.
3. **Expected:** The capability is automatically stripped. The
   `wp_sudo_capability_tampered` action fires (visible in debug log if
   an audit logger is connected).

---

## 13. Multisite-Specific (Local Only)

### 13.1 Network Admin Gating

1. Go to **Network Admin > Themes**.
2. Click **Network Enable** on a theme.
3. **Expected:** Redirected to challenge page with "Network enable
   theme." Cancel returns to the Network Admin Themes screen.

### 13.2 Network Admin Persistent Notice

1. Go to **Network Admin > Plugins** with no sudo session active.
2. **Expected:** Persistent notice at top: "Installing, activating,
   updating, and deleting themes and plugins requires an active sudo
   session. Confirm your identity or press Cmd+Shift+S to start one."
3. Go to **Network Admin > Themes**.
4. **Expected:** Same persistent notice appears.

### 13.3 Site Management

1. Go to **Network Admin > Sites**.
2. Try to deactivate, archive, spam, or delete a site.
3. **Expected:** Each action redirects to the challenge page. Cancel
   returns to the Sites screen.

### 13.4 Super Admin Grant

1. Go to **Network Admin > Users**, edit a user.
2. Toggle the Super Admin checkbox and save.
3. **Expected:** Redirected to challenge page with "Grant or revoke
   super admin." Cancel returns to the user edit page.

### 13.5 Network Settings

1. Go to **Network Admin > Settings** and save.
2. **Expected:** Redirected to challenge page with "Change network
   settings." Cancel returns to the Network Settings page.

---

## 14. Edge Cases

### 14.1 Expired Stash

1. Trigger a stash-challenge-replay flow (e.g. save Settings > General).
2. Wait more than 5 minutes on the challenge page (stash TTL expires).
3. Enter your password.
4. **Expected:** Error message: "Your challenge session has expired.
   Please try again."

### 14.2 Multiple Tabs

1. Open two admin tabs.
2. Activate sudo in tab 1.
3. Reload tab 2, then perform a gated action.
4. **Expected:** Action succeeds — the session is shared via cookie +
   user meta.

### 14.3 Uninstall Cleanup

1. Deactivate and delete WP Sudo.
2. Check the database:
   - `wp_options` should not contain `wp_sudo_settings`.
   - User meta should not contain keys starting with `_wp_sudo_`.
   - Editor role should have `unfiltered_html` restored.
3. **Expected:** All WP Sudo data is cleaned up.
