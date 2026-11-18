/**
 * Patch Studio — advice engine (SPEC §7.7). Pure and deterministic: same input → same output, no DOM, no I/O.
 *
 * Per technique: status ok/warn/bad with measured reasons (mm on the finished patch, via layoutPatch), concrete
 * fixes in the order "size first, then simplify colours, then switch technique" (research 04 §9.3), a ranking with
 * an honest recommendation, a summary written like a craftsman at the counter (research 05 §4–5), size and
 * thread-colour advice, relevant upsells only, trust lines from config.promises and general warnings.
 *
 * Every number, threshold and price comes from the config; texts come from messages.js (NL + FR).
 */
import { enabledTechniques, optionById, optionPrice } from '../shared/config.js';
import { hexToLab } from '../shared/color-math.js';
import { prepareThreads, reducePalette, pickFabric } from '../colors/index.js';
import { makeContext } from './context.js';
import {
  optionsForTechnique, fontFlags, sizeCheck, sizeCandidates, orderMaxCm,
  smallestPassingSize, minSizeForTechnique, colorCount,
} from './measure.js';

const EPS = 1e-9;

/**
 * Base suitability per design type (0..100) before measured penalties. Craft knowledge from research 04 §2–9
 * and 05 §4.3: flat logos are what embroidery is made for; shaded illustrations: woven most detail, embroidery after
 * simplification, print exact; gradients and photos belong to print/sublimation.
 */
const KIND_FIT = {
  flat: { embroidered: 100, woven: 90, printed: 86, sublimated: 84, pvc: 88, chenille: 78, leather: 72 },
  illustration: { embroidered: 94, woven: 95, printed: 88, sublimated: 86, pvc: 62, chenille: 30, leather: 58 },
  gradient: { embroidered: 74, woven: 74, printed: 96, sublimated: 95, pvc: 40, chenille: 30, leather: 60 },
  photo: { embroidered: 15, woven: 20, printed: 96, sublimated: 96, pvc: 10, chenille: 5, leather: 55 },
  text: { embroidered: 100, woven: 86, printed: 82, sublimated: 80, pvc: 78, chenille: 70, leather: 76 },
  unknown: { embroidered: 90, woven: 80, printed: 85, sublimated: 82, pvc: 70, chenille: 60, leather: 65 },
};
const DEFAULT_FIT = 70;

/** Score penalties (points). Small for normal craft steps, larger for real compromises. */
const PEN = {
  warnBase: 6,
  resize: 10, resizeQuote: 18, line: 6, textComfort: 5, lineComfort: 3, gap: 4,
  reduceColors: 8, shadedSimplify: 4, gradientSimplify: 10, gradientLimited: 8, flatten: 12, grayscale: 10,
  photoGrayscale: 20, colorsComfort: 3, noThread: 10, contrastBad: 8, contrastLow: 2, contrastAuto: 3,
  lowRes: 10, merrow: 5, minQty: 12, quoteMode: 3, sizeTooLarge: 10, layerText: 6, maxPrice: 6,
};
const BAD_CAP = 30;

/** Use cases where hook-and-loop backing is the professional standard (research 04 §11). */
const VELCRO_USES = new Set(['uniform', 'uniforms', 'workwear', 'werkkleding', 'airsoft', 'tactical', 'military', 'militair',
  'security', 'police', 'politie', 'fire', 'brandweer', 'rescue', 'hulpdienst', 'scouts', 'outdoor']);
const IRON_ON_BAD_FABRICS = new Set(['leather', 'leer', 'nylon', 'fleece', 'coated', 'gecoat', 'softshell', 'waterproof']);
const WORKWEAR_USES = new Set(['workwear', 'werkkleding', 'uniform', 'uniforms']);

const fabricVisible = (tech, id) => tech.fabricList === 'fabrics' && !tech.noWhiteInk && id !== 'leather';
const chroma = (lab) => Math.sqrt(lab[1] * lab[1] + lab[2] * lab[2]);
const ceilHalf = (x) => Math.ceil(x * 2 - EPS) / 2;

/* ------------------------------------------------------------------------------------------------ */

function kindOf(analysis, design) {
  if (!analysis) {
    const hasText = (design.texts || []).some((l) => l && String(l.text || '').trim());
    return design.source && design.source.kind === 'text' ? 'text' : hasText ? 'text' : 'unknown';
  }
  const k = analysis.kind;
  return KIND_FIT[k] ? k : 'flat';
}

function isShaded(analysis, kind) {
  return !!analysis && (kind === 'illustration' || (kind === 'flat' && analysis.hasSoftShading));
}

/** Option list where the option of `group` is replaced by `id` (plus a default velcro colour when needed). */
function withOption(design, config, techId, group, id) {
  const all = config.options || [];
  const base = optionsForTechnique(design, config, techId).filter((x) => (all.find((o) => o.id === x) || {}).group !== group);
  base.push(id);
  const needsColour = all.some((o) => o.group === 'velcro_color' && (o.techniques || []).includes(techId) && o.enabled !== false
    && (o.requiresAny || []).includes(id));
  if (needsColour && !base.some((x) => (all.find((o) => o.id === x) || {}).group === 'velcro_color')) {
    const def = all.find((o) => o.group === 'velcro_color' && o.default && (o.techniques || []).includes(techId))
      || all.find((o) => o.group === 'velcro_color' && (o.techniques || []).includes(techId));
    if (def) base.push(def.id);
  }
  return base;
}

/** Patch for a fix on a technique card: choosing a fix on another card also chooses that technique. */
function setPatch(design, techId, patch) {
  if (techId !== design.technique) return { type: 'set', patch: Object.assign({ technique: techId, techniquePicked: true }, patch) };
  return { type: 'set', patch };
}

function fabricLabel(config, ctx, id) {
  const f = (config.fabrics || []).find((x) => x.id === id);
  return f ? ctx.label(f.label).toLowerCase() : id;
}

function fabricIdByHex(config, hex) {
  if (!hex) return null;
  const h = String(hex).toLowerCase();
  const f = (config.fabrics || []).find((x) => String(x.hex).toLowerCase() === h);
  return f ? f.id : null;
}

/* ---------------------------------- thread colour suggestion ---------------------------------- */

const kCache = new WeakMap();

