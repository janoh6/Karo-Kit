/**
 * etch-structure-move-render.js
 * ------------------------------------------------------------------
 * Render + integration layer for structure.js.
 *
 * Blends into the Etch structure panel by mounting the arrows INSIDE the
 * existing action cluster (.etch-builder-accordion__quick-actions) and
 * tagging the SVGs `.etch-icon`, so they inherit Etch's own rules:
 *     .etch-builder-accordion__quick-actions svg {
 *       color:   var(--e-structure-item-header-foreground-color);
 *       opacity: var(--e-inactive-opacity);
 *     }
 *     .etch-icon { flex-shrink: 0; }
 * i.e. correct colour, dimming and light/dark tracking for free.
 *
 * Icons match Etch's geometry exactly: 24-grid viewBox rendered at 14px,
 * Lucide chevron paths, aria-hidden (the accessible name lives on the button).
 *
 * TWO values still need confirming from devtools — they're the only ungrounded
 * bits (see CONFIG). Everything else is derived from the inspected panel.
 * ------------------------------------------------------------------
 *
 * Ported from the standalone "Etch Structure Move Arrows" plugin (v1.1.1).
 * WordPress-facing names follow Karo Kit conventions; the internal `esm-`
 * CSS classes are kept as-is (they never escape the injected arrow cluster).
 */

(function () {
'use strict';

/* global document, MutationObserver, requestAnimationFrame */

const __ESM = (typeof window !== 'undefined' && window.KaroKitEtchStructure)
  ? window.KaroKitEtchStructure
  : require('./structure.js');
const {
  computeValidMoves, resolveArrowStates, arrowsForPhase, applyMove,
  RowInteractionController,
} = __ESM;

// -------------------------------------------------------------- CONFIG
// Pre-filled from the inspected panel. The two marked (( CONFIRM )) lines
// are the DOM lookups I can't see yet — set them from devtools.
const CONFIG = Object.freeze({
  // The row is the accordion HEADER, which carries data-blockid. Selecting by the
  // attribute catches every block row (header + leaf variants); and because child
  // rows live OUTSIDE the header element, this keeps nested hover/mount scoping clean.
  rowSelector: '[data-blockid]',                        // === .etch-builder-accordion__header
  getBlockId: (rowEl) => rowEl.dataset.blockid ?? null, // data-blockid, e.g. "7239ted"
  // Preferred mount points, in priority order; falls back to the row itself.
  // quick-actions inherits Etch's icon colour rule; header-content always exists.
  mountSelectors: [
    '.etch-builder-accordion__quick-actions',
    '.etch-builder-accordion__header-content',
  ],
  placement: 'prepend',   // 'prepend' | 'append' within the mount (flip if arrows land on the wrong side)
  skipReadonly: true,     // don't offer moves on data-block-readonly rows
  gap: '0.375rem',        // spacing between arrow buttons (tweak to match cluster)
  iconSizePx: 14,         // Etch renders .etch-icon at 14px
});

// Resolve the best in-row mount point.
function mountFor(row, cfg) {
  for (const sel of cfg.mountSelectors) {
    const el = row.querySelector(sel);
    if (el) return el;
  }
  return row;
}

// ------------------------------------------------------------- icons
// Lucide chevron paths on a 24-grid, stroked with currentColor.
const CHEVRON_PATHS = {
  up: 'm18 15-6-6-6 6',
  down: 'm6 9 6 6 6-6',
  left: 'm15 18-6-6 6-6',
  right: 'm9 18 6-6-6-6',
};
const LABELS = { up: 'Move up', down: 'Move down', left: 'Move out (outdent)', right: 'Move in (indent)' };

function chevronSvg(dir, sizePx) {
  return (
    `<svg class="etch-icon" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" role="img"` +
    ` width="${sizePx}px" height="${sizePx}px" viewBox="0 0 24 24" fill="none"` +
    ` stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">` +
    `<path d="${CHEVRON_PATHS[dir]}"/></svg>`
  );
}

// -------------------------------------------------------------- styles
// Scoped to our own classes; colour/opacity come from Etch's cluster rule.
// Active arrows override the inactive dimming; only the teaching-layer
// (disabled) arrows stay dim + non-interactive.
const STYLE_ID = 'esm-styles';
function injectStyles(cfg) {
  if (document.getElementById(STYLE_ID)) return;
  const style = document.createElement('style');
  style.id = STYLE_ID;
  style.textContent = `
    .esm-arrows { display:inline-flex; align-items:center; gap:${cfg.gap}; }
    .esm-arrow {
      display:inline-flex; align-items:center; justify-content:center;
      background:none; border:0; padding:0; margin:0; line-height:0;
      cursor:pointer; opacity:1;
      color: var(--e-structure-item-header-foreground-color, currentColor);
    }
    .esm-arrow:hover { opacity:1; }
    .esm-arrow.esm-arrow--disabled {
      opacity: var(--e-inactive-opacity, .4);
      cursor:default; pointer-events:none;
    }
  `;
  document.head.appendChild(style);
}

// --------------------------------------------------------- renderer
function createRenderer(adapter, options = {}) {
  const cfg = { ...CONFIG, ...options };
  injectStyles(cfg);

  let saveTimer = null;
  const scheduleSave = () => {
    if (!adapter.persistAsync) return;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => { adapter.persistAsync(); }, 400);
  };

  const rowFor = (id) =>
    [...document.querySelectorAll(cfg.rowSelector)].find((r) => cfg.getBlockId(r) === id) || null;

  const clearArrows = (row) => {
    const holder = row && row.querySelector('.esm-arrows');
    if (holder) holder.remove();
  };

  // Build (or rebuild) the arrow cluster for `id` in the given phase.
  function paint(id, phase, arrows, ctrl) {
    const row = rowFor(id);
    if (!row) return;
    const mount = mountFor(row, cfg);
    clearArrows(row);
    if (!arrows.length) return;

    const holder = document.createElement('span');
    holder.className = 'esm-arrows';
    holder.dataset.blockId = id;

    for (const { dir, interactive } of arrows) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'esm-arrow etch-builder-button etch-builder-button--variant-icon'
        + (interactive ? '' : ' esm-arrow--disabled');
      btn.setAttribute('aria-label', LABELS[dir]);
      btn.title = LABELS[dir];
      btn.dataset.dir = dir;
      btn.innerHTML = chevronSvg(dir, cfg.iconSizePx);
      if (interactive) {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();               // don't select/toggle the row
          e.preventDefault();
          const moved = applyMove(adapter, id, dir, cfg);
          if (moved) {
            scheduleSave();
            // Re-hover the (possibly re-rendered) row so arrows reflect the new position.
            requestAnimationFrame(() => ctrl && ctrl.enter(id));
          }
        });
      }
      holder.appendChild(btn);
    }
    if (cfg.placement === 'append') mount.appendChild(holder);
    else mount.insertBefore(holder, mount.firstChild);
  }

  return {
    onRender(id, phase, arrows, _state, ctrl) {
      if (phase === 'none') { const row = rowFor(id); clearArrows(row); return; }
      paint(id, phase, arrows, ctrl);
    },
    rowFor,
    clearArrows,
  };
}

