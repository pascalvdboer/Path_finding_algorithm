/**
 * Patch Studio — small-text heuristic (07 §7). No OCR: we only need HOW BIG letters are, not what they say.
 * Letter candidates = connected components with letter-like size/fill/stroke; grouped into straight lines
 * (PCA) or arcs (least-squares circle fit, e.g. text around a round badge); then measured perpendicular to the line.
 * Pure. Coordinates: pass-B pixels of the art crop.
 */
import { components, componentPixels, median, percentile, clamp } from './util.js';

/**
 * @param {Int16Array} L labels, @param {Float32Array} D chamfer distances, @param {Uint8Array} ridge
 * @param {number} W @param {number} H @param {Object} T config.analysis.text
 * @param {{palLab?:number[][]|null, fillsPatch?:boolean}} [opts] palette Lab (to treat shades of one background as one),
 *        fillsPatch: marks touching the patch edge (stitch dashes of a border) are never letters
 * @returns {import('../shared/types.js').TextLine[]}
 */
export function detectText(L, D, ridge, W, H, T, { palLab = null, fillsPatch = false, Dout = null } = {}) {
  const n = W * H, Hd = Math.max(W, H);
  const comp = components(L, W, H, true);
  const { off, px } = componentPixels(comp, n);
  const hMin = T.hMin * Hd, hMax = T.hMax * Hd;

  // The outer band of a patch is its border (merrow/satin dashes on mock-ups): no letters there.
  const rim = Math.max(3, 0.045 * Math.min(W, H));
  const touchesOutside = (c) => {
    for (let k = off[c]; k < off[c + 1]; k++) {
      const p = px[k], x = p % W, y = (p - x) / W;
      if (x < 2 || y < 2 || x >= W - 2 || y >= H - 2) return true;
      if (Dout ? Dout[p] < rim : L[p - 2] < 0 || L[p + 2] < 0 || L[p - 2 * W] < 0 || L[p + 2 * W] < 0) return true;
    }
    return false;
  };

  // Stage 1: loose candidates (rotation unknown yet).
  const cand = [];
  for (let c = 0; c < comp.count && cand.length < 3000; c++) {
    const sz = comp.size[c];
    if (sz < 6) continue;
    const bw = comp.maxX[c] - comp.minX[c] + 1, bh = comp.maxY[c] - comp.minY[c] + 1, s = Math.max(bw, bh);
    if (s < hMin || s > hMax * 1.5) continue;
    if (sz / (bw * bh) < 0.1) continue;
    if (fillsPatch && touchesOutside(c)) continue;
    const sw = [];
    for (let k = off[c]; k < off[c + 1]; k++) if (ridge[px[k]]) sw.push(2 * D[px[k]] - 0.5);
    if (sw.length < 2) continue;
    const m = median(sw);
    if (m / s < 0.04 || m / s > 0.5) continue;
    cand.push({ c, label: comp.label[c], cx: comp.sx[c] / sz + 0.5, cy: comp.sy[c] / sz + 0.5, s, sw, swMed: m });
  }
  if (cand.length < T.minRun) return [];

  // Stage 2: group neighbours of the same colour and similar size (union-find).
  const parent = cand.map((_, i) => i);
  const find = (i) => { while (parent[i] !== i) { parent[i] = parent[parent[i]]; i = parent[i]; } return i; };
  for (let i = 0; i < cand.length; i++) for (let j = i + 1; j < cand.length; j++) {
    const a = cand[i], b = cand[j];
    if (a.label !== b.label) continue;
    const ms = Math.max(a.s, b.s);
    if (Math.min(a.s, b.s) < ms * 0.45) continue;
    if (Math.hypot(a.cx - b.cx, a.cy - b.cy) > 1.8 * ms) continue;
    parent[find(i)] = find(j);
  }
  const groups = new Map();
  cand.forEach((cd, i) => { const r = find(i); if (!groups.has(r)) groups.set(r, []); groups.get(r).push(cd); });

  const lines = [];
  for (const g of groups.values()) {
    if (g.length < T.minRun) continue;
    const line = fitLine(g, comp, off, px, W, T, hMin, hMax, L, palLab);
    if (line) lines.push(line);
  }
  return lines.filter((l) => l.confidence >= MIN_CONFIDENCE).sort((a, b) => a.capPx - b.capPx);
}

/** Lines below this confidence are dropped: a false "text too small" warning costs more trust than a miss. */
const MIN_CONFIDENCE = 0.45;
const RING2 = [[2, 0], [-2, 0], [0, 2], [0, -2], [2, 2], [-2, 2], [2, -2], [-2, -2]];

function pcaLine(g) {
  const k = g.length;
  let mx = 0, my = 0;
  for (const c of g) { mx += c.cx; my += c.cy; }
  mx /= k; my /= k;
  let sxx = 0, syy = 0, sxy = 0;
  for (const c of g) { const dx = c.cx - mx, dy = c.cy - my; sxx += dx * dx; syy += dy * dy; sxy += dx * dy; }
  const th = 0.5 * Math.atan2(2 * sxy, sxx - syy), dx = Math.cos(th), dy = Math.sin(th);
  const resid = (c) => Math.abs((c.cx - mx) * -dy + (c.cy - my) * dx);
  let res = 0;
  for (const c of g) res += resid(c) ** 2;
  return { dx, dy, res: Math.sqrt(res / k), resid };
}

