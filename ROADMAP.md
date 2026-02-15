# WP Sudo — Roadmap

Remaining enhancements for future releases. Items marked ✅ were proposed in the
original v2 roadmap and have been implemented.

## Completed (v2.0.0–v2.1.0)

These items from the original roadmap are done:

- ✅ **Site Health integration** — MU-plugin status, session duration audit, entry
  point policy review, stale session cleanup.
- ✅ **Progressive rate limiting** — attempts 1–3 immediate, attempt 4 delayed 2 s,
  attempt 5 delayed 5 s, attempt 6+ locked out 5 min.
- ✅ **CSP-compatible asset loading** — all scripts are enqueued external files; no
  inline `<script>` blocks.
- ✅ **Lockout countdown timer** — remaining lockout seconds displayed on the
  challenge page.
- ✅ **Admin notice fallback for AJAX/REST gating** — transient-based notice with
  challenge link on next page load.
- ✅ **Gated actions reference table** — read-only table on the settings page
  showing all registered rules, categories, and surfaces.
- ✅ **Modal elimination (v2 architecture)** — the v1 modal + fetch/jQuery
  monkey-patching was replaced entirely by the stash-challenge-replay pattern.
- ✅ **Editor `unfiltered_html` restriction** — stripped from editors on activation,
  tamper detection canary at `init` priority 1.

## Open — Medium Effort

### 1. WP Activity Log (WSAL) Sensor Extension

Optional WSAL sensor shipping as a single PHP file. Register event IDs in the
8900+ range, create a sensor class in the `WSAL\Plugin_Sensors` namespace, and
map existing `wp_sudo_*` action hooks to WSAL alert triggers.

**Impact:** High — dramatically increases appeal to managed hosting and enterprise
customers who already use WSAL.

### 2. Multi-Dimensional Rate Limiting (IP + User)

Add per-IP tracking via transients alongside existing per-user tracking. Catches
distributed attacks where multiple IPs target the same user, or one IP targets
multiple users. Include IP in the `wp_sudo_lockout` audit hook for logging.

**Impact:** High — hardens brute-force protection against coordinated attacks.

### 3. Session Activity Dashboard Widget

Admin dashboard widget showing:
- Active sudo sessions on the site (count + user list).
- Recent gated operations (last 24 h from audit hooks).
- Policy summary.

On multisite, a network admin widget could show activity across all sites.

**Note:** Requires storing audit data — currently the hooks fire-and-forget with
no persistence. A lightweight custom table or transient-based ring buffer would
be needed.

**Impact:** Medium — useful visibility for site administrators, but not a security
improvement.

## Open — Low Effort

### 6. AI and Agentic Tool Guidance

Documentation section (README + help tab) covering how AI assistants and automated
agents interact with WP Sudo policies. AI tools don't introduce a new WordPress
surface — they use REST API (app passwords), WP-CLI, or browser-based cookie auth,
all of which are already gated by existing policies.

Topics to cover:
- Browser-based AI (Gutenberg sidebar assistants, Jetpack AI) → cookie-auth REST
  path → `sudo_required` like any other browser request.
- Headless AI agents (deployment bots, MCP-based tools, CI/CD) → app-password REST
  or WP-CLI → governed by the REST and CLI policies.
- Recommended policy configurations for common AI workflows.
- Why "Limited" is the correct default: AI agents performing non-gated operations
  (content creation, media uploads) are unaffected, while gated operations
  (plugin activation, user deletion) are blocked.

**Impact:** Medium — increasingly relevant as AI-powered site management tools
proliferate. No code changes, just documentation.

### 7. Per-Application-Password Policies

Allow different REST API policies per application password. Currently, one policy
governs all non-cookie REST auth. An AI agent's application password might warrant
`limited` while a trusted deployment pipeline's password could be `unrestricted`.

Implementation: store policy overrides keyed by application password UUID in
`wp_sudo_settings`. Check the authenticated app password ID in `intercept_rest()`
before falling back to the global policy. Expose per-password policy dropdowns on
the user profile Application Passwords section.

