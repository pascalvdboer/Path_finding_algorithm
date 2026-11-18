/**
 * Cross-sell — lichte, eigen canvas-mockups (SPEC-EXTRA §2.3). Geen externe afbeeldingen: vormen met canvas-paden,
 * het echte patchbeeld (preview-PNG van het ontwerp) erop op ware schaal. Licht vast van linksboven.
 *
 * drawMockup(ctx, kind, opts, W, H)
 *   kind: cap_trucker | cap_snapback | cap_dad | beanie_cuff | hoodie_chest | hoodie_back | leather_tag | bagtag | frame_box | pvc_keychain
 *   opts: { patchImg (HTMLImageElement|Canvas|null), colorHex, placement, mono, plateLines:[l1,l2], engraveHex, patchSizeCm,
 *           scaleRefMm, plateHex, plateTextHex, fallbackText }
 */
import { hexToRgb, rgbToHex } from '../shared/color-math.js';

const HEX = /^#?[0-9a-f]{6}$/i;

/** Kleur lichter (amt > 0) of donkerder (amt < 0), amt in −1..1. */
export function shade(hex, amt) {
  const [r, g, b] = hexToRgb(HEX.test(String(hex || '')) ? hex : '#777777');
  const f = (c) => (amt >= 0 ? c + (255 - c) * amt : c * (1 + amt));
  return rgbToHex(f(r), f(g), f(b));
}
const lum = (hex) => {
  const [r, g, b] = hexToRgb(HEX.test(String(hex || '')) ? hex : '#777777');
  return (0.299 * r + 0.587 * g + 0.114 * b) / 255;
};

/* ---------- textures (één keer per kleur, klein, herhaald) ---------- */
const texCache = new Map();
function makeCanvas(w, h) {
  if (typeof OffscreenCanvas !== 'undefined') return new OffscreenCanvas(w, h);
  const c = document.createElement('canvas');
  c.width = w;
  c.height = h;
  return c;
}
/** Deterministische ruis (geen Math.random: elke render identiek). */
function rng(seed) {
  let s = seed >>> 0 || 1;
  return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296);
}
function texture(ctx, kind, hex) {
  const key = kind + hex;
  if (texCache.has(key)) return ctx.createPattern(texCache.get(key), 'repeat');
  const S = kind === 'rib' ? 12 : 48;
  const c = makeCanvas(S, S);
  const g = c.getContext('2d');
  g.fillStyle = hex;
  g.fillRect(0, 0, S, S);
  const rnd = rng(S * 97 + hex.length);
  if (kind === 'twill' || kind === 'leather' || kind === 'fleece') {
    const n = kind === 'leather' ? 260 : 180;
    for (let i = 0; i < n; i++) {
      const a = kind === 'leather' ? 0.07 : 0.05;
      g.fillStyle = rnd() > 0.5 ? `rgba(255,255,255,${a})` : `rgba(0,0,0,${a * 1.4})`;
      const x = rnd() * S, y = rnd() * S;
      if (kind === 'twill') g.fillRect(x, y, 3, 1);
      else g.fillRect(x, y, kind === 'leather' ? 2 : 1.5, kind === 'leather' ? 2 : 1.5);
    }
  } else if (kind === 'mesh') {
    g.fillStyle = 'rgba(0,0,0,0.22)';
    for (let y = 0; y < S; y += 4) for (let x = (y / 4) % 2 ? 2 : 0; x < S; x += 4) g.fillRect(x, y, 1.6, 1.6);
  } else if (kind === 'rib') {
    g.fillStyle = 'rgba(0,0,0,0.16)';
    g.fillRect(0, 0, 2, S);
    g.fillStyle = 'rgba(255,255,255,0.07)';
    g.fillRect(6, 0, 2, S);
  }
  texCache.set(key, c);
  return ctx.createPattern(c, 'repeat');
}

/* ---------- monochroom (laser): luminantie → 3 bruintinten ---------- */
const monoCache = new WeakMap();
/**
 * Patchbeeld als lasergravure: donker → engraveHex, midden → tussentint, licht → transparant (leder blijft zichtbaar).
 * Valt terug op een CSS-filter als het beeld "tainted" is.
 */
