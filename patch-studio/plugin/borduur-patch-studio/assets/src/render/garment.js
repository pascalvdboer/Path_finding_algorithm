/**
 * Patch Studio — garment mockups (v2): polo chest, cap front, jacket back, backpack front.
 * Drawn as vector parts (no images), then lit like the patch: a soft height field (body volume, folds,
 * seams) + a fabric micro-texture (piqué knit, cotton twill, nylon ripstop, cordura) are shaded with the
 * same light direction. Garment coordinates are millimetres at true scale; `scaleRefMm` (config.mockups)
 * is the reference width of the garment body, so the patch is shown at its real proportion.
 */
import { shade, rgba, lum, rgbOf, makeCanvas, ctx2d, Lru, hash01, softShadow } from './util.js';

/* view: [x, y, w, h] in mm; anchor: patch centre; curve: cylinder radius for caps (mm) */
const G = {
  polo_chest: { ref: 520, view: [-345, -75, 690, 560], anchor: [122, 160], tex: 'pique', curve: 0, hex: '#1b2a4a', ks: 0.05, gl: 6 },
  cap_front: { ref: 210, view: [-165, -168, 330, 245], anchor: [0, -64], tex: 'twill', curve: 120, hex: '#222222', ks: 0.06, gl: 8 },
  jacket_back: { ref: 600, view: [-470, -95, 940, 900], anchor: [0, 250], tex: 'ripstop', curve: 0, hex: '#2d2d2d', ks: 0.22, gl: 18 },
  backpack_front: { ref: 300, view: [-215, -300, 430, 560], anchor: [0, -112], tex: 'cordura', curve: 0, hex: '#2b2f36', ks: 0.1, gl: 10 },
};

export const GARMENTS = Object.keys(G);

/* ------------------------------ path helpers ------------------------------ */

function path(c, cmds) {
  c.beginPath();
  for (const k of cmds) {
    if (k[0] === 'M') c.moveTo(k[1], k[2]);
    else if (k[0] === 'L') c.lineTo(k[1], k[2]);
    else if (k[0] === 'Q') c.quadraticCurveTo(k[1], k[2], k[3], k[4]);
    else if (k[0] === 'C') c.bezierCurveTo(k[1], k[2], k[3], k[4], k[5], k[6]);
    else if (k[0] === 'Z') c.closePath();
  }
}
const mirror = (cmds) => cmds.map((k) => k.map((v, i) => (i > 0 && i % 2 === 1 ? -v : v)));

/* A part: albedo fill + height contribution. Seams: groove + stitches. Folds: soft ridges/valleys. */
function makeKit(A, Hc, hex, s) {
  return {
    fill(cmds, f = 0) { path(A, cmds); A.fillStyle = f ? shade(hex, f) : hex; A.fill(); },
    fillHex(cmds, h) { path(A, cmds); A.fillStyle = h; A.fill(); },
    /** body volume: soft radial swell in the height map (fades out completely, no visible edge) */
    dome(cmds, cx, cy, r, k = 1) {
      const g = Hc.createRadialGradient(cx, cy, 0, cx, cy, r);
      g.addColorStop(0, `rgba(255,255,255,${0.32 * k})`); g.addColorStop(0.6, `rgba(255,255,255,${0.12 * k})`); g.addColorStop(1, 'rgba(255,255,255,0)');
      Hc.fillStyle = g; Hc.fillRect(cx - r, cy - r, 2 * r, 2 * r);
    },
    /** rounded silhouette: the fabric turns away near the outline (blurred dark rim inside the shape) */
    rim(cmds, width, k = 1) {
      const hsc = s / 2;
      Hc.save();
      path(Hc, cmds); Hc.clip();
      Hc.shadowColor = `rgba(0,0,0,${0.7 * k})`; Hc.shadowBlur = width * hsc; Hc.shadowOffsetX = 100000;
      Hc.translate(-100000 / hsc, 0);
      path(Hc, cmds); Hc.lineWidth = width * 0.6; Hc.strokeStyle = '#000'; Hc.stroke();
      Hc.restore();
    },
    /** soft fold: ridge (up = true) or valley; width in mm */
    fold(cmds, width, up, k = 1) {
      Hc.save();
      Hc.shadowColor = up ? `rgba(255,255,255,${0.32 * k})` : `rgba(0,0,0,${0.32 * k})`;
      const hsc = s / 2; // the height map is drawn at half resolution
      Hc.shadowBlur = width * hsc * 0.9; Hc.shadowOffsetX = 100000; Hc.shadowOffsetY = 0;
      Hc.translate(-100000 / hsc, 0);
      path(Hc, cmds); Hc.lineCap = 'round'; Hc.lineWidth = width * 0.3; Hc.strokeStyle = '#000'; Hc.stroke();
      Hc.restore();
    },
    seam(cmds, k = 1, stitch = true) {
      path(Hc, cmds); Hc.lineWidth = 2.6; Hc.strokeStyle = `rgba(0,0,0,${0.5 * k})`; Hc.stroke();
      path(A, cmds); A.lineWidth = 1.6; A.strokeStyle = rgba('#000000', 0.28 * k); A.stroke();
      if (stitch) {
        A.save(); A.translate(2.4, 0);
        path(A, cmds); A.setLineDash([3.2, 2.2]); A.lineWidth = 0.9; A.strokeStyle = rgba(shade(hex, lum(hex) < 80 ? 0.32 : -0.28), 0.85); A.stroke();
        A.restore(); A.setLineDash([]);
      }
    },
    /** raised edge (hem, band) in the height map */
    ridge(cmds, w, k = 1) { path(Hc, cmds); Hc.lineWidth = w; Hc.strokeStyle = `rgba(255,255,255,${0.35 * k})`; Hc.stroke(); },
  };
}

