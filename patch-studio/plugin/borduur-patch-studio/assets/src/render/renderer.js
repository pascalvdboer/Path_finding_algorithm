/**
 * Patch Studio — canvas renderer (SPEC §7.6). Composes a realistic patch close-up:
 *   scene → (fabric piece for "uncut") → patch shadow → base (background/fabric) → drawn backdrop → base material
 *   → design layer (material kernel, cached) → text layers (cached) → finishing light → border → guides.
 * Layout always comes from shared/geometry.js#layoutPatch() (opts.layout); the renderer never changes sizes.
 */
import { shapePolygon, transformPolygon, tracePath, bbox, offsetPolygon, contourFromMask, convexHull, traceBoundary } from './shapes.js';
import { analyzeComponents, chooseModes, stylizePixels, colorTable, cleanLabels } from './stylize.js';
import { overlayTexture } from './textures.js';
import { drawEdge, edgeCoverMm } from './edges.js';
import { drawTextLayer, fontFor, fontReady, loadFont } from './text.js';
import { drawGarment, placePatch } from './garment.js';
import { makeCanvas, ctx2d, Lru, idOf, now, shade, rgbOf, lum, clamp } from './util.js';

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
      drawTextLayer(c, t, { poly: box, cx: layout.patchWmm / 2, cy: layout.patchHmm / 2, ppm: 1, edgeMm: 0, config, hex: '#000' });
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

/* ------------------------------ shadows + finishes baked into layers ------------------------------ */

// [alpha, blurMm, offsetXmm, offsetYmm] of the relief shadow a raised layer casts on the patch
const SHADOWS = {
  embroidered: [0.45, 0.5, 0.12, 0.3],
  woven: [0.25, 0.2, 0.05, 0.1],
  chenille: [0.5, 1.0, 0.2, 0.45],
  pvc: [0.45, 0.6, 0.15, 0.35],
};

/** Print-like looks show the fabric weave through the ink: overlay the weave on the layer itself. */
function finishLayer(c, look, ppm, w, h) {
  if (look !== 'printed' && look !== 'sublimated') return;
  c.save();
  c.globalCompositeOperation = 'source-atop';
  overlayTexture(c, look === 'printed' ? 'twill' : 'poly', ppm, { x: 0, y: 0, w, h }, look === 'printed' ? 0.32 : 0.28);
  c.restore();
}

/** Return a padded copy of `cv` with the look's relief shadow baked in. pad in px of `cv`. */
function bakeShadow(cv, look, ppm) {
  const s = SHADOWS[look];
  if (!s) return { canvas: cv, pad: 0 };
  const pad = Math.ceil(ppm * (s[1] * 1.6 + Math.max(s[2], s[3])) + 2);
  const out = makeCanvas(cv.width + 2 * pad, cv.height + 2 * pad), c = ctx2d(out);
  c.shadowColor = `rgba(0,0,0,${s[0]})`; c.shadowBlur = s[1] * ppm; c.shadowOffsetX = s[2] * ppm; c.shadowOffsetY = s[3] * ppm;
  c.drawImage(cv, pad, pad);
  return { canvas: out, pad };
}

