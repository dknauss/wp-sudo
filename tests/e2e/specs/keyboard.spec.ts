/**
 * Keyboard navigation tests — KEYB-01, KEYB-02, KEYB-03, KEYB-04
 *
 * Covers all keyboard-driven interactions in WP Sudo that cannot be tested
 * by PHPUnit: native browser focus management, key event propagation, and
 * inline style mutation from JS event handlers.
 *
 * Tests:
 *   KEYB-01 — Tab key traverses challenge page form fields in correct order
 *   KEYB-02 — Enter key submits the challenge form
 *   KEYB-03 — Ctrl+Shift+S navigates to challenge page when no session active
 *   KEYB-04 — Ctrl+Shift+S flashes admin bar node when session is active
 *
 * Challenge page DOM structure (verified from class-challenge.php render_page()):
 *   #wp-sudo-challenge-password-step
 *     form#wp-sudo-challenge-password-form
 *       input#wp-sudo-challenge-password  [autofocus]
 *       button#wp-sudo-challenge-submit
 *       <a class="button">Cancel</a>
 *   #wp-sudo-challenge-2fa-step  [hidden by default — not in tab order]
 *
 * Shortcut architecture (mutually exclusive scripts):
 *   wp-sudo-shortcut.js   — enqueued when session NOT active → navigates to challenge page
 *   wp-sudo-admin-bar.js  — enqueued when session IS active  → flashes admin bar node
 * Source: includes/class-plugin.php enqueue_shortcut() (verified)
 */
import { test, expect, activateSudoSession } from '../fixtures/test';

