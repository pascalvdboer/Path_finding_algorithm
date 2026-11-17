/**
 * Patch Studio — distance fields for the material renderer (pure, typed arrays, no DOM).
 *
 *  - edt(feature, w, h): exact Euclidean distance (Felzenszwalb & Huttenlocher) from every cell to the
 *    nearest feature cell. Used for satin domes, PVC bevels, chenille pile and woven region edges.
 *  - regionDistance(labels): distance to the border of the own colour region (labels grid, 255 = empty).
 *  - alphaLabels(alpha, w, h, step): label grid from a coverage mask (text layers, untagged art).
 *  - bandField(poly, W, H, inPx, outPx): for every pixel within a band around a closed outline:
 *    signed distance (+ inside), arc length along the outline and the outline tangent. Used for borders
 *    (merrow tube, satin wrap, hot-cut bead, PVC rim) and the rolled patch edge.
 */

const BIG = 1e20;

function edt1d(f, n, d, v, z) {
  let k = 0;
  v[0] = 0; z[0] = -BIG; z[1] = BIG;
  for (let q = 1; q < n; q++) {
    let r = v[k];
    let s = ((f[q] + q * q) - (f[r] + r * r)) / (2 * q - 2 * r);
    while (s <= z[k]) {
      k--;
      r = v[k];
      s = ((f[q] + q * q) - (f[r] + r * r)) / (2 * q - 2 * r);
    }
    k++;
    v[k] = q; z[k] = s; z[k + 1] = BIG;
  }
  k = 0;
  for (let q = 0; q < n; q++) {
    while (z[k + 1] < q) k++;
    const r = v[k];
    d[q] = (q - r) * (q - r) + f[r];
  }
}

/**
 * Exact Euclidean distance transform.
 * @param {Uint8Array} feature  1 = feature cell (distance 0)
 * @returns {Float32Array} distance in cells
 */
export function edt(feature, w, h) {
  const n = Math.max(w, h);
  const f = new Float64Array(n), d = new Float64Array(n), v = new Int32Array(n), z = new Float64Array(n + 1);
  const out = new Float32Array(w * h);
  // columns
  for (let x = 0; x < w; x++) {
    for (let y = 0; y < h; y++) f[y] = feature[y * w + x] ? 0 : BIG;
    edt1d(f, h, d, v, z);
    for (let y = 0; y < h; y++) out[y * w + x] = d[y];
  }
  // rows
  for (let y = 0; y < h; y++) {
    const r = y * w;
    for (let x = 0; x < w; x++) f[x] = out[r + x];
    edt1d(f, w, d, v, z);
    for (let x = 0; x < w; x++) out[r + x] = Math.sqrt(d[x]);
  }
  return out;
}

/**
 * Fast approximate Euclidean distance (two-pass 5×5 chamfer, weights 1 / √2 / √5): within ~2 % of the
 * true distance, smooth enough after blurGrid() for domes and bevels.
 */
export function chamfer(feature, w, h) {
  const d = new Float32Array(w * h);
  for (let i = 0; i < w * h; i++) d[i] = feature[i] ? 0 : 1e9;
  const A = 1, B = Math.SQRT2, C = Math.sqrt(5);
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    const i = y * w + x; let v = d[i]; if (!v) continue;
    if (x > 0 && d[i - 1] + A < v) v = d[i - 1] + A;
    if (y > 0) {
      const u = i - w;
      if (d[u] + A < v) v = d[u] + A;
      if (x > 0 && d[u - 1] + B < v) v = d[u - 1] + B;
      if (x < w - 1 && d[u + 1] + B < v) v = d[u + 1] + B;
      if (x > 1 && d[u - 2] + C < v) v = d[u - 2] + C;
      if (x < w - 2 && d[u + 2] + C < v) v = d[u + 2] + C;
      if (y > 1) {
        const u2 = u - w;
        if (x > 0 && d[u2 - 1] + C < v) v = d[u2 - 1] + C;
        if (x < w - 1 && d[u2 + 1] + C < v) v = d[u2 + 1] + C;
      }
    }
    d[i] = v;
  }
  for (let y = h - 1; y >= 0; y--) for (let x = w - 1; x >= 0; x--) {
    const i = y * w + x; let v = d[i]; if (!v) continue;
    if (x < w - 1 && d[i + 1] + A < v) v = d[i + 1] + A;
    if (y < h - 1) {
      const u = i + w;
      if (d[u] + A < v) v = d[u] + A;
      if (x < w - 1 && d[u + 1] + B < v) v = d[u + 1] + B;
      if (x > 0 && d[u - 1] + B < v) v = d[u - 1] + B;
      if (x < w - 2 && d[u + 2] + C < v) v = d[u + 2] + C;
      if (x > 1 && d[u - 2] + C < v) v = d[u - 2] + C;
      if (y < h - 2) {
        const u2 = u + w;
        if (x < w - 1 && d[u2 + 1] + C < v) v = d[u2 + 1] + C;
        if (x > 0 && d[u2 - 1] + C < v) v = d[u2 - 1] + C;
      }
    }
    d[i] = v;
  }
  return d;
}

