// node --test tests/js
// DOM-free tests for public/js/assistant/guide-engine.js against the real registry in resources/assistant/guides.json.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  STORAGE_KEY, MAX_STEPS, RESUME_MAX_AGE_MS, clean, normalizePath, createRegistry, closeMatches, validateGuide, currentPageTour,
  initialGuide, reduceGuide, currentStep, serializeGuide, restoreGuide, createGuide, placeGuide,
} from '../../public/js/assistant/guide-engine.js';

const data = JSON.parse(readFileSync(new URL('../../resources/assistant/guides.json', import.meta.url), 'utf8'));

// Same slice as App\Services\Assistant\GuideRegistry::forRole (the page receives that JSON).
function slice(role) {
  const anchors = Object.fromEntries(Object.entries(data.anchors).filter(([, a]) => a.roles.includes(role)).map(([id, a]) => [id, { label: a.label, description: a.description, page: a.page, ...(a.fallback ? { fallback: a.fallback } : {}) }]));
  const pages = Object.fromEntries(Object.entries(data.pages).filter(([, p]) => p.roles.includes(role)).map(([path, p]) => [path, { label: p.label, ...(p.aliases ? { aliases: p.aliases } : {}) }]));
  return { role, pages, anchors, tours: data.tours[role] || {} };
}
const admin = createRegistry(slice('admin'));
const staff = createRegistry(slice('staff'));

// A manual clock and timer queue, so lookups and timeouts run without waiting.
function harness({ path = '/admin/dashboard', present = [], identity = 'App\\Models\\User:1', registry = admin, storage } = {}) {
  let now = 1_000_000, seq = 0;
  const timers = [], store = new Map(), navigations = [], found = new Set(present), scrolled = [];
  const env = {
    identity, here: path,
    path: () => env.here,
    navigate: p => navigations.push(p),
    find: step => (found.has(step.target) ? { id: step.target } : null),
    storage: storage || { get: k => (store.has(k) ? store.get(k) : null), set: (k, v) => store.set(k, v), remove: k => store.delete(k) },
    now: () => now,
    setTimeout: (fn, ms) => { const id = ++seq; timers.push({ id, fn, at: now + ms }); return id; },
    clearTimeout: id => { const i = timers.findIndex(t => t.id === id); if (i >= 0) timers.splice(i, 1); },
    scrollIntoView: el => scrolled.push(el.id),
  };
  const tick = async ms => {
    const end = now + ms;
    for (;;) {
      timers.sort((a, b) => a.at - b.at);
      if (!timers.length || timers[0].at > end) break;
      const t = timers.shift(); now = t.at; t.fn();
      await Promise.resolve();
    }
    now = end;
    await new Promise(r => setImmediate(r));
  };
  let opened = 0, closed = 0;
  const guide = createGuide({ registry, env, onOpen: () => opened++, onClose: () => closed++ });
  return { guide, env, tick, store, navigations, found, timers, scrolled, setNow: v => { now = v; }, counts: () => ({ opened, closed }) };
}

test('registry is role scoped: admin has anchors and presets, staff has none', () => {
  assert.ok(admin.hasGuides());
  assert.deepEqual(admin.presets().sort(), ['create_trip', 'getting_started', 'publish_advisory', 'verify_discount']);
  assert.equal(staff.hasGuides(), false);
  assert.deepEqual(staff.presets(), []);
  assert.equal(admin.isAnchor('toString'), false, 'prototype keys are not anchors');
  assert.equal(admin.isAnchor(['tickets.approve']), false);
});

test('every preset step is an admin anchor and getting_started has 8 to 14 steps', () => {
  for (const [name, tour] of Object.entries(admin.tours)) {
    const checked = validateGuide(admin, { preset: name });
    assert.equal(checked.steps.length, tour.steps.length, name);
    for (const s of checked.steps) { assert.ok(admin.isAnchor(s.target), `${name} ${s.target}`); assert.ok(s.title && s.body); }
  }
  const n = admin.tours.getting_started.steps.length;
  assert.ok(n >= 8 && n <= MAX_STEPS);
});

