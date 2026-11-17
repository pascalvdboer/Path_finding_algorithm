/**
 * Patch Studio — gedeelde geometrie: van ontwerp (analysepixels) naar millimeter op de patch.
 * Eigenaar: architect, BEVROREN contract (SPEC.md §7.6). render, advice, ui en production gebruiken
 * ALLEMAAL deze functie, zodat "4,2 mm letterhoogte" overal hetzelfde betekent.
 *
 * Coördinaten: millimeter, oorsprong linksboven van het omhullende kader van de patch.
 */

/**
 * @typedef {{x:number,y:number,w:number,h:number}} Box
 * @typedef {Object} Layout
 * @property {number} patchWmm       breedte patch (mm)
 * @property {number} patchHmm       hoogte patch (mm)
 * @property {string} shape          'circle'|'oval'|'rect'|'rounded'|'shield'|'contour'
 * @property {{id:string, style:string, widthMm:number, safeZoneMm:number}} edge
 * @property {Box} innerMm           veilige zone (patch min safeZoneMm rondom)
 * @property {Box|null} artRectMm    waar het ontwerp (artBox) terechtkomt, na schaal/verschuiving
 * @property {number|null} mmPerArtPx  mm per analysepixel (null zonder afbeelding)
 * @property {boolean} fillsPatch    ontwerp is zelf al een patch → vult de hele patch
 */

const DEFAULT_LAYOUT = {
  aspectMin: 0.4,
  aspectMax: 2.5,
  fill: { circle: 0.8, oval: 0.8, rect: 0.94, rounded: 0.92, shield: 0.8, contour: 1 },
};

/** Vind de gekozen rand-optie (group 'edge') voor deze techniek. */
export function selectedEdge(design, config) {
  const tech = config.techniques[design.technique] || {};
  const opts = config.options || [];
  let id = (design.options || []).find((o) => {
    const opt = opts.find((x) => x.id === o);
    return opt && opt.group === 'edge';
  });
  if (!id) id = tech.defaultEdge;
  const opt = opts.find((x) => x.id === id);
  const r = (opt && opt.render) || { style: 'satin', widthMm: 2.5, safeZoneMm: 2 };
  return { id: id || 'none', style: r.style, widthMm: r.widthMm ?? 0, safeZoneMm: r.safeZoneMm ?? 0 };
}

/**
 * Bereken de lay-out van patch + ontwerp.
 * @param {Object} design   DesignState (technique, sizeCm, shape, aspect, options, art)
 * @param {Object|null} analysis AnalysisResult of null (tekst-only)
 * @param {Object} config   publieke config
 * @returns {Layout}
 */
export function layoutPatch(design, analysis, config) {
  const L = Object.assign({}, DEFAULT_LAYOUT, config.layout || {});
  const fill = Object.assign({}, DEFAULT_LAYOUT.fill, (config.layout && config.layout.fill) || {});
  const longest = Math.max(1, Number(design.sizeCm) || 8) * 10;
  const shape = design.shape || 'circle';
  const artBox = analysis && analysis.artBox && analysis.artBox.w > 0 ? analysis.artBox : null;

  let aspect = 1;
  if (shape !== 'circle') {
    aspect = Number(design.aspect) || (analysis && analysis.shape && analysis.shape.aspect) || (artBox ? artBox.w / artBox.h : 1);
    aspect = Math.min(L.aspectMax, Math.max(L.aspectMin, aspect));
  }
  const patchWmm = aspect >= 1 ? longest : longest * aspect;
  const patchHmm = aspect >= 1 ? longest / aspect : longest;

  const edge = selectedEdge(design, config);
  const s = edge.safeZoneMm;
  const innerMm = { x: s, y: s, w: Math.max(1, patchWmm - 2 * s), h: Math.max(1, patchHmm - 2 * s) };

  const fillsPatch = !!(analysis && analysis.shape && analysis.shape.fillsPatch);
  let artRectMm = null, mmPerArtPx = null;
  if (artBox) {
    let fit;
    if (fillsPatch) {
      fit = { x: 0, y: 0, w: patchWmm, h: patchHmm };
    } else {
      const f = fill[shape] ?? 0.9;
      fit = { w: innerMm.w * f, h: innerMm.h * f };
      fit.x = (patchWmm - fit.w) / 2;
      fit.y = (patchHmm - fit.h) / 2;
    }
    const a = artBox.w / artBox.h;
    let w = fit.w, h = fit.w / a;
    if (h > fit.h) { h = fit.h; w = fit.h * a; } // contain, NOOIT cover (BRIEF)
    const art = design.art || {};
    const sc = Math.min(2, Math.max(0.2, Number(art.scale) || 1));
    w *= sc; h *= sc;
    const off = art.offsetMm || { x: 0, y: 0 };
    artRectMm = {
      x: patchWmm / 2 - w / 2 + (Number(off.x) || 0),
      y: patchHmm / 2 - h / 2 + (Number(off.y) || 0),
      w, h,
    };
    mmPerArtPx = w / artBox.w;
  }
  return { patchWmm, patchHmm, shape, edge, innerMm, artRectMm, mmPerArtPx, fillsPatch };
}

/** Analysepixels → mm (null-veilig). */
export function pxToMm(px, layout) {
  if (px == null || !layout || !layout.mmPerArtPx) return null;
  return px * layout.mmPerArtPx;
}

/**
 * Kleinste geprijsde/aangeboden maat die een probleem oplost.
 * requiredCm = currentCm × (limitMm / measuredMm), naar boven afgerond op `sizes` (gesorteerd).
 * @returns {number|null} null als geen enkele maat ≤ maxCm volstaat
 */
export function requiredSizeCm(currentCm, measuredMm, limitMm, sizes, maxCm) {
  if (!measuredMm || measuredMm <= 0) return null;
  const need = currentCm * (limitMm / measuredMm);
  const sorted = [...sizes].sort((a, b) => a - b);
  for (const s of sorted) if (s >= need - 1e-9 && s <= maxCm) return s;
  return null;
}
