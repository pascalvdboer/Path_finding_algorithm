/**
 * Full chain as the UI runs it (ui/app.js#applyAutoChoices + ui/derive.js): analyzeImage → auto shape/technique/size/threads
 * → derive() (colours, quotes, advice). Used by accuracy.test.js and for manual inspection:
 *   node tests/analysis/accuracy-pipeline.js [caseId…]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const plugin = path.resolve(here, '../../plugin/borduur-patch-studio');
const src = (p) => path.join(plugin, 'assets/src', p);

const { analyzeImage } = await import(src('analysis/index.js'));
const { buildPublicConfig } = await import(src('shared/config.js'));
const { initialState, reconcileOptions, normalizeQty } = await import(src('ui/state.js'));
const { derive } = await import(src('ui/derive.js'));
const { colorCount } = await import(src('advice/measure.js'));

const defaults = JSON.parse(fs.readFileSync(path.join(plugin, 'config/defaults.json'), 'utf8'));
const palette = JSON.parse(fs.readFileSync(path.join(plugin, 'config/thread-palette.json'), 'utf8'));
export const CONFIG = buildPublicConfig(defaults, palette, { locale: 'nl-BE' });

/** Mirror of ui/app.js applyAutoChoices(first = true). */
export function autoState(analysis, config = CONFIG, over = {}) {
  let s = { ...initialState(config), source: { kind: 'upload', fileName: 'upload.png', fileType: 'image/png', fileBytes: 1, hash: null }, ...over };
  const a = analysis;
  if (!s.shapePicked) {
    s.shape = (a.shape && a.shape.type) || 'circle';
    s.aspect = s.shape === 'circle' ? 1 : (a.shape && a.shape.aspect) || (a.artBox ? a.artBox.w / a.artBox.h : 1);
  }
  if (!s.techniquePicked) {
    for (let i = 0; i < 3; i++) {
      const rec = derive(s, a, config).advice.recommended;
      if (!rec || rec === s.technique || !config.techniques[rec] || !config.techniques[rec].enabled) break;
      s = { ...s, technique: rec, options: reconcileOptions(config, rec, s.options), qty: normalizeQty(config, rec, s.qty) };
    }
  }
  const d2 = derive(s, a, config);
  if (!s.sizePicked && d2.advice.size && d2.advice.size.recommendedCm) s = { ...s, sizeCm: d2.advice.size.recommendedCm };
  if (d2.advice.colors && d2.advice.colors.maxThreadColors) s = { ...s, maxThreadColors: d2.advice.colors.maxThreadColors };
  return s;
}

/**
 * Run a case. Measurements are taken on the embroidered layout at the starting size (8 cm) so that "text 4,2 mm"
 * means the same thing in every case, independent of what the advice picked.
 */
let jobSeq = 1000;
export async function runCase(c, config = CONFIG) {
  // Unique job per run: ui/derive.js memoises thread matches per analysis.job (the UI increments it per upload).
  const analysis = await analyzeImage(c.build(), config, { svgText: c.svgText || null, job: ++jobSeq });
  const firstSize = initialState(config).sizeCm;
  const base = autoState(analysis, config);
  const atStart = { ...base, sizeCm: firstSize, technique: 'embroidered', options: reconcileOptions(config, 'embroidered', base.options) };
  const dStart = derive(atStart, analysis, config);
  const d = derive(base, analysis, config);
  return { analysis, state: base, derived: d, start: dStart };
}