test('paths normalise and aliases resolve to the registry page', () => {
  assert.equal(normalizePath('/admin/Tickets/?date=2026-11-09#x'), '/admin/tickets');
  assert.equal(normalizePath(''), '/');
  assert.equal(admin.pageOf('/admin/user-management/ScannerStaff'), '/admin/user-management/scanner-staff');
  assert.equal(admin.pageOf('/admin/profile'), '/admin/user-management/profile');
  assert.equal(admin.pageOf('/admin/user-management/roles-permissions'), null);
  assert.ok(admin.samePage('/admin/user-management/ScannerStaff', '/admin/user-management/scanner-staff'));
});

test('highlight validation refuses unknown ids with close matches', () => {
  assert.throws(() => validateGuide(admin, { target: 'tickets.aprove' }, { single: true }), /Unknown guide target: tickets\.aprove\. Try: tickets\.approve/);
  assert.equal(closeMatches(admin, 'fare save')[0], 'fares.save');
  assert.throws(() => validateGuide(staff, { target: 'tickets.approve' }, { single: true }), /Unknown guide target/);
  assert.throws(() => validateGuide(admin, null, { single: true }), /Give a guide target/);
  const one = validateGuide(admin, { target: 'fares.save', message: 'Save here — then check.' }, { single: true });
  assert.deepEqual(one.steps, [{ target: 'fares.save', title: 'Save All Fares', body: 'Save here, then check.' }]);
  assert.equal(validateGuide(admin, { target: 'fares.save' }, { single: true }).steps[0].body, data.anchors['fares.save'].description);
});

test('tour validation: presets, custom tours drop unknown targets, limits', () => {
  assert.throws(() => validateGuide(admin, { preset: 'whole_app' }), /Choose: current_page, getting_started/);
  assert.throws(() => validateGuide(staff, { preset: 'getting_started' }), /Unknown tour/);
  assert.throws(() => validateGuide(admin, { title: 'A', steps: [] }), /1 to 14/);
  assert.throws(() => validateGuide(admin, { title: 'A', steps: Array(MAX_STEPS + 1).fill({ target: 'fares.save' }) }), /1 to 14/);
  assert.throws(() => validateGuide(admin, { steps: [{ target: 'fares.save' }] }), /short title/);
  assert.throws(() => validateGuide(admin, { title: 'A', steps: [{ target: 'nope' }, null] }), /Unknown guide target: nope/);
  const custom = validateGuide(admin, { title: 'Fares', steps: [{ target: 'made.up' }, { target: 'fares.matrix', title: 'Fares', body: 'Set fares.' }, 'junk', { target: 'fares.save' }] });
  assert.deepEqual(custom.dropped, ['made.up', '']);
  assert.deepEqual(custom.steps.map(s => s.target), ['fares.matrix', 'fares.save']);
  assert.equal(custom.steps[1].title, 'Save All Fares');
  assert.equal(clean('a—b · c', 100), 'a, b, c');
  assert.equal(clean(42, 10), '');
});

test('current page tour uses only that page and leaves shell parts out', () => {
  assert.deepEqual(currentPageTour(admin, '/admin/fare-management/').steps.map(s => s.target), ['fares.matrix', 'fares.save']);
  assert.ok(currentPageTour(admin, '/admin/user-management/ScannerStaff').steps.every(s => data.anchors[s.target].page === '/admin/user-management/scanner-staff'));
  assert.deepEqual(currentPageTour(admin, '/admin/user-management/roles-permissions').steps, []);
  assert.ok(currentPageTour(admin, '/admin/dashboard').steps.length <= 8);
});

test('reducer: next, back, missing, last step and skip', () => {
  const tour = validateGuide(admin, { preset: 'verify_discount' });
  let s = reduceGuide(initialGuide(), { type: 'start', tour });
  assert.equal(currentStep(s).target, 'tickets.date');
  assert.equal(s.status, 'loading');
  assert.equal(reduceGuide(s, { type: 'back' }), s, 'back on the first step does nothing');
  s = reduceGuide(s, { type: 'missing' }); assert.equal(s.status, 'not_found');
  s = reduceGuide(s, { type: 'next' }); assert.equal(s.index, 1); assert.equal(s.status, 'loading');
  s = reduceGuide(s, { type: 'back' }); assert.equal(s.index, 0);
  assert.equal(reduceGuide(s, { type: 'skip' }).active, false);
  for (let i = 0; i < tour.steps.length; i++) s = reduceGuide(s, { type: 'next' });
  assert.equal(s.active, false); assert.equal(currentStep(s), null);
  assert.equal(reduceGuide(initialGuide(), { type: 'next' }).active, false);
  const late = reduceGuide(initialGuide(), { type: 'start', tour, index: 99 });
  assert.equal(late.index, tour.steps.length - 1);
});

