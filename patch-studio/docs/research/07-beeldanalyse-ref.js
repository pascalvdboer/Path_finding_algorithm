/*
 * Patch Studio — referentie-implementatie beeldanalyse (hoort bij 07-beeldanalyse.md)
 * Puur JS, geen afhankelijkheden, werkt in de main thread en in een Web Worker (alleen typed arrays).
 * Gevalideerd met Playwright/Chromium op de testbeelden (zie 07-beeldanalyse.md §13).
 * Gebruik: kopieer/porteer naar de plugin (assets/js/analyse-worker.js). Alle drempels staan in TH.
 */
(function (root) {
  'use strict';

  /* ---------- Drempels (één plek, zodat de bouwers kunnen tunen) ---------- */
  const TH = {
    ANALYSE_PX: 360,          // langste zijde voor kleur/vorm-analyse
    FLAT_DE: 2.5,             // ΔE76 tussen buren: kleiner = "zelfde vlak" (absorbeert JPG-ruis q>=70)
    HARD_DE: 18,              // ΔE76 tussen buren: groter = harde rand
    MERGE_DE00: 7,            // paletkleuren dichter dan dit (ΔE2000) worden één garenkleur
    MIN_SHARE: 0.004,         // kleur < 0,4 % van het ontwerp = ruis/anti-aliasing (tenzij dunne lijn, zie doc)
    MAX_K: 16,                // k-means start-k
    RESID_FLAT: 4.0,          // gem. ΔE2000 pixel->palet: onder = vlak logo
    RESID_PHOTO: 7.5,         // boven = foto/verloop-rijk
    WHITE_L: 92, WHITE_C: 6,  // "wit": L*>92 en chroma<6
  };

  /* ---------- sRGB -> CIELAB (D65), met LUT ---------- */
  const LIN = new Float32Array(256);
  for (let i = 0; i < 256; i++) { const c = i / 255; LIN[i] = c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
  const fLab = t => t > 216 / 24389 ? Math.cbrt(t) : (24389 / 27 * t + 16) / 116;
  function rgb2lab(r, g, b) {
    const R = LIN[r], G = LIN[g], B = LIN[b];
    const X = (0.4124564 * R + 0.3575761 * G + 0.1804375 * B) / 0.95047;
    const Y = (0.2126729 * R + 0.7151522 * G + 0.0721750 * B);
    const Z = (0.0193339 * R + 0.1191920 * G + 0.9503041 * B) / 1.08883;
    const fx = fLab(X), fy = fLab(Y), fz = fLab(Z);
    return [116 * fy - 16, 500 * (fx - fy), 200 * (fy - fz)];
  }
  function lab2rgb(L, a, b) {
    const fy = (L + 16) / 116, fx = fy + a / 500, fz = fy - b / 200;
    const inv = t => t ** 3 > 216 / 24389 ? t ** 3 : (116 * t - 16) / (24389 / 27);
    const X = inv(fx) * 0.95047, Y = inv(fy), Z = inv(fz) * 1.08883;
    const lin = [3.2404542 * X - 1.5371385 * Y - 0.4985314 * Z, -0.9692660 * X + 1.8760108 * Y + 0.0415560 * Z, 0.0556434 * X - 0.2040259 * Y + 1.0572252 * Z];
    return lin.map(c => { c = Math.max(0, Math.min(1, c)); return Math.round(255 * (c <= 0.0031308 ? 12.92 * c : 1.055 * Math.pow(c, 1 / 2.4) - 0.055)); });
  }
  const hex2rgb = h => { h = h.replace('#', ''); return [0, 2, 4].map(i => parseInt(h.slice(i, i + 2), 16)); };

  /* ---------- CIEDE2000 (Sharma, Wu & Dalal 2005), kL=kC=kH=1 ---------- */
  function deltaE00(L1, a1, b1, L2, a2, b2) {
    const d2r = Math.PI / 180, r2d = 180 / Math.PI;
    const C1 = Math.hypot(a1, b1), C2 = Math.hypot(a2, b2), Cb = (C1 + C2) / 2;
    const G = 0.5 * (1 - Math.sqrt(Cb ** 7 / (Cb ** 7 + 25 ** 7)));
    const a1p = (1 + G) * a1, a2p = (1 + G) * a2;
    const C1p = Math.hypot(a1p, b1), C2p = Math.hypot(a2p, b2);
    const h = (b, a) => { if (b === 0 && a === 0) return 0; const v = Math.atan2(b, a) * r2d; return v < 0 ? v + 360 : v; };
    const h1p = h(b1, a1p), h2p = h(b2, a2p);
    const dLp = L2 - L1, dCp = C2p - C1p;
    let dhp = 0;
    if (C1p * C2p !== 0) { dhp = h2p - h1p; if (dhp > 180) dhp -= 360; else if (dhp < -180) dhp += 360; }
    const dHp = 2 * Math.sqrt(C1p * C2p) * Math.sin(dhp * d2r / 2);
    const Lbp = (L1 + L2) / 2, Cbp = (C1p + C2p) / 2;
    let hbp = h1p + h2p;
    if (C1p * C2p !== 0) { if (Math.abs(h1p - h2p) > 180) hbp = (h1p + h2p < 360) ? hbp + 360 : hbp - 360; hbp /= 2; }
    const T = 1 - 0.17 * Math.cos((hbp - 30) * d2r) + 0.24 * Math.cos(2 * hbp * d2r) + 0.32 * Math.cos((3 * hbp + 6) * d2r) - 0.20 * Math.cos((4 * hbp - 63) * d2r);
    const dTheta = 30 * Math.exp(-(((hbp - 275) / 25) ** 2));
    const Rc = 2 * Math.sqrt(Cbp ** 7 / (Cbp ** 7 + 25 ** 7));
    const Sl = 1 + 0.015 * (Lbp - 50) ** 2 / Math.sqrt(20 + (Lbp - 50) ** 2);
    const Sc = 1 + 0.045 * Cbp, Sh = 1 + 0.015 * Cbp * T;
    const Rt = -Math.sin(2 * dTheta * d2r) * Rc;
    return Math.sqrt((dLp / Sl) ** 2 + (dCp / Sc) ** 2 + (dHp / Sh) ** 2 + Rt * (dCp / Sc) * (dHp / Sh));
  }
  const dE00 = (p, q) => deltaE00(p[0], p[1], p[2], q[0], q[1], q[2]);

  /* ---------- 5-bit histogram in Lab (gewogen punten voor k-means: snel, ~32k max) ---------- */
  function labHistogram(rgba, mask) {
    const map = new Map();
    for (let p = 0, i = 0; i < rgba.length; i += 4, p++) {
      if (rgba[i + 3] < 128 || (mask && !mask[p])) continue;
      const key = (rgba[i] >> 3) << 10 | (rgba[i + 1] >> 3) << 5 | (rgba[i + 2] >> 3);
      let e = map.get(key); if (!e) { e = { n: 0, r: 0, g: 0, b: 0 }; map.set(key, e); }
      e.n++; e.r += rgba[i]; e.g += rgba[i + 1]; e.b += rgba[i + 2];
    }
    const pts = [];
    for (const e of map.values()) { const lab = rgb2lab(Math.round(e.r / e.n), Math.round(e.g / e.n), Math.round(e.b / e.n)); pts.push({ n: e.n, lab }); }
    return pts;
  }

  /* ---------- gewogen k-means++ in Lab (deterministisch: vaste seed) ---------- */
  function kmeansLab(pts, k, iters = 12) {
    let seed = 12345; const rnd = () => (seed = (seed * 1103515245 + 12345) >>> 0) / 4294967296;
    const d2 = (a, b) => (a[0] - b[0]) ** 2 + (a[1] - b[1]) ** 2 + (a[2] - b[2]) ** 2;
    k = Math.min(k, pts.length);
    const cs = []; let best = pts[0]; for (const p of pts) if (p.n > best.n) best = p; cs.push(best.lab.slice());
    const D = pts.map(p => d2(p.lab, cs[0]));
    while (cs.length < k) {
      let tot = 0; for (let i = 0; i < pts.length; i++) tot += D[i] * pts[i].n;
      if (tot === 0) break;
      let r = rnd() * tot, j = 0; for (; j < pts.length - 1; j++) { r -= D[j] * pts[j].n; if (r <= 0) break; }
      cs.push(pts[j].lab.slice());
      for (let i = 0; i < pts.length; i++) D[i] = Math.min(D[i], d2(pts[i].lab, cs[cs.length - 1]));
    }
    const asg = new Int32Array(pts.length);
    for (let it = 0; it < iters; it++) {
      const acc = cs.map(() => [0, 0, 0, 0]); let moved = 0;
      for (let i = 0; i < pts.length; i++) {
        let bi = 0, bd = Infinity; for (let c = 0; c < cs.length; c++) { const d = d2(pts[i].lab, cs[c]); if (d < bd) { bd = d; bi = c; } }
        if (asg[i] !== bi) moved++; asg[i] = bi; const a = acc[bi], w = pts[i].n; a[0] += pts[i].lab[0] * w; a[1] += pts[i].lab[1] * w; a[2] += pts[i].lab[2] * w; a[3] += w;
      }
      acc.forEach((a, c) => { if (a[3]) cs[c] = [a[0] / a[3], a[1] / a[3], a[2] / a[3]]; });
      if (it > 0 && moved === 0) break;
    }
    const n = cs.map(() => 0); let err = 0, tot = 0;
    pts.forEach((p, i) => { n[asg[i]] += p.n; err += dE00(p.lab, cs[asg[i]]) * p.n; tot += p.n; });
    return { centers: cs, counts: n, meanDE: err / tot };
  }

  /* ---------- automatisch palet: k-means(16) -> samenvoegen (ΔE00<MERGE) -> kleine kleuren weg ---------- */
  function autoPalette(pts, opts = {}) {
    const maxK = opts.maxK || TH.MAX_K, merge = opts.mergeDE || TH.MERGE_DE00, minShare = opts.minShare ?? TH.MIN_SHARE;
    const km = kmeansLab(pts, maxK);
    let cl = km.centers.map((c, i) => ({ lab: c, n: km.counts[i] })).filter(c => c.n > 0);
    for (;;) {
      let bi = -1, bj = -1, bd = merge;
      for (let i = 0; i < cl.length; i++) for (let j = i + 1; j < cl.length; j++) { const d = dE00(cl[i].lab, cl[j].lab); if (d < bd) { bd = d; bi = i; bj = j; } }
      if (bi < 0) break;
      const a = cl[bi], b = cl[bj], t = a.n + b.n;
      a.lab = [0, 1, 2].map(x => (a.lab[x] * a.n + b.lab[x] * b.n) / t); a.n = t; cl.splice(bj, 1);
    }
    const tot = cl.reduce((s, c) => s + c.n, 0);
    let pal = cl.filter(c => c.n / tot >= minShare).sort((a, b) => b.n - a.n);
    if (!pal.length) pal = [cl.sort((a, b) => b.n - a.n)[0]];
    let err = 0; for (const p of pts) { let bd = Infinity; for (const c of pal) bd = Math.min(bd, dE00(p.lab, c.lab)); err += bd * p.n; }
    return { pal: pal.map(c => ({ lab: c.lab, rgb: lab2rgb(...c.lab), share: c.n / tot })), residual: err / tot, kmeansDE: km.meanDE };
  }

  /* ---------- buur-statistiek: vlak / zacht / hard (in Lab, ΔE76: snel) ---------- */
  function edgeStats(rgba, W, H, mask) {
    const lab = new Float32Array(W * H * 3);
    for (let p = 0; p < W * H; p++) { const [L, a, b] = rgb2lab(rgba[p * 4], rgba[p * 4 + 1], rgba[p * 4 + 2]); lab[p * 3] = L; lab[p * 3 + 1] = a; lab[p * 3 + 2] = b; }
    let flat = 0, soft = 0, hard = 0, n = 0;
    const d = (p, q) => Math.hypot(lab[p * 3] - lab[q * 3], lab[p * 3 + 1] - lab[q * 3 + 1], lab[p * 3 + 2] - lab[q * 3 + 2]);
    for (let y = 0; y < H - 1; y++) for (let x = 0; x < W - 1; x++) {
      const p = y * W + x; if (mask && !mask[p]) continue;
      for (const q of [p + 1, p + W]) { if (mask && !mask[q]) continue; const v = d(p, q); n++; if (v < TH.FLAT_DE) flat++; else if (v < TH.HARD_DE) soft++; else hard++; }
    }
    // "texture": in een 3x3-blok de L*-standaardafwijking; foto's hebben overal kleine variatie, logo's niet
    let tex = 0, tn = 0;
    for (let y = 1; y < H - 1; y += 2) for (let x = 1; x < W - 1; x += 2) {
      const p = y * W + x; if (mask && !mask[p]) continue; let s = 0, s2 = 0;
      for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) { const v = lab[(p + dy * W + dx) * 3]; s += v; s2 += v * v; }
      const sd = Math.sqrt(Math.max(0, s2 / 9 - (s / 9) ** 2)); tn++; if (sd > 1.2 && sd < 8) tex++;
    }
    return { flat: flat / n, soft: soft / n, hard: hard / n, texture: tex / tn, lab };
  }

  /* ---------- label-randen: echte rand of "valse" rand (verloop/schaduw) ----------
   * Elke pixel krijgt de dichtstbijzijnde paletkleur. Bij buren met verschillend label kijken we naar
   * het verschil in het ORIGINEEL. Vlak logo: labelwissels vallen samen met grote sprongen.
   * Verloop/schaduw: labelwissels liggen midden in een vloeiende overgang (klein verschil) = "valse rand". */
  function labelEdges(lab, W, H, mask, pal) {
    const P = pal.map(p => p.lab), L = new Int16Array(W * H).fill(-1);
    for (let p = 0; p < W * H; p++) { if (mask && !mask[p]) continue; let bi = 0, bd = Infinity;
      for (let j = 0; j < P.length; j++) { const d = (lab[p*3]-P[j][0])**2 + (lab[p*3+1]-P[j][1])**2 + (lab[p*3+2]-P[j][2])**2; if (d < bd) { bd = d; bi = j; } } L[p] = bi; }
    let change = 0, fake = 0, n = 0;
    for (let y = 0; y < H - 1; y++) for (let x = 0; x < W - 1; x++) { const p = y * W + x; if (L[p] < 0) continue;
      for (const q of [p + 1, p + W]) { if (L[q] < 0) continue; n++; if (L[q] === L[p]) continue; change++;
        const v = Math.hypot(lab[p*3]-lab[q*3], lab[p*3+1]-lab[q*3+1], lab[p*3+2]-lab[q*3+2]); if (v < 6) fake++; } }
    return { labels: L, changeRatio: change / n, fakeEdge: change ? fake / change : 0 };
  }

  /* ---------- 4-bit bucket dekking (zoals demo-v2): hoeveel buckets dekken 85 % ---------- */
  function cover85(rgba, mask) {
    const m = new Map(); let n = 0;
    for (let p = 0, i = 0; i < rgba.length; i += 4, p++) { if (rgba[i + 3] < 128 || (mask && !mask[p])) continue; n++; const k = (rgba[i] >> 4) << 8 | (rgba[i + 1] >> 4) << 4 | (rgba[i + 2] >> 4); m.set(k, (m.get(k) || 0) + 1); }
    const v = [...m.values()].sort((a, b) => b - a); let acc = 0, c = 0; for (const x of v) { acc += x; c++; if (acc >= n * 0.85) break; } return c;
  }

  /* ---------- classificatie ---------- */
  function classify(f) {
    // f: {fake, chg, residual, flat, soft, hard, texture, cover, palSize}
    // gekalibreerd op 14 testbeelden (zie doc §13). Volgorde is belangrijk.
    if (f.fake < 0.08 && f.cover <= 12) return 'vlak';                         // effen vlakken, ook JPG q30-q60
    if (f.cover >= 70 && f.hard < 0.04) return 'foto';                          // veel tinten, weinig harde contouren
    if (f.hard < 0.012 && f.chg < 0.03 && f.cover <= 40) return 'verloop';      // vloeiend, (bijna) geen randen
    return 'illustratie';                                                       // contouren + tinten/schaduw (of vlak met verloopjes)
  }
  // los kenmerk: bevat het ontwerp ergens een verloop/schaduw (ook als het verder vlak is)?
  const hasSoftShading = f => f.fake >= 0.15;

  /* ---------- steekschatting (zie doc §11). areas in mm², lengths in mm ---------- */
  const ST = { FILL_PER_MM2: 1.6, SATIN_PER_MM: 5.0, RUN_PER_MM: 0.4, PER_COLOR: 30, PER_OBJECT: 15, MERROW_BORDER: 0, SATIN_BORDER_PER_MM: 6.0 };
  function estimateStitches(o) {
    // o: {fillArea, satinLen, runLen, colors, objects, borderType:'merrow'|'satijn'|'gesneden', perimeter}
    let s = o.fillArea * ST.FILL_PER_MM2 + o.satinLen * ST.SATIN_PER_MM + o.runLen * ST.RUN_PER_MM + o.colors * ST.PER_COLOR + o.objects * ST.PER_OBJECT;
    if (o.borderType === 'satijn') s += o.perimeter * ST.SATIN_BORDER_PER_MM;
    return { mid: Math.round(s), low: Math.round(s * 0.8), high: Math.round(s * 1.25) };
  }
  // productietijd per patch (machine), alle constanten configureerbaar in de admin
  function productionMinutes(stitches, colors, cfg = {}) {
    const spm = cfg.spm || 750, eff = cfg.efficiency || 0.7, colorChangeSec = cfg.colorChangeSec || 6, trims = cfg.trimsPerColor || 2, trimSec = cfg.trimSec || 4;
    return stitches / (spm * eff) + (colors * colorChangeSec + colors * trims * trimSec) / 60;
  }

  /* ---------- garenmatch: palet -> dichtstbijzijnde garen (ΔE2000) ---------- */
  function prepareThreads(list) { return list.filter(t => t.hex).map(t => ({ ...t, lab: rgb2lab(...hex2rgb(t.hex)) })); }
  function matchThread(lab, threads, n = 3) {
    return threads.map(t => ({ t, d: dE00(lab, t.lab) })).sort((a, b) => a.d - b.d).slice(0, n);
  }
  const matchQuality = d => d < 2 ? 'zeer goed' : d < 5 ? 'goed' : d < 10 ? 'benadering' : 'geen goede match';

  const api = { TH, rgb2lab, lab2rgb, hex2rgb, deltaE00, dE00, labHistogram, kmeansLab, autoPalette, edgeStats, labelEdges, cover85, classify, hasSoftShading, estimateStitches, productionMinutes, ST, prepareThreads, matchThread, matchQuality };
  if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.PSAnalyse = api;
})(typeof self !== 'undefined' ? self : this);
