// Mic capture (browser AudioWorklet -> 16 kHz PCM16 mono chunks) and
// playback (24 kHz PCM16 mono -> a scheduled Web Audio queue) for the Live
// client. Every byte-level helper here is plain JS with no DOM dependency,
// so it is unit-tested directly; only MicCapture / PlaybackQueue touch real
// browser audio APIs, and both accept injected factories so their scheduling
// logic can be exercised with fakes too.

// ---------------------------------------------------------------------------
// Base64 <-> bytes. Written by hand (no atob/btoa) so encode and decode are
// identical in the browser and under `node --test`.
// ---------------------------------------------------------------------------
const B64_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
const B64_LOOKUP = (() => {
  const table = new Uint8Array(256);
  for (let i = 0; i < B64_CHARS.length; i++) table[B64_CHARS.charCodeAt(i)] = i;
  return table;
})();

export function bytesToBase64(bytes) {
  let out = '';
  const len = bytes.length;
  for (let i = 0; i < len; i += 3) {
    const b0 = bytes[i];
    const b1 = i + 1 < len ? bytes[i + 1] : undefined;
    const b2 = i + 2 < len ? bytes[i + 2] : undefined;
    out += B64_CHARS[b0 >> 2];
    out += B64_CHARS[((b0 & 0x03) << 4) | ((b1 ?? 0) >> 4)];
    out += b1 === undefined ? '=' : B64_CHARS[((b1 & 0x0f) << 2) | ((b2 ?? 0) >> 6)];
    out += b2 === undefined ? '=' : B64_CHARS[b2 & 0x3f];
  }
  return out;
}

export function base64ToBytes(base64) {
  const clean = String(base64 || '').replace(/[^A-Za-z0-9+/]/g, '');
  const len = clean.length;
  const bytes = new Uint8Array(Math.floor((len * 3) / 4));
  let p = 0;
  for (let i = 0; i < len; i += 4) {
    const e0 = B64_LOOKUP[clean.charCodeAt(i)];
    const e1 = i + 1 < len ? B64_LOOKUP[clean.charCodeAt(i + 1)] : 0;
    const e2 = i + 2 < len ? B64_LOOKUP[clean.charCodeAt(i + 2)] : undefined;
    const e3 = i + 3 < len ? B64_LOOKUP[clean.charCodeAt(i + 3)] : undefined;
    bytes[p++] = (e0 << 2) | (e1 >> 4);
    if (e2 !== undefined) bytes[p++] = ((e1 & 0x0f) << 4) | (e2 >> 2);
    if (e3 !== undefined) bytes[p++] = ((e2 & 0x03) << 6) | e3;
  }
  return bytes.subarray(0, p);
}

// ---------------------------------------------------------------------------
// PCM16 <-> Float32, and linear-interpolation resampling.
// ---------------------------------------------------------------------------
export function floatTo16BitPCM(float32Array) {
  const out = new Int16Array(float32Array.length);
  for (let i = 0; i < float32Array.length; i++) {
    const s = Math.max(-1, Math.min(1, float32Array[i]));
    out[i] = s < 0 ? s * 0x8000 : s * 0x7fff;
  }
  return out;
}

export function pcm16ToFloat32(int16Array) {
  const out = new Float32Array(int16Array.length);
  for (let i = 0; i < int16Array.length; i++) {
    const v = int16Array[i];
    out[i] = v < 0 ? v / 0x8000 : v / 0x7fff;
  }
  return out;
}

// Same algorithm the inline AudioWorklet processor below uses — kept as a
// standalone export so the resampling math has a direct unit test.
export function downsampleLinear(float32Array, fromRate, toRate) {
  if (fromRate === toRate) return float32Array;
  const ratio = fromRate / toRate;
  const outLength = Math.max(0, Math.floor(float32Array.length / ratio));
  const out = new Float32Array(outLength);
  for (let i = 0; i < outLength; i++) {
    const idx = i * ratio;
    const i0 = Math.floor(idx);
    const i1 = Math.min(i0 + 1, float32Array.length - 1);
    const frac = idx - i0;
    out[i] = float32Array[i0] * (1 - frac) + float32Array[i1] * frac;
  }
  return out;
}

export const MIC_INPUT_RATE = 16000;
export const PLAYBACK_RATE = 24000;
export const AUDIO_INPUT_MIME_TYPE = 'audio/pcm;rate=16000';

export function isAudioCaptureSupported() {
  return typeof window !== 'undefined' &&
    !!(window.AudioContext || window.webkitAudioContext) &&
    typeof navigator !== 'undefined' &&
    !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
}

function getAudioContextCtor() {
  return (typeof window !== 'undefined' && (window.AudioContext || window.webkitAudioContext)) || null;
}

