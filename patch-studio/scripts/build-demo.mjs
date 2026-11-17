#!/usr/bin/env node
/**
 * Builds dist/patch-studio-demo.html (eigenaar: integrator): one self-contained file with the whole customer
 * experience without WordPress. Everything is inline (JS, CSS, worker, config, fonts, example files).
 *   npm run demo      (= build + this script)
 * Prerequisite: assets/dist is built (npm run build).
 */
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const plugin = resolve(root, 'plugin/borduur-patch-studio');
const demo = resolve(root, 'demo');
const out = resolve(root, 'dist/patch-studio-demo.html');

const read = (p, enc = 'utf8') => readFileSync(p, enc);
const b64 = (p, type) => `data:${type};base64,${readFileSync(p).toString('base64')}`;

for (const f of ['assets/dist/patch-studio.js', 'assets/dist/patch-studio-worker.js']) {
  if (!existsSync(resolve(plugin, f))) { console.error(`${f} ontbreekt: voer eerst npm run build uit.`); process.exit(1); }
}

/** Safe inside <script>: no "</script" or "<!--" can end/alter the element. Only appear inside strings in our code. */
const scriptSafe = (s) => s.replace(/<\/(script)/gi, '<\\/$1').replace(/<!--/g, '<\\!--');
/** JSON inside <script type="application/json">. */
const jsonSafe = (o) => JSON.stringify(o).replace(/</g, '\\u003c');

const defaults = JSON.parse(read(resolve(plugin, 'config/defaults.json')));
const palette = JSON.parse(read(resolve(plugin, 'config/thread-palette.json')));

// Text-layer fonts (canvas) from the plugin, keyed by family id.
const fonts = {};
for (const f of defaults.fonts || []) {
  const p = resolve(plugin, 'assets/fonts', f.file);
  if (existsSync(p)) fonts[f.id] = { weight: f.weight || 400, data: b64(p, 'font/woff2') };
}

// Page fonts: Sora (headings/buttons) + Assistant (body), as on the site.
const face = (family, weight, data) => `@font-face{font-family:"${family}";font-style:normal;font-weight:${weight};font-display:swap;src:url(${data}) format("woff2");}`;
const fontFaces = [
  face('Sora', 700, b64(resolve(plugin, 'assets/fonts/sora-700.woff2'), 'font/woff2')),
  face('Sora', 600, b64(resolve(plugin, 'assets/fonts/sora-700.woff2'), 'font/woff2')),
  ...[400, 500, 600, 700].map((w) => face('Assistant', w, b64(resolve(demo, `assets/assistant-${w}.woff2`), 'font/woff2'))),
].join('\n');

const samples = [
  { file: 'voorbeeld-verloop.svg', type: 'image/svg+xml', data: b64(resolve(demo, 'assets/voorbeeld-verloop.svg'), 'image/svg+xml'),
    nl: 'Badge met kleurverloop', nlHint: 'Zachte overgangen: wat doet garen daarmee?',
    fr: 'Badge avec dégradé', frHint: 'Des transitions douces : que fait le fil avec ça ?' },
  { file: 'voorbeeld-foto.webp', type: 'image/webp', data: b64(resolve(demo, 'assets/voorbeeld-foto.webp'), 'image/webp'),
    nl: 'Een foto', nlHint: 'Eerlijk advies voor foto’s',
    fr: 'Une photo', frHint: 'Un conseil honnête pour les photos' },
  { file: 'logo-vlak.png', type: 'image/png', data: b64(resolve(root, 'tests/e2e/fixtures/logo-flat.png'), 'image/png'),
    nl: 'Vlak logo (PNG)', nlHint: 'Klassiek borduurwerk',
    fr: 'Logo simple (PNG)', frHint: 'Broderie classique' },
];

let html = read(resolve(demo, 'template.html'));
const put = (marker, value) => {
  const i = html.indexOf(marker);
  if (i < 0) throw new Error('marker ontbreekt: ' + marker);
  html = html.slice(0, i) + value + html.slice(i + marker.length); // no String.replace: avoids $-patterns in code
};
put('/*__FONTFACES__*/', fontFaces);
put('/*__CSS__*/', read(resolve(plugin, 'assets/css/patch-studio.css')).replace(/<\/style/gi, '<\\/style'));
put('/*__DEFAULTS__*/', jsonSafe(defaults));
put('/*__PALETTE__*/', jsonSafe(palette));
put('/*__FONTS__*/', jsonSafe(fonts));
put('/*__SAMPLES__*/', jsonSafe(samples));
put('/*__WORKER__*/', scriptSafe(read(resolve(plugin, 'assets/dist/patch-studio-worker.js'))));
put('/*__APP__*/', scriptSafe(read(resolve(plugin, 'assets/dist/patch-studio.js'))));

mkdirSync(dirname(out), { recursive: true });
writeFileSync(out, html);
const kb = (n) => (n / 1024).toFixed(0) + ' kB';
console.log(`dist/patch-studio-demo.html  ${kb(html.length)}  (gzip ${kb(gzipSync(html).length)})`);
