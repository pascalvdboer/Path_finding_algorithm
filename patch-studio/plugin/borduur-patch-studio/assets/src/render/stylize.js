/**
 * Patch Studio — per-pixel material kernels (pure, run on RGBA typed arrays; no DOM).
 *
 *  - analyzeComponents(labels): connected colour regions with orientation and thickness, so every
 *    embroidered region gets its own stitch direction (satin across a column, radial stitches in rings,
 *    tatami fill in wide areas), like a digitiser would do.
 *  - stylizePixels(img, opts): turns a flat RGBA layer into embroidery / weave / chenille / PVC / leather.
 *
 * Light comes from the top left everywhere (relief, sheen, bevels, engraving walls).
 */

const LIGHT = -Math.PI * 0.75; // direction the light comes FROM (top-left), screen radians

/* Lookup tables (lazy): thread cross-section profile sin(pi t), and its 6th power for specular. */
let PROF = null, SPEC = null;
function luts() {
  if (PROF) return;
  PROF = new Float32Array(257); SPEC = new Float32Array(257);
  for (let i = 0; i <= 256; i++) {
    const p = Math.sin(Math.PI * (i / 256));
    PROF[i] = p; SPEC[i] = Math.pow(p, 7);
  }
}

/**
 * Connected regions of equal label (4-connectivity) with orientation statistics.
 * @param {{width:number,height:number,data:Uint8Array}} labels  255 = background
 * @returns {{comp:Int32Array, n:number, cos:Float32Array, sin:Float32Array, cx:Float32Array, cy:Float32Array,
 *            radius:Float32Array, thick:Float32Array, mode:Uint8Array, area:Float32Array}}
 *   mode: 0 = linear satin, 1 = radial satin (rings/arcs), filled in by the caller via chooseModes()
 */
export function analyzeComponents(labels) {
  const w = labels.width, h = labels.height, L = labels.data, N = w * h;
  const comp = new Int32Array(N).fill(-1);
  const st = [];
  const acc = []; // per comp: [n, sx, sy, sxx, syy, sxy, perim]
  const stack = new Int32Array(N);
  for (let s = 0; s < N; s++) {
    if (L[s] === 255 || comp[s] >= 0) continue;
    const id = acc.length, lab = L[s];
    let n = 0, sx = 0, sy = 0, sxx = 0, syy = 0, sxy = 0, per = 0, sp = 0;
    stack[sp++] = s; comp[s] = id;
    while (sp) {
      const i = stack[--sp], x = i % w, y = (i - x) / w;
      n++; sx += x; sy += y; sxx += x * x; syy += y * y; sxy += x * y;
      let edge = false;
      if (x > 0) { const j = i - 1; if (L[j] === lab) { if (comp[j] < 0) { comp[j] = id; stack[sp++] = j; } } else edge = true; } else edge = true;
      if (x < w - 1) { const j = i + 1; if (L[j] === lab) { if (comp[j] < 0) { comp[j] = id; stack[sp++] = j; } } else edge = true; } else edge = true;
      if (y > 0) { const j = i - w; if (L[j] === lab) { if (comp[j] < 0) { comp[j] = id; stack[sp++] = j; } } else edge = true; } else edge = true;
      if (y < h - 1) { const j = i + w; if (L[j] === lab) { if (comp[j] < 0) { comp[j] = id; stack[sp++] = j; } } else edge = true; } else edge = true;
      if (edge) per++;
    }
    acc.push([n, sx, sy, sxx, syy, sxy, per]);
    st.push(lab);
  }
  const n = acc.length;
  const out = {
    comp, n,
    cos: new Float32Array(n), sin: new Float32Array(n), cx: new Float32Array(n), cy: new Float32Array(n),
    radius: new Float32Array(n), thick: new Float32Array(n), mode: new Uint8Array(n), area: new Float32Array(n),
    ring: new Uint8Array(n), width: w, height: h,
  };
  for (let c = 0; c < n; c++) {
    const [k, sx, sy, sxx, syy, sxy, per] = acc[c];
    const mx = sx / k, my = sy / k;
    const vxx = sxx / k - mx * mx, vyy = syy / k - my * my, vxy = sxy / k - mx * my;
    const phi = 0.5 * Math.atan2(2 * vxy, vxx - vyy); // major axis
    const tr = vxx + vyy, det = vxx * vyy - vxy * vxy;
    const disc = Math.sqrt(Math.max(0, tr * tr / 4 - det));
    const l1 = tr / 2 + disc, l2 = Math.max(1e-6, tr / 2 - disc);
    const thick = per > 0 ? (2 * k) / per : Math.sqrt(k);
    out.cos[c] = Math.cos(phi); out.sin[c] = Math.sin(phi);
    out.cx[c] = mx; out.cy[c] = my; out.area[c] = k; out.thick[c] = thick;
    // ring/arc detection: roughly isotropic spread but thin compared with its extent
    const spread = Math.sqrt(l1 + l2);
    out.radius[c] = spread;
    out.ring[c] = l1 / l2 < 2.2 && thick < spread * 0.35 && k > 60 ? 1 : 0;
  }
  return out;
}

