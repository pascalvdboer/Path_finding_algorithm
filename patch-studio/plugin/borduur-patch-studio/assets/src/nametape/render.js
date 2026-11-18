/**
 * Naamstroken — canvas-renderer (SPEC-EXTRA §1.10). Twill-textuur, satijnachtige letters met lichte steekrichting en schaduw,
 * geborduurde rand, procedurele camo/reflecterend. Geen externe afbeeldingen. Doel ≤ 8 ms per tekening.
 *
 *   drawTape(ctx, spec, pxPerMm, {x, y})
 *   spec = { format:{w,h}, fabric:{hex, pattern?}, letterHex, borderHex|null, borderMm, font:{family, weight, capHeightEm, script},
 *            lines: string[] (al in hoofdletters indien nodig), capMm, lineGapRatio }
 */

const patternCache = new Map();
const MARGIN_MM = 2.5;

function hexToRgb(hex) {
  const h = String(hex || '#000000').replace('#', '');
  const v = parseInt(h.length === 3 ? h.split('').map((c) => c + c).join('') : h.slice(0, 6), 16) || 0;
  return [(v >> 16) & 255, (v >> 8) & 255, v & 255];
}
function shade(hex, f) {
  const [r, g, b] = hexToRgb(hex);
  const m = (c) => Math.max(0, Math.min(255, Math.round(f < 0 ? c * (1 + f) : c + (255 - c) * f)));
  return `rgb(${m(r)},${m(g)},${m(b)})`;
}
function luminance(hex) {
  const [r, g, b] = hexToRgb(hex);
  return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
}

/** Kleine seeded RNG zodat camo stabiel blijft tussen tekeningen. */
function rng(seed) {
  let s = seed >>> 0;
  return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296);
}

function makeCanvas(w, h) {
  if (typeof OffscreenCanvas !== 'undefined') return new OffscreenCanvas(w, h);
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  return c;
}

/** Herhaalbaar twill-patroon (diagonale ribbels) voor een stofkleur, gecachet per kleur+schaal. */
function fabricPattern(ctx, fabric, px) {
  const size = Math.max(8, Math.round(px * 2));
  const key = (fabric.pattern || '') + fabric.hex + '|' + size;
  let pat = patternCache.get(key);
  if (pat) return pat;
  const c = makeCanvas(size, size);
  const g = c.getContext('2d');
  const base = fabric.hex || '#1c1c1c';
  g.fillStyle = base;
  g.fillRect(0, 0, size, size);
  if (fabric.pattern === 'camo-woodland') {
    // camo vraagt een groter patroon
    patternCache.delete(key);
    return camoPattern(ctx, px);
  }
  const dark = luminance(base) > 0.6;
  g.lineWidth = Math.max(1, size / 7);
  const step = size / 3;
  for (let i = -size; i < size * 2; i += step) {
    g.strokeStyle = dark ? 'rgba(0,0,0,0.07)' : 'rgba(255,255,255,0.07)';
    g.beginPath(); g.moveTo(i, 0); g.lineTo(i + size, size); g.stroke();
    g.strokeStyle = 'rgba(0,0,0,0.08)';
    g.beginPath(); g.moveTo(i + step / 2, 0); g.lineTo(i + step / 2 + size, size); g.stroke();
  }
  if (fabric.pattern === 'reflective') {
    g.fillStyle = 'rgba(255,255,255,0.18)';
    for (let i = 0; i < size; i += 2) for (let j = (i / 2) % 2; j < size; j += 2) g.fillRect(i, j, 1, 1);
  }
  pat = ctx.createPattern(c, 'repeat');
  patternCache.set(key, pat);
  return pat;
}

function camoPattern(ctx, px) {
  const size = Math.max(64, Math.round(px * 40));
  const key = 'camo|' + size;
  let pat = patternCache.get(key);
  if (pat) return pat;
  const c = makeCanvas(size, size);
  const g = c.getContext('2d');
  const r = rng(42);
  g.fillStyle = '#6b6a45';
  g.fillRect(0, 0, size, size);
  const cols = ['#3f3d26', '#8a7a52', '#2b2a1d', '#58653a'];
  for (let k = 0; k < 28; k++) {
    g.fillStyle = cols[k % cols.length];
    const cx = r() * size, cy = r() * size, rad = size * (0.06 + r() * 0.12);
    g.beginPath();
    for (let a = 0; a <= 6; a++) {
      const ang = (a / 6) * Math.PI * 2;
      const rr = rad * (0.6 + r() * 0.6);
      if (a === 0) g.moveTo(cx + Math.cos(ang) * rr, cy + Math.sin(ang) * rr);
      else g.lineTo(cx + Math.cos(ang) * rr, cy + Math.sin(ang) * rr);
    }
    g.closePath(); g.fill();
  }
  pat = ctx.createPattern(c, 'repeat');
  patternCache.set(key, pat);
  return pat;
}

function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

