/**
 * Patch Studio — material geometry for design/text layers and patch bases (pure, typed arrays).
 *
 * buildLayer(img, look, o) turns a flat RGBA layer into a light-independent geometry buffer:
 *   embroidered  satin columns (domed, threads across the column, radial in rings) and tatami fill rows,
 *                thread ripples, needle grooves between regions, anisotropic thread sheen
 *   woven        fine weft floats with warp tie points, almost flat, crisp region edges
 *   printed      ink on twill (diagonal ribs), matte
 *   sublimated   dye in a fine polyester twill, soft satin-like sheen
 *   chenille     raised pile of looped yarn, matte, fuzzy towards the felt
 *   pvc          moulded plateaus with rounded shoulders ('2d') or sculpted domes ('3d'), soft rubber gloss
 *   leather      laser engraving: burnt, sunken, matte walls
 * buildBase(...) does the same for the patch body (twill, stitched background, felt, leather grain, ...).
 * Heights are in layer px (mm × px/mm); light.js#finishGeo turns them into normals.
 */
import { newGeo, finishGeo, glossByte } from './light.js';
import { regionDistance, alphaLabels } from './field.js';
import { analyzeComponents, chooseModes } from './stylize.js';

let PROF = null;
function prof() {
  if (PROF) return PROF;
  PROF = new Float32Array(257);
  for (let i = 0; i <= 256; i++) PROF[i] = Math.sin(Math.PI * (i / 256));
  return PROF;
}

const smooth = (t) => (t <= 0 ? 0 : t >= 1 ? 1 : t * t * (3 - 2 * t));
const hashI = (a, b) => ((((a * 2654435761) ^ (b * 40503)) >>> 0) / 4294967296);

/** Bilinear sampler of a cell grid at layer resolution (precomputed per row/column). */
function sampler(gw, gh, W, H) {
  const x0 = new Int32Array(W), x1 = new Int32Array(W), fx = new Float32Array(W), xn = new Int32Array(W);
  const y0 = new Int32Array(H), y1 = new Int32Array(H), fy = new Float32Array(H), yn = new Int32Array(H);
  for (let x = 0; x < W; x++) {
    const u = Math.max(0, Math.min(gw - 1, (x + 0.5) * gw / W - 0.5)), i = Math.floor(u);
    x0[x] = i; x1[x] = Math.min(gw - 1, i + 1); fx[x] = u - i; xn[x] = Math.min(gw - 1, ((x + 0.5) * gw / W) | 0);
  }
  for (let y = 0; y < H; y++) {
    const v = Math.max(0, Math.min(gh - 1, (y + 0.5) * gh / H - 0.5)), j = Math.floor(v);
    y0[y] = j * gw; y1[y] = Math.min(gh - 1, j + 1) * gw; fy[y] = v - j; yn[y] = Math.min(gh - 1, ((y + 0.5) * gh / H) | 0) * gw;
  }
  return { x0, x1, fx, xn, y0, y1, fy, yn };
}

/**
 * @param {{width:number,height:number,data:Uint8ClampedArray}} img  layer albedo (adopted, modified)
 * @param {string} look
 * @param {object} o
 * @param {number} o.ppm               layer px per mm
 * @param {object|null} [o.labels]     clean labels grid for the art (null → derived from alpha)
 * @param {object|null} [o.comps]      analyzeComponents+chooseModes for o.labels (embroidered)
 * @param {number[]} [o.engrave]       leather engraving rgb
 * @param {number} [o.relief]          relief multiplier (thickness option)
 * @param {'2d'|'3d'} [o.pvcDepth]
 */
