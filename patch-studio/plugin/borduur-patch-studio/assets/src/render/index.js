/**
 * Patch Studio — render module public API (SPEC §7.6, v2 "Rendering" in docs/STUDIO-REDESIGN.md,
 * full reference in docs/handoff/render-v2.md). Import only from here.
 *
 *   createRenderer(canvas, { config, onRedraw? })
 *       → { draw(opts): RenderResult, toBlob(opts, {size=1200, type='image/png'}): Promise<Blob>, dispose(),
 *           prewarm(opts, techniques) (extra: builds other techniques' layers in idle time) }
 *   renderThumb(opts, size)          → canvas (comparison view, technique cards). opts.config optional
 *                                      (defaults to the config of the last created renderer).
 *   artFromAnalysis(analysis)        → canvas built from analysis.preview (cached per analysis/job)
 *
 *   renderTechniques(opts, {size, techniques?, light?}) → [{technique, canvas, edge, ms}]  (v2: the same design
 *                                      in every technique, for the technique comparer)
 *   lightFromPointer(event, element)  → {x, y} for opts.light (v2: glint follows the pointer)
 *
 * Extra exports: artBundleFromAnalysis(analysis, reduced?) → full RenderArt (+backdrop), describeRender(),
 * renderMessages (NL/FR catalogue 'render.*'), patchPolygonMm(), GARMENTS, SCENE_KINDS, normLight,
 * DEFAULT_LIGHT, setCanvasFactory (tests).
 *
 * v2 RenderOptions (all optional, existing options unchanged):
 *   quality: 'fast' | 'high'   fast = reuse cached layers at any size (drag/zoom/pointer light), ≤ 25 ms;
 *                              high = full material pass, ≤ 150 ms at 1200 px once the design is analysed
 *   light: {x, y} | {azimuthDeg, elevationDeg}   light direction (default from the top left)
 *   scene: 'studio' | 'linen' | 'slate'          close-up background
 *   pvcDepth: '2d' | '3d'                         PVC moulding depth (also read from design.pvcDepth)
 *   garment: 'polo_chest' | 'cap_front' | 'jacket_back' | 'backpack_front'
 *
 * INTEGRATION NOTES
 * 1. RenderOptions extras (all optional): zoom (1..8) + zoomCenterMm {x,y} for a close-up loupe; config (renderThumb).
 *    RenderResult extras: view, garment.
 * 2. art: pass `artBundleFromAnalysis(analysis, reduced)` or at least { image: artFromAnalysis(analysis) } —
 *    labels, palette and the drawn backdrop (analysis.backdrop when background.keep) are found automatically
 *    from the image, so a drawn file background continues to the patch edge.
 * 3. Thread looks (embroidered/woven/chenille/pvc) colour each label with art.reducedHex[art.reducedMap[label]]
 *    when opts.reduced !== false and both arrays exist, else with art.paletteHex[label].
 * 4. Fonts: if a text font is not loaded yet the renderer starts document.fonts.load() and redraws itself
 *    (last opts) once it is ready; toBlob() always waits for the fonts.
 * 5. Arc text: insetMm is clamped so letters stay inside the border (edge width + cap height for arc_top).
 * 6. Performance: geometry (light independent) and lit layers (per light) are cached; while dragging, zooming
 *    or moving the light with the pointer pass quality:'fast' (reuses cached layers, relights only the design
 *    and border), and quality:'high' when the gesture ends.
 */
import { drawScene, exportSize, canvasToBlob, fontsFor, loadFont, registerBundle, setDefaultConfig, getDefaultConfig, patchPolygonMm } from './renderer.js';
import { makeCanvas, ctx2d, setCanvasFactory } from './util.js';
import { GARMENTS } from './garment.js';
import { renderTechniques } from './compare.js';
import { normLight, DEFAULT_LIGHT } from './light.js';
import { SCENE_KINDS } from './scene.js';
import messages from './messages.js';
import { createT } from '../shared/i18n.js';
import { label } from '../shared/config.js';

export { patchPolygonMm, GARMENTS, setCanvasFactory, renderTechniques, normLight, DEFAULT_LIGHT, SCENE_KINDS };

/**
 * Light direction from a pointer position over an element (centre = light from straight above, edges =
 * low raking light from that side). Pass the result as opts.light.
 * @param {{clientX:number, clientY:number}} ev
 * @param {Element|{getBoundingClientRect:Function}} el
 * @param {number} [strength=1]  0..1, how far the light may swing (0.6 = subtle)
 */
