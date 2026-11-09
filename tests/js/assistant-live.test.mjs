// Reliability tests for public/js/assistant/live.js, run with plain
// `node --test tests/js`. No browser, no network: a fake WebSocket, a manual
// clock and a fake online signal drive every failure mode the panel has to
// survive (drop and resume, goAway handover, Text mode and the return to
// Live, the per-turn watchdog, offline, malformed frames, tool calls).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  createLiveConnection, createOutbox, friendlyError, cleanMessage, historyToSeed,
  buildLiveSetup, isValidSession, liveSocketUrl, isQuotaClose, LIVE_COPY, LIVE_DEFAULTS
} from '../../public/js/assistant/live.js';

const flush = () => new Promise(resolve => setImmediate(resolve));

function fakeClock() {
  let t = 1_000_000;
  let nextId = 0;
  const queue = new Map();
  return {
    now: () => t,
    setTimeout(fn, ms) { const id = ++nextId; queue.set(id, { at: t + Math.max(0, Number(ms) || 0), fn }); return id; },
    clearTimeout(id) { queue.delete(id); },
    pending: () => queue.size,
    async advance(ms) {
      const end = t + ms;
      for (;;) {
        let next = null;
        for (const [id, entry] of queue) if (entry.at <= end && (!next || entry.at < next[1].at)) next = [id, entry];
        if (!next) break;
        queue.delete(next[0]);
        t = next[1].at;
        next[1].fn();
        await flush();
      }
      t = end;
      await flush();
    }
  };
}

class FakeSocket {
  constructor(url, registry) {
    this.url = url;
    this.readyState = 0;
    this.sent = [];
    this.handlers = {};
    this.closedByClient = null;
    registry.push(this);
  }
  addEventListener(type, fn) { (this.handlers[type] ||= []).push(fn); }
  fire(type, event = {}) { for (const fn of this.handlers[type] || []) fn(event); }
  send(data) { if (this.readyState !== 1) throw new Error('not open'); this.sent.push(JSON.parse(data)); }
  close(code, reason) {
    if (this.readyState >= 2) return;
    this.closedByClient = { code, reason };
    this.readyState = 3;
    this.fire('close', { code: code ?? 1005, reason: reason ?? '' });
  }
  // server side
  serverOpen() { this.readyState = 1; this.fire('open'); }
  serverSend(message) { this.fire('message', { data: typeof message === 'string' ? message : JSON.stringify(message) }); }
  serverBinary(message) { this.fire('message', { data: new TextEncoder().encode(JSON.stringify(message)).buffer }); }
  serverDrop(code = 1006, reason = '') { if (this.readyState === 3) return; this.readyState = 3; this.fire('close', { code, reason }); }
  get setup() { return this.sent.find(m => m.setup)?.setup; }
  get audio() { return this.sent.filter(m => m.realtimeInput).map(m => m.realtimeInput.audio.data); }
}

const SESSION = n => ({ token: 'tok-' + n, model: 'gemini-3.8-live', ws_url: 'wss://live.example/ws', system_instruction: 'Be brief.', tools: [{ name: 'search_trips' }] });

function harness({ mint, conn: connOptions = {} } = {}) {
  const clock = fakeClock();
  const sockets = [];
  const mints = [];
  let isOnline = true;
  const subscribers = new Set();
  const online = { isOnline: () => isOnline, onChange(cb) { subscribers.add(cb); return () => subscribers.delete(cb); } };
  let mintImpl = mint || (async n => SESSION(n));
  const conn = createLiveConnection({
    mintSession: async () => { mints.push(clock.now()); return mintImpl(mints.length); },
    wsFactory: url => new FakeSocket(url, sockets),
    online,
    timers: clock,
    now: clock.now,
    random: () => 0,
    ...connOptions
  });
  const events = [];
  for (const name of ['state', 'notice', 'ready', 'turnComplete', 'toolCall', 'toolCancelled', 'audio', 'inputTranscript', 'outputTranscript', 'interrupted', 'debug']) {
    conn.on(name, payload => events.push([name, payload]));
  }
  return {
    clock, sockets, mints, conn, events, subscribers,
    setOnline(value) { isOnline = value; for (const cb of [...subscribers]) cb(value); },
    setMint(fn) { mintImpl = fn; },
    of: name => events.filter(([n]) => n === name).map(([, p]) => p),
    states: () => events.filter(([n]) => n === 'state').map(([, p]) => p.state),
    notices: () => events.filter(([n]) => n === 'notice').map(([, p]) => p.message)
  };
}

