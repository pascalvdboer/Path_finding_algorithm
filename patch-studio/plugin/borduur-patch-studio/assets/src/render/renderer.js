/**
 * Patch Studio — canvas renderer v2 (SPEC §7.6, STUDIO-REDESIGN "Rendering"). Composes a lit patch close-up:
 *   scene → drop/contact shadow → lit patch body (twill, stitched ground, felt, grain, ...) → lit design layer
 *   (material geometry: satin/fill/weave/pile/PVC/engraving, relief shadow) → lit text layers → lit border
 *   (merrow tube, satin wrap, hot-cut bead, PVC rim, felt) → guides.
 * Every lit layer is cached per light direction (light.js); geometry is cached independently of the light.
 * Layout always comes from shared/geometry.js#layoutPatch() (opts.layout); the renderer never changes sizes.
 */
import { shapePolygon, transformPolygon, tracePath, bbox, offsetPolygon, contourFromMask, convexHull, traceBoundary } from './shapes.js';
import { analyzeComponents, chooseModes, colorTable, cleanLabels } from './stylize.js';
import { edgeCoverMm, edgeBandMm, edgeGeo, shadeEdge, edgeDecor } from './edges.js';
import { normLight, shadeGeo, halfGeo } from './light.js';
import { buildLayer, baseTile, shadeBase } from './material.js';
import { bandField, absorbSmall } from './field.js';
import { drawBackdrop } from './scene.js';
import { drawTextLayer, fontFor, fontReady, loadFont } from './text.js';
import { drawGarment, placePatch } from './garment.js';
import { makeCanvas, ctx2d, Lru, WLru, idOf, now, shade, rgbOf, lum, clamp, softShadow } from './util.js';

const LABEL_LOOKS = { embroidered: 1, woven: 1, chenille: 1, pvc: 1 };
const artCache = new Lru(6);
const artLatest = new Map(); // prefix → last key (fast mode reuse)
const textCache = new Lru(24);
const contourCache = new Lru(8);
const compCache = new WeakMap();
const cleanCache = new WeakMap();
const recolorCache = new Lru(6);
const bundles = new WeakMap(); // art image → full bundle (labels, palette, backdrop)
let defaultConfig = null;

export function setDefaultConfig(config) { if (config) defaultConfig = config; }
export function getDefaultConfig() { return defaultConfig; }
export function registerBundle(image, bundle) { if (image) bundles.set(image, bundle); }

/* ------------------------------------------------------------------ */

function lookOf(technique, config) {
  const t = config && config.techniques && config.techniques[technique];
  return (t && t.render) || technique || 'embroidered';
}

function engraveFor(fabricHex, config) {
  const l = ((config && config.leathers) || []).find((x) => String(x.hex).toLowerCase() === String(fabricHex).toLowerCase());
  return l && l.engrave ? l.engrave : shade(fabricHex, -0.55);
}

function textsKey(texts) {
  return (texts || []).map((t) => [t.id, t.text, t.font, t.heightMm, t.path, t.offsetMm && t.offsetMm.x, t.offsetMm && t.offsetMm.y, t.angleDeg, t.insetMm, t.letterSpacing].join('~')).join('|');
}

const backdropCanvases = new WeakMap();
/** Accept analysis.backdrop as raw pixels ({width,height,data,artBox}) or as {image, artBox}. */
function normBackdrop(bd) {
  if (!bd || !bd.artBox) return null;
  if (bd.image) return bd;
  if (!bd.data || !bd.width) return null;
  let image = backdropCanvases.get(bd.data);
  if (!image) {
    image = makeCanvas(bd.width, bd.height);
    const c = ctx2d(image), id = c.createImageData(bd.width, bd.height);
    id.data.set(bd.data);
    c.putImageData(id, 0, 0);
    backdropCanvases.set(bd.data, image);
  }
  return { image, artBox: bd.artBox };
}

function resolveArt(art) {
  if (!art || !art.image) return null;
  const b = bundles.get(art.image) || {};
  return Object.assign({}, b, art, {
    labels: art.labels || b.labels || null,
    paletteHex: art.paletteHex && art.paletteHex.length ? art.paletteHex : b.paletteHex || [],
    backdrop: normBackdrop(art.backdrop) || b.backdrop || null,
  });
}

/** Patch outline in mm (patch box origin), clockwise. */
export function patchPolygonMm(layout, art, design, config) {
  const W = layout.patchWmm, H = layout.patchHmm;
  if (layout.shape === 'contour') {
    const p = contourPolygonMm(layout, art, design, config);
    if (p) return p;
  }
  const cr = config && config.shapes && config.shapes.rounded && config.shapes.rounded.cornerRatio;
  return shapePolygon(layout.shape, W, H, { cornerRatio: cr != null ? cr : 0.18 });
}

function drawArtImage(c, art, rect, rotation, dst) {
  const img = dst || art.image;
  const rot = ((Number(rotation) || 0) % 360) * Math.PI / 180;
  if (!rot) { c.drawImage(img, rect.x, rect.y, rect.w, rect.h); return; }
  c.save();
  c.translate(rect.x + rect.w / 2, rect.y + rect.h / 2);
  c.rotate(rot);
  c.drawImage(img, -rect.w / 2, -rect.h / 2, rect.w, rect.h);
  c.restore();
}

