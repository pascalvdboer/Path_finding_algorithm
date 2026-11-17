/**
 * Patch Studio — stitch count estimate (07 §11.2). Explanation only: the price does NOT depend on it (SPEC D4).
 *   stitches = fillPerMm2·fill_mm² + satinPerMm·satin_mm + runPerMm·run_mm + perColor·colours + perObject·objects
 *              (+ satinBorderPerMm·perimeter_mm for a satin border)
 *   range    = [range[0]·s ; range[1]·s], everything rounded to stitchCfg.roundTo.
 */

const DEF = {
  fillPerMm2: 1.6, satinPerMm: 5.0, runPerMm: 0.4, perColor: 30, perObject: 15, satinBorderPerMm: 6.0,
  classifyMm: { runBelow: 1.0, fillAbove: 7.0 }, range: [0.8, 1.25], roundTo: 100,
};

/**
 * @param {{fillAreaPx2:number, satinLenPx:number, runLenPx:number, objects:number, bands?:[number,number][]}} regions
 * @param {number} mmPerPx  mm per analysis pixel (layoutPatch().mmPerArtPx)
 * @param {number} colorCount thread colours
 * @param {Object} stitchCfg config.stitches
 * @param {{perimeterMm?:number, satinBorder?:boolean}} [opts]
 * @returns {{estimate:number, min:number, max:number}}
 */
export function estimateStitches(regions, mmPerPx, colorCount, stitchCfg, { perimeterMm = 0, satinBorder = false } = {}) {
  const S = Object.assign({}, DEF, stitchCfg || {});
  const cls = Object.assign({}, DEF.classifyMm, (stitchCfg && stitchCfg.classifyMm) || {});
  const range = Array.isArray(S.range) && S.range.length === 2 ? S.range : DEF.range;
  const roundTo = S.roundTo > 0 ? S.roundTo : 1;
  const r = regions || {};
  const k = Number(mmPerPx) > 0 ? Number(mmPerPx) : 0;
  let fill = 0, satin = 0, run = 0;
  if (Array.isArray(r.bands) && r.bands.length && k) {
    for (const [w, area] of r.bands) {
      const wmm = w * k;
      if (wmm < cls.runBelow) run += (area / w) * k;
      else if (wmm <= cls.fillAbove) satin += (area / w) * k;
      else fill += area * k * k;
    }
  } else {
    fill = (r.fillAreaPx2 || 0) * k * k;
    satin = (r.satinLenPx || 0) * k;
    run = (r.runLenPx || 0) * k;
  }
  let s = fill * S.fillPerMm2 + satin * S.satinPerMm + run * S.runPerMm
    + Math.max(0, colorCount || 0) * S.perColor + Math.max(0, r.objects || 0) * S.perObject;
  if (satinBorder && perimeterMm > 0) s += perimeterMm * S.satinBorderPerMm;
  const rnd = (v) => Math.max(roundTo, Math.round(v / roundTo) * roundTo);
  if (s <= 0) return { estimate: 0, min: 0, max: 0 };
  return { estimate: rnd(s), min: rnd(s * range[0]), max: rnd(s * range[1]) };
}
