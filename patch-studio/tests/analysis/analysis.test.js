/**
 * Analysis core on generated test images (SPEC §9: flat logo, JPEG noise, gradient, illustration with shading,
 * transparent + drop shadow, round patch on white, white-on-white, small text, thin line, tilted phone photo, …).
 * Run: npm test  (node --test)
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import * as S from './synth.js';
import { analyzeImage, ANALYSIS_DEFAULTS } from '../../plugin/borduur-patch-studio/assets/src/analysis/index.js';
import { deltaE2000, hexToLab } from '../../plugin/borduur-patch-studio/assets/src/shared/color-math.js';
import defaults from '../../plugin/borduur-patch-studio/config/defaults.json' with { type: 'json' };

const cfg = defaults; // full public config shape (analysis section is what matters)
const run = (img, o) => analyzeImage(img, cfg, o);
const near = (hex, list, max = 4) => list.some((p) => deltaE2000(hexToLab(hex), hexToLab(p.hex)) < max);

/* ------------------------------ images ------------------------------ */
const flatLogo = () => {
  const im = S.image(800, 600);
  S.paint(im, S.circle(400, 260, 200), '#5926C5');
  S.paint(im, S.rect(250, 200, 300, 120), '#FD5C48');
  S.text(im, 'CLUB', 290, 500, 70, '#1E1B1B');
  return im;
};
const roundPatch = () => {
  const im = S.image(800, 800, '#ffffff');
  S.paint(im, S.circle(400, 400, 330), '#1b2a4a');
  S.paint(im, S.ring(400, 400, 300, 306), '#b3262d');
  S.paint(im, S.circle(400, 400, 250), '#c0c4cc');
  S.arcText(im, 'IRON CRUSADERS', 400, 400, 270, 40, '#ffffff', -170, 12);
  S.text(im, 'CLUB', 300, 370, 60, '#1b2a4a');
  return im;
};

test('defaults mirror config/defaults.json → analysis', () => {
  for (const [k, v] of Object.entries(defaults.analysis)) {
    if (k.startsWith('_')) continue;
    assert.deepEqual(ANALYSIS_DEFAULTS[k], v, `analysis.${k}`);
  }
});

test('flat logo on white: flat, white background, exact palette, no shape (text outside the circle)', async () => {
  const r = await run(flatLogo());
  assert.equal(r.v, 1);
  assert.equal(r.kind, 'flat');
  assert.equal(r.background.type, 'white');
  assert.equal(r.background.keep, false);
  assert.equal(r.shape.fillsPatch, false, 'club name below the round logo ⇒ not a round patch');
  for (const h of ['#5926C5', '#FD5C48', '#1E1B1B']) assert.ok(near(h, r.palette, 2), `palette has ${h}`);
  assert.ok(r.palette.length <= 4, `few colours, got ${r.palette.map((p) => p.hex)}`);
  const sum = r.palette.reduce((s, p) => s + p.share, 0);
  assert.ok(Math.abs(sum - 1) < 0.01);
  assert.ok(r.palette.every((p, i) => i === 0 || p.share <= r.palette[i - 1].share), 'sorted by share');
});

test('JPEG-like noise gives the same palette and class', async () => {
  const a = await run(flatLogo());
  const b = await run(S.noise(flatLogo(), 6));
  assert.equal(b.kind, 'flat');
  for (const p of a.palette.filter((x) => x.share > 0.02)) assert.ok(near(p.hex, b.palette, 3), `${p.hex} survives noise`);
});

test('deterministic: same input ⇒ identical palette, features and labels', async () => {
  const a = await run(roundPatch()), b = await run(roundPatch());
  assert.deepEqual(a.palette, b.palette);
  assert.deepEqual(a.features, b.features);
  assert.deepEqual(Array.from(a.labels), Array.from(b.labels));
});

test('linear gradient full bleed ⇒ gradient, rectangle that fills the patch, soft shading', async () => {
  const im = S.image(800, 600);
  S.paint(im, () => true, S.linearGradient(0, 0, 800, 600, '#5926C5', '#FD5C48'));
  S.text(im, 'TEAM', 220, 240, 120, '#ffffff');
  const r = await run(im);
  assert.equal(r.kind, 'gradient');
  assert.equal(r.hasSoftShading, true);
  assert.equal(r.background.type, 'none');
  assert.equal(r.shape.type, 'rect');
  assert.equal(r.shape.fillsPatch, true);
  assert.ok(r.warnings.includes('shape_uncertain'));
});

test('radial gradient is not mistaken for a background + circle', async () => {
  const im = S.image(800, 600);
  S.paint(im, () => true, S.radialGradient(400, 300, 400, '#52D7B4', '#21A9E2'));
  const r = await run(im);
  assert.equal(r.kind, 'gradient');
  assert.equal(r.background.type, 'none');
});