export function buildLayer(img, look, o) {
  const W = img.width, H = img.height;
  const g = newGeo(W, H, img.data);
  const ppm = Math.max(0.3, o.ppm), relief = o.relief || 1;
  const needDist = look !== 'printed' && look !== 'sublimated' && look !== 'leather';
  let labels = o.labels || null, comps = o.comps || null;
  if (needDist && !labels) {
    const step = Math.max(1, Math.round(Math.max(W, H) / 640));
    labels = alphaLabels(img.data, W, H, step);
    if (look === 'embroidered') comps = chooseModes(analyzeComponents(labels), step / ppm, 7);
  }
  let dist = null, S = null, mmCell = 1;
  if (needDist) {
    dist = regionDistance(labels);
    S = sampler(labels.width, labels.height, W, H);
    mmCell = (W / labels.width) / ppm;
  }
  const ctx = { g, W, H, ppm, relief, dist, S, mmCell, labels, comps, o };
  switch (look) {
    case 'embroidered': embroidery(ctx); break;
    case 'woven': woven(ctx); break;
    case 'chenille': chenille(ctx); break;
    case 'pvc': pvc(ctx); break;
    case 'leather': leather(ctx); break;
    case 'sublimated': fabricPrint(ctx, 'poly'); break;
    default: fabricPrint(ctx, 'twill'); break;
  }
  return finishGeo(g, look === 'woven' || look === 'printed' || look === 'sublimated' ? 1 : 1.25);
}

function distAt(ctx, x, y) {
  const { S, dist } = ctx;
  const a = dist[S.y0[y] + S.x0[x]], b = dist[S.y0[y] + S.x1[x]], c = dist[S.y1[y] + S.x0[x]], d = dist[S.y1[y] + S.x1[x]];
  const fx = S.fx[x], fy = S.fy[y];
  return ((a * (1 - fx) + b * fx) * (1 - fy) + (c * (1 - fx) + d * fx) * fy) * ctx.mmCell;
}

/* ------------------------------ embroidery ------------------------------ */

function embroidery(ctx) {
  const P = prof();
  const { g, W, H, ppm, relief, S, labels, comps } = ctx;
  const D = g.alb, hg = g.hgt, tan = g.tan, sp = g.spec, gl = g.gloss, ao = g.ao;
  const LD = labels.data, lw = labels.width;
  const sxl = W / labels.width, syl = H / labels.height, mmCell = ctx.mmCell;
  const satPitch = Math.max(1.3, 0.4 * ppm), invSat = 1 / satPitch;
  const rowPitch = Math.max(1.6, 0.42 * ppm), invRow = 1 / rowPitch;
  const stitchInv = 1 / Math.max(5, 2.8 * ppm);
  const fade = smooth((0.4 * ppm - 1.3) / 2.2); // thread ripples only where they can be resolved
  const ripple = 0.075 * ppm * fade, rowRipple = 0.06 * ppm * fade;
  const glSatin = glossByte(4, true), glFill = glossByte(3, true);
  // per component dome height (thicker columns stand higher, like real satin) and half width
  const n = comps ? comps.n : 0;
  const domeH = new Float32Array(n), halfT = new Float32Array(n);
  for (let c = 0; c < n; c++) {
    const t = comps.thick[c] * mmCell;
    halfT[c] = Math.max(0.12, t / 2);
    domeH[c] = relief * ppm * (comps.mode[c] === 2 ? 0.22 : Math.min(0.55, 0.16 + 0.11 * t));
  }
  for (let y = 0; y < H; y++) {
    const row = y * W, yn = S.yn[y];
    for (let x = 0; x < W; x++) {
      const i = row + x, p = i * 4, a = D[p + 3];
      if (!a) continue;
      const cell = yn + S.xn[x];
      const L = LD[cell];
      const c = L === 255 || !comps ? -1 : comps.comp[cell];
      const d = distAt(ctx, x, y);
      let mode = 0, cs = 0.8776, sn = -0.4794, h0 = 0.3 * ppm * relief, ht = 0.4;
      if (c >= 0) { mode = comps.mode[c]; cs = comps.cos[c]; sn = comps.sin[c]; h0 = domeH[c]; ht = halfT[c]; }
      let h, tx, ty, f, cav;
      if (mode === 2) {
        // tatami fill: rows along the region axis, brick-offset needle points, flat top with a soft shoulder
        const v = (-x * sn + y * cs) * invRow, rI = Math.floor(v), t = v - rI;
        const q = (x * cs + y * sn) * stitchInv + rI * 0.37, tq = q - Math.floor(q);
        const pr = P[(t * 256) | 0];
        h = h0 * smooth(d / 0.45) + rowRipple * pr - (tq < 0.06 ? rowRipple * 0.8 : 0);
        tx = cs; ty = sn;
        f = 0.94 + 0.08 * pr + (hashI(rI, c) - 0.5) * 0.05;
        cav = (0.84 + 0.16 * pr) * (tq < 0.06 ? 0.86 : 1);
        sp[i] = 150; gl[i] = glFill;
      } else {
        let u;
        if (mode === 1 && c >= 0) {
          const ccx = comps.cx[c] * sxl, ccy = comps.cy[c] * syl;
          const rx = x - ccx, ry = y - ccy, rr = Math.hypot(rx, ry) || 1;
          u = Math.atan2(ry, rx) * Math.max(4, comps.radius[c] * sxl) * invSat;
          tx = rx / rr; ty = ry / rr;
        } else {
          u = (x * cs + y * sn) * invSat;
          tx = -sn; ty = cs;
        }
        const ui = Math.floor(u), t = u - ui, pr = P[(t * 256) | 0];
        // satin column: round dome across the column
        const q = Math.min(1, d / ht), dome = Math.sqrt(Math.max(0, 1 - (1 - q) * (1 - q)));
        h = h0 * dome + ripple * pr;
        f = 0.95 + 0.07 * pr + (hashI(ui, c) - 0.5) * 0.09;
        cav = 0.8 + 0.2 * pr;
        sp[i] = 215; gl[i] = glSatin;
      }
      // needle groove where two regions (or the ground) meet
      cav *= 0.6 + 0.4 * smooth(d / 0.32);
      hg[i] = h;
      tan[i * 2] = tx * 127; tan[i * 2 + 1] = ty * 127;
      ao[i] = cav * 255;
      D[p] = Math.min(255, D[p] * f); D[p + 1] = Math.min(255, D[p + 1] * f); D[p + 2] = Math.min(255, D[p + 2] * f);
      if (L !== 255 && a < 255 && d > 0.2) D[p + 3] = 255;
    }
  }
  void lw;
}

