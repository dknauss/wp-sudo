# WP Sudo Manual Testing Guide

Manual verification tests for WP Sudo v2.5.2+. These complement the
automated PHPUnit suite (`composer test`) and should be run against a
real WordPress environment before each release.

## Environments

Set up at least two local dev sites — one single-site and one multisite
(subdirectory). Use any local WordPress development tool (Studio, Local,
wp-env, DDEV, etc.).

| Name | Type | Example URL |
|------|------|-------------|
| Single-site | Single-site install | `https://your-single-site.local/` |
| Multisite | Multisite (subdirectory) | `https://your-multisite.local/` |

> **Tip:** Use the single-site for admin UI and challenge-page testing.
> Use the multisite for network admin rules (section 13), WP-CLI, and
> app-password `curl` tests.

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
   occurs. (Respects `prefers-reduced-motion`.)

### 1.4 Session Expiry

1. Set session duration to 1 minute in Settings > Sudo.
2. Activate sudo.
3. Wait for the timer to count down to zero.
4. **Expected:** Timer disappears from admin bar. Page auto-reloads.
   The next gated action triggers a challenge.

### 1.5 Rate Limiting

1. Open the challenge page.
2. Enter an incorrect password 5 times.
3. **Expected:** After attempt 4 there is a noticeable delay (~2s).
   After attempt 5, the form is disabled and shows a lockout message
   with a countdown (~5 minutes).
4. **Accessibility:** Lockout countdown announces to screen readers at
   30-second intervals.

### 1.6 Two-Factor Authentication

