# Roadmap: Security Hardening Sprint

**Milestone:** Security Hardening Sprint
**Created:** 2026-03-03
**Depth:** Standard (4 phases)
**Source:** ROADMAP.md section 12, .planning/review/03-03-2026/PROPOSED-NEXT-STEPS-Claude.md

---

### Phase 1: Request Stash Redaction and Upload Action Coverage

**Goal:** Eliminate real data exposure in request stash (passwords stored verbatim in transients) and close the upload-plugin/upload-theme gating gap. Implement per-user stash cap to bound growth.
**Plans:** 4 plans

Plans:
- [ ] 01-01-PLAN.md — Redaction TDD: sanitize_params() omits sensitive fields, wp_sudo_sensitive_stash_keys filter (Wave 1)
- [ ] 01-02-PLAN.md — Upload rules TDD: plugin.upload and theme.upload added to Action_Registry (Wave 1, parallel)
- [ ] 01-03-PLAN.md — Stash cap TDD: MAX_STASH_PER_USER=5, user meta index, enforce_stash_cap(), Challenge + uninstall updates (Wave 2)
- [ ] 01-04-PLAN.md — Integration tests: redaction + cap + index verified against real WordPress/MySQL (Wave 3)

### Phase 2: Non-Blocking Rate Limiting

**Goal:** Replace blocking sleep() in auth failure path with non-blocking time-based throttling. Eliminate PHP-FPM worker exhaustion under concurrent failed auth attempts. Address TOCTOU race in failure counter.
**Plans:** 0 plans

Plans:
- [ ] TBD (run /gsd:plan-phase 2 to break down)

### Phase 3: Rule Schema Validation and MU Loader Resilience

**Goal:** Add strict schema validation for wp_sudo_gated_actions filter output. Harden MU loader path detection for non-standard plugin directory layouts.
**Plans:** 0 plans

Plans:
- [ ] TBD (run /gsd:plan-phase 3 to break down)

### Phase 4: WPGraphQL Persisted Query Strategy and WSAL Sensor

**Goal:** Document and handle persisted-query mutation detection in Limited mode. Ship WSAL sensor extension for enterprise audit visibility.
**Plans:** 0 plans

Plans:
- [ ] TBD (run /gsd:plan-phase 4 to break down)
