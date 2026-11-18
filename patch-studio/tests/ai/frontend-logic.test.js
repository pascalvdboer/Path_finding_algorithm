/**
 * AI-ontwerpstudio (frontend): toestandsmachine, REST-client + foutmapping, polling/back-off. Zonder browser.
 * Run: npm test
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { reduce, initialState, primaryAction, counterText, pickSheet, createStore, canAfford, VIEWS } from '../../plugin/borduur-patch-studio/assets/src/ai/store.js';
import { createApi, mapError, ApiError, uuid } from '../../plugin/borduur-patch-studio/assets/src/ai/api.js';
import { pollJob } from '../../plugin/borduur-patch-studio/assets/src/ai/poll.js';
import { createT } from '../../plugin/borduur-patch-studio/assets/src/shared/i18n.js';
import messages from '../../plugin/borduur-patch-studio/assets/src/ai/messages.js';

const status = (over) => Object.assign({ n: 3, refineN: 2, costs: { generate: 1, refine: 1, choose: 0 }, balance: { free: 1, paid: 0, paidLocked: 0, total: 1 }, emailGate: { offer: 2, done: false }, packs: [], packsBuyable: true, pausedUntil: null, openJob: null }, over || {});
const run = (actions, s0) => actions.reduce((s, a) => reduce(s, a), s0 || initialState());

/* ------------------------------------------------------------------ store */
test('store: hoofdpad idle → describing → waiting → proposals → chosen', () => {
  let s = run([{ type: 'STATUS', status: status() }]);
  assert.equal(s.view, 'describing');
  assert.equal(primaryAction(s), 'generate');
  s = run([{ type: 'FORM', patch: { description: 'wolf' } }, { type: 'SUBMIT', kind: 'generate' }], s);
  assert.equal(s.view, 'submitting');
  assert.equal(s.busy, true);
  s = reduce(s, { type: 'JOB', job: { jobId: 'J1', kind: 'generate', balance: { free: 0, paid: 0, paidLocked: 0, total: 0 } }, n: 3 });
  assert.equal(s.view, 'waiting');
  assert.equal(s.balance.total, 0, 'saldo uit het jobantwoord');
  assert.equal(primaryAction(s), 'cancel');
  s = reduce(s, { type: 'JOB_DONE', job: { jobId: 'J1', kind: 'generate', status: 'done', proposals: [{ index: 0, url: 'a' }, { index: 1, url: 'b' }, { index: 2, url: 'c' }], understood: { summary: 'wolf · rond' } } });
  assert.equal(s.view, 'proposals');
  assert.equal(s.selected, 0, 'eerste voorstel als keuze (geen aankoop)');
  assert.equal(s.proposalsJobId, 'J1');
  assert.equal(primaryAction(s), 'use');
  s = reduce(s, { type: 'SELECT', index: 2 });
  assert.equal(s.selected, 2);
  s = reduce(s, { type: 'CHOSEN' });
  assert.equal(s.view, 'chosen');
  assert.equal(s.form.description, 'wolf', 'beschrijving blijft bewaard');
});

test('store: elke toestand heeft precies één primaire actie', () => {
  for (const view of VIEWS) {
    const s = Object.assign(initialState(), { view, status: status() , balance: { free: 1, paid: 0, paidLocked: 0, total: 1 } });
    const a = primaryAction(s);
    if (view === 'idle') assert.equal(a, null, 'laden: geen knop');
    else assert.equal(typeof a, 'string', view + ' heeft een primaire actie');
  }
  for (const sheet of ['email', 'pack', 'consent', 'history']) assert.ok(primaryAction(Object.assign(initialState(), { sheet })), sheet);
});

