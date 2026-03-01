# Abilities API Assessment

**Date:** 2026-02-19 (updated 2026-02-28)
**WP version evaluated:** 7.0 Beta 1
**Status:** No gating changes required for WP 7.0
**Covers:** Abilities API and WordPress MCP Adapter (same REST surface)

---

## Overview

The WordPress Abilities API, introduced in WP 6.9, exposes registered "abilities" via
REST endpoints and (optionally) WP-CLI. The WordPress MCP Adapter translates these
abilities into MCP tools for AI agents (Claude, Cursor, etc.) — it calls the same
REST endpoints, so both are covered by the same gating analysis.

This document evaluates the current surface, explains why WP Sudo does not need to
gate any abilities for WP 7.0, and documents the strategy for when gating will
become necessary.

**Verification sources for ability names and REST routes:**

- [Abilities API in WordPress 6.9 — Make WordPress Core](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/) — 3 core abilities, `permission_callback` pattern
- [Abilities API REST Endpoints — developer.wordpress.org](https://developer.wordpress.org/apis/abilities-api/rest-api-endpoints/) — REST route structure
- [From Abilities to AI Agents: Introducing the WordPress MCP Adapter — developer.wordpress.org](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/) — confirms 3 read-only abilities as of 7.0 Beta 1

Ability names were verified against official sources listed above and in
`.planning/phases/05-wp-7-0-readiness/05-RESEARCH.md`, not inferred from training data.

---

## Current Abilities Surface in WP 7.0

As of WP 7.0 Beta 1, WordPress core registers exactly three abilities. All three are
read-only: they expose information but do not modify or destroy site state.

| Ability ID | Label | Permission Callback | Destructive? |
|------------|-------|---------------------|--------------|
| `core/get-site-info` | Get Site Information | `current_user_can('read')` | No |
| `core/get-user-info` | Get User Information | `current_user_can('read')` | No |
| `core/get-environment-info` | Get Environment Info | `current_user_can('read')` | No |

Abilities are registered inside the `wp_abilities_api_init` action using
`wp_register_ability()`. Each registration specifies a `permission_callback`
(capability check) and an `execute_callback` (returns data).

---

## REST Endpoints (WP Abilities API v1)

The Abilities API registers the following REST routes under the `wp-abilities/v1`
namespace:

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/wp-json/wp-abilities/v1/abilities` | List all registered abilities |
| `GET` | `/wp-json/wp-abilities/v1/categories` | List ability categories |
| `GET` | `/wp-json/wp-abilities/v1/{ns}/{name}` | Get a single ability by namespace and name |
| `GET\|POST\|DELETE` | `/wp-json/wp-abilities/v1/{ns}/{name}/run` | Execute an ability |

The HTTP method for the `/run` endpoint is determined by the ability type:

- Read-only operations use `GET`
- Operations requiring input parameters use `POST`
- Destructive operations use `DELETE`

As of WP 7.0 Beta 1, no registered core abilities use `DELETE` on `/run`. All three
core abilities use `GET`.

---

## Analysis: Does WP Sudo Need to Gate Abilities?

### Current state: No gating required

WP Sudo's gating model intercepts operations that **modify or destroy site state**:
activating plugins, deleting users, changing critical settings, installing themes,
and so on. Read-only operations are explicitly outside WP Sudo's scope.

All three core abilities in WP 7.0 are read-only. They expose information about the
site, user, and environment — but they do not change anything. No reauthentication
is warranted for information retrieval.

### `permission_callback` pattern vs. WP Sudo gating

The Abilities API uses `permission_callback` (a standard WordPress capability check
such as `current_user_can('read')`) to control access. This is authorization — it
answers "is this user allowed to call this ability at all?"

WP Sudo provides reauthentication — it answers "has this user recently confirmed
their identity, regardless of their role?" These are complementary controls, not
substitutes. The `permission_callback` check runs inside WordPress before the
`execute_callback` fires. WP Sudo would intercept at the REST layer (via
`rest_request_before_callbacks`) before the `permission_callback` even runs.

For read-only abilities, the `permission_callback` check is sufficient. WP Sudo
would add no additional security value by intercepting them.

### Current Gate surfaces: no `ability` surface type

The Gate class (`includes/class-gate.php`) currently recognizes six surfaces:

| Surface | Interception point |
|---------|--------------------|
| `admin` | `admin_init` at priority 1 |
| `ajax` | `admin_init` at priority 1 (also fires for `admin-ajax.php`) |
| `rest` | `rest_request_before_callbacks` filter |
| `cli` | `init` at priority 0 via function-level hooks |
| `cron` | `init` at priority 0 via function-level hooks |
| `xmlrpc` | `init` at priority 0 via `xmlrpc_enabled` filter and function hooks |

There is no `ability` surface type. The Abilities API REST routes are served through
the standard WordPress REST API and are therefore already covered by the existing
`rest` surface interception — no special handling is required.

---

## Gating Strategy for Future Destructive Abilities

When a destructive ability appears in WordPress core or a plugin (indicated by a
`DELETE` method on a `/wp-abilities/v1/{ns}/{name}/run` route), WP Sudo can gate it
without adding a new surface type.

### REST-exposed abilities (browser and App Password callers)

The existing `intercept_rest()` method in `Gate` already intercepts all REST requests
via `rest_request_before_callbacks` and routes them through `match_request('rest')`.
A new rule in `Action_Registry` matching the destructive ability's route is all that
is needed:

```php
// Example: hypothetical destructive ability
[
    'id'    => 'abilities.delete_plugin',
    'label' => __( 'Delete plugin via Abilities API', 'wp-sudo' ),
    'rest'  => [
        'route'   => '#^/wp-abilities/v1/core/delete-plugin/run$#',
        'methods' => [ 'DELETE' ],
    ],
],
```

The existing `matches_rest()` method in `Gate` checks route pattern and HTTP method,
so a regex matching `/wp-abilities/v1/.*/run` with `DELETE` would catch all destructive
ability runs in a single rule:

```php
[
    'id'    => 'abilities.run_destructive',
    'label' => __( 'Run destructive ability', 'wp-sudo' ),
    'rest'  => [
        'route'   => '#^/wp-abilities/v1/[^/]+/[^/]+/run$#',
        'methods' => [ 'DELETE' ],
    ],
],
```

No new surface type is required for REST-exposed abilities.

### WordPress MCP Adapter (AI agent callers)

The WordPress MCP Adapter translates registered abilities into MCP tools. When an AI
agent calls an MCP tool, the adapter executes the corresponding ability via the same
`/wp-abilities/v1/{ns}/{name}/run` REST endpoint. From WP Sudo's perspective, an
MCP-originated ability call is indistinguishable from any other REST request — it
flows through `rest_request_before_callbacks` and is subject to the same Gate
interception.

No special handling is required for MCP Adapter calls. The same REST rules that gate
direct ability calls also gate MCP-mediated calls.

### WP-CLI `wp ability run` (CLI callers)

For abilities executed via WP-CLI's `wp ability run` command, the existing CLI
surface gating via function-level hooks in `register_function_hooks()` applies. A
hook on the appropriate WordPress action that fires before the ability's
`execute_callback` would be added to the function hook registration block.

### When to add an `ability` surface type to Gate

An `ability` surface type in `Gate` would only be warranted if abilities gain a
non-REST execution path that bypasses all existing surfaces — for example, if a
future WordPress version introduces a PHP-level `do_ability()` function that
third-party code can call directly outside of REST or CLI contexts. As of WP 7.0,
no such path exists. The REST layer is the primary execution path for abilities,
and it is already covered.

**Trigger conditions for adding `ability` surface type:**

1. A non-REST, non-CLI ability execution path is introduced in WordPress core
2. That path bypasses `rest_request_before_callbacks` and `admin_init`
3. Destructive abilities are registered that use this new path

None of these conditions exist in WP 7.0.

---

## Recommendation

**No Gate changes are needed for WP 7.0.**

All three core abilities are read-only. The existing REST surface interception in
`Gate::intercept_rest()` already covers the `/wp-abilities/v1/` namespace routes if
a matching rule is ever added to `Action_Registry`.

**Monitoring action items:**

1. Watch the [abilities-api](https://github.com/WordPress/abilities-api) GitHub
   repository for new ability registrations, especially any using `DELETE` on `/run`.
2. When destructive abilities appear, add a REST rule to `Action_Registry` matching
   `/wp-abilities/v1/.*/run` with `DELETE` method. No `Gate` class changes required.
   This also covers MCP Adapter calls (same REST endpoints).
3. For WP-CLI `wp ability run` with destructive abilities, add a function-level hook
   in `Gate::register_function_hooks()` targeting the appropriate WordPress action.
4. Reassess the need for an `ability` surface type only if a non-REST, non-CLI
   ability execution path is introduced.
5. Monitor the WordPress MCP Adapter for any direct-execution path that bypasses REST
   (none exists as of WP 7.0 Beta 1).
