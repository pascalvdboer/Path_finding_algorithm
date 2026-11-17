/**
 * Patch Studio — advice: measurements in millimetres on the finished patch, per technique (SPEC §7.7).
 * Everything goes through shared/geometry.js#layoutPatch() so "4,2 mm" means the same in render, ui and production.
 * Pure functions, no DOM.
 */
import { layoutPatch } from '../shared/geometry.js';

const EPS = 1e-9;

/** Text lines below this detection confidence are ignored (the fine-line measurement still catches them). */
export const TEXT_MIN_CONFIDENCE = 0.3;

/**
 * Options valid for `techId`, plus its default edge when no valid edge is selected.
 * (layoutPatch picks the first edge option in design.options, whatever technique it belongs to,
 *  so we must hand it a technique-specific option list.)
 */
export function optionsForTechnique(design, config, techId) {
  const tech = (config.techniques && config.techniques[techId]) || {};
  const all = config.options || [];
  const valid = (id) => {
    const o = all.find((x) => x.id === id);
    return o && o.enabled !== false && (o.techniques || []).includes(techId);
  };
  const out = (design.options || []).filter(valid);
  const hasEdge = out.some((id) => (all.find((x) => x.id === id) || {}).group === 'edge');
  if (!hasEdge && tech.defaultEdge && valid(tech.defaultEdge)) out.unshift(tech.defaultEdge);
  return out;
}

/** Design copy for a technique (and optionally another size). */
export function designFor(design, config, techId, sizeCm) {
  return Object.assign({}, design, {
    technique: techId,
    sizeCm: sizeCm == null ? design.sizeCm : sizeCm,
    options: optionsForTechnique(design, config, techId),
  });
}

export function layoutFor(design, analysis, config, techId, sizeCm) {
  return layoutPatch(designFor(design, config, techId, sizeCm), analysis, config);
}

/** Font flags from config.fonts (thin/serif/script make limits stricter). */
export function fontFlags(config, fontId) {
  const f = (config.fonts || []).find((x) => x.id === fontId) || {};
  return { thin: !!f.thin, serif: !!f.serif, script: !!f.script, strict: !!(f.thin || f.serif || f.script) };
}

/**
 * Art measurements at a given layout for one technique's rules.
 * @returns {{
 *   lineMm:number|null, gapMm:number|null, minTextMm:number|null,
 *   text:{measured:number, limit:number, comfort:number, lower:boolean, serif:boolean, ratio:number, comfortRatio:number}|null
 * }}
 */
export function artMeasures(analysis, layout, rules) {
  const mm = layout && layout.mmPerArtPx;
  const out = { lineMm: null, gapMm: null, minTextMm: null, text: null };
  if (!analysis || !mm) return out;
  if (analysis.fineLinePx != null && analysis.fineLinePx > 0) out.lineMm = analysis.fineLinePx * mm;
  if (analysis.fineGapPx != null && analysis.fineGapPx > 0) out.gapMm = analysis.fineGapPx * mm;

  const caps = (rules && rules.textCapsMm) || null;
  const lower = (rules && rules.textLowerXHeightMm) || null;
  const factorRule = (rules && rules.serifScriptFactor) || 1;
  for (const line of analysis.textLines || []) {
    if (!line || !(line.capPx > 0) || (line.confidence != null && line.confidence < TEXT_MIN_CONFIDENCE)) continue;
    const capMm = line.capPx * mm;
    const xMm = line.xPx > 0 ? line.xPx * mm : null;
    const eq = xMm != null ? Math.min(capMm, xMm / 0.6) : capMm; // SPEC §7.7 minTextMm definition
    if (out.minTextMm == null || eq < out.minTextMm) out.minTextMm = eq;
    if (!caps) continue;
    const f = line.serifOrScript ? factorRule : 1;
    const cands = [{ measured: capMm, limit: caps.hard * f, comfort: (caps.comfort || caps.hard) * f, lower: false }];
    if (xMm != null && lower) cands.push({ measured: xMm, limit: lower.hard * f, comfort: (lower.comfort || lower.hard) * f, lower: true });
    for (const c of cands) {
      const ratio = c.measured / c.limit;
      if (!out.text || ratio < out.text.ratio) {
        out.text = Object.assign(c, { serif: !!line.serifOrScript, ratio, comfortRatio: c.measured / c.comfort });
      }
    }
  }
  return out;
}

/**
 * Hard-limit check of everything that scales with the patch size (art text, lines are soft) at `sizeCm`.
 * Lines and gaps are never blocking: the digitiser can thicken or open them up (research 04 §2, §14).
 * @returns {{ok:boolean, comfort:boolean, meas:ReturnType<typeof artMeasures>, layout:Object}}
 */
