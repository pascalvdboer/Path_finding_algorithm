/**
 * Patch Studio — file decoding on the MAIN THREAD (07 §3). This is the only analysis file that touches the DOM
 * (<img>, <canvas>); every DOM access is guarded so the module can still be imported in Node/Worker.
 *   raster → createImageBitmap(file, {imageOrientation:'from-image'}) (EXIF rotation), <img> fallback
 *   svg    → parse text, give the root explicit width/height (longest side sizes.svgRaster), rasterise via <img>
 *   pdf/ai/eps/psd/… → {kind:'unsupported', reason:'type'} (the file is still accepted and sent with the order)
 */
import { svgInfo } from './svg-info.js';

const RASTER_EXT = ['png', 'jpg', 'jpeg', 'jpe', 'webp', 'gif', 'avif', 'bmp', 'heic', 'heif'];
const MAX_CANVAS = 4096; // iOS Safari refuses bigger canvases (≈16.7 Mpx)

const hasDom = () => typeof document !== 'undefined' && typeof document.createElement === 'function';

/**
 * @param {File|Blob & {name?:string}} file
 * @param {Object} config public config (uses config.analysis.maxFileMb / acceptAnalyse / sizes.svgRaster)
 * @returns {Promise<{kind:'raster'|'svg'|'unsupported', ext:string, bitmap?:ImageBitmap, imageData?:ImageData,
 *   svgText?:string, reason?:'type'|'size'|'decode'}>}
 */
export async function decodeFile(file, config) {
  const A = (config && config.analysis) || {};
  const name = String((file && file.name) || '');
  const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
  const type = String((file && file.type) || '').toLowerCase();
  if (!file || typeof file.size !== 'number') return { kind: 'unsupported', ext, reason: 'decode' };
  if (file.size > (Number(A.maxFileMb) || 25) * 1024 * 1024) return { kind: 'unsupported', ext, reason: 'size' };

  if (type === 'image/svg+xml' || ext === 'svg') {
    try {
      const svgText = await readText(file);
      const out = await rasterizeSvg(svgText, (A.sizes && A.sizes.svgRaster) || 2048);
      return { kind: 'svg', ext: 'svg', svgText, ...out };
    } catch (e) {
      return { kind: 'unsupported', ext: 'svg', reason: 'decode' };
    }
  }

  const accept = Array.isArray(A.acceptAnalyse) ? A.acceptAnalyse : ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
  const raster = (type && accept.includes(type)) || RASTER_EXT.includes(ext) || (type.startsWith('image/') && !/(photoshop|postscript|pdf)/.test(type));
  if (!raster) return { kind: 'unsupported', ext, reason: 'type' };

  if (typeof createImageBitmap === 'function') {
    try { return { kind: 'raster', ext, bitmap: await createImageBitmap(file, { imageOrientation: 'from-image' }) }; } catch (e) { /* try below */ }
    try { return { kind: 'raster', ext, bitmap: await createImageBitmap(file) }; } catch (e) { /* <img> fallback */ }
  }
  try {
    const img = await loadImage(file);
    return { kind: 'raster', ext, ...(await fromDrawable(img, img.naturalWidth, img.naturalHeight)) };
  } catch (e) {
    return { kind: 'unsupported', ext, reason: 'decode' };
  }
}

function readText(file) {
  if (typeof file.text === 'function') return file.text();
  return new Promise((ok, no) => { const r = new FileReader(); r.onload = () => ok(String(r.result)); r.onerror = no; r.readAsText(file); });
}

function loadImage(blob) {
  if (!hasDom()) return Promise.reject(new Error('no_dom'));
  return new Promise((ok, no) => {
    const url = URL.createObjectURL(blob), img = new Image();
    const done = (fn, v) => { URL.revokeObjectURL(url); fn(v); };
    img.onload = () => { (img.decode ? img.decode().catch(() => {}) : Promise.resolve()).then(() => done(ok, img)); };
    img.onerror = () => done(no, new Error('decode'));
    img.src = url;
  });
}

/** Draw any drawable into a canvas (≤ MAX_CANVAS) → ImageBitmap (preferred) or ImageData. */
async function fromDrawable(src, w, h, maxSide = MAX_CANVAS) {
  if (!w || !h) throw new Error('decode');
  const k = Math.min(1, maxSide / Math.max(w, h));
  const cw = Math.max(1, Math.round(w * k)), ch = Math.max(1, Math.round(h * k));
  const c = makeCanvas(cw, ch), x = c.getContext('2d', { willReadFrequently: true });
  x.imageSmoothingQuality = 'high';
  x.drawImage(src, 0, 0, cw, ch);
  if (typeof createImageBitmap === 'function') {
    try { return { bitmap: await createImageBitmap(c) }; } catch (e) { /* ImageData below */ }
  }
  return { imageData: x.getImageData(0, 0, cw, ch) };
}

function makeCanvas(w, h) {
  if (typeof OffscreenCanvas !== 'undefined') return new OffscreenCanvas(w, h);
  if (!hasDom()) throw new Error('no_canvas');
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  return c;
}

/**
 * SVG → bitmap on the main thread. createImageBitmap(svgBlob) fails in browsers (and in workers), and an SVG with
 * only a viewBox has naturalWidth 0 in Firefox: give the root explicit width/height first (07 §3.2).
 * An SVG inside <img> runs no scripts and loads no external resources.
 */
export async function rasterizeSvg(svgText, longest = 2048) {
  const info = svgInfo(svgText) || {};
  let w = info.width || 0, h = info.height || 0;
  if (!w || !h) { w = 1; h = 1; }
  const k = longest / Math.max(w, h);
  const W = Math.max(1, Math.round(w * k)), H = Math.max(1, Math.round(h * k));
  let text = svgText.replace(/<svg\b([^>]*)>/i, (m, attrs) => {
    let a = attrs.replace(/\s(width|height)\s*=\s*(["'])[^"']*\2/gi, '');
    if (!/\sviewBox\s*=/i.test(a) && info.width && info.height) a += ` viewBox="0 0 ${info.width} ${info.height}"`;
    if (!/\sxmlns\s*=/i.test(a)) a += ' xmlns="http://www.w3.org/2000/svg"';
    return `<svg${a} width="${W}" height="${H}">`;
  });
  if (!/<svg\b/i.test(text)) throw new Error('not_svg');
  const blob = new Blob([text], { type: 'image/svg+xml' });
  const img = await loadImage(blob);
  return fromDrawable(img, W, H, Math.max(W, H));
}

/**
 * ImageBitmap → ImageData on the main thread (for a worker without OffscreenCanvas, old Safari).
 * @returns {{width:number,height:number,data:Uint8ClampedArray}}
 */
export function bitmapToImageData(bitmap, maxSide = 2048) {
  const k = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
  const w = Math.max(1, Math.round(bitmap.width * k)), h = Math.max(1, Math.round(bitmap.height * k));
  const c = makeCanvas(w, h), x = c.getContext('2d', { willReadFrequently: true });
  x.imageSmoothingQuality = 'high';
  x.drawImage(bitmap, 0, 0, w, h);
  const id = x.getImageData(0, 0, w, h);
  return { width: w, height: h, data: id.data };
}
