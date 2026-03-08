import { test, expect } from '../fixtures/test';

test.describe( 'WP Sudo smoke tests', () => {
    test( 'Settings page loads and displays correct title', async ( {
        visitAdminPage,
        page,
    } ) => {
        // Navigate to Settings > Sudo.
        // The settings page slug is wp-sudo-settings (not wp-sudo).
        // See: Admin::PAGE_SLUG in includes/class-admin.php.
        await visitAdminPage( 'options-general.php', 'page=wp-sudo-settings' );

        // Assert the page title contains "Sudo".
        await expect( page.locator( 'h1' ) ).toContainText( 'Sudo' );

        // Assert we are on the correct settings page (not a WP error page).
        await expect( page.locator( '.wrap' ) ).toBeVisible();
    } );

    test( 'WordPress admin dashboard loads for authenticated user', async ( {
        visitAdminPage,
        page,
    } ) => {
        await visitAdminPage( 'index.php' );

        // Verify we are on the dashboard, not redirected to login.
        await expect( page ).toHaveURL( /wp-admin/ );
        await expect( page.locator( '#wpbody' ) ).toBeVisible();
    } );
} );