/* ------------------------------ woven ------------------------------ */

function woven(ctx) {
  const P = prof();
  const { g, W, H, ppm, relief } = ctx;
  const D = g.alb, hg = g.hgt, tan = g.tan, sp = g.spec, gl = g.gloss, ao = g.ao;
  const pitch = Math.max(2, 0.19 * ppm), inv = 1 / pitch;
  const fade = smooth((pitch - 1.6) / 1.6);
  const amp = 0.035 * ppm * fade + 0.15, base = 0.05 * ppm * relief;
  const glW = glossByte(3, true);
  for (let y = 0; y < H; y++) {
    const row = y * W, v = y * inv, rI = Math.floor(v), t = v - rI, pr = P[(t * 256) | 0];
    for (let x = 0; x < W; x++) {
      const i = row + x, p = i * 4;
      if (!D[p + 3]) continue;
      const d = distAt(ctx, x, y);
      // 5-shed satin weave: weft floats, warp tie point every 5th pitch, shifted by 2 per row
      const cpos = x * inv + rI * 2, ci = Math.floor(cpos), ct = cpos - ci;
      const tie = ((ci % 5) + 5) % 5 === 0;
      let h, f;
      if (tie) {
        const pc = P[(ct * 256) | 0];
        h = amp * 0.6 * pc; tan[i * 2] = 0; tan[i * 2 + 1] = 127; f = 0.82 + 0.1 * pc;
      } else {
        h = amp * pr; tan[i * 2] = 127; tan[i * 2 + 1] = 0; f = 0.93 + 0.09 * pr;
      }
      hg[i] = h + base * smooth(d / 0.12);
      ao[i] = 255 * (0.8 + 0.2 * smooth(d / 0.1)) * (0.9 + 0.1 * pr);
      sp[i] = 90; gl[i] = glW;
      D[p] = Math.min(255, D[p] * f); D[p + 1] = Math.min(255, D[p + 1] * f); D[p + 2] = Math.min(255, D[p + 2] * f);
    }
  }
}

/* ------------------------------ chenille ------------------------------ */

