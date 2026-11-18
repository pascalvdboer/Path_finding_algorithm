/**
 * Patch Studio UI — derived data: colours, advice, quotes, layout, render options and the DesignPayload.
 * Everything other modules provide is used ONLY through their public index.js contracts (SPEC §7).
 */
import { layoutPatch } from '../shared/geometry.js';
import { label } from '../shared/config.js';
import { prepareThreads, matchPalette, reducePalette, contrastAgainst, pickFabric, pickEdgeColor, threadByCode, specialThread } from '../colors/index.js';
import { evaluate, measure } from '../advice/index.js';
import { quote, quoteAll } from '../pricing/index.js';
import { estimateStitches } from '../analysis/index.js';

import { edgeCoverMm } from '../render/edges.js';

const memo = new Map();

/** Share of the radius taken by the photographed rim of a patch photo (merrow / satin edge in the picture). */
export const RIM_FRAC = 0.03;

/**
 * Dominant colour of the outer ring of a design that fills its own round/shaped patch (just inside its rim):
 * label (palette index) that occurs most between 80 % and 92 % of the radius. Cached per analysis.
 */
const ringMemo = new WeakMap();
export function outerRing(analysis) {
  if (!analysis || !analysis.labels || !analysis.preview || !(analysis.shape && analysis.shape.fillsPatch)) return null;
  if (ringMemo.has(analysis)) return ringMemo.get(analysis);
  const W = analysis.preview.width, H = analysis.preview.height, L = analysis.labels;
  const cnt = new Map();
  const cx = W / 2, cy = H / 2;
  for (let i = 0; i < 720; i++) {
    const ang = (i / 720) * Math.PI * 2;
    for (const f of [0.8, 0.84, 0.88, 0.92]) {
      const x = Math.round(cx + Math.cos(ang) * f * W / 2), y = Math.round(cy + Math.sin(ang) * f * H / 2);
      if (x < 0 || y < 0 || x >= W || y >= H) continue;
      const l = L[y * W + x];
      if (l !== 255) cnt.set(l, (cnt.get(l) || 0) + 1);
    }
  }
  let best = -1, bn = 0, tot = 0;
  for (const [l, n] of cnt) { tot += n; if (n > bn) { bn = n; best = l; } }
  const pal = analysis.palette || [];
  const out = best >= 0 && pal[best] && bn / Math.max(1, tot) > 0.35 ? { label: best, hex: pal[best].hex, share: bn / tot } : null;
  ringMemo.set(analysis, out);
  return out;
}

function cached(key, fn) {
  if (memo.has(key)) return memo.get(key);
  const v = fn();
  memo.set(key, v);
  if (memo.size > 40) memo.delete(memo.keys().next().value);
  return v;
}

export function techOf(config, id) {
  return (config.techniques && config.techniques[id]) || {};
}

/** Fabric list for a technique ('fabrics' or 'leathers'). */
export function fabricList(config, techId) {
  const tech = techOf(config, techId);
  const key = tech.fabricList || 'fabrics';
  const list = (config[key] || config.fabrics || []).filter((f) => f && f.enabled !== false && f.stock !== false);
  // Sublimation only works on white polyester (see the carrier option): a coloured fabric would turn the
  // preview dark (multiply) and promise something we cannot make. Offer white only.
  if (tech.render === 'sublimated') {
    const white = list.filter((f) => f.id === 'white' || String(f.hex || '').toLowerCase() === '#ffffff');
    if (white.length) return white;
  }
  return list;
}

/**
 * Effective analysis: the UI may override "keep background" from the adjust dialog.
 * Returns a shallow copy so the original stays intact for re-runs.
 */
export function effectiveAnalysis(analysis, tweaks) {
  if (!analysis) return null;
  if (!tweaks || tweaks.keepBackground == null) return analysis;
  const bg = analysis.background || {};
  const keep = !!tweaks.keepBackground && !!bg.hex;
  if (keep === !!bg.keep) return analysis;
  return { ...analysis, background: { ...bg, keep } };
}

/**
 * Compute everything that follows from (state, analysis). Pure apart from memoisation.
 * @returns {Object} derived
 */
