/**
 * Tests for the advice engine (SPEC §7.7). Run: npm test  (node --test "tests/**\/*.test.js")
 * Scenarios per design type × size, text-only, file without analysis, PVC minimum, purple-without-thread,
 * merrow, quote sizes, upsells, contrast, locales, plus invariants (fixes on warn/bad, determinism, speed).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const plugin = path.resolve(here, '../../plugin/borduur-patch-studio');
const { buildPublicConfig, deepMerge, enabledTechniques } = await import(path.join(plugin, 'assets/src/shared/config.js'));
const { layoutPatch } = await import(path.join(plugin, 'assets/src/shared/geometry.js'));
const advice = await import(path.join(plugin, 'assets/src/advice/index.js'));
const { evaluate, messages, measure } = advice;

const defaults = JSON.parse(fs.readFileSync(path.join(plugin, 'config/defaults.json'), 'utf8'));
const palette = JSON.parse(fs.readFileSync(path.join(plugin, 'config/thread-palette.json'), 'utf8'));
const CONFIG = buildPublicConfig(defaults, palette, { locale: 'nl-BE' });
const configWith = (over) => buildPublicConfig(deepMerge(defaults, over), palette, { locale: 'nl-BE' });

/* ------------------------------------------------------------------ fixtures */

function design(over = {}) {
  return Object.assign({
    v: 1, designId: null, token: null, ref: null,
    source: { kind: 'upload', fileName: 'logo.png', fileType: 'image/png', fileBytes: 1000, hash: null },
    technique: 'embroidered', techniquePicked: false, shape: 'circle', shapePicked: false, aspect: 1,
    sizeCm: 8, qty: 10, options: [], fabric: 'auto', edgeColor: 'auto', threadOverrides: {}, maxThreadColors: 8,
    art: { scale: 1, offsetMm: { x: 0, y: 0 }, rotation: 0 }, texts: [], mockup: 'none', notes: '', locale: 'nl-BE',
  }, over);
}

const BOX = { x: 0, y: 0, w: 360, h: 360 };
function analysis(over = {}) {
  return Object.assign({
    v: 1, job: 1, source: 'raster', src: { w: 1200, h: 1200 }, size: { w: 360, h: 360 }, pxPerSrcPx: 0.3,
    kind: 'flat', hasSoftShading: false, isMockup: false, isPatchPhoto: false,
    background: { type: 'white', hex: '#ffffff', keep: false },
    shape: { type: null, confidence: 0, fillsPatch: false, aspect: 1, box: BOX },
    contentBox: BOX, artBox: BOX,
    palette: [
      { hex: '#1b2a4a', lab: [17.6, 3.3, -20.5], share: 0.6, thin: false },
      { hex: '#c8102e', lab: [42.5, 66.0, 41.0], share: 0.3, thin: false },
      { hex: '#ffffff', lab: [100, 0, 0], share: 0.1, thin: false },
    ],
    fineLinePx: 12, fineGapPx: 12,
    textLines: [{ box: BOX, capPx: 40, xPx: null, strokePx: 6, arc: false, serifOrScript: false, paletteIndex: 0, confidence: 0.9 }],
    regions: { fillAreaPx2: 50000, satinLenPx: 2000, runLenPx: 100, objects: 4 },
    features: { cover: 5, hard: 0.1, chg: 0.01, fake: 0.01, residual: 0, texture: 0 },
    resolution: { effectiveLongestPx: 1200, lowRes: false },
    warnings: [], timings: {},
  }, over);
}

/** Owner's round patch (BRIEF): detailed illustration with shading, mock-up, round, fills the patch, arc text. */
function ownerPatch(over = {}) {
  const pal = [];
  for (let i = 0; i < 16; i++) {
    const L = 15 + i * 5, a = (i % 4) * 8 - 10, b = (i % 3) * 15 - 5;
    pal.push({ hex: '#' + [L, L, L].map((v) => Math.round(v * 2.4).toString(16).padStart(2, '0')).join(''), lab: [L, a, b], share: 1 / 16, thin: false });
  }
  return analysis(Object.assign({
    kind: 'illustration', hasSoftShading: true, isMockup: true,
    background: { type: 'white', hex: '#ffffff', keep: false },
    shape: { type: 'circle', confidence: 0.93, fillsPatch: true, aspect: 1, box: BOX },
    palette: pal, fineLinePx: 4, fineGapPx: 4,
    textLines: [{ box: BOX, capPx: 26, xPx: null, strokePx: 4, arc: true, serifOrScript: false, paletteIndex: 1, confidence: 0.8 }],
    warnings: ['mockup'],
  }, over));
}

