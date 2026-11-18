// Cross-sell: pastoetsen, kaartkeuze, prijzen, kaarten en JS ↔ PHP-pariteit (SPEC-EXTRA §2, §5).
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { fitItem, designFromPayload, defaultQty, pickDefaultVariation } from '../../plugin/borduur-patch-studio/assets/src/crosssell/fit.js';
import { pick } from '../../plugin/borduur-patch-studio/assets/src/crosssell/rules.js';
import { priceItem, patchOptions, ladderPatchUnit, ladderPoints } from '../../plugin/borduur-patch-studio/assets/src/crosssell/pricing.js';
import { buildOffers, cardPrice, linkProducts } from '../../plugin/borduur-patch-studio/assets/src/crosssell/offers.js';
import { MESSAGES, t } from '../../plugin/borduur-patch-studio/assets/src/crosssell/messages.js';
import { main, cs, fitVectors, rulesVectors, priceVectors, deepMerge, item, patchUnit, fitsFor, root } from './lib.js';

const samples = JSON.parse(readFileSync(new URL('tests/crosssell/samples.json', root)));
const cfgLinked = linkProducts(cs, samples.productLinks);

const designOf = (v) => designFromPayload(deepMerge(fitVectors.base, v.design || {}));

/** Velden die een vector verwacht (subset-vergelijking). */
function expectSubset(actual, expect, name) {
  for (const [k, v] of Object.entries(expect)) assert.deepEqual(actual[k], v, `${name}: ${k}`);
}

test('fit-vectors: minstens 20 en elk resultaat klopt', () => {
  assert.ok(fitVectors.fit.length >= 20);
  for (const v of fitVectors.fit) {
    const d = designOf(v);
    const tech = main.techniques[d.technique] || null;
    const r = fitItem(item(v.item), d, { tech, cartQty: d.qty });
    expectSubset(r, v.expect, v.name);
  }
});

test('standaardvariatie: contrast met de rand, enkel rustige kleuren', () => {
  for (const v of fitVectors.variations) {
    assert.equal(pickDefaultVariation(v.variations, v.edgeHex, cs.defaultColors), v.expect, v.name);
  }
});

test('standaardaantal: team = zelfde aantal, cadeau = 1, PVC = minimum in stappen', () => {
  assert.equal(defaultQty(item('cap'), 1), 1);
  assert.equal(defaultQty(item('cap'), 9), 1);
  assert.equal(defaultQty(item('cap'), 25), 25);
  assert.equal(defaultQty(item('pvc_keychain'), 12), 50);
  assert.equal(defaultQty(item('pvc_keychain'), 55), 60);
});

test('rules-vectors: volgorde volgt bestelling en pastoets', () => {
  for (const v of rulesVectors.rules) {
    const cfg = deepMerge(cs, v.cfg || {});
    const r = pick(fitsFor(v, cfg), v.context, cfg, v.stats || {});
    assert.deepEqual(r.ids, v.expect.ids, v.name);
    if (v.expect.rule) assert.equal(r.rule, v.expect.rule, v.name + ' (regel)');
  }
});

test('price-vectors: bedragen uit 02 §3b–3d', () => {
  for (const v of priceVectors.prices) {
    const r = priceItem(item(v.item), v.input, { cfg: cs, main, patchUnit, locale: v.locale || 'nl-BE' });
    const e = v.expect;
    for (const k of ['ok', 'errors', 'unitExcl', 'unitIncl', 'lineIncl', 'lineExcl', 'tierDiscount']) {
      if (k in e) assert.deepEqual(r[k], e[k], `${v.name}: ${k}`);
    }
    if (e.parts) assert.deepEqual(r.parts.map((p) => p.incl), e.parts, v.name + ': parts');
    if (e.fees) assert.deepEqual(r.fees.map((f) => f.incl), e.fees, v.name + ': fees');
    if (e.labels) assert.deepEqual(r.parts.map((p) => p.label), e.labels, v.name + ': labels');
  }
});

test('achterkant wordt opnaaien; klittenband-extra\'s vallen weg', () => {
  assert.deepEqual(patchOptions(['cut_from_fabric', 'velcro_hook', 'velcro_black', 'thickness_normal'], main), ['cut_from_fabric', 'thickness_normal', 'sew_on']);
  assert.deepEqual(patchOptions(['iron_on'], main), ['sew_on']);
});

test('ladder: patchprijs per staffelpunt, met pool', () => {
  assert.deepEqual(ladderPoints(main, 0, true), [1, 10, 25, 50, 100]);
  assert.deepEqual(ladderPoints(main, 20, true), [1, 5, 30, 80]);
  const f = ladderPatchUnit([{ qty: 1, excl: 4.04 }, { qty: 10, excl: 3.838 }]);
  assert.equal(f('', 6, [], 9), 4.04);
  assert.equal(f('', 6, [], 10), 3.838);
});

const env = (extra = {}) => Object.assign({ cfg: cfgLinked, main, catalog: samples.catalog, patchUnit, locale: 'nl-BE', month: 6 }, extra);

