# External Integrations

**Analysis Date:** 2026-02-19

## APIs & External Services

**None.** WP Sudo has no external API integrations. All functionality is self-contained within the WordPress environment.

## Data Storage

**WordPress Options API:**
- Option key: `wp_sudo_settings` - Primary settings storage
  - Stored via `update_option()` / `get_option()` in `includes/class-admin.php`
  - Multisite: Uses `update_site_option()` / `get_site_option()` on network installs
  - Contents: Session duration (1-15 minutes), entry point policies (REST, CLI, Cron, XML-RPC)
  - Deletion: `uninstall.php` removes this option on uninstall

**WordPress User Meta:**
- `_wp_sudo_token` - Hashed session token per user
  - Stored via `update_user_meta()` in `includes/class-sudo-session.php` (line 506)
  - Hash algorithm: SHA-256 of cryptographic token
- `_wp_sudo_expires` - Session expiration timestamp
  - Stored via `update_user_meta()` in `includes/class-sudo-session.php` (line 168)
- `_wp_sudo_lockout_count` - Failed login attempt counter
  - Stored via `update_user_meta()` in `includes/class-sudo-session.php` (line 617)
- `_wp_sudo_lockout_until` - Lockout expiration timestamp (5-minute lockout)
  - Stored via `update_user_meta()` in `includes/class-sudo-session.php` (line 620)
- `wp_sudo_activated` - Network-wide activation flag
  - Stored via `update_option()` in `includes/class-plugin.php` (line 302)
- Deletion: `uninstall.php` removes all `_wp_sudo_*` meta keys across users and sites

**WordPress Transients (Temporary Storage):**
- `wp_sudo_2fa_pending_{hash}` - Two-factor pending state during 2FA challenge window
  - Set in `includes/class-sudo-session.php` (line 320)
  - Keyed by SHA-256 hash of challenge cookie
  - TTL: 10 minutes (adjustable via `wp_sudo_two_factor_window` filter)
  - Deleted in `includes/class-sudo-session.php` (line 446)
- `wp_sudo_blocked_action_{user_id}` - Blocked action notification transient
  - Set in `includes/class-gate.php` (line 1018)
  - Used for admin notice fallback on next page load
  - TTL: 10 seconds for fallback display

**Request Stash (Transient-based):**
- `wp_sudo_request_stash_{key}` - Serialized intercepted request data
  - Stored in `includes/class-request-stash.php` (line 70)
  - Contains: GET/POST parameters, nonce, stashed action metadata
  - TTL: 30 minutes (self::TTL constant)
  - Used to replay original admin request after reauthentication

**Cookies:**
- `wp_sudo_token` - Secure httponly session cookie
  - Set via `setcookie()` in `includes/class-sudo-session.php` (line 167)
  - Path: `/wp-admin` (ADMIN_COOKIE_PATH)
  - Domain: WordPress COOKIE_DOMAIN constant
  - Secure: `is_ssl()` dependent
  - HttpOnly: True (inaccessible to JavaScript)
  - SameSite: Strict (when PHP 7.3+)
- `wp_sudo_challenge_session` - Temporary challenge page session token
  - Used during 2FA challenge window for browser binding
  - Automatically cleared after successful or failed reauthentication

**File Storage:**
- Local filesystem only
  - `languages/` - Translation files (`.pot`, language-specific `.po` files)
  - No file upload handling

**Caching:**
- None configured. Transients are used for temporary state only (2FA pending, request stash, notifications).

## Authentication & Identity

**Auth Provider:**
- WordPress core authentication via `wp_authenticate()` and `wp_check_password()`
- Located in `includes/class-challenge.php` (reauthentication logic)

**Implementation Approach:**
- Password verification: Uses `wp_check_password()` (wp-includes/pluggable.php equivalent in tests)
- 2FA Integration: Hooks into Two Factor plugin if installed
  - Filter: `wp_sudo_requires_two_factor` - Detect if user has 2FA configured
  - Action: `wp_sudo_render_two_factor_fields` - Render 2FA form fields
  - Filter: `wp_sudo_validate_two_factor` - Validate 2FA submission
  - Filter: `wp_sudo_two_factor_window` - Adjust verification window (default 10 minutes)
  - Location: `includes/class-challenge.php` (lines 302, 456)
