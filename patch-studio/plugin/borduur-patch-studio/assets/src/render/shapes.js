/**
 * Patch Studio — patch outlines as polygons (pure, no DOM). All outlines are closed polygons
 * [[x,y],…] in the caller's unit (mm or px), oriented CLOCKWISE on screen (y down), starting at top centre.
 * Using polygons everywhere (instead of only Path2D) lets the edge renderer walk the outline
 * (wrap stitches, merrow, fraying) and lets arc text follow any shape.
 */

const TAU = Math.PI * 2;

function arcPts(out, cx, cy, rx, ry, a0, a1, n) {
  for (let i = 0; i <= n; i++) {
    const a = a0 + (a1 - a0) * (i / n);
    out.push([cx + rx * Math.cos(a), cy + ry * Math.sin(a)]);
  }
}

function roundedRectPts(w, h, r) {
  r = Math.max(0.0001, Math.min(r, w / 2, h / 2));
  const n = Math.max(4, Math.min(18, Math.round(r * 2)));
  const p = [[w / 2, 0]];
  arcPts(p, w - r, r, r, r, -Math.PI / 2, 0, n);
  arcPts(p, w - r, h - r, r, r, 0, Math.PI / 2, n);
  arcPts(p, r, h - r, r, r, Math.PI / 2, Math.PI, n);
  arcPts(p, r, r, r, r, Math.PI, Math.PI * 1.5, n);
  return dedupe(p);
}

function quadPts(out, x0, y0, cx, cy, x1, y1, n) {
  for (let i = 1; i <= n; i++) {
    const t = i / n, u = 1 - t;
    out.push([u * u * x0 + 2 * u * t * cx + t * t * x1, u * u * y0 + 2 * u * t * cy + t * t * y1]);
  }
}

function shieldPts(w, h) {
  const r = Math.min(w, h) * 0.06, sideY = h * 0.42, n = 28;
  const p = [[w / 2, 0]];
  arcPts(p, w - r, r, r, r, -Math.PI / 2, 0, 6);
  p.push([w, sideY]);
  quadPts(p, w, sideY, w, h * 0.8, w / 2, h, n);
  quadPts(p, w / 2, h, 0, h * 0.8, 0, sideY, n);
  p.push([0, r]);
  arcPts(p, r, r, r, r, Math.PI, Math.PI * 1.5, 6);
  return chaikin(dedupe(p), 1);
}

/**
 * Built-in outline for a shape id. `contour` has no analytic outline: callers pass the traced polygon;
 * without one it falls back to a rounded rectangle.
 * @param {string} shape  circle|oval|rect|rounded|shield|contour
 * @param {number} w
 * @param {number} h
 * @param {{cornerRatio?:number, segments?:number}} [o]
 */
export function shapePolygon(shape, w, h, o = {}) {
  const min = Math.min(w, h);
  switch (shape) {
    case 'circle':
    case 'oval': {
      const n = o.segments || 160, p = [];
      for (let i = 0; i < n; i++) {
        const a = -Math.PI / 2 + (TAU * i) / n;
        p.push([w / 2 + (w / 2) * Math.cos(a), h / 2 + (h / 2) * Math.sin(a)]);
      }
      return p;
    }
    case 'rect': return roundedRectPts(w, h, min * 0.035);
    case 'shield': return shieldPts(w, h);
    case 'rounded':
    case 'contour':
    default: return roundedRectPts(w, h, min * (o.cornerRatio != null ? o.cornerRatio : 0.18));
  }
}

function dedupe(p) {
  const out = [];
  for (const q of p) {
    const l = out[out.length - 1];
    if (!l || Math.abs(l[0] - q[0]) > 1e-6 || Math.abs(l[1] - q[1]) > 1e-6) out.push(q);
  }
  const f = out[0], l = out[out.length - 1];
  if (out.length > 1 && Math.abs(f[0] - l[0]) < 1e-6 && Math.abs(f[1] - l[1]) < 1e-6) out.pop();
  return out;
}

/** Chaikin corner cutting (closed polygon). */
export function chaikin(p, iter = 1) {
  let cur = p;
  for (let k = 0; k < iter; k++) {
    const out = [], n = cur.length;
    for (let i = 0; i < n; i++) {
      const a = cur[i], b = cur[(i + 1) % n];
      out.push([a[0] * 0.75 + b[0] * 0.25, a[1] * 0.75 + b[1] * 0.25], [a[0] * 0.25 + b[0] * 0.75, a[1] * 0.25 + b[1] * 0.75]);
    }
    cur = out;
  }
  return cur;
}

