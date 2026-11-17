/**
 * Patch Studio — patch borders drawn along the outline polygon (px, clockwise):
 * satin (wrap stitch, optional fraying), merrow (thick overlocked tube), laser (clean/burnt cut),
 * molded (PVC rim), felt (felt border band), uncut (satin + dashed cutting line on a fabric piece).
 */
import { offsetPolygon, tracePath, measure, pointAt } from './shapes.js';
import { shade, rgba, lum, prng } from './util.js';
import { overlayTexture } from './textures.js';

const LIGHT_X = -0.7071, LIGHT_Y = -0.7071;

function strokePoly(c, p, width, style) {
  c.beginPath(); tracePath(c, p);
  c.lineWidth = width; c.strokeStyle = style; c.stroke();
}

/**
 * Wrap stitches across a band centred on `mid`. Lines are bucketed by orientation so the sheen
 * follows the light direction around the patch.
 */
function wrapStitches(c, mid, bw, spacing, slant, color, gapAlpha, hiAlpha) {
  const m = measure(mid);
  const n = Math.max(12, Math.floor(m.total / spacing));
  const step = m.total / n;
  const B = 8, buckets = [];
  for (let i = 0; i < B; i++) buckets.push([]);
  for (let i = 0; i < n; i++) {
    const q = pointAt(mid, m, i * step);
    const nx = -q.ty, ny = q.tx; // inward normal
    const facing = -(nx * LIGHT_X + ny * LIGHT_Y); // outward normal · light
    const b = Math.max(0, Math.min(B - 1, Math.floor(((facing + 1) / 2) * B)));
    buckets[b].push(q);
  }
  const half = bw / 2;
  c.lineCap = 'butt';
  // dark gaps between wraps
  c.beginPath();
  for (const bucket of buckets) for (const q of bucket) {
    const nx = -q.ty, ny = q.tx, sx = q.tx * slant, sy = q.ty * slant;
    c.moveTo(q.x - nx * half - sx, q.y - ny * half - sy);
    c.lineTo(q.x + nx * half + sx, q.y + ny * half + sy);
  }
  c.lineWidth = Math.max(0.6, spacing * 0.3);
  c.strokeStyle = `rgba(0,0,0,${gapAlpha})`;
  c.stroke();
  // sheen on the thread crowns, stronger where the edge faces the light
  const light = lum(color) > 200 ? 0.5 : 1;
  for (let b = 0; b < B; b++) {
    if (!buckets[b].length) continue;
    c.beginPath();
    for (const q of buckets[b]) {
      const nx = -q.ty, ny = q.tx, sx = q.tx * slant, sy = q.ty * slant;
      const ox = q.tx * spacing * 0.5, oy = q.ty * spacing * 0.5;
      c.moveTo(q.x + ox - nx * half * 0.7 - sx * 0.7, q.y + oy - ny * half * 0.7 - sy * 0.7);
      c.lineTo(q.x + ox + nx * half * 0.55 + sx * 0.55, q.y + oy + ny * half * 0.55 + sy * 0.55);
    }
    c.lineWidth = Math.max(0.5, spacing * 0.24);
    c.strokeStyle = `rgba(255,255,255,${(hiAlpha * (0.25 + 0.75 * (b / (B - 1))) * light).toFixed(3)})`;
    c.stroke();
  }
}

function fray(c, outer, ppm, fabricHex, seed) {
  const m = measure(outer), rnd = prng(seed);
  const n = Math.floor(m.total / (ppm * 0.6));
  c.beginPath();
  for (let i = 0; i < n; i++) {
    if (rnd() < 0.55) continue;
    const q = pointAt(outer, m, rnd() * m.total);
    const nx = q.ty, ny = -q.tx; // outward
    const len = ppm * (0.25 + rnd() * 0.7), tw = (rnd() - 0.5) * 0.9;
    c.moveTo(q.x - nx * ppm * 0.15, q.y - ny * ppm * 0.15);
    c.lineTo(q.x + (nx + q.tx * tw) * len, q.y + (ny + q.ty * tw) * len);
  }
  c.lineWidth = Math.max(0.5, ppm * 0.08);
  c.strokeStyle = rgba(shade(fabricHex, lum(fabricHex) < 90 ? 0.12 : -0.08), 0.8);
  c.stroke();
}