/* ------------------------------ garments ------------------------------ */

function polo(A, Hc, hex, s) {
  const K = makeKit(A, Hc, hex, s);
  const body = [['M', -96, -30], ['Q', 0, -44, 96, -30], ['L', 248, 6], ['Q', 322, 36, 372, 150], ['L', 424, 282], ['L', 326, 332],
    ['Q', 298, 300, 268, 256], ['L', 276, 760], ['L', -276, 760], ['L', -268, 256], ['Q', -298, 300, -326, 332], ['L', -424, 282],
    ['L', -372, 150], ['Q', -322, 36, -248, 6], ['Z']];
  K.fill(body);
  K.rim(body, 80, 1.3);
  // chest volume left/right, rounder sleeves
  for (const k of [1, -1]) {
    K.dome(null, k * 140, 200, 240, 0.8);
    K.dome(null, k * 330, 170, 130, 0.9);
  }
  // inside back neck + label
  K.fill([['M', -86, -30], ['Q', 0, -42, 86, -30], ['Q', 46, 6, 0, 26], ['Q', -46, 6, -86, -30], ['Z']], -0.45);
  K.fillHex([['M', -14, -30], ['L', 14, -30], ['L', 13, -14], ['L', -13, -14], ['Z']], '#d9d4c8');
  // placket + buttons
  K.fill([['M', -18, 30], ['L', 18, 30], ['L', 18, 214], ['L', -18, 214], ['Z']], -0.04);
  K.seam([['M', -18, 32], ['L', -18, 214], ['L', 18, 214], ['L', 18, 32]], 1);
  K.ridge([['M', -18, 30], ['L', -18, 214]], 3, 0.6);
  for (const y of [92, 158]) {
    const bh = lum(hex) < 128 ? '#ece8df' : '#2b2b2b';
    A.save(); A.shadowColor = 'rgba(0,0,0,0.45)'; A.shadowBlur = 2.5 * s; A.shadowOffsetY = 1.2 * s;
    A.beginPath(); A.arc(0, y, 6.2, 0, Math.PI * 2); A.fillStyle = bh; A.fill(); A.restore();
    const g = A.createRadialGradient(-2, y - 2, 0.5, 0, y, 6.5); g.addColorStop(0, 'rgba(255,255,255,0.5)'); g.addColorStop(1, 'rgba(0,0,0,0.12)');
    A.fillStyle = g; A.beginPath(); A.arc(0, y, 6.2, 0, Math.PI * 2); A.fill();
    A.fillStyle = 'rgba(0,0,0,0.45)'; A.beginPath(); A.arc(-1.8, y, 1, 0, 7); A.arc(1.8, y, 1, 0, 7); A.fill();
    Hc.beginPath(); Hc.arc(0, y, 7, 0, 7); Hc.fillStyle = 'rgba(255,255,255,0.5)'; Hc.fill();
  }
  // shoulder / sleeve seams + sleeve hem
  for (const k of [1, -1]) {
    K.seam([['M', k * 248, 6], ['Q', k * 262, 140, k * 270, 256]]);
    K.seam([['M', k * 330, 318], ['L', k * 418, 272]]);
    K.ridge([['M', k * 326, 332], ['L', k * 424, 282]], 14, 0.8);
  }
  // drape folds from the armpits, soft vertical valley under the placket
  for (const k of [1, -1]) {
    K.fold([['M', k * 262, 262], ['Q', k * 200, 320, k * 150, 430]], 34, false, 0.8);
    K.fold([['M', k * 246, 300], ['Q', k * 190, 380, k * 170, 500]], 30, true, 0.6);
    K.fold([['M', k * 300, 120], ['Q', k * 330, 200, k * 352, 270]], 26, false, 0.5);
  }
  K.fold([['M', 0, 230], ['L', 0, 520]], 40, false, 0.4);
  // collar: band behind the neck + two flaps with a raised edge
  K.fill([['M', -98, -32], ['Q', 0, -48, 98, -32], ['L', 92, -18], ['Q', 0, -30, -92, -18], ['Z']], 0.04);
  K.ridge([['M', -98, -32], ['Q', 0, -48, 98, -32]], 6, 1);
  const flap = [['M', 98, -32], ['C', 112, 8, 104, 52, 84, 98], ['L', 12, 52], ['Q', 40, 20, 78, -14], ['Z']];
  for (const f of [flap, mirror(flap)]) {
    A.save(); A.shadowColor = 'rgba(0,0,0,0.45)'; A.shadowBlur = 9 * s; A.shadowOffsetY = 4 * s; K.fill(f, 0.05); A.restore();
    path(Hc, f); Hc.fillStyle = 'rgba(255,255,255,0.5)'; Hc.fill();
    path(Hc, f); Hc.lineWidth = 5; Hc.strokeStyle = 'rgba(255,255,255,0.35)'; Hc.stroke();
    path(A, f); A.lineWidth = 1.2; A.strokeStyle = rgba('#000000', 0.25); A.stroke();
  }
  // knit rib at the collar edge
  for (const f of [flap, mirror(flap)]) {
    A.save(); path(A, f); A.clip();
    A.strokeStyle = rgba(shade(hex, -0.3), 0.25); A.lineWidth = 0.8;
    for (let i = -120; i < 120; i += 3.2) { A.beginPath(); A.moveTo(i, -40); A.lineTo(i + 40, 110); A.stroke(); }
    A.restore();
  }
  return body;
}

