/**
 * AI-ontwerpstudio — het paneel (SPEC-AI-DIENSTEN §1, §10). Gsm: bottom sheet (dialog, focus-trap, Esc/terug sluit).
 * Desktop: inline in de assistent-kolom. Eén primaire knop per toestand, rondeteller altijd zichtbaar, rondes kopen
 * zonder de flow te verlaten (kassa in nieuw tabblad, saldo komt vanzelf binnen), label "Gemaakt met AI" als DOM-tekst
 * op elke tegel. Praat met de studio enkel via events op ctx.host en de optionele ctx-callbacks.
 */
import { createT } from '../shared/i18n.js';
import catalog, { IDEAS } from './messages.js';
import { createApi, mapError, uuid } from './api.js';
import { createStore, initialState, primaryAction, counterText, canAfford, pickSheet } from './store.js';
import { pollJob } from './poll.js';
import { injectStyles } from './styles.js';
import { h, ICON, focusables } from './dom.js';
import { renderSheet, fmtDate } from './sheets.js';

const DEFAULT_STYLES = ['club', 'mascot', 'vintage', 'minimal', 'badge', 'military', 'typographic'];
const SHAPES = ['auto', 'circle', 'oval', 'shield', 'rect'];
const REST_SHAPES = ['circle', 'oval', 'rect', 'rounded', 'shield', 'contour']; // §4.3
const COLORS = [null, 2, 3, 4, 6];
const DRAFT_KEY = 'bps_ai_draft';

function lsGet(k) {
  try {
    return JSON.parse(window.localStorage.getItem(k) || 'null');
  } catch (e) {
    return null;
  }
}
function lsSet(k, v) {
  try {
    if (v == null) window.localStorage.removeItem(k);
    else window.localStorage.setItem(k, JSON.stringify(v));
  } catch (e) { /* privévenster of geblokkeerd: dan zonder concept */ }
}

/**
 * @param {HTMLElement} el   container (bottom sheet of assistent-kolom)
 * @param {object} ctx       zie SPEC §10.2
 */