export function monochrome(img, engraveHex, baseHex) {
  if (!img) return null;
  const cached = monoCache.get(img);
  if (cached && cached.key === engraveHex) return cached.canvas; // baseHex beïnvloedt de gravure niet
  const iw = img.naturalWidth || img.width;
  const ih = img.naturalHeight || img.height;
  const k = Math.min(1, 320 / Math.max(iw, ih));
  const w = Math.max(1, Math.round(iw * k)), h = Math.max(1, Math.round(ih * k));
  const c = makeCanvas(w, h);
  const g = c.getContext('2d');
  g.drawImage(img, 0, 0, w, h);
  try {
    const d = g.getImageData(0, 0, w, h);
    const p = d.data;
    const dark = hexToRgb(engraveHex);
    const mid = hexToRgb(shade(engraveHex, 0.28));
    const light = hexToRgb(shade(engraveHex, 0.55));
    for (let i = 0; i < p.length; i += 4) {
      if (p[i + 3] < 10) continue;
      const L = (0.299 * p[i] + 0.587 * p[i + 1] + 0.114 * p[i + 2]) / 255;
      const tone = L < 0.36 ? dark : L < 0.62 ? mid : light;
      const alpha = L < 0.36 ? 1 : L < 0.62 ? 0.85 : L < 0.86 ? 0.5 : 0.12;
      p[i] = tone[0];
      p[i + 1] = tone[1];
      p[i + 2] = tone[2];
      p[i + 3] = Math.round(p[i + 3] * alpha);
    }
    g.putImageData(d, 0, 0);
  } catch (e) {
    g.clearRect(0, 0, w, h);
    g.filter = 'grayscale(1) sepia(1) brightness(0.55) contrast(1.4)';
    g.drawImage(img, 0, 0, w, h);
    g.filter = 'none';
  }
  monoCache.set(img, { key: engraveHex, canvas: c });
  return c;
}

/* ---------- patch tekenen ---------- */
function imgSize(img) {
  return { w: img ? img.naturalWidth || img.width : 1, h: img ? img.naturalHeight || img.height : 1 };
}
/** Maat in px voor een patch met langste zijde `mm`. */
function fitBox(img, mm, ppm) {
  const { w, h } = imgSize(img);
  const L = mm * ppm;
  return w >= h ? { w: L, h: (L * h) / w } : { w: (L * w) / h, h: L };
}

function fallback(ctx, cx, cy, w, h, text) {
  ctx.save();
  ctx.fillStyle = 'rgba(255,255,255,0.86)';
  ctx.strokeStyle = 'rgba(0,0,0,0.35)';
  ctx.setLineDash([5, 4]);
  ctx.lineWidth = 1.5;
  roundRect(ctx, cx - w / 2, cy - h / 2, w, h, Math.min(w, h) * 0.18);
  ctx.fill();
  ctx.stroke();
  ctx.setLineDash([]);
  ctx.fillStyle = '#333';
  ctx.font = `600 ${Math.max(10, Math.round(h * 0.13))}px system-ui, sans-serif`;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  const words = String(text || '').split(' ');
  const half = Math.ceil(words.length / 2);
  ctx.fillText(words.slice(0, half).join(' '), cx, cy - h * 0.09, w * 0.9);
  ctx.fillText(words.slice(half).join(' '), cx, cy + h * 0.09, w * 0.9);
  ctx.restore();
}

/**
 * Patch met schaduw, optioneel gebogen (verticale sinus-warp in 8 stroken, voor petten).
 */
function drawPatch(ctx, img, cx, cy, box, o, warp = 0) {
  if (!img) {
    fallback(ctx, cx, cy, box.w, box.h, o.fallbackText);
    return;
  }
  const src = o.mono ? monochrome(img, o.engraveHex || '#5a3a22', o.colorHex) : img;
  const { w: iw, h: ih } = imgSize(src);
  ctx.save();
  if (!o.mono) {
    ctx.shadowColor = 'rgba(0,0,0,0.38)';
    ctx.shadowBlur = Math.max(2, box.w * 0.035);
    ctx.shadowOffsetX = box.w * 0.012;
    ctx.shadowOffsetY = box.w * 0.02;
  }
  if (!warp) {
    ctx.drawImage(src, cx - box.w / 2, cy - box.h / 2, box.w, box.h);
  } else {
    const N = 8;
    for (let i = 0; i < N; i++) {
      const t0 = i / N, t1 = (i + 1) / N;
      const tm = (t0 + t1) / 2;
      const bulge = Math.sin(tm * Math.PI);
      const dy = -warp * bulge * box.h;
      const sy = 1 - 0.04 * (1 - bulge);
      ctx.drawImage(src, t0 * iw, 0, (t1 - t0) * iw + 0.5, ih,
        cx - box.w / 2 + t0 * box.w, cy - (box.h * sy) / 2 + dy, (t1 - t0) * box.w + 0.6, box.h * sy);
    }
  }
  ctx.restore();
}

