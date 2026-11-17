/**
 * Patch Studio UI — price card (desktop sticky column / mobile bottom sheet) and the sticky mobile order bar.
 */
import { errorMessage } from '../pricing/index.js';
import { h, text, keyed, icon, uid } from './dom.js';

function lineLabel(l, ctx) {
  return typeof l.label === 'string' ? l.label : (l.label && l.label.text) || '';
}

export function createPriceCard(ctx) {
  const { t, config } = ctx;
  const toggleId = uid('bps-excl');
  const showToggle = config.vat && config.vat.showExclToggle && !(config.features && config.features.exclVatToggle === false);
  const body = h('div.bps-price__body');
  const toggle = showToggle ? h('div.bps-check.bps-check--small',
    h('input', { type: 'checkbox', id: toggleId, onchange: (e) => { ctx.ui.exclVat = e.target.checked; ctx.refresh(); } }),
    h('label', { for: toggleId }, t('ui.price.toggle'))) : null;
  const el = h('section.bps-price', { 'aria-label': t('ui.price.title') }, h('h2.bps-price__title', t('ui.price.title')), body, toggle);

  function build(s, d) {
    if (!ctx.hasDesign()) return h('p.bps-muted', t('ui.price.empty'));
    const q = d.quote;
    const fmt = ctx.fmt;
    const what = h('p.bps-price__what', [ctx.L(config.techniques[s.technique].label), t('ui.s3.sizeCm', { n: ctx.n(s.sizeCm) }), t('ui.s5.qtyN', { n: s.qty })].join(' · '));
    if (d.quoteMode || q.mode === 'quote' || !(q.totalIncl > 0)) {
      const err = (q.errors || [])[0];
      return [
        what,
        h('p.bps-price__total', t('pricing.onRequest')),
        err ? h('p.bps-help', errorMessage(err, config, ctx.locale, ctx.overrides).text) : null,
        h('button.bps-btn.bps-btn--secondary.bps-btn--block', { type: 'button', onclick: () => ctx.openQuote() }, t('ui.s5.quoteBtn')),
        h('p.bps-help', t('ui.quote.free')),
      ];
    }
    const excl = ctx.ui.exclVat;
    const soft = (q.errors || []).map((e) => errorMessage(e, config, ctx.locale, ctx.overrides).text);
    const infoBtn = (l) => {
      if (!l.help) return null;
      const id = uid('bps-help');
      const btn = h('button.bps-ibtn.bps-ibtn--tiny', { type: 'button', 'aria-expanded': 'false', 'aria-controls': id, 'aria-label': t('ui.price.info', { label: lineLabel(l, ctx) }) }, icon('info'));
      const tip = h('p.bps-price__tip', { id, hidden: true }, ctx.L(l.help));
      btn.addEventListener('click', () => { tip.hidden = !tip.hidden; btn.setAttribute('aria-expanded', String(!tip.hidden)); });
      btn.addEventListener('keydown', (e) => { if (e.key === 'Escape') { tip.hidden = true; btn.setAttribute('aria-expanded', 'false'); } });
      return [btn, tip];
    };
    const amt = (l) => fmt(excl ? l.excl : l.incl);
    const lines = [
      h('li', h('span', t('ui.price.patches'), ' ', h('span.bps-muted', `${q.qty} × ${fmt(excl ? q.unitExcl : q.unitIncl)}`)), h('span', fmt(excl ? q.lineExcl : q.lineIncl))),
      ...q.extras.map((l) => h('li', h('span', lineLabel(l, ctx)), h('span', amt(l)))),
      ...q.fees.map((l) => h('li.bps-price__fee', h('span', lineLabel(l, ctx), lineLabel(l, ctx).toLowerCase().includes(t('pricing.oneTime').toLowerCase()) ? null : [' ', h('span.bps-muted', t('pricing.oneTime'))], infoBtn(l)), h('span', amt(l)))),
      h('li', h('span', t('ui.price.shipping')), h('span', q.shipping.free ? t('ui.price.free') : fmt(excl ? q.shipping.excl : q.shipping.incl))),
    ];
    return [
      what,
      h('p.bps-price__label', t('ui.price.total')),
      h('p.bps-price__total', fmt(q.totalIncl)),
      h('p.bps-price__sub', t(excl ? 'ui.price.subExcl' : 'ui.price.sub', { amount: fmt(excl ? q.unitExcl : q.unitIncl) })),
      h('p.bps-price__allin', t('ui.price.allIn', { amount: fmt(excl ? q.perPieceAllInExcl || q.perPieceAllInIncl / (1 + config.vat.rate) : q.perPieceAllInIncl) })),
      excl ? h('p.bps-price__excl', t('ui.price.excl', { amount: fmt(q.totalExcl) })) : null,
      q.tier && q.tier.discount ? h('p.bps-price__disc', icon('ok', 'bps-ico--ok'), t('ui.price.discount', { pct: Math.round(q.tier.discount * 100) })) : null,
      h('ul.bps-price__lines', lines),
      soft.map((m) => h('p.bps-notice.bps-notice--warn', m)),
      h('details.bps-why.bps-price__bd', h('summary', t('pricing.breakdownTitle')),
        h('table.bps-bd', h('tbody', q.breakdown.map((l) => h('tr', h('th', { scope: 'row' }, lineLabel(l, ctx)), h('td', fmt(l.excl), ' ', h('span.bps-muted', t('pricing.exclVat'))), h('td', fmt(l.incl))))))),
      q.pooled ? h('p.bps-help', t('pricing.poolNote')) : null,
      h('p.bps-help', t('ui.price.invoice')),
    ];
  }

  return {
    el,
    build,
    update(s, d) {
      const q = d.quote;
      keyed(body, JSON.stringify([ctx.hasDesign(), s.technique, s.sizeCm, q.totalIncl, q.totalExcl, q.qty, q.errors, d.quoteMode, ctx.ui.exclVat, q.breakdown.length, ctx.locale]), () => build(s, d));
      if (toggle) toggle.querySelector('input').checked = !!ctx.ui.exclVat;
      if (toggle) toggle.hidden = !ctx.hasDesign();
    },
  };
}

