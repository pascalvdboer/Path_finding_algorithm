// Checks the legal microcopy in config/ai-legal.json (see docs/ai/04-juridisch.md).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const cfg = JSON.parse(readFileSync(new URL('../../plugin/borduur-patch-studio/config/ai-legal.json', import.meta.url), 'utf8'));
const doc = readFileSync(new URL('../../docs/ai/04-juridisch.md', import.meta.url), 'utf8');
const S = cfg.strings;

test('every string has non-empty nl and fr', () => {
  assert.ok(Object.keys(S).length >= 20);
  for (const [k, v] of Object.entries(S)) {
    assert.ok(v.nl && v.nl.trim().length > 1, `${k} nl`);
    assert.ok(v.fr && v.fr.trim().length > 1, `${k} fr`);
  }
});

test('placeholders match between nl and fr', () => {
  const ph = (t) => [...t.matchAll(/\{([a-z_0-9]+)\}/g)].map((m) => m[1]).sort().join(',');
  for (const [k, v] of Object.entries(S)) assert.equal(ph(v.nl), ph(v.fr), k);
});

test('FR uses vous, NL uses je (no tu / u-vorm in customer copy)', () => {
  for (const [k, v] of Object.entries(S)) {
    assert.doesNotMatch(v.fr.replace(/\{[^}]*\}/g, ""), /\b(tu|ton|ta|tes|toi)\b/i, `${k} fr`);
    assert.doesNotMatch(v.nl.replace(/\{[^}]*\}/g, ""), /\b(uw|u)\b/, `${k} nl`);
  }
});

test('withdrawal consent is two separate texts, both referenced as consents', () => {
  assert.deepEqual(cfg.consents.aiPack, ['legal.ai.pack_consent_start', 'legal.ai.pack_consent_ack']);
  for (const k of [...cfg.consents.aiPack, ...cfg.consents.services]) assert.ok(S[k], k);
  assert.match(S['legal.ai.pack_consent_start'].nl, /uitdrukkelijk/);
  assert.match(S['legal.ai.pack_consent_ack'].nl, /herroepingsrecht verlies/);
  assert.match(S['legal.ai.pack_consent_ack'].fr, /droit de rétractation/);
});

test('no dark patterns in copy (fake scarcity, free claims for paid packs)', () => {
  const bad = /(nog maar|laatste kans|beperkte tijd|plus que|dernière chance|temps limité|\bgratis\b.*pakket)/i;
  for (const [k, v] of Object.entries(S)) {
    assert.doesNotMatch(v.nl, bad, k);
    assert.doesNotMatch(v.fr, bad, k);
  }
});

test('AI label present and safe defaults', () => {
  assert.equal(S['legal.ai.label'].nl, 'Gemaakt met AI');
  assert.equal(S['legal.ai.label'].fr, "Créé avec l'IA");
  assert.equal(cfg.defaults.refusalCostsCredit, false);
  assert.equal(cfg.defaults.sendSafetyIdentifier, false);
  assert.ok(cfg.defaults.packValidityMonths >= cfg.defaults.packValidityMinMonths);
  assert.ok(cfg.defaults.packValidityMinMonths >= 12);
  assert.equal(cfg.defaults.withdrawalDays, 14);
  assert.match(cfg.marking.iptcDigitalSourceType, /trainedAlgorithmicMedia$/);
});

test('config strings stay in sync with the doc table', () => {
  for (const k of Object.keys(S)) assert.ok(doc.includes(`| ${k} |`), `${k} missing in doc`);
});