function cap(A, Hc, hex, s) {
  const K = makeKit(A, Hc, hex, s);
  const crown = [['M', -108, 0], ['C', -112, -92, -60, -134, 0, -134], ['C', 60, -134, 112, -92, 108, 0], ['Q', 0, 24, -108, 0], ['Z']];
  K.fill(crown);
  K.rim(crown, 60, 1);
  K.dome(null, -6, -64, 120, 1.2);
  // front panels puff slightly
  for (const k of [1, -1]) K.dome(null, k * 34, -60, 70, 0.6);
  K.seam([['M', 0, -134], ['L', 0, 10]]);
  for (const k of [1, -1]) {
    K.seam([['M', 0, -134], ['Q', k * 62, -102, k * 74, 4]]);
    K.seam([['M', k * 6, -132], ['Q', k * 70, -98, k * 80, 2]], 0.6);
    // eyelets
    A.beginPath(); A.arc(k * 64, -92, 3.4, 0, 7); A.fillStyle = shade(hex, -0.55); A.fill();
    A.lineWidth = 1.3; A.strokeStyle = shade(hex, 0.22); A.stroke();
    Hc.beginPath(); Hc.arc(k * 64, -92, 4.6, 0, 7); Hc.lineWidth = 1.6; Hc.strokeStyle = 'rgba(255,255,255,0.6)'; Hc.stroke();
  }
  // top button
  A.save(); A.shadowColor = 'rgba(0,0,0,0.4)'; A.shadowBlur = 3 * s; A.shadowOffsetY = 1 * s;
  A.beginPath(); A.ellipse(0, -133, 9, 4.6, 0, 0, 7); A.fillStyle = shade(hex, 0.06); A.fill(); A.restore();
  Hc.beginPath(); Hc.ellipse(0, -133, 9, 4.6, 0, 0, 7); Hc.fillStyle = 'rgba(255,255,255,0.7)'; Hc.fill();
  // slight crease where the front meets the brim
  K.fold([['M', -100, -6], ['Q', 0, 16, 100, -6]], 16, false, 0.8);
  return crown;
}

function capBrim(A, Hc, hex, s) {
  const K = makeKit(A, Hc, hex, s);
  const brim = [['M', -126, 4], ['Q', 0, 28, 126, 4], ['Q', 124, 34, 0, 50], ['Q', -124, 34, -126, 4], ['Z']];
  A.save(); A.shadowColor = 'rgba(0,0,0,0.5)'; A.shadowBlur = 14 * s; A.shadowOffsetY = 7 * s; K.fill(brim, -0.04); A.restore();
  path(Hc, brim);
  const g = Hc.createLinearGradient(0, 6, 0, 50); g.addColorStop(0, 'rgba(255,255,255,0.15)'); g.addColorStop(0.45, 'rgba(255,255,255,0.6)'); g.addColorStop(1, 'rgba(0,0,0,0.2)');
  Hc.fillStyle = g; Hc.fill();
  for (let i = 1; i <= 4; i++) K.seam([['M', -120 + i * 3, 8 + i * 5], ['Q', 0, 30 + i * 5, 120 - i * 3, 8 + i * 5]], 0.6);
  // sweatband shadow under the crown
  path(A, [['M', -108, 1], ['Q', 0, 25, 108, 1]]); A.lineWidth = 4; A.strokeStyle = 'rgba(0,0,0,0.35)'; A.stroke();
  return brim;
}

