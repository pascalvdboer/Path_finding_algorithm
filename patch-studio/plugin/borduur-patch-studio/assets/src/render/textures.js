/**
 * Patch Studio — procedural, seamless grey texture tiles (128 = neutral) applied with 'overlay'.
 * Generated once per (kind, scale) and cached. No images are downloaded.
 */
import { makeCanvas, ctx2d, prng, Lru } from './util.js';

const cache = new Lru(40);

function tileSize(kind, ppm) {
  switch (kind) {
    case 'twill': return Math.max(4, Math.round(ppm * 0.7)) * 4;
    case 'poly': return Math.max(3, Math.round(ppm * 0.35)) * 4;
    case 'tatami': return 0; // special
    case 'weave': return Math.max(2, Math.round(ppm * 0.3)) * 2 * 4;
    case 'pique': return Math.max(3, Math.round(ppm * 1.1)) * 2;
    case 'ripstop': return Math.max(8, Math.round(ppm * 5));
    default: return Math.max(32, Math.min(192, Math.round(ppm * 12)));
  }
}

/**
 * @param {'twill'|'poly'|'tatami'|'weave'|'felt'|'grain'|'pvc'|'pique'|'ripstop'|'knit'} kind
 * @param {number} ppm  px per mm
 */
export function textureTile(kind, ppm) {
  const q = Math.max(0.5, Math.round(ppm * 4) / 4);
  const key = kind + '|' + q;
  const hit = cache.get(key);
  if (hit) return hit;
  let w, h;
  if (kind === 'tatami') {
    w = Math.max(6, Math.round(q * 2.6)); h = Math.max(3, Math.round(q * 0.45)) * 3;
  } else { w = h = tileSize(kind, q); }
  const cv = makeCanvas(w, h), c = ctx2d(cv);
  const im = c.createImageData(w, h), d = im.data;
  const rnd = prng(kind.length * 7919 + (q * 100) | 0);
  const set = (i, v) => { v = v < 0 ? 0 : v > 255 ? 255 : v; d[i * 4] = d[i * 4 + 1] = d[i * 4 + 2] = v; d[i * 4 + 3] = 255; };
  if (kind === 'twill' || kind === 'poly') {
    // diagonal 2/1 twill ribs
    const per = w / 4, amp = kind === 'twill' ? 46 : 26;
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      const t = (((x + y) % per) + per) % per / per;
      set(y * w + x, 128 + amp * Math.sin(Math.PI * 2 * t) + (rnd() - 0.5) * 14);
    }
  } else if (kind === 'tatami') {
    const rowH = h / 3;
    for (let y = 0; y < h; y++) {
      const row = Math.floor(y / rowH), t = (y % rowH) / rowH;
      for (let x = 0; x < w; x++) {
        const u = ((x / w + row / 3) % 1);
        let v = 128 + 40 * Math.sin(Math.PI * t) - 20;
        if (u < 0.08) v -= 34;
        set(y * w + x, v + (rnd() - 0.5) * 10);
      }
    }
  } else if (kind === 'weave') {
    const cell = w / 8;
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      const gx = Math.floor(x / cell), gy = Math.floor(y / cell);
      const t = (gx + gy) & 1 ? (y % cell) / cell : (x % cell) / cell;
      set(y * w + x, 128 + 34 * Math.sin(Math.PI * t) - 14);
    }
  } else if (kind === 'pique') {
    const cell = w / 2;
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      const cx = (Math.floor(x / cell) + 0.5) * cell, cy = (Math.floor(y / cell) + 0.5) * cell + ((Math.floor(x / cell) & 1) ? cell / 2 : 0);
      const dy = ((y - cy + h * 2) % cell) - cell / 2;
      const dd = Math.hypot(x - cx, dy) / (cell * 0.5);
      set(y * w + x, 128 + 30 * Math.cos(Math.min(1, dd) * Math.PI) - 6 + (rnd() - 0.5) * 8);
    }
  } else if (kind === 'ripstop') {
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      const gx = x % w < 2 || y % h < 2 ? 18 : 0;
      set(y * w + x, 128 + gx + ((x + y) & 1 ? 6 : -6));
    }
  } else {
    // value noise based textures: felt, grain (leather), pvc (matte), knit
    const n = 6 + (kind === 'grain' ? 10 : kind === 'felt' ? 16 : 4);
    const grid = new Float32Array(n * n);
    for (let i = 0; i < grid.length; i++) grid[i] = rnd();
    const sample = (u, v) => {
      const x = u * n, y = v * n, x0 = Math.floor(x), y0 = Math.floor(y), fx = x - x0, fy = y - y0;
      const g = (a, b) => grid[((b % n + n) % n) * n + ((a % n + n) % n)];
      const sx = fx * fx * (3 - 2 * fx), sy = fy * fy * (3 - 2 * fy);
      return (g(x0, y0) * (1 - sx) + g(x0 + 1, y0) * sx) * (1 - sy) + (g(x0, y0 + 1) * (1 - sx) + g(x0 + 1, y0 + 1) * sx) * sy;
    };
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      const u = x / w, v = y / h;
      let val;
      if (kind === 'grain') {
        const a = sample(u, v), b = sample(u * 2 + 0.3, v * 2 + 0.7);
        const cell = Math.abs(a - 0.5) * 2; // ridge-like grain lines
        val = 128 + (cell < 0.12 ? -38 : 0) + (b - 0.5) * 34 + (rnd() - 0.5) * 12;
      } else if (kind === 'felt') {
        val = 128 + (sample(u, v) - 0.5) * 40 + (rnd() - 0.5) * 46;
      } else if (kind === 'knit') {
        val = 128 + 22 * Math.sin((x / w) * Math.PI * 2 * 16) * Math.sin((y / h) * Math.PI * 2 * 24) + (rnd() - 0.5) * 10;
      } else {
        val = 128 + (sample(u, v) - 0.5) * 14 + (rnd() - 0.5) * 6;
      }
      set(y * w + x, val);
    }
  }
  c.putImageData(im, 0, 0);
  return cache.set(key, cv);
}

/**
 * Fill the current clip with a texture tile in overlay mode.
 * @param {CanvasRenderingContext2D} c
 * @param {string} kind
 * @param {number} ppm
 * @param {{x:number,y:number,w:number,h:number}} box   area to cover
 * @param {number} alpha
 * @param {number} [angle] radians
 */
export function overlayTexture(c, kind, ppm, box, alpha, angle = 0) {
  const tile = textureTile(kind, ppm);
  const pat = c.createPattern(tile, 'repeat');
  if (!pat) return;
  c.save();
  c.globalCompositeOperation = 'overlay';
  c.globalAlpha = alpha;
  c.fillStyle = pat;
  if (angle) {
    const cx = box.x + box.w / 2, cy = box.y + box.h / 2, r = Math.hypot(box.w, box.h) / 2 + 2;
    c.translate(cx, cy); c.rotate(angle);
    c.fillRect(-r, -r, 2 * r, 2 * r);
  } else {
    c.fillRect(box.x, box.y, box.w, box.h);
  }
  c.restore();
}
