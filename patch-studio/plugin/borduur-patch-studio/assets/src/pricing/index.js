/**
 * Patch Studio — pricing engine (owner: pricing builder). SPEC §6.3 / §7.5.
 *
 * ONE algorithm, implemented twice: here (browser, live price) and in includes/class-pricing.php (server, cart).
 * Both MUST return identical numbers for identical input + config. tests/pricing/vectors.json and
 * tests/pricing/parity.test.js (JS vs PHP on thousands of random inputs) enforce this.
 * If you change anything here, make the same change in class-pricing.php in the same order of operations
 * (floating point sums are order dependent) and run `npm test && npm run test:php`.
 *
 * Pure module: no DOM, no WordPress, runs in Node, the browser and a worker.
 * All config amounts are EXCLUDING VAT. Rounding: roundHalfUp (shared/money.js).
 */
import { roundHalfUp, round2, round4, formatMoney } from '../shared/money.js';
import { createT, msg } from '../shared/i18n.js';
import messages from './messages.js';

export { messages };

/**
 * Labels used inside a PriceQuote. Kept here (not in messages.js) because PHP has the identical table
 * (Pricing::LINE_LABELS) and the parity test compares the full quote, labels included.
 */
export const LINE_LABELS = {
  nl: { item: '{qty} × {technique} {size} cm', shipping: 'Verzending (schatting)', shippingFree: 'Verzending: gratis' },
  fr: { item: '{qty} × {technique} {size} cm', shipping: 'Livraison (estimation)', shippingFree: 'Livraison : gratuite' },
};

/** Error codes that make a price impossible (all amounts are 0). */
const BLOCKING = ['unknown_technique', 'quote_only', 'size_too_small', 'size_too_large'];

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const num = (v, d = 0) => {
  const n = typeof v === 'number' ? v : typeof v === 'string' && v.trim() !== '' ? Number(v) : NaN;
  return Number.isFinite(n) ? n : d;
};
const int = (v) => Math.trunc(num(v, 0));
const scalarStr = (v) => (typeof v === 'string' ? v : typeof v === 'number' && Number.isFinite(v) ? String(v) : null);
const langOf = (locale) => (String(locale || 'nl').toLowerCase().startsWith('fr') ? 'fr' : 'nl');

/** {nl,fr} label → text (identical to shared/config.js label() and Settings::label()). */
function label(obj, locale) {
  if (obj == null) return '';
  if (typeof obj === 'string') return obj;
  if (!isObj(obj)) return '';
  const nl = obj.nl == null ? '' : String(obj.nl);
  const fr = obj.fr == null ? '' : String(obj.fr);
  return langOf(locale) === 'fr' ? fr || nl : nl || fr;
}

/** "7,5" / "8" — size as shown in the item label. */
export function formatSize(sizeCm) {
  return String(roundHalfUp(num(sizeCm), 1)).replace('.', ',');
}

/** Smallest multiple of `step` that is ≥ max(qty, minQty). */
export function normalizeQty(tech, qty) {
  const minQty = Math.max(1, int(tech && tech.minQty) || 1);
  const step = Math.max(1, int(tech && tech.qtyStep) || 1);
  const q = Math.max(int(qty), minQty);
  return Math.ceil(q / step) * step;
}

function sortedSteps(config) {
  const steps = (config.tiers && Array.isArray(config.tiers.steps) ? config.tiers.steps : [])
    .filter(isObj)
    .map((s) => ({ min: int(s.min), discount: num(s.discount) }));
  // Stable sort by min (identical to PHP usort with index tiebreak).
  return steps.map((s, i) => [s, i]).sort((a, b) => a[0].min - b[0].min || a[1] - b[1]).map((x) => x[0]);
}

/**
 * Tier for a (pooled) quantity: the step with the highest `min` ≤ tierQty, else {min:1, discount:0}.
 * @param {number} tierQty @param {Object} config @returns {{min:number, discount:number}}
 */
