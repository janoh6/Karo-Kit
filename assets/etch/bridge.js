/**
 * etb-bridge.js
 *
 * The ONLY DOM-coupled module. Everything the Etch public API cannot do
 * (create, delete, reset) is driven by puppeteering Etch's own native
 * controls. When an Etch update moves the DOM, this is the one file to fix,
 * and almost always only the SELECTORS object below.
 *
 * Selector rules learned from the live DOM:
 *  - Never include the Svelte scope hash (svelte-tx5fcb) — it changes per build.
 *  - Never select action buttons by id (bits-cXXXX) — they regenerate per render.
 *  - The destructive button is reliably `button.etch-danger`; Edit is the
 *    non-danger `.etch-builder-button` sibling.
 *  - Cards carry no stable id; match them by their visible name.
  *
 * Ported from the standalone "Etch Template Board" plugin (v0.14.2). The
 * WordPress-facing surface (options, REST namespace, globals, filters) was
 * renamed to Karo Kit conventions; the internal `etb-` CSS class names and
 * DOM ids are kept as-is — they never escape #etb-root, so renaming ~600 CSS
 * selectors would be churn without benefit.
*/
(function () {
	'use strict';

	var SELECTORS = {
		// Whole templates screen body — board overlays this.
		screenBody: '.full-screen__body',
		// A single template card and the parts we read/drive.
		card: '.etch-templates__content-item',
		cardName: '.etch-templates__content-item-name',
		dangerButton: 'button.etch-danger',
		editButton: '.etch-builder-button:not(.etch-danger)',
		// Column header in the nav row, its label, and its create "+".
		// The "+" is a popover trigger (opens Etch's native create dialog).
		navItem: '.etch-templates__navigation-item',
		navName: 'p',
		navAddButton: 'button[data-popover-trigger]',
		// Content columns (parallel, by index, to the nav items above).
		contentWrapper: '.etch-templates__content-item-wrapper',
		// The create popover that lists "Single X / Archive X".
		createMenu: '.etch-templates__navigation-item-menu',
	};

	// Text a destructive button shows once it is armed for the second click.
	var CONFIRM_RE = /confirm/i;

	function q(sel, root) {
		return (root || document).querySelector(sel);
	}
	function qa(sel, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(sel));
	}

	function findCardByTitle(title) {
		var wanted = String(title).trim();
		var matches = qa(SELECTORS.card).filter(function (card) {
			var nameEl = q(SELECTORS.cardName, card);
			return nameEl && nameEl.textContent.trim() === wanted;
		});
		if (!matches.length) { return null; }
		// Prefer a laid-out card (offsetParent null => display:none clone/template).
		var visible = matches.find(function (c) { return c.offsetParent !== null; });
		return visible || matches[0];
	}

	/** Poll a getter until it returns a truthy value or times out. */
	function waitFor(getter, timeoutMs) {
		return new Promise(function (resolve, reject) {
			var deadline = Date.now() + (timeoutMs || 3000);
			(function poll() {
				var v;
				try { v = getter(); } catch (e) { v = null; }
				if (v) { resolve(v); }
				else if (Date.now() > deadline) { reject(new Error('KaroKitEtch: timed out')); }
				else { setTimeout(poll, 70); }
			})();
		});
	}

	/** The positioned ancestor floating-ui actually placed the popover with. */
	function floaterOf(menu) {
		var node = menu, floater = menu;
		while (node.parentElement && node.parentElement !== document.body) {
			node = node.parentElement;
			var cs = getComputedStyle(node);
			if (cs.position === 'fixed' || cs.position === 'absolute') { floater = node; }
		}
		return floater;
	}

	/**
	 * Move a popover so it sits under `anchorEl`, clearing floating-ui's
	 * transform/inset so our fixed left/top win.
	 */
	function positionFloater(menu, anchorEl) {
		var floater = floaterOf(menu);
		var r = anchorEl.getBoundingClientRect();
		var vw = window.innerWidth, vh = window.innerHeight, pad = 8;
		var mw = floater.offsetWidth || 220;
		var mh = floater.offsetHeight || 160;

		// Horizontal: prefer left-aligned to the anchor, clamp within viewport.
		var left = r.left;
		if (left + mw > vw - pad) { left = Math.max(pad, vw - pad - mw); }
		if (left < pad) { left = pad; }

		// Vertical: below the anchor, but flip above if it would overflow the bottom.
		var top = r.bottom + 6;
		if (top + mh > vh - pad && r.top - 6 - mh > pad) { top = r.top - 6 - mh; }
		if (top + mh > vh - pad) { top = Math.max(pad, vh - pad - mh); }

		floater.style.setProperty('position', 'fixed', 'important');
		floater.style.setProperty('transform', 'none', 'important');
		floater.style.setProperty('inset', 'auto', 'important');
		floater.style.setProperty('left', left + 'px', 'important');
		floater.style.setProperty('top', top + 'px', 'important');
		floater.style.setProperty('margin', '0', 'important');
		return floater;
	}

	/** Locate the native "+" create trigger for a column by its label. */
	function findAddButton(label) {
		var wanted = String(label).trim();
		var navs = qa(SELECTORS.navItem);
		var wrappers = qa(SELECTORS.contentWrapper);
		var idx = navs.findIndex(function (n) {
			var p = q(SELECTORS.navName, n);
			return p && p.textContent.trim() === wanted;
		});
		if (idx === -1) { return null; }
		return q(SELECTORS.navAddButton, navs[idx]) || (wrappers[idx] && q(SELECTORS.navAddButton, wrappers[idx]));
	}

	/** Park Etch's popover far off-screen so it can never be seen while we use it. */
	function parkOffscreen(menu) {
		var floater = floaterOf(menu);
		floater.style.setProperty('position', 'fixed', 'important');
		floater.style.setProperty('left', '-99999px', 'important');
		floater.style.setProperty('top', '0', 'important');
		floater.style.setProperty('visibility', 'hidden', 'important');
		floater.style.setProperty('pointer-events', 'none', 'important');
	}

	/** Open Etch's native create popover but keep it fully off-screen; resolve with the menu. */
	function openHiddenCreateMenu(add) {
		return new Promise(function (resolve, reject) {
			var existing = q(SELECTORS.createMenu);
			if (existing) { parkOffscreen(existing); resolve(existing); return; }
			var done = false;
			var obs = new MutationObserver(function () {
				if (done) { return; }
				var m = q(SELECTORS.createMenu);
				if (!m) { return; }
				done = true; obs.disconnect();
				parkOffscreen(m);
				// floating-ui repositions async — keep re-parking for a few frames.
				var start = Date.now();
				(function reassert() {
					if (!q(SELECTORS.createMenu)) { return; }
					parkOffscreen(m);
					if (Date.now() - start < 200) { requestAnimationFrame(reassert); }
				})();
				resolve(m);
			});
			obs.observe(document.body, { childList: true, subtree: true });
			setTimeout(function () { if (!done) { obs.disconnect(); reject(new Error('KaroKitEtch: native create menu did not open')); } }, 2000);
			add.click();
		});
	}

	function closeNativeCreateMenu(add) {
		if (q(SELECTORS.createMenu) && 'true' === add.getAttribute('aria-expanded')) { add.click(); }
	}

	/**
	 * After the first click, the destructive button re-labels to "Confirm ...".
	 * Re-locate the card + button each poll and resolve with the live armed button.
	 */
	function waitForArmed(title, timeoutMs) {
		return new Promise(function (resolve, reject) {
			var deadline = Date.now() + (timeoutMs || 4000);
			var lastText = '';
			(function poll() {
				var card = findCardByTitle(title);
				if (!card) { resolve(null); return; } // card gone => already committed
				var btn = q(SELECTORS.dangerButton, card);
				if (btn) { lastText = btn.textContent.trim(); }
				if (btn && CONFIRM_RE.test(btn.textContent)) {
					resolve(btn);
				} else if (Date.now() > deadline) {
					reject(new Error('KaroKitEtch: delete did not arm for "' + title + '" (button read: "' + lastText + '")'));
				} else {
					setTimeout(poll, 70);
				}
			})();
		});
	}

	var Bridge = {
		selectors: SELECTORS,

		getScreenBody: function () {
			return q(SELECTORS.screenBody);
		},

		/**
		 * Read the native column structure straight from the DOM: labels and
		 * order (from the nav row), whether each column has a create "+", and the
		 * cards in each column (paired to nav items by index). Each card's
		 * destructive kind is read from its own button text ("Reset" vs "Delete"),
		 * which also tells us System vs user templates without any guessing.
		 */
		readColumns: function () {
			var navs = qa(SELECTORS.navItem);
			var wrappers = qa(SELECTORS.contentWrapper);
			return navs.map(function (nav, i) {
				var p = q(SELECTORS.navName, nav);
				var wrapper = wrappers[i];
				var cards = wrapper ? qa(SELECTORS.card, wrapper).map(function (cardEl) {
					var nameEl = q(SELECTORS.cardName, cardEl);
					var danger = q(SELECTORS.dangerButton, cardEl);
					var dText = danger ? danger.textContent.trim().toLowerCase() : '';
					return {
						title: nameEl ? nameEl.textContent.trim() : '',
						destructive: /reset/.test(dText) ? 'reset' : 'delete',
					};
				}).filter(function (c) { return c.title; }) : [];
				return {
					label: p ? p.textContent.trim() : 'Column ' + (i + 1),
					// The create "+" lives in the nav header for non-empty columns
					// and in the empty-cell placeholder for empty ones — accept either.
					hasAdd: !!(q(SELECTORS.navAddButton, nav) || (wrapper && q(SELECTORS.navAddButton, wrapper))),
					cards: cards,
				};
			});
		},

		/** Open a template by clicking its native card's Edit button. */
		openTemplate: function (title) {
			var card = findCardByTitle(title);
			if (!card) {
				return Promise.reject(new Error('KaroKitEtch: no native card for "' + title + '"'));
			}
			card.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
			var edit = q(SELECTORS.editButton, card);
			if (!edit) {
				return Promise.reject(new Error('KaroKitEtch: no Edit button on "' + title + '"'));
			}
			edit.click();
			return Promise.resolve({ opened: true });
		},

		/**
		 * Delete or reset a template by driving its native card button, then
		 * accepting the confirmation dialog.
		 * Returns a promise that resolves once the native flow is triggered.
		 */
		removeTemplate: function (title) {
			var card = findCardByTitle(title);
			if (!card) {
				return Promise.reject(new Error('KaroKitEtch: no native card for "' + title + '"'));
			}
			// Reveal hover controls (harmless if always present), then wait for the
			// destructive button in case controls render on hover.
			card.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
			return waitFor(function () {
				var c = findCardByTitle(title);
				return c && q(SELECTORS.dangerButton, c);
			}, 2000).then(function (danger) {
				danger.click(); // "Delete" -> "Confirm delete" (or "Reset" -> "Confirm reset")
				return waitForArmed(title, 4000);
			}).then(function (armed) {
				if (armed) { armed.click(); } // null => card already gone
				return { done: true };
			}, function () {
				return Promise.reject(new Error('KaroKitEtch: could not find destructive control for "' + title + '"'));
			});
		},

		/**
		 * Open Etch's native create flow for a column, matched by its label.
		 * The "+" opens a popover (portaled above our overlay) where the user
		 * finishes creating the template natively.
		 */
		createInColumn: function (label, anchorEl) {
			var wanted = String(label).trim();
			var navs = qa(SELECTORS.navItem);
			var wrappers = qa(SELECTORS.contentWrapper);
			var idx = navs.findIndex(function (n) {
				var p = q(SELECTORS.navName, n);
				return p && p.textContent.trim() === wanted;
			});
			if (idx === -1) {
				return Promise.reject(new Error('KaroKitEtch: no nav item for "' + label + '"'));
			}
			// Header "+" (non-empty columns) or empty-cell "+" (empty columns).
			var add = q(SELECTORS.navAddButton, navs[idx]) ||
				(wrappers[idx] && q(SELECTORS.navAddButton, wrappers[idx]));
			if (!add) {
				return Promise.reject(new Error('KaroKitEtch: no create button for "' + label + '"'));
			}

			// Toggle: if this popover is already open, close it by re-clicking its
			// OWN trigger (bits-ui popover triggers toggle themselves). Never send a
			// global Escape — Etch uses that to exit the whole templates screen.
			if (q(SELECTORS.createMenu) && 'true' === add.getAttribute('aria-expanded')) {
				add.click();
				return Promise.resolve({ toggledClosed: true });
			}

			// The native popover anchors to the native "+" (hidden under our
			// overlay). Catch it the instant it mounts (MutationObserver fires
			// before paint), hide it, move it under the board's "+", then reveal —
			// so it never flashes at the wrong position.
			if (anchorEl) {
				var handled = false;
				var obs = new MutationObserver(function () {
					if (handled) { return; }
					var menu = q(SELECTORS.createMenu);
					if (!menu) { return; }
					handled = true;
					obs.disconnect();
					var floater = floaterOf(menu);
					floater.style.setProperty('visibility', 'hidden', 'important');
					// floating-ui positions asynchronously after mount, so re-pin
					// every frame for a short window (while hidden) and reveal only
					// once it has settled — otherwise it flashes at the native spot.
					var start = Date.now();
					(function pin() {
						positionFloater(menu, anchorEl);
						if (Date.now() - start < 250) {
							requestAnimationFrame(pin);
						} else {
							floater.style.setProperty('visibility', 'visible', 'important');
						}
					})();
				});
				obs.observe(document.body, { childList: true, subtree: true });
				setTimeout(function () { obs.disconnect(); }, 2000);
			}

			add.click();
			return Promise.resolve({ opened: true });
		},

		/**
		 * Read the real create-option labels for a column (e.g. "Single Faq",
		 * "FAQ Archive") by briefly opening Etch's native popover invisibly, then
		 * closing it. Returns [{ label }]. The board renders these in its own menu.
		 */
		getCreateOptions: function (label) {
			var add = findAddButton(label);
			if (!add) { return Promise.reject(new Error('KaroKitEtch: no create button for "' + label + '"')); }
			return openHiddenCreateMenu(add).then(function (menu) {
				var opts = qa('button', menu)
					.map(function (b) { return b.textContent.trim(); })
					.filter(function (t) { return t; });
				closeNativeCreateMenu(add);
				return opts.map(function (t) { return { label: t }; });
			});
		},

		/**
		 * Trigger the actual create by opening Etch's native popover (invisibly)
		 * and clicking the option whose label matches. Etch's own flow takes over.
		 */
		pickCreateOption: function (label, optionLabel) {
			var add = findAddButton(label);
			if (!add) { return Promise.reject(new Error('KaroKitEtch: no create button for "' + label + '"')); }
			var wanted = String(optionLabel).trim();
			return openHiddenCreateMenu(add).then(function (menu) {
				var btn = qa('button', menu).find(function (b) { return b.textContent.trim() === wanted; });
				if (!btn) {
					closeNativeCreateMenu(add);
					throw new Error('KaroKitEtch: create option "' + optionLabel + '" not found');
				}
				btn.click(); // Etch handles the rest (dialog or direct create)
				return { picked: wanted };
			});
		},
	};

	window.KaroKitEtchBridge = Bridge;
})();