async function handshake(socket, extra = []) {
  socket.serverOpen();
  socket.serverSend({ setupComplete: {} });
  for (const message of extra) socket.serverSend(message);
  await flush();
}

async function connectReady(h) {
  h.conn.connect();
  await flush();
  const socket = h.sockets.at(-1);
  await handshake(socket);
  return socket;
}

// ---------------------------------------------------------------------------

test('connects with the minted ephemeral token and a resumable setup', async () => {
  const h = harness({ conn: { seedTurns: () => historyToSeed([{ role: 'user', text: 'Trips tomorrow?' }, { role: 'assistant', text: 'Two trips.' }]) } });
  const socket = await connectReady(h);
  assert.equal(h.mints.length, 1);
  assert.equal(socket.url, 'wss://live.example/ws?access_token=tok-1');
  assert.equal(socket.setup.model, 'models/gemini-3.8-live');
  assert.deepEqual(socket.setup.sessionResumption, {});
  assert.deepEqual(socket.setup.contextWindowCompression, { slidingWindow: {} });
  assert.deepEqual(socket.setup.tools, [{ functionDeclarations: [{ name: 'search_trips' }] }]);
  assert.equal(h.conn.state, 'ready');
  assert.deepEqual(h.states(), ['connecting', 'ready']);
  const seed = socket.sent.find(m => m.clientContent);
  assert.equal(seed.clientContent.turnComplete, false, 'the seed must not ask for a reply');
  assert.deepEqual(seed.clientContent.turns.map(t => t.role), ['user', 'model']);
  assert.deepEqual(h.of('ready')[0], { resumed: false, recovered: false, replaced: false });
  h.conn.disconnect();
});

test('mic audio spoken while connecting is queued (newest only) and sent once ready', async () => {
  const h = harness({ conn: { maxQueuedAudio: 3 } });
  h.conn.connect();
  for (const chunk of ['a', 'b', 'c', 'd', 'e']) assert.equal(h.conn.sendAudio(chunk), true);
  assert.equal(h.conn.queuedAudio, 3);
  await flush();
  await handshake(h.sockets[0]);
  assert.deepEqual(h.sockets[0].audio, ['c', 'd', 'e']);
  assert.equal(h.conn.queuedAudio, 0);
  h.conn.sendAudio('f');
  assert.deepEqual(h.sockets[0].audio, ['c', 'd', 'e', 'f']);
  assert.equal(h.sockets[0].sent.at(-1).realtimeInput.audio.mimeType, 'audio/pcm;rate=16000');
  h.conn.disconnect();
});

test('a drop mid-reply fails that turn, reconnects with backoff and resumes the same session', async () => {
  const h = harness();
  const first = await connectReady(h);
  first.serverSend({ sessionResumptionUpdate: { newHandle: 'handle-1', resumable: true } });
  first.serverSend({ serverContent: { inputTranscription: { text: 'Book me' } } });
  first.serverDrop(1006);
  await flush();
  assert.equal(h.conn.state, 'reconnecting');
  assert.ok(h.notices().includes(LIVE_COPY.reconnecting));
  assert.deepEqual(h.of('turnComplete').at(-1), { ok: false, error: LIVE_COPY.dropped });
  assert.equal(h.mints.length, 1, 'no instant retry: the backoff timer decides');
  h.conn.sendAudio('during-drop');
  await h.clock.advance(LIVE_DEFAULTS.reconnectBaseMs);
  assert.equal(h.mints.length, 2);
  const second = h.sockets[1];
  await handshake(second);
  assert.equal(h.conn.state, 'ready');
  assert.deepEqual(second.setup.sessionResumption, { handle: 'handle-1' });
  assert.equal(second.sent.some(m => m.clientContent), false, 'a resumed session is not re-seeded');
  assert.deepEqual(second.audio, ['during-drop'], 'speech during the drop is sent once ready');
  assert.deepEqual(h.of('ready').at(-1), { resumed: true, recovered: false, replaced: false });
  h.conn.disconnect();
});

