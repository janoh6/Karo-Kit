/**
 * structure.js
 * ------------------------------------------------------------------
 * Arrow-based reordering logic for the Etch structure panel, built
 * against the public API (window.etch / @digital-gravy/etch-public-api).
 *
 * Interaction model (locked):
 *   - hover : show the valid VERTICAL moves (up / down). If the element
 *             has no vertical moves (e.g. only child), fall through and
 *             show the valid NESTING moves immediately, so hover is never
 *             empty.
 *   - dwell : after a configurable delay, reveal the NESTING axis
 *             (left = outdent, right = indent). Optionally also show the
 *             invalid arrows greyed-out as a teaching layer.
 *
 * Axes are orthogonal:  vertical = order among siblings (safe, common),
 *                       horizontal = nesting depth (rarer, consequential).
 *
 * API facts this is built on (docs.etchwp.com/public-api/blocks):
 *   - The document ROOT is `null`. Top-level blocks have parentId === null
 *     and are reordered with move(id, null, index).
 *   - Reads: getJson(id) -> { id, parentId, type, tag?, children[] };
 *            getTree() -> top-level blocks.
 *   - Mutation: move(blockId, newParentId, index?). Buffered; persisted by
 *     etch.saveAsync(). Moving into a block that can't contain it throws
 *     EtchApiError code "WRONG_BLOCK_TYPE" and leaves the block in place.
 *   - There is NO container query — canAcceptChildren() below is a heuristic,
 *     backstopped by catching WRONG_BLOCK_TYPE on the move itself.
 *
 * Everything is pure/testable except createEtchAdapter(), the single seam
 * that touches the live builder.
 * ------------------------------------------------------------------
 *
 * Ported from the standalone "Etch Structure Move Arrows" plugin (v1.1.1).
 * WordPress-facing names follow Karo Kit conventions; the internal `esm-`
 * CSS classes are kept as-is (they never escape the injected arrow cluster).
 */

