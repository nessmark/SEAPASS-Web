// Console guide overlay: dimmed cut-out, ring, arrow and a step card in a native modal dialog.
// Import-safe under node. Nothing touches the DOM until mountConsoleGuide() is called.
// The overlay never clicks, types or submits on the page; the modal layer blocks page input.
import { clean, createRegistry, createGuide, currentStep, currentPageTour, validateGuide, placeGuide } from './guide-engine.js?v=20261109-parity';

const SVG = 'http://www.w3.org/2000/svg';

export function isVisibleElement(el, win) {
  if (!el || !el.isConnected) return false;
  if (win.matchMedia?.('(max-width: 900px)').matches && el.closest?.('.sidebar') && !el.ownerDocument?.body?.classList?.contains('sidebar-open')) return false;
  const r = el.getBoundingClientRect();
  if (!(r.width > 0 && r.height > 0) || r.right <= 0 || r.left >= win.innerWidth) return false;
  const style = win.getComputedStyle(el);
  return style.visibility !== 'hidden' && style.display !== 'none' && !el.closest('[inert],[hidden]');
}

export function mountConsoleGuide({ doc = document, win = window, registryData = {}, identity = '', panel = null, launcher = null, say = () => {}, legacyPageSteps = () => [] } = {}) {
  const registry = createRegistry(registryData);
  const reduceMotion = () => !!win.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  const esc = value => (win.CSS?.escape ? win.CSS.escape(value) : String(value).replace(/["\\]/g, '\\$&'));
  const firstVisible = selector => [...doc.querySelectorAll(selector)].find(el => isVisibleElement(el, win)) || null;
  const storage = {
    get: key => win.sessionStorage.getItem(key),
    set: (key, value) => win.sessionStorage.setItem(key, value),
    remove: key => win.sessionStorage.removeItem(key),
  };
  const env = {
    identity,
    path: () => win.location.pathname,
    navigate: path => win.location.assign(path),
    find: step => (step.selector ? firstVisible(step.selector) : step.target ? firstVisible(`[data-guide="${esc(step.target)}"]`) : null),
    storage,
    now: () => Date.now(),
    setTimeout: (fn, ms) => win.setTimeout(fn, ms),
    clearTimeout: id => win.clearTimeout(id),
    scrollIntoView: el => el.scrollIntoView({ behavior: reduceMotion() ? 'auto' : 'smooth', block: 'center', inline: 'nearest' }),
  };

  let restorePanel = false, guiding = false, previousFocus = null;
  const guide = createGuide({
    registry, env,
    onOpen() {
      if (guiding) return;
      guiding = true;
      previousFocus = doc.activeElement;
      restorePanel = !!panel && !panel.hidden;
      // Hide, do not close: a live voice session keeps running during the tour.
      if (restorePanel) { panel.hidden = true; launcher?.setAttribute('aria-expanded', 'false'); }
    },
    onClose() {
      guiding = false;
      const wasPanel = restorePanel;
      if (restorePanel) { panel.hidden = false; launcher?.setAttribute('aria-expanded', 'true'); }
      restorePanel = false;
      const back = previousFocus; previousFocus = null;
      if (back && back !== doc.body && back !== doc.documentElement && back.isConnected && isVisibleElement(back, win)) back.focus();
      else if (wasPanel) (panel?.querySelector?.('#sp-assistant-input') || launcher)?.focus?.();
      else {
        const menu = doc.getElementById?.('sidebarToggle');
        (menu && isVisibleElement(menu, win) ? menu : launcher)?.focus?.();
      }
    },
  });

  // Overlay DOM, built on first use.
  let layer, card, ring, hole, dim, arrow, halo, progress, title, body, backBtn, primaryBtn, skipBtn, frame = null, last = {}, focusKey = '';
  function build() {
    if (layer) return;
    layer = doc.createElement('dialog');
    layer.className = 'sp-tour';
    layer.setAttribute('aria-labelledby', 'sp-tour-title');
    layer.setAttribute('aria-describedby', 'sp-tour-body');
    const svg = doc.createElementNS(SVG, 'svg');
    svg.setAttribute('class', 'sp-tour-shade'); svg.setAttribute('aria-hidden', 'true');
    const defs = doc.createElementNS(SVG, 'defs');
    const mask = doc.createElementNS(SVG, 'mask'); mask.setAttribute('id', 'sp-tour-hole');
    const full = doc.createElementNS(SVG, 'rect'); full.setAttribute('width', '100%'); full.setAttribute('height', '100%'); full.setAttribute('fill', 'white');
    hole = doc.createElementNS(SVG, 'rect'); hole.setAttribute('rx', '12'); hole.setAttribute('fill', 'black');
    mask.append(full, hole);
    const marker = doc.createElementNS(SVG, 'marker');
    for (const [k, v] of Object.entries({ id: 'sp-tour-head', markerWidth: '8', markerHeight: '8', refX: '7', refY: '4', orient: 'auto' })) marker.setAttribute(k, v);
    const head = doc.createElementNS(SVG, 'path'); head.setAttribute('d', 'M0.5,0.5 L7.5,4 L0.5,7.5 Z'); head.setAttribute('class', 'sp-tour-head');
    marker.append(head); defs.append(mask, marker);
    dim = doc.createElementNS(SVG, 'rect'); dim.setAttribute('class', 'sp-tour-dim'); dim.setAttribute('width', '100%'); dim.setAttribute('height', '100%'); dim.setAttribute('mask', 'url(#sp-tour-hole)');
    halo = doc.createElementNS(SVG, 'path'); halo.setAttribute('class', 'sp-tour-arrow-halo');
    arrow = doc.createElementNS(SVG, 'path'); arrow.setAttribute('class', 'sp-tour-arrow'); arrow.setAttribute('marker-end', 'url(#sp-tour-head)');
    svg.append(defs, dim, halo, arrow);
    ring = doc.createElement('div'); ring.className = 'sp-tour-ring'; ring.hidden = true;
    card = doc.createElement('section'); card.className = 'sp-tour-card'; card.tabIndex = -1;
    progress = doc.createElement('p'); progress.className = 'sp-tour-progress';
    title = doc.createElement('h2'); title.id = 'sp-tour-title';
    body = doc.createElement('p'); body.className = 'sp-tour-body'; body.id = 'sp-tour-body'; body.setAttribute('aria-live', 'polite');
    const actions = doc.createElement('div'); actions.className = 'sp-tour-actions';
    const button = (action, text) => { const b = doc.createElement('button'); b.type = 'button'; b.dataset.tourAction = action; b.textContent = text; return b; };
    backBtn = button('back', 'Back'); primaryBtn = button('primary', 'Next'); primaryBtn.classList.add('sp-tour-primary'); skipBtn = button('skip', 'Skip tour');
    actions.append(backBtn, primaryBtn, skipBtn);
    card.append(progress, title, body, actions);
    layer.append(svg, ring, card);
    layer.addEventListener('click', event => {
      const action = event.target.closest?.('[data-tour-action]')?.dataset.tourAction;
      if (action === 'back') guide.back();
      if (action === 'primary') primary();
      if (action === 'skip') guide.skip();
    });
    layer.addEventListener('cancel', event => { event.preventDefault(); guide.exit(); });
    doc.body.append(layer);
  }

  function primary() {
    const s = guide.state;
    if (!s.active || primaryBtn?.disabled) return;
    if (s.status === 'elsewhere') return guide.go();
    if (s.single) return guide.exit();
    return guide.next();
  }

  function buttons() { return [backBtn, primaryBtn, skipBtn].filter(b => b && !b.hidden && !b.disabled); }
  function keyboard(event) {
    if (!guide.state.active) return;
    const key = event.key;
    if (!['Escape', 'ArrowRight', 'ArrowLeft', 'Enter', 'Tab'].includes(key)) return;
    // Enter on a focused card button keeps its native click (Skip stays Skip).
    if (key === 'Enter' && card?.contains(event.target) && event.target.matches?.('button')) return;
    event.preventDefault(); event.stopImmediatePropagation();
    if (key === 'Escape') guide.exit();
    else if (key === 'ArrowRight' || key === 'Enter') primary();
    else if (key === 'ArrowLeft') guide.back();
    else {
      const list = buttons(), i = list.indexOf(doc.activeElement);
      (list[(i + (event.shiftKey ? -1 : 1) + list.length) % list.length] || card)?.focus();
    }
  }
  function trap(event) {
    if (guide.state.active && card && !card.contains(event.target)) (buttons().find(b => b === primaryBtn) || buttons()[0] || card).focus();
  }

  function position() {
    frame = null;
    const s = guide.state;
    if (!s.active || !layer) return;
    const width = win.innerWidth, height = win.innerHeight;
    let el = s.target;
    if (el && !isVisibleElement(el, win)) el = guide.refresh();
    let rect = null;
    if (el && isVisibleElement(el, win)) {
      const r = el.getBoundingClientRect();
      if (r.width && r.height && r.bottom > 0 && r.top < height && r.right > 0 && r.left < width) {
        const x = Math.max(4, r.left - 6), y = Math.max(4, r.top - 6);
        rect = { x, y, width: Math.max(0, Math.min(width - 4, r.right + 6) - x), height: Math.max(0, Math.min(height - 4, r.bottom + 6) - y) };
      }
    }
    const placed = placeGuide(rect, { width, height }, { width: Math.min(320, width - 24), height: card.offsetHeight || 200 });
    const key = JSON.stringify([width, height, rect, placed]);
    if (key !== last.key) {
      last.key = key;
      layer.querySelector('svg').setAttribute('viewBox', `0 0 ${width} ${height}`);
      if (rect) {
        for (const [k, v] of Object.entries(rect)) hole.setAttribute(k, String(v));
        Object.assign(ring.style, { left: rect.x + 'px', top: rect.y + 'px', width: rect.width + 'px', height: rect.height + 'px' });
        ring.hidden = false;
      } else {
        for (const k of ['x', 'y', 'width', 'height']) hole.setAttribute(k, '0');
        ring.hidden = true;
      }
      const d = placed.arrow ? `M ${placed.arrow.x} ${placed.arrow.y} L ${placed.arrow.tx} ${placed.arrow.ty}` : '';
      arrow.setAttribute('d', d); halo.setAttribute('d', d);
      arrow.style.display = halo.style.display = d ? '' : 'none';
      card.style.left = placed.card.x + 'px'; card.style.top = placed.card.y + 'px';
    }
    frame = win.requestAnimationFrame(position);
  }

  function render(s) {
    if (!s.active) {
      if (frame !== null) win.cancelAnimationFrame(frame);
      frame = null; last = {}; focusKey = '';
      win.removeEventListener('keydown', keyboard, true);
      doc.removeEventListener('focusin', trap, true);
      if (layer?.open) layer.close();
      return;
    }
    build();
    const step = currentStep(s);
    const page = step?.target ? registry.anchor(step.target)?.page : null;
    const pageLabel = page ? registry.pageLabel(page) : 'other';
    const isLast = s.index >= s.steps.length - 1;
    progress.textContent = s.single ? 'Quick guide' : `Step ${s.index + 1} of ${s.steps.length}`;
    title.textContent = s.status === 'not_found' ? "I can't find that on this screen" : step.title;
    body.textContent = {
      loading: 'Finding this part…',
      navigating: `Opening ${pageLabel}…`,
      not_found: 'It may need a record first, such as a booking or a trip. Move on or skip the tour.',
      elsewhere: `This is on the ${pageLabel} page. Open it to continue.`,
    }[s.status] || step.body;
    card.setAttribute('aria-busy', String(s.status === 'loading' || s.status === 'navigating'));
    backBtn.hidden = s.single;
    backBtn.disabled = s.index === 0 || s.status === 'loading' || s.status === 'navigating';
    primaryBtn.disabled = s.status === 'loading' || s.status === 'navigating';
    primaryBtn.textContent = s.status === 'loading' ? 'Finding…' : s.status === 'navigating' ? 'Opening…' : s.status === 'elsewhere' ? 'Open page' : s.single ? 'Got it' : isLast ? 'Done' : 'Next';
    skipBtn.textContent = s.single ? 'Skip' : 'Skip tour';
    if (!layer.open) {
      try { layer.showModal(); } catch { layer.setAttribute('open', ''); }
      win.addEventListener('keydown', keyboard, true);
      doc.addEventListener('focusin', trap, true);
    }
    const key = `${s.index}:${s.status}`;
    if (key !== focusKey) { focusKey = key; (primaryBtn.disabled ? card : primaryBtn).focus({ preventScroll: true }); }
    if (frame === null) position();
  }
  guide.subscribe(render);

  // Entry points that work without the AI connection.
  doc.addEventListener('click', event => {
    const trigger = event.target.closest?.('[data-sp-tour]');
    if (!trigger || layer?.contains(trigger)) return;
    event.preventDefault();
    if (doc.body.classList.contains('sidebar-open')) {
      doc.body.classList.remove('sidebar-open');
      doc.getElementById('sidebarToggle')?.setAttribute('aria-expanded', 'false');
    }
    api.startPreset(trigger.dataset.spTour || 'getting_started', { gesture: true }).catch(e => say(e.message));
  });

  const api = {
    guide, registry,
    hasGuides: () => registry.hasGuides(),
    hasPreset: name => !!registry.tours[name],
    startPreset(preset, { gesture = false } = {}) {
      if (preset === 'current_page') return api.currentPage({ gesture });
      return guide.start({ preset }, { gesture });
    },
    // Registry anchors on this page first; pages without anchors keep the older selector guide.
    currentPage({ gesture = false } = {}) {
      let tour = currentPageTour(registry, win.location.pathname);
      if (!tour.steps.length) tour = { ...tour, steps: legacyPageSteps() };
      if (!tour.steps.length) return Promise.reject(new Error('No guide targets are visible on this page.'));
      return guide.begin(tour, { gesture });
    },
    // A tour the server already validated. It is validated again against this page's registry.
    showTour(tour, { gesture = false } = {}) {
      if (!tour || tour.preset === 'current_page') return api.currentPage({ gesture });
      const checked = tour.single
        ? validateGuide(registry, { target: tour.steps?.[0]?.target, message: tour.steps?.[0]?.body }, { single: true })
        : tour.preset ? validateGuide(registry, { preset: tour.preset }) : validateGuide(registry, { title: tour.title, steps: tour.steps });
      return guide.begin(checked, { gesture });
    },
    highlight({ target, message, label } = {}, { gesture = false } = {}) {
      if (registry.isAnchor(target)) return guide.highlight({ target, message }, { gesture });
      // Older auto-generated page targets (data-sp-guide) from the page context.
      const selector = `[data-sp-guide="${esc(target)}"]`;
      if (!doc.querySelector(selector)) return Promise.reject(new Error('That guide target is not on this page.'));
      const titleText = clean(label, 100) || 'Here';
      const bodyText = clean(message, 600) || 'This is the part you asked about.';
      return guide.begin({ title: titleText, single: true, preset: null, dropped: [], steps: [{ selector, title: titleText, body: bodyText }] }, { gesture });
    },
    exit: () => guide.exit(),
    resume: () => guide.resume().catch(() => null),
  };
  return api;
}

export function readRegistry(doc = document) {
  try { return JSON.parse(doc.getElementById('sp-guide-registry')?.textContent || '{}') || {}; } catch { return {}; }
}
