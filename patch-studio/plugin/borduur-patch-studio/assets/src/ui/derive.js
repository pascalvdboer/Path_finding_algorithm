/**
 * Patch Studio UI — derived data: colours, advice, quotes, layout, render options and the DesignPayload.
 * Everything other modules provide is used ONLY through their public index.js contracts (SPEC §7).
 */
import { layoutPatch } from '../shared/geometry.js';
import { label } from '../shared/config.js';
import { prepareThreads, matchPalette, reducePalette, contrastAgainst, pickFabric, pickEdgeColor, threadByCode } from '../colors/index.js';
import { evaluate, measure } from '../advice/index.js';
import { quote, quoteAll } from '../pricing/index.js';
import { estimateStitches } from '../analysis/index.js';

const memo = new Map();
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
    ? cached('r' + job + ':' + state.maxThreadColors, () => reducePalette(analysis.palette, state.maxThreadColors, { prepared, config, kL }))
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
  const backgroundHex = keepBg ? bg.hex : null;
  const groundHex = backgroundHex || fabricHex;

  // Text colours: 'auto' = best-contrast thread against the patch ground.
  const autoText = pickEdgeColor(groundHex, [], prepared, config);
  const textHex = {};
  for (const l of state.texts || []) {
    const th = l.threadCode !== 'auto' ? threadByCode(l.threadCode, config.threads || []) : null;
    textHex[l.id] = (th && th.hex) || (autoText && autoText.hex) || '#1e1b1b';
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

  const advice = evaluate({
    analysis,
    design: state,
    config,
    locale,
    colors: { reduced, matches, contrast, fabricHex: groundHex },
    quotes,
    context: { useCase: state.useCase || '' },
  });

  const layout = layoutPatch(state, analysis, config);
  const meas = measure({ analysis, design: state, config });

  return {
    analysis, prepared, matches, reduced, reducedColors, threadOf, useThreads, colorList,
    fabrics, fabric, fabricPick: pick, fabricHex, backgroundHex, groundHex, keepBg,
    textHex, edgeThread, edgeHex, autoEdge, contrast, quotes, quote: q, advice, layout, meas,
    quoteMode: tech.mode === 'quote' || q.mode === 'quote',
  };
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
      threadCode: l.threadCode === 'auto' ? autoTextCode(d.textHex[l.id]) : l.threadCode,
      path: l.path,
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