test('illustration with outlines and shading ⇒ illustration (never "photo")', async () => {
  const im = S.image(800, 600);
  for (const [cx, cy, rr, c1, c2] of [[250, 250, 170, '#f2c48d', '#8a5a2b'], [560, 300, 200, '#9fd3ff', '#1f4e8c'], [400, 480, 90, '#ff9a8a', '#a3271b']]) {
    S.paint(im, S.circle(cx, cy, rr), '#111111');
    S.paint(im, S.circle(cx, cy, rr - 6), S.radialGradient(cx - rr / 3, cy - rr / 3, rr, c1, c2));
  }
  const r = await run(im);
  assert.equal(r.kind, 'illustration');
  assert.equal(r.hasSoftShading, true);
});

test('round patch on white: circle fills the patch, contain (nothing cut), preview transparent outside', async () => {
  const r = await run(roundPatch());
  assert.equal(r.shape.type, 'circle');
  assert.equal(r.shape.fillsPatch, true);
  assert.ok(r.shape.confidence >= cfg.analysis.shapeMinConfidence);
  assert.equal(r.kind, 'flat');
  assert.equal(r.background.keep, false);
  // artBox = the circle: square, ≈ 660 source px (radius 330) ⇒ whole design inside
  assert.equal(r.artBox.w, r.artBox.h);
  const srcW = r.artBox.w / r.pxPerSrcPx;
  assert.ok(Math.abs(srcW - 660) < 12, `circle diameter ${srcW}`);
  assert.equal(r.preview.width * r.preview.height * 4, r.preview.data.length);
  assert.equal(r.labels.length, r.preview.width * r.preview.height);
  assert.equal(r.preview.data[3], 0, 'corner is transparent');
  assert.equal(r.labels[0], 255, 'corner label = background');
  const c = (r.preview.height >> 1) * r.preview.width + (r.preview.width >> 1);
  assert.equal(r.preview.data[c * 4 + 3], 255);
  assert.ok(r.labels[c] < r.palette.length);
  assert.ok(near('#b3262d', r.palette, 3), 'thin red ring kept');
  // arc text "IRON CRUSADERS" (cap 40 src px) and straight "CLUB" (60 src px)
  const arc = r.textLines.filter((t) => t.arc);
  assert.ok(arc.length >= 1, 'arc text found');
  for (const t of arc) assert.ok(Math.abs(t.capPx / r.pxPerSrcPx - 40) < 6, `arc cap ${t.capPx / r.pxPerSrcPx}`);
  const straight = r.textLines.find((t) => !t.arc);
  assert.ok(straight && Math.abs(straight.capPx / r.pxPerSrcPx - 60) < 6);
});

test('tilted phone photo of a round patch on fabric: cut out, rectified to a circle, patch photo', async () => {
  const im = S.noise(S.image(700, 1000, '#f2efe6'), 5);
  S.paint(im, S.ellipse(350, 450, 330, 300, 0.3), '#202020');
  S.paint(im, S.ellipse(350, 450, 250, 227, 0.3), '#d4a72c');
  S.noise(im, 4, 3);
  const r = await run(im);
  assert.equal(r.shape.type, 'circle');
  assert.equal(r.isPatchPhoto, true);
  assert.ok(r.warnings.includes('patch_photo_cut'));
  assert.equal(r.background.keep, false);
  assert.ok(near('#d4a72c', r.palette, 3) && near('#202020', r.palette, 3));
  // after rectification the inner gold disc is centred: label at centre and at ±60 % radius left/right = gold
  const W = r.preview.width, gold = r.labels[(W >> 1) * W + (W >> 1)];
  assert.equal(r.labels[(W >> 1) * W + Math.round(W * 0.2)], gold);
  assert.equal(r.labels[(W >> 1) * W + Math.round(W * 0.8)], gold);
});

test('transparent PNG with drop shadow and a white design', async () => {
  const im = S.image(600, 600, '#000000', 0);
  S.paint(im, S.circle(315, 315, 205), '#000000', { alpha: 80 });
  S.paint(im, S.circle(300, 300, 200), '#ffffff');
  const r = await run(im);
  assert.equal(r.background.type, 'transparent');
  assert.ok(r.warnings.includes('transparent_shadow'));
  assert.ok(r.warnings.includes('white_on_transparent'));
});

