/**
 * Patch Studio UI — dialogs and server flows: adjust cut-out, save & mail, quote request, order (cart).
 * Server contract: SPEC §6.2. Offline (config.rest === null): a dev dialog shows the payload instead.
 */
import { EV, emit } from '../shared/events.js';
import { h, icon, uid } from './dom.js';
import { buildPayload } from './derive.js';
import { persist } from './state.js';
import { trustList } from './step-summary.js';

/** <dialog> with focus management (focus in, Esc closes, focus back to the opener). */
export function openDialog(ctx, title, build, { wide = false } = {}) {
  const opener = document.activeElement;
  const titleId = uid('bps-dlg');
  const dlg = h('dialog.bps-dialog' + (wide ? '.bps-dialog--wide' : ''), { 'aria-labelledby': titleId });
  const close = () => { try { dlg.close(); } catch (e) { dlg.removeAttribute('open'); } };
  dlg.addEventListener('close', () => {
    dlg.remove();
    if (opener && opener.focus && document.contains(opener)) opener.focus();
  });
  dlg.addEventListener('click', (e) => { if (e.target === dlg) close(); });
  const head = h('div.bps-dialog__head', h('h2.bps-h3', { id: titleId }, title),
    h('button.bps-ibtn', { type: 'button', 'aria-label': ctx.t('ui.dlg.close'), onclick: close }, icon('bad')));
  dlg.append(head, h('div.bps-dialog__body', build(close)));
  ctx.root.appendChild(dlg);
  if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
  const first = dlg.querySelector('.bps-dialog__body input:not([type=hidden]), .bps-dialog__body select, .bps-dialog__body textarea, .bps-dialog__body button');
  if (first) first.focus();
  return { dlg, close };
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

/** Labelled field with inline error (WCAG 3.3.1). */
function field(ctx, { name, label, type = 'text', required = false, autocomplete, textarea = false, value = '' }) {
  const id = uid('bps-f');
  const err = h('p.bps-field__error', { id: id + '-err', hidden: true });
  const input = h(textarea ? 'textarea.bps-input.bps-textarea' : 'input.bps-input', {
    id, name, type: textarea ? null : type, required, autocomplete, 'aria-describedby': id + '-err', rows: textarea ? 3 : null, value: textarea ? null : value,
  });
  if (textarea) input.value = value;
  const check = () => {
    const v = input.value.trim();
    let msg = '';
    if (required && !v) msg = ctx.t('ui.form.required');
    else if (type === 'email' && v && !EMAIL_RE.test(v)) msg = ctx.t('ui.form.email');
    err.hidden = !msg;
    err.textContent = msg;
    if (msg) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
    return !msg;
  };
  input.addEventListener('blur', () => { if (input.value) check(); });
  input.addEventListener('input', () => { if (!err.hidden) check(); });
  return { el: h('div.bps-field', h('label', { for: id }, label), input, err), input, check };
}

export function createFlows(ctx) {
  const { t, config, store, api } = ctx;
  let lastUploadKey = null;

  function devDialog(payload) {
    openDialog(ctx, t('ui.dev.title'), () => [h('p', t('ui.dev.text')), h('pre.bps-pre', JSON.stringify(payload, null, 2))], { wide: true });
  }

  /** Upload the design (once per unchanged content). Returns {designId, token, ref} or null in dev mode. */
  async function ensureUploaded(extra = {}) {
    const s = store.get();
    const payload = buildPayload(s, ctx.derived, config, extra);
    if (!api.online) { devDialog(payload); return null; }
    const hasFile = !!ctx.file;
    if (s.source.kind === 'upload' && !hasFile && !s.designId) {
      const e = new Error('reupload'); e.code = 'reupload'; throw e;
    }
    const { priceClient, rightsConsent, ...stable } = payload;
    const key = JSON.stringify(stable) + '|' + (s.source.hash || '');
    if (s.designId && s.token && (key === lastUploadKey || (!hasFile && s.source.kind === 'upload'))) return { designId: s.designId, token: s.token, ref: s.ref };
    const preview = await ctx.preview.toBlob(1200);
    const res = await api.createDesign({
      payload,
      original: s.source.kind === 'upload' ? ctx.file : null,
      preview,
      pageUrl: location.origin + location.pathname,
    });
    lastUploadKey = key;
    store.set({ designId: res.designId, token: res.token, ref: res.ref || null });
    return { designId: res.designId, token: res.token, ref: res.ref };
  }

  function errText(e) {
    if (!e) return t('ui.err.generic');
    if (e.code === 'reupload') return t('ui.s5.reupload');
    if (e.code === 'network') return t('ui.err.network');
    return e.message && e.status ? e.message : t('ui.err.generic');
  }

  function rightsConsent() {
    return { v: (config.legal && config.legal.rightsTextVersion) || '1', accepted: true };
  }

  async function order(resultEl) {
    if (ctx.busy) return;
    ctx.busy = true;
    ctx.refresh();
    resultEl.textContent = '';
    resultEl.append(h('p.bps-busy', h('span.bps-spinner', { 'aria-hidden': 'true' }), t('ui.s5.busy')));
    try {
      const up = await ensureUploaded({ rightsConsent: rightsConsent() });
      if (!up) { resultEl.textContent = ''; return; }
      const res = await api.addToCart({ designId: up.designId, token: up.token, rightsConsent: rightsConsent() });
      const s = store.get();
      emit(ctx.root, EV.ADDED_TO_CART, { designId: up.designId, ref: up.ref, cartUrl: res.cartUrl, cartCount: res.cartCount });
      ctx.track('added_to_cart', { technique: s.technique, sizeCm: s.sizeCm, qty: s.qty });
      try {
        document.body.dispatchEvent(new Event('wc_fragment_refresh'));
        if (window.jQuery) window.jQuery(document.body).trigger('wc_fragment_refresh');
      } catch (e) { /* theme without fragments */ }
      resultEl.textContent = '';
      const server = res.quote && res.quote.totalIncl;
      if (server != null && Math.abs(server - ctx.derived.quote.totalIncl) > 0.01) {
        resultEl.append(h('p.bps-notice.bps-notice--info', t('ui.s5.priceChanged', { total: ctx.fmt(server) })));
      }
      const redirect = res.redirect != null ? !!res.redirect : !(config.features && config.features.redirectToCart === false);
      resultEl.append(h('p.bps-notice.bps-notice--ok', icon('ok'), h('span', t('ui.s5.added'), ' ',
        h('a.bps-btn.bps-btn--small.bps-btn--secondary', { href: res.cartUrl || (config.rest && config.rest.cartUrl) || '#' }, t('ui.s5.toCart')))));
      if (redirect && res.cartUrl) setTimeout(() => { location.href = res.cartUrl; }, 600);
    } catch (e) {
      resultEl.textContent = '';
      if (e && e.code === 'bps_quote_only') { openQuote(); return; }
      emit(ctx.root, EV.ERROR, { code: e && e.code, message: errText(e), where: 'cart' });
      resultEl.append(h('div.bps-notice.bps-notice--bad', { role: 'alert' }, icon('warn'), h('div.bps-notice__body',
        h('p', e && e.code === 'reupload' ? t('ui.s5.reupload') : t('ui.s5.cartError')),
        e && e.message && e.status ? h('p.bps-muted', e.message) : null,
        h('p', h('button.bps-btn.bps-btn--small.bps-btn--secondary', { type: 'button', onclick: () => order(resultEl) }, t('ui.s5.retry')), ' ',
          h('button.bps-btn.bps-btn--small.bps-btn--ghost', { type: 'button', onclick: () => openQuote() }, t('ui.s5.asQuote'))))));
    } finally {
      ctx.busy = false;
      ctx.refresh();
    }
  }

  function openQuote() {
    const opened = Date.now();
    openDialog(ctx, t('ui.quote.title'), (close) => {
      const f = {
        name: field(ctx, { name: 'name', label: t('ui.quote.name'), required: true, autocomplete: 'name' }),
        email: field(ctx, { name: 'email', label: t('ui.quote.email'), type: 'email', required: true, autocomplete: 'email' }),
        phone: field(ctx, { name: 'phone', label: t('ui.quote.phone'), type: 'tel', autocomplete: 'tel' }),
        company: field(ctx, { name: 'company', label: t('ui.quote.company'), autocomplete: 'organization' }),
        deadline: field(ctx, { name: 'deadline', label: t('ui.quote.deadline') }),
        message: field(ctx, { name: 'message', label: t('ui.quote.message'), textarea: true, value: store.get().notes || '' }),
      };
      const rid = uid('bps-qr');
      const rerr = h('p.bps-field__error', { id: rid + '-err', hidden: true }, t('ui.s5.rightsError'));
      const rights = h('input', { type: 'checkbox', id: rid, checked: !!ctx.ui.rights, 'aria-describedby': rid + '-err' });
      const hp = h('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' });
      const out = h('div', { role: 'status', 'aria-live': 'polite' });
      const btn = h('button.bps-btn.bps-btn--primary.bps-btn--block', { type: 'submit' }, t('ui.quote.submit'));
      const form = h('form.bps-form', { novalidate: true },
        h('p', t('ui.quote.text')),
        f.name.el, f.email.el, f.phone.el, f.company.el, f.deadline.el, f.message.el,
        h('div.bps-check', rights, h('label', { for: rid }, t('ui.s5.rights'))), rerr,
        h('div.bps-hp', { 'aria-hidden': 'true' }, h('label', 'Website', hp)),
        h('p.bps-help', t('ui.quote.privacy'), ' ', t('ui.quote.free')),
        btn, out);
      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const bad = Object.values(f).filter((x) => !x.check());
        rerr.hidden = rights.checked;
        if (!rights.checked) rights.setAttribute('aria-invalid', 'true'); else rights.removeAttribute('aria-invalid');
        if (bad.length) { bad[0].input.focus(); return; }
        if (!rights.checked) { rights.focus(); return; }
        ctx.ui.rights = true;
        btn.disabled = true;
        out.textContent = t('ui.form.sending');
        const s = store.get();
        const body = {
          name: f.name.input.value.trim(), email: f.email.input.value.trim(), phone: f.phone.input.value.trim(),
          company: f.company.input.value.trim(), deadline: f.deadline.input.value.trim(), message: f.message.input.value.trim(),
          qty: s.qty, technique: s.technique, consent: true, rightsConsent: rightsConsent(), website: hp.value, elapsed: Date.now() - opened, locale: s.locale,
        };
        try {
          if (!api.online) { close(); devDialog({ design: buildPayload(s, ctx.derived, config), quote: body }); return; }
          let up = null;
          try { up = await ensureUploaded({ rightsConsent: rightsConsent() }); } catch (e) { if (e.code !== 'reupload') throw e; }
          const res = await api.requestQuote({ ...body, designId: up ? up.designId : undefined, token: up ? up.token : undefined });
          emit(ctx.root, EV.QUOTE_REQUESTED, { ref: res.ref });
          ctx.track('quote_requested', { technique: s.technique });
          form.replaceChildren(h('div.bps-notice.bps-notice--ok', icon('ok'), h('div.bps-notice__body',
            h('p', t('ui.quote.done', { name: body.name.split(' ')[0], phone: (config.contact && config.contact.phone) || '' })),
            res.ref ? h('p', t('ui.quote.ref', { ref: res.ref })) : null)), trustList(ctx));
        } catch (e) {
          btn.disabled = false;
          out.textContent = '';
          const fields = (e.data && e.data.fields) || {};
          for (const [k, msgTxt] of Object.entries(fields)) if (f[k]) { const er = f[k].el.querySelector('.bps-field__error'); er.hidden = false; er.textContent = msgTxt; f[k].input.setAttribute('aria-invalid', 'true'); }
          out.append(h('p.bps-notice.bps-notice--bad', { role: 'alert' }, errText(e)));
        }
      });
      return form;
    });
  }

  function openSave() {
    openDialog(ctx, t('ui.save.title'), () => {
      const email = field(ctx, { name: 'email', label: t('ui.save.email'), type: 'email', required: true, autocomplete: 'email' });
      const rid = uid('bps-rem');
      const rem = h('input', { type: 'checkbox', id: rid });
      const out = h('div', { role: 'status', 'aria-live': 'polite' });
      const btn = h('button.bps-btn.bps-btn--primary.bps-btn--block', { type: 'submit' }, t('ui.save.submit'));
      const devOut = h('p.bps-help', { role: 'status' });
      const form = h('form.bps-form', { novalidate: true },
        h('p', t('ui.save.text')),
        email.el,
        h('div.bps-check', rem, h('label', { for: rid }, t('ui.save.reminder'))),
        h('p.bps-help', t('ui.save.privacy', { days: (config.retention && config.retention.savedDays) || 90 })),
        btn, out,
        h('hr.bps-hr'),
        h('button.bps-btn.bps-btn--ghost.bps-btn--small', { type: 'button', onclick: () => { ctx.ui.device = true; persist(store.get(), true); devOut.textContent = t('ui.save.deviceDone'); } }, t('ui.save.device')),
        h('p.bps-help', t('ui.save.deviceHelp')), devOut);
      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        if (!email.check()) { email.input.focus(); return; }
        btn.disabled = true;
        out.textContent = t('ui.form.sending');
        try {
          const up = await ensureUploaded();
          if (!up) { btn.disabled = false; out.textContent = ''; return; }
          const s = store.get();
          await api.emailDesign(up.designId, { token: up.token, email: email.input.value.trim(), reminder: rem.checked, locale: s.locale });
          emit(ctx.root, EV.DESIGN_SAVED, { designId: up.designId, email: '' });
          ctx.track('design_saved', {});
          out.textContent = '';
          out.append(h('p.bps-notice.bps-notice--ok', icon('ok'), t('ui.save.done', { email: email.input.value.trim() })));
        } catch (e) {
          btn.disabled = false;
          out.textContent = '';
          out.append(h('p.bps-notice.bps-notice--bad', { role: 'alert' }, errText(e)));
        }
      });
      return form;
    });
  }

  function openAdjust() {
    const a = ctx.analysis;
    const levels = [3, 4.5, 6, 8, 11];
    const cur = (ctx.tweaks && ctx.tweaks.tolerance) || 2;
    openDialog(ctx, t('ui.adjust.title'), (close) => {
      const name = uid('bps-adj');
      const tol = h('input.bps-range', { type: 'range', min: 0, max: levels.length - 1, step: 1, value: cur, id: name + '-tol' });
      const keep = h('input', { type: 'checkbox', id: name + '-keep', checked: !!(ctx.derived.keepBg) });
      const shapes = (config.shapeOrder || Object.keys(config.shapes)).map((id) => h('label.bps-chip',
        h('input', { type: 'radio', name: name + '-shape', value: id, checked: store.get().shape === id }), h('span', ctx.L(config.shapes[id].label))));
      const form = h('form.bps-form', { method: 'dialog' },
        h('p', t('ui.adjust.text')),
        h('fieldset.bps-fieldset', h('legend.bps-legend', t('ui.s1.shape')), h('div.bps-chips', shapes)),
        h('div.bps-range-row', h('label', { for: tol.id }, t('ui.adjust.tolerance')), h('span.bps-muted', t('ui.adjust.tight')), tol, h('span.bps-muted', t('ui.adjust.loose'))),
        a && a.background && a.background.hex ? h('div.bps-check', keep, h('label', { for: keep.id }, t('ui.adjust.keepBg'))) : null,
        h('div.bps-step__nav',
          h('button.bps-btn.bps-btn--ghost', { type: 'button', onclick: close }, t('ui.dlg.cancel')),
          h('button.bps-btn.bps-btn--primary', { type: 'submit' }, t('ui.adjust.apply'))));
      form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const shape = (form.querySelector(`input[name="${name}-shape"]:checked`) || {}).value;
        const ti = Number(tol.value);
        close();
        ctx.reanalyze({ tolerance: ti, floodNeighborDE: levels[ti], keepBackground: keep.checked, shape });
      });
      return form;
    });
  }

  return { order, openQuote, openSave, openAdjust, ensureUploaded };
}
