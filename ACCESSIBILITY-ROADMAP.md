# WP Sudo — Accessibility Roadmap

Issues deferred from the initial WCAG 2.1 AA audit and the WCAG 2.2 AA
follow-up audit. All Critical and High severity items have been resolved.
The items below are Medium and Low priority improvements for a future release.

## Medium Priority

All medium-priority items have been addressed. See "Already Addressed" below.

## Low Priority

### 7. Admin bar countdown cleanup on page unload

**File:** `includes/class-admin-bar.php` (`countdown_script()`)
**WCAG:** Best practice (not a violation)

The `setInterval` is never cleared on page unload. While browsers handle this
automatically, explicitly clearing the interval in a `beforeunload` handler
is cleaner and prevents potential issues with bfcache (back/forward cache).

### 8. Settings page default value documentation

**File:** `includes/class-admin.php`
**WCAG:** 3.3.5 Help

Settings fields could include brief inline descriptions noting the default
values and their implications. The help tabs provide this information, but
inline context at the field level improves usability for all users.

### 9. Lockout countdown timer precision for screen readers

**File:** `admin/js/wp-sudo-challenge.js`
**WCAG:** 4.1.3 Status Messages

The lockout countdown updates the DOM every second. Consider throttling
`aria-live` announcements to every 15–30 seconds to avoid excessive
screen reader interruptions while still keeping users informed.

### 10. Admin bar timer keyboard navigation

**File:** `includes/class-admin-bar.php`
**WCAG:** Best practice

The admin bar timer is informational only. Ensure it does not receive
focus trap or interfere with keyboard navigation of the admin bar.

## Already Addressed

- **Escape key guard (WCAG 3.2.2):** Pressing Escape on the challenge page
  now triggers an `aria-live` announcement ("Leaving challenge page.") with a
  600 ms delay before navigating, giving screen readers time to announce.

- **Step-change announcement (WCAG 4.1.3):** Transitioning from password to
  2FA step now announces "Password verified. Two-factor authentication
  required." via `wp.a11y.speak()`.

- **Settings label-input association (WCAG 1.3.1):** All five
  `add_settings_field()` calls now include `label_for` matching the rendered
  input/select `id`, ensuring proper `<label for="">` association.

- **Replay status message (WCAG 4.1.3):** The POST replay now shows a visible
  "Replaying your action..." message in the loading overlay and announces it
  via `wp.a11y.speak()` before form submission.

- **Localized JavaScript strings (i18n):** All user-facing strings in
  `wp-sudo-challenge.js` (12 strings) and `wp-sudo-admin.js` (2 strings) are
  now passed through `wp_localize_script()` and translatable.

- **Session expiry handling (WCAG 2.2.1):** Already addressed by the
  "Start over" button and message that replaces automatic reload.

- **Reduced motion preferences:** Both CSS files (`wp-sudo-challenge.css`,
  `wp-sudo-admin-bar.css`) already include
  `@media (prefers-reduced-motion: reduce)` rules disabling animations and
  transitions. No further work needed.

- **Focus-visible outlines:** Challenge CSS includes `:focus-visible` outlines
  with proper offset. No further work needed.

- **Gated actions table `role="presentation"`:** Removed in v2.2.0 post-release
  patch. The table now uses native semantics with a `<caption>` element for
  screen reader context.

- **Disabled link contrast:** Changed from `#a7aaad` (2.7:1) to `#787c82`
  (4.6:1) in v2.2.0 post-release patch. Meets WCAG AA 4.5:1 for text.

- **Admin notice ARIA roles:** Added `role="alert"` to blocked-action notice
  and `role="status"` to gate notice in v2.2.0 post-release patch.

- **MU-plugin message area:** Already has `role="status"` and `aria-live="polite"`
  on the `#wp-sudo-mu-message` element. No further work needed.