/**
 * Decide per region: 0 linear satin, 1 radial satin, 2 tatami fill (wide areas).
 * @param {object} comps   analyzeComponents() result
 * @param {number} mmPerLabelPx
 * @param {number} satinMaxMm  wider columns are filled with tatami (default 7 mm, like real satin limits)
 */
export function chooseModes(comps, mmPerLabelPx, satinMaxMm = 7) {
  for (let c = 0; c < comps.n; c++) {
    const tMm = comps.thick[c] * mmPerLabelPx;
    const aMm2 = comps.area[c] * mmPerLabelPx * mmPerLabelPx;
    if (aMm2 < 2.5 && tMm <= satinMaxMm) {
      // tiny specks: one calm common direction instead of a noisy mix of angles
      comps.mode[c] = 0; comps.cos[c] = 0.8776; comps.sin[c] = -0.4794;
    } else comps.mode[c] = tMm > satinMaxMm ? 2 : comps.ring[c] ? 1 : 0;
  }
  return comps;
}

/**
 * Stylize an RGBA layer in place.
 * @param {{width:number,height:number,data:Uint8ClampedArray}} img   layer pixels (already drawn, antialiased)
 * @param {object} o
 * @param {'embroidered'|'woven'|'chenille'|'pvc'|'leather'} o.technique
 * @param {number} o.pxPerMm             layer px per mm on the patch
 * @param {{width:number,height:number,data:Uint8Array}|null} [o.labels]
 * @param {object|null} [o.comps]        analyzeComponents() (+chooseModes) for labels
 * @param {Uint8Array|null} [o.colors]   rgb per label (3 bytes each) — thread colours; null = keep source colour
 * @param {{mode:number, cos:number, sin:number, cx:number, cy:number, r:number}|null} [o.single] region for mask-only layers (text)
 * @param {number[]} [o.engrave]         leather engraving rgb
 */
