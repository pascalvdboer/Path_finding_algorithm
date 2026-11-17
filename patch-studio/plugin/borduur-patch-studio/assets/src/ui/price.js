/**
 * Patch Studio UI — price: the full price card (Maat-panel + details sheet), the slim action bar under the canvas
 * (size, quantity with tier hint, total, quote, order) and the quantity control shared by bar and panel.
 */
import { errorMessage } from '../pricing/index.js';
import { h, text, keyed, icon, uid } from './dom.js';
import { normalizeQty } from './state.js';

function lineLabel(l) {
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
  const el = h('section.bps-price', { 'aria-label': t('ui.price.title') }, h('h3.bps-price__title', t('ui.price.title')), body, toggle);

  function build(s, d) {
    if (!ctx.hasDesign()) return h('p.bps-muted', t('ui.price.empty'));
    const q = d.quote;
    const fmt = ctx.fmt;
    const what = h('p.bps-price__what', [ctx.L(config.techniques[s.technique].label), t('ui.s3.sizeCm', { n: ctx.n(s.sizeCm) }), t('ui.s5.qtyN', { n: s.qty })].join(' · '));
    if (d.quoteMode || q.mode === 'quote' || !(q.totalIncl > 0)) {
      const err = (q.errors || [])[0];
      return [what, h('p.bps-price__total', t('pricing.onRequest')),
        err ? h('p.bps-help', errorMessage(err, config, ctx.locale, ctx.overrides).text) : null,
        h('button.bps-btn.bps-btn--secondary.bps-btn--block', { type: 'button', onclick: () => ctx.openQuote() }, t('ui.s5.quoteBtn')),
        h('p.bps-help', t('ui.quote.free'))];
    }
    const excl = ctx.ui.exclVat;
    const soft = (q.errors || []).map((e) => errorMessage(e, config, ctx.locale, ctx.overrides).text);
    const infoBtn = (l) => {
      if (!l.help) return null;
      const id = uid('bps-help');
      const btn = h('button.bps-ibtn.bps-ibtn--tiny', { type: 'button', 'aria-expanded': 'false', 'aria-controls': id, 'aria-label': t('ui.price.info', { label: lineLabel(l) }) }, icon('info'));
      const tip = h('p.bps-price__tip', { id, hidden: true }, ctx.L(l.help));
      btn.addEventListener('click', () => { tip.hidden = !tip.hidden; btn.setAttribute('aria-expanded', String(!tip.hidden)); });
      btn.addEventListener('keydown', (e) => { if (e.key === 'Escape') { tip.hidden = true; btn.setAttribute('aria-expanded', 'false'); } });
      return [btn, tip];
    };
    const amt = (l) => fmt(excl ? l.excl : l.incl);
    const lines = [
      h('li', h('span', t('ui.price.patches'), ' ', h('span.bps-muted', `${q.qty} × ${fmt(excl ? q.unitExcl : q.unitIncl)}`)), h('span', fmt(excl ? q.lineExcl : q.lineIncl))),
      ...q.extras.map((l) => h('li', h('span', lineLabel(l)), h('span', amt(l)))),
      ...q.fees.map((l) => h('li.bps-price__fee', h('span', lineLabel(l), lineLabel(l).toLowerCase().includes(t('pricing.oneTime').toLowerCase()) ? null : [' ', h('span.bps-muted', t('pricing.oneTime'))], infoBtn(l)), h('span', amt(l)))),
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
        h('table.bps-bd', h('tbody', q.breakdown.map((l) => h('tr', h('th', { scope: 'row' }, lineLabel(l)), h('td', fmt(l.excl), ' ', h('span.bps-muted', t('pricing.exclVat'))), h('td', fmt(l.incl))))))),
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
      if (toggle) { toggle.querySelector('input').checked = !!ctx.ui.exclVat; toggle.hidden = !ctx.hasDesign(); }
    },
  };
}

/** Quantity stepper (+ presets in the panel). */
export function createQty(ctx, where) {
  const { t, config, store } = ctx;
  const qtyId = uid('bps-qty');
  const err = h('p.bps-field__error', { id: qtyId + '-err', hidden: true }, t('ui.s3.qtyInvalid'));
  const input = h('input.bps-input.bps-qty__input', {
    id: qtyId, type: 'text', inputmode: 'numeric', pattern: '[0-9]*', autocomplete: 'off', 'aria-describedby': qtyId + '-err',
    onchange: (e) => set(e.target.value, true),
    onkeydown: (e) => { if (e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); step(e.key === 'ArrowUp' ? 1 : -1); } },
  });
  function set(v, fromInput) {
    const n = Math.round(Number(String(v).replace(/[^\d]/g, '')));
    if (!n || n < 1) {
      if (fromInput) { err.hidden = false; input.setAttribute('aria-invalid', 'true'); }
      return;
    }
    err.hidden = true;
    input.removeAttribute('aria-invalid');
    const s = store.get();
    const q = normalizeQty(config, s.technique, n);
    ctx.ui.qtyAdjustedFrom = q !== n ? n : null;
    store.set({ qty: q });
    if (q === s.qty) input.value = q;
  }
  function step(dir) { const s = store.get(); const tt = config.techniques[s.technique] || {}; set(s.qty + dir * (tt.qtyStep || 1)); }
  const btn = (dir, lbl) => h('button.bps-ibtn.bps-qty__btn', { type: 'button', 'aria-label': lbl, onclick: () => step(dir) }, icon(dir < 0 ? 'minus' : 'plus'));
  const presets = where === 'panel' ? h('div.bps-chips.bps-presets') : null;
  const el = h('div.bps-qtywrap', h('div.bps-qty', btn(-1, t('ui.s3.qtyLess')), h('label.bps-sr', { for: qtyId }, t('ui.s3.qty')), input, btn(1, t('ui.s3.qtyMore'))), err, presets);
  if (!ctx.setQty) ctx.setQty = (v) => set(v);
  return {
    el,
    update(s) {
      if (document.activeElement !== input) input.value = s.qty;
      if (!presets) return;
      const tech = config.techniques[s.technique] || {};
      const pr = (config.quantity && config.quantity.presets) || [1, 10, 25, 50, 100];
      keyed(presets, s.qty + '|' + s.technique, () => pr.filter((p) => p >= (tech.minQty || 1) && p % (tech.qtyStep || 1) === 0)
        .map((p) => h('button.bps-chip.bps-chip--btn' + (p === s.qty ? '.is-on' : ''), { type: 'button', 'aria-pressed': String(p === s.qty), 'data-fk': 'pr-' + p, onclick: () => set(p) }, String(p))));
    },
  };
}