function roundRect(ctx, x, y, w, h, r) {
  r = Math.max(0, Math.min(r, w / 2, h / 2));
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}
function lightGradient(ctx, x0, y0, x1, y1, hex, strength = 0.18) {
  const g = ctx.createLinearGradient(x0, y0, x1, y1);
  g.addColorStop(0, shade(hex, strength));
  g.addColorStop(0.55, hex);
  g.addColorStop(1, shade(hex, -strength * 1.4));
  return g;
}
function overlayLight(ctx, x0, y0, x1, y1, a = 0.22) {
  const g = ctx.createLinearGradient(x0, y0, x1, y1);
  g.addColorStop(0, `rgba(255,255,255,${a})`);
  g.addColorStop(0.5, 'rgba(255,255,255,0)');
  g.addColorStop(1, `rgba(0,0,0,${a * 1.2})`);
  return g;
}
function stitches(ctx, color, width, dash) {
  ctx.save();
  ctx.strokeStyle = color;
  ctx.lineWidth = width;
  ctx.setLineDash(dash);
  ctx.stroke();
  ctx.restore();
}
function background(ctx, W, H) {
  const g = ctx.createLinearGradient(0, 0, 0, H);
  g.addColorStop(0, '#f6f5f2');
  g.addColorStop(1, '#e7e4de');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, W, H);
}
function groundShadow(ctx, cx, cy, rx, ry) {
  const g = ctx.createRadialGradient(cx, cy, 0, cx, cy, rx);
  g.addColorStop(0, 'rgba(0,0,0,0.22)');
  g.addColorStop(1, 'rgba(0,0,0,0)');
  ctx.save();
  ctx.translate(cx, cy);
  ctx.scale(1, ry / rx);
  ctx.translate(-cx, -cy);
  ctx.fillStyle = g;
  ctx.beginPath();
  ctx.arc(cx, cy, rx, 0, Math.PI * 2);
  ctx.fill();
  ctx.restore();
}

/* ---------- dragers ---------- */
function cap(ctx, W, H, o, model) {
  const ppm = W / (o.scaleRefMm || 230);
  const cx = W / 2;
  const base = H * 0.7;
  const cw = 196 * ppm;
  const ch = (model === 'cap_snapback' ? 118 : model === 'cap_dad' ? 96 : 110) * ppm;
  const col = o.colorHex || '#1c1c1c';
  groundShadow(ctx, cx, base + 58 * ppm, cw * 0.62, 18 * ppm);
  // kroon
  ctx.save();
  ctx.beginPath();
  ctx.moveTo(cx - cw / 2, base);
  if (model === 'cap_snapback') ctx.bezierCurveTo(cx - cw / 2, base - ch * 1.2, cx + cw / 2, base - ch * 1.2, cx + cw / 2, base);
  else ctx.bezierCurveTo(cx - cw * 0.52, base - ch * 1.32, cx + cw * 0.52, base - ch * 1.32, cx + cw / 2, base);
  ctx.closePath();
  ctx.fillStyle = lightGradient(ctx, cx - cw / 2, base - ch, cx + cw / 2, base, col);
  ctx.fill();
  ctx.clip();
  ctx.fillStyle = texture(ctx, 'twill', col);
  ctx.globalAlpha = 0.6;
  ctx.fillRect(0, 0, W, H);
  ctx.globalAlpha = 1;
  if (model === 'cap_trucker') {
    // mesh zijpanelen (lichter)
    const mesh = lum(col) > 0.6 ? shade(col, -0.08) : shade(col, 0.1);
    for (const side of [-1, 1]) {
      ctx.beginPath();
      ctx.moveTo(cx + side * 54 * ppm, base);
      ctx.quadraticCurveTo(cx + side * 42 * ppm, base - ch * 0.7, cx, base - ch * 1.05);
      ctx.lineTo(cx + side * cw, base - ch * 1.2);
      ctx.lineTo(cx + side * cw, base);
      ctx.closePath();
      ctx.fillStyle = mesh;
      ctx.fill();
      ctx.fillStyle = texture(ctx, 'mesh', mesh);
      ctx.fill();
    }
  }
  // naden
  ctx.lineWidth = 1.4;
  for (const side of [-1, 1]) {
    ctx.beginPath();
    ctx.moveTo(cx + side * 54 * ppm, base);
    ctx.quadraticCurveTo(cx + side * 42 * ppm, base - ch * 0.7, cx, base - ch * 1.02);
    ctx.strokeStyle = shade(col, -0.35);
    ctx.stroke();
    stitches(ctx, shade(col, 0.25), 0.8, [3, 2.5]);
  }
  if (model === 'cap_dad') {
    ctx.beginPath();
    ctx.moveTo(cx, base);
    ctx.lineTo(cx, base - ch * 1.02);
    ctx.strokeStyle = shade(col, -0.35);
    ctx.stroke();
  }
  ctx.fillStyle = overlayLight(ctx, cx - cw / 2, base - ch, cx + cw / 2, base, 0.2);
  ctx.fillRect(0, 0, W, H);
  ctx.restore();
  // knoop
  ctx.beginPath();
  ctx.ellipse(cx, base - ch * (model === 'cap_snapback' ? 0.92 : 0.99), 7 * ppm, 3.2 * ppm, 0, 0, Math.PI * 2);
  ctx.fillStyle = shade(col, -0.2);
  ctx.fill();
  // patch op het voorpaneel
  const box = fitBox(o.patchImg, (o.patchSizeCm || 6) * 10, ppm);
  drawPatch(ctx, o.patchImg, cx, base - ch * 0.46, box, o, model === 'cap_snapback' ? 0.025 : 0.06);
  // klep
  const brimCol = shade(col, -0.12);
  ctx.beginPath();
  ctx.moveTo(cx - cw * 0.53, base - 2 * ppm);
  ctx.quadraticCurveTo(cx, base + (model === 'cap_snapback' ? 46 : 74) * ppm, cx + cw * 0.53, base - 2 * ppm);
  ctx.quadraticCurveTo(cx, base + 16 * ppm, cx - cw * 0.53, base - 2 * ppm);
  ctx.closePath();
  ctx.fillStyle = lightGradient(ctx, cx - cw / 2, base, cx + cw / 2, base + 60 * ppm, brimCol, 0.14);
  ctx.fill();
  ctx.save();
  ctx.clip();
  ctx.fillStyle = texture(ctx, 'twill', brimCol);
  ctx.globalAlpha = 0.5;
  ctx.fillRect(0, 0, W, H);
  ctx.restore();
  for (let i = 1; i <= 4; i++) {
    ctx.beginPath();
    const k = i * 0.16;
    ctx.moveTo(cx - cw * (0.5 - k * 0.12), base + 2 * ppm);
    ctx.quadraticCurveTo(cx, base + (16 + (model === 'cap_snapback' ? 30 : 58) * k * 1.4) * ppm, cx + cw * (0.5 - k * 0.12), base + 2 * ppm);
    stitches(ctx, shade(brimCol, 0.3), 0.8, [3, 2.5]);
  }
  // zweetband-rand
  ctx.beginPath();
  ctx.moveTo(cx - cw / 2, base);
  ctx.quadraticCurveTo(cx, base + 16 * ppm, cx + cw / 2, base);
  ctx.strokeStyle = shade(col, -0.45);
  ctx.lineWidth = 2;
  ctx.stroke();
}

