/**
 * Naamstroken — passingstoets (SPEC-EXTRA §1.5.1). Pure functies, geen DOM.
 * PHP-tweeling: includes/nametape/class-fit.php. Zelfde algoritme, zelfde volgorde van bewerkingen (float-identiek),
 * getest met tests/nametape/fit-vectors.json in node én PHP.
 */
import { roundHalfUp } from '../shared/money.js';

const RANK = { error: 0, warn: 1, ok: 2 };
const round1 = (x) => roundHalfUp(x, 1);

/** Tekst opschonen: NFC, stuurtekens weg, dubbele spaties → één, trim. */
export function cleanText(s) {
  return String(s == null ? '' : s)
    .normalize('NFC')
    .replace(/[\u0000-\u001F\u007F-\u009F\u200B-\u200F\u2028-\u202E\u2060-\u206F\uFEFF]/g, ' ')
    .replace(/[\s\p{Z}]+/gu, ' ')
    .replace(/^ +| +$/g, '');
}

/** Basisteken voor de breedtetabel: accenten weg (NFD), typografische apostrof → '. */
export function baseChar(ch) {
  if (ch === '’' || ch === '‘' || ch === '`') return "'";
  if (ch === '–' || ch === '—' || ch === '−') return '-';
  const b = ch.normalize('NFD').replace(/\p{M}+/gu, '');
  return b === '' ? ch : b;
}

/** Som van de letterbreedtes (em) van één regel, in leesvolgorde. */
export function lineEm(text, fm) {
  let em = 0;
  for (const ch of text) {
    const k = baseChar(ch);
    const a = fm.adv[k];
    em += typeof a === 'number' ? a : fm.fallbackEm;
  }
  return em;
}

export function fontById(cfg, id) {
  return (cfg.fonts || []).find((f) => f.id === id) || null;
}
export function borderById(cfg, id) {
  return (cfg.borders || []).find((b) => b.id === id) || null;
}
export function formatById(cfg, id) {
  return (cfg.formats || []).find((f) => f.id === id) || null;
}

/** Hoofdletters toepassen tenzij het lettertype gemengd moet blijven. */
export function applyCase(text, uppercase, font) {
  const t = cleanText(text);
  if (font && font.forceMixedCase) return t;
  return uppercase ? t.toUpperCase() : t;
}

/** Aantal regels voor een eigen maat (hoogte in mm). */
export function customLines(cfg, h) {
  const map = (cfg.customSize && cfg.customSize.linesByHeight) || {};
  const n = Number(map[String(h)]);
  if (n > 0) return n;
  return h >= 50 ? 3 : h >= 30 ? 2 : 1;
}

/**
 * Formaat van een batch als {id, w, h, lines}, of null als het niet bestaat.
 * @param {{format:string, custom?:{w:number,h:number}|null}} batch
 */
export function resolveFormat(batch, cfg) {
  if (batch.format === 'custom') {
    const c = batch.custom || {};
    const w = Number(c.w), h = Number(c.h);
    if (!(w > 0) || !(h > 0)) return null;
    return { id: 'custom', w, h, lines: customLines(cfg, h) };
  }
  const f = formatById(cfg, batch.format);
  return f ? { id: f.id, w: f.w, h: f.h, lines: f.lines } : null;
}

/** Bloedgroep als tekst volgens de notatie ("O POS" of "O+"). */
export function bloodText(blood, notation) {
  if (!blood || !blood.group) return '';
  const pos = blood.rh === '+';
  if (String(notation || 'O POS').indexOf(' ') === -1) return blood.group + (pos ? '+' : '-');
  return blood.group + (pos ? ' POS' : ' NEG');
}

/**
 * Regels van één rij volgens de bloedgroep-modus (§1.5.2). Lege regels vallen weg.
 * inline → achter de naam; line2 → als extra regel na regel 2; separate/null → niet op deze strook.
 */
export function rowLines(row, bloodMode, cfg) {
  const bt = bloodText(row.blood, cfg.blood && cfg.blood.notation);
  let l1 = cleanText(row.name);
  const out = [];
  if (bloodMode === 'inline' && bt) l1 = cleanText(l1 + ' ' + bt);
  out.push(l1);
  const l2 = cleanText(row.line2);
  if (l2) out.push(l2);
  if (bloodMode === 'line2' && bt) out.push(bt);
  return out.filter((l, i) => i === 0 || l !== '');
}