test('shield, rectangle badge, rounded rectangle and contour silhouettes', async () => {
  const sh = S.image(600, 700);
  S.paint(sh, S.shield(100, 80, 400, 520), '#1f5aa6');
  S.paint(sh, S.rect(250, 200, 100, 200), '#d4a72c');
  assert.equal((await run(sh)).shape.type, 'shield');

  const rb = S.image(900, 500);
  S.paint(rb, S.rect(100, 100, 700, 300), '#b3262d');
  S.text(rb, 'MECKS', 200, 190, 120, '#ffffff');
  const r = await run(rb);
  assert.equal(r.shape.type, 'rect');
  assert.ok(Math.abs(r.shape.aspect - 700 / 300) < 0.05);
  assert.equal(r.palette.length, 2, `anti-aliasing mixes removed: ${r.palette.map((p) => p.hex)}`);

  const ro = S.image(800, 600);
  const R = 50, inside = (x, y) => {
    if (!S.rect(100, 100, 600, 400)(x, y)) return false;
    const qx = Math.min(x - 100, 700 - x), qy = Math.min(y - 100, 500 - y);
    return qx >= R || qy >= R || (R - qx) ** 2 + (R - qy) ** 2 <= R * R;
  };
  S.paint(ro, inside, '#3e4f2a');
  assert.equal((await run(ro)).shape.type, 'rounded');

  const star = S.image(700, 700);
  const pts = Array.from({ length: 10 }, (_, i) => { const a = (i * Math.PI) / 5 - Math.PI / 2, rr = i % 2 ? 120 : 300; return [350 + rr * Math.cos(a), 350 + rr * Math.sin(a)]; });
  const inPoly = (x, y) => { let c = false; for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) { const [xi, yi] = pts[i], [xj, yj] = pts[j]; if ((yi > y) !== (yj > y) && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) c = !c; } return c; };
  S.paint(star, inPoly, '#e86a1f');
  const st = await run(star);
  assert.equal(st.shape.type, 'contour');
  assert.ok(st.shape.confidence < cfg.analysis.shapeMinConfidence, 'contour is only proposed ("Klopt dit?")');
});

test('coloured background is kept as patch background (+ backdrop for the renderer)', async () => {
  const im = S.image(800, 600, '#1b2a4a');
  S.text(im, 'TEAM', 220, 240, 120, '#d4a72c');
  const r = await run(im);
  assert.equal(r.background.type, 'solid');
  assert.equal(r.background.keep, true);
  assert.ok(deltaE2000(hexToLab(r.background.hex), hexToLab('#1b2a4a')) < 1);
  assert.ok(r.backdrop && r.backdrop.width > 0 && r.backdrop.data.length === r.backdrop.width * r.backdrop.height * 4);
  assert.ok(r.backdrop.artBox.w > 0);
});

test('white design on white background: enclosed white stays design', async () => {
  const im = S.image(800, 600);
  S.paint(im, S.circle(400, 300, 220), '#1b2a4a');
  S.paint(im, S.circle(400, 300, 150), '#ffffff');
  const r = await run(im);
  assert.equal(r.shape.type, 'circle');
  assert.ok(near('#ffffff', r.palette, 2), 'white inner disc is a thread colour');
  assert.ok(r.palette.find((p) => p.hex === '#ffffff').share > 0.3);
});

test('small text: cap height measured within 15 %', async () => {
  const im = S.image(1200, 600);
  S.paint(im, S.rect(80, 100, 1040, 40), '#b3262d');
  S.text(im, 'SMALL TEXT CLUB', 100, 250, 30, '#1b2a4a');
  const r = await run(im);
  assert.ok(r.textLines.length >= 1, 'text found');
  const cap = Math.min(...r.textLines.map((t) => t.capPx)) / r.pxPerSrcPx;
  assert.ok(Math.abs(cap - 30) / 30 < 0.15, `cap ${cap}`);
  assert.ok(r.textLines.every((t) => t.paletteIndex >= 0 && t.paletteIndex < r.palette.length));
  assert.ok(r.textLines.every((t) => t.box.x >= r.artBox.x - 1 && t.box.x + t.box.w <= r.artBox.x + r.artBox.w + 1));
});

test('thin line: fine line measured, wide areas are fill', async () => {
  const im = S.image(800, 800);
  S.paint(im, S.rect(200, 200, 400, 400), '#1f5aa6');
  S.paint(im, S.line(100, 700, 700, 100, 3), '#111111');
  const r = await run(im);
  assert.ok(r.fineLinePx != null && r.fineLinePx / r.pxPerSrcPx <= 6, `fine line ${r.fineLinePx}`);
  const big = await run(Object.assign(S.image(800, 800), {}));
  assert.equal(big.fineLinePx, null);
});