function artLayer(art, look, rect, ppm, o) {
  const wPx = rect.w * ppm, hPx = rect.h * ppm;
  const maxSide = 1600;
  // snap the layer size to 6 % steps: small size/scale changes reuse the cached layer (drawImage rescales)
  const longest = Math.min(maxSide, Math.max(wPx, hPx, 1));
  const snapped = Math.min(maxSide, Math.pow(1.06, Math.round(Math.log(longest) / Math.log(1.06))));
  const f = snapped / Math.max(wPx, hPx, 1);
  let lw = Math.max(1, Math.round(wPx * f)), lh = Math.max(1, Math.round(hPx * f));
  const ck = colorKey(art, look, o.reduced);
  const prefix = [idOf(art.image), look, ck, look === 'leather' ? o.engraveHex : ''].join('#');
  let key = prefix + '#' + lw + 'x' + lh;
  const hit = artCache.get(key);
  if (hit) return hit;
  if (o.quality === 'fast') {
    const lk = artLatest.get(prefix);
    const prev = lk && artCache.get(lk);
    if (prev) return prev; // reuse at another size while dragging/zooming
    lw = Math.max(1, lw >> 1); lh = Math.max(1, lh >> 1); key = prefix + '#' + lw + 'x' + lh;
  }
  const cv = makeCanvas(lw, lh), c = ctx2d(cv, { willReadFrequently: true });
  c.imageSmoothingEnabled = true;
  try { c.imageSmoothingQuality = 'high'; } catch (e) { /* older engines */ }
  const useLabels = LABEL_LOOKS[look] && art.labels && art.labels.width > 0 && art.labels.data;
  // PVC is moulded in flat colour levels: smooth the regions harder than thread looks
  const clean = useLabels ? cleanFor(art.labels, look === 'pvc' ? 5 : 2) : null;
  c.drawImage(useLabels ? recolored(art, ck, clean) : art.image, 0, 0, lw, lh);
  const layerPpm = ppm * (lw / Math.max(1, wPx));
  if (look !== 'printed' && look !== 'sublimated') {
    const img = c.getImageData(0, 0, lw, lh);
    let comps = null;
    if (useLabels && look === 'embroidered') comps = chooseModes(componentsFor(clean), rect.w / clean.width, 7);
    stylizePixels(img, {
      technique: look, pxPerMm: layerPpm, labels: clean, comps, colors: null,
      single: useLabels ? null : { mode: 0, cos: 0.7071, sin: 0.7071, cx: 0, cy: 0, r: 1 },
      engrave: rgbOf(o.engraveHex),
    });
    c.putImageData(img, 0, 0);
  }
  finishLayer(c, look, layerPpm, lw, lh);
  const baked = bakeShadow(cv, look, layerPpm);
  const out = { canvas: baked.canvas, pad: baked.pad, w: lw, h: lh, ppm: layerPpm };
  artLatest.set(prefix, key);
  return artCache.set(key, out);
}

/** Text layer in the patch-local frame (same frame as the base layer), stylized + shadow baked. */
function textLayer(layer, look, g, o) {
  const f = fontFor(layer, o.config);
  const ready = fontReady(f);
  const fast = o.quality === 'fast';
  const key = [textsKey([layer]), look, g.W, g.H, g.ppm.toFixed(3), g.polyKey, o.hex, o.edgeMm, ready ? 1 : 0, look === 'leather' ? o.engraveHex : ''].join('#');
  const hit = textCache.get(key);
  if (hit) return hit;
  const cv = makeCanvas(g.W, g.H), c = ctx2d(cv, { willReadFrequently: !fast });
  const field = drawTextLayer(c, layer, { poly: g.poly, cx: g.cx, cy: g.cy, ppm: g.ppm, edgeMm: o.edgeMm, config: o.config, hex: o.hex });
  if (fast) return { canvas: cv, ready, pad: 0 }; // plain text while dragging, not cached
  if (look !== 'printed' && look !== 'sublimated') {
    const img = c.getImageData(0, 0, g.W, g.H);
    stylizePixels(img, { technique: look, pxPerMm: g.ppm, labels: null, comps: null, colors: null, single: field, engrave: rgbOf(o.engraveHex) });
    c.putImageData(img, 0, 0);
  }
  finishLayer(c, look, g.ppm, g.W, g.H);
  const s = SHADOWS[look];
  if (s) {
    // same frame size: draw the shadow under a copy (the frame has a margin, nothing gets cut)
    const out = makeCanvas(g.W, g.H), oc = ctx2d(out);
    oc.shadowColor = `rgba(0,0,0,${s[0]})`; oc.shadowBlur = s[1] * g.ppm; oc.shadowOffsetX = s[2] * g.ppm; oc.shadowOffsetY = s[3] * g.ppm;
    oc.drawImage(cv, 0, 0);
    return textCache.set(key, { canvas: out, ready, pad: 0 });
  }
  return textCache.set(key, { canvas: cv, ready, pad: 0 });
}