// ----------------------------------------------------------- attach
// One call to wire everything: hover -> controller, controller -> renderer,
// plus a MutationObserver so Svelte re-renders don't strip a hovered row's
// arrows mid-interaction.
function attach(adapter, options = {}) {
  const cfg = { ...CONFIG, ...options };
  const renderer = createRenderer(adapter, cfg);
  let hoveredId = null;

  const controller = new RowInteractionController(adapter, {
    dwellDelay: cfg.dwellDelay,
    showDisabledOnDwell: cfg.showDisabledOnDwell,
    // pass the controller through so clicks can re-trigger enter()
    onRender: (id, phase, arrows, state) => renderer.onRender(id, phase, arrows, state, controller),
  });

  const rowFromEvent = (e) => e.target.closest(cfg.rowSelector);

  const onEnter = (e) => {
    const row = rowFromEvent(e);
    if (!row) return;
    if (cfg.skipReadonly && row.dataset.blockReadonly === 'true') return;
    const id = cfg.getBlockId(row);
    if (!id || id === hoveredId) return;
    hoveredId = id;
    controller.enter(id);
  };
  const onLeave = (e) => {
    const row = rowFromEvent(e);
    if (!row) return;
    if (e.relatedTarget && row.contains(e.relatedTarget)) return; // still inside the same row
    const id = cfg.getBlockId(row);
    if (id && id === hoveredId) { hoveredId = null; controller.leave(id); }
  };

  document.addEventListener('mouseover', onEnter, true);
  document.addEventListener('mouseout', onLeave, true);

  // Survive Svelte re-renders: if the hovered row lost its arrows, repaint.
  const observer = new MutationObserver(() => {
    if (!hoveredId) return;
    const row = renderer.rowFor(hoveredId);
    if (row && !row.querySelector('.esm-arrows')) controller.enter(hoveredId);
  });
  observer.observe(document.body, { childList: true, subtree: true });

  return function teardown() {
    document.removeEventListener('mouseover', onEnter, true);
    document.removeEventListener('mouseout', onLeave, true);
    observer.disconnect();
    if (hoveredId) renderer.clearArrows(renderer.rowFor(hoveredId));
  };
}

// --------------------------------------------------------------- exports
const renderApi = { CONFIG, CHEVRON_PATHS, chevronSvg, injectStyles, createRenderer, attach };
if (typeof module !== 'undefined' && module.exports) module.exports = renderApi;
if (typeof window !== 'undefined') window.KaroKitEtchStructureRender = renderApi;

})();
