/**
 * Patch Studio — light model + shading kernel (pure, typed arrays).
 *
 * Every material layer is first turned into a "geometry" buffer (albedo, surface normal, thread tangent,
 * specular strength/gloss, cavity). shadeGeo() lights it for one light direction:
 *   diffuse  = ambient + (1-ambient)·max(0, n·L)/Lz      (a flat surface keeps its albedo exactly)
 *   specular = isotropic Blinn (n·H)^p, or for threads Kajiya-Kay √(1-(T·H)²)^p · (n·H)^4
 * so satin catches the light where its threads lie across the light, and the sheen moves when the light
 * moves (opts.light, e.g. following the pointer).
 */

/** Default light: from the top left, ~40° above the patch. */
export const DEFAULT_LIGHT = Object.freeze({ x: -0.55, y: -0.65 });

const DEG = Math.PI / 180;

/**
 * Normalise a light option.
 * @param {{x?:number,y?:number,azimuthDeg?:number,elevationDeg?:number}|null|undefined} l
 *   {x,y}: direction towards the light in screen space (-1..1, e.g. pointer offset from the canvas centre;
 *   length 0 = straight above, 1 = low raking light). Or {azimuthDeg, elevationDeg} (0° = from the right,
 *   -90° = from the top; elevation above the patch plane).
 * @returns {{L:number[], H:number[], key:string, az:number, el:number}}
 */
export function normLight(l) {
  let az, el;
  if (l && Number.isFinite(Number(l.azimuthDeg))) {
    az = Number(l.azimuthDeg) * DEG;
    el = (Number.isFinite(Number(l.elevationDeg)) ? Number(l.elevationDeg) : 42) * DEG;
  } else {
    const x = l && Number.isFinite(Number(l.x)) ? Number(l.x) : DEFAULT_LIGHT.x;
    const y = l && Number.isFinite(Number(l.y)) ? Number(l.y) : DEFAULT_LIGHT.y;
    const r = Math.min(1, Math.hypot(x, y));
    az = r < 1e-3 ? Math.atan2(DEFAULT_LIGHT.y, DEFAULT_LIGHT.x) : Math.atan2(y, x);
    el = (74 - 36 * r) * DEG;
  }
  el = Math.max(18 * DEG, Math.min(88 * DEG, el));
  // quantise (5° azimuth, 3° elevation) so caches are reused while the pointer moves
  const aq = Math.round(az / (5 * DEG)) * 5, eq = Math.round(el / (3 * DEG)) * 3;
  const a = aq * DEG, e = eq * DEG;
  const L = [Math.cos(a) * Math.cos(e), Math.sin(a) * Math.cos(e), Math.sin(e)];
  const hx = L[0], hy = L[1], hz = L[2] + 1, hl = Math.hypot(hx, hy, hz);
  return { L, H: [hx / hl, hy / hl, hz / hl], key: ((aq % 360) + 360) % 360 + '/' + eq, az: a, el: e };
}

/* exponent classes for the gloss byte */
export const GLOSS = [2, 4, 6, 10, 16, 24, 40, 64];
let POW = null;
const PN = 1024;
function pows() {
  if (POW) return POW;
  POW = GLOSS.map((p) => {
    const t = new Float32Array(PN + 1);
    for (let i = 0; i <= PN; i++) t[i] = Math.pow(i / PN, p);
    return t;
  });
  return POW;
}

/**
 * Geometry buffer for a layer of w×h px.
 * alb: RGBA albedo (straight alpha), hgt: height in px (only until finishGeo), nrm/tan: Int8 xy pairs,
 * spec: specular strength 0..255, gloss: GLOSS index (bit 3 set = anisotropic thread), ao: cavity 0..255.
 */
export function newGeo(w, h, alb) {
  const n = w * h;
  return {
    w, h, alb: alb || new Uint8ClampedArray(n * 4), hgt: new Float32Array(n), nrm: null,
    tan: new Int8Array(n * 2), spec: new Uint8Array(n), gloss: new Uint8Array(n), ao: new Uint8Array(n).fill(255),
  };
}

