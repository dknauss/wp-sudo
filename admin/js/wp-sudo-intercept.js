/**
 * WP Sudo – Request intercept layer (v2).
 *
 * Patches window.fetch() and jQuery.ajax to transparently detect
 * `sudo_required` errors from gated AJAX and REST requests. When
 * detected, the sudo modal is opened for reauthentication and the
 * original request is automatically retried on success.
 *
 * The intercept is invisible to callers — their Promise/callbacks
 * resolve as if the request succeeded on the first attempt.
 *
 * Detection strategy:
 *   - fetch():       Clones the response, reads JSON, checks for sudo_required.
 *   - jQuery.ajax(): Hooks into the error callback, checks responseJSON.
 *
 * Only same-origin requests are intercepted (no CORS leaks).
 *
 * @package WP_Sudo
 */
( function () {
	'use strict';

	// Require the modal API.
	if ( ! window.wpSudo || ! window.wpSudo.openModal ) {
		return;
	}

	var openModal = window.wpSudo.openModal;

	// ── Helpers ─────────────────────────────────────────────────────

	/**
	 * Check if a URL is same-origin.
	 *
	 * @param {string} url The URL to check.
	 * @return {boolean} True if same-origin.
	 */
	function isSameOrigin( url ) {
		try {
			var parsed = new URL( url, window.location.origin );
			return parsed.origin === window.location.origin;
		} catch ( e ) {
			// Relative URLs are same-origin.
			return true;
		}
	}

	/**
	 * Extract the sudo_required message from a response body.
	 *
	 * Handles both:
	 *   - WP AJAX format: { success: false, data: { code: 'sudo_required', message: '...' } }
	 *   - WP REST format: { code: 'sudo_required', message: '...', data: { status: 403 } }
	 *
	 * @param {Object} json Parsed JSON response.
	 * @return {string|false} The message if sudo_required, false otherwise.
	 */
	function getSudoMessage( json ) {
		if ( ! json || typeof json !== 'object' ) {
			return false;
		}

		// WP AJAX format (wp_send_json_error).
		if ( json.success === false && json.data && json.data.code === 'sudo_required' ) {
			return json.data.message || '';
		}

		// WP REST format (WP_Error serialized).
		if ( json.code === 'sudo_required' ) {
			return json.message || '';
		}

		return false;
	}

	// ── fetch() interception ───────────────────────────────────────

	var originalFetch = window.fetch;

	/**
	 * Patched fetch() that intercepts sudo_required responses.
	 *
	 * Clones the response so the original body is still consumable
	 * by the caller when it's not a sudo_required error.
	 *
	 * @param {string|Request} input  The resource to fetch.
	 * @param {Object}         [init] Fetch options.
	 * @return {Promise<Response>} The response (original or retried).
	 */
	window.fetch = function wpSudoFetch( input, init ) {
		var url = typeof input === 'string' ? input : ( input && input.url ? input.url : '' );

		// Only intercept same-origin requests.
		if ( ! isSameOrigin( url ) ) {
			return originalFetch.apply( this, arguments );
		}

		var fetchArgs = arguments;
		var self      = this;

		return originalFetch.apply( self, fetchArgs ).then( function ( response ) {
			// Only inspect non-OK responses (403 typically).
			if ( response.ok ) {
				return response;
			}

			// Clone so we can read the body without consuming it.
			var clone = response.clone();

			return clone.json().then( function ( json ) {
				var message = getSudoMessage( json );

				if ( message === false ) {
					// Not a sudo error — return original response.
					return response;
				}

				// sudo_required detected — open modal, retry on success.
				return openModal( message ).then( function () {
					// Retry the original request.
					return originalFetch.apply( self, fetchArgs );
				} );
			} ).catch( function () {
				// JSON parse failed — return original response untouched.
				return response;
			} );
		} );
	};

	// ── jQuery.ajax interception ───────────────────────────────────

	if ( window.jQuery && window.jQuery.ajax ) {
		var originalAjax = jQuery.ajax;

		/**
		 * Patched jQuery.ajax that intercepts sudo_required responses.
		 *
		 * Wraps the error handler to check for sudo_required, opens the
		 * modal, and retries the entire AJAX call on auth success.
		 *
		 * @param {string|Object} url     URL string or settings object.
		 * @param {Object}        [opts]  Settings when url is a string.
		 * @return {jqXHR} The jQuery XHR object.
		 */
		jQuery.ajax = function wpSudoAjax( url, opts ) {
			// Normalize arguments — jQuery.ajax( settings ) or jQuery.ajax( url, settings ).
			var settings;
			if ( typeof url === 'object' ) {
				settings = url;
			} else {
				settings = opts || {};
				settings.url = url;
			}

			// Only intercept same-origin requests.
			if ( settings.url && ! isSameOrigin( settings.url ) ) {
				return originalAjax.call( jQuery, settings );
			}

			// Wrap the original error handler.
			var originalError = settings.error;

			settings.error = function ( jqXHR, textStatus, errorThrown ) {
				var json = jqXHR.responseJSON;
				var message = getSudoMessage( json );

				if ( message === false ) {
					// Not a sudo error — call original handler.
					if ( typeof originalError === 'function' ) {
						originalError.call( this, jqXHR, textStatus, errorThrown );
					}
					return;
				}

				// sudo_required — open modal, retry on success.
				var retrySettings = jQuery.extend( true, {}, settings );
				retrySettings.error = originalError; // Restore original for retry.

				openModal( message ).then( function () {
					originalAjax.call( jQuery, retrySettings );
				} ).catch( function () {
					// User cancelled — call original error handler.
					if ( typeof originalError === 'function' ) {
						originalError.call( this, jqXHR, textStatus, errorThrown );
					}
				} );
			};

			return originalAjax.call( jQuery, settings );
		};
	}
} )();
