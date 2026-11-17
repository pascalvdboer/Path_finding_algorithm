/**
 * Tiny deterministic rasteriser for analysis tests (no canvas in Node).
 * Shapes are anti-aliased by 4×4 supersampling; gradients and JPEG-like noise are supported.
 */
export const hex = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));

export function image(w, h, fill = '#ffffff', alpha = 255) {
  const data = new Uint8ClampedArray(w * h * 4);
  const [r, g, b] = typeof fill === 'string' ? hex(fill) : fill;
  for (let i = 0; i < w * h; i++) { data[i * 4] = r; data[i * 4 + 1] = g; data[i * 4 + 2] = b; data[i * 4 + 3] = alpha; }
  return { width: w, height: h, data };
}

/** Paint coverage function inside(x,y)->bool with colour (string|fn(x,y)->[r,g,b]) and optional alpha. */
export function paint(img, inside, color, { alpha = 255, bbox = null } = {}) {
  const { width: W, height: H, data } = img;
  const [x0, y0, x1, y1] = bbox || [0, 0, W, H];
  const col = typeof color === 'string' ? hex(color) : null;
  for (let y = Math.max(0, Math.floor(y0)); y < Math.min(H, Math.ceil(y1)); y++) {
    for (let x = Math.max(0, Math.floor(x0)); x < Math.min(W, Math.ceil(x1)); x++) {
      let c = 0;
      for (let sy = 0; sy < 4; sy++) for (let sx = 0; sx < 4; sx++) if (inside(x + (sx + 0.5) / 4, y + (sy + 0.5) / 4)) c++;
      if (!c) continue;
      const a = (c / 16) * (alpha / 255);
      const rgb = col || color(x + 0.5, y + 0.5);
      const i = (y * W + x) * 4, da = data[i + 3] / 255, oa = a + da * (1 - a);
      for (let k = 0; k < 3; k++) data[i + k] = oa ? (rgb[k] * a + data[i + k] * da * (1 - a)) / oa : 0;
      data[i + 3] = Math.round(oa * 255);
    }
  }
  return img;
}

export const circle = (cx, cy, r) => (x, y) => (x - cx) ** 2 + (y - cy) ** 2 <= r * r;
export const ellipse = (cx, cy, a, b, th = 0) => (x, y) => {
  const dx = x - cx, dy = y - cy, c = Math.cos(th), s = Math.sin(th);
  const u = dx * c + dy * s, v = -dx * s + dy * c;
  return (u / a) ** 2 + (v / b) ** 2 <= 1;
};
export const ring = (cx, cy, r0, r1) => (x, y) => { const d = (x - cx) ** 2 + (y - cy) ** 2; return d <= r1 * r1 && d >= r0 * r0; };
export const rect = (x0, y0, w, h) => (x, y) => x >= x0 && x <= x0 + w && y >= y0 && y <= y0 + h;
export const line = (x0, y0, x1, y1, width) => (x, y) => {
  const dx = x1 - x0, dy = y1 - y0, l2 = dx * dx + dy * dy;
  const t = Math.max(0, Math.min(1, ((x - x0) * dx + (y - y0) * dy) / l2));
  return Math.hypot(x - (x0 + t * dx), y - (y0 + t * dy)) <= width / 2;
};
export const shield = (x0, y0, w, h) => (x, y) => {
  const u = (x - x0) / w, v = (y - y0) / h;
  if (u < 0 || u > 1 || v < 0 || v > 1) return false;
  if (v <= 0.55) return true;
  const half = 0.5 * Math.sqrt(Math.max(0, 1 - ((v - 0.55) / 0.45) ** 2));
  return Math.abs(u - 0.5) <= half;
};

export function linearGradient(x0, y0, x1, y1, c0, c1) {
  const a = hex(c0), b = hex(c1), dx = x1 - x0, dy = y1 - y0, l2 = dx * dx + dy * dy;
  return (x, y) => { const t = Math.max(0, Math.min(1, ((x - x0) * dx + (y - y0) * dy) / l2)); return a.map((v, i) => v + (b[i] - v) * t); };
}
export function radialGradient(cx, cy, r, c0, c1) {
  const a = hex(c0), b = hex(c1);
  return (x, y) => { const t = Math.min(1, Math.hypot(x - cx, y - cy) / r); return a.map((v, i) => v + (b[i] - v) * t); };
}