/** Height → normals (central differences); drops the height buffer. `k` scales the relief. */
export function finishGeo(g, k = 1) {
  const { w, h, hgt, alb } = g;
  const nrm = new Int8Array(w * h * 2);
  for (let y = 0; y < h; y++) {
    const r = y * w, up = y > 0 ? r - w : r, dn = y < h - 1 ? r + w : r;
    for (let x = 0; x < w; x++) {
      const i = r + x;
      if (!alb[i * 4 + 3]) continue;
      const gx = ((x < w - 1 ? hgt[i + 1] : hgt[i]) - (x > 0 ? hgt[i - 1] : hgt[i])) * 0.5 * k;
      const gy = (hgt[dn + x] - hgt[up + x]) * 0.5 * k;
      const il = 127 / Math.sqrt(gx * gx + gy * gy + 1);
      nrm[i * 2] = -gx * il; nrm[i * 2 + 1] = -gy * il;
    }
  }
  g.nrm = nrm;
  g.maxH = 0;
  for (let i = 0; i < hgt.length; i += 7) if (hgt[i] > g.maxH) g.maxH = hgt[i];
  g.hgt = null;
  return g;
}

/**
 * Light a geometry buffer into an RGBA array.
 * @param {object} g        newGeo()/finishGeo() result
 * @param {object} light    normLight()
 * @param {Uint8ClampedArray} out  w*h*4
 * @param {{ambient?:number, specTint?:number}} [o]  specTint: 0 = white highlights, 1 = thread coloured
 */
export function shadeGeo(g, light, out, o = {}) {
  const P = pows();
  const P4 = P[1];
  const { w, h, alb, nrm, tan, spec, gloss, ao } = g;
  const [lx, ly, lz] = light.L, [hx, hy, hz] = light.H;
  const amb = o.ambient != null ? o.ambient : 0.42, kd = (1 - amb) / lz;
  const tint = o.specTint != null ? o.specTint : 0.35;
  const I = 1 / 127;
  for (let i = 0, n = w * h; i < n; i++) {
    const p = i * 4, a = alb[p + 3];
    if (!a) { out[p + 3] = 0; continue; }
    const nx = nrm[i * 2] * I, ny = nrm[i * 2 + 1] * I;
    const nn = nx * nx + ny * ny, nz = nn < 1 ? Math.sqrt(1 - nn) : 0;
    let ndl = nx * lx + ny * ly + nz * lz;
    if (ndl < 0) ndl = 0;
    const cav = ao[i] * 0.003921569;
    const dif = (amb + kd * ndl) * cav;
    let sp = 0;
    const ks = spec[i];
    if (ks) {
      let ndh = nx * hx + ny * hy + nz * hz;
      if (ndh > 0) {
        const gl = gloss[i];
        const tx = tan[i * 2];
        if (gl & 8 && (tx || tan[i * 2 + 1])) {
          const th = (tx * hx + tan[i * 2 + 1] * hy) * I;
          const s = 1 - th * th;
          sp = P[gl & 7][(s * PN) | 0] * P4[(ndh * PN) | 0];
        } else sp = P[gl & 7][(ndh > 1 ? PN : (ndh * PN) | 0)];
        sp *= ks * cav;
      }
    }
    const r = alb[p], gg = alb[p + 1], b = alb[p + 2];
    const wr = sp * (1 - tint + tint * r * 0.003921569), wg = sp * (1 - tint + tint * gg * 0.003921569), wb = sp * (1 - tint + tint * b * 0.003921569);
    out[p] = r * dif + wr; out[p + 1] = gg * dif + wg; out[p + 2] = b * dif + wb; out[p + 3] = a;
  }
  return out;
}

/** Pack gloss: exponent class (index in GLOSS) + anisotropic flag. */
export function glossByte(idx, aniso) { return (idx & 7) | (aniso ? 8 : 0); }
