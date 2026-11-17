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
    // Eigenaar (BRIEF "nooit bijsnijden"): past de ontwerpvorm niet op de gekozen patchvorm (rond ontwerp in een
    // schild, rechthoekige foto in een cirkel, vierkant logo in een cirkel), dan verkleinen we tot elk ontwerppixel
    // binnen de patch (min. veilige zone) valt. Een ontwerp dat zelf al exact deze patchvorm is, vult de patch.
    let cy = patchHmm / 2;
    const pts = shape === 'contour' || (fillsPatch && shapeMatches(analysis.shape.type, shape)) ? null : footprint(analysis, fillsPatch);
    if (pts) {
      const k = fitInShape(shape, patchWmm, patchHmm, s, w, h, pts);
      w *= k.k; h *= k.k; cy = k.cy;
    }
    const art = design.art || {};
    const sc = Math.min(2, Math.max(0.2, Number(art.scale) || 1));
    w *= sc; h *= sc;
    const off = art.offsetMm || { x: 0, y: 0 };
    artRectMm = {
      x: patchWmm / 2 - w / 2 + (Number(off.x) || 0),
      y: cy - h / 2 + (Number(off.y) || 0),
      w, h,
    };
    mmPerArtPx = w / artBox.w;
  }
  return { patchWmm, patchHmm, shape, edge, innerMm, artRectMm, mmPerArtPx, fillsPatch };
}

/** Mag het ontwerp (herkende vorm `detected`) de hele patch (gekozen vorm `chosen`) vullen? */
function shapeMatches(detected, chosen) {
  if (!detected) return true; // geen vorminformatie: oud gedrag (vult de patch)
  if (detected === chosen) return true;
  const round = (x) => x === 'circle' || x === 'oval';
  const box = (x) => x === 'rect' || x === 'rounded';
  return (round(detected) && round(chosen)) || (detected === 'rounded' && chosen === 'rect');
}

const footprints = new WeakMap();
/**
 * Waar het ontwerp echt pixels heeft, als punten in 0..1 (artBox-coördinaten): de omtrek van een herkende ronde
 * vorm, anders analysis.labels (255 = achtergrond) op een grof raster, anders (enkel bij fillsPatch) het kader.
 * @returns {number[][]|null}
 */
function footprint(analysis, fillsPatch) {
  if (analysis && typeof analysis === 'object' && footprints.has(analysis)) return footprints.get(analysis);
  const pts = [];
  const lb = analysis && analysis.labels;
  const pv = analysis && analysis.preview;
  const type = analysis && analysis.shape && analysis.shape.type;
  if (fillsPatch && (type === 'circle' || type === 'oval')) {
    for (let i = 0; i < 64; i++) { const t = (i / 64) * Math.PI * 2; pts.push([0.5 + 0.5 * Math.cos(t), 0.5 + 0.5 * Math.sin(t)]); }
  } else if (lb && pv && pv.width > 0 && pv.height > 0 && lb.length === pv.width * pv.height) {
    const W = pv.width, H = pv.height, G = 48;
    const gx = Math.min(G, W), gy = Math.min(G, H);
    for (let j = 0; j < gy; j++) {
      const y0 = Math.floor((j * H) / gy), y1 = Math.floor(((j + 1) * H) / gy);
      for (let i = 0; i < gx; i++) {
        const x0 = Math.floor((i * W) / gx), x1 = Math.floor(((i + 1) * W) / gx);
        const need = Math.min(2, (x1 - x0) * (y1 - y0)); // 2 pixels: één los ruispixel telt niet
        let n = 0;
        for (let y = y0; y < y1 && n < need; y += 1) for (let x = x0; x < x1; x += 1) if (lb[y * W + x] !== 255 && ++n >= need) break;
        if (need > 0 && n >= need) pts.push([i / gx, j / gy], [(i + 1) / gx, j / gy], [i / gx, (j + 1) / gy], [(i + 1) / gx, (j + 1) / gy]);
      }
    }
  }
  if (!pts.length && fillsPatch) {
    for (let i = 0; i <= 8; i++) { const t = i / 8; pts.push([t, 0], [t, 1], [0, t], [1, t]); }
  }
  const out = pts.length ? pts : null; // zonder pixelinformatie (synthetische analyse): geen correctie
  if (analysis && typeof analysis === 'object') footprints.set(analysis, out);
  return out;
}

/** Halve breedte van de patchvorm op hoogte y (0..H), of -1 buiten de vorm. Zelfde vormen als render/shapes.js. */
function halfWidthAt(shape, W, H, y) {
  if (y < 0 || y > H) return -1;
  if (shape === 'circle' || shape === 'oval') {
    const v = (y - H / 2) / (H / 2);
    return v * v > 1 ? -1 : (W / 2) * Math.sqrt(1 - v * v);
  }
  if (shape === 'shield') {
    const side = H * 0.42, r = Math.min(W, H) * 0.06;
    if (y < r) return W / 2 - r + Math.sqrt(Math.max(0, r * r - (r - y) * (r - y)));
    if (y <= side) return W / 2;
    // quadratic bezier (W,side) → ctrl (W,0.8H) → (W/2,H): y(t) monotonic, half width = W/2 (1 - t²)
    let lo = 0, hi = 1;
    for (let i = 0; i < 30; i++) {
      const t = (lo + hi) / 2, yt = (1 - t) * (1 - t) * side + 2 * (1 - t) * t * 0.8 * H + t * t * H;
      if (yt < y) lo = t; else hi = t;
    }
    const t = (lo + hi) / 2;
    return (W / 2) * (1 - t * t);
  }
  if (shape === 'rounded') {
    const r = Math.min(W, H) * 0.18;
    const dy = y < r ? r - y : y > H - r ? y - (H - r) : 0;
    return W / 2 - r + Math.sqrt(Math.max(0, r * r - dy * dy));
  }
  return W / 2;
}

/**
 * Grootste schaal k (≤ 1) waarmee het ontwerp (w×h mm, voetafdruk pts) binnen de patch minus safe-zone valt.
 * Voor een schild zoeken we ook de beste verticale positie (de punt onderaan is smal).
 * @returns {{k:number, cy:number}}
 */
function fitInShape(shape, W, H, safe, w, h, pts) {
  const iw = Math.max(1, W - 2 * safe), ih = Math.max(1, H - 2 * safe);
  const fits = (k, cy) => {
    for (const [u, v] of pts) {
      const x = (u - 0.5) * w * k, y = cy + (v - 0.5) * h * k - safe;
      const hw = halfWidthAt(shape, iw, ih, y);
      if (hw < 0 || Math.abs(x) > hw + 1e-6) return false;
    }
    return true;
  };
  const centres = shape === 'shield' ? [0.5, 0.47, 0.44, 0.41, 0.38].map((f) => safe + ih * f) : [H / 2];
  let best = { k: 0, cy: H / 2 };
  for (const cy of centres) {
    if (fits(1, cy)) return { k: 1, cy };
    let lo = 0, hi = 1;
    for (let i = 0; i < 18; i++) { const m = (lo + hi) / 2; if (fits(m, cy)) lo = m; else hi = m; }
    if (lo > best.k + 1e-3) best = { k: lo, cy };
  }
  return best.k > 0 ? best : { k: 1, cy: H / 2 };
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