/* ------------------------------ patch painting ------------------------------ */

function baseMaterial(c, look, ppm, box, hasBg, fast) {
  switch (look) {
    case 'embroidered':
      if (hasBg) overlayTexture(c, 'tatami', ppm, box, 0.6, Math.PI / 4);
      else overlayTexture(c, 'twill', ppm, box, fast ? 0.35 : 0.5);
      break;
    case 'woven': overlayTexture(c, 'weave', ppm, box, 0.55); break;
    case 'chenille': overlayTexture(c, 'felt', ppm, box, 0.75); break;
    case 'leather': overlayTexture(c, 'grain', ppm, box, 0.85); break;
    case 'pvc': overlayTexture(c, 'pvc', ppm, box, 0.35); break;
    case 'printed': overlayTexture(c, 'twill', ppm, box, 0.32); break;
    case 'sublimated': overlayTexture(c, 'poly', ppm, box, 0.28); break;
    default: break;
  }
}

const underCache = new Lru(6);
const overCache = new Lru(6);

/** Everything under the design: patch shadow / fabric piece, base colour, drawn backdrop, base material. */
function underLayer(F, o) {
  const key = ['u', F.look, F.W, F.H, F.ppm.toFixed(3), F.polyKey, F.baseHex, F.fabricHex, F.edge.style, F.shadow ? 1 : 0,
    F.backdrop ? idOf(F.backdrop.image) + ':' + F.rectL.x.toFixed(1) + ',' + F.rectL.y.toFixed(1) + ',' + F.rectL.w.toFixed(1) : '', F.hasBg ? 1 : 0].join('#');
  const hit = underCache.get(key);
  if (hit) return hit;
  const cv = makeCanvas(F.W, F.H), c = ctx2d(cv);
  const ppm = F.ppm, poly = F.poly, pb = F.pb;
  if (F.edge.style === 'uncut') {
    const m = 5 * ppm, fb = { x: pb.x - m, y: pb.y - m, w: pb.w + 2 * m, h: pb.h + 2 * m };
    c.save();
    if (F.shadow) { c.shadowColor = 'rgba(10,20,40,0.28)'; c.shadowBlur = ppm * 2; c.shadowOffsetY = ppm * 0.8; }
    c.fillStyle = F.fabricHex; c.fillRect(fb.x, fb.y, fb.w, fb.h);
    c.restore();
    c.save(); c.beginPath(); c.rect(fb.x, fb.y, fb.w, fb.h); c.clip();
    overlayTexture(c, 'twill', ppm, fb, 0.5);
    c.restore();
  } else if (F.shadow) {
    c.save();
    c.shadowColor = 'rgba(10,20,40,0.30)'; c.shadowBlur = ppm * 2.4; c.shadowOffsetY = ppm * 1.0;
    c.beginPath(); tracePath(c, poly); c.fillStyle = F.baseHex; c.fill();
    c.restore();
  }
  c.save();
  c.beginPath(); tracePath(c, poly); c.clip();
  c.fillStyle = F.baseHex; c.fillRect(pb.x - 2, pb.y - 2, pb.w + 4, pb.h + 4);
  if (F.backdrop) {
    const bd = F.backdrop, s = F.rectL.w / bd.artBox.w;
    const bw = (bd.image.width || bd.width) * s, bh = (bd.image.height || bd.height) * s;
    c.drawImage(bd.image, F.rectL.x - bd.artBox.x * s, F.rectL.y - bd.artBox.y * s, bw, bh);
  }
  baseMaterial(c, F.look, ppm, pb, F.hasBg, false);
  c.restore();
  return underCache.set(key, cv);
}