- Session binding: Cryptographic token (SHA-256) stored in user meta + httponly cookie
- Rate limiting: 5 failed attempts → 5-minute lockout
  - Managed in `includes/class-sudo-session.php` (lines 617-620)

**Supported 2FA Plugins:**
- **Two Factor** (WordPress.org) - Automatic integration via `class_exists( 'Two_Factor_Core' )`
  - Supports TOTP, email, backup codes, WebAuthn via providers
- **WP 2FA** - Via bridge in `bridges/wp-2fa-bridge.php`
- **Other 2FA plugins** - Via four filters documented in `docs/two-factor-integration.md`

## Monitoring & Observability

**Error Tracking:**
- None configured. Errors logged via WordPress native logging (wp-includes/load.php error handling).

**Audit Logging:**
- No built-in audit logging. Plugin fires 9 action hooks for external logging systems:
  - `wp_sudo_activated` - Session created
  - `wp_sudo_deactivated` - Session expired or terminated
  - `wp_sudo_reauth_failed` - Failed password verification
  - `wp_sudo_lockout` - Lockout triggered (5 failed attempts)
  - `wp_sudo_action_gated` - Action intercepted and user challenged
  - `wp_sudo_action_blocked` - Non-interactive action blocked (policy decision)
  - `wp_sudo_action_allowed` - Non-interactive action allowed (policy decision)
  - `wp_sudo_action_replayed` - Stashed request replayed after reauthentication
  - `wp_sudo_capability_tampered` - Editor `unfiltered_html` capability tampered (canary detection)
- Hooks documented in `docs/developer-reference.md`
- Expected integration: WP Activity Log, Stream, or custom logging plugin

**Site Health Integration:**
- WordPress Site Health tests added in `includes/class-site-health.php`
- Status checks: sudo session distribution, capability integrity
- Debug info: Active session counts per user

## CI/CD & Deployment

**Hosting:**
- Self-hosted WordPress (any hosting supporting WordPress 6.2+)
- No vendor lock-in

**CI Pipeline:**
- None configured in repository (expected: external CI service like GitHub Actions)
- Manual verification commands:
  - `composer test` - PHPUnit 9.6
  - `composer lint` - PHPCS WordPress standards
  - `composer analyse` - PHPStan level 6
  - `composer sbom` - Generate CycloneDX SBOM

**Deployment:**
- Standard WordPress plugin deployment
- Upload to `/wp-content/plugins/wp-sudo/`
- Activate via dashboard or WP-CLI
- Optional: Install mu-plugin via settings page for early hook registration

## Environment Configuration

**Required Configuration:**
- None. Plugin works out-of-box with WordPress defaults.

**Optional Configuration:**
- WordPress option `wp_sudo_settings` - Session duration (1-15 min), entry point policies
- Settings UI at **Settings → Sudo** (or **Network Settings → Sudo** on multisite)

**Secrets Location:**
- No secrets required. All authentication is WordPress core native.
- Session tokens stored in WordPress user meta (encrypted via SHA-256 hashing).

## Webhooks & Callbacks

**Incoming:**
- None. Plugin does not receive inbound webhooks.

**Outgoing:**
- None. Plugin does not send outbound webhooks.

**Internal Callbacks:**
- AJAX handlers via `wp_ajax_wp_sudo_auth` and `wp_ajax_wp_sudo_2fa` for challenge page form submission
  - Located in `includes/class-challenge.php` (lines 78-79)
  - Used for password verification and 2FA validation without page reload

## Optional MU-Plugin

**Early Hook Registration:**
- Optional mu-plugin provided in `mu-plugin/wp-sudo-mu-plugin.php`
- Purpose: Register gate hooks before other plugins load
- Installation: Automatic via settings page button or manual upload
- Not required for functionality, but recommended for maximum coverage

## Multisite Configuration

**Network Behavior:**
- Settings stored at network level via `update_site_option()` / `get_site_option()`
- Sessions are network-wide (authenticating on one site covers all network sites)
- Additional network admin rules in `includes/class-action-registry.php`:
  - Network theme enable/disable
  - Site delete, deactivate, archive, spam
  - Super admin grant/revoke
  - Network settings changes

---

*Integration audit: 2026-02-19*