function chenille(ctx) {
  const { g, W, H, ppm, relief } = ctx;
  const D = g.alb, hg = g.hgt, sp = g.spec, gl = g.gloss, ao = g.ao;
  const loop = Math.max(2.5, 1.25 * ppm);
  const pile = 1.0 * ppm * relief, domeA = 0.32 * ppm, fibA = 0.05 * ppm;
  const glC = glossByte(0, false);
  for (let y = 0; y < H; y++) {
    const row = y * W;
    const gy = Math.floor(y / loop);
    for (let x = 0; x < W; x++) {
      const i = row + x, p = i * 4, a = D[p + 3];
      if (!a) continue;
      const d = distAt(ctx, x, y);
      const gx = Math.floor(x / loop);
      // nearest of the 4 surrounding jittered loop centres
      let best = 9, bh = 0;
      for (let oy = 0; oy <= 1; oy++) for (let ox = 0; ox <= 1; ox++) {
        const cx = gx + ox - ((x / loop - gx) < 0.5 ? 1 : 0), cy = gy + oy - ((y / loop - gy) < 0.5 ? 1 : 0);
        const hs = ((cx * 73856093) ^ (cy * 19349663)) >>> 0;
        const px = (cx + 0.5 + ((hs & 255) / 255 - 0.5) * 0.7) * loop, py = (cy + 0.5 + (((hs >> 8) & 255) / 255 - 0.5) * 0.7) * loop;
        const dd = ((x - px) * (x - px) + (y - py) * (y - py)) / (loop * loop);
        if (dd < best) { best = dd; bh = hs; }
      }
      const dome = Math.max(0, 1 - best * 2.4);
      const fib = hashI(x * 7 + (bh & 1023), y * 13) - 0.5;
      const edge = smooth(d / 1.1);
      hg[i] = pile * Math.sqrt(edge) + domeA * dome * edge + fibA * fib;
      ao[i] = 255 * (0.62 + 0.38 * dome) * (0.55 + 0.45 * smooth(d / 0.5));
      sp[i] = 26; gl[i] = glC;
      const f = 0.95 + fib * 0.14;
      D[p] = Math.min(255, D[p] * f); D[p + 1] = Math.min(255, D[p + 1] * f); D[p + 2] = Math.min(255, D[p + 2] * f);
      if (d < 0.35) D[p + 3] = a * Math.max(0, Math.min(1, 0.35 + d * 1.6 + fib * 1.3));
    }
  }
}

/* ------------------------------ PVC ------------------------------ */

function pvc(ctx) {
  const { g, W, H, ppm, relief, o } = ctx;
  const D = g.alb, hg = g.hgt, sp = g.spec, gl = g.gloss, ao = g.ao;
  const deep = o.pvcDepth === '3d';
  const bevel = 0.45, lvl = 0.55 * ppm * relief, sculpt = 0.9 * ppm * relief;
  const glP = glossByte(5, false);
  for (let y = 0; y < H; y++) {
    const row = y * W;
    for (let x = 0; x < W; x++) {
      const i = row + x, p = i * 4;
      if (!D[p + 3]) continue;
      const d = distAt(ctx, x, y);
      const sh = Math.sin(Math.min(1, d / bevel) * Math.PI / 2);
      let h = lvl * sh;
      if (deep) { const q = Math.min(1, d / 2.6); h = lvl * 0.6 * sh + sculpt * Math.sqrt(1 - (1 - q) * (1 - q)); }
      hg[i] = h;
      ao[i] = 255 * (0.7 + 0.3 * smooth(d / 0.25));
      sp[i] = 120; gl[i] = glP;
      D[p + 3] = 255 * Math.min(1, D[p + 3] / 200);
    }
  }
}

/* ------------------------------ leather ------------------------------ */

function leather(ctx) {
  const { g, W, H, ppm, relief, o } = ctx;
  const D = g.alb, hg = g.hgt, sp = g.spec, gl = g.gloss, ao = g.ao;
  const eng = o.engrave || [60, 35, 20];
  const depth = 0.35 * ppm * relief;
  const glL = glossByte(1, false);
  for (let i = 0, n = W * H; i < n; i++) {
    const p = i * 4, a = D[p + 3];
    if (!a) continue;
    const l = (0.299 * D[p] + 0.587 * D[p + 1] + 0.114 * D[p + 2]) / 255;
    let e = (1 - l - 0.18) / 0.6;
    e = smooth(e) * (a / 255);
    const n2 = hashI(i, 3) - 0.5;
    hg[i] = -depth * e;
    D[p] = eng[0] * (1 + n2 * 0.12); D[p + 1] = eng[1] * (1 + n2 * 0.12); D[p + 2] = eng[2] * (1 + n2 * 0.12);
    D[p + 3] = Math.min(255, e * 300);
    ao[i] = 255 * (0.85 + 0.15 * (1 - e));
    sp[i] = 12; gl[i] = glL;
  }
}

