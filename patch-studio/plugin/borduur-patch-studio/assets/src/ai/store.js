/**
 * AI-ontwerpstudio — toestandsmachine (SPEC-AI-DIENSTEN §10.4). Puur: `reduce(state, action)` + kleine store.
 *
 *   idle → describing → submitting → waiting(jobId) → proposals → (refining → waiting) → chosen
 *   zijpaden: blocked, failed, needRounds(email|pack|consent), paused, budget
 *
 * Elke toestand heeft precies één primaire actie (`primaryAction()`), getest in tests/ai/frontend-store.test.js.
 * Een sheet (e-mail, pakket, toestemming, geschiedenis) ligt bovenop de huidige toestand: na sluiten of na het
 * kopen van rondes gaat de klant verder waar hij was (beschrijving en voorstellen blijven staan).
 */

export const VIEWS = ['idle', 'describing', 'submitting', 'waiting', 'proposals', 'chosen', 'blocked', 'failed', 'needRounds', 'paused', 'budget'];
export const SHEETS = ['email', 'pack', 'consent', 'history'];

const EMPTY_BAL = { free: 0, paid: 0, paidLocked: 0, total: 0, nextExpiry: null, lockedUntil: null };

export function initialState(extra) {
  return Object.assign(
    {
      view: 'idle',
      status: null,
      balance: Object.assign({}, EMPTY_BAL),
      form: { description: '', text: '', style: 'auto', shape: 'auto', maxColors: null },
      job: null, // { jobId, kind, n, startedAt }
      proposals: [],
      proposalsJobId: null,
      understood: null,
      selected: null,
      checks: {},
      chat: [],
      notice: null, // { key, params, message, tone:'info'|'warn'|'ok' }
      error: null, // { code, message, refunded }
      blocked: null, // { code, message }
      sheet: null,
      sheetReturn: null,
      waitingForPayment: false,
      busy: false,
    },
    extra || {}
  );
}

function bal(b) {
  if (!b || typeof b !== 'object') return null;
  const n = (x) => Math.max(0, Math.floor(Number(x) || 0));
  return {
    free: n(b.free),
    paid: n(b.paid),
    paidLocked: n(b.paidLocked),
    total: b.total != null ? n(b.total) : n(b.free) + n(b.paid),
    nextExpiry: b.nextExpiry || null,
    lockedUntil: b.lockedUntil || null,
  };
}

/** Welke sheet bij "geen rondes": toestemming (gekocht maar vergrendeld) > e-mail (+2 gratis) > pakket. */
export function pickSheet(status, balance) {
  const b = balance || EMPTY_BAL;
  if (b.paidLocked > 0) return 'consent';
  const gate = status && status.emailGate;
  if (gate && gate.offer > 0 && !gate.done) return 'email';
  return 'pack';
}

/** Kosten van een actie in rondes (uit StatusResponse.costs; standaard 1). */
export function cost(status, kind) {
  const c = status && status.costs && status.costs[kind];
  return c == null ? 1 : Math.max(0, Number(c) || 0);
}

export function canAfford(state, kind) {
  return state.balance.total >= cost(state.status, kind);
}