export function createPanel(el, ctx) {
  if (!el || !ctx || !ctx.host) throw new Error('BPSAI.mount(el, ctx): el en ctx.host zijn verplicht');
  injectStyles(el.ownerDocument);
  const config = ctx.config || {};
  const aiCfg = config.ai || {};
  const lang = String(ctx.locale || 'nl').toLowerCase().startsWith('fr') ? 'fr' : 'nl';
  const overrides = Object.assign({}, aiCfg.legal || {}, (config.strings && config.strings[lang]) || {});
  const t0 = createT([catalog], lang, overrides);
  // Ontbrekende juridische tekst → leeg (nooit de sleutel tonen); de bron is config.ai.legal (ai-legal.json).
  const t = (k, p) => {
    const v = t0(k, p);
    return v === k && k.indexOf('legal.') === 0 ? '' : v;
  };
  // mockApi: object (createMockApi() uit mock-api.js) of true (dan de aparte demo-bundel window.BPSAIMock).
  // De nep-API zit bewust niet in de productiebundel (budget ≤ 25 kB gzip).
  let api;
  if (ctx.mockApi && typeof ctx.mockApi === 'object') api = ctx.mockApi;
  else if (ctx.mockApi) {
    const M = typeof window !== 'undefined' && window.BPSAIMock;
    if (!M || typeof M.createMockApi !== 'function') throw new Error('BPSAI: mockApi:true vraagt window.BPSAIMock (patch-studio-ai-mock.js)');
    api = M.createMockApi({ lang, n: aiCfg.n, refineN: aiCfg.refineN, netDelayMs: 120 });
  } else api = createApi({ base: (ctx.api && ctx.api.base) || '', nonce: ctx.api && ctx.api.nonce, token: ctx.api && ctx.api.token, lang });

  const draft = lsGet(DRAFT_KEY);
  const store = createStore(initialState(draft && typeof draft === 'object' ? { form: Object.assign(initialState().form, pick(draft, ['description', 'text', 'style', 'shape', 'maxColors'])) } : {}));
  const S = () => store.get();
  const D = (a) => store.dispatch(a);

  let root = null;
  let isOpen = false;
  let poller = null;
  let opener = null;
  let lastStatusAt = 0;
  let mode = 'sheet';
  let renderedKey = '';
  let payTimer = null;
  let waitTimer = null;
  let slowTimer = null;
  let longWait = false;
  let pushedHistory = false;
  const local = { email: '', emailStep: 'email', emailError: '', triesLeft: null, pack: null, checkoutUrl: '', consent: [false, false], history: null, historyLoading: false, sheetBusy: false, packError: '' };

  /* ------------------------------------------------------------- events */
  function emit(name, detail) {
    try {
      ctx.host.dispatchEvent(new CustomEvent(name, { detail, bubbles: true }));
    } catch (e) { /* host weg */ }
  }
  function track(event, props) {
    emit('bps:track', { event: 'ai_' + event, props: props || {} });
  }
  function announce(text) {
    if (!root) return;
    const live = root.querySelector('.bps-ai__live');
    if (!live) return;
    live.textContent = '';
    setTimeout(() => {
      live.textContent = text;
    }, 30);
  }
  function preview(url, index) {
    if (typeof ctx.renderOnPatch === 'function') {
      try {
        ctx.renderOnPatch(url);
      } catch (e) { /* studio-fout: geen voorbeeld */ }
    } else emit('bps:ai:preview', url ? { jobId: S().proposalsJobId, index, url } : { url: null });
  }

  /* ------------------------------------------------------------- data */
  async function loadStatus(force) {
    if (!force && S().status && Date.now() - lastStatusAt < 60000) return S().status;
    D({ type: 'LOADING' });
    try {
      const st = await api.status();
      lastStatusAt = Date.now();
      D({ type: 'STATUS', status: st });
      emit('bps:ai:credits:changed', { balance: S().balance });
      if (st && st.openJob && !poller) {
        D({ type: 'JOB', job: { jobId: st.openJob, kind: 'generate' }, n: st.n || 3, at: Date.now() });
        startPoll(st.openJob);
      }
      return st;
    } catch (err) {
      const m = mapError(err);
      D({ type: 'STATUS', status: S().status });
      if (m.view === 'budget' || m.view === 'paused') D({ type: 'VIEW', view: m.view, notice: { key: m.key } });
      else D({ type: 'VIEW', view: 'describing', notice: { key: m.key, params: m.params, tone: 'warn' } });
      return null;
    }
  }

  async function refreshBalance() {
    const before = S().balance.total;
    try {
      const r = api.credits ? await api.credits() : await api.status();
      const b = r && r.balance;
      if (b) {
        D({ type: 'BALANCE', balance: b });
        emit('bps:ai:credits:changed', { balance: S().balance });
        const after = S().balance.total;
        if (after > before && S().sheet === 'pack' && S().waitingForPayment) {
          const added = after - before;
          stopPayWatch();
          D({ type: 'SHEET', sheet: null });
          D({ type: 'NOTICE', notice: { key: 'ai.pack.arrived', params: { n: added }, tone: 'ok' } });
          announce(t('ai.pack.arrived', { n: added }));
          focusPrimary();
        } else if (S().sheet === 'pack' && S().balance.paidLocked > 0 && S().waitingForPayment) {
          stopPayWatch();
          D({ type: 'SHEET', sheet: 'consent' });
        }
      }
    } catch (e) { /* stil: volgende focus probeert opnieuw */ }
  }

  function stopPayWatch() {
    if (payTimer) clearInterval(payTimer);
    payTimer = null;
    D({ type: 'PAYING', on: false });
  }
  function startPayWatch() {
    D({ type: 'PAYING', on: true });
    if (payTimer) clearInterval(payTimer);
    payTimer = setInterval(() => {
      if (!document.hidden) refreshBalance();
    }, 4000);
  }

  let lastFocusRefresh = 0;
  function onFocusBack() {
    if (!isOpen || document.hidden) return;
    if (Date.now() - lastFocusRefresh < 2500) return;
    lastFocusRefresh = Date.now();
    refreshBalance();
  }

  function studioContext() {
    let s = {};
    try {
      s = (typeof ctx.getStudio === 'function' && ctx.getStudio()) || {};
    } catch (e) {
      s = {};
    }
    const c = {};
    ['technique', 'sizeMm', 'fabric', 'edge'].forEach((k) => {
      if (s[k] != null && s[k] !== '') c[k] = s[k];
    });
    return { context: c, shape: s.shape, maxColors: s.maxColors };
  }

  function saveDraft() {
    const f = S().form;
    lsSet(DRAFT_KEY, f.description || f.text ? pick(f, ['description', 'text', 'style', 'shape', 'maxColors']) : null);
  }

  /* ------------------------------------------------------------- acties */
  async function generate() {
    const s = S();
    if (s.busy) return;
    const f = s.form;
    const desc = String(f.description || '').trim();
    if (desc.length < 3) {
      D({ type: 'NOTICE', notice: { key: 'ai.prompt.short', tone: 'warn' } });
      announce(t('ai.prompt.short'));
      const ta = root && root.querySelector('#bps-ai-desc');
      if (ta) ta.focus();
      return;
    }
    if (!canAfford(s, 'generate')) {
      D({ type: 'NEED', sheet: 'auto' });
      return;
    }
    const sc = studioContext();
    const body = {
      clientRequestId: uuid(),
      description: desc.slice(0, limit('descriptionMaxChars', 400)),
      text: String(f.text || '').trim().slice(0, limit('textMaxChars', 60)),
      style: f.style || 'auto',
      // Wat de studio al weet, gaat automatisch mee (SPEC §1.1); een eigen keuze in "Meer opties" wint.
      shape: f.shape && f.shape !== 'auto' ? f.shape : REST_SHAPES.includes(sc.shape) ? sc.shape : 'auto',
      maxColors: f.maxColors || (Number(sc.maxColors) > 0 ? Math.min(8, Math.floor(Number(sc.maxColors))) : null),
      textMode: String(f.text || '').trim() ? 'studio' : 'auto',
      context: sc.context,
    };
    D({ type: 'SUBMIT', kind: 'generate' });
    saveDraft();
    track('generate', { style: body.style, shape: body.shape });
    await submit(() => api.generate(body), 'generate', (s.status && s.status.n) || 3);
  }

  async function submit(fn, kind, n) {
    try {
      const res = await fn();
      if (res && res.route === 'local') {
        D({ type: 'LOCAL', res });
        announce(res.message || t('ai.refine.free'));
        return;
      }
      if (res && (res.blocked || res.status === 'blocked')) {
        D({ type: 'BLOCKED', job: res });
        emit('bps:ai:blocked', { code: S().blocked ? S().blocked.code : res.code, message: res.message || '' });
        announce((res.message || t('ai.blocked.title')) + ' ' + t('ai.blocked.nocharge'));
        return;
      }
      if (res && ['done', 'failed', 'cancelled'].includes(res.status)) {
        onFinal(res);
        return;
      }
      D({ type: 'JOB', job: res, kind, n, at: Date.now() });
      emit('bps:ai:job:started', { jobId: res.jobId, kind, n });
      emit('bps:ai:credits:changed', { balance: S().balance });
      announce(kind === 'refine' ? t('ai.wait.refine') : t('ai.wait', { n }));
      startPoll(res.jobId);
    } catch (err) {
      handleError(err);
    }
  }

  function handleError(err) {
    const m = mapError(err);
    if (m.view === 'needRounds') {
      D({ type: 'NEED', sheet: m.sheet, notice: m.key ? { key: m.key, tone: 'warn' } : null });
      if (m.key) announce(t(m.key));
    } else if (m.view === 'waiting' && m.jobId) {
      D({ type: 'JOB', job: { jobId: m.jobId, kind: 'generate' }, n: (S().status && S().status.n) || 3, at: Date.now() });
      startPoll(m.jobId);
    } else if (m.view === 'paused' || m.view === 'budget') {
      D({ type: 'VIEW', view: m.view, notice: { key: m.key } });
      announce(t(m.key));
    } else {
      D({ type: 'NOTICE', notice: { key: m.key, params: m.params, tone: 'warn', message: m.message } });
      announce(t(m.key, m.params));
      if (m.retryAfter) {
        if (slowTimer) clearTimeout(slowTimer);
        slowTimer = setTimeout(() => {
          if (S().notice && S().notice.key === 'ai.slow_down') D({ type: 'NOTICE', notice: null });
        }, m.retryAfter * 1000);
      }
    }
  }

  function startPoll(jobId) {
    if (poller) poller.stop();
    longWait = false;
    if (waitTimer) clearTimeout(waitTimer);
    waitTimer = setTimeout(() => {
      longWait = true;
      if (S().view === 'waiting') {
        const p = root && root.querySelector('.a-wait-long');
        if (p) p.hidden = false;
      }
    }, 20000);
    poller = pollJob({
      fetchJob: () => api.job(jobId),
      onUpdate: (job) => D({ type: 'JOB_UPDATE', job }),
      onFinal: (job) => onFinal(job),
      onError: (e) => {
        poller = null;
        const code = e.code === 'timeout' ? 'timeout' : e.code === 'network' ? 'network' : e.code || 'provider_error';
        D({ type: 'JOB_FAILED', code, job: { jobId, error: { code, message: '' } } });
        emit('bps:ai:job:failed', { jobId, code, refunded: true });
        announce(code === 'timeout' ? t('ai.timeout') : code === 'network' ? t('ai.network') : t('ai.error'));
        if (code === 'bps_ai_not_found') D({ type: 'DESCRIBE' });
      },
      timeoutSec: 100,
    });
  }

  function onFinal(job) {
    poller = null;
    if (waitTimer) clearTimeout(waitTimer);
    if (job.status === 'done') {
      D({ type: 'JOB_DONE', job });
      emit('bps:ai:job:done', { jobId: job.jobId, kind: job.kind, count: (job.proposals || []).length });
      emit('bps:ai:credits:changed', { balance: S().balance });
      track('done', { kind: job.kind, count: (job.proposals || []).length });
      announce(job.kind === 'refine' ? t('ai.refine.done', { n: S().proposals.length }) : pickHint());
      const sel = S().proposals.find((p) => p.index === S().selected);
      if (sel) preview(sel.url, sel.index);
      runChecks();
      setTimeout(() => {
        const tile = root && root.querySelector('.a-tile[aria-checked=true]');
        if (tile) tile.focus();
      }, 60);
    } else if (job.status === 'blocked') {
      D({ type: 'BLOCKED', job });
      emit('bps:ai:blocked', { code: (job.error && job.error.code) || job.code, message: (job.error && job.error.message) || '' });
      announce(((job.error && job.error.message) || t('ai.blocked.title')) + ' ' + t('ai.blocked.nocharge'));
    } else if (job.status === 'cancelled') {
      D({ type: 'JOB_FAILED', job, cancelled: true });
      emit('bps:ai:credits:changed', { balance: S().balance });
      announce(t('ai.cancelled'));
    } else {
      D({ type: 'JOB_FAILED', job });
      emit('bps:ai:job:failed', { jobId: job.jobId, code: (job.error && job.error.code) || 'provider_error', refunded: true });
      emit('bps:ai:credits:changed', { balance: S().balance });
      announce((job.error && job.error.message) || t('ai.error'));
    }
  }

  async function cancel() {
    const j = S().job;
    if (!j) return;
    if (poller) poller.stop();
    poller = null;
    try {
      const res = await api.cancel(j.jobId);
      onFinal(res && res.status ? res : { jobId: j.jobId, status: 'cancelled' });
    } catch (e) {
      onFinal({ jobId: j.jobId, status: 'cancelled', error: { code: 'cancelled', message: '' } });
    }
  }

  async function runChecks() {
    if (typeof ctx.quickCheck !== 'function') return;
    const jobId = S().proposalsJobId;
    for (const p of S().proposals) {
      if (S().proposalsJobId !== jobId || !isOpen) return;
      try {
        const file = await api.file(p.url, 'voorstel-' + (p.index + 1), p.mime);
        const r = await ctx.quickCheck(file);
        if (r && r.status && S().proposalsJobId === jobId) D({ type: 'CHECK', index: p.index, result: r });
      } catch (e) { /* geen check: geen label */ }
    }
  }

  function select(index) {
    D({ type: 'SELECT', index });
    const p = S().proposals.find((x) => x.index === index);
    if (p) {
      preview(p.url, index);
      announce(p.alt || t('ai.proposal', { i: index + 1 }));
    }
  }

  async function refine(forceAi, instructionOverride) {
    const s = S();
    if (s.busy || s.selected == null) return;
    const input = root && root.querySelector('#bps-ai-refine');
    const instruction = String(instructionOverride != null ? instructionOverride : input ? input.value : '').trim();
    if (instruction.length < 2) {
      if (input) input.focus();
      return;
    }
    if (forceAi && !canAfford(s, 'refine')) {
      D({ type: 'NEED', sheet: 'auto' });
      return;
    }
    if (instructionOverride == null) D({ type: 'CHAT', entry: { who: 'you', text: instruction } });
    if (input) input.value = '';
    D({ type: 'SUBMIT', kind: 'refine' });
    track('refine', { forceAi: !!forceAi });
    await submit(
      () => api.refine(s.proposalsJobId, { proposalIndex: s.selected, instruction: instruction.slice(0, limit('refineMaxChars', 200)), forceAi: !!forceAi, clientRequestId: uuid() }),
      'refine',
      (s.status && s.status.refineN) || 2
    );
  }

  async function useDesign(after) {
    const s = S();
    if (s.busy || s.selected == null || !s.proposalsJobId) return;
    D({ type: 'USING' });
    announce(t('ai.using'));
    try {
      const res = await api.choose(s.proposalsJobId, { proposalIndex: s.selected });
      const file = await api.file(res.imageUrl, res.fileName || 'ai-ontwerp.png', res.mime);
      const detail = { file, url: res.imageUrl, source: 'ai', ai: res.ai, hints: Object.assign({ background: 'transparent' }, res.hints || {}) };
      emit('bps:ai:design:chosen', detail);
      track('chosen', { index: s.selected });
      const chk = s.checks[s.selected];
      if (chk && chk.status && chk.status !== 'ok' && !(after && after.d1)) emit('bps:ai:service:suggested', { serviceId: 'd1_finish', jobId: s.proposalsJobId, index: s.selected });
      D({ type: 'CHOSEN' });
      lsSet(DRAFT_KEY, null);
      announce(t('ai.chosen'));
      if (typeof after === 'function') after();
      close('chosen');
    } catch (err) {
      handleError(err);
    }
  }

  function localAction(res) {
    const detail = { action: res.action };
    ['text', 'colors', 'shape'].forEach((k) => {
      if (res[k] != null) detail[k] = res[k];
    });
    useDesign(() => emit('bps:ai:local:action', detail));
  }

  function upload(reason) {
    emit('bps:ai:upload:requested', { reason: reason || 'user' });
    close('upload');
  }

  function limit(k, d) {
    const l = (S().status && S().status.limits) || aiCfg.limits || {};
    return Number(l[k]) || d;
  }

  /* ------------------------------------------------------------- sheets */
  const sheetApi = {
    t,
    lang,
    S,
    D,
    local,
    rerender: () => render(true),
    announce,
    emit,
    track,
    api,
    startPayWatch,
    stopPayWatch,
    refreshBalance,
    upload,
    hasCustomCheckout: typeof ctx.openCheckout === 'function',
    openCheckout(url) {
      if (typeof ctx.openCheckout === 'function') {
        ctx.openCheckout(url);
        return true;
      }
      return false;
    },
    loadHistory: async () => {
      local.historyLoading = true;
      render(true);
      try {
        const r = await api.history();
        local.history = (r && r.jobs) || [];
      } catch (e) {
        local.history = [];
      }
      local.historyLoading = false;
      render(true);
    },
    showJob(job) {
      D({ type: 'SHEET', sheet: null });
      D({ type: 'JOB_DONE', job: Object.assign({}, job, { kind: 'generate', status: 'done' }) });
      const sel = S().proposals.find((p) => p.index === S().selected);
      if (sel) preview(sel.url, sel.index);
    },
    closeSheet() {
      stopPayWatch();
      D({ type: 'SHEET', sheet: null });
      focusPrimary();
    },
  };

  /* ------------------------------------------------------------- render */
  function key() {
    const s = S();
    return [s.view, s.sheet, s.busy, s.proposalsJobId, s.selected, s.chat.length, s.notice && s.notice.key, s.error && s.error.code, s.blocked && s.blocked.code, JSON.stringify(s.balance), JSON.stringify(s.checks), s.waitingForPayment, !!s.status].join('|');
  }

  function render(force) {
    if (!root || !isOpen) return;
    const k = key();
    if (!force && k === renderedKey) return;
    renderedKey = k;
    const active = document.activeElement && root.contains(document.activeElement) ? document.activeElement : null;
    const focusKey = active ? active.getAttribute('data-k') : null;
    let caret = null;
    try {
      caret = active && typeof active.selectionStart === 'number' ? [active.selectionStart, active.selectionEnd] : null;
    } catch (e) {
      caret = null;
    }
    const panel = root.querySelector('.bps-ai__panel');
    const s = S();
    const head = renderHead();
    const test = (s.status && s.status.testMode) || aiCfg.testMode ? h('p', { class: 'bps-ai__test', role: 'note' }, (s.status && s.status.testNotice) || t('ai.test_mode')) : null;
    const body = h('div', { class: 'bps-ai__body', id: 'bps-ai-body' }, renderNotice(), renderView());
    const foot = renderFoot();
    const sheet = s.sheet ? renderSheet(s.sheet, sheetApi) : null;
    const live = root.querySelector('.bps-ai__live');
    panel.replaceChildren(head, test || '', body, foot, live);
    if (sheet) {
      panel.appendChild(sheet);
      [head, test, body, foot].forEach((n) => n && n.setAttribute && n.setAttribute('inert', ''));
    }
    if (focusKey) {
      const target = panel.querySelector(`[data-k="${focusKey}"]`);
      if (target && (!sheet || sheet.contains(target))) {
        target.focus();
        if (caret && typeof target.setSelectionRange === 'function') {
          try {
            target.setSelectionRange(caret[0], caret[1]);
          } catch (e) { /* type zonder selectie */ }
        }
      }
      else if (sheet) focusFirst(sheet);
    } else if (sheet) focusFirst(sheet);
  }

  function focusFirst(container) {
    setTimeout(() => {
      const f = container.querySelector('[data-autofocus]') || focusables(container)[0];
      if (f) f.focus();
    }, 20);
  }
  function focusPrimary() {
    setTimeout(() => {
      const b = root && root.querySelector('.bps-ai__foot .a-btn--primary, #bps-ai-desc');
      if (b) b.focus();
    }, 30);
  }

  function renderHead() {
    const s = S();
    const txt = s.status ? counterText(s.balance, t) : t('ai.loading');
    return h(
      'header',
      { class: 'bps-ai__head' },
      h('h2', { class: 'bps-ai__title', id: 'bps-ai-title' }, h('span', { class: 'bps-ai__spark', html: ICON.spark }), t('ai.title')),
      h(
        'button',
        {
          type: 'button',
          class: 'bps-ai__counter' + (s.balance.total <= 0 ? ' bps-ai__counter--empty' : ''),
          'aria-label': t('ai.counter.label', { txt }),
          'data-k': 'counter',
          onclick: () => D({ type: 'SHEET', sheet: s.balance.paidLocked > 0 ? 'consent' : 'pack' }),
        },
        h('span', { 'aria-hidden': 'true' }, txt),
        h('b', { 'aria-hidden': 'true' }, '+')
      ),
      h('button', { type: 'button', class: 'bps-ai__x', 'aria-label': t('ai.close'), 'data-k': 'close', html: ICON.close, onclick: () => close('user') })
    );
  }

  function renderNotice() {
    const n = S().notice;
    if (!n || !n.key) return null;
    const tone = n.tone === 'ok' ? 'a-note a-note--ok' : n.tone === 'warn' ? 'a-note a-note--warn' : 'a-note';
    return h('p', { class: tone }, n.message && n.key === 'ai.prompt.short' ? n.message : t(n.key, n.params || {}));
  }

  function renderView() {
    const s = S();
    switch (s.view) {
      case 'idle':
        return h('p', { class: 'a-center a-small', 'aria-busy': 'true' }, t('ai.loading'));
      case 'describing':
      case 'submitting':
        return renderDescribe();
      case 'waiting':
        return renderWaiting();
      case 'proposals':
        return renderProposals();
      case 'blocked':
        return renderBlocked();
      case 'failed':
        return h('div', { class: 'a-center' }, h('span', { class: 'a-icon', html: ICON.stop }), h('p', { class: 'a-big' }, t('ai.retry')), h('p', null, msgForError(s.error)));
      case 'paused':
        return h('div', { class: 'a-center' }, h('span', { class: 'a-icon', html: ICON.stop }), h('p', null, t('legal.ai.abuse_pause') || t('ai.unavailable')));
      case 'budget':
        return h('div', { class: 'a-center' }, h('span', { class: 'a-icon', html: ICON.stop }), h('p', null, t((s.notice && s.notice.key) || 'ai.unavailable')));
      case 'chosen':
        return h('div', { class: 'a-center' }, h('span', { class: 'a-icon', html: ICON.ok }), h('p', null, t('ai.chosen')));
      case 'needRounds':
        return renderDescribe();
      default:
        return null;
    }
  }

  function msgForError(err) {
    if (!err) return t('ai.error');
    if (err.message) return err.message;
    if (err.code === 'timeout') return t('ai.timeout');
    if (err.code === 'network') return t('ai.network');
    return t('ai.error');
  }

  function renderDescribe() {
    const s = S();
    const f = s.form;
    const max = limit('descriptionMaxChars', 400);
    const ideasLang = IDEAS[lang];
    const styles = (Array.isArray(aiCfg.styles) && aiCfg.styles.length ? aiCfg.styles : DEFAULT_STYLES.map((id) => ({ id, label: id }))).filter((st) => ideasLang[st.id]);
    const count = h('span', { class: 'a-count', id: 'bps-ai-desc-count', 'aria-live': 'off' }, t('ai.prompt.count', { n: (f.description || '').length, max }));
    const ta = h('textarea', {
      id: 'bps-ai-desc',
      class: 'a-field',
      'data-k': 'desc',
      rows: '3',
      maxlength: String(max),
      placeholder: t('ai.prompt.placeholder'),
      'aria-describedby': 'bps-ai-desc-count bps-ai-privacy',
      enterkeyhint: 'go',
      oninput: (e) => {
        D({ type: 'FORM', patch: { description: e.target.value } });
        count.textContent = t('ai.prompt.count', { n: e.target.value.length, max });
      },
      onkeydown: (e) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
          e.preventDefault();
          generate();
        }
      },
    });
    ta.value = f.description || '';
    const ideas = h(
      'div',
      { class: 'a-block' },
      h('p', { class: 'a-h', id: 'bps-ai-ideas-h' }, t('ai.ideas')),
      h(
        'ul',
        { class: 'a-ideas', 'aria-labelledby': 'bps-ai-ideas-h' },
        styles.map((st) =>
          h(
            'li',
            null,
            h(
              'button',
              {
                type: 'button',
                class: 'a-idea',
                'data-k': 'idea-' + st.id,
                'aria-pressed': f.style === st.id ? 'true' : 'false',
                'aria-label': t('ai.idea.pick', { label: st.label }) + ': ' + ideasLang[st.id],
                onclick: () => {
                  const cur = String(S().form.description || '').trim();
                  const isIdea = Object.values(ideasLang).includes(cur);
                  const same = S().form.style === st.id;
                  const patch = { style: same ? 'auto' : st.id };
                  if (!same && (!cur || isIdea)) patch.description = ideasLang[st.id];
                  D({ type: 'FORM', patch });
                  render(true);
                },
              },
              h('strong', null, st.label),
              h('span', null, ideasLang[st.id])
            )
          )
        )
      )
    );
    const txt = h('input', {
      id: 'bps-ai-text',
      class: 'a-field',
      'data-k': 'text',
      type: 'text',
      maxlength: String(limit('textMaxChars', 60)),
      placeholder: t('ai.text.placeholder'),
      autocomplete: 'off',
      'aria-describedby': 'bps-ai-text-hint',
      oninput: (e) => D({ type: 'FORM', patch: { text: e.target.value } }),
    });
    txt.value = f.text || '';
    const seg = (name, values, cur, label, fmt) =>
      h(
        'fieldset',
        null,
        h('legend', null, label),
        h(
          'div',
          { class: 'a-seg' },
          values.map((v) =>
            h(
              'label',
              null,
              h('input', {
                type: 'radio',
                name: 'bps-ai-' + name,
                'data-k': name + '-' + v,
                checked: String(cur == null ? 'auto' : cur) === String(v == null ? 'auto' : v),
                onchange: () => D({ type: 'FORM', patch: { [name]: v } }),
              }),
              h('span', null, fmt(v))
            )
          )
        )
      );
    const more = h(
      'details',
      { class: 'a-more', open: f.shape !== 'auto' || f.maxColors ? true : null },
      h('summary', { 'data-k': 'more' }, t('ai.more')),
      seg('shape', SHAPES, f.shape, t('ai.shape'), (v) => (v === 'auto' ? t('ai.auto') : t('ai.shape.' + v))),
      seg('maxColors', COLORS, f.maxColors, t('ai.colors'), (v) => (v == null ? t('ai.auto') : String(v)))
    );
    return h(
      'div',
      null,
      h('div', { class: 'a-block' }, h('label', { class: 'a-label', for: 'bps-ai-desc' }, t('ai.prompt.label')), ta, count),
      ideas,
      h('div', { class: 'a-block' }, h('label', { class: 'a-label', for: 'bps-ai-text' }, t('ai.text.label')), txt, t('legal.ai.privacy_hint') ? h('p', { class: 'a-hint', id: 'bps-ai-text-hint' }, t('legal.ai.privacy_hint')) : null),
      more,
      renderPrivacy(),
      // Vervaldatum van betaalde rondes, 30 dagen vooraf (legal.ai.expiry_notice, 04-juridisch §4.5 en §8).
      S().balance.expiring && h('p', { class: 'a-small' }, t('legal.ai.expiry_notice', { u: S().balance.expiring.rounds, datum: fmtDate(S().balance.expiring.at, lang) })),
      h('div', { class: 'a-row a-row--center' }, h('button', { type: 'button', class: 'a-btn a-btn--ghost', 'data-k': 'history', onclick: () => openHistory() }, t('ai.history')))
    );
  }

  function pickHint() {
    return mode === 'sheet' ? t('ai.pick_hint.sheet') : t('ai.pick_hint');
  }

  /** Privacyzin onder het tekstvak; "Meer info" wordt een echte link (of verdwijnt als er geen privacypagina is). */
  function renderPrivacy() {
    const line = t('legal.ai.privacy_line');
    if (!line) return h('p', { class: 'a-small', id: 'bps-ai-privacy' }, t('ai.no_brands'));
    const m = /\s*(Meer info|En savoir plus)$/.exec(line);
    const url = String(aiCfg.privacyUrl || (config.contact && config.contact.privacyUrl) || '');
    const ok = m && /^(https?:\/\/|\/)/.test(url);
    return h('p', { class: 'a-small', id: 'bps-ai-privacy' }, m ? line.slice(0, m.index) : line, ok ? ' ' : null, ok ? h('a', { href: url, target: '_blank', rel: 'noopener' }, m[1]) : null);
  }

  /** "Laat onze ontwerper dit afwerken" (D1) — de chatmelding verwijst ernaar, dus de knop moet er ook echt zijn. */
  function designerButton() {
    const cat = config.services && Array.isArray(config.services.catalog) ? config.services.catalog : null;
    const d1 = cat ? cat.find((x) => x && x.id === 'd1_finish' && x.enabled !== false) : null;
    if (!d1) return null;
    return h(
      'button',
      {
        type: 'button',
        class: 'a-btn a-btn--ghost',
        'data-k': 'designer',
        onclick: () => {
          const s = S();
          const idx = s.selected;
          const after = () => emit('bps:ai:service:suggested', { serviceId: 'd1_finish', jobId: s.proposalsJobId, index: idx, explicit: true });
          after.d1 = true; // geen tweede, automatische suggestie
          useDesign(after);
        },
      },
      d1.label
    );
  }

  function openHistory() {
    local.history = null;
    D({ type: 'SHEET', sheet: 'history' });
    sheetApi.loadHistory();
  }

  function renderWaiting() {
    const s = S();
    const n = (s.job && s.job.n) || 3;
    const refine = s.job && s.job.kind === 'refine';
    return h(
      'div',
      { 'aria-busy': 'true' },
      h('div', { class: 'a-grid' + (n === 4 ? ' a-grid--4' : n === 2 ? ' a-grid--2' : '') }, Array.from({ length: n }, () => h('div', { class: 'a-skel', 'aria-hidden': 'true' }))),
      h('p', { class: 'a-wait' }, refine ? t('ai.wait.refine') : t('ai.wait', { n })),
      h('p', { class: 'a-small a-center a-wait-long', hidden: !longWait }, t('ai.wait.long'))
    );
  }

  function renderProposals() {
    const s = S();
    const und = s.understood;
    const chips = und && und.summary ? String(und.summary).split(/\s*·\s*/).filter(Boolean) : [];
    const n = s.proposals.length;
    const tiles = s.proposals.map((p) => {
      const chk = s.checks[p.index];
      const checked = p.index === s.selected;
      return h(
        'button',
        {
          type: 'button',
          role: 'radio',
          class: 'a-tile',
          'aria-checked': checked ? 'true' : 'false',
          tabindex: checked ? '0' : '-1',
          'data-k': 'tile-' + p.index,
          'data-index': String(p.index),
          onclick: () => select(p.index),
          ondblclick: () => useDesign(),
        },
        h('img', { src: p.url, alt: p.alt || t('ai.proposal', { i: p.index + 1 }), width: '256', height: '256', decoding: 'async', draggable: 'false' }),
        h('span', { class: 'a-num', 'aria-hidden': 'true' }, String(p.index + 1)),
        h('span', { class: 'a-ailabel' }, t('legal.ai.label')),
        chk && chk.status ? h('span', { class: 'a-chk a-chk--' + chk.status }, (chk.status === 'ok' ? '✓ ' : '! ') + t('ai.check.' + chk.status)) : null
      );
    });
    const group = h('div', { class: 'a-grid' + (n === 4 ? ' a-grid--4' : n === 2 ? ' a-grid--2' : ''), role: 'radiogroup', 'aria-label': t('ai.proposals'), onkeydown: radioKeys }, tiles);
    const provider = (s.status && s.status.providerLabel) || aiCfg.providerLabel || 'AI';
    return h(
      'div',
      null,
      chips.length ? h('div', null, h('p', { class: 'a-h' }, t('ai.understood')), h('ul', { class: 'a-chips' }, chips.map((c) => h('li', null, c)))) : null,
      group,
      h('p', { class: 'a-small' }, pickHint()),
      t('legal.ai.provider_notice') ? h('p', { class: 'a-small' }, t('legal.ai.provider_notice', { provider })) : null,
      t('legal.ai.rights_short') ? h('details', { class: 'a-rights' }, h('summary', { 'data-k': 'rights' }, t('ai.rights.q')), h('p', null, t('legal.ai.rights_short'))) : null,
      renderChat(),
      h(
        'div',
        { class: 'a-row a-row--center' },
        h('button', { type: 'button', class: 'a-btn a-btn--ghost', 'data-k': 'new', onclick: () => D({ type: 'DESCRIBE' }) }, t('ai.new')),
        h('button', { type: 'button', class: 'a-btn a-btn--ghost', 'data-k': 'history', onclick: () => openHistory() }, t('ai.history')),
        designerButton()
      )
    );
  }

  function radioKeys(e) {
    const s = S();
    const idx = s.proposals.map((p) => p.index);
    const cur = idx.indexOf(s.selected);
    let next = null;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = idx[(cur + 1) % idx.length];
    else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = idx[(cur - 1 + idx.length) % idx.length];
    else if (e.key === 'Home') next = idx[0];
    else if (e.key === 'End') next = idx[idx.length - 1];
    else if (e.key === ' ' || e.key === 'Enter') {
      e.preventDefault();
      return;
    }
    if (next == null) return;
    e.preventDefault();
    select(next);
    setTimeout(() => {
      const el = root.querySelector(`.a-tile[data-index="${next}"]`);
      if (el) el.focus();
    }, 0);
  }

  function renderChat() {
    const s = S();
    const sel = s.selected != null ? s.selected + 1 : 1;
    const items = s.chat.map((m) => {
      if (m.who === 'you') return h('li', { class: 'a-msg a-msg--you' }, h('span', { class: 'a-who' }, t('ai.refine.you')), m.text);
      const text = m.key ? t(m.key, m.params || {}) : m.text;
      const row = m.local
        ? h(
            'div',
            { class: 'a-row' },
            h('button', { type: 'button', class: 'a-btn', 'data-k': 'local-open', onclick: () => localAction(m.local) }, t('ai.refine.open')),
            h('button', { type: 'button', class: 'a-btn', 'data-k': 'local-force', onclick: () => refine(true, lastYou()) }, t('ai.refine.force'))
          )
        : m.blockedCode === 'brand' || m.blockedCode === 'copy'
          ? h('div', { class: 'a-row' }, h('button', { type: 'button', class: 'a-btn', onclick: () => upload(m.blockedCode) }, (t('legal.ai.own_logo_cta') || t('ai.upload'))))
          : null;
      return h('li', { class: 'a-msg a-msg--ai' + (m.tone === 'warn' ? ' a-msg--warn' : '') }, h('span', { class: 'a-who' }, t('ai.refine.ai')), text, row);
    });
    const input = h('input', {
      id: 'bps-ai-refine',
      class: 'a-field',
      'data-k': 'refine',
      type: 'text',
      maxlength: String(limit('refineMaxChars', 200)),
      placeholder: t('ai.refine'),
      autocomplete: 'off',
      enterkeyhint: 'send',
      onkeydown: (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          refine(false);
        }
      },
    });
    return h(
      'section',
      { class: 'a-chat', 'aria-labelledby': 'bps-ai-refine-l' },
      t('legal.ai.chat_notice') ? h('p', { class: 'a-small' }, t('legal.ai.chat_notice')) : null,
      items.length ? h('ul', { class: 'a-log', 'aria-label': t('ai.refine.ai') }, items) : null,
      h('label', { class: 'a-label', for: 'bps-ai-refine', id: 'bps-ai-refine-l' }, t('ai.refine.label', { i: sel })),
      h(
        'div',
        { class: 'a-ask' },
        input,
        h('button', { type: 'button', class: 'a-send', 'aria-label': t('ai.refine.send'), title: t('ai.refine.send'), 'data-k': 'refine-send', html: ICON.send, disabled: s.busy, onclick: () => refine(false) })
      ),
      h('p', { class: 'a-hint' }, t('ai.refine.cost'))
    );
  }

  function lastYou() {
    const c = S().chat;
    for (let i = c.length - 1; i >= 0; i--) if (c[i].who === 'you') return c[i].text;
    return '';
  }

  function renderBlocked() {
    const b = S().blocked || {};
    const ownLogo = ['brand', 'copy', 'official'].includes(b.code);
    return h(
      'div',
      { class: 'a-center' },
      h('span', { class: 'a-icon', html: ICON.stop }),
      h('p', { class: 'a-big' }, t('ai.blocked.title')),
      h('p', null, b.message || ''),
      h('p', { class: 'a-small' }, t('ai.blocked.nocharge')),
      ownLogo ? h('button', { type: 'button', class: 'a-btn a-btn--wide', 'data-k': 'own-logo', onclick: () => upload(b.code) }, (t('legal.ai.own_logo_cta') || t('ai.upload'))) : null
    );
  }

  function renderFoot() {
    const s = S();
    const act = primaryAction(Object.assign({}, s, { sheet: null }));
    const n = (s.status && s.status.n) || aiCfg.n || 3;
    let btn = null;
    let sub = null;
    const P = (label, onclick, extra) => h('button', Object.assign({ type: 'button', class: 'a-btn a-btn--primary', 'data-k': 'primary', onclick }, extra || {}), label);
    switch (act) {
      case 'generate':
        btn = P(t('ai.generate', { n }), generate, { 'aria-busy': s.busy ? 'true' : null, 'aria-disabled': s.busy ? 'true' : null });
        sub = (s.balance.paid <= 0 && s.balance.free > 0 ? t('ai.cost.one_free') : t('ai.cost.one')) + ' · ' + t('ai.no_brands');
        break;
      case 'buy':
        {
          // Kan de klant nog gratis rondes krijgen met een e-mailadres, zeg dat dan meteen op de knop.
          const gate = (s.status && s.status.emailGate) || {};
          const viaEmail = pickSheet(s.status, s.balance) === 'email';
          btn = P(viaEmail ? t('ai.email.title_n', { n: gate.offer }) : t('ai.counter.more'), () => D({ type: 'NEED', sheet: 'auto' }));
          sub = viaEmail ? t('ai.buy.email_sub') : counterText(s.balance, t);
        }
        break;
      case 'cancel':
        btn = h('button', { type: 'button', class: 'a-btn a-btn--wide', 'data-k': 'primary', onclick: cancel }, t('ai.cancel'));
        break;
      case 'use':
        btn = P(t('ai.use'), () => useDesign(), { 'aria-busy': s.busy ? 'true' : null, 'aria-disabled': s.busy ? 'true' : null });
        sub = t('ai.use.sub', { i: (s.selected || 0) + 1 });
        break;
      case 'edit':
        btn = P(t('ai.edit_description'), () => {
          D({ type: 'DESCRIBE' });
          focusPrimary();
        });
        break;
      case 'retry':
        btn = P(t('ai.retry'), () => {
          if (S().lastKind === 'refine' && S().proposals.length) D({ type: 'VIEW', view: 'proposals' });
          else {
            D({ type: 'DESCRIBE' });
            generate();
          }
        });
        sub = null;
        break;
      case 'close':
        btn = P(t('ai.close'), () => close('user'));
        if (s.view === 'paused' || s.view === 'budget') sub = null;
        break;
      default:
        btn = null;
    }
    const extra =
      s.view === 'paused' || s.view === 'budget'
        ? h('div', { class: 'a-row a-row--center' }, h('button', { type: 'button', class: 'a-btn a-btn--ghost', onclick: () => upload('user') }, t('ai.upload')))
        : s.view === 'failed'
          ? h('div', { class: 'a-row a-row--center' }, h('button', { type: 'button', class: 'a-btn a-btn--ghost', onclick: () => D({ type: 'DESCRIBE' }) }, t('ai.edit_description')))
          : null;
    return h('footer', { class: 'bps-ai__foot' }, btn, sub ? h('p', { class: 'bps-ai__sub' }, sub) : null, extra);
  }

  /* ------------------------------------------------------------- open/close */
  function build() {
    mode = ctx.presentation === 'inline' || ctx.presentation === 'sheet' ? ctx.presentation : window.matchMedia && window.matchMedia('(min-width: 1025px)').matches ? 'inline' : 'sheet';
    const dialog = mode === 'sheet';
    root = h(
      'div',
      { class: 'bps-ai bps-ai--' + mode, lang, onkeydown: onKey },
      dialog ? h('div', { class: 'bps-ai__backdrop', onclick: () => close('user') }) : null,
      h(
        'section',
        dialog ? { class: 'bps-ai__panel', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'bps-ai-title' } : { class: 'bps-ai__panel', role: 'region', 'aria-labelledby': 'bps-ai-title' },
        h('div', { class: 'bps-ai__live a-sr', 'aria-live': 'polite', role: 'status' })
      )
    );
    el.appendChild(root);
  }

  function onKey(e) {
    if (e.key === 'Escape') {
      e.stopPropagation();
      if (S().sheet) sheetApi.closeSheet();
      else close('user');
      return;
    }
    if (e.key !== 'Tab') return;
    const container = root.querySelector('.bps-ai__sheet') || (mode === 'sheet' ? root.querySelector('.bps-ai__panel') : null);
    if (!container) return;
    const list = focusables(container);
    if (!list.length) return;
    const first = list[0];
    const last = list[list.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function onViewport() {
    if (!root || mode !== 'sheet') return;
    const vv = window.visualViewport;
    const hgt = vv ? vv.height : window.innerHeight;
    const kb = vv ? Math.max(0, window.innerHeight - vv.height - vv.offsetTop) : 0;
    root.style.setProperty('--bps-ai-vh', hgt + 'px');
    root.style.setProperty('--bps-ai-kb', kb + 'px');
  }

  function onPop() {
    if (isOpen && pushedHistory) {
      pushedHistory = false;
      close('user', true);
    }
  }

  function open(opts) {
    const o = opts || {};
    if (isOpen) {
      if (o.prefill) D({ type: 'FORM', patch: { description: String(o.prefill) } });
      render(true);
      return;
    }
    opener = document.activeElement;
    if (!root) build();
    root.hidden = false;
    isOpen = true;
    if (o.prefill) D({ type: 'FORM', patch: { description: String(o.prefill) } });
    if (mode === 'sheet') {
      document.documentElement.classList.add('bps-ai-lock');
      onViewport();
      if (window.visualViewport) window.visualViewport.addEventListener('resize', onViewport);
      if (ctx.historyBack !== false && window.history && window.history.pushState) {
        try {
          window.history.pushState({ bpsAi: 1 }, '');
          pushedHistory = true;
          window.addEventListener('popstate', onPop);
        } catch (e) {
          pushedHistory = false;
        }
      }
    }
    window.addEventListener('focus', onFocusBack);
    document.addEventListener('visibilitychange', onFocusBack);
    emit('bps:ai:open', { entry: o.entry || 'empty-state' });
    track('open', { entry: o.entry || 'empty-state' });
    if (S().view === 'chosen') D({ type: 'DESCRIBE' });
    // Paneel gesloten tijdens het wachten: de job liep op de server door, dus meteen verder pollen.
    if (S().view === 'waiting' && S().job && S().job.jobId && !poller) startPoll(S().job.jobId);
    render(true);
    loadStatus(false).then(() => {
      if (S().view === 'describing') {
        const ta = root.querySelector('#bps-ai-desc');
        if (ta && !ta.value) ta.focus();
        else focusPrimary();
      }
    });
    // Desktop: de kolom kan lager op de pagina staan; scroll zodat de primaire knop onderaan meteen zichtbaar is.
    if (mode === 'inline') {
      const pnl = root.querySelector('.bps-ai__panel');
      try {
        const r = pnl && pnl.getBoundingClientRect();
        if (r && (r.bottom > window.innerHeight || r.top < 0)) pnl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      } catch (e) { /* oude browser: niet scrollen */ }
    }
    const title = root.querySelector('#bps-ai-title');
    if (title) {
      title.setAttribute('tabindex', '-1');
      title.focus({ preventScroll: true });
    }
  }

  function close(reason, fromPop) {
    if (!isOpen) return;
    isOpen = false;
    if (poller && reason !== 'user') poller.stop();
    if (poller) {
      poller.stop();
      poller = null;
    }
    stopPayWatch();
    if (S().sheet) D({ type: 'SHEET', sheet: null });
    if (reason !== 'chosen') preview(null);
    if (root) root.hidden = true;
    renderedKey = '';
    document.documentElement.classList.remove('bps-ai-lock');
    if (window.visualViewport) window.visualViewport.removeEventListener('resize', onViewport);
    window.removeEventListener('focus', onFocusBack);
    document.removeEventListener('visibilitychange', onFocusBack);
    window.removeEventListener('popstate', onPop);
    if (pushedHistory && !fromPop) {
      pushedHistory = false;
      try {
        if (window.history.state && window.history.state.bpsAi) window.history.back();
      } catch (e) { /* geen geschiedenis */ }
    }
    saveDraft();
    emit('bps:ai:close', { reason: reason || 'user' });
    if (opener && typeof opener.focus === 'function' && document.contains(opener)) {
      try {
        opener.focus();
      } catch (e) { /* niet focusbaar */ }
    }
  }

  store.subscribe(() => render(false));

  return {
    open,
    close: (reason) => close(reason || 'user'),
    refreshBalance,
    destroy() {
      close('user');
      if (root && root.parentNode) root.parentNode.removeChild(root);
      root = null;
    },
    state: () => Object.freeze(Object.assign({}, S())),
    /** Interne acties voor tests/dev (niet voor de studio). */
    _act: { generate, select, refine, useDesign, cancel, pickSheet: () => pickSheet(S().status, S().balance) },
  };
}

function pick(o, keys) {
  const out = {};
  keys.forEach((k) => {
    if (o && o[k] !== undefined) out[k] = o[k];
  });
  return out;
}