/* ------------------------------ print on fabric ------------------------------ */

function fabricPrint(ctx, kind) {
  const P = prof();
  const { g, W, H, ppm } = ctx;
  const D = g.alb, hg = g.hgt, tan = g.tan, sp = g.spec, gl = g.gloss, ao = g.ao;
  const poly = kind === 'poly';
  const pitch = Math.max(2, (poly ? 0.32 : 0.62) * ppm), inv = 1 / pitch;
  const amp = (poly ? 0.025 : 0.05) * ppm * smooth((pitch - 1.5) / 2) + 0.2;
  const ks = poly ? 70 : 26, glB = poly ? glossByte(3, true) : glossByte(1, false);
  for (let y = 0; y < H; y++) {
    const row = y * W;
    for (let x = 0; x < W; x++) {
      const i = row + x, p = i * 4;
      if (!D[p + 3]) continue;
      const u = (x + y) * inv, t = u - Math.floor(u), pr = P[(t * 256) | 0];
      hg[i] = amp * pr;
      ao[i] = 255 * (0.9 + 0.1 * pr);
      sp[i] = ks; gl[i] = glB;
      if (poly) { tan[i * 2] = 90; tan[i * 2 + 1] = -90; }
    }
  }
}

/* ------------------------------ patch base ------------------------------ */

/**
 * Patch body (fabric under the design) for a look.
 * @param {{width:number,height:number,data:Uint8ClampedArray}} img  albedo of the patch body (alpha = coverage)
 * @param {object} o  {ppm, look, stitched (background fully embroidered), band (bandField of the outline),
 *                    roll (mm of rounded patch edge), relief, uncut}
 */