/** Contour outline around the design (+ text), offset by shapes.contour.offsetMm. */
function contourPolygonMm(layout, art, design, config) {
  const texts = (design && design.texts || []).filter((t) => t && String(t.text || '').trim());
  if (!(art && art.image && layout.artRectMm) && !texts.length) return null;
  const off = Number(config && config.shapes && config.shapes.contour && config.shapes.contour.offsetMm) || 3;
  const r = layout.artRectMm || { x: 0, y: 0, w: 0, h: 0 };
  const key = [idOf(art && art.image), r.x.toFixed(2), r.y.toFixed(2), r.w.toFixed(2), r.h.toFixed(2), layout.patchWmm.toFixed(2), layout.patchHmm.toFixed(2), off, design && design.art && design.art.rotation, textsKey(texts)].join('/');
  const hit = contourCache.get(key);
  if (hit !== undefined) return hit;
  const pad = off * 2.5 + 3;
  const ext = Math.max(layout.patchWmm, layout.patchHmm) + 2 * pad;
  const g = 240 / ext;
  const gw = Math.ceil((layout.patchWmm + 2 * pad) * g), gh = Math.ceil((layout.patchHmm + 2 * pad) * g);
  const cv = makeCanvas(gw, gh), c = ctx2d(cv, { willReadFrequently: true });
  c.setTransform(g, 0, 0, g, pad * g, pad * g);
  if (art && art.image && layout.artRectMm) drawArtImage(c, art, r, design && design.art && design.art.rotation);
  if (texts.length) {
    const box = shapePolygon('rounded', layout.patchWmm, layout.patchHmm, { cornerRatio: 0.5 });
    for (const t of texts) {
      drawTextLayer(c, t, { poly: box, shape: layout.shape, cx: layout.patchWmm / 2, cy: layout.patchHmm / 2, ppm: 1, edgeMm: 0, config, hex: '#000' });
    }
  }
  const d = c.getImageData(0, 0, gw, gh).data, mask = new Uint8Array(gw * gh);
  let any = 0;
  for (let i = 0; i < gw * gh; i++) if (d[i * 4 + 3] > 60) { mask[i] = 1; any++; }
  let poly = null;
  if (any > 4) {
    for (const f of [1, 1.6, 2.4]) {
      poly = contourFromMask(mask, gw, gh, off * f * g, 1.5 * g);
      if (poly) break;
    }
    if (!poly) {
      const pts = traceBoundary(mask, gw, gh);
      const all = [];
      for (let i = 0; i < gw * gh; i += 2) if (mask[i]) all.push([i % gw, (i / gw) | 0]);
      const hull = convexHull(all.length ? all : pts);
      poly = hull.length > 2 ? offsetPolygon(hull, -off * g) : null;
    }
  }
  const out = poly ? poly.map((q) => [q[0] / g - pad, q[1] / g - pad]) : null;
  contourCache.set(key, out);
  return out;
}

/* ------------------------------ design layer ------------------------------ */

/** Speckle-free labels for thread looks (cached per analysis labels). */
function cleanFor(labels, passes = 2) {
  let m = cleanCache.get(labels.data);
  if (!m) { m = {}; cleanCache.set(labels.data, m); }
  if (!m[passes]) m[passes] = passes > 2 ? cleanLabels(cleanFor(labels, 2), passes - 2) : cleanLabels(labels, passes);
  return m[passes];
}

const islandCache = new WeakMap();
/** Labels without colour islands smaller than `minCells` (cached per clean labels). */
function islandsFor(labels, minCells) {
  const n = Math.max(1, Math.round(minCells));
  let m = islandCache.get(labels.data);
  if (!m) { m = {}; islandCache.set(labels.data, m); }
  if (!m[n]) m[n] = absorbSmall(labels, n);
  return m[n];
}

function componentsFor(labels) {
  let c = compCache.get(labels.data);
  if (!c) { c = analyzeComponents(labels); compCache.set(labels.data, c); }
  return c;
}

/**
 * Design recoloured with thread colours at label resolution (alpha from the source, so edges stay
 * antialiased). Upscaling this with smoothing gives clean colour borders instead of label stair-steps.
 */
function recolored(art, ck, clean) {
  const key = idOf(art.image) + '#' + ck + '#' + idOf(clean.data);
  const hit = recolorCache.get(key);
  if (hit) return hit;
  const w = clean.width, h = clean.height;
  const cv = makeCanvas(w, h), c = ctx2d(cv, { willReadFrequently: true });
  c.drawImage(art.image, 0, 0, w, h);
  const im = c.getImageData(0, 0, w, h), d = im.data, L = clean.data;
  const tab = buildColors(art, ck);
  for (let i = 0, n = w * h; i < n; i++) {
    const l = L[i];
    if (l === 255 || !tab) continue;
    d[i * 4] = tab[l * 3]; d[i * 4 + 1] = tab[l * 3 + 1]; d[i * 4 + 2] = tab[l * 3 + 2];
    if (d[i * 4 + 3] < 255 && d[i * 4 + 3] > 0) d[i * 4 + 3] = Math.min(255, d[i * 4 + 3] * 1.15);
  }
  c.putImageData(im, 0, 0);
  return recolorCache.set(key, cv);
}

