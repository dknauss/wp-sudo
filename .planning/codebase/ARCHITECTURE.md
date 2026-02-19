# Architecture

**Analysis Date:** 2026-02-19

## Pattern Overview

**Overall:** Zero-trust action-gating with request interception and reauthentication.

**Key Characteristics:**
- Role-agnostic: any logged-in user attempting a gated action is challenged, regardless of role
- Multi-surface: intercepts requests across admin UI, AJAX, REST API, WP-CLI, Cron, XML-RPC, and Application Passwords
- Request stashing: intercepted requests are serialized into transients and replayed after successful reauthentication
- Session binding: cryptographic tokens bound to the user and browser via secure httponly cookies
- Rate limiting: 5 failed password attempts trigger a 5-minute lockout with progressive delays
- Self-protecting: changes to WP Sudo settings require reauthentication

## Layers

**Entry Points Layer:**
- Purpose: Intercept incoming requests on all surfaces (admin, AJAX, REST, CLI, Cron, XML-RPC, App Passwords)
- Location: `includes/class-gate.php`
- Contains: Surface detection, routing to challenge or policy handlers
- Depends on: Action Registry, Sudo Session, Request Stash
- Used by: WordPress action hooks (`admin_init`, `rest_request_before_callbacks`, `wp_before_admin_bar_render`, early hooks via MU-plugin)

**Action Registry Layer:**
- Purpose: Define all gated operations (28+ built-in rules across 7 categories: plugins, themes, users, editors, options, multisite, core)
- Location: `includes/class-action-registry.php`
- Contains: Rule definitions with matching criteria (pagenow, actions, REST routes, AJAX actions, callbacks)
- Depends on: None
- Used by: Gate

**Authentication Layer:**
- Purpose: Challenge page rendering, password verification, 2FA integration, request replay
- Location: `includes/class-challenge.php`
- Contains: Hidden admin page registration, AJAX handlers for password and 2FA steps, asset enqueueing, request replay logic
- Depends on: Request Stash, Sudo Session, 2FA plugin integration (optional)
- Used by: Gate

**Session Management Layer:**
- Purpose: Track reauthentication state, manage time-bounded tokens, enforce rate limiting
- Location: `includes/class-sudo-session.php`
- Contains: Session activation/deactivation, token generation and validation, lockout tracking, browser binding
- Depends on: None (uses only WordPress user meta and cookies)
- Used by: Gate, Challenge

**Request Stash Layer:**
- Purpose: Serialize and replay intercepted requests after reauthentication
- Location: `includes/class-request-stash.php`
- Contains: Request serialization into transients, sanitization, GET redirect vs POST replay logic
- Depends on: None
- Used by: Gate, Challenge

**Configuration & UI Layer:**
- Purpose: Settings persistence, admin UI, audit logging, admin bar countdown
- Location: `includes/class-admin.php`, `includes/class-admin-bar.php`
- Contains: Settings page (single-site and multisite), MU-plugin installation, help tabs, live countdown timer
- Depends on: Sudo Session, Admin (for settings lookup)
- Used by: Plugin, challenge page assets

**Plugin Orchestration Layer:**
- Purpose: Bootstrap all components, manage activation/deactivation/upgrade lifecycle
- Location: `includes/class-plugin.php`
- Contains: Component initialization, lifecycle hooks, capability restriction (unfiltered_html enforcement), audit hook firing
- Depends on: All other classes
- Used by: Entry point `wp-sudo.php`, WordPress action hooks

**Site Health & Observability Layer:**
- Purpose: WordPress Site Health integration, audit trail support
- Location: `includes/class-site-health.php`
- Contains: Status tests, debug info, audit hook definitions
- Depends on: None
- Used by: Plugin

**Upgrade & Migration Layer:**
- Purpose: Version-aware database migrations
- Location: `includes/class-upgrader.php`
- Contains: Sequential upgrade routines, schema changes per version
- Depends on: None
- Used by: Plugin

## Data Flow

**Admin UI Request (Browser → Challenge → Replay):**