/**
 * Smallest choice from advice.threadColorChoices that holds the palette without forced merges (colors module),
 * i.e. without visibly changing the design (fewer colours than the smallest choice: that number). Falls back to advice.defaultMaxThreadColors without a thread list.
 */
function suggestThreadColors(analysis, config, nPalette) {
  const adv = config.advice || {};
  const emb = (config.techniques.embroidered && config.techniques.embroidered.rules && config.techniques.embroidered.rules.colors) || {};
  const max = emb.max || 12;
  const choices = (adv.threadColorChoices || [6, 8, 10, 12]).filter((c) => c <= max).sort((a, b) => a - b);
  const fallback = Math.min(adv.defaultMaxThreadColors || 8, max);
  if (!analysis || !nPalette) return fallback;
  if (nPalette <= (choices[0] || fallback)) return Math.max(1, nPalette);
  let per = kCache.get(analysis);
  if (per && per.config === config) return per.k;
  let k = choices.length ? choices[choices.length - 1] : fallback;
  const threads = config.threads || [];
  if (!threads.length) k = Math.min(fallback, nPalette);
  else {
    const prepared = prepareThreads(threads);
    const minShare = (config.analysis && config.analysis.minShare) || 0.004;
    const pal = (analysis.palette || []).filter((c) => c && ((c.share || 0) >= minShare || c.thin));
    // One reduction to a single colour: every merge the colors module does before it has to force one is a
    // "natural" merge (same thread or similar colour). What is left is the honest number of thread colours.
    const r = reducePalette(pal, 1, { prepared, config });
    const natural = Math.max(1, pal.length - (r.merges || []).filter((m) => m.reason !== 'forced').length);
    // One number for the advice text, the "simplify" button and the slider (critique-1 #12): a design with more
    // colours than the smallest choice is never advised below that choice (a grey ground and a beige cape stay two
    // thread colours instead of folding into white/black, critique-1 #2).
    const floor = choices[0] || fallback;
    if (natural < floor && pal.length > floor) { kCache.set(analysis, { config, k: floor }); return floor; }
    k = natural <= (choices[0] || fallback) ? natural : (choices.find((c) => c >= natural) || choices[choices.length - 1] || fallback);
  }
  kCache.set(analysis, { config, k });
  return k;
}

/* ---------------------------------- per technique ---------------------------------- */

