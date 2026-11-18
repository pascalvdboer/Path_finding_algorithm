/**
 * Cross-sell — pastoetsen (SPEC-EXTRA §2.2). JS-tweeling van includes/crosssell/class-fit.php.
 * Puur, zonder DOM. Zelfde volgorde van bewerkingen als PHP; tests/crosssell/fit-vectors.json bewaakt dat.
 *
 * De server is de bron (D7): deze module dient voor de dev-pagina, de tests en de live prijs in het paneel.
 */
import { hexToLab, deltaE2000 } from '../shared/color-math.js';

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const num = (v, d = 0) => {
  const n = typeof v === 'number' ? v : typeof v === 'string' && v.trim() !== '' ? Number(v) : NaN;
  return Number.isFinite(n) ? n : d;
};
const posOrNull = (v) => {
  const n = num(v, NaN);
  return Number.isFinite(n) && n > 0 ? n : null;
};
const int = (v) => {
  const n = num(v, 0);
  return n > 0 ? Math.floor(n) : 0;
};
const HEX = /^#?[0-9a-f]{6}$/i;
const normHex = (h) => (typeof h === 'string' && HEX.test(h.trim()) ? '#' + h.trim().replace('#', '').toLowerCase() : null);

/**
 * DesignPayload (SPEC §6) → compacte ontwerpinvoer voor de pastoetsen.
 * @param {Object} p DesignPayload
 */
export function designFromPayload(p) {
  p = isObj(p) ? p : {};
  const a = isObj(p.analysis) ? p.analysis : {};
  const sizeCm = num(p.sizeCm);
  let w = num(p.widthMm);
  let h = num(p.heightMm);
  if (!(w > 0) || !(h > 0)) {
    w = sizeCm * 10;
    h = sizeCm * 10;
  }
  let minText = posOrNull(a.minTextMm);
  for (const t of Array.isArray(p.texts) ? p.texts : []) {
    const th = isObj(t) ? posOrNull(t.heightMm) : null;
    if (th !== null && (minText === null || th < minText)) minText = th;
  }
  const threads = [];
  for (const t of Array.isArray(p.threads) ? p.threads : []) {
    const hex = isObj(t) ? normHex(t.hex) : null;
    if (hex) threads.push({ hex, share: Math.max(0, Math.min(1, num(t.share))) });
  }
  const fabricHex = isObj(p.fabric) ? normHex(p.fabric.hex) : null;
  const edgeHex = (isObj(p.edgeColor) ? normHex(p.edgeColor.hex) : null) || fabricHex;
  const kind = typeof a.kind === 'string' && a.kind !== '' ? a.kind : 'flat';
  return {
    technique: typeof p.technique === 'string' ? p.technique : '',
    sizeCm,
    widthMm: w,
    heightMm: h,
    shape: typeof p.shape === 'string' ? p.shape : '',
    qty: int(p.qty),
    kind,
    colors: int(a.colors) || threads.length,
    fineLineMm: posOrNull(a.fineLineMm),
    minTextMm: minText,
    threads,
    fabricHex,
    edgeHex,
    options: Array.isArray(p.options) ? p.options.filter((o) => typeof o === 'string') : [],
  };
}

/** Standaardaantal van een kaart (§2.4): team ≥ 10 patches → hetzelfde aantal; anders 1; PVC: minQty (in stappen). */
export function defaultQty(item, cartQty) {
  const q = int(cartQty);
  if (item && item.type === 'pvc') {
    const min = Math.max(1, int(item.minQty) || 1);
    const step = Math.max(1, int(item.qtyStep) || 1);
    return Math.ceil(Math.max(q, min) / step) * step;
  }
  return q >= 10 ? q : 1;
}

/** Langste zijde (mm) en verhouding (≥ 1) van het ontwerp. */
function dims(d) {
  const w = num(d.widthMm);
  const h = num(d.heightMm);
  const longest = Math.max(w, h);
  const shortest = Math.min(w, h);
  return { w, h, longest, aspect: shortest > 0 ? longest / shortest : 1, landscape: w >= h };
}

/** Hard limits of the design's technique (main config `techniques[t].rules`). */
function limits(tech) {
  const r = isObj(tech) && isObj(tech.rules) ? tech.rules : {};
  return {
    text: isObj(r.textCapsMm) ? num(r.textCapsMm.hard) : 0,
    line: isObj(r.lineMm) ? num(r.lineMm.hard) : 0,
  };
}

/** Na schalen met `factor` nog borduurbaar/maakbaar? Onbekende waarden (null) tellen als ok. */
function scaleOk(d, factor, tech) {
  const l = limits(tech);
  if (d.minTextMm !== null && d.minTextMm !== undefined && d.minTextMm * factor < l.text) return false;
  if (d.fineLineMm !== null && d.fineLineMm !== undefined && d.fineLineMm * factor < l.line) return false;
  return true;
}