> Requires the [Two Factor](https://wordpress.org/plugins/two-factor/)
> plugin with at least one method configured for the test user.

1. Ensure no sudo session is active.
2. Trigger any gated action to reach the challenge page.
3. Enter your password and click **Confirm & Continue**.
4. **Expected:** A second step appears requesting your verification
   code (TOTP, email, backup code, etc.).
5. Enter the correct code.
6. **Expected:** Session activates, original action is replayed.

---

## 2. Admin UI Gating (Stash-Challenge-Replay)

These tests verify the stash-challenge-replay flow. For each test,
log in with an Administrator account and ensure **no sudo session is
active** when you start.

**How it works:** Action links on plugin/theme screens are *disabled*
(grayed out) when no sudo session is active. A persistent notice links
to the challenge page. After authentication, links become operable and
actions proceed without further challenges until the session expires.

For form-based actions (user operations, settings changes, exports),
submitting the form triggers a redirect to the challenge page. After
authentication, the original POST request is replayed automatically.

### 2.1 Activate Plugin

1. Go to **Plugins**.
2. **Expected:** Activate links on inactive plugins are grayed out
   (disabled). A persistent notice links to the challenge page.
3. Activate a sudo session via the notice link or keyboard shortcut.
4. **Expected:** Redirected back to Plugins. Activate links are now
   operable.
5. Click **Activate** on a plugin.
6. **Expected:** Plugin activates immediately with no further challenge.

### 2.2 Deactivate Plugin

1. Go to **Plugins** with no sudo session active.
2. **Expected:** Deactivate links on active plugins are grayed out.
3. Activate a sudo session.
4. Click **Deactivate** on a plugin.
5. **Expected:** Plugin deactivates immediately.

### 2.3 Delete Plugin

1. Deactivate a plugin first (with sudo active), then let the session
   expire.
2. Go to **Plugins**.
3. **Expected:** Delete links on inactive plugins are grayed out.
4. Activate a sudo session.
5. Click **Delete** on an inactive plugin.
6. **Expected:** Plugin is deleted.

### 2.4 Switch Theme

1. Go to **Appearance > Themes** with no sudo session active.
2. **Expected:** The **Activate** button on inactive themes is grayed
   out. A persistent notice is visible.
3. Activate a sudo session.
4. Click **Activate** on an inactive theme.
5. **Expected:** Theme activates.

### 2.5 Delete User

1. Go to **Users**, hover over a non-admin user, click **Delete**.
2. WordPress shows a confirmation screen with content reassignment
   options.
3. Click **Confirm Deletion**.
4. **Expected:** Redirected to the challenge page. The action label
   shows "Delete user."
5. Authenticate.
6. **Expected:** User is deleted. Redirected back to Users.

### 2.6 Change User Role (Bulk Action)

1. Go to **Users**. Select a user via checkbox, choose a new role from
   the "Change role to" dropdown, click **Change**.
2. **Expected:** Redirected to the challenge page. Action label shows
   "Change user role."
3. Authenticate.
4. **Expected:** Role change is replayed. Redirected back to Users.

### 2.7 Change User Role (Profile Page)

1. Go to **Users**, click **Edit** on another user.
2. Change the **Role** dropdown, click **Update User**.
3. **Expected:** Redirected to challenge page. Action label shows
   "Change user role."
4. Authenticate.
5. **Expected:** Profile update is replayed.

### 2.8 Create User

1. Go to **Users > Add New**.
2. Fill in the form and click **Add New User**.
3. **Expected:** Redirected to challenge page. Action label shows
   "Create new user."
4. Authenticate.
5. **Expected:** User is created.

### 2.9 Change a Critical Site Setting

1. Go to **Settings > General**.
2. Change a field (e.g. Administration Email Address), click **Save
   Changes**.
3. **Expected:** Redirected to challenge page. Action label shows
   "Change critical site settings."
4. Authenticate.
5. **Expected:** Settings save is replayed.

### 2.10 Change WP Sudo Settings (Self-Protection)

1. Go to **Settings > Sudo** and change any value. Click **Save
   Changes**.
2. **Expected:** Redirected to challenge page. Action label shows
   "Change WP Sudo settings."
3. Authenticate.
4. **Expected:** Settings save is replayed.

### 2.11 Export Site Data

1. Go to **Tools > Export**.
2. Click **Download Export File**.
3. **Expected:** Redirected to challenge page. Action label shows
   "Export site data."
4. Authenticate.
5. **Expected:** Export file downloads.

### 2.12 Edit Plugin/Theme File

> Plugin and theme file editors are often (and should be) disabled.

1. Go to **Plugins > Plugin File Editor** (or **Appearance > Theme
   File Editor**).
2. Edit a file and click **Update File**.
3. **Expected:** Redirected to challenge page.
4. Authenticate.
5. **Expected:** File edit is replayed.

### 2.13 Bypass Challenges with Active Session

1. Activate a sudo session.
2. Repeat any test above.
3. **Expected:** Actions proceed immediately with no challenge.

### 2.14 Cancel Returns to Originating Page

1. Trigger any stash-challenge-replay flow.
2. On the challenge page, click **Cancel**.
3. **Expected:** Returned to the page you started from, not the
   dashboard.

---

## 3. AJAX Gating (Plugin and Theme Installers)

Buttons are disabled when no sudo session is active. If an AJAX request
is somehow sent, it receives a JSON error and the button resets.

### 3.1 Install Plugin via Search

1. Ensure no sudo session is active.
2. Go to **Plugins > Add New**, search for a plugin.
3. **Expected:** All **Install Now** buttons are grayed out. A
   persistent notice links to the challenge page.
4. Activate a sudo session.
5. Click **Install Now** on a plugin.
6. **Expected:** Plugin installs successfully via AJAX.

### 3.2 Delete Theme via AJAX

1. Go to **Appearance > Themes** with no sudo session active.
2. **Expected:** Delete links are grayed out.
3. Activate a sudo session.
4. Click **Delete** on an inactive theme.
5. **Expected:** Theme is deleted via AJAX.

### 3.3 Update Plugin via AJAX

1. With a plugin update available and no sudo session active, go to
   **Plugins** or **Dashboard > Updates**.
2. **Expected:** Update links/buttons are grayed out.
3. Activate a sudo session.
4. Click **Update Now** on a plugin.
5. **Expected:** Plugin updates successfully via AJAX.

---

## 4. REST API — Cookie Auth (Browser)

### 4.1 Create Application Password (Profile Page)

1. Ensure no sudo session is active.
2. Go to **Users > Profile**, scroll to Application Passwords.
3. Enter a name, click **Add New Application Password**.
4. **Expected:** Error notice: "This action (Create application
   password) requires reauthentication. Press Cmd+Shift+S to start a
   sudo session, then try again."
5. Activate a sudo session via keyboard shortcut.
6. Retry step 3.
7. **Expected:** Password is created successfully.

### 4.2 `curl` with Cookie Auth