function beanie(ctx, W, H, o) {
  const ppm = W / (o.scaleRefMm || 230);
  const cx = W / 2;
  const col = o.colorHex || '#1c1c1c';
  const cuffTop = H * 0.5, cuffH = 66 * ppm, bw = 200 * ppm;
  groundShadow(ctx, cx, cuffTop + cuffH + 10 * ppm, bw * 0.6, 14 * ppm);
  // koepel
  ctx.beginPath();
  ctx.moveTo(cx - bw * 0.47, cuffTop + 4);
  ctx.bezierCurveTo(cx - bw * 0.5, cuffTop - 150 * ppm, cx + bw * 0.5, cuffTop - 150 * ppm, cx + bw * 0.47, cuffTop + 4);
  ctx.closePath();
  ctx.fillStyle = lightGradient(ctx, cx - bw / 2, cuffTop - 120 * ppm, cx + bw / 2, cuffTop, col);
  ctx.fill();
  ctx.save();
  ctx.clip();
  ctx.fillStyle = texture(ctx, 'rib', shade(col, 0.03));
  ctx.globalAlpha = 0.7;
  ctx.fillRect(0, 0, W, H);
  ctx.restore();
  // omslag
  ctx.save();
  roundRect(ctx, cx - bw / 2, cuffTop, bw, cuffH, 10 * ppm);
  ctx.fillStyle = lightGradient(ctx, cx - bw / 2, cuffTop, cx + bw / 2, cuffTop + cuffH, shade(col, 0.04));
  ctx.fill();
  ctx.clip();
  ctx.fillStyle = texture(ctx, 'rib', shade(col, 0.04));
  ctx.fillRect(0, 0, W, H);
  ctx.fillStyle = overlayLight(ctx, 0, cuffTop, 0, cuffTop + cuffH, 0.18);
  ctx.fillRect(0, 0, W, H);
  ctx.restore();
  ctx.beginPath();
  ctx.moveTo(cx - bw / 2 + 6, cuffTop + 2);
  ctx.lineTo(cx + bw / 2 - 6, cuffTop + 2);
  ctx.strokeStyle = shade(col, -0.35);
  ctx.lineWidth = 2;
  ctx.stroke();
  const box = fitBox(o.patchImg, (o.patchSizeCm || 6) * 10, ppm);
  drawPatch(ctx, o.patchImg, cx, cuffTop + cuffH / 2, box, o, 0);
}

