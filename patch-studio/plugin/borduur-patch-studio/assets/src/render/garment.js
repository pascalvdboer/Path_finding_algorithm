/**
 * Patch Studio — garment scenes drawn as vector silhouettes (no images): polo chest, cap front, jacket back.
 * Garment coordinates are millimetres at true scale; `scaleRefMm` from config.mockups is the reference width
 * of the garment body (chest width / cap front / back width), so the patch is shown at its real proportion.
 */
import { shade, rgba, lum } from './util.js';
import { overlayTexture } from './textures.js';

/* Each garment: base reference width (mm) the paths are drawn for, viewport, patch anchor, texture. */
const G = {
  polo_chest: { ref: 520, view: [-430, -90, 860, 700], anchor: [118, 175], tex: 'pique', curve: 0 },
  cap_front: { ref: 210, view: [-165, -175, 330, 270], anchor: [0, -62], tex: 'twill', curve: 120 },
  jacket_back: { ref: 600, view: [-470, -90, 940, 900], anchor: [0, 250], tex: 'ripstop', curve: 0 },
};

export const GARMENTS = Object.keys(G);

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

function seam(c, cmds, hex, s) {
  path(c, cmds);
  c.lineWidth = 2.2 / s; c.strokeStyle = rgba('#000000', 0.22); c.setLineDash([]); c.stroke();
  c.lineWidth = 1.2 / s; c.strokeStyle = rgba(shade(hex, lum(hex) < 80 ? 0.35 : -0.25), 0.7);
  c.setLineDash([5 / s, 3.5 / s]); c.stroke(); c.setLineDash([]);
}

function bodyShade(c, x0, x1, y0, y1) {
  const g = c.createLinearGradient(x0, 0, x1, 0);
  g.addColorStop(0, 'rgba(0,0,0,0.30)'); g.addColorStop(0.18, 'rgba(0,0,0,0.04)');
  g.addColorStop(0.5, 'rgba(255,255,255,0.05)'); g.addColorStop(0.82, 'rgba(0,0,0,0.06)'); g.addColorStop(1, 'rgba(0,0,0,0.34)');
  c.fillStyle = g; c.fillRect(x0, y0, x1 - x0, y1 - y0);
}

function polo(c, hex, s) {
  const outline = () => {
    c.beginPath();
    c.moveTo(0, 38); c.quadraticCurveTo(40, 18, 82, -10); c.lineTo(232, 24); c.quadraticCurveTo(330, 60, 388, 252);
    c.lineTo(302, 322); c.quadraticCurveTo(280, 280, 260, 240); c.lineTo(264, 800); c.lineTo(-264, 800); c.lineTo(-260, 240);
    c.quadraticCurveTo(-280, 280, -302, 322); c.lineTo(-388, 252); c.quadraticCurveTo(-330, 60, -232, 24); c.lineTo(-82, -10);
    c.quadraticCurveTo(-40, 18, 0, 38); c.closePath();
  };
  return {
    outline,
    details(sc) {
      // back of collar (inside), collar stand and flaps
      c.fillStyle = shade(hex, -0.35);
      c.beginPath(); c.moveTo(-82, -10); c.quadraticCurveTo(0, -38, 82, -10); c.quadraticCurveTo(40, 6, 0, 30); c.quadraticCurveTo(-40, 6, -82, -10); c.fill();
      const flap = (k) => {
        c.beginPath(); c.moveTo(k * 84, -12); c.quadraticCurveTo(k * 70, 40, k * 46, 92); c.lineTo(k * 4, 52); c.quadraticCurveTo(k * 40, 18, k * 84, -12); c.closePath();
      };
      for (const k of [1, -1]) {
        c.save(); c.shadowColor = 'rgba(0,0,0,0.35)'; c.shadowBlur = 10 * sc; c.shadowOffsetY = 4 * sc;
        flap(k); c.fillStyle = shade(hex, 0.04); c.fill(); c.restore();
        flap(k); c.lineWidth = 1.5 / sc; c.strokeStyle = rgba('#000000', 0.25); c.stroke();
      }
      // placket + buttons
      c.fillStyle = shade(hex, -0.05); c.fillRect(-17, 48, 34, 170);
      seam(c, [['M', -17, 50], ['L', -17, 218], ['L', 17, 218], ['L', 17, 50]], hex, sc);
      for (const y of [92, 160]) {
        c.beginPath(); c.arc(0, y, 6.5, 0, Math.PI * 2); c.fillStyle = lum(hex) < 128 ? '#e9e6df' : '#2b2b2b'; c.fill();
        c.fillStyle = 'rgba(0,0,0,0.35)'; c.beginPath(); c.arc(-1.8, y, 1, 0, 7); c.arc(1.8, y, 1, 0, 7); c.fill();
      }
      seam(c, [['M', 232, 24], ['Q', 250, 140, 260, 240]], hex, sc);
      seam(c, [['M', -232, 24], ['Q', -250, 140, -260, 240]], hex, sc);
      seam(c, [['M', 312, 312], ['L', 392, 246]], hex, sc);
      seam(c, [['M', -312, 312], ['L', -392, 246]], hex, sc);
      // soft folds
      c.strokeStyle = 'rgba(0,0,0,0.07)'; c.lineWidth = 14;
      path(c, [['M', 150, 420], ['Q', 190, 520, 170, 640]]); c.stroke();
      path(c, [['M', -140, 380], ['Q', -180, 500, -150, 620]]); c.stroke();
    },
    box: [-392, -40, 784, 840],
  };
}