/** Deterministic noise (±amp per channel), roughly what a medium JPEG adds. */
export function noise(img, amp, seed = 7) {
  let s = seed;
  const rnd = () => ((s = (Math.imul(s, 1103515245) + 12345) >>> 0) / 4294967296) * 2 - 1;
  for (let i = 0; i < img.data.length; i += 4) for (let k = 0; k < 3; k++) img.data[i + k] = img.data[i + k] + Math.round(rnd() * amp);
  return img;
}

/** 5×7 block font (uppercase subset) — strokes of uniform width, like a bold sans. */
const FONT = {
  A: ['01110', '10001', '10001', '11111', '10001', '10001', '10001'],
  B: ['11110', '10001', '10001', '11110', '10001', '10001', '11110'],
  C: ['01111', '10000', '10000', '10000', '10000', '10000', '01111'],
  D: ['11110', '10001', '10001', '10001', '10001', '10001', '11110'],
  I: ['11111', '00100', '00100', '00100', '00100', '00100', '11111'],
  E: ['11111', '10000', '10000', '11110', '10000', '10000', '11111'],
  K: ['10001', '10010', '10100', '11000', '10100', '10010', '10001'],
  L: ['10000', '10000', '10000', '10000', '10000', '10000', '11111'],
  M: ['10001', '11011', '10101', '10101', '10001', '10001', '10001'],
  N: ['10001', '11001', '10101', '10011', '10001', '10001', '10001'],
  O: ['01110', '10001', '10001', '10001', '10001', '10001', '01110'],
  R: ['11110', '10001', '10001', '11110', '10100', '10010', '10001'],
  S: ['01111', '10000', '10000', '01110', '00001', '00001', '11110'],
  T: ['11111', '00100', '00100', '00100', '00100', '00100', '00100'],
  U: ['10001', '10001', '10001', '10001', '10001', '10001', '01110'],
};

/** Draw text with the block font; capHeight in px; returns the text width. Optional rotation (radians) per glyph about its centre. */
export function text(img, str, x, y, capHeight, color, { spacing = 1.4 } = {}) {
  const cell = capHeight / 7;
  let cx = x;
  for (const ch of str) {
    const g = FONT[ch];
    if (g) {
      for (let r = 0; r < 7; r++) for (let c = 0; c < 5; c++) {
        if (g[r][c] !== '1') continue;
        const rx = cx + c * cell, ry = y + r * cell;
        paint(img, rect(rx, ry, cell, cell), color, { bbox: [rx - 1, ry - 1, rx + cell + 1, ry + cell + 1] });
      }
    }
    cx += 5 * cell + spacing * cell;
  }
  return cx - x;
}

/** Text along an arc (upper half of a circle), glyphs rotated to follow the circle. */
export function arcText(img, str, cx, cy, radius, capHeight, color, startDeg = -150, stepDeg = 14) {
  const cell = capHeight / 7;
  let k = 0;
  for (const ch of str) {
    const g = FONT[ch];
    const ang = ((startDeg + k * stepDeg) * Math.PI) / 180;
    k++;
    if (!g) continue;
    const gx = cx + radius * Math.cos(ang), gy = cy + radius * Math.sin(ang);
    const rot = ang + Math.PI / 2, c = Math.cos(rot), s = Math.sin(rot);
    const inside = (x, y) => {
      const dx = x - gx, dy = y - gy;
      const u = dx * c + dy * s + 2.5 * cell, v = -dx * s + dy * c + 3.5 * cell;
      const col = Math.floor(u / cell), row = Math.floor(v / cell);
      return row >= 0 && row < 7 && col >= 0 && col < 5 && g[row][col] === '1';
    };
    const R = 5 * cell;
    paint(img, inside, color, { bbox: [gx - R, gy - R, gx + R, gy + R] });
  }
}

/** Glyph bitmaps (read-only), for seam-free text in accuracy-cases.js. */
export const GLYPHS = FONT;