```bash
# Replace YOUR_SITE_URL, YOUR_USERNAME, and YOUR_PASSWORD below.

# 0. Clean up stale cookies
rm -f /tmp/wp-cookies.txt

# 1. Log in and capture cookies
curl -sk -L -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -X POST "YOUR_SITE_URL/wp-login.php" \
  -d 'log=YOUR_USERNAME&pwd=YOUR_PASSWORD&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F&testcookie=1' \
  -H "Cookie: wordpress_test_cookie=WP+Cookie+check" -o /dev/null

# 2. Get the REST nonce
NONCE=$(curl -sk -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  "YOUR_SITE_URL/wp-admin/" \
  | grep -oE 'createNonceMiddleware\( "[a-f0-9]+" \)' \
  | grep -oE '[a-f0-9]{6,}')
echo "Nonce: $NONCE"

# 3. Test gated action WITHOUT sudo (should fail)
curl -sk -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -H "X-WP-Nonce: $NONCE" \
  -H "Content-Type: application/json" \
  -X POST "YOUR_SITE_URL/wp-json/wp/v2/users/me/application-passwords" \
  -d '{"name":"test"}'
# Expected: {"code":"sudo_required", ...}
```

---

## 5. REST API — App Password Policies

Replace `YOUR_SITE_URL` with your dev site URL and `YOUR_USERNAME:YOUR_APP_PASS`
with your Application Password credentials (strip spaces from the password).

### 5.1 Non-Gated Endpoint (All Policies)

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  "YOUR_SITE_URL/wp-json/wp/v2/users/me"
```

**Expected:** 200 with user data. Non-gated endpoints are never blocked.

### 5.2 Limited (Default)

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -X POST "YOUR_SITE_URL/wp-json/wp/v2/users/me/application-passwords" \
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
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -X POST "YOUR_SITE_URL/wp-json/wp/v2/users/me/application-passwords" \
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
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -X POST "YOUR_SITE_URL/wp-json/wp/v2/users/me/application-passwords" \
  -H "Content-Type: application/json" \
  -d '{"name":"test-unrestricted"}'
```

**Expected:** 200 with password data.

### 5.5 Per-Application-Password Override

> New in v2.3.0.

1. Set the global REST API (App Passwords) policy to **Limited**.
2. Go to **Users > Profile**, scroll to Application Passwords.
3. Find an existing application password and set its per-password policy
   dropdown to **Unrestricted**.
4. Use that specific password in a `curl` request to a gated endpoint.

**Expected:** The request succeeds (200) because the per-password
override takes precedence over the global Limited policy.

5. Change the per-password policy to **Disabled**.
6. Retry the same `curl` request.

**Expected:** `sudo_disabled` error — the per-password override blocks
the request even though the global policy is Limited.

7. Set the per-password policy back to **(Use global policy)** or
   delete the override.
8. Retry the `curl` request.

**Expected:** `sudo_blocked` error — falls back to the global Limited
policy.

> **Cleanup:** Delete any test app passwords and restore the policy to
> Limited after testing.

---

## 6. XML-RPC Policies

### 6.1 Limited (Default)

```bash
curl -sk -X POST "YOUR_SITE_URL/xmlrpc.php" \
  -H "Content-Type: text/xml" \
  -d '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>'
```

**Expected:** A valid XML response listing available methods.

### 6.2 Disabled

Set XML-RPC policy to Disabled, then repeat the request above.

**Expected:** XML-RPC fault response indicating the service is disabled.

### 6.3 Unrestricted

Set XML-RPC policy to Unrestricted. All methods pass through without
gating.

---

## 7. WP-CLI Policies

Run WP-CLI against a dev site where WP Sudo is active.

### 7.1 Limited (Default) — Non-Gated Command

```bash
wp option get blogname
```

**Expected:** Returns the site title.

### 7.2 Limited — Gated Command

```bash
wp plugin deactivate hello-dolly
```

**Expected:** Dies with a message indicating the action is blocked by
WP Sudo.

### 7.3 Disabled

Set CLI policy to Disabled, then:

```bash
wp option get blogname
```

**Expected:** Command exits immediately with an error. All CLI
operations are killed.

### 7.4 Unrestricted

Set CLI policy to Unrestricted, then:

```bash
wp plugin deactivate hello-dolly
```

**Expected:** Plugin deactivated successfully.

### 7.5 CLI Enforces Cron Policy

With CLI set to any value and Cron set to Disabled:

```bash
wp cron event list
```

**Expected:** Dies with a message that WP-Cron is disabled. The CLI
gate enforces the Cron policy for `wp cron` subcommands.

---

## 8. Cron Policies

### 8.1 Limited (Default)

Non-gated scheduled events run normally. Gated operations triggered by
cron are silently blocked.

### 8.2 Disabled

