/**
 * WP Sudo — Admin settings page scripts.
 *
 * Handles the MU-plugin install/uninstall toggle via AJAX.
 *
 * @package WP_Sudo
 */

/* global wpSudoAdmin */
( function () {
	'use strict';

	var strings      = ( wpSudoAdmin && wpSudoAdmin.strings ) || {};
	var installBtn   = document.getElementById( 'wp-sudo-mu-install' );
	var uninstallBtn = document.getElementById( 'wp-sudo-mu-uninstall' );
	var spinner      = document.getElementById( 'wp-sudo-mu-spinner' );
	var messageEl    = document.getElementById( 'wp-sudo-mu-message' );

	/**
	 * Send an AJAX request for MU-plugin install or uninstall.
	 *
	 * @param {string} action  The AJAX action name.
	 * @param {Element} button The button element that was clicked.
	 */
	function muPluginAction( action, button ) {
		if ( ! wpSudoAdmin || ! wpSudoAdmin.ajaxUrl ) {
			return;
		}

		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );

		if ( spinner ) {
			spinner.classList.add( 'is-active' );
		}
		if ( messageEl ) {
			messageEl.textContent = '';
		}

		var body = new FormData();
		body.append( 'action', action );
		body.append( '_nonce', wpSudoAdmin.nonce );

		fetch( wpSudoAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( spinner ) {
					spinner.classList.remove( 'is-active' );
				}
				button.setAttribute( 'aria-busy', 'false' );

				var data = result.data || {};

				if ( result.success ) {
					if ( messageEl ) {
						messageEl.textContent = data.message || '';
						messageEl.focus();
					}
					// Reload the page so the status indicator updates
					// (WP_SUDO_MU_LOADED will be defined or not on next load).
					setTimeout( function () {
						window.location.reload();
					}, 1000 );
				} else {
					button.disabled = false;
					if ( messageEl ) {
						messageEl.textContent = data.message || strings.genericError || '';
						messageEl.focus();
					}
				}
			} )
			.catch( function () {
				if ( spinner ) {
					spinner.classList.remove( 'is-active' );
				}
				button.disabled = false;
				button.setAttribute( 'aria-busy', 'false' );
				if ( messageEl ) {
					messageEl.textContent = strings.networkError || '';
					messageEl.focus();
				}
			} );
	}

	if ( installBtn ) {
		installBtn.addEventListener( 'click', function () {
			muPluginAction( wpSudoAdmin.installAction, installBtn );
		} );
	}

	if ( uninstallBtn ) {
		uninstallBtn.addEventListener( 'click', function () {
			muPluginAction( wpSudoAdmin.uninstallAction, uninstallBtn );
		} );
	}
} )();