test('no illusions on photos: no text claims, class photo needs many tones', async () => {
  // pseudo photo: smooth random field with many tones and few hard edges
  const im = S.image(600, 400);
  for (let y = 0; y < 400; y++) for (let x = 0; x < 600; x++) {
    const i = (y * 600 + x) * 4;
    im.data[i] = 128 + 90 * Math.sin(x / 37) * Math.cos(y / 23);
    im.data[i + 1] = 128 + 90 * Math.sin((x + y) / 53);
    im.data[i + 2] = 128 + 90 * Math.cos(x / 19 - y / 41);
  }
  S.noise(im, 3);
  const r = await run(im);
  assert.ok(['photo', 'gradient', 'illustration'].includes(r.kind));
  if (r.kind === 'photo') assert.equal(r.textLines.length, 0);
});

test('empty image ⇒ nearly_empty, no measurements', async () => {
  const r = await run(S.image(400, 300));
  assert.ok(r.warnings.includes('nearly_empty'));
  assert.equal(r.fineLinePx, null);
});

test('resolution: low_res below analysis.lowResMinPx, srcSize respected', async () => {
  const small = S.image(300, 300);
  S.paint(small, S.circle(150, 150, 120), '#1f5aa6');
  const r = await run(small);
  assert.ok(r.resolution.lowRes && r.warnings.includes('low_res'));
  assert.ok(r.resolution.effectiveLongestPx <= 245);
  const r2 = await run(small, { srcSize: { w: 1200, h: 1200 } });
  assert.equal(r2.src.w, 1200);
  assert.equal(r2.resolution.lowRes, false);
  assert.ok(Math.abs(r2.size.w - r2.src.w * r2.pxPerSrcPx) <= 1);
});

test('progress stages in contract order + cancellation', async () => {
  const stages = [];
  await run(flatLogo(), { job: 7, onProgress: (p) => { assert.equal(p.job, 7); stages.push(p.stage); } });
  assert.deepEqual(stages, ['background', 'shape', 'colors', 'details', 'preview']);
  let n = 0;
  await assert.rejects(run(flatLogo(), { shouldAbort: () => ++n > 1 }), (e) => e.code === 'cancelled');
});

test('svg source: gradient + embedded raster warnings, never low_res for pure vector', async () => {
  const im = S.image(200, 200);
  S.paint(im, S.circle(100, 100, 80), '#1f5aa6');
  const vec = await run(im, { svgText: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="#1f5aa6"/></svg>' });
  assert.equal(vec.source, 'svg');
  assert.equal(vec.resolution.lowRes, false);
  const grad = await run(im, { svgText: '<svg viewBox="0 0 10 10"><defs><linearGradient id="g"><stop/></linearGradient></defs><rect fill="url(#g)" width="10" height="10"/><image href="data:image/png;base64,AAAA"/></svg>' });
  assert.ok(grad.warnings.includes('svg_gradient'));
  assert.ok(grad.warnings.includes('svg_embedded_raster'));
  assert.equal(grad.hasSoftShading, true);
});

test('result honours the AnalysisResult contract', async () => {
  const r = await run(roundPatch());
  for (const k of ['v', 'job', 'source', 'src', 'size', 'pxPerSrcPx', 'kind', 'hasSoftShading', 'isMockup', 'isPatchPhoto', 'background', 'shape',
    'contentBox', 'artBox', 'palette', 'fineLinePx', 'fineGapPx', 'textLines', 'regions', 'features', 'resolution', 'warnings', 'preview', 'labels', 'timings']) {
    assert.ok(k in r, `missing ${k}`);
  }
  assert.ok(['flat', 'gradient', 'illustration', 'photo'].includes(r.kind));
  assert.ok(r.palette.length <= 16);
  assert.ok(r.labels instanceof Uint8Array);
  assert.ok(r.preview.data instanceof Uint8ClampedArray);
  assert.ok(Math.max(r.preview.width, r.preview.height) <= cfg.analysis.sizes.preview);
  assert.ok(Math.max(r.artBox.w, r.artBox.h) <= cfg.analysis.sizes.b);
  for (const k of ['fillAreaPx2', 'satinLenPx', 'runLenPx', 'objects']) assert.equal(typeof r.regions[k], 'number');
  for (const k of ['cover', 'hard', 'chg', 'fake', 'residual', 'texture']) assert.equal(typeof r.features[k], 'number');
  for (const t of r.textLines) for (const k of ['box', 'capPx', 'xPx', 'strokePx', 'arc', 'serifOrScript', 'paletteIndex', 'confidence']) assert.ok(k in t);
  for (const w of r.warnings) assert.ok(['low_res', 'transparent_shadow', 'mockup', 'patch_photo_cut', 'nearly_empty', 'white_on_transparent', 'shape_uncertain', 'svg_gradient', 'svg_embedded_raster'].includes(w));
  // works with only config.analysis (worker protocol) and with no config at all
  const r2 = await analyzeImage(roundPatch(), cfg.analysis);
  assert.deepEqual(r2.palette, r.palette);
  const r3 = await analyzeImage(roundPatch(), undefined);
  assert.equal(r3.shape.type, 'circle');
});
