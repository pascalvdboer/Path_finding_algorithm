/**
 * Patch Studio — analysis pass B: CIELAB colour quantisation (07 §4.5).
 * 5-bit histogram → weighted k-means++ (fixed seed, deterministic) → merge ΔE00 < mergeDE00 → small colours out
 * (thin-line exception) → anti-aliasing mix colours out → labels + 3×3 mode filter. Pure.
 */
import { deltaE2000, rgbToLab, labToRgb } from '../shared/color-math.js';
import { lcg, components, chamfer, ColorCache } from './util.js';

/** Weighted Lab points from a 5-bit RGB histogram of the pixels in `mask`. */
export function labHistogram(img, mask) {
  const d = img.data, map = new Map();
  for (let p = 0, i = 0; i < d.length; i += 4, p++) {
    if (!mask[p]) continue;
    const key = ((d[i] >> 3) << 10) | ((d[i + 1] >> 3) << 5) | (d[i + 2] >> 3);
    let e = map.get(key);
    if (!e) { e = { n: 0, r: 0, g: 0, b: 0 }; map.set(key, e); }
    e.n++; e.r += d[i]; e.g += d[i + 1]; e.b += d[i + 2];
  }
  const pts = [];
  for (const e of map.values()) pts.push({ n: e.n, lab: rgbToLab(Math.round(e.r / e.n), Math.round(e.g / e.n), Math.round(e.b / e.n)) });
  // Deterministic order regardless of Map insertion quirks.
  pts.sort((a, b) => b.n - a.n || a.lab[0] - b.lab[0] || a.lab[1] - b.lab[1] || a.lab[2] - b.lab[2]);
  return pts;
}

const d2 = (a, b) => (a[0] - b[0]) ** 2 + (a[1] - b[1]) ** 2 + (a[2] - b[2]) ** 2;

/** Weighted k-means++ in Lab (seed = largest bin, then D²-weighted with a fixed-seed PRNG). */
export function kmeansLab(pts, k, iters, seed) {
  if (!pts.length) return { centers: [], counts: [] };
  const rnd = lcg(seed);
  k = Math.min(k, pts.length);
  const cs = [pts[0].lab.slice()];
  const D = pts.map((p) => d2(p.lab, cs[0]));
  while (cs.length < k) {
    let tot = 0;
    for (let i = 0; i < pts.length; i++) tot += D[i] * pts[i].n;
    if (tot === 0) break;
    let r = rnd() * tot, j = 0;
    for (; j < pts.length - 1; j++) { r -= D[j] * pts[j].n; if (r <= 0) break; }
    cs.push(pts[j].lab.slice());
    for (let i = 0; i < pts.length; i++) D[i] = Math.min(D[i], d2(pts[i].lab, cs[cs.length - 1]));
  }
  const asg = new Int32Array(pts.length).fill(-1);
  for (let it = 0; it < iters; it++) {
    const acc = cs.map(() => [0, 0, 0, 0]);
    let moved = 0;
    for (let i = 0; i < pts.length; i++) {
      let bi = 0, bd = Infinity;
      for (let c = 0; c < cs.length; c++) { const v = d2(pts[i].lab, cs[c]); if (v < bd) { bd = v; bi = c; } }
      if (asg[i] !== bi) moved++;
      asg[i] = bi;
      const a = acc[bi], w = pts[i].n;
      a[0] += pts[i].lab[0] * w; a[1] += pts[i].lab[1] * w; a[2] += pts[i].lab[2] * w; a[3] += w;
    }
    acc.forEach((a, c) => { if (a[3]) cs[c] = [a[0] / a[3], a[1] / a[3], a[2] / a[3]]; });
    if (moved === 0) break;
  }
  const counts = cs.map(() => 0);
  pts.forEach((p, i) => { counts[asg[i]] += p.n; });
  return { centers: cs, counts };
}

