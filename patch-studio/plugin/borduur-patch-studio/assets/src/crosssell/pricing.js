/**
 * Cross-sell — prijzen (SPEC-EXTRA §2.6). JS-tweeling van includes/crosssell/class-pricing.php.
 * Alle bedragen excl. btw; incl. = round2(excl × (1 + btw)) per regel, zoals SPEC §6.3.
 *
 * De patchprijs komt NIET uit deze module: de aanroeper geeft `ctx.patchUnit(technique, sizeCm, options, qty, poolQty)`
 * mee (server: Pricing::cart_unit_excl; dev/tests: de JS-prijsengine; paneel: de ladder uit de kaart). Zo blijft er één bron.
 */
import { round2, round4 } from '../shared/money.js';

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const num = (v, d = 0) => {
  const n = typeof v === 'number' ? v : typeof v === 'string' && v.trim() !== '' ? Number(v) : NaN;
  return Number.isFinite(n) ? n : d;
};
const int = (v) => {
  const n = num(v, 0);
  return n > 0 ? Math.floor(n) : 0;
};

/** Staffel (hoofdconfig `tiers.steps`): hoogste stap met min ≤ qty, anders 0 %. */
export function tierFor(qty, main) {
  const steps = (main && isObj(main.tiers) && Array.isArray(main.tiers.steps) ? main.tiers.steps : []).filter(isObj);
  let best = { min: 1, discount: 0 };
  let found = false;
  for (const s of steps) {
    const min = int(s.min);
    if (min <= qty && (!found || min >= best.min)) {
      best = { min, discount: num(s.discount) };
      found = true;
    }
  }
  return best;
}

/** Ontwerpopties met de achterkant vervangen door `forceBacking` (de patch wordt op de drager genaaid). */
export function patchOptions(designOptions, main, forceBacking = 'sew_on') {
  const groupOf = {};
  for (const o of main && Array.isArray(main.options) ? main.options : []) if (isObj(o)) groupOf[o.id] = o.group;
  const drop = ['backing', 'velcro_color', 'backing_extra'];
  const out = (Array.isArray(designOptions) ? designOptions : []).filter((id) => typeof id === 'string' && !drop.includes(groupOf[id]));
  if (forceBacking && !out.includes(forceBacking)) out.push(forceBacking);
  return out;
}

/** Labels van de prijsopbouw. */
export const PART_LABELS = {
  nl: { blank: '{blank}', patch: 'patch', application: 'aanbrengen', product: '{product}', frame: 'kader', leather: 'lederen patch', mold: 'PVC-mal sleutelhanger (eenmalig)' },
  fr: { blank: '{blank}', patch: 'écusson', application: 'pose', product: '{product}', frame: 'cadre', leather: 'écusson en cuir', mold: 'Moule PVC porte-clés (unique)' },
};

const lang = (locale) => (String(locale || 'nl').toLowerCase().startsWith('fr') ? 'fr' : 'nl');
const lbl = (o, l) => (isObj(o) ? String(o[l] || o.nl || '') : typeof o === 'string' ? o : '');

/**
 * Prijs per stuk voor één cross-sell-regel.
 * @param {Object} item  item uit crosssell.json
 * @param {Object} input { qty, poolQty, blankExcl, productExcl, options:string[], placement, technique, patchSizeCm,
 *                         designOptions:string[], moldCovered:boolean, moldCharged:boolean }
 * @param {Object} ctx   { cfg (crosssell), main (hoofdconfig: vat, tiers, techniques, options), patchUnit, locale }
 */
