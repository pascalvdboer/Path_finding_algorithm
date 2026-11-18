/**
 * Cross-sell — kaarten opbouwen (JS-tweeling van includes/crosssell/class-offers.php).
 * In WordPress bouwt de server de kaarten (GET /crosssell/offers); deze module doet hetzelfde offline voor de dev-pagina
 * en levert `cardPrice()` waarmee het paneel de prijs live herberekent bij een ander aantal, een andere kleur of optie.
 */
import { designFromPayload, fitItem, defaultQty, pickDefaultVariation } from './fit.js';
import { pick } from './rules.js';
import { priceItem, ladderPatchUnit, ladderPoints, patchOptions } from './pricing.js';
import { t, langOf } from './messages.js';
import { formatMoney, round2 } from '../shared/money.js';

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const num = (v, d = 0) => (typeof v === 'number' && Number.isFinite(v) ? v : d);
const lbl = (o, l) => (isObj(o) ? String(o[l] || o.nl || '') : typeof o === 'string' ? o : '');

/** Product koopbaar? Variabel: minstens één koopbare variatie met voorraad. */
export function productAvailable(p) {
  if (!isObj(p) || p.purchasable === false) return false;
  if (Array.isArray(p.variations) && p.variations.length) return p.variations.some((v) => isObj(v) && v.inStock !== false && v.purchasable !== false);
  return num(p.priceExcl) > 0;
}

/** Product-ID's die een item gebruikt (frame: de maat die de pastoets koos). */
export function itemProductIds(item, fit) {
  if (item.type === 'frame') {
    const s = (item.sizes || []).find((x) => isObj(x) && String(x.id) === String(fit && fit.frameSize));
    return s && num(s.productId) > 0 ? [num(s.productId)] : [];
  }
  const ids = (Array.isArray(item.wcProductIds) ? item.wcProductIds : []).map(Number).filter((x) => x > 0);
  if (!ids.length && num(item.defaultProductId) > 0) ids.push(num(item.defaultProductId));
  return ids;
}

/**
 * Koppel product-ID's aan items (zoals "Maak ontbrekende producten aan" in het beheer doet). Geeft een nieuwe config.
 * @param {Object} cfg @param {{[item:string]: number[] | {[sizeId:string]: number}}} links
 */
export function linkProducts(cfg, links) {
  const out = Object.assign({}, cfg, { items: (cfg.items || []).map((it) => Object.assign({}, it)) });
  for (const it of out.items) {
    const l = links && links[it.id];
    if (!l) continue;
    if (it.type === 'frame' && isObj(l)) it.sizes = (it.sizes || []).map((s) => Object.assign({}, s, l[s.id] ? { productId: l[s.id] } : {}));
    else if (Array.isArray(l)) {
      it.wcProductIds = l.slice();
      it.defaultProductId = l[0] || 0;
    }
  }
  return out;
}

/** Pricing-context die het paneel nodig heeft om exact zoals de server te rekenen. */
export function pricingContext(cfg, main, technique) {
  const techs = {};
  for (const id of [technique, 'pvc']) {
    const tc = main.techniques && main.techniques[id];
    if (tc) techs[id] = { tiers: !!tc.tiers, minQty: tc.minQty || 1, mode: tc.mode };
  }
  return { vat: { rate: num(main.vat && main.vat.rate) }, tiers: main.tiers || { pooled: true, steps: [] }, techniques: techs, application: cfg.application || {} };
}

/** Getal zoals in de tekst: 6 → "6", 7.5 → "7,5". */
export const cmText = (n) => String(Math.round(num(n) * 100) / 100).replace('.', ',');

function adjustNote(item, fit, l, ov) {
  if (!fit || !fit.note) return null;
  if (fit.note === 'size') return t(item.id === 'beanie' ? 'cs.adjust.size.beanie' : 'cs.adjust.size.cap', l, { cm: cmText(fit.patchSizeCm) }, ov);
  if (fit.note === 'back') {
    const chest = num(item.fit && item.fit.chestMaxCm, 10);
    let s = t('cs.adjust.back', l, { chest: cmText(chest) }, ov);
    if (fit.alt) s += ' ' + t('cs.adjust.backAlt', l, { cm: cmText(fit.alt.patchSizeCm) }, ov);
    return s;
  }
  if (fit.note === 'simplify') return t('cs.adjust.simplify', l, {}, ov);
  if (fit.note === 'photo') return t('cs.leather.photo', l, {}, ov);
  if (fit.note === 'gradient') return t('cs.leather.gradient', l, {}, ov);
  return null;
}

function mockupKind(item, productId, placement) {
  if (item.id === 'hoodie' && placement === 'back') return item.mockupBack || 'hoodie_back';
  const map = isObj(item.mockupByProduct) ? item.mockupByProduct : {};
  return map[String(productId)] || item.mockup || 'cap_trucker';
}

