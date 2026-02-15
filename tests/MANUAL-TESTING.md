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
- [ ] The user conduting the tests has a role of Administrator or Super Administrator (on Multisite).

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
5. **Expected:** Session activates, redirected to the admin dashboard.

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

For each test below, log in with an Administrator account, and ensure **no sudo session is active** when you start.

### 2.1 Activate Plugins

1. Go to **Plugins** on single instance sites or any plugins screen on a multisite network — for an individual site or the network itself. 
2. It should not be possible to click the grayed-out (disabled) **(Network) Activate** links on inactive plugins.
3. A notification should be visible at the top of the screen with a link and the keyboard command for starting a sudo session. **Use either method.**
4. **Expected:** Redirected to challenge page with a password prompt followed by a 2FA challenge if 2FA is installed and active. Canceling will redirect back to the originating Plugins screen.
5. **Authenticate.**
6. **Expected:** Redirected back to Plugins on successful reauthentication. It is now possible the activate inactive plugins freely, until the sudo session expires. 

### 2.2 Deactivate Plugins

1. Go to **Plugins** on single instance sites or any plugins screen on a multisite network — for an individual site or the network itself. 
2. It should not be possible to click the grayed-out (disabled) **(Network) Deactivate** links on active plugins.
3. A notification should be visible at the top of the screen with a link and the keyboard command for starting a sudo session. Use either method.
4. **Expected:** Redirected to challenge page with a password prompt followed by a 2FA challenge if 2FA is installed and active. Canceling will redirect back to the originating Plugins screen.
5. **Authenticate.**
6. **Expected:** Redirected back to originating Plugins screen on successful reauthentication. If the latter, it is now possible the deactivate active plugins freely, until the sudo session expires. 

### 2.3 Delete Plugins

1. Go to **Plugins** on single instance sites or the plugins screen for the network root in a multisite network. 
2. Deactivate a plugin first (with sudo active), then let the session expire. It should not be possible to click the grayed-out (disabled) **(Network) Delete** links on inactive plugins.
3. A notification should be visible at the top of the screen with a link and the keyboard command for starting a sudo session. **Use either method.**
4. **Expected:** Redirected to challenge page with a password prompt followed by a 2FA challenge if 2FA is installed and active. Canceling will redirect back to the originating Plugins screen. 
5. **Authenticate.**
6. **Expected:** Redirected back to Plugins when authentication is successful. It is now possible to delete inactive plugins freely, until the sudo session expires. 

### 2.4 Activate / Deactivate Themes

#### Multisite

1. Go to **Appearance > Themes** on the network root in a multisite network. 
2. Click **Network Enable** on any network disabled theme with sudo inactive.
3. **Expected:** Redirected to challenge page with a password prompt followed by a 2FA challenge if 2FA is installed and active. Redirected back to originating Themes page on cancel.
4. **Authenticate.**
5. **Expected:** If authentication is successful, it is now possible to activate inactive themes per site or activate and deactivate network themes freely, until the sudo session expires. 

#### Single Instance or Subsite on Multisite Network

1. Go to **Appearance > Themes** on a single instance site or any single site in a multisite network.
2. It should not be possible to click the grayed-out (disabled) **Activate** button on inactive theme unless sudo is active. A notification should be visible at the top of the screen with a link and the keyboard command for starting a sudo session. **Use either method.**
3. **Expected:** Redirected to challenge page with a password prompt followed by a 2FA challenge if 2FA is installed and active. Redirected back to originating Themes page on cancel. 
4. **Authenticate.** 
5. **Expected:** If authentication is successful, it is now possible to activate inactive themes and deactivate active themes freely, until the sudo session expires. 

### 2.5 Delete User

1. Go to **Users** on any single instance site, a multisite network subsite, or the multisite network users screen.
2. Hover over a non-admin user, click **Delete**.
3. **Expected:** Redirected to confirmation screen. 
4. Click **Confirm Deletion**.
5. **Expected:** Redirected to challenge page with a password prompt followed by a 2FA challenge if 2FA is installed and active. Canceling will redirect back to the originating Users screen.
6. **Authenticate.**
6. **Expected:** If authentication is successful, it is now possible to delete other users freely, until the sudo session expires. 