/** Signed area (positive = clockwise on screen with y down). */
export function signedArea(p) {
  let s = 0;
  for (let i = 0, n = p.length; i < n; i++) {
    const a = p[i], b = p[(i + 1) % n];
    s += a[0] * b[1] - b[0] * a[1];
  }
  return s / 2;
}

export function bbox(p) {
  let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
  for (const q of p) {
    if (q[0] < x0) x0 = q[0]; if (q[0] > x1) x1 = q[0];
    if (q[1] < y0) y0 = q[1]; if (q[1] > y1) y1 = q[1];
  }
  return { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
}

/** Per-vertex inward unit normals (clockwise polygon) with miter factor. */
export function vertexNormals(p, miterLimit = 2.5) {
  const n = p.length, out = new Array(n);
  for (let i = 0; i < n; i++) {
    const a = p[(i - 1 + n) % n], b = p[i], c = p[(i + 1) % n];
    let e1x = b[0] - a[0], e1y = b[1] - a[1], e2x = c[0] - b[0], e2y = c[1] - b[1];
    const l1 = Math.hypot(e1x, e1y) || 1, l2 = Math.hypot(e2x, e2y) || 1;
    const n1x = -e1y / l1, n1y = e1x / l1, n2x = -e2y / l2, n2y = e2x / l2;
    let nx = n1x + n2x, ny = n1y + n2y;
    const ln = Math.hypot(nx, ny);
    if (ln < 1e-9) { nx = n1x; ny = n1y; } else { nx /= ln; ny /= ln; }
    const cos = nx * n1x + ny * n1y;
    const m = Math.min(miterLimit, 1 / Math.max(1e-3, cos));
    out[i] = [nx, ny, m];
  }
  return out;
}

/** Offset a clockwise polygon inward by d (negative = outward). */
export function offsetPolygon(p, d, miterLimit = 2.5) {
  if (!d) return p.map((q) => [q[0], q[1]]);
  const nm = vertexNormals(p, miterLimit);
  return p.map((q, i) => [q[0] + nm[i][0] * d * nm[i][2], q[1] + nm[i][1] * d * nm[i][2]]);
}

/** Map a polygon by scale + translation. */
export function transformPolygon(p, s, ox, oy) {
  return p.map((q) => [ox + q[0] * s, oy + q[1] * s]);
}

/** Cumulative arc lengths of a closed polygon. */
export function measure(p) {
  const n = p.length, cum = new Float64Array(n + 1);
  for (let i = 0; i < n; i++) {
    const a = p[i], b = p[(i + 1) % n];
    cum[i + 1] = cum[i] + Math.hypot(b[0] - a[0], b[1] - a[1]);
  }
  return { cum, total: cum[n] };
}

/** Point + unit tangent at arc length s (wraps around). */
export function pointAt(p, m, s) {
  const n = p.length, T = m.total || 1;
  s = ((s % T) + T) % T;
  let lo = 0, hi = n;
  while (hi - lo > 1) { const mid = (lo + hi) >> 1; if (m.cum[mid] <= s) lo = mid; else hi = mid; }
  const a = p[lo], b = p[(lo + 1) % n], seg = m.cum[lo + 1] - m.cum[lo] || 1;
  const t = (s - m.cum[lo]) / seg, dx = b[0] - a[0], dy = b[1] - a[1], l = Math.hypot(dx, dy) || 1;
  return { x: a[0] + dx * t, y: a[1] + dy * t, tx: dx / l, ty: dy / l };
}

/** Arc length where the ray from (cx,cy) with angle `ang` (radians, screen) leaves the polygon. */
export function rayArcLength(p, m, cx, cy, ang) {
  const dx = Math.cos(ang), dy = Math.sin(ang);
  let best = -1, bestS = 0;
  for (let i = 0, n = p.length; i < n; i++) {
    const a = p[i], b = p[(i + 1) % n];
    const ex = b[0] - a[0], ey = b[1] - a[1];
    const den = dx * ey - dy * ex;
    if (Math.abs(den) < 1e-12) continue;
    const ax = a[0] - cx, ay = a[1] - cy;
    const t = (ax * ey - ay * ex) / den;   // along the ray
    const u = (ax * dy - ay * dx) / den;   // along the segment
    if (t > 0 && u >= 0 && u <= 1 && t > best) { best = t; bestS = m.cum[i] + u * Math.hypot(ex, ey); }
  }
  return bestS;
}

/** Apply the polygon as a path on a 2D context (or Path2D). */
export function tracePath(ctx, p) {
  if (!p.length) return;
  ctx.moveTo(p[0][0], p[0][1]);
  for (let i = 1; i < p.length; i++) ctx.lineTo(p[i][0], p[i][1]);
  ctx.closePath();
}

/* ------------------------------------------------------------------ */
/* Contour tracing (pure): mask → smooth outline, dilated by an offset */
/* ------------------------------------------------------------------ */

/** Two-pass chamfer distance (3-4 metric /3 ≈ Euclidean px) from every pixel to the nearest `on` pixel. */
export function distanceTo(mask, w, h, on = 1) {
  const INF = 1e9, d = new Float32Array(w * h);
  for (let i = 0; i < w * h; i++) d[i] = mask[i] === on ? 0 : INF;
  const A = 1, B = 1.4142;
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    const i = y * w + x; let v = d[i]; if (!v) continue;
    if (x > 0 && d[i - 1] + A < v) v = d[i - 1] + A;
    if (y > 0) {
      if (d[i - w] + A < v) v = d[i - w] + A;
      if (x > 0 && d[i - w - 1] + B < v) v = d[i - w - 1] + B;
      if (x < w - 1 && d[i - w + 1] + B < v) v = d[i - w + 1] + B;
    }
    d[i] = v;
  }
  for (let y = h - 1; y >= 0; y--) for (let x = w - 1; x >= 0; x--) {
    const i = y * w + x; let v = d[i]; if (!v) continue;
    if (x < w - 1 && d[i + 1] + A < v) v = d[i + 1] + A;
    if (y < h - 1) {
      if (d[i + w] + A < v) v = d[i + w] + A;
      if (x < w - 1 && d[i + w + 1] + B < v) v = d[i + w + 1] + B;
      if (x > 0 && d[i + w - 1] + B < v) v = d[i + w - 1] + B;
    }
    d[i] = v;
  }
  return d;
}

