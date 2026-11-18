#!/usr/bin/env node
/**
 * Genereert config/nametape-metrics.json (SPEC-EXTRA §1.4, D3): meet elk woff2-lettertype uit config/nametape.json in
 * Chromium (Playwright) met canvas measureText. Per font-id: capHeightEm (hoogte van "H" in em), adv per teken (em) en
 * fallbackEm (breedte van "M", voor onbekende tekens). Waarden afgerond op 4 decimalen zodat JS en PHP identiek rekenen.
 *
 *   node tests/nametape/measure-fonts.mjs          (schrijft het bestand)
 *   node tests/nametape/measure-fonts.mjs --check  (vergelijkt enkel, exit 1 bij verschil)
 */
import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../plugin/borduur-patch-studio');
const cfg = JSON.parse(await readFile(resolve(plugin, 'config/nametape.json'), 'utf8'));
const out = resolve(plugin, 'config/nametape-metrics.json');

const CHARS = [];
for (let c = 65; c <= 90; c++) CHARS.push(String.fromCharCode(c));
for (let c = 97; c <= 122; c++) CHARS.push(String.fromCharCode(c));
for (let c = 48; c <= 57; c++) CHARS.push(String.fromCharCode(c));
CHARS.push(' ', '.', ',', "'", '-', '/', '&', '+', '#', '(', ')');

let chromium;
try { ({ chromium } = require('playwright')); } catch { console.error('Playwright ontbreekt (require("playwright")).'); process.exit(2); }

const fonts = [];
for (const f of cfg.fonts) {
  const buf = await readFile(resolve(plugin, 'assets/fonts', f.file));
  fonts.push({ id: f.id, family: f.family, weight: f.weight, b64: buf.toString('base64') });
}

const browser = await chromium.launch();
const page = await browser.newPage();
await page.setContent('<!doctype html><canvas id="c" width="10" height="10"></canvas>');
const result = await page.evaluate(async ({ fonts, chars }) => {
  const r4 = (x) => Math.round(x * 10000) / 10000;
  const res = { schema: 1, _doc: 'Gegenereerd door tests/nametape/measure-fonts.mjs. Niet met de hand aanpassen.', fonts: {} };
  const ctx = document.getElementById('c').getContext('2d');
  const S = 1000;
  for (const f of fonts) {
    const bin = Uint8Array.from(atob(f.b64), (ch) => ch.charCodeAt(0));
    const fam = 'M_' + f.id;
    const ff = new FontFace(fam, bin.buffer, { weight: String(f.weight) });
    await ff.load();
    document.fonts.add(ff);
    ctx.font = `${f.weight} ${S}px "${fam}"`;
    const h = ctx.measureText('H');
    const adv = {};
    for (const ch of chars) adv[ch] = r4(ctx.measureText(ch).width / S);
    res.fonts[f.id] = { capHeightEm: r4(h.actualBoundingBoxAscent / S), adv, fallbackEm: r4(ctx.measureText('M').width / S) };
  }
  return res;
}, { fonts, chars: CHARS });
await browser.close();

const json = JSON.stringify(result, null, 1) + '\n';
if (process.argv.includes('--check')) {
  let prev = '';
  try { prev = await readFile(out, 'utf8'); } catch { /* ontbreekt */ }
  if (prev !== json) { console.error('nametape-metrics.json wijkt af van een nieuwe meting.'); process.exit(1); }
  console.log('nametape-metrics.json is actueel.');
} else {
  await writeFile(out, json);
  console.log('Geschreven: ' + out);
  for (const [id, m] of Object.entries(result.fonts)) console.log(`  ${id}: cap ${m.capHeightEm} em, "PEETERS J." = ${[...'PEETERS J.'].reduce((s, c) => s + m.adv[c], 0).toFixed(3)} em`);
}