/** Finishing light inside the patch + the border on top. */
function overLayer(F, o) {
  const key = ['o', F.look, F.W, F.H, F.ppm.toFixed(3), F.polyKey, F.baseHex, F.fabricHex, F.edgeHex, JSON.stringify(F.edge)].join('#');
  const hit = overCache.get(key);
  if (hit) return hit;
  const cv = makeCanvas(F.W, F.H), c = ctx2d(cv);
  const pb = F.pb, look = F.look;
  c.save();
  c.beginPath(); tracePath(c, F.poly); c.clip();
  if (look === 'pvc') {
    const gl = c.createLinearGradient(pb.x, pb.y, pb.x + pb.w * 0.3, pb.y + pb.h);
    gl.addColorStop(0, 'rgba(255,255,255,0.30)'); gl.addColorStop(0.42, 'rgba(255,255,255,0.04)'); gl.addColorStop(1, 'rgba(0,0,0,0.12)');
    c.fillStyle = gl; c.fillRect(pb.x, pb.y, pb.w, pb.h);
    const sp = c.createRadialGradient(pb.x + pb.w * 0.3, pb.y + pb.h * 0.22, 0, pb.x + pb.w * 0.3, pb.y + pb.h * 0.22, pb.w * 0.32);
    sp.addColorStop(0, 'rgba(255,255,255,0.18)'); sp.addColorStop(1, 'rgba(255,255,255,0)');
    c.fillStyle = sp; c.fillRect(pb.x, pb.y, pb.w, pb.h);
  } else if (look === 'leather') {
    const vg = c.createRadialGradient(pb.x + pb.w * 0.45, pb.y + pb.h * 0.4, Math.min(pb.w, pb.h) * 0.25, pb.x + pb.w / 2, pb.y + pb.h / 2, Math.max(pb.w, pb.h) * 0.62);
    vg.addColorStop(0, 'rgba(255,240,220,0.06)'); vg.addColorStop(1, 'rgba(20,8,0,0.38)');
    c.fillStyle = vg; c.fillRect(pb.x, pb.y, pb.w, pb.h);
  }
  // soft key light from the top left gives every material some volume
  const lg = c.createLinearGradient(pb.x, pb.y, pb.x + pb.w, pb.y + pb.h);
  lg.addColorStop(0, 'rgba(255,255,255,0.07)'); lg.addColorStop(0.55, 'rgba(255,255,255,0)'); lg.addColorStop(1, 'rgba(0,0,0,0.10)');
  c.fillStyle = lg; c.fillRect(pb.x, pb.y, pb.w, pb.h);
  c.restore();
  drawEdge(c, F.poly, F.ppm, F.edge, {
    edgeHex: F.edgeHex, fabricHex: F.baseHex, technique: look, quality: 'high',
    seed: 1 + F.polyKey, engraveHex: F.engraveHex,
  });
  return overCache.set(key, cv);
}

/**
 * Paint a complete patch into `c` (patch mm (0,0) at canvas px (g.ox, g.oy)).
 * All layers live in one patch-local frame (bbox of the outline + margin) so they can be cached and
 * reused while the design is dragged.
 */
