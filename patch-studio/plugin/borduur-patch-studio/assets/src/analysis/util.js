/**
 * Patch Studio — analysis: low-level raster helpers (pure, no DOM; runs in Node, Worker and main thread).
 * All images are "ImageDataLike": {width, height, data: Uint8ClampedArray RGBA}.
 */
import { rgbToLab } from '../shared/color-math.js';

export const SQRT2 = Math.SQRT2;
export const INF = 1e9;

/** Fast Euclidean norm of 2 or 3 components (Math.hypot is slow in V8 hot loops). */
export const hyp = (a, b, c = 0) => Math.sqrt(a * a + b * b + c * c);
export const clamp = (v, lo, hi) => (v < lo ? lo : v > hi ? hi : v);
export const round3 = (v) => Math.round(v * 1000) / 1000;
export const now = () => (typeof performance !== 'undefined' && performance.now ? performance.now() : Date.now());
/** Yield to the event loop (keeps the main thread responsive in the fallback path, lets the worker see 'cancel'). */
export const pause = () => new Promise((r) => setTimeout(r, 0));

/** Create an empty ImageDataLike. */
export function makeImage(w, h) {
  return { width: w, height: h, data: new Uint8ClampedArray(w * h * 4) };
}

/**
 * Area-average resample of a source rectangle (source px, may be fractional / outside the image) to dw×dh.
 * Alpha-weighted (premultiplied) averaging so transparent pixels do not darken colours. Outside = transparent.
 */
export function resample(img, sx, sy, sw, sh, dw, dh) {
  const W = img.width, H = img.height, d = img.data;
  const out = new Uint8ClampedArray(dw * dh * 4);
  const spans = (s0, len, dn, max) => {
    const f = len / dn, idx = [], wts = [], start = new Int32Array(dn + 1);
    for (let o = 0; o < dn; o++) {
      start[o] = idx.length;
      const a = s0 + o * f, b = a + f;
      for (let i = Math.floor(a); i < Math.ceil(b); i++) {
        const w = Math.min(b, i + 1) - Math.max(a, i);
        if (w <= 1e-6) continue;
        idx.push(i >= 0 && i < max ? i : -1);
        wts.push(w);
      }
    }
    start[dn] = idx.length;
    return { idx: Int32Array.from(idx), wts: Float32Array.from(wts), start };
  };
  const X = spans(sx, sw, dw, W), Y = spans(sy, sh, dh, H);
  for (let oy = 0; oy < dh; oy++) {
    for (let ox = 0; ox < dw; ox++) {
      let r = 0, g = 0, b = 0, a = 0, wsum = 0;
      for (let j = Y.start[oy]; j < Y.start[oy + 1]; j++) {
        const iy = Y.idx[j], wy = Y.wts[j];
        for (let i = X.start[ox]; i < X.start[ox + 1]; i++) {
          const w = wy * X.wts[i];
          wsum += w;
          const ix = X.idx[i];
          if (iy < 0 || ix < 0) continue;
          const k = (iy * W + ix) * 4, al = d[k + 3] * w;
          r += d[k] * al; g += d[k + 1] * al; b += d[k + 2] * al; a += al;
        }
      }
      const o = (oy * dw + ox) * 4;
      if (a > 0) { out[o] = r / a; out[o + 1] = g / a; out[o + 2] = b / a; }
      out[o + 3] = wsum > 0 ? a / wsum : 0;
    }
  }
  return { width: dw, height: dh, data: out };
}

/** Bilinear RGBA sample at (x,y) in pixel-centre coordinates; writes into out[o..o+3]. Outside = transparent. */
export function sampleBilinear(img, x, y, out, o) {
  const W = img.width, H = img.height, d = img.data;
  x -= 0.5; y -= 0.5;
  const x0 = Math.floor(x), y0 = Math.floor(y), fx = x - x0, fy = y - y0;
  let r = 0, g = 0, b = 0, a = 0;
  for (let j = 0; j < 2; j++) {
    const yy = y0 + j; const wy = j ? fy : 1 - fy;
    if (yy < 0 || yy >= H || wy === 0) continue;
    for (let i = 0; i < 2; i++) {
      const xx = x0 + i; const w = (i ? fx : 1 - fx) * wy;
      if (xx < 0 || xx >= W || w === 0) continue;
      const k = (yy * W + xx) * 4, al = d[k + 3] * w;
      r += d[k] * al; g += d[k + 1] * al; b += d[k + 2] * al; a += al;
    }
  }
  if (a > 0) { out[o] = r / a; out[o + 1] = g / a; out[o + 2] = b / a; } else { out[o] = out[o + 1] = out[o + 2] = 0; }
  out[o + 3] = a;
}