### 2.6 Change User Role

1. Go to **Users** on a single-instance site or subsite within a multisite network. 
2. Select a user via checkbox, choose a new role from the "Change role
   to" dropdown, click **Change**. This can also be achieved from individual user profile pages, using the role selection dropdown. 
3. **Expected:** Redirected to sudo reauthorization challenge followed by 2FA challenge if one is active. Canceling the process should redirect back to the originating page. *Note that it’s not possible to change your own user role if the result would demote you to a role that can’t re-promote itself to your current level.*
4. **Authenticate.**
5. **Expected:** Redirected to originating page with confirmation notice about changed user role.

### 2.7 Create User

1. Go to **Users > Add New**.
2. Fill in the form and submit.
3. **Expected:** Redirected to sudo reauthorization challenge followed by 2FA challenge if one is active. Canceling the process should redirect back to the originating page. 
4. **Authenticate.**
5. **Expected:** Redirected to originating page with confirmation notice about newly created user.

### 2.8 Change a Critical Site Setting

1. Go to **Settings > General**.
2. Change the **Administration Email Address** (or anything else on this screen) and save.
3. **Expected:** Redirected to sudo reauthorization challenge followed by 2FA challenge if one is active. Canceling the process should redirect back to the originating page. 
4. **Authenticate.**
5. **Expected:** Redirected to originating page with confirmation notice about changed settings.


### 2.9 Change WP Sudo Settings (Self-Protection)

1. Go to **Settings > Sudo** and change any value. Save.
2. **Expected:** Redirected to sudo reauthorization challenge followed by 2FA challenge if one is active. Canceling the process should redirect back to the originating page. 
3. **Authenticate.**
4. **Expected:** Redirected to originating page with confirmation notice about changed settings.

### 2.10 Export Site Data

1. Go to **Tools > Export**.
2. Click **Download Export File**.
3. **Expected:** Redirected to sudo reauthorization challenge followed by 2FA challenge if one is active. Canceling the process should redirect back to the originating page. 
4. **Authenticate.**
5. **Expected:** Redirected to originating page with confirmation notice about export as export file is downloaded.

### 2.11 Edit Plugin/Theme File

1. Go to **Plugins > Plugin File Editor** (or Theme File Editor) if this is availabie — often it is (and should be) disabled.
2. Edit a file and click **Update File**.
3. **Expected:** Redirected to sudo reauthorization challenge followed by 2FA challenge if one is active. Canceling the process should redirect back to the originating page. 
4. **Authenticate.**
5. **Expected:** Redirected to theme/file editor.

### 2.12 Bypass Reauth Challenges with Active Session

1. Activate sudo yourself by any method.
2. Repeat any test above.
3. **Expected:** Action proceeds immediately with no additional reauth challenge as long as you have time remaining in an active sudo session.

---

## 3. AJAX Gating (Plugin and Theme Installers)

### 3.1 Install Plugin via Search (Multisite Network, Subsite, or Single-Instance Site)

1. Ensure no sudo session is active.
2. Go to **Plugins > Add Plugin**.
3. Search for a plugin (e.g. "Classic Editor") and confirm all **Install Now** buttons are grayed out and inoperable.
4. A notice at the top of the screen should read: “Installing, activating, updating, and deleting themes and plugins requires an active sudo session. Confirm your identity [link] or press Cmd+Shift+S to start one.” Follow either method.
5. **Expected:** Redirected to sudo reauthorization challenge followed by 2FA challenge if one is active. Canceling the process should redirect back to the originating page. 
6. **Authenticate.** 
7. **Expected:** Redirected to originating screen. **Install Now** buttons are now operable. Click one.
8. **Expected:** Plugin installs successfully with success message. 