export function derive(state, analysisRaw, config, tweaks) {
  const analysis = effectiveAnalysis(analysisRaw, tweaks);
  const locale = state.locale || config.locale;
  const prepared = prepareThreads(config.threads || []);
  const job = analysis ? analysis.job + ':' + (analysis.background && analysis.background.keep ? 1 : 0) : 'none';
  const tech = techOf(config, state.technique);
  const kL = analysis && analysis.isPatchPhoto ? 2 : 1;

  const matches = analysis ? cached('m' + job, () => matchPalette(analysis.palette || [], prepared, config, { kL })) : null;
  const reduced = analysis && (analysis.palette || []).length
    // reduction weighs lightness fully (kL 1): mid-tones (grey ground, beige cape) must not collapse into white or
    // black when a photo of a patch is simplified (critique-1 #2); matching keeps the lighting-tolerant kL.
    ? cached('r' + job + ':' + state.maxThreadColors, () => reducePalette(analysis.palette, state.maxThreadColors, { prepared, config, kL: 1 }))
    : null;

  // Apply customer thread overrides (paletteIndex → code) on the reduced colours.
  let reducedColors = reduced ? reduced.colors.map((c) => ({ ...c })) : [];
  if (reduced && state.threadOverrides) {
    for (const [pi, code] of Object.entries(state.threadOverrides)) {
      const ci = reduced.map[Number(pi)];
      const th = threadByCode(code, config.threads || []);
      if (ci != null && th && reducedColors[ci]) reducedColors[ci] = { ...reducedColors[ci], override: th };
    }
  }
  const threadOf = (c) => c.override || (c.thread && c.thread.thread) || null;
  const useThreads = !!tech.threadBased;
  const colorList = analysis
    ? (useThreads && reduced
      ? reducedColors.map((c) => ({ hex: threadOf(c) ? threadOf(c).hex : c.hex, share: c.share, thin: c.thin }))
      : (analysis.palette || []).map((c) => ({ hex: c.hex, share: c.share, thin: c.thin })))
    : [];

  // Fabric: auto pick (with kept background) or the customer's choice.
  const fabrics = fabricList(config, state.technique);
  const bg = analysis && analysis.background;
  const keepBg = !!(bg && bg.keep && bg.hex);
  const textColorsForPick = (state.texts || []).filter((l) => l.threadCode && l.threadCode !== 'auto').map((l) => {
    const th = threadByCode(l.threadCode, config.threads || []);
    return th ? { hex: th.hex, share: 0.05, important: true } : null;
  }).filter(Boolean);
  const pick = fabrics.length
    ? pickFabric([...colorList, ...textColorsForPick], fabrics, config, { background: keepBg ? bg.hex : null })
    : null;
  const chosenFabric = state.fabric !== 'auto' ? fabrics.find((f) => f.id === state.fabric) : null;
  const fabric = chosenFabric || (pick && fabrics.find((f) => f.id === pick.id)) || fabrics[0] || { id: 'white', hex: '#ffffff', label: { nl: 'Wit', fr: 'Blanc' } };
  const fabricHex = fabric.engrave && state.technique === 'leather' ? fabric.hex : fabric.hex;
  // A design that is itself a complete patch (own ring + rim): the new patch continues its outer ring colour, so a
  // round design in a shield or rectangle never sits as a sticker on a white field (critique-1 #5).
  const ring = state.fabric === 'auto' && !keepBg ? outerRing(analysis) : null;
  let ringHex = null;
  if (ring) {
    ringHex = ring.hex;
    if (useThreads && reduced && reduced.map && reducedColors[reduced.map[ring.label]]) {
      const th = threadOf(reducedColors[reduced.map[ring.label]]);
      if (th) ringHex = th.hex;
    }
  }
  const backgroundHex = keepBg ? bg.hex : ringHex;
  const groundHex = backgroundHex || fabricHex;

  // Text colours: 'auto' = best-contrast thread against what lies UNDER the text (the design pixels there, else the
  // patch ground), not against the patch ground alone (critique-1 #3). Over a busy part of the design an outline in
  // the opposite tone keeps the letters readable.
  const autoText = pickEdgeColor(groundHex, [], prepared, config);
  const textHex = {}, textOutline = {}, textAutoCode = {};
  const layout0 = fitOwnRim(layoutPatch(state, analysis, config), analysis, tech);
  for (const l of state.texts || []) {
    const th = l.threadCode !== 'auto' ? threadByCode(l.threadCode, config.threads || []) : null;
    const under = analysis && l.text && l.text.trim() ? underText(l, layout0, analysis, groundHex, config) : null;
    let auto = autoText;
    if (under) auto = pickEdgeColor(under.meanHex, [], prepared, config) || autoText;
    if (!th && auto) textAutoCode[l.id] = auto.code;
    textHex[l.id] = (th && th.hex) || (auto && auto.hex) || '#1e1b1b';
    const want = l.outline === 'none' ? false : l.outline === 'on' ? true : !!(under && under.busy(textHex[l.id]));
    if (want) {
      const dark = lumOf(textHex[l.id]) > 0.35;
      const o = specialThread(dark ? 'black' : 'white', prepared, config);
      textOutline[l.id] = { hex: (o && o.hex) || (dark ? '#191d29' : '#ffffff'), code: o && o.code, mm: Math.max(0.45, Math.min(0.9, l.heightMm * 0.09)) };
    }
  }

  // Edge colour.
  const autoEdge = pickEdgeColor(groundHex, colorList, prepared, config);
  const edgeThread = state.edgeColor !== 'auto' ? threadByCode(state.edgeColor, config.threads || []) || autoEdge : autoEdge;
  const edgeHex = (edgeThread && edgeThread.hex) || '#1e1b1b';

  const contrastItems = [...colorList.map((c, i) => ({ ...c, index: i })), ...Object.values(textHex).map((hex) => ({ hex, share: 0.05, index: -1, important: true }))];
  const contrast = colorList.length || contrastItems.length ? contrastAgainst(contrastItems, groundHex, config) : null;

  const pin = {
    technique: state.technique,
    sizeCm: state.sizeCm,
    qty: state.qty,
    options: state.options,
    locale,
    poolQty: state.poolQty || 0,
    feesAlreadyCharged: state.feesAlreadyCharged || [],
    otherItemsInclVat: state.otherItemsInclVat || 0,
  };
  const quotes = quoteAll({ ...pin, withLadder: false }, config);
  const q = quote({ ...pin, withLadder: true }, config);

  // 'auto' text colour = a real thread too: let the colour count (advice) see which one (eigenaar: tekstkleuren tellen mee).
  const autoCode = autoText && autoText.code ? String(autoText.code) : null;
  const designForAdvice = autoCode && (state.texts || []).some((l) => l.threadCode === 'auto')
    ? { ...state, texts: state.texts.map((l) => (l.threadCode === 'auto' ? { ...l, autoThreadCode: autoCode } : l)) }
    : state;
  const advice = evaluate({
    analysis,
    design: designForAdvice,
    config,
    locale,
    colors: { reduced, matches, contrast, fabricHex: groundHex },
    quotes,
    context: { useCase: state.useCase || '' },
  });

  const layout = layout0;
  const meas = measure({ analysis, design: state, config });

  return {
    analysis, prepared, matches, reduced, reducedColors, threadOf, useThreads, colorList,
    fabrics, fabric, fabricPick: pick, fabricHex, backgroundHex, groundHex, keepBg, ring,
    textHex, textOutline, textAutoCode, edgeThread, edgeHex, autoEdge, contrast, quotes, quote: q, advice, layout, meas,
    quoteMode: tech.mode === 'quote' || q.mode === 'quote',
  };
}