export function lightFromPointer(ev, el, strength = 1) {
  const r = el.getBoundingClientRect();
  const x = ((ev.clientX - r.left) / Math.max(1, r.width)) * 2 - 1, y = ((ev.clientY - r.top) / Math.max(1, r.height)) * 2 - 1;
  const k = Math.max(0, Math.min(1, strength));
  // blend with the default light so the patch never looks lit from below unless the pointer is far down
  return { x: DEFAULT_LIGHT.x * (1 - k) + x * k, y: DEFAULT_LIGHT.y * (1 - k) + y * k };
}
export const renderMessages = messages;

function sizeCanvas(canvas, px) {
  const w0 = canvas.width || 0, h0 = canvas.height || 0;
  const ar = w0 > 0 && h0 > 0 ? h0 / w0 : 1;
  let W, H;
  if (ar <= 1) { W = px; H = Math.max(1, Math.round(px * ar)); } else { H = px; W = Math.max(1, Math.round(px / ar)); }
  if (canvas.width !== W) canvas.width = W;
  if (canvas.height !== H) canvas.height = H;
  return { W, H };
}

/**
 * @param {HTMLCanvasElement|OffscreenCanvas} canvas
 * @param {{config:Object, onRedraw?:(r:Object)=>void}} options
 */
export function createRenderer(canvas, { config, onRedraw } = {}) {
  setDefaultConfig(config);
  let disposed = false, last = null, waiting = false;
  const c = ctx2d(canvas);

  function draw(opts) {
    if (disposed || !opts) return { ms: 0, pxPerMm: 0, originPx: { x: 0, y: 0 } };
    last = opts;
    const px = Math.max(16, Math.round(Number(opts.pxSize) || canvas.width || 800));
    const { W, H } = sizeCanvas(canvas, px);
    const pending = [];
    const res = drawScene(c, W, H, opts, config, pending);
    if (pending.length && !waiting) {
      waiting = true;
      Promise.all(pending.map(loadFont)).then(() => {
        waiting = false;
        if (!disposed && last) {
          const r = draw(last);
          if (onRedraw) onRedraw(r);
        }
      });
    }
    return res;
  }

  async function toBlob(opts, { size = 1200, type = 'image/png' } = {}) {
    size = Math.max(600, Math.round(size));
    await Promise.all(fontsFor(opts, config).map(loadFont));
    const o = Object.assign({}, opts, { view: 'patch', transparent: true, quality: 'high', guides: null, zoom: 1, _fit: 0.97 });
    const { w, h } = exportSize(o, config, size);
    const cv = makeCanvas(w, h);
    drawScene(ctx2d(cv), w, h, o, config, []);
    return canvasToBlob(cv, type);
  }

  function dispose() { disposed = true; last = null; }

  /**
   * Build the cached layers for other techniques in idle time (one technique per idle slot), so a later
   * technique switch renders from cache. Call after the first draw of a new design.
   * @param {Object} opts          the RenderOptions just drawn
   * @param {string[]} techniques  e.g. advice.ranking.slice(0, 3)
   */
  function prewarm(opts, techniques) {
    const list = (techniques || []).slice();
    const idle = typeof requestIdleCallback === 'function'
      ? (fn) => requestIdleCallback(fn, { timeout: 1500 }) : (fn) => setTimeout(fn, 40);
    const W = canvas.width || 800, H = canvas.height || 800;
    const step = () => {
      if (disposed || !list.length || last !== opts) return;
      const t = list.shift();
      try {
        const cv = makeCanvas(W, H);
        drawScene(ctx2d(cv), W, H, Object.assign({}, opts, { technique: t, quality: 'high' }), config, []);
      } catch (e) { /* warming is best effort */ }
      if (list.length) idle(step);
    };
    if (list.length) idle(step);
  }

  return { draw, toBlob, dispose, prewarm };
}

/** Small standalone render (comparison view, technique cards). */
export function renderThumb(opts, size) {
  const config = (opts && opts.config) || getDefaultConfig() || {};
  const s = Math.max(32, Math.round(Number(size) || 240));
  const cv = makeCanvas(s, s);
  drawScene(ctx2d(cv), s, s, Object.assign({ quality: 'high' }, opts, { pxSize: s }), config, []);
  return cv;
}