test('a found target is shown and reported as started', async () => {
  const h = harness({ path: '/admin/fare-management', present: ['fares.matrix', 'fares.save'] });
  const r = await h.guide.start({ preset: 'current_page' }, { gesture: true });
  assert.deepEqual(r, { status: 'started', total: 2, dropped: [], target: 'fares.matrix' });
  assert.equal(h.guide.state.status, 'shown');
  assert.deepEqual(h.scrolled, ['fares.matrix']);
  assert.deepEqual(h.counts(), { opened: 1, closed: 0 });
  await h.guide.next();
  assert.equal(currentStep(h.guide.state).target, 'fares.save');
  await h.guide.next();
  assert.equal(h.guide.state.active, false, 'Done on the last step closes the tour');
  assert.deepEqual(h.counts(), { opened: 1, closed: 1 });
});

test('a missing target times out after about 4 seconds instead of hanging', async () => {
  const h = harness({ path: '/admin/fare-management' });
  const pending = h.guide.highlight({ target: 'fares.save' });
  await h.tick(3900);
  assert.equal(h.guide.state.status, 'loading');
  await h.tick(200);
  const r = await pending;
  assert.equal(r.status, 'not_found');
  assert.equal(h.timers.length, 0, 'no timer left behind');
  assert.equal(h.guide.state.active, true, 'the card stays so the user can move on or skip');
});

test('a hidden part falls back to its parent after a short wait', async () => {
  const h = harness({ path: '/admin/tickets', present: ['tickets.tabs'] });
  const pending = h.guide.highlight({ target: 'tickets.approve' });
  await h.tick(700);
  const r = await pending;
  assert.equal(r.status, 'shown');
  assert.deepEqual(h.guide.state.target, { id: 'tickets.tabs' }, 'approve -> pending -> tabs');
});

test('exit during a lookup settles the caller and clears timers', async () => {
  const h = harness({ path: '/admin/fare-management' });
  const pending = h.guide.highlight({ target: 'fares.save' });
  await h.tick(300);
  h.guide.exit();
  const r = await pending;
  assert.equal(r.status, 'cancelled');
  assert.equal(h.timers.length, 0);
  assert.deepEqual(h.counts(), { opened: 1, closed: 1 });
  h.guide.exit();
  assert.deepEqual(h.counts(), { opened: 1, closed: 1 }, 'a second exit is a no-op');
});

test('a model tour on another page waits for the user, then moves with its state', async () => {
  const h = harness({ path: '/admin/dashboard' });
  const r = await h.guide.start({ preset: 'verify_discount' });
  assert.equal(r.status, 'elsewhere');
  assert.deepEqual(h.navigations, [], 'the model never changes page by itself');
  await h.guide.go();
  assert.deepEqual(h.navigations, ['/admin/tickets']);
  assert.equal(h.guide.state.status, 'navigating');
  const saved = JSON.parse(h.store.get(STORAGE_KEY));
  assert.equal(saved.index, 0);
  assert.equal(saved.role, 'admin');
  assert.ok(saved.steps.every(s => Object.keys(s).join() === 'target,title,body'), 'no elements or selectors cross the page');
  assert.equal(await h.guide.next().then(s => s.status), 'navigating', 'Next is ignored while the page changes');
});

test('Next onto another page persists the tour and the next page resumes it', async () => {
  const first = harness({ path: '/admin/fare-management', present: ['shell.nav'] });
  await first.guide.start({ preset: 'getting_started' }, { gesture: true });
  assert.equal(currentStep(first.guide.state).target, 'shell.nav');
  await first.guide.next();
  assert.deepEqual(first.navigations, ['/admin/dashboard']);

  const second = harness({ path: '/admin/dashboard', present: ['dash.attention', 'shell.nav'] });
  second.store.set(STORAGE_KEY, first.store.get(STORAGE_KEY));
  const r = await second.guide.resume();
  assert.equal(r.status, 'started');
  assert.equal(second.guide.state.index, 1);
  assert.equal(currentStep(second.guide.state).target, 'dash.attention');
  assert.equal(second.store.has(STORAGE_KEY), false, 'saved state is used once');
  await second.guide.back();
  assert.equal(second.guide.state.status, 'shown', 'shell.nav has no page, so Back stays here');
});

