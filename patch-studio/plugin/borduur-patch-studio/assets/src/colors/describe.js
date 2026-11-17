/**
 * Patch Studio — colors: human-language descriptions (Msg objects {key, params, text}) for matches, merges,
 * contrast and fabric choice. Optional helpers; the advice engine and UI may also build their own texts from the
 * raw data. Never exposes ΔE numbers.
 */
import { createT, msg } from '../shared/i18n.js';
import { label } from '../shared/config.js';
import messages from './messages.js';

function getT(opts = {}) {
  if (typeof opts.t === 'function') return opts.t;
  const locale = opts.locale || (opts.config && opts.config.locale) || 'nl-BE';
  const overrides = (opts.config && opts.config.strings && opts.config.strings[locale]) || {};
  return createT([messages], locale, overrides);
}
function localeOf(opts = {}) {
  return opts.locale || (opts.config && opts.config.locale) || 'nl-BE';
}
function brandOf(opts = {}) {
  const b = (opts.config && opts.config.threadBrand) || 'Madeira Polyneon';
  // Descriptive short form: "Polyneon 1747" (07 §5.1: brand names only descriptive).
  return String(b).replace(/^Madeira\s+/i, '');
}

/** "Rood · Polyneon 1839" (or short "Rood (1839)"). */
export function threadLabel(thread, opts = {}) {
  if (!thread) return '';
  const t = getT(opts);
  const name = label(thread.name, localeOf(opts)) || String(thread.code);
  return t(opts.short ? 'colors.thread.short' : 'colors.thread.label', { name, code: thread.code, brand: brandOf(opts) });
}

/** Customer-facing quality label ('zeer goed' | 'goed' | 'benadering' | 'geen exacte garenkleur'). */
export function qualityLabel(quality, opts = {}) {
  return getT(opts)('colors.quality.' + (quality || 'none'));
}

/** The fixed UI disclaimer sentence about screen colours. */
export function colorDisclaimer(opts = {}) {
  return getT(opts)('colors.disclaimer');
}

/**
 * Message for a single match when it deserves one: 'none' → suggest printed; 'approx' → which thread we use.
 * @returns {import('../shared/i18n.js').Msg|null}
 */
export function describeMatch(match, opts = {}) {
  if (!match || !match.thread) return null;
  const t = getT(opts);
  const thread = threadLabel(match.thread, opts);
  if (match.quality === 'none') return msg(t, 'colors.match.none', { thread });
  if (match.quality === 'approx') return msg(t, 'colors.match.approx', { thread });
  return null;
}

/**
 * Explanations for the merges of a ReducedPalette (one Msg per merge, same-thread merges grouped per target).
 * @param {ReturnType<import('./reduce.js').reducePalette>} reduced
 * @param {{locale?:string, config?:Object, t?:Function}} [opts]
 * @returns {import('../shared/i18n.js').Msg[]}
 */
export function describeMerges(reduced, opts = {}) {
  if (!reduced || !Array.isArray(reduced.merges) || !reduced.merges.length) return [];
  const t = getT(opts);
  const locale = localeOf(opts);
  const out = [];
  const sameByInto = new Map();
  for (const m of reduced.merges) {
    const into = reduced.colors[m.into];
    const tm = into && into.thread && into.thread.thread;
    if (!tm) continue;
    if (m.reason === 'same_thread') {
      sameByInto.set(m.into, (sameByInto.get(m.into) || 0) + m.from.length);
    }
  }
  for (const [ci, extra] of sameByInto) {
    const tm = reduced.colors[ci].thread.thread;
    out.push(msg(t, 'colors.merge.same_thread', {
      n: extra + 1,
      family: t('colors.family.' + (tm.family || 'other')),
      thread: threadLabel(tm, opts),
    }));
  }
  for (const m of reduced.merges) {
    if (m.reason === 'same_thread') continue;
    const into = reduced.colors[m.into];
    const tm = into && into.thread && into.thread.thread;
    if (!tm) continue;
    const from = m.fromThread ? label(m.fromThread.name, locale) || String(m.fromThread.code) : String(m.fromCode || '?');
    const thread = threadLabel(tm, opts);
    if (m.reason === 'forced') out.push(msg(t, 'colors.merge.forced', { from, thread, max: reduced.maxColors }));
    else out.push(msg(t, 'colors.merge.similar', { from, into: label(tm.name, locale), thread }));
  }
  return out;
}

/**
 * Contrast warnings (only 'low' and 'bad' levels) for display under the preview.
 * @param {import('../shared/types.js').ContrastCheck[]} checks
 * @param {{label?:Object}|string} fabric  fabric object from config.fabrics or a plain name
 * @param {{locale?:string, config?:Object, t?:Function, names?:string[]}} [opts]  names[i] = display name per check
 */
export function describeContrast(checks, fabric, opts = {}) {
  const t = getT(opts);
  const locale = localeOf(opts);
  const fabricName = typeof fabric === 'string' ? fabric : label(fabric && fabric.label, locale);
  const out = [];
  (checks || []).forEach((c, i) => {
    if (c.level !== 'bad' && c.level !== 'low') return;
    const color = (opts.names && opts.names[i]) || c.hex;
    out.push({ ...msg(t, c.level === 'bad' ? 'colors.contrast.bad' : 'colors.contrast.low', { color, fabric: fabricName }), level: c.level, index: c.index });
  });
  return out;
}

/**
 * "We kozen marineblauw: daarop komen je kleuren het best uit."
 * @param {{id:string, reason:string}} pick   result of pickFabric
 * @param {Object[]} fabrics  config.fabrics
 */
export function describeFabric(pick, fabrics, opts = {}) {
  if (!pick) return null;
  const t = getT(opts);
  const f = (fabrics || []).find((x) => x && x.id === pick.id);
  const name = f ? label(f.label, localeOf(opts)).toLowerCase() : pick.id;
  return msg(t, 'colors.fabric.' + (pick.reason || 'default'), { fabric: name });
}