function jacket(A, Hc, hex, s) {
  const K = makeKit(A, Hc, hex, s);
  const body = [['M', -92, -16], ['Q', 0, -6, 92, -16], ['L', 250, 38], ['Q', 362, 80, 422, 330], ['L', 472, 820], ['L', 380, 832], ['L', 332, 420],
    ['L', 308, 292], ['L', 312, 862], ['L', -312, 862], ['L', -308, 292], ['L', -332, 420], ['L', -380, 832], ['L', -472, 820], ['L', -422, 330],
    ['Q', -362, 80, -250, 38], ['Z']];
  K.fill(body);
  K.rim(body, 120, 1.3);
  K.dome(null, 0, 330, 420, 0.8);
  for (const k of [1, -1]) { K.dome(null, k * 390, 420, 140, 0.9); K.dome(null, k * 410, 650, 120, 0.8); }
  // ribbed bomber collar (stands up at the back of the neck)
  const collar = [['M', -112, -12], ['Q', 0, 2, 112, -12], ['L', 118, -74], ['Q', 0, -58, -118, -74], ['Z']];
  A.save(); A.shadowColor = 'rgba(0,0,0,0.45)'; A.shadowBlur = 10 * s; A.shadowOffsetY = 4 * s; K.fill(collar, -0.16); A.restore();
  path(Hc, collar); Hc.fillStyle = 'rgba(255,255,255,0.45)'; Hc.fill();
  A.save(); path(A, collar); A.clip(); A.strokeStyle = rgba('#000000', 0.22); A.lineWidth = 1.4;
  for (let x = -120; x <= 120; x += 4.5) { A.beginPath(); A.moveTo(x, -78); A.lineTo(x * 0.97, 4); A.stroke(); }
  A.restore();
  K.seam([['M', -96, -26], ['Q', 0, -15, 96, -26]]);
  // yoke + raglan seams + hem band + cuffs
  K.seam([['M', -262, 150], ['Q', 0, 126, 262, 150]]);
  for (const k of [1, -1]) {
    K.seam([['M', k * 92, -14], ['Q', k * 230, 140, k * 308, 292]]);
    K.fill([['M', k * 382, 790], ['L', k * 470, 780], ['L', k * 472, 820], ['L', k * 380, 832], ['Z']], -0.12);
  }
  K.fill([['M', -312, 806], ['L', 312, 806], ['L', 312, 862], ['L', -312, 862], ['Z']], -0.1);
  K.ridge([['M', -312, 834], ['L', 312, 834]], 50, 0.5);
  A.save(); A.beginPath(); A.rect(-312, 806, 624, 56); A.clip();
  A.strokeStyle = rgba('#000000', 0.18); A.lineWidth = 1;
  for (let x = -312; x < 312; x += 4) { A.beginPath(); A.moveTo(x, 806); A.lineTo(x, 862); A.stroke(); }
  A.restore();
  K.seam([['M', -312, 806], ['L', 312, 806]]);
  // nylon drape: compression wrinkles at the waist, soft verticals
  for (const [y, w, k] of [[640, 80, 0.5], [700, 60, 0.4], [752, 50, 0.35]]) {
    K.fold([['M', -250, y], ['Q', -60, y - 18, 60, y + 6], ['Q', 180, y + 18, 250, y - 8]], w * 0.5, true, k);
    K.fold([['M', -250, y + 22], ['Q', -40, y + 4, 80, y + 26], ['Q', 190, y + 40, 250, y + 14]], w * 0.4, false, k);
  }
  for (const k of [1, -1]) {
    K.fold([['M', k * 180, 300], ['Q', k * 210, 460, k * 190, 620]], 40, false, 0.5);
    K.fold([['M', k * 380, 420], ['Q', k * 400, 600, k * 420, 780]], 30, false, 0.7);
  }
  return body;
}

function backpack(A, Hc, hex, s) {
  const K = makeKit(A, Hc, hex, s);
  const rr = (x0, y0, x1, y1, rt, rb) => [['M', x0 + rt, y0], ['L', x1 - rt, y0], ['Q', x1, y0, x1, y0 + rt], ['L', x1, y1 - rb], ['Q', x1, y1, x1 - rb, y1],
    ['L', x0 + rb, y1], ['Q', x0, y1, x0, y1 - rb], ['L', x0, y0 + rt], ['Q', x0, y0, x0 + rt, y0], ['Z']];
  // top handle (behind)
  const handle = [['M', -38, -214], ['C', -40, -268, 40, -268, 38, -214], ['L', 26, -214], ['C', 28, -252, -28, -252, -26, -214], ['Z']];
  A.save(); A.shadowColor = 'rgba(0,0,0,0.35)'; A.shadowBlur = 6 * s; A.shadowOffsetY = 3 * s; K.fill(handle, -0.08); A.restore();
  path(Hc, handle); Hc.fillStyle = 'rgba(255,255,255,0.35)'; Hc.fill();
  const body = rr(-155, -222, 155, 236, 74, 44);
  K.fill(body);
  K.rim(body, 70, 1);
  K.dome(null, 0, -20, 230, 1.2);
  K.seam(rr(-147, -214, 147, 228, 68, 40), 0.8);
  // bottom panel
  K.fill([['M', -155, 196], ['L', 155, 196], ['L', 155, 192 + 2], ['Q', 155, 236, 111, 236], ['L', -111, 236], ['Q', -155, 236, -155, 194], ['Z']], -0.15);
  K.seam([['M', -153, 196], ['L', 153, 196]]);
  // front pocket with zipper
  const pocket = rr(-122, -20, 122, 186, 46, 30);
  A.save(); A.shadowColor = 'rgba(0,0,0,0.4)'; A.shadowBlur = 10 * s; A.shadowOffsetY = 4 * s; K.fill(pocket, 0.03); A.restore();
  K.rim(pocket, 30, 0.8);
  K.dome(null, 0, 80, 140, 1);
  K.seam(rr(-116, -14, 116, 180, 40, 26), 0.8);
  const zip = [['M', -112, -6], ['Q', 0, -26, 112, -6]];
  path(A, zip); A.lineWidth = 7; A.strokeStyle = shade(hex, -0.45); A.stroke();
  path(A, zip); A.setLineDash([1.6, 1.4]); A.lineWidth = 4.6; A.strokeStyle = rgba('#c9ccd2', 0.55); A.stroke(); A.setLineDash([]);
  path(Hc, zip); Hc.lineWidth = 6; Hc.strokeStyle = 'rgba(0,0,0,0.45)'; Hc.stroke();
  // zipper pull
  A.save(); A.shadowColor = 'rgba(0,0,0,0.45)'; A.shadowBlur = 4 * s; A.shadowOffsetY = 2 * s;
  path(A, [['M', 86, -12], ['L', 96, -12], ['L', 99, 26], ['Q', 91, 34, 83, 26], ['Z']]); A.fillStyle = '#9aa0a8'; A.fill(); A.restore();
  const zg = A.createLinearGradient(83, 0, 99, 0); zg.addColorStop(0, 'rgba(255,255,255,0.55)'); zg.addColorStop(0.5, 'rgba(255,255,255,0)'); zg.addColorStop(1, 'rgba(0,0,0,0.25)');
  A.fillStyle = zg; A.fill();
  // side compression straps + buckles
  for (const k of [1, -1]) {
    const strap = [['M', k * 150, 40], ['L', k * 96, 46], ['L', k * 96, 64], ['L', k * 152, 60], ['Z']];
    A.save(); A.shadowColor = 'rgba(0,0,0,0.4)'; A.shadowBlur = 4 * s; A.shadowOffsetY = 2 * s; K.fillHex(strap, shade(hex, -0.3)); A.restore();
    path(Hc, strap); Hc.fillStyle = 'rgba(255,255,255,0.5)'; Hc.fill();
    path(A, [['M', k * 104, 40], ['L', k * 84, 40], ['L', k * 84, 70], ['L', k * 104, 70], ['Z']]); A.fillStyle = '#16181c'; A.fill();
    path(Hc, [['M', k * 104, 40], ['L', k * 84, 40], ['L', k * 84, 70], ['L', k * 104, 70], ['Z']]); Hc.fillStyle = 'rgba(255,255,255,0.7)'; Hc.fill();
  }
  // soft creases of the loaded bag
  K.fold([['M', -120, -150], ['Q', -60, -120, -20, -160]], 30, false, 0.4);
  K.fold([['M', 140, -60], ['Q', 128, 0, 140, 60]], 26, false, 0.5);
  K.fold([['M', -140, -60], ['Q', -128, 0, -140, 60]], 26, false, 0.5);
  return body;
}

