/**
 * Per-application-password policy management.
 *
 * Augments the WordPress Application Passwords table on user profile
 * pages with a "Sudo Policy" column. Each application password can be
 * assigned an individual policy (Disabled, Limited, Unrestricted) that
 * overrides the global REST API (App Passwords) policy for that
 * specific credential.
 *
 * @since 2.3.0
 * @package WP_Sudo
 */
( function () {
	'use strict';

	var config = window.wpSudoAppPasswords;
	if ( ! config ) {
		return;
	}

	/**
	 * Build a policy <select> element for a given application password UUID.
	 *
	 * @param {string} uuid The application password UUID.
	 * @return {HTMLSelectElement} The select element.
	 */
	function buildSelect( uuid ) {
		var select = document.createElement( 'select' );
		select.className = 'wp-sudo-app-password-policy';
		select.setAttribute( 'data-uuid', uuid );
		select.setAttribute( 'aria-label', 'Sudo policy for this application password' );

		var options = config.options || {};
		var currentPolicy = ( config.policies && config.policies[ uuid ] ) || '';

		Object.keys( options ).forEach( function ( value ) {
			var option = document.createElement( 'option' );
			option.value = value;
			option.textContent = options[ value ];
			if ( value === currentPolicy ) {
				option.selected = true;
			}
			select.appendChild( option );
		} );

		select.addEventListener( 'change', function () {
			savePolicy( uuid, select.value, select );
		} );

		return select;
	}

	/**
	 * Save a policy override via AJAX.
	 *
	 * @param {string}            uuid   The application password UUID.
	 * @param {string}            policy The policy value (or '' for global default).
	 * @param {HTMLSelectElement} select The select element (for visual feedback).
	 */
	function savePolicy( uuid, policy, select ) {
		select.disabled = true;

		var data = new FormData();
		data.append( 'action', 'wp_sudo_app_password_policy' );
		data.append( '_nonce', config.nonce );
		data.append( 'uuid', uuid );
		data.append( 'policy', policy );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				select.disabled = false;
				if ( result.success ) {
					// Update local cache.
					if ( ! config.policies ) {
						config.policies = {};
					}
					if ( policy ) {
						config.policies[ uuid ] = policy;
					} else {
						delete config.policies[ uuid ];
					}
					// Brief visual confirmation.
					select.style.outline = '2px solid #00a32a';
					setTimeout( function () {
						select.style.outline = '';
					}, 1000 );
				} else {
					select.style.outline = '2px solid #d63638';
					setTimeout( function () {
						select.style.outline = '';
					}, 2000 );
				}
			} )
			.catch( function () {
				select.disabled = false;
				select.style.outline = '2px solid #d63638';
				setTimeout( function () {
					select.style.outline = '';
				}, 2000 );
			} );
	}

	/**
	 * Initialize: add the Sudo Policy column to the Application Passwords table.
	 *
	 * WordPress renders the Application Passwords table via JavaScript
	 * (wp.template + Backbone). We use a MutationObserver to detect when
	 * rows are added, then augment them with our policy dropdown.
	 */
	function init() {
		var table = document.querySelector( '.application-passwords-list-table' );
		if ( ! table ) {
			return;
		}

		// Add header column.
		var thead = table.querySelector( 'thead tr' );
		if ( thead ) {
			var th = document.createElement( 'th' );
			th.scope = 'col';
			th.textContent = 'Sudo Policy';
			th.className = 'column-wp-sudo-policy';
			// Insert before the last column (Revoke).
			var lastTh = thead.querySelector( 'th:last-child' );
			if ( lastTh ) {
				thead.insertBefore( th, lastTh );
			} else {
				thead.appendChild( th );
			}
		}

		// Process existing rows.
		var rows = table.querySelectorAll( 'tbody tr' );
		rows.forEach( function ( row ) {
			augmentRow( row );
		} );

		// Watch for new rows (Application Passwords are rendered dynamically).
		var tbody = table.querySelector( 'tbody' );
		if ( tbody ) {
			var observer = new MutationObserver( function ( mutations ) {
				mutations.forEach( function ( mutation ) {
					mutation.addedNodes.forEach( function ( node ) {
						if ( node.nodeType === 1 && node.tagName === 'TR' ) {
							augmentRow( node );
						}
					} );
				} );
			} );
			observer.observe( tbody, { childList: true } );
		}
	}

	/**
	 * Augment a single Application Password table row with a policy dropdown.
	 *
	 * @param {HTMLTableRowElement} row The table row element.
	 */
	function augmentRow( row ) {
		// Skip if already augmented.
		if ( row.querySelector( '.wp-sudo-app-password-policy' ) ) {
			return;
		}

		// Find the UUID from the row data. WordPress stores application
		// password data in the row's data attributes or the revoke button.
		var uuid = extractUuid( row );
		if ( ! uuid ) {
			return;
		}

		var td = document.createElement( 'td' );
		td.className = 'column-wp-sudo-policy';
		td.setAttribute( 'data-colname', 'Sudo Policy' );
		td.appendChild( buildSelect( uuid ) );

		// Insert before the last cell (Revoke button).
		var lastTd = row.querySelector( 'td:last-child' );
		if ( lastTd ) {
			row.insertBefore( td, lastTd );
		} else {
			row.appendChild( td );
		}
	}

	/**
	 * Extract the UUID from an Application Password table row.
	 *
	 * WordPress stores the UUID in the revoke button's data attribute
	 * or in the Backbone model accessible via the row.
	 *
	 * @param {HTMLTableRowElement} row The table row element.
	 * @return {string|null} The UUID, or null if not found.
	 */
	function extractUuid( row ) {
		// Try the revoke button (WordPress adds data-slug on the button).
		var revokeBtn = row.querySelector( 'button.delete' );
		if ( revokeBtn ) {
			// The button's click handler sends the UUID; check its parent's model.
			var slug = revokeBtn.getAttribute( 'data-slug' );
			if ( slug ) {
				return slug;
			}
		}

		// Try finding a cell with a UUID pattern.
		var cells = row.querySelectorAll( 'td' );
		var uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
		for ( var i = 0; i < cells.length; i++ ) {
			var text = cells[ i ].textContent.trim();
			if ( uuidPattern.test( text ) ) {
				return text;
			}
		}

		// Try the row's data-uuid attribute if WordPress sets one.
		return row.getAttribute( 'data-uuid' ) || null;
	}

	// Run on DOM ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