/**
 * Distance (in label cells) from each labelled cell to the border of its own region.
 * Border cells (a 4-neighbour with another label, or the grid edge) get 0.5.
 */
export function regionDistance(labels) {
  const w = labels.width, h = labels.height, L = labels.data;
  const feat = new Uint8Array(w * h);
  for (let y = 0; y < h; y++) {
    const r = y * w;
    for (let x = 0; x < w; x++) {
      const i = r + x, c = L[i];
      if (c === 255) { feat[i] = 1; continue; }
      if (x === 0 || y === 0 || x === w - 1 || y === h - 1 || L[i - 1] !== c || L[i + 1] !== c || L[i - w] !== c || L[i + w] !== c) feat[i] = 1;
    }
  }
  const d = chamfer(feat, w, h);
  for (let i = 0; i < w * h; i++) d[i] = L[i] === 255 ? 0 : d[i] + 0.5;
  return d;
}

/**
 * Label grid from an RGBA coverage mask: 0 = covered, 255 = empty. `step` px per cell.
 * @returns {{width:number,height:number,data:Uint8Array}}
 */
export function alphaLabels(rgba, w, h, step) {
  const gw = Math.max(1, Math.ceil(w / step)), gh = Math.max(1, Math.ceil(h / step));
  const data = new Uint8Array(gw * gh).fill(255);
  for (let gy = 0; gy < gh; gy++) {
    const y = Math.min(h - 1, Math.floor((gy + 0.5) * step));
    for (let gx = 0; gx < gw; gx++) {
      const x = Math.min(w - 1, Math.floor((gx + 0.5) * step));
      if (rgba[(y * w + x) * 4 + 3] > 110) data[gy * gw + gx] = 0;
    }
  }
  return { width: gw, height: gh, data };
}

/**
 * Pixels within a band around a closed outline.
 * @param {number[][]} poly  outline in px (closed, clockwise on screen)
 * @param {Uint8Array|null} inside  W×H mask (1 = inside the outline), or null to derive the side from the segment
 * @returns {{n:number, idx:Int32Array, d:Float32Array, s:Float32Array, tx:Float32Array, ty:Float32Array, total:number}}
 *   d > 0 inside, < 0 outside (px); s = arc length (px) of the nearest outline point; (tx,ty) = outline tangent
 */
export function bandField(poly, W, H, inPx, outPx, inside) {
  const n = poly.length;
  const ax = new Float32Array(n), ay = new Float32Array(n), dx = new Float32Array(n), dy = new Float32Array(n), len = new Float32Array(n), cum = new Float32Array(n);
  let total = 0;
  for (let i = 0; i < n; i++) {
    const a = poly[i], b = poly[(i + 1) % n];
    ax[i] = a[0]; ay[i] = a[1];
    const ex = b[0] - a[0], ey = b[1] - a[1], l = Math.hypot(ex, ey) || 1e-6;
    dx[i] = ex / l; dy[i] = ey / l; len[i] = l; cum[i] = total; total += l;
  }
  const R = Math.max(inPx, outPx) + 1;
  const cs = Math.max(6, Math.ceil(R / 3));
  const gw = Math.ceil(W / cs) + 1, gh = Math.ceil(H / cs) + 1;
  const cells = new Array(gw * gh);
  for (let i = 0; i < n; i++) {
    const bx = ax[i] + dx[i] * len[i], by = ay[i] + dy[i] * len[i];
    const x0 = Math.max(0, Math.floor((Math.min(ax[i], bx) - R) / cs)), x1 = Math.min(gw - 1, Math.floor((Math.max(ax[i], bx) + R) / cs));
    const y0 = Math.max(0, Math.floor((Math.min(ay[i], by) - R) / cs)), y1 = Math.min(gh - 1, Math.floor((Math.max(ay[i], by) + R) / cs));
    for (let gy = y0; gy <= y1; gy++) for (let gx = x0; gx <= x1; gx++) {
      const k = gy * gw + gx;
      (cells[k] || (cells[k] = [])).push(i);
    }
  }
  // signed area → which side is inside when no mask is given
  let area = 0;
  for (let i = 0; i < n; i++) { const a = poly[i], b = poly[(i + 1) % n]; area += a[0] * b[1] - b[0] * a[1]; }
  const sgn = area > 0 ? 1 : -1;
  let cap = 1 << 16;
  let idx = new Int32Array(cap), D = new Float32Array(cap), S = new Float32Array(cap), TX = new Float32Array(cap), TY = new Float32Array(cap);
  let m = 0;
  for (let gy = 0; gy < gh; gy++) for (let gx = 0; gx < gw; gx++) {
    const list = cells[gy * gw + gx];
    if (!list) continue;
    const px0 = gx * cs, py0 = gy * cs, px1 = Math.min(W, px0 + cs), py1 = Math.min(H, py0 + cs);
    for (let y = py0; y < py1; y++) {
      const fy = y + 0.5;
      for (let x = px0; x < px1; x++) {
        const fx = x + 0.5;
        let best = 1e30, bi = -1, bt = 0;
        for (let j = 0; j < list.length; j++) {
          const i = list[j];
          const qx = fx - ax[i], qy = fy - ay[i];
          let t = qx * dx[i] + qy * dy[i];
          if (t < 0) t = 0; else if (t > len[i]) t = len[i];
          const ex = qx - dx[i] * t, ey = qy - dy[i] * t, dd = ex * ex + ey * ey;
          if (dd < best) { best = dd; bi = i; bt = t; }
        }
        if (bi < 0) continue;
        const dist = Math.sqrt(best);
        let ins;
        if (inside) ins = inside[y * W + x] === 1;
        else {
          // outward (pseudo) normal of the nearest feature: segment, or the mean of both segments at a vertex
          let ox = dy[bi], oy = -dx[bi];
          if (bt <= 1e-4) { const j = (bi + n - 1) % n; ox += dy[j]; oy -= dx[j]; }
          else if (bt >= len[bi] - 1e-4) { const j = (bi + 1) % n; ox += dy[j]; oy -= dx[j]; }
          const qx = fx - ax[bi] - dx[bi] * bt, qy = fy - ay[bi] - dy[bi] * bt;
          ins = (qx * ox + qy * oy) * sgn < 0;
        }
        const sd = ins ? dist : -dist;
        if (sd > inPx || sd < -outPx) continue;
        if (m === cap) {
          cap *= 2;
          const grow = (A, T) => { const B = new T(cap); B.set(A); return B; };
          idx = grow(idx, Int32Array); D = grow(D, Float32Array); S = grow(S, Float32Array); TX = grow(TX, Float32Array); TY = grow(TY, Float32Array);
        }
        idx[m] = y * W + x; D[m] = sd; S[m] = cum[bi] + bt; TX[m] = dx[bi]; TY[m] = dy[bi];
        m++;
      }
    }
  }
  return { n: m, idx, d: D, s: S, tx: TX, ty: TY, total, sgn };
}

