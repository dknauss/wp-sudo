# Security Model

WP Sudo is a **hook-based interception layer**. It operates within WordPress's plugin API — `admin_init`, `pre_option_*`, `activate_plugin`, REST `permission_callback`, etc. — and is subject to the same boundaries as any WordPress plugin.

## What It Protects Against

- **Compromised admin sessions** — a stolen session cookie cannot perform gated actions without reauthenticating. The sudo session is cryptographically bound to the browser.
- **Session theft → password change → lockout** — password changes on the profile/user-edit pages and via the REST API are a gated action (`user.change_password`). An attacker who steals a session cookie cannot silently change the victim's password without triggering the challenge.
- **Insider threats** — even legitimate administrators must prove their identity before destructive operations.
- **Automated abuse** — headless entry points (WP-CLI, Cron, XML-RPC, Application Passwords, WPGraphQL) can be disabled entirely or restricted to non-gated operations.
- **2FA replay** — the two-factor challenge is bound to the originating browser via a one-time cookie, preventing cross-browser replay.
- **Capability tampering** — direct database modifications to restore `unfiltered_html` on the Editor role are detected and reversed at `init`.

## What It Does Not Protect Against

- **Direct database access** — an attacker with SQL access can modify data without triggering any WordPress hooks. WP Sudo cannot gate operations that bypass the WordPress API entirely.
- **File system access** — PHP scripts that load `wp-load.php` and call WordPress functions directly may bypass the gate if they don't trigger the standard hook sequence.
- **Other plugins that bypass hooks** — if a plugin calls `activate_plugin()` in a way that suppresses `do_action('activate_plugin')`, the gate won't fire. The mu-plugin mitigates this by loading the gate before other plugins.
- **Server-level operations** — database migrations, WP-CLI commands run as root with direct PHP execution, or deployment scripts that modify files are outside WordPress's hook system.

## WPGraphQL Surface

WPGraphQL registers its endpoint via WordPress rewrite rules and dispatches requests at the `parse_request` hook — it does not use the WordPress REST API pipeline. WordPress's standard authentication still applies — cookies, nonces, and Application Passwords are valid. WP Sudo hooks into WPGraphQL's own `graphql_process_http_request` action, which fires after authentication but before body reading, regardless of how the endpoint is named or configured.

```
HTTP POST /graphql
        │
        ▼  parse_request (WPGraphQL Router)
        │
        ▼  graphql_process_http_request  ◄── WP Sudo intercepts here
        │  (after auth validation, before body read)
        │  Policy check:
        │    Disabled     → wp_send_json(sudo_disabled, 403) + exit
        │    Limited+mutation, no session → wp_send_json(sudo_blocked, 403) + exit
        │    otherwise    → pass through
        │
        ▼  new Request() — php://input read
        │
        ▼  execute_http() — GraphQL schema execution
        │
        ▼  graphql_process_http_request_response
        │
        ▼  HTTP Response
```

WP Sudo adds WPGraphQL as a fifth non-interactive surface with the same three-tier policy model (Disabled / Limited / Unrestricted) as WP-CLI, Cron, XML-RPC, and Application Passwords. The default is **Limited**.

**Mutation detection heuristic.** In Limited mode, WP Sudo checks whether the POST body contains the word `mutation`. This is a deliberately blunt heuristic — it cannot false-negative on a standard inline GraphQL mutation, but it may false-positive on a query that mentions `mutation` in a string argument. The tradeoff is intentional: safe to over-block (for inline queries), and independent of WPGraphQL's schema. **Exception: persisted queries.** When using the WPGraphQL Persisted Queries extension (or Automatic Persisted Queries), the POST body contains only a query ID or hash — the word `mutation` never appears. The heuristic cannot detect these. If your environment uses persisted queries and mutation gating is a security requirement, use the **Disabled** policy rather than relying on Limited.

**Scope.** WPGraphQL core exposes `deleteUser`, `updateUser`, `createUser`, and related mutations that map directly to gated operations. Third-party WPGraphQL extensions may add further mutations. The surface-level policy gates all mutations uniformly without requiring a schema-coupled rule set.

### WPGraphQL: Headless Authentication Boundary

The **Limited** policy has a constraint that does not apply to the other surfaces:
a sudo session can only be created from the WordPress admin interface, and it is
bound to the specific browser that completed the challenge.

For a mutation to pass through in Limited mode, two conditions must be met simultaneously:

1. **WordPress must identify the requesting user** — `get_current_user_id()` must return a non-zero value. This requires the request to carry valid WordPress authentication: a session cookie (browser-based admin access), an Application Password (`Authorization` header), or a JWT token if a JWT plugin is active.

2. **The sudo session cookie must be present** — the `_wp_sudo_token` cookie must accompany the request and match the token hash in user meta. This cookie is only set when the user completes a sudo challenge in the WordPress admin UI.

