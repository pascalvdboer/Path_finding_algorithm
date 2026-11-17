/**
 * Node unit tests for the pure parts of the renderer (outlines, contour tracing, label clean-up,
 * stitch kernels, descriptions). Run: node --test plugin/borduur-patch-studio/assets/src/render/tests/
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { shapePolygon, signedArea, bbox, offsetPolygon, measure, pointAt, rayArcLength, contourFromMask, convexHull } from '../../plugin/borduur-patch-studio/assets/src/render/shapes.js';
import { cleanLabels, analyzeComponents, chooseModes, stylizePixels } from '../../plugin/borduur-patch-studio/assets/src/render/stylize.js';
import { describeRender, renderMessages } from '../../plugin/borduur-patch-studio/assets/src/render/index.js';
import { layoutPatch } from '../../plugin/borduur-patch-studio/assets/src/shared/geometry.js';
import { buildPublicConfig } from '../../plugin/borduur-patch-studio/assets/src/shared/config.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const cfgDir = path.resolve(here, '../../plugin/borduur-patch-studio/config');
const defaults = JSON.parse(fs.readFileSync(path.join(cfgDir, 'defaults.json'), 'utf8'));
const palette = JSON.parse(fs.readFileSync(path.join(cfgDir, 'thread-palette.json'), 'utf8'));
const config = buildPublicConfig(defaults, palette, { locale: 'nl-BE' });

test('every shape is a clockwise closed outline that fills its box', () => {
  for (const s of ['circle', 'oval', 'rect', 'rounded', 'shield', 'contour']) {
    const p = shapePolygon(s, 80, 60, { cornerRatio: 0.18 });
    assert.ok(p.length >= 20, s);
    assert.ok(signedArea(p) > 0, s + ' clockwise');
    const b = bbox(p);
    assert.ok(Math.abs(b.x) < 0.5 && Math.abs(b.y) < 0.5 && Math.abs(b.w - 80) < 0.6 && Math.abs(b.h - 60) < 0.6, s + ' box ' + JSON.stringify(b));
  }
  // shield ends in a point at the bottom centre
  const sh = shapePolygon('shield', 60, 80);
  const low = sh.reduce((a, q) => (q[1] > a[1] ? q : a));
  assert.ok(Math.abs(low[0] - 30) < 1.5 && low[1] > 79);
});

test('offsetPolygon moves a circle inward by the given distance', () => {
  const p = shapePolygon('circle', 100, 100);
  const q = offsetPolygon(p, 10);
  for (const v of q) assert.ok(Math.abs(Math.hypot(v[0] - 50, v[1] - 50) - 40) < 0.2);
  const m = measure(q);
  assert.ok(Math.abs(m.total - 2 * Math.PI * 40) < 1);
  const top = pointAt(q, m, rayArcLength(q, m, 50, 50, -Math.PI / 2));
  assert.ok(Math.abs(top.x - 50) < 0.5 && Math.abs(top.y - 10) < 0.5 && top.tx > 0.99, 'top point heads right (clockwise)');
});

test('contour tracing: one blob gives a smooth outline grown by the offset; far apart blobs give null', () => {
  const w = 120, h = 120, m = new Uint8Array(w * h);
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) if (Math.hypot(x - 60, y - 60) < 30) m[y * w + x] = 1;
  const p = contourFromMask(m, w, h, 6, 2);
  assert.ok(p && p.length > 20);
  assert.ok(signedArea(p) > 0);
  const r = p.map((q) => Math.hypot(q[0] - 60, q[1] - 60));
  const avg = r.reduce((a, b) => a + b, 0) / r.length;
  assert.ok(Math.abs(avg - 36) < 2, 'radius ≈ 30 + 6, got ' + avg.toFixed(2));
  const m2 = new Uint8Array(w * h);
  for (let y = 10; y < 30; y++) for (let x = 5; x < 25; x++) m2[y * w + x] = 1;
  for (let y = 80; y < 110; y++) for (let x = 80; x < 110; x++) m2[y * w + x] = 1;
  assert.equal(contourFromMask(m2, w, h, 3, 1), null);
  const hull = convexHull([[0, 0], [10, 0], [10, 10], [0, 10], [5, 5]]);
  assert.equal(hull.length, 4);
  assert.ok(signedArea(hull) > 0);
});

test('cleanLabels removes speckles but keeps real regions', () => {
  const w = 40, h = 40, d = new Uint8Array(w * h).fill(1);
  for (let y = 10; y < 30; y++) for (let x = 10; x < 30; x++) d[y * w + x] = 2;
  d[5 * w + 5] = 3; d[20 * w + 20] = 4; // single-pixel specks
  const c = cleanLabels({ width: w, height: h, data: d });
  assert.equal(c.data[5 * w + 5], 1);
  assert.equal(c.data[20 * w + 20], 2);
  assert.equal(c.data[15 * w + 15], 2);
  assert.equal(c.data[2 * w + 2], 1);
  assert.equal(d[5 * w + 5], 3, 'input untouched');
});

test('components: ring → radial satin, wide area → tatami, thin bar → linear along its axis', () => {
  const w = 200, h = 200, d = new Uint8Array(w * h).fill(255);
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    const r = Math.hypot(x - 100, y - 100);
    if (r > 85 && r < 95) d[y * w + x] = 0;               // ring
    else if (r < 40) d[y * w + x] = 1;                     // disk
    else if (y >= 60 && y < 66 && x > 45 && x < 155 && r > 45 && r < 80) d[y * w + x] = 2; // bars
  }
  const comps = chooseModes(analyzeComponents({ width: w, height: h, data: d }), 0.2, 7);
  const at = (x, y) => comps.comp[y * w + x];
  assert.equal(comps.mode[at(190, 100)], 1, 'ring radial');
  assert.equal(comps.mode[at(100, 100)], 2, 'disk 16 mm wide → tatami');
  const bar = at(70, 62);
  assert.equal(comps.mode[bar], 0);
  assert.ok(Math.abs(comps.cos[bar]) > 0.95, 'bar axis horizontal');
});

test('stylizePixels is deterministic, keeps transparent pixels and is fast enough', () => {
  const W = 400, H = 400;
  const mk = () => {
    const data = new Uint8ClampedArray(W * H * 4);
    for (let i = 0; i < W * H; i++) { const x = i % W, y = (i / W) | 0; const on = Math.hypot(x - 200, y - 200) < 150; data[i * 4] = 200; data[i * 4 + 1] = 40; data[i * 4 + 2] = 40; data[i * 4 + 3] = on ? 255 : 0; }
    return { width: W, height: H, data };
  };
  for (const tech of ['embroidered', 'woven', 'chenille', 'pvc', 'leather']) {
    const a = mk(), b = mk();
    const t0 = performance.now();
    stylizePixels(a, { technique: tech, pxPerMm: 8, single: { mode: 0, cos: 1, sin: 0, cx: 0, cy: 0, r: 1 }, engrave: [70, 40, 20] });
    const ms = performance.now() - t0;
    stylizePixels(b, { technique: tech, pxPerMm: 8, single: { mode: 0, cos: 1, sin: 0, cx: 0, cy: 0, r: 1 }, engrave: [70, 40, 20] });
    assert.deepEqual(a.data, b.data, tech + ' deterministic');
    assert.equal(a.data[3], 0, tech + ' transparent corner stays transparent');
    assert.ok(ms < 60, tech + ' 400² in ' + ms.toFixed(1) + ' ms');
    // texture varies inside the shape (PVC and engraved leather are flat inside: check their bevel/wall at the edge)
    const vals = new Set();
    const flat = tech === 'pvc' || tech === 'leather';
    const [x0, x1] = flat ? [45, 70] : [150, 250];
    for (let x = x0; x < x1; x++) vals.add(a.data[(200 * W + x) * 4]);
    assert.ok(vals.size > (flat ? 1 : 4), tech + ' has texture');
  }
});

test('describeRender gives NL and FR canvas descriptions', () => {
  const design = { technique: 'embroidered', shape: 'circle', sizeCm: 8, options: [], art: { scale: 1, offsetMm: { x: 0, y: 0 }, rotation: 0 },
    texts: [{ id: 'a', text: 'Kalmthout', path: 'straight' }], locale: 'nl-BE' };
  const layout = layoutPatch(design, null, config);
  const nl = describeRender({ design, layout, art: null, view: 'patch' }, config, 'nl-BE');
  assert.match(nl, /^Voorbeeld van je patch: geborduurd, rond, 8 × 8 cm/);
  assert.match(nl, /“Kalmthout”/);
  const fr = describeRender({ design, layout, art: null, view: 'garment', garment: 'cap_front' }, config, 'fr-BE');
  assert.match(fr, /casquette/);
  assert.equal(describeRender({ design: Object.assign({}, design, { texts: [] }), layout, art: null }, config, 'nl-BE'), renderMessages.nl['render.alt.empty']);
  for (const k of Object.keys(renderMessages.nl)) assert.ok(renderMessages.fr[k], 'FR for ' + k);
});
