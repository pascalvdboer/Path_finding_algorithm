/**
 * Patch Studio — analysis: classification features and decision tree (07 §6). Pure.
 *   cover    4-bit RGB buckets covering 85 % of the design pixels
 *   hard     share of neighbour pairs with ΔE76 ≥ 18 (contours)
 *   chg      share of neighbour pairs whose palette label differs
 *   fake     of those label changes: share where the original ΔE76 < 6 ("false edges" = banding in gradients/shading)
 *   texture  share of 3×3 blocks with small L* noise (fabric texture / photo grain)
 */
import { hyp } from './util.js';

export function cover85(img, mask) {
  const d = img.data, m = new Map();
  let n = 0;
  for (let p = 0, i = 0; i < d.length; i += 4, p++) {
    if (!mask[p]) continue;
    n++;
    const k = ((d[i] >> 4) << 8) | ((d[i + 1] >> 4) << 4) | (d[i + 2] >> 4);
    m.set(k, (m.get(k) || 0) + 1);
  }
  const v = [...m.values()].sort((a, b) => b - a);
  let acc = 0, c = 0;
  for (const x of v) { acc += x; c++; if (acc >= n * 0.85) break; }
  return c;
}

/** Neighbour statistics on the Lab image (ΔE76) inside the mask. */
export function edgeStats(lab, W, H, mask) {
  let flat = 0, soft = 0, hard = 0, n = 0;
  const dd = (p, q) => hyp(lab[p * 3] - lab[q * 3], lab[p * 3 + 1] - lab[q * 3 + 1], lab[p * 3 + 2] - lab[q * 3 + 2]);
  for (let y = 0; y < H - 1; y++) for (let x = 0; x < W - 1; x++) {
    const p = y * W + x;
    if (!mask[p]) continue;
    for (let k = 0; k < 2; k++) {
      const q = k ? p + W : p + 1;
      if (!mask[q]) continue;
      const v = dd(p, q);
      n++;
      if (v < 2.5) flat++; else if (v < 18) soft++; else hard++;
    }
  }
  let tex = 0, tn = 0;
  for (let y = 1; y < H - 1; y += 2) for (let x = 1; x < W - 1; x += 2) {
    const p = y * W + x;
    if (!mask[p]) continue;
    let s = 0, s2 = 0, ok = true;
    for (let dy = -1; dy <= 1 && ok; dy++) for (let dx = -1; dx <= 1; dx++) {
      const q = p + dy * W + dx;
      if (!mask[q]) { ok = false; break; }
      const v = lab[q * 3];
      s += v; s2 += v * v;
    }
    if (!ok) continue;
    const sd = Math.sqrt(Math.max(0, s2 / 9 - (s / 9) ** 2));
    tn++;
    if (sd > 1.2 && sd < 8) tex++;
  }
  return { flat: n ? flat / n : 1, soft: n ? soft / n : 0, hard: n ? hard / n : 0, texture: tn ? tex / tn : 0 };
}

/** Label changes and "false edges" (labels: nearest palette colour, BEFORE the mode filter). */
export function labelEdges(lab, L, W, H) {
  let change = 0, fake = 0, n = 0;
  for (let y = 0; y < H - 1; y++) for (let x = 0; x < W - 1; x++) {
    const p = y * W + x;
    if (L[p] < 0) continue;
    for (let k = 0; k < 2; k++) {
      const q = k ? p + W : p + 1;
      if (L[q] < 0) continue;
      n++;
      if (L[q] === L[p]) continue;
      change++;
      const v = hyp(lab[p * 3] - lab[q * 3], lab[p * 3 + 1] - lab[q * 3 + 1], lab[p * 3 + 2] - lab[q * 3 + 2]);
      if (v < 6) fake++;
    }
  }
  return { chg: n ? change / n : 0, fake: change ? fake / change : 0 };
}

/** 07 §6.2 decision tree (order matters). */
export function classify(f, C) {
  if (f.fake < C.flatFakeMax && f.cover <= C.flatCoverMax) return 'flat';
  if (f.cover >= C.photoCoverMin && f.hard < C.photoHardMax) return 'photo';
  if (f.hard < C.gradientHardMax && f.chg < C.gradientChgMax && f.cover <= C.gradientCoverMax) return 'gradient';
  return 'illustration';
}
