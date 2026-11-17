/**
 * Patch Studio — mini-i18n (eigenaar: architect, BEVROREN contract).
 * Elke module heeft een eigen catalogus: `export default { nl: {key: 'tekst {param}'}, fr: {...} }`.
 * Brontaal = Vlaams Nederlands ("je"); Frans = "vous". Ontbrekende FR-sleutel → NL-tekst.
 * PHP kan elke sleutel overschrijven via config.strings[locale][key] (vertaling via .po).
 *
 * Placeholders: {naam}. Getallen worden NIET automatisch geformatteerd: geef al geformatteerde strings mee
 * (formatNumber/formatMoney uit money.js), behalve {n} voor meervoud.
 * Meervoud: waarde als object { one: '1 stuk', other: '{n} stuks' } en param n.
 */

/** 'nl-BE' | 'fr-BE' | 'fr_FR' … → 'nl' | 'fr' */
export function langOf(locale) {
  return String(locale || 'nl').toLowerCase().startsWith('fr') ? 'fr' : 'nl';
}

/**
 * @typedef {{[lang: string]: {[key: string]: string | {one: string, other: string}}}} Catalog
 */

/**
 * Maak een vertaalfunctie.
 * @param {Catalog[]} catalogs  één of meer catalogi (later = voorrang)
 * @param {string} locale
 * @param {{[key: string]: string}} [overrides] config.strings[locale] uit PHP
 * @returns {(key: string, params?: object) => string}
 */
export function createT(catalogs, locale, overrides = {}) {
  const lang = langOf(locale);
  const merged = { nl: {}, fr: {} };
  for (const c of catalogs) {
    if (!c) continue;
    Object.assign(merged.nl, c.nl || {});
    Object.assign(merged.fr, c.fr || {});
  }
  return function t(key, params = {}) {
    let v = overrides && overrides[key] != null ? overrides[key] : merged[lang][key] ?? merged.nl[key];
    if (v == null) return key; // zichtbaar ontbrekend, nooit crashen
    if (typeof v === 'object') {
      const n = Number(params.n ?? 0);
      // NL: 1 = enkelvoud; FR: 0 en 1 = enkelvoud (gettext plural=(n > 1))
      const one = lang === 'fr' ? n <= 1 : n === 1;
      v = one ? v.one : v.other;
    }
    return String(v).replace(/\{(\w+)\}/g, (m, k) => (params[k] != null ? String(params[k]) : m));
  };
}

/**
 * Bericht-object zoals de adviesengine het teruggeeft: sleutel + parameters + kant-en-klare tekst.
 * @typedef {{key: string, params: object, text: string}} Msg
 */
export function msg(t, key, params = {}) {
  return { key, params, text: t(key, params) };
}