const res = (fit, reason, extra = {}) => Object.assign({ fit, reason, patchSizeCm: null, placement: null, note: null, frameSize: null, alt: null }, extra);

/** Grootste lichtheidsverschil (ΔL*) tussen de hoofdkleuren: stof + garens met share ≥ minShare. null = te weinig gegevens. */
export function maxDeltaL(d, minShare) {
  const hexes = [];
  if (d.fabricHex) hexes.push(d.fabricHex);
  for (const t of d.threads || []) {
    if (t.share >= minShare && !hexes.includes(t.hex)) hexes.push(t.hex);
  }
  if (hexes.length < 2) return null;
  const Ls = hexes.map((h) => hexToLab(h)[0]);
  let best = 0;
  for (let i = 0; i < Ls.length; i++) {
    for (let j = i + 1; j < Ls.length; j++) {
      const dl = Math.abs(Ls[i] - Ls[j]);
      if (dl > best) best = dl;
    }
  }
  return best;
}

function laserFit(item, d) {
  const f = isObj(item.fit) ? item.fit : {};
  if (d.kind === 'photo' || d.kind === 'gradient') return res('adjust', d.kind, { note: d.kind });
  const dl = maxDeltaL(d, num(f.minShare, 0.1));
  if (dl !== null && dl < num(f.minDeltaL, 15)) return res('hide', 'contrast');
  const { longest } = dims(d);
  const factor = longest > 0 ? num(f.sizeMm, 60) / longest : 1;
  if (d.fineLineMm !== null && d.fineLineMm * factor < num(f.minLineMm, 0.5)) return res('hide', 'too_fine');
  if (d.minTextMm !== null && d.minTextMm * factor < num(f.minTextMm, 2.5)) return res('hide', 'too_fine');
  return res('ok', 'ok');
}

/**
 * Pastoets voor één item.
 * @param {Object} item  item uit crosssell.json
 * @param {Object} d     designFromPayload(...)
 * @param {Object} ctx   { tech: techniques[d.technique] uit de hoofdconfig, cartQty: patches van dit ontwerp in de mand }
 * @returns {{fit:'ok'|'adjust'|'hide', reason:string, patchSizeCm:number|null, placement:string|null, note:string|null, frameSize:string|null, alt:Object|null}}
 */
