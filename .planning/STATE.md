# Project State: WP Sudo v2.4

**Updated:** 2026-02-19
**Current phase:** Phase 1 complete — Phase 2 next
**Milestone:** Integration Tests & WP 7.0 Readiness

## Phase Status

| Phase | Name | Status | Requirements |
|-------|------|--------|-------------|
| 1 | Integration Test Harness Scaffold | ✅ Complete | HARN-01–07 |
| 2 | Core Security Integration Tests | ⬜ Not started | INTG-01–04 |
| 3 | Surface Coverage Tests | ⬜ Not started | SURF-01–05 |
| 4 | Advanced Coverage (Two Factor + Multisite) | ⬜ Not started | ADVN-01–03 |
| 5 | WP 7.0 Readiness | ⬜ Not started | WP70-01–04 |

## Completed Work (Pre-Phase)

- Codebase mapped (7 docs, 1,575 lines) — `8196f46`
- PROJECT.md written — `493242a`
- config.json written — `ce71da4`
- Research completed (4 agents: Stack, Features, Architecture, Pitfalls)
- Research synthesized — `581d5f4`
- Requirements defined (23 requirements) — `695869a`
- Roadmap created (5 phases) — `312a145`

## Phase 1 Completion

- PLAN.md committed — `03c2656`
- yoast/phpunit-polyfills ^2.0 added; composer test:unit and test:integration scripts added — `718e14c`
- phpunit-integration.xml.dist, tests/integration/bootstrap.php, tests/integration/TestCase.php created — `aa1c837`
- bin/install-wp-tests.sh added from wp-cli/scaffold-command — `7d89c85`
- .github/workflows/phpunit.yml created with unit (PHP 8.1-8.4) and integration (PHP 8.1/8.3 x WP latest/trunk) jobs — `324ba1c`
- SUMMARY.md committed — (pending final commit)
- All 343 unit tests passing, zero regressions

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
| Multisite CI approach | `WP_TESTS_MULTISITE=1` env var in matrix | Phase 4 (decided) |
| WP_TESTS_PHPUNIT_POLYFILLS_PATH placement | Defined before WP bootstrap require | Phase 1 |
| Plugin load hook | muplugins_loaded (not plugins_loaded) | Phase 1 |
| Integration bootstrap isolation | Must NOT require tests/bootstrap.php | Phase 1 |
| Integration config strict flags | beStrictAboutOutputDuringTests/failOnWarning omitted | Phase 1 |
| CI MySQL connection | 127.0.0.1 not localhost (TCP, not Unix socket) | Phase 1 |
| CI runner | ubuntu-24.04 explicit (not ubuntu-latest) | Phase 1 |
| Integration test PHP matrix | PHP 8.1+8.3 (subset for speed); unit runs 8.1-8.4 | Phase 1 |

## Open Decisions

| Decision | Options | Status |
|----------|---------|--------|
| Two Factor in test harness | Composer path repo / git submodule / CI download | Open — Phase 4 |
| Rate limiting `sleep()` bypass | Test-only filter vs unit-test-only | Open — Phase 3 |

## Known Risks

- WP 7.0 Beta 1 changelog not yet published (as of 2026-02-19)
- `sleep()` in `record_failed_attempt()` makes rate-limiting integration tests slow
- LLM confabulation — 5 documented instances in `llm_lies_log.txt`; all external refs must be verified

## Performance Metrics

| Phase | Plan | Duration | Tasks | Files |
|-------|------|----------|-------|-------|
| 01 | 01 | 3 min | 5 | 7 |

## Git State

- Branch: `main`
- Last commit: `324ba1c` (CI workflow)
- Tag: `v2.3.2` (12 commits behind HEAD — all docs/chore/feat)

---
*State initialized: 2026-02-19*
*Last session: 2026-02-19 — Completed Phase 1 (01-integration-test-harness-scaffold)*
*Next action: `/gsd:plan-phase 2`*
