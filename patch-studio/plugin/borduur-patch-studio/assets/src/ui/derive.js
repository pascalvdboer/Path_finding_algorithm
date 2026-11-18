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

/**
 * Measured share of the radius taken by the photographed rim (ribbed merrow / satin edge in the picture): walking
 * inwards from the outline, the rim ends where the outer ring colour becomes continuous. Falls back to RIM_FRAC.
 * Cached per analysis (critique-2 #8: a 6 % ribbed rim showed as a second border inside the new one).
 */
const rimMemo = new WeakMap();
export function rimFrac(analysis) {
  if (!analysis || !analysis.labels || !analysis.preview) return RIM_FRAC;
  if (rimMemo.has(analysis)) return rimMemo.get(analysis);
  const ring = outerRing(analysis);
  let out = RIM_FRAC;
  if (ring) {
    const W = analysis.preview.width, H = analysis.preview.height, L = analysis.labels;
    const count = (f) => {
      const cnt = new Map(); let n = 0;
      for (let i = 0; i < 360; i++) {
        const ang = (i / 360) * Math.PI * 2;
        const x = Math.round(W / 2 + Math.cos(ang) * f * W / 2), y = Math.round(H / 2 + Math.sin(ang) * f * H / 2);
        if (x < 0 || y < 0 || x >= W || y >= H) continue;
        n++; const l = L[y * W + x]; cnt.set(l, (cnt.get(l) || 0) + 1);
      }
      return { cnt, n: Math.max(1, n) };
    };
    // the rim shows as a steady second colour next to the ring colour (ribs, wrapped stitches)
    const c0 = count(0.97);
    let rimLabel = -1, rn = 0;
    for (const [l, k] of c0.cnt) if (l !== 255 && l !== ring.label && k > rn) { rn = k; rimLabel = l; }
    const share0 = rn / c0.n;
    let edge = 1 - RIM_FRAC;
    if (rimLabel >= 0 && share0 >= 0.2) {
      let run = 0;
      for (let f = 0.97; f >= 0.84; f -= 0.01) {
        const c = count(f);
        if ((c.cnt.get(rimLabel) || 0) / c.n < share0 * 0.5) { run++; if (run >= 2) { edge = f + 0.01; break; } } else run = 0;
      }
    }
    out = Math.min(0.14, Math.max(RIM_FRAC, 1 - edge + 0.008));
  }
  rimMemo.set(analysis, out);
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

/** CIE L* of a hex colour (0..100). */
function lstar(hex) {
  const n = parseInt(String(hex || '').replace('#', ''), 16) || 0;
  const lin = (v) => { v /= 255; return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
  const y = 0.2126 * lin((n >> 16) & 255) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255);
  return y > 0.008856 ? 116 * Math.cbrt(y) - 16 : 903.3 * y;
}

/**
 * Palette indices that must keep their own thread when the palette is reduced: one colour per distinct lightness
 * level (ΔL* > 15 to every level already kept, largest share first). Without this a mid-grey (armour, a rifle) melts
 * into the black ground and an illustration turns into a dark blob (critique-2 #2). Light greys and white go first,
 * so a light tone wins over an extra dark one. At most max-2 levels, so two hues (red, gold) still fit.
 */
export function tonalKeep(palette, max) {
  const list = (palette || []).map((c, i) => ({ i, L: lstar(c && c.hex), share: Number(c && c.share) || 0 }))
    .filter((c) => c.share >= 0.02);
  const levels = [];
  const cap = Math.max(1, max - 2);
  const w = (c) => c.share * (c.L > 70 ? 2 : 1);
  for (const c of list.sort((a, b) => w(b) - w(a))) {
    if (levels.length < cap && levels.every((k) => Math.abs(k.L - c.L) > 15)) levels.push(c);
  }
  return levels.map((c) => c.i);
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
    ? cached('r' + job + ':' + state.maxThreadColors, () => reducePalette(analysis.palette, state.maxThreadColors, { prepared, config, kL: 1, keep: tonalKeep(analysis.palette, state.maxThreadColors) }))
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
  // In its own shape too: where the photo shows a notch or a torn rim, the ground continues the ring (critique-2 #8).
  const ring = state.fabric === 'auto' && !keepBg && analysis && analysis.shape ? outerRing(analysis) : null;
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
  // the ring colour only continues the design's own outer ring: contrast advice still looks at the real fabric
  const adviceGround = (keepBg ? bg.hex : null) || fabricHex;

  // Text colours: 'auto' = best-contrast thread against what lies UNDER the text (the design pixels there, else the
  // patch ground), not against the patch ground alone (critique-1 #3). Over a busy part of the design an outline in
  // the opposite tone keeps the letters readable.
  const autoText = pickEdgeColor(groundHex, [], prepared, config);
  const textHex = {}, textOutline = {}, textAutoCode = {};
  const layout0 = fitOwnRim(withEdgeWidth(layoutPatch(state, analysis, config), state.edgeWidth), analysis, tech);
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
  const contrast = colorList.length || contrastItems.length ? contrastAgainst(contrastItems, ringHex ? adviceGround : groundHex, config) : null;

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
    colors: { reduced, matches, contrast, fabricHex: ringHex ? adviceGround : groundHex },
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
  const k = Math.min(1, (R - cover - 0.6) / (R * (1 - rimFrac(analysis))));
  if (!(k > 0.5) || k >= 0.999) return layout;
  const cx = W / 2, cy = H / 2;
  const artRectMm = { x: cx + (r.x - cx) * k, y: cy + (r.y - cy) * k, w: r.w * k, h: r.h * k };
  return { ...layout, artRectMm, mmPerArtPx: layout.mmPerArtPx ? layout.mmPerArtPx * k : layout.mmPerArtPx };
}

/** Border width choice (Rand → Meer opties): narrow / normal / wide, only for thread borders (critique-2 #9). */
export const EDGE_WIDTHS = { narrow: 0.75, normal: 1, wide: 1.4 };
export function edgeWidthApplies(edge) { return !!edge && (edge.style === 'satin' || edge.style === 'merrow' || edge.style === 'uncut') && edge.widthMm > 0; }
function withEdgeWidth(layout, w) {
  const k = EDGE_WIDTHS[w] || 1;
  if (k === 1 || !layout || !edgeWidthApplies(layout.edge)) return layout;
  const e = layout.edge;
  return { ...layout, edge: { ...e, widthMm: Math.round(e.widthMm * k * 10) / 10, safeZoneMm: Math.round((e.safeZoneMm + e.widthMm * (k - 1)) * 10) / 10 } };
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
/**
 * Strong-contrast edges of the design (letters, outlines), dilated ±3 px: text placed there would collide with
 * existing lettering ("IRON CRUSADERS") even where the ground under it is calm (critique-2 #3). Cached per analysis.
 */
const edgeMemo = new WeakMap();
function edgeMap(analysis) {
  const pv = analysis && analysis.preview;
  if (!pv || !pv.data) return null;
  if (edgeMemo.has(analysis)) return edgeMemo.get(analysis);
  const W = pv.width, H = pv.height, D = pv.data, lum = new Float32Array(W * H), e = new Uint8Array(W * H);
  for (let i = 0; i < W * H; i++) lum[i] = D[i * 4 + 3] < 30 ? -1 : 0.299 * D[i * 4] + 0.587 * D[i * 4 + 1] + 0.114 * D[i * 4 + 2];
  for (let y = 1; y < H - 1; y++) for (let x = 1; x < W - 1; x++) {
    const i = y * W + x;
    if (lum[i] < 0) continue;
    const gx = Math.abs(lum[i + 1] - lum[i - 1]), gy = Math.abs(lum[i + W] - lum[i - W]);
    if (lum[i + 1] >= 0 && lum[i - 1] >= 0 && lum[i + W] >= 0 && lum[i - W] >= 0 && gx + gy > 70) e[i] = 1;
  }
  // dilate 3 px (separable max)
  const tmp = new Uint8Array(W * H), out = new Uint8Array(W * H), R = 3;
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) { let m = 0; for (let k = -R; k <= R && !m; k++) { const xx = x + k; if (xx >= 0 && xx < W) m = e[y * W + xx]; } tmp[y * W + x] = m; }
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) { let m = 0; for (let k = -R; k <= R && !m; k++) { const yy = y + k; if (yy >= 0 && yy < H) m = tmp[yy * W + x]; } out[y * W + x] = m; }
  const res = { W, H, data: out };
  edgeMemo.set(analysis, res);
  return res;
}