/** Compact, human-readable digest of a run (also used in assertion messages). */
export function digest(r) {
  const a = r.analysis, adv = r.derived.advice, st = r.start;
  const v = adv.verdicts || {};
  const statuses = Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x.status]));
  const startStatuses = Object.fromEntries(Object.entries(st.advice.verdicts || {}).map(([k, x]) => [k, x.status]));
  return {
    kind: a.kind, soft: a.hasSoftShading, bg: a.background.type, keep: a.background.keep,
    shape: a.shape.fillsPatch ? a.shape.type : null, shapeType: a.shape.type, conf: Math.round((a.shape.confidence || 0) * 100) / 100,
    pal: a.palette.length, warnings: a.warnings,
    textMm: st.meas.minTextMm != null ? Math.round(st.meas.minTextMm * 100) / 100 : null,
    lineMm: st.meas.fineLineMm != null ? Math.round(st.meas.fineLineMm * 100) / 100 : null,
    colors: colorCount(a, r.state, CONFIG, r.derived.matches).n,
    recommended: adv.recommended, ranking: adv.ranking, sizeCm: r.state.sizeCm, threads: adv.colors ? adv.colors.maxThreadColors : null,
    statuses, startStatuses,
    resizeTo: (() => {
      const v = (st.advice.verdicts || {}).embroidered;
      const f = v && (v.fixes || []).find((x) => x.code === 'resize');
      const a = f && f.action;
      return a ? (a.sizeCm ?? (a.patch && a.patch.sizeCm) ?? null) : null;
    })(),
    reasons: Object.fromEntries(Object.entries(st.advice.verdicts || {}).map(([k, x]) => [k, (x.reasons || []).map((m) => m.code)])),
    headline: adv.summary && adv.summary.headline && adv.summary.headline.text,
    summary: adv.summary && adv.summary.text && adv.summary.text.text,
  };
}

/* ------------------------------------------------------------------ rule check */

const arr = (x) => (Array.isArray(x) ? x : [x]);
const inRange = (v, [lo, hi]) => v != null && v >= lo - 1e-9 && v <= hi + 1e-9;

/** @returns {string[]} broken rules */
export function check(expect, d) {
  const f = [];
  const e = expect || {};
  if (e.kind != null && !arr(e.kind).includes(d.kind)) f.push(`kind ${d.kind} ∉ ${arr(e.kind)}`);
  if (e.bg != null && !arr(e.bg).includes(d.bg)) f.push(`bg ${d.bg} ∉ ${arr(e.bg)}`);
  if (e.keepBg != null && !!d.keep !== e.keepBg) f.push(`keepBg ${d.keep} ≠ ${e.keepBg}`);
  if (e.shape !== undefined && !arr(e.shape).includes(d.shape)) f.push(`shape ${d.shape} ∉ ${arr(e.shape)}`);
  if (e.recommended && !e.recommended.includes(d.recommended)) f.push(`recommended ${d.recommended} ∉ ${e.recommended}`);
  for (const [t, st] of Object.entries(e.status || {})) {
    if (!arr(st).includes(d.startStatuses[t])) f.push(`status ${t} ${d.startStatuses[t]} ∉ ${arr(st)}`);
  }
  if (e.textMm && !inRange(d.textMm, e.textMm)) f.push(`textMm ${d.textMm} ∉ [${e.textMm}]`);
  if (e.lineMm && !inRange(d.lineMm, e.lineMm)) f.push(`lineMm ${d.lineMm} ∉ [${e.lineMm}]`);
  if (e.colors && !inRange(d.colors, e.colors)) f.push(`colors ${d.colors} ∉ [${e.colors}]`);
  if (e.threads && !inRange(d.threads, e.threads)) f.push(`threads ${d.threads} ∉ [${e.threads}]`);
  if (e.resizeTo && !inRange(d.resizeTo, e.resizeTo)) f.push(`resizeTo ${d.resizeTo} ∉ [${e.resizeTo}]`);
  for (const w of e.summaryNot || []) if (String(d.summary || '').toLowerCase().includes(w.toLowerCase())) f.push(`summary contains "${w}"`);
  for (const w of e.warnings || []) if (!d.warnings.includes(w)) f.push(`warning ${w} missing`);
  for (const w of e.noWarnings || []) if (d.warnings.includes(w)) f.push(`warning ${w} unexpected`);
  for (const [t, codes] of Object.entries(e.reasons || {})) {
    for (const c of codes) if (!(d.reasons[t] || []).includes(c)) f.push(`reason ${t}.${c} missing`);
  }
  for (const [t, codes] of Object.entries(e.noReasons || {})) {
    for (const c of codes) if ((d.reasons[t] || []).includes(c)) f.push(`reason ${t}.${c} unexpected`);
  }
  return f;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const { CASES } = await import('./accuracy-cases.js');
  const want = process.argv.slice(2);
  for (const c of CASES) {
    if (want.length && !want.includes(c.id)) continue;
    const d = digest(await runCase(c));
    const broken = check(c.expect, d);
    console.log('\n=== ' + c.id + (broken.length ? '  FAIL: ' + broken.join(' | ') : '  ok') + ' — ' + c.note);
    console.log(JSON.stringify(d, null, 0));
  }
}