test('resume refuses other accounts, stale, tampered or misplaced state', async () => {
  const tour = validateGuide(admin, { preset: 'verify_discount' });
  const state = { ...reduceGuide(initialGuide(), { type: 'start', tour }), index: 2 };
  const meta = { identity: 'App\\Models\\User:1', role: 'admin', now: 5_000 };
  const text = serializeGuide(state, meta);
  assert.equal(restoreGuide(text, admin, meta).index, 2);
  assert.equal(restoreGuide(text, admin, { ...meta, identity: 'App\\Models\\User:2' }), null);
  assert.equal(restoreGuide(text, admin, { ...meta, role: 'staff' }), null);
  assert.equal(restoreGuide(text, admin, { ...meta, now: 5_000 + RESUME_MAX_AGE_MS + 1 }), null);
  assert.equal(restoreGuide(text, admin, { ...meta, now: 4_000 }), null, 'a clock that went backwards');
  assert.equal(restoreGuide(text, staff, meta), null, 'targets must exist in this registry');
  assert.equal(restoreGuide('{not json', admin, meta), null);
  const tampered = JSON.parse(text); tampered.steps[0].target = 'evil.part';
  assert.equal(restoreGuide(JSON.stringify(tampered), admin, meta), null);
  const outOfRange = JSON.parse(text); outOfRange.index = 9;
  assert.equal(restoreGuide(JSON.stringify(outOfRange), admin, meta), null);

  const h = harness({ path: '/admin/dashboard', present: ['tickets.pending'] });
  h.store.set(STORAGE_KEY, serializeGuide(state, { ...meta, now: h.env.now() }));
  assert.equal(await h.guide.resume(), null, 'the saved step is on the tickets page, not this one');
  assert.equal(h.guide.state.active, false);
});

test('blocked storage ends the tour quietly instead of throwing', async () => {
  const blocked = { get() { throw new Error('blocked'); }, set() { throw new Error('blocked'); }, remove() { throw new Error('blocked'); } };
  const h = harness({ path: '/admin/fare-management', present: ['shell.nav'], storage: blocked });
  assert.equal(await h.guide.resume(), null);
  await h.guide.start({ preset: 'getting_started' }, { gesture: true });
  await h.guide.next();
  assert.deepEqual(h.navigations, ['/admin/dashboard']);
});

test('begin refuses an empty tour and a new tour replaces the old one', async () => {
  const h = harness({ path: '/admin/user-management/roles-permissions', present: ['fares.save', 'shell.menu'] });
  await assert.rejects(h.guide.start({ preset: 'current_page' }), /no guided walkthrough/);
  assert.equal(h.guide.state.active, false);
  h.env.here = '/admin/fare-management';
  await h.guide.highlight({ target: 'fares.save' });
  await h.guide.highlight({ target: 'shell.menu' });
  assert.equal(currentStep(h.guide.state).target, 'shell.menu');
  assert.equal(h.guide.state.single, true);
  assert.deepEqual(h.counts(), { opened: 1, closed: 0 }, 'replacing an active tour keeps the session open');
});