(function () {
'use strict';

// ---------------------------------------------------------------- config
const DEFAULTS = Object.freeze({
  dwellDelay: 700,             // ms before the nesting axis is revealed
  showDisabledOnDwell: false,  // teaching layer: render invalid arrows greyed
  indentTarget: 'last-child',  // where an indented element lands: 'last-child' | 'first-child'
});

const STATE = Object.freeze({
  ACTIVE: 'active',     // valid, visible on hover
  DWELL: 'dwell',       // valid, revealed only after the dwell delay
  DISABLED: 'disabled', // invalid, shown greyed only if teaching layer is on
  HIDDEN: 'hidden',     // not rendered at all
});

// HTML void elements can never hold children.
const VOID_TAGS = new Set([
  'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
  'link', 'meta', 'param', 'source', 'track', 'wbr',
]);

// -------------------------------------------------------- structural core
// Depends ONLY on the adapter's read methods. parentId === null means the
// document root (a valid sibling context, reordered via move(id, null, i)).

function contextOf(adapter, id) {
  const parentId = adapter.getParent(id);     // null === document root
  const sibs = adapter.getChildren(parentId); // getTree() ids when parentId is null
  return { parentId, sibs, i: sibs.indexOf(id) };
}

function prevSibling(adapter, id) {
  const { sibs, i } = contextOf(adapter, id);
  return i > 0 ? sibs[i - 1] : null;
}

function nextSibling(adapter, id) {
  const { sibs, i } = contextOf(adapter, id);
  return i > -1 && i < sibs.length - 1 ? sibs[i + 1] : null;
}

/**
 * Which of the four moves are legal for `id`, from tree shape alone.
 * @returns {{up:boolean, down:boolean, left:boolean, right:boolean}}
 */
function computeValidMoves(adapter, id) {
  const { parentId, sibs, i } = contextOf(adapter, id);
  const prev = i > 0 ? sibs[i - 1] : null;
  return {
    up: i > 0,                                   // swap with previous sibling
    down: i > -1 && i < sibs.length - 1,         // swap with next sibling
    left: parentId !== null,                     // outdent — only if not already at root
    right: prev != null && adapter.canAcceptChildren(prev), // indent into a container prev
  };
}

/**
 * Map valid moves onto per-arrow render states for the hover/dwell model.
 * @returns {{up:string, down:string, left:string, right:string}}
 */
function resolveArrowStates(moves, options = {}) {
  const opts = { ...DEFAULTS, ...options };
  const hasVertical = moves.up || moves.down;
  const state = { up: STATE.HIDDEN, down: STATE.HIDDEN, left: STATE.HIDDEN, right: STATE.HIDDEN };

  if (moves.up) state.up = STATE.ACTIVE;
  if (moves.down) state.down = STATE.ACTIVE;

  // nesting: on dwell normally; promoted to hover when there is nothing
  // vertical to show, so an only-child row still surfaces something.
  const nestingPhase = hasVertical ? STATE.DWELL : STATE.ACTIVE;
  if (moves.left) state.left = nestingPhase;
  if (moves.right) state.right = nestingPhase;

  if (opts.showDisabledOnDwell) {
    for (const dir of ['up', 'down', 'left', 'right']) {
      if (!moves[dir]) state[dir] = STATE.DISABLED;
    }
  }
  return state;
}

/** The arrows to actually render for a given phase. */
function arrowsForPhase(state, phase /* 'hover' | 'dwell' */) {
  return Object.entries(state)
    .filter(([, s]) => (phase === 'hover' ? s === STATE.ACTIVE : s !== STATE.HIDDEN))
    .map(([dir, s]) => ({ dir, interactive: s !== STATE.DISABLED }));
}

// ----------------------------------------------------------- move actions
// Each reduces to one etch.blocks.move(id, newParentId, index). Every move
// is wrapped so a WRONG_BLOCK_TYPE refusal degrades to a no-op (false)
// rather than throwing — the runtime backstop for the container heuristic.

function isWrongBlockType(err) {
  return !!err && (err.code === 'WRONG_BLOCK_TYPE' || err.name === 'EtchApiError');
}

function safeMove(adapter, id, parentId, index) {
  try {
    adapter.move(id, parentId, index);
    return true;
  } catch (err) {
    if (isWrongBlockType(err)) return false; // block left in place by Etch
    throw err;
  }
}

function moveUp(adapter, id) {
  const { parentId, i } = contextOf(adapter, id);
  if (i <= 0) return false;
  return safeMove(adapter, id, parentId, i - 1);
}

function moveDown(adapter, id) {
  const { parentId, sibs, i } = contextOf(adapter, id);
  if (i < 0 || i >= sibs.length - 1) return false;
  return safeMove(adapter, id, parentId, i + 1);
}

function outdent(adapter, id) {
  const parentId = adapter.getParent(id);
  if (parentId == null) return false;                 // already at root
  const grandparentId = adapter.getParent(parentId);  // may be null (root) — fine
  const gpChildren = adapter.getChildren(grandparentId);
  const parentIdx = gpChildren.indexOf(parentId);
  return safeMove(adapter, id, grandparentId, parentIdx + 1); // sit after former parent
}

function indent(adapter, id, options = {}) {
  const opts = { ...DEFAULTS, ...options };
  const prev = prevSibling(adapter, id);
  if (prev == null || !adapter.canAcceptChildren(prev)) return false;
  const target = opts.indentTarget === 'first-child' ? 0 : adapter.getChildren(prev).length;
  return safeMove(adapter, id, prev, target);
}

function applyMove(adapter, id, dir, options) {
  switch (dir) {
    case 'up': return moveUp(adapter, id);
    case 'down': return moveDown(adapter, id);
    case 'left': return outdent(adapter, id);
    case 'right': return indent(adapter, id, options);
    default: return false;
  }
}

// --------------------------------------------------- hover/dwell controller
// Headless: owns only timing. Rendering + DOM binding live in `onRender`.
class RowInteractionController {
  constructor(adapter, { dwellDelay, showDisabledOnDwell, onRender } = {}) {
    this.adapter = adapter;
    this.dwellDelay = dwellDelay ?? DEFAULTS.dwellDelay;
    this.showDisabledOnDwell = showDisabledOnDwell ?? DEFAULTS.showDisabledOnDwell;
    this.onRender = onRender ?? (() => {});
    this._timer = null;
    this._activeId = null;
  }

  enter(id) {
    this._clear();
    this._activeId = id;
    const state = resolveArrowStates(
      computeValidMoves(this.adapter, id),
      { showDisabledOnDwell: this.showDisabledOnDwell }
    );
    this.onRender(id, 'hover', arrowsForPhase(state, 'hover'), state);
    this._timer = setTimeout(() => {
      this.onRender(id, 'dwell', arrowsForPhase(state, 'dwell'), state);
    }, this.dwellDelay);
  }

  leave(id) {
    if (this._activeId !== id) return;
    this._clear();
    this.onRender(id, 'none', [], null);
  }

  _clear() {
    if (this._timer) { clearTimeout(this._timer); this._timer = null; }
    this._activeId = null;
  }
}

// ----------------------------------------------------------- Etch adapter
// The only Etch-specific surface. Acquire the handle with:
//   import { getEtch, isEtchAvailable } from "@digital-gravy/etch-public-api";
//   if (isEtchAvailable()) createEtchAdapter(getEtch());
//
// Reads are synchronous (the logic runs inline on hover). Moves are buffered;
// call adapter.persistAsync() (etch.saveAsync) when you want them written.
function createEtchAdapter(etch) {
  const handle = etch || (typeof window !== 'undefined' ? window.etch : undefined);
  if (!handle || !handle.blocks) {
    throw new Error('createEtchAdapter: no Etch handle (call getEtch() inside the builder)');
  }
  const blocks = handle.blocks;

  const canAcceptChildren = (id) => {
    let b;
    try { b = blocks.getJson(id); } catch { return false; }
    if (!b) return false;
    // Heuristic only — the authoritative check is move() throwing WRONG_BLOCK_TYPE.
    // Non-void HTML elements are treated as containers; text/svg/image/component
    // are conservatively excluded (slots/component internals need edit mode).
    if (b.type === 'etch/element') return !VOID_TAGS.has(String(b.tag || '').toLowerCase());
    return false;
  };

  return {
    // --- reads ---
    getParent: (id) => blocks.getJson(id).parentId ?? null,
    getChildren: (parentId) =>
      (parentId == null
        ? blocks.getTree()
        : (blocks.getJson(parentId).children || [])
      ).map((b) => b.id),
    canAcceptChildren,
    // --- write (single primitive) ---
    move: (id, parentId, index) => blocks.move(id, parentId ?? null, index),
    // --- persistence ---
    persistAsync: () => handle.saveAsync(),
  };
}

// --------------------------------------------------------------- exports
const api = {
  DEFAULTS, STATE, VOID_TAGS,
  computeValidMoves, resolveArrowStates, arrowsForPhase,
  moveUp, moveDown, outdent, indent, applyMove,
  RowInteractionController, createEtchAdapter,
};
if (typeof module !== 'undefined' && module.exports) module.exports = api;
if (typeof window !== 'undefined') window.KaroKitEtchStructure = api;

// ================================================================ SELF-TEST
if (typeof require !== 'undefined' && require.main === module) {
  // In-memory adapter mirroring Etch's null-root model + WRONG_BLOCK_TYPE.
  function mockAdapter() {
    const roots = [];                 // ordered top-level ids (the null root)
    const nodes = new Map();          // id -> { parent, children:[], accepts }
    const listFor = (pid) => (pid == null ? roots : nodes.get(pid).children);
    const add = (id, parent, accepts) => {
      nodes.set(id, { parent, children: [], accepts });
      listFor(parent).push(id);
    };
    const adapter = {
      getParent: (id) => nodes.get(id).parent,
      getChildren: (pid) => listFor(pid).slice(),
      canAcceptChildren: (id) => nodes.get(id).accepts,
      move: (id, newParent, index) => {
        // container check BEFORE mutating (Etch leaves the block in place)
        if (newParent != null && !nodes.get(newParent).accepts) {
          const e = new Error('cannot contain'); e.name = 'EtchApiError'; e.code = 'WRONG_BLOCK_TYPE';
          throw e;
        }
        const cur = listFor(nodes.get(id).parent);
        cur.splice(cur.indexOf(id), 1);           // detach
        nodes.get(id).parent = newParent;
        const arr = listFor(newParent);
        let idx = index;
        if (idx == null) idx = arr.length;        // append
        else if (idx < 0) idx = arr.length + idx + 1;
        idx = Math.max(0, Math.min(idx, arr.length));
        arr.splice(idx, 0, id);
      },
    };
    return { adapter, add, nodes };
  }

  //  (root)
  //  ├─ section (container)
  //  │   ├─ innerBox (container)
  //  │   ├─ heading  (leaf)
  //  │   ├─ text     (leaf)
  //  │   └─ image    (leaf)
  //  ├─ grid (container)
  //  │   └─ card (container)   <- only child
  //  └─ footer (leaf)
  const { adapter, add, nodes } = mockAdapter();
  add('section', null, true);
  add('innerBox', 'section', true);
  add('heading', 'section', false);
  add('text', 'section', false);
  add('image', 'section', false);
  add('grid', null, true);
  add('card', 'grid', true);
  add('footer', null, false);

  let pass = 0, fail = 0;
  const eq = (label, got, want) => {
    const g = JSON.stringify(got), w = JSON.stringify(want);
    if (g === w) pass++;
    else { fail++; console.log(`FAIL ${label}\n     got  ${g}\n     want ${w}`); }
  };

  // --- validity (null-root aware) ---
  eq('heading (nested, prev=container)', computeValidMoves(adapter, 'heading'),
    { up: true, down: true, left: true, right: true });
  eq('innerBox (first child)', computeValidMoves(adapter, 'innerBox'),
    { up: false, down: true, left: true, right: false });
  eq('image (last child, prev=leaf)', computeValidMoves(adapter, 'image'),
    { up: true, down: false, left: true, right: false });
  eq('section (top-level first: no outdent)', computeValidMoves(adapter, 'section'),
    { up: false, down: true, left: false, right: false });
  eq('footer (top-level last, prev=container)', computeValidMoves(adapter, 'footer'),
    { up: true, down: false, left: false, right: true });
  eq('card (only child, nested: outdent only)', computeValidMoves(adapter, 'card'),
    { up: false, down: false, left: true, right: false });

  // --- hover/dwell resolution ---
  eq('heading arrow states', resolveArrowStates(computeValidMoves(adapter, 'heading')),
    { up: 'active', down: 'active', left: 'dwell', right: 'dwell' });
  eq('card fallthrough (nesting -> hover)', resolveArrowStates(computeValidMoves(adapter, 'card')),
    { up: 'hidden', down: 'hidden', left: 'active', right: 'hidden' });
  eq('teaching layer greys invalids', resolveArrowStates(computeValidMoves(adapter, 'section'), { showDisabledOnDwell: true }),
    { up: 'disabled', down: 'active', left: 'disabled', right: 'disabled' });
  eq('card hover phase = left only',
    arrowsForPhase(resolveArrowStates(computeValidMoves(adapter, 'card')), 'hover'),
    [{ dir: 'left', interactive: true }]);

  // --- executions ---
  eq('top-level reorder via null parent', (() => { moveDown(adapter, 'section'); return adapter.getChildren(null); })(),
    ['grid', 'section', 'footer']);
  moveUp(adapter, 'section'); // restore root order
  eq('root order restored', adapter.getChildren(null), ['section', 'grid', 'footer']);

  eq('outdent top-level is a no-op', applyMove(adapter, 'section', 'left'), false);

  moveDown(adapter, 'heading');
  eq('moveDown heading', adapter.getChildren('section'), ['innerBox', 'text', 'heading', 'image']);

  eq('indent blocked into leaf (prev=text)', indent(adapter, 'heading'), false);
  eq('  tree unchanged after blocked indent', adapter.getChildren('section'), ['innerBox', 'text', 'heading', 'image']);

  moveUp(adapter, 'heading'); moveUp(adapter, 'heading');
  eq('moveUp heading to front', adapter.getChildren('section'), ['heading', 'innerBox', 'text', 'image']);

  moveDown(adapter, 'heading'); // now prev = innerBox (container)
  eq('indent heading into innerBox', (() => { indent(adapter, 'heading'); return adapter.getChildren('innerBox'); })(), ['heading']);
  eq('  heading left section', adapter.getChildren('section').includes('heading'), false);

  eq('outdent heading back after innerBox', (() => { outdent(adapter, 'heading'); return adapter.getParent('heading'); })(), 'section');
  eq('  innerBox now empty', adapter.getChildren('innerBox'), []);

  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}

})();