test.describe( 'Keyboard navigation', () => {

    /**
     * KEYB-01: Tab key traverses challenge form in correct order.
     *
     * Source: class-challenge.php render_page() — DOM order: password input →
     * submit button → Cancel link. No tabindex attributes found anywhere in
     * the challenge page HTML.
     *
     * Tab sequence (standard document order, no tabindex overrides):
     *   1. #wp-sudo-challenge-password (password input — has autofocus)
     *   2. #wp-sudo-challenge-submit   (submit button)
     *   3. Cancel <a class="button">  (inside #wp-sudo-challenge-password-step, no ID)
     *
     * Excluded from tab order (hidden by default):
     *   - #wp-sudo-challenge-error    (error box — shown only on failure)
     *   - #wp-sudo-challenge-2fa-step (2FA step — not visible without 2FA plugin)
     *
     * PITFALL: autofocus fires on fresh page.goto() to challenge URL. Wait for
     * wpSudoChallenge config before evaluating document.activeElement to avoid
     * a transient 'body' state while JS is still initializing.
     */
    test( 'KEYB-01: Tab key traverses challenge form in correct order', async ( { page } ) => {
        // Navigate directly to challenge page (autofocus fires on fresh page load).
        // Source: class-challenge.php — PAGE_SLUG = 'wp-sudo-challenge' (verified)
        await page.goto( '/wp-admin/admin.php?page=wp-sudo-challenge' );

        // Wait for JS config object — ensures page is fully initialized before
        // evaluating document.activeElement (avoids transient 'body' state).
        // Source: admin/js/wp-sudo-challenge.js — wpSudoChallenge localized config (verified)
        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
        );

        // Password input receives autofocus on page load.
        // Source: class-challenge.php render_page() — autofocus on #wp-sudo-challenge-password (verified)
        let activeId = await page.evaluate( () => document.activeElement?.id );
        expect( activeId ).toBe( 'wp-sudo-challenge-password' );

        // Tab once → submit button.
        // Source: class-challenge.php render_page() — button#wp-sudo-challenge-submit (verified)
        await page.keyboard.press( 'Tab' );
        activeId = await page.evaluate( () => document.activeElement?.id );
        expect( activeId ).toBe( 'wp-sudo-challenge-submit' );

        // Tab again → Cancel link (an <a class="button"> with no ID).
        // Source: class-challenge.php render_page() — <a href="cancelUrl" class="button">Cancel</a> (verified)
        // NOTE: The hidden 2FA step also has a Cancel link, but it is excluded from
        // tab order because #wp-sudo-challenge-2fa-step has the hidden attribute.
        await page.keyboard.press( 'Tab' );
        const activeTag = await page.evaluate( () => document.activeElement?.tagName?.toLowerCase() );
        const activeText = await page.evaluate( () => ( document.activeElement as HTMLElement )?.textContent?.trim() );
        expect( activeTag ).toBe( 'a' );
        expect( activeText ).toBe( 'Cancel' );
    } );

    /**
     * KEYB-02: Enter key submits the challenge form.
     *
     * Source: admin/js/wp-sudo-challenge.js — passwordForm.addEventListener('submit', ...)
     * intercepts the native form submit with e.preventDefault(), then runs the AJAX
     * auth chain, then sets window.location.href on success (verified).
     *
     * Enter on a focused input inside a form fires the native form submit event,
     * which is caught by the JS submit listener. There is NO explicit keydown
     * listener for Enter — it triggers native form submit behavior.
     *
     * PITFALL: Use page.fill() (not keyboard.type()) for setting the password.
     * fill() sets the value directly without keystroke events. Enter is then
     * pressed separately as the form-submit trigger.
     *
     * PITFALL: Do NOT wrap Enter press in Promise.all with waitForNavigation().
     * Use waitForURL() with a predicate function (Pitfall 2 pattern from Phase 7):
     * the challenge page URL already contains '/wp-admin/' so a bare regex
     * resolves immediately before AJAX completes.
     */
    test( 'KEYB-02: Enter key submits challenge form', async ( { page } ) => {
        await page.goto( '/wp-admin/admin.php?page=wp-sudo-challenge' );

        // Wait for JS config — autofocus is active after this point.
        // Source: admin/js/wp-sudo-challenge.js — wpSudoChallenge localized config (verified)
        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
        );

        // Fill the password field. autofocus places focus on the input; fill() does
        // not move focus. Focus remains on #wp-sudo-challenge-password.
        // Source: class-challenge.php render_page() — id="wp-sudo-challenge-password" (verified)
        await page.fill( '#wp-sudo-challenge-password', 'password' );

        // Press Enter — fires native form submit → JS submit handler → AJAX → navigation.
        // Source: admin/js/wp-sudo-challenge.js — submit listener calls fetch() then sets
        // window.location.href = config.cancelUrl on session-only mode success (verified)
        await Promise.all( [
            page.waitForURL(
                ( url ) => url.pathname.includes( '/wp-admin/' ) && ! url.search.includes( 'wp-sudo-challenge' ),
                { timeout: 15_000 }
            ),
            page.keyboard.press( 'Enter' ),
        ] );

        // Should have navigated away from challenge page (session activated).
        expect( page.url() ).not.toContain( 'wp-sudo-challenge' );
    } );

    /**
     * KEYB-03: Ctrl+Shift+S navigates to challenge page when no session active.
     *
     * Source: admin/js/wp-sudo-shortcut.js — keydown handler checks:
     *   e.shiftKey && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's'
     * Then: window.location.href = config.challengeUrl (verified)
     *
     * Source: includes/class-plugin.php enqueue_shortcut() — shortcut script enqueued
     * only when: user logged in AND session NOT active AND not on challenge page itself (verified)
     *
     * PITFALL: Shortcut script is NOT loaded on the challenge page itself. The
     * enqueue_shortcut() function returns early if 'wp-sudo-challenge' === $page.
     * Always start KEYB-03 from a different admin page (the dashboard).
     *
     * PITFALL: Use Control+Shift+S (not Meta+Shift+S) for CI/Linux consistency.
     * The JS checks e.ctrlKey || e.metaKey, so both work — tests use Control for CI.
     *
     * PITFALL: The JS checks e.key.toLowerCase() === 's'. Playwright's Control+Shift+S
     * dispatches key: 'S' (uppercase because Shift is held). After toLowerCase() this
     * becomes 's', which matches correctly.
     */
    test( 'KEYB-03: Ctrl+Shift+S navigates to challenge page when no session active', async ( { page } ) => {
        // No sudo session — shortcut script (wp-sudo-shortcut.js) is loaded.
        // Navigate to dashboard (NOT the challenge page — shortcut excluded there).
        // Source: class-plugin.php enqueue_shortcut() — excludes challenge page (verified)
        await page.goto( '/wp-admin/' );

        // Wait for shortcut config to be available and challengeUrl to be set.
        // Source: class-plugin.php — wp_localize_script('wp-sudo-shortcut', 'wpSudoShortcut', ...) (verified)
        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoShortcut?: unknown } ).wpSudoShortcut !== 'undefined' &&
                  !! ( ( window as Window & { wpSudoShortcut?: { challengeUrl?: string } } ).wpSudoShortcut?.challengeUrl )
        );

        // Press Ctrl+Shift+S — triggers window.location.href = config.challengeUrl.
        // Source: admin/js/wp-sudo-shortcut.js — window.location.href navigation (verified)
        await Promise.all( [
            page.waitForURL( /page=wp-sudo-challenge/, { timeout: 10_000 } ),
            page.keyboard.press( 'Control+Shift+S' ),
        ] );

        // Verify we're on the challenge page.
        // Source: class-challenge.php render_page() — id="wp-sudo-challenge-card" (verified)
        await expect( page.locator( '#wp-sudo-challenge-card' ) ).toBeVisible();
    } );

    /**
     * KEYB-04: Ctrl+Shift+S flashes admin bar node when session is active.
     *
     * Source: admin/js/wp-sudo-admin-bar.js — keydown handler (lines 83–101):
     *   a.style.setProperty('transition', 'background 0.15s ease', 'important')
     *   a.style.setProperty('background', '#4caf50', 'important')
     *   setTimeout(fn, 300)  — removes both inline styles after 300ms
     * where a = n.querySelector('.ab-item'), n = #wp-admin-bar-wp-sudo-active (verified)
     *
     * The script is guarded by prefers-reduced-motion: returns early if
     * window.matchMedia('(prefers-reduced-motion: reduce)').matches (verified)
     *
     * PITFALL: Two separate, mutually exclusive scripts handle Ctrl+Shift+S:
     *   wp-sudo-shortcut.js  (no session) → navigates to challenge
     *   wp-sudo-admin-bar.js (session active) → flashes admin bar
     * KEYB-04 must use activateSudoSession() to ensure admin-bar.js is loaded.
     *
     * PITFALL: Must call page.emulateMedia({ reducedMotion: 'no-preference' })
     * BEFORE pressing the shortcut. The JS reads matchMedia at handler invocation
     * time — calling emulateMedia after the keydown is too late.
     *
     * PITFALL: The inline background style is set SYNCHRONOUSLY in the keydown
     * handler. page.evaluate() runs after keyboard.press() resolves — well
     * within the 300ms setTimeout window. Assert immediately; no clock needed.
     *
     * NOTE: activateSudoSession() is called with real timers — never call
     * page.clock.install() before it (Phase 7 key decision).
     */
    test( 'KEYB-04: Ctrl+Shift+S flashes admin bar node when session is active', async ( { page } ) => {
        // Activate sudo session — this causes admin-bar.js to be enqueued (not shortcut.js).
        // Source: includes/class-plugin.php enqueue_shortcut() — returns early if session active (verified)
        // CRITICAL: activateSudoSession uses real timers; do NOT install fake clock before it.
        await activateSudoSession( page );

        // Navigate to admin dashboard where admin-bar.js is loaded.
        await page.goto( '/wp-admin/' );

        // Confirm admin bar timer node is visible (session is active and correct script loaded).
        // Source: class-admin-bar.php admin_bar_node() — id 'wp-sudo-active' (verified)
        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).toBeVisible();

        // Override prefers-reduced-motion BEFORE pressing the shortcut.
        // The keydown handler checks matchMedia at invocation time — must override first.
        // Source: admin/js/wp-sudo-admin-bar.js — prefers-reduced-motion guard (verified)
        await page.emulateMedia( { reducedMotion: 'no-preference' } );

        // Press Ctrl+Shift+S — triggers flash animation on .ab-item.
        // Source: admin/js/wp-sudo-admin-bar.js — shortcut handler (verified)
        await page.keyboard.press( 'Control+Shift+S' );

        // Assert inline background style was set synchronously (before 300ms setTimeout fires).
        // Source: admin/js/wp-sudo-admin-bar.js — a.style.setProperty('background', '#4caf50', 'important') (verified)
        // Target: #wp-admin-bar-wp-sudo-active .ab-item (the <a> element inside the li node)
        //
        // NOTE: Chromium normalizes hex color values to rgb() notation when reading back
        // via style.getPropertyValue(). The JS sets '#4caf50' (hex) with !important, but
        // Chromium returns 'rgb(76, 175, 80)' — the same color in normalized form.
        // We check cssText for the hex string to verify the style was actually set via JS.
        const flashStyleSet = await page.evaluate( () => {
            const el = document.querySelector( '#wp-admin-bar-wp-sudo-active .ab-item' ) as HTMLElement | null;
            if ( ! el ) return false;
            // Check cssText — it preserves the original hex value as written by JS.
            // style.getPropertyValue('background') returns normalized rgb() in Chromium.
            const cssText = el.style.cssText;
            return cssText.includes( '#4caf50' ) || cssText.includes( 'rgb(76, 175, 80)' );
        } );

        expect( flashStyleSet ).toBe( true );
    } );

} );