/* ------------------------------ fabric micro texture ------------------------------ */

const texCache = new Lru(8);
/** Seamless normal tile (nx, ny, cavity) for a fabric at `ppm` px per mm. */
function fabricTile(kind, ppm) {
  const q = Math.max(0.25, Math.round(ppm * 8) / 8);
  const key = kind + q;
  let t = texCache.get(key);
  if (t) return t;
  // periods in mm
  const per = { pique: 1.4, twill: 0.9, ripstop: 0.5, cordura: 1.1 }[kind] || 1;
  const cellPx = Math.max(2, per * q);
  const n = Math.max(2, Math.round(48 / cellPx));
  const T = Math.max(8, Math.round(n * cellPx)), cell = T / n;
  const h = new Float32Array(T * T);
  for (let y = 0; y < T; y++) for (let x = 0; x < T; x++) {
    const u = x / cell, v = y / cell, fu = u - Math.floor(u), fv = v - Math.floor(v);
    let val;
    if (kind === 'pique') {
      // honeycomb knit cells (offset rows)
      const row = Math.floor(v), fu2 = (u + (row & 1) * 0.5) % 1;
      const dx = fu2 - 0.5, dy = fv - 0.5;
      val = Math.cos(Math.min(1, Math.hypot(dx, dy) * 2.1) * Math.PI) * 0.5 + 0.5;
    } else if (kind === 'twill') {
      val = Math.sin(((x + y) / cell) * Math.PI * 2) * 0.5 + 0.5;
    } else if (kind === 'ripstop') {
      val = ((Math.floor(u) + Math.floor(v)) & 1 ? Math.sin(fu * Math.PI) : Math.sin(fv * Math.PI)) * 0.6;
      if (Math.floor(u) % 8 === 0 || Math.floor(v) % 8 === 0) val += 0.4; // reinforcement grid
    } else {
      // cordura: coarse basket weave with slubs
      const bu = Math.floor(u / 2), bv = Math.floor(v / 2);
      val = ((bu + bv) & 1 ? Math.sin(fu * Math.PI) : Math.sin(fv * Math.PI)) * 0.8 + hash01(Math.floor(u) * 31 + Math.floor(v) * 7) * 0.3;
    }
    h[y * T + x] = val;
  }
  const amp = Math.min(1.2, 0.25 * cellPx) * (kind === 'ripstop' ? 0.6 : 1);
  const nx = new Float32Array(T * T), ny = new Float32Array(T * T), cav = new Float32Array(T * T);
  for (let y = 0; y < T; y++) for (let x = 0; x < T; x++) {
    const i = y * T + x;
    nx[i] = -(h[y * T + (x + 1) % T] - h[y * T + (x + T - 1) % T]) * amp;
    ny[i] = -(h[((y + 1) % T) * T + x] - h[((y + T - 1) % T) * T + x]) * amp;
    cav[i] = 0.9 + 0.1 * h[i];
  }
  t = { T, nx, ny, cav };
  return texCache.set(key, t);
}

/* ------------------------------ lit scene ------------------------------ */

const sceneCache = new Lru(4);

/**
 * Draw the garment scene and return where/how to place the patch.
 * @param {CanvasRenderingContext2D} c
 * @param {number} W canvas width (px)
 * @param {number} H canvas height (px)
 * @param {string} id   polo_chest | cap_front | jacket_back | backpack_front
 * @param {object} config
 * @param {{garmentHex?:string, transparent?:boolean, zoom?:number, light?:object, quality?:string}} o
 * @returns {{pxPerMm:number, cx:number, cy:number, curveMm:number, garmentHex:string, shadeCanvas:object}}
 */