const run = (a, d, extra = {}) => evaluate(Object.assign({ analysis: a, design: d, config: CONFIG, locale: d.locale, colors: {} }, extra));

/** Walk any result and collect every Msg-like object. */
function msgs(obj, out = []) {
  if (!obj || typeof obj !== 'object') return out;
  if (typeof obj.key === 'string' && typeof obj.text === 'string' && 'params' in obj) out.push(obj);
  for (const v of Array.isArray(obj) ? obj : Object.values(obj)) msgs(v, out);
  return out;
}

/** Common invariants every AdviceResult must satisfy. */
function invariants(r, cfg = CONFIG, { lang = 'nl' } = {}) {
  const techs = enabledTechniques(cfg);
  assert.deepEqual([...r.ranking].sort(), [...techs].sort(), 'ranking holds all enabled techniques');
  assert.equal(r.recommended, r.ranking[0]);
  for (const t of techs) {
    const v = r.verdicts[t];
    assert.ok(['ok', 'warn', 'bad'].includes(v.status), t);
    assert.ok(v.score >= 0 && v.score <= 100, `${t} score in range`);
    if (v.status !== 'ok') assert.ok(v.fixes.length >= 1, `${t} (${v.status}) has a fix`);
    for (const f of v.fixes) assert.ok(f.action && ['set', 'quote', 'upload'].includes(f.action.type), `${t} fix action`);
    if (lang === 'nl') assert.ok(v.headline.text.split(/\s+/).length <= 6, `headline ≤ 6 words: ${v.headline.text}`);
    assert.equal(v.orderable, v.mode === 'order' && v.status !== 'bad' && v.orderable, 'orderable implies order mode');
  }
  for (const m of msgs(r)) {
    assert.notEqual(m.text, m.key, `missing catalogue text for ${m.key}`);
    assert.ok(!/\{\w+\}/.test(m.text), `unfilled placeholder in ${m.key}: ${m.text}`);
    assert.ok(!m.text.includes('!'), `no exclamation marks in advice: ${m.text}`);
    assert.ok(!/\d\.\d/.test(m.text.replace(/\d{1,3}(\.\d{3})+/g, '')), `comma decimals: ${m.text}`);
  }
  assert.ok(r.upsells.length <= (cfg.advice.maxUpsells ?? 2));
  assert.ok(r.summary.headline.text && r.summary.text.text);
}

/* ------------------------------------------------------------------ catalogue */

test('catalogue: NL and FR have the same advice.* keys, plural objects intact', () => {
  const nl = Object.keys(messages.nl), fr = Object.keys(messages.fr);
  assert.ok(nl.length > 100);
  assert.deepEqual([...nl].sort(), [...fr].sort());
  for (const k of nl) {
    assert.ok(k.startsWith('advice.'), k);
    assert.equal(typeof messages.nl[k], typeof messages.fr[k], k);
    if (typeof messages.nl[k] === 'object') { assert.ok(messages.nl[k].one && messages.nl[k].other, k); }
  }
  // Forbidden patterns (SPEC §1.3-8, D9).
  const all = JSON.stringify(messages);
  for (const bad of ['Bestseller', 'Populairst', 'Slechts vandaag', '100 %', 'gratis retour', '!!!']) assert.ok(!all.includes(bad), bad);
  assert.ok(!/\bu\b|\buw\b/.test(JSON.stringify(messages.nl)), 'NL uses "je", never "u/uw"');
});

/* ------------------------------------------------------------------ design types */

test('flat logo with clear text at 8 cm → embroidery recommended, all ok', () => {
  const r = run(analysis(), design());
  invariants(r);
  assert.equal(r.recommended, 'embroidered');
  const v = r.verdicts.embroidered;
  assert.equal(v.status, 'ok');
  assert.equal(v.headline.key, 'advice.headline.embroidered.ok');
  assert.ok(v.reasons.some((x) => x.code === 'text_ok' && x.measured >= 6));
  assert.equal(r.summary.headline.key, 'advice.summary.headline.embroidered');
  assert.equal(r.summary.text.key, 'advice.summary.flat_embroidered');
  assert.match(r.summary.text.text, /3 kleuren/);
  assert.equal(r.size.recommendedCm, 8);
  assert.equal(r.size.reason.key, 'advice.size.ok_text');
  assert.equal(v.threadColors, 3);
  assert.equal(r.colors.reason.key, 'advice.colors.keep');
  assert.deepEqual(r.trust.map((m) => m.key), ['advice.trust.proof', 'advice.trust.from_one', 'advice.trust.response']);
  assert.equal(r.trust[0].text, CONFIG.promises.proof.nl, 'trust text comes from config');
});