test('without a resumption handle a reconnect seeds the conversation again', async () => {
  let seedCalls = 0;
  const h = harness({ conn: { seedTurns: () => { seedCalls++; return [{ role: 'user', parts: [{ text: 'hi' }] }, { role: 'model', parts: [{ text: 'hello' }] }]; } } });
  await connectReady(h);
  h.sockets[0].serverDrop();
  await h.clock.advance(1000);
  await handshake(h.sockets[1]);
  assert.equal(seedCalls, 2);
  assert.ok(h.sockets[1].sent.some(m => m.clientContent));
  h.conn.disconnect();
});

test('three failures within 60s switch to Text mode, then a probe returns to Live at a turn boundary', async () => {
  let busy = false;
  const h = harness({ conn: { canSwitchBack: () => !busy } });
  await connectReady(h);
  h.sockets[0].serverDrop();                 // failure 1
  await h.clock.advance(1000);
  h.sockets[1].serverDrop();                 // failure 2 (handshake never finished)
  await flush();
  assert.equal(h.conn.state, 'reconnecting');
  await h.clock.advance(2000);
  h.sockets[2].serverDrop();                 // failure 3
  await flush();
  assert.equal(h.conn.state, 'fallback');
  assert.equal(h.notices().at(-1), LIVE_COPY.fallback);
  assert.equal(h.conn.sendAudio('x'), false, 'no mic audio is held in Text mode');

  const mintsAtFallback = h.mints.length;
  await h.clock.advance(LIVE_DEFAULTS.probeMs - 1);
  assert.equal(h.mints.length, mintsAtFallback, 'no probe before the probe interval');

  busy = true;                               // a typed reply is still in flight
  await h.clock.advance(1);
  assert.equal(h.mints.length, mintsAtFallback, 'the probe waits for a turn boundary');
  busy = false;
  await h.clock.advance(LIVE_DEFAULTS.busyProbeMs);
  assert.equal(h.mints.length, mintsAtFallback + 1);
  const probe = h.sockets.at(-1);
  probe.serverOpen();
  assert.deepEqual(probe.setup.sessionResumption, {}, 'Text mode turns happened, so Live starts fresh');
  assert.equal(h.conn.state, 'fallback', 'still Text mode until the probe is healthy');
  probe.serverSend({ setupComplete: {} });
  await flush();
  assert.equal(h.conn.state, 'ready');
  assert.deepEqual(h.of('ready').at(-1), { resumed: false, recovered: true, replaced: false });
  h.conn.disconnect();
});

test('a healthy probe is dropped if a turn started meanwhile, and retried shortly after', async () => {
  let busy = false;
  const h = harness({ mint: async n => { const e = new Error('Live voice could not connect. You can still use text chat.'); e.status = 503; if (n === 1) throw e; return SESSION(n); }, conn: { canSwitchBack: () => !busy } });
  h.conn.connect();
  await flush();
  assert.equal(h.conn.state, 'fallback');
  await h.clock.advance(LIVE_DEFAULTS.probeMs);
  const probe = h.sockets.at(-1);
  busy = true;
  await handshake(probe);
  assert.equal(h.conn.state, 'fallback');
  assert.ok(probe.closedByClient, 'the probe socket is closed, not adopted mid-turn');
  busy = false;
  await h.clock.advance(LIVE_DEFAULTS.busyProbeMs);
  await handshake(h.sockets.at(-1));
  assert.equal(h.conn.state, 'ready');
  h.conn.disconnect();
});

test('failures spread wider than the window keep reconnecting instead of Text mode', async () => {
  const h = harness();
  await connectReady(h);
  for (let i = 0; i < 4; i++) {
    h.sockets.at(-1).serverDrop();
    await h.clock.advance(LIVE_DEFAULTS.reconnectMaxMs);
    await handshake(h.sockets.at(-1));
    assert.equal(h.conn.state, 'ready');
    await h.clock.advance(LIVE_DEFAULTS.failureWindowMs);
  }
  assert.equal(h.states().includes('fallback'), false);
  h.conn.disconnect();
});

test('a 503 from /assistant/session goes straight to Text mode with the server reason', async () => {
  const h = harness({ mint: async () => { const e = new Error('Live voice is unavailable. You can still use text chat.'); e.status = 503; throw e; } });
  h.conn.connect();
  await flush();
  assert.equal(h.conn.state, 'fallback');
  assert.equal(h.notices().at(-1), 'Live voice is unavailable. You can still use text chat.');
  assert.equal(h.sockets.length, 0);
  h.conn.disconnect();
});

