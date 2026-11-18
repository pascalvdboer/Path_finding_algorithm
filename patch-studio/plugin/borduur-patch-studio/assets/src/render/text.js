/**
 * Patch Studio — text layers: straight text and text following the patch outline (arc top / arc bottom).
 * heightMm is the cap height. insetMm = distance from the outer edge to the baseline; it is clamped so that
 * letters never run into the border (BRIEF: everything stays inside the edge).
 */
import { offsetPolygon, measure, pointAt, rayArcLength, bbox } from './shapes.js';

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

function inside(p, x, y) {
  let c = false;
  for (let i = 0, j = p.length - 1; i < p.length; j = i++) {
    const a = p[i], b = p[j];
    if ((a[1] > y) !== (b[1] > y) && x < ((b[0] - a[0]) * (y - a[1])) / (b[1] - a[1]) + a[0]) c = !c;
  }
  return c;
}

/**
 * Guide line for arc text. Round patches: the outline itself. Other shapes (rect, shield, contour): a gentle
 * circular arc (radius 0.7 × the longest side) that touches the top (or bottom) of the patch and is moved inward
 * until the part under the text lies inside the outline. Following a rectangle's outline would wrap the letters
 * around its corners; a small inscribed circle (demo-v2) bends long words too much on wide patches.
 * @param {number[][]} poly  patch outline (px, clockwise)
 * @param {boolean} top
 * @param {number} halfLen   half the text length along the guide (px)
 */
function arcGuide(poly, shape, top, halfLen) {
  if (!shape || shape === 'circle' || shape === 'oval') return poly;
  const b = bbox(poly);
  const cx = b.x + b.w / 2;
  const R = Math.max(b.w, b.h) * 0.7;
  const want = Math.min(Math.PI * 0.9, (halfLen * 1.1 + 2) / R); // half angle under the text
  const n = 160;
  const circle = (cy) => {
    const out = [];
    for (let i = 0; i < n; i++) { const a = -Math.PI / 2 + (2 * Math.PI * i) / n; out.push([cx + R * Math.cos(a), cy + R * Math.sin(a)]); }
    return out;
  };
  const ok = (cy, span) => {
    for (let i = 0; i <= 24; i++) {
      const a = (top ? -Math.PI / 2 : Math.PI / 2) + span * (i / 12 - 1);
      if (!inside(poly, cx + R * Math.cos(a), cy + R * Math.sin(a))) return false;
    }
    return true;
  };
  const step = Math.max(0.5, b.h / 200);
  for (let d = 0.5; d < b.h / 2; d += step) {
    const cy = top ? b.y + d + R : b.y + b.h - d - R;
    if (ok(cy, want)) return circle(cy);
  }
  // Text longer than the patch is wide: keep the arc along the edge (the ends run out of the patch, as straight
  // text would; the UI says the text is too long) instead of wrapping letters around the corners.
  for (let d = 0.5; d < b.h / 2; d += step) {
    const cy = top ? b.y + d + R : b.y + b.h - d - R;
    if (ok(cy, 0.05)) return circle(cy);
  }
  return poly;
}

/**
 * Draw one text layer.
 * @param {CanvasRenderingContext2D} c
 * @param {object} layer      TextLayer
 * @param {object} g          {poly (px, patch outline), shape?, cx, cy (px, patch centre), ppm, edgeMm, config, hex}
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
  // optional outline (contour) in a contrasting thread: stroked first for every letter, then the letters on top
  const ol = g.outline && g.outline.hex && g.outline.mm > 0 ? g.outline : null;
  const passes = ol ? ['stroke', 'fill'] : ['fill'];
  if (ol) { c.lineJoin = 'round'; c.miterLimit = 2; c.lineWidth = 2 * ol.mm * g.ppm; c.strokeStyle = ol.hex; }
  const put = (pass, ch, x, y) => (pass === 'stroke' ? c.strokeText(ch, x, y) : c.fillText(ch, x, y));
  if (path === 'straight' || !g.poly || g.poly.length < 3) {
    const off = layer.offsetMm || { x: 0, y: 0 };
    const x = g.cx + (Number(off.x) || 0) * g.ppm, y = g.cy + (Number(off.y) || 0) * g.ppm + (capMm * g.ppm) / 2;
    for (const pass of passes) {
      if (!spacing) put(pass, text, x, y);
      else {
        let acc = x - total / 2;
        chars.forEach((ch, i) => { put(pass, ch, acc + widths[i] / 2, y); acc += widths[i] + spacing; });
      }
    }
    return { mode: 0, cos: Math.cos(0.3), sin: Math.sin(0.3), cx: x, cy: y, r: 1 };
  }
  const top = path === 'arc_top';
  const minInset = (g.edgeMm || 0) + (top ? capMm + 0.5 : 0.7);
  const inset = Math.max(Number(layer.insetMm) || 0, minInset) * g.ppm;
  const base = offsetPolygon(arcGuide(g.poly, g.shape, top, total / 2), inset);
  const m = measure(base);
  const ang = ((top ? -90 : 90) + (Number(layer.angleDeg) || 0)) * Math.PI / 180;
  const s0 = rayArcLength(base, m, g.cx, g.cy, ang);
  for (const pass of passes) {
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
      put(pass, ch, 0, 0);
      c.restore();
    });
  }
  // radial stitches around the patch centre (threads perpendicular to the baseline)
  return { mode: 1, cos: 1, sin: 0, cx: g.cx, cy: g.cy, r: Math.max(4, m.total / (2 * Math.PI)) };
}
