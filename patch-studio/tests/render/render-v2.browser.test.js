/**
 * Browser tests for render v2 (docs/handoff/render-v2.md): light direction, technique comparer, backpack
 * mockup, quality budgets at 1200 px (high ≤ 150 ms once analysed, fast ≤ 25 ms), 2D/3D PVC.
 * Skipped automatically when Playwright/Chromium/esbuild are not available.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { openHarness } from './harness.mjs';

let H = null, skip = false;
test.before(async () => {
  H = await openHarness();
  if (H.skip) { skip = H.skip; H = null; return; }
  await H.page.evaluate(async () => {
    window.A = { round: await T.analyzeUrl('/img/eigenaar-ronde-patch.png', CFG) };
    window.px = (cv) => cv.getContext('2d').getImageData(0, 0, cv.width, cv.height).data;
    window.diff = (a, b) => { let s = 0; for (let i = 0; i < a.length; i += 4) s += Math.abs(a[i] - b[i]) + Math.abs(a[i + 1] - b[i + 1]) + Math.abs(a[i + 2] - b[i + 2]); return s / (a.length / 4) / 3; };
  });
});
test.after(async () => { if (H) await H.close(); });

test('light direction changes the sheen (and is deterministic)', async (t) => {
  if (skip) return t.skip(skip);
  const r = await H.page.evaluate(() => {
    const opts = T.makeOpts(CFG, A.round, { technique: 'embroidered', pxSize: 500 });
    const cv = document.createElement('canvas'); cv.width = cv.height = 500;
    const R = T.R.createRenderer(cv, { config: CFG });
    const a = (R.draw(Object.assign({}, opts, { light: { x: -0.7, y: -0.7 } })), px(cv).slice());
    const a2 = (R.draw(Object.assign({}, opts, { light: { x: -0.7, y: -0.7 } })), px(cv).slice());
    const res = R.draw(Object.assign({}, opts, { light: { x: 0.8, y: 0.4 } }));
    const b = px(cv).slice();
    const n1 = T.R.normLight({ x: -0.7, y: -0.7 }), n2 = T.R.normLight({ azimuthDeg: -135, elevationDeg: 40 });
    return { same: diff(a, a2), moved: diff(a, b), light: res.light, n1: n1.L.map((v) => +v.toFixed(2)), n2: n2.key };
  });
  assert.equal(r.same, 0, 'same light → identical pixels');
  assert.ok(r.moved > 1.5, 'another light direction visibly changes the render: ' + r.moved);
  assert.ok(r.light && Number.isFinite(r.light.azimuthDeg), 'RenderResult reports the light');
  assert.ok(r.n1[0] < 0 && r.n1[1] < 0 && r.n1[2] > 0.3, 'light from the top left: ' + r.n1);
  assert.equal(r.n2, '225/39');
});

test('renderTechniques: one card per technique, all different, each with a fitting border', async (t) => {
  if (skip) return t.skip(skip);
  const r = await H.page.evaluate(() => {
    const opts = T.makeOpts(CFG, A.round, { technique: 'embroidered', pxSize: 200 });
    const list = T.R.renderTechniques(opts, { size: 160, config: CFG });
    const data = list.map((x) => { const c = document.createElement('canvas'); c.width = c.height = 160; c.getContext('2d').drawImage(x.canvas, 0, 0); return px(c); });
    let minDiff = 1e9;
    for (let i = 0; i < data.length; i++) for (let j = i + 1; j < data.length; j++) minDiff = Math.min(minDiff, diff(data[i], data[j]));
    return { techs: list.map((x) => x.technique), edges: list.map((x) => x.edge), sizes: list.map((x) => [x.canvas.width, x.canvas.height]), minDiff };
  });
  assert.deepEqual(r.techs, ['embroidered', 'woven', 'printed', 'sublimated', 'pvc', 'chenille', 'leather']);
  assert.equal(r.edges[4], 'molded'); assert.equal(r.edges[5], 'felt_border'); assert.ok(['laser'].includes(r.edges[6]));
  for (const s of r.sizes) assert.deepEqual(s, [160, 160]);
  assert.ok(r.minDiff > 2, 'every technique looks different: ' + r.minDiff);
  assert.deepEqual(H.errors, []);
});

test('garment mockups: backpack added, all four lit without errors', async (t) => {
  if (skip) return t.skip(skip);
  const r = await H.page.evaluate(() => {
    const out = {};
    for (const g of T.R.GARMENTS) {
      const opts = T.makeOpts(CFG, A.round, { technique: 'embroidered', view: 'garment', garment: g, pxSize: 400 });
      const cv = document.createElement('canvas'); cv.width = cv.height = 400;
      const res = T.R.createRenderer(cv, { config: CFG }).draw(opts);
      const d = px(cv);
      const i = (Math.round(res.originPx.y + 10 * res.pxPerMm) * 400 + Math.round(res.originPx.x + 40 * res.pxPerMm)) * 4;
      out[g] = { garment: res.garment, ppm: res.pxPerMm, alpha: d[i + 3] };
    }
    return out;
  });
  assert.deepEqual(Object.keys(r), ['polo_chest', 'cap_front', 'jacket_back', 'backpack_front']);
  for (const [g, x] of Object.entries(r)) { assert.equal(x.garment, g); assert.ok(x.ppm > 0); assert.equal(x.alpha, 255); }
  assert.ok(r.backpack_front.ppm > r.polo_chest.ppm, 'backpack is smaller than a polo → larger scale');
});

test('PVC 3D is sculpted differently from 2D', async (t) => {
  if (skip) return t.skip(skip);
  const d = await H.page.evaluate(() => {
    const opts = T.makeOpts(CFG, A.round, { technique: 'pvc', pxSize: 400 });
    const cv = document.createElement('canvas'); cv.width = cv.height = 400;
    const R = T.R.createRenderer(cv, { config: CFG });
    R.draw(opts); const a = px(cv).slice();
    R.draw(Object.assign({}, opts, { pvcDepth: '3d' })); const b = px(cv);
    return diff(a, b);
  });
  assert.ok(d > 1, 'pvcDepth 3d changes the relief: ' + d);
});

test('budgets at 1200 px: high ≤ 150 ms after a drag, fast ≤ 25 ms (drag, zoom, pointer light)', async (t) => {
  if (skip) return t.skip(skip);
  const r = await H.page.evaluate(() => {
    const flush = (cv) => cv.getContext('2d').getImageData(0, 0, 1, 1);
    const out = {};
    for (const tech of ['embroidered', 'woven', 'printed', 'sublimated', 'pvc', 'chenille', 'leather']) {
      const opts = T.makeOpts(CFG, A.round, { technique: tech, pxSize: 1200 });
      const cv = document.createElement('canvas'); cv.width = cv.height = 1200;
      const R = T.R.createRenderer(cv, { config: CFG });
      let t0 = performance.now(); R.draw(opts); flush(cv); const cold = performance.now() - t0;
      const high = [], fast = [], relight = [];
      for (let i = 1; i <= 5; i++) {
        const d = Object.assign({}, opts.design, { art: { scale: 1, offsetMm: { x: i * 0.8, y: -i * 0.5 }, rotation: 0 } });
        const layout = T.layoutPatch(d, A.round, CFG);
        t0 = performance.now(); R.draw(Object.assign({}, opts, { design: d, layout, quality: 'fast', zoom: 1 + i * 0.05 })); flush(cv); fast.push(performance.now() - t0);
        t0 = performance.now(); R.draw(Object.assign({}, opts, { design: d, layout, quality: 'fast', light: { x: -0.6 + i * 0.2, y: -0.6 } })); flush(cv); fast.push(performance.now() - t0);
        t0 = performance.now(); R.draw(Object.assign({}, opts, { design: d, layout })); flush(cv); high.push(performance.now() - t0);
        t0 = performance.now(); R.draw(Object.assign({}, opts, { design: d, layout, light: { x: -0.6 + i * 0.25, y: -0.5 } })); flush(cv); relight.push(performance.now() - t0);
      }
      const med = (a) => a.sort((x, y) => x - y)[a.length >> 1];
      out[tech] = { cold: Math.round(cold), high: Math.round(med(high)), relight: Math.round(med(relight)), fast: Math.round(med(fast)) };
    }
    return out;
  });
  console.log('render v2 ms @1200', JSON.stringify(r));
  for (const [tech, x] of Object.entries(r)) {
    assert.ok(x.high <= 150, tech + ' high ' + x.high);
    // full-quality relight (new light direction, every layer re-shaded): ~100–140 ms here (headless Chromium,
    // software GL, tests running in parallel); generous bound against regressions only
    assert.ok(x.relight <= 260, tech + ' relight ' + x.relight);
    assert.ok(x.cold <= 900, tech + ' cold ' + x.cold);
    assert.ok(x.fast <= 25, tech + ' fast ' + x.fast);
  }
});