/**
 * Prijs van een kaart voor een selectie (paneel). Zelfde formule als de server (priceItem + patchladder).
 * @param {Object} card @param {{variationId?:number, productId?:number, qty:number, options?:string[], placement?:string}} sel
 * @param {Object} pricing  pricingContext()
 */
export function cardPrice(card, sel, pricing, locale) {
  const p = card.price || {};
  const v = (card.variations || []).find((x) => x.id === sel.variationId);
  const prod = (card.products || []).find((x) => x.id === (sel.productId || (v && v.productId) || card.defaultProductId));
  const placement = sel.placement || card.placement;
  const useAlt = !!(card.alt && placement === card.alt.placement);
  const ladder = useAlt ? p.altPatchLadder || p.patchLadder : p.patchLadder;
  return priceItem(
    card.item,
    {
      qty: sel.qty,
      poolQty: p.pool || 0,
      blankExcl: v ? v.priceExcl : prod ? prod.priceExcl : 0,
      productExcl: prod ? prod.priceExcl : p.productExcl || 0,
      options: sel.options || [],
      placement,
      technique: p.technique,
      patchSizeCm: useAlt ? card.alt.patchSizeCm : card.patchSizeCm,
      designOptions: [],
      moldCovered: !!p.moldCovered,
      moldCharged: !!p.moldCharged,
    },
    { cfg: { application: pricing.application }, main: pricing, patchUnit: ladderPatchUnit(ladder), locale },
  );
}

/**
 * Bouw de offers offline (dev-pagina, tests).
 * @param {{payload:Object, previewUrl?:string, designId?:number, ref?:string}} design
 * @param {{cfg:Object, main:Object, catalog:Object<string,Object>, cart?:Object, patchUnit:Function, locale?:string, month?:number, stats?:Object, overrides?:Object}} env
 */
export function buildOffers(design, env) {
  const { cfg, main, catalog = {} } = env;
  const locale = env.locale || 'nl-BE';
  const l = langOf(locale);
  const ov = env.overrides || {};
  const cart = Object.assign({ qtyOfDesign: null, poolQty: 0, feeGroups: [], pvcMoldCovered: false, pvcMoldCharged: false }, env.cart || {});
  const d = designFromPayload(design.payload);
  const cartQty = cart.qtyOfDesign === null ? d.qty : cart.qtyOfDesign;
  const tech = (main.techniques && main.techniques[d.technique]) || null;
  const pooled = !!(main.tiers && main.tiers.pooled) && !!(tech && tech.tiers);
  const month = env.month || new Date().getMonth() + 1;

  const fits = {};
  const productsOf = {};
  for (const item of cfg.items || []) {
    if (!isObj(item) || item.enabled === false) continue;
    const f = fitItem(item, d, { tech, cartQty });
    if (f.fit === 'hide') continue;
    const ids = itemProductIds(item, f).filter((id) => productAvailable(catalog[id]));
    if (!ids.length) continue;
    fits[item.id] = f;
    productsOf[item.id] = ids;
  }
  const chosen = pick(fits, { qty: cartQty, technique: d.technique, kind: d.kind, month, mode: env.mode || 'patch' }, cfg, env.stats || {});
  const pricing = pricingContext(cfg, main, d.technique);
  const cards = [];
  for (const id of chosen.ids) {
    const item = cfg.items.find((i) => i.id === id);
    const card = buildCard(item, fits[id], productsOf[id], { d, cfg, main, catalog, cart, cartQty, pooled, pricing, locale, l, ov, patchUnit: env.patchUnit });
    if (card) cards.push(card);
  }
  const feeGroup = tech && tech.feeGroup ? String(tech.feeGroup) : '';
  const feeNote = main.fees && main.fees.scope === 'order' && feeGroup !== '' && (cart.feeGroups || []).includes(feeGroup) && cards.some((c) => c.item.type === 'patch_on_blank' || c.item.type === 'frame')
    ? t('cs.feeNote', l, {}, ov)
    : null;
  return { added: t('cs.added', l, {}, ov), heading: t('cs.heading', l, {}, ov), feeNote, previewUrl: design.previewUrl || '', designId: design.designId || 0, ref: design.ref || '', rule: chosen.rule, pricing,
    edgeHex: d.edgeHex, defaultColors: cfg.defaultColors || [], neutralHex: cfg.neutralHex || '#8a8d91', cards };
}