function hoodie(ctx, W, H, o, back) {
  const ppm = Math.min(W, H * 1.15) / (o.scaleRefMm || 560);
  const cx = W / 2;
  const top = H * 0.08;
  const col = o.colorHex || '#1c1c1c';
  const bodyW = 300 * ppm, bodyH = 330 * ppm;
  const sh = top + 70 * ppm;
  groundShadow(ctx, cx, top + 70 * ppm + bodyH + 8, bodyW * 0.7, 14 * ppm);
  ctx.save();
  ctx.beginPath();
  // romp + mouwen
  ctx.moveTo(cx - 70 * ppm, top + 30 * ppm);
  ctx.quadraticCurveTo(cx - bodyW * 0.55, sh - 22 * ppm, cx - bodyW * 0.62, sh + 20 * ppm);
  ctx.lineTo(cx - bodyW * 0.86, sh + 300 * ppm);
  ctx.lineTo(cx - bodyW * 0.68, sh + 312 * ppm);
  ctx.lineTo(cx - bodyW * 0.52, sh + 100 * ppm);
  ctx.lineTo(cx - bodyW * 0.5, sh + bodyH);
  ctx.lineTo(cx + bodyW * 0.5, sh + bodyH);
  ctx.lineTo(cx + bodyW * 0.52, sh + 100 * ppm);
  ctx.lineTo(cx + bodyW * 0.68, sh + 312 * ppm);
  ctx.lineTo(cx + bodyW * 0.86, sh + 300 * ppm);
  ctx.lineTo(cx + bodyW * 0.62, sh + 20 * ppm);
  ctx.quadraticCurveTo(cx + bodyW * 0.55, sh - 22 * ppm, cx + 70 * ppm, top + 30 * ppm);
  ctx.closePath();
  ctx.fillStyle = lightGradient(ctx, cx - bodyW, top, cx + bodyW, sh + bodyH, col, 0.14);
  ctx.fill();
  ctx.clip();
  ctx.fillStyle = texture(ctx, 'fleece', col);
  ctx.globalAlpha = 0.6;
  ctx.fillRect(0, 0, W, H);
  ctx.globalAlpha = 1;
  // boord + manchetten
  ctx.fillStyle = texture(ctx, 'rib', shade(col, -0.06));
  ctx.fillRect(cx - bodyW * 0.5, sh + bodyH - 26 * ppm, bodyW, 26 * ppm);
  // plooien
  ctx.strokeStyle = 'rgba(0,0,0,0.18)';
  ctx.lineWidth = 2;
  ctx.beginPath();
  ctx.moveTo(cx - bodyW * 0.52, sh + 110 * ppm);
  ctx.quadraticCurveTo(cx - bodyW * 0.42, sh + 200 * ppm, cx - bodyW * 0.46, sh + 290 * ppm);
  ctx.moveTo(cx + bodyW * 0.52, sh + 110 * ppm);
  ctx.quadraticCurveTo(cx + bodyW * 0.42, sh + 200 * ppm, cx + bodyW * 0.46, sh + 290 * ppm);
  ctx.stroke();
  if (!back) {
    // buidelzak
    ctx.beginPath();
    ctx.moveTo(cx - 95 * ppm, sh + bodyH - 28 * ppm);
    ctx.lineTo(cx - 70 * ppm, sh + 200 * ppm);
    ctx.lineTo(cx + 70 * ppm, sh + 200 * ppm);
    ctx.lineTo(cx + 95 * ppm, sh + bodyH - 28 * ppm);
    ctx.strokeStyle = shade(col, -0.32);
    ctx.lineWidth = 1.6;
    ctx.stroke();
    stitches(ctx, shade(col, 0.22), 0.8, [3, 2.5]);
  }
  ctx.fillStyle = overlayLight(ctx, cx - bodyW, top, cx + bodyW, sh + bodyH, 0.16);
  ctx.fillRect(0, 0, W, H);
  ctx.restore();
  // kap
  ctx.beginPath();
  if (back) {
    ctx.moveTo(cx - 72 * ppm, top + 30 * ppm);
    ctx.quadraticCurveTo(cx, top + 150 * ppm, cx + 72 * ppm, top + 30 * ppm);
    ctx.quadraticCurveTo(cx, top - 10 * ppm, cx - 72 * ppm, top + 30 * ppm);
  } else {
    ctx.moveTo(cx - 74 * ppm, top + 32 * ppm);
    ctx.quadraticCurveTo(cx - 80 * ppm, top - 30 * ppm, cx, top - 34 * ppm);
    ctx.quadraticCurveTo(cx + 80 * ppm, top - 30 * ppm, cx + 74 * ppm, top + 32 * ppm);
    ctx.quadraticCurveTo(cx, top + 92 * ppm, cx - 74 * ppm, top + 32 * ppm);
  }
  ctx.fillStyle = lightGradient(ctx, cx - 80 * ppm, top - 30 * ppm, cx + 80 * ppm, top + 90 * ppm, shade(col, -0.05));
  ctx.fill();
  if (!back) {
    ctx.beginPath();
    ctx.ellipse(cx, top + 26 * ppm, 46 * ppm, 34 * ppm, 0, 0, Math.PI * 2);
    ctx.fillStyle = shade(col, -0.45);
    ctx.fill();
    // koorden
    ctx.strokeStyle = lum(col) > 0.55 ? shade(col, -0.25) : shade(col, 0.55);
    ctx.lineWidth = 3 * ppm + 1;
    ctx.lineCap = 'round';
    for (const s of [-1, 1]) {
      ctx.beginPath();
      ctx.moveTo(cx + s * 22 * ppm, top + 64 * ppm);
      ctx.quadraticCurveTo(cx + s * 26 * ppm, top + 120 * ppm, cx + s * 20 * ppm, top + 160 * ppm);
      ctx.stroke();
    }
  }
  const box = fitBox(o.patchImg, (o.patchSizeCm || 8) * 10, ppm);
  if (back) drawPatch(ctx, o.patchImg, cx, sh + 95 * ppm, box, o, 0);
  else drawPatch(ctx, o.patchImg, cx + 72 * ppm, sh + 62 * ppm, box, o, 0);
}

