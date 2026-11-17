// Pricing engine (JS) against the shared vectors + unit tests. Run: npm test
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { quote, quoteAll, tierFor, savingHint, optionEffect, errorMessage, normalizeQty, formatSize } from '../../plugin/borduur-patch-studio/assets/src/pricing/index.js';
import { buildPublicConfig, defaultOptions } from '../../plugin/borduur-patch-studio/assets/src/shared/config.js';
import { vectors, configFor, check, defaults } from './lib.js';

// formatMoney uses a no-break space between € and the amount.
const sp = (s) => s.replace(/\u00a0/g, ' ');

for (const v of vectors) {
  test(`vector: ${v.name}`, () => {
    const q = quote(v.input, configFor(v.configOverrides));
    assert.deepEqual(check(v, q), []);
  });
}

test('at least the 9 checkout examples + 10 edge cases are present', () => {
  assert.ok(vectors.filter((v) => v.name.startsWith('kassa:')).length >= 9);
  assert.ok(vectors.length >= 19);
});

test('works with the public config (as in the browser)', () => {
  const pub = buildPublicConfig(defaults, { threads: [] }, { locale: 'nl-BE' });
  const q = quote({ technique: 'embroidered', sizeCm: 4, qty: 1, options: defaultOptions(pub, 'embroidered') }, pub);
  assert.equal(q.totalIncl, 69.58);
  assert.equal(q.ok, true);
});

test('breakdown sums to the total', () => {
  const cfg = configFor();
  for (const t of ['embroidered', 'printed', 'sublimated', 'pvc']) {
    for (const qty of [50, 60, 100, 250]) {
      const q = quote({ technique: t, sizeCm: 8, qty, options: [...defaultOptions(cfg, t), 'thread_roll_black'] }, cfg);
      const sum = q.breakdown.reduce((s, l) => s + l.incl, 0);
      assert.ok(Math.abs(sum - q.totalIncl) < 0.005, `${t} ${qty}: ${sum} vs ${q.totalIncl}`);
      assert.equal(q.breakdown[0].key, 'item');
      assert.equal(q.breakdown.at(-1).key, 'shipping');
    }
  }
});

test('tierFor picks the highest step <= qty, unsorted steps too', () => {
  const cfg = configFor({ tiers: { steps: [{ min: 50, discount: 0.15 }, { min: 1, discount: 0 }, { min: 10, discount: 0.05 }] } });
  assert.deepEqual(tierFor(0, cfg), { min: 1, discount: 0 });
  assert.deepEqual(tierFor(9, cfg), { min: 1, discount: 0 });
  assert.deepEqual(tierFor(10, cfg), { min: 10, discount: 0.05 });
  assert.deepEqual(tierFor(1000, cfg), { min: 50, discount: 0.15 });
});

test('quoteAll: every enabled technique, own defaults, qty normalised (pvc -> 50)', () => {
  const cfg = configFor();
  const all = quoteAll({ sizeCm: 8, qty: 10, options: ['on_film'] }, cfg);
  assert.deepEqual(Object.keys(all).sort(), ['chenille', 'embroidered', 'leather', 'printed', 'pvc', 'sublimated', 'woven']);
  assert.equal(all.embroidered.ok, true);
  assert.equal(all.embroidered.qtyAdjusted, false);
  assert.equal(all.pvc.qty, 50);
  assert.equal(all.pvc.qtyAdjusted, true);
  assert.equal(all.pvc.ok, true);
  assert.equal(all.woven.mode, 'quote');
  assert.deepEqual(all.embroidered.ladder, []);
});

test('normalizeQty and formatSize', () => {
  assert.equal(normalizeQty({ minQty: 50, qtyStep: 10 }, 55), 60);
  assert.equal(normalizeQty({ minQty: 50, qtyStep: 10 }, 3), 50);
  assert.equal(normalizeQty({}, 0), 1);
  assert.equal(formatSize(7.5), '7,5');
  assert.equal(formatSize(8), '8');
});

