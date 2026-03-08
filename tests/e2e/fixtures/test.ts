import { test as base, expect } from '@playwright/test';

type WpAdminFixtures = {
    visitAdminPage: ( path: string, query?: string ) => Promise<void>;
};

export const test = base.extend<WpAdminFixtures>( {
    visitAdminPage: async ( { page }, use ) => {
        const visitAdminPage = async (
            adminPath: string,
            query?: string
        ) => {
            const url =
                '/wp-admin/' + adminPath + ( query ? '?' + query : '' );
            await page.goto( url );

            // Handle WordPress database upgrade screen (appears with trunk WP).
            if ( page.url().includes( 'upgrade.php' ) ) {
                await page.click( 'input[type="submit"]' );
                // After upgrade, there may be a "Continue" link.
                const continueLink = page.locator( 'a.button' );
                if ( await continueLink.isVisible( { timeout: 2000 } ) ) {
                    await continueLink.click();
                }
            }

            // Fail fast if WordPress redirected to login (storageState stale).
            if ( page.url().includes( 'wp-login.php' ) ) {
                throw new Error(
                    'Not authenticated — storageState may be stale. ' +
                    'Delete tests/e2e/artifacts/storage-states/admin.json and re-run.'
                );
            }
        };
        await use( visitAdminPage );
    },
} );

export { expect };