test('voorbeeldontwerpen tonen de juiste kaarten (SPEC-EXTRA §5)', () => {
  for (const s of samples.designs) {
    const o = buildOffers({ payload: s.payload, designId: s.designId, ref: s.ref }, env());
    const ids = o.cards.map((c) => c.id);
    if (s.expect.ids) assert.deepEqual([...ids].sort(), [...s.expect.ids].sort(), s.id + ': ' + ids.join(','));
    for (const n of s.expect.notIds || []) assert.ok(!ids.includes(n), s.id + ' toont ' + n);
    for (const n of s.expect.includes || []) assert.ok(ids.includes(n), s.id + ' mist ' + n);
    assert.ok(ids.length <= 3);
    for (const c of o.cards) {
      assert.ok(c.unitIncl > 0, c.id + ' prijs');
      assert.ok(c.why && c.title, c.id + ' teksten');
      assert.equal(c.options.length >= 0, true);
    }
  }
});

test('kaart: volledige prijs incl. patch en aanbrengen, opbouw klopt', () => {
  const s = samples.designs.find((x) => x.id === 'flat25');
  const o = buildOffers({ payload: s.payload }, env());
  const cap = o.cards.find((c) => c.id === 'cap');
  assert.equal(cap.fit, 'adjust');
  assert.equal(cap.patchSizeCm, 6);
  assert.match(cap.adjustNote, /6 cm/);
  assert.equal(cap.defaultQty, 25);
  // standaardkleur: navy patch met zwarte rand → contrast → wit/grijs, nooit een felle kleur
  const v = cap.variations.find((x) => x.id === cap.defaultVariationId);
  assert.ok(cs.defaultColors.includes(v.colorSlug), v.colorSlug);
  // 25 petten + 25 patches → staffel; cardPrice = zelfde als priceItem met de echte engine
  const direct = priceItem(item('cap'), { qty: 25, poolQty: 0, blankExcl: v.priceExcl, technique: 'embroidered', patchSizeCm: 6, designOptions: s.payload.options }, { cfg: cs, main, patchUnit });
  assert.equal(cap.unitIncl, direct.unitIncl);
  const p10 = cardPrice(cap, { variationId: v.id, qty: 10 }, o.pricing, 'nl-BE');
  const d10 = priceItem(item('cap'), { qty: 10, blankExcl: v.priceExcl, technique: 'embroidered', patchSizeCm: 6, designOptions: s.payload.options }, { cfg: cs, main, patchUnit });
  assert.equal(p10.unitIncl, d10.unitIncl);
});

test('hoodie: maatkeuze nodig, prijs per maat volgt de variatie', () => {
  const s = samples.designs.find((x) => x.id === 'flat25');
  const o = buildOffers({ payload: s.payload }, env());
  const h = o.cards.find((c) => c.id === 'hoodie');
  assert.equal(h.needsSize, true);
  const xxl = h.variations.find((v) => v.productId === 16400 && v.size === '3XL');
  assert.ok(xxl);
  const a = cardPrice(h, { variationId: xxl.id, productId: 16400, qty: 1 }, o.pricing);
  const b = cardPrice(h, { variationId: h.variations.find((v) => v.productId === 16400 && v.size === 'M').id, productId: 16400, qty: 1 }, o.pricing);
  assert.ok(a.unitIncl > b.unitIncl);
});

test('foto: lederkaart met eerlijk bruintinten-label, geen PVC', () => {
  const s = samples.designs.find((x) => x.id === 'photo1');
  const o = buildOffers({ payload: s.payload }, env());
  const l = o.cards.find((c) => c.id === 'leather_keychain');
  assert.equal(l.adjustNote, MESSAGES.nl['cs.leather.photo']);
  assert.equal(l.mockup.mono, true);
  const f = o.cards.find((c) => c.id === 'frame');
  assert.equal(f.needsPlate, true);
  assert.equal(f.title, 'Schaduwdoos 20 × 20 cm');
});

test('PVC: minimum, levertijd en mal staan op de kaart; gedeelde mal enkel met sharedMold', () => {
  const s = samples.designs.find((x) => x.id === 'pvc50');
  const o = buildOffers({ payload: s.payload }, env());
  const p = o.cards.find((c) => c.id === 'pvc_keychain');
  assert.equal(p.minQty, 50);
  assert.equal(p.qtyStep, 10);
  assert.equal(p.defaultQty, 50);
  assert.match(p.note, /Vanaf 50 stuks/);
  assert.match(p.note, /4 weken/);
  assert.match(p.note, /96,80/);
  const o2 = buildOffers({ payload: s.payload }, env({ cart: { pvcMoldCovered: true } }));
  assert.match(o2.cards.find((c) => c.id === 'pvc_keychain').note, /96,80/, 'sharedMold staat standaard uit');
  const shared = deepMerge(cfgLinked, {});
  shared.items = shared.items.map((i) => (i.id === 'pvc_keychain' ? Object.assign({}, i, { sharedMold: true }) : i));
  const o3 = buildOffers({ payload: s.payload }, env({ cfg: shared, cart: { pvcMoldCovered: true } }));
  const p3 = o3.cards.find((c) => c.id === 'pvc_keychain');
  assert.equal(p3.fees.length, 0);
});

