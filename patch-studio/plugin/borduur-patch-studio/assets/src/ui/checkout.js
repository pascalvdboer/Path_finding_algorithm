/**
 * Patch Studio UI — order review ("Bestellen"): overview with "Wijzig" links into the studio, what happens next,
 * lead time, note, rights confirmation, order / quote, trust, save. Lives in the assistant column (desktop) or the
 * sheet (phone); it is not a modal, so the patch stays in view while the customer checks everything.
 */
import { optionById } from '../shared/config.js';
import { h, text, keyed, icon, uid } from './dom.js';

export function trustList(ctx) {
  const { config, t } = ctx;
  const p = config.promises || {};
  const c = config.contact || {};
  const items = [p.proof && ctx.L(p.proof), p.fromOne !== false && p.fromOneText && ctx.L(p.fromOneText), p.responseTime && ctx.L(p.responseTime)].filter(Boolean);
  return h('ul.bps-trust',
    items.map((s) => h('li', icon('ok', 'bps-ico--ok'), s)),
    c.whatsapp ? h('li', icon('chat'), h('a', { href: 'https://wa.me/' + String(c.whatsapp).replace(/\D/g, ''), target: '_blank', rel: 'noopener' }, t('ui.trust.whatsapp'))) : null);
}

export function createCheckout(ctx) {
  const { t, config, store } = ctx;
  const L = (o) => ctx.L(o);
  const rows = h('dl.bps-summary');
  const next = h('ol.bps-next');
  const lead = h('p.bps-help');
  const total = h('p.bps-checkout__total');
  const notesId = uid('bps-notes');
  const notes = h('textarea.bps-input.bps-textarea', { id: notesId, rows: 3, maxlength: 2000, 'aria-describedby': notesId + '-h', oninput: (e) => store.set({ notes: e.target.value.slice(0, 2000) }) });
  const rightsId = uid('bps-rights');
  const rightsErr = h('p.bps-field__error', { id: rightsId + '-err', hidden: true }, icon('warn'), t('ui.s5.rightsError'));
  const rights = h('input', { type: 'checkbox', id: rightsId, required: true, 'aria-describedby': rightsId + '-help', onchange: (e) => { ctx.ui.rights = e.target.checked; if (e.target.checked) { rightsErr.hidden = true; rights.removeAttribute('aria-invalid'); } ctx.refresh(); } });
  const p = config.promises || {};
  const legal = config.legal || {};
  const orderBtn = h('button.bps-btn.bps-btn--primary.bps-btn--big.bps-btn--block', { type: 'button', onclick: () => submit() });
  const orderSub = h('p.bps-cta__sub');
  const result = h('div.bps-result', { role: 'status', 'aria-live': 'polite' });
  const testimonial = p.testimonial && p.testimonial.enabled
    ? h('blockquote.bps-testimonial', t('ui.s5.testimonial', { text: L(p.testimonial.text), name: p.testimonial.name, role: L(p.testimonial.role) }))
    : null;

  function submit() {
    if (!rights.checked) {
      ctx.openPanel('checkout');
      rightsErr.hidden = false;
      rights.setAttribute('aria-invalid', 'true');
      rights.setAttribute('aria-describedby', rightsId + '-err ' + rightsId + '-help');
      rights.focus();
      return;
    }
    if (ctx.derived.quoteMode || !ctx.derived.quote.ok) ctx.openQuote();
    else ctx.order(result);
  }

  const el = h('section.bps-panel.bps-step.bps-checkout', { dataset: { panel: 'checkout' }, 'aria-labelledby': ctx.uidBase + '-p-checkout', hidden: true },
    h('header.bps-panel__head',
      h('button.bps-ibtn.bps-panel__back', { type: 'button', 'aria-label': t('ui.checkout.back'), title: t('ui.checkout.back'), onclick: () => ctx.openPanel('advice') }, icon('back2')),
      h('h2.bps-panel__title', { id: ctx.uidBase + '-p-checkout', tabindex: '-1' }, t('ui.s5.title'))),
    h('div.bps-panel__body',
      rows, total,
      h('section.bps-nextbox', h('h3.bps-h3', t('ui.s5.next.title')), next, lead),
      h('div.bps-field', h('label', { for: notesId }, t('ui.s5.notes')), notes, h('p.bps-help', { id: notesId + '-h' }, t('ui.s5.notesHelp'))),
      h('div.bps-rights',
        h('div.bps-check', rights, h('label', { for: rightsId }, t('ui.s5.rights'))),
        rightsErr,
        h('details.bps-why', h('summary', t('ui.s5.rightsWhy')), h('p', { id: rightsId + '-help' }, t('ui.s5.rightsHelp')))),
      legal.showWithdrawalNotice !== false ? h('div.bps-custom', icon('scissors'),
        h('div', h('p', h('strong', t('ui.s5.custom')), ' ', t('ui.s5.customText'), p.cancelUntilProof ? ' ' + t('ui.s5.cancelUntilProof') : ''),
          h('details.bps-why', h('summary', t('ui.s5.customWhy')), h('p', t('ui.s5.customMore'))))) : null,
      h('div.bps-cta', orderBtn, orderSub, trustList(ctx)),
      result,
      h('div.bps-secondary',
        config.features && config.features.saveDesign === false ? null : h('button.bps-btn.bps-btn--ghost', { type: 'button', onclick: () => ctx.openSave() }, t('ui.s5.save')),
        config.features && config.features.quote === false ? null : h('button.bps-btn.bps-btn--ghost', { type: 'button', onclick: () => ctx.openQuote() }, t('ui.s5.expert'))),
      testimonial));

  function row(label, value, panelId) {
    return [h('dt', label), h('dd', h('span', value), h('button.bps-btn.bps-btn--link.bps-btn--small', { type: 'button', 'aria-label': t('ui.s5.changeX', { what: label.toLowerCase() }), onclick: () => ctx.openPanel(panelId, { focus: true }) }, t('ui.s5.change')))];
  }

  return {
    el,
    submit,
    rights: () => rights.checked,
    update(s, d) {
      const tech = config.techniques[s.technique] || {};
      const shape = L((config.shapes[s.shape] || {}).label);
      const designTxt = [s.source.kind === 'text' ? t('ui.s5.textOnly') : s.source.fileName || '', ...s.texts.map((x) => `“${x.text}”`)].filter(Boolean).join(' · ');
      const techTxt = [L(tech.label), d.useThreads && d.reduced ? t('ui.s5.threads', { n: d.reduced.k }) : null].filter(Boolean).join(' · ');
      const sizeTxt = t('ui.s5.sizeLine', { shape, size: ctx.n(s.sizeCm), w: Math.round(d.layout.patchWmm), h: Math.round(d.layout.patchHmm), qty: t('ui.s5.qtyN', { n: s.qty }) });
      const gOrder = Object.keys(config.optionGroups || {});
      const ordered = s.options.slice().sort((a, b) => gOrder.indexOf((optionById(config, a) || {}).group) - gOrder.indexOf((optionById(config, b) || {}).group));
      const fin = [...ordered.map((id) => { const o = optionById(config, id); return o ? L(o.label) : null; }).filter(Boolean), t('ui.s5.fabric', { name: L(d.fabric.label).toLowerCase() })].join(' · ');
      keyed(rows, [designTxt, techTxt, sizeTxt, fin].join('|'), () => [
        row(t('ui.s5.row.design'), designTxt, 'design'), row(t('ui.s5.row.technique'), techTxt, 'advice'), row(t('ui.s5.row.size'), sizeTxt, 'size'), row(t('ui.s5.row.finish'), fin, 'edge')]);
      const qm = d.quoteMode || !d.quote.ok;
      text(total, qm ? t('pricing.onRequest') : t('ui.checkout.total', { total: ctx.fmt(d.quote.totalIncl), unit: ctx.fmt(d.quote.perPieceAllInIncl || d.quote.unitIncl) }));
      const days = Number(p.proofDays) || 0;
      const steps = qm ? [t('ui.s5.next.quote1'), t('ui.s5.next.quote2'), t('ui.s5.next.quote3')]
        : [t('ui.s5.next.1'), days ? t('ui.s5.next.2days', { n: days }) : t('ui.s5.next.2asap'), t('ui.s5.next.3')];
      keyed(next, steps.join('|'), () => steps.map((x) => h('li', x)));
      const ltTech = L(tech.leadTime);
      // The fallback is already a full sentence ("We bevestigen de levertijd ..."): no "Levertijd:" prefix in front of it.
      text(lead, ltTech ? t('ui.s5.leadTime', { text: ltTech }) : (L(p.leadTimeFallback) || ''));
      if (document.activeElement !== notes) notes.value = s.notes || '';
      rights.checked = !!ctx.ui.rights;
      text(orderBtn, qm ? t('ui.s5.quoteBtn') : t('ui.s5.order', { total: ctx.fmt(d.quote.totalIncl) }));
      text(orderSub, qm ? t('ui.s5.quoteSub') : t('ui.s5.orderSub'));
      orderBtn.disabled = !!ctx.busy;
    },
  };
}