let textCanvas = null;

/** Canvasmaat (px) voor een formaat incl. marge. */
export function canvasSize(format, pxPerMm) {
  return { w: Math.ceil((format.w + 2 * MARGIN_MM) * pxPerMm), h: Math.ceil((format.h + 2 * MARGIN_MM) * pxPerMm) };
}

/** CSS-fontstring voor een lettertype op een gegeven cap-hoogte (px). */
export function fontString(font, capPx) {
  const size = capPx / (font.capHeightEm || 0.72);
  return `${font.weight || 700} ${size.toFixed(2)}px "${font.loadedFamily || font.family}", ${font.script ? 'cursive' : 'system-ui, sans-serif'}`;
}

/**
 * Teken één strook. ctx moet groot genoeg zijn (canvasSize). Geeft de gebruikte rechthoek terug.
 */
export function drawTape(ctx, spec, pxPerMm, origin = { x: 0, y: 0 }) {
  const px = pxPerMm;
  const W = spec.format.w * px, H = spec.format.h * px;
  const x = origin.x + MARGIN_MM * px, y = origin.y + MARGIN_MM * px;
  const radius = Math.min(H / 2, 1.6 * px);
  const fabric = spec.fabric || { hex: '#1c1c1c' };

  // schaduw van de strook op de achtergrond
  ctx.save();
  ctx.shadowColor = 'rgba(0,0,0,0.35)';
  ctx.shadowBlur = 1.6 * px;
  ctx.shadowOffsetY = 0.5 * px;
  roundRect(ctx, x, y, W, H, radius);
  ctx.fillStyle = fabric.hex || '#1c1c1c';
  ctx.fill();
  ctx.restore();

  // stof
  ctx.save();
  roundRect(ctx, x, y, W, H, radius);
  ctx.clip();
  ctx.fillStyle = fabricPattern(ctx, fabric, px);
  ctx.fillRect(x, y, W, H);
  // licht linksboven
  const lg = ctx.createLinearGradient(x, y, x + W * 0.4, y + H);
  lg.addColorStop(0, 'rgba(255,255,255,0.10)');
  lg.addColorStop(1, 'rgba(0,0,0,0.10)');
  ctx.fillStyle = lg;
  ctx.fillRect(x, y, W, H);
  if (fabric.pattern === 'reflective') {
    const rg = ctx.createLinearGradient(x, y, x + W, y + H);
    rg.addColorStop(0, 'rgba(255,255,255,0)');
    rg.addColorStop(0.45, 'rgba(255,255,255,0.35)');
    rg.addColorStop(0.55, 'rgba(255,255,255,0)');
    ctx.fillStyle = rg;
    ctx.fillRect(x, y, W, H);
  }
  ctx.restore();

  // geborduurde rand (satijnsteek)
  const bmm = spec.borderHex ? Number(spec.borderMm) || 0 : 0;
  if (bmm > 0) {
    const bw = bmm * px;
    ctx.save();
    roundRect(ctx, x + bw / 2, y + bw / 2, W - bw, H - bw, Math.max(0, radius - bw / 2));
    ctx.lineWidth = bw;
    ctx.strokeStyle = spec.borderHex;
    ctx.stroke();
    // steekrichting: fijne lichte/donkere lijntjes dwars op de rand
    ctx.beginPath();
    roundRect(ctx, x, y, W, H, radius);
    roundRect(ctx, x + bw, y + bw, W - 2 * bw, H - 2 * bw, Math.max(0, radius - bw));
    ctx.clip('evenodd');
    const st = Math.max(1.5, px * 0.45);
    ctx.lineWidth = Math.max(0.5, st * 0.35);
    ctx.strokeStyle = luminance(spec.borderHex) > 0.5 ? 'rgba(0,0,0,0.13)' : 'rgba(255,255,255,0.16)';
    ctx.beginPath();
    for (let i = -H; i < W + H; i += st) { ctx.moveTo(x + i, y); ctx.lineTo(x + i + H * 0.35, y + H); }
    ctx.stroke();
    const eg = ctx.createLinearGradient(x, y, x, y + H);
    eg.addColorStop(0, 'rgba(255,255,255,0.18)');
    eg.addColorStop(0.5, 'rgba(255,255,255,0)');
    eg.addColorStop(1, 'rgba(0,0,0,0.18)');
    ctx.fillStyle = eg;
    ctx.fillRect(x, y, W, H);
    ctx.restore();
  }

  // tekst
  const lines = (spec.lines || []).filter((l) => l !== '');
  if (lines.length && spec.capMm > 0) {
    const cap = spec.capMm * px;
    const gap = cap * (spec.lineGapRatio == null ? 0.35 : spec.lineGapRatio);
    const total = lines.length * cap + (lines.length - 1) * gap;
    const tw = Math.ceil(W), th = Math.ceil(H);
    if (!textCanvas) textCanvas = makeCanvas(tw, th);
    if (textCanvas.width < tw || textCanvas.height < th) { textCanvas.width = Math.max(tw, textCanvas.width); textCanvas.height = Math.max(th, textCanvas.height); }
    const t = textCanvas.getContext('2d');
    t.setTransform(1, 0, 0, 1, 0, 0);
    t.globalCompositeOperation = 'source-over';
    t.clearRect(0, 0, textCanvas.width, textCanvas.height);
    t.font = fontString(spec.font || {}, cap);
    t.textAlign = 'center';
    t.textBaseline = 'alphabetic';
    t.fillStyle = spec.letterHex || '#ffffff';
    let by = (H - total) / 2 + cap;
    for (const l of lines) { t.fillText(l, W / 2, by); by += cap + gap; }
    // satijnglans + steekrichting enkel op de letters
    t.globalCompositeOperation = 'source-atop';
    const sg = t.createLinearGradient(0, 0, 0, H);
    sg.addColorStop(0, 'rgba(255,255,255,0.22)');
    sg.addColorStop(0.55, 'rgba(255,255,255,0)');
    sg.addColorStop(1, 'rgba(0,0,0,0.22)');
    t.fillStyle = sg;
    t.fillRect(0, 0, tw, th);
    const st = Math.max(1.2, px * 0.3);
    t.lineWidth = Math.max(0.4, st * 0.3);
    t.strokeStyle = luminance(spec.letterHex || '#ffffff') > 0.5 ? 'rgba(0,0,0,0.10)' : 'rgba(255,255,255,0.12)';
    t.beginPath();
    for (let i = -th; i < tw + th; i += st) { t.moveTo(i, 0); t.lineTo(i - th * 0.5, th); }
    t.stroke();
    t.globalCompositeOperation = 'source-over';

    ctx.save();
    if (spec.placeholder) ctx.globalAlpha = 0.45;
    ctx.shadowColor = 'rgba(0,0,0,0.45)';
    ctx.shadowBlur = Math.max(1, px * 0.35);
    ctx.shadowOffsetX = px * 0.12;
    ctx.shadowOffsetY = px * 0.18;
    ctx.drawImage(textCanvas, 0, 0, tw, th, x, y, tw, th);
    ctx.restore();
  }
  return { x, y, w: W, h: H };
}

