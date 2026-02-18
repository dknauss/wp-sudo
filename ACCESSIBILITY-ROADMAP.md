# WP Sudo — Accessibility Roadmap

Issues deferred from the initial WCAG 2.1 AA audit and the WCAG 2.2 AA
follow-up audit. All Critical, High, Medium, and Low severity items have
been resolved. This document is retained for reference.

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

- **Admin bar countdown cleanup on page unload (best practice):** Both the
  external `wp-sudo-admin-bar.js` and the PHP `countdown_script()` method
  listen for `pagehide` and clear the interval, preventing bfcache issues.

- **Settings page default value documentation (WCAG 3.3.5):** All settings
  fields include inline `<p class="description">` text documenting the
  default value, valid range, and implications of each setting.

- **Lockout countdown screen reader throttling (WCAG 4.1.3):** The lockout
  countdown sets `aria-live="off"` on the error box to suppress per-second
  announcements. Screen readers are notified via `announce()` every 30
  seconds and at 10 seconds remaining only.

- **Admin bar timer keyboard navigation (best practice):** The timer node
  is a standard WordPress admin bar `<a>` element (deactivation link) that
  participates in normal tab order. No `tabindex` manipulation, no focus
  trap, no interference with admin bar keyboard navigation.