1. User attempts a gated action (e.g., activate plugin) on `plugins.php`
2. `admin_init` hook fires → `Gate::register()` has hooked into it
3. Gate checks request against Action Registry rules (matches `plugin.activate` rule)
4. No active sudo session found
5. Gate stashes the original request (URL, method, GET/POST params) in a 5-minute transient via `Request_Stash::save()`
6. User is redirected to challenge page: `admin.php?page=wp-sudo-challenge&stash={key}`
7. Challenge page renders password form via `Challenge::render_page()`
8. User enters password, form submits via AJAX to `wp_ajax_wp_sudo_challenge_auth`
9. Challenge handler verifies password via WordPress user auth, creates Sudo_Session token, sets httponly cookie
10. If 2FA required, user enters 2FA code (separate AJAX action `wp_ajax_wp_sudo_challenge_2fa`)
11. Challenge page retrieves stashed request and replays:
    - GET requests: redirect via `wp_safe_redirect()`
    - POST requests: JavaScript renders self-submitting form with original fields
12. Replayed request re-enters `admin_init` → Gate now sees active sudo session → request passes

**AJAX/REST Request (Non-Interactive):**

1. JavaScript on admin page makes AJAX or REST call
2. Gate intercepts request (checks `wp_ajax_` action or REST route)
3. No sudo session active
4. Gate returns `sudo_required` error response
5. JavaScript receives error, checks for admin notice link (set via blocked transient)
6. On next page load, admin notice displays: "Sudo required — click here to activate"
7. User clicks link → redirected to challenge page in session-only mode (no stash key)
8. After reauthentication, redirects back to referring page
9. User retries the AJAX/REST call, now passes Gate

**CLI/Cron/XML-RPC/App Password Request (Non-Interactive, Policy-Gated):**

1. Request enters via WP-CLI, scheduled event, XML-RPC, or Application Password
2. Gate checks policy (Disabled/Limited/Unrestricted) for that surface
3. If Limited (default): gated actions are blocked, non-gated actions allowed
4. If Unrestricted: all requests pass without checks
5. If Disabled: entire surface is disabled, no checks run
6. For App Passwords specifically: per-password override checked before global policy

**State Management:**

- User meta keys (Sudo_Session):
  - `_wp_sudo_expires`: Session expiry timestamp (Unix time)
  - `_wp_sudo_token`: Session binding token (cryptographic hash)
  - `_wp_sudo_failed_attempts`: Count of failed password attempts
  - `_wp_sudo_lockout_until`: Lockout expiry timestamp
- Cookies (HttpOnly, Secure if HTTPS):
  - `wp_sudo_token`: Session binding token (matches user meta)
  - `wp_sudo_challenge`: 2FA browser binding (one-time use, destroyed after 2FA completion)
- Transients (WordPress temporary storage):
  - `_wp_sudo_stash_{key}`: Serialized request data (5-minute TTL)
  - `_wp_sudo_blocked_{user_id}_{surface}`: Blocked action notice flag (short TTL)
- Settings (Option/Site Option):
  - `wp_sudo_settings`: JSON with session_duration, cli_policy, cron_policy, xmlrpc_policy, rest_app_password_policy

## Key Abstractions

**Gate:**
- Purpose: Multi-surface request interceptor
- Examples: `includes/class-gate.php` line 28
- Pattern: Dependency injection (Sudo_Session, Request_Stash), policy-based routing, early hooks registration via MU-plugin

**Sudo_Session:**
- Purpose: Represent a reauthenticated user state bound to a browser
- Examples: `includes/class-sudo-session.php` line 28
- Pattern: User meta + cryptographic token + httponly cookie for binding; progressive retry delays and hard lockouts

**Action_Registry:**
- Purpose: Centralized definition of gated operations
- Examples: `includes/class-action-registry.php` line 30
- Pattern: Pure data class, closures in rule conditions, per-request caching, `wp_sudo_gated_actions` filter for extensibility

**Challenge:**
- Purpose: Interstitial reauthentication UI and workflow orchestration
- Examples: `includes/class-challenge.php` line 26
- Pattern: Hidden admin page, dual AJAX handlers (password + 2FA), JavaScript-driven replay, optional 2FA integration via filter

**Request_Stash:**
- Purpose: Serialize and replay intercepted requests
- Examples: `includes/class-request-stash.php` line 26
- Pattern: Transient-backed temporary storage, param sanitization, method-aware replay (redirect vs form submit)

## Entry Points

**Plugin Entry Point:**
- Location: `wp-sudo.php` lines 1–100
- Triggers: WordPress `plugins_loaded` hook
- Responsibilities: Define constants, register SPL autoloader, instantiate Plugin singleton, call `Plugin::init()`