### 3.2 Delete Theme via AJAX (Multisite Network, Subsite, or Single-Instance Site)

1. Go to **Appearance > Themes**, and look for an inactive theme. Any visible **Delete** links should be grayed-out and inoperable. 
2. A notice at the top of the screen should read: “Installing, activating, updating, and deleting themes and plugins requires an active sudo session. Confirm your identity [link] or press Cmd+Shift+S to start one.” Follow either method.
3. **Expected:** Redirected to sudo reauthorization challenge followed by 2FA challenge if one is active. Canceling the process should redirect back to the originating page. 
4. **Authenticate.** 
5. **Expected:** Redirected to originating screen. **Delete** links are operable now. Click one.
8. **Expected:** Theme is deleted with success confirmation message. 

---

## 4. REST API — Cookie Auth (Browser)

These test the cookie-authenticated REST path, which is how the admin
UI communicates with the REST API.

### 4.1 Create Application Password (Profile Page)

1. Ensure no sudo session is active.
2. Go to **Users > Profile**, scroll to Application Passwords.
3. Enter a name, click **Add New Application Password**.
4. **Expected:** Error notice: "This action (Create application password) requires reauthentication. Please confirm your identity."
5. Activate sudo (Cmd+Shift+S or challenge page).
6. Retry step 3.
7. **Expected:** Password is created successfully. The new password is
   displayed.

### 4.2 `curl` with Cookie Auth

```bash
# 1. Log in and capture cookies
curl -s -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -X POST "http://localhost:8883/wp-login.php" \
  -d 'log=admin&pwd=YOUR_PASSWORD&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F&testcookie=1' \
  -H "Cookie: wordpress_test_cookie=WP+Cookie+check" -o /dev/null

# 2. Get the REST nonce
NONCE=$(curl -s -b /tmp/wp-cookies.txt "http://localhost:8883/wp-admin/" \
  | grep -oE '"nonce":"[a-f0-9]+"' | grep -oE '[a-f0-9]+$')

# 3. Test gated action WITHOUT sudo (should fail)
curl -s -b /tmp/wp-cookies.txt \
  -H "X-WP-Nonce: $NONCE" \
  -H "Content-Type: application/json" \
  -X POST "http://localhost:8883/wp-json/wp/v2/users/me/application-passwords" \
  -d '{"name":"test"}'
# Expected: {"code":"sudo_required", ...}

# 4. Activate sudo via challenge AJAX
CNONCE=$(curl -s -b /tmp/wp-cookies.txt \
  "http://localhost:8883/wp-admin/admin.php?page=wp-sudo-challenge" \
  | grep -oE 'wpSudoChallenge[^<]+' | grep -oE '"nonce":"[^"]+' \
  | grep -oE '[a-f0-9]+$')
curl -s -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -X POST "http://localhost:8883/wp-admin/admin-ajax.php" \
  -d "action=wp_sudo_challenge_auth&_ajax_nonce=$CNONCE&password=YOUR_PASSWORD"
# Expected: {"success":true,"data":{"code":"authenticated"}}

# 5. Retry gated action WITH sudo (should succeed)
curl -s -b /tmp/wp-cookies.txt \
  -H "X-WP-Nonce: $NONCE" \
  -H "Content-Type: application/json" \
  -X POST "http://localhost:8883/wp-json/wp/v2/users/me/application-passwords" \
  -d '{"name":"test-with-sudo"}'
# Expected: 200 with password data
```

---

## 5. REST API — App Password Policies

These tests require `curl` against a site that supports app-password
auth (Local, not Studio). Replace `USER:APP_PASS` with your
Application Password credentials.

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

**Expected:** Command exits immediately with no output. All CLI
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

**Expected:** Dies immediately. The CLI gate enforces the cron policy
for `wp cron` subcommands.

---

## 8. Settings Page

### 8.1 Three-Option Policy Dropdowns

1. Go to **Settings > Sudo**.
2. **Expected:** Four policy fields, each with three options: Disabled,
   Limited (default), Unrestricted.
