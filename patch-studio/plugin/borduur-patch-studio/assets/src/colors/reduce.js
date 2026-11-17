/**
 * Patch Studio — colors: reduce a design palette to at most N thread colours (SPEC §7.3).
 *
 * Order of operations (deterministic):
 *  1. Colours that land on the same thread are merged (physically they ARE one thread).
 *  2. While there are more than N colours: merge the closest pair (ΔE2000 between representative colours) under
 *     rising thresholds `config.analysis.embroideryMergeSteps` (default 7, 10, 14, 18). Thin-line colours are
 *     protected in a first round and only merged in a second round if still needed.
 *  3. Still too many: forced merges of the closest pairs (flagged `forced`, the advice engine can warn about it).
 * The surviving colour of a merge is the one marked `keep`, otherwise the one with the larger share; its own colour
 * (and therefore its thread) is kept, so brand colours do not drift towards muddy averages.
 */
import { deltaE2000 } from '../shared/color-math.js';
import { prepareThreads, matchColor, normalizeHex, toLab, hexOf } from './match.js';

const DEFAULT_STEPS = [7, 10, 14, 18];

/**
 * @param {import('../shared/types.js').PaletteColor[]} palette
 * @param {number} maxColors
 * @param {{prepared?:Object[], config?:Object, kL?:number, keep?:number[]}} [opts]
 *        keep = palette indices that must survive as their own colour if at all possible (e.g. text colours)
 * @returns {import('../shared/types.js').ReducedPalette & {forced:boolean, maxDeltaE:number, maxColors:number}}
 */