function evalTechnique(t, env) {
  const { analysis, design, config, ctx, kind, cc, colors, context } = env;
  const tech = config.techniques[t];
  const rules = tech.rules || {};
  const cr = rules.colors || {};
  const tp = ctx.techParams(t);
  const sizeCm = env.sizeCm;
  const reasons = [];
  const fixes = [];
  const notes = [];
  let score = (KIND_FIT[kind] || KIND_FIT.unknown)[t] ?? DEFAULT_FIT;
  let variant = null;
  let resizeCm = null;
  let threadColors = null;

  const add = (code, severity, key, params = {}, extra = {}) => {
    reasons.push(Object.assign({ code, severity, msg: ctx.m(key, Object.assign({}, tp, params)) }, extra));
  };
  const fix = (code, key, params, action) => {
    const sig = code + JSON.stringify(action);
    if (fixes.some((f) => f._sig === sig)) return;
    fixes.push({ code, msg: ctx.m(key, Object.assign({}, tp, params)), action, _sig: sig });
  };
  const note = (key, params = {}) => notes.push(ctx.m(key, Object.assign({}, tp, params)));
  const resizeFix = (target) => {
    fix('resize', target.quote ? 'advice.fix.resize_quote' : 'advice.fix.resize', { cm: ctx.num(target.cm) },
      setPatch(design, t, { sizeCm: target.cm }));
  };

  const isPhoto = kind === 'photo';
  const shaded = isShaded(analysis, kind);
  let reduceK = null;

  /* 1. Design type --------------------------------------------------------------------------- */
  if (analysis) {
    if (isPhoto) {
      if (cr.photo === true) add('photo', 'ok', 'advice.reason.photo_ok');
      else if (cr.photo === 'grayscale_engraving') { add('photo', 'warn', 'advice.reason.photo_leather'); score -= PEN.photoGrayscale; }
      else add('photo', 'bad', t === 'pvc' ? 'advice.reason.photo_pvc' : 'advice.reason.photo_thread');
    } else if (kind === 'gradient') {
      const g = cr.gradients;
      if (g === true) add('gradient', 'ok', 'advice.reason.gradient_ok');
      else if (g === 'simplify') {
        reduceK = Math.min(env.suggestedK, cr.max || env.suggestedK);
        add('gradient', 'warn', 'advice.reason.gradient_simplify'); score -= PEN.gradientSimplify; variant = 'reduce';
      } else if (g === 'limited') { add('gradient', 'warn', 'advice.reason.gradient_woven'); score -= PEN.gradientLimited; }
      else if (g === 'grayscale') { add('gradient', 'warn', 'advice.reason.shaded_grayscale'); score -= PEN.grayscale; }
      else add('gradient', 'bad', 'advice.reason.gradient_no');
    } else if (shaded) {
      const s = cr.shadedIllustration;
      if (s === 'exact') add('gradient', 'ok', t === 'sublimated' ? 'advice.reason.exact_sublimated' : 'advice.reason.exact');
      else if (s === 'good') add('gradient', 'ok', 'advice.reason.shaded_woven', { line: ctx.num((rules.lineMm && rules.lineMm.comfort) || 0.5) });
      else if (s === 'simplify') {
        reduceK = Math.min(env.suggestedK, cr.max || env.suggestedK);
        add('too_many_colors', 'warn', 'advice.reason.shaded_simplify', { k: String(reduceK) }, { measured: cc.n, limit: reduceK, unit: 'colors' });
        score -= PEN.shadedSimplify; variant = 'reduce';
      } else if (s === 'flatten_or_3d') {
        reduceK = Math.min(cc.n || cr.max || 6, cr.comfortMax || cr.max || 6);
        add('gradient', 'warn', 'advice.reason.shaded_flatten'); score -= PEN.flatten; variant = 'reduce';
      } else if (s === 'grayscale') { add('gradient', 'warn', 'advice.reason.shaded_grayscale'); score -= PEN.grayscale; }
      else if (s === 'no') add('gradient', 'bad', 'advice.reason.shaded_no');
    }
  }

  /* 2. Colour count ------------------------------------------------------------------------------ */
  if (analysis && !isPhoto && kind !== 'gradient' && !shaded) {
    if (tech.monochrome) {
      if (cc.n > 1) add('monochrome', 'ok', 'advice.reason.leather_mono');
    } else if (cr.max != null && cc.n > cr.max) {
      reduceK = Math.min(cr.max, tech.threadBased ? env.suggestedK : (cr.comfortMax || cr.max));
      add('too_many_colors', 'warn', 'advice.reason.too_many_colors',
        { n: String(cc.n), max: String(cr.max), k: String(reduceK) }, { measured: cc.n, limit: cr.max, unit: 'colors' });
      score -= PEN.reduceColors; variant = variant || 'reduce';
    } else if (cr.comfortMax && cc.n > cr.comfortMax) {
      add('colors_many', 'ok', 'advice.reason.colors_many', { n: String(cc.n), max: String(cr.max), comfort: String(cr.comfortMax) });
      score -= PEN.colorsComfort;
    } else if (cc.n > 0 && cr.max != null) {
      add('colors_ok', 'ok', 'advice.reason.colors_ok', { n: cc.n });
    }
  }
  if (reduceK != null) {
    fix('reduce_colors', tech.threadBased ? 'advice.fix.reduce' : 'advice.fix.reduce_plain', { k: String(reduceK) },
      setPatch(design, t, { maxThreadColors: reduceK }));
    if (tech.threadBased && t === design.technique && colors.reduced && colors.reduced.forced) note('advice.reason.colors_forced');
  }
  if (tech.threadBased) {
    if (t === design.technique && colors.reduced && colors.reduced.k) threadColors = colors.reduced.k + cc.textThreads;
    else if (reduceK != null) threadColors = reduceK + cc.textThreads;
    else if (cc.n) threadColors = cr.max ? Math.min(cc.n, cr.max) : cc.n;
  }

  /* 3. Colour that does not exist as thread (e.g. the brand purple) ------------------------------- */
  if (tech.threadBased && analysis && !isPhoto && Array.isArray(colors.matches)) {
    const pal = analysis.palette || [];
    const missing = colors.matches.some((m, i) => m && m.quality === 'none' && pal[i] && (pal[i].share || 0) >= 0.05);
    if (missing) {
      add('no_thread_match', 'warn', 'advice.reason.no_thread_match'); score -= PEN.noThread;
      if (config.techniques.printed && config.techniques.printed.enabled && t !== 'printed') {
        fix('switch_technique', 'advice.fix.switch', ctx.techParams('printed'), { type: 'set', patch: { technique: 'printed', techniquePicked: true } });
      }
    }
  }

  /* 4. Measured details: text, lines, gaps, size -------------------------------------------------- */
  const cur = sizeCheck(analysis, design, config, t, sizeCm);
  const meas = cur.meas;
  const minCm = Math.max(Number(tech.minCm) || 0, ((rules.sizeMm && rules.sizeMm.hard) || 0) / 10);
  const { techMax, maxOrder } = sizeCandidates(config, t);
  const line = rules.lineMm;
  const lineBad = !isPhoto && meas.lineMm != null && line && meas.lineMm + EPS < line.hard;
  const textBad = !isPhoto && meas.text && meas.text.ratio + EPS < 1;
  const sizeSmall = sizeCm + EPS < minCm;

  if (!isPhoto && meas.text && !textBad) {
    const p = { measured: ctx.mm(meas.text.measured), limit: ctx.num(meas.text.limit), comfort: ctx.num(meas.text.comfort) };
    const extra = { measured: meas.text.measured, limit: meas.text.limit, unit: 'mm' };
    if (meas.text.comfortRatio + EPS < 1) { add('text_small', 'ok', 'advice.reason.text_small', p, extra); score -= PEN.textComfort; }
    else add('text_ok', 'ok', 'advice.reason.text_ok', p, extra);
  }
  if (!isPhoto && meas.lineMm != null && line && !lineBad) {
    const p = { measured: ctx.mm(meas.lineMm), limit: ctx.num(line.hard), comfort: ctx.num(line.comfort || line.hard) };
    if (line.comfort && meas.lineMm + EPS < line.comfort) { add('line_small', 'ok', 'advice.reason.line_small', p); score -= PEN.lineComfort; }
    else if (!meas.text) add('line_ok', 'ok', 'advice.reason.line_ok', p, { measured: meas.lineMm, limit: line.hard, unit: 'mm' });
  }
  if (!isPhoto && meas.gapMm != null && rules.gapMm && meas.gapMm + EPS < rules.gapMm.hard) {
    add('gap_too_small', 'ok', 'advice.reason.gap_too_small', { measured: ctx.mm(meas.gapMm), limit: ctx.num(rules.gapMm.hard) },
      { measured: meas.gapMm, limit: rules.gapMm.hard, unit: 'mm' });
    score -= PEN.gap;
  }

  if (textBad || sizeSmall) {
    const linePred = (c) => c.ok && (!lineBad || c.meas.lineMm == null || c.meas.lineMm + EPS >= line.hard);
    const target = smallestPassingSize(analysis, design, config, t, sizeCm, { pred: linePred })
      || smallestPassingSize(analysis, design, config, t, sizeCm);
    const sev = target ? 'warn' : 'bad';
    if (textBad) {
      const tx = meas.text;
      const key = tx.lower ? 'advice.reason.text_lower_too_small' : tx.serif ? 'advice.reason.text_too_small_serif' : 'advice.reason.text_too_small';
      add('text_too_small', sev, key, { measured: ctx.mm(tx.measured), limit: ctx.num(tx.limit) }, { measured: tx.measured, limit: tx.limit, unit: 'mm' });
    }
    if (sizeSmall) {
      add('size_too_small', target ? 'warn' : 'bad', 'advice.reason.size_too_small', { limit: ctx.num(minCm) }, { measured: sizeCm, limit: minCm, unit: 'cm' });
    }
    if (target) {
      resizeFix(target); resizeCm = target.cm; variant = 'resize';
      score -= target.quote ? PEN.resizeQuote : PEN.resize;
    }
  }
  if (lineBad) {
    const target = (resizeCm == null) ? smallestPassingSize(analysis, design, config, t, sizeCm,
      { pred: (c) => c.ok && (c.meas.lineMm == null || c.meas.lineMm + EPS >= line.hard) }) : null;
    const run = line.runStitchHard && meas.lineMm + EPS >= line.runStitchHard;
    add('line_too_thin', 'warn', run ? 'advice.reason.line_too_thin_run' : 'advice.reason.line_too_thin',
      { measured: ctx.mm(meas.lineMm), limit: ctx.num(line.hard) }, { measured: meas.lineMm, limit: line.hard, unit: 'mm' });
    score -= PEN.line;
    if (target && !target.quote) { resizeFix(target); resizeCm = target.cm; variant = variant || 'resize'; }
  }
  if (sizeCm > techMax + EPS) {
    const down = [...sizeCandidates(config, t).priced].reverse().find((s) => s <= techMax) || techMax;
    add('size_too_large', 'warn', 'advice.reason.size_too_large', { limit: ctx.num(techMax) }, { measured: sizeCm, limit: techMax, unit: 'cm' });
    fix('resize', 'advice.fix.resize', { cm: ctx.num(down) }, setPatch(design, t, { sizeCm: down }));
    score -= PEN.sizeTooLarge;
  } else if (tech.mode === 'order' && sizeCm > maxOrder + EPS) {
    note('advice.reason.size_quote', { max: ctx.num(maxOrder), limit: ctx.num(techMax) });
    fix('resize', 'advice.fix.resize', { cm: ctx.num(maxOrder) }, setPatch(design, t, { sizeCm: maxOrder }));
    fix('quote', 'advice.fix.quote', {}, { type: 'quote' });
  }

  /* 5. Text layers (absolute mm, independent of the patch size) ------------------------------------ */
  const caps = rules.textCapsMm;
  if (caps) {
    const layout = cur.layout;
    const maxH = Math.min((config.text && config.text.maxHeightMm) || 40, layout.innerMm.h * 0.6);
    let worst = null;
    const enlarged = [];
    let changed = false;
    for (const l of design.texts || []) {
      if (!l || !String(l.text || '').trim()) { enlarged.push(l); continue; }
      const ff = fontFlags(config, l.font);
      const limit = caps.hard * (ff.strict ? (rules.serifScriptFactor || 1) : 1);
      const h = Number(l.heightMm) || 0;
      if (h + EPS < limit) {
        const need = ceilHalf(limit);
        const ratio = h / limit;
        if (!worst || ratio < worst.ratio) worst = { l, h, limit, need, ratio };
        if (need <= maxH + EPS) { enlarged.push(Object.assign({}, l, { heightMm: need })); changed = true; continue; }
      }
      enlarged.push(l);
    }
    if (worst) {
      const txt = String(worst.l.text).trim();
      const short = txt.length > 24 ? txt.slice(0, 23) + '…' : txt;
      if (worst.need <= maxH + EPS) {
        add('text_too_small', 'warn', 'advice.reason.layer_text_too_small',
          { text: short, measured: ctx.num(worst.h), limit: ctx.num(worst.limit) }, { measured: worst.h, limit: worst.limit, unit: 'mm' });
        score -= PEN.layerText;
      } else {
        add('text_too_small', 'bad', 'advice.reason.layer_text_too_big', { text: short, limit: ctx.num(worst.limit) },
          { measured: worst.h, limit: worst.limit, unit: 'mm' });
      }
      if (changed) fix('enlarge_text', 'advice.fix.enlarge_text', { mm: ctx.num(worst.need) }, setPatch(design, t, { texts: enlarged }));
    }
  }

  /* 6. Edge ---------------------------------------------------------------------------------------- */
  const edgeId = optionsForTechnique(design, config, t).find((id) => (optionById(config, id) || {}).group === 'edge');
  const edgeOpt = edgeId ? optionById(config, edgeId) : null;
  if (edgeOpt && edgeOpt.render && edgeOpt.render.style === 'merrow') {
    const alt = (config.options || []).find((o) => o.group === 'edge' && o.id !== edgeOpt.id && o.enabled !== false && (o.techniques || []).includes(t));
    const mwHard = rules.sizeMm && rules.sizeMm.hardWithMerrow;
    let problem = null;
    if (edgeOpt.shapes && !edgeOpt.shapes.includes(design.shape)) problem = ['merrow_shape', 'advice.reason.merrow_shape', {}];
    else if (mwHard && sizeCm * 10 + EPS < mwHard) problem = ['merrow_small', 'advice.reason.merrow_small', { limit: ctx.num(mwHard / 10) }];
    if (problem) {
      add(problem[0], 'warn', problem[1], problem[2]); score -= PEN.merrow;
      if (alt) fix('change_edge', 'advice.fix.edge', { edge: ctx.label(alt.label).toLowerCase() }, setPatch(design, t, { options: withOption(design, config, t, 'edge', alt.id) }));
    }
  }

  /* 7. Contrast with the fabric (only when the fabric shows and the design's own background is not kept) */
  const bgKept = !!(analysis && analysis.background && analysis.background.keep);
  if (fabricVisible(tech, t) && !bgKept && Array.isArray(colors.contrast) && colors.contrast.length) {
    const bad = colors.contrast.some((c) => c && c.level === 'bad');
    const low = colors.contrast.some((c) => c && c.level === 'low');
    if (bad) {
      const curId = design.fabric && design.fabric !== 'auto' ? design.fabric : fabricIdByHex(config, colors.fabricHex);
      const alt = env.autoFabric;
      if (alt && alt.id && alt.id !== curId) {
        add('low_contrast', 'warn', 'advice.reason.low_contrast', { fabric: fabricLabel(config, ctx, curId || '') });
        fix('change_fabric', 'advice.fix.fabric', { fabric: fabricLabel(config, ctx, alt.id) }, setPatch(design, t, { fabric: alt.id }));
        score -= PEN.contrastBad;
      } else { note('advice.note.contrast_auto'); score -= PEN.contrastAuto; }
    } else if (low) score -= PEN.contrastLow;
  }

  /* 8. Resolution --------------------------------------------------------------------------------- */
  if (analysis && analysis.resolution && analysis.resolution.lowRes) {
    if (!tech.threadBased && t !== 'pvc' && t !== 'leather') {
      add('low_res', 'warn', 'advice.reason.low_res_print',
        { px: String(Math.round(analysis.resolution.effectiveLongestPx || 0)), cm: ctx.num(sizeCm) });
      fix('upload_better', 'advice.fix.upload', {}, { type: 'upload' });
      score -= PEN.lowRes; variant = variant || 'lowres';
    } else note('advice.note.low_res_thread');
  }

  /* 9. Technique specials ------------------------------------------------------------------------- */
  if (tech.noWhiteInk) {
    const w = (config.analysis && config.analysis.white) || { L: 92, C: 6 };
    const whiteShare = ((analysis && analysis.palette) || []).reduce((s, c) => {
      const lab = c && (c.lab || (c.hex && hexToLab(c.hex)));
      return lab && lab[0] >= w.L && chroma(lab) <= w.C ? s + (c.share || 0) : s;
    }, 0);
    const fabricChosen = design.fabric && design.fabric !== 'auto' && design.fabric !== 'white';
    if (whiteShare >= 0.05 || fabricChosen) note('advice.note.sublimation_white');
  }
  if (analysis && analysis.shape && analysis.shape.fillsPatch && (analysis.textLines || []).length && cur.layout.edge && cur.layout.edge.widthMm > 0) {
    note('advice.note.rim', { mm: ctx.num(Math.max(cur.layout.edge.safeZoneMm || 0, cur.layout.edge.widthMm)) });
  }
  if (reasons.some((r) => r.severity === 'warn' && (r.code === 'too_many_colors' || r.code === 'gradient'))) note('advice.note.what_changes');

  /* 10. Mode, quantity, fees, lead time ----------------------------------------------------------- */
  const mode = tech.mode === 'quote' ? 'quote' : 'order';
  if (mode === 'quote') {
    note('advice.note.quote_mode'); score -= PEN.quoteMode;
    fix('quote', 'advice.fix.quote', {}, { type: 'quote' });
  }
  const minQty = Number(tech.minQty) || 1;
  if (minQty > 1) {
    const step = Number(tech.qtyStep) || 1;
    note(step > 1 ? 'advice.note.min_qty_step' : 'advice.note.min_qty', { n: String(minQty), step: String(step) });
    if ((Number(design.qty) || 0) < minQty) score -= PEN.minQty;
  }
  const fee = tech.feeGroup === 'pvc_mold' && config.fees && config.fees.groups && config.fees.groups.pvc_mold;
  if (fee && fee.amount > 0) note('advice.note.mold', { amount: ctx.money(fee.amount) });
  if (t === 'pvc') note('advice.note.no_iron_pvc');
  const lead = ctx.label(tech.leadTime);
  if (lead) note('advice.note.lead_time', { text: lead });
  if (t === design.technique && (design.options || []).includes('iron_on')) {
    const gf = String(context.garmentFabric || '').toLowerCase();
    const use = String(context.useCase || design.useCase || '').toLowerCase();
    if (IRON_ON_BAD_FABRICS.has(gf)) note('advice.note.iron_on_fabric');
    else if (WORKWEAR_USES.has(use)) note('advice.note.iron_on_workwear');
  }

  /* 11. Status, headline, score ------------------------------------------------------------------- */
  const sevRank = { ok: 0, warn: 1, bad: 2 };
  let status = 'ok';
  for (const r of reasons) if (sevRank[r.severity] > sevRank[status]) status = r.severity;
  if (status === 'warn') score -= PEN.warnBase;
  if (status === 'bad') score = Math.min(score, BAD_CAP);
  reasons.sort((a, b) => sevRank[b.severity] - sevRank[a.severity]); // most important first (stable)

  const orderable = mode === 'order' && status !== 'bad' && sizeCm <= maxOrder + EPS;
  const hv = status === 'ok' ? 'ok' : status === 'bad' ? 'bad' : (variant || 'warn');
  const hp = Object.assign({}, tp, { cm: ctx.num(resizeCm || sizeCm) });
  let headline = ctx.m(`advice.headline.${t}.${hv}`, hp);
  if (headline.text === headline.key) headline = ctx.m(`advice.headline.${t}.${status}`, hp);
  if (headline.text === headline.key) headline = ctx.m(`advice.headline.generic.${hv === 'reduce' || hv === 'lowres' ? 'warn' : hv}`, hp);

  return {
    technique: t,
    status,
    score: Math.max(0, Math.min(100, score)),
    mode,
    orderable,
    headline,
    reasons,
    fixes,
    threadColors,
    notes,
    _resizeCm: resizeCm,
    _meas: meas,
  };
}

