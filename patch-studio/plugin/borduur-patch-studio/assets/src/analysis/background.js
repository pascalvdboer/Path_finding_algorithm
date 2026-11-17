/**
 * Patch Studio — analysis pass A: background / transparency detection (07 §3.4, §4.1, demo-v2).
 * Works on the whole image at ≤ sizes.a px. Pure.
 */
import { chroma } from '../shared/color-math.js';
import { labImage, median, hyp, chamfer } from './util.js';

/**
 * @param {{width:number,height:number,data:Uint8ClampedArray}} img  pass-A image
 * @param {Object} A  config.analysis (merged with defaults)
 * @returns {{bgMask:Uint8Array, lab:Float32Array, type:'transparent'|'white'|'solid'|'none', bgLab:number[]|null,
 *   bgRgb:number[]|null, noise:number, photoLike:boolean, fgCount:number, box:{x:number,y:number,w:number,h:number}|null,
 *   shadowShare:number}}
 */
export function detectBackground(img, A) {
  const W = img.width, H = img.height, d = img.data, n = W * H;
  const lab = labImage(img);
  const alphaBg = A.alphaBg;
  const tr = (p) => d[p * 4 + 3] < alphaBg;
  const dE = (p, c) => hyp(lab[p * 3] - c[0], lab[p * 3 + 1] - c[1], lab[p * 3 + 2] - c[2]);

  // Border pixels (each once).
  const border = [];
  for (let x = 0; x < W; x++) { border.push(x); if (H > 1) border.push((H - 1) * W + x); }
  for (let y = 1; y < H - 1; y++) { border.push(y * W); if (W > 1) border.push(y * W + W - 1); }

  let trEdge = 0;
  const eL = [], ea = [], eb = [], ep = [];
  for (const p of border) {
    if (tr(p)) trEdge++;
    else { eL.push(lab[p * 3]); ea.push(lab[p * 3 + 1]); eb.push(lab[p * 3 + 2]); ep.push(p); }
  }

  let type = 'none', bgLab = null, noise = 0;
  if (trEdge >= border.length * 0.5) {
    type = 'transparent';
  } else if (ep.length) {
    bgLab = [median(eL), median(ea), median(eb)];
    // The border must be mostly plain, otherwise the design runs to the edge (full bleed).
    const devs = [];
    let uni = 0;
    for (const p of ep) { const v = dE(p, bgLab); if (v < 20) { uni++; devs.push(v); } }
    // Hue drift along the border = a full-bleed gradient, not a background (folds/light only change L*).
    let drift = 0;
    for (const p of ep) drift += hyp(lab[p * 3 + 1] - bgLab[1], lab[p * 3 + 2] - bgLab[2]);
    drift /= ep.length;
    if (uni < ep.length * 0.3 || drift > 4) bgLab = null;
    else noise = median(devs);
  }
  // Photos of real fabric: folds and uneven light mostly change L*, so allow bigger lightness steps.
  const photoLike = !!bgLab && noise > 1.2;

  const bgMask = new Uint8Array(n);
  if (type === 'transparent' || bgLab) {
    const nbDE = A.floodNeighborDE, dl = A.floodPhotoDL, dc = A.floodPhotoDC;
    // A perfectly flat digital background needs a tight cap, otherwise the fill creeps into dark/soft design
    // edges (e.g. a dark patch with a soft shadow on navy). Photos keep the wide cap from the config.
    const bgDE = photoLike ? A.floodBgDE : Math.min(A.floodBgDE, 10 + noise * 8);
    const near = (p, lim) => !!bgLab && dE(p, bgLab) < lim;
    const step = (p, q) => {
      if (tr(q)) return true;
      if (tr(p) || !near(q, bgDE)) return false;
      const i = p * 3, j = q * 3;
      const dL = Math.abs(lab[i] - lab[j]), dab = hyp(lab[i + 1] - lab[j + 1], lab[i + 2] - lab[j + 2]);
      if (hyp(dL, dab) < nbDE) return true;
      return photoLike && dL < dl && dab < dc;
    };
    const stack = new Int32Array(n);
    let sp = 0;
    for (const p of border) if (!bgMask[p] && (tr(p) || near(p, 20))) { bgMask[p] = 1; stack[sp++] = p; }
    while (sp) {
      const p = stack[--sp], x = p % W, y = (p - x) / W;
      if (x > 0) { const q = p - 1; if (!bgMask[q] && step(p, q)) { bgMask[q] = 1; stack[sp++] = q; } }
      if (x < W - 1) { const q = p + 1; if (!bgMask[q] && step(p, q)) { bgMask[q] = 1; stack[sp++] = q; } }
      if (y > 0) { const q = p - W; if (!bgMask[q] && step(p, q)) { bgMask[q] = 1; stack[sp++] = q; } }
      if (y < H - 1) { const q = p + W; if (!bgMask[q] && step(p, q)) { bgMask[q] = 1; stack[sp++] = q; } }
    }
    removeSpeckles(bgMask, lab, W, H, bgLab);
    if (bgLab) openSmallHoles(bgMask, lab, W, H, bgLab, A.floodNeighborDE);
    let c = 0, hue = 0;
    for (let p = 0; p < n; p++) {
      if (!bgMask[p]) continue;
      c++;
      if (bgLab && !tr(p)) hue += hyp(lab[p * 3 + 1] - bgLab[1], lab[p * 3 + 2] - bgLab[2]);
    }
    // A real background is one colour; folds/light change L* only. Drifting hue = a full-bleed gradient, not a background.
    const hueDrift = c ? hue / c : 0;
    // A design has an edge: across the fg/bg boundary the colour must jump. A soft boundary means the fill only
    // stopped somewhere inside a smooth gradient ⇒ there is no background (full bleed).
    let soft = false;
    if (type !== 'transparent' && c >= n * 0.03 && c < n) {
      const jumps = [];
      for (let y = 2; y < H - 2 && jumps.length < 20000; y++) for (let x = 2; x < W - 2; x++) {
        const p = y * W + x;
        if (bgMask[p]) continue;
        for (const d of [1, -1, W, -W]) {
          if (!bgMask[p + d]) continue;
          const a = p - d, b = p + 2 * d;
          if (bgMask[a] || !bgMask[b]) continue;
          jumps.push(hyp(lab[a * 3] - lab[b * 3], lab[a * 3 + 1] - lab[b * 3 + 1], lab[a * 3 + 2] - lab[b * 3 + 2]));
          break;
        }
      }
      soft = jumps.length > 20 && median(jumps) < 4;
    }
    if (type !== 'transparent' && (c < n * 0.03 || hueDrift > 5 || soft)) { bgMask.fill(0); bgLab = null; }
  }

  if (type !== 'transparent') {
    if (bgLab) {
      const C = chroma(bgLab);
      const white = (bgLab[0] > A.white.L && C < A.white.C) || (bgLab[0] > 80 && C < 10);
      type = white ? 'white' : 'solid';
    } else type = 'none';
  }

  // Foreground box.
  let x0 = W, y0 = H, x1 = -1, y1 = -1, fg = 0;
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
    if (bgMask[y * W + x]) continue;
    fg++;
    if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y;
  }

  // Semi-transparent drop shadows (07 §3.4): 20 ≤ alpha ≤ 230 away from the 1-px mask edge.
  let semi = 0, opaque = 0;
  if (type === 'transparent') {
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
      const p = y * W + x, a = d[p * 4 + 3];
      if (a >= 20) opaque++;
      if (a < 20 || a > 230) continue;
      let edge = false;
      for (let dy = -1; dy <= 1 && !edge; dy++) for (let dx = -1; dx <= 1; dx++) {
        const xx = x + dx, yy = y + dy;
        if (xx < 0 || yy < 0 || xx >= W || yy >= H) continue;
        const b = d[(yy * W + xx) * 4 + 3];
        if ((a >= alphaBg) !== (b >= alphaBg) || b < 20) { edge = true; break; }
      }
      if (!edge) semi++;
    }
  }

  return {
    bgMask, lab, type, bgLab, noise, photoLike, fgCount: fg,
    box: x1 >= 0 ? { x: x0, y: y0, w: x1 - x0 + 1, h: y1 - y0 + 1 } : null,
    shadowShare: opaque ? semi / opaque : 0,
  };
}

