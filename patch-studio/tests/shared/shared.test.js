// Tests voor de gedeelde contractmodules (eigenaar: architect). Draai: npm test
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { deltaE2000, hexToLab, rgbToLab, labToHex, contrastRatio } from '../../plugin/borduur-patch-studio/assets/src/shared/color-math.js';
import { roundHalfUp, round2, formatMoney } from '../../plugin/borduur-patch-studio/assets/src/shared/money.js';
import { createT } from '../../plugin/borduur-patch-studio/assets/src/shared/i18n.js';
import { layoutPatch, requiredSizeCm } from '../../plugin/borduur-patch-studio/assets/src/shared/geometry.js';
import { buildPublicConfig, defaultOptions, deepMerge } from '../../plugin/borduur-patch-studio/assets/src/shared/config.js';

const base = new URL('../../plugin/borduur-patch-studio/config/', import.meta.url);
const defaults = JSON.parse(readFileSync(new URL('defaults.json', base)));
const palette = JSON.parse(readFileSync(new URL('thread-palette.json', base)));

test('CIEDE2000 Sharma-testparen', () => {
  const pairs = [
    [[50, 2.6772, -79.7751], [50, 0, -82.7485], 2.0425],
    [[50, 2.5, 0], [50, 0, -2.5], 4.3065],
    [[50, 2.5, 0], [56, -27, -3], 31.9030],
    [[60.2574, -34.0099, 36.2677], [60.4626, -34.1751, 39.4387], 1.2644],
    [[2.0776, 0.0795, -1.135], [0.9033, -0.0636, -0.5514], 0.9082],
  ];
  for (const [a, b, e] of pairs) assert.equal(Math.round(deltaE2000(a, b) * 1e4) / 1e4, e);
});

test('Lab round-trip', () => {
  for (const h of ['#21a9e2', '#5926c5', '#fd5c48', '#000000', '#ffffff']) assert.equal(labToHex(hexToLab(h)), h);
  assert.ok(Math.abs(rgbToLab(255, 255, 255)[0] - 100) < 0.01);
  assert.ok(contrastRatio('#5926C5', '#ffffff') > 8.3);
});

test('afronding half-up identiek aan PHP-variant', () => {
  assert.equal(round2(230.175), 230.18);
  assert.equal(round2(28.7375), 28.74);
  assert.equal(round2(1.005), 1.01);
  assert.equal(roundHalfUp(2.37499, 4), 2.375);
  assert.equal(round2(45 * 0.21), 9.45);
  assert.match(formatMoney(5.98, 'nl-BE'), /€\s?5,98/);
  assert.match(formatMoney(5.98, 'fr-BE'), /5,98\s?€/);
});

test('i18n met meervoud en fallback', () => {
  const t = createT([{ nl: { a: 'Hallo {naam}', p: { one: '{n} stuk', other: '{n} stuks' } }, fr: { a: 'Bonjour {naam}' } }], 'fr-BE');
  assert.equal(t('a', { naam: 'X' }), 'Bonjour X');
  assert.equal(t('p', { n: 1 }), '1 stuk'); // FR ontbreekt → NL
  const nl = createT([{ nl: { p: { one: '{n} stuk', other: '{n} stuks' } } }], 'nl-BE');
  assert.equal(nl('p', { n: 3 }), '3 stuks');
});

test('publieke config + standaardopties', () => {
  const c = buildPublicConfig(defaults, palette, { locale: 'nl-BE' });
  assert.equal(c.woo, undefined);
  assert.equal(c.techniques.embroidered.legacy, undefined);
  assert.ok(c.threads.length > 0);
  assert.deepEqual(defaultOptions(c, 'embroidered'), ['cut_from_fabric', 'sew_on', 'thickness_normal']);
  assert.deepEqual(defaultOptions(c, 'sublimated'), ['embroidered_border', 'sew_on', 'thickness_normal', 'carrier_standard']);
  assert.deepEqual(defaultOptions(c, 'pvc'), ['molded', 'sew_on']);
  assert.deepEqual(deepMerge({ a: { b: 1, c: [1, 2] } }, { a: { c: [3] } }), { a: { b: 1, c: [3] } });
});

test('layout: logo in ronde patch (contain) en ontwerp dat de patch vult', () => {
  const c = buildPublicConfig(defaults, palette);
  const design = { technique: 'embroidered', sizeCm: 8, shape: 'circle', options: ['cut_from_fabric'], art: { scale: 1, offsetMm: { x: 0, y: 0 } } };
  const an = { artBox: { x: 0, y: 0, w: 360, h: 180 }, shape: { fillsPatch: false, aspect: 2 } };
  const L = layoutPatch(design, an, c);
  assert.equal(L.patchWmm, 80);
  assert.equal(L.patchHmm, 80);
  assert.ok(Math.abs(L.artRectMm.w - (80 - 4) * 0.8) < 1e-9); // safeZone 2 mm rondom, fill 0.8
  assert.ok(Math.abs(L.mmPerArtPx - L.artRectMm.w / 360) < 1e-12);
  const full = layoutPatch(design, { artBox: { x: 0, y: 0, w: 300, h: 300 }, shape: { fillsPatch: true, aspect: 1 } }, c);
  assert.deepEqual(full.artRectMm, { x: 0, y: 0, w: 80, h: 80 });
  assert.equal(requiredSizeCm(6, 3, 4, [4, 6, 8, 10, 12], 12), 8);
  assert.equal(requiredSizeCm(6, 1, 4, [4, 6, 8, 10, 12], 12), null);
});
