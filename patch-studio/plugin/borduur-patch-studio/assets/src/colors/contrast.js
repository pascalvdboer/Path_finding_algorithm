/**
 * Patch Studio — colors: contrast against the fabric, automatic fabric choice and edge colour (SPEC §7.3).
 * ΔE2000 drives the decision (thread on twill is about perceived difference, not text legibility);
 * the WCAG ratio is reported as extra information.
 */
import { deltaE2000, contrastRatio, hexToLab } from '../shared/color-math.js';
import { prepareThreads, matchColor, normalizeHex, toLab, hexOf, specialThread } from './match.js';

/** Weight floor for thin lines / text colours: small in area, but they must stay visible. */
const THIN_WEIGHT = 0.05;
/** ΔE above which more difference does not matter any more for the fabric score. */
const SCORE_CAP = 45;

function contrastCfg(config) {
  const c = (config && config.advice && config.advice.contrast) || {};
  return { badDE: c.badDE ?? 8, lowDE: c.lowDE ?? 15, minShareForBad: c.minShareForBad ?? 0.05 };
}

function hexOfItem(c) {
  if (!c) return null;
  return normalizeHex(c.hex) || hexOf(c.lab);
}

/**
 * Contrast of each colour against the fabric.
 * @param {{hex:string, share?:number, index?:number, thin?:boolean}[]} colors  `index` overrides the position (-1 for a text layer)
 * @param {string} fabricHex
 * @param {Object} config  uses advice.contrast {badDE, lowDE, minShareForBad}
 * @returns {(import('../shared/types.js').ContrastCheck & {share:number})[]}
 */
export function contrastAgainst(colors, fabricHex, config) {
  const fab = normalizeHex(fabricHex);
  if (!fab || !Array.isArray(colors)) return [];
  const fLab = hexToLab(fab);
  const { badDE, lowDE, minShareForBad } = contrastCfg(config);
  const out = [];
  colors.forEach((c, i) => {
    const hex = hexOfItem(c);
    if (!hex) return;
    const share = Math.max(0, Number(c.share) || 0);
    const dE = deltaE2000(hexToLab(hex), fLab);
    const index = Number.isInteger(c.index) ? c.index : i;
    // A text layer (index -1) is always "important": treat it as if it had the minimum share for "bad".
    const important = share >= minShareForBad || index === -1 || c.important === true;
    let level = 'ok';
    if (dE < badDE && important) level = 'bad';
    else if (dE < lowDE) level = 'low';
    out.push({ index, hex, deltaE: dE, ratio: contrastRatio(hex, fab), level, share });
  });
  return out;
}

/** Worst level in a list of checks: 'bad' > 'low' > 'ok'. */
export function worstContrast(checks) {
  let w = 'ok';
  for (const c of checks || []) {
    if (c.level === 'bad') return 'bad';
    if (c.level === 'low') w = 'low';
  }
  return w;
}

function usableFabrics(fabrics) {
  return (Array.isArray(fabrics) ? fabrics : [])
    .filter((f) => f && f.enabled !== false && f.stock !== false && normalizeHex(f.hex))
    .map((f) => ({ f, hex: normalizeHex(f.hex), lab: hexToLab(normalizeHex(f.hex)) }));
}

/**
 * Choose the fabric (twill/background) colour.
 * - background: the design's own background when it is kept as patch background (string hex or
 *   AnalysisResult.background {type, hex, keep}) → nearest fabric, reason 'background'.
 * - otherwise: best weighted contrast for all colours (ΔE2000, weighted on share, thin lines count for at least 5 %),
 *   never a fabric (nearly) equal to the main colour → reason 'contrast'.
 * - no colours → first available fabric, reason 'default'.
 * @param {{hex:string, share?:number, thin?:boolean}[]} colors
 * @param {{id:string, hex:string, label?:Object, enabled?:boolean}[]} fabrics
 * @param {Object} config
 * @param {{background?: string|{type?:string, hex?:string|null, keep?:boolean}|null}} [opts]
 * @returns {{id:string, hex:string, reason:'background'|'contrast'|'default', score?:number, deltaE?:number}|null}
 */
