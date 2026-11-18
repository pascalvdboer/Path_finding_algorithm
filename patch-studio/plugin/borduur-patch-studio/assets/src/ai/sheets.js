/**
 * AI-ontwerpstudio — sheets bovenop het paneel (SPEC-AI-DIENSTEN §1.2 [M] [P], §10.4 needRounds):
 *   email   — e-mail → 6-cijferige code → +2 gratis rondes (e-mail gaat nooit naar de AI)
 *   pack    — 2 pakketten, bovenste als keuze geselecteerd (geen vinkje), terugbetaalregel, "Betaal € 4,95";
 *             kassa in een nieuw tabblad, het saldo komt hier vanzelf binnen (focus/visibility + elke 4 s)
 *   consent — gekochte maar vergrendelde rondes: twee lege vakjes (legal.ai.pack_consent_start/ack)
 *   history — "Mijn voorstellen" (laatste 30 dagen)
 * De klant verlaat de flow nooit: na sluiten of na betalen staat zijn beschrijving en zijn voorstellen er nog.
 */
import { h, ICON, money } from './dom.js';

function fmtNum(v, lang) {
  const n = Number(v) || 0;
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ',');
}
export function fmtDate(iso, lang) {
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  const p = (x) => String(x).padStart(2, '0');
  return lang === 'fr' ? `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()}` : `${p(d.getDate())}-${p(d.getMonth() + 1)}-${d.getFullYear()}`;
}

/**
 * @param {'email'|'pack'|'consent'|'history'} name
 * @param {object} x  sheetApi uit panel.js
 */
export function renderSheet(name, x) {
  const { t } = x;
  const titles = {
    email: () => {
      const offer = (x.S().status && x.S().status.emailGate && x.S().status.emailGate.offer) || 2;
      return t('ai.email.title_n', { n: offer });
    },
    pack: () => (x.S().balance.total <= 0 ? t('ai.pack.title') : t('ai.pack.title_more')),
    consent: () => t('ai.consent.title'),
    history: () => t('ai.history'),
  };
  const build = { email: emailSheet, pack: packSheet, consent: consentSheet, history: historySheet }[name] || historySheet;
  const { body, foot } = build(x);
  return h(
    'div',
    { class: 'bps-ai__sheet', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'bps-ai-sheet-title' },
    h(
      'header',
      { class: 'bps-ai__head' },
      h('button', { type: 'button', class: 'bps-ai__x', 'aria-label': t('ai.back'), 'data-k': 'sheet-back', html: ICON.back, onclick: () => x.closeSheet() }),
      h('h2', { class: 'bps-ai__title', id: 'bps-ai-sheet-title' }, titles[name] ? titles[name]() : '')
    ),
    h('div', { class: 'bps-ai__body' }, body),
    h('footer', { class: 'bps-ai__foot' }, foot)
  );
}

function note(text, tone) {
  return text ? h('p', { class: 'a-note' + (tone ? ' a-note--' + tone : ''), role: tone === 'bad' || tone === 'warn' ? 'alert' : null }, text) : null;
}