test('placement keeps the card inside a 360px phone and off the target', () => {
  const viewport = { width: 360, height: 640 }, size = { width: 320, height: 210 };
  const inside = ({ card }) => card.x >= 12 && card.y >= 12 && card.x + card.width <= viewport.width - 12 + 0.01 && card.y + size.height <= viewport.height - 12 + 0.01;
  const centre = placeGuide(null, viewport, size);
  assert.ok(inside(centre)); assert.equal(centre.arrow, null);
  assert.equal(Math.round(centre.card.x), 20);
  for (const rect of [
    { x: 8, y: 8, width: 60, height: 40 }, { x: 290, y: 590, width: 60, height: 40 }, { x: 0, y: 300, width: 360, height: 48 },
    { x: 100, y: 20, width: 160, height: 560 }, { x: 300, y: 10, width: 50, height: 50 },
  ]) {
    const p = placeGuide(rect, viewport, size);
    assert.ok(inside(p), JSON.stringify(rect));
    assert.equal(p.card.width, 320);
    const cx = rect.x + rect.width / 2, cy = rect.y + rect.height / 2;
    assert.ok(!(cx >= p.card.x && cx <= p.card.x + p.card.width && cy >= p.card.y && cy <= p.card.y + size.height), `card covers ${JSON.stringify(rect)}`);
    assert.ok(p.arrow);
  }
  const low = placeGuide({ x: 20, y: 560, width: 100, height: 40 }, viewport, size);
  assert.ok(low.card.y + size.height <= 560, 'flips above a target near the bottom');
  const wide = placeGuide({ x: 40, y: 300, width: 60, height: 40 }, { width: 1280, height: 720 }, size);
  assert.ok(wide.card.x > 100, 'goes to the side with room on a desktop');
});

test('the overlay module is import safe under node', async () => {
  const mod = await import('../../public/js/assistant/guide-overlay.js?v=20261110-live');
  assert.equal(typeof mod.mountConsoleGuide, 'function');
  assert.equal(typeof globalThis.document, 'undefined');
  assert.deepEqual(mod.readRegistry({ getElementById: () => ({ textContent: '{bad' }) }), {});
});

