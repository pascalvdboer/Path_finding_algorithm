/**
 * Patch Studio — analysis core: analyzeImage() (SPEC §7.2, research 07). Pure: no DOM.
 * Runs in the Web Worker (ImageBitmap + OffscreenCanvas, or ImageData), on the main thread (fallback) and in Node (tests).
 *
 * Passes:  A (≤ sizes.a, whole image)  background + shape        → stages 'background', 'shape'
 *          B (≤ sizes.b, art crop)     palette, labels, features → stage 'colors'
 *          C (on B labels)             fine line/gap, text, stitch regions → stage 'details'
 *          P (≤ sizes.preview)         preview RGBA + labels (halo decontaminated) → stage 'preview'
 *
 * Coordinate frame of every Box / *Px value in the result: the whole source image scaled by `pxPerSrcPx`
 * ("analysis px", size = result.size). The B canvas is exactly the artBox of that frame.
 */
import { rgbToLab, labToHex, labToRgb, chroma } from '../shared/color-math.js';
import { resample, sampleBilinear, labImage, median3, now, pause, round3, hyp } from './util.js';
import { detectBackground } from './background.js';
import { detectShape, shapeCoverageFn } from './shape.js';
import { buildPalette, labelPixels, modeFilter, residualOf } from './palette.js';
import { cover85, edgeStats, labelEdges, classify } from './features.js';
import { measureDetails, regionsFromBands } from './details.js';
import { detectText } from './text.js';
import { svgInfo } from './svg-info.js';

/** Defaults = config/defaults.json → analysis (kept here so Node/worker work with a partial config). */
export const ANALYSIS_DEFAULTS = {
  sizes: { a: 600, b: 360, preview: 700, svgRaster: 2048 },
  maxFileMb: 25,
  acceptAnalyse: ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml'],
  acceptStore: ['application/pdf', '.ai', '.eps', '.psd'],
  alphaBg: 128, alphaStat: 200,
  floodNeighborDE: 6, floodBgDE: 35, floodPhotoDL: 10, floodPhotoDC: 4,
  white: { L: 92, C: 6 }, black: { L: 15, C: 8 },
  kmeans: { k: 16, iters: 12, seed: 1 },
  mergeDE00: 7, minShare: 0.004,
  mixMaxShare: 0.08, fringeBgDE: 12,
  embroideryMergeSteps: [7, 10, 14, 18], // used by colors.reducePalette, listed for completeness
  classify: {
    flatFakeMax: 0.08, flatCoverMax: 12, photoCoverMin: 70, photoHardMax: 0.04,
    gradientHardMax: 0.012, gradientChgMax: 0.03, gradientCoverMax: 40, softShadingFakeMin: 0.15,
  },
  mockup: { textureMin: 0.6, hardMin: 0.04 },
  text: { hMin: 0.008, hMax: 0.15, aspectMin: 0.08, aspectMax: 1.6, fillMin: 0.15, fillMax: 0.85, swMin: 0.05, swMax: 0.35, cvMax: 0.6, serifCv: 0.4, minRun: 3 },
  lowResMinPx: 500,
  shapeMinConfidence: 0.75,
  // Not in defaults.json → analysis: reference used for the fixed `regions` fields (stitches.classifyMm, sizes.defaultCm).
  ref: { defaultCm: 8, classifyMm: { runBelow: 1.0, fillAbove: 7.0 } },
};

/** Accepts the full public config or just config.analysis (worker protocol). */
export function analysisConfig(config) {
  const c = config || {};
  const a = c.analysis || c;
  const out = {};
  for (const [k, v] of Object.entries(ANALYSIS_DEFAULTS)) {
    out[k] = v && typeof v === 'object' && !Array.isArray(v) ? Object.assign({}, v, a[k] || {}) : (a[k] ?? v);
  }
  if (c.sizes && c.sizes.defaultCm) out.ref.defaultCm = c.sizes.defaultCm;
  if (c.stitches && c.stitches.classifyMm) out.ref.classifyMm = Object.assign({}, out.ref.classifyMm, c.stitches.classifyMm);
  return out;
}

