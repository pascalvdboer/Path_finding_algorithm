/**
 * Patch Studio — gedeelde kleurwiskunde (eigenaar: architect, BEVROREN contract; wijzigen via SPEC.md).
 * Port van docs/research/07-beeldanalyse-ref.js (CIEDE2000 gevalideerd tegen Sharma et al. 2005).
 * Puur, zonder DOM: werkt in main thread, Web Worker en Node.
 */

const LIN = new Float32Array(256);
for (let i = 0; i < 256; i++) {
  const c = i / 255;
  LIN[i] = c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}
const fLab = (t) => (t > 216 / 24389 ? Math.cbrt(t) : ((24389 / 27) * t + 16) / 116);

/**
 * sRGB (0-255 gehele getallen) → CIELAB D65.
 * @param {number} r @param {number} g @param {number} b
 * @returns {[number, number, number]} [L, a, b]
 */
export function rgbToLab(r, g, b) {
  const R = LIN[r | 0], G = LIN[g | 0], B = LIN[b | 0];
  const X = (0.4124564 * R + 0.3575761 * G + 0.1804375 * B) / 0.95047;
  const Y = 0.2126729 * R + 0.7151522 * G + 0.072175 * B;
  const Z = (0.0193339 * R + 0.119192 * G + 0.9503041 * B) / 1.08883;
  const fx = fLab(X), fy = fLab(Y), fz = fLab(Z);
  return [116 * fy - 16, 500 * (fx - fy), 200 * (fy - fz)];
}

/**
 * CIELAB D65 → sRGB (0-255, geklemd).
 * @returns {[number, number, number]}
 */
export function labToRgb(L, a, b) {
  const fy = (L + 16) / 116, fx = fy + a / 500, fz = fy - b / 200;
  const inv = (t) => (t ** 3 > 216 / 24389 ? t ** 3 : (116 * t - 16) / (24389 / 27));
  const X = inv(fx) * 0.95047, Y = inv(fy), Z = inv(fz) * 1.08883;
  const lin = [
    3.2404542 * X - 1.5371385 * Y - 0.4985314 * Z,
    -0.969266 * X + 1.8760108 * Y + 0.041556 * Z,
    0.0556434 * X - 0.2040259 * Y + 1.0572252 * Z,
  ];
  return /** @type {[number,number,number]} */ (lin.map((c) => {
    c = Math.max(0, Math.min(1, c));
    return Math.round(255 * (c <= 0.0031308 ? 12.92 * c : 1.055 * Math.pow(c, 1 / 2.4) - 0.055));
  }));
}

/** '#RRGGBB' of 'RRGGBB' of '#RGB' → [r,g,b] */
export function hexToRgb(hex) {
  let h = String(hex).trim().replace('#', '');
  if (h.length === 3) h = h.split('').map((c) => c + c).join('');
  return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
}

/** [r,g,b] → '#rrggbb' (kleine letters) */
export function rgbToHex(r, g, b) {
  return '#' + [r, g, b].map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('');
}

/** '#rrggbb' → [L,a,b] */
export function hexToLab(hex) {
  const [r, g, b] = hexToRgb(hex);
  return rgbToLab(r, g, b);
}

/** [L,a,b] → '#rrggbb' */
export function labToHex(lab) {
  const [r, g, b] = labToRgb(lab[0], lab[1], lab[2]);
  return rgbToHex(r, g, b);
}

/** Chroma C* = √(a²+b²) */
export function chroma(lab) {
  return Math.hypot(lab[1], lab[2]);
}

/** ΔE76 (kwadratisch niet gewortelde variant: deltaE76sq) */
export function deltaE76(p, q) {
  return Math.hypot(p[0] - q[0], p[1] - q[1], p[2] - q[2]);
}
export function deltaE76sq(p, q) {
  const a = p[0] - q[0], b = p[1] - q[1], c = p[2] - q[2];
  return a * a + b * b + c * c;
}