// ---------------------------------------------------------------------------
// Mic capture. The worklet always downsamples from whatever the context's
// native rate turns out to be (44.1k/48k on most hardware) down to 16 kHz —
// it never relies on the browser honouring a requested context sample rate,
// which is the one part of this that has historically differed by browser.
// ---------------------------------------------------------------------------
const MIC_WORKLET_NAME = 'dielco-mic-worklet';

function micWorkletSource() {
  return `
class DielcoMicProcessor extends AudioWorkletProcessor {
  constructor(options) {
    super();
    const opts = (options && options.processorOptions) || {};
    this.targetRate = opts.targetSampleRate || 16000;
    this.chunkMs = opts.chunkMs || 80;
    this.ratio = sampleRate / this.targetRate;
    this.targetChunkSamples = Math.max(1, Math.round((this.targetRate * this.chunkMs) / 1000));
    this.nativeAccum = [];
  }
  process(inputs) {
    const input = inputs[0];
    const channel = input && input[0];
    if (channel && channel.length) {
      for (let i = 0; i < channel.length; i++) this.nativeAccum.push(channel[i]);
      const neededNative = Math.ceil(this.targetChunkSamples * this.ratio) + 4;
      if (this.nativeAccum.length >= neededNative) {
        const src = this.nativeAccum;
        const outLen = Math.max(1, Math.floor(src.length / this.ratio));
        const out = new Int16Array(outLen);
        let peak = 0;
        for (let i = 0; i < outLen; i++) {
          const idx = i * this.ratio;
          const i0 = Math.floor(idx);
          const i1 = Math.min(i0 + 1, src.length - 1);
          const frac = idx - i0;
          const sample = src[i0] * (1 - frac) + src[i1] * frac;
          const clamped = Math.max(-1, Math.min(1, sample));
          out[i] = clamped < 0 ? clamped * 0x8000 : clamped * 0x7fff;
          const abs = Math.abs(clamped);
          if (abs > peak) peak = abs;
        }
        this.port.postMessage({ pcm16: out.buffer, level: peak }, [out.buffer]);
        this.nativeAccum = [];
      }
    }
    return true;
  }
}
registerProcessor('${MIC_WORKLET_NAME}', DielcoMicProcessor);
`;
}

export class MicCapture {
  constructor({ onChunk, onLevel, onError, chunkMs = 80 } = {}) {
    this._onChunk = onChunk || (() => {});
    this._onLevel = onLevel || (() => {});
    this._onError = onError || (() => {});
    this._chunkMs = chunkMs;
    this._ctx = null;
    this._stream = null;
    this._source = null;
    this._node = null;
    this._silentGain = null;
    this._active = false;
    this._workletUrl = null;
  }

  get active() { return this._active; }

  async resume() {
    if (this._ctx && this._ctx.state === 'suspended') {
      try { await this._ctx.resume(); } catch { /* best effort */ }
    }
  }

  async start() {
    if (this._active) return;
    if (!isAudioCaptureSupported()) {
      this._onError(new Error('This browser cannot capture microphone audio.'));
      return;
    }
    const AudioCtx = getAudioContextCtor();
    try {
      this._stream = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true, channelCount: 1 }
      });
    } catch (err) {
      const denied = err && (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError');
      this._onError(new Error(denied
        ? 'Microphone access was denied. You can still type to the assistant.'
        : 'The microphone could not be started. You can still type to the assistant.'));
      return;
    }

    try {
      this._ctx = new AudioCtx();
      const blob = new Blob([micWorkletSource()], { type: 'application/javascript' });
      this._workletUrl = URL.createObjectURL(blob);
      await this._ctx.audioWorklet.addModule(this._workletUrl);

      this._source = this._ctx.createMediaStreamSource(this._stream);
      this._node = new AudioWorkletNode(this._ctx, MIC_WORKLET_NAME, {
        processorOptions: { targetSampleRate: MIC_INPUT_RATE, chunkMs: this._chunkMs }
      });
      this._node.port.onmessage = (event) => {
        const { pcm16, level } = event.data || {};
        if (pcm16) {
          const base64 = bytesToBase64(new Uint8Array(pcm16));
          this._onChunk(base64);
        }
        if (typeof level === 'number') this._onLevel(level);
      };

      // A worklet node with no path to destination can be throttled by some
      // engines; route through a silent gain so the graph stays "live"
      // without the user hearing their own mic back.
      this._silentGain = this._ctx.createGain();
      this._silentGain.gain.value = 0;
      this._source.connect(this._node);
      this._node.connect(this._silentGain);
      this._silentGain.connect(this._ctx.destination);

      this._active = true;
    } catch (err) {
      this._teardown();
      this._onError(new Error('The microphone pipeline failed to start. You can still type to the assistant.'));
    }
  }

  stop() {
    this._teardown();
  }

  _teardown() {
    this._active = false;
    try { this._node && (this._node.port.onmessage = null); } catch { /* noop */ }
    try { this._source && this._source.disconnect(); } catch { /* noop */ }
    try { this._node && this._node.disconnect(); } catch { /* noop */ }
    try { this._silentGain && this._silentGain.disconnect(); } catch { /* noop */ }
    try { this._stream && this._stream.getTracks().forEach(t => t.stop()); } catch { /* noop */ }
    try { this._ctx && this._ctx.close(); } catch { /* noop */ }
    if (this._workletUrl) { try { URL.revokeObjectURL(this._workletUrl); } catch { /* noop */ } }
    this._ctx = null; this._stream = null; this._source = null; this._node = null; this._silentGain = null; this._workletUrl = null;
  }
}