export function buildBase(img, o) {
  const P = prof();
  const W = img.width, H = img.height;
  const g = newGeo(W, H, img.data);
  const D = g.alb, hg = g.hgt, tan = g.tan, sp = g.spec, gl = g.gloss, ao = g.ao;
  const ppm = Math.max(0.3, o.ppm), look = o.look;
  let kind = 'twill';
  if (look === 'embroidered' && o.stitched) kind = 'tatami';
  else if (look === 'woven') kind = 'weave';
  else if (look === 'chenille') kind = 'felt';
  else if (look === 'leather') kind = 'grain';
  else if (look === 'pvc') kind = 'pvc';
  else if (look === 'sublimated') kind = 'poly';
  const pitch = Math.max(1.8, (kind === 'poly' ? 0.32 : kind === 'weave' ? 0.19 : kind === 'tatami' ? 0.42 : 0.62) * ppm);
  const inv = 1 / pitch, fade = smooth((pitch - 1.5) / 2);
  const ampT = (kind === 'tatami' ? 0.07 : kind === 'poly' ? 0.025 : kind === 'weave' ? 0.03 : 0.06) * ppm * fade + 0.2;
  const stitchInv = 1 / Math.max(5, 2.8 * ppm);
  const glT = glossByte(kind === 'tatami' ? 3 : kind === 'poly' ? 3 : 1, kind === 'tatami' || kind === 'poly' || kind === 'weave');
  const ks = { tatami: 140, poly: 70, weave: 80, twill: 22, felt: 10, grain: 40, pvc: 110 }[kind];
  const glossIdx = kind === 'grain' ? glossByte(2, false) : kind === 'pvc' ? glossByte(5, false) : kind === 'felt' ? glossByte(0, false) : glT;
  // value noise for felt/grain/pvc
  const N = 64, grid = new Float32Array(N * N);
  for (let i = 0; i < grid.length; i++) grid[i] = hashI(i, 77);
  const cellPx = kind === 'grain' ? Math.max(3, ppm * 1.1) : Math.max(2, ppm * 0.5);
  const noise = (x, y) => {
    const u = x / cellPx, v = y / cellPx, x0 = Math.floor(u), y0 = Math.floor(v), fx = u - x0, fy = v - y0;
    const gg = (a, b) => grid[((b & (N - 1)) * N) + (a & (N - 1))];
    const sx = fx * fx * (3 - 2 * fx), sy = fy * fy * (3 - 2 * fy);
    return (gg(x0, y0) * (1 - sx) + gg(x0 + 1, y0) * sx) * (1 - sy) + (gg(x0, y0 + 1) * (1 - sx) + gg(x0 + 1, y0 + 1) * sx) * sy;
  };
  const C = 0.7071, Sn = 0.7071; // tatami background at 45°
  for (let y = 0; y < H; y++) {
    const row = y * W;
    for (let x = 0; x < W; x++) {
      const i = row + x, p = i * 4;
      if (!D[p + 3]) continue;
      let h = 0, f = 1, cav = 1;
      if (kind === 'twill' || kind === 'poly') {
        const u = (x + y) * inv, t = u - Math.floor(u), pr = P[(t * 256) | 0];
        h = ampT * pr; cav = 0.86 + 0.14 * pr;
        if (kind === 'poly') { tan[i * 2] = 90; tan[i * 2 + 1] = -90; }
        f = 0.97 + 0.04 * pr;
      } else if (kind === 'tatami') {
        const v = (-x * Sn + y * C) * inv, rI = Math.floor(v), t = v - rI;
        const q = (x * C + y * Sn) * stitchInv + rI * 0.37, tq = q - Math.floor(q);
        const pr = P[(t * 256) | 0];
        h = ampT * pr - (tq < 0.06 ? ampT * 0.8 : 0); cav = (0.82 + 0.18 * pr) * (tq < 0.06 ? 0.85 : 1);
        tan[i * 2] = C * 127; tan[i * 2 + 1] = Sn * 127;
        f = 0.95 + 0.07 * pr + (hashI(rI, 5) - 0.5) * 0.04;
      } else if (kind === 'weave') {
        const v = y * inv, rI = Math.floor(v), t = v - rI, pr = P[(t * 256) | 0];
        const cpos = x * inv + rI * 2, ci = Math.floor(cpos), tie = ((ci % 5) + 5) % 5 === 0;
        if (tie) { const pc = P[((cpos - ci) * 256) | 0]; h = ampT * 0.6 * pc; tan[i * 2 + 1] = 127; f = 0.84 + 0.1 * pc; }
        else { h = ampT * pr; tan[i * 2] = 127; f = 0.94 + 0.08 * pr; }
        cav = 0.9 + 0.1 * pr;
      } else if (kind === 'felt') {
        const nz = noise(x, y), fib = hashI(x, y * 31) - 0.5;
        h = (nz * 0.12 + fib * 0.05) * ppm; f = 0.93 + nz * 0.08 + fib * 0.1; cav = 0.85 + 0.15 * nz;
      } else if (kind === 'grain') {
        const a1 = noise(x, y), a2 = noise(x * 2.3 + 17, y * 2.3 + 5);
        const ridge = Math.abs(a1 - 0.5) * 2;
        h = (-(ridge < 0.14 ? (0.14 - ridge) * 0.9 : 0) + (a2 - 0.5) * 0.05) * ppm;
        f = 0.95 + (a2 - 0.5) * 0.12 - (ridge < 0.1 ? 0.06 : 0); cav = ridge < 0.12 ? 0.82 : 1;
      } else if (kind === 'pvc') {
        const nz = noise(x * 0.5, y * 0.5);
        h = (nz - 0.5) * 0.03 * ppm; f = 0.99 + (nz - 0.5) * 0.03;
      }
      hg[i] = h; ao[i] = cav * 255; sp[i] = ks; gl[i] = glossIdx;
      D[p] = Math.min(255, D[p] * f); D[p + 1] = Math.min(255, D[p + 1] * f); D[p + 2] = Math.min(255, D[p + 2] * f);
    }
  }
  // rounded patch edge (body thickness rolls down towards the cut)
  const B = o.band;
  if (B && o.roll > 0) {
    const roll = o.roll * ppm, th = (o.thick || 0.8) * ppm;
    for (let k = 0; k < B.n; k++) {
      const d = B.d[k];
      if (d < 0 || d > roll) continue;
      const i = B.idx[k];
      const q = d / roll;
      hg[i] += th * (Math.sqrt(1 - (1 - q) * (1 - q)) - 1);
    }
  }
  return finishGeo(g, 1.2);
}
