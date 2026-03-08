# Milestones

## v2.13 — Security Hardening Sprint (Archived)

**Shipped:** v2.10.2–v2.13.0
**Phases:** 5 (numbered 01–05)
**Last phase number:** 05

### Phases

| # | Phase | Version |
|---|-------|---------|
| 01 | Request Stash Redaction and Upload Action Coverage | v2.10.2 |
| 02 | Non-Blocking Rate Limiting | v2.10.2 |
| 03 | Rule Schema Validation and MU Loader Resilience | v2.11.0 |
| 04 | WPGraphQL Persisted Query Strategy and WSAL Sensor | v2.11.0 |
| 05 | IP + User Multidimensional Rate Limiting | v2.13.0 |

### Key Outcomes

- Request stash redaction (passwords no longer stored in transients)
- Non-blocking rate limiting (no more PHP-FPM worker exhaustion)
- Rule-schema validation (malformed third-party rules fail closed)
- MU loader resilience (non-standard directory layouts supported)
- WPGraphQL persisted-query classification hook
- WSAL sensor bridge for enterprise audit
- IP + user multidimensional lockout policy
- 496 unit tests, 132 integration tests at completion
