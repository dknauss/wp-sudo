# Project State: WP Sudo v2.4

**Updated:** 2026-02-19
**Current phase:** Not started — Phase 1 next
**Milestone:** Integration Tests & WP 7.0 Readiness

## Phase Status

| Phase | Name | Status | Requirements |
|-------|------|--------|-------------|
| 1 | Integration Test Harness Scaffold | ⬜ Not started | HARN-01–07 |
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

## Open Decisions

| Decision | Options | Status |
|----------|---------|--------|
| phpunit-polyfills version | ^2.0 (research consensus) | Decided: ^2.0 |
| Two Factor in test harness | Composer path repo / git submodule / CI download | Open — Phase 4 |
| Multisite CI approach | `WP_TESTS_MULTISITE=1` env var in matrix | Decided |
| Rate limiting `sleep()` bypass | Test-only filter vs unit-test-only | Open — Phase 3 |

## Known Risks

- WP 7.0 Beta 1 changelog not yet published (as of 2026-02-19)
- `sleep()` in `record_failed_attempt()` makes rate-limiting integration tests slow
- LLM confabulation — 5 documented instances in `llm_lies_log.txt`; all external refs must be verified

## Git State

- Branch: `main`
- Last commit: `312a145` (roadmap)
- Tag: `v2.3.2` (8 commits behind HEAD — all docs/chore)

---
*State initialized: 2026-02-19*
*Next action: `/gsd:plan-phase 1`*