export function tierFor(tierQty, config) {
  let best = { min: 1, discount: 0 };
  let found = false;
  for (const s of sortedSteps(config)) {
    if (s.min <= tierQty && (!found || s.min >= best.min)) {
      best = { min: s.min, discount: s.discount };
      found = true;
    }
  }
  return best;
}

function optionById(config, id) {
  const list = Array.isArray(config.options) ? config.options : [];
  for (const o of list) if (isObj(o) && o.id === id) return o;
  return null;
}

function optionPrice(opt, techId) {
  const p = (isObj(opt.priceByTechnique) && isObj(opt.priceByTechnique[techId]) && opt.priceByTechnique[techId]) || (isObj(opt.price) && opt.price) || null;
  if (!p) return { type: 'per_piece', amount: 0 };
  const type = p.type === 'per_line' || p.type === 'percent' ? p.type : 'per_piece';
  return { type, amount: num(p.amount) };
}

function appliesTo(opt, techId) {
  return opt.enabled !== false && Array.isArray(opt.techniques) && opt.techniques.includes(techId);
}

function line(key, lbl, excl, vat, help) {
  const e = round2(excl);
  const tax = round2(e * vat);
  const out = { key, label: lbl, excl: e, tax, incl: round2(e + tax) };
  if (help !== undefined) out.help = help;
  return out;
}

/** Validate + normalise options (SPEC §6.3 step 4). Returns {chosen:string[], errors:[]} */
function normalizeOptions(rawOptions, techId, config) {
  const errors = [];
  const seen = new Set();
  const valid = [];
  const list = Array.isArray(rawOptions) ? rawOptions : [];
  for (const raw of list) {
    const id = scalarStr(raw);
    if (id === null || id === '' || seen.has(id)) continue;
    seen.add(id);
    const opt = optionById(config, id);
    if (!opt || !appliesTo(opt, techId)) {
      errors.push({ code: 'invalid_option', params: { id } });
      continue;
    }
    valid.push(opt);
  }
  const validIds = valid.map((o) => o.id);
  // requiresAny unmet → silently dropped.
  const chosen = valid.filter((o) => !Array.isArray(o.requiresAny) || o.requiresAny.length === 0 || o.requiresAny.some((r) => validIds.includes(r)));
  const chosenIds = chosen.map((o) => o.id);
  const groups = isObj(config.optionGroups) ? config.optionGroups : {};
  for (const gid of Object.keys(groups)) {
    const g = groups[gid];
    if (!isObj(g) || g.type !== 'radio') continue;
    const n = chosen.filter((o) => o.group === gid).length;
    if (n > 1) errors.push({ code: 'option_conflict', params: { group: gid } });
  }
  for (const gid of Object.keys(groups)) {
    const g = groups[gid];
    if (!isObj(g) || g.type !== 'radio' || !g.required) continue;
    if (Array.isArray(g.showIfAny) && g.showIfAny.length && !g.showIfAny.some((id) => chosenIds.includes(id))) continue;
    const available = (Array.isArray(config.options) ? config.options : []).some((o) => isObj(o) && o.group === gid && appliesTo(o, techId));
    if (!available) continue;
    if (!chosen.some((o) => o.group === gid)) errors.push({ code: 'missing_required_option', params: { group: gid } });
  }
  return { chosen, errors };
}

/** Single-pass placeholder fill (same as PHP strtr). */
function fill(tpl, vals) {
  return tpl.replace(/\{(qty|technique|size)\}/g, (m, k) => vals[k]);
}

function emptyShipping() {
  return { excl: 0, tax: 0, incl: 0, free: false, remainingForFreeInclVat: null };
}

/**
 * Core calculation. `withLadder` false is used for the ladder/next-tier sub-quotes.
 * @returns {import('../shared/types.js').PriceQuote}
 */
