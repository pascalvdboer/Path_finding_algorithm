/**
 * Patch Studio — advice: translation/formatting context (pure, no DOM).
 * Every customer-facing text goes through the catalogue in messages.js + shared/i18n.js,
 * so a Msg is always {key, params, text} with already formatted numbers (comma decimals).
 */
import { createT, msg, langOf } from '../shared/i18n.js';
import { formatMoney, round2 } from '../shared/money.js';
import { label } from '../shared/config.js';
import catalog from './messages.js';

const cache = new WeakMap();
const fmtCache = new Map();

/** Cached Intl.NumberFormat (creating one per call costs ~0,05 ms; the engine formats hundreds of numbers). */
function numFmt(locale, d) {
  const key = locale + '|' + d;
  let f = fmtCache.get(key);
  if (!f) { f = new Intl.NumberFormat(locale, { maximumFractionDigits: d }); fmtCache.set(key, f); }
  return f;
}

/** config.strings may be {locale:{key:text}}, {lang:{key:text}} or a flat {key:text} map. */
function overridesFor(config, locale) {
  const s = (config && config.strings) || {};
  if (s[locale] && typeof s[locale] === 'object') return s[locale];
  const lang = langOf(locale);
  if (s[lang] && typeof s[lang] === 'object') return s[lang];
  return Object.keys(s).some((k) => k.indexOf('.') > 0) ? s : {};
}

/** Lower-case a label for use mid-sentence, keeping acronyms ("PVC / rubber") intact. */
export function lowerFirst(str) {
  if (!str) return '';
  if (str.length > 1 && str[1] === str[1].toUpperCase() && str[1] !== str[1].toLowerCase()) return str;
  return str[0].toLowerCase() + str.slice(1);
}

export function upperFirst(str) {
  return str ? str[0].toUpperCase() + str.slice(1) : '';
}

/**
 * @param {Object} config public config
 * @param {string} [locale]
 */
export function makeContext(config, locale) {
  const loc = locale || (config && config.locale) || 'nl-BE';
  let perConfig = cache.get(config);
  if (!perConfig) { perConfig = new Map(); if (config && typeof config === 'object') cache.set(config, perConfig); }
  const hit = perConfig.get(loc);
  if (hit) return hit;

  const t = createT([catalog], loc, overridesFor(config, loc));
  const vat = (config && config.vat) || {};
  const ctx = {
    locale: loc,
    lang: langOf(loc),
    t,
    /** Build a Msg; params must already be formatted strings (except n). */
    m: (key, params = {}) => msg(t, key, params),
    /** 2,5 — comma decimals, max `d` decimals. */
    num: (n, d = 1) => numFmt(loc, d).format(Number(n) || 0),
    /** Measured value, rounded DOWN to 0,1 so a shown value never looks better than it is. */
    mm: (n) => numFmt(loc, 1).format(Math.floor((Number(n) || 0) * 10 + 1e-6) / 10),
    /** Option/extra amount (config is excl. VAT) as shown to the customer. */
    money: (amountExcl) => {
      const incl = vat.displayInclVat === false ? amountExcl : round2(amountExcl * (1 + (Number(vat.rate) || 0)));
      return formatMoney(incl, loc, (config && config.currency) || 'EUR');
    },
    label: (obj) => label(obj, loc),
    techName: (id) => {
      const tech = config && config.techniques && config.techniques[id];
      return tech ? label(tech.label, loc) || id : id;
    },
  };
  /** Both spellings for templates: {tech} mid-sentence, {Tech} at sentence start. */
  ctx.techParams = (id) => {
    const name = ctx.techName(id);
    return { tech: lowerFirst(name), Tech: upperFirst(name) };
  };
  perConfig.set(loc, ctx);
  return ctx;
}
