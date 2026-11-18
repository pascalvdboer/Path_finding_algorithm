// Gedeelde hulp voor de cross-sell-tests (eigenaar: bouwer crosssell). Spiegel: functies in run-php.php.
import { readFileSync } from 'node:fs';
import { deepMerge } from '../../plugin/borduur-patch-studio/assets/src/shared/config.js';
import { quote } from '../../plugin/borduur-patch-studio/assets/src/pricing/index.js';

export const root = new URL('../../', import.meta.url);
const read = (p) => JSON.parse(readFileSync(new URL(p, root)));
export const main = read('plugin/borduur-patch-studio/config/defaults.json');
export const cs = read('plugin/borduur-patch-studio/config/crosssell.json');
export const fitVectors = read('tests/crosssell/fit-vectors.json');
export const rulesVectors = read('tests/crosssell/rules-vectors.json');
export const priceVectors = read('tests/crosssell/price-vectors.json');
export { deepMerge };

export const item = (id, cfg = cs) => cfg.items.find((i) => i.id === id);

/** Patchprijs uit de echte JS-prijsengine (zelfde rol als Pricing::cart_unit_excl in PHP). */
export const patchUnit = (technique, sizeCm, options, qty, poolQty) =>
  quote({ technique, sizeCm, qty, options, poolQty, withLadder: false }, main).unitExcl;

/** Fits-map uit een vector ('all' = elk ingeschakeld item ok). */
export function fitsFor(v, cfg) {
  if (v.fits === 'all') {
    const out = {};
    for (const it of cfg.items) if (it.enabled !== false) out[it.id] = { fit: 'ok' };
    return out;
  }
  const out = {};
  for (const [k, f] of Object.entries(v.fits || {})) out[k] = { fit: f };
  return out;
}
