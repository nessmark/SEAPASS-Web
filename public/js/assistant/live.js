// Live voice connection for the SeaPass assistant: a small state machine
// around the Gemini Live WebSocket, so nothing the network does (a drop, a
// goAway, a slow handshake, three failures in a row, going offline) can leave
// the panel stuck. Ported from DIELCO's liveClient.js and adapted to SeaPass:
// the ephemeral token comes from POST /assistant/session (injected here as
// `mintSession`), typed chat always goes through /assistant/chat, and Live
// carries voice only.
//
// States: idle -> connecting -> ready -> reconnecting -> fallback | offline |
// unavailable | closed. "fallback" is Text mode: Live is resting, typed chat
// keeps working, and a background probe returns to Live at a turn boundary
// once it is healthy again.
//
// This file has no DOM access at import time and every browser dependency
// (WebSocket, timers, clock, online signal) is injectable, so the whole state
// machine runs under plain `node --test` (tests/js/assistant-live.test.mjs).

export const LIVE_STATES = Object.freeze(['idle', 'connecting', 'ready', 'reconnecting', 'fallback', 'offline', 'unavailable', 'closed']);

export const LIVE_DEFAULTS = Object.freeze({
  setupTimeoutMs: 12000,
  turnWatchdogMs: 30000,
  reconnectBaseMs: 500,
  reconnectMaxMs: 15000,
  failureThreshold: 3,
  failureWindowMs: 60000,
  probeMs: 60000,
  busyProbeMs: 10000,
  quotaProbeMs: 120000,
  quotaProbeMaxMs: 900000,
  maxQueuedAudio: 64,
  voiceName: 'Kore',
  audioMimeType: 'audio/pcm;rate=16000'
});

// UI copy: plain short English, no dashes, no developer detail.
export const LIVE_COPY = Object.freeze({
  connecting: 'Connecting live voice…',
  listening: 'Listening. Tap Stop voice when finished.',
  reconnecting: 'Connection lost. Reconnecting…',
  refreshing: 'Refreshing the connection…',
  fallback: 'Voice is having trouble, so answers come as text for now.',
  quota: 'Voice is busy right now, so answers come as text for now.',
  back: 'Voice is back. Listening.',
  offline: 'You are offline.',
  offlineTurn: 'You went offline before the reply finished.',
  signIn: 'Sign in again to use the assistant.',
  unavailable: 'Live voice is unavailable right now. You can still type.',
  tooLong: 'That took too long. Try again.',
  dropped: 'The connection dropped before the reply finished.',
  network: 'The assistant could not connect. Check your connection and try again.',
  generic: 'The assistant could not connect. Try again.',
  busy: 'Too many requests. Wait a moment and try again.'
});

const OPEN = 1;
const QUEUE_STATES = new Set(['connecting', 'reconnecting', 'ready']);