function leatherTag(ctx, W, H, o, bag) {
  const ppm = W / (o.scaleRefMm || 110);
  const cx = W / 2, cy = H * 0.56;
  const tw = (bag ? 100 : 64) * ppm, th = (bag ? 62 : 42) * ppm;
  const leather = o.leatherHex || '#9a6236';
  groundShadow(ctx, cx, cy + th / 2 + 8, tw * 0.7, 10 * ppm);
  // ring / riempje
  if (bag) {
    ctx.beginPath();
    ctx.moveTo(cx - tw / 2 + 10 * ppm, cy);
    ctx.bezierCurveTo(cx - tw / 2 - 30 * ppm, cy - 40 * ppm, cx - tw / 2 - 10 * ppm, cy - 70 * ppm, cx - tw / 2 + 4 * ppm, cy - 74 * ppm);
    ctx.strokeStyle = shade(leather, -0.2);
    ctx.lineWidth = 7 * ppm;
    ctx.lineCap = 'round';
    ctx.stroke();
  } else {
    ctx.beginPath();
    ctx.arc(cx, cy - th / 2 - 13 * ppm, 13 * ppm, 0, Math.PI * 2);
    const rg = ctx.createLinearGradient(cx - 13 * ppm, 0, cx + 13 * ppm, 0);
    rg.addColorStop(0, '#d9d9d9');
    rg.addColorStop(0.5, '#8d8d8d');
    rg.addColorStop(1, '#efefef');
    ctx.strokeStyle = rg;
    ctx.lineWidth = 2.6 * ppm;
    ctx.stroke();
  }
  ctx.save();
  roundRect(ctx, cx - tw / 2, cy - th / 2, tw, th, (bag ? 8 : 10) * ppm);
  ctx.fillStyle = lightGradient(ctx, cx - tw / 2, cy - th / 2, cx + tw / 2, cy + th / 2, leather, 0.16);
  ctx.fill();
  ctx.clip();
  ctx.fillStyle = texture(ctx, 'leather', leather);
  ctx.globalAlpha = 0.8;
  ctx.fillRect(0, 0, W, H);
  ctx.globalAlpha = 1;
  ctx.restore();
  roundRect(ctx, cx - tw / 2 + 3.2 * ppm, cy - th / 2 + 3.2 * ppm, tw - 6.4 * ppm, th - 6.4 * ppm, (bag ? 6 : 8) * ppm);
  stitches(ctx, shade(leather, 0.35), 1, [3, 2.5]);
  if (!bag) {
    ctx.beginPath();
    ctx.arc(cx, cy - th / 2 + 6 * ppm, 2.6 * ppm, 0, Math.PI * 2);
    ctx.fillStyle = '#b7b7b7';
    ctx.fill();
  }
  const box = fitBox(o.patchImg, Math.min(tw, th * 1.7) / ppm * 0.72, ppm);
  if (box.h > th * 0.74) {
    const k = (th * 0.74) / box.h;
    box.w *= k;
    box.h *= k;
  }
  drawPatch(ctx, o.patchImg, cx, cy + (bag ? 0 : 2 * ppm), box, Object.assign({}, o, { mono: true, colorHex: leather }), 0);
}

