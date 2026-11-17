/**
 * Test page entry (bundled by harness.mjs, never part of the plugin bundle): exposes the render API plus
 * the real analysis/colors modules so browser tests render the owner's reference images end-to-end.
 */
import * as R from '../../plugin/borduur-patch-studio/assets/src/render/index.js';
import { analyzeImage } from '../../plugin/borduur-patch-studio/assets/src/analysis/index.js';
import { prepareThreads, reducePalette, pickFabric, pickEdgeColor } from '../../plugin/borduur-patch-studio/assets/src/colors/index.js';
import { layoutPatch } from '../../plugin/borduur-patch-studio/assets/src/shared/geometry.js';
import { buildPublicConfig, defaultOptions } from '../../plugin/borduur-patch-studio/assets/src/shared/config.js';

const T = { R, analyzeImage, layoutPatch, buildPublicConfig, defaultOptions, prepareThreads, reducePalette, pickFabric, pickEdgeColor };

/** Load an image URL and analyse it on the main thread (pure core). */
T.analyzeUrl = async function (url, config) {
  const blob = await (await fetch(url)).blob();
  const bmp = await createImageBitmap(blob);
  const s = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
  const c = new OffscreenCanvas(Math.round(bmp.width * s), Math.round(bmp.height * s));
  const x = c.getContext('2d');
  x.drawImage(bmp, 0, 0, c.width, c.height);
  const id = x.getImageData(0, 0, c.width, c.height);
  return analyzeImage({ width: id.width, height: id.height, data: id.data }, config, { job: 1, srcSize: { w: bmp.width, h: bmp.height } });
};

/** Build complete render options the way the UI would. */
T.makeOpts = function (config, analysis, o = {}) {
  const technique = o.technique || 'embroidered';
  const opts0 = T.defaultOptions(config, technique);
  const options = o.edge ? opts0.filter((id) => (config.options.find((x) => x.id === id) || {}).group !== 'edge').concat([o.edge]) : opts0;
  const shape = o.shape || (analysis && analysis.shape && analysis.shape.type) || 'circle';
  const design = {
    v: 1, technique, shape, aspect: o.aspect || (shape === 'circle' ? 1 : (analysis ? analysis.shape.aspect : 1.4)),
    sizeCm: o.sizeCm || 8, qty: 10, options, fabric: 'auto', edgeColor: 'auto', threadOverrides: {}, maxThreadColors: o.maxColors || 8,
    art: { scale: o.scale || 1, offsetMm: o.offsetMm || { x: 0, y: 0 }, rotation: o.rotation || 0 },
    texts: o.texts || [], mockup: o.garment || 'none', locale: 'nl-BE',
  };
  const layout = T.layoutPatch(design, analysis, config);
  const prepared = T.prepareThreads(config.threads);
  let reduced = null, fabricHex = o.fabricHex || '#1b2a4a', edgeHex = o.edgeHex || null, backgroundHex = null;
  if (analysis) {
    reduced = T.reducePalette(analysis.palette, design.maxThreadColors, { prepared, config });
    const f = T.pickFabric(analysis.palette, config.fabrics, config, { background: analysis.background });
    if (!o.fabricHex && f) fabricHex = f.hex;
    if (!edgeHex) { const e = T.pickEdgeColor(fabricHex, analysis.palette, prepared, config); edgeHex = e ? e.hex : '#222'; }
    if (analysis.background && analysis.background.keep) backgroundHex = analysis.background.hex;
  }
  if (technique === 'leather') fabricHex = o.fabricHex || config.leathers[0].hex;
  if (technique === 'sublimated' && !o.fabricHex) fabricHex = '#ffffff';
  const art = analysis ? R.artBundleFromAnalysis(analysis, reduced) : null;
  const textHex = {};
  for (const t of design.texts) textHex[t.id] = t.hex || '#ffffff';
  return { design, layout, art, fabricHex, backgroundHex, edgeHex: edgeHex || '#222222', textHex, view: o.view || 'patch', garment: o.garment,
    reduced: true, quality: o.quality || 'high', pxSize: o.pxSize || 1000, transparent: !!o.transparent, guides: o.guides || null, zoom: o.zoom, zoomCenterMm: o.zoomCenterMm };
};

window.T = T;