/** Remove loose specks (fabric texture, dust, noise) that look like background (demo-v2). */
function removeSpeckles(bgMask, lab, W, H, bgLab) {
  const n = W * H, id = new Int32Array(n).fill(-1), sizes = [], sd = [];
  const stack = new Int32Array(n);
  for (let p0 = 0; p0 < n; p0++) {
    if (bgMask[p0] || id[p0] >= 0) continue;
    const c = sizes.length;
    let sp = 0, sz = 0, s = 0;
    stack[sp++] = p0; id[p0] = c;
    while (sp) {
      const p = stack[--sp], x = p % W, y = (p - x) / W;
      sz++;
      if (bgLab) s += hyp(lab[p * 3] - bgLab[0], lab[p * 3 + 1] - bgLab[1], lab[p * 3 + 2] - bgLab[2]);
      if (x > 0 && !bgMask[p - 1] && id[p - 1] < 0) { id[p - 1] = c; stack[sp++] = p - 1; }
      if (x < W - 1 && !bgMask[p + 1] && id[p + 1] < 0) { id[p + 1] = c; stack[sp++] = p + 1; }
      if (y > 0 && !bgMask[p - W] && id[p - W] < 0) { id[p - W] = c; stack[sp++] = p - W; }
      if (y < H - 1 && !bgMask[p + W] && id[p + W] < 0) { id[p + W] = c; stack[sp++] = p + W; }
    }
    sizes.push(sz); sd.push(s / sz);
  }
  if (sizes.length < 2) return;
  let big = 0;
  for (const s of sizes) if (s > big) big = s;
  const drop = sizes.map((s, i) => s < big * 0.02 && ((bgLab && sd[i] < 25) || s < n * 0.00015));
  for (let p = 0; p < n; p++) if (id[p] >= 0 && drop[id[p]]) bgMask[p] = 1;
}