export function drawGarment(c, W, H, id, config, o = {}) {
  const gid = G[id] ? id : 'polo_chest';
  const def = G[gid];
  const mk = ((config && config.mockups) || {})[gid] || {};
  const hex = o.garmentHex || mk.garmentHex || def.hex;
  const refMm = Number(mk.scaleRefMm) || def.ref;
  const k = refMm / def.ref; // garment drawn at real scale for this reference width
  const [vx, vy, vw, vh] = def.view;
  // frame 'patch' (default in the studio): zoom in until the patch is ±35 % of the view, the garment may run out of
  // the picture; frame 'garment' shows the whole piece (critique-1 #10)
  const fit1 = Math.min(W / (vw * k), H / (vh * k));
  const auto = o.frame === 'patch' && o.patchMm > 0 ? Math.max(1, (0.35 * Math.min(W, H)) / (o.patchMm * fit1)) : 1;
  const zoom = (o.zoom || 1) * auto;
  const pxPerMm = fit1 * zoom;
  const off = Array.isArray(mk.patchCenterMm) ? mk.patchCenterMm : [0, 0];
  const ax = def.anchor[0] * k + (Number(off[0]) || 0), ay = def.anchor[1] * k + (Number(off[1]) || 0);
  const vcx = (vx + vw / 2) * k, vcy = (vy + vh / 2) * k;
  const fx = zoom > 1 ? ax : vcx, fy = zoom > 1 ? ay : vcy;
  const tx = W / 2 - fx * pxPerMm, ty = H / 2 - fy * pxPerMm;
  const light = o.light || { L: [-0.45, -0.53, 0.72], H: [-0.24, -0.28, 0.93], key: 'd' };
  const key = [gid, hex, W, H, pxPerMm.toFixed(4), tx.toFixed(1), ty.toFixed(1), light.key, o.transparent ? 1 : 0].join('#');
  let sc = sceneCache.get(key);
  if (!sc) { sc = buildScene(W, H, gid, def, hex, pxPerMm * k, tx, ty, pxPerMm, light, o.transparent); sceneCache.set(key, sc); }
  c.drawImage(sc.canvas, 0, 0);
  return {
    pxPerMm, cx: tx + ax * pxPerMm, cy: ty + ay * pxPerMm, curveMm: def.curve * k, garmentHex: hex, shadeCanvas: sc.shade, after: sc.after,
  };
}

function buildScene(W, H, gid, def, hex, s, tx, ty, ppm, light, transparent) {
  const out = makeCanvas(W, H), oc = ctx2d(out);
  if (!transparent) {
    const bg = oc.createLinearGradient(0, 0, 0, H);
    bg.addColorStop(0, '#f5f6f8'); bg.addColorStop(1, '#e2e6ec');
    oc.fillStyle = bg; oc.fillRect(0, 0, W, H);
    const v = oc.createRadialGradient(W * 0.45, H * 0.3, Math.min(W, H) * 0.1, W / 2, H / 2, Math.hypot(W, H) * 0.6);
    v.addColorStop(0, 'rgba(255,255,255,0.55)'); v.addColorStop(1, 'rgba(20,30,50,0.08)');
    oc.fillStyle = v; oc.fillRect(0, 0, W, H);
  }
  // albedo at full res, height at half res
  const alb = makeCanvas(W, H), A = ctx2d(alb, { willReadFrequently: true });
  const H2w = Math.ceil(W / 2), H2h = Math.ceil(H / 2);
  const hcv = makeCanvas(H2w, H2h), Hc = ctx2d(hcv, { willReadFrequently: true });
  Hc.fillStyle = 'rgb(128,128,128)'; Hc.fillRect(0, 0, H2w, H2h);
  A.setTransform(s, 0, 0, s, tx, ty);
  Hc.setTransform(s / 2, 0, 0, s / 2, tx / 2, ty / 2);
  const draw = gid === 'cap_front' ? cap : gid === 'jacket_back' ? jacket : gid === 'backpack_front' ? backpack : polo;
  draw(A, Hc, hex, s);
  // the cap brim is a separate layer drawn after the patch (the patch sits on the crown above it)
  let after = null;
  if (gid === 'cap_front') {
    const bcv = makeCanvas(W, H), B = ctx2d(bcv, { willReadFrequently: true });
    B.setTransform(s, 0, 0, s, tx, ty);
    const bh = makeCanvas(H2w, H2h), BH = ctx2d(bh, { willReadFrequently: true });
    BH.fillStyle = 'rgb(128,128,128)'; BH.fillRect(0, 0, H2w, H2h);
    BH.setTransform(s / 2, 0, 0, s / 2, tx / 2, ty / 2);
    capBrim(B, BH, hex, s);
    after = makeCanvas(W, H);
    litInto(ctx2d(after), bcv, bh, W, H, def, ppm, light);
  }
  const lit = makeCanvas(W, H), lc = ctx2d(lit);
  const shadeMap = litInto(lc, alb, hcv, W, H, def, ppm, light);
  // garment drop shadow on the backdrop
  if (!transparent) {
    const [L0, L1] = light.L;
    softShadow(oc, alb, 0, 0, -L0 * ppm * 14, -L1 * ppm * 14 + ppm * 6, ppm * 22, 0.28, '20,28,45');
  }
  oc.drawImage(lit, 0, 0);
  if (after) {
    if (!transparent) softShadow(oc, after, 0, 0, 0, ppm * 4, ppm * 8, 0.35);
  }
  return { canvas: out, shade: shadeMap, after };
}