All cron execution is killed at `init`. Covers both WP-Cron (page-load
trigger) and server-level cron hitting `wp-cron.php`.

### 8.3 Unrestricted

All scheduled events run as if WP Sudo is not installed.

---

## 9. Settings Page

### 9.1 Three-Option Policy Dropdowns

1. Go to **Settings > Sudo**.
2. **Expected:** Five policy fields, each with three options: Disabled,
   Limited (default), Unrestricted. The fifth (WPGraphQL) is visible only
   when WPGraphQL is active.
3. Change a policy, save, reload.
4. **Expected:** The saved value persists.

### 9.2 Session Duration

1. Set session duration to a valid value (1–15 minutes). Save.
2. **Expected:** Saved successfully.
3. Try setting it to 0 or 99.
4. **Expected:** Sanitized back to the valid range.

### 9.3 Help Tabs

1. Click the **Help** button (top-right of Settings > Sudo).
2. **Expected:** 10 help tabs: How Sudo Works, Session & Policies,
   App Passwords, MU-Plugin, Security Features, Security Model,
   Environment, Recommended Plugins, Extending, Audit Hooks.

### 9.4 Gated Actions Table

1. Scroll down on Settings > Sudo.
2. **Expected:** A table listing all 28 gated rules grouped by
   category, showing which surfaces each action covers. When WPGraphQL
   is active, an additional GraphQL row appears at the bottom of the table.

### 9.5 MU-Plugin Toggle

1. Scroll to the MU-Plugin section.
2. Click **Install** to install the mu-plugin.
3. **Expected:** Success message. The mu-plugin file exists in
   `wp-content/mu-plugins/`.
4. Click **Uninstall** to remove it.
5. **Expected:** Success message. The mu-plugin file is removed.

---

## 10. Site Health

1. Go to **Tools > Site Health**.
2. **Expected:** A "WP Sudo Entry Point Policies" test result.

### 10.1 All Limited or Disabled

- Set all five policies to Limited (or Disabled).
- **Expected:** Status is **Good** (green/blue shield).

### 10.2 Any Unrestricted

- Set one or more policies to Unrestricted.
- **Expected:** Status is **Recommended** (orange shield) with a
  message identifying which policies are unrestricted.

---

## 11. Admin Bar Timer

1. Activate sudo.
2. **Expected:** A countdown timer appears in the admin bar (green
   background, `M:SS` format).
3. Navigate between admin pages.
4. **Expected:** Timer persists and updates.
5. Hover over the timer.
6. **Expected:** A deactivation link is visible.
7. In the final 60 seconds:
   - **Expected:** Timer turns red.
   - **Accessibility:** Milestone announcements at 1 minute, 30 seconds,
     and 10 seconds are read by screen readers.
8. Let the session expire.
9. **Expected:** Timer disappears, page auto-reloads.
10. Click the deactivation link during an active session.
11. **Expected:** Session ends, you stay on the current page (not
    redirected to the dashboard).

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
5. **Result:** PASS — 2026-02-20 (Studio, SQLite). Verified Editor role
   lacks `unfiltered_html` via direct DB query; Administrator retains it.

### 12.2 Tamper Detection

1. Manually add `unfiltered_html` back to the Editor role (via a
   snippet or direct DB edit on `wp_user_roles`).
2. Load any admin page.
3. **Expected:** The capability is automatically stripped. The
   `wp_sudo_capability_tampered` action fires (visible in debug log if
   an audit logger is connected).
4. **Result:** PASS — 2026-02-20 (Studio, SQLite). Injected
   `unfiltered_html` into Editor role via direct DB edit; loading any
   admin page stripped it automatically.

---

## 13. Multisite-Specific (Multisite Only)

### 13.1 Network Admin Gating

1. Go to **Network Admin > Themes**.
2. Click **Network Enable** on a theme.
3. **Expected:** Redirected to challenge page with "Network enable
   theme." Cancel returns to the Network Admin Themes screen.
4. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61697, Local multisite).
   Challenge shows "Network enable theme." Cancel returns to Themes.

### 13.2 Network Admin Persistent Notice

1. Go to **Network Admin > Plugins** with no sudo session active.
2. **Expected:** Persistent notice linking to challenge page and
   showing keyboard shortcut.
