/**
 * Browser tests (Playwright + Chromium) for the renderer with the owner's reference images analysed by the
 * real analysis module: every technique / edge / shape / garment renders without errors, the patch is never
 * empty or cropped, PNG export is transparent and cropped to the patch, timing budgets (SPEC §5.3).
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
    window.A = {
      round: await T.analyzeUrl('/img/eigenaar-ronde-patch.png', CFG),
      photo: await T.analyzeUrl('/img/eigenaar-foto-patch.jpg', CFG),
      art: await T.analyzeUrl('/img/testbeelden/abstract-geometric-art.webp', CFG),
    };
    // pixel statistics helper: share of opaque px, and whether anything touches the canvas border
    window.stats = (cv) => {
      const d = cv.getContext('2d').getImageData(0, 0, cv.width, cv.height).data;
      let op = 0, border = 0;
      for (let y = 0; y < cv.height; y++) for (let x = 0; x < cv.width; x++) {
        const a = d[(y * cv.width + x) * 4 + 3];
        if (a > 0) { op++; if (x === 0 || y === 0 || x === cv.width - 1 || y === cv.height - 1) border++; }
      }
      return { opaque: op / (cv.width * cv.height), border };
    };
  });
});
test.after(async () => { if (H) await H.close(); });

test('all techniques × edges × shapes render without errors', async (t) => {
  if (skip) return t.skip(skip);
  const res = await H.page.evaluate(() => {
    const out = [];
    const combos = [];
    for (const tech of ['embroidered', 'woven', 'printed', 'sublimated', 'pvc', 'chenille', 'leather']) {
      const edges = CFG.options.filter((o) => o.group === 'edge' && o.techniques.includes(tech)).map((o) => o.id);
      for (const edge of edges) for (const shape of ['circle', 'oval', 'rect', 'rounded', 'shield', 'contour']) combos.push({ tech, edge, shape });
    }
    for (const c of combos) {
      const opts = T.makeOpts(CFG, A.art, { technique: c.tech, edge: c.edge, shape: c.shape, aspect: 1.4, pxSize: 300,
        texts: [{ id: 'x', text: 'TEST', font: 'Sora', heightMm: 6, path: 'arc_bottom', angleDeg: 0, insetMm: 2, letterSpacing: 0 }] });
      const cv = document.createElement('canvas'); cv.width = cv.height = 300;
      const r = T.R.createRenderer(cv, { config: CFG }).draw(opts);
      const s = stats(cv);
      out.push({ c, ok: r.pxPerMm > 0 && s.opaque > 0.99 });
    }
    return out;
  });
  assert.ok(res.length >= 60, 'combos: ' + res.length);
  for (const r of res) assert.ok(r.ok, JSON.stringify(r.c));
  assert.deepEqual(H.errors, []);
});

test('PNG export: transparent background, only the patch, longest side = size', async (t) => {
  if (skip) return t.skip(skip);
  const res = await H.page.evaluate(async () => {
    const out = {};
    for (const [name, o] of Object.entries({ round: { technique: 'embroidered' }, rect: { technique: 'printed', shape: 'rect', aspect: 1.6 }, uncut: { technique: 'embroidered', edge: 'self_cut' } })) {
      const opts = T.makeOpts(CFG, A.round, o);
      const cv = document.createElement('canvas'); cv.width = cv.height = 500;
      const blob = await T.R.createRenderer(cv, { config: CFG }).toBlob(opts, { size: 1200 });
      const bmp = await createImageBitmap(blob);
      const c2 = document.createElement('canvas'); c2.width = bmp.width; c2.height = bmp.height;
      const x = c2.getContext('2d'); x.drawImage(bmp, 0, 0);
      const corner = x.getImageData(0, 0, 1, 1).data[3], centre = x.getImageData(bmp.width >> 1, bmp.height >> 1, 1, 1).data[3];
      out[name] = { type: blob.type, w: bmp.width, h: bmp.height, corner, centre, stats: stats(c2) };
    }
    return out;
  });
  assert.equal(res.round.type, 'image/png');
  assert.equal(Math.max(res.round.w, res.round.h), 1200);
  assert.equal(res.round.corner, 0, 'transparent corner');
  assert.equal(res.round.centre, 255);
  assert.ok(res.round.stats.opaque > 0.7 && res.round.stats.opaque < 0.85, 'round patch fills ≈ π/4 of the export: ' + res.round.stats.opaque);
  assert.ok(res.rect.w > res.rect.h * 1.4, 'rect export follows the patch aspect');
  assert.ok(res.uncut.stats.opaque > 0.9, 'self-cut export includes its fabric piece');
});

test('design is never cropped: contain inside the patch at 100 %', async (t) => {
  if (skip) return t.skip(skip);
  const r = await H.page.evaluate(() => {
    const out = [];
    for (const [k, a] of Object.entries(A)) for (const shape of ['circle', 'oval', 'rect', 'shield']) {
      const opts = T.makeOpts(CFG, a, { technique: 'printed', shape, aspect: 1.3 });
      const L = opts.layout, rr = L.artRectMm;
      out.push({ k, shape, inside: rr.x >= -0.01 && rr.y >= -0.01 && rr.x + rr.w <= L.patchWmm + 0.01 && rr.y + rr.h <= L.patchHmm + 0.01 });
    }
    return out;
  });
  for (const x of r) assert.ok(x.inside, JSON.stringify(x));
});

test('garment views: polo, cap and jacket at true scale', async (t) => {
  if (skip) return t.skip(skip);
  const res = await H.page.evaluate(() => {
    const out = {};
    for (const g of ['polo_chest', 'cap_front', 'jacket_back']) {
      const opts = T.makeOpts(CFG, A.round, { technique: 'embroidered', view: 'garment', garment: g, sizeCm: 8, pxSize: 600 });
      const cv = document.createElement('canvas'); cv.width = cv.height = 600;
      const r = T.R.createRenderer(cv, { config: CFG }).draw(opts);
      out[g] = { ppm: r.pxPerMm, view: r.view, garment: r.garment, ref: CFG.mockups[g].scaleRefMm };
    }
    return out;
  });
  for (const [g, r] of Object.entries(res)) {
    assert.equal(r.view, 'garment'); assert.equal(r.garment, g);
    assert.ok(r.ppm * r.ref < 600 && r.ppm * r.ref > 150, g + ' garment reference width fits the canvas');
  }
  assert.ok(res.cap_front.ppm > res.polo_chest.ppm && res.polo_chest.ppm > res.jacket_back.ppm, 'scales follow the garment size');
});

test('performance: ≤ 50 ms per render at 1000 px (cached layers), fast mode ≤ 16 ms', async (t) => {
  if (skip) return t.skip(skip);
  const r = await H.page.evaluate(() => {
    const flush = (cv) => cv.getContext('2d').getImageData(0, 0, 1, 1);
    const out = {};
    for (const tech of ['embroidered', 'woven', 'printed', 'sublimated', 'pvc', 'chenille', 'leather']) {
      const opts = T.makeOpts(CFG, A.round, { technique: tech, pxSize: 1000 });
      const cv = document.createElement('canvas'); cv.width = cv.height = 1000;
      const R = T.R.createRenderer(cv, { config: CFG });
      let t0 = performance.now(); R.draw(opts); flush(cv); const cold = performance.now() - t0;
      const times = [], fast = [];
      for (let i = 1; i <= 6; i++) {
        const d = Object.assign({}, opts.design, { art: { scale: 1, offsetMm: { x: i * 0.7, y: -i * 0.4 }, rotation: 0 } });
        const layout = T.layoutPatch(d, A.round, CFG);
        t0 = performance.now(); R.draw(Object.assign({}, opts, { design: d, layout })); flush(cv); times.push(performance.now() - t0);
        t0 = performance.now(); R.draw(Object.assign({}, opts, { design: d, layout, quality: 'fast' })); flush(cv); fast.push(performance.now() - t0);
      }
      times.sort((a, b) => a - b); fast.sort((a, b) => a - b);
      out[tech] = { cold: Math.round(cold), median: times[3], fast: fast[3] };
    }
    return out;
  });
  console.log('render ms', JSON.stringify(r));
  for (const [tech, x] of Object.entries(r)) {
    assert.ok(x.median <= 50, tech + ' median ' + x.median);
    assert.ok(x.fast <= 16, tech + ' fast ' + x.fast);
    assert.ok(x.cold <= 400, tech + ' cold ' + x.cold);
  }
});

test('arc text stays inside the border and redraw after fonts load', async (t) => {
  if (skip) return t.skip(skip);
  const ok = await H.page.evaluate(async () => {
    const opts = T.makeOpts(CFG, null, { technique: 'embroidered', shape: 'circle', sizeCm: 6, fabricHex: '#1b2a4a', edgeHex: '#1b2a4a', pxSize: 600,
      texts: [{ id: 'a', text: 'WWWWWWWWWW', font: 'Sora', heightMm: 6, path: 'arc_top', angleDeg: 0, insetMm: 0, letterSpacing: 0, hex: '#ffffff' }] });
    opts.textHex = { a: '#ffffff' };
    const cv = document.createElement('canvas'); cv.width = cv.height = 600;
    const R = T.R.createRenderer(cv, { config: CFG });
    const r = R.draw(opts);
    // no white text pixels inside the border band (outer 2.5 mm of the 60 mm patch)
    const d = cv.getContext('2d').getImageData(0, 0, 600, 600).data;
    const cx = r.originPx.x + 30 * r.pxPerMm, cy = r.originPx.y + 30 * r.pxPerMm;
    let bad = 0;
    for (let y = 0; y < 600; y++) for (let x = 0; x < 600; x++) {
      const rr = Math.hypot(x - cx, y - cy) / r.pxPerMm;
      const i = (y * 600 + x) * 4;
      if (rr > 27.8 && rr < 29.5 && d[i] > 200 && d[i + 1] > 200 && d[i + 2] > 200) bad++;
    }
    return bad;
  });
  assert.equal(ok, 0);
});

test('kept file background (raw analysis.backdrop) fills the patch up to the edge', async (t) => {
  if (skip) return t.skip(skip);
  const r = await H.page.evaluate(async () => {
    const c = new OffscreenCanvas(600, 400), x = c.getContext('2d');
    x.fillStyle = '#1f6f3a'; x.fillRect(0, 0, 600, 400);
    x.fillStyle = '#f2c230'; x.fillRect(180, 120, 60, 160); x.fillRect(180, 230, 200, 50);
    x.fillStyle = '#ffffff'; x.fillRect(300, 120, 50, 50); x.fillRect(380, 150, 40, 40);
    const url = URL.createObjectURL(await c.convertToBlob({ type: 'image/png' }));
    const a = await T.analyzeUrl(url, CFG);
    const opts = T.makeOpts(CFG, a, { technique: 'printed', shape: 'circle', fabricHex: '#ffffff', pxSize: 500 });
    // feed the backdrop exactly the way ui/derive.js does (raw pixels)
    opts.art = { image: T.R.artFromAnalysis(a), labels: null, paletteHex: a.palette.map((p) => p.hex), reducedMap: null, reducedHex: null, backdrop: a.backdrop || null };
    opts.backgroundHex = null;
    const cv = document.createElement('canvas'); cv.width = cv.height = 500;
    const res = T.R.createRenderer(cv, { config: CFG }).draw(opts);
    // a point just inside the border, upper-left diagonal, outside the original file area
    const L = opts.layout, ppm = res.pxPerMm;
    const ang = -Math.PI * 0.75, rad = L.patchWmm / 2 - 4;
    const px = res.originPx.x + (L.patchWmm / 2 + Math.cos(ang) * rad) * ppm, py = res.originPx.y + (L.patchHmm / 2 + Math.sin(ang) * rad) * ppm;
    const d = cv.getContext('2d').getImageData(Math.round(px), Math.round(py), 1, 1).data;
    return { keep: a.background.keep, hasBackdrop: !!a.backdrop, rgb: [d[0], d[1], d[2]] };
  });
  assert.ok(r.keep && r.hasBackdrop, 'analysis keeps the drawn background: ' + JSON.stringify(r));
  assert.ok(r.rgb[1] > r.rgb[0] + 20 && r.rgb[1] > r.rgb[2], 'green background reaches the patch edge, got ' + r.rgb);
});