function compute(input, config, withLadder) {
  input = isObj(input) ? input : {};
  config = isObj(config) ? config : {};
  const locale = (typeof input.locale === 'string' && input.locale) || (typeof config.locale === 'string' && config.locale) || 'nl-BE';
  const lang = langOf(locale);
  const vat = num(config.vat && config.vat.rate);
  const techId = scalarStr(input.technique) ?? '';
  const sizeCm = num(input.sizeCm);
  const qty = int(input.qty);
  const poolQty = Math.max(0, int(input.poolQty));
  const other = Math.max(0, num(input.otherItemsInclVat));
  const charged = (Array.isArray(input.feesAlreadyCharged) ? input.feesAlreadyCharged : []).map(scalarStr).filter((x) => x !== null);
  const sizes = isObj(config.sizes) ? config.sizes : {};
  const techs = isObj(config.techniques) ? config.techniques : {};
  const tech = techId !== '' && isObj(techs[techId]) ? techs[techId] : null;

  const errors = [];
  let mode = 'order';
  let pricedSizeCm = 0;
  let base = 0;

  if (!tech || tech.enabled === false) {
    errors.push({ code: 'unknown_technique', params: { technique: techId } });
  } else if (tech.mode === 'quote') {
    mode = 'quote';
    errors.push({ code: 'quote_only', params: { technique: techId } });
  } else {
    const minCm = num(tech.minCm, num(sizes.minCm));
    const maxCm = num(tech.maxOrderCm, num(sizes.maxOrderCm));
    if (!(sizeCm > 0) || sizeCm < minCm) {
      errors.push({ code: 'size_too_small', params: { min: minCm } });
    } else if (sizeCm > maxCm) {
      mode = 'quote';
      errors.push({ code: 'size_too_large', params: { max: maxCm } });
    } else {
      const priced = (Array.isArray(sizes.pricedCm) ? sizes.pricedCm : []).map((v) => num(v, NaN)).filter((v) => Number.isFinite(v)).sort((a, b) => a - b);
      const hit = priced.find((v) => v >= sizeCm);
      const bp = isObj(tech.basePrice) && hit !== undefined ? tech.basePrice[String(hit)] : undefined;
      const b = num(bp, NaN);
      if (hit === undefined || !Number.isFinite(b)) {
        // No configured price for this size → the shop cannot sell it online.
        mode = 'quote';
        errors.push({ code: 'quote_only', params: { technique: techId } });
      } else {
        pricedSizeCm = hit;
        base = b;
      }
    }
  }

  const minQty = tech ? Math.max(1, int(tech.minQty) || 1) : 1;
  const qtyStep = tech ? Math.max(1, int(tech.qtyStep) || 1) : 1;
  if (tech && tech.enabled !== false && tech.mode !== 'quote') {
    if (qty < minQty) errors.push({ code: 'below_min_qty', params: { min: minQty } });
    else if (qty % qtyStep !== 0) errors.push({ code: 'qty_step', params: { step: qtyStep } });
  }

  let chosen = [];
  if (tech && tech.enabled !== false) {
    const n = normalizeOptions(input.options, techId, config);
    chosen = n.chosen;
    if (tech.mode !== 'quote') errors.push(...n.errors);
  }

  const tiered = !!(tech && tech.tiers);
  const pooled = tiered && !!(config.tiers && config.tiers.pooled);
  const blocked = qty < 1 || errors.some((e) => BLOCKING.includes(e.code));

  const out = {
    ok: false,
    mode,
    errors,
    technique: techId,
    sizeCm,
    pricedSizeCm,
    qty,
    currency: config.currency || 'EUR',
    vatRate: vat,
    options: chosen.map((o) => o.id),
    basePriceExcl: 0,
    optionsPerPieceExcl: 0,
    optionsPercent: 0,
    tier: { min: 1, discount: 0 },
    tierQty: qty,
    pooled,
    unitExcl: 0,
    unitIncl: 0,
    lineExcl: 0,
    lineTax: 0,
    lineIncl: 0,
    extras: [],
    fees: [],
    extrasIncl: 0,
    feesIncl: 0,
    subtotalExcl: 0,
    subtotalIncl: 0,
    shipping: emptyShipping(),
    totalExcl: 0,
    totalIncl: 0,
    perPieceAllInExcl: 0,
    perPieceAllInIncl: 0,
    nextTier: null,
    ladder: [],
    breakdown: [],
  };
  if (blocked) return out;

  // 5. Options.
  let pp = 0;
  let pct = 0;
  const extras = [];
  for (const o of chosen) {
    const p = optionPrice(o, techId);
    if (p.type === 'per_piece') pp += p.amount;
    else if (p.type === 'percent') pct += p.amount;
    else extras.push(line('extra:' + o.id, label(o.label, locale), p.amount, vat, label(o.help, locale)));
  }

  // 6. Tier.
  let tier = { min: 1, discount: 0 };
  let tierQty = qty;
  if (tiered) {
    tierQty = qty + (pooled ? poolQty : 0);
    tier = tierFor(tierQty, config);
  }

  // 7. Line.
  const unitExcl = round4((base + pp) * (1 + pct) * (1 - tier.discount));
  const lineExcl = round2(unitExcl * qty);
  const lineTax = round2(lineExcl * vat);
  const lineIncl = round2(lineExcl + lineTax);
  const unitIncl = round2(lineIncl / qty);

  // 9. Fees.
  const fees = [];
  const groups = (config.fees && isObj(config.fees.groups) && config.fees.groups) || {};
  const fg = scalarStr(tech.feeGroup) ?? '';
  if (fg !== '' && isObj(groups[fg]) && !charged.includes(fg)) {
    const amount = num(groups[fg].amount);
    if (amount > 0) fees.push(line('fee:' + fg, label(groups[fg].label, locale), amount, vat, label(groups[fg].help, locale)));
  }

  // 10. Subtotal + shipping estimate.
  let subExcl = lineExcl;
  let subIncl = lineIncl;
  let extrasIncl = 0;
  let feesIncl = 0;
  for (const l of extras) { subExcl += l.excl; subIncl += l.incl; extrasIncl += l.incl; }
  for (const l of fees) { subExcl += l.excl; subIncl += l.incl; feesIncl += l.incl; }
  const subtotalExcl = round2(subExcl);
  const subtotalIncl = round2(subIncl);

  const ship = isObj(config.shipping) ? config.shipping : {};
  const articles = round2(lineIncl + other);
  const freeFrom = ship.freeFromInclVat == null ? null : num(ship.freeFromInclVat, NaN);
  const hasFree = freeFrom !== null && Number.isFinite(freeFrom);
  let shipping;
  if (hasFree && articles >= freeFrom) {
    shipping = { excl: 0, tax: 0, incl: 0, free: true, remainingForFreeInclVat: null };
  } else {
    const flat = round2(Math.max(0, num(ship.flat)));
    const tax = round2(flat * vat);
    shipping = { excl: flat, tax, incl: round2(flat + tax), free: false, remainingForFreeInclVat: hasFree ? round2(freeFrom - articles) : null };
  }

  const L = LINE_LABELS[lang];
  const itemLine = {
    key: 'item',
    label: fill(L.item, { qty: String(qty), technique: label(tech.label, locale), size: formatSize(sizeCm) }),
    excl: lineExcl,
    tax: lineTax,
    incl: lineIncl,
  };
  const shipLine = { key: 'shipping', label: shipping.free ? L.shippingFree : L.shipping, excl: shipping.excl, tax: shipping.tax, incl: shipping.incl };

  Object.assign(out, {
    ok: mode === 'order' && errors.length === 0,
    basePriceExcl: base,
    optionsPerPieceExcl: round4(pp),
    optionsPercent: round4(pct),
    tier,
    tierQty,
    unitExcl,
    unitIncl,
    lineExcl,
    lineTax,
    lineIncl,
    extras,
    fees,
    extrasIncl: round2(extrasIncl),
    feesIncl: round2(feesIncl),
    subtotalExcl,
    subtotalIncl,
    shipping,
    totalExcl: round2(subtotalExcl + shipping.excl),
    totalIncl: round2(subtotalIncl + shipping.incl),
    perPieceAllInExcl: round2(subtotalExcl / qty),
    perPieceAllInIncl: round2(subtotalIncl / qty),
    breakdown: [itemLine, ...extras, ...fees, shipLine],
  });

  // 12. Ladder + next tier (real recalculations with the same options, pool and cart context).
  if (withLadder && tiered) {
    const steps = sortedSteps(config);
    const sub = (q) => compute({ ...input, qty: q, withLadder: false }, config, false);
    const seen = new Set();
    for (const s of steps) {
      if (s.min < minQty) continue;
      const q = Math.ceil(Math.max(s.min, minQty) / qtyStep) * qtyStep;
      if (seen.has(q)) continue;
      seen.add(q);
      const r = sub(q);
      out.ladder.push({ min: q, discount: r.tier.discount, unitIncl: r.unitIncl, perPieceAllInIncl: r.perPieceAllInIncl, totalIncl: r.totalIncl, current: r.tier.min === tier.min });
    }
    const next = steps.find((s) => s.min > tierQty);
    if (next) {
      const nq = Math.ceil(Math.max(next.min - (pooled ? poolQty : 0), minQty) / qtyStep) * qtyStep;
      if (nq > qty) {
        const r = sub(nq);
        out.nextTier = {
          min: next.min,
          qty: nq,
          discount: r.tier.discount,
          unitIncl: r.unitIncl,
          perPieceAllInIncl: r.perPieceAllInIncl,
          totalIncl: r.totalIncl,
          savingPerPieceIncl: round2(unitIncl - r.unitIncl),
          savingPerPieceAllInIncl: round2(out.perPieceAllInIncl - r.perPieceAllInIncl),
          extraPieces: nq - qty,
          extraCostIncl: round2(r.totalIncl - out.totalIncl),
          withinReach: nq <= 2 * qty,
        };
      }
    }
  }
  return out;
}