/** Morphological closing + dilation + hole filling. Returns {mask, components}. */
export function growMask(mask, w, h, offsetPx, closePx) {
  const dIn = distanceTo(mask, w, h, 1);
  const grown = new Uint8Array(w * h);
  const R = offsetPx + closePx;
  for (let i = 0; i < w * h; i++) grown[i] = dIn[i] <= R ? 1 : 0;
  let out = grown;
  if (closePx > 0) {
    const dOut = distanceTo(grown, w, h, 0);
    out = new Uint8Array(w * h);
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      const i = y * w + x;
      // the grid border counts as "outside"
      const border = Math.min(x + 1, y + 1, w - x, h - y);
      out[i] = grown[i] && Math.min(dOut[i], border) > closePx ? 1 : 0;
    }
  }
  // fill holes: flood the outside from the border
  const outside = new Uint8Array(w * h), stack = [];
  const push = (i) => { if (!out[i] && !outside[i]) { outside[i] = 1; stack.push(i); } };
  for (let x = 0; x < w; x++) { push(x); push((h - 1) * w + x); }
  for (let y = 0; y < h; y++) { push(y * w); push(y * w + w - 1); }
  while (stack.length) {
    const i = stack.pop(), x = i % w, y = (i / w) | 0;
    if (x > 0) push(i - 1); if (x < w - 1) push(i + 1);
    if (y > 0) push(i - w); if (y < h - 1) push(i + w);
  }
  const filled = new Uint8Array(w * h);
  for (let i = 0; i < w * h; i++) filled[i] = outside[i] ? 0 : 1;
  // count 4-connected components
  const lab = new Int32Array(w * h);
  let comps = 0;
  const sizes = [];
  for (let i = 0; i < w * h; i++) {
    if (!filled[i] || lab[i]) continue;
    comps++; let size = 0;
    lab[i] = comps; stack.push(i);
    while (stack.length) {
      const j = stack.pop(), x = j % w, y = (j / w) | 0; size++;
      const nb = [x > 0 ? j - 1 : -1, x < w - 1 ? j + 1 : -1, y > 0 ? j - w : -1, y < h - 1 ? j + w : -1];
      for (const k of nb) if (k >= 0 && filled[k] && !lab[k]) { lab[k] = comps; stack.push(k); }
    }
    sizes.push(size);
  }
  return { mask: filled, components: comps, labels: lab, sizes };
}

