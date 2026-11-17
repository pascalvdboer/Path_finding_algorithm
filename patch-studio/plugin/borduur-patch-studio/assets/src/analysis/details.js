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

  const widths = [];
  const ridge = new Uint8Array(n);
  for (let p = 0; p < n; p++) {
    if (L[p] < 0 || !isRidge(L, D, W, H, p)) continue;
    ridge[p] = 1;
    if (Dout && Dout[p] <= D[p] + 1.5) continue; // band touching the patch edge: replaced by the border anyway
    widths.push(2 * D[p] - 0.5);
  }
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
    const gw = [], maxW = 0.1 * Math.min(W, H);
    for (let p = 0; p < n; p++) {
      if (G[p] < 0 || !isRidge(G, DG, W, H, p)) continue;
      if (fillsPatch && Dout && Dout[p] <= DG[p] + 1.5) continue;
      const w = 2 * DG[p] - 0.5;
      if (w <= maxW) gw.push(w);
    }
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
