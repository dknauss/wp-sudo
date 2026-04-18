#!/usr/bin/env node
/**
 * Patch @wordpress/env after npm install so its generated Dockerfile disables
 * Composer's block-insecure gate for the global PHPUnit install step.
 *
 * GitHub Actions runners started failing in April 2026 when Composer began
 * refusing wp-env's broad phpunit/phpunit constraint during image build. This
 * project only needs wp-env to provision its E2E stack; the PHPUnit binary
 * installed inside the wp-env CLI image is not part of WP Sudo's own
 * dependency graph. We therefore patch the generated Docker config to set
 * `audit.block-insecure=false` before wp-env performs that global install.
 */

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const targetPath = path.join(
	process.cwd(),
	'node_modules',
	'@wordpress',
	'env',
	'lib',
	'runtime',
	'docker',
	'docker-config.js'
);

const needle =
	'ENV PATH="\\${PATH}:/home/$HOST_USERNAME/.composer/vendor/bin"\n' +
	'RUN composer global require --dev phpunit/phpunit:"^5.7.21 || ^6.0 || ^7.0 || ^8.0 || ^9.0 || ^10.0"\n' +
	'USER root';

const replacement =
	'ENV PATH="\\${PATH}:/home/$HOST_USERNAME/.composer/vendor/bin"\n' +
	'RUN composer config --global audit.block-insecure false\n' +
	'RUN composer global require --dev phpunit/phpunit:"^5.7.21 || ^6.0 || ^7.0 || ^8.0 || ^9.0 || ^10.0"\n' +
	'USER root';

if ( ! fs.existsSync( targetPath ) ) {
	console.error( `wp-env patch target not found: ${ targetPath }` );
	process.exit( 1 );
}

const source = fs.readFileSync( targetPath, 'utf8' );

if ( source.includes( 'RUN composer config --global audit.block-insecure false' ) ) {
	console.log( 'wp-env patch already applied.' );
	process.exit( 0 );
}

if ( ! source.includes( needle ) ) {
	console.error( 'wp-env patch target changed unexpectedly; update bin/patch-wp-env.js.' );
	process.exit( 1 );
}

fs.writeFileSync( targetPath, source.replace( needle, replacement ) );
console.log( 'Patched @wordpress/env Docker config for Composer audit.block-insecure compatibility.' );