/**
 * A design that fills its own patch (photo of a patch, round club logo with rim): the generated border replaces the
 * photographed rim instead of lying on top of the lettering next to it (critique-1 #6). The design is scaled down so
 * that its own rim ends under the new border and everything inside it (text!) stays at least 0.6 mm clear.
 */
export function fitOwnRim(layout, analysis, tech) {
  const r = layout && layout.artRectMm;
  if (!r || !analysis || !analysis.shape || !analysis.shape.fillsPatch) return layout;
  const W = layout.patchWmm, H = layout.patchHmm;
  // only when the design covers the whole patch (same shape); a design fitted into another shape is already inset
  if (r.w < W * 0.97 && r.h < H * 0.97) return layout;
  const edge = tech && tech.render === 'pvc' ? { ...layout.edge, style: 'molded' } : layout.edge;
  const cover = edgeCoverMm(edge);
  const R = Math.min(W, H) / 2;
  const k = Math.min(1, (R - cover - 0.6) / (R * (1 - RIM_FRAC)));
  if (!(k > 0.5) || k >= 0.999) return layout;
  const cx = W / 2, cy = H / 2;
  const artRectMm = { x: cx + (r.x - cx) * k, y: cy + (r.y - cy) * k, w: r.w * k, h: r.h * k };
  return { ...layout, artRectMm, mmPerArtPx: layout.mmPerArtPx ? layout.mmPerArtPx * k : layout.mmPerArtPx };
}