function colorKey(art, look, reduced) {
  if (!LABEL_LOOKS[look] || !art.labels) return 'src';
  if (reduced && art.reducedMap && art.reducedHex) return 'r' + art.reducedMap.join(',') + ':' + art.reducedHex.join(',');
  return 'p' + (art.paletteHex || []).join(',');
}

function buildColors(art, key) {
  if (key === 'src') return null;
  const n = Math.max(art.paletteHex ? art.paletteHex.length : 0, art.reducedMap ? art.reducedMap.length : 0);
  const hexes = [];
  for (let i = 0; i < n; i++) {
    if (key[0] === 'r') hexes.push(art.reducedHex[art.reducedMap[i]] || (art.paletteHex && art.paletteHex[i]));
    else hexes.push(art.paletteHex[i]);
  }
  return colorTable(hexes);
}

/* ------------------------------ v2 material layers ------------------------------ */

// patch body thickness in mm (drop shadow length) and rounded-edge roll per look
const BODY = {
  embroidered: { thick: 1.6, roll: 0.5, shadowA: 0.5 },
  woven: { thick: 0.7, roll: 0.25, shadowA: 0.25 },
  printed: { thick: 1.1, roll: 0.4, shadowA: 0.3 },
  sublimated: { thick: 0.8, roll: 0.3, shadowA: 0.25 },
  pvc: { thick: 2.6, roll: 0.9, shadowA: 0.45 },
  chenille: { thick: 3.0, roll: 0.8, shadowA: 0.45 },
  leather: { thick: 2.2, roll: 0.9, shadowA: 0.3 },
};
// relief shadow cast by the design layer: [alpha, blur mm]
const RELIEF_SHADOW = { embroidered: [0.55, 0.35], woven: [0.25, 0.1], chenille: [0.6, 0.9], pvc: [0.5, 0.5] };

function reliefOf(design) {
  const ops = (design && design.options) || [];
  if (ops.includes('extra_thick')) return 1.7;
  if (ops.includes('thick')) return 1.35;
  return 1;
}

const geoCache = new WLru(5e6, 24);   // art geometry (light independent), bounded by pixels
const bandCache = new Lru(12);
const edgeGeoCache = new Lru(12);
const litCache = new WLru(1.2e7, 48); // lit art layers (per light), bounded by pixels

/** Lit canvas of a geometry buffer, with an optional light-dependent relief shadow baked in. */
function litCanvas(geo, light, shadow, ppm, extraPad = 0) {
  const w = geo.w, h = geo.h;
  const cv = makeCanvas(w, h), c = ctx2d(cv);
  const id = c.createImageData(w, h);
  shadeGeo(geo, light, id.data, { specTint: 0.38, ambient: geo.ambient });
  c.putImageData(id, 0, 0);
  if (!shadow) return { canvas: cv, pad: 0 };
  const [L0, L1, L2] = light.L;
  const hpx = Math.max(geo.maxH || 0, ppm * 0.15);
  const k = Math.min(3, 0.8 / Math.max(0.25, L2));
  const ox = -L0 * k * hpx, oy = -L1 * k * hpx;
  const blur = shadow[1] * ppm + hpx * 0.6;
  const pad = Math.ceil(Math.abs(ox) + Math.abs(oy) + blur * 1.5 + 2 + extraPad);
  const out = makeCanvas(w + 2 * pad, h + 2 * pad), oc = ctx2d(out);
  softShadow(oc, cv, pad, pad, ox, oy, blur, shadow[0]);
  oc.drawImage(cv, pad, pad);
  return { canvas: out, pad };
}

