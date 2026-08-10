/**
 * Karo Kit — settings autosave.
 *
 * Progressive enhancement: the form posts to options.php as normal without
 * JavaScript. When this runs, the Save button is replaced by a status pill and
 * each field is written on change.
 */
( function () {
	'use strict';

	var cfg = window.karoKitAutosave;
	if ( ! cfg ) {
		return;
	}

	var form = document.querySelector( '.kk-app form[action$="options.php"]' );
	if ( ! form ) {
		return;
	}

	var actions = form.querySelector( '.kk-actions' );
	if ( ! actions ) {
		return;
	}

	// Swap the submit button for a live status pill.
	actions.innerHTML = '';
	var status = document.createElement( 'span' );
	status.className = 'kk-savestate';
	// Announce changes to screen readers without stealing focus.
	status.setAttribute( 'role', 'status' );
	status.setAttribute( 'aria-live', 'polite' );
	actions.appendChild( status );

	var pending = 0;
	var timers = {};

	function setState( state, text ) {
		status.className = 'kk-savestate kk-savestate--' + state;
		status.textContent = text;
	}

	function save( field ) {
		var name = field.name;
		if ( ! name ) {
			return;
		}

		var value;
		if ( 'checkbox' === field.type ) {
			value = field.checked ? '1' : '0';
		} else {
			value = field.value;
		}

		pending++;
		setState( 'saving', cfg.i18n.saving );

		var body = new URLSearchParams();
		body.append( 'action', 'karo_kit_save_setting' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'option', name );
		body.append( 'value', value );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( res ) {
				return res.json().catch( function () {
					throw new Error( 'bad response' );
				} );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( 'save failed' );
				}
				pending--;
				if ( 0 === pending ) {
					setState( 'saved', cfg.i18n.saved );
				}
			} )
			.catch( function () {
				pending--;
				// Leave the error visible: a silently dropped setting is worse
				// than a noisy one.
				setState( 'error', cfg.i18n.error );
			} );
	}

	function isTyped( field ) {
		return 'text' === field.type || 'number' === field.type;
	}

	function clearTimer( field ) {
		if ( timers[ field.name ] ) {
			window.clearTimeout( timers[ field.name ] );
			delete timers[ field.name ];
		}
	}

	// Toggles and dropdowns commit immediately; typed fields settle first, or
	// flush straight away on blur so tabbing out never drops an edit.
	form.addEventListener( 'change', function ( e ) {
		var field = e.target;
		if ( ! field.name ) {
			return;
		}
		clearTimer( field );
		save( field );
	} );

	form.addEventListener( 'input', function ( e ) {
		var field = e.target;
		if ( ! field.name || ! isTyped( field ) ) {
			return;
		}
		clearTimer( field );
		timers[ field.name ] = window.setTimeout( function () {
			delete timers[ field.name ];
			save( field );
		}, 600 );
		setState( 'saving', cfg.i18n.saving );
	} );

	// Never let an in-flight or still-debounced edit be lost by navigating away.
	window.addEventListener( 'beforeunload', function ( e ) {
		var waiting = Object.keys( timers ).length > 0;
		if ( pending > 0 || waiting ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );
}() );
