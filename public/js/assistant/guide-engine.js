// Console guide engine. No DOM access at import time: node --test loads this file directly.
// Registry data comes from resources/assistant/guides.json, filtered per role by the server.
// Laravel pages are full reloads, so tour progress moves between pages through sessionStorage.
// The engine never clicks, types or submits anything on the page.

export const STORAGE_KEY = 'seapass.guide.v1';
export const MAX_STEPS = 14;
export const RESUME_MAX_AGE_MS = 30 * 60 * 1000;

const DASHES = /\s*[–—·]\s*/g;
export function clean(value, max) {
  return typeof value === 'string' ? value.replace(DASHES, ', ').replace(/\s+/g, ' ').trim().slice(0, max) : '';
}

export function normalizePath(path) {
  const p = String(path || '').split(/[?#]/)[0].replace(/\/+$/, '');
  return (p || '/').toLowerCase();
}

export function createRegistry(raw = {}) {
  const anchors = raw && typeof raw.anchors === 'object' && raw.anchors ? raw.anchors : {};
  const pages = raw && typeof raw.pages === 'object' && raw.pages ? raw.pages : {};
  const tours = raw && typeof raw.tours === 'object' && raw.tours ? raw.tours : {};
  const aliases = new Map();
  for (const [path, page] of Object.entries(pages)) {
    aliases.set(normalizePath(path), path);
    for (const alias of page?.aliases || []) aliases.set(normalizePath(alias), path);
  }
  const registry = {
    role: typeof raw.role === 'string' ? raw.role : '',
    anchors, pages, tours,
    ids: () => Object.keys(anchors),
    anchor: id => (typeof id === 'string' && Object.hasOwn(anchors, id) ? anchors[id] : null),
    isAnchor: id => registry.anchor(id) !== null,
    // Canonical registry path for a browser path, or null when the page has no guide.
    pageOf: path => aliases.get(normalizePath(path)) || null,
    samePage: (a, b) => {
      const pa = registry.pageOf(a) || normalizePath(a);
      const pb = registry.pageOf(b) || normalizePath(b);
      return pa === pb;
    },
    pageLabel: path => pages[registry.pageOf(path) || path]?.label || 'other',
    presets: () => Object.keys(tours),
    hasGuides: () => Object.keys(anchors).length > 0,
  };
  return registry;
}

function distance(a, b) {
  let row = Array.from({ length: b.length + 1 }, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    const next = [i];
    for (let j = 1; j <= b.length; j++) next[j] = Math.min(next[j - 1] + 1, row[j] + 1, row[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
    row = next;
  }
  return row[b.length];
}

// Same ranking as App\Services\Assistant\GuideRegistry::closeMatches.
export function closeMatches(registry, target, limit = 5) {
  const text = String(target || '').toLowerCase();
  const words = text.split(/[ ._-]+/).filter(Boolean);
  return registry.ids()
    .map(id => ({ id, score: words.reduce((n, w) => n + (id.toLowerCase().includes(w) || String(registry.anchor(id).label).toLowerCase().includes(w) ? 1 : 0), 0), d: distance(text, id.toLowerCase()) }))
    .sort((a, b) => b.score - a.score || a.d - b.d || (a.id < b.id ? -1 : 1))
    .slice(0, limit).map(m => m.id);
}

export function targetError(registry, target) {
  const matches = closeMatches(registry, target);
  return `Unknown guide target: ${String(target || '').slice(0, 80)}.${matches.length ? ` Try: ${matches.join(', ')}.` : ''}`;
}

function anchorStep(registry, target, title, body) {
  const a = registry.anchor(target);
  return { target, title: clean(title, 100) || clean(a.label, 100), body: clean(body, 600) || clean(a.description, 600) };
}

// Validates a highlight ({target, message}) or a tour ({preset} or {title, steps}).
// Unknown custom-tour targets are dropped; a tour with no valid step is refused.
export function validateGuide(registry, args, { single = false } = {}) {
  if (!args || typeof args !== 'object' || Array.isArray(args)) throw new Error('Give a guide target or a tour.');
  if (single) {
    if (!registry.isAnchor(args.target)) throw new Error(targetError(registry, args.target));
    const step = anchorStep(registry, args.target, '', args.message);
    return { title: step.title, single: true, preset: null, dropped: [], steps: [step] };
  }
  if (args.preset !== undefined && args.preset !== null && args.preset !== '') {
    const tour = registry.tours[args.preset];
    if (!tour) throw new Error(`Unknown tour. Choose: ${['current_page', ...registry.presets()].join(', ')}.`);
    const steps = (tour.steps || []).filter(s => registry.isAnchor(s.target)).map(s => anchorStep(registry, s.target, s.title, s.body));
    if (!steps.length) throw new Error('That tour has no steps for your account.');
    return { title: clean(tour.title, 100) || 'Tour', single: false, preset: args.preset, dropped: [], steps };
  }
  if (!Array.isArray(args.steps) || !args.steps.length || args.steps.length > MAX_STEPS) throw new Error(`Give 1 to ${MAX_STEPS} steps, or a preset tour.`);
  const title = clean(args.title, 100);
  if (!title) throw new Error('Give the tour a short title.');
  const dropped = [];
  const steps = args.steps.flatMap(step => {
    if (!step || typeof step !== 'object' || !registry.isAnchor(step.target)) { dropped.push(String(step?.target ?? '').slice(0, 80)); return []; }
    return [anchorStep(registry, step.target, step.title, step.body)];
  });
  if (!steps.length) throw new Error(targetError(registry, dropped[0]));
  return { title, single: false, preset: null, steps, dropped };
}

// Anchors that live on the current page, in registry order. Shell parts are left out.
export function currentPageTour(registry, path, limit = 8) {
  const page = registry.pageOf(path);
  const steps = page ? registry.ids().filter(id => registry.anchor(id).page === page).slice(0, limit).map(id => anchorStep(registry, id, '', '')) : [];
  return { title: 'This page', single: false, preset: 'current_page', dropped: [], steps };
}

export function initialGuide() {
  return { active: false, title: '', steps: [], index: 0, single: false, preset: null, status: 'idle', target: null };
}
export function currentStep(state) { return state.active ? state.steps[state.index] || null : null; }

// Statuses: loading, shown, not_found, elsewhere (step lives on another page), navigating.
export function reduceGuide(state, action) {
  if (action.type === 'start') return { ...initialGuide(), ...action.tour, index: Math.max(0, Math.min(action.index || 0, action.tour.steps.length - 1)), active: true, status: 'loading' };
  if (action.type === 'exit' || action.type === 'skip') return initialGuide();
  if (!state.active) return state;
  if (action.type === 'next') return state.index >= state.steps.length - 1 ? initialGuide() : { ...state, index: state.index + 1, target: null, status: 'loading' };
  if (action.type === 'back') return state.index > 0 ? { ...state, index: state.index - 1, target: null, status: 'loading' } : state;
  if (action.type === 'found') return { ...state, target: action.target, status: 'shown' };
  if (action.type === 'missing') return { ...state, target: null, status: 'not_found' };
  if (action.type === 'elsewhere') return { ...state, target: null, status: 'elsewhere' };
  if (action.type === 'navigating') return { ...state, target: null, status: 'navigating' };
  return state;
}

// What crosses a page load. Elements and legacy selector steps never do.
export function serializeGuide(state, meta) {
  return JSON.stringify({
    v: 1, identity: meta.identity, role: meta.role, savedAt: meta.now,
    title: state.title, single: state.single, preset: state.preset, index: state.index,
    steps: state.steps.map(s => ({ target: s.target, title: s.title, body: s.body })),
  });
}

export function restoreGuide(text, registry, meta) {
  let saved;
  try { saved = JSON.parse(text); } catch { return null; }
  if (!saved || saved.v !== 1 || saved.identity !== meta.identity || saved.role !== meta.role) return null;
  if (typeof saved.savedAt !== 'number' || meta.now - saved.savedAt > RESUME_MAX_AGE_MS || meta.now < saved.savedAt) return null;
  if (!Array.isArray(saved.steps) || !saved.steps.length || saved.steps.length > MAX_STEPS) return null;
  if (!saved.steps.every(s => s && registry.isAnchor(s.target))) return null;
  const index = Number.isInteger(saved.index) ? saved.index : -1;
  if (index < 0 || index >= saved.steps.length) return null;
  return {
    tour: {
      title: clean(saved.title, 100) || 'Tour', single: !!saved.single, preset: typeof saved.preset === 'string' ? saved.preset : null, dropped: [],
      steps: saved.steps.map(s => anchorStep(registry, s.target, s.title, s.body)),
    },
    index,
  };
}

// env: { path(), navigate(path), find(step) -> element|null, storage {get,set,remove}, now(), identity,
//        setTimeout, clearTimeout, scrollIntoView(el) }
export function createGuide({ registry, env, timeoutMs = 4000, pollMs = 100, fallbackAfterMs = 600, onOpen = () => {}, onClose = () => {} }) {
  let state = initialGuide();
  let seq = 0;
  let timer = null;
  const listeners = new Set();
  const meta = () => ({ identity: env.identity, role: registry.role, now: env.now() });
  function emit(action) {
    const was = state.active;
    state = reduceGuide(state, action);
    for (const fn of listeners) fn(state);
    if (was && !state.active) onClose();
  }
  function stopLookup() { seq++; if (timer !== null) { env.clearTimeout(timer); timer = null; } }
  function stepPage(step) { return step?.target ? registry.anchor(step.target)?.page || null : null; }
  // A hidden part points at the next best visible one: a row at its table, a sidebar link at the menu button.
  function fallbackChain(id) {
    const chain = [];
    for (let next = registry.anchor(id)?.fallback; next && !chain.includes(next) && next !== id && chain.length < 4; next = registry.anchor(next)?.fallback) chain.push(next);
    return chain;
  }
  function persistAndGo(path) {
    try { env.storage.set(STORAGE_KEY, serializeGuide(state, meta())); } catch { /* storage blocked: the tour ends on this page */ }
    emit({ type: 'navigating' });
    env.navigate(path);
  }
  // Resolves when the current step is shown, missing, elsewhere or navigating.
  function show(gesture) {
    stopLookup();
    const step = currentStep(state);
    if (!step) return Promise.resolve(state);
    const page = stepPage(step);
    if (page && !registry.samePage(env.path(), page)) {
      if (gesture) persistAndGo(page);
      else emit({ type: 'elsewhere' });
      return Promise.resolve(state);
    }
    const mine = seq;
    const started = env.now();
    const fallbacks = fallbackChain(step.target);
    return new Promise(resolve => {
      const check = () => {
        timer = null;
        if (mine !== seq || !state.active) return resolve(state);
        const elapsed = env.now() - started;
        let el = env.find(step);
        if (!el && elapsed >= fallbackAfterMs) for (const id of fallbacks) { el = env.find({ target: id }); if (el) break; }
        if (el) {
          env.scrollIntoView?.(el);
          emit({ type: 'found', target: el });
          return resolve(state);
        }
        if (elapsed >= timeoutMs) { emit({ type: 'missing' }); return resolve(state); }
        timer = env.setTimeout(check, pollMs);
      };
      check();
    });
  }
  const api = {
    get state() { return state; },
    get registry() { return registry; },
    subscribe(fn) { listeners.add(fn); fn(state); return () => listeners.delete(fn); },
    // tour: a validated tour object; options.gesture: started by a user click, so it may change page.
    async begin(tour, { gesture = false, index = 0 } = {}) {
      if (!tour?.steps?.length) throw new Error('There is no guided walkthrough for this page yet.');
      api.exit();
      emit({ type: 'start', tour, index });
      onOpen();
      await show(gesture);
      return api.result(tour);
    },
    async start(args, { gesture = false, path } = {}) {
      const tour = args?.preset === 'current_page' ? currentPageTour(registry, path ?? env.path()) : validateGuide(registry, args);
      return api.begin(tour, { gesture });
    },
    async highlight(args, { gesture = false } = {}) {
      return api.begin(validateGuide(registry, args, { single: true }), { gesture });
    },
    result(tour) {
      const status = !state.active ? 'cancelled' : { shown: tour.single ? 'shown' : 'started', not_found: 'not_found', elsewhere: 'elsewhere', navigating: 'navigating' }[state.status] || 'started';
      return { status, total: tour.steps.length, dropped: tour.dropped || [], target: tour.steps[0]?.target || null };
    },
    async next() { if (!state.active || state.status === 'loading' || state.status === 'navigating') return state; emit({ type: 'next' }); return show(true); },
    async back() { if (!state.active || state.index === 0 || state.status === 'loading' || state.status === 'navigating') return state; emit({ type: 'back' }); return show(true); },
    // The step lives on another page and the user asked to go there.
    async go() { if (state.status === 'elsewhere') return show(true); return state; },
    // Re-find a target replaced by a live refresh, without waiting.
    refresh() {
      const step = currentStep(state);
      if (!step || state.status !== 'shown') return null;
      let el = env.find(step);
      if (!el) for (const id of fallbackChain(step.target)) { el = env.find({ target: id }); if (el) break; }
      if (el && el !== state.target) emit({ type: 'found', target: el });
      return el;
    },
    skip() { api.exit(); },
    exit() { stopLookup(); if (state.active) emit({ type: 'exit' }); },
    // Called once on page load. Picks up a tour that moved here from the previous page.
    async resume() {
      let text = null;
      try { text = env.storage.get(STORAGE_KEY); env.storage.remove(STORAGE_KEY); } catch { return null; }
      if (!text) return null;
      const saved = restoreGuide(text, registry, meta());
      if (!saved) return null;
      const page = stepPage(saved.tour.steps[saved.index]);
      if (page && !registry.samePage(env.path(), page)) return null;
      return api.begin(saved.tour, { index: saved.index, gesture: false });
    },
  };
  return api;
}

// Card stays on screen and off the target's centre. Ported from the DIELCO guide.
export function placeGuide(rect, viewport, size) {
  const margin = 12, gap = 24;
  const w = Math.min(size.width, viewport.width - margin * 2), h = Math.min(size.height, viewport.height - margin * 2);
  const clamp = (n, low, high) => Math.max(low, Math.min(high, n));
  let x = (viewport.width - w) / 2, y = (viewport.height - h) / 2;
  if (rect) {
    const choices = [
      { space: viewport.height - rect.y - rect.height, x: rect.x + rect.width / 2 - w / 2, y: rect.y + rect.height + gap, need: h + gap },
      { space: rect.y, x: rect.x + rect.width / 2 - w / 2, y: rect.y - h - gap, need: h + gap },
      { space: viewport.width - rect.x - rect.width, x: rect.x + rect.width + gap, y: rect.y + rect.height / 2 - h / 2, need: w + gap },
      { space: rect.x, x: rect.x - w - gap, y: rect.y + rect.height / 2 - h / 2, need: w + gap },
    ].sort((a, b) => (b.space - b.need) - (a.space - a.need));
    x = choices[0].x; y = choices[0].y;
  }
  x = clamp(x, margin, Math.max(margin, viewport.width - w - margin)); y = clamp(y, margin, Math.max(margin, viewport.height - h - margin));
  if (!rect) return { card: { x, y, width: w }, arrow: null };
  const cx = rect.x + rect.width / 2, cy = rect.y + rect.height / 2;
  const covers = () => cx >= x && cx <= x + w && cy >= y && cy <= y + h;
  if (covers()) y = cy > viewport.height / 2 ? margin : Math.max(margin, viewport.height - h - margin);
  if (covers()) x = cx > viewport.width / 2 ? margin : Math.max(margin, viewport.width - w - margin);
  const edge = (px, py, bx, by, bw, bh) => {
    const dx = px - (bx + bw / 2), dy = py - (by + bh / 2);
    const s = Math.max(Math.abs(dx) / (bw / 2 || 1), Math.abs(dy) / (bh / 2 || 1), 0.001);
    return { x: bx + bw / 2 + dx / s, y: by + bh / 2 + dy / s };
  };
  const start = edge(cx, cy, x, y, w, h);
  let tip = edge(x + w / 2, y + h / 2, rect.x, rect.y, rect.width, rect.height);
  const inward = (tip.x - start.x) * (cx - start.x) + (tip.y - start.y) * (cy - start.y) > 0;
  if (!inward || Math.hypot(tip.x - start.x, tip.y - start.y) < 24) {
    const d = Math.hypot(cx - start.x, cy - start.y) || 1, reach = Math.min(72, d * 0.6);
    tip = { x: start.x + (cx - start.x) / d * reach, y: start.y + (cy - start.y) / d * reach };
  }
  return { card: { x, y, width: w }, arrow: { x: start.x, y: start.y, tx: tip.x, ty: tip.y } };
}