/**
 * PriceInput → PriceQuote (SPEC §6.3). Identical to Pricing::quote() in PHP.
 * @param {import('../shared/types.js').PriceInput & {locale?:string}} input
 * @param {Object} config public config
 */
export function quote(input, config) {
  return compute(input, config, !isObj(input) || input.withLadder !== false);
}

/**
 * Quotes for every enabled technique (technique cards / compare view). Each technique gets its own
 * default options and a quantity normalised to its minQty/qtyStep (as the UI does on a technique switch);
 * `qtyAdjusted` tells the UI when that happened. Ladder only when input.withLadder === true.
 */
export function quoteAll(input, config) {
  const out = {};
  const techs = (config && config.techniques) || {};
  const order = (config && Array.isArray(config.techniqueOrder) && config.techniqueOrder) || Object.keys(techs);
  for (const id of order) {
    const t = techs[id];
    if (!isObj(t) || !t.enabled) continue;
    const q = t.mode === 'quote' ? int(input && input.qty) : normalizeQty(t, input && input.qty);
    const r = quote({ ...(input || {}), technique: id, qty: q, options: defaultOptionsFor(config, id), withLadder: !!(input && input.withLadder === true) }, config);
    r.qtyAdjusted = q !== int(input && input.qty);
    out[id] = r;
  }
  return out;
}