/** Trace the outer boundary of the component containing the first `on` pixel (Moore neighbour tracing). */
export function traceBoundary(mask, w, h) {
  let start = -1;
  for (let i = 0; i < w * h; i++) if (mask[i]) { start = i; break; }
  if (start < 0) return [];
  const dirs = [[1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1], [0, -1], [1, -1]];
  const at = (x, y) => x >= 0 && y >= 0 && x < w && y < h && mask[y * w + x] === 1;
  const sx = start % w, sy = (start / w) | 0;
  const pts = [[sx, sy]];
  let x = sx, y = sy, back = 4; // we arrived from the west
  const maxSteps = w * h * 4;
  for (let step = 0; step < maxSteps; step++) {
    let found = false;
    for (let k = 1; k <= 8; k++) {
      const d = (back + k) % 8, nx = x + dirs[d][0], ny = y + dirs[d][1];
      if (at(nx, ny)) {
        x = nx; y = ny; back = (d + 4) % 8; found = true;
        break;
      }
    }
    if (!found) break;
    if (x === sx && y === sy) break;
    pts.push([x, y]);
  }
  return pts;
}

/** Ramer–Douglas–Peucker for a closed polygon. */
export function simplify(p, eps) {
  if (p.length < 8) return p;
  const rdp = (pts) => {
    if (pts.length < 3) return pts;
    const a = pts[0], b = pts[pts.length - 1];
    const dx = b[0] - a[0], dy = b[1] - a[1], L = Math.hypot(dx, dy) || 1;
    let md = 0, mi = 0;
    for (let i = 1; i < pts.length - 1; i++) {
      const d = Math.abs((pts[i][0] - a[0]) * dy - (pts[i][1] - a[1]) * dx) / L;
      if (d > md) { md = d; mi = i; }
    }
    if (md <= eps) return [a, b];
    const l = rdp(pts.slice(0, mi + 1)), r = rdp(pts.slice(mi));
    return l.slice(0, -1).concat(r);
  };
  const half = p.length >> 1;
  const A = rdp(p.slice(0, half + 1)), B = rdp(p.slice(half).concat([p[0]]));
  return A.slice(0, -1).concat(B.slice(0, -1));
}

/**
 * Full contour pipeline on a binary mask (1 = ink): dilate by offsetPx, round off narrow notches (closePx),
 * fill holes and return a smooth clockwise polygon in grid px, or null when the result is not one piece.
 */
export function contourFromMask(mask, w, h, offsetPx, closePx) {
  const g = growMask(mask, w, h, offsetPx, closePx);
  if (!g.components) return null;
  let m = g.mask;
  if (g.components > 1) {
    // keep only components that matter (> 2 % of the largest); tiny specks are dropped
    const maxS = Math.max(...g.sizes);
    const keep = g.sizes.map((s) => s >= maxS * 0.02);
    if (keep.filter(Boolean).length > 1) return null;
    const id = g.sizes.indexOf(maxS) + 1;
    m = new Uint8Array(w * h);
    for (let i = 0; i < w * h; i++) m[i] = g.labels[i] === id ? 1 : 0;
  }
  let poly = traceBoundary(m, w, h);
  if (poly.length < 3) return null;
  // shift to pixel centres, simplify, smooth
  poly = poly.map((q) => [q[0] + 0.5, q[1] + 0.5]);
  poly = simplify(poly, 0.6);
  poly = chaikin(poly, 2);
  if (signedArea(poly) < 0) poly.reverse();
  return poly;
}

/** Convex hull (monotone chain) → clockwise on screen. Fallback for contour shapes that stay in pieces. */
export function convexHull(points) {
  const p = points.slice().sort((a, b) => a[0] - b[0] || a[1] - b[1]);
  if (p.length < 3) return p;
  const cross = (o, a, b) => (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0]);
  const lo = [], up = [];
  for (const q of p) { while (lo.length >= 2 && cross(lo[lo.length - 2], lo[lo.length - 1], q) <= 0) lo.pop(); lo.push(q); }
  for (let i = p.length - 1; i >= 0; i--) { const q = p[i]; while (up.length >= 2 && cross(up[up.length - 2], up[up.length - 1], q) <= 0) up.pop(); up.push(q); }
  const hull = lo.slice(0, -1).concat(up.slice(0, -1));
  if (signedArea(hull) < 0) hull.reverse();
  return hull;
}