export function paintPatch(c, o, g) {
  const { config, layout, design } = o;
  const look = g.look, ppm = g.ppm;
  const art = g.art;
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
  const F = { look, ppm, W, H, poly, pb, polyKey: g.polyKey, baseHex, fabricHex, edge, edgeHex, engraveHex, shadow: g.shadow, backdrop, rectL, hasBg: !!o.backgroundHex || !!backdrop };

  c.drawImage(underLayer(F, o), fx, fy);

  c.save();
  c.beginPath(); tracePath(c, transformPolygon(g.polyMm, ppm, g.ox, g.oy)); c.clip();
  if (art && art.image && rectL && rectL.w > 0 && rectL.h > 0) {
    const L = artLayer(art, look, layout.artRectMm, ppm, { reduced: o.reduced !== false, quality: o.quality, engraveHex });
    const sx = rectL.w / L.w, sy = rectL.h / L.h;
    const r = { x: fx + rectL.x - L.pad * sx, y: fy + rectL.y - L.pad * sy, w: rectL.w + 2 * L.pad * sx, h: rectL.h + 2 * L.pad * sy };
    if (look === 'sublimated') c.globalCompositeOperation = 'multiply';
    drawArtImage(c, null, r, design && design.art ? design.art.rotation : 0, L.canvas);
    c.globalCompositeOperation = 'source-over';
  }
  const texts = (design && design.texts || []).filter((t) => t && String(t.text || '').trim());
  if (texts.length) {
    const tg = { W, H, ppm, poly, cx: lx + (layout.patchWmm / 2) * ppm, cy: ly + (layout.patchHmm / 2) * ppm, polyKey: g.polyKey };
    for (const t of texts) {
      const hex = (o.textHex && o.textHex[t.id]) || (lum(baseHex) < 128 ? '#ffffff' : '#151515');
      const tl = textLayer(t, look, tg, { config, hex, edgeMm: edgeCoverMm(edge), quality: o.quality, engraveHex });
      if (!tl.ready) g.pendingFonts.push(fontFor(t, config));
      if (look === 'sublimated') c.globalCompositeOperation = 'multiply';
      c.drawImage(tl.canvas, fx, fy);
      c.globalCompositeOperation = 'source-over';
    }
  }
  c.restore();

  c.drawImage(overLayer(F, o), fx, fy);

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

let sceneBg = null;
function sceneBackground(c, W, H) {
  if (!sceneBg || sceneBg.width !== W || sceneBg.height !== H) {
    sceneBg = makeCanvas(W, H);
    const s = ctx2d(sceneBg);
    const g = s.createRadialGradient(W * 0.42, H * 0.35, Math.min(W, H) * 0.05, W / 2, H / 2, Math.max(W, H) * 0.75);
    g.addColorStop(0, '#ffffff'); g.addColorStop(1, '#e9eef3');
    s.fillStyle = g; s.fillRect(0, 0, W, H);
  }
  c.drawImage(sceneBg, 0, 0);
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
  const bb = bbox(P.polyMm);
  const extra = layout.edge && layout.edge.style === 'uncut' ? 5 : 1;
  const bx = bb.x - extra, by = bb.y - extra, bw = bb.w + 2 * extra, bh = bb.h + 2 * extra;

  if (view === 'garment') {
    const garment = opts.garment || (P.design.mockup && P.design.mockup !== 'none' ? P.design.mockup : 'polo_chest');
    const place = drawGarment(c, W, H, garment, config, { garmentHex: opts.garmentHex, transparent: opts.transparent, zoom: opts.zoom });
    const ppm = place.pxPerMm;
    // render the patch at garment scale (2× supersampled when tiny) into its own canvas
    const ss = ppm < 4 ? 2 : 1;
    const pw = Math.max(2, Math.ceil(bw * ppm * ss)), ph = Math.max(2, Math.ceil(bh * ppm * ss));
    const pc = makeCanvas(pw, ph), pctx = ctx2d(pc);
    paintPatch(pctx, o, { look: P.look, ppm: ppm * ss, ox: -bx * ppm * ss, oy: -by * ppm * ss, polyMm: P.polyMm, polyKey: P.polyKey, art: P.art, shadow: false, pendingFonts });
    const cx = place.cx + ((bx + bw / 2) - layout.patchWmm / 2) * ppm, cy = place.cy + ((by + bh / 2) - layout.patchHmm / 2) * ppm;
    placePatch(c, pc, Object.assign({}, place, { cx, cy }), bw * ppm, bh * ppm);
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
  if (!opts.transparent) sceneBackground(c, W, H);
  paintPatch(c, o, { look: P.look, ppm, ox, oy, polyMm: P.polyMm, polyKey: P.polyKey, art: P.art, shadow: !opts.transparent, pendingFonts });
  return { ms: now() - t0, pxPerMm: ppm, originPx: { x: ox, y: oy }, view };
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