/** Merge clusters closer than `merge` (ΔE2000, kL) — weighted mean. Returns [{lab, n}] sorted by n desc. */
export function mergeClusters(centers, counts, merge, kL = 1) {
  const cl = centers.map((c, i) => ({ lab: c, n: counts[i] })).filter((c) => c.n > 0);
  for (;;) {
    let bi = -1, bj = -1, bd = merge;
    for (let i = 0; i < cl.length; i++) for (let j = i + 1; j < cl.length; j++) {
      const v = deltaE2000(cl[i].lab, cl[j].lab, kL);
      if (v < bd) { bd = v; bi = i; bj = j; }
    }
    if (bi < 0) break;
    const a = cl[bi], b = cl[bj], t = a.n + b.n;
    a.lab = [0, 1, 2].map((x) => (a.lab[x] * a.n + b.lab[x] * b.n) / t);
    a.n = t;
    cl.splice(bj, 1);
  }
  return cl.sort((a, b) => b.n - a.n);
}

/**
 * Nearest-palette labels for every pixel in `mask` (ΔE76 in Lab; cached per RGB). -1 elsewhere.
 * @param {Float32Array} lab per-pixel Lab
 */
export function labelPixels(img, lab, mask, palLab) {
  const n = img.width * img.height, d = img.data, L = new Int16Array(n).fill(-1), cache = new ColorCache(17);
  const K = palLab.length, P = new Float64Array(K * 3);
  palLab.forEach((c, j) => { P[j * 3] = c[0]; P[j * 3 + 1] = c[1]; P[j * 3 + 2] = c[2]; });
  for (let p = 0; p < n; p++) {
    if (!mask[p]) continue;
    const i = p * 4, key = (d[i] << 16) | (d[i + 1] << 8) | d[i + 2];
    let v = cache.get(key);
    if (v < 0) {
      const l0 = lab[p * 3], l1 = lab[p * 3 + 1], l2 = lab[p * 3 + 2];
      let bd = Infinity;
      v = 0;
      for (let j = 0; j < K; j++) {
        const a = l0 - P[j * 3], b = l1 - P[j * 3 + 1], c = l2 - P[j * 3 + 2], t = a * a + b * b + c * c;
        if (t < bd) { bd = t; v = j; }
      }
      cache.set(key, v);
    }
    L[p] = v;
  }
  return L;
}

/**
 * 3×3 mode filter (demo-v2) that removes isolated anti-aliasing pixels but keeps 1-px lines:
 * a pixel only changes when fewer than 3 pixels of its own label are in its 3×3 window.
 */
export function modeFilter(L, W, H, nLabels) {
  const out = new Int16Array(L.length), cnt = new Int32Array(nLabels + 1);
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
    const p = y * W + x, own = L[p] + 1;
    if (own === 0) { out[p] = -1; continue; }
    cnt.fill(0);
    for (let dy = -1; dy <= 1; dy++) {
      const yy = y + dy;
      if (yy < 0 || yy >= H) continue;
      for (let dx = -1; dx <= 1; dx++) {
        const xx = x + dx;
        if (xx < 0 || xx >= W) continue;
        cnt[L[yy * W + xx] + 1]++;
      }
    }
    if (cnt[own] >= 3) { out[p] = own - 1; continue; }
    let bi = own;
    for (let j = 1; j < cnt.length; j++) if (cnt[j] > cnt[bi]) bi = j;
    out[p] = bi - 1;
  }
  return out;
}

/**
 * Build the final palette.
 * @returns {{pal:{lab:number[], n:number, thin:boolean}[], pts:Object[]}}
 */