3. Go to **Network Admin > Themes**.
4. **Expected:** Same persistent notice.
5. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61697, Local multisite).
   Notice appears on both Plugins and Themes pages with "Confirm your
   identity" link and `Cmd+Shift+S` shortcut. Also verified: "Delete"
   actions converted to disabled `<span>` (Themes) or disabled `<a>`
   (Plugins) with grey `wp-sudo-disabled` styling.

### 13.3 Site Management

1. Go to **Network Admin > Sites**.
2. Try to deactivate, archive, spam, or delete a site.
3. **Expected:** Each action redirects to the challenge page. Cancel
   returns to the Sites screen.
4. **Result:** PASS — 2026-02-19 (WP 7.0-alpha-61697, Local multisite).
   After fix in `702534a` (action2 fallback in `match_request()`),
   clicking Archive on `/one` redirects to the challenge page with
   "Archive site." Cancel returns to Sites screen without archiving.
   Previously PARTIAL (2026-02-20): WordPress sends
   `action=confirm&action2=archiveblog` and the Gate only checked
   `action`, missing the real action in `action2`.

### 13.4 Super Admin Grant

1. Go to **Network Admin > Users**, edit a user.
2. Toggle the Super Admin checkbox and save.
3. **Expected:** Redirected to challenge page with "Grant or revoke
   super admin."
4. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61697, Local multisite).
   Challenge shows "Grant or revoke super admin." Cancel returns to
   user edit page.

### 13.5 Network Settings

1. Go to **Network Admin > Settings** and save.
2. **Expected:** Redirected to challenge page with "Change network
   settings."
3. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61697, Local multisite).
   Challenge shows "Change network settings."

---

## 14. Edge Cases

### 14.1 Expired Stash

1. Trigger a stash-challenge-replay flow.
2. Wait more than 5 minutes on the challenge page (stash TTL expires).
3. Enter your password.
4. **Expected:** Error message: "Your challenge session has expired.
   Please try again."
5. **Result:** PASS — 2026-02-19 (WP 7.0-alpha-61698, Studio). Triggered
   Save Changes on General Settings, waited 5.5 minutes on the challenge
   page, then entered password. Error displayed: "Your challenge session
   has expired. Please try again."

### 14.2 Multiple Tabs

1. Open two admin tabs.
2. Activate sudo in tab 1.
3. Reload tab 2, then perform a gated action.
4. **Expected:** Action succeeds — the session is shared via cookie +
   user meta.
5. **Result:** PASS — 2026-02-19 (WP 7.0-alpha-61698, Studio). Activated
   sudo via Cmd+Shift+S in tab 1. Reloaded tab 2 (Plugins page) — admin
   bar showed the shared sudo timer, gate notice was absent, and action
   links were enabled. Session is shared via cookie + user meta.

### 14.3 Challenge Page in iframe Context

> Fixed in v2.3.0.

1. Trigger a gated action from a screen that uses `wp_iframe()` (e.g.
   the media uploader during a plugin or theme update).
2. **Expected:** The challenge page breaks out of the iframe and loads
   as a full page.
3. **Result:** PASS — 2026-02-19 (WP 7.0-alpha-61698, Studio). Clicked
   "Update to latest 7.0 nightly" on update-core.php with no active
   sudo session. Gate intercepted the POST to `do-core-upgrade` and
   redirected to the challenge page as a full page (not inside an
   iframe). Challenge showed "Update WordPress core" with stash key.
   Cancel returned to the Updates page.

### 14.4 Uninstall Cleanup

1. Deactivate and delete WP Sudo.
2. Check the database:
   - `wp_options` should not contain `wp_sudo_settings` or
     `wp_sudo_db_version`.
   - User meta should not contain keys starting with `_wp_sudo_`.
   - Editor role should have `unfiltered_html` restored.
3. **Expected:** All WP Sudo data is cleaned up.
4. **Result:** PASS — 2026-02-19 (WP 7.0-alpha-61698, Studio).
   Deactivated then deleted WP Sudo. PHP DB check confirmed:
   `wp_sudo_settings` removed, `wp_sudo_db_version` removed, zero
   `_wp_sudo_*` user meta rows, Editor role `unfiltered_html` restored
   to YES. Plugin reinstalled and reactivated successfully afterward.

---

## 15. WP 7.0 Visual Compatibility