/** Same rules as shared/config.js defaultOptions(), kept local so this module has no extra coupling. */
function defaultOptionsFor(config, techId) {
  const tech = (config.techniques && config.techniques[techId]) || {};
  const opts = (config.options || []).filter((o) => isObj(o) && appliesTo(o, techId));
  const out = [];
  for (const [gid, g] of Object.entries(config.optionGroups || {})) {
    if (!isObj(g) || g.type !== 'radio') continue;
    if (Array.isArray(g.showIfAny) && g.showIfAny.length && !g.showIfAny.some((id) => out.includes(id))) continue;
    const inGroup = opts.filter((o) => o.group === gid);
    if (!inGroup.length) continue;
    const pref = (tech.defaults && tech.defaults[gid]) || (inGroup.find((o) => o.default) || {}).id;
    if (pref && inGroup.some((o) => o.id === pref)) out.push(pref);
    else if (g.required) out.push(inGroup[0].id);
  }
  return out;
}

/**
 * Honest "save X at Y pieces" hint, built from quote.nextTier (a real recalculation).
 * Shown only when the next tier is within reach (≤ 2× current qty) and actually cheaper per piece.
 * @param {import('../shared/types.js').PriceQuote} q
 * @param {string} [locale]
 * @param {Object} [overrides] config.strings[locale]
 * @returns {null | {kind:'cheaper_total'|'same_total'|'next_tier', main:{key,params,text}, secondary:{key,params,text}|null, nextTier:Object}}
 */