export function sizeCheck(analysis, design, config, techId, sizeCm) {
  const tech = config.techniques[techId] || {};
  const rules = tech.rules || {};
  const layout = layoutFor(design, analysis, config, techId, sizeCm);
  const meas = artMeasures(analysis, layout, rules);
  let ok = true, comfort = true;
  const minCm = Math.max(Number(tech.minCm) || 0, ((rules.sizeMm && rules.sizeMm.hard) || 0) / 10);
  if (sizeCm + EPS < minCm) ok = false;
  if (meas.text) {
    if (meas.text.ratio + EPS < 1) ok = false;
    if (meas.text.comfortRatio + EPS < 1) comfort = false;
  }
  const line = rules.lineMm;
  if (meas.lineMm != null && line && meas.lineMm + EPS < (line.comfort || line.hard)) comfort = false;
  return { ok, comfort, meas, layout };
}

/** Sizes the customer can choose for a technique: priced sizes (order) and the 0,5 cm grid up to the technique max. */
export function sizeCandidates(config, techId) {
  const sizes = config.sizes || {};
  const tech = config.techniques[techId] || {};
  const step = Number(sizes.stepCm) || 0.5;
  const min = Number(sizes.minCm) || 4;
  const techMax = Math.min(Number(tech.maxCm) || Number(sizes.maxQuoteCm) || 30, Number(sizes.maxQuoteCm) || 30);
  const maxOrder = orderMaxCm(config, techId);
  const priced = (sizes.pricedCm || []).filter((s) => s <= maxOrder && s <= techMax).sort((a, b) => a - b);
  const grid = [];
  for (let s = min; s <= techMax + EPS; s = Math.round((s + step) * 1000) / 1000) grid.push(s);
  return { priced, grid, maxOrder, techMax };
}

/** Largest size that can be ordered online for a technique (0 for quote-only techniques). */
export function orderMaxCm(config, techId) {
  const tech = config.techniques[techId] || {};
  if (tech.mode !== 'order') return 0;
  const global = Number((config.sizes || {}).maxOrderCm) || 12;
  const own = Number(tech.maxOrderCm);
  return own > 0 ? Math.min(own, global) : global;
}

/**
 * Smallest size ≥ fromCm where all hard limits pass.
 * Prefers a priced, orderable size; falls back to the grid (quote range). `pred(check)` replaces the default `check.ok`.
 * @returns {{cm:number, quote:boolean, check:Object}|null}
 */
export function smallestPassingSize(analysis, design, config, techId, fromCm, { comfort = false, pred = null } = {}) {
  const { priced, grid, maxOrder } = sizeCandidates(config, techId);
  const tech = config.techniques[techId] || {};
  const passes = (c) => (pred ? pred(c) : c.ok) && (!comfort || c.comfort);
  if (tech.mode === 'order') {
    for (const s of priced) {
      if (s + EPS < fromCm) continue;
      const c = sizeCheck(analysis, design, config, techId, s);
      if (passes(c)) return { cm: s, quote: false, check: c };
    }
  }
  for (const s of grid) {
    if (s + EPS < fromCm) continue;
    if (tech.mode === 'order' && s <= maxOrder + EPS) continue; // orderable range handled by priced sizes
    const c = sizeCheck(analysis, design, config, techId, s);
    if (passes(c)) return { cm: s, quote: tech.mode === 'order' ? s > maxOrder + EPS : true, check: c };
  }
  return null;
}

/** Minimum size (grid) where the technique's hard limits pass, or null (SPEC: size.minCmByTechnique). */
export function minSizeForTechnique(analysis, design, config, techId) {
  const { grid } = sizeCandidates(config, techId);
  for (const s of grid) if (sizeCheck(analysis, design, config, techId, s).ok) return s;
  return null;
}

/**
 * Colour count the way a customer perceives it: significant palette colours plus extra text-layer threads.
 * @returns {{n:number, palette:number, textThreads:number}}
 */
export function colorCount(analysis, design, config, matches) {
  const minShare = (config.analysis && config.analysis.minShare) || 0.004;
  const pal = (analysis && analysis.palette) || [];
  const sig = pal.filter((c) => c && ((c.share || 0) >= minShare || c.thin));
  const known = new Set();
  (matches || []).forEach((m) => { if (m && m.thread && m.thread.code) known.add(String(m.thread.code)); });
  const extra = new Set();
  for (const l of design.texts || []) {
    if (!l || !String(l.text || '').trim()) continue;
    const code = l.threadCode;
    if (code && code !== 'auto' && !known.has(String(code))) extra.add(String(code));
  }
  return { n: sig.length + extra.size, palette: sig.length, textThreads: extra.size };
}

/** Measurements for the payload/UI (current technique and size). */
export function measure(input) {
  const { analysis = null, design, config } = input || {};
  if (!design || !config) return { fineLineMm: null, fineGapMm: null, minTextMm: null, mmPerArtPx: null };
  const techId = design.technique;
  const tech = (config.techniques && config.techniques[techId]) || {};
  const layout = layoutFor(design, analysis, config, techId, design.sizeCm);
  const meas = artMeasures(analysis, layout, tech.rules || {});
  return { fineLineMm: meas.lineMm, fineGapMm: meas.gapMm, minTextMm: meas.minTextMm, mmPerArtPx: layout.mmPerArtPx };
}