/**
 * Light the garment: fold normals from the half-res height map + fabric micro-texture.
 * Writes the lit garment into `dst` and returns a soft-light shading canvas (grey 128 = neutral) used to
 * let folds and light continue over the patch.
 */
function litInto(dst, albCv, hCv, W, H, def, ppm, light) {
  const A = ctx2d(albCv).getImageData(0, 0, W, H);
  const hw = hCv.width, hh = hCv.height;
  const Hd = ctx2d(hCv).getImageData(0, 0, hw, hh).data;
  let hs = new Float32Array(hw * hh);
  for (let i = 0; i < hw * hh; i++) hs[i] = Hd[i * 4];
  hs = boxBlur(hs, hw, hh, 2, 2); // float smoothing: no 8-bit banding in the slopes
  // low-pass copy (no seams / eyelets) for the shading that continues over the patch
  const lo = boxBlur(Float32Array.from(hs), hw, hh, Math.max(3, Math.round(ppm * 4)), 2);
  const tile = fabricTile(def.tex, ppm), T = tile.T;
  const [lx, ly, lz] = light.L, [hx, hy, hz] = light.H;
  const amb = 0.36, kd = (1 - amb) / lz;
  // fold relief: grey units → slope; scaled with the garment size so folds read the same at every zoom
  const bump = 0.04 * Math.min(4, Math.max(0.6, ppm * 2));
  // studio key light: a broad falloff across the scene along the light direction
  const kx = light.L[0], ky = light.L[1], kl = Math.hypot(kx, ky) || 1, diag = Math.hypot(W, H);
  const sh = new Uint8ClampedArray(hw * hh * 4);
  const D = A.data;
  const P = def.gl, ks = def.ks * 255;
  for (let y = 0; y < H; y++) {
    const y2 = Math.min(hh - 1, y >> 1), r2 = y2 * hw, up = Math.max(0, y2 - 2) * hw, dn = Math.min(hh - 1, y2 + 2) * hw;
    const tr = (y % T) * T;
    for (let x = 0; x < W; x++) {
      const p = (y * W + x) * 4;
      if (!D[p + 3]) continue;
      const x2 = Math.min(hw - 1, x >> 1);
      // ±2 cell stencil: smooth slopes without 8-bit banding
      const gx = (hs[r2 + Math.min(hw - 1, x2 + 2)] - hs[r2 + Math.max(0, x2 - 2)]) * bump * 0.5;
      const gy = (hs[dn + x2] - hs[up + x2]) * bump * 0.5;
      const occ = 0.78 + 0.22 * Math.min(1, hs[r2 + x2] / 150);
      const ti = tr + (x % T);
      let nx = -gx + tile.nx[ti] * 0.35, ny = -gy + tile.ny[ti] * 0.35, nz = 1;
      const l = 1 / Math.sqrt(nx * nx + ny * ny + 1); nx *= l; ny *= l; nz = l;
      const ndl = Math.max(0, nx * lx + ny * ly + nz * lz);
      const key = 1 + 0.16 * (((x - W / 2) * kx + (y - H / 2) * ky) / kl) / (diag * 0.5);
      const dif = (amb + kd * ndl) * tile.cav[ti] * occ * key;
      const ndh = Math.max(0, nx * hx + ny * hy + nz * hz);
      const sp = ks * Math.pow(ndh, P);
      D[p] = D[p] * dif + sp; D[p + 1] = D[p + 1] * dif + sp; D[p + 2] = D[p + 2] * dif + sp;
      if (!(x & 1) && !(y & 1)) {
        // low-frequency shading only (no texture, no seams) for the patch overlay
        const lx2 = (lo[r2 + Math.min(hw - 1, x2 + 2)] - lo[r2 + Math.max(0, x2 - 2)]) * bump * 0.5;
        const ly2 = (lo[dn + x2] - lo[up + x2]) * bump * 0.5;
        const fl = 1 / Math.sqrt(lx2 * lx2 + ly2 * ly2 + 1);
        const d2 = (amb + kd * Math.max(0, (-lx2 * lx - ly2 * ly + lz) * fl)) * key;
        const q = (r2 + x2) * 4, v = Math.max(0, Math.min(255, 128 * d2));
        sh[q] = sh[q + 1] = sh[q + 2] = v; sh[q + 3] = 255;
      }
    }
  }
  const tmp = makeCanvas(W, H);
  ctx2d(tmp).putImageData(A, 0, 0);
  dst.drawImage(tmp, 0, 0);
  const shc = makeCanvas(hw, hh), shx = ctx2d(shc);
  const sid = shx.createImageData(hw, hh);
  sid.data.set(sh);
  shx.putImageData(sid, 0, 0);
  return shc;
}

