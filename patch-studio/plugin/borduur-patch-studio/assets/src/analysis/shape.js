/**
 * Patch Studio — analysis pass A: is the design itself already a patch shape? (BRIEF owner feedback 2, 07 §4.4)
 * circle / oval (moment-fitted ellipse, tolerates a slightly tilted phone photo), rect, rounded, shield, contour.
 * Pure. Coordinates are pass-A pixels.
 */
import { components, clamp } from './util.js';

/**
 * @param {Uint8Array} bgMask 1 = background
 * @param {number} W @param {number} H
 * @param {{type:string, photoLike:boolean, fgCount:number}} bg
 * @returns {{type:string|null, confidence:number, fillsPatch:boolean, box:{x,y,w,h}|null, aspect:number,
 *   ellipse:{cx:number,cy:number,a:number,b:number,theta:number}|null, rectify:boolean, cornerR:number}}
 */
export function detectShape(bgMask, W, H, bg) {
  const none = { type: null, confidence: 0, fillsPatch: false, box: null, aspect: 1, ellipse: null, rectify: false, cornerR: 0 };
  const n = W * H;
  if (!bg.fgCount) return none;

  // Full bleed: the image itself is the patch (rectangle), the customer may still pick another shape.
  if (bg.type === 'none') {
    return { ...none, type: 'rect', confidence: 0.5, fillsPatch: true, box: { x: 0, y: 0, w: W, h: H }, aspect: W / H };
  }

  const L = new Int16Array(n);
  for (let p = 0; p < n; p++) L[p] = bgMask[p] ? -1 : 0;
  const comp = components(L, W, H, false);
  let main = 0;
  for (let c = 1; c < comp.count; c++) if (comp.size[c] > comp.size[main]) main = c;
  const mSize = comp.size[main];
  if (mSize < 400 || mSize < n * 0.01) return none;
  const mainShare = mSize / bg.fgCount;
  const bx = comp.minX[main], by = comp.minY[main], bw = comp.maxX[main] - bx + 1, bh = comp.maxY[main] - by + 1;
  if (bw < 20 || bh < 20) return none;
  const inMain = (p) => comp.id[p] === main;

  // ---- Ellipse from second moments of the main component.
  const cx = comp.sx[main] / mSize, cy = comp.sy[main] / mSize;
  let sxx = 0, syy = 0, sxy = 0;
  for (let y = by; y < by + bh; y++) for (let x = bx; x < bx + bw; x++) {
    const p = y * W + x;
    if (!inMain(p)) continue;
    const dx = x - cx, dy = y - cy;
    sxx += dx * dx; syy += dy * dy; sxy += dx * dy;
  }
  sxx /= mSize; syy /= mSize; sxy /= mSize;
  const tr = (sxx + syy) / 2, det = Math.sqrt(((sxx - syy) / 2) ** 2 + sxy * sxy);
  const l1 = tr + det, l2 = Math.max(1e-6, tr - det);
  let theta = 0.5 * Math.atan2(2 * sxy, sxx - syy);
  let a = 2 * Math.sqrt(l1), b = 2 * Math.sqrt(l2); // semi-axes of a uniformly filled ellipse
  const ct = Math.cos(theta), st = Math.sin(theta);
  let inE = 0, inFg = 0, outE = 0, outFg = 0;
  const ext = Math.max(a, b) * 1.35;
  const ex0 = Math.max(0, Math.floor(cx - ext)), ex1 = Math.min(W - 1, Math.ceil(cx + ext));
  const ey0 = Math.max(0, Math.floor(cy - ext)), ey1 = Math.min(H - 1, Math.ceil(cy + ext));
  for (let y = ey0; y <= ey1; y++) for (let x = ex0; x <= ex1; x++) {
    const dx = x + 0.5 - cx - 0.5, dy = y + 0.5 - cy - 0.5;
    const u = dx * ct + dy * st, v = -dx * st + dy * ct;
    const e = (u / a) ** 2 + (v / b) ** 2;
    const fg = !bgMask[y * W + x];
    if (e <= 0.92) { inE++; if (fg) inFg++; } else if (e >= 1.08 && e <= 1.6) { outE++; if (fg) outFg++; }
  }
  // Ellipse must be (nearly) inside the image.
  const xr = Math.sqrt(a * a * ct * ct + b * b * st * st), yr = Math.sqrt(a * a * st * st + b * b * ct * ct);
  const insideImg = cx - xr > -0.03 * W && cx + xr < W * 1.03 && cy - yr > -0.03 * H && cy + yr < H * 1.03;
  const inR = inE ? inFg / inE : 0, outR = outE ? outFg / outE : 1;
  // Nothing substantial may lie outside the ellipse (e.g. a club name under a round logo ⇒ not a round patch).
  let farFg = 0;
  if (insideImg && inR > 0.97 && outR < 0.12) {
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
      if (bgMask[y * W + x]) continue;
      const dx = x - cx, dy = y - cy, u = dx * ct + dy * st, v = -dx * st + dy * ct;
      if ((u / a) ** 2 + (v / b) ** 2 > 1.15) farFg++;
    }
  }
  if (insideImg && inR > 0.97 && outR < 0.12 && farFg < bg.fgCount * 0.02) {
    const ratio = b / a;
    const conf = clamp(0.55 + 0.45 * Math.min((inR - 0.97) / 0.025, (0.12 - outR) / 0.1, 1), 0, 0.98);
    const ellipse = { cx: cx + 0.5, cy: cy + 0.5, a, b, theta };
    if (ratio >= 0.92 || (bg.photoLike && ratio >= 0.8)) {
      // Circle (possibly photographed at a slight angle → rectify to a true circle).
      const rectify = ratio < 0.97;
      const r = rectify ? Math.sqrt(a * b) : (a + b) / 2;
      return { ...none, type: 'circle', confidence: conf, fillsPatch: true, ellipse, rectify,
        box: { x: ellipse.cx - r, y: ellipse.cy - r, w: 2 * r, h: 2 * r }, aspect: 1 };
    }
    // Oval: keep it axis-aligned (rotated ovals are rare; the bounding box keeps the full design visible).
    return { ...none, type: 'oval', confidence: conf * (Math.abs(st * ct) > 0.17 ? 0.8 : 1), fillsPatch: true, ellipse,
      box: { x: ellipse.cx - xr, y: ellipse.cy - yr, w: 2 * xr, h: 2 * yr }, aspect: xr / yr };
  }

  // ---- Rectangle / rounded rectangle (axis aligned).
  let fillCount = 0;
  for (let y = by; y < by + bh; y++) for (let x = bx; x < bx + bw; x++) if (!bgMask[y * W + x]) fillCount++;
  const fill = fillCount / (bw * bh);
  const box = { x: bx, y: by, w: bw, h: bh };
  if (fill > 0.88 && mainShare > 0.97) {
    // Corner test first: empty corners + full sides ⇒ rounded rectangle (also slightly rounded ones).
    const m = Math.min(bw, bh);
    let rBest = 0;
    for (const rr of [0.05, 0.08, 0.12, 0.16, 0.2, 0.25, 0.3]) {
      const r = rr * m;
      let tot = 0, bad = 0;
      for (let y = by; y < by + bh; y++) for (let x = bx; x < bx + bw; x++) {
        const qx = Math.min(x - bx + 0.5, bx + bw - x - 0.5), qy = Math.min(y - by + 0.5, by + bh - y - 0.5);
        if (qx >= r || qy >= r) continue;
        const outside = (r - qx) ** 2 + (r - qy) ** 2 > r * r;
        tot++;
        if (outside === !bgMask[y * W + x]) bad++;
      }
      if (tot && bad / tot < 0.12) rBest = r;
    }
    if (rBest >= 3) return { ...none, type: 'rounded', confidence: 0.8, fillsPatch: true, box, aspect: bw / bh, cornerR: rBest };
    if (fill > 0.975) {
      return { ...none, type: 'rect', confidence: clamp(0.6 + (fill - 0.975) * 15, 0, 0.97), fillsPatch: true, box, aspect: bw / bh };
    }
  }

  // ---- Shield: flat full top, straight-ish sides, tapers to a point at the bottom, mirror symmetric.
  if (mainShare > 0.97 && fill > 0.6 && fill < 0.93) {
    const width = (fy) => {
      const y = Math.min(by + bh - 1, Math.max(by, Math.round(by + fy * (bh - 1))));
      let c = 0;
      for (let x = bx; x < bx + bw; x++) if (!bgMask[y * W + x]) c++;
      return c / bw;
    };
    let sym = 0, symN = 0;
    for (let y = by; y < by + bh; y++) for (let x = bx; x < bx + (bw >> 1); x++) {
      const xm = bx + bw - 1 - (x - bx);
      const a1 = !bgMask[y * W + x], a2 = !bgMask[y * W + xm];
      if (a1 || a2) { symN++; if (a1 === a2) sym++; }
    }
    const symR = symN ? sym / symN : 0;
    const top = (width(0.04) + width(0.1)) / 2, mid = width(0.45), low = width(0.8), tip = width(0.97);
    if (symR > 0.92 && top > 0.8 && mid > 0.85 && low < mid - 0.05 && tip < 0.45 && bh / bw > 0.8 && bh / bw < 1.6) {
      return { ...none, type: 'shield', confidence: clamp(0.55 + (symR - 0.92) * 3 + (0.45 - tip) * 0.5, 0, 0.9), fillsPatch: true, box, aspect: bw / bh };
    }
  }

  // ---- Contour: one silhouette on white/transparent (never on a coloured background: that becomes the patch fill).
  if ((bg.type === 'white' || bg.type === 'transparent') && mainShare > 0.97 && mSize > n * 0.02) {
    return { ...none, type: 'contour', confidence: 0.55, fillsPatch: true, box, aspect: bw / bh };
  }
  return none;
}