function artLayer(art, look, rect, ppm, o) {
  const wPx = rect.w * ppm, hPx = rect.h * ppm;
  const maxSide = 1700;
  // snap the layer size to 6 % steps: small size/scale changes reuse the cached layer (drawImage rescales)
  const longest = Math.min(maxSide, Math.max(wPx, hPx, 1));
  const snapped = Math.min(maxSide, Math.pow(1.06, Math.round(Math.log(longest) / Math.log(1.06))));
  const f = snapped / Math.max(wPx, hPx, 1);
  let lw = Math.max(1, Math.round(wPx * f)), lh = Math.max(1, Math.round(hPx * f));
  const ck = colorKey(art, look, o.reduced);
  const prefix = [idOf(art.image), look, ck, look === 'leather' ? o.engraveHex : '', o.relief, o.pvcDepth].join('#');
  let key = prefix + '#' + lw + 'x' + lh;
  const lkey = key + '#' + o.light.key;
  const hit = litCache.get(lkey);
  if (hit) { artLatest.set(prefix, lkey); return hit; }
  if (o.quality === 'fast' && !geoCache.get(key)) {
    // same geometry at this size: only the light moved → relight below (the glint follows the pointer);
    // otherwise reuse the last layer at another size while dragging / zooming
    const lk = artLatest.get(prefix);
    const prev = lk && litCache.get(lk);
    if (prev) return prev;
    lw = Math.max(1, lw >> 1); lh = Math.max(1, lh >> 1); key = prefix + '#' + lw + 'x' + lh;
  }
  let geo = geoCache.get(key);
  const layerPpm = ppm * (lw / Math.max(1, wPx));
  if (!geo) {
    const cv = makeCanvas(lw, lh), c = ctx2d(cv, { willReadFrequently: true });
    c.imageSmoothingEnabled = true;
    try { c.imageSmoothingQuality = 'high'; } catch (e) { /* older engines */ }
    const useLabels = LABEL_LOOKS[look] && art.labels && art.labels.width > 0 && art.labels.data;
    // PVC is moulded in flat colour levels: smooth the regions harder than thread looks
    const tc = now();
    let clean = useLabels ? cleanFor(art.labels, look === 'pvc' ? 5 : look === 'embroidered' ? 3 : 2) : null;
    // islands smaller than a stitchable area (mm²) melt into their neighbour: no digitiser stitches 1 mm² specks
    if (clean) clean = islandsFor(clean, (look === 'pvc' ? 1.2 : look === 'embroidered' ? 1.6 : 0.7) / Math.pow(rect.w / clean.width, 2));
    c.drawImage(useLabels ? recolored(art, ck, clean) : art.image, 0, 0, lw, lh);
    const img = c.getImageData(0, 0, lw, lh);
    let comps = null;
    if (useLabels && look === 'embroidered') comps = chooseModes(componentsFor(clean), rect.w / clean.width, 7);
    PT('prep', tc);
    const tq = now();
    geo = buildLayer(img, look, { ppm: layerPpm, labels: clean, comps, engrave: rgbOf(o.engraveHex), relief: o.relief, pvcDepth: o.pvcDepth });
    PT('artBuild', tq);
    geoCache.set(key, geo, lw * lh);
  }
  const tl = now();
  if (o.quality === 'fast') {
    // pointer-driven light: relight a half-resolution copy (¼ of the pixels); 'high' restores full detail
    const hk = key + '#h#' + o.light.key;
    let hl = litCache.get(hk);
    if (!hl) {
      const hg = halfGeo(geo);
      const l2 = litCanvas(hg, o.light, RELIEF_SHADOW[look], layerPpm / 2);
      hl = litCache.set(hk, { canvas: l2.canvas, pad: l2.pad, w: hg.w, h: hg.h, ppm: layerPpm / 2 }, l2.canvas.width * l2.canvas.height);
    }
    return hl;
  }
  const lit = litCanvas(geo, o.light, RELIEF_SHADOW[look], layerPpm);
  PT('artLit', tl);
  const out = { canvas: lit.canvas, pad: lit.pad, w: lw, h: lh, ppm: layerPpm };
  const outKey = key + '#' + o.light.key;
  artLatest.set(prefix, outKey);
  return litCache.set(outKey, out, out.canvas.width * out.canvas.height);
}

/** Text layer in the patch-local frame (same frame as the base layer), lit + relief shadow baked. */
function textLayer(layer, look, g, o) {
  const f = fontFor(layer, o.config);
  const ready = fontReady(f);
  const fast = o.quality === 'fast';
  const ol = o.outline || null;
  const key = [textsKey([layer]), look, g.W, g.H, g.ppm.toFixed(3), g.polyKey, o.hex, ol ? ol.hex + ':' + ol.mm : '', o.edgeMm, ready ? 1 : 0, look === 'leather' ? o.engraveHex : '', o.relief, o.pvcDepth, o.light.key].join('#');
  const hit = textCache.get(key);
  if (hit) return hit;
  // crop to the text: the stylize pass only runs over the letters
  const cv = makeCanvas(g.W, g.H), c = ctx2d(cv, { willReadFrequently: !fast });
  drawTextLayer(c, layer, { poly: g.poly, shape: g.shape, cx: g.cx, cy: g.cy, ppm: g.ppm, edgeMm: o.edgeMm, config: o.config, hex: o.hex, outline: ol });
  if (fast) return { canvas: cv, ready, pad: 0, x: 0, y: 0 }; // plain text while dragging, not cached
  const full = c.getImageData(0, 0, g.W, g.H).data;
  let x0 = g.W, y0 = g.H, x1 = -1, y1 = -1;
  for (let y = 0; y < g.H; y += 2) {
    const r = y * g.W;
    for (let x = 0; x < g.W; x += 2) if (full[(r + x) * 4 + 3]) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
  }
  if (x1 < 0) return textCache.set(key, { canvas: cv, ready, pad: 0, x: 0, y: 0 });
  const m = Math.ceil(g.ppm * 0.6) + 2;
  x0 = Math.max(0, x0 - m); y0 = Math.max(0, y0 - m); x1 = Math.min(g.W - 1, x1 + m); y1 = Math.min(g.H - 1, y1 + m);
  const w = x1 - x0 + 1, h = y1 - y0 + 1;
  const img = c.getImageData(x0, y0, w, h);
  const geo = buildLayer(img, look, { ppm: g.ppm, labels: null, comps: null, engrave: rgbOf(o.engraveHex), relief: o.relief, pvcDepth: o.pvcDepth });
  const lit = litCanvas(geo, o.light, RELIEF_SHADOW[look], g.ppm);
  return textCache.set(key, { canvas: lit.canvas, ready, pad: lit.pad, x: x0, y: y0 });
}

/* ------------------------------ patch body + border ------------------------------ */

const underCache = new WLru(1.2e7, 32);
const overCache = new WLru(1.2e7, 32);
const frameLatest = new Map();

