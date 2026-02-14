# WP Sudo — Accessibility Roadmap

Issues deferred from the initial WCAG 2.1 AA audit. All Critical and High
severity items have been resolved. The items below are Medium and Low
priority improvements for a future release.

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
handles this, but custom field callbacks should be audited.

### 4. Replay form accessible context

**File:** `admin/js/wp-sudo-challenge.js` (`handleReplay()`, line 209)
**WCAG:** 4.1.3 Status Messages

The self-submitting hidden form for POST replay provides no indication to the
user that the action is being replayed. Add a visible/announced "Replaying
your action..." status message before form submission.

## Low Priority

### 5. Admin bar countdown cleanup on page unload

**File:** `includes/class-admin-bar.php` (`countdown_script()`)
**WCAG:** Best practice (not a violation)

The `setInterval` is never cleared on page unload. While browsers handle this
automatically, explicitly clearing the interval in a `beforeunload` handler
is cleaner and prevents potential issues with bfcache (back/forward cache).

### 6. Settings page default value documentation

**File:** `includes/class-admin.php`
**WCAG:** 3.3.5 Help

Settings fields could include brief inline descriptions noting the default
values and their implications. The help tabs provide this information, but
inline context at the field level improves usability for all users.

## Already Addressed

- **Reduced motion preferences:** Both CSS files (`wp-sudo-challenge.css`,
  `wp-sudo-admin-bar.css`) already include
  `@media (prefers-reduced-motion: reduce)` rules disabling animations and
  transitions. No further work needed.

- **Focus-visible outlines:** Challenge CSS includes `:focus-visible` outlines
  with proper offset. No further work needed.
