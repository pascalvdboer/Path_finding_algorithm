#!/usr/bin/env node
/**
 * Visual gallery: renders every technique / edge / shape / garment combination to PNG files for review.
 *   node plugin/borduur-patch-studio/assets/src/render/tests/gallery.mjs <outDir> [filter]
 */
import fs from 'node:fs';
import path from 'node:path';
import { openHarness } from './harness.mjs';

const out = path.resolve(process.argv[2] || 'render-gallery');
const filter = process.argv[3] || '';
fs.mkdirSync(out, { recursive: true });
const h = await openHarness();
if (h.skip) { console.log('skip:', h.skip); process.exit(0); }
const { page } = h;

const T1 = [{ id: 't1', text: 'BORDUURSERVICE', font: 'Sora', heightMm: 5, path: 'arc_top', angleDeg: 0, insetMm: 0, letterSpacing: 0.08, offsetMm: { x: 0, y: 0 }, hex: '#ffffff' },
  { id: 't2', text: 'Kalmthout', font: 'Sora', heightMm: 5, path: 'arc_bottom', angleDeg: 0, insetMm: 0, letterSpacing: 0.05, offsetMm: { x: 0, y: 0 }, hex: '#f2c230' }];
const cases = [
  { n: 'round-embroidered', img: 'eigenaar-ronde-patch.png', o: { technique: 'embroidered' } },
  { n: 'round-woven', img: 'eigenaar-ronde-patch.png', o: { technique: 'woven' } },
  { n: 'round-printed', img: 'eigenaar-ronde-patch.png', o: { technique: 'printed' } },
  { n: 'round-sublimated', img: 'eigenaar-ronde-patch.png', o: { technique: 'sublimated' } },
  { n: 'round-pvc', img: 'eigenaar-ronde-patch.png', o: { technique: 'pvc' } },
  { n: 'round-chenille', img: 'eigenaar-ronde-patch.png', o: { technique: 'chenille' } },
  { n: 'round-leather', img: 'eigenaar-ronde-patch.png', o: { technique: 'leather', edge: 'laser' } },
  { n: 'photo-embroidered', img: 'eigenaar-foto-patch.jpg', o: { technique: 'embroidered' } },
  { n: 'abstract-rect-embroidered', img: 'testbeelden/abstract-geometric-art.webp', o: { technique: 'embroidered', shape: 'rect' } },
  { n: 'abstract-shield-woven-merrow', img: 'testbeelden/abstract-geometric-art.webp', o: { technique: 'woven', shape: 'shield', edge: 'merrow' } },
  { n: 'abstract-oval-printed', img: 'testbeelden/abstract-geometric-art.webp', o: { technique: 'printed', shape: 'oval', aspect: 1.5 } },
  { n: 'text-only-embroidered', img: null, o: { technique: 'embroidered', shape: 'circle', sizeCm: 6, texts: T1, fabricHex: '#1b2a4a', edgeHex: '#f2c230' } },
  { n: 'text-only-contour-pvc', img: null, o: { technique: 'pvc', shape: 'contour', sizeCm: 8, texts: [{ id: 'a', text: 'IMTA', font: 'Sora', heightMm: 18, path: 'straight', offsetMm: { x: 0, y: 0 }, hex: '#21A9E2' }], fabricHex: '#151515' } },
  { n: 'text-only-chenille', img: null, o: { technique: 'chenille', shape: 'rounded', aspect: 1.3, texts: [{ id: 'a', text: 'Club', font: 'Pacifico', heightMm: 16, path: 'straight', offsetMm: { x: 0, y: 0 }, hex: '#ffffff' }], fabricHex: '#b3262d', edgeHex: '#ffffff' } },
  { n: 'uncut-embroidered', img: 'eigenaar-ronde-patch.png', o: { technique: 'embroidered', edge: 'self_cut' } },
  { n: 'onfilm-embroidered-contour', img: 'testbeelden/abstract-geometric-art.webp', o: { technique: 'embroidered', shape: 'contour', edge: 'on_film' } },
  { n: 'garment-polo', img: 'eigenaar-ronde-patch.png', o: { technique: 'embroidered', view: 'garment', garment: 'polo_chest' } },
  { n: 'garment-cap', img: 'eigenaar-ronde-patch.png', o: { technique: 'embroidered', view: 'garment', garment: 'cap_front', sizeCm: 6 } },
  { n: 'garment-jacket', img: 'eigenaar-ronde-patch.png', o: { technique: 'woven', view: 'garment', garment: 'jacket_back', sizeCm: 12 } },
  { n: 'zoom-embroidered', img: 'eigenaar-ronde-patch.png', o: { technique: 'embroidered', zoom: 3, zoomCenterMm: { x: 40, y: 22 } } },
  { n: 'guides', img: 'eigenaar-ronde-patch.png', o: { technique: 'embroidered', guides: { safeZone: true, boxesMm: [{ x: 30, y: 30, w: 10, h: 6 }] } } },
];

const analyses = {};
for (const c of cases) {
  if (filter && !c.n.includes(filter)) continue;
  const res = await page.evaluate(async ({ c }) => {
    window.A = window.A || {};
    let a = null;
    if (c.img) { a = window.A[c.img] || (window.A[c.img] = await T.analyzeUrl('/img/' + c.img, CFG)); }
    const opts = T.makeOpts(CFG, a, c.o);
    const cv = document.createElement('canvas'); cv.width = cv.height = 1000;
    const r = T.R.createRenderer(cv, { config: CFG });
    await document.fonts.ready;
    const first = r.draw(opts);
    const second = r.draw(opts);
    const fast = r.draw(Object.assign({}, opts, { quality: 'fast', design: Object.assign({}, opts.design) }));
    const url = cv.toDataURL('image/png');
    const blob = await r.toBlob(opts, { size: 900 });
    const buf = new Uint8Array(await blob.arrayBuffer());
    let bin = ''; for (let i = 0; i < buf.length; i += 8192) bin += String.fromCharCode.apply(null, buf.subarray(i, i + 8192));
    return { url, first: first.ms, cached: second.ms, fast: fast.ms, blob: btoa(bin), kind: a && a.kind, shape: a && a.shape && a.shape.type };
  }, { c });
  fs.writeFileSync(path.join(out, c.n + '.png'), Buffer.from(res.url.split(',')[1], 'base64'));
  fs.writeFileSync(path.join(out, c.n + '.export.png'), Buffer.from(res.blob, 'base64'));
  console.log(c.n.padEnd(32), 'first', res.first.toFixed(1), 'ms  cached', res.cached.toFixed(1), 'ms  fast', res.fast.toFixed(1), 'ms', res.kind || '', res.shape || '');
}
if (h.errors.length) console.log('ERRORS:', h.errors);
await h.close();
