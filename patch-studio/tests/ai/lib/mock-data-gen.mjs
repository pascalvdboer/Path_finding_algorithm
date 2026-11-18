#!/usr/bin/env node
/**
 * Genereert plugin/borduur-patch-studio/assets/src/ai/mock-data.js uit config/ai-mock.json + config/ai-mock/*.svg,
 * zodat de in-browser nep-API (mock-api.js) exact dezelfde eigen voorbeeldtekeningen gebruikt als de PHP-mock.
 *   node tests/ai/lib/mock-data-gen.mjs          → schrijft het bestand
 * tests/ai/frontend-mock.test.js controleert dat het bestand in sync is.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const cfgDir = resolve(root, 'plugin/borduur-patch-studio/config');
export const target = resolve(root, 'plugin/borduur-patch-studio/assets/src/ai/mock-data.js');

/** Binnenste SVG-tekening (zonder <svg>-wrapper), witruimte samengeperst. */
function inner(svg) {
  const m = svg.match(/<svg[^>]*>([\s\S]*)<\/svg>/);
  return (m ? m[1] : svg).replace(/>\s+</g, '><').replace(/\s+/g, ' ').trim();
}

export function buildSource() {
  const cfg = JSON.parse(readFileSync(resolve(cfgDir, 'ai-mock.json'), 'utf8'));
  const templates = cfg.templates.map((t) => {
    const kw = new Set();
    for (const list of Object.values(t.keywords || {})) for (const w of list) kw.add(String(w).toLowerCase());
    return { id: t.id, kw: [...kw], alt: t.alt, svg: inner(readFileSync(resolve(cfgDir, 'ai-mock', t.file), 'utf8')) };
  });
  const data = { templates, palettes: cfg.palettes, colorHex: cfg.colorHex };
  return (
    '/**\n * AI-ontwerpstudio — data voor de in-browser nep-API. GEGENEREERD, niet met de hand aanpassen:\n' +
    ' *   node tests/ai/lib/mock-data-gen.mjs\n' +
    ' * Bron: config/ai-mock.json + config/ai-mock/*.svg (eigen, vlakke, borduurbare tekeningen; geen merken).\n */\n' +
    'export default ' + JSON.stringify(data) + ';\n'
  );
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  writeFileSync(target, buildSource());
  console.log('geschreven: ' + target);
}