/** Fit, then drop the worst outlier (fragments, accents, stars) until the fit is good or too few remain. */
function robustFit(g0, fit, thr, minKeep) {
  let g = g0.slice();
  for (;;) {
    const f = fit(g);
    if (!f) return null;
    if (f.res < thr) return { g, f };
    if (g.length - 1 < minKeep) return null;
    let wi = 0;
    for (let i = 1; i < g.length; i++) if (f.resid(g[i]) > f.resid(g[wi])) wi = i;
    g.splice(wi, 1);
  }
}

function fitLine(g0, comp, off, px, W, T, hMin, hMax, L, palLab) {
  const sMed = median(g0.map((c) => c.s));
  const minKeep = Math.max(T.minRun, Math.ceil(0.6 * g0.length));
  let mode = null, circ = null, dx = 1, dy = 0, res = 0, g = g0;
  const ln = robustFit(g0, pcaLine, 0.25 * sMed, minKeep);
  if (ln) { mode = 'line'; g = ln.g; dx = ln.f.dx; dy = ln.f.dy; res = ln.f.res; }
  else if (g0.length >= 4) {
    const ar = robustFit(g0, (gg) => {
      const c = fitCircle(gg);
      return c && c.r > 2 * sMed ? { ...c, resid: (p) => Math.abs(Math.hypot(p.cx - c.x, p.cy - c.y) - c.r) } : null;
    }, 0.3 * sMed, Math.max(4, minKeep));
    if (ar) { mode = 'arc'; g = ar.g; circ = ar.f; }
  }
  if (!mode) return null;

  // Measure each letter perpendicular (h) and along (w) the text line.
  const letters = [];
  for (const cd of g) {
    let n0 = Infinity, n1 = -Infinity, t0 = Infinity, t1 = -Infinity;
    let ux, uy;
    if (mode === 'line') { ux = dx; uy = dy; } else {
      const rx = cd.cx - circ.x, ry = cd.cy - circ.y, rl = Math.hypot(rx, ry) || 1;
      ux = -ry / rl; uy = rx / rl; // tangent
    }
    for (let q = off[cd.c]; q < off[cd.c + 1]; q++) {
      const p = px[q], x = (p % W) + 0.5, y = (p - (p % W)) / W + 0.5;
      const ox = x - cd.cx, oy = y - cd.cy;
      const t = ox * ux + oy * uy, nn = -ox * uy + oy * ux;
      if (t < t0) t0 = t; if (t > t1) t1 = t; if (nn < n0) n0 = nn; if (nn > n1) n1 = nn;
    }
    const h = n1 - n0 + 1, w = t1 - t0 + 1, sz = comp.size[cd.c];
    const fill = sz / (w * h), sw = cd.swMed;
    const mean = cd.sw.reduce((s, v) => s + v, 0) / cd.sw.length;
    const cv = Math.sqrt(cd.sw.reduce((s, v) => s + (v - mean) ** 2, 0) / cd.sw.length) / (mean || 1);
    const ok = h >= hMin && h <= hMax && w / h >= T.aspectMin && w / h <= T.aspectMax
      && fill >= T.fillMin && fill <= T.fillMax && sw / h >= T.swMin && sw / h <= T.swMax && cv < T.cvMax;
    if (ok) letters.push({ cd, h, w, fill, sw, x0: comp.minX[cd.c], y0: comp.minY[cd.c], x1: comp.maxX[cd.c] + 1, y1: comp.maxY[cd.c] + 1 });
  }
  if (letters.length < T.minRun) return null;

  // Consistency checks that separate real lettering from shading blobs in illustrations / photos:
  // (1) letters share one cap height (or the x-height), (2) similar stroke width, (3) they sit on ONE background colour.
  const cap0 = percentile(letters.map((l) => l.h), 0.8);
  const inCap = letters.filter((l) => l.h >= 0.75 * cap0 && l.h <= 1.25 * cap0).length;
  const inX = letters.filter((l) => l.h >= 0.45 * cap0 && l.h < 0.75 * cap0).length;
  if (inCap < T.minRun || inCap + inX < 0.85 * letters.length) return null;
  const sws = letters.map((l) => l.sw), swm = sws.reduce((a, b) => a + b, 0) / sws.length;
  const swCv = Math.sqrt(sws.reduce((a, b) => a + (b - swm) ** 2, 0) / sws.length) / (swm || 1);
  if (swCv > 0.45) return null;
  // Rows of identical marks (stitch dashes of a border, stripes, dots) are not text: real letters differ in
  // width and/or ink coverage (L vs M vs O), even in monospaced fonts.
  if (letters.length >= 5) {
    const cv = (vals) => { const m = vals.reduce((a, b) => a + b, 0) / vals.length; return Math.sqrt(vals.reduce((a, b) => a + (b - m) ** 2, 0) / vals.length) / (m || 1); };
    if (cv(letters.map((l) => l.w)) < 0.12 && cv(letters.map((l) => l.fill)) < 0.1) return null;
  }
  const H = L.length / W, lab0 = g0[0].label, around = new Map();
  let ring = 0;
  for (const l of letters) {
    for (let q = off[l.cd.c]; q < off[l.cd.c + 1]; q++) {
      const p = px[q], x = p % W, y = (p - x) / W;
      // Sample 2 px away: the 1-px anti-aliasing seam often snaps to a third palette colour.
      for (const [dx, dy] of RING2) {
        const xx = x + dx, yy = y + dy;
        if (xx < 0 || yy < 0 || xx >= W || yy >= H) continue;
        const v = L[yy * W + xx];
        if (v === lab0) continue;
        ring++;
        around.set(v, (around.get(v) || 0) + 1);
      }
    }
  }
  let dom = -2, bestAround = 0;
  for (const [v, c] of around) if (c > bestAround) { bestAround = c; dom = v; }
  // Shades of the same background (texture, light) count as one background.
  let same = bestAround;
  if (palLab && dom >= 0) {
    for (const [v, c] of around) {
      if (v === dom || v < 0) continue;
      const a = palLab[v], b = palLab[dom];
      if (Math.hypot(a[0] - b[0], a[1] - b[1], a[2] - b[2]) < 20) same += c;
    }
  }
  const bgUniform = ring ? same / ring : 0;
  if (bgUniform < 0.7) return null;

  const hs = letters.map((l) => l.h);
  const capPx = percentile(hs, 0.8);
  const small = hs.filter((h) => h / capPx >= 0.5 && h / capPx <= 0.72);
  const xPx = small.length >= 2 && small.length >= 0.25 * hs.length ? median(small) : null;
  const allSw = [];
  for (const l of letters) for (const v of l.cd.sw) allSw.push(v);
  const m = allSw.reduce((s, v) => s + v, 0) / allSw.length;
  const cvAll = Math.sqrt(allSw.reduce((s, v) => s + (v - m) ** 2, 0) / allSw.length) / (m || 1);
  let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
  for (const l of letters) { x0 = Math.min(x0, l.x0); y0 = Math.min(y0, l.y0); x1 = Math.max(x1, l.x1); y1 = Math.max(y1, l.y1); }
  const r = mode === 'arc' ? circ.res : res;
  const confidence = clamp(Math.min(1, 0.45 + 0.07 * letters.length) * (1 - 0.4 * clamp(r / (0.3 * sMed), 0, 1)) * (0.7 + 0.3 * (bgUniform - 0.7) / 0.3) * (1 - 0.5 * clamp(swCv, 0, 1)), 0, 1);
  return {
    box: { x: x0, y: y0, w: x1 - x0, h: y1 - y0 },
    capPx: Math.round(capPx * 100) / 100,
    xPx: xPx == null ? null : Math.round(xPx * 100) / 100,
    strokePx: Math.round(median(letters.map((l) => l.sw)) * 100) / 100,
    arc: mode === 'arc',
    serifOrScript: cvAll >= T.serifCv,
    paletteIndex: g[0].label,
    confidence: Math.round(confidence * 100) / 100,
  };
}