export function createMicCapture(options) {
  return new MicCapture(options);
}

// ---------------------------------------------------------------------------
// Playback queue: 24 kHz PCM16 mono chunks scheduled back-to-back, with an
// instant flush for barge-in / `interrupted`. `contextFactory` is injectable
// so the scheduling logic itself is unit-testable without a real AudioContext.
// ---------------------------------------------------------------------------
export class PlaybackQueue {
  constructor({ contextFactory, onStateChange } = {}) {
    this._contextFactory = contextFactory || (() => {
      const Ctx = getAudioContextCtor();
      if (!Ctx) throw new Error('Web Audio is not available in this browser.');
      return new Ctx();
    });
    this._onStateChange = onStateChange || (() => {});
    this._ctx = null;
    this._nextStartTime = 0;
    this._sources = new Set();
    this._muted = false;
    this._playing = false;
  }

  get isPlaying() { return this._playing; }
  get muted() { return this._muted; }

  setMuted(muted) {
    this._muted = !!muted;
    for (const { gain } of this._sources) {
      if (gain) gain.gain.value = this._muted ? 0 : 1;
    }
  }

  _ensureContext() {
    if (!this._ctx) {
      this._ctx = this._contextFactory();
      this._nextStartTime = this._ctx.currentTime;
    }
    return this._ctx;
  }

  async resume() {
    const ctx = this._ensureContext();
    if (ctx.state === 'suspended' && typeof ctx.resume === 'function') {
      try { await ctx.resume(); } catch { /* best effort — a later user gesture will retry */ }
    }
  }

  // base64Pcm16: raw PCM16 mono little-endian, base64-encoded (Gemini's
  // serverContent.modelTurn.parts[].inlineData.data for an audio part).
  enqueue(base64Pcm16, sampleRate = PLAYBACK_RATE) {
    if (!base64Pcm16) return null;
    const ctx = this._ensureContext();
    const bytes = base64ToBytes(base64Pcm16);
    const sampleCount = Math.floor(bytes.byteLength / 2);
    if (sampleCount <= 0) return null;
    const int16 = new Int16Array(bytes.buffer, bytes.byteOffset, sampleCount);
    const float32 = pcm16ToFloat32(int16);

    const buffer = ctx.createBuffer(1, float32.length, sampleRate);
    if (typeof buffer.copyToChannel === 'function') buffer.copyToChannel(float32, 0);
    else buffer.getChannelData(0).set(float32);

    const source = ctx.createBufferSource();
    source.buffer = buffer;
    const gain = ctx.createGain();
    gain.gain.value = this._muted ? 0 : 1;
    source.connect(gain);
    gain.connect(ctx.destination);

    const startAt = Math.max(this._nextStartTime, ctx.currentTime);
    source.start(startAt);
    this._nextStartTime = startAt + buffer.duration;

    const entry = { source, gain };
    this._sources.add(entry);
    this._setPlaying(true);
    source.onended = () => {
      this._sources.delete(entry);
      if (this._sources.size === 0) this._setPlaying(false);
    };

    return { startAt, duration: buffer.duration };
  }

  // Instant stop — used on `interrupted` (the user barged in) and when the
  // client drops/reconnects, so stale audio never plays over a new turn.
  flush() {
    for (const { source } of this._sources) {
      try { source.onended = null; source.stop(0); } catch { /* already stopped */ }
    }
    this._sources.clear();
    if (this._ctx) this._nextStartTime = this._ctx.currentTime;
    this._setPlaying(false);
  }

  close() {
    this.flush();
    try { this._ctx && this._ctx.close(); } catch { /* noop */ }
    this._ctx = null;
  }

  _setPlaying(playing) {
    if (playing !== this._playing) {
      this._playing = playing;
      this._onStateChange(playing);
    }
  }
}

export function createPlaybackQueue(options) {
  return new PlaybackQueue(options);
}