test('borduurkaart-melding enkel als ze waar is', () => {
  const s = samples.designs.find((x) => x.id === 'flat25');
  assert.equal(buildOffers({ payload: s.payload }, env()).feeNote, null);
  assert.equal(buildOffers({ payload: s.payload }, env({ cart: { feeGroups: ['embroidery'] } })).feeNote, MESSAGES.nl['cs.feeNote']);
  const design = deepMerge(main, { fees: { scope: 'design' } });
  assert.equal(buildOffers({ payload: s.payload }, env({ main: design, cart: { feeGroups: ['embroidery'] } })).feeNote, null);
});

test('niets koopbaar → geen kaart', () => {
  const s = samples.designs.find((x) => x.id === 'photo1');
  const o = buildOffers({ payload: s.payload }, env({ catalog: {} }));
  assert.equal(o.cards.length, 0);
});

test('teksten: NL en FR hebben dezelfde sleutels, geen druk- of schaarstewoorden', () => {
  assert.deepEqual(Object.keys(MESSAGES.fr).sort(), Object.keys(MESSAGES.nl).sort());
  const all = Object.values(MESSAGES.nl).join(' ') + Object.values(MESSAGES.fr).join(' ');
  for (const bad of ['nog maar', 'laatste kans', 'meest gekozen', 'populair', 'dernière chance', 'plus populaire', 'bijna uitverkocht']) {
    assert.ok(!all.toLowerCase().includes(bad), bad);
  }
  assert.equal(t('cs.pvc.min', 'fr-BE', { n: 50 }).startsWith('À partir de 50'), true);
  for (const it of cs.items) {
    assert.ok(it.label && it.label.nl && it.label.fr, it.id + ' label');
    assert.ok(it.why && it.why.nl && it.why.fr, it.id + ' why');
  }
});

test('config: echte Woo-ID\'s, standaard eerlijk (sharedMold uit, autoDisable uit)', () => {
  assert.deepEqual(item('cap').wcProductIds, [36262, 36278, 36027, 36247]);
  assert.deepEqual(item('beanie').wcProductIds, [36237, 36225, 36209, 36178]);
  assert.deepEqual(item('hoodie').wcProductIds, [15590, 16400, 15324]);
  assert.equal(item('pvc_keychain').sharedMold, false);
  assert.equal(cs.stats.autoDisable, false);
  assert.equal(cs.maxCards, 3);
});

// ---------------------------------------------------------------- JS ↔ PHP
const php = spawnSync('php', ['-v'], { encoding: 'utf8' });
test('PHP-tweeling geeft exact dezelfde resultaten', { skip: php.status !== 0 ? 'php niet beschikbaar' : false }, () => {
  const script = fileURLToPath(new URL('tests/crosssell/run-php.php', root));
  const r = spawnSync('php', [script, '--dump'], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
  assert.equal(r.status, 0, r.stderr || r.stdout);
  const dump = JSON.parse(r.stdout);
  fitVectors.fit.forEach((v, i) => {
    const d = designOf(v);
    const js = fitItem(item(v.item), d, { tech: main.techniques[d.technique] || null, cartQty: d.qty });
    assert.deepEqual(dump.fit[i], js, 'fit ' + v.name);
  });
  fitVectors.variations.forEach((v, i) => assert.equal(dump.variations[i], pickDefaultVariation(v.variations, v.edgeHex, cs.defaultColors), v.name));
  rulesVectors.rules.forEach((v, i) => {
    const cfg = deepMerge(cs, v.cfg || {});
    assert.deepEqual(dump.rules[i], pick(fitsFor(v, cfg), v.context, cfg, v.stats || {}), 'rules ' + v.name);
  });
  priceVectors.prices.forEach((v, i) => {
    const js = priceItem(item(v.item), v.input, { cfg: cs, main, patchUnit, locale: v.locale || 'nl-BE' });
    assert.deepEqual(dump.prices[i], js, 'price ' + v.name);
  });
  assert.deepEqual(dump.messages, MESSAGES, 'klantteksten JS = PHP');
  // Ontwerp-normalisatie en ΔL* van de voorbeeldontwerpen.
  samples.designs.forEach((s, i) => assert.deepEqual(dump.designs[i], designFromPayload(s.payload), 'design ' + s.id));
  // Kaarten van de voorbeeldontwerpen: zelfde kaarten, prijzen en standaardvariatie.
  samples.designs.forEach((s, i) => {
    const o = buildOffers({ payload: s.payload }, env());
    const js = o.cards.map((c) => ({ id: c.id, fit: c.fit, unitIncl: c.unitIncl, defaultQty: c.defaultQty, defaultVariationId: c.defaultVariationId, patchSizeCm: c.patchSizeCm, adjustNote: c.adjustNote, title: c.title, note: c.note, breakdown: c.breakdown, ladder: c.price.patchLadder, variations: c.variations.length }));
    assert.deepEqual(dump.offers[i], js, 'offers ' + s.id);
  });
});