/**
 * Small enclosed areas in exactly the background colour (letter counters in B, O, A, R; gaps in a logo) are
 * paper, not white thread: give them back to the background. Large enclosed areas (a white disc inside a badge)
 * stay design (07 §4.2 rule 1).
 */
function openSmallHoles(bgMask, lab, W, H, bgLab, lim) {
  const n = W * H, seen = new Uint8Array(n), stack = new Int32Array(n), list = [];
  let fg = 0;
  for (let p = 0; p < n; p++) if (!bgMask[p]) fg++;
  const maxHole = Math.max(4, fg * 0.006);
  // Distance of every design pixel to the real background: a letter counter sits one stroke away from it,
  // white lettering inside a badge sits far away (the badge margin) and must stay thread.
  const R = new Int16Array(n);
  for (let p = 0; p < n; p++) R[p] = bgMask[p] ? -1 : 0;
  const Dbg = chamfer(R, W, H, false);
  const like = (p) => !bgMask[p] && hyp(lab[p * 3] - bgLab[0], lab[p * 3 + 1] - bgLab[1], lab[p * 3 + 2] - bgLab[2]) < lim;
  for (let p0 = 0; p0 < n; p0++) {
    if (seen[p0] || !like(p0)) continue;
    let sp = 0, size = 0, big = false, dmin = Infinity;
    list.length = 0;
    stack[sp++] = p0; seen[p0] = 1;
    while (sp) {
      const p = stack[--sp], x = p % W, y = (p - x) / W;
      size++;
      if (Dbg[p] < dmin) dmin = Dbg[p];
      if (size > maxHole) big = true;
      if (!big) list.push(p);
      if (x > 0 && !seen[p - 1] && like(p - 1)) { seen[p - 1] = 1; stack[sp++] = p - 1; }
      if (x < W - 1 && !seen[p + 1] && like(p + 1)) { seen[p + 1] = 1; stack[sp++] = p + 1; }
      if (y > 0 && !seen[p - W] && like(p - W)) { seen[p - W] = 1; stack[sp++] = p - W; }
      if (y < H - 1 && !seen[p + W] && like(p + W)) { seen[p + W] = 1; stack[sp++] = p + W; }
    }
    if (!big && dmin <= Math.max(4, 0.6 * Math.sqrt(size))) for (const p of list) bgMask[p] = 1;
  }
}