function frameBox(ctx, W, H, o) {
  const ppm = Math.min(W, H) / (o.scaleRefMm || 260);
  const cx = W / 2, cy = H / 2;
  const side = (o.frameMm || ((o.scaleRefMm || 260) > 300 ? 250 : 200)) * ppm;
  const border = 16 * ppm;
  ctx.save();
  ctx.shadowColor = 'rgba(0,0,0,0.35)';
  ctx.shadowBlur = 14 * ppm;
  ctx.shadowOffsetY = 6 * ppm;
  ctx.fillStyle = '#1f1d1b';
  ctx.fillRect(cx - side / 2, cy - side / 2, side, side);
  ctx.restore();
  ctx.fillStyle = lightGradient(ctx, cx - side / 2, cy - side / 2, cx + side / 2, cy + side / 2, '#2a2725', 0.2);
  ctx.fillRect(cx - side / 2, cy - side / 2, side, side);
  // passe-partout
  const inner = side - 2 * border;
  ctx.fillStyle = '#f3efe6';
  ctx.fillRect(cx - inner / 2, cy - inner / 2, inner, inner);
  ctx.fillStyle = texture(ctx, 'twill', '#f3efe6');
  ctx.fillRect(cx - inner / 2, cy - inner / 2, inner, inner);
  // schaduw binnenin
  const ig = ctx.createLinearGradient(0, cy - inner / 2, 0, cy - inner / 2 + 14 * ppm);
  ig.addColorStop(0, 'rgba(0,0,0,0.28)');
  ig.addColorStop(1, 'rgba(0,0,0,0)');
  ctx.fillStyle = ig;
  ctx.fillRect(cx - inner / 2, cy - inner / 2, inner, 14 * ppm);
  const lg = ctx.createLinearGradient(cx - inner / 2, 0, cx - inner / 2 + 10 * ppm, 0);
  lg.addColorStop(0, 'rgba(0,0,0,0.2)');
  lg.addColorStop(1, 'rgba(0,0,0,0)');
  ctx.fillStyle = lg;
  ctx.fillRect(cx - inner / 2, cy - inner / 2, 10 * ppm, inner);
  const box = fitBox(o.patchImg, (o.patchSizeCm || 8) * 10, ppm);
  drawPatch(ctx, o.patchImg, cx, cy - inner * 0.08, box, o, 0);
  // plaatje
  const pw = Math.min(inner * 0.62, 120 * ppm), ph = 22 * ppm;
  const py = cy + inner / 2 - ph - 14 * ppm;
  ctx.save();
  ctx.shadowColor = 'rgba(0,0,0,0.25)';
  ctx.shadowBlur = 3;
  ctx.shadowOffsetY = 1;
  roundRect(ctx, cx - pw / 2, py, pw, ph, 2 * ppm);
  ctx.fillStyle = lightGradient(ctx, cx - pw / 2, py, cx + pw / 2, py + ph, o.plateHex || '#c9b26b', 0.3);
  ctx.fill();
  ctx.restore();
  const lines = (o.plateLines || []).map((s) => String(s || '').trim()).filter(Boolean);
  ctx.fillStyle = o.plateTextHex || '#2b2b2b';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  if (lines.length === 1) {
    ctx.font = `600 ${Math.round(8.5 * ppm)}px Georgia, serif`;
    ctx.fillText(lines[0], cx, py + ph / 2, pw * 0.9);
  } else if (lines.length >= 2) {
    ctx.font = `600 ${Math.round(7 * ppm)}px Georgia, serif`;
    ctx.fillText(lines[0], cx, py + ph * 0.32, pw * 0.9);
    ctx.font = `${Math.round(6.2 * ppm)}px Georgia, serif`;
    ctx.fillText(lines[1], cx, py + ph * 0.72, pw * 0.9);
  }
  // glasreflectie
  ctx.save();
  ctx.beginPath();
  ctx.rect(cx - inner / 2, cy - inner / 2, inner, inner);
  ctx.clip();
  const gl = ctx.createLinearGradient(cx - inner / 2, cy - inner / 2, cx + inner / 2, cy + inner / 2);
  gl.addColorStop(0, 'rgba(255,255,255,0.18)');
  gl.addColorStop(0.3, 'rgba(255,255,255,0.03)');
  gl.addColorStop(0.31, 'rgba(255,255,255,0)');
  ctx.fillStyle = gl;
  ctx.fillRect(cx - inner / 2, cy - inner / 2, inner, inner);
  ctx.restore();
}