/* ------------------------------ text: what lies under it, where it fits ------------------------------ */

const CAP = 0.71;
let mctx = null;
/** Width (mm) of a text layer at its height (same measure as the renderer; rough fallback without canvas). */
export function textWidthOf(config, l, heightMm = l.heightMm) {
  try {
    if (!mctx) mctx = document.createElement('canvas').getContext('2d');
    const f = (config.fonts || []).find((x) => x.id === l.font) || {};
    mctx.font = `${f.weight || 700} 100px "${f.id || 'Sora'}", ${f.fallback || 'sans-serif'}`;
    const chars = Array.from(l.text || '');
    const px = chars.reduce((a, ch) => a + mctx.measureText(ch).width, 0) + (Number(l.letterSpacing) || 0) * 100 * Math.max(0, chars.length - 1);
    return (px / 100) * (heightMm / CAP);
  } catch (e) { return Array.from(l.text || '').length * heightMm * 0.78; }
}

function hexRgb(hex) { const n = parseInt(String(hex || '#ffffff').slice(1, 7), 16) || 0; return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; }
function relLum(r, g, b) {
  const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
}
function lumOf(hex) { const [r, g, b] = hexRgb(hex); return relLum(r, g, b); }
export function contrastRatio(a, b) { const x = Math.max(a, b), y = Math.min(a, b); return (x + 0.05) / (y + 0.05); }

/** Sample points (mm, patch top-left origin) covering a text layer. */
function textPoints(l, layout, config, heightMm = l.heightMm) {
  const W = layout.patchWmm, H = layout.patchHmm, h = heightMm;
  const w = Math.max(h, textWidthOf(config, l, h));
  const pts = [];
  if (l.path === 'straight' || !(layout.shape === 'circle' || layout.shape === 'oval')) {
    const off = l.offsetMm || { x: 0, y: 0 };
    let cx = W / 2 + (Number(off.x) || 0), cy = H / 2 + (Number(off.y) || 0);
    if (l.path === 'arc_top') cy = (layout.edge ? layout.edge.widthMm : 2) + h;
    if (l.path === 'arc_bottom') cy = H - (layout.edge ? layout.edge.widthMm : 2) - h;
    for (let i = 0; i <= 16; i++) for (let j = 0; j <= 4; j++) pts.push([cx - w / 2 + (w * i) / 16, cy - h / 2 + (h * j) / 4]);
    return pts;
  }
  const top = l.path === 'arc_top';
  const edge = (layout.edge && layout.edge.widthMm) || 0;
  const R0 = Math.min(W, H) / 2;
  const rad = top ? R0 - Math.max(l.insetMm || 0, edge + h + 0.5) + h / 2 : R0 - Math.max(l.insetMm || 0, edge + 0.7) - h / 2;
  const span = w / Math.max(1, rad);
  const a0 = ((top ? -90 : 90) + (Number(l.angleDeg) || 0)) * Math.PI / 180;
  for (let i = 0; i <= 16; i++) for (let j = 0; j <= 4; j++) {
    const a = a0 + span * (i / 16 - 0.5), r = rad - h / 2 + (h * j) / 4;
    pts.push([W / 2 + Math.cos(a) * r, H / 2 + Math.sin(a) * r]);
  }
  return pts;
}

/**
 * Colours under a text layer: mean colour, share on the design, and busy(textHex) = true when the text colour would
 * reach less than 3:1 against a noticeable part (≥ 4 %) of the pixels under it.
 */