test('store: zonder rondes wordt de knop "Meer rondes"; NEED kiest de juiste sheet', () => {
  let s = run([{ type: 'STATUS', status: status({ balance: { free: 0, paid: 0, paidLocked: 0, total: 0 } }) }]);
  assert.equal(canAfford(s, 'generate'), false);
  assert.equal(primaryAction(s), 'buy');
  assert.equal(pickSheet(s.status, s.balance), 'email', 'eerst +2 gratis met e-mail');
  assert.equal(pickSheet(status({ emailGate: { offer: 2, done: true } }), s.balance), 'pack');
  assert.equal(pickSheet(s.status, { free: 0, paid: 0, paidLocked: 10, total: 0 }), 'consent', 'gekocht maar vergrendeld');
  s = reduce(s, { type: 'NEED', sheet: 'auto' });
  assert.equal(s.sheet, 'email');
  assert.equal(s.sheetReturn, 'describing');
  s = reduce(s, { type: 'SHEET', sheet: null });
  assert.equal(s.sheet, null);
  assert.equal(s.view, 'describing', 'na sluiten: terug waar je was');
  // NEED tijdens submit: terug naar voorstellen als die er zijn.
  s = Object.assign(initialState(), { view: 'submitting', proposals: [{ index: 0 }], status: status() });
  s = reduce(s, { type: 'NEED', sheet: 'pack' });
  assert.equal(s.view, 'proposals');
  assert.equal(s.sheet, 'pack');
});

test('store: weigering, storing en annulering', () => {
  let s = run([{ type: 'STATUS', status: status() }, { type: 'SUBMIT' }, { type: 'BLOCKED', job: { kind: 'generate', status: 'blocked', code: 'brand', message: 'Geen merken.' } }]);
  assert.equal(s.view, 'blocked');
  assert.deepEqual(s.blocked, { code: 'brand', message: 'Geen merken.' });
  assert.equal(primaryAction(s), 'edit');
  s = reduce(s, { type: 'DESCRIBE' });
  assert.equal(s.view, 'describing');
  assert.equal(s.blocked, null);
  s = run([{ type: 'SUBMIT' }, { type: 'JOB', job: { jobId: 'J' } }, { type: 'JOB_FAILED', job: { status: 'failed', error: { code: 'timeout', message: '' } } }], s);
  assert.equal(s.view, 'failed');
  assert.equal(s.error.code, 'timeout');
  assert.equal(s.error.refunded, true);
  assert.equal(primaryAction(s), 'retry');
  s = run([{ type: 'SUBMIT' }, { type: 'JOB', job: { jobId: 'J2' } }, { type: 'JOB_FAILED', cancelled: true, job: { status: 'cancelled' } }], s);
  assert.equal(s.view, 'describing', 'annuleren zonder voorstellen: terug naar beschrijven');
  assert.equal(s.notice.key, 'ai.cancelled');
  // Geweigerde verfijning: blijft bij de voorstellen, als chatbericht.
  s = Object.assign(initialState(), { view: 'waiting', proposals: [{ index: 0 }], chat: [] });
  s = reduce(s, { type: 'BLOCKED', job: { kind: 'refine', status: 'blocked', error: { code: 'person', message: 'Geen echte personen.' } } });
  assert.equal(s.view, 'proposals');
  assert.equal(s.chat.length, 1);
  assert.equal(s.chat[0].tone, 'warn');
});

test('store: verfijning voegt een chatbericht toe; lokaal = gratis', () => {
  let s = Object.assign(initialState(), { view: 'proposals', proposals: [{ index: 0 }, { index: 1 }], selected: 1, chat: [{ who: 'you', text: 'bozer' }] });
  s = reduce(s, { type: 'JOB_DONE', job: { jobId: 'R', kind: 'refine', proposals: [{ index: 0 }, { index: 1 }] } });
  assert.equal(s.chat.length, 2);
  assert.equal(s.chat[1].key, 'ai.refine.done');
  s = reduce(s, { type: 'LOCAL', res: { route: 'local', action: 'open_colors', message: 'Gratis in de studio' } });
  assert.equal(s.chat[2].local.action, 'open_colors');
  assert.equal(s.view, 'proposals');
});

