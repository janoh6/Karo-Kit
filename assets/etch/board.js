/**
 * etb-board.js
 *
 * Board shell: header (search + graph toggle) and a body that renders either the
 * kanban columns or a structural graph. Column structure is mirrored from the
 * native templates DOM via KaroKitEtchBridge; the REST endpoint refines type/url/thumb.
 * Open/edit/create/delete are delegated to native controls through the bridge.
  *
 * Ported from the standalone "Etch Template Board" plugin (v0.14.2). The
 * WordPress-facing surface (options, REST namespace, globals, filters) was
 * renamed to Karo Kit conventions; the internal `etb-` CSS class names and
 * DOM ids are kept as-is — they never escape #etb-root, so renaming ~600 CSS
 * selectors would be churn without benefit.
*/
(function () {
	'use strict';

	var DATA = window.KaroKitEtchData || { restBase: '', nonce: '' };
	var Bridge = window.KaroKitEtchBridge;
	var state = { mounted: false, columns: [], order: [], enrichment: {}, apiSlugByTitle: {}, components: {}, filter: '', view: 'board', selectedNodeId: null, activeCategory: null, graphZoom: 1, legendPos: null };
	var warnedNoSlug = {};

	/* ---------- Etch API readiness (used only for place watching) ---------- */

	function etchReady(timeoutMs) {
		return new Promise(function (resolve, reject) {
			var deadline = Date.now() + (timeoutMs || 10000);
			(function poll() {
				if (window.etch) { resolve(window.etch); }
				else if (Date.now() > deadline) { reject(new Error('KaroKitEtch: Etch API did not load')); }
				else { setTimeout(poll, 100); }
			})();
		});
	}

	/* ---------- Utilities ---------- */

	function fetchJSON(path, opts) {
		return fetch(DATA.restBase + path, Object.assign({
			headers: { 'X-WP-Nonce': DATA.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		}, opts || {})).then(function (r) { return r.json(); });
	}

	function slugify(s) { return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); }
	function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
	var STATUS_LABELS = { wip: 'WIP', review: 'Review', ready: 'Ready', live: 'Live' };
	function matchesFilter(title) {
		return !state.filter || String(title).toLowerCase().indexOf(state.filter.toLowerCase()) !== -1;
	}

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (text != null) { node.textContent = text; }
		return node;
	}
	function svgEl(tag, attrs) {
		var node = document.createElementNS('http://www.w3.org/2000/svg', tag);
		Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
		return node;
	}
	function gripIcon() {
		var svg = svgEl('svg', { viewBox: '0 0 10 16', class: 'etb-column__grip-icon', 'aria-hidden': 'true' });
		[[3, 3], [7, 3], [3, 8], [7, 8], [3, 13], [7, 13]].forEach(function (p) {
			svg.appendChild(svgEl('circle', { cx: p[0], cy: p[1], r: 1.3 }));
		});
		return svg;
	}

	/* ---------- Data ---------- */

	function mergeColumns(nativeCols, enrichment, order) {
		var cols = nativeCols.map(function (col) {
			var items = col.cards.map(function (c) {
				var type = c.destructive === 'reset' ? 'System' : 'Single';
				var meta = enrichment[c.title];
				if (meta && meta.type) { type = cap(meta.type); }
				var slug = (meta && meta.slug) ? meta.slug : (state.apiSlugByTitle[c.title] || null);
				return {
					title: c.title,
					type: type,
					destructive: c.destructive,
					url: meta && meta.url ? meta.url : null,
					slug: slug,
					thumbUrl: meta && meta.thumbUrl ? meta.thumbUrl : null,
					stale: !!(meta && meta.stale),
					status: (meta && meta.status) ? meta.status : 'live',
					lastModified: meta && meta.lastModified ? meta.lastModified : null,
					components: (meta && meta.components) ? meta.components : [],
				};
			});
			return { key: slugify(col.label), label: col.label, hasAdd: col.hasAdd, items: items };
		});
		if (order && order.length) {
			cols.sort(function (a, b) {
				var ia = order.indexOf(a.key); var ib = order.indexOf(b.key);
				return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
			});
		}
		return cols;
	}

	function buildColumns() {
		state.columns = mergeColumns(Bridge.readColumns(), state.enrichment || {}, state.order || []);
	}

	/** Fetch enrichment + order and rebuild columns (no render). */
	function refreshData() {
		return Promise.all([
			fetchJSON('/templates').catch(function () { return {}; }),
			fetchJSON('/order').catch(function () { return []; }),
			etchReady().then(function (etch) { return etch.navigation.listTemplatesAsync(); }).catch(function () { return []; }),
			fetchJSON('/components').catch(function () { return {}; }),
		]).then(function (res) {
			state.enrichment = res[0] || {};
			state.order = res[1] || [];
			var map = {};
			(res[2] || []).forEach(function (t) { if (t && t.title) { map[String(t.title).trim()] = t.slug; } });
			state.apiSlugByTitle = map;
			state.components = res[3] || {};
			buildColumns();
		});
	}

	/* ---------- Thumbnail generation queue (one at a time) ---------- */

	var thumbQueue = [];
	var thumbInFlight = {};
	var thumbBusy = false;

	function queueThumb(slug, thumbEl) {
		if (thumbInFlight[slug]) { return; }
		thumbInFlight[slug] = true;
		thumbEl.classList.add('is-generating');
		thumbQueue.push({ slug: slug, thumbEl: thumbEl, attempts: 0 });
		if (!thumbBusy) { processThumbQueue(); }
	}

	function processThumbQueue() {
		if (!thumbQueue.length) { thumbBusy = false; return; }
		thumbBusy = true;
		var job = thumbQueue.shift();
		fetchJSON('/thumbnail', { method: 'POST', body: JSON.stringify({ slug: job.slug }) })
			.then(function (res) {
				if (res && res.thumbUrl) {
					applyThumb(job.thumbEl, res.thumbUrl, job.slug);
					delete thumbInFlight[job.slug];
				} else if (res && res.pending && job.attempts < 8) {
					// mShots is still generating — retry this slug shortly.
					job.attempts += 1;
					setTimeout(function () {
						thumbQueue.push(job);
						if (!thumbBusy) { processThumbQueue(); }
					}, 4000);
				} else {
					job.thumbEl.classList.remove('is-generating');
					if (res && res.pending) { console.warn('ETB thumbnail: still generating after retries for "' + job.slug + '" (is the site publicly reachable?)'); }
					else if (res && res.message) { console.warn('ETB thumbnail:', res.message); }
					delete thumbInFlight[job.slug];
				}
			})
			.catch(function (e) {
				job.thumbEl.classList.remove('is-generating');
				console.warn('ETB thumbnail failed', e);
				delete thumbInFlight[job.slug];
			})
			.then(function () { processThumbQueue(); });
	}

	function applyThumb(thumbEl, url, slug) {
		thumbEl.classList.remove('is-generating');
		thumbEl.textContent = '';
		var img = el('img', 'etb-card__thumb-img');
		img.src = url;
		img.loading = 'lazy';
		thumbEl.appendChild(img);
		state.columns.forEach(function (c) {
			c.items.forEach(function (it) { if (it.slug === slug) { it.thumbUrl = url; it.stale = false; } });
		});
	}

	/* ---------- Card kebab menu ---------- */

	function closeMenus() {
		document.querySelectorAll('.etb-menu').forEach(function (m) { m.remove(); });
	}

	/** Position a menu under an anchor, clamped/flipped to stay in the viewport. */
	function positionMenuUnder(menu, anchor) {
		var root = document.getElementById('etb-root');
		var rect = anchor.getBoundingClientRect();
		var rootRect = root.getBoundingClientRect();
		var pad = 8;

		// Cap height to the taller of (space below, space above) so the menu can
		// never exceed the viewport; it scrolls internally past that.
		var spaceBelow = rootRect.bottom - rect.bottom - 4 - pad;
		var spaceAbove = rect.top - rootRect.top - 4 - pad;
		menu.style.maxHeight = Math.max(120, Math.max(spaceBelow, spaceAbove)) + 'px';

		var mw = menu.offsetWidth || 180, mh = menu.offsetHeight || 200;

		var left = rect.right - mw;               // right-align to the anchor
		if (left < rootRect.left + pad) { left = rootRect.left + pad; }
		if (left + mw > rootRect.right - pad) { left = rootRect.right - pad - mw; }

		// Place below if it fits there; otherwise flip above.
		var top = rect.bottom + 4;
		if (mh > spaceBelow && spaceAbove > spaceBelow) { top = rect.top - 4 - mh; }
		if (top < rootRect.top + pad) { top = rootRect.top + pad; }

		menu.style.left = (left - rootRect.left) + 'px';
		menu.style.top = (top - rootRect.top) + 'px';
		menu.style.right = 'auto';
	}

	/** Toggle my own create menu, populated with Etch's real option labels. */
	function toggleCreateMenu(anchor, label) {
		var existing = document.querySelector('.etb-menu--create');
		var wasForThisAnchor = existing && existing.__anchor === anchor;
		closeMenus();
		if (wasForThisAnchor) { return; } // second click on same "+" → now closed
		if (!Bridge) { return; }

		Bridge.getCreateOptions(label).then(function (opts) {
			if (!opts || !opts.length) { console.warn('KaroKitEtch: no create options for "' + label + '"'); return; }
			var menu = el('div', 'etb-menu etb-menu--create');
			menu.__anchor = anchor;
			opts.forEach(function (opt) {
				var it = el('button', 'etb-menu__item', opt.label);
				it.type = 'button';
				it.addEventListener('click', function (e) {
					e.stopPropagation();
					closeMenus();
					Bridge.pickCreateOption(label, opt.label).then(watchForNewTemplate).catch(function (er) { console.warn(er.message); });
				});
				menu.appendChild(it);
			});
			menu.addEventListener('click', function (e) { e.stopPropagation(); });
			var root = document.getElementById('etb-root');
			root.appendChild(menu);
			positionMenuUnder(menu, anchor);
		}).catch(function (er) { console.warn(er.message); });
	}

	function executeRemove(item) {
		if (!Bridge) { return; }
		var title = item.title;
		state.columns.forEach(function (c) { c.items = c.items.filter(function (it) { return it.title !== title; }); });
		renderBody();
		Bridge.removeTemplate(title)
			.then(function () { reconcileAfterGone(title); })
			.catch(function (er) { console.warn(er.message); refreshData().then(renderBody); });
	}

	function reconcileAfterGone(title) {
		var deadline = Date.now() + 6000;
		(function poll() {
			var stillThere = Bridge.readColumns().some(function (c) {
				return c.cards.some(function (cd) { return cd.title === title; });
			});
			if (!stillThere || Date.now() > deadline) { refreshData().then(renderBody); }
			else { setTimeout(poll, 200); }
		})();
	}

	function openMenu(anchor, item) {
		closeMenus();
		var menu = el('div', 'etb-menu');

		var openBtn = el('button', 'etb-menu__item', 'Open');
		openBtn.type = 'button';
		openBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			closeMenus();
			if (item.url) { window.open(item.url, '_blank', 'noopener'); }
			else { console.warn('KaroKitEtch: no front-end URL resolved for "' + item.title + '"'); }
		});
		menu.appendChild(openBtn);

		var editBtn = el('button', 'etb-menu__item', 'Edit');
		editBtn.type = 'button';
		editBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			closeMenus();
			if (Bridge) { Bridge.openTemplate(item.title).catch(function (er) { console.warn(er.message); }); }
		});
		menu.appendChild(editBtn);

		var statusBtn = el('button', 'etb-menu__item', 'Status: ' + (STATUS_LABELS[item.status] || 'Live'));
		statusBtn.type = 'button';
		statusBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			if (!item.slug) { console.warn('KaroKitEtch: cannot set status for "' + item.title + '" — no slug resolved'); return; }
			var sub = menu.querySelector('.etb-menu__sub');
			if (sub) { sub.remove(); positionMenuUnder(menu, anchor); return; }
			sub = el('div', 'etb-menu__sub');
			['wip', 'review', 'ready', 'live'].forEach(function (s) {
				var opt = el('button', 'etb-menu__item' + (s === item.status ? ' is-current' : ''), STATUS_LABELS[s]);
				opt.type = 'button';
				opt.addEventListener('click', function (ev) {
					ev.stopPropagation();
					closeMenus();
					fetchJSON('/status', { method: 'POST', body: JSON.stringify({ slug: item.slug, status: s }) })
						.then(function () {
							item.status = s;
							state.columns.forEach(function (c) {
								c.items.forEach(function (it) { if (it.title === item.title) { it.status = s; } });
							});
							renderBody();
						})
						.catch(function (er) { console.warn('KaroKitEtch: status save failed', er); });
				});
				sub.appendChild(opt);
			});
			menu.appendChild(sub);
			positionMenuUnder(menu, anchor); // menu grew taller — re-place it in the viewport
		});
		menu.appendChild(statusBtn);

		var isReset = item.destructive === 'reset';
		var danger = el('button', 'etb-menu__item etb-menu__item--danger', isReset ? 'Reset' : 'Delete');
		danger.type = 'button';
		var armed = false;
		danger.addEventListener('click', function (e) {
			e.stopPropagation();
			if (!armed) {
				armed = true;
				danger.textContent = isReset ? 'Confirm reset' : 'Confirm delete';
				danger.classList.add('is-armed');
				return;
			}
			closeMenus();
			executeRemove(item);
		});
		menu.appendChild(danger);

		var root = document.getElementById('etb-root');
		root.appendChild(menu);
		positionMenuUnder(menu, anchor);
	}

	/* ---------- Card ---------- */

	function renderCard(item) {
		var card = el('div', 'etb-card');
		card.setAttribute('data-etb-title', item.title);

		var thumb = el('div', 'etb-card__thumb');
		if (item.thumbUrl) {
			var img = el('img', 'etb-card__thumb-img');
			img.src = item.thumbUrl; img.alt = item.title; img.loading = 'lazy';
			thumb.appendChild(img);
		} else {
			thumb.appendChild(el('span', 'etb-card__thumb-label', 'thumbnail'));
		}
		card.appendChild(thumb);
		if (item.slug && (!item.thumbUrl || item.stale)) {
			queueThumb(item.slug, thumb);
		} else if (!item.slug && !item.thumbUrl && !warnedNoSlug[item.title]) {
			warnedNoSlug[item.title] = true;
			console.warn('KaroKitEtch: could not resolve a slug for "' + item.title + '" — thumbnail skipped (title may not match a template slug)');
		}

		var footer = el('div', 'etb-card__footer');
		var meta = el('div', 'etb-card__meta');
		var nameRow = el('div', 'etb-card__name-row');
		nameRow.appendChild(el('span', 'etb-card__name', item.title));
		if (item.status && item.status !== 'live') {
			nameRow.appendChild(el('span', 'etb-badge etb-badge--' + item.status, STATUS_LABELS[item.status] || item.status));
		}
		meta.appendChild(nameRow);
		var typeLine = item.type + (item.lastModified ? ' \u00B7 ' + item.lastModified : '');
		meta.appendChild(el('span', 'etb-card__type', typeLine));
		footer.appendChild(meta);

		var kebab = el('button', 'etb-card__menu-btn');
		kebab.type = 'button';
		kebab.setAttribute('aria-label', 'Actions for ' + item.title);
		kebab.textContent = '\u22EE';
		kebab.addEventListener('click', function (e) { e.stopPropagation(); openMenu(kebab, item); });
		footer.appendChild(kebab);
		card.appendChild(footer);
		return card;
	}

	/* ---------- Column ---------- */

	function renderColumn(col) {
		var items = col.items.filter(function (it) { return matchesFilter(it.title); });
		if (state.filter && items.length === 0) { return null; } // focus results while filtering

		var column = el('section', 'etb-column');
		column.setAttribute('data-etb-key', col.key);

		var header = el('header', 'etb-column__header');
		var grip = el('span', 'etb-column__grip');
		grip.setAttribute('aria-hidden', 'true');
		grip.appendChild(gripIcon());
		header.appendChild(grip);
		header.appendChild(el('h2', 'etb-column__title', col.label));
		header.appendChild(el('span', 'etb-column__count', String(col.items.length)));

		if (col.hasAdd) {
			var add = el('button', 'etb-column__add');
			add.type = 'button';
			add.textContent = '+';
			add.setAttribute('aria-label', 'New template in ' + col.label);
			add.addEventListener('click', function (e) {
				e.stopPropagation();
				toggleCreateMenu(add, col.label);
			});
			header.appendChild(add);
		}
		column.appendChild(header);

		var list = el('div', 'etb-column__list');
		if (col.items.length === 0 && !state.filter) {
			var empty = el('div', 'etb-column__empty');
			empty.appendChild(el('p', 'etb-column__empty-text', 'No templates yet.'));
			if (col.hasAdd) {
				var eadd = el('button', 'etb-column__empty-add', '+');
				eadd.type = 'button';
				eadd.setAttribute('aria-label', 'New template in ' + col.label);
				eadd.addEventListener('click', function (e) {
					e.stopPropagation();
					toggleCreateMenu(eadd, col.label);
				});
				empty.appendChild(eadd);
			}
			list.appendChild(empty);
		} else {
			items.forEach(function (item) { list.appendChild(renderCard(item)); });
		}
		column.appendChild(list);

		attachColumnDrag(column, grip);
		return column;
	}

	function renderChips() {
		var chips = el('div', 'etb-chips');
		state.columns.forEach(function (col) {
			var chip = el('button', 'etb-chip');
			chip.type = 'button';
			chip.appendChild(el('span', null, col.label));
			chip.appendChild(el('span', 'etb-chip__count', String(col.items.length)));
			chip.addEventListener('click', function () {
				var root = document.getElementById('etb-root');
				if (!root) { return; }
				var track = root.querySelector('.etb-board__track');
				var target = track && track.querySelector('.etb-column[data-etb-key="' + col.key + '"]');
				if (track && target) {
					// Scroll only the track horizontally — never the page/builder.
					var delta = target.getBoundingClientRect().left - track.getBoundingClientRect().left;
					track.scrollTo({ left: Math.max(0, track.scrollLeft + delta - 8), behavior: 'smooth' });
				}
			});
			chips.appendChild(chip);
		});
		return chips;
	}

	function renderChipsInto() {
		var root = document.getElementById('etb-root');
		if (!root) { return; }
		var old = root.querySelector('.etb-chips');
		if (state.view === 'graph') { if (old) { old.remove(); } return; }
		var fresh = renderChips();
		if (old) { root.replaceChild(fresh, old); }
		else {
			var body = document.getElementById('etb-body');
			root.insertBefore(fresh, body);
		}
	}

	function renderBoard() {
		var wrap = el('div', 'etb-board');
		var track = el('div', 'etb-board__track');
		state.columns.forEach(function (col) {
			var c = renderColumn(col);
			if (c) { track.appendChild(c); }
		});
		wrap.appendChild(track);
		return wrap;
	}

	/* ---------- Column drag-to-reorder (from the grip) ---------- */

	function attachColumnDrag(column, grip) {
		grip.addEventListener('mousedown', function () { column.draggable = true; });
		document.addEventListener('mouseup', function () { column.draggable = false; });

		column.addEventListener('dragstart', function (e) {
			column.classList.add('is-dragging');
			if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; }
			var track = column.parentNode;
			// A ghost slot the width of the dragged column marks the drop position.
			var ghost = track.querySelector('.etb-column-ghost');
			if (!ghost) {
				ghost = el('div', 'etb-column-ghost');
				ghost.style.width = column.getBoundingClientRect().width + 'px';
			}
			track._etbGhost = ghost;
		});

		column.addEventListener('dragend', function () {
			var track = column.parentNode;
			column.classList.remove('is-dragging');
			column.draggable = false;
			if (track && track._etbGhost && track._etbGhost.parentNode) {
				// Land the column where the ghost sits.
				track.insertBefore(column, track._etbGhost);
				track._etbGhost.remove();
			}
			if (track) { track._etbGhost = null; }
			track.querySelectorAll('.is-drop-target').forEach(function (c) { c.classList.remove('is-drop-target'); });
			persistOrder();
			renderChipsInto(); // chip order follows the new column order
		});

		column.addEventListener('dragover', function (e) {
			e.preventDefault();
			if (e.dataTransfer) { e.dataTransfer.dropEffect = 'move'; }
			var track = column.parentNode;
			var dragged = track.querySelector('.is-dragging');
			var ghost = track._etbGhost;
			if (!dragged || dragged === column || !ghost) { return; }
			track.querySelectorAll('.is-drop-target').forEach(function (c) { c.classList.remove('is-drop-target'); });
			column.classList.add('is-drop-target');
			var rect = column.getBoundingClientRect();
			var after = e.clientX > rect.left + rect.width / 2;
			track.insertBefore(ghost, after ? column.nextSibling : column);
		});
	}

	function persistOrder() {
		var body = document.getElementById('etb-body');
		if (!body) { return; }
		var order = Array.prototype.map.call(body.querySelectorAll('.etb-column'), function (c) { return c.getAttribute('data-etb-key'); });
		state.order = order;
		// Reorder the in-memory columns to match, so chips and any re-render agree.
		state.columns.sort(function (a, b) {
			var ia = order.indexOf(a.key), ib = order.indexOf(b.key);
			return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
		});
		fetchJSON('/order', { method: 'POST', body: JSON.stringify({ order: order }) }).catch(function (e) { console.warn('KaroKitEtch: order save failed', e); });
	}

	/* ---------- Graph view (category hubs -> templates -> shared components) ---------- */

	function showTooltip(wrap, m, x, y) {
		hideTooltip(wrap);
		if (!m || state.selectedNodeId) { return; }
		var tip = el('div', 'etb-graph__tooltip');
		tip.appendChild(el('div', 'etb-graph__tooltip-title', m.title));
		tip.appendChild(el('div', 'etb-graph__tooltip-sub', m.subtitle));
		tip.style.left = (x + 90) + 'px';
		tip.style.top = Math.max(4, y - 6) + 'px';
		wrap.appendChild(tip);
	}
	function hideTooltip(wrap) {
		var t = wrap.querySelector('.etb-graph__tooltip');
		if (t) { t.remove(); }
	}
	function closeGraphPanel(wrap) {
		var p = wrap.querySelector('.etb-graph__panel');
		if (p) { p.remove(); }
	}
	function showGraphPanel(wrap, m, item) {
		closeGraphPanel(wrap);
		if (!m) { return; }
		var panel = el('div', 'etb-graph__panel');
		var head = el('div', 'etb-graph__panel-head');
		head.appendChild(el('div', 'etb-graph__panel-title', m.title));
		var closeBtn = el('button', 'etb-graph__panel-close', '\u00D7');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', 'Close');
		closeBtn.addEventListener('click', function (e) { e.stopPropagation(); state.selectedNodeId = null; closeGraphPanel(wrap); });
		head.appendChild(closeBtn);
		panel.appendChild(head);
		panel.appendChild(el('div', 'etb-graph__panel-sub', m.subtitle));

		panel.appendChild(el('div', 'etb-graph__panel-label', m.listLabel || 'Contents'));
		var list = el('div', 'etb-graph__panel-list');
		(m.items && m.items.length ? m.items : ['\u2014']).forEach(function (li) {
			list.appendChild(el('div', 'etb-graph__panel-item', li));
		});
		panel.appendChild(list);

		if (item) {
			var actions = el('div', 'etb-graph__panel-actions');
			var openBtn = el('button', 'etb-menu__item', 'Open');
			openBtn.type = 'button';
			openBtn.addEventListener('click', function (e) { e.stopPropagation(); if (item.url) { window.open(item.url, '_blank', 'noopener'); } });
			var editBtn = el('button', 'etb-menu__item', 'Edit');
			editBtn.type = 'button';
			editBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				if (Bridge) { Bridge.openTemplate(item.title).catch(function (er) { console.warn(er.message); }); }
			});
			actions.appendChild(openBtn);
			actions.appendChild(editBtn);
			panel.appendChild(actions);
		}

		panel.addEventListener('click', function (e) { e.stopPropagation(); });
		wrap.appendChild(panel);
	}

	/* ---------- Graph legend drag (free position, in-session only) ---------- */

	var LEGEND_DRAG_THRESHOLD = 3; // px before a press counts as a drag

	function setupLegendDrag(legend, handle) {
		var startX = 0, startY = 0, startLeft = 0, startTop = 0, dragging = false;

		function onPointerMove(e) {
			var dx = e.clientX - startX;
			var dy = e.clientY - startY;
			if (!dragging && (Math.abs(dx) > LEGEND_DRAG_THRESHOLD || Math.abs(dy) > LEGEND_DRAG_THRESHOLD)) {
				dragging = true;
				legend.classList.add('etb-graph__legend--dragging');
			}
			if (dragging) {
				legend.style.left = Math.max(0, startLeft + dx) + 'px';
				legend.style.top = Math.max(0, startTop + dy) + 'px';
				legend.style.right = 'auto';
			}
		}
		function onPointerUp() {
			document.removeEventListener('pointermove', onPointerMove);
			document.removeEventListener('pointerup', onPointerUp);
			if (dragging) {
				legend.classList.remove('etb-graph__legend--dragging');
				state.legendPos = { left: legend.offsetLeft, top: legend.offsetTop };
			}
			dragging = false;
		}
		handle.addEventListener('pointerdown', function (e) {
			if (e.button !== 0) { return; } // primary button / touch only
			e.preventDefault();
			startX = e.clientX; startY = e.clientY;
			startLeft = legend.offsetLeft; startTop = legend.offsetTop;
			document.addEventListener('pointermove', onPointerMove);
			document.addEventListener('pointerup', onPointerUp);
		});
	}

	function renderGraph() {
		var cols = state.columns.map(function (col) {
			return { key: col.key, label: col.label, items: col.items.filter(function (it) { return matchesFilter(it.title); }) };
		}).filter(function (c) { return !state.filter || c.items.length; });

		var nodeH = 44, tplNodeW = 158, compNodeW = 170, pad = 50;
		var TWO_PI = Math.PI * 2;

		var compEntries = Object.keys(state.components || {}).map(function (id) {
			var c = state.components[id] || {};
			return { id: id, label: c.label || ('Component ' + id), usedIn: c.usedIn || [] };
		});

		// -----------------------------------------------------------
		// Radial layout, computed in an arbitrary center-at-origin space
		// first (offset into positive SVG coordinates once the extent of
		// the whole graph is known). Hubs ring a center point; each hub's
		// own templates fan out from it along the same outward direction
		// as the hub, so a category reads as a spoke cluster rather than
		// a fixed column. Shared components sit on an outer ring near the
		// templates that use them; components nothing uses form a further
		// halo of their own, spaced evenly since they have no anchor.
		// -----------------------------------------------------------
		var hubCount = Math.max(cols.length, 1);
		var hubRadius = Math.max(190, hubCount * 34);
		var maxItems = cols.reduce(function (m, c) { return Math.max(m, c.items.length); }, 1);
		var maxTplRadius = 118 + Math.min(maxItems, 7) * 10;
		var compRingRadius = hubRadius + maxTplRadius + 90;
		var orphanRingRadius = compRingRadius + 90;

		function angularDelta(a, b) {
			var d = Math.abs(a - b) % TWO_PI;
			return d > Math.PI ? TWO_PI - d : d;
		}

		var hubs = [], templates = [], tplRawPos = {};

		cols.forEach(function (c, ci) {
			var angle = TWO_PI * (ci / hubCount) - Math.PI / 2;
			var hx = Math.cos(angle) * hubRadius, hy = Math.sin(angle) * hubRadius;
			var hubId = 'cat:' + c.key;
			hubs.push({ id: hubId, label: c.label, items: c.items, x: hx, y: hy });

			var n = c.items.length;
			var spread = (Math.min(280, Math.max(46, n * 32)) * Math.PI) / 180;
			var tplRadius = 118 + Math.min(n, 7) * 10;

			c.items.forEach(function (it, ii) {
				var a = n > 1 ? (angle - spread / 2 + (spread * ii) / (n - 1)) : angle;
				var tx = hx + Math.cos(a) * tplRadius, ty = hy + Math.sin(a) * tplRadius;
				var tplId = 'tpl:' + (it.slug || it.title);
				templates.push({ id: tplId, hubX: hx, hubY: hy, x: tx, y: ty, item: it });
				tplRawPos[tplId] = { x: tx, y: ty };
			});
		});

		// Components with usedIn are placed near the angular midpoint of the
		// templates that use them; ones already claiming that angle at that
		// radius push the next claimant out a ring further, rather than overlap.
		var placedAngles = [];
		var MIN_ANGLE_GAP = (26 * Math.PI) / 180;

		var components = compEntries.map(function (comp) {
			return { comp: comp, orphan: comp.usedIn.length === 0, x: 0, y: 0 };
		});

		components.filter(function (c) { return !c.orphan; }).forEach(function (c) {
			var sx = 0, sy = 0, n = 0;
			c.comp.usedIn.forEach(function (slug) {
				var p = tplRawPos['tpl:' + slug];
				if (p) { sx += p.x; sy += p.y; n++; }
			});
			var angle = n ? Math.atan2(sy / n, sx / n) : 0;
			var radius = compRingRadius, guard = 0;
			while (guard < 6 && placedAngles.some(function (p) { return p.radius === radius && angularDelta(angle, p.angle) < MIN_ANGLE_GAP; })) {
				radius += 70; guard++;
			}
			placedAngles.push({ angle: angle, radius: radius });
			c.x = Math.cos(angle) * radius;
			c.y = Math.sin(angle) * radius;
		});

		var orphanList = components.filter(function (c) { return c.orphan; });
		orphanList.forEach(function (c, i) {
			var angle = TWO_PI * (i / Math.max(orphanList.length, 1)) - Math.PI / 2;
			c.x = Math.cos(angle) * orphanRingRadius;
			c.y = Math.sin(angle) * orphanRingRadius;
		});

		// ---- Bounds -> offset so nothing sits at a negative coordinate ----
		var xs = [0], ys = [0];
		hubs.forEach(function (h) {
			var hw = Math.max(70, h.label.length * 6.5 + 40);
			xs.push(h.x - hw / 2, h.x + hw / 2); ys.push(h.y - 16, h.y + 16);
		});
		templates.forEach(function (t) { xs.push(t.x - tplNodeW / 2, t.x + tplNodeW / 2); ys.push(t.y - nodeH / 2, t.y + nodeH / 2); });
		components.forEach(function (c) { xs.push(c.x - compNodeW / 2, c.x + compNodeW / 2); ys.push(c.y - nodeH / 2, c.y + nodeH / 2); });

		var minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
		var minY = Math.min.apply(null, ys), maxY = Math.max.apply(null, ys);
		var offsetX = pad - minX, offsetY = pad - minY;
		var width = maxX - minX + pad * 2, height = maxY - minY + pad * 2;

		var wrap = el('div', 'etb-graph');
		var svg = svgEl('svg', { viewBox: '0 0 ' + width + ' ' + height, width: width, height: height, class: 'etb-graph__svg' });

		var pos = {};   // node id -> {x, y} (final, offset-applied center)
		var meta = {};  // node id -> panel/tooltip data
		var refs = {};  // node id -> template item (templates only)

		function attachNodeEvents(g, id, anchorX, anchorY) {
			g.addEventListener('mouseenter', function () { showTooltip(wrap, meta[id], anchorX, anchorY); });
			g.addEventListener('mouseleave', function () { hideTooltip(wrap); });
			g.addEventListener('click', function (e) {
				e.stopPropagation();
				hideTooltip(wrap);
				if (state.selectedNodeId === id) { state.selectedNodeId = null; closeGraphPanel(wrap); return; }
				state.selectedNodeId = id;
				showGraphPanel(wrap, meta[id], refs[id]);
			});
		}

		// Category hubs + their templates.
		hubs.forEach(function (h) {
			var hx = h.x + offsetX, hy = h.y + offsetY;
			meta[h.id] = {
				title: h.label,
				subtitle: h.items.length + ' template' + (h.items.length === 1 ? '' : 's'),
				listLabel: 'Templates', items: h.items.map(function (it) { return it.title; }),
			};

			var hubG = svgEl('g', { class: 'etb-graph__hub-node', tabindex: '0', role: 'button' });
			var hubText = svgEl('text', { x: hx, y: hy, 'text-anchor': 'middle', class: 'etb-graph__hub' });
			hubText.textContent = h.label + ' (' + h.items.length + ')';
			hubG.appendChild(hubText);
			attachNodeEvents(hubG, h.id, hx, hy - 10);
			svg.appendChild(hubG);
		});

		templates.forEach(function (t) {
			var tx = t.x + offsetX, ty = t.y + offsetY;
			var hx = t.hubX + offsetX, hy = t.hubY + offsetY;
			pos[t.id] = { x: tx, y: ty };
			refs[t.id] = t.item;

			var compLabels = (t.item.components || []).map(function (cc) { return cc.label; });
			var subBits = [t.item.type];
			if (t.item.status && t.item.status !== 'live') { subBits.push(STATUS_LABELS[t.item.status] || t.item.status); }
			if (t.item.lastModified) { subBits.push(t.item.lastModified); }
			meta[t.id] = {
				title: t.item.title, subtitle: subBits.join(' \u00B7 '),
				listLabel: 'Composition', items: compLabels,
			};

			svg.appendChild(svgEl('line', { x1: hx, y1: hy, x2: tx, y2: ty, class: 'etb-graph__edge' }));

			var statusCls = t.item.status && t.item.status !== 'live' ? ' etb-graph__node--' + t.item.status : '';
			var typeCls = ' etb-graph__node--' + String(t.item.type || 'single').toLowerCase();
			var g = svgEl('g', { class: 'etb-graph__node' + typeCls + statusCls, tabindex: '0', role: 'button' });
			g.appendChild(svgEl('rect', { x: tx - tplNodeW / 2, y: ty - nodeH / 2, width: tplNodeW, height: nodeH, rx: 8 }));
			var t2 = svgEl('text', { x: tx, y: ty + 5, 'text-anchor': 'middle', class: 'etb-graph__label' });
			t2.textContent = t.item.title;
			g.appendChild(t2);
			attachNodeEvents(g, t.id, tx, ty - nodeH / 2);
			svg.appendChild(g);
		});

		// Shared components: solid edges to every template that uses them; unused ones sit on the outer halo, unconnected.
		components.forEach(function (c) {
			var cx = c.x + offsetX, cy = c.y + offsetY;
			var compId = 'comp:' + c.comp.id;
			meta[compId] = {
				title: '\u29C9 ' + c.comp.label,
				subtitle: c.orphan
					? 'shared component \u00B7 not used in any template'
					: 'shared component \u00B7 used in ' + c.comp.usedIn.length + ' template' + (c.comp.usedIn.length === 1 ? '' : 's'),
				listLabel: 'Used in', items: c.comp.usedIn,
			};

			if (!c.orphan) {
				c.comp.usedIn.forEach(function (slug) {
					var p = pos['tpl:' + slug];
					if (p) { svg.appendChild(svgEl('line', { x1: p.x, y1: p.y, x2: cx, y2: cy, class: 'etb-graph__edge etb-graph__edge--component' })); }
				});
			}

			var g = svgEl('g', { class: 'etb-graph__node etb-graph__node--component' + (c.orphan ? ' etb-graph__node--orphan' : ''), tabindex: '0', role: 'button' });
			g.appendChild(svgEl('rect', { x: cx - compNodeW / 2, y: cy - nodeH / 2, width: compNodeW, height: nodeH, rx: 8 }));
			var t3 = svgEl('text', { x: cx, y: cy + 5, 'text-anchor': 'middle', class: 'etb-graph__label' });
			t3.textContent = c.comp.label;
			g.appendChild(t3);
			attachNodeEvents(g, compId, cx, cy);
			svg.appendChild(g);
		});

		svg.style.transform = 'scale(' + state.graphZoom + ')';
		wrap.appendChild(svg);

		// Legend
		var legend = el('div', 'etb-graph__legend');
		var legendHandle = el('div', 'etb-graph__legend-title');
		legendHandle.appendChild(gripIcon());
		legendHandle.appendChild(el('span', null, 'Legend'));
		legend.appendChild(legendHandle);
		[
			['category', 'Category'], ['single', 'Single template'], ['archive', 'Archive template'],
			['system', 'System template'], ['component', 'Shared component'], ['orphan', 'Unused component'],
		].forEach(function (row) {
			var r = el('div', 'etb-graph__legend-row');
			var dot = el('span', 'etb-graph__legend-dot');
			dot.style.background = 'var(--etb-c-' + row[0] + ')';
			r.appendChild(dot);
			r.appendChild(el('span', null, row[1]));
			legend.appendChild(r);
		});
		var reuse = el('div', 'etb-graph__legend-row');
		reuse.appendChild(el('span', 'etb-graph__legend-dash'));
		reuse.appendChild(el('span', null, 'reused by'));
		legend.appendChild(reuse);
		if (state.legendPos) {
			legend.style.left = state.legendPos.left + 'px';
			legend.style.top = state.legendPos.top + 'px';
			legend.style.right = 'auto';
		}
		setupLegendDrag(legend, legendHandle);
		wrap.appendChild(legend);

		// Zoom controls
		var zoom = el('div', 'etb-graph__zoom');
		var zLabel = el('button', 'etb-graph__zoom-label', Math.round(state.graphZoom * 100) + '%');
		zLabel.type = 'button';
		function applyZoom() { svg.style.transform = 'scale(' + state.graphZoom + ')'; zLabel.textContent = Math.round(state.graphZoom * 100) + '%'; }
		var zOut = el('button', null, '\u2212'); zOut.type = 'button';
		zOut.addEventListener('click', function (e) { e.stopPropagation(); state.graphZoom = Math.max(0.5, +(state.graphZoom - 0.1).toFixed(2)); applyZoom(); });
		var zIn = el('button', null, '+'); zIn.type = 'button';
		zIn.addEventListener('click', function (e) { e.stopPropagation(); state.graphZoom = Math.min(2, +(state.graphZoom + 0.1).toFixed(2)); applyZoom(); });
		zLabel.addEventListener('click', function (e) { e.stopPropagation(); state.graphZoom = 1; applyZoom(); });
		zoom.appendChild(zOut); zoom.appendChild(zLabel); zoom.appendChild(zIn);
		wrap.appendChild(zoom);

		// Keep an open panel across re-renders (e.g. after a status change).
		if (state.selectedNodeId && meta[state.selectedNodeId]) {
			showGraphPanel(wrap, meta[state.selectedNodeId], refs[state.selectedNodeId]);
		}

		return wrap;
	}

	/* ---------- Header ---------- */

	function renderHeader() {
		var h = el('header', 'etb-header');
		var brand = el('div', 'etb-header__brand');
		brand.appendChild(el('div', 'etb-header__logo', 'E'));
		brand.appendChild(el('div', 'etb-header__title', 'Etch templates'));
		h.appendChild(brand);

		var tools = el('div', 'etb-header__tools');

		var search = el('div', 'etb-search');
		var input = el('input', 'etb-search__input');
		input.id = 'etb-search';
		input.type = 'text';
		input.placeholder = 'Search templates & commands';
		input.autocomplete = 'off';
		input.value = state.filter;
		input.addEventListener('input', function () { state.filter = input.value.trim(); renderBody(); });
		search.appendChild(input);
		tools.appendChild(search);

		var toggle = el('button', 'etb-view-toggle');
		toggle.type = 'button';
		toggle.appendChild(el('span', null, '\u25C7'));
		toggle.appendChild(el('span', null, state.view === 'graph' ? 'Board view' : 'Graph view'));
		toggle.addEventListener('click', function () {
			state.view = state.view === 'graph' ? 'board' : 'graph';
			state.selectedNodeId = null;
			renderHeaderInto();
			renderBody();
		});
		tools.appendChild(toggle);

		h.appendChild(tools);
		return h;
	}

	function renderHeaderInto() {
		var root = document.getElementById('etb-root');
		if (!root) { return; }
		var old = root.querySelector('.etb-header');
		if (old) { root.replaceChild(renderHeader(), old); }
		renderChipsInto();
	}

	/* ---------- Body render ---------- */

	function renderBody() {
		var body = document.getElementById('etb-body');
		if (!body) { return; }
		renderChipsInto();
		body.replaceChildren(state.view === 'graph' ? renderGraph() : renderBoard());
	}

	/* ---------- Mount / place watching ---------- */

	function sampledBackground(node) {
		var elm = node;
		while (elm && elm !== document.documentElement) {
			var bg = getComputedStyle(elm).backgroundColor;
			if (bg && bg !== 'transparent' && bg.indexOf('rgba(0, 0, 0, 0)') === -1) { return bg; }
			elm = elm.parentElement;
		}
		return null;
	}

	function populate(attempt) {
		buildColumns();
		if (!state.columns.length && (attempt || 0) < 20) {
			setTimeout(function () { populate((attempt || 0) + 1); }, 100);
			return;
		}
		renderBody();
	}

	function mount() {
		if (state.mounted || !Bridge) { return; }
		var host = Bridge.getScreenBody();
		if (!host) { return; }
		if (getComputedStyle(host).position === 'static') { host.style.position = 'relative'; }

		// Stop the NATIVE screen (underneath) from scrolling into view beside the
		// board — that's the outer horizontal scrollbar. The native DOM stays put
		// for the bridge; it just can't be scrolled or seen. Restored on unmount.
		state.hostPrevOverflow = host.style.overflow;
		host.style.overflow = 'hidden';
		host.scrollLeft = 0;
		host.scrollTop = 0;

		// Mount the opaque overlay immediately so the native screen never flashes.
		var root = el('div');
		root.id = 'etb-root';
		root.appendChild(renderHeader());
		var body = el('div', 'etb-body');
		body.id = 'etb-body';
		root.appendChild(body);
		root.addEventListener('click', function () {
			closeMenus();
			if (state.selectedNodeId) {
				state.selectedNodeId = null;
				var panel = root.querySelector('.etb-graph__panel');
				if (panel) { panel.remove(); }
			}
		});
		host.appendChild(root);
		state.mounted = true;

		populate();                          // sync render from the native DOM
		refreshData().then(renderBody);      // enrich (types/urls/thumbs) + rerender
	}

	function unmount() {
		if (!state.mounted) { return; }
		var root = document.getElementById('etb-root');
		if (root) { root.remove(); }
		var host = Bridge.getScreenBody && Bridge.getScreenBody();
		if (host) { host.style.overflow = state.hostPrevOverflow || ''; }
		state.mounted = false;
	}

	/** After opening the native create popover, refresh once a new card appears. */
	function watchForNewTemplate() {
		var startCount = Bridge.readColumns().reduce(function (n, c) { return n + c.cards.length; }, 0);
		var deadline = Date.now() + 30000;
		(function poll() {
			if (!state.mounted || Date.now() > deadline) { return; }
			var now = Bridge.readColumns().reduce(function (n, c) { return n + c.cards.length; }, 0);
			if (now > startCount) { refreshData().then(renderBody); }
			else { setTimeout(poll, 1200); }
		})();
	}

	function watchPlace() {
		etchReady().then(function (etch) {
			setInterval(function () {
				var place;
				try { place = etch.navigation.getCurrentPlace(); } catch (e) { return; }
				if (place === 'templates' && !state.mounted) { mount(); }
				else if (place !== 'templates' && state.mounted) { unmount(); }
			}, 300);
		}).catch(function (e) { console.warn(e.message); });
	}

	// Esc clears the board search when it's focused. Cmd/Ctrl+K is intentionally
	// NOT bound here — Etch provides its own built-in command palette on that combo.
	document.addEventListener('keydown', function (e) {
		if (!state.mounted) { return; }
		if (e.key === 'Escape') {
			var s2 = document.getElementById('etb-search');
			if (s2 && document.activeElement === s2) {
				s2.value = ''; state.filter = ''; s2.blur(); renderBody();
			}
		}
	});

	watchPlace();
})();