export function stylizePixels(img, o) {
  luts();
  const W = img.width, H = img.height, D = img.data;
  const tech = o.technique, ppm = Math.max(0.5, o.pxPerMm);
  const lab = o.labels || null, lw = lab ? lab.width : 0, lh = lab ? lab.height : 0, LD = lab ? lab.data : null;
  const comps = o.comps || null, colors = o.colors || null;
  const xs = new Int32Array(W), ys = new Int32Array(H);
  if (lab) {
    for (let x = 0; x < W; x++) xs[x] = Math.min(lw - 1, ((x + 0.5) * lw / W) | 0);
    for (let y = 0; y < H; y++) ys[y] = Math.min(lh - 1, ((y + 0.5) * lh / H) | 0);
  }
  // scale between layer px and label px (for component centres)
  const sxl = lab ? W / lw : 1, syl = lab ? H / lh : 1;
  // relief offset (layer px)
  const k = Math.max(1, Math.round(ppm * (tech === 'pvc' ? 0.55 : tech === 'leather' ? 0.3 : 0.35)));
  const k2 = Math.max(1, k >> 1);
  // region lookup helper values
  const single = o.single || null;

  // per-technique constants
  const satinSp = ppm * 0.42;                // satin thread pitch ≈ 0.42 mm
  const invSat = 1 / Math.max(1.6, satinSp);
  const rowSp = 1 / Math.max(1.6, ppm * 0.45); // tatami rows
  const stitchLen = 1 / Math.max(4, ppm * 2.6);
  const weave = Math.max(1.2, ppm * 0.3);
  const loop = Math.max(2, ppm * 1.4);
  const eng = o.engrave || [60, 35, 20];

  // per-component sheen: satin catches more light when threads lie across the light direction
  let sheen = null;
  if (comps) {
    sheen = new Float32Array(comps.n);
    for (let c = 0; c < comps.n; c++) {
      const threadAng = Math.atan2(comps.sin[c], comps.cos[c]) + Math.PI / 2;
      sheen[c] = 0.35 + 0.65 * Math.abs(Math.cos(threadAng - LIGHT));
    }
  }

  const getLab = (x, y) => {
    if (x < 0 || y < 0 || x >= W || y >= H) return 255;
    return LD[ys[y] * lw + xs[x]];
  };
  const getA = (x, y) => (x < 0 || y < 0 || x >= W || y >= H ? 0 : D[(y * W + x) * 4 + 3]);

  // neighbour lookups at ±k without bounds checks in the hot loop (-1 = outside)
  const xm = new Int32Array(W), xp = new Int32Array(W);
  for (let x = 0; x < W; x++) { xm[x] = x - k >= 0 ? xs[x - k] : -1; xp[x] = x + k < W ? xs[x + k] : -1; }

  for (let y = 0; y < H; y++) {
    const row = y * W;
    const ly = lab ? ys[y] * lw : 0;
    const rUL = lab && y - k >= 0 ? ys[y - k] * lw : -1, rLR = lab && y + k < H ? ys[y + k] * lw : -1;
    for (let x = 0; x < W; x++) {
      const p = (row + x) * 4;
      const a = D[p + 3];
      if (a === 0) continue;
      let r = D[p], g = D[p + 1], b = D[p + 2];
      let L = 255, c = -1;
      if (lab) {
        L = LD[ly + xs[x]];
        if (L !== 255) {
          if (colors) { r = colors[L * 3]; g = colors[L * 3 + 1]; b = colors[L * 3 + 2]; }
          c = comps ? comps.comp[ly + xs[x]] : -1;
        }
      }
      // edge relief: different region (or nothing) towards the light / away from it
      let ul, lr;
      if (lab) {
        const cu = xm[x], cl = xp[x];
        ul = rUL < 0 || cu < 0 || LD[rUL + cu] !== L;
        lr = rLR < 0 || cl < 0 || LD[rLR + cl] !== L;
      } else {
        ul = getA(x - k, y - k) < 110; lr = getA(x + k, y + k) < 110;
      }
      let f = 1, add = 0;

      if (tech === 'embroidered') {
        let mode, cs, sn, ccx, ccy, rr;
        if (c >= 0) { mode = comps.mode[c]; cs = comps.cos[c]; sn = comps.sin[c]; ccx = comps.cx[c] * sxl; ccy = comps.cy[c] * syl; rr = comps.radius[c] * sxl; }
        else if (single) { mode = single.mode; cs = single.cos; sn = single.sin; ccx = single.cx; ccy = single.cy; rr = single.r; }
        else { mode = 0; cs = 0.7071; sn = 0.7071; ccx = 0; ccy = 0; rr = 1; }
        if (mode === 2) {
          // tatami: rows along the region axis, brick-offset needle points
          const v = (-x * sn + y * cs) * rowSp, wv = (x * cs + y * sn) * stitchLen;
          const rowI = Math.floor(v), t = v - rowI;
          const q = wv + rowI * 0.371; const tq = q - Math.floor(q);
          const pr = PROF[(t * 256) | 0];
          f = 0.80 + 0.24 * pr - (tq < 0.07 ? 0.16 : 0);
          add = 18 * SPEC[(t * 256) | 0] * (sheen && c >= 0 ? sheen[c] : 0.6);
        } else {
          let u;
          if (mode === 1) u = Math.atan2(y - ccy, x - ccx) * Math.max(4, rr) * invSat;
          else u = (x * cs + y * sn) * invSat;
          const ui = Math.floor(u), t = u - ui;
          const pr = PROF[(t * 256) | 0];
          const varn = (((ui * 2654435761) ^ (c * 40503)) >>> 0) / 4294967296 - 0.5;
          const sh = sheen && c >= 0 ? sheen[c] : 0.7;
          f = 0.70 + 0.36 * pr + varn * 0.08;
          add = 70 * sh * SPEC[(t * 256) | 0];
        }
        if (ul && !lr) { f *= 1.10; add += 10; } else if (lr && !ul) f *= 0.72;
      } else if (tech === 'woven') {
        const gx = Math.floor(x / weave), gy = Math.floor(y / weave);
        let t;
        if ((gx + gy) & 1) t = y / weave - gy; else t = x / weave - gx;
        const pr = PROF[(t * 256) | 0];
        f = 0.86 + 0.16 * pr;
        add = 10 * SPEC[(t * 256) | 0];
        if (lr && !ul) f *= 0.9;
      } else if (tech === 'chenille') {
        // looped yarn: soft bumps on a jittered grid (each loop is a small dome) + fine fibre noise
        const gx = Math.floor(x / loop), gy = Math.floor(y / loop);
        const hsh = ((gx * 73856093) ^ (gy * 19349663)) >>> 0;
        const ox = (gx + 0.5 + ((hsh & 255) / 255 - 0.5) * 0.6) * loop;
        const oy = (gy + 0.5 + (((hsh >> 8) & 255) / 255 - 0.5) * 0.6) * loop;
        const dx = (x - ox) / loop, dy = (y - oy) / loop;
        const dome = Math.max(0, 1 - (dx * dx + dy * dy) * 2.2);
        const fib = (((x * 374761393 + y * 668265263) ^ (hsh >> 5)) >>> 0) / 4294967296;
        // light from the top left: the upper-left flank of every loop is brighter
        f = 0.72 + 0.26 * dome - 0.18 * (dx + dy) * dome + (fib - 0.5) * 0.16;
        // raised pile: highlight / shade along the outline; fuzzy only towards the base felt
        let ulb, lrb;
        if (lab) { ulb = rUL < 0 || xm[x] < 0 || LD[rUL + xm[x]] === 255; lrb = rLR < 0 || xp[x] < 0 || LD[rLR + xp[x]] === 255; } else { ulb = ul; lrb = lr; }
        if (ul && !lr) f *= 1.1; else if (lr && !ul) f *= 0.8;
        if (ulb || lrb) D[p + 3] = (a * (0.45 + 0.55 * fib)) | 0;
      } else if (tech === 'pvc') {
        // flat colour levels with a rounded bevel
        let ul2, lr2;
        if (lab) { ul2 = getLab(x - k2, y - k2) !== L; lr2 = getLab(x + k2, y + k2) !== L; }
        else { ul2 = getA(x - k2, y - k2) < 110; lr2 = getA(x + k2, y + k2) < 110; }
        if (ul2 && !lr2) add = 70; else if (ul && !lr) add = 34;
        if (lr2 && !ul2) f = 0.62; else if (lr && !ul) f = 0.8;
      } else if (tech === 'leather') {
        // monochrome engraving: depth from coverage + darkness of the source
        const l = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
        // laser engraving burns dark parts of the design deeper; light parts stay (almost) bare leather
        let e = (1 - l - 0.18) / 0.6;
        e = e < 0 ? 0 : e > 1 ? 1 : e * e * (3 - 2 * e);
        e *= a / 255;
        r = eng[0]; g = eng[1]; b = eng[2];
        D[p + 3] = Math.min(255, e * 240) | 0;
        // engraved walls: the upper-left wall is in shadow, the lower-right lip catches light
        if (ul && !lr) f = 0.55; else if (lr && !ul) { f = 1; add = 38; }
      }
      D[p] = Math.min(255, r * f + add);
      D[p + 1] = Math.min(255, g * f + add);
      D[p + 2] = Math.min(255, b * f + add);
    }
  }
  return img;
}

