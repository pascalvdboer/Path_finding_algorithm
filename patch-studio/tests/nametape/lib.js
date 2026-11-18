/**
 * Gedeelde helpers voor de naamstroken-tests (node). Laadt de configs zoals Nametape\Config (PHP) ze merget.
 */
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
export const plugin = resolve(here, '../../plugin/borduur-patch-studio');
export const read = (p) => JSON.parse(readFileSync(resolve(plugin, p), 'utf8'));
export const readHere = (p) => JSON.parse(readFileSync(resolve(here, p), 'utf8'));

export const cfg = read('config/nametape.json');
export const metrics = read('config/nametape-metrics.json');
export const defaults = read('config/defaults.json');

/** Diepe merge zoals Settings::deep_merge: objecten recursief, lijsten en scalars vervangen. */
export function merge(base, over) {
  const isObj = (v) => v && typeof v === 'object' && !Array.isArray(v);
  if (!isObj(base) || !isObj(over)) return over;
  const out = { ...base };
  for (const [k, v] of Object.entries(over)) out[k] = isObj(out[k]) && isObj(v) ? merge(out[k], v) : v;
  return out;
}

/** Standaard prijscontext uit defaults.json (btw, staffel, verzending). */
export function defaultCtx(over = {}) {
  return { patchPoolQty: 0, otherNametapePieces: 0, otherItemsInclVat: 0, vatRate: defaults.vat.rate, tiers: defaults.tiers, shipping: defaults.shipping, locale: 'nl-BE', ...over };
}

/** Standaardbatch (10 × 2,5, zwart/wit, blokletter, haak). */
export function batch(over = {}) {
  return {
    v: 1, locale: 'nl-BE', format: '10x2.5', custom: null, fabric: 'black', letter: 'white', border: 'satin_letter', borderColor: null,
    font: 'block', uppercase: true, backing: 'hook', loop: false, bloodMode: null, rows: [{ name: 'PEETERS J.', line2: '', blood: null, qty: 1 }], ...over,
  };
}

export function names(n, prefix = 'NAAM') {
  return Array.from({ length: n }, (_, i) => ({ name: `${prefix} ${i + 1}`, line2: '', blood: null, qty: 1 }));
}
