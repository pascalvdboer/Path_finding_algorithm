/**
 * Patch Studio — analysis pass C: finest line, smallest gap and stitch regions (07 §8, §11.1). Pure.
 * Widths come from a chamfer distance transform per colour region; ridge (medial-axis) pixel width = 2·D − 0.5.
 */
import { chamfer, isRidge, components, componentPixels, weightedLowPercentile, median } from './util.js';

/**
 * @param {Int16Array} L final labels (-1 = background / outside the patch)
 * @param {Object} o {W, H, fillsPatch, baseLabel}
 */
export function measureDetails(L, { W, H, fillsPatch, baseLabel }) {
  const n = W * H;
  const D = chamfer(L, W, H, true);

  // Distance to the outside of the design (for "the patch's own rim is not a thin line", 07 §8).
  let Dout = null;
  if (fillsPatch) {
    const M = new Int16Array(n);
    for (let p = 0; p < n; p++) M[p] = L[p] < 0 ? -1 : 0;
    Dout = chamfer(M, W, H, false);
  }

  const ridge = new Uint8Array(n);
  const RW = new Float32Array(n).fill(-1);
  for (let p = 0; p < n; p++) {
    if (L[p] < 0 || !isRidge(L, D, W, H, p)) continue;
    ridge[p] = 1;
    if (Dout && Dout[p] <= D[p] + 1.5) continue; // band touching the patch edge: replaced by the border anyway
    RW[p] = 2 * D[p] - 0.5;
  }
  dropNecks(RW, L, W, H);
  const widths = [];
  for (let p = 0; p < n; p++) if (RW[p] >= 0) widths.push(RW[p]);
  const fineLinePx = weightedLowPercentile(widths, 0.04, 30);

  // Smallest gap: narrow strips of "no thread" between design parts (background), or of the base colour
  // inside a full patch. The crop edge is not a boundary for gaps.
  const G = new Int16Array(n);
  let gapCount = 0;
  for (let p = 0; p < n; p++) {
    const isGap = fillsPatch ? L[p] === baseLabel && baseLabel >= 0 : L[p] < 0;
    G[p] = isGap ? 0 : -1;
    if (isGap) gapCount++;
  }
  let fineGapPx = null;
  if (gapCount && gapCount < n) {
    const DG = chamfer(G, W, H, false);
    const GW = new Float32Array(n).fill(-1), maxW = 0.1 * Math.min(W, H);
    for (let p = 0; p < n; p++) {
      if (G[p] < 0 || !isRidge(G, DG, W, H, p)) continue;
      if (fillsPatch && Dout && Dout[p] <= DG[p] + 1.5) continue;
      // A strip between the design and the crop edge (fractional crop box) is not a gap between design parts.
      const gx = p % W, gy = (p - gx) / W;
      if (Math.min(gx, gy, W - 1 - gx, H - 1 - gy) <= DG[p]) continue;
      const w = 2 * DG[p] - 0.5;
      if (w <= maxW) GW[p] = w;
    }
    dropNecks(GW, G, W, H);
    const gw = [];
    for (let p = 0; p < n; p++) if (GW[p] >= 0) gw.push(GW[p]);
    fineGapPx = weightedLowPercentile(gw, 0.04, 30);
  }

  // Stitch regions: per colour component a mean width (median ridge width) and an area.
  const comp = components(L, W, H, false);
  const { off, px } = componentPixels(comp, n);
  const bandMap = new Map();
  let objects = 0;
  const minObj = Math.max(4, n * 0.0002);
  for (let c = 0; c < comp.count; c++) {
    const area = comp.size[c];
    if (area < 2) continue;
    if (area >= minObj) objects++;
    const rw = [];
    for (let k = off[c]; k < off[c + 1]; k++) { const p = px[k]; if (ridge[p]) rw.push(2 * D[p] - 0.5); }
    let w = rw.length ? median(rw) : 1;
    w = Math.max(1, w);
    const key = w < 4 ? Math.round(w * 2) / 2 : w < 20 ? Math.round(w) : Math.round(w / 4) * 4;
    bandMap.set(key, (bandMap.get(key) || 0) + area);
  }
  const bands = [...bandMap.entries()].sort((a, b) => a[0] - b[0]);
  return { D, Dout, ridge, fineLinePx, fineGapPx, bands, objects };
}

/**
 * Necks are not lines (accuracy review): where two shapes of one colour touch at a corner, or a gap pinches to a
 * point, the medial axis has a few narrow ridge pixels. A real thin line or gap has a long narrow ridge. Narrow
 * ridge runs (≤ NECK_MAX_W px wide) shorter than NECK_MIN_RUN pixels are removed from `RW` (in place, -1 = none).
 */
const NECK_MAX_W = 4.5, NECK_MIN_RUN = 4;
function dropNecks(RW, L, W, H) {
  const n = W * H, seen = new Uint8Array(n), stack = [], run = [];
  for (let s = 0; s < n; s++) {
    if (seen[s] || RW[s] < 0 || RW[s] > NECK_MAX_W) continue;
    seen[s] = 1; stack.push(s); run.length = 0;
    while (stack.length) {
      const p = stack.pop(), x = p % W, y = (p - x) / W;
      run.push(p);
      for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) {
        const xx = x + dx, yy = y + dy;
        if ((!dx && !dy) || xx < 0 || yy < 0 || xx >= W || yy >= H) continue;
        const q = yy * W + xx;
        if (!seen[q] && RW[q] >= 0 && RW[q] <= NECK_MAX_W && L[q] === L[p]) { seen[q] = 1; stack.push(q); }
      }
    }
    if (run.length < NECK_MIN_RUN) for (const p of run) RW[p] = -1;
  }
}

/**
 * Fixed region fields of the contract, classified at a reference size (the default patch size).
 * estimateStitches() prefers `regions.bands` (exact for every size) when present.
 */
export function regionsFromBands(bands, objects, refMmPerPx, classifyMm) {
  let fillAreaPx2 = 0, satinLenPx = 0, runLenPx = 0;
  for (const [w, area] of bands) {
    const wmm = w * refMmPerPx;
    if (wmm < classifyMm.runBelow) runLenPx += area / w;
    else if (wmm <= classifyMm.fillAbove) satinLenPx += area / w;
    else fillAreaPx2 += area;
  }
  return {
    fillAreaPx2: Math.round(fillAreaPx2), satinLenPx: Math.round(satinLenPx), runLenPx: Math.round(runLenPx), objects,
    bands: bands.map(([w, a]) => [w, a]),
  };
}