export function underText(l, layout, analysis, groundHex, config, heightMm) {
  const r = layout.artRectMm, pv = analysis && analysis.preview;
  const pts = textPoints(l, layout, config, heightMm);
  const g = hexRgb(groundHex);
  const lums = [];
  let sr = 0, sg = 0, sb = 0, onArt = 0;
  for (const [x, y] of pts) {
    let c = g;
    if (r && pv && pv.data) {
      const u = Math.floor(((x - r.x) / r.w) * pv.width), v = Math.floor(((y - r.y) / r.h) * pv.height);
      if (u >= 0 && v >= 0 && u < pv.width && v < pv.height) {
        const i = (v * pv.width + u) * 4, a = pv.data[i + 3] / 255;
        if (a > 0.05) { onArt++; c = [pv.data[i] * a + g[0] * (1 - a), pv.data[i + 1] * a + g[1] * (1 - a), pv.data[i + 2] * a + g[2] * (1 - a)]; }
      }
    }
    sr += c[0]; sg += c[1]; sb += c[2];
    lums.push(relLum(c[0], c[1], c[2]));
  }
  const n = Math.max(1, pts.length);
  const h2 = (v) => Math.round(v / n).toString(16).padStart(2, '0');
  const meanHex = '#' + h2(sr) + h2(sg) + h2(sb);
  const sorted = lums.slice().sort((a, b) => a - b);
  return {
    meanHex,
    onArt: onArt / n,
    spread: sorted[Math.min(n - 1, Math.floor(n * 0.96))] - sorted[Math.floor(n * 0.04)],
    lums,
    /** share of points where `hex` reaches less than 3:1 */
    weak(hex) { const t = lumOf(hex); return lums.filter((L) => contrastRatio(t, L) < 3).length / n; },
    busy(hex) { return this.weak(hex) >= 0.04; },
  };
}

/** Room (mm) for a text layer along its path inside the border. */
export function textRoom(l, layout, heightMm = l.heightMm) {
  const edge = (layout.edge && layout.edge.widthMm) || 0;
  const round = layout.shape === 'circle' || layout.shape === 'oval';
  const W = layout.patchWmm, H = layout.patchHmm;
  if (l.path !== 'straight' && round) return Math.PI * (Math.min(W, H) / 2 - edge - Math.max(l.insetMm || 0, 0) - heightMm) * 0.9;
  if (l.path === 'straight' && round) {
    const r = Math.min(W, H) / 2 - edge - 1, y = Math.abs((l.offsetMm && l.offsetMm.y) || 0) + heightMm / 2;
    return 2 * Math.sqrt(Math.max(0, r * r - y * y)) * (W / Math.min(W, H));
  }
  return (W - 2 * (edge + 1)) * (layout.shape === 'shield' && l.path === 'arc_bottom' ? 0.65 : 1);
}

/** Largest cap height (≤ wanted) at which the text fits its room; never below the minimum. */
export function fitHeight(l, layout, config, wanted) {
  const T = config.text || {};
  const min = T.minHeightMm || 3;
  let h = Math.max(min, wanted);
  for (let i = 0; i < 6; i++) {
    const w = textWidthOf(config, l, h), room = textRoom(l, layout, h);
    if (w <= room) break;
    h = Math.max(min, Math.floor((h * Math.max(0.3, room / Math.max(1, w))) * 2) / 2);
    if (h <= min) { h = min; break; }
  }
  return h;
}

/**
 * Where a new text layer looks best: the quietest free spot (below/above the design, along the edge on round
 * patches, or the centre on an empty patch), fitted inside the border.
 */
