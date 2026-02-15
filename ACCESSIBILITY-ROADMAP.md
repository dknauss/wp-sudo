# WP Sudo — Accessibility Roadmap

Issues deferred from the initial WCAG 2.1 AA audit and the WCAG 2.2 AA
follow-up audit. All Critical and High severity items have been resolved.
The items below are Medium and Low priority improvements for a future release.

## Medium Priority

### 1. Challenge page Escape key navigates without warning

**File:** `admin/js/wp-sudo-challenge.js` (line 270-273)
**WCAG:** 3.2.2 On Input

Pressing Escape immediately navigates to the cancel URL with no confirmation
or screen reader announcement. Users who accidentally press Escape lose their
challenge state. Add a confirmation step or at minimum an `aria-live`
announcement before navigating.

### 2. Challenge page step-change announcement

**File:** `admin/js/wp-sudo-challenge.js` (line 84-94)
**WCAG:** 4.1.3 Status Messages

Transitioning from the password step to the 2FA step has no screen reader
announcement. Focus moves to the first 2FA input, but the context change is
not communicated to AT users. Add a brief `aria-live` announcement such as
"Password verified. Two-factor authentication required."

### 3. Settings page label-input association audit

**File:** `includes/class-admin.php`
**WCAG:** 1.3.1 Info and Relationships

Verify that all settings fields (session duration, entry point policies) have
proper `<label for="">` associations. WordPress Settings API typically
handles this, but custom field callbacks should be audited. Add `label_for`
to `add_settings_field()` calls to ensure programmatic association.

### 4. Replay form accessible context

**File:** `admin/js/wp-sudo-challenge.js` (`handleReplay()`, line 209)
**WCAG:** 4.1.3 Status Messages

The self-submitting hidden form for POST replay provides no indication to the
user that the action is being replayed. Add a visible/announced "Replaying
your action..." status message before form submission.

### 5. Localize hardcoded JavaScript strings

**Files:** `admin/js/wp-sudo-challenge.js`, `admin/js/wp-sudo-admin.js`
**WCAG:** 3.1.2 Language of Parts / i18n best practice

Several user-facing strings in challenge JS are hardcoded in English
(error messages, status text, button labels during state changes). Pass
these through `wp_localize_script()` so they are translatable and
consistent with the PHP-side localization.

### 6. Challenge page auto-reload on session expiry

**File:** `admin/js/wp-sudo-challenge.js`
**WCAG:** 2.2.1 Timing Adjustable / 3.2.5 Change on Request

The challenge page reloads automatically when the 2FA timer expires
without warning or user confirmation. Screen reader users may lose
context. Add an announced warning before the reload, or replace with
a "Session expired — click to retry" message instead of automatic reload.

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
