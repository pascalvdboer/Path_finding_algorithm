/**
 * Naamstroken — prijs (SPEC-EXTRA §1.7). JS-tweeling voor de live weergave; de server (includes/nametape/class-pricing.php)
 * rekent de echte prijs. Getest met tests/nametape/price-vectors.json in node én PHP (exact gelijk).
 */
import { roundHalfUp } from '../shared/money.js';

const r2 = (x) => roundHalfUp(x, 2);
const r4 = (x) => roundHalfUp(x, 4);

const LABELS = {
  nl: {
    main: '{n} × naamstrook {format}',
    blood: '{n} × bloedgroepstrook {format}',
    shipping: 'Verzending (schatting)',
    shippingFree: 'Verzending: gratis',
    custom: '{w} × {h} cm',
  },
  fr: {
    main: '{n} × bande nominative {format}',
    blood: '{n} × bande groupe sanguin {format}',
    shipping: 'Livraison (estimation)',
    shippingFree: 'Livraison : gratuite',
    custom: '{w} × {h} cm',
  },
};

const lang = (locale) => (String(locale || 'nl').toLowerCase().startsWith('fr') ? 'fr' : 'nl');
const fill = (s, p) => s.replace(/\{(\w+)\}/g, (m, k) => (p[k] != null ? String(p[k]) : m));
const cm = (mm) => String(Math.round(mm) / 10).replace('.', ',');
const label = (obj, l) => (obj && typeof obj === 'object' ? obj[l] || obj.nl || '' : String(obj || ''));

/** Aantal per rij begrensd op 1..99 (zoals de server). */
export function rowQty(q) {
  const n = Math.floor(Number(q));
  return Number.isFinite(n) ? Math.min(99, Math.max(1, n)) : 1;
}

/** Hoogste staffelstap met min ≤ qty. */
export function tierFor(qty, tiers) {
  let best = { min: 1, discount: 0 };
  let found = false;
  for (const s of (tiers && tiers.steps) || []) {
    const min = Number(s.min), d = Number(s.discount);
    if (min <= qty && (!found || min >= best.min)) { best = { min, discount: d }; found = true; }
  }
  return best;
}

/** Prijsrij van het formaat (of de eigen maat). @returns {{priceExcl:number, label:string}|{error:string}} */
export function formatPrice(batch, cfg, l = 'nl') {
  if (batch.format === 'custom') {
    const cs = cfg.customSize || {};
    const c = batch.custom || {};
    const w = Number(c.w), h = Number(c.h);
    if (!cs.enabled || !(w >= cs.minW && w <= cs.maxW) || (cs.heights || []).indexOf(h) === -1) return { error: 'size_out_of_range' };
    const row = (cs.price || []).find((p) => w <= p.maxW && h <= p.maxH);
    if (!row) return { error: 'size_out_of_range' };
    return { priceExcl: Number(row.priceExcl), label: fill(LABELS[l].custom, { w: cm(w), h: cm(h) }) };
  }
  const f = (cfg.formats || []).find((x) => x.id === batch.format);
  if (!f) return { error: 'unknown_format' };
  return { priceExcl: Number(f.priceExcl), label: label(f.label, l) || f.id };
}

function emptyQuote(errors) {
  return {
    ok: false, errors, pieces: 0, mainPieces: 0, bloodPieces: 0, tier: { min: 1, discount: 0 },
    mainUnitExcl: 0, bloodUnitExcl: 0, unitIncl: 0, lineExcl: 0, lineTax: 0, lineIncl: 0,
    fees: [], feesIncl: 0, shippingIncl: 0, totalIncl: 0, perPieceAllInIncl: 0, nextTier: null, breakdown: [],
  };
}

/**
 * @param {object} batch  NametapeBatch (§1.6)
 * @param {{patchPoolQty?:number, otherNametapePieces?:number, otherItemsInclVat?:number, vatRate:number, tiers:object, shipping:object|null, locale?:string}} ctx
 * @param {object} cfg    gemergde nametape-config
 */
