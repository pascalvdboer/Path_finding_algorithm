/** estimateStitches (07 §11.2) + svgInfo. */
import test from 'node:test';
import assert from 'node:assert/strict';
import { estimateStitches, svgInfo, analyzeImage } from '../../plugin/borduur-patch-studio/assets/src/analysis/index.js';
import defaults from '../../plugin/borduur-patch-studio/config/defaults.json' with { type: 'json' };
import * as S from './synth.js';

const ST = defaults.stitches;

test('research example: 2500 mm² fill + 400 mm satin + 200 mm run + 5 colours + 12 objects ⇒ 6.400 (5.100–8.000)', () => {
  const r = estimateStitches({ fillAreaPx2: 2500, satinLenPx: 400, runLenPx: 200, objects: 12 }, 1, 5, ST);
  assert.deepEqual(r, { estimate: 6400, min: 5100, max: 8000 });
});

test('mm per pixel scales area quadratically and lengths linearly', () => {
  const a = estimateStitches({ fillAreaPx2: 10000, satinLenPx: 0, runLenPx: 0, objects: 0 }, 0.5, 0, ST);
  assert.equal(a.estimate, Math.round((10000 * 0.25 * 1.6) / 100) * 100);
  const b = estimateStitches({ fillAreaPx2: 0, satinLenPx: 1000, runLenPx: 0, objects: 0 }, 0.5, 0, ST);
  assert.equal(b.estimate, 2500);
});

test('bands are classified per size: the same strokes are run stitch when small, satin when larger', () => {
  const regions = { fillAreaPx2: 0, satinLenPx: 0, runLenPx: 0, objects: 1, bands: [[4, 4000]] }; // 4 px wide, 1000 px long
  const small = estimateStitches(regions, 0.2, 1, ST);  // 0,8 mm wide ⇒ run: 200 mm × 0,4
  const big = estimateStitches(regions, 0.5, 1, ST);    // 2 mm wide ⇒ satin: 500 mm × 5
  assert.equal(small.estimate, Math.round((200 * 0.4 + 30 + 15) / 100) * 100);
  assert.equal(big.estimate, Math.round((500 * 5 + 30 + 15) / 100) * 100);
  const fill = estimateStitches({ ...regions, bands: [[40, 40000]] }, 0.5, 1, ST); // 20 mm wide ⇒ fill 10 000 mm²
  assert.equal(fill.estimate, Math.round((10000 * 1.6 + 45) / 100) * 100);
});

test('satin border adds perimeter × satinBorderPerMm; merrow does not', () => {
  const base = { fillAreaPx2: 1000, satinLenPx: 0, runLenPx: 0, objects: 0 };
  const a = estimateStitches(base, 1, 0, ST);
  const b = estimateStitches(base, 1, 0, ST, { perimeterMm: 250, satinBorder: true });
  const c = estimateStitches(base, 1, 0, ST, { perimeterMm: 250, satinBorder: false });
  assert.equal(b.estimate - a.estimate, 1500);
  assert.equal(c.estimate, a.estimate);
});

test('empty / invalid input is safe', () => {
  assert.deepEqual(estimateStitches(null, 0, 0, ST), { estimate: 0, min: 0, max: 0 });
  assert.deepEqual(estimateStitches({}, 1, 0, {}), { estimate: 0, min: 0, max: 0 });
});

test('an analysed 8 cm logo gives a plausible stitch count (thousands, not millions)', async () => {
  const im = S.image(800, 800);
  S.paint(im, S.circle(400, 400, 380), '#1b2a4a');
  S.paint(im, S.circle(400, 400, 200), '#d4a72c');
  const r = await analyzeImage(im, defaults);
  const mmPerPx = 80 / r.artBox.w;
  const s = estimateStitches(r.regions, mmPerPx, r.palette.length, ST);
  // full 8 cm disc = 5027 mm² × 1,6 ≈ 8.000 + extras
  assert.ok(s.estimate > 7000 && s.estimate < 11000, `estimate ${s.estimate}`);
  assert.ok(s.min < s.estimate && s.max > s.estimate);
  // fixed fields (reference size 8 cm) agree with bands at the same size
  const fixed = estimateStitches({ ...r.regions, bands: undefined }, mmPerPx, r.palette.length, ST);
  assert.equal(fixed.estimate, s.estimate);
});

test('svgInfo: size from attributes or viewBox, gradient only when used, embedded raster, text', () => {
  assert.deepEqual(svgInfo('<svg width="120px" height="60" xmlns="http://www.w3.org/2000/svg"></svg>'), { hasGradient: false, hasEmbeddedRaster: false, hasText: false, width: 120, height: 60 });
  const vb = svgInfo('<svg viewBox="0 0 300 150"><text>Hi</text></svg>');
  assert.equal(vb.width, 300); assert.equal(vb.height, 150); assert.equal(vb.hasText, true);
  assert.equal(svgInfo('<svg viewBox="0 0 1 1"><linearGradient id="unused"/><rect fill="#f00"/></svg>').hasGradient, false);
  assert.equal(svgInfo('<svg viewBox="0 0 1 1"><radialGradient id="a.b"/><rect fill="url(#a.b)"/></svg>').hasGradient, true);
  assert.equal(svgInfo('<svg><image xlink:href="data:image/jpeg;base64,xx"/></svg>').hasEmbeddedRaster, true);
  assert.equal(svgInfo(null), null);
});
