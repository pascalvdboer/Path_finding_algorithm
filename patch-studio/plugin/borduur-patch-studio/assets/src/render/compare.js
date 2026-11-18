/**
 * Patch Studio — technique comparison: the SAME design rendered in every technique (small live renders for
 * the assistant's "techniekvergelijker"). Each technique gets a fitting border (the current one when the
 * technique offers it, else its default) and material (leather colour for leather), so every card shows
 * what the customer would really receive.
 */
import { drawScene, getDefaultConfig } from './renderer.js';
import { makeCanvas, ctx2d, now } from './util.js';
import { defaultOptions } from '../shared/config.js';

function edgeFor(config, tech, current) {
  const opts = (config.options || []).filter((o) => o.group === 'edge' && (o.techniques || []).includes(tech));
  let o = current && opts.find((x) => x.id === current);
  if (!o) {
    const def = defaultOptions(config, tech);
    o = opts.find((x) => def.includes(x.id)) || opts[0];
  }
  if (!o) return null;
  const r = o.render || { style: 'satin', widthMm: 2.5, safeZoneMm: 2 };
  return { id: o.id, style: r.style, widthMm: r.widthMm ?? 0, safeZoneMm: r.safeZoneMm ?? 0, fray: !!r.fray };
}

/**
 * Render one design in several techniques.
 * @param {Object} opts   RenderOptions as for draw() (design, layout, art, fabricHex, ...); view is forced to 'patch'
 * @param {{size?:number, techniques?:string[], config?:Object, supersample?:number, transparent?:boolean, light?:Object, scene?:string}} [o]
 * @returns {{technique:string, canvas:HTMLCanvasElement|OffscreenCanvas, edge:string|null, ms:number}[]}
 */
export function renderTechniques(opts, o = {}) {
  const config = o.config || opts.config || getDefaultConfig() || {};
  const size = Math.max(48, Math.round(o.size || 220));
  const ss = Math.max(1, Math.min(3, o.supersample || (size < 360 ? 2 : 1)));
  const order = o.techniques || config.techniqueOrder || Object.keys(config.techniques || {});
  const curEdge = opts.layout && opts.layout.edge && opts.layout.edge.id;
  const out = [];
  for (const tech of order) {
    if (config.techniques && !config.techniques[tech]) continue;
    const t0 = now();
    const edge = edgeFor(config, tech, curEdge);
    const layout = Object.assign({}, opts.layout, edge ? { edge } : {});
    const design = Object.assign({}, opts.design, { technique: tech });
    let fabricHex = opts.fabricHex;
    if (tech === 'leather') {
      const leathers = config.leathers || [];
      if (!leathers.some((l) => String(l.hex).toLowerCase() === String(fabricHex || '').toLowerCase())) fabricHex = (leathers[0] && leathers[0].hex) || '#8b5a2b';
    }
    const ro = Object.assign({}, opts, {
      technique: tech, design, layout, fabricHex, view: 'patch', quality: 'high', guides: null, zoom: 1,
      transparent: !!o.transparent, light: o.light || opts.light, scene: o.scene || opts.scene, _fit: 0.9,
    });
    const big = makeCanvas(size * ss, size * ss);
    drawScene(ctx2d(big), size * ss, size * ss, ro, config, []);
    let cv = big;
    if (ss > 1) {
      cv = makeCanvas(size, size);
      const c = ctx2d(cv);
      c.imageSmoothingEnabled = true;
      try { c.imageSmoothingQuality = 'high'; } catch (e) { /* older engines */ }
      c.drawImage(big, 0, 0, size, size);
    }
    out.push({ technique: tech, canvas: cv, edge: edge ? edge.id : null, ms: now() - t0 });
  }
  return out;
}