/**
 * Teken in een <canvas>-element dat zijn CSS-breedte vult (devicePixelRatio-scherp). Geeft pxPerMm terug.
 * @param {HTMLCanvasElement} canvas
 * @param {object} spec
 * @param {{maxCssW?:number, maxCssH?:number}} [fit]
 */
export function renderInto(canvas, spec, fit = {}) {
  const dpr = Math.min(3, (typeof window !== 'undefined' && window.devicePixelRatio) || 1);
  const totalWmm = spec.format.w + 2 * MARGIN_MM, totalHmm = spec.format.h + 2 * MARGIN_MM;
  const maxW = fit.maxCssW || canvas.clientWidth || 320;
  const maxH = fit.maxCssH || 9999;
  const cssPxPerMm = Math.max(0.5, Math.min(maxW / totalWmm, maxH / totalHmm));
  const cssW = Math.round(totalWmm * cssPxPerMm), cssH = Math.round(totalHmm * cssPxPerMm);
  const pxw = Math.round(cssW * dpr), pxh = Math.round(cssH * dpr);
  if (canvas.width !== pxw) canvas.width = pxw;
  if (canvas.height !== pxh) canvas.height = pxh;
  canvas.style.width = cssW + 'px';
  canvas.style.height = cssH + 'px';
  const ctx = canvas.getContext('2d');
  ctx.setTransform(1, 0, 0, 1, 0, 0);
  ctx.clearRect(0, 0, pxw, pxh);
  drawTape(ctx, spec, cssPxPerMm * dpr);
  return cssPxPerMm;
}

/**
 * PNG van één strook (voor de winkelmand-preview): ≥ minWidth px breed, ≤ maxBytes.
 * @returns {Promise<Blob|null>}
 */
export async function toBlob(spec, { minWidth = 640, maxBytes = 300 * 1024 } = {}) {
  const totalWmm = spec.format.w + 2 * MARGIN_MM;
  let pxPerMm = Math.ceil(minWidth / totalWmm);
  for (let attempt = 0; attempt < 3; attempt++) {
    const size = canvasSize(spec.format, pxPerMm);
    const c = makeCanvas(size.w, size.h);
    drawTape(c.getContext('2d'), spec, pxPerMm);
    let blob = null;
    if (c.convertToBlob) blob = await c.convertToBlob({ type: 'image/png' });
    else blob = await new Promise((res) => c.toBlob(res, 'image/png'));
    if (blob && blob.size <= maxBytes) return blob;
    pxPerMm = Math.max(Math.ceil(600 / totalWmm), Math.floor(pxPerMm * 0.85));
  }
  return null;
}

export const MARGIN = MARGIN_MM;
