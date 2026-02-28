# Testing Recommendations

*Updated February 28, 2026*

**~~1. Measure before optimizing: add PCOV + coverage reporting~~ ✅ Done (v2.9.1)**

PCOV coverage CI job added (`unit-tests-coverage`, PHP 8.3 only). `composer test:coverage` generates Clover XML + text summary. Baseline established. No failure threshold yet — ratchet up once the baseline is known.

**2. Cover the exit paths with `@runInSeparateProcess` — selectively**

The 76 `exit`/`die` paths are the biggest blind spot. You don't need to cover all 76 — most are `wp_send_json()` + `exit` in the Gate, and they follow the same pattern. Pick the 5–8 most security-critical exit paths (the ones that block destructive actions on REST, AJAX, and WPGraphQL surfaces) and write integration tests that run in separate processes. The rest follow the same code path and aren't worth the execution cost.

**~~3. Add a Challenge integration test~~ ✅ Done (v2.9.1)**

`tests/Integration/ChallengeTest.php` — 5 test methods covering: wrong password + audit hook, correct password + session activation, token binding (SHA-256 cookie ↔ user meta), request stash lifecycle, and rate-limiting lockout after max failed attempts.

**~~4. Test the uninstall cleanup~~ ✅ Done (v2.9.1)**

`tests/Integration/UninstallTest.php` — 2 test methods: single-site uninstall (options, user meta, capability restoration) and multisite uninstall (network-wide user meta cleanup when no site has the plugin active).

**5. Add mutation testing (long-term)**

Once coverage is measured and the critical gaps above are filled, mutation testing (Infection PHP) is the next level. It tells you not just "this line was executed" but "would the test fail if I changed this line?" A test suite can have 95% line coverage and still miss logic errors if assertions are weak. Mutation testing catches that. It's slow to run — probably nightly CI only — but it surfaces the tests that are executing code without actually verifying behavior.

**6. What I would NOT do**

- Don't add coverage gates to every CI matrix entry — one PHP version is enough
- Don't write tests for Admin_Bar or Site_Health integration — they're UI readouts with no security implications, unit tests are sufficient
- Don't pursue 100% line coverage as a goal — the last 5% is always the most expensive and least valuable
- Don't add `@runInSeparateProcess` to unit tests — it's an integration test tool for testing real exit behavior