test('an expired sign in makes Live unavailable with no retries', async () => {
  const h = harness({ mint: async () => { const e = new Error('Unauthenticated.'); e.status = 401; throw e; } });
  h.conn.connect();
  await flush();
  assert.equal(h.conn.state, 'unavailable');
  assert.equal(h.notices().at(-1), LIVE_COPY.signIn);
  await h.clock.advance(10 * 60 * 1000);
  assert.equal(h.mints.length, 1);
  h.conn.disconnect();
});

test('a quota close skips the retries and probes again only after minutes', async () => {
  const h = harness();
  await connectReady(h);
  h.sockets[0].serverDrop(1011, 'You exceeded your current quota, please check your plan.');
  await flush();
  assert.equal(h.conn.state, 'fallback');
  assert.equal(h.notices().at(-1), LIVE_COPY.quota);
  const mints = h.mints.length;
  await h.clock.advance(LIVE_DEFAULTS.probeMs);
  assert.equal(h.mints.length, mints, 'the normal probe interval is too soon after a quota close');
  await h.clock.advance(LIVE_DEFAULTS.quotaProbeMs - LIVE_DEFAULTS.probeMs);
  assert.equal(h.mints.length, mints + 1);
  h.conn.disconnect();
});

test('goAway opens a replacement before the old socket closes, with no visible drop', async () => {
  const h = harness();
  const old = await connectReady(h);
  old.serverSend({ sessionResumptionUpdate: { newHandle: 'handle-9' } });
  old.serverSend({ goAway: { timeLeft: '10s' } });
  await flush();
  assert.equal(h.notices().at(-1), LIVE_COPY.refreshing);
  assert.equal(h.sockets.length, 2, 'replacement opened');
  const fresh = h.sockets[1];
  assert.equal(old.closedByClient, null, 'old socket still open while the replacement handshakes');
  h.conn.sendAudio('still-old');
  assert.deepEqual(old.audio, ['still-old']);
  await handshake(fresh);
  assert.deepEqual(fresh.setup.sessionResumption, { handle: 'handle-9' });
  assert.ok(old.closedByClient, 'old socket retired after the handover');
  h.conn.sendAudio('now-new');
  assert.deepEqual(fresh.audio, ['now-new']);
  assert.deepEqual(h.states(), ['connecting', 'ready'], 'never left ready');
  assert.deepEqual(h.of('ready').at(-1), { resumed: true, recovered: false, replaced: true });
  old.serverSend({ serverContent: { outputTranscription: { text: 'stale' } } });
  assert.equal(h.of('outputTranscript').length, 0, 'the retired socket is ignored');
  h.conn.disconnect();
});

test('goAway mid-turn waits for the turn to finish before replacing', async () => {
  const h = harness();
  const old = await connectReady(h);
  old.serverSend({ serverContent: { outputTranscription: { text: 'The 7 AM trip' } } });
  old.serverSend({ goAway: {} });
  await flush();
  assert.equal(h.sockets.length, 1, 'no replacement during the reply');
  old.serverSend({ sessionResumptionUpdate: { newHandle: 'after-turn' } });
  old.serverSend({ serverContent: { turnComplete: true } });
  await flush();
  assert.equal(h.sockets.length, 2);
  await handshake(h.sockets[1]);
  assert.deepEqual(h.sockets[1].setup.sessionResumption, { handle: 'after-turn' });
  h.conn.disconnect();
});

test('if the old socket closes before the replacement is ready, audio waits for the replacement', async () => {
  const h = harness();
  const old = await connectReady(h);
  old.serverSend({ goAway: {} });
  await flush();
  const fresh = h.sockets[1];
  old.serverDrop(1000);
  await flush();
  assert.equal(h.conn.state, 'reconnecting');
  h.conn.sendAudio('held');
  await handshake(fresh);
  assert.equal(h.conn.state, 'ready');
  assert.deepEqual(fresh.audio, ['held']);
  assert.equal(h.mints.length, 2, 'no extra session minted');
  h.conn.disconnect();
});

