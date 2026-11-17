/**
 * Browser tests (Playwright + Chromium): real decoding (createImageBitmap, SVG rasterising), the Web Worker
 * protocol, the main-thread fallback, and the owner's reference images (BRIEF):
 *   eigenaar-ronde-patch.png → illustration + mock-up, round, fills the patch (never "photo", never cropped)
 *   eigenaar-foto-patch.jpg  → phone photo of a real patch: cut out, round, isPatchPhoto
 * Skipped automatically when Playwright/Chromium is not available.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const src = path.join(root, 'plugin/borduur-patch-studio/assets/src/analysis');
const ref = path.join(root, 'reference');
const require = createRequire(import.meta.url);

let chromium = null, esbuild = null;
try { ({ chromium } = require('playwright')); } catch (e) { /* skipped below */ }
try { esbuild = await import('esbuild'); } catch (e) { /* skipped below */ }

const defaults = JSON.parse(fs.readFileSync(path.join(root, 'plugin/borduur-patch-studio/config/defaults.json'), 'utf8'));
const MIME = { '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.js': 'text/javascript', '.html': 'text/html' };

let browser = null, page = null, tmp = null, skip = !chromium || !esbuild ? 'playwright or esbuild not available' : false;

test.before(async () => {
  if (skip) return;
  try { browser = await chromium.launch(); } catch (e) { skip = 'chromium not available: ' + e.message; return; }
  tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'bps-analysis-'));
  const common = { bundle: true, format: 'iife', target: ['es2019', 'safari14'], logLevel: 'silent' };
  await esbuild.build({ ...common, entryPoints: [path.join(src, 'worker.js')], outfile: path.join(tmp, 'worker.js') });
  fs.writeFileSync(path.join(tmp, 'entry.js'), `import { decodeFile, createAnalyzer } from ${JSON.stringify(path.join(src, 'index.js'))}; window.T = { decodeFile, createAnalyzer };`);
  await esbuild.build({ ...common, entryPoints: [path.join(tmp, 'entry.js')], outfile: path.join(tmp, 'api.js') });
  page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.errors = errors;
  await page.route('http://bps.test/**', (route) => {
    const u = new URL(route.request().url());
    let file = null;
    if (u.pathname === '/' || u.pathname === '/index.html') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><script src="/api.js"></script>' });
    if (u.pathname.startsWith('/img/')) file = path.join(ref, decodeURIComponent(u.pathname.slice(5)));
    else file = path.join(tmp, path.basename(u.pathname));
    if (!fs.existsSync(file)) return route.fulfill({ status: 404, body: 'nf' });
    return route.fulfill({ contentType: MIME[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file) });
  });
  await page.goto('http://bps.test/');
});

test.after(async () => {
  if (browser) await browser.close();
  if (tmp) fs.rmSync(tmp, { recursive: true, force: true });
});

/** Decode + analyse an image file in the page through the real worker. */
async function analyse(file, { forceMainThread = false } = {}) {
  return page.evaluate(async ({ file, config, forceMainThread }) => {
    const blob = await (await fetch('/img/' + file)).blob();
    const f = new File([blob], file.split('/').pop(), { type: blob.type });
    const decoded = await window.T.decodeFile(f, config);
    const a = window.T.createAnalyzer({ workerUrl: '/worker.js', config, forceMainThread });
    const stages = [];
    const t0 = performance.now();
    const r = await a.analyze(decoded, { job: 1, onProgress: (p) => stages.push(p.stage) });
    const ms = Math.round(performance.now() - t0);
    const mode = a.mode();
    a.terminate();
    const corner = r.preview.data[3];
    return { kind: r.kind, isMockup: r.isMockup, isPatchPhoto: r.isPatchPhoto, shape: r.shape, background: r.background, warnings: r.warnings,
      palette: r.palette.length, textLines: r.textLines.length, artBox: r.artBox, src: r.src, pxPerSrcPx: r.pxPerSrcPx, preview: { w: r.preview.width, h: r.preview.height, corner },
      labelsOk: r.labels.length === r.preview.width * r.preview.height, stages, mode, ms, decodedKind: decoded.kind };
  }, { file, config: defaults, forceMainThread });
}

test('owner round patch PNG (mock-up): illustration, round, fills the patch, analysed in the worker', async (t) => {
  if (skip) return t.skip(skip);
  const r = await analyse('eigenaar-ronde-patch.png');
  assert.equal(r.decodedKind, 'raster');
  assert.equal(r.mode, 'worker');
  assert.deepEqual(r.stages, ['background', 'shape', 'colors', 'details', 'preview']);
  assert.equal(r.kind, 'illustration');
  assert.equal(r.isMockup, true);
  assert.ok(r.warnings.includes('mockup'));
  assert.equal(r.shape.type, 'circle');
  assert.equal(r.shape.fillsPatch, true);
  assert.equal(r.background.keep, false, 'navy presentation background is not the patch');
  assert.equal(r.preview.corner, 0, 'outside the round patch is transparent');
  assert.ok(r.labelsOk);
  assert.ok(r.palette >= 6 && r.palette <= 16);
  t.diagnostic(`worker analysis ${r.ms} ms (desktop Chromium)`);
});