test('store: openstaande pauze uit status → paused; createStore meldt wijzigingen', () => {
  const s = run([{ type: 'STATUS', status: status({ pausedUntil: '2026-11-18T12:00:00Z' }) }]);
  assert.equal(s.view, 'paused');
  const st = createStore();
  let calls = 0;
  st.subscribe(() => calls++);
  st.dispatch({ type: 'FORM', patch: { text: 'X' } });
  st.dispatch({ type: 'ONBEKEND' });
  assert.equal(calls, 1, 'onbekende actie wijzigt niets');
});

test('rondeteller in gewone woorden (nooit "credits")', () => {
  for (const lang of ['nl', 'fr']) {
    const t = createT([messages], lang);
    const c = (b) => counterText(Object.assign({ free: 0, paid: 0, paidLocked: 0, total: 0 }, b), t);
    if (lang === 'nl') {
      assert.equal(c({ free: 1, total: 1 }), '1 gratis ronde');
      assert.equal(c({ free: 3, total: 3 }), '3 gratis rondes');
      assert.equal(c({ free: 2, paid: 5, total: 7 }), 'Nog 7 rondes');
      assert.equal(c({ paid: 1, total: 1 }), 'Nog 1 ronde');
      assert.equal(c({}), 'Geen rondes meer');
      assert.equal(c({ paidLocked: 10 }), 'Je gekochte rondes staan klaar');
    } else {
      assert.equal(c({ free: 1, total: 1 }), '1 essai gratuit');
      assert.equal(c({ paid: 7, total: 7 }), 'Encore 7 essais');
    }
  }
});

/* ------------------------------------------------------------------ api */
function fakeFetch(routes) {
  const calls = [];
  const f = async (url, init) => {
    calls.push({ url, init });
    const path = url.replace(/^https?:\/\/[^/]+/, '').replace(/\?.*$/, '');
    const r = routes(path, init, calls.length);
    if (r instanceof Error) throw r;
    return { ok: r.status < 400, status: r.status, json: async () => r.body, blob: async () => new Blob(['<svg/>'], { type: 'image/svg+xml' }) };
  };
  f.calls = calls;
  return f;
}

test('api: headers, taal en paden volgens §4 (geen prompt in de body)', async () => {
  const fetch = fakeFetch((p) => ({ status: p.endsWith('/generate') ? 202 : 200, body: { ok: 1 } }));
  const api = createApi({ base: 'https://shop.test/wp-json/patch-studio/v1/', nonce: 'N', token: async () => 'TK', lang: 'fr', fetch });
  await api.status();
  await api.generate({ clientRequestId: 'x', description: 'loup' });
  await api.job('01JD3W0000000000000000ABCD');
  await api.refine('01JD3W0000000000000000ABCD', { proposalIndex: 1, instruction: 'plus féroce' });
  await api.checkout({ packId: 'ai_pack_10' });
  const [st, gen, job, ref, co] = fetch.calls;
  assert.equal(st.url, 'https://shop.test/wp-json/patch-studio/v1/ai/status?lang=fr');
  assert.equal(st.init.method, 'GET');
  assert.equal(st.init.headers['X-BPS-Token'], 'TK');
  assert.equal(st.init.headers['X-WP-Nonce'], 'N');
  assert.equal(st.init.headers['X-BPS-Locale'], 'fr');
  assert.equal(st.init.credentials, 'same-origin');
  assert.equal(gen.url, 'https://shop.test/wp-json/patch-studio/v1/ai/generate');
  const body = JSON.parse(gen.init.body);
  assert.equal(body.lang, 'fr');
  assert.ok(!('prompt' in body) && !('brief' in body), 'browser stuurt nooit een prompt');
  assert.match(job.url, /\/ai\/jobs\/01JD3W0000000000000000ABCD\?lang=fr$/);
  assert.match(ref.url, /\/ai\/jobs\/01JD3W0000000000000000ABCD\/refine$/);
  assert.match(co.url, /\/ai\/checkout$/);
  assert.throws(() => api.job('../../etc'), (e) => e instanceof ApiError && e.code === 'bps_ai_invalid', 'job-id wordt gevalideerd');
});

