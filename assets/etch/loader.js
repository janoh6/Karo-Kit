/**
 * loader.js — the only Etch asset that always ships.
 *
 * The builder is a front-end route with no server-side marker we can trust, so
 * the board and the structure arrows used to be enqueued on every front-end
 * page for anyone who can edit — ~104KB downloaded and parsed on page views
 * that would never show a builder, just so they could no-op at runtime.
 *
 * This waits for the Etch API instead, and only then injects the real assets.
 * Off the builder the cost is this file and a handful of timers.
 */
( function () {
	'use strict';

	var cfg = window.KaroKitEtchLoader;
	if ( ! cfg || ! cfg.bundles || ! cfg.bundles.length ) {
		return;
	}

	// Guard against a double include (two enqueues, a cached duplicate, …).
	if ( window.__karoKitEtchLoaded ) {
		return;
	}
	window.__karoKitEtchLoaded = true;

	function addStyle( href ) {
		var link = document.createElement( 'link' );
		link.rel = 'stylesheet';
		link.href = href;
		document.head.appendChild( link );
	}

	/**
	 * Load scripts strictly in order. Dynamically created scripts default to
	 * async, which would let a dependent run before its dependency, so each
	 * one waits for the previous to finish.
	 */
	function addScripts( urls, done ) {
		var i = 0;
		( function next() {
			if ( i >= urls.length ) {
				if ( done ) {
					done();
				}
				return;
			}
			var script = document.createElement( 'script' );
			script.src = urls[ i++ ];
			script.async = false;
			script.onload = next;
			script.onerror = function () {
				window.console && console.error( '[karo-kit-etch] failed to load ' + script.src );
				next(); // a broken bundle shouldn't strand the others
			};
			document.head.appendChild( script );
		}() );
	}

	function start() {
		( cfg.styles || [] ).forEach( addStyle );

		cfg.bundles.forEach( function ( bundle ) {
			// The globals each bundle reads must exist before it executes.
			if ( bundle.data && bundle.data.name ) {
				window[ bundle.data.name ] = bundle.data.value;
			}
			addScripts( bundle.scripts || [] );
		} );
	}

	/** Resolves once the builder API is actually usable, or gives up quietly. */
	function whenEtchReady() {
		var deadline = Date.now() + ( cfg.timeout || 15000 );
		( function poll() {
			var etch = window.etch;
			if ( etch && etch.blocks && typeof etch.blocks.getTree === 'function' ) {
				start();
				return;
			}
			if ( Date.now() > deadline ) {
				return; // not the builder — nothing more to do, and nothing logged
			}
			setTimeout( poll, 150 );
		}() );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', whenEtchReady );
	} else {
		whenEtchReady();
	}
}() );
