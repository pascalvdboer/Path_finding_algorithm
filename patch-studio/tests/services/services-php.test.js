/**
 * Commerce: integrity of config/ai-pricing.json + config/ai-services.json and a bridge that runs the PHP commerce
 * tests (tests/services/run-services-php.php) when PHP is available. Run: npm test
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const plugin = path.join(root, 'plugin/borduur-patch-studio');
const pricing = JSON.parse(fs.readFileSync(path.join(plugin, 'config/ai-pricing.json'), 'utf8'));
const services = JSON.parse(fs.readFileSync(path.join(plugin, 'config/ai-services.json'), 'utf8'));
const vectors = JSON.parse(fs.readFileSync(path.join(here, 'offer-vectors.json'), 'utf8'));

const incl = (excl, vat) => Math.floor(excl * (1 + vat) * 100 + 0.5 + 1e-9) / 100;
const bilingual = (o, where) => {
  assert.ok(o && typeof o.nl === 'string' && o.nl.trim(), `${where}: nl ontbreekt`);
  assert.ok(typeof o.fr === 'string' && o.fr.trim(), `${where}: fr ontbreekt`);
};

test('ai-pricing.json: schema, veilige standaarden, pakketten € 4,95 / € 9,95 incl. btw', () => {
  assert.equal(pricing.schema, 1);
  assert.equal(pricing.mode, 'off', 'standaard uit na installatie');
  assert.equal(pricing.free.anonymous, 1);
  assert.equal(pricing.free.withEmail, 2);
  assert.equal(pricing.free.perOrder, 5);
  const ids = pricing.packs.map((p) => p.id);
  assert.deepEqual(ids, ['ai_pack_10', 'ai_pack_25']);
  assert.equal(incl(pricing.packs[0].priceExcl, pricing.vatRate), 4.95);
  assert.equal(incl(pricing.packs[1].priceExcl, pricing.vatRate), 9.95);
  for (const p of pricing.packs) bilingual(p.label, `packs.${p.id}.label`);
  assert.ok(pricing.validityMonths >= pricing.validityMinMonths && pricing.validityMinMonths >= 12);
  assert.equal(pricing.orderCredit.percent, 100);
  assert.equal(pricing.orderCredit.minArticleIncl, 50);
  assert.ok(!JSON.stringify(pricing).match(/sk-[a-z0-9]|api[_-]?key/i), 'geen sleutels in de config');
});

test('ai-services.json: D1–D7, NL+FR, D4/D5 standaard uit, regeloptie nooit los in de winkel', () => {
  assert.equal(services.schema, 1);
  assert.equal(services.offerRules.maxVisible, 1);
  assert.equal(services.offerRules.neverPreselected, true);
  assert.equal(services.offerRules.rememberDismiss, true);
  const ids = Object.keys(services.services);
  for (const want of ['d1_finish', 'd2_redraw', 'd2b_redraw_xl', 'd3_file', 'd3b_file_addon', 'd4_rush', 'd5_extra_proof', 'd6_sample', 'd7_photo_test']) {
    assert.ok(ids.includes(want), `${want} ontbreekt`);
  }
  for (const [id, s] of Object.entries(services.services)) {
    bilingual(s.label, `${id}.label`);
    bilingual(s.short, `${id}.short`);
    if (s.reason) bilingual(s.reason, `${id}.reason`);
    if (s.freeAlternative) bilingual(s.freeAlternative, `${id}.freeAlternative`);
    assert.ok(['product', 'line_option'].includes(s.kind), `${id}.kind`);
    if (s.kind === 'line_option') assert.equal(s.showInShop, false, `${id}: regeloptie niet los in de winkel`);
    assert.ok(Number.isInteger(s.leadDays) && s.leadDays >= 0, `${id}.leadDays`);
  }
  assert.equal(services.services.d4_rush.enabled, false, 'spoed uit tot de eigenaar het bevestigt');
  assert.equal(services.services.d5_extra_proof.enabled, false, 'extra proefronde uit tot het proefbeleid vastligt');
  assert.deepEqual(services.statuses, ['waiting', 'queued', 'in_progress', 'proof_sent', 'delivered', 'cancelled']);
});

test('eerlijke teksten: geen "credits", schaarste of aftelklokken in klantteksten', () => {
  const forbidden = /\b(credits?|tokens?|beperkt aanbod|nog maar|populairst|offre limitée|le plus populaire)\b|★|aftel/iu;
  const strip = (o) => JSON.parse(JSON.stringify(o, (k, v) => (k.startsWith('_') ? undefined : v)));
  for (const [name, cfg] of [['ai-pricing.json', pricing], ['ai-services.json', services]]) {
    assert.ok(!forbidden.test(JSON.stringify(strip(cfg))), `${name} bevat verboden woorden`);
  }
  const text = fs.readFileSync(path.join(plugin, 'includes/services/class-text.php'), 'utf8');
  const strings = [...text.matchAll(/'(?:nl|fr)'\s*=>\s*(['"])((?:\\.|(?!\1).)*)\1/g)].map((m) => m[2]);
  assert.ok(strings.length > 100, 'teksten gevonden');
  for (const s of strings) assert.ok(!forbidden.test(s), `verboden woord in: ${s}`);
});

test('offer-vectors.json: elke vector verwijst naar een bestaande dienst', () => {
  const ids = new Set(Object.keys(services.services));
  for (const v of [...vectors.vectors, ...vectors.rushEnabled]) {
    assert.ok(v.name && v.signals && typeof v.signals.stage === 'string', v.name);
    if (v.expect !== null) assert.ok(ids.has(v.expect), `${v.name}: ${v.expect}`);
  }
});

test('PHP: rondes, pakketten, coupons, diensten, kassa, fulfilment, herroeping (run-services-php.php)', (t) => {
  const probe = spawnSync('php', ['-v'], { encoding: 'utf8' });
  if (probe.status !== 0) {
    t.skip('php niet gevonden');
    return;
  }
  const res = spawnSync('php', [path.join(here, 'run-services-php.php')], { encoding: 'utf8' });
  assert.equal(res.status, 0, `${res.stdout}\n${res.stderr}`);
  assert.match(res.stdout, /(\d+)\/\1 ok/);
});