**Impact:** High — enables fine-grained control over automated access without
weakening the default policy for all app passwords. Especially valuable when
multiple AI tools and automation pipelines share the same site.

## Open — High Effort

### 8. Gutenberg Block Editor Integration

Detect block editor context and queue the reauthentication requirement instead of
interrupting save. Show a snackbar-style notice using the `@wordpress/notices`
API. Requires specific Gutenberg awareness and testing across WordPress versions.

**Impact:** Medium — improves UX for block editor users, but the current
stash-replay pattern already works for most editor operations.

### 9. Network Policy Hierarchy for Multisite

Super admins set minimum session duration and maximum allowed entry-point policies
at the network level. Site admins can only tighten (not loosen) these constraints.

**Impact:** Medium — valuable for large multisite networks with delegated site
administration. Not relevant for single-site installs.

## Accessibility Improvements

See [ACCESSIBILITY-ROADMAP.md](ACCESSIBILITY-ROADMAP.md) for the full list.
Summary:

**Medium priority (6 items):**
1. Challenge page Escape key navigates without warning.
2. Challenge page 2FA step-change announcement.
3. Settings page label-input association audit.
4. Replay form accessible context.
5. Localize hardcoded JavaScript strings.
6. Challenge page auto-reload on session expiry.

**Low priority (4 items):**
7. Admin bar countdown cleanup on page unload.
8. Settings page default value documentation.
9. Lockout countdown timer precision for screen readers.
10. Admin bar timer keyboard navigation.

## Toolchain Candidates

These tools have been evaluated and are worth adding to the project when the time is
right. They don't block any current work but would improve quality assurance.

### PHPStan (Static Analysis)

Add PHPStan level 6 with `szepeviktor/phpstan-wordpress` for static type analysis
beyond what PHPCS catches. PHPStan finds type errors, dead code, impossible
conditions, and missing return types that PHPCS ignores. The `phpstan-wordpress`
extension understands WordPress function signatures, hooks, and globals.

**When to add:** Next time the test/lint toolchain is touched. Add a `composer
analyse` script alongside `composer test` and `composer lint`.

**Reference:** [wp-dev-prompts/testing-setup](https://github.com/courtneyr-dev/wp-dev-prompts/blob/main/prompts/core/testing-setup.md)
recommends PHPStan level 6 as part of the standard WordPress plugin CI matrix.

### UI/UX Testing Prompts

Structured prompt frameworks for UI/UX validation that could be run against WP Sudo's
admin pages:

- **Heuristic evaluation** — Nielsen's 10 heuristics applied to the settings page,
  challenge page, and admin bar timer.
- **Navigation flow tests** — verify that gating, challenge, and replay flows don't
  break expected WordPress navigation patterns.
- **Responsive tests** — validate challenge page and admin bar timer across viewport
  sizes.

**When to use:** Before any major UI change or when addressing the accessibility
roadmap items.

**Reference:** [wp-dev-prompts/testing/](https://github.com/courtneyr-dev/wp-dev-prompts/tree/main/prompts/testing)
— heuristic-evaluation.md, navigation-flow-tests.md, responsive-tests.md,
ui-ux-audit.md.

## Declined

These items were considered and intentionally excluded:

- **Session extension** — allowing users to extend an active session without
  reauthentication would undermine the time-bounded trust model and violate
  zero-trust principles. The keyboard shortcut (`Cmd+Shift+S` / `Ctrl+Shift+S`)
  makes re-authentication fast enough that extending is unnecessary.

- **Passkey/WebAuthn reauthentication** — already works through the existing
  Two Factor plugin integration. WP Sudo's challenge page is provider-agnostic:
  it renders whatever HTML/JS the active 2FA provider outputs, including
  WebAuthn's `navigator.credentials.get()` ceremony. The
  [WebAuthn Provider for Two Factor](https://wordpress.org/plugins/two-factor-provider-webauthn/)
  plugin is recommended in the readme. No WP Sudo changes needed.
