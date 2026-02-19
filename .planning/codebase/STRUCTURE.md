# Codebase Structure

**Analysis Date:** 2026-02-19

## Directory Layout

```
wp-sudo/
├── wp-sudo.php                 # Entry point: constants, autoloader, plugin hooks
├── uninstall.php               # Uninstall handler: cleanup on plugin deletion
├── includes/                   # Core plugin classes (WP_Sudo namespace)
│   ├── class-plugin.php        # Orchestrator: wires all components
│   ├── class-gate.php          # Multi-surface interceptor
│   ├── class-action-registry.php # Gated action definitions
│   ├── class-challenge.php     # Reauthentication interstitial
│   ├── class-sudo-session.php  # Session state management
│   ├── class-request-stash.php # Request serialization and replay
│   ├── class-admin.php         # Settings page (single + multisite)
│   ├── class-admin-bar.php     # Countdown timer in admin bar
│   ├── class-site-health.php   # WordPress Site Health integration
│   └── class-upgrader.php      # Version-aware migrations
├── admin/                      # Admin UI assets
│   ├── js/                     # JavaScript (ES5, no build step)
│   │   ├── wp-sudo-challenge.js         # Challenge page controller
│   │   ├── wp-sudo-challenge.js         # Challenge interstitial UX
│   │   ├── wp-sudo-admin.js             # Settings page interactions
│   │   ├── wp-sudo-admin-bar.js         # Admin bar countdown timer
│   │   ├── wp-sudo-app-passwords.js     # Per-app-password policy overrides
│   │   ├── wp-sudo-gate-ui.js           # Gate notice rendering
│   │   └── wp-sudo-shortcut.js          # Keyboard shortcut (Ctrl+Shift+S)
│   └── css/                    # Stylesheets
│       ├── wp-sudo-challenge.css        # Challenge page styles
│       ├── wp-sudo-admin.css            # Settings page styles
│       └── wp-sudo-admin-bar.css        # Admin bar countdown styles
├── mu-plugin/                  # Early-loading hooks for CLI/Cron/XML-RPC
│   ├── wp-sudo-loader.php      # Versioned loader (ships with plugin)
│   └── wp-sudo-gate.php        # Stable shim (copied to wp-content/mu-plugins/)
├── languages/                  # Translation files (.pot, .po, .mo)
├── tests/                      # Unit tests (PHPUnit 9.6 + Brain\Monkey)
│   ├── bootstrap.php           # Test environment setup
│   ├── TestCase.php            # Base test class with helpers
│   └── Unit/                   # Test files (one per class)
│       ├── PluginTest.php
│       ├── GateTest.php
│       ├── ActionRegistryTest.php
│       ├── ChallengeTest.php
│       ├── SudoSessionTest.php
│       ├── RequestStashTest.php
│       ├── AdminTest.php
│       ├── AdminBarTest.php
│       ├── SiteHealthTest.php
│       └── UpgraderTest.php
├── docs/                       # Developer and user documentation
│   ├── security-model.md       # Threat model, boundaries, assumptions
│   ├── developer-reference.md  # Hook signatures, filter reference, examples
│   ├── FAQ.md                  # Frequently asked questions
│   ├── CHANGELOG.md            # Full version history
│   ├── ai-agentic-guidance.md  # AI tool integration guidance
│   ├── two-factor-integration.md # 2FA plugin integration guide
│   ├── two-factor-ecosystem.md   # 2FA plugin ecosystem survey
│   ├── ui-ux-testing-prompts.md  # Structured testing prompts
│   └── roadmap-2026-02.md        # Development roadmap
├── bridges/                    # Third-party plugin bridges
│   └── wp-sudo-wp2fa-bridge.php # WP 2FA integration (optional)
├── assets/                     # Screenshots and demo files
├── composer.json               # PHP dependencies (dev only)
├── composer.lock               # Locked dependency versions
├── phpunit.xml.dist            # PHPUnit configuration
├── phpstan.neon                # PHPStan configuration (level 6)
├── phpstan-bootstrap.php       # PHPStan WordPress stubs
├── phpcs.xml.dist              # PHPCS code standards (WordPress-Extra, VIP)
├── patchwork.json              # Patchwork configuration (mock headers/cookies)
├── bom.json                    # CycloneDX SBOM for supply chain transparency
├── CLAUDE.md                   # Instructions for Claude Code
├── README.md                   # User-facing readme
├── ROADMAP.md                  # Public roadmap
├── ACCESSIBILITY-ROADMAP.md    # Accessibility improvement plans
└── .planning/codebase/         # GSD codebase analysis docs (this directory)
    ├── ARCHITECTURE.md         # Architecture pattern and layers
    └── STRUCTURE.md            # This file
```

## Directory Purposes

**wp-sudo/ (root):**
- Purpose: Entry point, configuration, lifecycle management
- Contains: Plugin header, constants, autoloader, activation hooks
- Key files: `wp-sudo.php`, `uninstall.php`, `composer.json`, `phpunit.xml.dist`