/**
 * A characteristic spot of the design (mm, patch top-left origin) for detail crops in the technique comparer: the
 * densest cluster of contrast edges (letters, outlines) inside the patch, else the first text, else the centre.
 */
export function detailMm(state, d) {
  const L = d.layout, W = L.patchWmm, H = L.patchHmm;
  const em = d.analysis ? edgeMap(d.analysis) : null, r = L.artRectMm;
  if (em && r) {
    const G = 10, cnt = new Float32Array(G * G);
    for (let y = 0; y < em.H; y += 2) for (let x = 0; x < em.W; x += 2) if (em.data[y * em.W + x]) cnt[((y * G / em.H) | 0) * G + ((x * G / em.W) | 0)]++;
    let best = -1, bv = 0;
    for (let i = 0; i < G * G; i++) {
      const mx = r.x + ((i % G) + 0.5) / G * r.w, my = r.y + (((i / G) | 0) + 0.5) / G * r.h;
      const q = Math.hypot((mx - W / 2) / (W / 2), (my - H / 2) / (H / 2));
      if (q > 0.72) continue; // not the rim
      if (cnt[i] > bv) { bv = cnt[i]; best = i; }
    }
    if (best >= 0) return { x: r.x + ((best % G) + 0.5) / G * r.w, y: r.y + (((best / G) | 0) + 0.5) / G * r.h };
  }
  const l = (state.texts || []).find((x) => x.text && x.text.trim());
  if (l && l.path === 'straight') return { x: W / 2 + l.offsetMm.x, y: H / 2 + l.offsetMm.y };
  return { x: W / 2, y: H / 2 };
}

