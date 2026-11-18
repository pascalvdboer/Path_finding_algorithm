/**
 * AI-ontwerpstudio (frontend): in-browser nep-API (mock-api.js) — zelfde JSON-vormen als de backend (§4.3),
 * eerlijke rondes (weigering/storing/annuleren = gratis), e-mail en pakket, en sync met config/ai-mock.
 * Run: npm test
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createMockApi, MOCK_EMAIL_CODE } from '../../plugin/borduur-patch-studio/assets/src/ai/mock-api.js';
import { buildSource, target } from './lib/mock-data-gen.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const php = (f) => fs.readFileSync(path.join(root, 'plugin/borduur-patch-studio/includes/ai', f), 'utf8');

function clocked(opts) {
  let now = 1_800_000_000_000;
  const api = createMockApi(Object.assign({ now: () => now, latencyMs: 1000 }, opts || {}));
  return { api, tick: (ms) => (now += ms) };
}
const body = (over) => Object.assign({ clientRequestId: Math.random().toString(36), description: 'een wolf met een bijl, rond, zwart en rood', text: '', style: 'auto', shape: 'auto', maxColors: null }, over || {});

test('mock-data.js is in sync met config/ai-mock.json + ai-mock/*.svg', () => {
  assert.equal(fs.readFileSync(target, 'utf8'), buildSource(), 'draai: node tests/ai/lib/mock-data-gen.mjs');
});

test('JSON-vormen gelijk aan de backend (StatusResponse, JobResponse, ChooseResponse)', async () => {
  const jobs = php('class-jobs.php');
  const { api, tick } = clocked({ n: 3 });
  const st = await api.status();
  for (const k of ['mode', 'providerLabel', 'testMode', 'testNotice', 'n', 'refineN', 'costs', 'balance', 'freePaused', 'emailGate', 'packs', 'orderCredit', 'packsBuyable', 'limits', 'pausedUntil', 'legalVersion', 'openJob']) {
    assert.ok(k in st, 'status.' + k);
    assert.ok(jobs.includes(`'${k}'`), 'backend kent status.' + k);
  }
  const job = await api.generate(body());
  for (const k of ['jobId', 'kind', 'status', 'blocked', 'pollAfterMs', 'proposals', 'understood', 'charged', 'error', 'balance']) {
    assert.ok(k in job, 'job.' + k);
    assert.ok(jobs.includes(`'${k}'`), 'backend kent job.' + k);
  }
  assert.match(job.jobId, /^[0-9A-HJKMNP-TV-Z]{26}$/, 'job-id = 26 tekens Crockford base32 zoals de backend');
  assert.equal(job.status, 'queued');
  tick(1100);
  const done = await api.job(job.jobId);
  assert.equal(done.status, 'done');
  const p = done.proposals[0];
  for (const k of ['index', 'url', 'mime', 'w', 'h', 'alt']) assert.ok(k in p, 'proposal.' + k);
  assert.ok(!('_meta' in p), 'interne velden lekken niet');
  const ch = await api.choose(job.jobId, { proposalIndex: 1 });
  for (const k of ['imageUrl', 'mime', 'fileName', 'svgUrl', 'ai', 'hints']) assert.ok(k in ch && jobs.includes(`'${k}'`), 'choose.' + k);
  for (const k of ['jobId', 'index', 'provider', 'model', 'label', 'humanFinish', 'legalVersion']) assert.ok(k in ch.ai, 'choose.ai.' + k);
  assert.equal(ch.ai.humanFinish, false);
  assert.equal(ch.hints.background, 'transparent');
});

test('n verschillende voorstellen, gekleurd volgens de beschrijving, eigen tekeningen', async () => {
  for (const n of [2, 3, 4]) {
    const { api, tick } = clocked({ n, balance: { free: 5 } });
    const j = await api.generate(body());
    tick(1100);
    const d = await api.job(j.jobId);
    assert.equal(d.proposals.length, n);
    assert.equal(new Set(d.proposals.map((p) => p.url)).size, n, 'allemaal verschillend');
    const svg = decodeURIComponent(d.proposals[0].url.split(',')[1]);
    assert.ok(svg.includes('#c62828') && svg.includes('#1b1b1b'), 'rood en zwart uit de beschrijving');
    assert.ok(svg.includes('<metadata>AI-generated'), 'AI-markering in de SVG');
    assert.ok(!/<script|on\w+=|href=/i.test(svg), 'geen script/links in de tekening');
    assert.deepEqual(d.understood.colors, ['#1b1b1b', '#c62828']);
    assert.equal(d.understood.shape, 'circle');
    assert.match(d.proposals[1].alt, /^Voorstel 2: wolvenkop/);
  }
});

test('rondes: weigering kost niets, storing en annuleren worden teruggeboekt, 402/409', async () => {
  const { api, tick } = clocked({ balance: { free: 1 } });
  const b = await api.generate(body({ description: 'het logo van Nike op een pet' }));
  assert.equal(b.status, 'blocked');
  assert.equal(b.blocked, true);
  assert.equal(b.code, 'brand');
  assert.equal(b.charged, 0);
  assert.equal(b.balance.free, 1, 'geweigerd: geen ronde af');
  for (const d of ['politie embleem', 'een swastika', 'in de stijl van Hergé']) assert.equal((await api.generate(body({ description: d }))).status, 'blocked', d);
  const j = await api.generate(body());
  assert.equal(j.balance.total, 0);
  await assert.rejects(api.generate(body()), (e) => e.status === 409 && e.code === 'bps_ai_busy' && e.data.jobId === j.jobId, 'één lopende job');
  const c = await api.cancel(j.jobId);
  assert.equal(c.status, 'cancelled');
  assert.equal(c.charged, 0);
  assert.equal(c.balance.free, 1, 'annuleren: ronde terug');
  const f = await api.generate(body({ description: 'een leeuw [[mock:fail]]' }));
  tick(1100);
  const fd = await api.job(f.jobId);
  assert.equal(fd.status, 'failed');
  assert.match(fd.error.message, /teruggezet/);
  assert.equal(fd.balance.free, 1, 'storing: ronde terug');
  const pol = await api.generate(body({ description: 'een leeuw [[mock:policy]]' }));
  tick(1100);
  assert.equal((await api.job(pol.jobId)).status, 'blocked');
  assert.equal((await api.status()).balance.free, 1, 'providerweigering: ronde terug');
  const ok = await api.generate(body());
  tick(1100);
  await api.job(ok.jobId);
  await assert.rejects(api.generate(body()), (e) => e.status === 402 && e.code === 'bps_ai_no_rounds' && e.data.emailGate.offer === 2);
  await assert.rejects(api.generate(body({ description: 'ab' })), (e) => e.code === 'bps_ai_invalid');
  await assert.rejects(api.job('01JD3W0000000000000000ABCD'), (e) => e.status === 404, 'onbekende job = 404 (nooit 403)');
  const dupe = body();
  const st2 = clocked({ balance: { free: 2 } });
  const a1 = await st2.api.generate(dupe);
  const a2 = await st2.api.generate(dupe);
  assert.equal(a1.jobId, a2.jobId, 'zelfde clientRequestId = zelfde job (dubbel tikken)');
  assert.equal(a2.balance.total, 1, 'dubbel tikken kost maar 1 ronde');
});

test('privacy: e-mail en telefoon uit de beschrijving gehaald (warnings)', async () => {
  const { api } = clocked();
  const j = await api.generate(body({ description: 'een wolf voor jan@voorbeeld.be tel 0470 12 34 56' }));
  assert.ok(j.understood.warnings.includes('personal_data_removed'));
});

test('verfijnen: lokaal gratis (route local), anders AI-versies (2) met zichtbare wijziging', async () => {
  const { api, tick } = clocked({ balance: { free: 3 }, refineN: 2 });
  const j = await api.generate(body());
  tick(1100);
  const d = await api.job(j.jobId);
  const loc = await api.refine(j.jobId, { proposalIndex: 0, instruction: 'andere kleuren', clientRequestId: 'r1' });
  assert.equal(loc.route, 'local');
  assert.equal(loc.action, 'open_colors');
  assert.deepEqual(loc.aiOffer, { cost: 1 });
  assert.equal((await api.status()).balance.free, 2, 'lokaal kost niets');
  for (const [txt, act] of [['minder kleuren', 'reduce_colors'], ['zet onze naam erop', 'open_text'], ['iets groter', 'open_size'], ['plus à gauche', 'open_arrange']]) assert.equal((await api.refine(j.jobId, { proposalIndex: 0, instruction: txt })).action, act, txt);
  const forced = await api.refine(j.jobId, { proposalIndex: 0, instruction: 'andere kleuren', forceAi: true, clientRequestId: 'r2' });
  assert.equal(forced.kind, 'refine');
  assert.equal(forced.balance.free, 1);
  tick(1100);
  const r = await api.job(forced.jobId);
  assert.equal(r.proposals.length, 2);
  const sw = (u) => Number(decodeURIComponent(u).match(/stroke-width="(\d+)"/g).map((x) => x.match(/\d+/)[0]).pop());
  assert.ok(sw(r.proposals[0].url) > sw(d.proposals[0].url), 'dikkere lijnen dan het origineel');
  const blk = await api.refine(j.jobId, { proposalIndex: 0, instruction: 'maak er het Nike-logo van' });
  assert.equal(blk.status, 'blocked');
});

test('e-mail: code 123456 → +2 eenmalig; foute code telt pogingen af', async () => {
  const { api } = clocked({ balance: { free: 0 } });
  await assert.rejects(api.email({ email: 'geen-adres' }), (e) => e.code === 'bps_ai_invalid');
  assert.deepEqual(await api.email({ email: 'Jan@Example.com ' }), { sent: true });
  await assert.rejects(api.emailVerify({ email: 'jan@example.com', code: '000000' }), (e) => e.code === 'bps_ai_code_wrong' && e.data.triesLeft === 4);
  const ok = await api.emailVerify({ email: 'jan@example.com', code: MOCK_EMAIL_CODE });
  assert.equal(ok.granted, 2);
  assert.equal(ok.balance.free, 2);
  assert.equal((await api.status()).emailGate.done, true);
  await api.email({ email: 'jan@example.com' });
  assert.equal((await api.emailVerify({ email: 'jan@example.com', code: MOCK_EMAIL_CODE })).granted, 0, 'maar één keer gratis');
});

test('pakket: kassa-URL, rondes komen na de (gesimuleerde) betaling; vergrendeld → toestemming', async () => {
  const { api, tick } = clocked({ balance: { free: 0 }, paymentDelayMs: 2000 });
  const st = await api.status();
  assert.deepEqual(st.packs.map((p) => [p.id, p.rounds, p.priceIncl]), [['ai_pack_10', 10, 4.95], ['ai_pack_25', 25, 9.95]]);
  const co = await api.checkout({ packId: 'ai_pack_25' });
  assert.equal(co.packId, 'ai_pack_25');
  assert.equal((await api.credits()).balance.total, 0, 'nog niet betaald');
  tick(2100);
  assert.equal((await api.credits()).balance.paid, 25);
  const L = clocked({ balance: { free: 0 }, lockPaid: true, paymentDelayMs: 0 });
  await L.api.checkout({ packId: 'ai_pack_10' });
  L.tick(1);
  assert.equal((await L.api.credits()).balance.paidLocked, 10);
  await assert.rejects(L.api.generate(body()), (e) => e.code === 'bps_ai_consent_needed');
  await assert.rejects(L.api.consent({ start: true }), (e) => e.code === 'bps_ai_invalid', 'beide vakjes nodig');
  assert.equal((await L.api.consent({ start: true, ack: true })).balance.paid, 10);
  await assert.rejects(createMockApi({ packsBuyable: false }).checkout({ packId: 'ai_pack_10' }), (e) => e.code === 'bps_ai_invalid');
});

test('file(): data-URL → File met naam en type; FR-teksten', async () => {
  const { api, tick } = clocked({ lang: 'fr' });
  const j = await api.generate(body({ description: 'un loup avec une hache, noir et rouge' }));
  tick(1100);
  const d = await api.job(j.jobId);
  assert.match(d.proposals[0].alt, /^Proposition 1 : tête de loup/);
  const ch = await api.choose(j.jobId, { proposalIndex: 0 });
  const f = await api.file(ch.imageUrl, ch.fileName, ch.mime);
  assert.equal(f.name, ch.fileName);
  assert.equal(f.type, 'image/svg+xml');
  assert.ok((await f.text()).startsWith('<svg'));
  assert.equal(ch.ai.label, 'Créé avec l’IA');
  assert.match((await api.status()).testNotice, /^Mode test/);
});
