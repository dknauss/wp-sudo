# Project State: WP Sudo v2.4

**Updated:** 2026-02-19
**Current phase:** Phase 5 — Plan 01 complete; Plan 02 next
**Milestone:** Integration Tests & WP 7.0 Readiness

## Phase Status

| Phase | Name | Status | Requirements |
|-------|------|--------|-------------|
| 1 | Integration Test Harness Scaffold | ✅ Complete | HARN-01–07 |
| 2 | Core Security Integration Tests | ✅ Complete | INTG-01–04 |
| 3 | Surface Coverage Tests | ✅ Complete | SURF-01–05 |
| 4 | Advanced Coverage (Two Factor + Multisite) | ✅ Complete | ADVN-01–03 |
| 5 | WP 7.0 Readiness | 🔄 In progress — Plan 01 complete | WP70-01–04 |

## Completed Work (Pre-Phase)

- Codebase mapped (7 docs, 1,575 lines) — `8196f46`
- PROJECT.md written — `493242a`
- config.json written — `ce71da4`
- Research completed (4 agents: Stack, Features, Architecture, Pitfalls)
- Research synthesized — `581d5f4`
- Requirements defined (23 requirements) — `695869a`
- Roadmap created (5 phases) — `312a145`

## Phase 4 Completion

- Two Factor plugin installed in test harness (install-wp-tests.sh `install_plugins()`, bootstrap.php `muplugins_loaded`)
- TwoFactorTest (7 tests: ADVN-01 real Two Factor plugin detection, 2FA pending state machine, challenge cookie/transient lifecycle, filter override)
- MultisiteTest (5 tests: ADVN-02 network-wide settings, user meta, cookie-based session isolation, stash transients, upgrader site_option)
- CI multisite matrix (ADVN-03): `multisite: [false, true]` dimension → 12 total jobs (4 unit + 8 integration)
- Multisite compatibility fixes: TestCase `update_wp_sudo_option()`/`get_wp_sudo_option()` helpers; UpgraderTest/RestGatingTest/RequestStashTest use multisite-aware option/transient APIs
- Upgrader 2.0.0 migration uses `get_option()` not `get_site_option()` — documented as known limitation on multisite (role removal still works, settings cleanup is blog-scoped)
- `enforce_editor_unfiltered_html()` is a no-op on multisite — tests guard with `if ( ! is_multisite() )`
- `WP_MULTISITE=1` env var (not `WP_TESTS_MULTISITE`) — matches WP test bootstrap detection
- `curl -sL` flag added to `download()` helper for HTTP redirect support (wordpress.org plugin zips redirect)
- 343 unit tests passing, 55 integration tests passing (50 on single-site + 5 multisite-only), 0 failures
- No Brain\Monkey contamination in tests/Integration/

## Phase 3 Completion

- UpgraderTest (4 tests: SURF-01 migration chain, skip-when-current, partial migration, valid-value preservation) — `3017805`
- RestGatingTest (7 tests: SURF-02 cookie-auth gating, SURF-03 app-password policies) — `85effc4`
- AuditHooksTest (5 tests: SURF-04 argument verification for all audit hooks) — `0bb282c`
- RateLimitingTest (6 tests: SURF-05 user meta operations, lockout simulation) — `b38abcd`
- 343 unit tests passing, 43 integration tests passing, 0 failures
- No Brain\Monkey contamination in tests/Integration/
- CI: 8/8 jobs green
- Sleep strategy: lockout branch returns 0 before progressive delay — @group slow test runs in <1s
- Open decision resolved: Rate limiting sleep() bypass — NOT NEEDED (lockout branch skips sleep)

## Phase 2 Completion

- PLAN.md read — `07af246` (test files commit references)
- TestCase base class enhanced (superglobal isolation, cache resets, simulate_admin_request()) — `4eb7d7c`
- SudoSessionTest (10 tests: INTG-02 bcrypt, INTG-03 token binding), RequestStashTest (7 tests: INTG-04 transients), ReauthFlowTest (4 tests: INTG-01 full flow) — `07af246`
- Deviation fix: headers_sent() guard for setcookie() in CLI/integration-test contexts; WP 6.8+ $wp$2y$ prefix assertion — `60cef31`
- 343 unit tests passing, 21 integration tests passing, 0 failures
- No Brain\Monkey contamination in tests/integration/

## Phase 1 Completion

- PLAN.md committed — `03c2656`
- yoast/phpunit-polyfills ^2.0 added; composer test:unit and test:integration scripts added — `718e14c`
- phpunit-integration.xml.dist, tests/integration/bootstrap.php, tests/integration/TestCase.php created — `aa1c837`
- bin/install-wp-tests.sh added from wp-cli/scaffold-command — `7d89c85`
- .github/workflows/phpunit.yml created with unit (PHP 8.1-8.4) and integration (PHP 8.1/8.3 x WP latest/trunk) jobs — `324ba1c`
- SUMMARY.md committed — `0c0b380`
- CI fix: svn installed via apt-get (not setup-php tools) — `7c511b6`
- CI fix: removed MYSQL_DATABASE to avoid interactive prompt — `19da598`
- All 343 unit tests passing, zero regressions
- `composer test:integration` passes locally (empty suite, 0 tests)
- GitHub Actions CI: 8/8 jobs green (4 unit + 4 integration)