**includes/:**
- Purpose: Core plugin logic (all business logic)
- Contains: 10 classes in `WP_Sudo` namespace, all hooked into WordPress
- Key files: `class-plugin.php` (orchestrator), `class-gate.php` (interceptor)
- Naming pattern: `class-{kebab-case-name}.php` → `WP_Sudo\{PascalCaseName}`
- Example: `class-sudo-session.php` → `WP_Sudo\Sudo_Session`

**admin/js/:**
- Purpose: Frontend JavaScript for dashboard and challenge page
- Contains: 6 scripts, ES5 syntax (no transpilation), inline i18n via wp_localize_script()
- Key files: `wp-sudo-challenge.js` (challenge controller), `wp-sudo-admin.js` (settings UI)
- No module system; direct DOM manipulation via IDs

**admin/css/:**
- Purpose: Styles for challenge page, settings page, admin bar countdown
- Contains: 3 stylesheets, scoped to specific pages and components
- Enqueueing: Done in Challenge, Admin, Admin_Bar classes via `wp_enqueue_style()`

**mu-plugin/:**
- Purpose: Early hook registration before plugins load
- Contains: 2 files: stable shim + versioned loader
- Pattern: Shim never changes; loader ships with updates; shim delegates to loader
- Reason: Ensures Gate can hook CLI, Cron, XML-RPC before other plugins initialize

**tests/:**
- Purpose: Unit test suite with 100+ test methods
- Contains: Brain\Monkey mocks, Mockery object mocks, Patchwork function mocks
- Key files: `bootstrap.php` (WordPress stubs), `TestCase.php` (helpers)
- One test class per production class (GateTest.php tests Gate.php, etc.)
- Run: `composer test` or `./vendor/bin/phpunit tests/Unit/SpecificTest.php`

**docs/:**
- Purpose: Documentation for users, developers, and operators
- Contains: Threat model, hook reference, FAQ, integration guides
- Key files: `security-model.md` (threat boundaries), `developer-reference.md` (extensibility)

**bridges/:**
- Purpose: Integration shims for third-party plugins
- Contains: Optional integration code that doesn't ship in core
- Example: `wp-sudo-wp2fa-bridge.php` for WP 2FA integration (not installed by default)

## Key File Locations

**Entry Points:**
- `wp-sudo.php`: Main plugin file; defines constants `WP_SUDO_VERSION`, `WP_SUDO_PLUGIN_DIR`, `WP_SUDO_PLUGIN_URL`, `WP_SUDO_PLUGIN_BASENAME`; registers SPL autoloader; registers lifecycle hooks
- `includes/class-plugin.php`: Singleton orchestrator; creates all component instances; called at `plugins_loaded`
- `mu-plugin/wp-sudo-loader.php`: Early bootstrap for non-interactive surfaces; called at `muplugins_loaded`

**Configuration:**
- `includes/class-admin.php`: Settings option key is `wp_sudo_settings`; network-aware (uses `get_site_option()` on multisite)
- `includes/class-action-registry.php`: Action rules returned by static method `rules()`, cached per-request in `$cached_rules`; filterable via `wp_sudo_gated_actions`

**Core Logic:**
- `includes/class-gate.php`: Multi-surface request interception; checks Action_Registry rules against incoming request; fires `wp_sudo_action_gated`, `wp_sudo_action_blocked`, `wp_sudo_action_allowed` audit hooks
- `includes/class-challenge.php`: Handles password verification (AJAX action `wp_sudo_challenge_auth`) and 2FA (AJAX action `wp_sudo_challenge_2fa`); integrates with third-party 2FA via `wp_sudo_challenge_2fa_providers` filter
- `includes/class-sudo-session.php`: Token generation (`wp_generate_password()`), storage (user meta + cookie), validation (timing-safe comparison)
- `includes/class-request-stash.php`: Serializes original request into transient (`_wp_sudo_stash_{key}`); replays via `wp_safe_redirect()` (GET) or self-submitting HTML form (POST)

**Session/State:**
- User meta (Sudo_Session):
  - `_wp_sudo_expires`: Expiry timestamp
  - `_wp_sudo_token`: Session binding token
  - `_wp_sudo_failed_attempts`: Failed attempt count
  - `_wp_sudo_lockout_until`: Lockout timestamp
- Option (Admin):
  - `wp_sudo_settings`: JSON with duration, policies

**UI/Admin:**
- `includes/class-admin.php`: Settings page at Settings → Sudo (single-site) or Network Admin → Settings → Sudo (multisite)
- `includes/class-admin-bar.php`: Adds countdown timer to admin bar during active sessions
- `admin/js/wp-sudo-admin.js`: Settings page form interactions (field validation, MU-plugin installation UI)
- `admin/js/wp-sudo-admin-bar.js`: Countdown timer in admin bar (updates every second)

**Testing:**
- `tests/bootstrap.php`: Defines WordPress constants and minimal stubs (`WP_User`, `WP_Role`, `WP_Admin_Bar`)
- `tests/TestCase.php`: Base class extending PHPUnit's TestCase; sets up Brain\Monkey; provides helpers `make_user()`, `make_role()`
- Test files in `tests/Unit/`: One per class, covering both happy paths and edge cases