test('savingHint: SPEC example 24 -> 25 pieces is honest about the total', () => {
  const cfg = configFor();
  const q = quote({ technique: 'embroidered', sizeCm: 8, qty: 24, options: defaultOptions(cfg, 'embroidered') }, cfg);
  const h = savingHint(q, 'nl-BE');
  assert.equal(h.kind, 'cheaper_total');
  assert.match(sp(h.main.text), /25 stuks kosten samen € 208,12/);
  assert.match(sp(h.main.text), /€ 1,89 minder/);
  const fr = savingHint(q, 'fr-BE');
  assert.match(sp(fr.main.text), /25 pièces/);
});

test('savingHint: normal next tier with honest counter-hint; hidden when out of reach or no tiers', () => {
  const cfg = configFor();
  const q = quote({ technique: 'embroidered', sizeCm: 8, qty: 15, options: defaultOptions(cfg, 'embroidered') }, cfg);
  const h = savingHint(q, 'nl-BE');
  assert.equal(h.kind, 'next_tier');
  assert.equal(sp(h.main.text), 'Bij 25 stuks betaal je € 5,66 per stuk (nu € 5,98).');
  assert.equal(sp(h.secondary.text), 'Heb je er maar 15 nodig? Bestel er dan gewoon 15.');
  assert.match(sp(h.extra.text), /10 patches extra/);
  const far = quote({ technique: 'embroidered', sizeCm: 8, qty: 5, options: defaultOptions(cfg, 'embroidered') }, cfg);
  assert.equal(far.nextTier.qty, 10);
  assert.equal(far.nextTier.withinReach, true); // 10 <= 2 x 5
  assert.equal(savingHint(far).kind, 'next_tier');
  const one = quote({ technique: 'embroidered', sizeCm: 8, qty: 1, options: defaultOptions(cfg, 'embroidered') }, cfg);
  assert.equal(one.nextTier.withinReach, false);
  assert.equal(savingHint(one), null);
  const sub = quote({ technique: 'sublimated', sizeCm: 8, qty: 9, options: defaultOptions(cfg, 'sublimated') }, cfg);
  assert.equal(sub.nextTier, null);
  assert.equal(savingHint(sub), null);
});

test('optionEffect texts incl. VAT', () => {
  const cfg = configFor();
  const byId = (id) => cfg.options.find((o) => o.id === id);
  assert.equal(sp(optionEffect(byId('iron_on'), 'embroidered', cfg).text), '+ € 1,82/st.');
  assert.equal(optionEffect(byId('self_cut'), 'embroidered', cfg).text, '− 10 %');
  assert.equal(optionEffect(byId('sew_on'), 'embroidered', cfg).text, 'inbegrepen');
  assert.equal(sp(optionEffect(byId('velcro_hook'), 'pvc', cfg).text), '+ € 1,21/st.');
  assert.equal(sp(optionEffect(byId('carrier_flock'), 'sublimated', cfg, 'fr-BE').text), '+ 0,91 € (unique)');
});

test('errorMessage uses config group labels', () => {
  const cfg = configFor();
  assert.equal(errorMessage({ code: 'missing_required_option', params: { group: 'edge' } }, cfg).text, 'Kies nog een optie bij "Rand".');
  assert.equal(errorMessage({ code: 'below_min_qty', params: { min: 50 } }, cfg).text, 'Deze techniek maken we vanaf 50 stuks.');
});

test('performance: quote with ladder < 5 ms', () => {
  const cfg = configFor();
  const input = { technique: 'embroidered', sizeCm: 8, qty: 30, options: defaultOptions(cfg, 'embroidered'), poolQty: 5 };
  for (let i = 0; i < 50; i++) quote(input, cfg);
  const t0 = performance.now();
  for (let i = 0; i < 200; i++) quote(input, cfg);
  assert.ok((performance.now() - t0) / 200 < 5);
});

test('garbage input never throws', () => {
  const cfg = configFor();
  for (const input of [null, {}, { technique: {}, qty: 'x', sizeCm: NaN, options: 'a' }, { technique: 'embroidered', sizeCm: 8, qty: -3, options: [null, {}, 5] }]) {
    const q = quote(input, cfg);
    assert.equal(q.ok, false);
  }
  assert.equal(quote({ technique: 'embroidered', sizeCm: 8, qty: 1 }, null).ok, false);
});