test('a turn that starts during the replacement handshake drops that replacement and redoes it after the turn', async () => {
  const h = harness();
  const old = await connectReady(h);
  old.serverSend({ sessionResumptionUpdate: { newHandle: 'h1' } });
  old.serverSend({ goAway: {} });
  await flush();
  const stale = h.sockets[1];
  stale.serverOpen();
  assert.deepEqual(stale.setup.sessionResumption, { handle: 'h1' });
  old.serverSend({ serverContent: { inputTranscription: { text: 'Any trip at noon?' } } });
  stale.serverSend({ setupComplete: {} });
  await flush();
  assert.equal(stale.closedByClient?.reason, 'stale', 'the replacement is a turn behind, so it is dropped');
  assert.equal(old.closedByClient, null, 'the old socket keeps carrying the turn');
  h.conn.sendAudio('to-old');
  assert.ok(old.audio.includes('to-old'));
  old.serverSend({ serverContent: { outputTranscription: { text: 'Yes, at 12:30.' } } });
  old.serverSend({ sessionResumptionUpdate: { newHandle: 'h2' } });
  old.serverSend({ serverContent: { turnComplete: true } });
  await flush();
  assert.equal(h.sockets.length, 3, 'a fresh replacement opens at the turn boundary');
  await handshake(h.sockets[2]);
  assert.deepEqual(h.sockets[2].setup.sessionResumption, { handle: 'h2' });
  assert.ok(old.closedByClient, 'old socket retired after the handover');
  assert.deepEqual(h.states(), ['connecting', 'ready'], 'never left ready');
  assert.deepEqual(h.of('turnComplete'), [{ ok: true }], 'the turn on the old socket finished normally');
  assert.deepEqual(h.of('ready').at(-1), { resumed: true, recovered: false, replaced: true });
  h.conn.disconnect();
});

test('a turn that finished during the replacement handshake redoes it at once from the newer handle', async () => {
  const h = harness();
  const old = await connectReady(h);
  old.serverSend({ sessionResumptionUpdate: { newHandle: 'h1' } });
  old.serverSend({ goAway: {} });
  await flush();
  const stale = h.sockets[1];
  stale.serverOpen();
  old.serverSend({ serverContent: { inputTranscription: { text: 'Fare to San Jose?' } } });
  old.serverSend({ serverContent: { outputTranscription: { text: 'It is 250 pesos.' } } });
  old.serverSend({ sessionResumptionUpdate: { newHandle: 'h2' } });
  old.serverSend({ serverContent: { turnComplete: true } });
  stale.serverSend({ setupComplete: {} });
  await flush();
  assert.equal(stale.closedByClient?.reason, 'stale');
  assert.equal(h.sockets.length, 3);
  await handshake(h.sockets[2]);
  assert.deepEqual(h.sockets[2].setup.sessionResumption, { handle: 'h2' });
  assert.equal(h.conn.state, 'ready');
  assert.deepEqual(h.states(), ['connecting', 'ready']);
  h.conn.disconnect();
});

test('if the old socket closed after a newer turn, a stale replacement gives way to a resumed reconnect', async () => {
  const h = harness();
  const old = await connectReady(h);
  old.serverSend({ sessionResumptionUpdate: { newHandle: 'h1' } });
  old.serverSend({ goAway: {} });
  await flush();
  const stale = h.sockets[1];
  stale.serverOpen();
  old.serverSend({ serverContent: { inputTranscription: { text: 'Is the 7 AM trip full?' } } });
  old.serverSend({ sessionResumptionUpdate: { newHandle: 'h2' } });
  old.serverSend({ serverContent: { turnComplete: true } });
  old.serverDrop(1000);
  await flush();
  assert.equal(h.conn.state, 'reconnecting');
  h.conn.sendAudio('held');
  stale.serverSend({ setupComplete: {} });
  await flush();
  assert.equal(stale.closedByClient?.reason, 'stale');
  assert.equal(h.sockets.length, 3);
  await handshake(h.sockets[2]);
  assert.deepEqual(h.sockets[2].setup.sessionResumption, { handle: 'h2' });
  assert.equal(h.conn.state, 'ready');
  assert.deepEqual(h.sockets[2].audio, ['held'], 'audio held during the gap goes to the new session');
  h.conn.disconnect();
});

test('the per-turn watchdog ends a stuck turn with a friendly line', async () => {
  const h = harness();
  const socket = await connectReady(h);
  socket.serverSend({ serverContent: { inputTranscription: { text: 'When is the next trip?' } } });
  await h.clock.advance(LIVE_DEFAULTS.turnWatchdogMs - 1);
  assert.equal(h.of('turnComplete').length, 0);
  await h.clock.advance(1);
  assert.deepEqual(h.of('turnComplete').at(-1), { ok: false, error: LIVE_COPY.tooLong, timeout: true });
  assert.equal(h.notices().at(-1), 'That took too long. Try again.');
  assert.equal(h.conn.state, 'ready', 'the connection itself stays up');
  h.conn.disconnect();
});

