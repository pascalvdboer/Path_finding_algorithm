/**
 * Patch Studio — config-hulpfuncties (eigenaar: architect, BEVROREN contract; SPEC.md §7.1).
 * PHP-tegenhanger: BorduurPatchStudio\Settings (deep merge + public_config) — zelfde regels.
 */

/** Sleutels die NOOIT naar de browser gaan (Settings::public_config() strip ze ook). */
export const PRIVATE_KEYS = ['woo', 'retention', 'security', 'admin'];

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

/**
 * Deep merge: objecten recursief, arrays (lijsten) en scalars worden VERVANGEN.
 * Identiek aan Settings::deep_merge() in PHP.
 */
export function deepMerge(base, over) {
  if (!isObj(base) || !isObj(over)) return over === undefined ? base : over;
  const out = { ...base };
  for (const k of Object.keys(over)) out[k] = isObj(base[k]) && isObj(over[k]) ? deepMerge(base[k], over[k]) : over[k];
  return out;
}

/**
 * Publieke config opbouwen (dev-pagina en tests; in WordPress doet PHP dit).
 * @param {Object} defaults   inhoud van config/defaults.json
 * @param {Object} palette    inhoud van config/thread-palette.json ({threads:[...]})
 * @param {Object} [runtime]  {locale, rest:{root,namespace}|null, assets:{workerUrl,fontsUrl}, strings:{}, page:{}}
 * @param {Object} [overrides] opgeslagen beheerinstellingen (zelfde vorm als defaults)
 */
export function buildPublicConfig(defaults, palette, runtime = {}, overrides = {}) {
  const full = deepMerge(defaults, overrides);
  const off = new Set(full.threadsDisabled || []);
  full.threads = ((palette && palette.threads) || full.threads || []).filter((t) => !off.has(t.code) && t.stock !== false);
  for (const k of PRIVATE_KEYS) delete full[k];
  for (const t of Object.values(full.techniques || {})) delete t.legacy;
  return Object.assign(full, {
    locale: runtime.locale || full.locale || 'nl-BE',
    rest: runtime.rest === undefined ? null : runtime.rest,
    assets: runtime.assets || {},
    strings: runtime.strings || {},
    page: runtime.page || {},
  });
}

/** Techniek-object of null */
export function technique(config, id) {
  return (config.techniques && config.techniques[id]) || null;
}

/** Ingeschakelde technieken in de volgorde van config.techniqueOrder */
export function enabledTechniques(config) {
  return (config.techniqueOrder || Object.keys(config.techniques)).filter((id) => config.techniques[id] && config.techniques[id].enabled);
}

/** Opties die bij een techniek horen (en ingeschakeld zijn), in config-volgorde */
export function optionsFor(config, techId) {
  return (config.options || []).filter((o) => o.enabled !== false && (o.techniques || []).includes(techId));
}

export function optionById(config, id) {
  return (config.options || []).find((o) => o.id === id) || null;
}

/** Prijs van een optie voor een techniek: {type:'per_piece'|'per_line'|'percent', amount} */
export function optionPrice(opt, techId) {
  return (opt.priceByTechnique && opt.priceByTechnique[techId]) || opt.price || { type: 'per_piece', amount: 0 };
}

/** Standaardopties (radio-defaults per groep) voor een techniek */
export function defaultOptions(config, techId) {
  const tech = technique(config, techId) || {};
  const out = [];
  const groups = config.optionGroups || {};
  for (const [gid, g] of Object.entries(groups)) {
    if (g.type !== 'radio') continue;
    if (g.showIfAny && !g.showIfAny.some((id) => out.includes(id))) continue;
    const opts = optionsFor(config, techId).filter((o) => o.group === gid);
    if (!opts.length) continue;
    const pref = (tech.defaults && tech.defaults[gid]) || (opts.find((o) => o.default) || {}).id;
    if (pref && opts.some((o) => o.id === pref)) out.push(pref);
    else if (g.required) out.push(opts[0].id);
  }
  return out;
}

/** Tekst uit een {nl,fr}-label volgens locale */
export function label(obj, locale) {
  if (obj == null) return '';
  if (typeof obj === 'string') return obj;
  return String(locale || 'nl').toLowerCase().startsWith('fr') ? obj.fr || obj.nl || '' : obj.nl || obj.fr || '';
}
