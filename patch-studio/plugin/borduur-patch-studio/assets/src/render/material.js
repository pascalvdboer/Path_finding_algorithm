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
import { newGeo, finishGeo, glossByte, GLOSS } from './light.js';
import { regionDistance, alphaLabels, blurGrid } from './field.js';
import { analyzeComponents, chooseModes } from './stylize.js';
import { now } from './util.js';

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
  const _t = now();
  if (needDist) {
    dist = blurGrid(regionDistance(labels), labels, 2);
    PT('dist', _t);
    S = sampler(labels.width, labels.height, W, H);
    mmCell = (W / labels.width) / ppm;
  }
  const ctx = { g, W, H, ppm, relief, dist, S, mmCell, labels, comps, o };
  const _t2 = now();
  switch (look) {
    case 'embroidered': embroidery(ctx); break;
    case 'woven': woven(ctx); break;
    case 'chenille': chenille(ctx); break;
    case 'pvc': pvc(ctx); break;
    case 'leather': leather(ctx); break;
    case 'sublimated': fabricPrint(ctx, 'poly'); break;
    default: fabricPrint(ctx, 'twill'); break;
  }
  PT('kernel', _t2);
  const _t3 = now();
  const r = finishGeo(g, look === 'chenille' ? 0.55 : look === 'woven' || look === 'printed' || look === 'sublimated' ? 1 : 1.25);
  r.ambient = look === 'chenille' ? 0.6 : 0.5;
  PT('finish', _t3);
  return r;
}
function PT(k, t) { const P = globalThis.__bpsProf; if (P) P[k] = (P[k] || 0) + now() - t; }

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
  // Thread lines only where the screen can resolve them (pitch ≥ ±3 px); below that they alias into moiré, so the
  // threads blend into an even satin sheen, as the eye sees real embroidery from a distance (critique-1 #2).
  const fade = smooth((0.4 * ppm - 2.4) / 2.6);
  const ripple = 0.075 * ppm * fade, rowRipple = 0.06 * ppm * fade;
  const glSatin = glossByte(4, true), glFill = glossByte(3, true);
  // far away (thumbnails, garments) the single threads blur together: less mirror-like sheen
  const spS = Math.round(160 * (0.55 + 0.45 * fade)), spF = Math.round(120 * (0.6 + 0.4 * fade));
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
        f = 0.98 + fade * (0.08 * pr - 0.04 + (hashI(rI, c) - 0.5) * 0.05);
        cav = (1 - fade * 0.16 * (1 - pr)) * (tq < 0.06 ? 1 - 0.14 * fade : 1);
        sp[i] = spF; gl[i] = glFill;
      } else {
        let u, lf = 1;
        if (mode === 1 && c >= 0) {
          const ccx = comps.cx[c] * sxl, ccy = comps.cy[c] * syl;
          const rx = x - ccx, ry = y - ccy, rr = Math.hypot(rx, ry) || 1;
          const R = Math.max(4, comps.radius[c] * sxl);
          u = Math.atan2(ry, rx) * R * invSat;
          tx = rx / rr; ty = ry / rr;
          // radial stitches converge towards the centre: there the lines get too dense to show (no moiré)
          lf = smooth((satPitch * rr / R - 2.4) / 2.6);
        } else {
          u = (x * cs + y * sn) * invSat;
          tx = -sn; ty = cs;
        }
        const ui = Math.floor(u), t = u - ui, pr = P[(t * 256) | 0];
        // satin column: round dome across the column
        const q = Math.min(1, d / ht), dome = Math.sqrt(Math.max(0, 1 - (1 - q) * (1 - q)));
        const fl = fade * lf;
        h = h0 * dome + ripple * lf * pr;
        f = 0.985 + fl * (0.07 * pr - 0.035 + (hashI(ui, c) - 0.5) * 0.09);
        cav = 1 - fl * 0.14 * (1 - pr);
        sp[i] = spS; gl[i] = glSatin;
      }
      // needle groove where two regions (or the ground) meet
      cav *= 0.68 + 0.32 * smooth(d / 0.3);
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