function bandFor(F) {
  const ext = edgeBandMm(F.edge);
  const inMm = Math.max(ext[0], (BODY[F.look] || BODY.embroidered).roll + 0.1);
  const key = [F.W, F.H, F.ppm.toFixed(3), F.polyKey, inMm, ext[1]].join('#');
  let b = bandCache.get(key);
  if (!b) {
    const tb = now();
    b = bandField(F.poly, F.W, F.H, inMm * F.ppm, ext[1] * F.ppm + 2, null);
    PT('band', tb);
    bandCache.set(key, b);
  }
  return b;
}

/** Everything under the design: drop shadow / fabric piece, base colour, drawn backdrop, lit base material. */
function PT(k, t) { const P = globalThis.__bpsProf; if (P) P[k] = (P[k] || 0) + now() - t; }
function underLayer(F) {
  const t0 = now();
  const r = underLayer0(F);
  PT('under', t0);
  return r;
}
function underLayer0(F) {
  const gkey = ['u', F.look, F.W, F.H, F.ppm.toFixed(3), F.polyKey, F.baseHex, F.fabricHex, F.edge.style, F.relief,
    F.backdrop ? idOf(F.backdrop.image) + ':' + F.rectL.x.toFixed(1) + ',' + F.rectL.y.toFixed(1) + ',' + F.rectL.w.toFixed(1) : '', F.hasBg ? 1 : 0].join('#');
  const key = gkey + '#' + (F.shadow ? 1 : 0) + '#' + F.light.key;
  const hit = underCache.get(key);
  if (hit) return hit;
  const pb = F.pb, ppm = F.ppm;
  const uncut = F.edge.style === 'uncut';
  const fb = uncut ? { x: pb.x - 5 * ppm, y: pb.y - 5 * ppm, w: pb.w + 10 * ppm, h: pb.h + 10 * ppm } : null;
  const body = BODY[F.look] || BODY.embroidered;
  // albedo (fabric piece, base colour, drawn backdrop)
  const cv = makeCanvas(F.W, F.H), ac = ctx2d(cv, { willReadFrequently: true });
  if (uncut) { ac.fillStyle = F.fabricHex; ac.fillRect(fb.x, fb.y, fb.w, fb.h); }
  ac.save();
  ac.beginPath(); tracePath(ac, F.poly); ac.clip();
  ac.fillStyle = F.baseHex; ac.fillRect(pb.x - 2, pb.y - 2, pb.w + 4, pb.h + 4);
  if (F.backdrop) {
    const bd = F.backdrop, s = F.rectL.w / bd.artBox.w;
    const bw = (bd.image.width || bd.width) * s, bh = (bd.image.height || bd.height) * s;
    ac.drawImage(bd.image, F.rectL.x - bd.artBox.x * s, F.rectL.y - bd.artBox.y * s, bw, bh);
  }
  ac.restore();
  // crop the lighting pass to the patch (+ fabric piece)
  const bx0 = Math.max(0, Math.floor((uncut ? fb.x : pb.x) - 2)), by0 = Math.max(0, Math.floor((uncut ? fb.y : pb.y) - 2));
  const bx1 = Math.min(F.W, Math.ceil((uncut ? fb.x + fb.w : pb.x + pb.w) + 2)), by1 = Math.min(F.H, Math.ceil((uncut ? fb.y + fb.h : pb.y + pb.h) + 2));
  const img = ac.getImageData(bx0, by0, bx1 - bx0, by1 - by0);
  const tile = baseTile(F.look, F.hasBg, ppm);
  const band = uncut ? null : bandFor(F);
  const tb = now();
  shadeBase(img.data, img.width, img.height, tile, F.light, band ? cropBand(band, F.W, bx0, by0, img.width) : null,
    { roll: body.roll, thick: Math.min(body.roll, body.thick * 0.5) * F.relief, ppm });
  PT('baseBuild', tb);
  ac.putImageData(img, bx0, by0);
  if (!F.shadow) return underCache.set(key, cv, F.W * F.H);
  const out = makeCanvas(F.W, F.H), c = ctx2d(out);
  // contact shadow + soft cast shadow, both following the light
  const [L0, L1, L2] = F.light.L;
  const t = body.thick * ppm * (uncut ? 0.4 : 1), k = 1 / Math.max(0.3, L2 / Math.hypot(L0, L1, L2));
  const sx = -L0 * k * t * 0.55, sy = -L1 * k * t * 0.55;
  softShadow(c, cv, 0, 0, sx * 1.6, sy * 1.6 + ppm * 0.3, t * 2.2 + ppm * 1.5, 0.16 + body.shadowA * 0.22, '18,24,38');
  softShadow(c, cv, 0, 0, sx * 0.35, sy * 0.35, Math.max(1.5, ppm * 0.45), 0.25 + body.shadowA * 0.3, '10,14,24');
  c.drawImage(cv, 0, 0);
  return underCache.set(key, out, F.W * F.H);
}

/** Band field re-indexed into a crop of the frame. */
function cropBand(b, W, x0, y0, w) {
  if (!x0 && !y0 && w === W) return b;
  const idx = new Int32Array(b.n);
  for (let m = 0; m < b.n; m++) { const i = b.idx[m], x = i % W, y = (i - x) / W; idx[m] = (y - y0) * w + (x - x0); }
  return Object.assign({}, b, { idx });
}

