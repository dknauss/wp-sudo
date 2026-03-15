import { request } from '@playwright/test';
import * as fs from 'fs/promises';
import * as path from 'path';

const STORAGE_STATE_PATH = path.join(
    process.cwd(),
    'tests/e2e/artifacts/storage-states/admin.json'
);

const BASE_URL = process.env.WP_BASE_URL ?? 'http://localhost:8889';
const USERNAME = process.env.WP_USERNAME ?? 'admin';
const PASSWORD = process.env.WP_PASSWORD ?? 'password';
const REQUEST_BASE_URL = process.env.WP_REQUEST_BASE_URL ?? BASE_URL;
const REQUEST_HOST_HEADER = process.env.WP_REQUEST_HOST_HEADER;

async function globalSetup() {
    const parsedRequestBaseUrl = new URL( REQUEST_BASE_URL );
    const extraHTTPHeaders: Record<string, string> = {};

    // Optional escape hatch for environments that need a different bootstrap
    // origin or explicit Host header during the request-context login flow.
    if ( REQUEST_HOST_HEADER ) {
        extraHTTPHeaders.Host = REQUEST_HOST_HEADER;
    }

    // Ensure the storage-states directory exists.
    await fs.mkdir( path.dirname( STORAGE_STATE_PATH ), { recursive: true } );

    const requestContext = await request.newContext( {
        baseURL: REQUEST_BASE_URL,
        extraHTTPHeaders,
        ignoreHTTPSErrors: parsedRequestBaseUrl.protocol === 'https:',
    } );

    // Warm-up request: hit wp-admin to ensure WordPress is fully initialized.
    // Addresses Pitfall 5 (cold-start latency) — first request after wp-env start
    // can take 3-8 seconds while WordPress initializes.
    await requestContext.get( '/wp-admin/', { timeout: 30_000 } );

    // Log in via the WordPress login form.
    const loginResponse = await requestContext.post( '/wp-login.php', {
        form: {
            log: USERNAME,
            pwd: PASSWORD,
            rememberme: 'forever',
            redirect_to: '/wp-admin/',
            testcookie: '1',
        },
    } );

    if ( ! loginResponse.ok() && loginResponse.status() !== 302 ) {
        throw new Error(
            `WordPress login failed with status ${ loginResponse.status() }`
        );
    }

    // Get the full storage state (cookies + origins).
    const storageState = await requestContext.storageState();

    // CRITICAL (TOOL-06 / Pitfall 6): Filter out wp_sudo_* cookies from storageState.
    // Sudo session cookies are time-bounded and must NOT be reused across tests.
    // Each test that needs a sudo session must acquire it fresh via the challenge flow.
    storageState.cookies = storageState.cookies.filter(
        ( cookie ) => ! cookie.name.startsWith( 'wp_sudo' )
    );

    await fs.writeFile(
        STORAGE_STATE_PATH,
        JSON.stringify( storageState, null, 2 )
    );

    await requestContext.dispose();
}

export default globalSetup;