function fail(code, message) {
  const e = new Error(message || code);
  e.code = code;
  return e;
}

/** Pixel source: pure ImageData resampler, or OffscreenCanvas for ImageBitmap (worker). */
function makeSource(input) {
  if (input && input.data && input.width > 0 && input.height > 0) {
    // Huge raw images (Node / no-OffscreenCanvas fallback): reduce once to a working copy ≤ 2048 px,
    // every later crop then reads far fewer pixels. Source coordinates are kept.
    const W = input.width, H = input.height, k = Math.min(1, 2048 / Math.max(W, H));
    const work = k < 0.75 ? resample(input, 0, 0, W, H, Math.max(1, Math.round(W * k)), Math.max(1, Math.round(H * k))) : input;
    const kx = work.width / W, ky = work.height / H;
    return { w: W, h: H, crop: (x, y, w, h, dw, dh) => resample(work, x * kx, y * ky, w * kx, h * ky, dw, dh) };
  }
  if (input && input.width > 0 && input.height > 0 && typeof OffscreenCanvas !== 'undefined') {
    return {
      w: input.width, h: input.height,
      crop(sx, sy, sw, sh, dw, dh) {
        let img = input, x = sx, y = sy, w = sw, h = sh;
        // Halve in steps to avoid aliasing (moiré on fabric) on big phone photos (07 §3.1).
        while (w > dw * 2.5 || h > dh * 2.5) {
          const nw = Math.max(dw, Math.ceil(w / 2)), nh = Math.max(dh, Math.ceil(h / 2));
          const c = new OffscreenCanvas(nw, nh), cx = c.getContext('2d');
          cx.imageSmoothingEnabled = true; cx.imageSmoothingQuality = 'high';
          cx.drawImage(img, x, y, w, h, 0, 0, nw, nh);
          img = c; x = 0; y = 0; w = nw; h = nh;
        }
        const c = new OffscreenCanvas(dw, dh), cx = c.getContext('2d', { willReadFrequently: true });
        cx.imageSmoothingEnabled = true; cx.imageSmoothingQuality = 'high';
        cx.drawImage(img, x, y, w, h, 0, 0, dw, dh);
        const id = cx.getImageData(0, 0, dw, dh);
        return { width: dw, height: dh, data: id.data };
      },
    };
  }
  throw fail('unsupported_input', 'analyzeImage needs ImageData or (in a worker) an ImageBitmap with OffscreenCanvas');
}

const dims = (w, h, max) => {
  const s = Math.min(1, max / Math.max(w, h));
  return { s, w: Math.max(1, Math.round(w * s)), h: Math.max(1, Math.round(h * s)) };
};
const scaleBox = (b, k) => ({ x: round2(b.x * k), y: round2(b.y * k), w: round2(b.w * k), h: round2(b.h * k) });
const round2 = (v) => Math.round(v * 100) / 100;

/**
 * @param {Object} input ImageDataLike {width,height,data} or ImageBitmap
 * @param {Object} config full public config or config.analysis
 * @param {{job?:number, svgText?:string|null, onProgress?:Function|null, shouldAbort?:Function|null, srcSize?:{w:number,h:number}|null}} [opts]
 *        srcSize: real source size when `input` was already downscaled (e.g. ImageData made from a big bitmap)
 * @returns {Promise<import('../shared/types.js').AnalysisResult>}
 */