/** Lit border (+ its shadow on the patch) and small border details. */
function overLayer(F) {
  const t0 = now();
  const r = overLayer0(F);
  PT('over', t0);
  return r;
}
function overLayer0(F) {
  const key = ['o', F.look, F.W, F.H, F.ppm.toFixed(3), F.polyKey, F.baseHex, F.fabricHex, F.edgeHex, JSON.stringify(F.edge), F.relief, F.light.key].join('#');
  const hit = overCache.get(key);
  if (hit) return hit;
  const cv = makeCanvas(F.W, F.H), c = ctx2d(cv);
  const band = bandFor(F);
  const tmp = makeCanvas(F.W, F.H), tc = ctx2d(tmp);
  const id = tc.createImageData(F.W, F.H);
  const gkey = ['e', F.look, F.W, F.H, F.ppm.toFixed(3), F.polyKey, F.baseHex, F.edgeHex, JSON.stringify(F.edge), F.relief].join('#');
  let eg = edgeGeoCache.get(gkey);
  if (!eg) {
    eg = edgeGeo(F.W, F.H, band, F.ppm, F.edge, { edgeHex: F.edgeHex, fabricHex: F.baseHex, technique: F.look, engraveHex: F.engraveHex, relief: F.relief });
    edgeGeoCache.set(gkey, eg);
  }
  const r = shadeEdge(eg, F.light, id.data);
  tc.putImageData(id, 0, 0);
  if (F.edge.style !== 'laser') {
    const [L0, L1, L2] = F.light.L;
    const hpx = r.maxHmm * F.ppm, k = Math.min(2.5, 0.8 / Math.max(0.25, L2));
    softShadow(c, tmp, 0, 0, -L0 * k * hpx, -L1 * k * hpx, hpx * 0.8 + F.ppm * 0.3, 0.5);
    c.drawImage(tmp, 0, 0);
  } else c.drawImage(tmp, 0, 0);
  edgeDecor(c, F.poly, F.ppm, F.edge, { fabricHex: F.baseHex, edgeHex: F.edgeHex, seed: 1 + F.polyKey });
  return overCache.set(key, cv, F.W * F.H);
}

/** Cached frame layers at another scale (fast mode while zooming): {canvas, ppm, lx, ly}. */
function frameLayer(kind, F, build) {
  const prefix = [kind, F.look, F.polyKey, F.baseHex, F.fabricHex, F.edgeHex, JSON.stringify(F.edge), F.relief, F.shadow ? 1 : 0,
    F.backdrop ? idOf(F.backdrop.image) : '', F.hasBg ? 1 : 0].join('#');
  const geomKey = [F.W, F.H, F.ppm.toFixed(3)].join('#'), sizeKey = geomKey + '#' + F.light.key;
  if (F.quality === 'fast') {
    const prev = frameLatest.get(prefix);
    // fast: reuse the cached layer at another scale / light (the design layer carries the moving glint)
    if (prev && prev.sizeKey !== sizeKey) return prev.layer;
  }
  const canvas = build(F);
  const layer = { canvas, ppm: F.ppm, lx: F.lx, ly: F.ly };
  frameLatest.set(prefix, { sizeKey, geomKey, layer });
  if (frameLatest.size > 24) frameLatest.delete(frameLatest.keys().next().value);
  return layer;
}

function drawFrameLayer(c, L, F, fx, fy) {
  if (L.ppm === F.ppm) { c.drawImage(L.canvas, fx, fy); return; }
  const s = F.ppm / L.ppm;
  c.drawImage(L.canvas, fx + F.lx - L.lx * s, fy + F.ly - L.ly * s, L.canvas.width * s, L.canvas.height * s);
}

/**
 * Paint a complete patch into `c` (patch mm (0,0) at canvas px (g.ox, g.oy)).
 * All layers live in one patch-local frame (bbox of the outline + margin) so they can be cached and
 * reused while the design is dragged.
 */