const pileTiles = new Map();
/** Seamless tile of chenille loops: height (dome + fibre streaks) and cavity. */
function pileTile(loop) {
  const n = 12, T = Math.max(24, Math.round(loop * n)), L = T / n;
  const key = T;
  let t = pileTiles.get(key);
  if (t) return t;
  const h = new Float32Array(T * T), cav = new Float32Array(T * T);
  for (let y = 0; y < T; y++) for (let x = 0; x < T; x++) {
    const gx = Math.floor(x / L), gy = Math.floor(y / L), fx = x / L - gx, fy = y / L - gy;
    const bx = fx < 0.5 ? gx - 1 : gx, by = fy < 0.5 ? gy - 1 : gy;
    let best = 9, bh = 0;
    for (let oy = 0; oy <= 1; oy++) for (let ox = 0; ox <= 1; ox++) {
      const cx = bx + ox, cy = by + oy, wx = ((cx % n) + n) % n, wy = ((cy % n) + n) % n;
      const hs = ((wx * 73856093) ^ (wy * 19349663)) >>> 0;
      const px = (cx + 0.5 + ((hs & 255) / 255 - 0.5) * 0.8) * L, py = (cy + 0.5 + (((hs >> 8) & 255) / 255 - 0.5) * 0.8) * L;
      const dd = ((x - px) * (x - px) + (y - py) * (y - py)) / (L * L);
      if (dd < best) { best = dd; bh = hs; }
    }
    const dome = Math.max(0, 1 - best * 2.2);
    const ang = (bh & 1023) / 1023 * Math.PI;
    const fs = Math.sin((x * Math.cos(ang) + y * Math.sin(ang)) * 2.1 + (bh >> 10)) * 0.5 + (hashI(x, y * 31) - 0.5) * 0.9;
    h[y * T + x] = dome * 0.16 + fs * 0.07;
    cav[y * T + x] = (0.8 + 0.2 * dome) * (0.92 + 0.08 * fs);
  }
  t = { T, h, cav };
  pileTiles.set(key, t);
  return t;
}