function cap(c, hex) {
  const outline = () => {
    c.beginPath();
    c.moveTo(-108, 0); c.bezierCurveTo(-112, -90, -60, -132, 0, -132); c.bezierCurveTo(60, -132, 112, -90, 108, 0); c.quadraticCurveTo(0, 24, -108, 0); c.closePath();
  };
  return {
    outline,
    details(sc) {
      // panel seams, button, eyelets
      seam(c, [['M', 0, -132], ['L', 0, 0]], hex, sc);
      seam(c, [['M', 0, -132], ['Q', 62, -100, 74, 0]], hex, sc);
      seam(c, [['M', 0, -132], ['Q', -62, -100, -74, 0]], hex, sc);
      for (const k of [1, -1]) {
        c.beginPath(); c.arc(k * 66, -86, 3.4, 0, 7); c.fillStyle = shade(hex, -0.5); c.fill();
        c.lineWidth = 1.4 / sc; c.strokeStyle = shade(hex, 0.25); c.stroke();
      }
      c.beginPath(); c.ellipse(0, -132, 9, 4.5, 0, 0, 7); c.fillStyle = shade(hex, 0.1); c.fill();
      c.lineWidth = 1 / sc; c.strokeStyle = 'rgba(0,0,0,0.3)'; c.stroke();
    },
    after(sc) {
      // brim seen slightly from above
      c.save(); c.shadowColor = 'rgba(0,0,0,0.45)'; c.shadowBlur = 14 * sc; c.shadowOffsetY = 6 * sc;
      c.beginPath(); c.moveTo(-124, 4); c.quadraticCurveTo(0, 26, 124, 4); c.quadraticCurveTo(122, 30, 0, 44); c.quadraticCurveTo(-122, 30, -124, 4); c.closePath();
      c.fillStyle = shade(hex, -0.06); c.fill(); c.restore();
      const g = c.createLinearGradient(0, 4, 0, 44); g.addColorStop(0, 'rgba(0,0,0,0.35)'); g.addColorStop(0.5, 'rgba(0,0,0,0)'); g.addColorStop(1, 'rgba(255,255,255,0.06)');
      c.fillStyle = g; c.fill();
      for (let i = 1; i <= 3; i++) {
        seam(c, [['M', -120 + i * 3, 6 + i * 4.5], ['Q', 0, 26 + i * 4.5, 120 - i * 3, 6 + i * 4.5]], hex, sc);
      }
      // sweatband shadow line
      c.strokeStyle = 'rgba(0,0,0,0.35)'; c.lineWidth = 3; path(c, [['M', -108, 0], ['Q', 0, 24, 108, 0]]); c.stroke();
    },
    box: [-125, -140, 250, 190],
  };
}