export function buildPalette(img, lab, statMask, mask, W, H, A, { kL = 1, bgLab = null } = {}) {
  const pts = labHistogram(img, statMask);
  if (!pts.length) return { pal: [], pts };
  const km = kmeansLab(pts, A.kmeans.k, A.kmeans.iters, A.kmeans.seed);
  let cl = mergeClusters(km.centers, km.counts, A.mergeDE00, kL).map((c) => ({ ...c, thin: false }));
  const tot = cl.reduce((s, c) => s + c.n, 0);
  // Anti-aliasing mix colours: c ≈ lerp(i, j) of two bigger colours (or of a colour and the background around
  // the design / outside the patch shape) and its pixels sit between both ⇒ not a real thread colour.
  const mixCandidates = cl.filter((c) => c.n / tot < 0.03);
  if (mixCandidates.length && cl.length > 1) {
    const L1 = labelPixels(img, lab, mask, cl.map((c) => c.lab));
    const BG = cl.length; // virtual partner index for "background / outside"
    const rgb = cl.map((c) => labToRgb(c.lab[0], c.lab[1], c.lab[2]));
    if (bgLab) rgb.push(labToRgb(bgLab[0], bgLab[1], bgLab[2]));
    const big = (i, c) => (i === BG ? !!bgLab : cl[i].n > c.n);
    const drop = new Set();
    for (const c of mixCandidates) {
      const ci = cl.indexOf(c);
      const pairs = [];
      for (let i = 0; i < cl.length; i++) for (let j = i + 1; j <= BG; j++) {
        if (i === ci || j === ci || !big(i, c) || !big(j, c) || drop.has(i) || drop.has(j)) continue;
        let best = Infinity;
        for (let t = 0.08; t <= 0.921; t += 0.04) {
          const m = [0, 1, 2].map((k) => Math.round(rgb[i][k] + (rgb[j][k] - rgb[i][k]) * t));
          best = Math.min(best, deltaE2000(c.lab, rgbToLab(m[0], m[1], m[2])));
        }
        if (best < 3) pairs.push([i, j]);
      }
      if (!pairs.length) continue;
      let px = 0, both = 0;
      const seen = new Uint8Array(BG + 1);
      for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
        if (L1[y * W + x] !== ci) continue;
        px++;
        seen.fill(0);
        for (let dy = -3; dy <= 3; dy++) for (let dx = -3; dx <= 3; dx++) {
          const xx = x + dx, yy = y + dy;
          if (xx < 0 || yy < 0 || xx >= W || yy >= H) { seen[BG] = 1; continue; }
          const q = yy * W + xx, l = L1[q];
          if (!mask[q]) seen[BG] = 1; else if (l >= 0) seen[l] = 1;
        }
        if (pairs.some(([i, j]) => seen[i] && seen[j])) both++;
      }
      if (px && both / px >= 0.7) drop.add(ci);
    }
    if (drop.size) cl = cl.filter((_, i) => !drop.has(i));
  }
  const small = cl.filter((c) => c.n / tot < A.minShare);
  if (small.length && cl.length > 1) {
    // Thin-line exception: a rare colour that forms a long, thin line (e.g. the red ring in the owner's design).
    const L0 = labelPixels(img, lab, mask, cl.map((c) => c.lab));
    const D = chamfer(L0, W, H, true);
    const comp = components(L0, W, H, true);
    const maxD = new Float32Array(comp.count);
    for (let p = 0; p < W * H; p++) { const c = comp.id[p]; if (c >= 0 && D[p] > maxD[c]) maxD[c] = D[p]; }
    const diag = Math.hypot(W, H);
    for (let c = 0; c < comp.count; c++) {
      const cli = cl[comp.label[c]];
      if (cli.n / tot >= A.minShare || cli.thin) continue;
      const dg = Math.hypot(comp.maxX[c] - comp.minX[c] + 1, comp.maxY[c] - comp.minY[c] + 1);
      if (dg > 0.1 * diag && maxD[c] <= 2 && comp.size[c] >= 12) cli.thin = true;
    }
  }

  cl = cl.filter((c) => c.thin || c.n / tot >= A.minShare);
  if (!cl.length) cl = [mergeClusters(km.centers, km.counts, A.mergeDE00, kL)[0]].map((c) => ({ ...c, thin: false }));
  return { pal: cl.slice(0, 16), pts };
}

/** Mean ΔE2000 of the histogram points to their nearest palette colour (07 §6.1 "residual"). */
export function residualOf(pts, palLab) {
  if (!pts.length || !palLab.length) return 0;
  let err = 0, tot = 0;
  for (const p of pts) {
    let bd = Infinity, bj = 0;
    for (let j = 0; j < palLab.length; j++) { const v = d2(p.lab, palLab[j]); if (v < bd) { bd = v; bj = j; } }
    err += deltaE2000(p.lab, palLab[bj]) * p.n;
    tot += p.n;
  }
  return err / tot;
}