test('overlay captures focus before opening dialog, restores panel and handles mobile sidebar fallback', async () => {
  const { mountConsoleGuide, isVisibleElement } = await import('../../public/js/assistant/guide-overlay.js?v=20261110-live');
  let mobile = false, sidebarOpen = false;
  const listeners = new Map(), docListeners = new Map(), frames = [];
  const doc = {
    activeElement: null,
    body: {
      classList: {
        contains: c => c === 'sidebar-open' && sidebarOpen,
        remove: c => { if (c === 'sidebar-open') sidebarOpen = false; },
      },
      append(el) { el.isConnected = true; },
    },
    documentElement: {},
    createElement(tag) {
      const attrs = new Map(), children = [];
      const el = {
        tag, className: '', id: '', textContent: '', hidden: false, disabled: false, open: false, tabIndex: 0, offsetHeight: 180,
        dataset: {}, style: {}, isConnected: true, ownerDocument: doc,
        classList: { add() {}, remove() {}, contains: () => false },
        setAttribute: (k, v) => attrs.set(k, v),
        getAttribute: k => attrs.get(k) ?? null,
        append(...items) { for (const c of items) { c.parent = el; children.push(c); } },
        contains(other) { for (let p = other; p; p = p.parent) if (p === el) return true; return false; },
        matches: sel => sel === 'button' && tag === 'button',
        querySelector: sel => (sel === 'svg' ? children[0] : null),
        showModal() { el.open = true; },
        close() { el.open = false; },
        focus() { doc.activeElement = el; },
        addEventListener() {},
      };
      return el;
    },
    createElementNS(_, tag) { return doc.createElement(tag); },
    addEventListener(type, fn) { docListeners.set(type, fn); },
    removeEventListener(type, fn) { if (docListeners.get(type) === fn) docListeners.delete(type); },
    getElementById: id => elements.get(id) || null,
    querySelector: sel => (sel === '[data-sp-guide="target-0"]' ? legacyTarget : null),
    querySelectorAll(sel) {
      if (sel === '[data-sp-guide="target-0"]') return [legacyTarget];
      const m = /^\[data-guide="(.+)"\]$/.exec(sel);
      return m && anchors.has(m[1]) ? [anchors.get(m[1])] : [];
    },
  };
  const makeNode = (id, { inSidebar = false, rect = { left: 20, top: 20, right: 120, bottom: 60, width: 100, height: 40 } } = {}) => ({
    id, hidden: false, isConnected: true, ownerDocument: doc,
    getBoundingClientRect: () => (typeof rect === 'function' ? rect() : rect),
    closest: sel => (sel === '.sidebar' && inSidebar ? {} : null),
    scrollIntoView() {},
    setAttribute() {},
    focus() { doc.activeElement = this; },
  });
  const sidebar = makeNode('sidebar', { inSidebar: true, rect: () => ({ left: 0, top: 0, right: 240, bottom: 600, width: 240, height: 600 }) });
  const menuBtn = makeNode('sidebarToggle');
  const tourBtn = makeNode('tourBtn', { inSidebar: true });
  const panelInput = makeNode('sp-assistant-input');
  const panelBtn = makeNode('panelBtn');
  const launcher = makeNode('sp-assistant-launch');
  const legacyTarget = makeNode('target-0');
  const panel = { hidden: false, querySelector: sel => (sel === '#sp-assistant-input' ? panelInput : null) };
  const elements = new Map([['sidebarToggle', menuBtn]]);
  const anchors = new Map([['shell.nav', sidebar], ['shell.menu', menuBtn], ['fares.save', makeNode('fares.save')]]);
  const timers = [];
  const win = {
    innerWidth: 1280, innerHeight: 720,
    location: { pathname: '/admin/fare-management', assign() {} },
    matchMedia: q => ({ matches: q === '(max-width: 900px)' && mobile }),
    getComputedStyle: () => ({ visibility: 'visible', display: 'block' }),
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    setTimeout: (fn, ms) => { const id = timers.length + 1; timers.push({ id, fn, ms }); return id; },
    clearTimeout: id => { const i = timers.findIndex(t => t.id === id); if (i >= 0) timers.splice(i, 1); },
    requestAnimationFrame: fn => { frames.push(fn); return frames.length; },
    cancelAnimationFrame: () => { frames.length = 0; },
    addEventListener(type, fn) { listeners.set(type, fn); },
    removeEventListener(type, fn) { if (listeners.get(type) === fn) listeners.delete(type); },
  };

  const overlay = mountConsoleGuide({ doc, win, registryData: slice('admin'), identity: 'App\\Models\\User:1', panel, launcher });

  // 1. Focus captured before render() moves focus to dialog card, and restored along with panel on exit.
  doc.activeElement = panelBtn;
  await overlay.highlight({ target: 'fares.save', message: 'Save here — now.' });
  assert.equal(panel.hidden, true, 'assistant panel hides during the tour');
  assert.notEqual(doc.activeElement, panelBtn);
  overlay.exit();
  assert.equal(panel.hidden, false, 'assistant panel reopens after the tour');
  assert.equal(doc.activeElement, panelBtn, 'focus returns to the trigger button, not the closed dialog card');

  // 2. When activeElement was doc.body (e.g. disabled Send button), closing a panel-started tour focuses #sp-assistant-input.
  doc.activeElement = doc.body;
  await overlay.highlight({ target: 'target-0', message: 'Legacy — dash', label: 'Save — button' });
  assert.equal(overlay.guide.state.steps[0].body, 'Legacy, dash');
  assert.equal(overlay.guide.state.steps[0].title, 'Save, button');
  overlay.exit();
  assert.equal(doc.activeElement, panelInput);

  // 3. On a <=900px screen, closing the mobile drawer hides .sidebar immediately (even mid-transition) and falls back to shell.menu.
  panel.hidden = true;
  mobile = false; win.innerWidth = 1280; sidebarOpen = false;
  doc.activeElement = tourBtn;
  await overlay.highlight({ target: 'shell.nav' });
  assert.equal(overlay.guide.state.target, sidebar);
  mobile = true; win.innerWidth = 360; sidebarOpen = true;
  assert.equal(isVisibleElement(sidebar, win), true);
  doc.body.classList.remove('sidebar-open');
  assert.equal(isVisibleElement(sidebar, win), false, 'off-canvas sidebar is not visible once sidebar-open is removed');
  frames.shift()?.();
  assert.equal(overlay.guide.state.target, menuBtn, 'position() refreshes target to shell.menu when sidebar becomes hidden on mobile');
  overlay.exit();
  assert.equal(doc.activeElement, menuBtn, 'restores focus to #sidebarToggle when sidebar trigger is hidden');
});

test('registry copy follows the UI copy rule', () => {
  const dashes = /[–—·]/;
  for (const [id, a] of Object.entries(data.anchors)) assert.ok(!dashes.test(a.label + a.description), id);
  for (const tours of Object.values(data.tours)) for (const t of Object.values(tours)) for (const s of t.steps) assert.ok(!dashes.test(t.title + s.body), s.target);
});