/**
 * Open-addressing hash map int24 (RGB) → int, on typed arrays (a JS Map is far slower and allocates).
 * When the table is 70 % full new keys are simply not cached (callers then compute directly).
 */
export class ColorCache {
  constructor(bits = 17) {
    this.size = 1 << bits; this.mask = this.size - 1; this.count = 0;
    this.keys = new Int32Array(this.size).fill(-1); this.vals = new Int32Array(this.size);
  }
  get(key) {
    let h = (Math.imul(key, 0x9e3779b1) >>> 12) & this.mask;
    for (;;) {
      const k = this.keys[h];
      if (k === key) return this.vals[h];
      if (k === -1) return -1;
      h = (h + 1) & this.mask;
    }
  }
  set(key, v) {
    if (this.count > this.size * 0.7) return;
    let h = (Math.imul(key, 0x9e3779b1) >>> 12) & this.mask;
    while (this.keys[h] !== -1 && this.keys[h] !== key) h = (h + 1) & this.mask;
    if (this.keys[h] === -1) this.count++;
    this.keys[h] = key; this.vals[h] = v;
  }
}

/** Lab image (Float32Array, 3 per pixel). Unique colours are converted once. */
export function labImage(img) {
  const n = img.width * img.height, d = img.data, lab = new Float32Array(n * 3);
  const cache = new ColorCache(18), store = [];
  for (let p = 0; p < n; p++) {
    const i = p * 4, key = (d[i] << 16) | (d[i + 1] << 8) | d[i + 2];
    const j = cache.get(key);
    let L, A, B;
    if (j >= 0) { L = store[j * 3]; A = store[j * 3 + 1]; B = store[j * 3 + 2]; } else {
      const v = rgbToLab(d[i], d[i + 1], d[i + 2]);
      L = v[0]; A = v[1]; B = v[2];
      cache.set(key, store.length / 3);
      store.push(L, A, B);
    }
    lab[p * 3] = L; lab[p * 3 + 1] = A; lab[p * 3 + 2] = B;
  }
  return lab;
}

export function median(arr) {
  if (!arr.length) return 0;
  const s = Float64Array.from(arr).sort();
  return s[s.length >> 1];
}

export function percentile(arr, q) {
  if (!arr.length) return 0;
  const s = Float64Array.from(arr).sort();
  return s[Math.min(s.length - 1, Math.max(0, Math.floor(q * (s.length - 1) + 0.5)))];
}

/** Weighted low percentile used by demo-v2 for line widths: each value weighs as much as itself. */
export function weightedLowPercentile(values, q, minCount = 30) {
  if (values.length < minCount) return null;
  const s = Float64Array.from(values).sort();
  let tot = 0;
  for (const v of s) tot += v;
  let c = 0;
  for (const v of s) { c += v; if (c >= tot * q) return v; }
  return s[s.length - 1];
}

/**
 * Chamfer distance transform inside label regions. L: Int16Array labels (-1 = no region, D=0).
 * A pixel's region ends where the neighbour has another label; with edgeIsBorder the crop edge also ends it.
 */
export function chamfer(L, W, H, edgeIsBorder = true) {
  const n = W * H, D = new Float32Array(n);
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      const p = y * W + x, l = L[p];
      if (l < 0) { D[p] = 0; continue; }
      let edge = false;
      if (x === 0 || y === 0 || x === W - 1 || y === H - 1) {
        if (edgeIsBorder) edge = true;
      }
      if (!edge) {
        edge = (x > 0 && L[p - 1] !== l) || (x < W - 1 && L[p + 1] !== l) || (y > 0 && L[p - W] !== l) || (y < H - 1 && L[p + W] !== l);
      }
      D[p] = edge ? 1 : INF;
    }
  }
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      const p = y * W + x, l = L[p];
      if (l < 0) continue;
      let v = D[p];
      if (x > 0 && L[p - 1] === l && D[p - 1] + 1 < v) v = D[p - 1] + 1;
      if (y > 0) {
        if (L[p - W] === l && D[p - W] + 1 < v) v = D[p - W] + 1;
        if (x > 0 && L[p - W - 1] === l && D[p - W - 1] + SQRT2 < v) v = D[p - W - 1] + SQRT2;
        if (x < W - 1 && L[p - W + 1] === l && D[p - W + 1] + SQRT2 < v) v = D[p - W + 1] + SQRT2;
      }
      D[p] = v;
    }
  }
  for (let y = H - 1; y >= 0; y--) {
    for (let x = W - 1; x >= 0; x--) {
      const p = y * W + x, l = L[p];
      if (l < 0) continue;
      let v = D[p];
      if (x < W - 1 && L[p + 1] === l && D[p + 1] + 1 < v) v = D[p + 1] + 1;
      if (y < H - 1) {
        if (L[p + W] === l && D[p + W] + 1 < v) v = D[p + W] + 1;
        if (x < W - 1 && L[p + W + 1] === l && D[p + W + 1] + SQRT2 < v) v = D[p + W + 1] + SQRT2;
        if (x > 0 && L[p + W - 1] === l && D[p + W - 1] + SQRT2 < v) v = D[p + W - 1] + SQRT2;
      }
      D[p] = v;
    }
  }
  return D;
}