export function placeText(state, d, config, layer) {
  const layout = d.layout;
  const a = d.analysis;
  const T = config.text || {};
  const want = T.defaultHeightMm || 6;
  const H = layout.patchHmm;
  const round = layout.shape === 'circle' || layout.shape === 'oval';
  const others = (state.texts || []).filter((x) => x.id !== layer.id);
  const taken = (c) => others.some((o) => o.path === c.path && (c.path !== 'straight' || Math.abs(((o.offsetMm && o.offsetMm.y) || 0) - c.offsetMm.y) < want + 2));
  const cands = [];
  if (round) { cands.push({ path: 'arc_bottom', offsetMm: { x: 0, y: 0 } }, { path: 'arc_top', offsetMm: { x: 0, y: 0 } }); }
  for (const f of [0.3, -0.3, 0.22, -0.22, 0.36, -0.36, 0]) cands.push({ path: 'straight', offsetMm: { x: 0, y: Math.round(H * f * 2) / 2 } });
  let best = null;
  for (const c of cands) {
    if (taken(c)) continue;
    const l = { ...layer, ...c, angleDeg: 0, insetMm: 0 };
    const h = fitHeight(l, layout, config, want);
    const u = a ? underText(l, layout, a, d.groundHex, config, h) : null;
    const room = textRoom(l, layout, h), w = textWidthOf(config, l, h);
    // quiet = little of the design under it and one thread colour that reads well everywhere; big enough
    let weak = 0;
    if (u) { const th = pickEdgeColor(u.meanHex, [], d.prepared, config); weak = th ? u.weak(th.hex) : 0; }
    let score = (u ? u.onArt + u.spread * 1.5 + weak * 3 : 0) + (want - h) * 0.25 + (w > room + 0.5 ? 5 : 0);
    if (!a && c.path === 'straight' && c.offsetMm.y === 0) score -= 1;
    if (!a && !round && c.path === 'straight') score += Math.abs(c.offsetMm.y) / H;
    if (globalThis.__bpsPlaceDbg) globalThis.__bpsPlaceDbg.push([c.path, c.offsetMm.y, h, u && +u.onArt.toFixed(2), u && +u.spread.toFixed(2), +weak.toFixed(2), +score.toFixed(2)]);
    if (!best || score < best.score) best = { score, patch: { ...c, heightMm: h, angleDeg: 0, insetMm: 0 } };
  }
  return best ? best.patch : { path: 'straight', offsetMm: { x: 0, y: 0 }, heightMm: fitHeight(layer, layout, config, want) };
}

const roundish = (x) => x === 'circle' || x === 'oval';
function sameShape(a, b) { return a === b || (roundish(a) && roundish(b)); }

/** RenderArt for the renderer (art image comes from render.artFromAnalysis, passed in). */
export function renderArt(d, image) {
  const a = d.analysis;
  if (!a || !image) return null;
  return {
    image,
    labels: a.labels ? { width: a.preview.width, height: a.preview.height, data: a.labels } : null,
    paletteHex: (a.palette || []).map((c) => c.hex),
    reducedMap: d.reduced ? d.reduced.map : null,
    reducedHex: d.reduced ? d.reducedColors.map((c) => (d.threadOf(c) ? d.threadOf(c).hex : c.hex)) : null,
    backdrop: a.backdrop || null,
    // photographed rim of a patch-like design: masked away, the generated border takes its place (critique-1 #5)
    // (only when the patch gets another shape: in its own shape the rim simply ends under the new border)
    clip: a.shape && a.shape.fillsPatch && !d.keepBg && !sameShape(a.shape.type, d.layout.shape) ? { shape: a.shape.type, inset: RIM_FRAC } : null,
  };
}

/** RenderOptions (SPEC §7.6 / types.js). */
export function renderOptions(state, d, art, extra = {}) {
  return {
    design: state,
    layout: d.layout,
    art,
    fabricHex: d.fabricHex,
    backgroundHex: d.backgroundHex,
    edgeHex: d.edgeHex,
    textHex: d.textHex,
    textOutline: d.textOutline,
    view: 'patch',
    reduced: d.useThreads && !!d.reduced,
    quality: 'high',
    pxSize: 600,
    ...extra,
  };
}

const r1 = (x) => (x == null ? null : Math.round(x * 10) / 10);

