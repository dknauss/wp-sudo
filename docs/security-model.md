# Security Model

WP Sudo is a **hook-based interception layer**. It operates within WordPress's plugin API — `admin_init`, `pre_option_*`, `activate_plugin`, REST `permission_callback`, etc. — and is subject to the same boundaries as any WordPress plugin.

## What It Protects Against

- **Compromised admin sessions** — a stolen session cookie cannot perform gated actions without reauthenticating. The sudo session is cryptographically bound to the browser.
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

WP Sudo adds WPGraphQL as a fifth non-interactive surface with the same three-tier policy model (Disabled / Limited / Unrestricted) as WP-CLI, Cron, XML-RPC, and Application Passwords. The default is **Limited**.

**Mutation detection heuristic.** In Limited mode, WP Sudo checks whether the POST body contains the word `mutation`. This is a deliberately blunt heuristic — it cannot false-negative on an actual GraphQL mutation, but it may false-positive on a query that mentions `mutation` in a string argument. The tradeoff is intentional: safe to over-block, impossible to under-block, and independent of WPGraphQL's schema.

**Scope.** WPGraphQL core exposes `deleteUser`, `updateUser`, `createUser`, and related mutations that map directly to gated operations. Third-party WPGraphQL extensions may add further mutations. The surface-level policy gates all mutations uniformly without requiring a schema-coupled rule set.

## Environmental Considerations

- **Cookies** — sudo session tokens require secure httponly cookies. Reverse proxies that strip or rewrite `Set-Cookie` headers may break session binding. Ensure the proxy passes cookies through to PHP.
- **Object cache** — user meta reads go through `get_user_meta()`, which may be served from an object cache (Redis, Memcached). If the cache returns stale meta, a revoked session could briefly appear valid. Standard WordPress cache invalidation handles this correctly; custom cache configurations should verify meta writes propagate.
- **Surface detection** — the gate relies on WordPress constants (`REST_REQUEST`, `DOING_CRON`, `WP_CLI`, `XMLRPC_REQUEST`) set by WordPress core before plugin code runs. These constants are stable across all standard WordPress hosting environments.

## Session Binding

When sudo is activated, a cryptographic token is stored in a secure httponly cookie and its hash is saved in user meta. On every gated request, both must match. A stolen session cookie on a different browser will not have a valid sudo session.

## 2FA Browser Binding

When the password step succeeds and 2FA is required, a one-time challenge cookie is set in the browser. The 2FA pending state is keyed by the hash of this cookie, not by user ID. An attacker who stole the WordPress session cookie but is on a different machine does not have the challenge cookie and cannot complete the 2FA step.