/* ---------------------------------- whole design ---------------------------------- */

/**
 * @param {Object} input AdviceInput { analysis, design, config, locale, colors:{reduced, matches, contrast, fabricHex}, quotes?, context? }
 *        context (optional extra, see docs/handoff/advice.md): { useCase?:string, garmentFabric?:string }
 * @returns {import('../shared/types.js').AdviceResult}
 */
export function evaluate(input) {
  const { analysis = null, design, config } = input || {};
  if (!design || !config || !config.techniques) throw new TypeError('advice.evaluate: design and config are required');
  const colors = (input && input.colors) || {};
  const context = (input && input.context) || {};
  const ctx = makeContext(config, (input && input.locale) || design.locale || config.locale);
  const techs = enabledTechniques(config);
  const kind = kindOf(analysis, design);
  const cc = colorCount(analysis, design, config, colors.matches);
  const sizeCm = Number(design.sizeCm) || (config.sizes && config.sizes.defaultCm) || 8;
  const advCfg = config.advice || {};

  // Auto fabric suggestion (used for contrast fixes and autoChoices).
  let autoFabric = null;
  if (Array.isArray(config.fabrics) && config.fabrics.length) {
    const list = (colors.reduced && colors.reduced.colors) || (analysis && analysis.palette) || [];
    autoFabric = pickFabric(list, config.fabrics, config, { background: analysis ? analysis.background : null });
  }

  const env = {
    analysis, design, config, ctx, kind, cc, colors, context, sizeCm, autoFabric,
    suggestedK: suggestThreadColors(analysis, config, cc.palette),
  };

  const verdicts = {};
  for (const t of techs) verdicts[t] = evalTechnique(t, env);

  // Small price factor (never more than PEN.maxPrice points): honest, the cheaper technique may win.
  const quotes = input && input.quotes;
  if (quotes) {
    const prices = techs.map((t) => quotes[t]).filter((q) => q && q.ok && q.mode === 'order' && q.perPieceAllInIncl > 0).map((q) => q.perPieceAllInIncl);
    const min = prices.length ? Math.min(...prices) : 0;
    if (min > 0) {
      for (const t of techs) {
        const q = quotes[t];
        if (q && q.ok && q.mode === 'order' && q.perPieceAllInIncl > 0 && verdicts[t].status !== 'bad') {
          verdicts[t].score = Math.max(0, verdicts[t].score - Math.min(PEN.maxPrice, 10 * (q.perPieceAllInIncl / min - 1)));
        }
      }
    }
  }
  for (const t of techs) verdicts[t].score = Math.round(verdicts[t].score * 10) / 10;

  // Ranking.
  const order = config.techniqueOrder || techs;
  const ranking = techs.slice().sort((a, b) => {
    const A = verdicts[a], B = verdicts[b];
    if (B.score !== A.score) return B.score - A.score;
    if (A.orderable !== B.orderable) return A.orderable ? -1 : 1;
    return order.indexOf(a) - order.indexOf(b);
  });
  const delta = advCfg.closeScoreDelta ?? 8;
  if (advCfg.preferOrderable !== false && ranking.length && !verdicts[ranking[0]].orderable) {
    const top = verdicts[ranking[0]].score;
    const idx = ranking.findIndex((t) => verdicts[t].orderable && top - verdicts[t].score <= delta + EPS);
    if (idx > 0) ranking.unshift(ranking.splice(idx, 1)[0]);
  }
  const recommended = ranking[0];

  // Green alternatives: every warn/bad verdict gets a "switch" fix to the best ok technique (a red card falls back to
  // the best warn technique when nothing is green); never a dead end.
  const bestOk = (except) => ranking.find((t) => t !== except && verdicts[t].status === 'ok' && verdicts[t].orderable)
    || ranking.find((t) => t !== except && verdicts[t].status === 'ok')
    || (verdicts[except].status === 'bad' ? ranking.find((t) => t !== except && verdicts[t].status === 'warn') : undefined);
  for (const t of techs) {
    const v = verdicts[t];
    if (v.status === 'ok') continue;
    const alt = bestOk(t);
    const hasSwitch = v.fixes.some((f) => f.code === 'switch_technique');
    if (alt && !hasSwitch && (v.status === 'bad' || v.fixes.length < 2)) {
      v.fixes.push({ code: 'switch_technique', msg: ctx.m('advice.fix.switch', ctx.techParams(alt)),
        action: { type: 'set', patch: { technique: alt, techniquePicked: true } } });
    }
    if (!v.fixes.length) v.fixes.push({ code: 'quote', msg: ctx.m('advice.fix.expert'), action: { type: 'quote' } });
  }

  const size = sizeAdvice(env, verdicts, recommended, techs);
  const summary = summaryAdvice(env, verdicts, ranking, recommended, size, delta);
  const colorAdvice = colorsAdvice(env);
  const upsells = upsellAdvice(env, verdicts, recommended);
  const trust = trustLines(env);
  const warnings = generalWarnings(env);

  // Strip internals.
  const outVerdicts = {};
  for (const t of techs) {
    const { _resizeCm, _meas, ...v } = verdicts[t];
    v.fixes = v.fixes.map(({ _sig, ...f }) => f);
    outVerdicts[t] = v;
  }

  return {
    recommended,
    ranking,
    verdicts: outVerdicts,
    summary,
    size,
    colors: colorAdvice,
    upsells,
    trust,
    warnings,
    autoChoices: {
      designFill: (analysis && analysis.shape && analysis.shape.type) || design.shape || 'circle',
      fabric: autoFabric ? autoFabric.id : null,
    },
  };
}