## Naming Conventions

**Files:**
- Class files: `class-{kebab-case-name}.php` (e.g., `class-sudo-session.php`)
- Test files: `{ClassName}Test.php` (e.g., `SudoSessionTest.php`)
- Template files (admin): Named by component and type (e.g., challenge page renders via Challenge class, not a separate template file)
- Asset files: `wp-sudo-{component}.js`, `wp-sudo-{component}.css` (e.g., `wp-sudo-challenge.js`)

**Directories:**
- Kebab-case: `admin/`, `mu-plugin/`, `includes/`, `tests/`, `bridges/`
- Feature grouping: `admin/js/`, `admin/css/` (not split by route or responsibility)

**Classes:**
- Namespace: `WP_Sudo`
- Class names: PascalCase with underscores where meaningful (e.g., `Sudo_Session`, `Admin_Bar`)
- Constants: `UPPERCASE_WITH_UNDERSCORES` (e.g., `SETTING_CLI_POLICY`, `TOKEN_META_KEY`)

**Functions:**
- Plugin accessor: `wp_sudo()` (returns singleton Plugin instance)
- Filters: `wp_sudo_{noun}` (e.g., `wp_sudo_gated_actions`, `wp_sudo_challenge_2fa_providers`)
- Actions: `wp_sudo_{verb}` (e.g., `wp_sudo_action_gated`, `wp_sudo_reauth_failed`)

**Variables:**
- Camel case in JavaScript: `wpSudoChallenge` (config object), `stashKey` (function params)
- Snake case in PHP: `$session`, `$stash_key`, `$matched_rule`
- WordPress conventions: `$_GET`, `$_POST`, `$_COOKIE` (preserved)

## Where to Add New Code

**New Feature (e.g., new gated action type):**
- Primary code: `includes/class-action-registry.php` — add rule to `rules()` method
- Tests: `tests/Unit/ActionRegistryTest.php` — add test case for new rule
- Integration: `includes/class-gate.php` — no changes needed (Gate reads Action_Registry rules)

**New Component/Module (e.g., custom audit logger):**
- Implementation: `includes/class-{component-name}.php` (follows naming pattern)
- Instantiation: `includes/class-plugin.php` — add instance variable and creation in `init()` method
- Tests: `tests/Unit/{ComponentName}Test.php` — follow existing test structure

**Utilities and Helpers:**
- Shared helpers (not tied to a class): Add static methods to `Plugin` class or create a new `Helper` class
- Do not create loose functions; keep everything in classes for testability

**JavaScript (Client-Side Logic):**
- Challenge page behavior: `admin/js/wp-sudo-challenge.js`
- Settings page interactions: `admin/js/wp-sudo-admin.js`
- Admin bar countdown: `admin/js/wp-sudo-admin-bar.js`
- New scripts: Follow existing pattern (IIFE, wp_localize_script() for config/i18n, strict mode)

**Styles:**
- Challenge page: `admin/css/wp-sudo-challenge.css`
- Settings page: `admin/css/wp-sudo-admin.css`
- Admin bar: `admin/css/wp-sudo-admin-bar.css`

**Documentation:**
- Architecture/design: `docs/` directory (e.g., `docs/security-model.md`)
- Code-level docs: JSDoc/PHPDoc in source files
- External integrations: `docs/developer-reference.md` or new integration guide in `docs/`

## Special Directories

**assets/:**
- Purpose: Screenshots and demo files for readme/marketplace
- Generated: No
- Committed: Yes

**vendor/:**
- Purpose: Composer dependencies (dev only: PHPUnit, Brain\Monkey, Mockery, PHPStan, PHPCS)
- Generated: Yes (via `composer install`)
- Committed: No (listed in `.gitignore`)

**.planning/codebase/:**
- Purpose: GSD (Goal-Seeking Development) analysis documents
- Generated: Yes (by Claude mapper tool)
- Committed: Yes (documentation artifacts)

**node_modules/ (if applicable):**
- Purpose: JavaScript dependencies (currently none; no build step)
- Generated: N/A
- Committed: N/A

**languages/:**
- Purpose: Translation files (.pot source, .po translated, .mo compiled)
- Generated: Partially (pot is generated, po/mo are translated)
- Committed: Yes

## File Naming Pattern Summary

| Type | Pattern | Example |
|------|---------|---------|
| Class files | `class-{kebab-case}.php` | `class-sudo-session.php` |
| Class name | `WP_Sudo\{PascalCase}` | `WP_Sudo\Sudo_Session` |
| Test files | `{ClassName}Test.php` | `SudoSessionTest.php` |
| JS files | `wp-sudo-{component}.js` | `wp-sudo-challenge.js` |
| CSS files | `wp-sudo-{component}.css` | `wp-sudo-challenge.css` |
| Constants | `UPPERCASE_WITH_UNDERSCORES` | `META_KEY`, `TOKEN_COOKIE` |
| Methods | `snake_case()` | `handle_ajax_auth()` |
| Variables | `$snake_case` | `$stash_key`, `$session` |

---

*Structure analysis: 2026-02-19*
