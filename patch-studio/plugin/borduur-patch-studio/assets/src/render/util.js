/**
 * Patch Studio — render helpers (colour, canvas factory, small caches). No DOM access at import time.
 */

/** '#rgb' | '#rrggbb' → [r,g,b]; invalid → fallback. */
export function rgbOf(hex, fallback = [128, 128, 128]) {
  if (typeof hex !== 'string') return fallback.slice();
  let h = hex.trim().replace('#', '');
  if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
  if (!/^[0-9a-f]{6}$/i.test(h)) return fallback.slice();
  const n = parseInt(h, 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

export function hexOf(rgb) {
  return '#' + rgb.map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('');
}

/** Perceived lightness 0..255 (fast, Rec.601). */
export function lum(hexOrRgb) {
  const c = Array.isArray(hexOrRgb) ? hexOrRgb : rgbOf(hexOrRgb);
  return 0.299 * c[0] + 0.587 * c[1] + 0.114 * c[2];
}

/** Lighten (amt > 0) or darken (amt < 0) by mixing with white/black, amt in -1..1. */
export function shade(hex, amt) {
  const c = rgbOf(hex);
  const t = amt < 0 ? 0 : 255, p = Math.abs(amt);
  return hexOf(c.map((v) => v + (t - v) * p));
}

export function mix(hexA, hexB, t) {
  const a = rgbOf(hexA), b = rgbOf(hexB);
  return hexOf(a.map((v, i) => v + (b[i] - v) * t));
}

export function rgba(hex, a) {
  const c = rgbOf(hex);
  return `rgba(${c[0]},${c[1]},${c[2]},${a})`;
}

/** Deterministic 32-bit hash → 0..1 (no Math.random: renders must be reproducible). */
export function hash01(n) {
  let x = (n | 0) ^ 0x9e3779b9;
  x = Math.imul(x ^ (x >>> 16), 0x85ebca6b);
  x = Math.imul(x ^ (x >>> 13), 0xc2b2ae35);
  x ^= x >>> 16;
  return (x >>> 0) / 4294967296;
}

/** Seeded PRNG (mulberry32). */
export function prng(seed) {
  let a = seed >>> 0;
  return function () {
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

let canvasFactory = null;
/** Allow tests / special hosts to inject a canvas factory (w,h) → canvas. */
export function setCanvasFactory(fn) { canvasFactory = fn; }

/** Create a 2D-capable canvas: OffscreenCanvas when available, else a detached <canvas>. */
export function makeCanvas(w, h) {
  w = Math.max(1, Math.ceil(w)); h = Math.max(1, Math.ceil(h));
  if (canvasFactory) return canvasFactory(w, h);
  if (typeof OffscreenCanvas !== 'undefined') {
    try {
      const c = new OffscreenCanvas(w, h);
      if (c.getContext('2d')) return c;
    } catch (e) { /* fall through */ }
  }
  if (typeof document !== 'undefined') {
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    return c;
  }
  throw new Error('bps-render: no canvas available');
}

export function ctx2d(canvas, opts) {
  return canvas.getContext('2d', opts);
}

/** Tiny LRU map. */
export class Lru {
  constructor(max) { this.max = max; this.m = new Map(); }
  get(k) {
    if (!this.m.has(k)) return undefined;
    const v = this.m.get(k);
    this.m.delete(k); this.m.set(k, v);
    return v;
  }
  set(k, v) {
    if (this.m.has(k)) this.m.delete(k);
    this.m.set(k, v);
    while (this.m.size > this.max) this.m.delete(this.m.keys().next().value);
    return v;
  }
  clear() { this.m.clear(); }
}

/** Stable object identity id (for cache keys). */
const ids = new WeakMap();
let nextId = 1;
export function idOf(obj) {
  if (!obj || (typeof obj !== 'object' && typeof obj !== 'function')) return 0;
  let v = ids.get(obj);
  if (!v) { v = nextId++; ids.set(obj, v); }
  return v;
}

export function now() {
  return typeof performance !== 'undefined' && performance.now ? performance.now() : Date.now();
}

export function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