export async function analyzeImage(input, config, { job = 1, svgText = null, onProgress = null, shouldAbort = null, srcSize = null } = {}) {
  const A = analysisConfig(config);
  const timings = {};
  const t0 = now();
  let tl = t0;
  const lap = (k) => { const t = now(); timings[k] = Math.round(t - tl); tl = t; };
  const emit = (stage, partial) => { if (onProgress) try { onProgress({ job, stage, partial }); } catch (e) { /* UI errors must not break analysis */ } };
  const step = async () => { await pause(); if (shouldAbort && shouldAbort()) throw fail('cancelled'); };

  const src = makeSource(input);
  const SW = src.w, SH = src.h;
  const real = srcSize && srcSize.w > 0 && srcSize.h > 0 ? { w: srcSize.w, h: srcSize.h } : { w: SW, h: SH };
  const realK = real.w / SW; // real source px per input px
  const svg = svgInfo(svgText);
  const warnings = [];

  /* ---------------- Pass A: background ---------------- */
  const da = dims(SW, SH, A.sizes.a);
  const sA = da.w / SW;
  const imgA = src.crop(0, 0, SW, SH, da.w, da.h);
  const bg = detectBackground(imgA, A);
  let nearlyEmpty = false;
  if (!bg.box || bg.fgCount < Math.max(4, da.w * da.h * 0.0005)) {
    nearlyEmpty = true;
    if (!bg.box) { bg.bgMask.fill(0); bg.box = { x: 0, y: 0, w: da.w, h: da.h }; bg.fgCount = da.w * da.h; }
  }
  const bgHex = bg.bgLab ? labToHex(bg.bgLab) : null;
  lap('background');
  emit('background', { src: real, background: { type: bg.type, hex: bgHex, keep: false } });
  await step();

  /* ---------------- Pass A: shape ---------------- */
  const shape = nearlyEmpty ? { type: null, confidence: 0, fillsPatch: false, box: null, aspect: 1, ellipse: null, rectify: false }
    : detectShape(bg.bgMask, da.w, da.h, bg);
  const isPatchPhoto = !!(shape.fillsPatch && shape.type !== 'contour' && bg.photoLike && (bg.type === 'white' || bg.type === 'solid'));
  // Light, neutral fabric/paper under a photographed patch (shade makes it greyish) counts as white.
  if (isPatchPhoto && bg.type === 'solid' && bg.bgLab[0] > 70 && chroma(bg.bgLab) < 10) bg.type = 'white';
  const keep = bg.type === 'solid' && !shape.fillsPatch && !isPatchPhoto;
  const background = { type: bg.type, hex: bgHex, keep };
  const contentSrc = scaleBox(bg.box, 1 / sA);
  const artSrc = shape.fillsPatch && shape.box ? scaleBox(shape.box, 1 / sA) : contentSrc;
  const geomCov = shape.fillsPatch ? shapeCoverageFn(shape) : null;

  // Source sampling of the art box (with ellipse → circle rectification for tilted phone photos, 07 §4.4a).
  let rect = null;
  if (shape.rectify && shape.ellipse) {
    const e = shape.ellipse, r = shape.box.w / 2;
    const ct = Math.cos(e.theta), st = Math.sin(e.theta), ka = e.a / r, kb = e.b / r;
    // M = R·diag(ka,kb)·Rᵀ maps the circle frame onto the photographed ellipse.
    rect = {
      cx: e.cx / sA, cy: e.cy / sA, ext: Math.max(e.a, e.b) / sA,
      m00: ka * ct * ct + kb * st * st, m01: (ka - kb) * ct * st, m11: ka * st * st + kb * ct * ct,
    };
  }
  const sampleArt = (dw, dh) => {
    if (!rect) return src.crop(artSrc.x, artSrc.y, artSrc.w, artSrc.h, dw, dh);
    const k = dw / artSrc.w, R = { x: rect.cx - rect.ext - 2, y: rect.cy - rect.ext - 2, w: 2 * rect.ext + 4, h: 2 * rect.ext + 4 };
    const rw = Math.max(1, Math.ceil(R.w * k)), rh = Math.max(1, Math.ceil(R.h * k));
    const reg = src.crop(R.x, R.y, R.w, R.h, rw, rh);
    const out = new Uint8ClampedArray(dw * dh * 4), kx = rw / R.w, ky = rh / R.h;
    for (let v = 0; v < dh; v++) for (let u = 0; u < dw; u++) {
      const qx = artSrc.x + (u + 0.5) / k - rect.cx, qy = artSrc.y + (v + 0.5) / k - rect.cy;
      const px = rect.cx + rect.m00 * qx + rect.m01 * qy, py = rect.cy + rect.m01 * qx + rect.m11 * qy;
      sampleBilinear(reg, (px - R.x) * kx, (py - R.y) * ky, out, (v * dw + u) * 4);
    }
    return { width: dw, height: dh, data: out };
  };

  // Design mask of a sampled art image: geometric patch shape, or the pass-A background mask refined by colour.
  const fgA = (x, y) => {
    const W = da.w, H = da.h;
    x -= 0.5; y -= 0.5;
    const x0 = Math.max(0, Math.min(W - 1, Math.floor(x))), y0 = Math.max(0, Math.min(H - 1, Math.floor(y)));
    const x1 = Math.min(W - 1, x0 + 1), y1 = Math.min(H - 1, y0 + 1);
    const fx = Math.max(0, Math.min(1, x - x0)), fy = Math.max(0, Math.min(1, y - y0));
    const m = (xx, yy) => 1 - bg.bgMask[yy * W + xx];
    return (m(x0, y0) * (1 - fx) + m(x1, y0) * fx) * (1 - fy) + (m(x0, y1) * (1 - fx) + m(x1, y1) * fx) * fy;
  };
  const buildMask = (img) => {
    const W = img.width, H = img.height, d = img.data, n = W * H;
    const cov = new Float32Array(n), mask = new Uint8Array(n), amb = new Uint8Array(n);
    const kx = artSrc.w / W, ky = artSrc.h / H;
    const pxA = kx * sA; // pixel size in pass-A units
    let count = 0;
    for (let v = 0; v < H; v++) for (let u = 0; u < W; u++) {
      const p = v * W + u, alpha = d[p * 4 + 3];
      let c;
      if (geomCov) {
        c = geomCov((artSrc.x + (u + 0.5) * kx) * sA, (artSrc.y + (v + 0.5) * ky) * sA, pxA);
      } else if (nearlyEmpty) {
        c = 1;
      } else {
        const m = fgA((artSrc.x + (u + 0.5) * kx) * sA, (artSrc.y + (v + 0.5) * ky) * sA);
        if (m > 0.02 && m < 0.98 && bg.bgLab && alpha >= A.alphaBg) {
          const lab = rgbToLab(d[p * 4], d[p * 4 + 1], d[p * 4 + 2]);
          c = hyp(lab[0] - bg.bgLab[0], lab[1] - bg.bgLab[1], lab[2] - bg.bgLab[2]) > 12 ? 1 : 0;
          amb[p] = 1;
        } else c = m >= 0.5 ? 1 : 0;
      }
      cov[p] = c;
      if (c >= 0.5 && alpha >= A.alphaBg) { mask[p] = 1; count++; }
    }
    if (!count) for (let p = 0; p < n; p++) if (d[p * 4 + 3] >= A.alphaBg) { mask[p] = 1; cov[p] = 1; }
    return { cov, mask, amb };
  };
  // Anti-aliasing fringe on the design edge (accuracy review): a mixed edge pixel that is nearest to a palette colour
  // equal to the background (e.g. the light grey rim of black text on white, when white is also a thread colour inside
  // the design) is background, not a 1 px "line" of that colour. Without this every glyph gets a fake hairline.
  const dropFringe = (L, mask, amb, palLab, W) => {
    if (!bg.bgLab || !palLab.length || geomCov) return L;
    const near = palLab.map((l) => hyp(l[0] - bg.bgLab[0], l[1] - bg.bgLab[1], l[2] - bg.bgLab[2]) < A.fringeBgDE);
    if (!near.some(Boolean)) return L;
    const n = L.length, H = n / W;
    const out = (q) => L[q] < 0;
    for (let pass = 0; pass < 2; pass++) {
      const drop = [];
      for (let p = 0; p < n; p++) {
        if (L[p] < 0 || !near[L[p]]) continue;
        const x = p % W, y = (p - x) / W;
        const touches = (x > 0 && out(p - 1)) || (x < W - 1 && out(p + 1)) || (y > 0 && out(p - W)) || (y < H - 1 && out(p + W));
        if ((pass === 0 && amb[p]) || touches) drop.push(p);
      }
      if (!drop.length) break;
      for (const p of drop) { L[p] = -1; mask[p] = 0; }
    }
    return L;
  };

  lap('shape');
  emit('shape', {
    background, isPatchPhoto,
    shape: { type: shape.type, confidence: round2(shape.confidence), fillsPatch: shape.fillsPatch, aspect: round3(shape.aspect || 1) },
  });
  await step();

  /* ---------------- Pass B: colours ---------------- */
  const db = dims(artSrc.w, artSrc.h, A.sizes.b);
  const sB = db.w / artSrc.w;
  const Wb = db.w, Hb = db.h, nB = Wb * Hb;
  let imgB = sampleArt(Wb, Hb);
  const { mask: maskB, amb: ambB } = buildMask(imgB);
  let labB = labImage(imgB);
  const statMask = new Uint8Array(nB);
  for (let y = 0; y < Hb; y++) for (let x = 0; x < Wb; x++) {
    const p = y * Wb + x;
    if (!maskB[p] || imgB.data[p * 4 + 3] < A.alphaStat) continue;
    const edge = (x > 0 && !maskB[p - 1]) || (x < Wb - 1 && !maskB[p + 1]) || (y > 0 && !maskB[p - Wb]) || (y < Hb - 1 && !maskB[p + Wb]);
    if (!edge) statMask[p] = 1;
  }
  let anyStat = false;
  for (let p = 0; p < nB && !anyStat; p++) anyStat = statMask[p] === 1;
  const { pal, pts } = buildPalette(imgB, labB, anyStat ? statMask : maskB, maskB, Wb, Hb, A, { kL: isPatchPhoto ? 2 : 1, bgLab: bg.bgLab });
  let palLab = pal.map((c) => c.lab);
  const L0 = palLab.length ? dropFringe(labelPixels(imgB, labB, maskB, palLab), maskB, ambB, palLab, Wb) : new Int16Array(nB).fill(-1);
  const es = edgeStats(labB, Wb, Hb, maskB);
  const le = labelEdges(labB, L0, Wb, Hb);
  const features = {
    cover: cover85(imgB, maskB), hard: round3(es.hard), chg: round3(le.chg), fake: round3(le.fake),
    residual: round3(residualOf(pts, palLab)), texture: round3(es.texture),
  };
  const kind = classify(features, A.classify);
  let hasSoftShading = features.fake >= A.classify.softShadingFakeMin;
  const isMockup = kind === 'illustration' && features.texture > A.mockup.textureMin && features.hard > A.mockup.hardMin;
  let Lraw = L0;
  if (isMockup && palLab.length) {
    // Fabric texture of a mock-up would create false fine lines: median first (07 §6.3).
    imgB = median3(imgB);
    labB = labImage(imgB);
    Lraw = labelPixels(imgB, labB, maskB, palLab);
  }
  let Lb = modeFilter(Lraw, Wb, Hb, Math.max(1, pal.length));
  // Shares from final labels; drop colours that vanished; sort by share.
  const cnt = new Float64Array(pal.length);
  let tot = 0;
  for (let p = 0; p < nB; p++) if (Lb[p] >= 0) { cnt[Lb[p]]++; tot++; }
  const order = pal.map((_, i) => i).filter((i) => cnt[i] > 0).sort((a, b) => cnt[b] - cnt[a] || a - b);
  const remap = new Int16Array(pal.length).fill(-1);
  order.forEach((old, i) => { remap[old] = i; });
  for (let p = 0; p < nB; p++) if (Lb[p] >= 0) Lb[p] = remap[Lb[p]];
  const palette = order.map((i) => ({
    hex: labToHex(pal[i].lab),
    lab: /** @type {[number,number,number]} */ (pal[i].lab.map(round2)),
    share: round3(cnt[i] / (tot || 1)),
    thin: !!pal[i].thin,
  }));
  palLab = order.map((i) => pal[i].lab);
  if (svg && svg.hasGradient) { hasSoftShading = true; warnings.push('svg_gradient'); }
  if (svg && svg.hasEmbeddedRaster) warnings.push('svg_embedded_raster');
  lap('colors');
  emit('colors', { palette, kind, hasSoftShading, isMockup, features });
  await step();

  /* ---------------- Pass C: details ---------------- */
  const det = measureDetails(Lb, { W: Wb, H: Hb, fillsPatch: shape.fillsPatch, baseLabel: 0 });
  const offX = artSrc.x * sB, offY = artSrc.y * sB;
  const textLines = (kind === 'photo' ? [] : detectText(Lb, det.D, det.ridge, Wb, Hb, A.text, { palLab, fillsPatch: shape.fillsPatch, Dout: det.Dout })).map((t) => ({
    ...t, box: { x: round2(t.box.x + offX), y: round2(t.box.y + offY), w: t.box.w, h: t.box.h },
  }));
  const regions = regionsFromBands(det.bands, det.objects, (A.ref.defaultCm * 10) / Math.max(Wb, Hb), A.ref.classifyMm);
  const fineLinePx = det.fineLinePx == null || nearlyEmpty ? null : round2(det.fineLinePx);
  const fineGapPx = det.fineGapPx == null || nearlyEmpty ? null : round2(det.fineGapPx);
  lap('details');
  emit('details', { fineLinePx, fineGapPx, textLines, regions });
  await step();

  /* ---------------- Preview ---------------- */
  const dp = dims(artSrc.w, artSrc.h, A.sizes.preview);
  const Wp = dp.w, Hp = dp.h, nP = Wp * Hp;
  const imgP = sampleArt(Wp, Hp);
  const { cov: covP, mask: maskP, amb: ambP } = buildMask(imgP);
  const labP = labImage(imgP);
  let Lp = palLab.length ? dropFringe(labelPixels(imgP, labP, maskP, palLab), maskP, ambP, palLab, Wp) : new Int16Array(nP).fill(-1);
  Lp = modeFilter(Lp, Wp, Hp, Math.max(1, palLab.length));
  const pd = imgP.data;
  const geometric = !!(rect || geomCov);
  for (let p = 0; p < nP; p++) {
    const a = pd[p * 4 + 3];
    pd[p * 4 + 3] = geometric ? Math.round(a * covP[p]) : maskP[p] ? a : 0;
  }
  // Halo decontamination on the design edge (07 §4.3): mixed edge pixels get the inner colour + partial alpha.
  if (!geometric && bg.bgLab && palLab.length) {
    const bgRgb = labToRgb(bg.bgLab[0], bg.bgLab[1], bg.bgLab[2]);
    const palRgb = palLab.map((l) => labToRgb(l[0], l[1], l[2]));
    const edgeP = new Uint8Array(nP);
    for (let y = 0; y < Hp; y++) for (let x = 0; x < Wp; x++) {
      const p = y * Wp + x;
      if (maskP[p] && ((x > 0 && !maskP[p - 1]) || (x < Wp - 1 && !maskP[p + 1]) || (y > 0 && !maskP[p - Wp]) || (y < Hp - 1 && !maskP[p + Wp]))) edgeP[p] = 1;
    }
    const votes = new Int32Array(palLab.length);
    for (let y = 0; y < Hp; y++) for (let x = 0; x < Wp; x++) {
      const p = y * Wp + x;
      if (!edgeP[p]) continue;
      votes.fill(0);
      let any = false;
      for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) {
        const xx = x + dx, yy = y + dy;
        if (xx < 0 || yy < 0 || xx >= Wp || yy >= Hp) continue;
        const q = yy * Wp + xx;
        if (!maskP[q] || Lp[q] < 0 || edgeP[q]) continue;
        votes[Lp[q]]++; any = true;
      }
      if (!any) continue;
      let li = 0;
      for (let j = 1; j < votes.length; j++) if (votes[j] > votes[li]) li = j;
      const f = palRgb[li], i = p * 4;
      const fx = f[0] - bgRgb[0], fy = f[1] - bgRgb[1], fz = f[2] - bgRgb[2], ff = fx * fx + fy * fy + fz * fz;
      if (ff < 100) continue;
      const t = Math.max(0, Math.min(1, ((pd[i] - bgRgb[0]) * fx + (pd[i + 1] - bgRgb[1]) * fy + (pd[i + 2] - bgRgb[2]) * fz) / ff));
      const mix = rgbToLab(Math.round(bgRgb[0] + fx * t), Math.round(bgRgb[1] + fy * t), Math.round(bgRgb[2] + fz * t));
      const dE = hyp(labP[p * 3] - mix[0], labP[p * 3 + 1] - mix[1], labP[p * 3 + 2] - mix[2]);
      if (dE < 4) { pd[i] = f[0]; pd[i + 1] = f[1]; pd[i + 2] = f[2]; pd[i + 3] = Math.round(pd[i + 3] * t); Lp[p] = li; }
    }
  }
  const labels = new Uint8Array(nP);
  for (let p = 0; p < nP; p++) {
    if (pd[p * 4 + 3] === 0) { pd[p * 4] = pd[p * 4 + 1] = pd[p * 4 + 2] = 0; }
    labels[p] = Lp[p] >= 0 && pd[p * 4 + 3] >= A.alphaBg ? Lp[p] : 255;
  }
  const preview = { width: Wp, height: Hp, data: pd };

  let backdrop = null;
  if (keep) {
    const sK = Math.min(dp.s, 1000 / Math.max(SW, SH));
    const bw = Math.max(1, Math.round(SW * sK)), bh = Math.max(1, Math.round(SH * sK));
    const bimg = src.crop(0, 0, SW, SH, bw, bh);
    backdrop = { width: bw, height: bh, data: bimg.data, artBox: scaleBox(artSrc, sK) };
  }

  /* ---------------- Resolution + warnings ---------------- */
  const effectiveLongestPx = Math.round(Math.max(artSrc.w, artSrc.h) * realK);
  const vector = !!svg && !svg.hasEmbeddedRaster;
  const lowRes = !vector && effectiveLongestPx < A.lowResMinPx;
  if (lowRes) warnings.push('low_res');
  if (bg.shadowShare > 0.03) warnings.push('transparent_shadow');
  if (isMockup) warnings.push('mockup');
  if (isPatchPhoto) warnings.push('patch_photo_cut');
  if (nearlyEmpty) warnings.push('nearly_empty');
  if (bg.type === 'transparent') {
    let w = 0;
    palette.forEach((c) => { if (c.lab[0] > A.white.L && chroma(c.lab) < A.white.C) w += c.share; });
    if (w >= 0.5) warnings.push('white_on_transparent');
  }
  if (shape.type && shape.confidence < A.shapeMinConfidence) warnings.push('shape_uncertain');
  lap('preview');
  timings.total = Math.round(now() - t0);

  const artBox = { x: round2(offX), y: round2(offY), w: Wb, h: Hb };
  const contentBox = scaleBox(contentSrc, sB);
  /** @type {import('../shared/types.js').AnalysisResult & {backdrop?:Object}} */
  const result = {
    v: 1, job,
    source: svgText ? 'svg' : 'raster',
    src: real,
    size: { w: Math.max(1, Math.round(SW * sB)), h: Math.max(1, Math.round(SH * sB)) },
    pxPerSrcPx: sB / realK,
    kind, hasSoftShading, isMockup, isPatchPhoto,
    background,
    shape: {
      type: shape.type, confidence: round2(shape.confidence), fillsPatch: !!shape.fillsPatch,
      aspect: round3(shape.fillsPatch ? Wb / Hb : shape.aspect || Wb / Hb),
      box: shape.fillsPatch ? { ...artBox } : contentBox,
    },
    contentBox, artBox, palette, fineLinePx, fineGapPx, textLines, regions, features,
    resolution: { effectiveLongestPx, lowRes },
    warnings, preview, labels, timings,
  };
  if (backdrop) result.backdrop = backdrop;
  emit('preview', { resolution: result.resolution, warnings });
  return result;
}