test('api: foutvorm {code, message, data} → ApiError; 401 bad_token → 1× opnieuw met vers token', async () => {
  let tokens = 0;
  const fetch = fakeFetch((p, init, n) => {
    if (p.endsWith('/status')) return n === 1 ? { status: 401, body: { code: 'bps_bad_token', message: 'Token', data: { status: 401 } } } : { status: 200, body: { mode: 'live' } };
    return { status: 402, body: { code: 'bps_ai_no_rounds', message: 'Op', data: { status: 402, emailGate: { offer: 2 } } } };
  });
  const api = createApi({ base: '/b', token: async (fresh) => 'T' + ++tokens + (fresh ? 'f' : ''), fetch });
  const st = await api.status();
  assert.equal(st.mode, 'live');
  assert.equal(fetch.calls[1].init.headers['X-BPS-Token'], 'T2f');
  await assert.rejects(api.generate({}), (e) => e instanceof ApiError && e.status === 402 && e.code === 'bps_ai_no_rounds' && e.data.emailGate.offer === 2);
  const off = createApi({ base: '/b', fetch: async () => { throw new TypeError('Failed to fetch'); } });
  await assert.rejects(off.status(), (e) => e.code === 'network' && e.status === 0);
});

test('mapError: elke foutcode van §4.4 heeft een nette afhandeling', () => {
  const m = (code, data, status = 400) => mapError(new ApiError(status, code, '', data));
  assert.deepEqual(m('bps_ai_no_rounds'), { view: 'needRounds', sheet: 'auto' });
  assert.deepEqual(m('bps_ai_consent_needed'), { view: 'needRounds', sheet: 'consent' });
  assert.deepEqual(m('bps_ai_busy', { jobId: 'J9' }), { view: 'waiting', jobId: 'J9' });
  assert.equal(m('bps_ai_slow_down', { retryAfter: 6.2 }).params.s, 7);
  assert.equal(m('bps_ai_daily_limit').key, 'ai.daily_limit');
  assert.equal(m('bps_ai_paused').view, 'paused');
  assert.equal(m('bps_ai_budget', { freeOnly: true }).sheet, 'pack');
  assert.equal(m('bps_ai_budget').key, 'ai.unavailable');
  assert.equal(m('bps_ai_off').view, 'budget');
  assert.equal(m('network').key, 'ai.network');
  assert.equal(m('iets_onbekends').key, 'ai.error.nocharge');
  assert.match(uuid(), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
});

/* ------------------------------------------------------------------ poll */
function fakeClock() {
  let now = 0;
  let timers = [];
  let id = 0;
  return {
    now: () => now,
    setTimeout: (f, ms) => {
      timers.push({ id: ++id, at: now + ms, f });
      return id;
    },
    clearTimeout: (i) => {
      timers = timers.filter((x) => x.id !== i);
    },
    async advance(ms) {
      const end = now + ms;
      for (;;) {
        timers.sort((a, b) => a.at - b.at);
        const next = timers[0];
        if (!next || next.at > end) break;
        timers.shift();
        now = next.at;
        await next.f();
        await new Promise((r) => setImmediate(r));
      }
      now = end;
    },
    pending: () => timers.length,
  };
}

test('poll: 1,5 s, na 10 s 3 s; pollAfterMs van de server wint; stopt bij done', async () => {
  const clock = fakeClock();
  const at = [];
  let final = null;
  let n = 0;
  pollJob({
    clock,
    doc: null,
    firstDelayMs: 0,
    fetchJob: async () => {
      at.push(clock.now());
      n++;
      return n >= 10 ? { status: 'done', proposals: [] } : { status: 'running', pollAfterMs: n === 9 ? 5000 : 0 };
    },
    onUpdate: () => {},
    onFinal: (j) => (final = j),
    onError: () => assert.fail('geen fout verwacht'),
  });
  await clock.advance(60000);
  assert.equal(final.status, 'done');
  const gaps = at.slice(1).map((x, i) => x - at[i]);
  assert.deepEqual(gaps.slice(0, 7), [1500, 1500, 1500, 1500, 1500, 1500, 1500]);
  assert.equal(gaps[7], 3000, 'na 10 s: 3 s');
  assert.equal(gaps[8], 5000, 'pollAfterMs van de server');
  assert.equal(clock.pending(), 0, 'gestopt na done');
});

test('poll: time-out na timeoutSec, netwerk-back-off, pauze als het tabblad verborgen is', async () => {
  const clock = fakeClock();
  let err = null;
  pollJob({ clock, doc: null, firstDelayMs: 0, timeoutSec: 20, fetchJob: async () => ({ status: 'queued' }), onUpdate() {}, onFinal: () => assert.fail(), onError: (e) => (err = e) });
  await clock.advance(30000);
  assert.deepEqual(err, { code: 'timeout' });

  const c2 = fakeClock();
  let e2 = null;
  let tries = 0;
  pollJob({ clock: c2, doc: null, firstDelayMs: 0, fetchJob: async () => { tries++; throw new ApiError(0, 'network', ''); }, onUpdate() {}, onFinal() {}, onError: (e) => (e2 = e) });
  await c2.advance(60000);
  assert.equal(tries, 4, '3× opnieuw met back-off');
  assert.equal(e2.code, 'network');

  const c3 = fakeClock();
  const listeners = {};
  const doc = { hidden: true, addEventListener: (n, f) => (listeners[n] = f), removeEventListener: (n) => delete listeners[n] };
  let calls = 0;
  let done = false;
  pollJob({ clock: c3, doc, firstDelayMs: 0, fetchJob: async () => (++calls >= 2 ? { status: 'done' } : { status: 'running' }), onUpdate() {}, onFinal: () => (done = true), onError() {} });
  await c3.advance(20000);
  assert.equal(calls, 0, 'verborgen: geen verzoeken');
  doc.hidden = false;
  listeners.visibilitychange();
  await new Promise((r) => setImmediate(r));
  await c3.advance(5000);
  assert.equal(done, true, 'hervat bij terugkeer');
  assert.ok(!listeners.visibilitychange, 'listener opgeruimd');

  const c4 = fakeClock();
  let e4 = null;
  pollJob({ clock: c4, doc: null, firstDelayMs: 0, fetchJob: async () => { throw new ApiError(404, 'bps_ai_not_found', ''); }, onUpdate() {}, onFinal() {}, onError: (e) => (e4 = e) });
  await c4.advance(2000);
  assert.equal(e4.code, 'bps_ai_not_found', '404 stopt meteen');
});

/* ------------------------------------------------------------------ juridisch-check (18-11) */
test('saldo: betaalde rondes die binnenkort vervallen komen door (legal.ai.expiry_notice), rest blijft null', () => {
  let s = run([{ type: 'STATUS', status: status() }]);
  assert.equal(s.balance.expiring, null);
  s = reduce(s, { type: 'BALANCE', balance: { free: 0, paid: 4, paidLocked: 0, total: 4, expiring: { rounds: 4, at: '2026-12-01T10:00:00Z' } } });
  assert.deepEqual(s.balance.expiring, { rounds: 4, at: '2026-12-01T10:00:00Z' });
  s = reduce(s, { type: 'BALANCE', balance: { free: 0, paid: 4, paidLocked: 0, total: 4, expiring: { rounds: 0, at: 'x' } } });
  assert.equal(s.balance.expiring, null);
  const t = createT([messages], 'nl');
  assert.match(t('ai.pack.per', { per: '€ 0,50', m: 24 }), /24 maanden geldig/);
  assert.match(createT([messages], 'fr')('ai.pack.per', { per: '0,50 €', m: 24 }), /valables 24 mois/);
});
