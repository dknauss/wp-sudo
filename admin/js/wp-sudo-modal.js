/**
 * WP Sudo – Modal reauthentication controller (v2).
 *
 * Provides the in-page <dialog> for password + optional 2FA when
 * an AJAX or REST request is intercepted with `sudo_required`.
 *
 * The intercept script (wp-sudo-intercept.js) calls
 * window.wpSudo.openModal() and receives a Promise that resolves
 * when reauthentication succeeds, or rejects on cancel.
 *
 * @package WP_Sudo
 */
( function () {
	'use strict';

	var config = window.wpSudoModal || {};
	var dialog = document.getElementById( 'wp-sudo-modal' );

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
	var actionLabel    = document.getElementById( 'wp-sudo-modal-action-label' );
	var twofaStep      = document.getElementById( 'wp-sudo-modal-2fa-step' );
	var twofaForm      = document.getElementById( 'wp-sudo-modal-2fa-form' );
	var twofaSubmitBtn = document.getElementById( 'wp-sudo-modal-2fa-submit' );
	var twofaCancelBtn = document.getElementById( 'wp-sudo-modal-2fa-cancel' );
	var twofaErrorBox  = document.getElementById( 'wp-sudo-modal-2fa-error' );
	var twofaTimer     = document.getElementById( 'wp-sudo-modal-2fa-timer' );
	var loadingOverlay = document.getElementById( 'wp-sudo-modal-loading' );

	// Promise callbacks for the current open session.
	var pendingResolve    = null;
	var pendingReject     = null;
	var countdownInterval = null;
	var previousFocus     = null; // Element that had focus before modal opened.

	// ── Public API ─────────────────────────────────────────────────

	/**
	 * Open the modal and return a Promise.
	 *
	 * Resolves when the user successfully authenticates.
	 * Rejects when the user cancels.
	 *
	 * @param {string} [message] Optional action description to show the user.
	 * @return {Promise} Resolves on auth success, rejects on cancel.
	 */
	function openModal( message ) {
		return new Promise( function ( resolve, reject ) {
			// If already open, reject the previous pending promise.
			if ( pendingReject ) {
				pendingReject( 'superseded' );
			}

			pendingResolve = resolve;
			pendingReject  = reject;

			// Store the element that had focus so we can restore it on close.
			previousFocus = document.activeElement;

			resetModal();

			if ( message && actionLabel ) {
				actionLabel.textContent = message;
				actionLabel.hidden = false;
			} else if ( actionLabel ) {
				actionLabel.textContent = '';
				actionLabel.hidden = true;
			}

			dialog.showModal();
			passwordInput.focus();
		} );
	}

	// ── Modal controls ─────────────────────────────────────────────

	function closeModal( reason ) {
		dialog.close();
		resetModal();

		if ( reason === 'cancel' && pendingReject ) {
			pendingReject( 'cancelled' );
		}

		pendingResolve = null;
		pendingReject  = null;

		// Restore focus to the element that triggered the modal.
		if ( previousFocus && typeof previousFocus.focus === 'function' ) {
			previousFocus.focus();
		}
		previousFocus = null;
	}

	function resetModal() {
		passwordInput.value = '';
		hideError( errorBox );
		hideError( twofaErrorBox );
		passwordStep.hidden   = false;
		twofaStep.hidden      = true;
		loadingOverlay.hidden = true;
		submitBtn.disabled    = false;
		if ( twofaSubmitBtn ) {
			twofaSubmitBtn.disabled = false;
		}
		if ( countdownInterval ) {
			clearInterval( countdownInterval );
			countdownInterval = null;
		}
		if ( twofaTimer ) {
			twofaTimer.hidden     = true;
			twofaTimer.textContent = '';
			twofaTimer.classList.remove( 'wp-sudo-expiring' );
		}
		dialog.setAttribute( 'aria-labelledby', 'wp-sudo-modal-title' );
		dialog.removeAttribute( 'aria-busy' );
	}

	cancelBtn.addEventListener( 'click', function () {
		closeModal( 'cancel' );
	} );
	if ( twofaCancelBtn ) {
		twofaCancelBtn.addEventListener( 'click', function () {
			closeModal( 'cancel' );
		} );
	}

	// Handle native Escape key (dialog fires 'cancel' event).
	dialog.addEventListener( 'cancel', function ( e ) {
		e.preventDefault(); // Prevent default close — we handle it ourselves.
		closeModal( 'cancel' );
	} );

	// Close on backdrop click (native dialog sends click on dialog element itself).
	dialog.addEventListener( 'click', function ( e ) {
		if ( e.target === dialog ) {
			closeModal( 'cancel' );
		}
	} );

	// ── Focus trap ─────────────────────────────────────────────────
	// Native <dialog> traps focus in some browsers but may leak during
	// step transitions (password → 2FA) when focusable elements change.
	// This explicit trap ensures Tab/Shift+Tab stays within the visible step.

	var FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	dialog.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Tab' ) {
			return;
		}

		var focusable = Array.prototype.filter.call(
			dialog.querySelectorAll( FOCUSABLE_SELECTOR ),
			function ( el ) {
				// Only include visible elements (not inside a hidden step).
				return el.offsetParent !== null || el === document.activeElement;
			}
		);

		if ( focusable.length === 0 ) {
			return;
		}

		var first = focusable[ 0 ];
		var last  = focusable[ focusable.length - 1 ];

		if ( e.shiftKey ) {
			// Shift+Tab: wrap to last element if we're on the first.
			if ( document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			}
		} else {
			// Tab: wrap to first element if we're on the last.
			if ( document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}
	} );

	// ── Password form submission ───────────────────────────────────

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
		body.append( 'action', config.authAction );
		body.append( '_wpnonce', config.nonce );
		body.append( 'password', password );

		fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
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
						if ( response.data.expires_at ) {
							startCountdown( response.data.expires_at );
						}
						return;
					}

					// Success — resolve the promise so intercept retries.
					dialog.close();
					if ( pendingResolve ) {
						pendingResolve();
					}
					pendingResolve = null;
					pendingReject  = null;
					return;
				}

				// Error.
				var data = response.data || {};
				if ( data.code === 'locked_out' && data.remaining > 0 ) {
					startLockoutCountdown( data.remaining );
				} else {
					showError( errorBox, data.message || 'An error occurred.' );
				}
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

	// ── 2FA form submission ────────────────────────────────────────

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

			// The Two Factor provider's authentication_page() may render hidden
			// fields named "action" and "_wpnonce". Delete them before setting
			// ours so the AJAX request hits our handler, not the provider's.
			body.delete( 'action' );
			body.delete( '_wpnonce' );
			body.append( 'action', config.tfaAction );
			body.append( '_wpnonce', config.nonce );

			fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) {
					// Read the raw text first so we can inspect non-JSON responses.
					return r.text().then( function ( text ) {
						return { text: text, status: r.status };
					} );
				} )
				.then( function ( result ) {
					var response;
					try {
						response = JSON.parse( result.text );
					} catch ( e ) {
						// Response is not valid JSON — show a meaningful error.
						loadingOverlay.hidden = true;
						dialog.removeAttribute( 'aria-busy' );
						if ( twofaSubmitBtn ) {
							twofaSubmitBtn.disabled = false;
						}
						/* eslint-disable no-console */
						console.error( 'WP Sudo 2FA: non-JSON response (HTTP ' + result.status + '):', result.text );
						/* eslint-enable no-console */
						showError( twofaErrorBox, 'The server returned an unexpected response. Check the browser console for details.' );
						return;
					}

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

						// Success — resolve the promise so intercept retries.
						dialog.close();
						if ( pendingResolve ) {
							pendingResolve();
						}
						pendingResolve = null;
						pendingReject  = null;
						return;
					}

					var data = response.data || {};
					showError( twofaErrorBox, data.message || 'Verification failed.' );
				} )
				.catch( function ( err ) {
					loadingOverlay.hidden = true;
					dialog.removeAttribute( 'aria-busy' );
					if ( twofaSubmitBtn ) {
						twofaSubmitBtn.disabled = false;
					}
					/* eslint-disable no-console */
					console.error( 'WP Sudo 2FA: fetch error:', err );
					/* eslint-enable no-console */
					showError( twofaErrorBox, 'A network error occurred. Please try again.' );
				} );
		} );
	}

	// ── Lockout countdown ────────────────────────────────────────────

	var lockoutInterval = null;

	/**
	 * Show a countdown when the user is locked out.
	 *
	 * Disables the submit button and updates the error message each second
	 * until the lockout expires, then re-enables the form.
	 *
	 * @param {number} remaining Seconds until lockout expires.
	 */
	function startLockoutCountdown( remaining ) {
		if ( lockoutInterval ) {
			clearInterval( lockoutInterval );
		}

		submitBtn.disabled = true;

		function tick() {
			if ( remaining <= 0 ) {
				clearInterval( lockoutInterval );
				lockoutInterval = null;
				submitBtn.disabled = false;
				hideError( errorBox );
				passwordInput.focus();
				return;
			}

			var m = Math.floor( remaining / 60 );
			var s = remaining % 60;
			var timeStr = m + ':' + ( s < 10 ? '0' : '' ) + s;
			showError( errorBox, 'Too many failed attempts. Try again in ' + timeStr + '.' );
			remaining--;
		}

		tick();
		lockoutInterval = setInterval( tick, 1000 );
	}

	// ── Helpers ─────────────────────────────────────────────────────

	function showError( box, message ) {
		if ( ! box ) {
			return;
		}
		// Unhide first so screen readers register the live region,
		// then populate content in the next frame so AT announces it.
		box.hidden = false;
		requestAnimationFrame( function () {
			var p = box.querySelector( 'p' );
			if ( p ) {
				p.textContent = message;
			}
		} );
	}

	function hideError( box ) {
		if ( ! box ) {
			return;
		}
		box.hidden = true;
		var p = box.querySelector( 'p' );
		if ( p ) {
			p.textContent = '';
		}
	}

	// ── 2FA countdown timer ─────────────────────────────────────────

	/**
	 * Start a visible countdown to the 2FA window expiry.
	 *
	 * @param {number} expiresAt Unix timestamp (seconds) when the 2FA window ends.
	 */
	function startCountdown( expiresAt ) {
		if ( ! twofaTimer ) {
			return;
		}

		if ( countdownInterval ) {
			clearInterval( countdownInterval );
		}

		twofaTimer.hidden = false;

		function tick() {
			var remaining = Math.max( 0, expiresAt - Math.floor( Date.now() / 1000 ) );
			var minutes   = Math.floor( remaining / 60 );
			var seconds   = remaining % 60;

			var timeStr = minutes + ':' + ( seconds < 10 ? '0' : '' ) + seconds;

			if ( remaining <= 60 ) {
				twofaTimer.classList.add( 'wp-sudo-expiring' );
				twofaTimer.textContent = '\u26A0 Time remaining: ' + timeStr;
			} else {
				twofaTimer.classList.remove( 'wp-sudo-expiring' );
				twofaTimer.textContent = 'Time remaining: ' + timeStr;
			}

			if ( remaining <= 0 ) {
				clearInterval( countdownInterval );
				countdownInterval = null;
				twofaTimer.textContent = '';
				if ( twofaSubmitBtn ) {
					twofaSubmitBtn.disabled = true;
				}

				// Show expiry message with a restart button.
				var expiredMsg = document.createElement( 'span' );
				expiredMsg.textContent = 'Your verification session has expired. ';
				twofaTimer.appendChild( expiredMsg );

				var restartBtn = document.createElement( 'button' );
				restartBtn.type = 'button';
				restartBtn.className = 'button button-link';
				restartBtn.textContent = 'Start over';
				restartBtn.addEventListener( 'click', function () {
					// Reset to password step so user can re-authenticate.
					resetModal();
					passwordInput.focus();
				} );
				twofaTimer.appendChild( restartBtn );
			}
		}

		tick();
		countdownInterval = setInterval( tick, 1000 );
	}

	// ── Expose public API ──────────────────────────────────────────

	window.wpSudo = window.wpSudo || {};
	window.wpSudo.openModal = openModal;
} )();