test('small text at 6 cm → embroidery warn with "Maak X cm" (size first), X really fixes it', () => {
  const a = analysis({ textLines: [{ box: BOX, capPx: 20, xPx: null, strokePx: 3, arc: false, serifOrScript: false, paletteIndex: 0, confidence: 0.9 }] });
  const d = design({ sizeCm: 6 });
  const r = run(a, d);
  invariants(r);
  const v = r.verdicts.embroidered;
  assert.equal(v.status, 'warn');
  const reason = v.reasons[0];
  assert.equal(reason.code, 'text_too_small');
  assert.equal(reason.limit, 4);
  assert.match(reason.msg.text, /^Je kleinste tekst wordt \d+,\d mm hoog\. Geborduurd vraagt minstens 4 mm/);
  const fix = v.fixes[0];
  assert.equal(fix.code, 'resize', 'size is the first fix');
  const cm = fix.action.patch.sizeCm;
  assert.ok(CONFIG.sizes.pricedCm.includes(cm) && cm > 6 && cm <= 12);
  assert.equal(fix.msg.text, `Maak ${cm} cm`);
  assert.equal(v.headline.text, `Te borduren vanaf ${cm} cm`);
  // The proposed size really reaches 4 mm, the previous priced size does not.
  const lay = layoutPatch(Object.assign({}, d, { sizeCm: cm, options: ['cut_from_fabric'] }), a, CONFIG);
  assert.ok(20 * lay.mmPerArtPx >= 4 - 1e-9);
  const prev = CONFIG.sizes.pricedCm.filter((s) => s < cm).pop();
  const layPrev = layoutPatch(Object.assign({}, d, { sizeCm: prev, options: ['cut_from_fabric'] }), a, CONFIG);
  assert.ok(20 * layPrev.mmPerArtPx < 4);
  // Embroidery is the chosen technique here → size advice points to the same size when embroidery is recommended.
  if (r.recommended === 'embroidered') assert.equal(r.size.recommendedCm, cm);
  // The summary explains why embroidery is not advised at this size.
  if (r.recommended !== 'embroidered') assert.ok(r.summary.text.parts.some((p) => p.key === 'advice.summary.flat_embroidery_bigger'));
});

test('text too small for any embroidery size → bad, never a dead end', () => {
  // 10 px at 8 cm ≈ 1,7 mm: embroidery would need > 30 cm → bad; print (1,6 mm) is fine → green alternative.
  const a = analysis({ textLines: [{ box: BOX, capPx: 10, xPx: null, strokePx: 2, arc: false, serifOrScript: false, paletteIndex: 0, confidence: 0.9 }] });
  const r = run(a, design({ sizeCm: 8 }));
  invariants(r);
  const v = r.verdicts.embroidered;
  assert.equal(v.status, 'warn', '1,7 mm → 4 mm needs ± 19 cm: possible via quote, so warn');
  const tiny = analysis({ textLines: [{ box: BOX, capPx: 5, xPx: null, strokePx: 1, arc: false, serifOrScript: false, paletteIndex: 0, confidence: 0.9 }] });
  const r2 = run(tiny, design({ sizeCm: 8 }));
  invariants(r2);
  // 5 px: even 30 cm gives < 4 mm → bad. Nothing is green at 8 cm, but the red card points to the best alternative.
  assert.equal(r2.verdicts.embroidered.status, 'bad');
  assert.ok(r2.verdicts.embroidered.fixes.some((f) => f.code === 'switch_technique'));
  assert.notEqual(r2.recommended, 'embroidered');
  assert.notEqual(r2.verdicts[r2.recommended].status, 'bad');
  assert.equal(r.verdicts[r.recommended].status, 'ok');
});

