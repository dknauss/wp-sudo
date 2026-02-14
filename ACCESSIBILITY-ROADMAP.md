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

### 2. Modal loading state announcement timing

**File:** `includes/class-modal.php` (loading overlay, line 179)
**WCAG:** 4.1.3 Status Messages

The loading overlay has `role="status"` but is toggled via the `hidden`
attribute. Some screen readers may not announce the "Verifying..." text when
the element transitions from hidden to visible. Consider keeping the element
in the DOM (visible to AT) but visually hidden, and toggling visibility with
CSS classes instead of the `hidden` attribute.

### 3. Modal step-change announcement (password to 2FA)

**File:** `admin/js/wp-sudo-modal.js` (line 171-178)
**WCAG:** 4.1.3 Status Messages

When the password step succeeds and the 2FA step appears, there is no screen
reader announcement that the context has changed. The `aria-labelledby`
update is silent. Add a brief `aria-live` announcement such as "Password
verified. Two-factor authentication required."

### 4. Challenge page step-change announcement

**File:** `admin/js/wp-sudo-challenge.js` (line 84-94)
**WCAG:** 4.1.3 Status Messages

Same issue as the modal — transitioning from password to 2FA step has no
screen reader announcement. Focus moves to the first 2FA input, but the
context change is not communicated to AT users.

### 5. Modal password field `aria-required`

**File:** `includes/class-modal.php` (line 118)
**WCAG:** 3.3.2 Labels or Instructions

The password input has the HTML `required` attribute but not
`aria-required="true"`. While most modern screen readers infer required state
from the HTML attribute, adding the ARIA attribute provides redundancy for
older AT. Low effort, add `aria-required="true"`.

### 6. Settings page label-input association audit

**File:** `includes/class-admin.php`
**WCAG:** 1.3.1 Info and Relationships

Verify that all settings fields (session duration, entry point policies) have
proper `<label for="">` associations. WordPress Settings API typically
handles this, but custom field callbacks should be audited.

### 7. Replay form accessible context

**File:** `admin/js/wp-sudo-challenge.js` (`handleReplay()`, line 209)
**WCAG:** 4.1.3 Status Messages

The self-submitting hidden form for POST replay provides no indication to the
user that the action is being replayed. Add a visible/announced "Replaying
your action..." status message before form submission.

## Low Priority

### 8. Modal backdrop click discoverability

**File:** `admin/js/wp-sudo-modal.js` (line 134-138)
**WCAG:** Best practice (not a violation)

Clicking the backdrop closes the modal, but this affordance is not
discoverable by keyboard-only or screen reader users. The Escape key and
Cancel button already provide accessible alternatives. Consider adding a
tooltip or help text noting that clicking outside the dialog also cancels.

### 9. Admin bar countdown cleanup on page unload

**File:** `includes/class-admin-bar.php` (`countdown_script()`)
**WCAG:** Best practice (not a violation)

The `setInterval` is never cleared on page unload. While browsers handle this
automatically, explicitly clearing the interval in a `beforeunload` handler
is cleaner and prevents potential issues with bfcache (back/forward cache).

### 10. Settings page default value documentation

**File:** `includes/class-admin.php`
**WCAG:** 3.3.5 Help

Settings fields could include brief inline descriptions noting the default
values and their implications. The help tabs provide this information, but
inline context at the field level improves usability for all users.

## Already Addressed

- **Reduced motion preferences:** All three CSS files (`wp-sudo-modal.css`,
  `wp-sudo-challenge.css`, `wp-sudo-admin-bar.css`) already include
  `@media (prefers-reduced-motion: reduce)` rules disabling animations and
  transitions. No further work needed.

- **Focus-visible outlines:** Both modal and challenge CSS include
  `:focus-visible` outlines with proper offset. No further work needed.
