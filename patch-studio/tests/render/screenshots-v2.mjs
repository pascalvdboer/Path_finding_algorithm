#!/usr/bin/env node
/**
 * Render v2 screenshots for docs/screenshots/v2-render/ (close-up per technique, garments, technique
 * comparer, light directions). Usage: node tests/render/screenshots-v2.mjs [outDir]
 */
import fs from 'node:fs';
import path from 'node:path';
import { openHarness, projectDir } from './harness.mjs';

const out = path.resolve(process.argv[2] || path.join(projectDir, 'docs/screenshots/v2-render'));
fs.mkdirSync(out, { recursive: true });
const h = await openHarness();
if (h.skip) { console.log('skip:', h.skip); process.exit(0); }
const { page } = h;
await page.evaluate(async () => {
  window.A = {
    round: await T.analyzeUrl('/img/eigenaar-ronde-patch.png', CFG),
    photo: await T.analyzeUrl('/img/eigenaar-foto-patch.jpg', CFG),
  };
  await Promise.all([document.fonts.load('600 40px Sora'), document.fonts.load('40px Pacifico')].map((p) => p.catch(() => null)));
});

const TXT = [{ id: 't1', text: 'BORDUURSERVICE', font: 'Sora', heightMm: 5.5, path: 'arc_top', angleDeg: 0, insetMm: 0, letterSpacing: 0.08, offsetMm: { x: 0, y: 0 }, hex: '#ffffff' },
  { id: 't2', text: 'Kalmthout', font: 'Sora', heightMm: 5.5, path: 'arc_bottom', angleDeg: 0, insetMm: 0, letterSpacing: 0.05, offsetMm: { x: 0, y: 0 }, hex: '#f2c230' }];
const shots = [
  ['embroidered', 'round', { technique: 'embroidered' }],
  ['woven', 'round', { technique: 'woven', edge: 'merrow' }],
  ['woven-hotcut', 'round', { technique: 'woven', edge: 'laser' }],
  ['printed', 'round', { technique: 'printed' }],
  ['sublimated', 'round', { technique: 'sublimated' }],
  ['pvc', 'round', { technique: 'pvc' }],
  ['pvc-3d', 'round', { technique: 'pvc', pvcDepth: '3d' }],
  ['chenille', null, { technique: 'chenille', shape: 'rounded', aspect: 1.3, sizeCm: 9, texts: [{ id: 'a', text: 'Club', font: 'Pacifico', heightMm: 22, path: 'straight', offsetMm: { x: 0, y: -2 }, hex: '#ffffff' }], fabricHex: '#b3262d', edgeHex: '#ffffff' }],
  ['leather', 'round', { technique: 'leather', edge: 'laser' }],
  ['embroidered-tekst', null, { technique: 'embroidered', shape: 'circle', sizeCm: 7, texts: TXT, fabricHex: '#1b2a4a', edgeHex: '#f2c230' }],
  ['embroidered-foto', 'photo', { technique: 'embroidered' }],
];

async function shot(name, a, o, px = 1200) {
  const url = await page.evaluate(async ({ a, o, px }) => {
    const opts = T.makeOpts(CFG, a ? A[a] : null, Object.assign({ pxSize: px }, o));
    if (o.pvcDepth) opts.pvcDepth = o.pvcDepth;
    if (o.light) opts.light = o.light;
    if (o.scene) opts.scene = o.scene;
    const cv = document.createElement('canvas'); cv.width = cv.height = px;
    const R = T.R.createRenderer(cv, { config: CFG });
    R.draw(opts);
    if (opts.design.texts.length) { await new Promise((res) => setTimeout(res, 400)); await document.fonts.ready; }
    const r = R.draw(opts);
    window.lastMs = r.ms;
    return cv.toDataURL('image/jpeg', 0.88);
  }, { a, o, px });
  fs.writeFileSync(path.join(out, name + '.jpg'), Buffer.from(url.split(',')[1], 'base64'));
  console.log(name);
}

for (const [n, a, o] of shots) await shot('na-closeup-' + n, a, o);
await shot('na-detail-embroidered-zoom', 'round', { technique: 'embroidered', zoom: 2.6, zoomCenterMm: { x: 22, y: 30 } });
await shot('na-detail-woven-merrow-zoom', 'round', { technique: 'woven', edge: 'merrow', zoom: 3, zoomCenterMm: { x: 12, y: 18 } });
for (const g of ['polo_chest', 'cap_front', 'jacket_back', 'backpack_front']) {
  await shot('na-kledij-' + g, 'round', { technique: 'embroidered', view: 'garment', garment: g, sizeCm: g === 'cap_front' ? 6 : g === 'jacket_back' ? 14 : 8 });
}
// light follows the pointer: same patch, three light directions
for (const [n, l] of [['linksboven', { x: -0.7, y: -0.7 }], ['rechtsboven', { x: 0.75, y: -0.6 }], ['onder', { x: 0.1, y: 0.9 }]]) {
  await shot('na-licht-' + n, 'round', { technique: 'embroidered', zoom: 1.8, zoomCenterMm: { x: 55, y: 30 }, light: l }, 800);
}
// technique comparer
const cmp = await page.evaluate(async () => {
  const opts = T.makeOpts(CFG, A.round, { technique: 'embroidered', pxSize: 260 });
  const t0 = performance.now();
  const list = T.R.renderTechniques(opts, { size: 260, config: CFG });
  const ms = performance.now() - t0;
  const W = 260 * list.length + 20 * (list.length + 1), H = 330;
  const cv = document.createElement('canvas'); cv.width = W; cv.height = H;
  const c = cv.getContext('2d');
  c.fillStyle = '#ffffff'; c.fillRect(0, 0, W, H);
  c.font = '600 18px Sora, sans-serif'; c.textAlign = 'center'; c.fillStyle = '#0A1F44';
  list.forEach((x, i) => {
    const X = 20 + i * 280;
    c.drawImage(x.canvas, X, 20);
    const lab = CFG.techniques[x.technique].label; c.fillText(lab.nl || lab, X + 130, 310);
  });
  return { url: cv.toDataURL('image/png'), ms, each: list.map((x) => Math.round(x.ms)) };
});
fs.writeFileSync(path.join(out, 'na-vergelijker.png'), Buffer.from(cmp.url.split(',')[1], 'base64'));
console.log('vergelijker', Math.round(cmp.ms), 'ms', cmp.each);
if (h.errors.length) console.log('ERRORS:', h.errors);
await h.close();
