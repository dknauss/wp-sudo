# Release Status (Canonical Current State)

Last verified: 2026-04-21

This file is the canonical source for **current release state** in this repository:

- the latest public/stable WP Sudo version
- unreleased work already present on `main`
- the latest stable WordPress release WP Sudo should advertise in public metadata
- the forward WordPress lane used in CI, Playground, and manual verification
- the current status of the delayed WordPress 7.0 final release

## Latest public/tagged release

- **Latest tagged release:** `3.0.0`
- **Latest git tag observed:** `v3.0.0`

## Current `main` release target

- **Next planned release:** `3.1.0` (planning lane)
- **Current `main` runtime version constant:** `3.0.0` (post-release bump to `3.1.0` pending)
- **Current `main` metadata should match:** `readme.txt` stable tag, `wp-sudo.php`, `tests/bootstrap.php`, `phpstan-bootstrap.php`
- **Last completed release checklist:** `docs/release-3.0.0-checklist.md`

## Unreleased work already on `main`

Current commits ahead of `v3.0.0`:

- Repository URL/name cleanup from `wp-sudo` to `Sudo` references.
- Draft governance spec for External Audit Mode (`v3.2` planning).

Canonical source for post-tag drift: `git log v3.0.0..main --oneline`

## WordPress release posture

### Latest stable WordPress release

- **Latest stable major/minor branch:** `6.9`
- **Latest stable patch release observed:** `6.9.4`

### Forward lane used by this repository

- **Forward WordPress lane in CI/local previews:** `7.0-RC1`
- This is a forward-compatibility target for testing and preview workflows, **not** the current public `Tested up to` value.

### WordPress 7.0 final release status

- The previously scheduled **April 9, 2026** WordPress 7.0 final release was delayed on **March 31, 2026**.
- As of **April 17, 2026**, no replacement final release date is published.
- The Make/Core 7.0 release page says an updated final-stretch schedule will be published **no later than April 22, 2026**.

## Public metadata rule

Until WordPress 7.0 final actually ships:

- keep `readme.txt` **Tested up to** at `6.9`
- keep README support badges aligned with the latest stable release line
- record forward 7.0 readiness in `CHANGELOG.md`, `docs/ROADMAP.md`, `tests/MANUAL-TESTING.md`, and this file instead of claiming a final 7.0 public support bump early

## Canonical sources

### Repository sources

- `wp-sudo.php`
- `readme.txt`
- `CHANGELOG.md`
- `docs/current-metrics.md`
- `tests/MANUAL-TESTING.md`

### External sources

- WordPress release archive: <https://wordpress.org/download/releases/>
- WordPress download page: <https://wordpress.org/download/>
- WordPress 7.0 release page: <https://make.wordpress.org/core/7-0/>
- Delay announcement: <https://make.wordpress.org/core/2026/03/31/extending-the-7-0-cycle/>

## Update procedure

Update this file whenever any of the following changes:

1. latest tagged public release or current `main` target version
2. `CHANGELOG.md` unreleased feature list in a way that changes current `main` status
3. latest stable WordPress release line
4. forward WordPress lane (`7.0-RC1`, final `7.0`, etc.)
5. WordPress release-date posture (delays, final date publication, GA completion)