/**
 * @param {CanvasRenderingContext2D} c
 * @param {number[][]} poly      outline in canvas px (clockwise)
 * @param {number} ppm           px per mm
 * @param {{style:string, widthMm:number, fray?:boolean}} edge
 * @param {{edgeHex:string, fabricHex:string, technique:string, quality:string, seed:number, engraveHex?:string}} o
 */
export function drawEdge(c, poly, ppm, edge, o) {
  const style = edge.style || 'satin';
  const ehex = o.edgeHex || '#222222';
  const fast = o.quality === 'fast';
  c.save();
  c.lineJoin = 'round';
  if (style === 'satin' || style === 'uncut') {
    const bw = Math.max(2, (edge.widthMm || 2.5) * ppm);
    const mid = offsetPolygon(poly, bw / 2);
    c.save();
    c.shadowColor = 'rgba(0,0,0,0.38)'; c.shadowBlur = bw * 0.45; c.shadowOffsetX = bw * 0.08; c.shadowOffsetY = bw * 0.16;
    strokePoly(c, mid, bw, ehex);
    c.restore();
    strokePoly(c, mid, bw * 0.5, rgba('#ffffff', lum(ehex) > 200 ? 0.04 : 0.09));
    if (!fast) wrapStitches(c, mid, bw, Math.max(1.6, ppm * 0.38), bw * 0.28, ehex, 0.26, 0.26);
    strokePoly(c, poly, Math.max(0.6, bw * 0.1), 'rgba(0,0,0,0.28)');
    strokePoly(c, offsetPolygon(poly, bw), Math.max(0.6, bw * 0.08), 'rgba(0,0,0,0.22)');
    if (style === 'satin' && edge.fray && !fast) fray(c, poly, ppm, o.fabricHex, o.seed);
    if (style === 'uncut') {
      c.setLineDash([ppm * 1.2, ppm * 0.9]);
      strokePoly(c, offsetPolygon(poly, -ppm * 1.6), Math.max(1, ppm * 0.18), lum(o.fabricHex) < 110 ? 'rgba(255,255,255,0.55)' : 'rgba(0,0,0,0.45)');
      c.setLineDash([]);
    }
  } else if (style === 'merrow') {
    const bw = Math.max(3, (edge.widthMm || 3) * ppm);
    const mid = offsetPolygon(poly, bw / 2);
    c.save();
    c.shadowColor = 'rgba(0,0,0,0.42)'; c.shadowBlur = bw * 0.5; c.shadowOffsetX = bw * 0.08; c.shadowOffsetY = bw * 0.18;
    strokePoly(c, mid, bw, shade(ehex, -0.3));
    c.restore();
    // rounded tube profile
    strokePoly(c, mid, bw * 0.82, shade(ehex, -0.12));
    strokePoly(c, mid, bw * 0.6, ehex);
    strokePoly(c, mid, bw * 0.3, shade(ehex, lum(ehex) > 200 ? 0.02 : 0.1));
    if (!fast) wrapStitches(c, mid, bw * 1.02, Math.max(1.8, ppm * 0.5), bw * 0.55, ehex, 0.32, 0.3);
    strokePoly(c, poly, Math.max(0.6, bw * 0.06), 'rgba(0,0,0,0.35)');
  } else if (style === 'laser') {
    const burnt = o.technique === 'leather' ? (o.engraveHex || shade(o.fabricHex, -0.6)) : shade(o.fabricHex, -0.45);
    c.save();
    c.shadowColor = rgba(burnt, 0.6); c.shadowBlur = ppm * 0.3;
    strokePoly(c, poly, Math.max(1, ppm * 0.35), burnt);
    c.restore();
    strokePoly(c, offsetPolygon(poly, ppm * 0.45), Math.max(0.5, ppm * 0.12), 'rgba(255,255,255,0.12)');
  } else if (style === 'molded') {
    const bw = Math.max(2, (edge.widthMm || 1.5) * ppm);
    const mid = offsetPolygon(poly, bw / 2);
    const b = { x: Infinity, y: Infinity, X: -Infinity, Y: -Infinity };
    for (const q of poly) { b.x = Math.min(b.x, q[0]); b.y = Math.min(b.y, q[1]); b.X = Math.max(b.X, q[0]); b.Y = Math.max(b.Y, q[1]); }
    const g = c.createLinearGradient(b.x, b.y, b.X, b.Y);
    g.addColorStop(0, shade(ehex, 0.35)); g.addColorStop(0.5, ehex); g.addColorStop(1, shade(ehex, -0.4));
    strokePoly(c, mid, bw, g);
    const g2 = c.createLinearGradient(b.x, b.y, b.X, b.Y);
    g2.addColorStop(0, 'rgba(0,0,0,0.35)'); g2.addColorStop(1, 'rgba(255,255,255,0.25)');
    strokePoly(c, offsetPolygon(poly, bw), Math.max(0.8, bw * 0.22), g2); // inner groove (inverted light)
    strokePoly(c, offsetPolygon(poly, bw * 0.3), Math.max(0.6, bw * 0.12), 'rgba(255,255,255,0.3)');
  } else if (style === 'felt') {
    const bw = Math.max(3, (edge.widthMm || 4) * ppm);
    const inner = offsetPolygon(poly, bw);
    c.save();
    c.beginPath(); tracePath(c, poly); tracePath(c, inner.slice().reverse());
    c.fillStyle = ehex; c.fill('evenodd');
    c.clip('evenodd');
    if (!fast) overlayTexture(c, 'felt', ppm, bboxOf(poly), 0.7);
    c.restore();
    // the chenille base felt sits on top of the border felt: soft shadow + zigzag stitch
    c.save();
    c.shadowColor = 'rgba(0,0,0,0.35)'; c.shadowBlur = ppm * 0.8; c.shadowOffsetY = ppm * 0.25;
    strokePoly(c, inner, Math.max(0.8, ppm * 0.15), shade(ehex, -0.2));
    c.restore();
    if (!fast) {
      const zz = offsetPolygon(poly, bw - ppm * 0.6), m = measure(zz);
      const n = Math.floor(m.total / (ppm * 0.9));
      c.beginPath();
      for (let i = 0; i <= n; i++) {
        const q = pointAt(zz, m, (i / n) * m.total), s = (i & 1 ? 1 : -1) * ppm * 0.45;
        const x = q.x - q.ty * s, y = q.y + q.tx * s;
        if (i) c.lineTo(x, y); else c.moveTo(x, y);
      }
      c.lineWidth = Math.max(0.6, ppm * 0.12); c.strokeStyle = rgba(shade(ehex, lum(ehex) > 128 ? -0.35 : 0.35), 0.8);
      c.stroke();
    }
    strokePoly(c, poly, Math.max(0.6, ppm * 0.1), 'rgba(0,0,0,0.25)');
  }
  c.restore();
}

export function bboxOf(p) {
  let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
  for (const q of p) { if (q[0] < x0) x0 = q[0]; if (q[0] > x1) x1 = q[0]; if (q[1] < y0) y0 = q[1]; if (q[1] > y1) y1 = q[1]; }
  return { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
}

/** Inner (safe) area of an edge style in mm: what the edge covers. */
export function edgeCoverMm(edge) {
  if (!edge) return 0;
  if (edge.style === 'laser') return 0.4;
  return edge.widthMm || 0;
}
