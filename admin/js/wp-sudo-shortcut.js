/**
 * WP Sudo – Keyboard shortcut for proactive sudo activation.
 *
 * Listens for Ctrl+Shift+S (Windows/Linux) or Cmd+Shift+S (Mac)
 * and opens the sudo reauthentication modal. After successful
 * authentication the page reloads so the admin bar countdown
 * appears.
 *
 * When a sudo session is already active the modal is not rendered
 * and this script is not enqueued, so the shortcut is silently
 * unavailable — the admin bar countdown provides visual feedback.
 *
 * @package WP_Sudo
 */
( function () {
	'use strict';

	// Bail if the modal API is not available.
	if ( ! window.wpSudo || typeof window.wpSudo.openModal !== 'function' ) {
		return;
	}

	document.addEventListener( 'keydown', function ( e ) {
		// Ctrl+Shift+S (Windows/Linux) or Cmd+Shift+S (Mac).
		if ( e.shiftKey && ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 's' ) {
			e.preventDefault();

			window.wpSudo.openModal( 'Activate sudo mode' )
				.then( function () {
					// Session activated — reload to show admin bar countdown.
					window.location.reload();
				} )
				.catch( function () {
					// User cancelled — nothing to do.
				} );
		}
	} );
} )();