/** Algebraic (Kåsa) least-squares circle through the letter centroids. */
function fitCircle(g) {
  let sx = 0, sy = 0, sxx = 0, syy = 0, sxy = 0, sxz = 0, syz = 0, sz = 0;
  const k = g.length;
  for (const c of g) {
    const x = c.cx, y = c.cy, z = x * x + y * y;
    sx += x; sy += y; sxx += x * x; syy += y * y; sxy += x * y; sxz += x * z; syz += y * z; sz += z;
  }
  // Solve [sxx sxy sx; sxy syy sy; sx sy k] [a b c]ᵀ = [-sxz -syz -sz]ᵀ
  const M = [[sxx, sxy, sx, -sxz], [sxy, syy, sy, -syz], [sx, sy, k, -sz]];
  for (let i = 0; i < 3; i++) {
    let piv = i;
    for (let r = i + 1; r < 3; r++) if (Math.abs(M[r][i]) > Math.abs(M[piv][i])) piv = r;
    [M[i], M[piv]] = [M[piv], M[i]];
    if (Math.abs(M[i][i]) < 1e-9) return null;
    for (let r = 0; r < 3; r++) {
      if (r === i) continue;
      const f = M[r][i] / M[i][i];
      for (let c = i; c < 4; c++) M[r][c] -= f * M[i][c];
    }
  }
  const a = M[0][3] / M[0][0], b = M[1][3] / M[1][1], c = M[2][3] / M[2][2];
  const x = -a / 2, y = -b / 2, r2 = x * x + y * y - c;
  if (!(r2 > 0)) return null;
  const r = Math.sqrt(r2);
  let res = 0;
  for (const p of g) res += (Math.hypot(p.cx - x, p.cy - y) - r) ** 2;
  return { x, y, r, res: Math.sqrt(res / k) };
}