/* ------------------------------------------------------------------ e-mail */
function emailSheet(x) {
  const { t, local, api } = x;
  const st = x.S().status || {};
  const busy = local.sheetBusy;
  const setErr = (err) => {
    const code = err && err.code;
    const d = (err && err.data) || {};
    if (code === 'bps_ai_code_wrong') local.emailError = t('ai.email.wrong', { n: Math.max(0, Number(d.triesLeft) || 0) });
    else if (code === 'bps_ai_code_expired') {
      local.emailError = t('ai.email.expired');
      local.emailStep = 'email';
    } else if (code === 'bps_ai_slow_down') local.emailError = t('ai.slow_down', { s: Math.max(1, Math.ceil(Number(d.retryAfter) || 30)) });
    else if (code === 'bps_ai_invalid') local.emailError = t('ai.email.invalid');
    else if (code === 'network') local.emailError = t('ai.network');
    else local.emailError = t('ai.error.nocharge');
    x.announce(local.emailError);
  };
  async function send() {
    const input = document.getElementById('bps-ai-email');
    const email = String(input ? input.value : local.email).trim();
    local.email = email;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
      local.emailError = t('ai.email.invalid');
      x.rerender();
      x.announce(local.emailError);
      return;
    }
    local.sheetBusy = true;
    local.emailError = '';
    x.rerender();
    try {
      await api.email({ email });
      local.emailStep = 'code';
      x.announce(t('ai.email.sent', { email }));
    } catch (e) {
      setErr(e);
    }
    local.sheetBusy = false;
    x.rerender();
  }
  async function verify() {
    const input = document.getElementById('bps-ai-code');
    const code = String(input ? input.value : '').replace(/\D/g, '');
    if (code.length !== 6) {
      if (input) input.focus();
      return;
    }
    local.sheetBusy = true;
    local.emailError = '';
    x.rerender();
    try {
      const r = await api.emailVerify({ email: local.email, code });
      const status = Object.assign({}, x.S().status, { emailGate: Object.assign({}, (x.S().status || {}).emailGate, { done: true }), balance: r.balance });
      local.sheetBusy = false;
      local.emailStep = 'email';
      x.D({ type: 'STATUS', status });
      x.D({ type: 'SHEET', sheet: null });
      x.D({ type: 'NOTICE', notice: { key: 'ai.email.ok', params: { n: r.granted || 0 }, tone: 'ok' } });
      x.emit('bps:ai:credits:changed', { balance: x.S().balance });
      x.announce(t('ai.email.ok', { n: r.granted || 0 }));
      return;
    } catch (e) {
      setErr(e);
    }
    local.sheetBusy = false;
    x.rerender();
  }
  const err = local.emailError ? note(local.emailError, 'warn') : null;
  const orPack = st.packsBuyable ? h('div', { class: 'a-row a-row--center' }, h('button', { type: 'button', class: 'a-btn a-btn--ghost', 'data-k': 'or-pack', onclick: () => x.D({ type: 'SHEET', sheet: 'pack' }) }, t('ai.email.or_pack'))) : null;
  if (local.emailStep === 'code') {
    const code = h('input', {
      id: 'bps-ai-code', class: 'a-field', 'data-k': 'code', 'data-autofocus': '', type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code',
      pattern: '[0-9]*', maxlength: '6', 'aria-describedby': 'bps-ai-code-hint',
      onkeydown: (e) => { if (e.key === 'Enter') { e.preventDefault(); verify(); } },
    });
    return {
      body: h(
        'div',
        null,
        h('p', { id: 'bps-ai-code-hint' }, t('ai.email.sent', { email: local.email })),
        err,
        h('label', { class: 'a-label', for: 'bps-ai-code' }, t('ai.email.code')),
        code,
        h('div', { class: 'a-row a-row--center' }, h('button', { type: 'button', class: 'a-btn a-btn--ghost', 'data-k': 'again', onclick: () => { local.emailStep = 'email'; local.emailError = ''; x.rerender(); } }, t('ai.email.again'))),
        orPack
      ),
      foot: h('button', { type: 'button', class: 'a-btn a-btn--primary', 'data-k': 'verify', 'aria-busy': busy ? 'true' : null, onclick: verify }, t('ai.email.verify')),
    };
  }
  const email = h('input', {
    id: 'bps-ai-email', class: 'a-field', 'data-k': 'email', 'data-autofocus': '', type: 'email', inputmode: 'email', autocomplete: 'email',
    value: local.email, 'aria-describedby': 'bps-ai-email-body',
    onkeydown: (e) => { if (e.key === 'Enter') { e.preventDefault(); send(); } },
  });
  return {
    body: h('div', null, h('p', { id: 'bps-ai-email-body' }, t('ai.email.body')), err, h('label', { class: 'a-label', for: 'bps-ai-email' }, t('ai.email.label')), email, orPack),
    foot: h('button', { type: 'button', class: 'a-btn a-btn--primary', 'data-k': 'send', 'aria-busy': busy ? 'true' : null, onclick: send }, t('ai.email.send')),
  };
}