export function underText(l, layout, analysis, groundHex, config, heightMm) {
  const r = layout.artRectMm, pv = analysis && analysis.preview;
  const pts = textPoints(l, layout, config, heightMm);
  const g = hexRgb(groundHex);
  const em = edgeMap(analysis);
  const lums = [];
  let sr = 0, sg = 0, sb = 0, onArt = 0, edges = 0;
  for (const [x, y] of pts) {
    let c = g;
    if (r && pv && pv.data) {
      const u = Math.floor(((x - r.x) / r.w) * pv.width), v = Math.floor(((y - r.y) / r.h) * pv.height);
      if (u >= 0 && v >= 0 && u < pv.width && v < pv.height) {
        const i = (v * pv.width + u) * 4, a = pv.data[i + 3] / 255;
        if (a > 0.05) { onArt++; c = [pv.data[i] * a + g[0] * (1 - a), pv.data[i + 1] * a + g[1] * (1 - a), pv.data[i + 2] * a + g[2] * (1 - a)]; }
        if (em && em.data[v * pv.width + u]) edges++;
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
    edges: edges / n,
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
    // existing lettering and outlines count as occupied, even on a calm ground (critique-2 #3)
    let score = (u ? u.onArt + u.spread * 1.5 + weak * 3 + u.edges * 8 : 0) + (want - h) * 0.25 + (w > room + 0.5 ? 5 : 0);
    if (!a && c.path === 'straight' && c.offsetMm.y === 0) score -= 1;
    if (!a && !round && c.path === 'straight') score += Math.abs(c.offsetMm.y) / H;
    const busy = !!u && u.onArt > 0.5 && (u.edges > 0.05 || weak > 0.1);
    if (!best || score < best.score) best = { score, busy, patch: { ...c, heightMm: h, angleDeg: 0, insetMm: 0 } };
  }
  // No free spot on a design that fills the patch: make the design a bit smaller and put the text in the band that
  // frees up (an arc under it on a round patch, a line under it otherwise). The UI offers to undo this.
  if (a && (!best || best.busy) && !others.length && (state.art || {}).scale >= 0.95) {
    const shrink = shrinkForText(state, d, config, layer);
    if (shrink) return shrink;
  }
  return best ? best.patch : { path: 'straight', offsetMm: { x: 0, y: 0 }, heightMm: fitHeight(layer, layout, config, want) };
}

/** Layer patch + `_art` (smaller design) that leaves room for a text under the design, or null. */
function shrinkForText(state, d, config, layer) {
  const L = d.layout, T = config.text || {};
  const W = L.patchWmm, H = L.patchHmm;
  const round = L.shape === 'circle' || L.shape === 'oval';
  const edge = edgeCoverMm(L.edge || {}) || (L.edge && L.edge.widthMm) || 2;
  const lim = config.layout || {};
  const minScale = Math.max(lim.artScaleMin || 0.2, 0.66);
  const art = state.art || { scale: 1, offsetMm: { x: 0, y: 0 } };
  let h = Math.max(T.minHeightMm || 3, Math.min(T.defaultHeightMm || 6, Math.min(W, H) * 0.075));
  for (let i = 0; i < 4; i++) {
    let scale, patch;
    if (round) {
      const R = Math.min(W, H) / 2, rf = d.analysis && d.analysis.shape && d.analysis.shape.fillsPatch ? rimFrac(d.analysis) : 0;
      scale = (R - edge - h - 1.8) / (R * (1 - rf));
      patch = { path: 'arc_bottom', offsetMm: { x: 0, y: 0 }, heightMm: h, angleDeg: 0, insetMm: 0 };
    } else {
      scale = (H - 2 * edge - h - 2.6) / H;
      patch = { path: 'straight', offsetMm: { x: 0, y: Math.round((H / 2 - edge - 1 - h / 2) * 2) / 2 }, heightMm: h, angleDeg: 0, insetMm: 0 };
    }
    scale = Math.floor(Math.min(0.92, scale) * 100) / 100;
    if (scale < minScale) { h -= 0.5; if (h < (T.minHeightMm || 3)) return null; continue; }
    const l = { ...layer, ...patch };
    const fit = fitHeight(l, L, config, h);
    const offY = round ? 0 : -Math.round(((H - H * scale) / 2 - edge * 0.5) * 2) / 2;
    return { ...patch, heightMm: fit, _art: { ...art, scale, offsetMm: { x: 0, y: offY } } };
  }
  return null;
}


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
    // photographed rim of a patch-like design: masked away, the generated border takes its place (critique-1 #5),
    // also in its own shape: a ribbed rim in the photo must never show as a second border (critique-2 #8)
    clip: a.shape && a.shape.fillsPatch && !d.keepBg ? { shape: a.shape.type, inset: rimFrac(a) / 2 } : null,
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
    edgeWidth: state.edgeWidth && state.edgeWidth !== 'normal' && edgeWidthApplies(L.edge) ? state.edgeWidth : 'normal',
    // until the production sheet shows the border width itself, a non-standard width travels in the notes too
    notes: [state.edgeWidth && state.edgeWidth !== 'normal' && edgeWidthApplies(L.edge) ? `[${state.edgeWidth === 'wide' ? 'Brede' : 'Smalle'} rand: ${L.edge.widthMm} mm]` : '', state.notes || ''].filter(Boolean).join(' '),
    ...extra,
  };
}