/** Is p a ridge (medial-axis) pixel: no 8-neighbour of the same label is further from the edge. */
export function isRidge(L, D, W, H, p) {
  const l = L[p];
  if (l < 0 || D[p] >= INF) return false;
  const x = p % W, y = (p - x) / W;
  for (let dy = -1; dy <= 1; dy++) {
    const yy = y + dy;
    if (yy < 0 || yy >= H) continue;
    for (let dx = -1; dx <= 1; dx++) {
      if (!dx && !dy) continue;
      const xx = x + dx;
      if (xx < 0 || xx >= W) continue;
      const q = yy * W + xx;
      if (L[q] === l && D[q] > D[p]) return false;
    }
  }
  return true;
}

/**
 * Connected components of equal (non-negative) labels.
 * @returns {{id:Int32Array, count:number, size:Int32Array, label:Int16Array, minX,minY,maxX,maxY:Int32Array, sx,sy:Float64Array}}
 */
export function components(L, W, H, conn8 = true) {
  const n = W * H, id = new Int32Array(n).fill(-1);
  const size = [], label = [], minX = [], minY = [], maxX = [], maxY = [], sx = [], sy = [];
  const stack = new Int32Array(n);
  for (let p0 = 0; p0 < n; p0++) {
    if (L[p0] < 0 || id[p0] >= 0) continue;
    const c = size.length, l = L[p0];
    let sp = 0, sz = 0, x0 = W, y0 = H, x1 = -1, y1 = -1, ax = 0, ay = 0;
    stack[sp++] = p0; id[p0] = c;
    while (sp) {
      const p = stack[--sp], x = p % W, y = (p - x) / W;
      sz++; ax += x; ay += y;
      if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y;
      for (let dy = -1; dy <= 1; dy++) {
        const yy = y + dy;
        if (yy < 0 || yy >= H) continue;
        for (let dx = -1; dx <= 1; dx++) {
          if ((!dx && !dy) || (!conn8 && dx && dy)) continue;
          const xx = x + dx;
          if (xx < 0 || xx >= W) continue;
          const q = yy * W + xx;
          if (id[q] < 0 && L[q] === l) { id[q] = c; stack[sp++] = q; }
        }
      }
    }
    size.push(sz); label.push(l); minX.push(x0); minY.push(y0); maxX.push(x1); maxY.push(y1); sx.push(ax); sy.push(ay);
  }
  return {
    id, count: size.length, size: Int32Array.from(size), label: Int16Array.from(label),
    minX: Int32Array.from(minX), minY: Int32Array.from(minY), maxX: Int32Array.from(maxX), maxY: Int32Array.from(maxY),
    sx: Float64Array.from(sx), sy: Float64Array.from(sy),
  };
}

/** Pixel index lists per component (CSR layout). */
export function componentPixels(comp, n) {
  const off = new Int32Array(comp.count + 1);
  for (let p = 0; p < n; p++) if (comp.id[p] >= 0) off[comp.id[p] + 1]++;
  for (let c = 0; c < comp.count; c++) off[c + 1] += off[c];
  const fill = off.slice(0, comp.count), px = new Int32Array(off[comp.count]);
  for (let p = 0; p < n; p++) { const c = comp.id[p]; if (c >= 0) px[fill[c]++] = p; }
  return { off, px };
}

/** 3×3 median filter per RGB channel (alpha kept). Used for mock-ups (fabric texture) before fine-line measurement. */
export function median3(img) {
  const W = img.width, H = img.height, d = img.data, out = new Uint8ClampedArray(d);
  const v = new Uint8Array(9);
  for (let y = 1; y < H - 1; y++) {
    for (let x = 1; x < W - 1; x++) {
      for (let c = 0; c < 3; c++) {
        let k = 0;
        for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) v[k++] = d[((y + dy) * W + x + dx) * 4 + c];
        v.sort();
        out[(y * W + x) * 4 + c] = v[4];
      }
    }
  }
  return { width: W, height: H, data: out };
}

/** Mini deterministic PRNG (LCG) for k-means++ seeding. */
export function lcg(seed) {
  let s = (seed >>> 0) || 1;
  return () => (s = (Math.imul(s, 1103515245) + 12345) >>> 0) / 4294967296;
}