test('serif/script text uses the stricter limit (serifScriptFactor)', () => {
  const tl = (serif) => [{ box: BOX, capPx: 30, xPx: null, strokePx: 4, arc: false, serifOrScript: serif, paletteIndex: 0, confidence: 0.9 }];
  // 30 px at 8 cm ≈ 5,07 mm: ok for block letters (4 mm), not for serif/script (6 mm).
  const plain = run(analysis({ textLines: tl(false) }), design());
  const serif = run(analysis({ textLines: tl(true) }), design());
  assert.notEqual(plain.verdicts.embroidered.reasons[0].code, 'text_too_small');
  assert.equal(serif.verdicts.embroidered.reasons[0].code, 'text_too_small');
  assert.equal(serif.verdicts.embroidered.reasons[0].limit, 6);
  assert.equal(serif.verdicts.embroidered.reasons[0].msg.key, 'advice.reason.text_too_small_serif');
});

test('thin lines: embroidery warns (run stitch) but never says bad for lines alone', () => {
  const a = analysis({ textLines: [], fineLinePx: 3 }); // ≈ 0,5 mm at 8 cm
  const r = run(a, design());
  invariants(r);
  const v = r.verdicts.embroidered;
  assert.equal(v.status, 'warn');
  const reason = v.reasons.find((x) => x.code === 'line_too_thin');
  assert.equal(reason.msg.key, 'advice.reason.line_too_thin_run');
  assert.ok(v.fixes.length >= 1);
});

test('photo → no thread techniques, print/sublimation recommended, honest summary', () => {
  const a = analysis({ kind: 'photo', textLines: [], palette: analysis().palette });
  const r = run(a, design());
  invariants(r);
  for (const t of ['embroidered', 'woven', 'pvc', 'chenille']) assert.equal(r.verdicts[t].status, 'bad', t);
  assert.equal(r.verdicts.leather.status, 'warn');
  assert.ok(['printed', 'sublimated'].includes(r.recommended));
  assert.equal(r.summary.text.key, 'advice.summary.photo');
  assert.match(r.summary.text.text, /^Dit is een foto\./);
  assert.equal(r.verdicts.embroidered.reasons[0].msg.key, 'advice.reason.photo_thread');
  assert.equal(r.colors, null);
  // A green alternative is offered on the red card.
  assert.ok(r.verdicts.embroidered.fixes.some((f) => f.code === 'switch_technique' && ['printed', 'sublimated'].includes(f.action.patch.technique)));
});

test('owner round illustration (mock-up, fills patch) → woven/embroidered/printed advice like a craftsman', () => {
  const a = ownerPatch();
  const d = design({ shape: 'circle' });
  const r = run(a, d);
  invariants(r);
  const top = r.ranking[0];
  const printTop = top === 'printed' || top === 'sublimated';
  // Integrator: headline and explanation may never contradict (print wins → explanation says why, then thread alternatives).
  assert.equal(r.summary.text.key, printTop ? 'advice.summary.illustration_print' : 'advice.summary.illustration');
  const k = r.summary.text.params.k;
  assert.match(r.summary.text.text, printTop ? /Geweven geeft het meeste detail/ : /geweven \(meeste detail\)/);
  assert.match(r.summary.text.text, new RegExp(`± ${k} garenkleuren`));
  assert.match(r.summary.text.text, printTop ? /daarom is dat ons advies/ : /bedrukt de keuze/);
  const emb = r.verdicts.embroidered;
  assert.equal(emb.status, 'warn');
  const red = emb.fixes.find((f) => f.code === 'reduce_colors');
  assert.ok(red, 'embroidery offers simplification');
  assert.ok(CONFIG.advice.threadColorChoices.includes(red.action.patch.maxThreadColors) || red.action.patch.maxThreadColors < 6);
  assert.equal(r.verdicts.woven.status, 'ok');
  assert.equal(r.verdicts.woven.mode, 'quote');
  assert.equal(r.verdicts.woven.orderable, false);
  assert.equal(r.verdicts.printed.status, 'ok');
  assert.equal(r.verdicts.chenille.status, 'bad');
  assert.ok(r.verdicts[r.recommended].orderable, 'recommended is orderable (preferOrderable)');
  assert.ok(r.verdicts.embroidered.notes.some((n) => n.key === 'advice.note.rim'), 'own rim becomes the edge');
  assert.ok(r.warnings.some((w) => w.key === 'advice.warn.mockup'));
  assert.ok(r.colors && r.colors.reason.key === 'advice.colors.reduce');
  assert.equal(r.autoChoices.designFill, 'circle');
});

