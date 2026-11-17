// JS engine vs PHP engine: the FULL PriceQuote (numbers, labels, errors, ladder, next tier) must be identical.
// Runs the vectors plus thousands of seeded random inputs and random price configs through both engines.
// Skips when `php` is not installed. Run: npm test
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { quote, quoteAll } from '../../plugin/borduur-patch-studio/assets/src/pricing/index.js';
import { vectors, configFor, defaults } from './lib.js';

const php = spawnSync('php', ['-v'], { encoding: 'utf8' });
const hasPhp = php.status === 0;
const dump = fileURLToPath(new URL('./dump-php.php', import.meta.url));

function runPhp(cases) {
  const r = spawnSync('php', ['-d', 'memory_limit=1G', dump], { input: JSON.stringify(cases), encoding: 'utf8', maxBuffer: 1 << 30 });
  if (r.status !== 0) throw new Error('php failed: ' + r.stderr + r.stdout.slice(0, 500));
  return JSON.parse(r.stdout);
}

/** First differing path, or null. Numbers compared with === (so -0 === 0), null == missing. */
function diff(a, b, path = '$') {
  if (a == null || b == null) return a == null && b == null ? null : `${path}: ${JSON.stringify(a)} vs ${JSON.stringify(b)}`;
  if (typeof a !== 'object' || typeof b !== 'object') return a === b ? null : `${path}: ${JSON.stringify(a)} vs ${JSON.stringify(b)}`;
  if (Array.isArray(a) !== Array.isArray(b)) {
    // PHP encodes an empty object as []; accept {} vs [] when both are empty.
    if (Object.keys(a).length === 0 && Object.keys(b).length === 0) return null;
    return `${path}: array/object mismatch`;
  }
  const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
  for (const k of keys) {
    const d = diff(a[k], b[k], `${path}.${k}`);
    if (d) return d;
  }
  return null;
}

// Deterministic PRNG (mulberry32).
function rng(seed) {
  return () => {
    seed |= 0; seed = (seed + 0x6d2b79f5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function randomCases(n, seed) {
  const r = rng(seed);
  const pick = (arr) => arr[Math.floor(r() * arr.length)];
  const money = (max) => Math.round(r() * max * 100) / 100;
  const optionIds = defaults.options.map((o) => o.id).concat(['nope', '']);
  const techs = ['embroidered', 'printed', 'sublimated', 'pvc', 'woven', 'chenille', 'leather', 'glitter'];
  const cases = [];
  for (let i = 0; i < n; i++) {
    const technique = r() < 0.75 ? pick(techs.slice(0, 4)) : pick(techs);
    const options = [];
    const k = Math.floor(r() * 6);
    for (let j = 0; j < k; j++) options.push(pick(optionIds));
    if (r() < 0.8) {
      // mostly valid: start from the technique's defaults
      const valid = defaults.options.filter((o) => o.techniques.includes(technique));
      const groups = {};
      for (const o of valid) (groups[o.group] ||= []).push(o.id);
      options.length = 0;
      for (const ids of Object.values(groups)) if (r() < 0.97) options.push(pick(ids));
      if (r() < 0.3) options.push(pick(optionIds));
    }
    const input = {
      technique,
      sizeCm: r() < 0.7 ? pick([3, 4, 4.5, 5, 6, 7, 7.5, 8, 9.5, 10, 11, 12, 12.5, 13]) : Math.round(r() * 1500) / 100,
      qty: r() < 0.1 ? pick([0, -2, 1]) : Math.floor(r() * (r() < 0.5 ? 30 : 400)),
      options,
      poolQty: r() < 0.5 ? 0 : Math.floor(r() * 150),
      feesAlreadyCharged: r() < 0.2 ? [pick(['embroidery', 'print', 'pvc_mold'])] : [],
      otherItemsInclVat: r() < 0.5 ? 0 : money(200),
      withLadder: r() < 0.7,
      locale: pick(['nl-BE', 'fr-BE', 'nl_BE', 'fr_FR']),
    };
    let configOverrides = {};
    if (r() < 0.5) {
      // Random price config to stress rounding: 2-decimal amounts, odd percentages, other VAT rates.
      const bp = () => ({ 4: money(9), 6: money(9), 8: money(12), 10: money(14), 12: money(16) });
      configOverrides = {
        vat: { rate: pick([0.21, 0.06, 0.09, 0, 0.19]) },
        tiers: { pooled: r() < 0.7, steps: [{ min: 1, discount: 0 }, { min: pick([5, 10, 12]), discount: pick([0.03, 0.05, 0.07]) }, { min: 25, discount: pick([0.1, 0.125, 0.11]) }, { min: 100, discount: pick([0.2, 0.175, 0.33]) }] },
        shipping: { flat: money(15), freeFromInclVat: pick([150, 99.99, 0, null, 75.5]) },
        fees: { groups: { embroidery: { amount: money(60) }, print: { amount: money(30) }, pvc_mold: { amount: pick([0, money(120)]) } } },
        techniques: { embroidered: { basePrice: bp() }, printed: { basePrice: bp() }, sublimated: { basePrice: bp(), tiers: r() < 0.5 }, pvc: { basePrice: bp(), qtyStep: pick([1, 5, 10]) } },
        options: defaults.options.map((o) => ({ ...o, price: o.price.type === 'percent' ? { type: 'percent', amount: -pick([0.1, 0.15, 0.075, 0.333]) } : { type: o.price.type, amount: r() < 0.5 ? 0 : money(o.price.type === 'per_line' ? 30 : 4) } })),
      };
    }
    cases.push({ input, configOverrides });
  }
  return cases;
}

test('JS and PHP give identical full quotes (vectors + 6000 random cases)', { skip: !hasPhp && 'php not installed' }, () => {
  const cases = [...vectors.map((v) => ({ input: v.input, configOverrides: v.configOverrides })), ...randomCases(6000, 20261117)];
  const out = runPhp(cases);
  assert.equal(out.length, cases.length);
  let compared = 0;
  for (let i = 0; i < cases.length; i++) {
    const js = JSON.parse(JSON.stringify(quote(cases[i].input, configFor(cases[i].configOverrides))));
    const d = diff(js, out[i]);
    assert.equal(d, null, `case ${i} ${JSON.stringify(cases[i].input)} → ${d}`);
    compared++;
  }
  assert.equal(compared, cases.length);
});

test('JS quoteAll and PHP quote_all are identical', { skip: !hasPhp && 'php not installed' }, () => {
  const inputs = [
    { sizeCm: 8, qty: 10, options: [] },
    { sizeCm: 4, qty: 1, options: [], locale: 'fr-BE', poolQty: 30 },
    { sizeCm: 12, qty: 55, options: [], withLadder: true, otherItemsInclVat: 40 },
    { sizeCm: 7.5, qty: 0, options: [] },
  ];
  const cases = inputs.map((input) => ({ input, configOverrides: {}, all: true }));
  const out = runPhp(cases);
  inputs.forEach((input, i) => {
    const js = JSON.parse(JSON.stringify(quoteAll(input, configFor({}))));
    assert.equal(diff(js, out[i]), null);
  });
});