function better(a, b) {
  return RANK[a] > RANK[b];
}

let patternCache = { src: null, re: null };
function allowedRe(cfg) {
  const src = (cfg.text && cfg.text.allowedPattern) || "^[\\p{L}\\p{M}\\p{N} .,'’\\-/&+#()]+$";
  if (patternCache.src !== src) patternCache = { src, re: new RegExp(src, 'u') };
  return patternCache.re;
}

/**
 * Passingstoets.
 * @param {{lines:string[], fontId:string, format:{id?:string,w:number,h:number,lines:number}, borderId:string, uppercase:boolean}} input
 * @param {object} cfg      gemergde nametape-config
 * @param {object} metrics  config/nametape-metrics.json
 * @param {{fixes?:boolean}} [opt]
 * @returns {{status:'ok'|'warn'|'error', codes:string[], capMm:number, widthsMm:number[], badChars:string[], fixes:string[]}}
 */
export function fitText(input, cfg, metrics, opt = {}) {
  const fit = cfg.fit || {};
  const font = fontById(cfg, input.fontId) || fontById(cfg, 'block') || (cfg.fonts || [])[0] || { id: 'block' };
  const fm = (metrics.fonts || metrics)[font.id];
  const border = borderById(cfg, input.borderId) || { borderMm: 0 };
  const format = input.format;
  const script = !!font.script;

  const lines = (input.lines || []).map((l) => applyCase(l, input.uppercase !== false, font));
  // Lege regels tellen niet; zonder naam (regel 1) is de strook leeg.
  const used = lines.filter((l) => l !== '');
  const empty = lines.length === 0 || lines[0] === '';
  const n = used.length;
  const codes = [];
  let capMm = 0;
  let widths = [];
  const bad = [];

  if (empty) {
    codes.push('empty');
  } else if (n > format.lines) {
    codes.push(format.lines === 1 && n === 2 ? 'line2_no_room' : 'too_many_lines');
  }

  const re = allowedRe(cfg);
  const maxChars = Number(fit.maxCharsPerLine) || 30;
  let tooMany = false;
  for (const l of used) {
    if ([...l].length > maxChars) tooMany = true;
    for (const ch of l) {
      if (!re.test(ch) && bad.indexOf(ch) === -1) bad.push(ch);
    }
  }
  if (bad.length) codes.push('bad_chars');
  if (tooMany) codes.push('too_many_chars');

  if (!empty && n <= format.lines && fm) {
    const inset = Number(border.borderMm) + Number(fit.padMm);
    const innerW = format.w - 2 * inset;
    const innerH = format.h - 2 * inset;
    let cap = Math.min(Number(fit.maxCapMm), innerH / (n + (n - 1) * Number(fit.lineGapRatio)));
    const ems = used.map((l) => lineEm(l, fm));
    for (const em of ems) {
      if (em > 0) cap = Math.min(cap, (innerW * fm.capHeightEm) / em);
    }
    capMm = round1(Math.max(0, cap));
    widths = ems.map((em) => round1((capMm * em) / fm.capHeightEm));
    const min = script ? Number(fit.scriptMinCapMm) : Number(fit.minCapMm);
    const abs = script ? Number(fit.scriptAbsMinCapMm) : Number(fit.absMinCapMm);
    if (capMm < abs) codes.push('too_long');
    else if (capMm < min) codes.push('small_text');
  }

  const isError = codes.some((c) => c !== 'small_text');
  const status = isError ? 'error' : codes.length ? 'warn' : 'ok';
  const res = { status, codes, capMm, widthsMm: widths, badChars: bad, fixes: [] };
  if (opt.fixes !== false && status !== 'ok') res.fixes = computeFixes(input, used, res, cfg, metrics);
  return res;
}

/** Splits op de spatie die de langste regel het kortst maakt (null als er geen spatie is). */
export function bestSplit(text, fm) {
  const t = cleanText(text);
  let best = null, bestEm = Infinity;
  for (let i = 0; i < t.length; i++) {
    if (t[i] !== ' ') continue;
    const a = t.slice(0, i).trim(), b = t.slice(i + 1).trim();
    if (!a || !b) continue;
    const m = Math.max(lineEm(a, fm), lineEm(b, fm));
    if (m < bestEm) { bestEm = m; best = [a, b]; }
  }
  return best;
}