/* ---------------------------------- size ---------------------------------- */

function sizeAdvice(env, verdicts, rt, techs) {
  const { analysis, design, config, ctx, sizeCm } = env;
  const minCmByTechnique = {};
  for (const t of techs) minCmByTechnique[t] = minSizeForTechnique(analysis, design, config, t);

  const tech = config.techniques[rt] || {};
  const rules = tech.rules || {};
  const line = rules.lineMm;
  const cur = sizeCheck(analysis, design, config, rt, sizeCm);
  const lineBad = env.kind !== 'photo' && cur.meas.lineMm != null && line && cur.meas.lineMm + EPS < line.hard;
  const textBad = env.kind !== 'photo' && !cur.ok;
  if (textBad || lineBad) {
    const pred = (c) => c.ok && (c.meas.lineMm == null || !line || c.meas.lineMm + EPS >= line.hard);
    const target = smallestPassingSize(analysis, design, config, rt, sizeCm, { pred })
      || smallestPassingSize(analysis, design, config, rt, sizeCm);
    if (target) {
      const m = target.check.meas;
      const cm = ctx.num(target.cm);
      let reason;
      if (target.quote) reason = ctx.m('advice.size.need_quote', { cm });
      else if (textBad && m.text) reason = ctx.m('advice.size.need_text', { cm, mm: ctx.mm(m.text.measured) });
      else if (m.lineMm != null && lineBad) reason = ctx.m('advice.size.need_line', { cm, mm: ctx.mm(m.lineMm) });
      else reason = ctx.m('advice.size.need_min', Object.assign({ cm }, ctx.techParams(rt)));
      return { recommendedCm: target.cm, reason, minCmByTechnique };
    }
  }
  // Only claim "goed leesbaar / blijft scherp" when the comfort value is reached; otherwise say plainly that it works.
  let reason = null;
  if (env.kind !== 'photo' && cur.meas.text) {
    const comfy = cur.meas.text.comfortRatio + EPS >= 1;
    reason = ctx.m(comfy ? 'advice.size.ok_text' : 'advice.size.ok_text_min', { cm: ctx.num(sizeCm), mm: ctx.mm(cur.meas.text.measured) });
  } else if (env.kind !== 'photo' && cur.meas.lineMm != null) {
    const comfy = !line || cur.meas.lineMm + EPS >= (line.comfort || line.hard);
    reason = ctx.m(comfy ? 'advice.size.ok_line' : 'advice.size.ok_line_min', { cm: ctx.num(sizeCm), mm: ctx.mm(cur.meas.lineMm) });
  }
  return { recommendedCm: sizeCm, reason, minCmByTechnique };
}