function jacket(c, hex) {
  const outline = () => {
    c.beginPath();
    c.moveTo(-90, -14); c.quadraticCurveTo(0, -4, 90, -14); c.lineTo(250, 40); c.quadraticCurveTo(360, 80, 420, 330);
    c.lineTo(470, 820); c.lineTo(380, 830); c.lineTo(330, 420); c.lineTo(306, 290); c.lineTo(310, 860); c.lineTo(-310, 860);
    c.lineTo(-306, 290); c.lineTo(-330, 420); c.lineTo(-380, 830); c.lineTo(-470, 820); c.lineTo(-420, 330); c.quadraticCurveTo(-360, 80, -250, 40);
    c.closePath();
  };
  return {
    outline,
    details(sc) {
      // collar band
      c.save(); c.shadowColor = 'rgba(0,0,0,0.4)'; c.shadowBlur = 10 * sc; c.shadowOffsetY = 4 * sc;
      c.beginPath(); c.moveTo(-92, -14); c.quadraticCurveTo(0, -4, 92, -14); c.lineTo(96, -58); c.quadraticCurveTo(0, -46, -96, -58); c.closePath();
      c.fillStyle = shade(hex, -0.12); c.fill(); c.restore();
      seam(c, [['M', -94, -24], ['Q', 0, -14, 94, -24]], hex, sc);
      // yoke + raglan seams + hem band
      seam(c, [['M', -262, 150], ['Q', 0, 126, 262, 150]], hex, sc);
      seam(c, [['M', 92, -12], ['Q', 230, 140, 306, 290]], hex, sc);
      seam(c, [['M', -92, -12], ['Q', -230, 140, -306, 290]], hex, sc);
      c.fillStyle = shade(hex, -0.1); c.fillRect(-310, 800, 620, 60);
      seam(c, [['M', -310, 802], ['L', 310, 802]], hex, sc);
      // nylon sheen
      const g = c.createLinearGradient(-300, 0, 300, 600);
      g.addColorStop(0, 'rgba(255,255,255,0.10)'); g.addColorStop(0.45, 'rgba(255,255,255,0)'); g.addColorStop(0.7, 'rgba(255,255,255,0.06)'); g.addColorStop(1, 'rgba(0,0,0,0.08)');
      c.fillStyle = g; c.fillRect(-470, -60, 940, 920);
      c.strokeStyle = 'rgba(0,0,0,0.08)'; c.lineWidth = 18;
      path(c, [['M', 180, 480], ['Q', 210, 600, 190, 760]]); c.stroke();
      path(c, [['M', -170, 460], ['Q', -210, 590, -180, 760]]); c.stroke();
    },
    box: [-470, -60, 940, 920],
  };
}

/**
 * Draw the garment scene and return where/how to place the patch.
 * @param {CanvasRenderingContext2D} c
 * @param {number} W canvas width (px)
 * @param {number} H canvas height (px)
 * @param {string} id   polo_chest | cap_front | jacket_back
 * @param {object} config
 * @param {{garmentHex?:string, transparent?:boolean, zoom?:number}} o
 * @returns {{pxPerMm:number, cx:number, cy:number, curveMm:number, garmentHex:string}}
 */