test('illustration with 100+ pieces: woven scores high, still an orderable recommendation when close', () => {
  const r = run(ownerPatch(), design({ qty: 150 }));
  invariants(r);
  assert.ok(r.verdicts.woven.score >= r.verdicts.printed.score - 1);
  assert.ok(r.verdicts[r.recommended].orderable);
});

test('gradient → print exact, thread techniques simplify', () => {
  const r = run(analysis({ kind: 'gradient', hasSoftShading: true }), design());
  invariants(r);
  assert.ok(['printed', 'sublimated'].includes(r.recommended));
  assert.equal(r.verdicts.embroidered.status, 'warn');
  assert.ok(r.verdicts.embroidered.reasons.some((x) => x.msg.key === 'advice.reason.gradient_simplify'));
  assert.equal(r.verdicts.pvc.status, 'bad');
  assert.equal(r.summary.text.key, 'advice.summary.gradient_print');
});

test('gsm photo of an existing patch → re-order summary', () => {
  const r = run(analysis({ isPatchPhoto: true, shape: { type: 'circle', confidence: 0.9, fillsPatch: true, aspect: 1, box: BOX }, warnings: ['patch_photo_cut'] }), design());
  invariants(r);
  assert.equal(r.summary.text.key, 'advice.summary.patch_photo');
  assert.ok(r.warnings.some((w) => w.key === 'advice.warn.patch_photo_cut'));
});

/* ------------------------------------------------------------------ text-only and no analysis */

test('text-only patch → embroidery, summary with letter height', () => {
  const d = design({ source: { kind: 'text', fileName: null, fileType: null, fileBytes: null, hash: null }, sizeCm: 6,
    texts: [{ id: 't1', text: 'BRANDWEER', font: 'Sora', heightMm: 7, threadCode: '1801', path: 'arc_top', offsetMm: { x: 0, y: 0 }, angleDeg: 0, insetMm: 5, letterSpacing: 0 }] });
  const r = run(null, d);
  invariants(r);
  assert.equal(r.recommended, 'embroidered');
  assert.equal(r.verdicts.embroidered.status, 'ok');
  assert.equal(r.summary.text.key, 'advice.summary.text_only');
  assert.match(r.summary.text.text, /7 mm/);
  assert.equal(r.colors, null);
});

test('script font text layer below the stricter limit → warn with "Maak de tekst X mm"', () => {
  const d = design({ source: { kind: 'text' }, sizeCm: 6,
    texts: [{ id: 't1', text: 'Jasper', font: 'Pacifico', heightMm: 4.5, threadCode: 'auto', path: 'straight', offsetMm: { x: 0, y: 0 }, angleDeg: 0, insetMm: 4, letterSpacing: 0 }] });
  const r = run(null, d);
  invariants(r);
  const v = r.verdicts.embroidered;
  assert.equal(v.status, 'warn');
  const reason = v.reasons.find((x) => x.code === 'text_too_small');
  assert.equal(reason.limit, 6, '4 mm × 1,5 for script');
  const fix = v.fixes.find((f) => f.code === 'enlarge_text');
  assert.equal(fix.action.patch.texts[0].heightMm, 6);
  assert.equal(fix.msg.text, 'Maak de tekst 6 mm');
  assert.equal(r.summary.text.key, 'advice.summary.text_only_small');
});

test('file without analysis (PDF/AI) → general advice, nothing marked bad, honest warning', () => {
  const d = design({ source: { kind: 'upload', fileName: 'logo.ai', fileType: 'application/postscript', fileBytes: 5000, hash: null } });
  const r = run(null, d);
  invariants(r);
  assert.equal(r.summary.text.key, 'advice.summary.unsupported');
  assert.ok(r.warnings.some((w) => w.key === 'advice.warn.unsupported'));
  for (const t of r.ranking) assert.notEqual(r.verdicts[t].status, 'bad', t);
  assert.equal(r.size.recommendedCm, 8);
});

/* ------------------------------------------------------------------ technique specifics */