/* ------------------------------------------------------------------ pakket */
function packSheet(x) {
  const { t, local, lang } = x;
  const s = x.S();
  const st = s.status || {};
  const packs = Array.isArray(st.packs) ? st.packs : [];
  if (!local.pack || !packs.some((p) => p.id === local.pack)) local.pack = packs.length ? packs[0].id : null;
  const chosen = packs.find((p) => p.id === local.pack);
  const credit = st.orderCredit || {};
  const gate = st.emailGate || {};
  const parts = [];
  if (s.balance.total <= 0) parts.push(h('p', null, t('ai.pack.kept')));
  if (s.notice && s.notice.key === 'ai.free_paused') parts.push(note(t('ai.free_paused'), 'warn'));
  if (local.packError) parts.push(note(local.packError, 'warn'));

  if (!st.packsBuyable || !packs.length) {
    parts.push(note(t('ai.pack.not_buyable')));
    if (gate.offer > 0 && !gate.done) parts.push(h('button', { type: 'button', class: 'a-btn a-btn--wide', 'data-k': 'to-email', onclick: () => x.D({ type: 'SHEET', sheet: 'email' }) }, t('ai.email.title_n', { n: gate.offer })));
    parts.push(h('p', { class: 'a-small' }, t('ai.pack.alt')));
    parts.push(h('button', { type: 'button', class: 'a-btn a-btn--wide', 'data-k': 'upload', onclick: () => x.upload('user') }, t('ai.upload')));
    return { body: h('div', null, parts), foot: h('button', { type: 'button', class: 'a-btn a-btn--primary', 'data-k': 'sheet-close', onclick: () => x.closeSheet() }, t('ai.back')) };
  }

  if (s.waitingForPayment) {
    parts.push(note(t('ai.pack.back')));
    if (local.checkoutUrl) parts.push(h('p', { class: 'a-center' }, h('a', { class: 'a-btn', href: local.checkoutUrl, target: '_blank', rel: 'noopener', 'data-k': 'open-link' }, t('ai.pack.open_link'))));
    return {
      body: h('div', null, parts),
      foot: h('button', { type: 'button', class: 'a-btn a-btn--primary', 'data-k': 'paid', 'data-autofocus': '', onclick: () => x.refreshBalance() }, t('ai.pack.check')),
    };
  }

  const list = h(
    'fieldset',
    null,
    h('legend', { class: 'a-sr' }, t('ai.pack.title_more')),
    h(
      'div',
      { class: 'a-packs' },
      packs.map((p) =>
        h(
          'label',
          { class: 'a-pack' },
          h('input', {
            type: 'radio', name: 'bps-ai-pack', value: p.id, 'data-k': 'pack-' + p.id, checked: p.id === local.pack,
            onchange: () => { local.pack = p.id; x.rerender(); },
          }),
          h('span', null, h('strong', null, t('ai.pack.option', { n: p.rounds, price: p.priceHtml || money(p.priceIncl, lang) })), h('small', null, t('ai.pack.per', { per: money((Number(p.priceIncl) || 0) / Math.max(1, p.rounds), lang), m: st.validityMonths || 24 })))
        )
      )
    )
  );
  parts.push(list);
  // Een leek weet niet wat een "ronde" is: zeg het één keer, in gewone woorden.
  parts.push(h('p', { class: 'a-small' }, t('ai.pack.what', { n: Number(st.n) || 3 })));
  const pct = Number(credit.percent);
  if (pct > 0) {
    const params = { percent: pct, min: fmtNum(credit.minArticleIncl, lang), months: credit.validMonths || 12 };
    parts.push(h('p', { class: 'a-note a-note--ok' }, t('ai.pack.refund', params)));
  }
  parts.push(h('p', { class: 'a-small' }, t('ai.pack.failed_back') + ' ' + t('legal.ai.pack_unused_refund')));
  if (gate.offer > 0 && !gate.done) parts.push(h('button', { type: 'button', class: 'a-btn a-btn--wide', 'data-k': 'to-email', onclick: () => x.D({ type: 'SHEET', sheet: 'email' }) }, t('ai.email.title_n', { n: gate.offer })));
  parts.push(h('p', { class: 'a-small', style: 'margin-top:14px' }, t('ai.pack.alt')));
  parts.push(h('div', { class: 'a-row a-row--center' }, h('button', { type: 'button', class: 'a-btn a-btn--ghost', 'data-k': 'upload', onclick: () => x.upload('user') }, t('ai.upload'))));

  async function pay() {
    if (!chosen || local.sheetBusy) return;
    local.packError = '';
    local.checkoutUrl = '';
    // Venster meteen (synchroon, binnen de tik) openen: anders blokkeert de browser het als pop-up.
    let win = null;
    const custom = typeof x.openCheckout === 'function' && x.hasCustomCheckout;
    if (!custom) {
      try {
        win = window.open('', '_blank');
      } catch (e) {
        win = null;
      }
    }
    local.sheetBusy = true;
    x.rerender();
    try {
      const r = await x.api.checkout({ packId: chosen.id });
      const url = String((r && r.checkoutUrl) || '');
      if (!/^(https?:\/\/|\/|#)/i.test(url)) throw new Error('bad url');
      if (!x.openCheckout(url)) {
        if (win && !win.closed) {
          try {
            win.opener = null;
            win.location.href = url;
          } catch (e) {
            local.checkoutUrl = url;
          }
        } else local.checkoutUrl = url;
      } else if (win) win.close();
      x.emit('bps:ai:pack:checkout', { packId: chosen.id, url });
      x.track('pack_checkout', { packId: chosen.id });
      x.announce(t('ai.pack.opening') + ' ' + t('ai.pack.back'));
      x.startPayWatch();
    } catch (e) {
      if (win && !win.closed) win.close();
      local.packError = e && e.code === 'network' ? t('ai.network') : t('ai.error.nocharge');
      x.announce(local.packError);
    }
    local.sheetBusy = false;
    x.rerender();
  }
  return {
    body: h('div', null, parts),
    foot: h(
      'button',
      { type: 'button', class: 'a-btn a-btn--primary', 'data-k': 'pay', 'aria-busy': local.sheetBusy ? 'true' : null, onclick: pay },
      t('ai.pack.pay', { price: chosen ? chosen.priceHtml || money(chosen.priceIncl, lang) : '' })
    ),
  };
}

/* ------------------------------------------------------------------ toestemming */
function consentSheet(x) {
  const { t, local, lang } = x;
  const b = x.S().balance;
  const both = local.consent[0] && local.consent[1];
  const box = (i, key) =>
    h(
      'label',
      { class: 'a-check' },
      h('input', { type: 'checkbox', 'data-k': 'consent-' + i, checked: local.consent[i], onchange: (e) => { local.consent[i] = e.target.checked; x.rerender(); } }),
      h('span', null, t(key))
    );
  async function go() {
    if (!both || local.sheetBusy) return;
    local.sheetBusy = true;
    x.rerender();
    try {
      const r = await x.api.consent({ start: true, ack: true });
      local.consent = [false, false];
      local.sheetBusy = false;
      x.D({ type: 'BALANCE', balance: r.balance });
      x.D({ type: 'SHEET', sheet: null });
      x.D({ type: 'NOTICE', notice: { key: 'ai.pack.arrived', params: { n: x.S().balance.total }, tone: 'ok' } });
      x.emit('bps:ai:credits:changed', { balance: x.S().balance });
      x.announce(t('ai.pack.arrived', { n: x.S().balance.total }));
      return;
    } catch (e) {
      local.packError = t('ai.error.nocharge');
    }
    local.sheetBusy = false;
    x.rerender();
  }
  return {
    body: h(
      'div',
      null,
      h('p', null, t('legal.ai.pack_consent_later')),
      local.packError ? note(local.packError, 'warn') : null,
      box(0, 'legal.ai.pack_consent_start'),
      box(1, 'legal.ai.pack_consent_ack'),
      h('p', { class: 'a-small' }, t('legal.ai.pack_unused_refund')),
      b.lockedUntil ? h('p', { class: 'a-small' }, t('ai.consent.wait', { date: fmtDate(b.lockedUntil, lang) })) : null
    ),
    foot: h('button', { type: 'button', class: 'a-btn a-btn--primary', 'data-k': 'consent-go', disabled: !both, 'aria-busy': local.sheetBusy ? 'true' : null, onclick: go }, t('ai.consent.go')),
  };
}

/* ------------------------------------------------------------------ geschiedenis */
function historySheet(x) {
  const { t, local, lang } = x;
  let body;
  if (local.historyLoading || local.history == null) body = h('p', { class: 'a-small a-center', 'aria-busy': 'true' }, t('ai.loading'));
  else if (!local.history.length) body = h('p', { class: 'a-small' }, t('ai.history.empty'));
  else
    body = h(
      'ul',
      { class: 'a-hist' },
      local.history.map((j) =>
        h(
          'li',
          null,
          (j.proposals || []).slice(0, 2).map((p) => h('img', { src: p.url, alt: '', loading: 'lazy' })),
          h('p', null, (j.understood && j.understood.summary) || '', h('br'), h('span', { class: 'a-small' }, fmtDate(j.createdAt, lang))),
          h('button', { type: 'button', class: 'a-btn', onclick: () => x.showJob(j) }, t('ai.history.open'))
        )
      )
    );
  return { body, foot: h('button', { type: 'button', class: 'a-btn a-btn--primary', 'data-k': 'sheet-close', onclick: () => x.closeSheet() }, t('ai.back')) };
}