export function quote(batch, ctx, cfg) {
  const l = lang(ctx.locale || batch.locale);
  const L = LABELS[l];
  const vat = Number(ctx.vatRate) || 0;
  const errors = [];

  const fp = formatPrice(batch, cfg, l);
  if (fp.error) errors.push({ code: fp.error });
  const fabric = (cfg.fabrics || []).find((f) => f.id === batch.fabric);
  if (!fabric) errors.push({ code: 'unknown_fabric' });
  const backing = (cfg.backings || []).find((b) => b.id === batch.backing);
  if (!backing) errors.push({ code: 'unknown_backing' });
  if (errors.length) return emptyQuote(errors);

  const lc = cfg.loopCounterpart || {};
  const loopOn = !!batch.loop && !!lc.enabled && (lc.onlyWith || ['hook']).indexOf(batch.backing) !== -1;
  const deltas = Number(fabric.surchargeExcl || 0) + Number(backing.deltaExcl || 0) + (loopOn ? Number(lc.priceExcl || 0) : 0);

  const rows = Array.isArray(batch.rows) ? batch.rows : [];
  const mainUnit = fp.priceExcl + deltas;
  let mainPieces = 0, bloodPieces = 0;
  for (const r of rows) {
    const q = rowQty(r.qty);
    mainPieces += q;
    if (batch.bloodMode === 'separate' && r.blood && r.blood.group) bloodPieces += q;
  }
  const bf = (cfg.formats || []).find((x) => x.id === ((cfg.blood && cfg.blood.separateFormat) || '5x2.5'));
  const bloodUnit = (bf ? Number(bf.priceExcl) : 0) + deltas;

  const pieces = mainPieces + bloodPieces;
  const other = Math.max(0, Math.floor(Number(ctx.otherNametapePieces) || 0));
  const pool = cfg.tiers && cfg.tiers.pooledWithPatches === false ? 0 : Math.max(0, Math.floor(Number(ctx.patchPoolQty) || 0));
  const tierQty = pieces + other + pool;
  const tier = tierFor(tierQty, ctx.tiers);
  const d = tier.discount;

  const mainUnitExcl = r4(mainUnit * (1 - d));
  const bloodUnitExcl = r4(bloodUnit * (1 - d));
  const mainLineExcl = r2(mainUnitExcl * mainPieces);
  const bloodLineExcl = r2(bloodUnitExcl * bloodPieces);
  const lineExcl = r2(mainLineExcl + bloodLineExcl);
  const lineTax = r2(lineExcl * vat);
  const lineIncl = r2(lineExcl + lineTax);
  const unitIncl = r2(mainUnitExcl * (1 + vat));

  const fees = [];
  const sf = cfg.smallOrderFee || {};
  if (sf.enabled && pieces > 0 && pieces + other < Number(sf.belowQty)) {
    const excl = r2(Number(sf.amountExcl) || 0);
    const tax = r2(excl * vat);
    fees.push({ key: 'fee:nametape_setup', label: label(sf.label, l), excl, tax, incl: r2(excl + tax) });
  }
  let feesIncl = 0;
  for (const f of fees) feesIncl += f.incl;
  feesIncl = r2(feesIncl);

  let shippingIncl = 0, shippingFree = false;
  const ship = ctx.shipping;
  if (ship && pieces > 0) {
    const articles = r2(lineIncl + (Number(ctx.otherItemsInclVat) || 0));
    const freeFrom = ship.freeFromInclVat;
    if (freeFrom != null && Number.isFinite(Number(freeFrom)) && articles >= Number(freeFrom)) {
      shippingFree = true;
    } else {
      const flat = r2(Math.max(0, Number(ship.flat) || 0));
      shippingIncl = r2(flat + r2(flat * vat));
    }
  }
  const totalIncl = r2(lineIncl + feesIncl + shippingIncl);

  let nextTier = null;
  const steps = ((ctx.tiers && ctx.tiers.steps) || []).map((s) => ({ min: Number(s.min), discount: Number(s.discount) })).sort((a, b) => a.min - b.min);
  for (const s of steps) {
    if (s.min > tierQty && pieces > 0) {
      nextTier = { min: s.min, needed: s.min - tierQty, discount: s.discount, unitIncl: r2(r4(mainUnit * (1 - s.discount)) * (1 + vat)) };
      break;
    }
  }

  const breakdown = [];
  if (mainPieces) breakdown.push({ key: 'main', label: fill(L.main, { n: mainPieces, format: fp.label }), qty: mainPieces, unitIncl, incl: r2(mainLineExcl * (1 + vat)) });
  if (bloodPieces) breakdown.push({ key: 'blood', label: fill(L.blood, { n: bloodPieces, format: bf ? label(bf.label, l) : '' }), qty: bloodPieces, unitIncl: r2(bloodUnitExcl * (1 + vat)), incl: r2(bloodLineExcl * (1 + vat)) });
  for (const f of fees) breakdown.push({ key: f.key, label: f.label, incl: f.incl });
  if (ship && pieces > 0) breakdown.push({ key: 'shipping', label: shippingFree ? L.shippingFree : L.shipping, incl: shippingIncl });

  return {
    ok: pieces > 0,
    errors: pieces > 0 ? [] : [{ code: 'no_rows' }],
    pieces, mainPieces, bloodPieces, tier, mainUnitExcl, bloodUnitExcl, unitIncl, lineExcl, lineTax, lineIncl,
    fees, feesIncl, shippingIncl, totalIncl,
    perPieceAllInIncl: pieces > 0 ? r2(totalIncl / pieces) : 0,
    nextTier, breakdown,
  };
}