test('PVC: minimum 50 per 10, one-off mould with real amount incl. VAT, no ironing; velcro upsell', () => {
  const d = design({ technique: 'pvc', techniquePicked: true, qty: 10 });
  const r = run(analysis(), d);
  invariants(r);
  const v = r.verdicts.pvc;
  const keys = v.notes.map((n) => n.key);
  assert.ok(keys.includes('advice.note.min_qty_step'));
  assert.ok(keys.includes('advice.note.mold'));
  assert.ok(keys.includes('advice.note.no_iron_pvc'));
  const mold = v.notes.find((n) => n.key === 'advice.note.mold');
  assert.match(mold.text, /€\s96,80/);
  const withQty = run(analysis(), design({ technique: 'pvc', techniquePicked: true, qty: 50 }));
  assert.ok(withQty.verdicts.pvc.score > v.score, 'below minimum quantity lowers the PVC score');
  const up = r.upsells.find((u) => u.code === 'velcro');
  assert.ok(up, 'velcro is the classic for PVC');
  assert.ok(up.action.patch.options.includes('velcro_hook'));
  assert.match(up.msg.text, /€\s1,21/);
});

test('brand purple without thread match → warn on thread techniques, switch to printed', () => {
  const a = analysis({ palette: [{ hex: '#5926c5', lab: [34, 58, -76], share: 0.7, thin: false }, { hex: '#ffffff', lab: [100, 0, 0], share: 0.3, thin: false }] });
  const matches = [{ thread: { code: '2000', hex: '#4b2a8a' }, deltaE: 12, quality: 'none', alternatives: [] }, { thread: { code: '1801', hex: '#ffffff' }, deltaE: 0, quality: 'very_good', alternatives: [] }];
  const r = run(a, design(), { colors: { matches, reduced: null, contrast: null, fabricHex: '#1b2a4a' } });
  invariants(r);
  const v = r.verdicts.embroidered;
  assert.equal(v.status, 'warn');
  assert.ok(v.reasons.some((x) => x.code === 'no_thread_match'));
  const sw = v.fixes.find((f) => f.code === 'switch_technique');
  assert.equal(sw.action.patch.technique, 'printed');
  assert.equal(r.verdicts.printed.reasons.some((x) => x.code === 'no_thread_match'), false);
});

test('woven merrow on a contour shape → change edge to laser', () => {
  const r = run(analysis(), design({ shape: 'contour' }));
  invariants(r);
  const v = r.verdicts.woven;
  assert.ok(v.reasons.some((x) => x.code === 'merrow_shape'));
  const f = v.fixes.find((x) => x.code === 'change_edge');
  assert.ok(f.action.patch.options.includes('laser'));
  assert.ok(!f.action.patch.options.includes('merrow'));
  assert.equal(f.action.patch.technique, 'woven', 'a fix on another card also chooses that technique');
});

test('bigger than 12 cm → not orderable online, resize-or-quote offered', () => {
  const r = run(analysis(), design({ sizeCm: 14 }));
  invariants(r);
  const v = r.verdicts.embroidered;
  assert.equal(v.orderable, false);
  assert.ok(v.notes.some((n) => n.key === 'advice.reason.size_quote'));
  assert.ok(v.fixes.some((f) => f.code === 'resize' && f.action.patch.sizeCm === 12));
  assert.ok(v.fixes.some((f) => f.code === 'quote'));
  assert.equal(r.summary.headline.key, 'advice.summary.headline.quote');
  // PVC max 15 cm, woven max 19 cm: 20 cm is too large → warn with resize down.
  const big = run(analysis(), design({ sizeCm: 20 }));
  assert.ok(big.verdicts.pvc.reasons.some((x) => x.code === 'size_too_large'));
});

test('low resolution → print asks for a sharper file, embroidery stays calm', () => {
  const r = run(analysis({ resolution: { effectiveLongestPx: 240, lowRes: true }, warnings: ['low_res'] }), design());
  invariants(r);
  assert.equal(r.verdicts.printed.status, 'warn');
  assert.ok(r.verdicts.printed.fixes.some((f) => f.code === 'upload_better' && f.action.type === 'upload'));
  assert.equal(r.verdicts.printed.headline.key, 'advice.headline.printed.lowres');
  assert.ok(r.verdicts.embroidered.notes.some((n) => n.key === 'advice.note.low_res_thread'));
  assert.match(r.warnings[0].text, /240 px/);
});

test('sublimation explains that white is the carrier', () => {
  const r = run(analysis(), design());
  assert.ok(r.verdicts.sublimated.notes.some((n) => n.key === 'advice.note.sublimation_white'));
});