/** Build the rgb byte table for labels from hex strings (index = label). */
export function colorTable(hexes) {
  const t = new Uint8Array(256 * 3);
  for (let i = 0; i < hexes.length && i < 255; i++) {
    const h = String(hexes[i] || '').replace('#', '');
    if (!/^[0-9a-f]{6}$/i.test(h)) continue;
    const n = parseInt(h, 16);
    t[i * 3] = (n >> 16) & 255; t[i * 3 + 1] = (n >> 8) & 255; t[i * 3 + 2] = n & 255;
  }
  return t;
}

/**
 * Clean label map for thread looks: 3×3 majority filter (2 passes) removes speckles that no digitiser would
 * stitch, so regions get clean outlines and stable stitch directions. Returns a new labels object.
 */
export function cleanLabels(labels, passes = 2) {
  const w = labels.width, h = labels.height;
  let src = labels.data, dst = new Uint8Array(w * h);
  const cnt = new Uint16Array(256), nb = new Uint8Array(8);
  for (let pass = 0; pass < passes; pass++) {
    dst.set(src);
    for (let y = 1; y < h - 1; y++) {
      const r = y * w;
      for (let x = 1; x < w - 1; x++) {
        const i = r + x, c = src[i];
        nb[0] = src[i - w - 1]; nb[1] = src[i - w]; nb[2] = src[i - w + 1]; nb[3] = src[i - 1];
        nb[4] = src[i + 1]; nb[5] = src[i + w - 1]; nb[6] = src[i + w]; nb[7] = src[i + w + 1];
        const same = (nb[0] === c) + (nb[1] === c) + (nb[2] === c) + (nb[3] === c) + (nb[4] === c) + (nb[5] === c) + (nb[6] === c) + (nb[7] === c);
        if (same >= 3) continue;
        let best = c, bc = 0;
        for (let j = 0; j < 8; j++) { const v = nb[j], n = ++cnt[v]; if (n > bc) { bc = n; best = v; } }
        for (let j = 0; j < 8; j++) cnt[nb[j]] = 0;
        dst[i] = best;
      }
    }
    src = dst; dst = new Uint8Array(w * h);
  }
  return { width: w, height: h, data: src };
}