/** Coverage mask (1 inside) from canvas RGBA alpha. */
export function maskFromAlpha(rgba, w, h, thr = 127) {
  const m = new Uint8Array(w * h);
  for (let i = 0, n = w * h; i < n; i++) if (rgba[i * 4 + 3] > thr) m[i] = 1;
  return m;
}

/**
 * Merge colour islands smaller than `minCells` into their most common neighbour label (no digitiser
 * stitches a 0.3 mm speck; in a relief render every speck would become a pit). 255 (empty) is kept.
 */
export function absorbSmall(labels, minCells) {
  const w = labels.width, h = labels.height, src = labels.data, N = w * h;
  const out = new Uint8Array(src);
  if (minCells < 2) return { width: w, height: h, data: out };
  const seen = new Uint8Array(N), stack = new Int32Array(N), members = new Int32Array(N);
  const votes = new Uint32Array(256);
  for (let s = 0; s < N; s++) {
    if (seen[s] || src[s] === 255) continue;
    const lab = src[s];
    let sp = 0, m = 0;
    stack[sp++] = s; seen[s] = 1;
    let big = false;
    while (sp) {
      const i = stack[--sp];
      if (!big) members[m] = i;
      m++;
      if (m >= minCells) big = true;
      const x = i % w;
      if (x > 0 && !seen[i - 1] && src[i - 1] === lab) { seen[i - 1] = 1; stack[sp++] = i - 1; }
      if (x < w - 1 && !seen[i + 1] && src[i + 1] === lab) { seen[i + 1] = 1; stack[sp++] = i + 1; }
      if (i >= w && !seen[i - w] && src[i - w] === lab) { seen[i - w] = 1; stack[sp++] = i - w; }
      if (i < N - w && !seen[i + w] && src[i + w] === lab) { seen[i + w] = 1; stack[sp++] = i + w; }
    }
    if (big) continue;
    votes.fill(0);
    let best = -1, bc = 0;
    for (let k = 0; k < m; k++) {
      const i = members[k], x = i % w;
      const nb = [x > 0 ? i - 1 : -1, x < w - 1 ? i + 1 : -1, i >= w ? i - w : -1, i < N - w ? i + w : -1];
      for (const j of nb) {
        if (j < 0) continue;
        const l = src[j];
        if (l === lab || l === 255) continue;
        const v = ++votes[l];
        if (v > bc) { bc = v; best = l; }
      }
    }
    if (best >= 0) for (let k = 0; k < m; k++) out[members[k]] = best;
  }
  return { width: w, height: h, data: out };
}

/** In-place 3×3 box blur of a distance grid, only where cells are labelled (keeps empty cells at 0). */
export function blurGrid(d, labels, passes = 1) {
  const w = labels.width, h = labels.height, L = labels.data;
  const tmp = new Float32Array(d.length);
  for (let p = 0; p < passes; p++) {
    tmp.set(d);
    for (let y = 1; y < h - 1; y++) for (let x = 1; x < w - 1; x++) {
      const i = y * w + x;
      if (L[i] === 255) continue;
      d[i] = (tmp[i] * 4 + tmp[i - 1] + tmp[i + 1] + tmp[i - w] + tmp[i + w]) / 8;
    }
  }
  return d;
}
