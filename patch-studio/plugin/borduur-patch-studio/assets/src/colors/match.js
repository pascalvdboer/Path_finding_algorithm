/**
 * Patch Studio — colors: thread preparation and ΔE2000 matching (SPEC §7.3, research 07 §5).
 * Pure (no DOM): runs in the main thread, a worker and Node.
 */
import { hexToLab, labToHex, deltaE2000, isWhiteLab, isBlackLab } from '../shared/color-math.js';

/** Quality bands on ΔE2000 (07 §5.2). The number itself is never shown to customers. */
export const QUALITY_LIMITS = Object.freeze({ very_good: 2, good: 5, approx: 10 });

const HEX_RE = /^#?(?:[0-9a-f]{3}|[0-9a-f]{6})$/i;

/** ΔE2000 → 'very_good' | 'good' | 'approx' | 'none' */
export function qualityFor(deltaE) {
  if (!Number.isFinite(deltaE)) return 'none';
  if (deltaE < QUALITY_LIMITS.very_good) return 'very_good';
  if (deltaE < QUALITY_LIMITS.good) return 'good';
  if (deltaE < QUALITY_LIMITS.approx) return 'approx';
  return 'none';
}

/** Normalise '#RGB' / 'RRGGBB' / '#RRGGBB' → '#rrggbb' or null when invalid. */
export function normalizeHex(hex) {
  if (typeof hex !== 'string' || !HEX_RE.test(hex.trim())) return null;
  let h = hex.trim().replace('#', '').toLowerCase();
  if (h.length === 3) h = h.split('').map((c) => c + c).join('');
  return '#' + h;
}

/** Accepts a hex string or an [L,a,b] array; returns [L,a,b] or null. */
export function toLab(hexOrLab) {
  if (Array.isArray(hexOrLab) || (hexOrLab && typeof hexOrLab.length === 'number' && typeof hexOrLab !== 'string')) {
    const l = [Number(hexOrLab[0]), Number(hexOrLab[1]), Number(hexOrLab[2])];
    return l.every(Number.isFinite) ? l : null;
  }
  const h = normalizeHex(hexOrLab);
  return h ? hexToLab(h) : null;
}

const cache = new WeakMap();

/**
 * Thread[] → PreparedThread[] (Thread + lab), cached per input array.
 * Invalid entries (no code or bad hex) and `stock:false` threads are skipped; hex is normalised.
 * @param {import('../shared/types.js').Thread[]} threads
 */
export function prepareThreads(threads) {
  if (!Array.isArray(threads)) return [];
  const hit = cache.get(threads);
  if (hit) return hit;
  const out = [];
  const seen = new Set();
  for (const t of threads) {
    if (!t || t.code == null || t.stock === false) continue;
    const code = String(t.code);
    const hex = normalizeHex(t.hex);
    if (!hex || seen.has(code)) continue;
    seen.add(code);
    out.push({ ...t, code, hex, lab: hexToLab(hex) });
  }
  cache.set(threads, out);
  return out;
}

/**
 * Thread by code (works on Thread[] and PreparedThread[]).
 * @returns {import('../shared/types.js').Thread|null}
 */
export function threadByCode(code, threads) {
  if (code == null || !Array.isArray(threads)) return null;
  const c = String(code);
  for (const t of threads) if (t && String(t.code) === c) return t;
  return null;
}

function ensurePrepared(prepared) {
  if (!Array.isArray(prepared)) return [];
  // Accept raw Thread[] too (convenience); prepareThreads is cached and idempotent for prepared arrays.
  return prepared.length && !prepared[0].lab ? prepareThreads(prepared) : prepared;
}

function thresholds(config) {
  const a = (config && config.analysis) || {};
  const w = a.white || {}, b = a.black || {};
  return { wL: w.L ?? 92, wC: w.C ?? 6, bL: b.L ?? 15, bC: b.C ?? 8 };
}

/** The configured standard black/white thread, if present in `prepared`. */
export function specialThread(kind, prepared, config) {
  const code = config && config.threadSpecial ? config.threadSpecial[kind] : null;
  const p = ensurePrepared(prepared);
  let t = threadByCode(code, p);
  if (!t) {
    // Fallback: darkest / lightest neutral in the palette.
    let best = null, bestL = kind === 'black' ? Infinity : -Infinity;
    for (const x of p) {
      if (Math.hypot(x.lab[1], x.lab[2]) > 10) continue;
      if (kind === 'black' ? x.lab[0] < bestL : x.lab[0] > bestL) { best = x; bestL = x.lab[0]; }
    }
    t = best;
  }
  return t;
}

/** n nearest threads by ΔE2000 (sorted ascending, ties by code for determinism). */
export function nearestThreads(lab, prepared, n = 3, kL = 1) {
  const p = ensurePrepared(prepared);
  const scored = new Array(p.length);
  for (let i = 0; i < p.length; i++) scored[i] = { t: p[i], d: deltaE2000(lab, p[i].lab, kL) };
  scored.sort((x, y) => x.d - y.d || (x.t.code < y.t.code ? -1 : 1));
  return scored.slice(0, Math.max(0, n));
}

/**
 * Match one colour to the nearest thread.
 * Near-black / near-white design colours map to the configured standard threads (threadSpecial), because the site
 * swatches for black/white are greyish (07 §5.2) — that is a "very good" match in reality.
 * @param {string|number[]} hexOrLab
 * @param {import('../shared/types.js').PreparedThread[]} prepared
 * @param {Object} config  public config (uses analysis.white/black, threadSpecial)
 * @param {number} [n=3]   number of candidates considered (1 best + n-1 alternatives)
 * @param {{kL?:number}} [opts]  kL=2 for photos of real fabric
 * @returns {import('../shared/types.js').ThreadMatch}
 */
export function matchColor(hexOrLab, prepared, config, n = 3, opts = {}) {
  const p = ensurePrepared(prepared);
  const lab = toLab(hexOrLab);
  const kL = opts.kL || 1;
  const nAlt = Math.max(0, (n | 0) - 1);
  if (!lab || !p.length) return { thread: null, deltaE: Infinity, quality: 'none', alternatives: [], special: null };
  const th = thresholds(config);
  let special = null;
  if (isBlackLab(lab, th.bL, th.bC)) special = 'black';
  else if (isWhiteLab(lab, th.wL, th.wC)) special = 'white';
  if (special) {
    const t = specialThread(special, p, config);
    if (t) {
      const alts = nearestThreads(lab, p, nAlt + 1, kL).filter((x) => x.t !== t).slice(0, nAlt).map((x) => x.t);
      return { thread: t, deltaE: deltaE2000(lab, t.lab, kL), quality: 'very_good', alternatives: alts, special };
    }
  }
  const top = nearestThreads(lab, p, nAlt + 1, kL);
  return {
    thread: top[0].t,
    deltaE: top[0].d,
    quality: qualityFor(top[0].d),
    alternatives: top.slice(1).map((x) => x.t),
    special: null,
  };
}

/**
 * Match every palette colour (same order as `palette`).
 * @param {{hex?:string, lab?:number[]}[]} palette
 */
export function matchPalette(palette, prepared, config, opts = {}) {
  if (!Array.isArray(palette)) return [];
  const p = ensurePrepared(prepared);
  return palette.map((c) => matchColor(c && (c.lab || c.hex), p, config, 3, opts));
}

/** Hex of a colour given as hex or Lab. */
export function hexOf(hexOrLab) {
  if (typeof hexOrLab === 'string') return normalizeHex(hexOrLab);
  const lab = toLab(hexOrLab);
  return lab ? labToHex(lab) : null;
}