function buildCard(item, fit, productIds, x) {
  const { d, cfg, main, catalog, cart, cartQty, pooled, pricing, locale, l, ov } = x;
  const products = productIds.map((id) => ({ id, name: String(catalog[id].name || ''), priceExcl: num(catalog[id].priceExcl) }));
  const defaultProductId = productIds.includes(num(item.defaultProductId)) ? num(item.defaultProductId) : productIds[0];
  const colorMap = isObj(cfg.colorMap) ? cfg.colorMap : {};
  const variations = [];
  for (const pid of productIds) {
    for (const v of catalog[pid].variations || []) {
      if (!isObj(v) || v.purchasable === false) continue;
      const slug = String(v.colorSlug || '');
      variations.push({
        id: num(v.id), productId: pid, colorSlug: slug, colorName: String(v.colorName || slug),
        colorHex: colorMap[slug] || null, size: v.size ? String(v.size) : null, inStock: v.inStock !== false, priceExcl: num(v.priceExcl),
      });
    }
  }
  const ownVars = variations.filter((v) => v.productId === defaultProductId);
  const defaultVariationId = ownVars.length ? pickDefaultVariation(ownVars, d.edgeHex, cfg.defaultColors || []) : null;
  const qty = defaultQty(item, cartQty);
  const usesPatch = item.type === 'patch_on_blank' || item.type === 'frame';
  const pool = usesPatch && pooled ? Math.max(0, num(cart.poolQty)) : 0;
  const opts = patchOptions(d.options, main, (cfg.application && cfg.application.forceBacking) || 'sew_on');
  const ladderFor = (sizeCm) => (usesPatch ? ladderPoints(main, pool, pooled).map((q) => ({ qty: q, excl: num(x.patchUnit(d.technique, sizeCm, opts, q, pool)) })) : []);
  const patchLadder = ladderFor(fit.patchSizeCm);
  const altPatchLadder = fit.alt ? ladderFor(fit.alt.patchSizeCm) : null;
  const shared = item.type === 'pvc' && item.sharedMold === true && !!cart.pvcMoldCovered;
  const sizeCfg = item.type === 'frame' ? (item.sizes || []).find((s) => String(s.id) === String(fit.frameSize)) : null;
  const card = {
    id: item.id,
    kind: item.type,
    title: sizeCfg ? lbl(sizeCfg.label, l) : lbl(item.label, l),
    why: lbl(item.why, l),
    fit: fit.fit,
    adjustNote: adjustNote(item, fit, l, ov),
    patchSizeCm: fit.patchSizeCm,
    placement: fit.placement,
    alt: fit.alt,
    frameSize: fit.frameSize,
    minQty: item.type === 'pvc' ? num(item.minQty, 1) : 1,
    qtyStep: item.type === 'pvc' ? num(item.qtyStep, 1) : 1,
    defaultQty: qty,
    needsSize: !!item.requiresSize,
    needsPlate: item.type === 'frame',
    plate: item.type === 'frame' ? { lines: num(item.plate && item.plate.lines, 2), maxChars: num(item.plate && item.plate.maxChars, 28) } : null,
    options: [],
    products,
    variations,
    defaultProductId,
    defaultVariationId,
    mockup: {
      kind: mockupKind(item, defaultProductId, fit.placement),
      placement: fit.placement,
      scaleRefMm: num(sizeCfg ? sizeCfg.scaleRefMm : item.scaleRefMm, 230),
      mono: item.type === 'laser' || item.type === 'laser_patch_on_blank' || !!item.mono,
      engraveHex: cfg.engraveHex || '#5a3a22',
      plateHex: item.plate ? item.plate.hex : null,
      plateTextHex: item.plate ? item.plate.textHex : null,
      byProduct: isObj(item.mockupByProduct) ? item.mockupByProduct : {},
    },
    leadTime: item.leadTime ? lbl(item.leadTime, l) : null,
    note: null,
    item: {
      id: item.id, type: item.type, zone: item.zone, label: item.label, blankLabel: item.blankLabel, options: item.options || [],
      patchExcl: item.patchExcl, minQty: item.minQty, qtyStep: item.qtyStep, moldFeeExcl: item.moldFeeExcl, moldLabel: item.moldLabel,
    },
    price: { technique: d.technique, pool, patchLadder, altPatchLadder, productExcl: products[0] ? products[0].priceExcl : 0, moldCovered: shared, moldCharged: !!cart.pvcMoldCharged },
  };
  if (item.type === 'pvc') {
    const notes = [t('cs.pvc.min', l, { n: card.minQty }, ov)];
    if (card.leadTime) notes.push(t('cs.pvc.lead', l, { lead: card.leadTime }, ov));
    card.note = notes.join(' ');
  }
  const q = cardPrice(card, { variationId: defaultVariationId, productId: defaultProductId, qty, options: [] }, pricing, locale);
  if (!q.ok) return null;
  card.unitExcl = q.unitExcl;
  card.unitIncl = q.unitIncl;
  card.breakdown = q.parts.map((p) => ({ key: p.key, label: p.label, incl: p.incl }));
  card.fees = q.fees;
  if (item.type === 'pvc' && q.fees.length) card.note += ' ' + t('cs.pvc.mold', l, { price: formatMoney(q.fees[0].incl, locale) }, ov);
  if (item.type === 'pvc' && shared) card.note += ' ' + t('cs.pvc.moldShared', l, {}, ov);
  const vat = num(main.vat && main.vat.rate);
  card.options = (item.options || []).filter(isObj).map((o) => ({ id: o.id, label: lbl(o.label, l), incl: round2(num(o.priceExcl) * (1 + vat)) }));
  return card;
}
