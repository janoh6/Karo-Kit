/**
 * sidebar.js — collapse/expand tabs for the Etch builder's side panels.
 *
 * Ported from the standalone "Etch Sidebar Toggle" snippet. Behaviour is
 * unchanged; three things were fixed on the way in, each marked below:
 * duplicate tabs when Etch remounts a panel, a DOMContentLoaded assumption
 * that no longer holds now the script is injected rather than inline, and
 * controllers left pointing at detached nodes.
 */
( function () {
	'use strict';

	var cfg = window.KaroKitEtchSidebarData || {};
	var PREFIX = 'karoKitEtchSidebar:' + location.hostname + ':';
	var CHEVRON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
		'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
		'<polyline points="15 18 9 12 15 6"></polyline></svg>';

	var DRAG_THRESHOLD = 4;  // px before a press counts as a drag, not a click
	var TAB_HEIGHT = 56;

	var remember = false !== cfg.remember;
	var SHORTCUT_HINT = { left: 'Alt+[', right: 'Alt+]' };

	/* ---- Storage: a no-op when the user turned persistence off ------------ */

	function store( key, value ) {
		if ( ! remember ) {
			return;
		}
		try {
			window.localStorage.setItem( PREFIX + key, value );
		} catch ( e ) { /* private mode / quota — not worth failing over */ }
	}

	function read( key ) {
		if ( ! remember ) {
			return null;
		}
		try {
			return window.localStorage.getItem( PREFIX + key );
		} catch ( e ) {
			return null;
		}
	}

	/* ---- Geometry --------------------------------------------------------- */

	function getSide( sidebar ) {
		var rect = sidebar.getBoundingClientRect();
		return rect.left + rect.width / 2 < window.innerWidth / 2 ? 'left' : 'right';
	}

	function findResizeHandle( sidebar ) {
		var siblings = [ sidebar.previousElementSibling, sidebar.nextElementSibling ];
		for ( var i = 0; i < siblings.length; i++ ) {
			var sib = siblings[ i ];
			if ( sib && sib.className && String( sib.className ).indexOf( 'etch-sidebar__resize-handle' ) !== -1 ) {
				return sib;
			}
		}
		return null;
	}

	function clampTop( top ) {
		var max = window.innerHeight - TAB_HEIGHT - 4;
		return Math.min( Math.max( top, 4 ), Math.max( max, 4 ) );
	}

	/* ---- Dragging the tab up and down ------------------------------------- */

	function setupDrag( btn, positionKey ) {
		var startY = 0;
		var startTop = 0;
		var dragged = false;
		var dragging = false;

		function onPointerMove( e ) {
			var delta = e.clientY - startY;
			if ( ! dragged && Math.abs( delta ) > DRAG_THRESHOLD ) {
				dragged = true;
				dragging = true;
				btn.classList.add( 'kk-sidebar-tab--dragging' );
				btn.style.transform = 'none'; // we own the position from here
			}
			if ( dragging ) {
				btn.style.top = clampTop( startTop + delta ) + 'px';
			}
		}

		function onPointerUp() {
			document.removeEventListener( 'pointermove', onPointerMove );
			document.removeEventListener( 'pointerup', onPointerUp );
			if ( dragging ) {
				btn.classList.remove( 'kk-sidebar-tab--dragging' );
				store( positionKey, btn.style.top );
			}
			// Swallow the click that follows a real drag, so releasing the tab
			// after moving it doesn't also toggle the panel.
			if ( dragged ) {
				btn.addEventListener( 'click', function suppress( ev ) {
					ev.stopImmediatePropagation();
					ev.preventDefault();
					btn.removeEventListener( 'click', suppress, true );
				}, true );
			}
			dragging = false;
			dragged = false;
		}

		btn.addEventListener( 'pointerdown', function ( e ) {
			if ( 0 !== e.button ) {
				return; // primary button / touch only
			}
			startY = e.clientY;
			startTop = btn.getBoundingClientRect().top;
			document.addEventListener( 'pointermove', onPointerMove );
			document.addEventListener( 'pointerup', onPointerUp );
		} );

		var savedTop = read( positionKey );
		if ( savedTop ) {
			btn.style.top = clampTop( parseFloat( savedTop ) ) + 'px';
			btn.style.transform = 'none';
		}

		window.addEventListener( 'resize', function () {
			if ( btn.style.top ) {
				btn.style.top = clampTop( parseFloat( btn.style.top ) ) + 'px';
			}
		} );
	}

	/* ---- Panels ----------------------------------------------------------- */

	// Live controllers by side, so the shortcuts drive whichever panels exist.
	var controllers = {};

	function setupSidebar( sidebar ) {
		if ( sidebar.dataset.kkSidebarReady ) {
			return;
		}
		sidebar.dataset.kkSidebarReady = 'true';

		var side = getSide( sidebar );
		var stateKey = side;
		var positionKey = side + ':top';
		var handle = findResizeHandle( sidebar );

		// FIX: the original flagged the sidebar but appended the tab to <body>,
		// so a remounted panel (a fresh element, unflagged) grew a second tab
		// while the first lingered forever. One tab per side, reused.
		var existing = document.querySelector( '.kk-sidebar-tab[data-side="' + side + '"]' );
		if ( existing ) {
			existing.remove();
		}

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'kk-sidebar-tab';
		btn.dataset.side = side;
		var label = 'Toggle ' + side + ' sidebar (' + SHORTCUT_HINT[ side ] + ')';
		btn.setAttribute( 'aria-label', label );
		btn.setAttribute( 'title', label );
		btn.innerHTML = CHEVRON;
		document.body.appendChild( btn );

		// FEATURE: some sidebars are tabbed pane groups — Etch's Structure panel
		// shares its .etch-sidebar with other panes (Style, Settings, ...), only
		// one of which is visible at a time. A collapse tab for that container
		// only makes sense while Structure is the pane actually showing, so it
		// hides itself the rest of the time. Etch marks the active pane with
		// data-pane-state="expanded" on the wrapper around the pane's header;
		// the Structure pane's header carries etch-header-title-wrapper--structure-panel.
		// Sidebars without a Structure pane at all (e.g. the other side) have no
		// marker to find, so this is a no-op for them — normal behaviour applies.
		var structureMarker = sidebar.querySelector( '.etch-header-title-wrapper--structure-panel' );
		var structurePane = structureMarker ? structureMarker.closest( '[data-pane-state]' ) : null;

		function syncStructureVisibility() {
			if ( ! structurePane ) {
				return;
			}
			btn.style.display = 'expanded' === structurePane.getAttribute( 'data-pane-state' ) ? '' : 'none';
		}

		if ( structurePane ) {
			syncStructureVisibility();
			new MutationObserver( syncStructureVisibility ).observe( structurePane, {
				attributes: true,
				attributeFilter: [ 'data-pane-state' ]
			} );
		}

		function isCollapsed() {
			return sidebar.classList.contains( 'kk-sidebar--collapsed' );
		}

		function applyState( collapsed ) {
			sidebar.classList.toggle( 'kk-sidebar--collapsed', collapsed );
			if ( handle ) {
				handle.style.display = collapsed ? 'none' : '';
			}
			btn.setAttribute( 'data-collapsed', String( collapsed ) );
			btn.setAttribute( 'aria-expanded', String( ! collapsed ) );
		}

		function setCollapsed( collapsed ) {
			applyState( collapsed );
			store( stateKey, String( collapsed ) );
		}

		applyState( 'true' === read( stateKey ) );
		setupDrag( btn, positionKey );

		btn.addEventListener( 'click', function () {
			setCollapsed( ! isCollapsed() );
		} );

		controllers[ side ] = {
			// FIX: a remount can leave a controller holding a detached panel;
			// the shortcuts check this before acting on it.
			isLive: function () {
				return sidebar.isConnected;
			},
			isCollapsed: isCollapsed,
			setCollapsed: setCollapsed
		};
	}

	/* ---- Watching for panels ---------------------------------------------- */

	function scan( mutations ) {
		if ( mutations ) {
			var added = false;
			for ( var i = 0; i < mutations.length; i++ ) {
				if ( mutations[ i ].addedNodes.length ) {
					added = true;
					break;
				}
			}
			if ( ! added ) {
				return;
			}
		}
		document.querySelectorAll( '.etch-sidebar:not([data-kk-sidebar-ready])' ).forEach( setupSidebar );
	}

	// Collapse bursts of mutations (typing, dragging, re-renders) into one scan.
	var queued = false;
	var pending = [];
	function scheduleScan( mutations ) {
		pending = pending.concat( mutations );
		if ( queued ) {
			return;
		}
		queued = true;
		queueMicrotask( function () {
			queued = false;
			var batch = pending;
			pending = [];
			scan( batch );
		} );
	}

	/* ---- Keyboard --------------------------------------------------------- */

	function isTypingContext( el ) {
		if ( ! el ) {
			return false;
		}
		if ( el.isContentEditable ) {
			return true;
		}
		var tag = el.tagName;
		return 'INPUT' === tag || 'TEXTAREA' === tag || 'SELECT' === tag;
	}

	function live( side ) {
		var c = controllers[ side ];
		return c && c.isLive() ? c : null;
	}

	function toggleSide( side ) {
		var c = live( side );
		if ( c ) {
			c.setCollapsed( ! c.isCollapsed() );
		}
	}

	// Hide-all / show-all: if anything is open, close everything; only when all
	// are already closed does this reopen them.
	function toggleBoth() {
		var sides = Object.keys( controllers ).filter( live );
		if ( ! sides.length ) {
			return;
		}
		var anyOpen = sides.some( function ( side ) {
			return ! controllers[ side ].isCollapsed();
		} );
		sides.forEach( function ( side ) {
			controllers[ side ].setCollapsed( anyOpen );
		} );
	}

	document.addEventListener( 'keydown', function ( e ) {
		// Alt alone — no Ctrl/Cmd/Shift — to stay clear of browser and Etch
		// shortcuts. Matched on physical key so macOS Option remapping is moot.
		if ( ! e.altKey || e.ctrlKey || e.metaKey || e.shiftKey ) {
			return;
		}
		if ( isTypingContext( e.target ) ) {
			return;
		}

		var action = null;
		switch ( e.code ) {
			case 'BracketLeft':
				action = function () { toggleSide( 'left' ); };
				break;
			case 'BracketRight':
				action = function () { toggleSide( 'right' ); };
				break;
			case 'Backslash':
				action = toggleBoth;
				break;
		}
		if ( action ) {
			e.preventDefault();
			action();
		}
	} );

	/* ---- Start ------------------------------------------------------------ */

	function start() {
		var target = document.getElementById( 'etch-builder' ) || document.body;
		new MutationObserver( scheduleScan ).observe( target, { childList: true, subtree: true } );
		scan();
	}

	// FIX: the snippet started on DOMContentLoaded, which was right while it
	// was inline in wp_footer. This file is injected after the builder API
	// appears, by which point that event has long since fired — waiting for it
	// would mean the observer never starts and the feature silently does
	// nothing. Run now unless the document genuinely is still parsing.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