/** Sticky bar (mobile) + bottom sheet with the full price card. */
export function createStickyBar(ctx) {
  const { t } = ctx;
  const amount = h('span.bps-bar__amount');
  const meta = h('span.bps-bar__meta');
  const sheetBody = h('div.bps-sheet__body');
  const sheet = h('dialog.bps-sheet', { 'aria-label': t('ui.price.details') },
    h('div.bps-sheet__head', h('h2.bps-h3', t('ui.price.details')),
      h('button.bps-btn.bps-btn--link', { type: 'button', onclick: () => sheet.close() }, t('ui.price.close'))),
    sheetBody);
  sheet.addEventListener('click', (e) => { if (e.target === sheet) sheet.close(); });
  const info = h('button.bps-bar__info', { type: 'button', 'aria-label': t('ui.bar.details'), onclick: () => ctx.openSheet() }, amount, meta);
  const btn = h('button.bps-btn.bps-btn--primary.bps-bar__btn', { type: 'button', onclick: () => ctx.barAction() });
  const el = h('div.bps-bar', { hidden: true }, info, btn);
  return {
    el,
    sheet,
    update(s, d, card) {
      const show = ctx.hasDesign() && ctx.step >= 1;
      el.hidden = !show;
      ctx.root.classList.toggle('has-bar', show);
      if (!show) return;
      const q = d.quote;
      const qm = d.quoteMode || q.mode === 'quote' || !(q.totalIncl > 0);
      text(amount, qm ? t('pricing.onRequest') : ctx.fmt(ctx.ui.exclVat ? q.totalExcl : q.totalIncl));
      text(meta, t(ctx.ui.exclVat ? 'ui.bar.qtyExcl' : 'ui.bar.qty', { qty: s.qty }));
      text(btn, ctx.step < 5 ? t('ui.bar.next') : qm ? t('ui.bar.quote') : t('ui.bar.order'));
      if (sheet.open) keyed(sheetBody, JSON.stringify([q.totalIncl, q.qty, ctx.ui.exclVat, qm]), () => card.build(s, d));
      else sheetBody._bpsKey = null;
    },
    refreshSheet(s, d, card) { sheetBody._bpsKey = null; keyed(sheetBody, 'x', () => card.build(s, d)); },
  };
}
