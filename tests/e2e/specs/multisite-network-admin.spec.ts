/**
 * Multisite network admin reauthentication flow — MULTI-01
 *
 * Local-only regression for the network-admin return_url bug fixed in 903bc32.
 *
 * This spec is intentionally excluded from default CI expectations because the
 * GitHub-hosted wp-env stack is single-site. It is meant for a real multisite
 * Local/Studio environment exposed through WP_BASE_URL.
 *
 * Flow covered:
 *   1. Start with an active sudo session.
 *   2. Navigate to /wp-admin/network/plugins.php.
 *   3. Deactivate sudo from the admin bar on that page.
 *   4. Follow the gate notice challenge link.
 *   5. Reauthenticate.
 *   6. Verify redirect returns to /wp-admin/network/plugins.php with sudo active.
 */
import { test, expect, activateSudoSession } from '../fixtures/test';

const LOCAL_MULTISITE_HOST = 'multisite-subdomains.local';
const DEFAULT_PASSWORD = process.env.WP_PASSWORD ?? 'password';

test.describe( 'Multisite network admin flow', () => {
    test( 'MULTI-01: network plugins reauth returns to the same network admin page', async ( {
        page,
        context,
    } ) => {
        const configuredBaseUrl = process.env.WP_BASE_URL ?? '';

        test.skip(
            ! configuredBaseUrl || new URL( configuredBaseUrl ).hostname !== LOCAL_MULTISITE_HOST,
            `Requires WP_BASE_URL=http://${ LOCAL_MULTISITE_HOST }`
        );

        await activateSudoSession( page, DEFAULT_PASSWORD );
        await page.goto( '/wp-admin/network/plugins.php' );

        // Fail fast if this environment is not a multisite network admin.
        await expect( page ).toHaveURL( /\/wp-admin\/network\/plugins\.php(?:\?.*)?$/ );
        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).toBeVisible();

        const networkPluginsUrl = page.url();
        const networkOrigin = new URL( networkPluginsUrl ).origin;

        // Deactivate from the current network-admin page so the gate notice is rendered
        // for the exact URL that previously built an incorrect return_url host.
        await Promise.all( [
            page.waitForURL( /\/wp-admin\/network\/plugins\.php(?:\?.*)?$/, {
                waitUntil: 'load',
                timeout: 10_000,
            } ),
            page.locator( '#wp-admin-bar-wp-sudo-active' ).click(),
        ] );

        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).not.toBeVisible();

        const cookies = await context.cookies();
        expect( cookies.find( ( cookie ) => cookie.name === 'wp_sudo_token' ) ).toBeUndefined();

        const challengeLink = page.locator(
            '.wp-sudo-notice a:has-text("Confirm your identity")'
        ).first();

        await expect( challengeLink ).toBeVisible();
        const challengeHref = await challengeLink.getAttribute( 'href' );

        expect( challengeHref ).not.toBeNull();

        const challengeUrl = new URL( challengeHref ?? '' );

        expect( challengeUrl.origin ).toBe( networkOrigin );
        expect( challengeUrl.pathname ).toBe( '/wp-admin/network/admin.php' );
        expect( challengeUrl.searchParams.get( 'page' ) ).toBe( 'wp-sudo-challenge' );
        expect( challengeUrl.searchParams.get( 'return_url' ) ).toBe( networkPluginsUrl );

        await Promise.all( [
            page.waitForURL( /page=wp-sudo-challenge/, { timeout: 10_000 } ),
            challengeLink.click(),
        ] );

        const visitedChallengeUrl = new URL( page.url() );

        expect( visitedChallengeUrl.origin ).toBe( networkOrigin );
        expect( visitedChallengeUrl.pathname ).toBe( '/wp-admin/network/admin.php' );
        expect( visitedChallengeUrl.searchParams.get( 'page' ) ).toBe( 'wp-sudo-challenge' );
        expect( visitedChallengeUrl.searchParams.get( 'return_url' ) ).toBe( networkPluginsUrl );
        await expect(
            page.locator( '#wp-sudo-challenge-password-step a.button:has-text("Cancel")' )
        ).toHaveAttribute( 'href', networkPluginsUrl );

        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
        );
        await page.fill( '#wp-sudo-challenge-password', DEFAULT_PASSWORD );

        await Promise.all( [
            page.waitForURL( networkPluginsUrl, { timeout: 15_000 } ),
            page.click( '#wp-sudo-challenge-submit' ),
        ] );

        await expect( page ).toHaveURL( networkPluginsUrl );
        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).toBeVisible();
    } );
} );