/** Separable box blur (radius r, `passes` times) of a float grid. */
function boxBlur(src, w, h, r, passes) {
  let a = src, b = new Float32Array(w * h);
  const n = 2 * r + 1;
  for (let p = 0; p < passes; p++) {
    for (let y = 0; y < h; y++) {
      const row = y * w;
      let acc = 0;
      for (let x = -r; x <= r; x++) acc += a[row + Math.min(w - 1, Math.max(0, x))];
      for (let x = 0; x < w; x++) {
        b[row + x] = acc / n;
        acc += a[row + Math.min(w - 1, x + r + 1)] - a[row + Math.max(0, x - r)];
      }
    }
    for (let x = 0; x < w; x++) {
      let acc = 0;
      for (let y = -r; y <= r; y++) acc += b[Math.min(h - 1, Math.max(0, y)) * w + x];
      for (let y = 0; y < h; y++) {
        a[y * w + x] = acc / n;
        acc += b[Math.min(h - 1, y + r + 1) * w + x] - b[Math.max(0, y - r) * w + x];
      }
    }
  }
  return a;
}

/**
 * Composite a rendered patch (canvas) onto the garment: contact shadow along the light, the garment's
 * folds and light continuing over the patch, and for caps a cylindrical warp (strips) so the patch follows
 * the curved front.
 */
export function placePatch(c, patch, place, wPx, hPx) {
  const x0 = place.cx - wPx / 2, y0 = place.cy - hPx / 2;
  const L = (place.light && place.light.L) || [-0.45, -0.53, 0.72];
  const ppm = place.pxPerMm;
  // patch at its final size, with the garment shading laid over it (soft-light, alpha kept)
  const pw = Math.max(2, Math.ceil(wPx)), ph = Math.max(2, Math.ceil(hPx));
  const pc = makeCanvas(pw, ph), pcx = ctx2d(pc);
  pcx.imageSmoothingEnabled = true;
  try { pcx.imageSmoothingQuality = 'high'; } catch (e) { /* older engines */ }
  pcx.drawImage(patch, 0, 0, pw, ph);
  if (place.shadeCanvas) {
    pcx.globalCompositeOperation = 'soft-light';
    pcx.globalAlpha = 0.6;
    pcx.drawImage(place.shadeCanvas, x0 / 2, y0 / 2, wPx / 2, hPx / 2, 0, 0, pw, ph);
    pcx.globalAlpha = 1;
    pcx.globalCompositeOperation = 'destination-in';
    pcx.drawImage(patch, 0, 0, pw, ph);
    pcx.globalCompositeOperation = 'source-over';
  }
  const thick = place.look === 'pvc' || place.look === 'chenille' ? 2.4 : place.look === 'woven' || place.look === 'sublimated' ? 0.8 : 1.5;
  const k = 1 / Math.max(0.3, L[2]);
  const R = place.curveMm * ppm;
  if (R > 0 && wPx < R * 2.6) {
    const strips = Math.max(8, Math.min(200, Math.round(wPx / 2.5)));
    const sw = pw / strips;
    // warp into a temp canvas first so the shadow is computed once
    const ext = 2 * R * Math.sin(wPx / R / 2);
    const wc = makeCanvas(Math.ceil(ext) + 4, ph + 4), wcx = ctx2d(wc);
    for (let i = 0; i < strips; i++) {
      const a0 = (i / strips - 0.5) * (wPx / R), a1 = ((i + 1) / strips - 0.5) * (wPx / R);
      const X0 = ext / 2 + 2 + R * Math.sin(a0), X1 = ext / 2 + 2 + R * Math.sin(a1);
      const shf = 1 - 0.05 * Math.abs(a0 + a1);
      wcx.drawImage(pc, i * sw, 0, sw, ph, X0, 2 + ph * (1 - shf) / 2, Math.max(0.5, X1 - X0 + 0.4), ph * shf);
      // the sides turn away from the light a little
      const turn = Math.sin((a0 + a1) / 2) * L[0];
      if (Math.abs(turn) > 0.01) { wcx.fillStyle = turn > 0 ? `rgba(255,255,255,${Math.min(0.12, turn * 0.25)})` : `rgba(0,0,0,${Math.min(0.25, -turn * 0.4)})`; wcx.globalCompositeOperation = 'source-atop'; wcx.fillRect(X0, 0, X1 - X0 + 0.4, ph + 4); wcx.globalCompositeOperation = 'source-over'; }
    }
    const dx = place.cx - ext / 2 - 2, dy = y0 - 2;
    softShadow(c, wc, dx, dy, -L[0] * k * thick * ppm * 0.6, -L[1] * k * thick * ppm * 0.6 + ppm * 0.3, Math.max(2, ppm * 1.6), 0.5);
    c.drawImage(wc, dx, dy);
    if (place.after) c.drawImage(place.after, 0, 0);
    return { x: place.cx - ext / 2, y: y0, w: ext, h: hPx };
  }
  softShadow(c, pc, x0, y0, -L[0] * k * thick * ppm * 0.6, -L[1] * k * thick * ppm * 0.6 + ppm * 0.3, Math.max(2, ppm * 1.8), 0.5);
  softShadow(c, pc, x0, y0, -L[0] * k * thick * ppm * 0.15, -L[1] * k * thick * ppm * 0.15, Math.max(1.2, ppm * 0.4), 0.35);
  c.drawImage(pc, x0, y0);
  if (place.after) c.drawImage(place.after, 0, 0);
  return { x: x0, y: y0, w: wPx, h: hPx };
}
