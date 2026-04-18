# Documentation Remediation Checklist

Created: 2026-04-17
Purpose: track the cleanup work from the repo-wide documentation, research, and planning audit.

## P1 — Immediate accuracy fixes

- [x] Remove hardcoded dynamic rule/hook counts from `AGENTS.md` and `CLAUDE.md`; point both files to `docs/current-metrics.md` instead.
- [x] Add a canonical current release-state document (`docs/release-status.md`) and point contributor/maintainer docs to it.
- [x] Correct public metadata drift: `readme.txt` now stays at `Tested up to: 6.9` until WordPress 7.0 final actually ships; `readme.md` badge follows the same rule.
- [x] Clean `docs/ROADMAP.md` of stale fixed-date WordPress 7.0 final references and remove the fabricated `wp_sudo_lockout` `type` payload claim.
- [x] Correct the user-facing settings help copy that still claimed WP Sudo fires 9 audit hooks.

## P2 — Structural documentation governance

- [x] Mark the major `.planning/` state/status/codebase summary docs as **historical planning snapshots** so they are no longer mistaken for current canonical state.
- [x] Add `.planning/README.md` to explain that `.planning/` is historical working material unless explicitly marked current.
- [x] Refresh `CONTRIBUTING.md` to stop duplicating volatile live counts and point contributors at `docs/current-metrics.md` and `docs/release-status.md`.

## P3 — Ongoing drift prevention

- [x] Add a lightweight documentation drift checklist to `CONTRIBUTING.md`.
- [x] Update maintainer instruction docs to treat `docs/current-metrics.md` and `docs/release-status.md` as the only canonical sources for current counts and release state.
- [x] Optional follow-up: add a dedicated docs-lint or grep-based CI check for stale fixed-date references and old count patterns outside canonical docs.

## Notes

- `docs/current-metrics.md` remains the canonical source for current counts.
- `docs/release-status.md` is now the canonical source for stable vs `main` release state and WordPress 7.0 forward-lane posture.
- `.planning/` remains useful for project history, but should no longer be relied on as current-state documentation without explicit confirmation.
