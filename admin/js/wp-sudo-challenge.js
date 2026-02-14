/**
 * WP Sudo – Challenge page controller.
 *
 * Handles the password + optional 2FA flow on the challenge interstitial,
 * then replays the stashed request (redirect for GET, self-submitting
 * form for POST).
 *
 * @package WP_Sudo
 */
( function () {
	'use strict';

	var config = window.wpSudoChallenge || {};

	if ( ! config.ajaxUrl || ! config.stashKey ) {
		return;
	}

	// Elements.
	var card           = document.getElementById( 'wp-sudo-challenge-card' );
	var passwordStep   = document.getElementById( 'wp-sudo-challenge-password-step' );
	var passwordForm   = document.getElementById( 'wp-sudo-challenge-password-form' );
	var passwordInput  = document.getElementById( 'wp-sudo-challenge-password' );
	var submitBtn      = document.getElementById( 'wp-sudo-challenge-submit' );
	var errorBox       = document.getElementById( 'wp-sudo-challenge-error' );
	var twofaStep      = document.getElementById( 'wp-sudo-challenge-2fa-step' );
	var twofaForm      = document.getElementById( 'wp-sudo-challenge-2fa-form' );
	var twofaSubmitBtn = document.getElementById( 'wp-sudo-challenge-2fa-submit' );
	var twofaErrorBox  = document.getElementById( 'wp-sudo-challenge-2fa-error' );
	var twofaTimer     = document.getElementById( 'wp-sudo-challenge-2fa-timer' );
	var loadingOverlay = document.getElementById( 'wp-sudo-challenge-loading' );

	var countdownInterval = null;

	// ── Password form submission ──────────────────────────────────────

	if ( passwordForm ) {
		passwordForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var password = passwordInput.value;
			if ( ! password ) {
				return;
			}

			hideError( errorBox );
			submitBtn.disabled        = true;
			loadingOverlay.hidden     = false;
			card.setAttribute( 'aria-busy', 'true' );

			var body = new FormData();
			body.append( 'action', config.authAction );
			body.append( '_wpnonce', config.nonce );
			body.append( 'password', password );
			body.append( 'stash_key', config.stashKey );

			fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.text().then( function ( text ) {
						return { text: text, status: r.status };
					} );
				} )
				.then( function ( result ) {
					var response;
					try {
						response = JSON.parse( result.text );
					} catch ( e ) {
						loadingOverlay.hidden = true;
						submitBtn.disabled    = false;
						card.removeAttribute( 'aria-busy' );
						/* eslint-disable no-console */
						console.error( 'WP Sudo auth: non-JSON response (HTTP ' + result.status + '):', result.text );
						/* eslint-enable no-console */
						showError( errorBox, 'The server returned an unexpected response. Check the browser console for details.' );
						return;
					}

					loadingOverlay.hidden = true;
					submitBtn.disabled    = false;
					card.removeAttribute( 'aria-busy' );

					if ( response.success ) {
						if ( response.data && response.data.code === '2fa_pending' ) {
							// Switch to 2FA step.
							passwordStep.hidden = true;
							twofaStep.hidden    = false;
							var firstInput = twofaStep.querySelector( 'input:not([type="hidden"])' );
							if ( firstInput ) {
								firstInput.focus();
							}
							if ( response.data.expires_at ) {
								startCountdown( response.data.expires_at );
							}
							return;
						}

						// Success — replay the stashed request.
						handleReplay( response.data );
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
				.catch( function ( err ) {
					loadingOverlay.hidden = true;
					submitBtn.disabled    = false;
					card.removeAttribute( 'aria-busy' );
					/* eslint-disable no-console */
					console.error( 'WP Sudo auth: fetch error:', err );
					/* eslint-enable no-console */
					showError( errorBox, 'A network error occurred. Please try again.' );
				} );
		} );
	}

	// ── 2FA form submission ───────────────────────────────────────────

	if ( twofaForm ) {
		twofaForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			hideError( twofaErrorBox );
			if ( twofaSubmitBtn ) {
				twofaSubmitBtn.disabled = true;
			}
			loadingOverlay.hidden = false;
			card.setAttribute( 'aria-busy', 'true' );

			var body = new FormData( twofaForm );

			// The Two Factor provider's authentication_page() may render hidden
			// fields named "action" and "_wpnonce". Delete them before setting
			// ours so the AJAX request hits our handler, not the provider's.
			body.delete( 'action' );
			body.delete( '_wpnonce' );
			body.append( 'action', config.tfaAction );
			body.append( '_wpnonce', config.nonce );
			body.append( 'stash_key', config.stashKey );

			fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) {
					// Read as text first so non-JSON responses don't break the chain.
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
						card.removeAttribute( 'aria-busy' );
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
					card.removeAttribute( 'aria-busy' );
					if ( twofaSubmitBtn ) {
						twofaSubmitBtn.disabled = false;
					}

					if ( response.success ) {
						if ( response.data && response.data.code === '2fa_resent' ) {
							return; // Code resent — stay on 2FA step.
						}

						handleReplay( response.data );
						return;
					}

					var data = response.data || {};
					showError( twofaErrorBox, data.message || 'Verification failed.' );
				} )
				.catch( function ( err ) {
					loadingOverlay.hidden = true;
					card.removeAttribute( 'aria-busy' );
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

	// ── Request replay ────────────────────────────────────────────────

	/**
	 * Replay the stashed request.
	 *
	 * For GET: redirect to the original URL.
	 * For POST: build a self-submitting hidden form.
	 */
	function handleReplay( data ) {
		if ( ! data ) {
			window.location.href = window.location.origin + '/wp-admin/';
			return;
		}

		// Simple redirect for GET or when no replay data.
		if ( data.redirect && ! data.replay ) {
			window.location.href = data.redirect;
			return;
		}

		// POST replay: build and auto-submit a hidden form.
		if ( data.replay && data.url ) {
			var form = document.createElement( 'form' );
			form.method = data.method || 'POST';
			form.action = data.url;
			form.style.display = 'none';

			// Add POST fields.
			var postData = data.post_data || {};
			appendFields( form, postData, '' );

			document.body.appendChild( form );

			// Use the prototype method because stashed POST data may include
			// a field named "submit" which shadows the native form.submit().
			HTMLFormElement.prototype.submit.call( form );
			return;
		}

		// Fallback: just go to the dashboard.
		window.location.href = window.location.origin + '/wp-admin/';
	}

	/**
	 * Recursively append hidden inputs to a form.
	 *
	 * Handles nested arrays using PHP bracket notation (e.g. "checked[]").
	 */
	function appendFields( form, data, prefix ) {
		var keys = Object.keys( data );
		for ( var i = 0; i < keys.length; i++ ) {
			var key   = keys[ i ];
			var value = data[ key ];
			var name  = prefix ? prefix + '[' + key + ']' : key;

			if ( typeof value === 'object' && value !== null ) {
				appendFields( form, value, name );
			} else {
				var input   = document.createElement( 'input' );
				input.type  = 'hidden';
				input.name  = name;
				input.value = value;
				form.appendChild( input );
			}
		}
	}

	// ── Escape key — navigate to cancel (dashboard) ─────────────────

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && config.cancelUrl ) {
			window.location.href = config.cancelUrl;
		}
	} );

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

	// ── Helpers ──────────────────────────────────────────────────────

	function showError( box, message ) {
		if ( ! box ) return;
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
		if ( ! box ) return;
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
					// Reload to restart the challenge from the password step.
					window.location.reload();
				} );
				twofaTimer.appendChild( restartBtn );
			}
		}

		tick();
		countdownInterval = setInterval( tick, 1000 );
	}
} )();