/* ---------------------------------- summary ---------------------------------- */

function summaryAdvice(env, verdicts, ranking, rt, size, delta) {
  const { analysis, design, config, ctx, kind, cc } = env;
  const rv = verdicts[rt];
  const tp = ctx.techParams(rt);
  const cm = ctx.num(size.recommendedCm);
  const quoteRoute = rv.mode === 'quote' || size.recommendedCm > orderMaxCm(config, rt) + EPS;

  let headline;
  if (quoteRoute) headline = ctx.m('advice.summary.headline.quote', tp);
  else {
    headline = ctx.m(`advice.summary.headline.${rt}`, Object.assign({ cm }, tp));
    if (headline.text === headline.key) headline = ctx.m('advice.summary.headline', Object.assign({ cm }, tp));
  }

  const parts = [];
  let sentences = 0;
  const push = (m, n) => { parts.push(m); sentences += n; };
  const printLike = !config.techniques[rt].threadBased && rt !== 'pvc' && rt !== 'leather';
  const printedOrRt = printLike ? rt : (config.techniques.printed && config.techniques.printed.enabled ? 'printed' : rt);

  if (kind === 'unknown') push(ctx.m('advice.summary.unsupported'), 2);
  else if (kind === 'text') {
    const layers = (design.texts || []).filter((l) => l && String(l.text || '').trim());
    const caps = (config.techniques[rt].rules || {}).textCapsMm;
    if (!layers.length) push(ctx.m('advice.summary.text_only_empty', tp), 2);
    else {
      const h = Math.min(...layers.map((l) => Number(l.heightMm) || 0));
      const tooSmall = rv.reasons.some((r) => r.code === 'text_too_small');
      if (tooSmall && caps) push(ctx.m('advice.summary.text_only_small', Object.assign({ limit: ctx.num(caps.hard) }, tp)), 2);
      else push(ctx.m('advice.summary.text_only', Object.assign({ mm: ctx.num(h) }, tp)), 2);
    }
  } else if (analysis && analysis.isPatchPhoto) push(ctx.m('advice.summary.patch_photo'), 2);
  else if (kind === 'photo') push(ctx.m('advice.summary.photo', ctx.techParams(printedOrRt)), 2);
  else if (kind === 'gradient') {
    push(printLike ? ctx.m('advice.summary.gradient_print', tp) : ctx.m('advice.summary.gradient_thread'), 2);
  } else if (isShaded(analysis, kind)) {
    const k = String(env.suggestedK);
    const woven = config.techniques.woven && config.techniques.woven.enabled;
    // Headline and explanation must never contradict each other: when a print technique wins, say why first,
    // then give the honest thread alternatives (woven = most detail, embroidered after simplification).
    const base = woven ? 'advice.summary.illustration' : 'advice.summary.illustration_nowoven';
    push(printLike ? ctx.m(base + '_print', Object.assign({ k }, tp)) : ctx.m(base, { k }), 3);
  } else {
    const n = Math.max(1, cc.n);
    push(rt === 'embroidered' ? ctx.m('advice.summary.flat_embroidered', { n }) : ctx.m('advice.summary.flat_other', Object.assign({ n }, tp)), 2);
  }

  // Flat design where embroidery only works bigger: say why we advise something else, and from which size it can.
  const ev = verdicts.embroidered;
  const whyNot = kind === 'flat' && rt !== 'embroidered' && ev && ev.status === 'warn' && ev._resizeCm && !(analysis && analysis.isPatchPhoto)
    ? ctx.m('advice.summary.flat_embroidery_bigger', { cm: ctx.num(ev._resizeCm) }) : null;

  // Honest tie: when the runner-up is (almost) as good, say so.
  const second = ranking[1];
  const sv = second && verdicts[second];
  const tie = sv && sv.status !== 'bad' && (sv.status === rv.status || sv.status === 'ok') && Math.abs(rv.score - sv.score) <= delta + EPS;
  if (tie && !whyNot && sentences <= 2 && kind !== 'unknown') {
    const order = config.techniqueOrder || [];
    const pair = [rt, second].sort((a, b) => order.indexOf(a) - order.indexOf(b));
    let m = ctx.m(`advice.summary.tie.${pair[0]}.${pair[1]}`);
    if (m.text === m.key) m = ctx.m('advice.summary.tie.generic', { a: ctx.techParams(rt).Tech, b: ctx.techParams(second).tech });
    push(m, 1);
  } else if (whyNot && sentences <= 2) {
    push(whyNot, 1);
  } else if (rv.status === 'warn' && rv._resizeCm && sentences <= 2) {
    push(ctx.m('advice.summary.fix_resize', { cm: ctx.num(rv._resizeCm) }), 1);
  }

  const text = { key: parts[0].key, params: parts[0].params, text: parts.map((p) => p.text).join(' '), parts };
  return { headline, text };
}