/** DesignPayload (types.js) — what goes to POST /designs. */
export function buildPayload(state, d, config, extra = {}) {
  const locale = state.locale || config.locale;
  const a = d.analysis;
  const L = d.layout;
  const threads = [];
  const seen = new Set();
  if (a && d.useThreads && d.reduced) {
    d.reducedColors.forEach((c) => {
      const th = d.threadOf(c);
      if (!th || seen.has(th.code)) return;
      seen.add(th.code);
      threads.push({ code: th.code, name: label(th.name, locale), hex: th.hex, share: Math.round((c.share || 0) * 1000) / 1000 });
    });
  }
  for (const l of state.texts || []) {
    if (!l.text.trim()) continue;
    const th = l.threadCode !== 'auto' ? threadByCode(l.threadCode, config.threads || []) : null;
    if (th && !seen.has(th.code) && d.useThreads) {
      seen.add(th.code);
      threads.push({ code: th.code, name: label(th.name, locale), hex: th.hex, share: 0 });
    }
  }
  let stitches = null;
  if (a && d.useThreads && a.regions && L.mmPerArtPx && config.stitches) {
    try {
      const perim = L.shape === 'circle' ? Math.PI * L.patchWmm : 2 * (L.patchWmm + L.patchHmm);
      const s = estimateStitches(a.regions, L.mmPerArtPx, d.reduced ? d.reduced.k : (a.palette || []).length, config.stitches, {
        perimeterMm: perim,
        satinBorder: L.edge && (L.edge.style === 'satin' || L.edge.style === 'merrow'),
      });
      stitches = s ? { min: s.min, max: s.max } : null;
    } catch (e) { stitches = null; }
  }
  const adv = d.advice;
  const statuses = {};
  for (const [k, v] of Object.entries(adv.verdicts || {})) statuses[k] = v.status;
  const autoTextCode = (hex) => {
    const th = (config.threads || []).find((x) => x.hex && x.hex.toLowerCase() === String(hex).toLowerCase());
    return th ? th.code : 'auto';
  };
  return {
    v: 1,
    locale,
    technique: state.technique,
    sizeCm: state.sizeCm,
    widthMm: r1(L.patchWmm),
    heightMm: r1(L.patchHmm),
    shape: state.shape,
    qty: state.qty,
    options: state.options.slice(),
    fabric: { id: d.fabric.id, hex: d.backgroundHex || d.fabricHex, label: label(d.fabric.label, locale) },
    edgeColor: d.edgeThread ? { code: d.edgeThread.code, hex: d.edgeThread.hex, name: label(d.edgeThread.name, locale) } : null,
    threads,
    texts: (state.texts || []).filter((l) => l.text.trim()).map((l) => ({
      text: l.text,
      font: l.font,
      heightMm: l.heightMm,
      threadCode: l.threadCode === 'auto' ? (d.textAutoCode && d.textAutoCode[l.id]) || autoTextCode(d.textHex[l.id]) : l.threadCode,
      path: l.path,
      // plaatsing (eigenaar: zelf schikken), zodat herladen, winkelmand en productiefiche dezelfde patch tonen
      offsetMm: { x: r1(l.offsetMm && l.offsetMm.x) || 0, y: r1(l.offsetMm && l.offsetMm.y) || 0 },
      angleDeg: Math.round(Number(l.angleDeg) || 0),
      insetMm: r1(l.insetMm) || 0,
      letterSpacing: Math.round((Number(l.letterSpacing) || 0) * 1000) / 1000,
      outline: d.textOutline && d.textOutline[l.id] ? { threadCode: d.textOutline[l.id].code || null, hex: d.textOutline[l.id].hex, mm: r1(d.textOutline[l.id].mm) } : null,
    })),
    art: { scale: state.art.scale, offsetMm: { ...state.art.offsetMm }, rotation: state.art.rotation },
    analysis: a
      ? {
        kind: a.kind,
        hasSoftShading: !!a.hasSoftShading,
        colors: d.reduced ? d.reduced.k : (a.palette || []).length,
        fineLineMm: r1(d.meas.fineLineMm),
        minTextMm: r1(d.meas.minTextMm),
        stitches,
        warnings: (a.warnings || []).slice(),
        shape: a.shape ? a.shape.type : null,
        background: (a.background && a.background.type) + (d.keepBg ? ':keep' : ''),
      }
      : null,
    advice: {
      recommended: adv.recommended,
      chosenStatus: statuses[state.technique] || 'ok',
      statuses,
      headline: (adv.summary && adv.summary.headline && adv.summary.headline.text) || '',
    },
    priceClient: { totalIncl: d.quote.totalIncl, unitExcl: d.quote.unitExcl },
    source: { fileName: state.source.fileName, fileType: state.source.fileType },
    notes: state.notes || '',
    ...extra,
  };
}