3. Change a policy, save, reload.
4. **Expected:** The saved value persists.

### 8.2 Session Duration

1. Set session duration to a valid value (1-15 minutes). Save.
2. **Expected:** Saved successfully.
3. Try setting it to 0 or 99.
4. **Expected:** Sanitized back to the valid range.

### 8.3 Help Tabs

1. Click the **Help** button (top-right of Settings > Sudo).
2. **Expected:** Tabs appear for Settings, Extending, and Audit Hooks.
   The Settings tab describes the three policy modes. No references to
   `--sudo` flag.

### 8.4 Gated Actions Table

1. Scroll down on Settings > Sudo.
2. **Expected:** A table listing all gated actions grouped by category,
   showing which surfaces (Admin, AJAX, REST) each action covers.

---

## 9. Site Health

1. Go to **Tools > Site Health**.
2. **Expected:** A "WP Sudo Entry Point Policies" test result.

### 9.1 All Limited or Disabled

- Set all four policies to Limited (or Disabled).
- **Expected:** Status is **Good** (green/blue shield).

### 9.2 Any Unrestricted

- Set one or more policies to Unrestricted.
- **Expected:** Status is **Recommended** (orange shield) with a
  message identifying which policies are unrestricted.

---

## 10. Admin Bar Timer

1. Activate sudo.
2. **Expected:** A countdown timer appears in the admin bar showing
   remaining seconds.
3. Navigate between admin pages.
4. **Expected:** Timer persists and updates.
5. Let the session expire.
6. **Expected:** Timer disappears.

---

## 11. Capability Restriction (Single-Site)

> Skip on multisite — WordPress core already restricts
> `unfiltered_html` to Super Admins.

### 11.1 Editor Role on Activation

1. Activate WP Sudo.
2. Log in as an Editor.
3. Create a post with HTML like `<script>alert(1)</script>`.
4. **Expected:** KSES strips the script tag. Editors do not have
   `unfiltered_html`.

### 11.2 Tamper Detection

1. Manually add `unfiltered_html` back to the Editor role (via a
   snippet or direct DB edit on `wp_user_roles`).
2. Load any admin page.
3. **Expected:** The capability is automatically stripped. The
   `wp_sudo_capability_tampered` action fires (visible in debug log if
   an audit logger is connected).

---

## 12. Multisite-Specific (Local Only)

### 12.1 Network Admin Gating

1. Go to **Network Admin > Themes**.
2. Click **Network Enable** on a theme.
3. **Expected:** Challenge with "Network enable theme."

### 12.2 Site Management

1. Go to **Network Admin > Sites**.
2. Try to deactivate, archive, spam, or delete a site.
3. **Expected:** Challenge page for each action.

### 12.3 Super Admin Grant

1. Go to **Network Admin > Users**, edit a user.
2. Toggle the Super Admin checkbox and save.
3. **Expected:** Challenge with "Grant or revoke super admin."

### 12.4 Network Settings

1. Go to **Network Admin > Settings** and save.
2. **Expected:** Challenge with "Change network settings."

---

## 13. Edge Cases

### 13.1 Expired Stash

1. Trigger a gated action (get redirected to challenge page).
2. Wait more than 5 minutes (stash TTL).
3. Enter your password.
4. **Expected:** Error message: "Your challenge session has expired.
   Please try again."

### 13.2 Multiple Tabs

1. Open two admin tabs.
2. Activate sudo in tab 1.
3. Perform a gated action in tab 2 (reload the page first).
4. **Expected:** Action succeeds — the session is shared via cookie +
   user meta.

### 13.3 Uninstall Cleanup

1. Deactivate and delete WP Sudo.
2. Check the database:
   - `wp_options` should not contain `wp_sudo_settings`.
   - User meta should not contain keys starting with `_wp_sudo_`.
   - Editor role should have `unfiltered_html` restored.
3. **Expected:** All WP Sudo data is cleaned up.
