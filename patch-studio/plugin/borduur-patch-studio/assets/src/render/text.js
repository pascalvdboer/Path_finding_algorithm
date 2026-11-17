/**
 * Patch Studio — text layers: straight text and text following the patch outline (arc top / arc bottom).
 * heightMm is the cap height. insetMm = distance from the outer edge to the baseline; it is clamped so that
 * letters never run into the border (BRIEF: everything stays inside the edge).
 */
import { offsetPolygon, measure, pointAt, rayArcLength } from './shapes.js';

const CAP = 0.71; // typical cap height / font size ratio of the bundled fonts

export function fontFor(layer, config) {
  const fonts = (config && config.fonts) || [];
  return fonts.find((f) => f.id === layer.font) || fonts[0] || { id: 'Sora', weight: 700, fallback: 'sans-serif' };
}

export function fontString(f, px) {
  return `${f.weight || 400} ${Math.max(1, px).toFixed(2)}px "${f.id}", ${f.fallback || 'sans-serif'}`;
}

/** Is the font usable right now? (true outside the browser so tests never block.) */
export function fontReady(f) {
  try {
    if (typeof document === 'undefined' || !document.fonts || !document.fonts.check) return true;
    return document.fonts.check(fontString(f, 20));
  } catch (e) { return true; }
}

export function loadFont(f) {
  try {
    if (typeof document === 'undefined' || !document.fonts || !document.fonts.load) return Promise.resolve();
    return document.fonts.load(fontString(f, 20)).catch(() => {});
  } catch (e) { return Promise.resolve(); }
}

/**
 * Draw one text layer.
 * @param {CanvasRenderingContext2D} c
 * @param {object} layer      TextLayer
 * @param {object} g          {poly (px, patch outline), cx, cy (px, patch centre), ppm, edgeMm, config, hex}
 * @returns {{mode:number, cos:number, sin:number, cx:number, cy:number, r:number}} stitch field for the text
 */
export function drawTextLayer(c, layer, g) {
  const text = String(layer.text || '').slice(0, 60);
  const f = fontFor(layer, g.config);
  const capMm = Math.max(1, Number(layer.heightMm) || 6);
  const px = (capMm * g.ppm) / CAP;
  c.font = fontString(f, px);
  c.fillStyle = g.hex || '#ffffff';
  c.textAlign = 'center';
  c.textBaseline = 'alphabetic';
  const spacing = (Number(layer.letterSpacing) || 0) * px;
  const chars = Array.from(text);
  const widths = chars.map((ch) => c.measureText(ch).width);
  const total = widths.reduce((a, b) => a + b, 0) + spacing * Math.max(0, chars.length - 1);
  const path = layer.path || 'straight';
  if (path === 'straight' || !g.poly || g.poly.length < 3) {
    const off = layer.offsetMm || { x: 0, y: 0 };
    const x = g.cx + (Number(off.x) || 0) * g.ppm, y = g.cy + (Number(off.y) || 0) * g.ppm + (capMm * g.ppm) / 2;
    if (!spacing) c.fillText(text, x, y);
    else {
      let acc = x - total / 2;
      chars.forEach((ch, i) => { c.fillText(ch, acc + widths[i] / 2, y); acc += widths[i] + spacing; });
    }
    return { mode: 0, cos: Math.cos(0.3), sin: Math.sin(0.3), cx: x, cy: y, r: 1 };
  }
  const top = path === 'arc_top';
  const minInset = (g.edgeMm || 0) + (top ? capMm + 0.5 : 0.7);
  const inset = Math.max(Number(layer.insetMm) || 0, minInset) * g.ppm;
  const base = offsetPolygon(g.poly, inset);
  const m = measure(base);
  const ang = ((top ? -90 : 90) + (Number(layer.angleDeg) || 0)) * Math.PI / 180;
  const s0 = rayArcLength(base, m, g.cx, g.cy, ang);
  let acc = 0;
  chars.forEach((ch, i) => {
    const mid = acc + widths[i] / 2;
    acc += widths[i] + spacing;
    const s = top ? s0 - total / 2 + mid : s0 + total / 2 - mid;
    const q = pointAt(base, m, s);
    const rot = top ? Math.atan2(q.ty, q.tx) : Math.atan2(-q.ty, -q.tx);
    c.save();
    c.translate(q.x, q.y);
    c.rotate(rot);
    c.fillText(ch, 0, 0);
    c.restore();
  });
  // radial stitches around the patch centre (threads perpendicular to the baseline)
  return { mode: 1, cos: 1, sin: 0, cx: g.cx, cy: g.cy, r: Math.max(4, m.total / (2 * Math.PI)) };
}
