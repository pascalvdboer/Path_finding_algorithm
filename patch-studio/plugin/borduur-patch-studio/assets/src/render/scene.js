/**
 * Patch Studio — soft scene behind the close-up: a calm, slightly textured surface lit from the same side
 * as the patch (studio paper, linen or dark slate). Procedural, cached per size/light/kind; no images.
 */
import { makeCanvas, ctx2d, prng, Lru } from './util.js';

const SCENES = {
  studio: { top: '#f7f8fa', mid: '#eceff3', low: '#dfe4ea', grain: 0.035, weave: 0 },
  linen: { top: '#f4efe7', mid: '#e9e2d6', low: '#d9d0c1', grain: 0.05, weave: 0.10 },
  slate: { top: '#3a4150', mid: '#2a303c', low: '#1b2029', grain: 0.06, weave: 0 },
};
export const SCENE_KINDS = Object.keys(SCENES);

const cache = new Lru(4);
let tiles = {};

function grainTile(kind) {
  if (tiles[kind]) return tiles[kind];
  const s = 256, cv = makeCanvas(s, s), c = ctx2d(cv);
  const im = c.createImageData(s, s), d = im.data, rnd = prng(kind.length * 977 + 3);
  const def = SCENES[kind];
  // linen: irregular slubs in warp and weft; others: fine paper grain
  const rowN = new Float32Array(s), colN = new Float32Array(s);
  for (let i = 0; i < s; i++) { rowN[i] = rnd(); colN[i] = rnd(); }
  for (let y = 0; y < s; y++) for (let x = 0; x < s; x++) {
    let v = (rnd() - 0.5) * 2;
    if (def.weave) {
      const wx = Math.sin((x / s) * Math.PI * 2 * 64), wy = Math.sin((y / s) * Math.PI * 2 * 64);
      v = v * 0.5 + ((x + y) & 1 ? wx : wy) * 0.6 + (rowN[y] - 0.5) * 1.2 + (colN[x] - 0.5) * 0.9;
    }
    const g = 128 + v * 40;
    d[(y * s + x) * 4] = d[(y * s + x) * 4 + 1] = d[(y * s + x) * 4 + 2] = g; d[(y * s + x) * 4 + 3] = 255;
  }
  c.putImageData(im, 0, 0);
  return (tiles[kind] = cv);
}

/**
 * @param {CanvasRenderingContext2D} c
 * @param {object} light  light.js#normLight()
 * @param {string} [kind] studio | linen | slate
 */
export function drawBackdrop(c, W, H, light, kind) {
  const k = SCENES[kind] ? kind : 'studio';
  // the light pool only follows the light coarsely (8 directions): cheap while the pointer moves
  const oct = Math.round(light.az / (Math.PI / 4));
  const key = [k, W, H, oct].join('#');
  let cv = cache.get(key);
  if (!cv) {
    cv = makeCanvas(W, H);
    const s = ctx2d(cv), def = SCENES[k];
    // light pool on the side of the light, falling off towards the opposite corner
    const a = oct * Math.PI / 4;
    const lx = W / 2 + Math.cos(a) * W * 0.32, ly = H / 2 + Math.sin(a) * H * 0.32;
    const g = s.createRadialGradient(lx, ly, Math.min(W, H) * 0.04, W / 2, H / 2, Math.hypot(W, H) * 0.62);
    g.addColorStop(0, def.top); g.addColorStop(0.55, def.mid); g.addColorStop(1, def.low);
    s.fillStyle = g; s.fillRect(0, 0, W, H);
    const pat = s.createPattern(grainTile(k), 'repeat');
    if (pat) {
      s.save(); s.globalCompositeOperation = 'overlay'; s.globalAlpha = def.grain * 4; s.fillStyle = pat;
      const sc = Math.max(1, Math.min(W, H) / 900);
      s.scale(sc, sc); s.fillRect(0, 0, W / sc, H / sc); s.restore();
    }
    // soft vignette
    const v = s.createRadialGradient(W / 2, H / 2, Math.min(W, H) * 0.35, W / 2, H / 2, Math.hypot(W, H) * 0.6);
    v.addColorStop(0, 'rgba(0,0,0,0)'); v.addColorStop(1, k === 'slate' ? 'rgba(0,0,0,0.35)' : 'rgba(20,30,50,0.10)');
    s.fillStyle = v; s.fillRect(0, 0, W, H);
    cache.set(key, cv);
  }
  c.drawImage(cv, 0, 0);
}