export function drawGarment(c, W, H, id, config, o = {}) {
  const def = G[id] || G.polo_chest;
  const mk = ((config && config.mockups) || {})[id] || {};
  const hex = o.garmentHex || mk.garmentHex || '#1b2a4a';
  const refMm = Number(mk.scaleRefMm) || def.ref;
  const k = refMm / def.ref; // garment drawn at real scale for this reference width
  const [vx, vy, vw, vh] = def.view;
  const zoom = o.zoom || 1;
  const pxPerMm = Math.min(W / (vw * k), H / (vh * k)) * zoom;
  const off = Array.isArray(mk.patchCenterMm) ? mk.patchCenterMm : [0, 0];
  const ax = def.anchor[0] * k + (Number(off[0]) || 0), ay = def.anchor[1] * k + (Number(off[1]) || 0);
  // viewport centre (zoom focuses on the patch)
  const vcx = (vx + vw / 2) * k, vcy = (vy + vh / 2) * k;
  const fx = zoom > 1 ? ax : vcx, fy = zoom > 1 ? ay : vcy;
  const tx = W / 2 - fx * pxPerMm, ty = H / 2 - fy * pxPerMm;

  if (!o.transparent) {
    const bg = c.createLinearGradient(0, 0, 0, H);
    bg.addColorStop(0, '#f4f6f9'); bg.addColorStop(1, '#e3e8ee');
    c.fillStyle = bg; c.fillRect(0, 0, W, H);
  }
  c.save();
  c.setTransform(pxPerMm * k, 0, 0, pxPerMm * k, tx, ty);
  const sc = pxPerMm * k;
  const g = id === 'cap_front' ? cap(c, hex) : id === 'jacket_back' ? jacket(c, hex) : polo(c, hex, sc);
  c.save();
  c.shadowColor = 'rgba(20,30,50,0.28)'; c.shadowBlur = 30 * sc; c.shadowOffsetY = 12 * sc;
  g.outline(); c.fillStyle = hex; c.fill();
  c.restore();
  c.save();
  g.outline(); c.clip();
  const [bx, by, bw, bh] = g.box;
  // texture in device px: reset transform for the pattern so its scale is in mm
  c.save();
  c.setTransform(1, 0, 0, 1, 0, 0);
  overlayTexture(c, def.tex, pxPerMm, { x: 0, y: 0, w: W, h: H }, lum(hex) < 60 ? 0.55 : 0.4);
  c.restore();
  bodyShade(c, bx, bx + bw, by, by + bh);
  if (id === 'cap_front') {
    const rg = c.createRadialGradient(-30, -90, 10, 0, -40, 150);
    rg.addColorStop(0, 'rgba(255,255,255,0.12)'); rg.addColorStop(1, 'rgba(0,0,0,0.25)');
    c.fillStyle = rg; c.fillRect(bx, by, bw, bh);
  }
  g.details(sc);
  c.restore();
  if (g.after) g.after(sc);
  c.restore();
  return {
    pxPerMm, cx: tx + ax * pxPerMm, cy: ty + ay * pxPerMm, curveMm: def.curve * k, garmentHex: hex,
  };
}

/**
 * Composite a rendered patch (canvas) onto the garment, with a soft contact shadow and,
 * for caps, a cylindrical warp (strips) so the patch follows the curved front.
 */
export function placePatch(c, patch, place, wPx, hPx) {
  const x0 = place.cx - wPx / 2, y0 = place.cy - hPx / 2;
  c.save();
  c.shadowColor = 'rgba(0,0,0,0.45)'; c.shadowBlur = Math.max(2, place.pxPerMm * 1.4); c.shadowOffsetY = Math.max(1, place.pxPerMm * 0.6);
  const R = place.curveMm * place.pxPerMm;
  if (R > 0 && wPx < R * 2.6) {
    const strips = Math.max(8, Math.min(160, Math.round(wPx / 3)));
    const sw = patch.width / strips;
    for (let i = 0; i < strips; i++) {
      const a0 = (i / strips - 0.5) * (wPx / R), a1 = ((i + 1) / strips - 0.5) * (wPx / R);
      const X0 = place.cx + R * Math.sin(a0), X1 = place.cx + R * Math.sin(a1);
      const sh = 1 - 0.05 * Math.abs(a0 + a1); // slight foreshortening towards the sides
      c.drawImage(patch, i * sw, 0, sw, patch.height, X0, y0 + hPx * (1 - sh) / 2, Math.max(0.5, X1 - X0 + 0.4), hPx * sh);
      if (i === 0) { c.shadowColor = 'transparent'; }
    }
    c.restore();
    return { x: place.cx - R * Math.sin(wPx / R / 2), y: y0, w: 2 * R * Math.sin(wPx / R / 2), h: hPx };
  }
  c.drawImage(patch, x0, y0, wPx, hPx);
  c.restore();
  return { x: x0, y: y0, w: wPx, h: hPx };
}