export function pickFabric(colors, fabrics, config, { background = null } = {}) {
  const fs = usableFabrics(fabrics);
  if (!fs.length) return null;

  let bgHex = null;
  if (typeof background === 'string') bgHex = normalizeHex(background);
  else if (background && background.keep && background.hex) bgHex = normalizeHex(background.hex);
  if (bgHex) {
    const bLab = hexToLab(bgHex);
    let best = null;
    for (const x of fs) {
      const d = deltaE2000(bLab, x.lab);
      if (!best || d < best.d) best = { x, d };
    }
    return { id: best.x.f.id, hex: best.x.hex, reason: 'background', deltaE: best.d };
  }

  const items = (Array.isArray(colors) ? colors : [])
    .map((c) => ({ hex: hexOfItem(c), share: Math.max(0, Number(c && c.share) || 0), thin: !!(c && c.thin) }))
    .filter((c) => c.hex);
  if (!items.length) return { id: fs[0].f.id, hex: fs[0].hex, reason: 'default' };

  const { badDE, lowDE } = contrastCfg(config);
  const weights = items.map((c) => Math.max(c.share, c.thin ? THIN_WEIGHT : 0, 1e-3));
  const wSum = weights.reduce((a, b) => a + b, 0);
  const labs = items.map((c) => hexToLab(c.hex));
  let main = 0;
  items.forEach((c, i) => { if (c.share > items[main].share) main = i; });

  let best = null;
  fs.forEach((x, order) => {
    let score = 0;
    for (let i = 0; i < items.length; i++) {
      const d = deltaE2000(labs[i], x.lab);
      const w = weights[i] / wSum;
      score += w * Math.min(d, SCORE_CAP);
      if (d < badDE) score -= w * 60;
      else if (d < lowDE) score -= w * 20;
    }
    const equalsMain = deltaE2000(labs[main], x.lab) < badDE;
    const cand = { x, score, equalsMain, order };
    const better = !best
      || (best.equalsMain && !equalsMain)
      || (best.equalsMain === equalsMain && score > best.score + 1e-9);
    if (better) best = cand;
  });
  return { id: best.x.f.id, hex: best.x.hex, reason: 'contrast', score: best.score };
}

/**
 * Edge (border) thread: the darkest clearly visible design colour with a real share, otherwise the dominant visible
 * colour, otherwise standard black (light fabric) or white (dark fabric).
 * @param {string} fabricHex
 * @param {{hex?:string, lab?:number[], share?:number, thread?:import('../shared/types.js').ThreadMatch}[]} colors
 * @param {Object[]} prepared
 * @param {Object} config
 * @returns {import('../shared/types.js').Thread|null}
 */
export function pickEdgeColor(fabricHex, colors, prepared, config) {
  const p = Array.isArray(prepared) && prepared.length && !prepared[0].lab ? prepareThreads(prepared) : prepared || [];
  if (!p.length) return null;
  const fab = normalizeHex(fabricHex) || '#ffffff';
  const fLab = hexToLab(fab);
  const { lowDE } = contrastCfg(config);
  const cands = [];
  for (const c of Array.isArray(colors) ? colors : []) {
    if (!c) continue;
    let t = c.thread && c.thread.thread ? c.thread.thread : null;
    if (!t) {
      const lab = toLab(c.lab || c.hex);
      if (!lab) continue;
      t = matchColor(lab, p, config, 1).thread;
    }
    if (!t || !t.lab) continue;
    if (deltaE2000(t.lab, fLab) < lowDE) continue;
    cands.push({ t, share: Math.max(0, Number(c.share) || 0) });
  }
  const real = cands.filter((c) => c.share >= 0.1);
  const dark = real.filter((c) => c.t.lab[0] < 45).sort((a, b) => a.t.lab[0] - b.t.lab[0] || b.share - a.share);
  if (dark.length) return dark[0].t;
  if (cands.length) return cands.slice().sort((a, b) => b.share - a.share || a.t.lab[0] - b.t.lab[0])[0].t;
  return specialThread(fLab[0] > 55 ? 'black' : 'white', p, config);
}