function computeFixes(input, used, res, cfg, metrics) {
  const fixes = [];
  const st = res.status;
  const nf = { fixes: false };
  const n = used.length;
  const structural = res.codes.some((c) => c === 'empty' || c === 'bad_chars' || c === 'too_many_chars');
  const lineErr = res.codes.indexOf('line2_no_room') !== -1 || res.codes.indexOf('too_many_lines') !== -1;

  if (!structural) {
    // 1. smalle letter
    const cond = fontById(cfg, 'condensed');
    if (cond && cond.enabled !== false && input.fontId !== 'condensed') {
      const r = fitText({ ...input, fontId: 'condensed' }, cfg, metrics, nf);
      if (better(r.status, st)) fixes.push('use_font:condensed');
    }
    // 2. kleinste groter formaat dat ok geeft (anders: het eerste dat beter is)
    const area = input.format.w * input.format.h;
    const cands = (cfg.formats || [])
      .filter((f) => !f.bloodFormat && f.id !== input.format.id && f.w * f.h > area && f.lines >= n && f.enabled !== false)
      .sort((a, b) => a.w * a.h - b.w * b.h || (a.id < b.id ? -1 : 1));
    let pick = null;
    for (const f of cands) {
      const r = fitText({ ...input, format: { id: f.id, w: f.w, h: f.h, lines: f.lines } }, cfg, metrics, nf);
      if (r.status === 'ok') { pick = f.id; break; }
      if (!pick && better(r.status, st)) pick = f.id;
    }
    if (pick) fixes.push('use_format:' + pick);
    // 3. op twee regels
    if (input.format.lines >= 2 && n === 1) {
      const fm = (metrics.fonts || metrics)[(fontById(cfg, input.fontId) || {}).id || 'block'];
      const sp = fm ? bestSplit(used[0], fm) : null;
      if (sp) {
        const r = fitText({ ...input, lines: sp }, cfg, metrics, nf);
        if (better(r.status, st)) fixes.push('split_lines');
      }
    }
    // 4. regel 2 weglaten
    if (lineErr && n >= 2) {
      const r = fitText({ ...input, lines: [input.lines[0]] }, cfg, metrics, nf);
      if (better(r.status, st)) fixes.push('drop_line2');
    }
  }
  // 5. naam aanpassen (altijd mogelijk)
  fixes.push('edit');
  return fixes;
}

/** Tekst zonder accenten, hoofdletters (voor controlewoorden). */
export function foldText(s) {
  return cleanText(s).normalize('NFD').replace(/\p{M}+/gu, '').toUpperCase();
}

/**
 * Controlewoorden (§1.5.4): hele woorden, hoofdletterongevoelig, zonder accenten.
 * @returns {string[]} gevonden woorden (in config-volgorde)
 */
export function reviewHits(texts, words) {
  const hay = ' ' + texts.map(foldText).join(' ').replace(/[^\p{L}\p{N}]+/gu, ' ') + ' ';
  const hits = [];
  for (const w of words || []) {
    const fw = foldText(w).replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
    if (fw && hay.indexOf(' ' + fw + ' ') !== -1) hits.push(w);
  }
  return hits;
}

/**
 * Passingstoets voor een hele rij in een batch (hoofdstrook + eventueel eigen bloedgroepstrook).
 * @returns {{fit: object, bloodFit: object|null, review: string[]}}
 */
export function checkRow(row, batch, cfg, metrics) {
  const format = resolveFormat(batch, cfg);
  const base = { fontId: batch.font, borderId: batch.border, uppercase: batch.uppercase !== false };
  const fit = format
    ? fitText({ ...base, lines: rowLines(row, batch.bloodMode, cfg), format }, cfg, metrics)
    : { status: 'error', codes: ['bad_format'], capMm: 0, widthsMm: [], badChars: [], fixes: [] };
  let bloodFit = null;
  if (batch.bloodMode === 'separate' && row.blood) {
    const bf = formatById(cfg, (cfg.blood && cfg.blood.separateFormat) || '5x2.5');
    if (bf) {
      bloodFit = fitText({ ...base, lines: [bloodText(row.blood, cfg.blood && cfg.blood.notation)], format: { id: bf.id, w: bf.w, h: bf.h, lines: bf.lines } }, cfg, metrics);
    }
  }
  return { fit, bloodFit, review: reviewHits([row.name, row.line2], cfg.reviewWords) };
}