/** Slim action bar (desktop: under the canvas; phone: above the tab bar) + price details sheet. */
export function createBar(ctx, card) {
  const { t, config, store } = ctx;
  const sz = config.sizes || {};
  const priced = sz.pricedCm || [4, 6, 8, 10, 12];
  const sizeId = uid('bps-barsize');
  const sizeSel = h('select.bps-select.bps-bar__size', { id: sizeId, onchange: (e) => { const v = Number(e.target.value); if (v) store.set({ sizeCm: v, sizePicked: true }); else ctx.openPanel('size', { focus: true }); } });
  const qty = createQty(ctx, 'bar');
  const tier = h('button.bps-bar__tier', { type: 'button', hidden: true });
  const amount = h('span.bps-bar__amount');
  const meta = h('span.bps-bar__meta');
  const sheetBody = h('div.bps-sheet__body');
  const sheet = h('dialog.bps-sheet', { 'aria-label': t('ui.price.details') },
    h('div.bps-sheet__head', h('h2.bps-h3', t('ui.price.details')), h('button.bps-btn.bps-btn--link', { type: 'button', onclick: () => sheet.close() }, t('ui.price.close'))),
    sheetBody);
  sheet.addEventListener('click', (e) => { if (e.target === sheet) sheet.close(); });
  const info = h('button.bps-bar__info', { type: 'button', 'aria-label': t('ui.bar.details'), onclick: () => ctx.openSheet() }, amount, meta);
  const quote = config.features && config.features.quote === false ? null
    : h('button.bps-btn.bps-btn--ghost.bps-bar__quote', { type: 'button', onclick: () => ctx.openQuote() }, t('ui.bar.quoteProof'));
  const btn = h('button.bps-btn.bps-btn--primary.bps-bar__btn', { type: 'button', onclick: () => ctx.barAction() });
  const el = h('div.bps-bar', { role: 'region', 'aria-label': t('ui.bar.label'), hidden: true },
    h('div.bps-bar__field.bps-bar__f-size', h('label.bps-bar__lbl', { for: sizeId }, t('ui.s3.size')), sizeSel),
    h('div.bps-bar__field.bps-bar__f-qty', h('span.bps-bar__lbl', { 'aria-hidden': 'true' }, t('ui.s3.qty')), qty.el, tier),
    h('div.bps-bar__spacer'),
    info, quote, h('span.bps-bar__cart', btn));
  return {
    el,
    sheet,
    update(s, d) {
      const show = ctx.hasDesign();
      el.hidden = !show;
      ctx.root.classList.toggle('has-bar', show);
      if (!show) return;
      const q = d.quote;
      const qm = d.quoteMode || q.mode === 'quote' || !(q.totalIncl > 0);
      keyed(sizeSel, s.sizeCm + '|' + ctx.locale, () => [
        ...(priced.includes(s.sizeCm) ? [] : [s.sizeCm]).concat(priced).sort((a, b) => a - b).map((cm) => h('option', { value: cm, selected: cm === s.sizeCm }, t('ui.s3.sizeCm', { n: ctx.n(cm) }))),
        h('option', { value: '' }, t('ui.s3.otherSize') + '…')]);
      sizeSel.value = String(s.sizeCm);
      qty.update(s, d);
      const lad = q && q.ok && q.ladder ? q.ladder : null;
      const nx = lad && lad.find((r) => r.min > s.qty);
      tier.hidden = !nx || qm;
      if (nx) {
        text(tier, t('ui.bar.tier', { min: nx.min, unit: ctx.money(nx.unitIncl, nx.unitIncl / (1 + config.vat.rate)) }));
        tier.onclick = () => ctx.setQty(nx.min);
      }
      text(amount, qm ? t('pricing.onRequest') : ctx.fmt(ctx.ui.exclVat ? q.totalExcl : q.totalIncl));
      text(meta, qm ? t('ui.bar.qtyOnly', { qty: s.qty }) : t(ctx.ui.exclVat ? 'ui.bar.metaExcl' : 'ui.bar.meta', { qty: s.qty, unit: ctx.fmt(ctx.ui.exclVat ? q.unitExcl : q.unitIncl) }));
      text(btn, qm ? t('ui.bar.quote') : t('ui.bar.order'));
      btn.disabled = !!ctx.busy;
      if (sheet.open) keyed(sheetBody, JSON.stringify([q.totalIncl, q.qty, ctx.ui.exclVat, qm]), () => card.build(s, d));
      else sheetBody._bpsKey = null;
    },
    refreshSheet(s, d) { sheetBody._bpsKey = null; keyed(sheetBody, 'x', () => card.build(s, d)); },
  };
}