function chenille(ctx) {
  const { g, W, H, ppm, relief } = ctx;
  const D = g.alb, hg = g.hgt, sp = g.spec, gl = g.gloss, ao = g.ao;
  const tile = pileTile(Math.max(2.2, 0.95 * ppm)), T = tile.T, th = tile.h, tc = tile.cav;
  const pile = 1.1 * ppm * relief;
  const glC = glossByte(0, false);
  for (let y = 0; y < H; y++) {
    const row = y * W, tr = (y % T) * T;
    let tx = 0;
    for (let x = 0; x < W; x++, tx++) {
      if (tx === T) tx = 0;
      const i = row + x, p = i * 4;
      if (!D[p + 3]) continue;
      const d = distAt(ctx, x, y);
      const k = tr + tx;
      const edge = smooth(d / 1.8);
      hg[i] = pile * Math.sqrt(edge) + th[k] * ppm;
      ao[i] = 255 * tc[k] * (0.78 + 0.22 * smooth(d / 0.6));
      sp[i] = 18; gl[i] = glC;
      const f = 0.97 + th[k] * 0.6;
      D[p] = Math.min(255, D[p] * f); D[p + 1] = Math.min(255, D[p + 1] * f); D[p + 2] = Math.min(255, D[p + 2] * f);
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

/* ------------------------------ patch base (periodic lit tile) ------------------------------ */

const tileCache = new Map();

function baseKind(look, stitched) {
  if (look === 'embroidered' && stitched) return 'tatami';
  if (look === 'woven') return 'weave';
  if (look === 'chenille') return 'felt';
  if (look === 'leather') return 'grain';
  if (look === 'pvc') return 'pvc';
  if (look === 'sublimated') return 'poly';
  return 'twill';
}

/**
 * Seamless material tile for the patch body: normals, thread tangent, gloss, cavity and albedo factor.
 * Periods are snapped so the tile wraps exactly (twill ribs, tatami rows with 1/3 brick offset, 5-shed
 * weave, wrapping value noise for felt / leather grain / PVC).
 */
export function baseTile(look, stitched, ppm) {
  const kind = baseKind(look, stitched);
  const q = Math.max(0.5, Math.round(ppm * 8) / 8);
  const key = kind + '|' + q;
  let t = tileCache.get(key);
  if (t) return t;
  const P = prof();
  let T, fn;
  if (kind === 'twill' || kind === 'poly') {
    const pitch = Math.max(1.8, (kind === 'poly' ? 0.32 : 0.62) * q);
    const n = Math.max(1, Math.round(48 / pitch));
    T = Math.max(8, Math.round(n * pitch));
    const a = n / T, fade = smooth((T / n - 1.5) / 2);
    // twill ribs at half the old contrast plus a faint colour wobble per yarn: reads as fine twill at 200 %,
    // not as pinstripes (critique-2 #12)
    const amp = (kind === 'poly' ? 0.025 : 0.03) * q * fade + 0.12;
    fn = (x, y, o) => {
      const u = (x + y) * a, ui = Math.floor(u), tt = u - ui, pr = P[(tt * 256) | 0];
      const yarn = kind === 'twill' ? (hashI(((ui % n) + n) % n, 9) - 0.5) * 0.04 : 0;
      o.h = amp * pr; o.cav = 0.93 + 0.07 * pr; o.f = 0.985 + 0.02 * pr + yarn;
      if (kind === 'poly') { o.tx = 0.7071; o.ty = -0.7071; }
    };
  } else if (kind === 'tatami') {
    const pitch = Math.max(1.6, 0.42 * q), stitch = Math.max(5, 2.8 * q);
    const m = Math.max(1, Math.round(72 / (Math.SQRT2 * stitch)));
    T = Math.max(12, Math.round(Math.SQRT2 * stitch * m));
    const nr = Math.max(3, 3 * Math.round(T / (Math.SQRT2 * pitch) / 3));
    const a = nr / T, b = m / T;
    const fade = smooth((T / nr / Math.SQRT2 * 2 - 1.5) / 2);
    const amp = 0.07 * q * fade + 0.2;
    fn = (x, y, o) => {
      const v = (y - x) * a, rI = Math.floor(v), tt = v - rI;
      const qq = (x + y) * b + rI / 3, tq = qq - Math.floor(qq);
      const pr = P[(tt * 256) | 0];
      o.h = amp * pr - (tq < 0.06 ? amp * 0.8 : 0); o.cav = (0.82 + 0.18 * pr) * (tq < 0.06 ? 0.85 : 1);
      o.tx = 0.7071; o.ty = 0.7071; o.f = 0.95 + 0.07 * pr + (hashI(((rI % nr) + nr) % nr, 5) - 0.5) * 0.04;
    };
  } else if (kind === 'weave') {
    const pitch = Math.max(2, 0.19 * q);
    const k = Math.max(1, Math.round(64 / (5 * pitch)));
    T = Math.max(10, Math.round(5 * k * pitch));
    const inv = 5 * k / T, amp = 0.03 * q * smooth((T / (5 * k) - 1.6) / 1.6) + 0.15;
    fn = (x, y, o) => {
      const v = y * inv, rI = Math.floor(v), tt = v - rI, pr = P[(tt * 256) | 0];
      const cpos = x * inv + rI * 2, ci = Math.floor(cpos), tie = ((ci % 5) + 5) % 5 === 0;
      if (tie) { const pc = P[((cpos - ci) * 256) | 0]; o.h = amp * 0.6 * pc; o.tx = 0; o.ty = 1; o.f = 0.84 + 0.1 * pc; }
      else { o.h = amp * pr; o.tx = 1; o.ty = 0; o.f = 0.94 + 0.08 * pr; }
      o.cav = 0.9 + 0.1 * pr;
    };
  } else {
    // wrapping value noise
    const cell0 = kind === 'grain' ? Math.max(3, q * 1.1) : kind === 'felt' ? Math.max(2, q * 0.5) : Math.max(4, q * 1.5);
    const N = Math.max(4, Math.round(320 / cell0));
    T = Math.max(16, Math.round(N * cell0));
    const cell = T / N;
    const grid = new Float32Array(N * N);
    for (let i = 0; i < grid.length; i++) grid[i] = hashI(i, kind.length * 77);
    const gg = (a, b) => grid[(((b % N) + N) % N) * N + (((a % N) + N) % N)];
    const noise = (x, y, c, N2) => {
      const u = x / c, v = y / c, x0 = Math.floor(u), y0 = Math.floor(v), fx = u - x0, fy = v - y0;
      const sx = fx * fx * (3 - 2 * fx), sy = fy * fy * (3 - 2 * fy);
      const g2 = N2 ? (a, b) => grid[(((b % N2) + N2) % N2) * N + (((a % N2) + N2) % N2)] : gg;
      return (g2(x0, y0) * (1 - sx) + g2(x0 + 1, y0) * sx) * (1 - sy) + (g2(x0, y0 + 1) * (1 - sx) + g2(x0 + 1, y0 + 1) * sx) * sy;
    };
    fn = (x, y, o) => {
      if (kind === 'felt') {
        const nz = noise(x, y, cell), fib = hashI(x, y * 31) - 0.5;
        o.h = (nz * 0.12 + fib * 0.05) * q; o.f = 0.93 + nz * 0.08 + fib * 0.1; o.cav = 0.85 + 0.15 * nz;
      } else if (kind === 'grain') {
        const a1 = noise(x, y, cell), a2 = noise(x, y, cell / 2, N * 2 <= N ? 0 : 0);
        const ridge = Math.abs(a1 - 0.5) * 2;
        o.h = (-(ridge < 0.14 ? (0.14 - ridge) * 0.9 : 0) + (a2 - 0.5) * 0.05) * q;
        o.f = 0.95 + (a2 - 0.5) * 0.12 - (ridge < 0.1 ? 0.06 : 0); o.cav = ridge < 0.12 ? 0.82 : 1;
      } else {
        const nz = noise(x, y, cell);
        o.h = (nz - 0.5) * 0.03 * q; o.f = 0.99 + (nz - 0.5) * 0.03; o.cav = 1;
      }
    };
  }
  const n = T * T;
  const hgt = new Float32Array(n), tx = new Int8Array(n), ty = new Int8Array(n), f = new Float32Array(n), ao = new Float32Array(n);
  const o = {};
  for (let y = 0; y < T; y++) for (let x = 0; x < T; x++) {
    o.h = 0; o.cav = 1; o.f = 1; o.tx = 0; o.ty = 0;
    fn(x, y, o);
    const i = y * T + x;
    hgt[i] = o.h; f[i] = o.f; ao[i] = o.cav; tx[i] = o.tx * 127; ty[i] = o.ty * 127;
  }
  const nx = new Float32Array(n), ny = new Float32Array(n), nz = new Float32Array(n);
  const k = 1.2;
  for (let y = 0; y < T; y++) for (let x = 0; x < T; x++) {
    const i = y * T + x;
    const gx = (hgt[y * T + (x + 1) % T] - hgt[y * T + (x + T - 1) % T]) * 0.5 * k;
    const gy = (hgt[((y + 1) % T) * T + x] - hgt[((y + T - 1) % T) * T + x]) * 0.5 * k;
    const l = 1 / Math.sqrt(gx * gx + gy * gy + 1);
    nx[i] = -gx * l; ny[i] = -gy * l; nz[i] = l;
  }
  const ks = { tatami: 150, poly: 70, weave: 80, twill: 22, felt: 10, grain: 40, pvc: 110 }[kind] / 255;
  const gIdx = { tatami: 3, poly: 3, weave: 3, twill: 1, felt: 0, grain: 2, pvc: 5 }[kind];
  const aniso = kind === 'tatami' || kind === 'poly' || kind === 'weave';
  t = { kind, T, nx, ny, nz, tx, ty, f, ao, ks, gIdx, aniso, lit: new Map() };
  tileCache.set(key, t);
  if (tileCache.size > 24) tileCache.delete(tileCache.keys().next().value);
  return t;
}

/** Shade one normal with a tile's material (returns [diffuse, specular]). */
function shadeN(t, i, nx, ny, nz, light, out) {
  const [lx, ly, lz] = light.L, [hx, hy, hz] = light.H;
  const amb = 0.42, kd = (1 - amb) / lz;
  const ndl = Math.max(0, nx * lx + ny * ly + nz * lz);
  out[0] = (amb + kd * ndl) * t.ao[i];
  let sp = 0;
  const ndh = nx * hx + ny * hy + nz * hz;
  if (ndh > 0) {
    const p = GLOSS[t.gIdx];
    if (t.aniso) {
      const th = (t.tx[i] * hx + t.ty[i] * hy) / 127;
      sp = Math.pow(1 - th * th, p) * Math.pow(ndh, 4);
    } else sp = Math.pow(ndh, p);
  }
  out[1] = sp * t.ks * t.ao[i] * 255;
  return out;
}

function litTile(t, light) {
  let L = t.lit.get(light.key);
  if (L) return L;
  const n = t.T * t.T, dif = new Float32Array(n), spc = new Float32Array(n), o = [0, 0];
  for (let i = 0; i < n; i++) {
    shadeN(t, i, t.nx[i], t.ny[i], t.nz[i], light, o);
    dif[i] = o[0] * t.f[i]; spc[i] = o[1];
  }
  L = { dif, spc };
  t.lit.set(light.key, L);
  if (t.lit.size > 12) t.lit.delete(t.lit.keys().next().value);
  return L;
}

/**
 * Light the patch body: albedo (RGBA, W×H) × tile lighting, plus the rolled edge (band) with its own slope.
 * @param {Uint8ClampedArray} alb  in/out (lit in place)
 * @param {object} tile  baseTile()
 * @param {object|null} band  bandField() of the outline
 * @param {{roll:number, thick:number, ppm:number}} o  roll / thick in mm
 */
export function shadeBase(alb, W, H, tile, light, band, o) {
  const T = tile.T, L = litTile(tile, light), dif = L.dif, spc = L.spc;
  const tint = 0.3;
  for (let y = 0; y < H; y++) {
    const row = y * W, tr = (y % T) * T;
    let tx = 0;
    for (let x = 0; x < W; x++, tx++) {
      if (tx === T) tx = 0;
      const p = (row + x) * 4;
      if (!alb[p + 3]) continue;
      const k = tr + tx, d = dif[k], s = spc[k];
      const r = alb[p], g = alb[p + 1], b = alb[p + 2];
      alb[p] = r * d + s * (1 - tint + tint * r / 255);
      alb[p + 1] = g * d + s * (1 - tint + tint * g / 255);
      alb[p + 2] = b * d + s * (1 - tint + tint * b / 255);
    }
  }
  if (!band || !(o.roll > 0)) return alb;
  // rolled edge: tilt the tile normal outwards, slope from a quarter-circle profile
  const roll = o.roll * o.ppm, th = Math.max(0.05, o.thick) / Math.max(0.05, o.roll);
  const out = [0, 0];
  for (let m = 0; m < band.n; m++) {
    const d = band.d[m];
    if (d < 0 || d > roll) continue;
    const i = band.idx[m], p = i * 4;
    if (!alb[p + 3]) continue;
    const q = d / roll, sl = th * (1 - q) / Math.max(0.2, Math.sqrt(1 - (1 - q) * (1 - q)));
    const s = Math.min(2.2, sl);
    const ox = band.ty[m] * band.sgn, oy = -band.tx[m] * band.sgn;
    const x = i % W, y = (i - x) / W, k = (y % T) * T + (x % T);
    let nx = tile.nx[k] + ox * s, ny = tile.ny[k] + oy * s, nz = tile.nz[k];
    const l = 1 / Math.hypot(nx, ny, nz); nx *= l; ny *= l; nz *= l;
    shadeN(tile, k, nx, ny, nz, light, out);
    const d0 = dif[k] || 1e-3, ratio = (out[0] * tile.f[k]) / d0;
    // re-light: undo the flat tile lighting on this pixel and apply the tilted one
    const ds = out[1] - spc[k];
    for (let c = 0; c < 3; c++) alb[p + c] = Math.max(0, alb[p + c] * ratio + ds);
  }
  return alb;
}