**Why this matters for headless deployments.** A frontend running at a different origin from the WordPress backend (e.g. a SvelteKit app at `localhost:5173` calling WordPress at `site.wp.local`) cannot automatically share the sudo session cookie. Cross-origin requests do not carry cookies unless CORS is configured with `Access-Control-Allow-Credentials: true` and a matching origin, and the frontend fetch uses `credentials: 'include'`. Without this, `get_current_user_id()` returns `0` and the sudo session cookie is absent — mutations are blocked by the Limited policy regardless of whether the frontend user is "logged in" from the application's perspective.

In practice, for most headless deployments, **Limited behaves identically to Disabled**: all mutations are blocked. The difference only becomes relevant when a user is simultaneously accessing the WordPress admin in the same browser with an active sudo session, and the frontend is configured to share credentials cross-origin.

**JWT authentication (wp-graphql-jwt-authentication).** The standard WPGraphQL JWT plugin hooks `determine_current_user` at priority 99, so `get_current_user_id()` returns the correct user ID for JWT-authenticated requests. However, JWT requests do not carry WordPress cookies, so the sudo session check always fails — authenticated JWT mutations are blocked in Limited mode. Worse, the JWT `login` mutation is sent by *unauthenticated* users (they are trying to obtain a token), so it is also blocked. **The default Limited policy breaks the JWT authentication flow entirely.** Use the `wp_sudo_wpgraphql_bypass` filter to exempt authentication mutations, or set the policy to Unrestricted. See the [developer reference](developer-reference.md#wp_sudo_wpgraphql_bypass-filter) for a bridge mu-plugin example.

**Recommended policy by deployment type:**

| Deployment | Recommended policy |
|---|---|
| Public-facing headless app (ratings, comments, contact forms) | Unrestricted |
| JWT-authenticated headless app (with bypass filter for auth mutations) | Limited + `wp_sudo_wpgraphql_bypass` filter |
| Internal admin tool with concurrent wp-admin access, same browser | Limited |
| Block all GraphQL mutations unconditionally | Disabled |

For headless deployments that need to gate mutations by authentication — require a WordPress user but not a full sudo session — the recommended approach is to use Application Password authentication on the GraphQL endpoint and set the global REST API (App Passwords) policy to Limited. Unauthenticated requests will still be blocked by the WPGraphQL Limited policy (since `get_current_user_id()` = 0), while authenticated app-password requests are governed by the REST API policy.

## Environmental Considerations

- **Cookies** — sudo session tokens require secure httponly cookies. Reverse proxies that strip or rewrite `Set-Cookie` headers may break session binding. Ensure the proxy passes cookies through to PHP.
- **Object cache** — user meta reads go through `get_user_meta()`, which may be served from an object cache (Redis, Memcached). If the cache returns stale meta, a revoked session could briefly appear valid. Standard WordPress cache invalidation handles this correctly; custom cache configurations should verify meta writes propagate.
- **Surface detection** — the gate relies on WordPress constants (`REST_REQUEST`, `DOING_CRON`, `WP_CLI`, `XMLRPC_REQUEST`) set by WordPress core before plugin code runs. These constants are stable across all standard WordPress hosting environments.

## Session Binding

When sudo is activated, a cryptographic token is stored in a secure httponly cookie and its hash is saved in user meta. On every gated request, both must match. A stolen session cookie on a different browser will not have a valid sudo session.

## Grace Period

Since v2.6.0, sudo sessions have a 120-second grace window (`Sudo_Session::GRACE_SECONDS`) after they expire. If a user was filling in a form when the session expired, the gate calls `Sudo_Session::is_within_grace()` before redirecting to the challenge page.

**Security properties of the grace window:**

- **Token binding is enforced** — `is_within_grace()` calls `verify_token()` before returning `true`. The session cookie must still be present and match the stored hash. A browser without the original sudo cookie cannot gain grace access.
- **Grace applies to interactive surfaces only** — the admin UI, REST API, and WPGraphQL gating points check grace. The admin bar timer does not — it reflects the true session state so the user sees accurately when their session has expired.
- **Meta cleanup is deferred** — `is_active()` does not delete the session meta while the grace window is open. This allows `is_within_grace()` to read the expiry timestamp and token. Cleanup runs when `time() > $expires + GRACE_SECONDS`.
- **No new permissions** — the grace window only prevents a re-challenge for work that was already in progress when the session expired. It does not allow new gated actions to be initiated.

## 2FA Browser Binding

When the password step succeeds and 2FA is required, a one-time challenge cookie is set in the browser. The 2FA pending state is keyed by the hash of this cookie, not by user ID. An attacker who stole the WordPress session cookie but is on a different machine does not have the challenge cookie and cannot complete the 2FA step.