export function paintPatch(c, o, g) {
  const { config, layout, design } = o;
  const look = g.look;
  const ppm = g.ppm;
  const art = g.art;
  const light = g.light || normLight(o.light);
  const relief = reliefOf(design);
  const pvcDepth = o.pvcDepth === '3d' || (design && design.pvcDepth === '3d') ? '3d' : '2d';
  const fabricHex = o.fabricHex || '#1b2a4a';
  const baseHex = (look !== 'leather' && o.backgroundHex) || fabricHex;
  const engraveHex = engraveFor(fabricHex, config);
  const edge0 = layout.edge || { style: 'satin', widthMm: 2.5, safeZoneMm: 2 };
  const edge = Object.assign({}, edge0, { style: look === 'pvc' && edge0.style !== 'molded' ? 'molded' : edge0.style });
  const bbMm = bbox(g.polyMm);
  const M = Math.ceil(ppm * 7.5);
  // integer frame origin on the target canvas (no resampling blur)
  const fx = Math.round(g.ox + bbMm.x * ppm) - M, fy = Math.round(g.oy + bbMm.y * ppm) - M;
  const lx = g.ox - fx, ly = g.oy - fy; // patch mm (0,0) inside the frame
  const W = Math.ceil(bbMm.w * ppm + 2 * M), H = Math.ceil(bbMm.h * ppm + 2 * M);
  const poly = transformPolygon(g.polyMm, ppm, lx, ly);
  const pb = bbox(poly);
  const rectL = layout.artRectMm ? { x: lx + layout.artRectMm.x * ppm, y: ly + layout.artRectMm.y * ppm, w: layout.artRectMm.w * ppm, h: layout.artRectMm.h * ppm } : null;
  const bd = art && art.backdrop;
  const backdrop = bd && bd.image && bd.artBox && bd.artBox.w > 0 && rectL && look !== 'leather' ? bd : null;
  const edgeHex = look === 'pvc' ? (o.edgeHex || baseHex)
    : o.edgeHex || shade(fabricHex, lum(fabricHex) < 90 ? 0.45 : -0.45);
  const F = { look, ppm, W, H, lx, ly, poly, pb, polyKey: g.polyKey, baseHex, fabricHex, edge, edgeHex, engraveHex, shadow: g.shadow, backdrop, rectL,
    hasBg: !!o.backgroundHex || !!backdrop, light, relief, quality: o.quality };

  drawFrameLayer(c, frameLayer('u', F, underLayer), F, fx, fy);

  c.save();
  c.beginPath(); tracePath(c, transformPolygon(g.polyMm, ppm, g.ox, g.oy)); c.clip();
  if (art && art.image && rectL && rectL.w > 0 && rectL.h > 0) {
    const tal = now();
    const L = artLayer(art, look, layout.artRectMm, ppm, { reduced: o.reduced !== false, quality: o.quality, engraveHex, light, relief, pvcDepth });
    const sx = rectL.w / L.w, sy = rectL.h / L.h;
    const r = { x: fx + rectL.x - L.pad * sx, y: fy + rectL.y - L.pad * sy, w: rectL.w + 2 * L.pad * sx, h: rectL.h + 2 * L.pad * sy };
    const clip = art.clip && art.clip.inset > 0 ? art.clip : null;
    if (clip) {
      // mask the photographed rim of a patch-like design (round → ellipse, other shapes → inset rectangle)
      c.save();
      const ix = rectL.w * clip.inset, iy = rectL.h * clip.inset;
      c.beginPath();
      if (clip.shape === 'circle' || clip.shape === 'oval') c.ellipse(fx + rectL.x + rectL.w / 2, fy + rectL.y + rectL.h / 2, rectL.w / 2 - ix, rectL.h / 2 - iy, 0, 0, Math.PI * 2);
      else c.rect(fx + rectL.x + ix, fy + rectL.y + iy, rectL.w - 2 * ix, rectL.h - 2 * iy);
      c.clip();
    }
    drawArtImage(c, null, r, design && design.art ? design.art.rotation : 0, L.canvas);
    if (clip) c.restore();
    PT('artTotal', tal);
  }
  const texts = (design && design.texts || []).filter((t) => t && String(t.text || '').trim());
  if (texts.length) {
    const tg = { W, H, ppm, poly, shape: layout.shape, cx: lx + (layout.patchWmm / 2) * ppm, cy: ly + (layout.patchHmm / 2) * ppm, polyKey: g.polyKey };
    for (const t of texts) {
      const hex = (o.textHex && o.textHex[t.id]) || (lum(baseHex) < 128 ? '#ffffff' : '#151515');
      const tl = textLayer(t, look, tg, { config, hex, outline: (o.textOutline && o.textOutline[t.id]) || null, edgeMm: edgeCoverMm(edge), quality: o.quality, engraveHex, light, relief, pvcDepth });
      if (!tl.ready) g.pendingFonts.push(fontFor(t, config));
      c.drawImage(tl.canvas, fx + (tl.x || 0) - tl.pad, fy + (tl.y || 0) - tl.pad);
    }
  }
  c.restore();

  drawFrameLayer(c, frameLayer('o', F, overLayer), F, fx, fy);

  // guides (UI only, never cached)
  if (o.guides && !o.transparent) {
    const polyPx = transformPolygon(g.polyMm, ppm, g.ox, g.oy);
    c.save();
    if (o.guides.safeZone) {
      const sz = Math.max(edge.safeZoneMm || 0, edgeCoverMm(edge)) * ppm;
      c.setLineDash([ppm * 1.2, ppm * 0.8]);
      c.beginPath(); tracePath(c, offsetPolygon(polyPx, sz)); c.lineWidth = Math.max(1, ppm * 0.18); c.strokeStyle = 'rgba(33,169,226,0.95)'; c.stroke();
      c.setLineDash([]);
    }
    for (const b of o.guides.boxesMm || []) {
      const r = { x: g.ox + b.x * ppm, y: g.oy + b.y * ppm, w: b.w * ppm, h: b.h * ppm };
      c.fillStyle = 'rgba(229,72,77,0.14)'; c.fillRect(r.x, r.y, r.w, r.h);
      c.lineWidth = Math.max(1.5, ppm * 0.25); c.strokeStyle = 'rgba(229,72,77,0.95)'; c.strokeRect(r.x, r.y, r.w, r.h);
    }
    c.restore();
  }
  return { frame: { x: fx, y: fy, w: W, h: H } };
}

/* ------------------------------ public renderer ------------------------------ */

function polyKeyOf(polyMm) {
  let h = polyMm.length;
  for (let i = 0; i < polyMm.length; i += 7) h = (h * 31 + Math.round(polyMm[i][0] * 50) * 17 + Math.round(polyMm[i][1] * 50)) | 0;
  return h;
}