/**
 * CIEDE2000 (Sharma, Wu & Dalal 2005). kL=kC=kH=1 standaard; kL=2 voor foto's van echte stof (licht/schaduw).
 * @param {number[]} p [L,a,b] @param {number[]} q [L,a,b] @param {number} [kL=1]
 */
export function deltaE2000(p, q, kL = 1) {
  const [L1, a1, b1] = p, [L2, a2, b2] = q;
  const d2r = Math.PI / 180, r2d = 180 / Math.PI;
  const C1 = Math.hypot(a1, b1), C2 = Math.hypot(a2, b2), Cb = (C1 + C2) / 2;
  const G = 0.5 * (1 - Math.sqrt(Cb ** 7 / (Cb ** 7 + 25 ** 7)));
  const a1p = (1 + G) * a1, a2p = (1 + G) * a2;
  const C1p = Math.hypot(a1p, b1), C2p = Math.hypot(a2p, b2);
  const h = (b, a) => {
    if (b === 0 && a === 0) return 0;
    const v = Math.atan2(b, a) * r2d;
    return v < 0 ? v + 360 : v;
  };
  const h1p = h(b1, a1p), h2p = h(b2, a2p);
  const dLp = L2 - L1, dCp = C2p - C1p;
  let dhp = 0;
  if (C1p * C2p !== 0) {
    dhp = h2p - h1p;
    if (dhp > 180) dhp -= 360;
    else if (dhp < -180) dhp += 360;
  }
  const dHp = 2 * Math.sqrt(C1p * C2p) * Math.sin((dhp * d2r) / 2);
  const Lbp = (L1 + L2) / 2, Cbp = (C1p + C2p) / 2;
  let hbp = h1p + h2p;
  if (C1p * C2p !== 0) {
    if (Math.abs(h1p - h2p) > 180) hbp = h1p + h2p < 360 ? hbp + 360 : hbp - 360;
    hbp /= 2;
  }
  const T = 1 - 0.17 * Math.cos((hbp - 30) * d2r) + 0.24 * Math.cos(2 * hbp * d2r)
    + 0.32 * Math.cos((3 * hbp + 6) * d2r) - 0.2 * Math.cos((4 * hbp - 63) * d2r);
  const dTheta = 30 * Math.exp(-(((hbp - 275) / 25) ** 2));
  const Rc = 2 * Math.sqrt(Cbp ** 7 / (Cbp ** 7 + 25 ** 7));
  const Sl = 1 + (0.015 * (Lbp - 50) ** 2) / Math.sqrt(20 + (Lbp - 50) ** 2);
  const Sc = 1 + 0.045 * Cbp, Sh = 1 + 0.015 * Cbp * T;
  const Rt = -Math.sin(2 * dTheta * d2r) * Rc;
  return Math.sqrt((dLp / (kL * Sl)) ** 2 + (dCp / Sc) ** 2 + (dHp / Sh) ** 2 + Rt * (dCp / Sc) * (dHp / Sh));
}

/** Relatieve luminantie (WCAG) van '#rrggbb' */
export function relativeLuminance(hex) {
  const [r, g, b] = hexToRgb(hex);
  return 0.2126 * LIN[r] + 0.7152 * LIN[g] + 0.0722 * LIN[b];
}

/** WCAG-contrastverhouding (1..21) tussen twee hexkleuren */
export function contrastRatio(hexA, hexB) {
  const A = relativeLuminance(hexA), B = relativeLuminance(hexB);
  const [hi, lo] = A > B ? [A, B] : [B, A];
  return (hi + 0.05) / (lo + 0.05);
}

/** "wit" volgens 07 §4.2: L*>92 en C*<6 */
export function isWhiteLab(lab, L = 92, C = 6) {
  return lab[0] > L && chroma(lab) < C;
}
/** "zwart" (garen-speciaal) volgens 07 §5.2: L*<15 en C*<8 */
export function isBlackLab(lab, L = 15, C = 8) {
  return lab[0] < L && chroma(lab) < C;
}
