/**
 * WP Sudo – Modal reauthentication controller.
 *
 * Opens a <dialog> overlay when the admin-bar "Activate Sudo" button
 * is clicked, handles password + optional 2FA via AJAX, and reloads
 * the page on success so capability escalation takes effect.
 *
 * @package WP_Sudo
 */
( function () {
	'use strict';

	var config  = window.wpSudoModal || {};
	var dialog  = document.getElementById( 'wp-sudo-modal' );

	if ( ! dialog || ! config.ajaxUrl ) {
		return;
	}

	// Elements.
	var passwordStep   = document.getElementById( 'wp-sudo-modal-password-step' );
	var passwordForm   = document.getElementById( 'wp-sudo-modal-password-form' );
	var passwordInput  = document.getElementById( 'wp-sudo-modal-password' );
	var submitBtn      = document.getElementById( 'wp-sudo-modal-submit' );
	var cancelBtn      = document.getElementById( 'wp-sudo-modal-cancel' );
	var errorBox       = document.getElementById( 'wp-sudo-modal-error' );
	var twofaStep      = document.getElementById( 'wp-sudo-modal-2fa-step' );
	var twofaForm      = document.getElementById( 'wp-sudo-modal-2fa-form' );
	var twofaSubmitBtn = document.getElementById( 'wp-sudo-modal-2fa-submit' );
	var twofaCancelBtn = document.getElementById( 'wp-sudo-modal-2fa-cancel' );
	var twofaErrorBox  = document.getElementById( 'wp-sudo-modal-2fa-error' );
	var loadingOverlay = document.getElementById( 'wp-sudo-modal-loading' );

	// ── Intercept admin-bar click ──────────────────────────────────────

	document.addEventListener( 'click', function ( e ) {
		var barNode = document.getElementById( 'wp-admin-bar-wp-sudo-toggle' );
		if ( ! barNode || ! barNode.classList.contains( 'wp-sudo-inactive' ) ) {
			return;
		}

		var link = barNode.querySelector( 'a.ab-item' );
		if ( ! link || ! link.contains( e.target ) ) {
			return;
		}

		e.preventDefault();
		openModal();
	} );

	// ── Modal controls ─────────────────────────────────────────────────

	function openModal() {
		resetModal();
		dialog.showModal();
		passwordInput.focus();
	}

	function closeModal() {
		dialog.close();
		resetModal();
	}

	function resetModal() {
		passwordInput.value = '';
		hideError( errorBox );
		hideError( twofaErrorBox );
		passwordStep.hidden = false;
		twofaStep.hidden    = true;
		loadingOverlay.hidden = true;
		submitBtn.disabled  = false;
		if ( twofaSubmitBtn ) {
			twofaSubmitBtn.disabled = false;
		}
		// Reset dialog label to password step heading.
		dialog.setAttribute( 'aria-labelledby', 'wp-sudo-modal-title' );
		// Clear busy state.
		dialog.removeAttribute( 'aria-busy' );
	}

	cancelBtn.addEventListener( 'click', closeModal );
	if ( twofaCancelBtn ) {
		twofaCancelBtn.addEventListener( 'click', closeModal );
	}

	// Close on backdrop click (native dialog behaviour sends click on dialog itself).
	dialog.addEventListener( 'click', function ( e ) {
		if ( e.target === dialog ) {
			closeModal();
		}
	} );

	// ── Password form submission ───────────────────────────────────────

	passwordForm.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		var password = passwordInput.value;
		if ( ! password ) {
			return;
		}

		hideError( errorBox );
		submitBtn.disabled    = true;
		loadingOverlay.hidden = false;
		dialog.setAttribute( 'aria-busy', 'true' );

		var body = new FormData();
		body.append( 'action', 'wp_sudo_modal_auth' );
		body.append( '_wpnonce', config.nonce );
		body.append( 'password', password );

		fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( response ) {
				loadingOverlay.hidden = true;
				submitBtn.disabled    = false;
				dialog.removeAttribute( 'aria-busy' );

				if ( response.success ) {
					if ( response.data && response.data.code === '2fa_pending' ) {
						// Switch to 2FA step.
						passwordStep.hidden = true;
						twofaStep.hidden    = false;
						dialog.setAttribute( 'aria-labelledby', 'wp-sudo-modal-2fa-title' );
						var firstInput = twofaStep.querySelector( 'input:not([type="hidden"])' );
						if ( firstInput ) {
							firstInput.focus();
						}
						return;
					}

					// Success — reload to pick up escalated capabilities.
					window.location.reload();
					return;
				}

				// Error.
				var data = response.data || {};
				showError( errorBox, data.message || 'An error occurred.' );
				passwordInput.value = '';
				passwordInput.focus();
			} )
			.catch( function () {
				loadingOverlay.hidden = true;
				submitBtn.disabled    = false;
				dialog.removeAttribute( 'aria-busy' );
				showError( errorBox, 'A network error occurred. Please try again.' );
			} );
	} );

	// ── 2FA form submission ────────────────────────────────────────────

	if ( twofaForm ) {
		twofaForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			hideError( twofaErrorBox );
			if ( twofaSubmitBtn ) {
				twofaSubmitBtn.disabled = true;
			}
			loadingOverlay.hidden = false;
			dialog.setAttribute( 'aria-busy', 'true' );

			var body = new FormData( twofaForm );
			body.append( 'action', 'wp_sudo_modal_2fa' );
			body.append( '_wpnonce', config.nonce );

			fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( response ) {
					loadingOverlay.hidden = true;
					dialog.removeAttribute( 'aria-busy' );
					if ( twofaSubmitBtn ) {
						twofaSubmitBtn.disabled = false;
					}

					if ( response.success ) {
						if ( response.data && response.data.code === '2fa_resent' ) {
							// Code was resent — stay on 2FA step.
							return;
						}
						window.location.reload();
						return;
					}

					var data = response.data || {};
					showError( twofaErrorBox, data.message || 'Verification failed.' );
				} )
				.catch( function () {
					loadingOverlay.hidden = true;
					dialog.removeAttribute( 'aria-busy' );
					if ( twofaSubmitBtn ) {
						twofaSubmitBtn.disabled = false;
					}
					showError( twofaErrorBox, 'A network error occurred. Please try again.' );
				} );
		} );
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	function showError( box, message ) {
		if ( ! box ) return;
		var p = box.querySelector( 'p' );
		if ( p ) {
			p.textContent = message;
		}
		box.hidden = false;
	}

	function hideError( box ) {
		if ( ! box ) return;
		box.hidden = true;
		var p = box.querySelector( 'p' );
		if ( p ) {
			p.textContent = '';
		}
	}
} )();
