/**
 * esm-boot.js — readiness bootstrap for the structure-panel move arrows.
 *
 * Load AFTER structure.js and structure-render.js.
 * No build step: it talks to window.etch directly and waits until the
 * builder has finished booting before attaching.
 *
 * Options come from the Etch tab (KaroKitEtchStructureData), not from editing
 * this file — the standalone plugin had them hard-coded here.
 *
 * Ported from the standalone "Etch Structure Move Arrows" plugin (v1.1.1).
 * WordPress-facing names follow Karo Kit conventions; the internal `esm-`
 * CSS classes are kept as-is (they never escape the injected arrow cluster).
 */
(function () {
  'use strict';

  var DATA = window.KaroKitEtchStructureData || {};
  var OPTIONS = {
    dwellDelay: typeof DATA.dwellDelay === 'number' ? DATA.dwellDelay : 700,
    showDisabledOnDwell: !!DATA.showDisabledOnDwell,
    placement: DATA.placement === 'append' ? 'append' : 'prepend',
  };

  function attach(etch) {
    if (!window.KaroKitEtchStructure || !window.KaroKitEtchStructureRender) {
      console.warn('[karo-kit-etch] libraries not loaded before boot');
      return;
    }
    try {
      var adapter = window.KaroKitEtchStructure.createEtchAdapter(etch);
      // expose the teardown so you can re-init while iterating in the console
      window.__karoKitEtchStructureTeardown =
        window.KaroKitEtchStructureRender.attach(adapter, OPTIONS);
    } catch (e) {
      console.error('[karo-kit-etch] attach failed', e);
    }
  }

  function poll(tries) {
    var etch = window.etch;
    if (etch && etch.blocks && typeof etch.blocks.getTree === 'function') {
      return attach(etch);
    }
    if (tries <= 0) {
      console.warn('[karo-kit-etch] window.etch not detected — not on the builder screen, ' +
        'or this build exposes getEtch() only (use the bundled route in that case)');
      return;
    }
    setTimeout(function () { poll(tries - 1); }, 100);
  }

  poll(100); // ~10s readiness window
})();