export function savingHint(q, locale = 'nl-BE', overrides = {}) {
  const n = q && q.nextTier;
  if (!q || !q.ok || !n || !n.withinReach || !(n.savingPerPieceIncl > 0)) return null;
  const t = createT([messages], locale, overrides);
  const loc = langOf(locale) === 'fr' ? 'fr-BE' : 'nl-BE';
  const m = (x) => formatMoney(x, loc);
  if (n.extraCostIncl < 0) {
    return {
      kind: 'cheaper_total',
      main: msg(t, 'pricing.hint.cheaperTotal', { qty: n.qty, total: m(n.totalIncl), diff: m(-n.extraCostIncl), currentQty: q.qty, currentTotal: m(q.totalIncl) }),
      secondary: null,
      nextTier: n,
    };
  }
  if (n.extraCostIncl === 0) {
    return { kind: 'same_total', main: msg(t, 'pricing.hint.sameTotal', { qty: n.qty, currentQty: q.qty, total: m(q.totalIncl) }), secondary: null, nextTier: n };
  }
  return {
    kind: 'next_tier',
    main: msg(t, 'pricing.hint.next', { qty: n.qty, unit: m(n.unitIncl), current: m(q.unitIncl) }),
    secondary: msg(t, 'pricing.hint.honest', { n: q.qty }),
    extra: msg(t, 'pricing.hint.extraCost', { n: n.extraPieces, diff: m(n.extraCostIncl) }),
    nextTier: n,
  };
}

/**
 * Customer text for a PriceQuote error ({code, params}) → Msg. Group labels come from the config.
 */
export function errorMessage(err, config, locale = 'nl-BE', overrides = {}) {
  const t = createT([messages], locale, overrides);
  const p = { ...(err && err.params) };
  if (p.group && config && config.optionGroups && config.optionGroups[p.group]) p.group = label(config.optionGroups[p.group].label, locale);
  for (const k of ['min', 'max']) if (typeof p[k] === 'number') p[k] = String(p[k]).replace('.', ',');
  return msg(t, 'pricing.error.' + (err && err.code), p);
}

/**
 * Effect of one option on the price, for the option list ("+ € 1,82/st." / "inbegrepen" / "− 10 %").
 * Amounts incl. VAT. Returns {type, amountIncl, text}.
 */
export function optionEffect(opt, techId, config, locale = 'nl-BE', overrides = {}) {
  const t = createT([messages], locale, overrides);
  const p = optionPrice(opt || {}, techId);
  const vat = num(config && config.vat && config.vat.rate);
  const loc = langOf(locale) === 'fr' ? 'fr-BE' : 'nl-BE';
  if (p.type === 'percent') {
    const pct = String(roundHalfUp(Math.abs(p.amount) * 100, 1)).replace('.', ',');
    return { type: 'percent', amountIncl: p.amount, text: p.amount === 0 ? t('pricing.included') : t('pricing.percentDelta', { sign: p.amount < 0 ? '−' : '+', pct }) };
  }
  const incl = round2(round2(p.amount) * (1 + vat));
  if (incl === 0) return { type: p.type, amountIncl: 0, text: t('pricing.included') };
  return { type: p.type, amountIncl: incl, text: t(p.type === 'per_line' ? 'pricing.perLineDelta' : 'pricing.perPieceDelta', { amount: formatMoney(incl, loc) }) };
}
