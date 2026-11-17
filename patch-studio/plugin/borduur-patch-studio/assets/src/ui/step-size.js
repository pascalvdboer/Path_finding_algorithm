/**
 * Patch Studio UI — step 3: size (chips + custom), size advice, garment view, quantity stepper, tier ladder.
 */
import { savingHint } from '../pricing/index.js';
import { h, text, keyed, icon, uid } from './dom.js';
import { normalizeQty } from './state.js';

export function stepSize(ctx) {
  const { t, config, store } = ctx;
  const L = (o) => ctx.L(o);
  const sz = config.sizes || {};
  const priced = sz.pricedCm || [4, 6, 8, 10, 12];
  const sizeBox = h('div.bps-chips');
  const otherId = uid('bps-size');
  const other = h('input.bps-input.bps-input--short', {
    id: otherId, type: 'number', inputmode: 'decimal', min: sz.minCm || 4, max: sz.maxQuoteCm || 30, step: sz.stepCm || 0.5,
    onchange: (e) => {
      const v = Number(String(e.target.value).replace(',', '.'));
      if (!Number.isFinite(v) || v <= 0) return;
      const step = sz.stepCm || 0.5;
      const c = Math.min(sz.maxQuoteCm || 30, Math.max(sz.minCm || 4, Math.round(v / step) * step));
      e.target.value = c;
      store.set({ sizeCm: c, sizePicked: true });
    },
  });
  const dims = h('p.bps-help');
  const hint = h('p.bps-help');
  const quoteNote = h('p.bps-notice.bps-notice--warn', { hidden: true });
  const sizeAdvice = h('div.bps-sizeadvice');
  const garmentBox = h('div.bps-chips');
  const garment = config.features && config.features.garmentView === false ? null
    : h('fieldset.bps-fieldset', h('legend.bps-legend', t('ui.s3.garment')), garmentBox);

  const qtyId = uid('bps-qty');
  const qtyErr = h('p.bps-field__error', { id: qtyId + '-err', hidden: true }, t('ui.s3.qtyInvalid'));
  const qty = h('input.bps-input.bps-qty__input', {
    id: qtyId, type: 'text', inputmode: 'numeric', pattern: '[0-9]*', autocomplete: 'off', 'aria-describedby': qtyId + '-err',
    onchange: (e) => setQty(e.target.value, true),
  });
  const stepBtn = (d, lbl) => h('button.bps-ibtn.bps-qty__btn', { type: 'button', 'aria-label': lbl, onclick: () => { const s = store.get(); const tt = config.techniques[s.technique] || {}; setQty(s.qty + d * (tt.qtyStep || 1)); } }, d < 0 ? '−' : '+');
  const presets = h('div.bps-chips.bps-presets');
  const adjusted = h('p.bps-notice.bps-notice--info', { hidden: true, role: 'status' });
  const ladder = h('div.bps-ladder');
  const saving = h('div.bps-saving');
  const shipHint = h('p.bps-help');
  const poolHint = h('p.bps-help', { hidden: true });

  function setQty(v, fromInput) {
    const n = Math.round(Number(String(v).replace(/[^\d]/g, '')));
    if (!n || n < 1) {
      if (fromInput) { qtyErr.hidden = false; qty.setAttribute('aria-invalid', 'true'); }
      return;
    }
    qtyErr.hidden = true;
    qty.removeAttribute('aria-invalid');
    const s = store.get();
    const q = normalizeQty(config, s.technique, n);
    ctx.ui.qtyAdjustedFrom = q !== n ? n : null;
    store.set({ qty: q });
    if (q === s.qty) qty.value = q;
  }

  const el = h('section.bps-step',
    h('h2.bps-h2', { tabindex: '-1' }, t('ui.s3.title')),
    h('fieldset.bps-fieldset', h('legend.bps-legend', t('ui.s3.size')),
      h('p.bps-help', t('ui.s3.sizeHelp')),
      sizeBox,
      h('div.bps-field.bps-field--inline', h('label', { for: otherId }, t('ui.s3.otherSizeLabel')), other),
      dims, hint, quoteNote),
    sizeAdvice,
    garment,
    h('fieldset.bps-fieldset', h('legend.bps-legend', { id: qtyId + '-lg' }, t('ui.s3.qty')),
      h('div.bps-qty', stepBtn(-1, t('ui.s3.qtyLess')), h('label.bps-sr', { for: qtyId }, t('ui.s3.qty')), qty, stepBtn(1, t('ui.s3.qtyMore'))),
      qtyErr, presets, adjusted,
      h('p.bps-help', t('ui.s3.fromOne')), poolHint),
    ladder, saving, shipHint,
    h('div.bps-step__nav',
      h('button.bps-btn.bps-btn--ghost', { type: 'button', onclick: () => ctx.go(2) }, t('ui.back')),
      h('button.bps-btn.bps-btn--primary', { type: 'button', onclick: () => ctx.go(4) }, t('ui.s3.next'), icon('arrow'))));

  return {
    el,
    update(s, d) {
      const tech = config.techniques[s.technique] || {};
      keyed(sizeBox, s.sizeCm + '|' + s.technique, () => priced.map((cm) => h('label.bps-chip',
        h('input', { type: 'radio', name: ctx.uidBase + '-size', value: cm, checked: s.sizeCm === cm, 'data-fk': 'size-' + cm, onchange: () => store.set({ sizeCm: cm, sizePicked: true }) }),
        h('span', t('ui.s3.sizeCm', { n: ctx.n(cm) })))));
      if (document.activeElement !== other) other.value = priced.includes(s.sizeCm) ? '' : s.sizeCm;
      const Lp = d.layout;
      text(dims, t('ui.s3.dims', { w: Math.round(Lp.patchWmm), h: Math.round(Lp.patchHmm) }));
      const hh = (sz.hints || []).find((x) => s.sizeCm <= x.upToCm);
      text(hint, hh ? t('ui.s3.hint', { hint: L(hh.label) }) : '');
      const big = s.sizeCm > (tech.maxOrderCm || sz.maxOrderCm || 12);
      quoteNote.hidden = !big;
      text(quoteNote, t('ui.s3.quoteSize'));

      const sa = d.advice.size || {};
      keyed(sizeAdvice, JSON.stringify([sa.recommendedCm, sa.reason && sa.reason.text, s.sizeCm]), () => sa.reason
        ? h('div.bps-notice.bps-notice--' + (sa.recommendedCm > s.sizeCm ? 'warn' : 'ok'), icon(sa.recommendedCm > s.sizeCm ? 'warn' : 'ok'),
          h('div.bps-notice__body', h('p', sa.reason.text),
            sa.recommendedCm && sa.recommendedCm !== s.sizeCm ? h('button.bps-btn.bps-btn--small.bps-btn--secondary', { type: 'button', onclick: () => store.set({ sizeCm: sa.recommendedCm, sizePicked: true }) }, t('ui.s3.adviceBtn', { n: ctx.n(sa.recommendedCm) })) : null))
        : []);

      if (garment) {
        const ids = ['none', ...Object.keys(config.mockups || {})];
        keyed(garmentBox, s.mockup, () => ids.map((id) => h('label.bps-chip',
          h('input', { type: 'radio', name: ctx.uidBase + '-garment', value: id, checked: s.mockup === id, 'data-fk': 'g-' + id, onchange: () => store.set({ mockup: id }) }),
          h('span', id === 'none' ? t('ui.s3.garmentNone') : L(config.mockups[id].label)))));
      }

      if (document.activeElement !== qty) qty.value = s.qty;
      const pr = (config.quantity && config.quantity.presets) || [1, 10, 25, 50, 100];
      keyed(presets, s.qty + '|' + s.technique, () => pr.filter((p) => p >= (tech.minQty || 1) && p % (tech.qtyStep || 1) === 0).map((p) => h('button.bps-chip.bps-chip--btn' + (p === s.qty ? '.is-on' : ''), { type: 'button', 'aria-pressed': String(p === s.qty), 'data-fk': 'pr-' + p, onclick: () => setQty(p) }, String(p))));
      const adj = ctx.ui.qtyAdjustedFrom != null && ((tech.minQty || 1) > 1 || (tech.qtyStep || 1) > 1);
      adjusted.hidden = !adj;
      if (adj) text(adjusted, t('ui.s3.qtyAdjusted', { tech: L(tech.label), min: tech.minQty || 1, step: tech.qtyStep || 1, qty: s.qty }));

      const q = d.quote;
      const lad = q && q.ok && tech.tiers && q.ladder && q.ladder.length > 1 ? q.ladder : null;
      keyed(ladder, JSON.stringify([lad && lad.map((r) => [r.min, r.unitIncl, r.current]), ctx.ui.exclVat]), () => lad
        ? [h('h3.bps-h3', t('ui.s3.ladder')), h('ol.bps-ladder__list', lad.map((r) => h('li.bps-ladder__row' + (r.current ? '.is-current' : ''), r.current ? { 'aria-current': 'true' } : null,
          h('span.bps-ladder__min', t('ui.s3.ladderRow', { min: r.min })),
          h('span.bps-ladder__unit', ctx.money(r.unitIncl, r.unitIncl / (1 + config.vat.rate))),
          r.discount ? h('span.bps-ladder__pct', t('ui.s3.ladderDiscount', { pct: Math.round(r.discount * 100) })) : h('span.bps-ladder__pct'),
          r.current ? h('span.bps-ladder__you', t('ui.s3.ladderCurrent')) : null)))]
        : []);
      const sh = q && q.ok && tech.tiers ? savingHint(q, ctx.locale, ctx.overrides) : null;
      keyed(saving, sh ? sh.main.text + (sh.secondary ? sh.secondary.text : '') : '', () => sh
        ? h('div.bps-notice.bps-notice--info', icon('info'), h('div.bps-notice__body',
          h('p', sh.main.text, ' ', h('button.bps-btn.bps-btn--small.bps-btn--link', { type: 'button', onclick: () => setQty(sh.nextTier.qty) }, String(sh.nextTier.qty) + ' ' + t('ui.s3.qty').toLowerCase())),
          sh.extra ? h('p', sh.extra.text) : null,
          sh.secondary ? h('p.bps-muted', sh.secondary.text) : null))
        : []);
      const ship = q && q.shipping;
      const articles = q ? q.lineIncl + (s.otherItemsInclVat || 0) : 0;
      const showShip = ship && !ship.free && ship.remainingForFreeInclVat != null && articles >= ((config.shipping && config.shipping.hintFromInclVat) || Infinity);
      text(shipHint, showShip ? t('pricing.shippingRemaining', { amount: ctx.fmt(ship.remainingForFreeInclVat) }) : '');
      poolHint.hidden = !(s.poolQty > 0 && tech.tiers);
      if (s.poolQty > 0) text(poolHint, t('ui.s3.poolHint', { n: s.poolQty }));
    },
  };
}