test('a running tool pauses the watchdog; the result goes back on the asking socket', async () => {
  const h = harness();
  const socket = await connectReady(h);
  socket.serverSend({ toolCall: { functionCalls: [{ id: 'call-1', name: 'search_trips', args: { date: 'tomorrow' } }] } });
  const call = h.of('toolCall')[0];
  assert.equal(call.name, 'search_trips');
  assert.deepEqual(call.args, { date: 'tomorrow' });
  await h.clock.advance(LIVE_DEFAULTS.turnWatchdogMs * 3);
  assert.equal(h.of('turnComplete').length, 0, 'a slow tool is not a stuck turn');
  assert.equal(h.conn.respondTool(call.key, { trips: [] }), true);
  assert.deepEqual(socket.sent.at(-1), { toolResponse: { functionResponses: [{ id: 'call-1', name: 'search_trips', response: { trips: [] } }] } });
  assert.equal(h.conn.respondTool(call.key, {}), false, 'one answer per call');
  await h.clock.advance(LIVE_DEFAULTS.turnWatchdogMs);
  assert.equal(h.of('turnComplete').at(-1).error, LIVE_COPY.tooLong, 'watchdog re-armed after the tool answered');
  h.conn.disconnect();
});

test('a tool call the model cancels is aborted and never answered', async () => {
  const h = harness();
  const socket = await connectReady(h);
  socket.serverSend({ toolCall: { functionCalls: [{ id: 'c9', name: 'cancel_booking', args: {} }] } });
  const call = h.of('toolCall')[0];
  socket.serverSend({ toolCallCancellation: { ids: ['c9'] } });
  assert.equal(call.signal.aborted, true);
  assert.deepEqual(h.of('toolCancelled')[0], { key: 'c9', id: 'c9' });
  const sentBefore = socket.sent.length;
  assert.equal(h.conn.respondTool(call.key, { ok: true }), false);
  assert.equal(socket.sent.length, sentBefore);
  h.conn.disconnect();
});

test('malformed and unknown frames are ignored and the next valid one still works', async () => {
  const h = harness();
  const socket = await connectReady(h);
  socket.serverSend('{not json');
  socket.serverSend('[1,2,3]');
  socket.serverSend('"just a string"');
  socket.serverSend({ somethingNew: { a: 1 } });
  socket.serverSend({ serverContent: { modelTurn: { parts: [null, { inlineData: { data: 42 } }, { text: 'thought' }] } } });
  socket.serverBinary({ serverContent: { outputTranscription: { text: 'Hello' }, turnComplete: true } });
  assert.equal(h.conn.state, 'ready');
  assert.equal(h.of('debug').filter(d => d.malformed).length, 3);
  assert.deepEqual(h.of('debug').find(d => d.unknown).keys, ['somethingNew']);
  assert.deepEqual(h.of('outputTranscript'), [{ text: 'Hello' }], 'binary frames are decoded');
  assert.deepEqual(h.of('turnComplete'), [{ ok: true }]);
  assert.equal(h.of('audio').length, 0);
  h.conn.disconnect();
});

test('audio, transcripts and interruptions are passed to the panel', async () => {
  const h = harness();
  const socket = await connectReady(h);
  socket.serverSend({ serverContent: { inputTranscription: { text: 'Hi' } } });
  socket.serverSend({ serverContent: { modelTurn: { parts: [{ inlineData: { mimeType: 'audio/pcm;rate=24000', data: 'AAAA' } }] } } });
  socket.serverSend({ serverContent: { interrupted: true } });
  assert.deepEqual(h.of('inputTranscript'), [{ text: 'Hi' }]);
  assert.deepEqual(h.of('audio'), [{ data: 'AAAA', mimeType: 'audio/pcm;rate=24000' }]);
  assert.equal(h.of('interrupted').length, 1);
  h.conn.disconnect();
});

