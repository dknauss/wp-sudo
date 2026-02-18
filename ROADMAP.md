# WP Sudo — Roadmap

Remaining enhancements for future releases. Items marked ✅ were proposed in the
original v2 roadmap and have been implemented.

## Completed (v2.0.0–v2.3.0)

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
- ✅ **Per-application-password policies** (v2.3.0) — per-password REST API policy
  overrides stored by UUID. Dropdown on the user profile Application Passwords
  section. AI agents and deployment pipelines can have distinct policies.
- ✅ **AI and agentic tool guidance** (v2.3.0) — documentation covering how AI
  assistants and automated agents interact with WP Sudo policies across cookie,
  REST API, and WP-CLI surfaces.
- ✅ **PHPStan level 6** (v2.3.0) — static analysis with `szepeviktor/phpstan-wordpress`.
  Integrated as `composer analyse` alongside `test` and `lint`.
- ✅ **UI/UX testing prompts** (v2.3.0) — structured prompt frameworks for heuristic
  evaluation, navigation flow, and responsive testing.

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
All medium-priority items (1–6) were resolved in v2.2.0–v2.3.0.

**Low priority (4 items remaining):**
7. Admin bar countdown cleanup on page unload.
8. Settings page default value documentation.
9. Lockout countdown timer precision for screen readers.
10. Admin bar timer keyboard navigation.

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