export function reduce(state, a) {
  const s = Object.assign({}, state);
  switch (a.type) {
    case 'LOADING':
      s.view = s.view === 'idle' || !s.status ? 'idle' : s.view;
      s.busy = true;
      return s;
    case 'STATUS': {
      s.status = a.status || null;
      s.balance = bal(a.status && a.status.balance) || s.balance;
      s.busy = false;
      if (s.status && s.status.pausedUntil) s.view = 'paused';
      else if (s.view === 'idle' || s.view === 'paused') s.view = 'describing';
      return s;
    }
    case 'FORM':
      s.form = Object.assign({}, s.form, a.patch || {});
      if (s.notice && s.notice.key === 'ai.prompt.short') s.notice = null;
      return s;
    case 'SUBMIT':
      s.view = 'submitting';
      s.busy = true;
      s.notice = null;
      s.error = null;
      s.blocked = null;
      s.lastKind = a.kind || 'generate';
      return s;
    case 'JOB': {
      const j = a.job || {};
      s.job = { jobId: j.jobId, kind: j.kind || a.kind || 'generate', n: a.n || (j.proposals && j.proposals.length) || 3, startedAt: a.at || 0 };
      s.view = 'waiting';
      s.busy = false;
      if (j.balance) s.balance = bal(j.balance);
      return s;
    }
    case 'JOB_UPDATE':
      if (a.job && a.job.balance) s.balance = bal(a.job.balance);
      return s;
    case 'JOB_DONE': {
      const j = a.job || {};
      const refine = j.kind === 'refine';
      s.proposals = Array.isArray(j.proposals) ? j.proposals.slice(0, 4) : [];
      s.proposalsJobId = j.jobId || null;
      s.understood = j.understood || s.understood;
      s.selected = s.proposals.length ? s.proposals[0].index : null;
      s.checks = {};
      s.job = null;
      s.busy = false;
      s.view = s.proposals.length ? 'proposals' : 'failed';
      if (!s.proposals.length) s.error = { code: 'provider_error', message: '', refunded: true };
      if (j.balance) s.balance = bal(j.balance);
      if (refine) s.chat = s.chat.concat([{ who: 'ai', key: 'ai.refine.done', params: { n: s.proposals.length } }]);
      else s.chat = [];
      return s;
    }
    case 'JOB_FAILED': {
      const j = a.job || {};
      const err = j.error || {};
      s.error = { code: err.code || a.code || 'provider_error', message: err.message || '', refunded: a.refunded !== false };
      s.job = null;
      s.busy = false;
      if (j.balance) s.balance = bal(j.balance);
      if (a.cancelled || j.status === 'cancelled') {
        s.notice = { key: 'ai.cancelled', message: err.message || '', tone: 'info' };
        s.view = s.proposals.length ? 'proposals' : 'describing';
        return s;
      }
      s.view = 'failed';
      return s;
    }
    case 'BLOCKED': {
      const j = a.job || {};
      s.blocked = { code: a.code || j.code || (j.error && j.error.code) || 'blocked', message: a.message || j.message || (j.error && j.error.message) || '' };
      s.job = null;
      s.busy = false;
      if (j.balance) s.balance = bal(j.balance);
      if (j.kind === 'refine' && s.proposals.length) {
        s.chat = s.chat.concat([{ who: 'ai', text: s.blocked.message, tone: 'warn', blockedCode: s.blocked.code }]);
        s.view = 'proposals';
        s.blocked = null;
      } else {
        s.view = 'blocked';
      }
      return s;
    }
    case 'SELECT':
      s.selected = a.index;
      return s;
    case 'CHECK':
      s.checks = Object.assign({}, s.checks, { [a.index]: a.result });
      return s;
    case 'CHAT':
      s.chat = s.chat.concat([a.entry]);
      return s;
    case 'LOCAL':
      s.busy = false;
      s.view = 'proposals';
      s.chat = s.chat.concat([{ who: 'ai', local: a.res, text: (a.res && a.res.message) || '' }]);
      return s;
    case 'NEED': {
      const sheet = a.sheet === 'auto' || !a.sheet ? pickSheet(s.status, s.balance) : a.sheet;
      if (!s.sheet) s.sheetReturn = s.view === 'submitting' ? (s.proposals.length ? 'proposals' : 'describing') : s.view;
      s.sheet = sheet;
      s.busy = false;
      if (s.view === 'submitting') s.view = s.sheetReturn;
      if (a.notice) s.notice = a.notice;
      return s;
    }
    case 'SHEET':
      if (a.sheet && !s.sheet) s.sheetReturn = s.view;
      s.sheet = a.sheet || null;
      if (!a.sheet) s.waitingForPayment = false;
      return s;
    case 'PAYING':
      s.waitingForPayment = !!a.on;
      return s;
    case 'BALANCE': {
      const b = bal(a.balance);
      if (b) s.balance = b;
      return s;
    }
    case 'NOTICE':
      s.notice = a.notice || null;
      s.busy = false;
      if (s.view === 'submitting') s.view = s.proposals.length ? 'proposals' : 'describing';
      return s;
    case 'USING':
      s.busy = true;
      return s;
    case 'CHOSEN':
      s.busy = false;
      s.view = 'chosen';
      return s;
    case 'VIEW':
      s.view = a.view;
      s.busy = false;
      if (a.notice !== undefined) s.notice = a.notice;
      return s;
    case 'DESCRIBE':
      s.view = 'describing';
      s.blocked = null;
      s.error = null;
      s.busy = false;
      return s;
    default:
      return state;
  }
}

/**
 * De ene primaire actie van de huidige toestand (bepaalt de grote knop onderaan).
 * @returns {string|null} generate|buy|cancel|use|edit|retry|close|consent|email|pay|null
 */
export function primaryAction(state) {
  if (state.sheet === 'email') return 'email';
  if (state.sheet === 'pack') return 'pay';
  if (state.sheet === 'consent') return 'consent';
  if (state.sheet === 'history') return 'close';
  switch (state.view) {
    case 'describing':
      return canAfford(state, 'generate') ? 'generate' : 'buy';
    case 'submitting':
      return 'generate';
    case 'waiting':
      return 'cancel';
    case 'proposals':
      return 'use';
    case 'blocked':
      return 'edit';
    case 'failed':
      return 'retry';
    case 'needRounds':
      return 'buy';
    case 'chosen':
    case 'paused':
    case 'budget':
      return 'close';
    default:
      return null;
  }
}

/** Rondeteller in gewone woorden: "1 gratis ronde" · "Nog 7 rondes" · "Geen rondes meer". */
export function counterText(balance, t) {
  const b = balance || EMPTY_BAL;
  if (b.total <= 0) return b.paidLocked > 0 ? t('ai.locked') : t('ai.none_left');
  if (b.paid <= 0 && b.free > 0) return t('ai.free_left', { n: b.free });
  return t('ai.left', { n: b.total });
}

/** Mini-store met abonnees (het paneel tekent opnieuw bij elke wijziging). */
export function createStore(initial) {
  let state = initial || initialState();
  const subs = new Set();
  return {
    get: () => state,
    dispatch(action) {
      const next = reduce(state, action);
      if (next !== state) {
        state = next;
        subs.forEach((f) => {
          try {
            f(state, action);
          } catch (e) { /* een kapotte abonnee breekt de rest niet */ }
        });
      }
      return state;
    },
    subscribe(f) {
      subs.add(f);
      return () => subs.delete(f);
    },
  };
}