export function reducePalette(palette, maxColors, { prepared = [], config = {}, kL = 1, keep = [] } = {}) {
  const list = Array.isArray(palette) ? palette : [];
  const p = Array.isArray(prepared) && prepared.length && !prepared[0].lab ? prepareThreads(prepared) : prepared || [];
  const max = Math.max(1, Math.floor(Number(maxColors) || 1));
  const keepSet = new Set((keep || []).map(Number));
  const steps = (((config && config.analysis) || {}).embroideryMergeSteps || DEFAULT_STEPS)
    .map(Number).filter((x) => x > 0).sort((a, b) => a - b);

  const labs = list.map((c) => toLab(c && (c.lab || c.hex)) || [0, 0, 0]);
  const matches = list.map((c, i) => matchColor(labs[i], p, config, 3, { kL }));

  /** @type {{rep:number, sources:number[], share:number, thin:boolean, keep:boolean, alive:boolean, parent:number}[]} */
  const cl = list.map((c, i) => ({
    rep: i,
    sources: [i],
    share: Math.max(0, Number(c && c.share) || 0),
    thin: !!(c && c.thin),
    keep: keepSet.has(i),
    alive: true,
    parent: i,
  }));
  const raw = []; // {from:number[], survivor:number, deltaE, reason, fromCode, intoCode}
  let alive = cl.length;

  const dist = (a, b) => deltaE2000(labs[cl[a].rep], labs[cl[b].rep], kL);
  const survivorOf = (a, b) => {
    const A = cl[a], B = cl[b];
    if (A.keep !== B.keep) return A.keep ? a : b;
    if (A.share !== B.share) return A.share > B.share ? a : b;
    return A.rep < B.rep ? a : b;
  };
  const merge = (a, b, reason, d) => {
    const s = survivorOf(a, b), o = s === a ? b : a;
    const S = cl[s], O = cl[o];
    raw.push({
      from: O.sources.slice().sort((x, y) => x - y),
      survivor: s,
      deltaE: d,
      reason,
      fromThread: matches[O.rep].thread || null,
      fromCode: matches[O.rep].thread ? matches[O.rep].thread.code : null,
      intoCode: matches[S.rep].thread ? matches[S.rep].thread.code : null,
    });
    S.sources = S.sources.concat(O.sources);
    S.share += O.share;
    S.thin = S.thin && O.thin;
    S.keep = S.keep || O.keep;
    O.alive = false;
    O.parent = s;
    alive--;
  };

  // 1. Same thread → one colour.
  const byCode = new Map();
  cl.forEach((c, i) => {
    const t = matches[i].thread;
    if (!t) return;
    if (!byCode.has(t.code)) byCode.set(t.code, []);
    byCode.get(t.code).push(i);
  });
  for (const group of byCode.values()) {
    if (group.length < 2) continue;
    group.sort((a, b) => cl[b].share - cl[a].share || a - b);
    let head = group[0];
    for (let g = 1; g < group.length; g++) {
      const d = dist(head, group[g]);
      merge(head, group[g], 'same_thread', d);
      if (!cl[head].alive) head = group[g];
    }
  }

  // Closest eligible pair under a limit.
  const closest = (limit, protectThin, allowKeepPair) => {
    let best = null;
    for (let a = 0; a < cl.length; a++) {
      if (!cl[a].alive) continue;
      for (let b = a + 1; b < cl.length; b++) {
        if (!cl[b].alive) continue;
        if (!allowKeepPair && cl[a].keep && cl[b].keep) continue;
        if (protectThin && (cl[a].thin || cl[b].thin)) continue;
        const d = dist(a, b);
        if (d >= limit) continue;
        // Tie-break: prefer absorbing the smaller share (less visible change).
        const w = Math.min(cl[a].share, cl[b].share);
        if (!best || d < best.d - 1e-9 || (Math.abs(d - best.d) <= 1e-9 && w < best.w)) best = { a, b, d, w };
      }
    }
    return best;
  };

  // 2. Rising thresholds, thin lines protected first.
  for (const protectThin of [true, false]) {
    for (const step of steps) {
      while (alive > max) {
        const pair = closest(step, protectThin, false);
        if (!pair) break;
        merge(pair.a, pair.b, 'similar', pair.d);
      }
      if (alive <= max) break;
    }
    if (alive <= max) break;
  }

  // 3. Forced merges (closest first; two keep colours only as a last resort).
  let forced = false;
  while (alive > max) {
    const pair = closest(Infinity, false, false) || closest(Infinity, false, true);
    if (!pair) break;
    forced = true;
    merge(pair.a, pair.b, 'forced', pair.d);
  }

  // Build the result.
  const root = (i) => { while (cl[i].parent !== i) i = cl[i].parent; return i; };
  const survivors = cl.map((c, i) => i).filter((i) => cl[i].alive)
    .sort((a, b) => cl[b].share - cl[a].share || cl[a].rep - cl[b].rep);
  const indexOf = new Map(survivors.map((ci, k) => [ci, k]));
  const colors = survivors.map((ci) => {
    const c = cl[ci];
    const src = list[c.rep] || {};
    return {
      hex: normalizeHex(src.hex) || hexOf(labs[c.rep]),
      lab: labs[c.rep].slice(),
      share: c.share,
      sources: c.sources.slice().sort((x, y) => x - y),
      thread: matches[c.rep],
      thin: c.thin,
      keep: c.keep,
    };
  });
  const map = list.map((_, i) => indexOf.get(root(i)));
  const merges = raw.map((m) => ({
    from: m.from,
    into: indexOf.get(root(m.survivor)),
    deltaE: m.deltaE,
    reason: m.reason,
    fromThread: m.fromThread,
    fromCode: m.fromCode,
    intoCode: m.intoCode,
  }));
  return {
    colors,
    map,
    merges,
    k: colors.length,
    forced,
    maxDeltaE: merges.reduce((x, m) => Math.max(x, m.reason === 'same_thread' ? 0 : m.deltaE), 0),
    maxColors: max,
  };
}

/**
 * Hex per palette index after reduction (for the "Toon wat er verandert" preview); `null` → index not in palette.
 * @param {ReturnType<typeof reducePalette>} reduced
 * @param {'design'|'thread'} [mode='thread']  thread = screen colour of the matched thread
 */
export function reducedHexes(reduced, mode = 'thread') {
  if (!reduced || !Array.isArray(reduced.map)) return [];
  return reduced.map.map((ci) => {
    const c = reduced.colors[ci];
    if (!c) return null;
    return mode === 'thread' && c.thread && c.thread.thread ? c.thread.thread.hex : c.hex;
  });
}

/** Quick count of distinct threads a palette needs without merging similar colours. */
export function distinctThreadCount(palette, prepared, config) {
  const codes = new Set();
  for (const c of palette || []) {
    const m = matchColor(c && (c.lab || c.hex), prepared, config, 1);
    if (m.thread) codes.add(m.thread.code);
  }
  return codes.size;
}