/* ---------------------------------- thread colours ---------------------------------- */

function colorsAdvice(env) {
  const { analysis, config, ctx, kind, cc } = env;
  if (!analysis || kind === 'photo' || !config.techniques.embroidered) return null;
  const k = env.suggestedK;
  const choices = ((config.advice && config.advice.threadColorChoices) || [6, 8, 10, 12]).slice().sort((a, b) => a - b);
  const n = cc.n;
  if (kind === 'gradient' || isShaded(analysis, kind) || n > k) {
    // One number everywhere (critique-1 #12): the advice text, the "simplify" button and the slider all use k.
    return { maxThreadColors: k < (choices[0] || k) ? k : choices.find((c) => c >= k) || k, reason: ctx.m('advice.colors.reduce', { k: String(k) }) };
  }
  return { maxThreadColors: choices.find((c) => c >= n) || choices[choices.length - 1] || n, reason: ctx.m('advice.colors.keep', { n }) };
}

/* ---------------------------------- upsells ---------------------------------- */

function isGold(lab) {
  if (!lab) return false;
  const h = (Math.atan2(lab[2], lab[1]) * 180) / Math.PI;
  return lab[0] >= 45 && lab[0] <= 85 && chroma(lab) >= 25 && h >= 65 && h <= 100;
}
function isSilver(lab) {
  return !!lab && lab[0] >= 68 && lab[0] <= 84 && chroma(lab) < 5;
}