export function fitItem(item, d, ctx = {}) {
  if (!isObj(item) || !isObj(d)) return res('hide', 'invalid');
  const tech = isObj(ctx.tech) ? ctx.tech : null;
  const cartQty = ctx.cartQty === undefined ? int(d.qty) : int(ctx.cartQty);
  const f = isObj(item.fit) ? item.fit : {};
  const { w, h, longest, aspect, landscape } = dims(d);
  const usesPatch = item.type === 'patch_on_blank' || item.type === 'frame';

  if (usesPatch) {
    if (!tech || tech.enabled === false || tech.mode === 'quote') return res('hide', 'technique');
    const tMin = Math.max(1, int(tech.minQty) || 1);
    if (tMin > 1 && cartQty + defaultQty(item, cartQty) < tMin) return res('hide', 'min_qty');
    if (!(longest > 0) || !(d.sizeCm > 0)) return res('hide', 'invalid');
  }

  switch (item.id) {
    case 'cap': {
      const maxH = num(f.maxPatchHeightMm, 65);
      const maxW = num(f.maxPatchWidthMm, 110);
      if (d.sizeCm > num(f.maxSizeCm, 12)) return res('hide', 'too_large');
      if (h <= maxH && w <= maxW) return res('ok', 'ok', { patchSizeCm: d.sizeCm, placement: item.placement || 'front' });
      const target = landscape && aspect > num(f.wideAspect, 1.6) ? num(f.wideSizeCm, 10) : num(f.suggestSizeCm, 6);
      if (target >= d.sizeCm || target < num(tech.minCm, 0)) return res('hide', 'too_large');
      const factor = (target * 10) / longest;
      if (h * factor > maxH || w * factor > maxW) return res('hide', 'too_large');
      if (!scaleOk(d, factor, tech)) return res('hide', 'too_fine');
      return res('adjust', 'size', { patchSizeCm: target, placement: item.placement || 'front', note: 'size' });
    }
    case 'beanie': {
      const maxH = num(f.maxPatchHeightMm, 55);
      if (d.sizeCm > num(f.maxSizeCm, 12)) return res('hide', 'too_large');
      let size = null;
      let factor = 1;
      let fit = 'ok';
      if (h <= maxH) {
        size = d.sizeCm;
      } else {
        for (const s of Array.isArray(f.sizesCm) ? f.sizesCm : [6, 4]) {
          const sc = num(s);
          if (!(sc > 0) || sc >= d.sizeCm || sc < num(tech.minCm, 0)) continue;
          const fc = (sc * 10) / longest;
          if (h * fc <= maxH && scaleOk(d, fc, tech)) {
            size = sc;
            factor = fc;
            fit = 'adjust';
            break;
          }
        }
      }
      if (size === null) return res('hide', 'too_large');
      if (aspect > num(f.maxAspect, 2.2) && d.minTextMm !== null && d.minTextMm * factor < num(f.minTextMmWide, 5)) return res('hide', 'wide_small_text');
      return res(fit, fit === 'ok' ? 'ok' : 'size', { patchSizeCm: size, placement: item.placement || 'cuff', note: fit === 'ok' ? null : 'size' });
    }
    case 'hoodie': {
      const chest = num(f.chestMaxCm, 10);
      const back = num(f.backMaxCm, 12);
      if (d.sizeCm <= chest) return res('ok', 'ok', { patchSizeCm: d.sizeCm, placement: 'chest' });
      if (d.sizeCm > back) return res('hide', 'too_large');
      const fc = chest / d.sizeCm;
      const alt = scaleOk(d, fc, tech) ? { placement: 'chest', patchSizeCm: chest } : null;
      return res('adjust', 'back', { patchSizeCm: d.sizeCm, placement: 'back', note: 'back', alt });
    }
    case 'pvc_keychain': {
      if (!(cartQty >= num(item.showFromQty, 10) || d.technique === 'pvc')) return res('hide', 'qty');
      const classes = Array.isArray(f.classes) ? f.classes : ['flat', 'illustration'];
      if (!classes.includes(d.kind)) return res('hide', 'kind');
      if (d.colors > num(f.hardMaxColors, 8)) return res('hide', 'colors');
      const factor = longest > 0 ? num(f.sizeMm, 50) / longest : 1;
      const line = d.fineLineMm === null ? null : d.fineLineMm * factor;
      const text = d.minTextMm === null ? null : d.minTextMm * factor;
      if (line !== null && line < num(f.hardMinLineMm, 0.6)) return res('hide', 'too_fine');
      if (text !== null && text < num(f.hardMinTextMm, 3)) return res('hide', 'too_fine');
      const simplify = d.colors > num(f.maxColors, 6) || (line !== null && line < num(f.minLineMm, 1)) || (text !== null && text < num(f.minTextMm, 4));
      return simplify ? res('adjust', 'simplify', { note: 'simplify' }) : res('ok', 'ok');
    }
    case 'leather_keychain':
    case 'leather_bagtag':
      return laserFit(item, d);
    case 'leather_patch_cap': {
      const r = laserFit(item, d);
      if (r.fit !== 'hide') {
        r.patchSizeCm = num(item.patchSizeCm, 7);
        r.placement = item.placement || 'front';
      }
      return r;
    }
    case 'frame': {
      const sizes = (Array.isArray(item.sizes) ? item.sizes.slice() : []).filter(isObj).sort((a, b) => num(a.maxPatchCm) - num(b.maxPatchCm));
      for (const s of sizes) {
        if (d.sizeCm <= num(s.maxPatchCm)) return res('ok', 'ok', { patchSizeCm: d.sizeCm, frameSize: String(s.id) });
      }
      return res('hide', 'too_large');
    }
    default:
      return res('hide', 'unknown_item');
  }
}

/**
 * Standaardvariatie (§2.5): met voorraad, beperkt tot `defaultColors` (als er zo een is), hoogste ΔE2000 met de randkleur.
 * @param {{id:number, colorSlug:string, colorHex:string, inStock:boolean}[]} variations
 * @param {string|null} edgeHex
 * @param {string[]} defaultColors
 * @returns {number|null}
 */
export function pickDefaultVariation(variations, edgeHex, defaultColors) {
  const inStock = (Array.isArray(variations) ? variations : []).filter((v) => isObj(v) && v.inStock !== false);
  if (!inStock.length) return null;
  const pref = inStock.filter((v) => (defaultColors || []).includes(String(v.colorSlug || '')));
  const pool = pref.length ? pref : inStock;
  const edge = normHex(edgeHex);
  if (!edge) return pool[0].id;
  const edgeLab = hexToLab(edge);
  let best = pool[0];
  let bestD = -1;
  for (const v of pool) {
    const hx = normHex(v.colorHex);
    if (!hx) continue;
    const dE = deltaE2000(hexToLab(hx), edgeLab);
    if (dE > bestD + 1e-9) {
      bestD = dE;
      best = v;
    }
  }
  return best.id;
}
