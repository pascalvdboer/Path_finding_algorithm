/**
 * Diensten-JS (assets/src/services/index.js): dezelfde uitkomst als PHP Services::evaluate() op de gedeelde vectoren,
 * catalogus identiek aan Services::catalog(), eerlijke teksten, afwijzing onthouden. Run: npm test
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { evaluate, catalogFromConfig, matches, money, dismissedFor, rememberDismiss } from '../../plugin/borduur-patch-studio/assets/src/services/index.js';
import svcMessages from '../../plugin/borduur-patch-studio/assets/src/services/messages.js';
import { createServicesApi } from '../../plugin/borduur-patch-studio/assets/src/services/api.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const services = JSON.parse(fs.readFileSync(path.join(root, 'plugin/borduur-patch-studio/config/ai-services.json'), 'utf8'));
const vectors = JSON.parse(fs.readFileSync(path.join(here, 'offer-vectors.json'), 'utf8'));
const withRush = () => {
  const s = JSON.parse(JSON.stringify(services));
  s.services.d4_rush.enabled = true;
  return s;
};

test('evaluate(): alle gedeelde vectoren (incl. "dismissed")', () => {
  const cat = catalogFromConfig(services, 'nl');
  for (const v of vectors.vectors) {
    const o = evaluate(v.signals, cat, v.dismissed || []);
    assert.equal(o ? o.serviceId : null, v.expect, v.name);
    if (o) {
      assert.equal(o.preselected, false, 'nooit vooraf gekozen: ' + v.name);
      assert.ok(o.decline && o.cta.includes(o.priceHtml), 'altijd "Nee, dank je" en de prijs op de knop: ' + v.name);
      assert.ok(!/\{\w+\}/.test(o.cta + o.reason + (o.freeAlternative || '')), 'geen open plaatshouders: ' + v.name);
    }
  }
});

test('evaluate(): spoed aan (rushEnabled) met prijs max(min, 25 %)', () => {
  const cat = catalogFromConfig(withRush(), 'nl');
  for (const v of vectors.rushEnabled) {
    const o = evaluate(v.signals, cat, []);
    assert.equal(o ? o.serviceId : null, v.expect, v.name);
    if (v.priceIncl != null && o) assert.equal(o.priceIncl, v.priceIncl, 'spoedprijs: ' + v.name);
  }
});

test('max 1 aanbod, volgorde = catalogus, uitgeschakeld = nooit', () => {
  const cat = catalogFromConfig(services, 'nl');
  assert.ok(!cat.some((d) => d.id === 'd4_rush' || d.id === 'd5_extra_proof'), 'D4/D5 standaard uit');
  // Klein bestand én schets: D2 komt eerst (volgorde in ai-services.json).
  const o = evaluate({ stage: 'analysis', analysis: { minSidePx: 100, sketch: true } }, cat, []);
  assert.equal(o.serviceId, 'd2_redraw');
  assert.equal(evaluate({ stage: 'analysis', analysis: { minSidePx: 100 } }, cat.map((d) => Object.assign({}, d, { enabled: false })), []), null);
  assert.equal(evaluate({}, cat, []), null, 'geen fase: niets');
  assert.equal(evaluate({ stage: 'analysis' }, null, null), null, 'geen catalogus: niets, geen fout');
});

test('D7 gratis vanaf freeFromQty; geld zoals de server', () => {
  const def = { id: 'd7_photo_test', enabled: true, kind: 'line_option', priceIncl: 9.95, stages: ['price'], triggers: {}, freeFromQty: 25 };
  assert.equal(matches(def, { stage: 'price' }), false, 'D7 enkel via PHP-filter');
  assert.equal(money(24.95, 'nl'), '€ 24,95');
  assert.equal(money(1234.5, 'fr'), '1.234,50 €');
  assert.equal(money(0.495, 'nl'), '€ 0,50', 'half-up zoals PHP');
});

test('FR: teksten en knop in het Frans', () => {
  const cat = catalogFromConfig(services, 'fr');
  const o = evaluate({ stage: 'analysis', analysis: { minSidePx: 220 }, lang: 'fr' }, cat, []);
  assert.equal(o.label, 'Faites redessiner votre logo');
  assert.ok(o.reason.includes('220 px'));
  assert.equal(o.decline, 'Non merci');
  assert.ok(o.cta.startsWith('Ajouter · '));
});

test('PHP-pariteit: catalogus en elk aanbod identiek aan Services::catalog()/evaluate() (als PHP aanwezig is)', (t) => {
  const r = spawnSync('php', [path.join(here, 'js-parity.php')], { encoding: 'utf8' });
  if (r.error || r.status !== 0) {
    if (r.error && r.error.code === 'ENOENT') return t.skip('php niet gevonden');
    assert.fail('js-parity.php faalde: ' + (r.stderr || r.error));
  }
  // PHP json_encode maakt van een lege `triggers`-map een lege lijst ([]); voor de JS-module is dat hetzelfde.
  const php = JSON.parse(r.stdout, (k, v) => (k === 'triggers' && Array.isArray(v) && !v.length ? {} : v));
  const fields = ['serviceId', 'label', 'reason', 'priceIncl', 'priceHtml', 'freeAlternative', 'cta', 'decline', 'preselected'];
  for (const lang of ['nl', 'fr']) {
    const cat = catalogFromConfig(services, lang);
    assert.deepEqual(JSON.parse(JSON.stringify(cat)), php[lang].catalog, `catalogus ${lang}`);
    vectors.vectors.forEach((v, i) => {
      const js = evaluate(Object.assign({}, v.signals, { lang }), cat, v.dismissed || []);
      const p = php[lang].vectors[i];
      assert.equal(js === null, p === null, `${lang} ${v.name}: null/aanbod`);
      if (js) for (const f of fields) assert.deepEqual(js[f], p[f], `${lang} ${v.name}: ${f}`);
    });
    const rc = catalogFromConfig(withRush(), lang);
    assert.deepEqual(JSON.parse(JSON.stringify(rc)), php[lang].rushCatalog, `spoedcatalogus ${lang}`);
    vectors.rushEnabled.forEach((v, i) => {
      const js = evaluate(Object.assign({}, v.signals, { lang }), rc, []);
      const p = php[lang].rush[i];
      assert.equal(js === null, p === null, `${lang} spoed ${v.name}`);
      if (js) for (const f of fields) assert.deepEqual(js[f], p[f], `${lang} spoed ${v.name}: ${f}`);
    });
  }
});

test('messages: NL en FR hebben dezelfde sleutels, eerlijk (geen druk)', () => {
  assert.deepEqual(Object.keys(svcMessages.nl).sort(), Object.keys(svcMessages.fr).sort());
  const all = JSON.stringify(svcMessages) + JSON.stringify(services);
  for (const bad of [/\bcredits?\b/i, /\btokens?\b/i, /beperkt aanbod/i, /nog maar/i, /populairst/i, /★|☆/, /offre limitée/i, /plus que \d/i]) assert.ok(!bad.test(all), 'verboden: ' + bad);
  assert.equal(svcMessages.nl['svc.no'], 'Nee, dank je');
  assert.equal(svcMessages.fr['svc.no'], 'Non merci');
});

test('afwijzing per ontwerp onthouden (localStorage, veilig zonder opslag)', () => {
  const store = new Map();
  globalThis.window = { localStorage: { getItem: (k) => (store.has(k) ? store.get(k) : null), setItem: (k, v) => store.set(k, String(v)), removeItem: (k) => store.delete(k) } };
  try {
    assert.deepEqual(dismissedFor('ref-1'), []);
    rememberDismiss('ref-1', 'd2_redraw');
    rememberDismiss('ref-1', 'd2_redraw');
    assert.deepEqual(dismissedFor('ref-1'), ['d2_redraw']);
    assert.deepEqual(dismissedFor('ref-2'), []);
    assert.ok(store.has('bps_svc_dismiss_ref-1'));
    globalThis.window = { localStorage: { getItem: () => { throw new Error('blocked'); }, setItem: () => { throw new Error('blocked'); } } };
    assert.deepEqual(dismissedFor('ref-1'), [], 'geblokkeerde opslag: leeg, geen fout');
    rememberDismiss('ref-1', 'x');
  } finally {
    delete globalThis.window;
  }
});

test('api: /services/cart met token, nonce, taal; fouten als {status, code}', async () => {
  const calls = [];
  const fetch = async (url, init) => {
    calls.push({ url, init });
    if (url.endsWith('/services/cart')) return { ok: true, status: 200, json: async () => ({ ok: true, cartUrl: '/winkelmand/', cartCount: 2, line: { key: 'k' } }) };
    return { ok: false, status: 409, json: async () => ({ code: 'bps_svc_exists', message: 'x', data: { status: 409 } }) };
  };
  const api = createServicesApi({ base: '/wp-json/patch-studio/v1/', nonce: 'n1', token: async () => 'tk', lang: 'fr', fetch });
  const r = await api.cart('d2_redraw', 'ref-9');
  assert.equal(r.cartUrl, '/winkelmand/');
  assert.equal(calls[0].url, '/wp-json/patch-studio/v1/services/cart');
  assert.equal(calls[0].init.headers['X-BPS-Token'], 'tk');
  assert.equal(calls[0].init.headers['X-WP-Nonce'], 'n1');
  assert.equal(calls[0].init.headers['X-BPS-Locale'], 'fr');
  assert.deepEqual(JSON.parse(calls[0].init.body), { lang: 'fr', serviceId: 'd2_redraw', designRef: 'ref-9' });
  await assert.rejects(api.dismiss('d2_redraw', 'ref-9'), (e) => e.status === 409 && e.code === 'bps_svc_exists');
  const g = createServicesApi({ base: '/b', fetch: async () => { throw new Error('offline'); } });
  await assert.rejects(g.catalog(), (e) => e.code === 'network' && e.status === 0);
});