**Plugin::init() (main bootstrap):**
- Location: `includes/class-plugin.php` lines 81–120+
- Triggers: `plugins_loaded` action
- Responsibilities: Load translations, run upgrader, instantiate components (Gate, Challenge, Admin, Admin_Bar, Site_Health), register hooks

**Gate::register() (interactive surfaces):**
- Location: `includes/class-gate.php` line ~250–300
- Triggers: Called during `Plugin::init()`
- Responsibilities: Hook into `admin_init`, `rest_request_before_callbacks`, `wp_before_admin_bar_render`

**Gate::register_early() (non-interactive surfaces):**
- Location: `includes/class-gate.php` line ~300–350
- Triggers: Called during `muplugins_loaded` if MU-plugin is active
- Responsibilities: Hook into early WordPress actions for WP-CLI, Cron, XML-RPC (before plugins load)

**Challenge::register():**
- Location: `includes/class-challenge.php` line 70–81
- Triggers: Called during `Plugin::init()`
- Responsibilities: Register hidden admin page, AJAX handlers, asset enqueueing

**Admin::register():**
- Location: `includes/class-admin.php` line 78–120+
- Triggers: Called during `Plugin::init()`
- Responsibilities: Register settings page, option sanitization, MU-plugin installation UI

**MU-Plugin Shim:**
- Location: `mu-plugin/wp-sudo-gate.php`
- Triggers: Automatically loaded by WordPress before plugins
- Responsibilities: Define constant, require loader in plugin directory

**MU-Plugin Loader:**
- Location: `mu-plugin/wp-sudo-loader.php`
- Triggers: Required by shim at `muplugins_loaded`
- Responsibilities: Load main plugin, instantiate Gate and call `register_early()`

## Error Handling

**Strategy:** Multi-level validation with clear error states and audit logging.

**Patterns:**
- Password verification failure: Rate limiting with progressive delays (0s, 0s, 0s, 2s, 5s), then 5-minute lockout. `wp_sudo_reauth_failed` and `wp_sudo_lockout` actions fire for audit logging.
- Session expiry: Silent; request is blocked and treated as "no session" (no error message — user is redirected to challenge page)
- Request stash expiry: Silent; stash is treated as missing, user is prompted to retry
- 2FA timeout: User can restart the flow; 2FA pending state expires independently
- Transient cleanup: Uses WordPress transient TTL; no explicit cleanup needed
- Capability tampering: `wp_sudo_capability_tampered` action fires if `unfiltered_html` reappears on Editor role

## Cross-Cutting Concerns

**Logging:**
- 9 audit hooks fire throughout the request lifecycle:
  - `wp_sudo_activated` — plugin activation
  - `wp_sudo_deactivated` — plugin deactivation
  - `wp_sudo_reauth_failed` — password verification failure
  - `wp_sudo_lockout` — 5-minute hard lockout triggered
  - `wp_sudo_action_gated` — action intercepted (gated)
  - `wp_sudo_action_blocked` — action blocked by policy
  - `wp_sudo_action_allowed` — action allowed (passed Gate and capability checks)
  - `wp_sudo_action_replayed` — stashed request replayed successfully
  - `wp_sudo_capability_tampered` — unfiltered_html capability reappeared on Editor role
- External audit plugins (WP Activity Log, Stream) can hook these to log entries

**Validation:**
- User input: All `$_GET`, `$_POST`, `$_COOKIE` data is sanitized via WordPress functions (sanitize_text_field, sanitize_key, wp_verify_nonce)
- Nonce verification: Challenge form uses `wp_nonce_field()` and `wp_verify_nonce()`
- Token validation: Session tokens are generated via `wp_generate_password()` and stored/compared via hash
- Request stash: Original request params are sanitized before storage and before replay
- Capability checks: WordPress permission checks still run after the Gate (gate adds a reauthentication layer on top)

**Authentication:**
- Password: Verified via `wp_check_password()` against stored hashes
- 2FA (optional): Integrated via `wp_sudo_challenge_2fa_providers` filter; third-party plugins provide custom providers
- Session: Verified via token in user meta + matching token in httponly cookie (binding)
- Rate limiting: 5 attempts trigger 5-minute lockout; progressive delays (2s, 5s) before lockout
- Multisite: On multisite, settings are network-wide; sessions are per-user across all sites in network

---

*Architecture analysis: 2026-02-19*
