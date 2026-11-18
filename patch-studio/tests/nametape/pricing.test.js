import test from 'node:test';
import assert from 'node:assert/strict';
import { readHere, cfg, merge, defaultCtx, batch, names } from './lib.js';
import { quote, tierFor, rowQty } from '../../plugin/borduur-patch-studio/assets/src/nametape/pricing.js';

const V = readHere('price-vectors.json');

for (const c of V.cases) {
  test(`quote: ${c.name}`, () => {
    assert.deepEqual(quote(c.batch, defaultCtx(c.ctx), c.cfg ? merge(cfg, c.cfg) : cfg), c.expect);
  });
}

// Vaste controlebedragen uit SPEC-EXTRA §1.7 (hand-gecontroleerd, los van de gegenereerde vectors).
test('controle §1.7: 25 namen 10 × 2,5 haak zwart', () => {
  const q = quote(batch({ rows: names(25) }), defaultCtx(), cfg);
  assert.equal(q.mainUnitExcl, 4.05);
  assert.equal(q.lineExcl, 101.25);
  assert.equal(q.lineIncl, 122.51);
  assert.equal(q.shippingIncl, 12.1);
  assert.equal(q.totalIncl, 134.61);
  assert.equal(q.unitIncl, 4.9);
  assert.deepEqual(q.tier, { min: 25, discount: 0.1 });
  assert.deepEqual(q.nextTier, { min: 50, needed: 25, discount: 0.15, unitIncl: 4.63 });
});

test('controle §1.7: 1 strook = € 5,45 incl.; met instelkost € 14,53 + verzending', () => {
  assert.equal(quote(batch(), defaultCtx(), cfg).lineIncl, 5.45);
  const q = quote(batch(), defaultCtx(), merge(cfg, { smallOrderFee: { enabled: true } }));
  assert.equal(Math.round((q.lineIncl + q.feesIncl) * 100) / 100, 14.53);
  assert.equal(q.totalIncl, 26.63);
  assert.equal(q.fees[0].key, 'fee:nametape_setup');
});

test('controle §1.7: 10 × 12 × 3 opnaaien → 5 % staffel', () => {
  const q = quote(batch({ format: '12x3', backing: 'sew', rows: names(10) }), defaultCtx(), cfg);
  assert.equal(q.tier.discount, 0.05);
  assert.equal(q.mainUnitExcl, 4.0375); // (5,00 − 0,75) × 0,95
});

test('controle §1.7: pool 20 patches + 5 stroken → 10 %', () => {
  assert.equal(quote(batch({ rows: names(5) }), defaultCtx({ patchPoolQty: 20 }), cfg).tier.discount, 0.1);
});

test('bloedgroep op eigen strook telt als extra stuk in prijs en staffel', () => {
  const q = quote(batch({ bloodMode: 'separate', rows: [...names(8), { name: 'X', line2: '', blood: { group: 'O', rh: '+' }, qty: 2 }] }), defaultCtx(), cfg);
  assert.equal(q.mainPieces, 10);
  assert.equal(q.bloodPieces, 2);
  assert.equal(q.pieces, 12);
  assert.equal(q.bloodUnitExcl, 2.85); // 3,00 × 0,95
});

test('tegenstuk enkel bij klittenband (haak)', () => {
  assert.equal(quote(batch({ loop: true }), defaultCtx(), cfg).mainUnitExcl, 5.5);
  assert.equal(quote(batch({ loop: true, backing: 'sew' }), defaultCtx(), cfg).mainUnitExcl, 3.75);
});

test('eigen maat: eerste prijsrij die past; buiten bereik = fout', () => {
  assert.equal(quote(batch({ format: 'custom', custom: { w: 140, h: 30 } }), defaultCtx(), cfg).mainUnitExcl, 5.5);
  assert.equal(quote(batch({ format: 'custom', custom: { w: 100, h: 25 } }), defaultCtx(), cfg).mainUnitExcl, 4.5);
  assert.deepEqual(quote(batch({ format: 'custom', custom: { w: 100, h: 27 } }), defaultCtx(), cfg).errors, [{ code: 'size_out_of_range' }]);
});

test('labels in het Frans', () => {
  const q = quote(batch({ rows: names(2) }), defaultCtx({ locale: 'fr-BE' }), cfg);
  assert.match(q.breakdown[0].label, /bande nominative/);
  assert.match(q.breakdown.at(-1).label, /Livraison/);
});

test('hulpfuncties', () => {
  assert.deepEqual(tierFor(9, { steps: [{ min: 1, discount: 0 }, { min: 10, discount: 0.05 }] }), { min: 1, discount: 0 });
  assert.equal(rowQty('3'), 3);
  assert.equal(rowQty(-4), 1);
  assert.equal(rowQty('x'), 1);
  assert.equal(rowQty(150), 99);
});