## Dev Environment

| Site | Tool | WP Version | URL | wp-sudo |
|------|------|------------|-----|---------|
| Individual Sudo Dev Site | Studio | 7.0-alpha-61682 | localhost:8883 | 2.3.2 active |
| Individual Sudo Dev Site | Local | 7.0-alpha-61682 | localhost:10045 | 2.3.2 active |

**Local WP-CLI:**
```
PHP_BIN="/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.27+1/bin/darwin-arm64/bin/php"
WP_CLI="/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp"
SITE="/Users/danknauss/Local Sites/individual-sudo-dev-site/app/public"
SOCK="/Users/danknauss/Library/Application Support/Local/run/y2n1whA9B/mysql/mysqld.sock"
```

## Decisions Made

| Decision | Choice | Phase |
|----------|--------|-------|
| phpunit-polyfills version | ^2.0 | Phase 1 |
| Multisite CI approach | `WP_MULTISITE=1` env var in matrix (matches WP bootstrap) | Phase 4 |
| Two Factor in test harness | CI download via `install-wp-tests.sh` + self-guarding `markTestSkipped` | Phase 4 |
| Multisite option API | `update_wp_sudo_option()` / `get_wp_sudo_option()` helpers in TestCase | Phase 4 |
| WP_TESTS_PHPUNIT_POLYFILLS_PATH placement | Defined before WP bootstrap require | Phase 1 |
| Plugin load hook | muplugins_loaded (not plugins_loaded) | Phase 1 |
| Integration bootstrap isolation | Must NOT require tests/bootstrap.php | Phase 1 |
| Integration config strict flags | beStrictAboutOutputDuringTests/failOnWarning omitted | Phase 1 |
| CI MySQL connection | 127.0.0.1 not localhost (TCP, not Unix socket) | Phase 1 |
| CI SVN install | apt-get (not setup-php tools — svn not a supported tool name) | Phase 1 |
| CI MySQL service | No MYSQL_DATABASE pre-creation (avoids interactive prompt) | Phase 1 |
| CI runner | ubuntu-24.04 explicit (not ubuntu-latest) | Phase 1 |
| Integration test PHP matrix | PHP 8.1+8.3 (subset for speed); unit runs 8.1-8.4 | Phase 1 |
| setcookie() in CLI/integration contexts | headers_sent() guard on all call sites | Phase 2 |
| WP 6.8+ bcrypt prefix | Assert $wp$2y$ OR $2y$ for portability | Phase 2 |
| headers_sent mocking | Add to patchwork.json redefinable-internals | Phase 2 |
| Abilities API gating for WP 7.0 | No changes needed — all 3 core abilities are read-only | Phase 5 |
| Future destructive ability gating | Action_Registry REST rule (no new Gate surface type) | Phase 5 |

## Open Decisions

| Decision | Options | Status |
|----------|---------|--------|
| Rate limiting `sleep()` bypass | NOT NEEDED — lockout branch returns before sleep() | Resolved — Phase 3 |

## Known Risks

- WP 7.0 Beta 1 changelog not yet published (as of 2026-02-19)
- Plan 05-03 (readme.txt "Tested up to" bump) time-gated — re-execute on or after 2026-04-09
- ~~`sleep()` in `record_failed_attempt()` makes rate-limiting integration tests slow~~ Resolved: lockout branch returns before sleep()
- LLM confabulation — 5 documented instances in `llm_lies_log.txt`; all external refs must be verified

## Phase 5 Completion

- WP 7.0 visual compatibility section (section 15, 5 subsections) added to `tests/MANUAL-TESTING.md` — `3d71329`
- Abilities API assessment document created (`docs/abilities-api-assessment.md`) — `129eb63`
- No Gate changes required for WP 7.0: all 3 core abilities are read-only
- Future destructive abilities can be gated via Action_Registry REST rule (no new Gate surface type needed)
- Plan 05-03 (readme.txt "Tested up to" bump) time-gated — re-execute on or after 2026-04-09

## Performance Metrics

| Phase | Plan | Duration | Tasks | Files |
|-------|------|----------|-------|-------|
| 01 | 01 | 3 min | 5 | 7 |
| 02 | 02 | 9 min | 5 | 7 |
| 03 | 03 | 5 min | 5 | 4 |
| 05 | 01 | 2 min | 2 | 2 |

## Decisions Made (Phase 5)

| Decision | Choice | Phase |
|----------|--------|-------|
| Abilities API gating for WP 7.0 | No changes needed — all 3 core abilities read-only | Phase 5 |
| Future destructive ability gating | Action_Registry REST rule (no new Gate surface type) | Phase 5 |

## Git State

- Branch: `main`
- Last commit: `129eb63` (Phase 5 Plan 01 complete)
- Tag: `v2.3.2` (20+ commits behind HEAD — all docs/chore/feat/test)

---
*State initialized: 2026-02-19*
*Last session: 2026-02-19 — Completed Phase 5 Plan 01 (05-wp-7-0-readiness docs)*
*Next action: Execute Phase 5 Plan 02; re-execute Plan 03 on or after 2026-04-09*