// ---------------------------------------------------------------------------
// Error text. Only short, plain sentences reach the user; anything that looks
// like a stack trace, SQL, a path, a URL or a credential becomes the fallback.
// ---------------------------------------------------------------------------
const TECHNICAL = /(sqlstate|exception|stack ?trace|traceback|\bat [\w$.<>]+ ?\(|\/home\/|\/var\/|\/usr\/|\\app\\|vendor\/|\.php\b|https?:\/\/|wss?:\/\/|api[_ -]?key|aiza[0-9a-z_-]{8,}|bearer\s|access_token|token=|<\/?[a-z][^>]*>|[{}[\]]|undefined|\bnull\b|syntax error|cannot read propert)/i;

export function cleanMessage(text, max = 200) {
  if (typeof text !== 'string') return '';
  const line = text.replace(/\s+/g, ' ').trim();
  if (!line || TECHNICAL.test(line)) return '';
  return line.length > max ? line.slice(0, max - 1).trimEnd() + '…' : line;
}

export function friendlyError(error, fallback = LIVE_COPY.generic) {
  const status = Number(error?.status) || 0;
  if (status === 401 || status === 419) return LIVE_COPY.signIn;
  if (status === 429) return LIVE_COPY.busy;
  if (status >= 500 && status !== 503) return fallback;
  const message = typeof error === 'string' ? error : error?.message;
  if (!status && (error?.name === 'TypeError' || /failed to fetch|networkerror|load failed|network request failed/i.test(String(message || '')))) {
    return globalThis.navigator?.onLine === false ? LIVE_COPY.offline : LIVE_COPY.network;
  }
  return cleanMessage(message) || fallback;
}

// ---------------------------------------------------------------------------
// Helpers shared with the panel.
// ---------------------------------------------------------------------------
export function browserOnline() {
  if (typeof window === 'undefined' || typeof navigator === 'undefined' || typeof window.addEventListener !== 'function') {
    return { isOnline: () => true, onChange: () => () => {} };
  }
  return {
    isOnline: () => navigator.onLine !== false,
    onChange(cb) {
      const up = () => cb(true);
      const down = () => cb(false);
      window.addEventListener('online', up);
      window.addEventListener('offline', down);
      return () => { window.removeEventListener('online', up); window.removeEventListener('offline', down); };
    }
  };
}

// Earlier messages of the open chat as Live Content turns, so a fresh Live
// session (first start, or a reconnect without a resumption handle) picks up
// the same conversation. Sent with turnComplete:false, so the model does not
// answer them; trailing user turns are dropped so the next thing the model
// hears is the user's voice.
export function historyToSeed(messages, { maxTurns = 10, maxChars = 1500 } = {}) {
  const turns = (Array.isArray(messages) ? messages : [])
    .filter(m => m && (m.role === 'user' || m.role === 'assistant') && typeof m.text === 'string' && m.text.trim())
    .slice(-maxTurns)
    .map(m => ({ role: m.role === 'assistant' ? 'model' : 'user', parts: [{ text: m.text.length > maxChars ? m.text.slice(0, maxChars) + ' …' : m.text }] }));
  while (turns.length && turns[turns.length - 1].role === 'user') turns.pop();
  return turns;
}

export function buildLiveSetup(session, { resumeHandle = null, voiceName = LIVE_DEFAULTS.voiceName } = {}) {
  return {
    setup: {
      model: 'models/' + session.model,
      generationConfig: { responseModalities: ['AUDIO'], speechConfig: { voiceConfig: { prebuiltVoiceConfig: { voiceName } } } },
      systemInstruction: { parts: [{ text: String(session.system_instruction || '') }] },
      tools: [{ functionDeclarations: Array.isArray(session.tools) ? session.tools : [] }],
      inputAudioTranscription: {},
      outputAudioTranscription: {},
      sessionResumption: resumeHandle ? { handle: resumeHandle } : {},
      contextWindowCompression: { slidingWindow: {} }
    }
  };
}

export function isValidSession(session) {
  return !!session && typeof session === 'object' &&
    typeof session.token === 'string' && session.token.length > 0 &&
    typeof session.model === 'string' && session.model.length > 0 &&
    typeof session.ws_url === 'string' && /^wss:\/\//.test(session.ws_url);
}

export function liveSocketUrl(session) {
  return session.ws_url + (session.ws_url.includes('?') ? '&' : '?') + 'access_token=' + encodeURIComponent(session.token);
}

// Google closes with a reason like "You exceeded your current quota" when the
// key is over its limit. Retrying in seconds only burns more, so Text mode
// takes over at once and Live is probed again after minutes, not seconds.
export function isQuotaClose(event) {
  return /quota|exceeded|resource.?exhausted|rate.?limit/i.test(String(event?.reason || ''));
}

const frameDecoder = typeof TextDecoder === 'function' ? new TextDecoder() : null;
function frameText(data) {
  if (typeof data === 'string') return data;
  if (frameDecoder && (data instanceof ArrayBuffer || ArrayBuffer.isView(data))) return frameDecoder.decode(data);
  return null;
}
const isObject = value => !!value && typeof value === 'object' && !Array.isArray(value);
const textOf = value => (isObject(value) && typeof value.text === 'string' ? value.text : '');

const defaultTimers = {
  setTimeout: (fn, ms) => globalThis.setTimeout(fn, ms),
  clearTimeout: id => globalThis.clearTimeout(id)
};

// ---------------------------------------------------------------------------
// The connection.
// ---------------------------------------------------------------------------
export function createLiveConnection(options = {}) {
  const cfg = { ...LIVE_DEFAULTS, ...options };
  const mintSession = cfg.mintSession;
  if (typeof mintSession !== 'function') throw new TypeError('createLiveConnection needs a mintSession function.');
  const wsFactory = cfg.wsFactory || (url => new WebSocket(url));
  const online = cfg.online || browserOnline();
  const timers = cfg.timers || defaultTimers;
  const now = cfg.now || (() => Date.now());
  const random = cfg.random || Math.random;
  const canSwitchBack = cfg.canSwitchBack || (() => true);
  const seedTurns = cfg.seedTurns || (() => []);
  const buildSetup = cfg.buildSetup || buildLiveSetup;

  const listeners = new Map();
  function on(event, fn) {
    if (!listeners.has(event)) listeners.set(event, new Set());
    listeners.get(event).add(fn);
    return () => off(event, fn);
  }
  function off(event, fn) { listeners.get(event)?.delete(fn); }
  function emit(event, payload = {}) {
    for (const fn of [...(listeners.get(event) || [])]) {
      try { fn(payload); } catch { /* a listener's own bug must not break the connection */ }
    }
  }

  let state = 'idle';
  let closed = false;
  let epoch = 0;            // bumped by every hard transition; stale async work checks it
  let active = null;        // socket record carrying the conversation
  let pending = null;       // socket record still handshaking
  let resumeHandle = null;
  let failures = [];
  let reconnectTimer = null;
  let probeTimer = null;
  let watchdogTimer = null;
  let quotaDelay = 0;
  let audioQueue = [];
  let turnOpen = false;     // the model owes the user a reply
  let turnSeq = 0;          // counts turns, so a goAway replacement can tell it fell behind
  const tools = new Map();  // key -> { rec, id, name, controller }
  let toolSeq = 0;
  let unsubscribeOnline = null;

  const later = (fn, ms) => timers.setTimeout(fn, ms);
  const cancel = id => { if (id != null) timers.clearTimeout(id); return null; };

  function setState(next, notice) {
    const previous = state;
    if (previous !== next) {
      state = next;
      emit('state', { state: next, previous });
    }
    if (notice) emit('notice', { message: notice, state: next });
  }

  function sendOn(rec, payload) {
    if (!rec || rec.retired || !rec.socket || rec.socket.readyState !== OPEN) return false;
    try { rec.socket.send(JSON.stringify(payload)); return true; } catch { return false; }
  }

  function retire(rec, code = 1000, reason = 'client closing') {
    if (!rec || rec.retired) return;
    rec.retired = true;
    rec.setupTimer = cancel(rec.setupTimer);
    try { rec.socket?.close(code, reason); } catch { /* already closed */ }
  }

  function openTurn() {
    if (!turnOpen) { turnOpen = true; turnSeq += 1; }
  }

  function clearWatchdog() { watchdogTimer = cancel(watchdogTimer); }
  function armWatchdog() {
    clearWatchdog();
    watchdogTimer = later(() => {
      watchdogTimer = null;
      if (!turnOpen || tools.size) return;
      turnOpen = false;
      emit('turnComplete', { ok: false, error: LIVE_COPY.tooLong, timeout: true });
      emit('notice', { message: LIVE_COPY.tooLong, state });
    }, cfg.turnWatchdogMs);
  }

  // Ends whatever turn is in flight as failed. Tool calls still running can
  // no longer be answered on this connection, so their slots are dropped.
  function failTurn(message) {
    const had = turnOpen || tools.size > 0;
    turnOpen = false;
    clearWatchdog();
    tools.clear();
    if (had) emit('turnComplete', { ok: false, error: message });
  }

  // ---- public API ---------------------------------------------------------
  function connect() {
    if (closed || state !== 'idle') return;
    unsubscribeOnline = online.onChange(handleOnline);
    if (!online.isOnline()) { goOffline(); return; }
    setState('connecting', LIVE_COPY.connecting);
    attempt('primary');
  }

  function disconnect() {
    if (closed) return;
    closed = true;
    epoch += 1;
    reconnectTimer = cancel(reconnectTimer);
    probeTimer = cancel(probeTimer);
    clearWatchdog();
    if (unsubscribeOnline) { try { unsubscribeOnline(); } catch { /* noop */ } unsubscribeOnline = null; }
    retire(pending); pending = null;
    retire(active); active = null;
    tools.clear();
    turnOpen = false;
    audioQueue = [];
    setState('closed');
  }

  // Mic audio. Sent straight away when Live is up; held (newest few seconds
  // only) while connecting or reconnecting and sent once ready; dropped in
  // every other state so stale speech never reaches a later session.
  function sendAudio(data) {
    if (closed || typeof data !== 'string' || !data) return false;
    if (sendOn(active, { realtimeInput: { audio: { data, mimeType: cfg.audioMimeType } } })) return true;
    if (!QUEUE_STATES.has(state)) return false;
    audioQueue.push(data);
    if (audioQueue.length > cfg.maxQueuedAudio) audioQueue.splice(0, audioQueue.length - cfg.maxQueuedAudio);
    return true;
  }

  function flushAudio() {
    const queued = audioQueue;
    audioQueue = [];
    for (const data of queued) sendOn(active, { realtimeInput: { audio: { data, mimeType: cfg.audioMimeType } } });
  }

  // The panel answers a toolCall here. The response goes back on the socket
  // that asked for it; a call the model cancelled, or one whose connection
  // is gone, is dropped instead.
  function respondTool(key, response) {
    const slot = tools.get(String(key));
    if (!slot) return false;
    tools.delete(String(key));
    const sent = sendOn(slot.rec, { toolResponse: { functionResponses: [{ id: slot.id, name: slot.name, response: isObject(response) ? response : { result: response ?? null } }] } });
    if (!tools.size && turnOpen && state === 'ready') armWatchdog();
    return sent;
  }

  // ---- connection lifecycle -------------------------------------------------
  // kind: 'primary' (connect / reconnect), 'replacement' (after goAway, opened
  // while the old socket still works), 'probe' (from Text mode, in background).
  async function attempt(kind) {
    const myEpoch = kind === 'replacement' ? epoch : ++epoch;
    if (kind !== 'replacement') { retire(pending); pending = null; }
    let session;
    try {
      session = await mintSession();
    } catch (err) {
      if (myEpoch !== epoch || closed) return;
      mintFailed(kind, err);
      return;
    }
    if (myEpoch !== epoch || closed) return;
    if (kind === 'probe' && state !== 'fallback') return;
    if (kind === 'replacement') {
      if (pending || (state !== 'ready' && state !== 'reconnecting')) return;
      // The old socket already closed and a plain reconnect was booked: this
      // replacement is further along, so it takes that job over.
      reconnectTimer = cancel(reconnectTimer);
    }
    if (!isValidSession(session)) { handshakeFailed({ kind, opened: false, usedHandle: null }); return; }
    openSocket(kind, session);
  }

  function mintFailed(kind, err) {
    const status = Number(err?.status) || 0;
    if (kind === 'probe') { if (state === 'fallback') armProbe(cfg.probeMs); return; }
    // A goAway replacement that cannot start leaves the old socket working;
    // when that one closes, the normal reconnect path takes over.
    if (kind === 'replacement') { if (!active && state === 'reconnecting') recordFailure(null); return; }
    if (status === 401 || status === 419) { toUnavailable(LIVE_COPY.signIn); return; }
    if (status === 403) { toUnavailable(friendlyError(err, LIVE_COPY.unavailable)); return; }
    if (status === 503) { toFallback(friendlyError(err, LIVE_COPY.fallback)); return; }
    // /assistant/* shares one per-minute throttle with typed chat, so quick
    // retries would only lock typing out too. Text mode now, probe in a minute.
    if (status === 429) { toFallback(LIVE_COPY.quota); return; }
    if (!online.isOnline()) { goOffline(); return; }
    recordFailure(kind === 'replacement' ? null : LIVE_COPY.reconnecting);
  }

  function openSocket(kind, session) {
    const usedHandle = kind === 'probe' ? null : resumeHandle;
    const rec = { kind, session, socket: null, opened: false, settled: false, retired: false, usedHandle, setupTimer: null, goingAway: false, replaceAfterTurn: false, chain: Promise.resolve(), waiting: 0 };
    pending = rec;
    try {
      rec.socket = wsFactory(liveSocketUrl(session));
    } catch {
      pending = null;
      handshakeFailed(rec);
      return;
    }
    // Live sends JSON as binary frames. As an ArrayBuffer they decode
    // synchronously, so messages are handled in the order they arrived.
    try { rec.socket.binaryType = 'arraybuffer'; } catch { /* a test double may not have it */ }

    rec.setupTimer = later(() => {
      rec.setupTimer = null;
      if (rec.settled || rec.retired) return;
      retire(rec);
      if (pending === rec) pending = null;
      handshakeFailed(rec);
    }, cfg.setupTimeoutMs);

    rec.socket.addEventListener('open', () => {
      if (rec.retired) return;
      rec.opened = true;
      rec.turnSeq = turnSeq;
      sendOn(rec, buildSetup(session, { resumeHandle: usedHandle, voiceName: cfg.voiceName }));
    });
    rec.socket.addEventListener('message', event => onFrame(rec, event?.data));
    rec.socket.addEventListener('close', event => onClose(rec, event));
    rec.socket.addEventListener('error', () => { /* the close event that follows carries the recovery */ });
  }

  function handshakeFailed(rec) {
    if (closed) return;
    // The server was reached but refused to set up: a stale resumption
    // handle must not be presented again, the next session is seeded instead.
    if (rec.opened && rec.usedHandle && rec.usedHandle === resumeHandle) resumeHandle = null;
    if (rec.kind === 'probe') { if (state === 'fallback') armProbe(cfg.probeMs); return; }
    if (!online.isOnline()) { goOffline(); return; }
    if (rec.kind === 'replacement') { if (!active) recordFailure(null); return; }
    recordFailure(LIVE_COPY.reconnecting);
  }

  function settle(rec) {
    if (rec.settled || rec.retired) return;
    rec.settled = true;
    rec.setupTimer = cancel(rec.setupTimer);
    if (pending === rec) pending = null;
    if (closed) { retire(rec); return; }
    if (rec.kind === 'probe' && (state !== 'fallback' || !canSwitchBack())) {
      retire(rec);
      if (state === 'fallback') armProbe(cfg.busyProbeMs);
      return;
    }
    // A goAway replacement resumed from the handle it had when its setup went
    // out. If the user spoke on the old socket since then, that session is a
    // turn behind: drop it and open a fresh one from the newer handle, at the
    // next turn boundary (or now, if that turn already finished).
    if (rec.kind === 'replacement' && (turnOpen || tools.size > 0 || rec.turnSeq !== turnSeq)) {
      retire(rec, 1000, 'stale');
      if (active) {
        if (turnOpen || tools.size > 0) active.replaceAfterTurn = true;
        else startReplacement();
      } else {
        attempt('primary');
      }
      return;
    }
    adopt(rec);
  }

  function adopt(rec) {
    const previous = state;
    const old = active;
    active = rec;
    if (old && old !== rec) retire(old, 1000, 'replaced');
    reconnectTimer = cancel(reconnectTimer);
    probeTimer = cancel(probeTimer);
    quotaDelay = 0;
    if (!rec.usedHandle) {
      let turns = [];
      try { turns = seedTurns() || []; } catch { turns = []; }
      if (Array.isArray(turns) && turns.length) sendOn(rec, { clientContent: { turns, turnComplete: false } });
    }
    setState('ready');
    emit('ready', { resumed: !!rec.usedHandle, recovered: previous === 'fallback', replaced: rec.kind === 'replacement' });
    flushAudio();
  }

  function onClose(rec, event) {
    rec.setupTimer = cancel(rec.setupTimer);
    if (rec.retired || closed) return;
    rec.retired = true;
    if (!rec.settled) {
      if (pending === rec) pending = null;
      if (isQuotaClose(event)) { quotaHit(rec.kind); return; }
      handshakeFailed(rec);
      return;
    }
    if (rec !== active) return;
    active = null;
    // Partial reply text from the dropped socket must not bleed into the
    // next turn, and the panel's in-flight turn has to end now.
    const midTurn = turnOpen || tools.size > 0;
    if (isQuotaClose(event)) { quotaHit('primary'); return; }
    if (midTurn) failTurn(LIVE_COPY.dropped);
    if (!online.isOnline()) { goOffline(); return; }
    if (rec.goingAway && pending && pending.kind === 'replacement') {
      // The announced close beat the replacement: audio waits in the queue.
      setState('reconnecting');
      return;
    }
    recordFailure(rec.goingAway ? null : LIVE_COPY.reconnecting);
  }

  function recordFailure(notice) {
    const t = now();
    failures = failures.filter(ts => t - ts < cfg.failureWindowMs);
    failures.push(t);
    if (failures.length >= cfg.failureThreshold) { toFallback(LIVE_COPY.fallback); return; }
    scheduleReconnect(notice);
  }

  function reconnectDelay() {
    const n = Math.max(1, failures.length);
    const base = Math.min(cfg.reconnectMaxMs, cfg.reconnectBaseMs * 2 ** (n - 1));
    return Math.min(cfg.reconnectMaxMs, base + Math.floor(random() * Math.min(300, base)));
  }

  function scheduleReconnect(notice) {
    setState('reconnecting', notice || undefined);
    reconnectTimer = cancel(reconnectTimer);
    reconnectTimer = later(() => {
      reconnectTimer = null;
      if (closed || state !== 'reconnecting') return;
      if (!online.isOnline()) { goOffline(); return; }
      attempt('primary');
    }, reconnectDelay());
  }

  // ---- Text mode ------------------------------------------------------------
  function toFallback(reason, probeDelay = cfg.probeMs) {
    epoch += 1;
    reconnectTimer = cancel(reconnectTimer);
    retire(pending); pending = null;
    const old = active; active = null;
    retire(old, 1000, 'switching to text');
    failTurn(LIVE_COPY.dropped);
    audioQueue = [];
    // Typed turns happen in Text mode, so the next Live session starts fresh
    // and is seeded with the whole chat instead of resuming a stale one.
    resumeHandle = null;
    setState('fallback', reason || LIVE_COPY.fallback);
    armProbe(probeDelay);
  }

  function armProbe(delay) {
    probeTimer = cancel(probeTimer);
    probeTimer = later(() => {
      probeTimer = null;
      if (closed || state !== 'fallback') return;
      if (!online.isOnline() || !canSwitchBack()) { armProbe(cfg.busyProbeMs); return; }
      attempt('probe');
    }, delay);
  }

  function quotaHit(kind) {
    quotaDelay = quotaDelay ? Math.min(quotaDelay * 2, cfg.quotaProbeMaxMs) : cfg.quotaProbeMs;
    if (kind === 'probe') { if (state === 'fallback') armProbe(quotaDelay); return; }
    toFallback(LIVE_COPY.quota, quotaDelay);
  }

  function toUnavailable(message) {
    epoch += 1;
    reconnectTimer = cancel(reconnectTimer);
    probeTimer = cancel(probeTimer);
    retire(pending); pending = null;
    const old = active; active = null;
    retire(old);
    failTurn(message);
    audioQueue = [];
    setState('unavailable', message);
  }

  // ---- offline ----------------------------------------------------------------
  function goOffline() {
    if (closed) return;
    epoch += 1;
    reconnectTimer = cancel(reconnectTimer);
    probeTimer = cancel(probeTimer);
    retire(pending); pending = null;
    const old = active; active = null;
    retire(old);
    failTurn(LIVE_COPY.offlineTurn);
    audioQueue = [];
    setState('offline', LIVE_COPY.offline);
  }

  function handleOnline(isOnline) {
    if (closed) return;
    if (!isOnline) {
      if (state !== 'offline' && state !== 'unavailable' && state !== 'idle') goOffline();
      return;
    }
    if (state === 'offline') {
      setState('reconnecting');
      attempt('primary');
    }
  }

  // ---- server -> client --------------------------------------------------------
  function onFrame(rec, data) {
    if (rec.retired) return;
    const text = frameText(data);
    if (text !== null && !rec.waiting) { handleFrame(rec, text); return; }
    // A Blob (binaryType could not be set) decodes asynchronously; later
    // frames queue behind it so the order is kept.
    rec.waiting += 1;
    rec.chain = rec.chain
      .then(() => (text !== null ? text : (data && typeof data.text === 'function' ? data.text() : null)))
      .then(value => { if (typeof value === 'string' && !rec.retired) handleFrame(rec, value); }, () => {})
      .finally(() => { rec.waiting -= 1; });
  }

  function handleFrame(rec, text) {
    let msg;
    try { msg = JSON.parse(text); } catch { emit('debug', { malformed: true }); return; }
    if (!isObject(msg)) { emit('debug', { malformed: true }); return; }
    if (msg.setupComplete !== undefined) { settle(rec); return; }
    if (!rec.settled || rec !== active) return;
    let known = false;
    if (isObject(msg.sessionResumptionUpdate)) {
      known = true;
      const u = msg.sessionResumptionUpdate;
      if (typeof u.newHandle === 'string' && u.newHandle && u.resumable !== false) resumeHandle = u.newHandle;
    }
    if (isObject(msg.serverContent)) { known = true; handleContent(rec, msg.serverContent); }
    if (isObject(msg.toolCall)) { known = true; handleToolCall(rec, msg.toolCall); }
    if (isObject(msg.toolCallCancellation)) { known = true; handleToolCancel(msg.toolCallCancellation); }
    if (msg.goAway !== undefined) { known = true; handleGoAway(rec); }
    if (msg.usageMetadata !== undefined) known = true;
    if (!known) emit('debug', { unknown: true, keys: Object.keys(msg).slice(0, 5) });
  }

  function handleContent(rec, sc) {
    if (sc.interrupted) emit('interrupted', {});
    const heard = textOf(sc.inputTranscription);
    if (heard) { openTurn(); emit('inputTranscript', { text: heard }); }
    for (const part of Array.isArray(sc.modelTurn?.parts) ? sc.modelTurn.parts : []) {
      const data = part?.inlineData?.data;
      if (typeof data === 'string' && data) { openTurn(); emit('audio', { data, mimeType: part.inlineData.mimeType }); }
    }
    const said = textOf(sc.outputTranscription);
    if (said) { openTurn(); emit('outputTranscript', { text: said }); }
    if (sc.turnComplete) {
      turnOpen = false;
      clearWatchdog();
      emit('turnComplete', { ok: true });
      if (rec.replaceAfterTurn) { rec.replaceAfterTurn = false; startReplacement(); }
      return;
    }
    if (turnOpen && !tools.size) armWatchdog();
  }

  function handleToolCall(rec, toolCall) {
    const calls = Array.isArray(toolCall.functionCalls) ? toolCall.functionCalls : [];
    for (const call of calls) {
      if (!isObject(call) || typeof call.name !== 'string' || !call.name) continue;
      const key = call.id != null ? String(call.id) : 'local-' + (++toolSeq);
      const controller = typeof AbortController === 'function' ? new AbortController() : null;
      tools.set(key, { rec, id: call.id, name: call.name, controller });
      // While our own tool runs the model is waiting on us, so the reply
      // watchdog pauses until every result has been sent.
      openTurn();
      clearWatchdog();
      emit('toolCall', { key, id: call.id, name: call.name, args: isObject(call.args) ? call.args : {}, signal: controller?.signal });
    }
  }

  function handleToolCancel(cancellation) {
    for (const id of Array.isArray(cancellation.ids) ? cancellation.ids : []) {
      const key = String(id);
      const slot = tools.get(key);
      if (slot) {
        tools.delete(key);
        try { slot.controller?.abort(); } catch { /* noop */ }
      }
      emit('toolCancelled', { key, id });
    }
    if (!tools.size && turnOpen && state === 'ready') armWatchdog();
  }

  // goAway: the server will close this socket soon. A replacement opens in
  // the background (resuming the session) and takes over before the old one
  // closes. Mid-turn, it waits for the turn to finish so the handle it
  // resumes from already includes that turn.
  function handleGoAway(rec) {
    if (rec.goingAway) return;
    rec.goingAway = true;
    emit('notice', { message: LIVE_COPY.refreshing, state, quiet: true });
    if (turnOpen || tools.size) { rec.replaceAfterTurn = true; return; }
    startReplacement();
  }

  function startReplacement() {
    if (closed || pending || state !== 'ready') return;
    attempt('replacement');
  }

  return {
    connect,
    disconnect,
    close: disconnect,
    sendAudio,
    respondTool,
    on,
    off,
    get state() { return state; },
    get resumeHandle() { return resumeHandle; },
    get queuedAudio() { return audioQueue.length; },
    get turnOpen() { return turnOpen; }
  };
}

// ---------------------------------------------------------------------------
// Typed messages written while offline wait here and go out, in order, once
// the browser is back online. Text mode and Live both depend on the network,
// so this is the one queue that matters for typed chat.
// ---------------------------------------------------------------------------
export function createOutbox({ deliver, online = browserOnline(), max = 5 } = {}) {
  if (typeof deliver !== 'function') throw new TypeError('createOutbox needs a deliver function.');
  let items = [];
  let flushing = false;
  const unsubscribe = online.onChange(isOnline => { if (isOnline) flush(); });
  async function flush() {
    if (flushing) return;
    flushing = true;
    try {
      while (items.length && online.isOnline()) {
        const item = items.shift();
        try { await deliver(item); } catch { /* deliver reports its own errors */ }
      }
    } finally {
      flushing = false;
    }
  }
  return {
    push(item) {
      if (items.length >= max) return false;
      items.push(item);
      return true;
    },
    flush,
    clear() { items = []; },
    dispose() { items = []; try { unsubscribe(); } catch { /* noop */ } },
    get size() { return items.length; }
  };
}