const artCanvases = new WeakMap();

function imageDataCanvas(px) {
  const cv = makeCanvas(px.width, px.height), c = ctx2d(cv);
  const id = c.createImageData(px.width, px.height);
  id.data.set(px.data);
  c.putImageData(id, 0, 0);
  return cv;
}

/** Canvas from analysis.preview, cached per analysis object. */
export function artFromAnalysis(analysis) {
  if (!analysis || !analysis.preview || !analysis.preview.width) return null;
  let cv = artCanvases.get(analysis);
  if (cv) return cv;
  cv = imageDataCanvas(analysis.preview);
  artCanvases.set(analysis, cv);
  const backdrop = analysis.backdrop && analysis.backdrop.width
    ? { image: imageDataCanvas(analysis.backdrop), artBox: analysis.backdrop.artBox } : null;
  registerBundle(cv, {
    image: cv,
    labels: analysis.labels ? { width: analysis.preview.width, height: analysis.preview.height, data: analysis.labels } : null,
    paletteHex: (analysis.palette || []).map((p) => p.hex),
    reducedMap: null, reducedHex: null, backdrop,
  });
  return cv;
}

/**
 * Full RenderArt for an analysis (+ optional ReducedPalette from colors.reducePalette).
 * @param {Object} analysis
 * @param {{map:number[], colors:{hex:string, thread?:{thread?:{hex:string}}}[]}|null} [reduced]
 */
export function artBundleFromAnalysis(analysis, reduced = null) {
  const image = artFromAnalysis(analysis);
  if (!image) return null;
  const art = {
    image,
    labels: analysis.labels ? { width: analysis.preview.width, height: analysis.preview.height, data: analysis.labels } : null,
    paletteHex: (analysis.palette || []).map((p) => p.hex),
    reducedMap: null, reducedHex: null,
    backdrop: analysis.backdrop && analysis.backdrop.width ? undefined : null,
  };
  if (reduced && reduced.map && reduced.colors) {
    art.reducedMap = reduced.map.slice();
    art.reducedHex = reduced.colors.map((c) => (c.thread && c.thread.thread && c.thread.thread.hex) || c.hex);
  }
  if (art.backdrop === undefined) delete art.backdrop; // resolved from the image bundle
  return art;
}

function fmtCm(mm) {
  const v = Math.round(mm) / 10;
  return (Number.isInteger(v) ? String(v) : v.toFixed(1)).replace('.', ',');
}

/**
 * Text description of a render for <canvas role="img" aria-label>.
 * @param {Object} opts     RenderOptions (design, layout, view, garment)
 * @param {Object} config
 * @param {string} [locale]
 */
export function describeRender(opts, config, locale) {
  const loc = locale || (opts.design && opts.design.locale) || config.locale || 'nl-BE';
  const t = createT([messages], loc, (config.strings && config.strings[loc]) || {});
  const L = opts.layout;
  const d = opts.design || {};
  if (!L || (!opts.art && !(d.texts || []).some((x) => x && String(x.text || '').trim()))) return t('render.alt.empty');
  const tech = config.techniques && config.techniques[opts.technique || d.technique];
  const shape = config.shapes && config.shapes[L.shape];
  const edgeOpt = (config.options || []).find((x) => x.id === (L.edge && L.edge.id));
  const p = {
    technique: tech ? label(tech.label, loc).toLowerCase() : (opts.technique || d.technique || ''),
    shape: shape ? label(shape.label, loc).toLowerCase() : L.shape,
    w: fmtCm(L.patchWmm), h: fmtCm(L.patchHmm),
    edge: edgeOpt ? label(edgeOpt.label, loc).toLowerCase() : '',
  };
  let s;
  if (opts.view === 'garment') {
    const g = opts.garment || d.mockup || 'polo_chest';
    s = t('render.alt.garment', Object.assign(p, { garment: t('render.garment.' + g) }));
  } else s = t(p.edge ? 'render.alt.patch' : 'render.alt.patchNoEdge', p);
  const texts = (d.texts || []).filter((x) => x && String(x.text || '').trim());
  if (texts.length) s += ' ' + t('render.alt.texts', { n: texts.length, list: texts.map((x) => '“' + x.text + '”').join(', ') });
  return s;
}