function prepare(opts, config) {
  const design = opts.design || {};
  const technique = opts.technique || design.technique || 'embroidered';
  const look = lookOf(technique, config);
  const layout = opts.layout;
  const art = resolveArt(opts.art);
  const polyMm = patchPolygonMm(layout, art, design, config);
  return { design, technique, look, layout, art, polyMm, polyKey: polyKeyOf(polyMm) };
}


/**
 * Draw onto a 2D context of size W×H. Shared by createRenderer, renderThumb and toBlob.
 */
export function drawScene(c, W, H, opts, config, pendingFonts) {
  const t0 = now();
  const P = prepare(opts, config);
  const layout = P.layout;
  const o = Object.assign({}, opts, { config, layout, design: P.design });
  c.setTransform(1, 0, 0, 1, 0, 0);
  c.clearRect(0, 0, W, H);
  if (!layout) return { ms: now() - t0, pxPerMm: 0, originPx: { x: 0, y: 0 } };
  const view = opts.view === 'garment' ? 'garment' : 'patch';
  const light = normLight(opts.light);
  const bb = bbox(P.polyMm);
  const extra = layout.edge && layout.edge.style === 'uncut' ? 5 : 1;
  const bx = bb.x - extra, by = bb.y - extra, bw = bb.w + 2 * extra, bh = bb.h + 2 * extra;

  if (view === 'garment') {
    const garment = opts.garment || (P.design.mockup && P.design.mockup !== 'none' ? P.design.mockup : 'polo_chest');
    const place = drawGarment(c, W, H, garment, config, { garmentHex: opts.garmentHex, transparent: opts.transparent, zoom: opts.zoom, light, quality: opts.quality,
      frame: opts.frame || 'garment', patchMm: Math.max(bw, bh) });
    const ppm = place.pxPerMm;
    // render the patch at garment scale (2× supersampled when tiny) into its own canvas
    const ss = ppm < 3 ? 3 : ppm < 6 ? 2 : 1;
    const pw = Math.max(2, Math.ceil(bw * ppm * ss)), ph = Math.max(2, Math.ceil(bh * ppm * ss));
    const pc = makeCanvas(pw, ph), pctx = ctx2d(pc);
    paintPatch(pctx, o, { look: P.look, ppm: ppm * ss, ox: -bx * ppm * ss, oy: -by * ppm * ss, polyMm: P.polyMm, polyKey: P.polyKey, art: P.art, shadow: false, pendingFonts, light });
    const cx = place.cx + ((bx + bw / 2) - layout.patchWmm / 2) * ppm, cy = place.cy + ((by + bh / 2) - layout.patchHmm / 2) * ppm;
    placePatch(c, pc, Object.assign({}, place, { cx, cy, light, look: P.look }), bw * ppm, bh * ppm);
    return {
      ms: now() - t0, pxPerMm: ppm,
      originPx: { x: place.cx - (layout.patchWmm / 2) * ppm, y: place.cy - (layout.patchHmm / 2) * ppm },
      view, garment,
    };
  }

  const fit = opts._fit || (opts.transparent ? 0.96 : 0.84);
  const zoom = clamp(Number(opts.zoom) || 1, 0.5, 8);
  const ppm = Math.min(W / bw, H / bh) * fit * zoom;
  let fx = bx + bw / 2, fy = by + bh / 2;
  if (zoom > 1 && opts.zoomCenterMm) { fx = Number(opts.zoomCenterMm.x) || fx; fy = Number(opts.zoomCenterMm.y) || fy; }
  const ox = W / 2 - fx * ppm, oy = H / 2 - fy * ppm;
  const tbd = now();
  if (!opts.transparent) drawBackdrop(c, W, H, light, opts.scene);
  PT('backdrop', tbd);
  paintPatch(c, o, { look: P.look, ppm, ox, oy, polyMm: P.polyMm, polyKey: P.polyKey, art: P.art, shadow: !opts.transparent, pendingFonts, light });
  return { ms: now() - t0, pxPerMm: ppm, originPx: { x: ox, y: oy }, view, light: { azimuthDeg: Math.round(light.az * 180 / Math.PI), elevationDeg: Math.round(light.el * 180 / Math.PI) } };
}

/** Size of an export canvas whose longest side is `size`, cropped to the patch. */
export function exportSize(opts, config, size) {
  const P = prepare(opts, config);
  const bb = bbox(P.polyMm);
  const extra = P.layout.edge && P.layout.edge.style === 'uncut' ? 5 : 1.2;
  const w = bb.w + 2 * extra, h = bb.h + 2 * extra;
  return w >= h ? { w: size, h: Math.max(1, Math.round(size * h / w)) } : { w: Math.max(1, Math.round(size * w / h)), h: size };
}

export function canvasToBlob(cv, type) {
  if (cv.convertToBlob) return cv.convertToBlob({ type });
  return new Promise((res, rej) => cv.toBlob((b) => (b ? res(b) : rej(new Error('toBlob failed'))), type));
}

export function fontsFor(opts, config) {
  return ((opts.design && opts.design.texts) || []).filter((t) => t && String(t.text || '').trim()).map((t) => fontFor(t, config));
}

export { loadFont };