export function priceItem(item, input, ctx) {
  const cfg = (ctx && ctx.cfg) || {};
  const main = (ctx && ctx.main) || {};
  const l = lang(ctx && ctx.locale);
  const vat = num(main.vat && main.vat.rate);
  const qty = int(input.qty);
  const pool = Math.max(0, int(input.poolQty));
  const errors = [];
  const parts = [];
  const fees = [];
  const app = isObj(cfg.application) ? cfg.application : {};
  const L = PART_LABELS[l];
  const part = (key, label, excl) => parts.push({ key, label, excl, incl: round2(excl * (1 + vat)) });
  let unitExcl = 0;
  let discount = 0;

  if (qty < 1) errors.push('qty');
  const type = String(item && item.type);

  if (type === 'patch_on_blank' || type === 'frame') {
    const tech = isObj(main.techniques) && isObj(main.techniques[input.technique]) ? main.techniques[input.technique] : null;
    if (!tech) errors.push('technique');
    const opts = patchOptions(input.designOptions, main, app.forceBacking || 'sew_on');
    const patch = tech && ctx.patchUnit ? num(ctx.patchUnit(String(input.technique), num(input.patchSizeCm), opts, qty, pool)) : 0;
    if (!(patch > 0)) errors.push('patch_price');
    if (type === 'patch_on_blank') {
      const blank = num(input.blankExcl);
      if (!(blank > 0)) errors.push('no_price');
      const zone = input.placement === 'back' ? 'garment_back' : String(item.zone || 'cap');
      const pooled = !!(main.tiers && main.tiers.pooled);
      if (app.tiers === 'patch' && tech && tech.tiers) discount = tierFor(qty + (pooled ? pool : 0), main).discount;
      const appUnit = round4(num(app[zone]) * (1 - discount));
      unitExcl = round4(blank + patch + appUnit);
      part('blank', lbl(item.blankLabel, l) || L.blank, blank);
      part('patch', L.patch, patch);
      part('application', L.application, appUnit);
    } else {
      const product = num(input.productExcl);
      if (!(product > 0)) errors.push('no_price');
      unitExcl = round4(product + patch);
      part('frame', L.frame, product);
      part('patch', L.patch, patch);
    }
  } else if (type === 'laser_patch_on_blank') {
    const blank = num(input.blankExcl);
    if (!(blank > 0)) errors.push('no_price');
    discount = tierFor(qty, main).discount;
    const leather = round4(num(item.patchExcl) * (1 - discount));
    const appUnit = round4(num(app[String(item.zone || 'cap')]) * (1 - discount));
    unitExcl = round4(blank + leather + appUnit);
    part('blank', lbl(item.blankLabel, l) || L.blank, blank);
    part('leather', L.leather, leather);
    part('application', L.application, appUnit);
  } else if (type === 'laser') {
    const product = num(input.productExcl);
    if (!(product > 0)) errors.push('no_price');
    let extra = 0;
    const chosen = Array.isArray(input.options) ? input.options : [];
    for (const o of Array.isArray(item.options) ? item.options : []) {
      if (isObj(o) && chosen.includes(o.id)) extra += num(o.priceExcl);
    }
    discount = tierFor(qty, main).discount;
    unitExcl = round4((product + extra) * (1 - discount));
    part('product', lbl(item.label, l), unitExcl);
  } else if (type === 'pvc') {
    const product = num(input.productExcl);
    if (!(product > 0)) errors.push('no_price');
    const min = Math.max(1, int(item.minQty) || 1);
    const step = Math.max(1, int(item.qtyStep) || 1);
    if (qty > 0 && qty < min) errors.push('below_min_qty');
    else if (qty > 0 && qty % step !== 0) errors.push('qty_step');
    const tech = isObj(main.techniques) ? main.techniques.pvc : null;
    discount = !tech || tech.tiers ? tierFor(qty, main).discount : 0;
    unitExcl = round4(product * (1 - discount));
    part('product', lbl(item.label, l), unitExcl);
    const mold = num(item.moldFeeExcl);
    if (mold > 0 && !input.moldCovered && !input.moldCharged) {
      fees.push({ key: 'fee:pvc_keychain_mold', label: lbl(item.moldLabel, l) || L.mold, excl: mold, incl: round2(mold * (1 + vat)) });
    }
  } else {
    errors.push('type');
  }

  const ok = errors.length === 0;
  if (!ok) unitExcl = 0;
  const unitIncl = round2(unitExcl * (1 + vat));
  const lineExcl = round2(unitExcl * qty);
  const lineTax = round2(lineExcl * vat);
  return { ok, errors, qty, unitExcl, unitIncl, lineExcl, lineTax, lineIncl: round2(lineExcl + lineTax), tierDiscount: discount, parts: ok ? parts : [], fees: ok ? fees : [] };
}

/**
 * Patchprijs uit een ladder (paneel): [{qty, excl}] oplopend; hoogste qty ≤ gevraagd aantal.
 * @returns {(technique:string, sizeCm:number, options:string[], qty:number) => number}
 */
export function ladderPatchUnit(ladder) {
  const rows = (Array.isArray(ladder) ? ladder : []).filter(isObj).slice().sort((a, b) => num(a.qty) - num(b.qty));
  return (_t, _s, _o, qty) => {
    let v = 0;
    for (const r of rows) if (num(r.qty) <= qty) v = num(r.excl);
    return v;
  };
}

/**
 * Aantallen waarop de patchprijs van staffel wisselt (met de pool erbij): de server rekent de patch op die punten voor de ladder.
 * @returns {number[]}
 */
export function ladderPoints(main, poolQty, pooled) {
  const pts = new Set([1]);
  const steps = main && isObj(main.tiers) && Array.isArray(main.tiers.steps) ? main.tiers.steps : [];
  for (const s of steps) {
    if (!isObj(s)) continue;
    const q = int(s.min) - (pooled ? Math.max(0, int(poolQty)) : 0);
    if (q > 1) pts.add(q);
  }
  return [...pts].sort((a, b) => a - b);
}