test('bad contrast with the fabric → change fabric fix to the suggested fabric', () => {
  const contrast = [{ index: 0, hex: '#1b2a4a', deltaE: 2, ratio: 1.1, level: 'bad', share: 0.6 }];
  const d = design({ fabric: 'navy' });
  const r = run(analysis(), d, { colors: { contrast, fabricHex: '#1b2a4a', matches: null, reduced: null } });
  invariants(r);
  const v = r.verdicts.embroidered;
  const f = v.fixes.find((x) => x.code === 'change_fabric');
  assert.ok(f, 'fabric fix offered');
  assert.notEqual(f.action.patch.fabric, 'navy');
  assert.equal(f.action.patch.fabric, r.autoChoices.fabric);
  assert.match(v.reasons.find((x) => x.code === 'low_contrast').msg.text, /marineblauw/);
});

/* ------------------------------------------------------------------ upsells */

test('upsells only when relevant: puff for big simple letters, metallic for gold, max 2', () => {
  const a = analysis({
    palette: [{ hex: '#1b2a4a', lab: [17.6, 3.3, -20.5], share: 0.7, thin: false }, { hex: '#d4af37', lab: [73.4, 1.9, 61.7], share: 0.3, thin: false }],
    textLines: [{ box: BOX, capPx: 80, xPx: null, strokePx: 14, arc: false, serifOrScript: false, paletteIndex: 1, confidence: 0.9 }],
  });
  const r = run(a, design({ techniquePicked: true }), { context: { useCase: 'uniform' } });
  invariants(r);
  const codes = r.upsells.map((u) => u.code);
  assert.deepEqual(codes, ['puff3d', 'metallic'], 'priority order, capped at maxUpsells');
  assert.equal(r.upsells[0].action.type, 'quote', 'on request → quote');
  assert.match(r.upsells[0].msg.text, /Op aanvraag/);
  // No gold, small text: no puff, no metallic.
  const plain = run(analysis(), design({ techniquePicked: true }));
  assert.ok(!plain.upsells.some((u) => u.code === 'puff3d' || u.code === 'metallic'));
});

test('velcro for uniforms, on film for small embroidered patches (with real price incl. VAT)', () => {
  const r = run(analysis({ textLines: [] }), design({ techniquePicked: true, sizeCm: 6 }), { context: { useCase: 'werkkleding' } });
  const codes = r.upsells.map((u) => u.code);
  assert.deepEqual(codes, ['velcro', 'on_film']);
  const film = r.upsells[1];
  assert.match(film.msg.text, /€\s3,03/);
  assert.ok(film.action.patch.options.includes('on_film'));
  assert.ok(!film.action.patch.options.includes('cut_from_fabric'));
  const velcro = r.upsells[0];
  assert.ok(velcro.action.patch.options.includes('velcro_hook') && velcro.action.patch.options.includes('velcro_black'));
  // Without a work context and at 8 cm: none of these.
  const none = run(analysis({ textLines: [] }), design({ techniquePicked: true }));
  assert.ok(!none.upsells.some((u) => u.code === 'velcro' || u.code === 'on_film'));
});

test('size_up upsell when text is legal but below comfort', () => {
  // 26 px at 8 cm ≈ 4,4 mm: ok (≥ 4) but below comfort 6 mm.
  const a = analysis({ textLines: [{ box: BOX, capPx: 26, xPx: null, strokePx: 4, arc: false, serifOrScript: false, paletteIndex: 0, confidence: 0.9 }] });
  const r = run(a, design({ techniquePicked: true }));
  const up = r.upsells.find((u) => u.code === 'size_up');
  assert.ok(up);
  assert.ok(up.action.patch.sizeCm > 8 && up.action.patch.sizeCm <= 12);
});

/* ------------------------------------------------------------------ config, locale, ranking */

test('French locale: all texts French, "vous", money as "x,xx €"', () => {
  const r = evaluate({ analysis: analysis(), design: design({ technique: 'pvc', techniquePicked: true, locale: 'fr-BE' }), config: CONFIG, locale: 'fr-BE', colors: {} });
  invariants(r, CONFIG, { lang: 'fr' });
  assert.equal(r.summary.headline.text.startsWith('Nous'), true);
  const mold = r.verdicts.pvc.notes.find((n) => n.key === 'advice.note.mold');
  assert.match(mold.text, /96,80\s€/);
  assert.equal(r.trust[0].text, CONFIG.promises.proof.fr);
  const nl = run(analysis(), design({ technique: 'pvc', techniquePicked: true }));
  assert.notEqual(nl.summary.text.text, r.summary.text.text);
});