test('offline: no connection attempts and no reconnect storm, then it resumes once online', async () => {
  const h = harness();
  await connectReady(h);
  h.sockets[0].serverSend({ serverContent: { inputTranscription: { text: 'Hello' } } });
  h.setOnline(false);
  await flush();
  assert.equal(h.conn.state, 'offline');
  assert.equal(h.notices().at(-1), 'You are offline.');
  assert.ok(h.sockets[0].closedByClient);
  assert.deepEqual(h.of('turnComplete').at(-1), { ok: false, error: LIVE_COPY.offlineTurn });
  assert.equal(h.conn.sendAudio('x'), false);
  await h.clock.advance(30 * 60 * 1000);
  assert.equal(h.mints.length, 1, 'nothing retried while offline');
  assert.equal(h.clock.pending(), 0, 'no timers left running while offline');
  h.setOnline(true);
  await flush();
  assert.equal(h.mints.length, 2);
  await handshake(h.sockets[1]);
  assert.equal(h.conn.state, 'ready');
  h.conn.disconnect();
});

test('starting while offline waits for the network instead of failing', async () => {
  const h = harness();
  h.setOnline(false);
  h.conn.connect();
  await flush();
  assert.equal(h.conn.state, 'offline');
  assert.equal(h.mints.length, 0);
  h.setOnline(true);
  await flush();
  await handshake(h.sockets[0]);
  assert.equal(h.conn.state, 'ready');
  h.conn.disconnect();
});

test('a slow handshake times out and counts as a failure', async () => {
  const h = harness();
  h.conn.connect();
  await flush();
  h.sockets[0].serverOpen();
  await h.clock.advance(LIVE_DEFAULTS.setupTimeoutMs);
  assert.ok(h.sockets[0].closedByClient);
  assert.equal(h.conn.state, 'reconnecting');
  await h.clock.advance(1000);
  assert.equal(h.mints.length, 2);
  h.conn.disconnect();
});

test('a rejected resumption handle is not presented again', async () => {
  const h = harness();
  const first = await connectReady(h);
  first.serverSend({ sessionResumptionUpdate: { newHandle: 'expired' } });
  first.serverDrop();
  await h.clock.advance(1000);
  const second = h.sockets[1];
  second.serverOpen();
  assert.deepEqual(second.setup.sessionResumption, { handle: 'expired' });
  second.serverDrop(1008, 'session not found');
  await h.clock.advance(5000);
  assert.deepEqual(h.sockets[2].setup ?? null, null);
  h.sockets[2].serverOpen();
  assert.deepEqual(h.sockets[2].setup.sessionResumption, {});
  h.conn.disconnect();
});

test('an invalid session payload is a failure, not a crash', async () => {
  const h = harness({ mint: async () => ({ token: 'x', model: 'm', ws_url: 'http://not-a-socket' }) });
  h.conn.connect();
  await flush();
  assert.equal(h.conn.state, 'reconnecting');
  assert.equal(h.sockets.length, 0);
  h.conn.disconnect();
});

test('disconnect closes everything and nothing fires afterwards', async () => {
  const h = harness();
  const socket = await connectReady(h);
  socket.serverSend({ serverContent: { inputTranscription: { text: 'Hi' } } });
  h.conn.disconnect();
  assert.equal(h.conn.state, 'closed');
  assert.ok(socket.closedByClient);
  assert.equal(h.clock.pending(), 0);
  assert.equal(h.subscribers.size, 0, 'online listener removed');
  const count = h.events.length;
  socket.serverSend({ serverContent: { outputTranscription: { text: 'late' } } });
  h.setOnline(false);
  await h.clock.advance(120000);
  assert.equal(h.events.length, count);
  assert.equal(h.conn.sendAudio('x'), false);
});

test('a throwing listener cannot break the connection', async () => {
  const h = harness();
  h.conn.on('state', () => { throw new Error('panel bug'); });
  await connectReady(h);
  assert.equal(h.conn.state, 'ready');
  h.conn.disconnect();
});

// ---------------------------------------------------------------------------