test('owner phone photo of a printed patch: cut out of the photo, round, patch photo, not "photo"', async (t) => {
  if (skip) return t.skip(skip);
  const r = await analyse('eigenaar-foto-patch.jpg');
  assert.equal(r.shape.type, 'circle');
  assert.equal(r.shape.fillsPatch, true);
  assert.equal(r.isPatchPhoto, true);
  assert.ok(r.warnings.includes('patch_photo_cut'));
  assert.notEqual(r.kind, 'photo');
  assert.equal(r.background.keep, false);
  assert.ok(Math.abs(r.artBox.w - r.artBox.h) <= 1);
  // the circle almost touches the image sides: its source diameter ≈ image width
  const diameter = r.artBox.w / r.pxPerSrcPx;
  assert.ok(diameter > 0.85 * r.src.w && diameter < 1.08 * r.src.w, `diameter ${diameter} vs width ${r.src.w}`);
  t.diagnostic(`worker analysis ${r.ms} ms`);
});

test('CC0 test photos are photos, the graphic one is not', async (t) => {
  if (skip) return t.skip(skip);
  for (const f of ['tourist-and-building.webp', 'art-gallery.webp']) {
    const r = await analyse('testbeelden/' + f);
    assert.equal(r.kind, 'photo', f);
    assert.equal(r.textLines, 0, `${f}: no text claims on photos`);
  }
  const g = await analyse('testbeelden/abstract-geometric-art.webp');
  assert.equal(g.kind, 'illustration');
});

test('main-thread fallback loads the core from the worker bundle and gives the same result', async (t) => {
  if (skip) return t.skip(skip);
  const a = await analyse('eigenaar-ronde-patch.png');
  const b = await analyse('eigenaar-ronde-patch.png', { forceMainThread: true });
  assert.equal(b.mode, 'main');
  assert.equal(b.kind, a.kind);
  assert.equal(b.shape.type, a.shape.type);
  assert.equal(b.palette, a.palette);
});

test('decodeFile: SVG with only a viewBox, PDF/AI accepted but not analysed, size limit', async (t) => {
  if (skip) return t.skip(skip);
  const r = await page.evaluate(async (config) => {
    const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100"><rect width="200" height="100" fill="#fff"/><circle cx="100" cy="50" r="40" fill="#5926C5"/></svg>';
    const d = await window.T.decodeFile(new File([svg], 'logo.svg', { type: 'image/svg+xml' }), config);
    const w = d.bitmap.width, h = d.bitmap.height; // read before: the bitmap is transferred to the worker
    const a = window.T.createAnalyzer({ workerUrl: '/worker.js', config });
    const res = await a.analyze(d, { job: 3 });
    const pdf = await window.T.decodeFile(new File(['%PDF-1.4'], 'logo.pdf', { type: 'application/pdf' }), config);
    const ai = await window.T.decodeFile(new File(['x'], 'logo.ai', { type: '' }), config);
    const big = await window.T.decodeFile(new File([new Uint8Array(2 * 1024 * 1024)], 'big.png', { type: 'image/png' }), { analysis: { ...config.analysis, maxFileMb: 1 } });
    const bad = await window.T.decodeFile(new File(['not an image'], 'broken.png', { type: 'image/png' }), config);
    // job ordering: a newer job cancels the older one
    const d2 = await window.T.decodeFile(new File([svg], 'logo.svg', { type: 'image/svg+xml' }), config);
    const d3 = await window.T.decodeFile(new File([svg], 'logo.svg', { type: 'image/svg+xml' }), config);
    const p1 = a.analyze(d2, { job: 4 }).then(() => 'done', (e) => e.code);
    const p2 = a.analyze(d3, { job: 5 }).then((x) => x.job);
    const order = [await p1, await p2];
    a.terminate();
    return { kind: d.kind, w, h, source: res.source, shape: res.shape.type, lowRes: res.resolution.lowRes,
      pdf: [pdf.kind, pdf.reason], ai: [ai.kind, ai.reason], big: [big.kind, big.reason], bad: [bad.kind, bad.reason], order };
  }, defaults);
  assert.equal(r.kind, 'svg');
  assert.equal(r.w, 2048); assert.equal(r.h, 1024);
  assert.equal(r.source, 'svg');
  assert.equal(r.shape, 'circle');
  assert.equal(r.lowRes, false);
  assert.deepEqual(r.pdf, ['unsupported', 'type']);
  assert.deepEqual(r.ai, ['unsupported', 'type']);
  assert.deepEqual(r.big, ['unsupported', 'size']);
  assert.deepEqual(r.bad, ['unsupported', 'decode']);
  assert.deepEqual(r.order, ['cancelled', 5]);
  assert.deepEqual(page.errors, []);
});