test('owner overrides via config.strings win over the catalogue', () => {
  const cfg = Object.assign({}, CONFIG, { strings: { 'advice.headline.embroidered.ok': 'Dit borduren we graag' } });
  const r = evaluate({ analysis: analysis(), design: design(), config: cfg, locale: 'nl-BE', colors: {} });
  assert.equal(r.verdicts.embroidered.headline.text, 'Dit borduren we graag');
});

test('disabled technique disappears; illustration summary without woven', () => {
  const cfg = configWith({ techniques: { woven: { enabled: false } } });
  const r = evaluate({ analysis: ownerPatch(), design: design(), config: cfg, locale: 'nl-BE', colors: {} });
  invariants(r, cfg);
  assert.ok(!r.ranking.includes('woven'));
  const printTop = ['printed', 'sublimated'].includes(r.ranking[0]);
  assert.equal(r.summary.text.key, printTop ? 'advice.summary.illustration_nowoven_print' : 'advice.summary.illustration_nowoven');
});

test('quotes: a small price factor only, never overruling suitability', () => {
  const quotes = {
    embroidered: { ok: true, mode: 'order', perPieceAllInIncl: 12.0 },
    printed: { ok: true, mode: 'order', perPieceAllInIncl: 6.0 },
    sublimated: { ok: true, mode: 'order', perPieceAllInIncl: 5.0 },
    pvc: { ok: true, mode: 'order', perPieceAllInIncl: 9.0 },
  };
  const base = run(analysis(), design());
  const r = run(analysis(), design(), { quotes });
  invariants(r);
  const drop = base.verdicts.embroidered.score - r.verdicts.embroidered.score;
  assert.ok(drop > 0 && drop <= 6, `price penalty ≤ 6 (was ${drop})`);
  assert.equal(r.recommended, 'embroidered', 'flat logo: embroidery still wins');
});

test('measure(): fineLineMm and minTextMm for the payload, via layoutPatch', () => {
  const a = analysis();
  const d = design();
  const m = measure({ analysis: a, design: d, config: CONFIG });
  const lay = layoutPatch(Object.assign({}, d, { options: ['cut_from_fabric'] }), a, CONFIG);
  assert.ok(Math.abs(m.fineLineMm - 12 * lay.mmPerArtPx) < 1e-9);
  assert.ok(Math.abs(m.minTextMm - 40 * lay.mmPerArtPx) < 1e-9);
  // x-height rule: min(cap, x/0,6).
  const ax = analysis({ textLines: [{ box: BOX, capPx: 40, xPx: 18, strokePx: 5, arc: false, serifOrScript: false, paletteIndex: 0, confidence: 0.9 }] });
  assert.ok(Math.abs(measure({ analysis: ax, design: d, config: CONFIG }).minTextMm - (18 / 0.6) * lay.mmPerArtPx) < 1e-9);
  assert.equal(measure({ analysis: null, design: d, config: CONFIG }).minTextMm, null);
});

test('deterministic and fast (≤ 5 ms per change, warm)', () => {
  const a = ownerPatch();
  const d = design();
  const r1 = run(a, d);
  const r2 = run(a, d);
  assert.equal(JSON.stringify(r1), JSON.stringify(r2));
  // Warm-up, then measure typical changes (size/qty/technique) on the same analysis.
  for (let i = 0; i < 20; i++) run(a, design({ sizeCm: 4 + (i % 5) * 2 }));
  const n = 50;
  const t0 = performance.now();
  for (let i = 0; i < n; i++) run(a, design({ sizeCm: 4 + (i % 5) * 2, qty: 1 + i, technique: i % 2 ? 'printed' : 'embroidered' }));
  const per = (performance.now() - t0) / n;
  assert.ok(per <= 5, `avg ${per.toFixed(2)} ms`);
});

test('robust against partial/odd analysis input', () => {
  const a = analysis({ palette: [], textLines: undefined, fineLinePx: null, fineGapPx: null, warnings: undefined, resolution: undefined, kind: 'weird' });
  const r = run(a, design());
  invariants(r);
  assert.throws(() => evaluate({}), TypeError);
});