function upsellAdvice(env, verdicts, rt) {
  const { analysis, design, config, ctx, cc, context } = env;
  const max = (config.advice && config.advice.maxUpsells) ?? 2;
  if (!max) return [];
  const cur = design.techniquePicked && verdicts[design.technique] ? design.technique : rt;
  const v = verdicts[cur];
  if (!v || v.status === 'bad') return [];
  const specials = config.specials || {};
  const out = [];
  const curOptions = optionsForTechnique(design, config, cur);
  const layers = (design.texts || []).filter((l) => l && String(l.text || '').trim());
  const artText = v._meas && v._meas.minTextMm;
  const layerMin = layers.length ? Math.min(...layers.map((l) => Number(l.heightMm) || 0)) : null;
  const minText = [artText, layerMin].filter((x) => x != null);
  const smallestText = minText.length ? Math.min(...minText) : null;
  const special = (id) => specials[id] && specials[id].enabled !== false && (specials[id].techniques || []).includes(cur) ? specials[id] : null;
  // Specials have no option/price in the config: 'on_request' (and anything else) goes along with the quote/proof.
  const specialAction = () => ({ type: 'quote' });

  // 3D puff: big, simple lettering only (research 04 §2.1).
  const puff = special('puff3d');
  if (puff && smallestText != null && smallestText + EPS >= (puff.minTextCapsMm || 11) && cc.n > 0 && cc.n <= (puff.maxColors || 3)) {
    out.push({ code: 'puff3d', msg: ctx.m('advice.upsell.puff3d'), action: specialAction(puff) });
  }
  // Metallic: when the design really has gold/silver tones and no tiny text (research 04 §2.2).
  const met = special('metallic');
  if (met && analysis && (smallestText == null || smallestText + EPS >= (met.minTextCapsMm || 5))) {
    const metallicLike = (analysis.palette || []).some((c) => {
      const lab = c && (c.lab || (c.hex && hexToLab(c.hex)));
      return (c.share || 0) >= 0.03 && (isGold(lab) || isSilver(lab));
    });
    if (metallicLike) out.push({ code: 'metallic', msg: ctx.m('advice.upsell.metallic'), action: specialAction(met) });
  }
  // Hook-and-loop backing: uniforms/workwear/airsoft, or PVC (the classic).
  const use = String(context.useCase || design.useCase || '').toLowerCase();
  const velcro = optionById(config, 'velcro_hook');
  const hasVelcro = curOptions.some((id) => id === 'velcro_hook' || id === 'velcro_loop');
  if (velcro && velcro.enabled !== false && (velcro.techniques || []).includes(cur) && !hasVelcro && (VELCRO_USES.has(use) || cur === 'pvc')) {
    const p = optionPrice(velcro, cur);
    out.push({
      code: 'velcro',
      msg: ctx.m(cur === 'pvc' ? 'advice.upsell.velcro_pvc' : 'advice.upsell.velcro_uniform', { price: ctx.money(p.amount || 0) }),
      action: setPatch(design, cur, { options: withOption(design, config, cur, 'backing', 'velcro_hook') }),
    });
  }
  // On film: small embroidered patch, clean edge without fraying.
  const film = optionById(config, 'on_film');
  if (film && film.enabled !== false && (film.techniques || []).includes(cur) && env.sizeCm <= 6 + EPS && !curOptions.includes('on_film')) {
    const p = optionPrice(film, cur);
    out.push({
      code: 'on_film',
      msg: ctx.m('advice.upsell.on_film', { price: ctx.money(p.amount || 0) }),
      action: setPatch(design, cur, { options: withOption(design, config, cur, 'edge', 'on_film') }),
    });
  }
  // One size up when that makes small (but legal) text comfortable.
  if (v.status === 'ok' && v._meas && v._meas.text && v._meas.text.comfortRatio + EPS < 1) {
    const target = smallestPassingSize(analysis, design, config, cur, env.sizeCm + 0.01, { comfort: true });
    if (target && !target.quote && target.check.meas.text) {
      out.push({
        code: 'size_up',
        msg: ctx.m('advice.upsell.size_up', { cm: ctx.num(target.cm), mm: ctx.mm(target.check.meas.text.measured) }),
        action: setPatch(design, cur, { sizeCm: target.cm }),
      });
    }
  }
  return out.slice(0, max);
}

/* ---------------------------------- trust + warnings ---------------------------------- */

function trustLines(env) {
  const { config, ctx } = env;
  const p = config.promises || {};
  const out = [];
  const fromConfig = (key, obj) => {
    const override = ctx.t(key);
    const text = override !== key ? override : ctx.label(obj);
    if (text) out.push({ key, params: {}, text });
  };
  if (p.proofDays > 0) out.push(ctx.m('advice.trust.proof_days', { n: Number(p.proofDays) }));
  else fromConfig('advice.trust.proof', p.proof);
  if (p.fromOne !== false) fromConfig('advice.trust.from_one', p.fromOneText);
  fromConfig('advice.trust.response', p.responseTime);
  return out;
}

const WARN_KEYS = {
  low_res: 'advice.warn.low_res',
  mockup: 'advice.warn.mockup',
  patch_photo_cut: 'advice.warn.patch_photo_cut',
  transparent_shadow: 'advice.warn.transparent_shadow',
  white_on_transparent: 'advice.warn.white_on_transparent',
  nearly_empty: 'advice.warn.nearly_empty',
  shape_uncertain: 'advice.warn.shape_uncertain',
  svg_embedded_raster: 'advice.warn.svg_embedded_raster',
  svg_gradient: 'advice.warn.svg_gradient',
};

function generalWarnings(env) {
  const { analysis, design, ctx } = env;
  const out = [];
  if (!analysis) {
    if (design.source && design.source.kind === 'upload' && env.kind === 'unknown') out.push(ctx.m('advice.warn.unsupported'));
    return out;
  }
  const seen = new Set();
  for (const code of analysis.warnings || []) {
    const key = WARN_KEYS[code];
    if (!key || seen.has(code)) continue;
    seen.add(code);
    const params = code === 'low_res' ? { px: String(Math.round((analysis.resolution && analysis.resolution.effectiveLongestPx) || 0)) } : {};
    out.push(ctx.m(key, params));
  }
  return out;
}