test('friendlyError keeps plain server messages and hides technical ones', () => {
  assert.equal(friendlyError({ status: 503, message: 'Live voice could not connect. You can still use text chat.' }), 'Live voice could not connect. You can still use text chat.');
  assert.equal(friendlyError({ status: 422, message: 'The message field is required.' }), 'The message field is required.');
  assert.equal(friendlyError({ status: 500, message: 'SQLSTATE[42S02]: Base table not found' }), LIVE_COPY.generic);
  assert.equal(friendlyError({ status: 422, message: 'cURL error 6 https://generativelanguage.googleapis.com/v1alpha?key=AIzaSyFAKEFAKEFAKE' }), LIVE_COPY.generic);
  assert.equal(friendlyError({ status: 401, message: 'Unauthenticated.' }), LIVE_COPY.signIn);
  assert.equal(friendlyError({ status: 419, message: 'CSRF token mismatch.' }), LIVE_COPY.signIn);
  assert.equal(friendlyError({ status: 429, message: 'Too Many Attempts.' }), LIVE_COPY.busy);
  assert.equal(friendlyError(new TypeError('Failed to fetch')), LIVE_COPY.network);
  assert.equal(friendlyError(new Error("Cannot read properties of undefined (reading 'x')")), LIVE_COPY.generic);
  assert.equal(friendlyError(null, 'Fallback.'), 'Fallback.');
  assert.equal(cleanMessage('Error in /home/app/vendor/x.php line 4'), '');
  assert.equal(cleanMessage('x'.repeat(300)).length, 200);
  assert.equal(cleanMessage('  Two\n lines  '), 'Two lines');
});

test('historyToSeed builds Live turns and ends on a model turn', () => {
  const turns = historyToSeed([
    { role: 'user', text: 'a' }, { role: 'assistant', text: 'b' }, { role: 'system', text: 'x' },
    { role: 'assistant', text: '   ' }, { role: 'user', text: 'unanswered' }
  ]);
  assert.deepEqual(turns, [{ role: 'user', parts: [{ text: 'a' }] }, { role: 'model', parts: [{ text: 'b' }] }]);
  const long = historyToSeed([{ role: 'user', text: 'q' }, { role: 'assistant', text: 'y'.repeat(4000) }], { maxChars: 100 });
  assert.equal(long[1].parts[0].text.length, 102);
  assert.equal(historyToSeed(Array.from({ length: 30 }, (_, i) => ({ role: i % 2 ? 'assistant' : 'user', text: String(i) }))).length, 10);
  assert.deepEqual(historyToSeed(null), []);
});

test('session helpers validate and build the socket URL', () => {
  assert.equal(isValidSession(SESSION(1)), true);
  assert.equal(isValidSession({ ...SESSION(1), ws_url: 'ws://plain' }), false);
  assert.equal(isValidSession({ ...SESSION(1), token: '' }), false);
  assert.equal(liveSocketUrl({ ws_url: 'wss://h/p?x=1', token: 'a/b' }), 'wss://h/p?x=1&access_token=a%2Fb');
  assert.deepEqual(buildLiveSetup(SESSION(1), { resumeHandle: 'h' }).setup.sessionResumption, { handle: 'h' });
  assert.equal(buildLiveSetup({ ...SESSION(1), tools: undefined }).setup.tools[0].functionDeclarations.length, 0);
  assert.equal(isQuotaClose({ reason: 'RESOURCE_EXHAUSTED' }), true);
  assert.equal(isQuotaClose({ reason: 'going away' }), false);
});

test('the offline outbox holds typed messages and sends them in order once online', async () => {
  let isOnline = false;
  const subscribers = new Set();
  const online = { isOnline: () => isOnline, onChange(cb) { subscribers.add(cb); return () => subscribers.delete(cb); } };
  const delivered = [];
  const outbox = createOutbox({ online, max: 2, deliver: async item => { await flush(); delivered.push(item.text); if (item.text === 'one') throw new Error('handled by the panel'); } });
  assert.equal(outbox.push({ text: 'one' }), true);
  assert.equal(outbox.push({ text: 'two' }), true);
  assert.equal(outbox.push({ text: 'three' }), false, 'bounded');
  await outbox.flush();
  assert.deepEqual(delivered, [], 'nothing goes out while offline');
  isOnline = true;
  for (const cb of subscribers) cb(true);
  await flush(); await flush(); await flush();
  assert.deepEqual(delivered, ['one', 'two'], 'in order, and one failure does not block the rest');
  assert.equal(outbox.size, 0);
  outbox.push({ text: 'x' });
  outbox.clear();
  assert.equal(outbox.size, 0);
  outbox.dispose();
  assert.equal(subscribers.size, 0);
});

test('the module imports cleanly without a DOM', async () => {
  assert.equal(typeof globalThis.document, 'undefined');
  const mod = await import('../../public/js/assistant/live.js');
  assert.equal(typeof mod.createLiveConnection, 'function');
  assert.throws(() => mod.createLiveConnection({}), /mintSession/);
});