/**
 * Anti-aliased coverage (0..1) of the recognised shape for a pixel centred at (x, y) with size `s`
 * (pass-A pixel coordinates). Returns null when the shape is not geometric (shield/contour/none):
 * callers then use the background mask.
 */
export function shapeCoverageFn(shape) {
  if (!shape || !shape.fillsPatch) return null;
  const ramp = (sd, s) => (sd <= -s / 2 ? 1 : sd >= s / 2 ? 0 : 0.5 - sd / s); // sd = signed distance (outside > 0)
  if (shape.type === 'circle' || shape.type === 'oval') {
    if (shape.type === 'circle' && shape.rectify) {
      const r = shape.box.w / 2, x0 = shape.box.x + r, y0 = shape.box.y + r;
      return (x, y, s) => ramp(Math.sqrt((x - x0) ** 2 + (y - y0) ** 2) - r, s);
    }
    if (!shape.ellipse) return null;
    const { cx, cy, a, b, theta } = shape.ellipse;
    const ct = Math.cos(theta), st = Math.sin(theta), m = Math.min(a, b);
    return (x, y, s) => {
      const dx = x - cx, dy = y - cy, u = dx * ct + dy * st, v = -dx * st + dy * ct;
      return ramp((Math.sqrt((u / a) ** 2 + (v / b) ** 2) - 1) * m, s);
    };
  }
  if (shape.type === 'rect' || shape.type === 'rounded') {
    const B = shape.box, r = shape.type === 'rounded' ? shape.cornerR : 0;
    return (x, y, s) => {
      const qx = Math.min(x - B.x, B.x + B.w - x), qy = Math.min(y - B.y, B.y + B.h - y); // distance inside (≥0 inside)
      if (r > 0 && qx < r && qy < r) return ramp(Math.hypot(r - qx, r - qy) - r, s);
      return ramp(-Math.min(qx, qy), s);
    };
  }
  return null;
}