Run against a WP 7.0 beta/RC dev site.
Document pass/fail and any visual regressions. These checks verify the plugin's
admin UI renders correctly under the WP 7.0 admin visual refresh (Trac #64308).

### 15.1 Settings Page (Settings > Sudo)

1. Load **Settings > Sudo** on a WP 7.0 site.
2. **Expected:** Settings page renders correctly under WP 7.0 admin chrome.
   - Form table rows, labels, and select dropdowns align properly.
   - Policy dropdowns respect the 140px minimum width.
   - Shield dashicon renders in the page title.
   - Help tabs open and display correctly.
   - Gated actions table (`.widefat.striped`) renders with correct spacing.
   - MU-plugin status section renders correctly.
3. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61698, Studio)

### 15.2 Challenge Page

1. Ensure no sudo session is active.
2. Trigger a gated action (e.g., **Settings > General > Save Changes**).
3. **Expected:** Challenge card renders correctly under WP 7.0 admin chrome.
   - Card is centered with max-width 420px, white background, border, and shadow.
   - Password field fills the full card width.
   - "Confirm & Continue" and "Cancel" buttons render correctly.
   - No raw text or visible escape sequences appear in the card.
4. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61698, Studio)

### 15.3 Admin Bar Countdown

1. Activate a sudo session.
2. **Expected:** Green countdown node renders in the admin bar.
   - Timer text is readable against the green (#2e7d32) background.
   - Red state (#c62828) appears in the final 60 seconds.
   - Admin bar node does not conflict with WP 7.0 toolbar chrome.
3. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61698, Studio)

### 15.4 Admin Notices (Gate Notice + Blocked Notice)

1. Go to **Plugins** with no sudo session active.
2. **Expected:** Persistent gate notice renders with correct styling.
   - `.notice.notice-warning` class applies correctly under WP 7.0.
   - Link to the challenge page is visible and styled.
3. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61698, Studio)

### 15.5 Disabled Action Links (Plugin/Theme rows)

1. Go to **Plugins** with no sudo session active.
2. **Expected:** Activate, Deactivate, and Delete links are replaced with
   gray disabled spans.
   - Inline `color:#787c82; cursor:default` renders correctly.
   - No conflict with new row-action hover styles from the admin refresh.
3. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61698, Studio)

---

## 16. WPGraphQL Surface Policy

> Requires the [WPGraphQL](https://wordpress.org/plugins/wp-graphql/) plugin to be
> active. If WPGraphQL is not installed, skip this section.

WPGraphQL gating is **surface-level** (not per-action): in Limited mode, all
mutations are blocked regardless of which operation they perform. Queries always
pass through. Gating hooks into WPGraphQL's own lifecycle so it works regardless
of how the endpoint is named.

For these tests, use an Application Password for authentication. In the `curl`
commands below, replace `YOUR_SITE_URL` and `YOUR_USERNAME:YOUR_APP_PASS`
accordingly (strip spaces from the application password).

### 16.1 Limited (Default) — Query passes through

Ensure WPGraphQL policy is set to **Limited** (the default), then:

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -H "Content-Type: application/json" \
  -X POST "YOUR_SITE_URL/graphql" \
  -d '{"query":"{ __typename }"}'
```

**Expected:** HTTP 200 with a valid GraphQL response body (e.g.
`{"data":{"__typename":"RootQuery"}}`). The query is not blocked.

### 16.2 Limited — Mutation blocked (no sudo session)

With the policy still on **Limited** and **no active sudo session**:

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -H "Content-Type: application/json" \
  -X POST "YOUR_SITE_URL/graphql" \
  -d '{"query":"mutation { __typename }"}'
```

**Expected:** HTTP 403 with a `sudo_blocked` error:

```json
{"code":"sudo_blocked","message":"This GraphQL mutation requires sudo. Activate a sudo session and try again.","data":{"status":403}}
```

### 16.3 Disabled — All requests blocked

Set WPGraphQL policy to **Disabled** in Settings > Sudo, then:

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -H "Content-Type: application/json" \
  -X POST "YOUR_SITE_URL/graphql" \
  -d '{"query":"{ __typename }"}'
```

**Expected:** HTTP 403 with a `sudo_disabled` error:

```json
{"code":"sudo_disabled","message":"WPGraphQL is disabled by WP Sudo policy.","data":{"status":403}}
```

### 16.4 Unrestricted — Mutation passes through

Set WPGraphQL policy to **Unrestricted**, then repeat the mutation request
from 16.2.

**Expected:** HTTP 200. WP Sudo does not block the request. (WPGraphQL may
still return a schema-level error if the mutation is invalid — that is
expected and unrelated to WP Sudo.)

> **Cleanup:** Restore the WPGraphQL policy to **Limited** after testing.
