# Contributing to WP Sudo

## Prerequisites

- PHP 8.1+
- Composer
- MySQL 8.0 (for integration tests only)
- SVN + unzip (for integration test setup only — `apt install subversion unzip` on Ubuntu, `brew install subversion` on macOS)

## Setup

```bash
composer install
```

## Running Tests

### Unit tests (fast, no database)

```bash
composer test:unit
```

Runs in ~0.3s. No external dependencies — all WordPress functions are mocked with Brain\Monkey.

### Integration tests (real WordPress + MySQL)

```bash
# One-time setup: installs WordPress test library and creates test DB
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest

# Run tests
composer test:integration
```

Set `WP_MULTISITE=1` to run the multisite test suite:

```bash
WP_MULTISITE=1 composer test:integration
```

### Static analysis + code style

```bash
composer analyse   # PHPStan level 6
composer lint      # PHPCS (WordPress-Extra + WordPress-Docs + VIP rulesets)
composer lint:fix  # Auto-fix PHPCS violations
```

## Test Strategy

Two environments are used deliberately — choose based on what you are testing:

**Unit tests** (`tests/Unit/`) use Brain\Monkey to mock all WordPress functions. Use for:
- Request matching logic (Gate surfaces, action registry)
- Session state machine and policy enforcement
- Hook registration
- Settings sanitization and defaults
- Upgrader migration logic

**Integration tests** (`tests/Integration/`) load real WordPress against a MySQL database. Use for:
- Full reauth flows (Gate → Challenge → Session → Stash)
- Real bcrypt password verification (`wp_check_password`)
- Transient TTL and cookie behavior
- REST and AJAX gating
- Two Factor plugin interaction
- Multisite session isolation
- Upgrader migrations against real DB

When in doubt: if the test needs a real database, real crypto, or calls that cross class boundaries in production, write an integration test.

## TDD Workflow

1. Write a failing test first — commit or show it before writing production code
2. Write the minimum production code to pass
3. `composer test:unit` must pass before every commit
4. `composer analyse` must pass before every commit (PHPStan level 6, zero errors)

## MU-Plugin (Local Dev)

The one-click installer on the Settings page copies the shim via `file_put_contents`. If it fails due to file permissions in your local environment, install manually:

1. Copy `mu-plugin/wp-sudo-gate.php` from the plugin directory
2. Paste it into `wp-content/mu-plugins/wp-sudo-gate.php` (create the directory if needed)
3. The mu-plugin will be active on the next page load

## Commit Conventions

Use conventional commit format. Run tests and static analysis before committing.

## Extending Gated Actions

Use the `wp_sudo_gated_actions` filter. See [docs/developer-reference.md](docs/developer-reference.md) for the rule structure and examples.

## Audit Hooks

Nine action hooks are available for external logging. See [docs/developer-reference.md](docs/developer-reference.md).

> **Note:** `wp_sudo_action_allowed` appears in Site Health debug info for historical reasons but is not fired by the current three-tier policy model.