function pvcKeychain(ctx, W, H, o) {
  const ppm = W / (o.scaleRefMm || 100);
  const cx = W / 2, cy = H * 0.58;
  const box = fitBox(o.patchImg, 50, ppm);
  groundShadow(ctx, cx, cy + box.h / 2 + 8, box.w * 0.6, 8 * ppm);
  // ring + oogje
  const ey = cy - box.h / 2 - 3 * ppm;
  ctx.beginPath();
  ctx.arc(cx, ey - 14 * ppm, 13 * ppm, 0, Math.PI * 2);
  const rg = ctx.createLinearGradient(cx - 13 * ppm, 0, cx + 13 * ppm, 0);
  rg.addColorStop(0, '#dcdcdc');
  rg.addColorStop(0.5, '#8a8a8a');
  rg.addColorStop(1, '#f0f0f0');
  ctx.strokeStyle = rg;
  ctx.lineWidth = 2.6 * ppm;
  ctx.stroke();
  ctx.beginPath();
  ctx.arc(cx, ey, 5 * ppm, 0, Math.PI * 2);
  ctx.fillStyle = o.colorHex || '#222';
  ctx.fill();
  ctx.beginPath();
  ctx.arc(cx, ey, 2.2 * ppm, 0, Math.PI * 2);
  ctx.fillStyle = '#e9e6df';
  ctx.fill();
  // rubberrand
  ctx.save();
  roundRect(ctx, cx - box.w / 2 - 3 * ppm, cy - box.h / 2 - 3 * ppm, box.w + 6 * ppm, box.h + 6 * ppm, Math.min(box.w, box.h) * 0.22);
  ctx.fillStyle = o.colorHex || '#222';
  ctx.shadowColor = 'rgba(0,0,0,0.3)';
  ctx.shadowBlur = 6;
  ctx.shadowOffsetY = 3;
  ctx.fill();
  ctx.restore();
  drawPatch(ctx, o.patchImg, cx, cy, box, Object.assign({}, o, { mono: false }), 0);
  // glans
  ctx.save();
  roundRect(ctx, cx - box.w / 2 - 3 * ppm, cy - box.h / 2 - 3 * ppm, box.w + 6 * ppm, box.h + 6 * ppm, Math.min(box.w, box.h) * 0.22);
  ctx.clip();
  const gl = ctx.createLinearGradient(0, cy - box.h / 2, 0, cy + box.h / 2);
  gl.addColorStop(0, 'rgba(255,255,255,0.35)');
  gl.addColorStop(0.42, 'rgba(255,255,255,0.06)');
  gl.addColorStop(0.43, 'rgba(255,255,255,0)');
  ctx.fillStyle = gl;
  ctx.fillRect(cx - box.w, cy - box.h, box.w * 2, box.h * 2);
  ctx.restore();
}

export const MOCKUP_KINDS = ['cap_trucker', 'cap_snapback', 'cap_dad', 'beanie_cuff', 'hoodie_chest', 'hoodie_back', 'leather_tag', 'bagtag', 'frame_box', 'pvc_keychain'];

/**
 * Teken een mockup. Geeft de duur in ms terug (prestatiebudget ≤ 25 ms per kaart bij 480 px).
 * @param {CanvasRenderingContext2D} ctx
 */
export function drawMockup(ctx, kind, opts, W, H) {
  const t0 = typeof performance !== 'undefined' ? performance.now() : 0;
  const o = Object.assign({ colorHex: '#1c1c1c', patchSizeCm: 6, fallbackText: '' }, opts || {});
  ctx.save();
  ctx.clearRect(0, 0, W, H);
  background(ctx, W, H);
  switch (kind) {
    case 'cap_trucker':
    case 'cap_snapback':
    case 'cap_dad':
      cap(ctx, W, H, o, kind);
      break;
    case 'beanie_cuff':
      beanie(ctx, W, H, o);
      break;
    case 'hoodie_chest':
    case 'hoodie_back':
      hoodie(ctx, W, H, o, kind === 'hoodie_back');
      break;
    case 'leather_tag':
    case 'bagtag':
      leatherTag(ctx, W, H, o, kind === 'bagtag');
      break;
    case 'frame_box':
      frameBox(ctx, W, H, o);
      break;
    case 'pvc_keychain':
      pvcKeychain(ctx, W, H, o);
      break;
    default:
      cap(ctx, W, H, o, 'cap_trucker');
  }
  ctx.restore();
  return typeof performance !== 'undefined' ? performance.now() - t0 : 0;
}

/** Laad een preview-afbeelding (zelfde domein; anders crossOrigin=anonymous). Lost op met null bij een fout. */
export function loadImage(url) {
  return new Promise((resolve) => {
    if (!url) return resolve(null);
    const img = new Image();
    try {
      if (new URL(url, location.href).origin !== location.origin) img.crossOrigin = 'anonymous';
    } catch (e) { /* relatieve of data-URL */ }
    img.decoding = 'async';
    // Decoderen vóór het paneel opent: de eerste drawImage van een grote JPEG kost anders tientallen ms (budget 25 ms/kaart).
    img.onload = () => {
      if (typeof img.decode === 'function') img.decode().then(() => resolve(img), () => resolve(img));
      else resolve(img);
    };
    img.onerror = () => resolve(null);
    img.src = url;
  });
}
