/**
 * Cross-sell — paneel (SPEC-EXTRA §2.4). Rendert de kaarten in een container (dialog of inline).
 * Eén klik waar het kan (pet, muts, sleutelhanger, PVC), twee waar het moet (hoodie: maten; kader: plaatje).
 * Niets vooraf aangevinkt, "Nee, bedankt" even groot als "Toevoegen", geen aftelklok of schaarste.
 */
import { t } from './messages.js';
import { cardPrice } from './offers.js';
import { drawMockup } from './mockups.js';
import { pickDefaultVariation } from './fit.js';
import { formatMoney } from '../shared/money.js';

let uid = 0;
const el = (tag, attrs = {}, ...kids) => {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') n.className = v;
    else if (k === 'text') n.textContent = v;
    else if (k.startsWith('on') && typeof v === 'function') n.addEventListener(k.slice(2), v);
    else n.setAttribute(k, v === true ? '' : String(v));
  }
  for (const c of kids.flat()) if (c !== null && c !== undefined && c !== false) n.append(c);
  return n;
};

/** Unieke kleuren (op slug) van de variaties van één product, met voorraad eerst. */
function colorsOf(card, productId) {
  const seen = new Map();
  for (const v of card.variations) {
    if (v.productId !== productId) continue;
    const prev = seen.get(v.colorSlug);
    if (!prev || (!prev.inStock && v.inStock)) seen.set(v.colorSlug, v);
  }
  return [...seen.values()];
}
function sizesOf(card, productId, colorSlug) {
  return card.variations.filter((v) => v.productId === productId && v.colorSlug === colorSlug && v.size);
}

/**
 * @param {HTMLElement} container
 * @param {Object} offers  antwoord van GET /crosssell/offers (of buildOffers offline)
 * @param {Object} env     { locale, overrides, patchImg, inDialog, cartUrl, onAdd(card, body), onUndo(key), onDecline(), onToCart(), onShown(ids) }
 */
export function renderPanel(container, offers, env) {
  const locale = env.locale || 'nl-BE';
  const ov = env.overrides || {};
  const tr = (k, p) => t(k, locale, p, ov);
  const money = (x) => formatMoney(x, locale);
  const titleId = 'bps-cs-title-' + ++uid;
  let addedAny = false;

  const live = el('p', { class: 'bps-cs__live', role: 'status', 'aria-live': 'polite' });
  const toCart = el('a', { class: 'bps-cs__btn bps-cs__btn--ghost bps-cs__tocart', href: env.cartUrl || '#', hidden: true, text: tr('cs.toCart') });
  toCart.addEventListener('click', (e) => {
    if (env.onToCart) {
      e.preventDefault();
      env.onToCart();
    }
  });
  const decline = el('button', { type: 'button', class: 'bps-cs__btn bps-cs__btn--secondary bps-cs__decline', text: tr('cs.decline') });
  decline.addEventListener('click', () => env.onDecline && env.onDecline());

  const head = el('header', { class: 'bps-cs__head' },
    el('p', { class: 'bps-cs__added' }, el('span', { class: 'bps-cs__tick', 'aria-hidden': 'true', text: '✓' }), ' ', offers.added || tr('cs.added')),
    el('h2', { class: 'bps-cs__title', id: titleId, text: offers.heading || tr('cs.heading') }),
    offers.feeNote ? el('p', { class: 'bps-cs__fee', text: offers.feeNote }) : null,
  );
  if (env.inDialog) {
    head.append(el('button', { type: 'button', class: 'bps-cs__x', 'aria-label': tr('cs.close'), onclick: () => env.onDecline && env.onDecline() }, el('span', { 'aria-hidden': 'true', text: '×' })));
  }
  const list = el('div', { class: 'bps-cs__cards' });
  const foot = el('footer', { class: 'bps-cs__foot' }, decline, toCart);
  const panel = el('section', { class: 'bps-cs__panel', 'aria-labelledby': titleId }, head, list, foot, live);
  container.replaceChildren(panel);

  const raf = typeof requestAnimationFrame === 'function' ? requestAnimationFrame : (fn) => setTimeout(fn, 0);
  const controllers = (offers.cards || []).map((card) => cardView(card));
  for (const c of controllers) list.append(c.node);
  // Mockups pas tekenen nu het paneel zichtbaar is.
  raf(() => controllers.forEach((c) => c.draw()));
  if (env.onShown) env.onShown((offers.cards || []).map((c) => c.id));

  function markAdded() {
    addedAny = true;
    toCart.hidden = false;
  }

  function cardView(card) {
    const p = card.id;
    const sel = {
      productId: card.defaultProductId,
      variationId: card.defaultVariationId,
      qty: card.defaultQty,
      sizes: {},
      options: [],
      placement: card.placement,
      plate: ['', ''],
      names: '',
    };
    const defVar = card.variations.find((v) => v.id === sel.variationId);
    sel.colorSlug = defVar ? defVar.colorSlug : null;
    sel.size = defVar && defVar.size && !card.needsSize ? defVar.size : null;

    const canvas = el('canvas', { class: 'bps-cs__canvas', role: 'img', width: 480, height: 360 });
    const fig = el('figure', { class: 'bps-cs__fig' }, canvas, el('figcaption', { class: 'bps-cs__caption', text: tr('cs.mockup') }));
    const priceStrong = el('strong', { class: 'bps-cs__amount' });
    const priceLine = el('p', { class: 'bps-cs__price' }, priceStrong, ' ', el('span', { class: 'bps-cs__per' }), el('span', { class: 'bps-cs__incl', text: card.kind === 'laser' || card.kind === 'pvc' ? tr('cs.inclVat') : tr('cs.inclAll') }));
    const breakdown = el('p', { class: 'bps-cs__breakdown' });
    const total = el('p', { class: 'bps-cs__total', hidden: true });
    const err = el('p', { class: 'bps-cs__err', role: 'alert', hidden: true });
    const editId = 'bps-cs-edit-' + ++uid;
    const edit = el('div', { class: 'bps-cs__edit', id: editId, hidden: true });
    const needsSecond = card.needsSize || card.needsPlate;
    const addBtn = el('button', { type: 'button', class: 'bps-cs__btn bps-cs__btn--primary bps-cs__add', text: tr(card.needsSize ? 'cs.chooseSizes' : card.needsPlate ? 'cs.writePlate' : 'cs.add') });
    const changeBtn = el('button', { type: 'button', class: 'bps-cs__link bps-cs__change', 'aria-expanded': 'false', 'aria-controls': editId, text: tr('cs.change') });
    const actions = el('div', { class: 'bps-cs__actions' }, addBtn, needsSecond ? null : changeBtn);
    const undoBtn = el('button', { type: 'button', class: 'bps-cs__link bps-cs__undo', text: tr('cs.undo') });
    const done = el('p', { class: 'bps-cs__done', hidden: true }, el('span', { text: tr('cs.added.card') }), ' · ', undoBtn);
    const node = el('article', { class: 'bps-cs__card', 'data-item': p, 'data-fit': card.fit },
      fig,
      el('div', { class: 'bps-cs__body' },
        el('h3', { class: 'bps-cs__name', text: card.title }),
        el('p', { class: 'bps-cs__why', text: card.why }),
        card.adjustNote ? el('p', { class: 'bps-cs__adjust', text: card.adjustNote }) : null,
        card.note ? el('p', { class: 'bps-cs__note', text: card.note }) : null,
        priceLine, breakdown, edit, total, err, actions, done),
    );
    let keys = [];
    let editorBuilt = false;

    const currentVariation = () => {
      if (card.needsSize) return card.variations.find((v) => v.productId === sel.productId && v.colorSlug === sel.colorSlug) || null;
      return card.variations.find((v) => v.productId === sel.productId && v.colorSlug === sel.colorSlug && (!sel.size || v.size === sel.size))
        || card.variations.find((v) => v.id === sel.variationId) || null;
    };
    const namesList = () => sel.names.split(/\r?\n/).map((s) => s.trim()).filter(Boolean).slice(0, 250);
    const effectiveQty = () => {
      if (card.needsSize) return Object.values(sel.sizes).reduce((a, b) => a + (b | 0), 0);
      if (sel.options.includes('name_back')) return Math.max(1, namesList().length);
      return sel.qty;
    };

    function updatePrice() {
      const v = currentVariation();
      const qty = Math.max(1, effectiveQty());
      const q = cardPrice(card, { variationId: v ? v.id : null, productId: sel.productId, qty, options: sel.options, placement: sel.placement }, offers.pricing, locale);
      const unit = q.ok ? q.unitIncl : card.unitIncl;
      priceStrong.textContent = money(unit);
      priceLine.querySelector('.bps-cs__per').textContent = tr('cs.per') + ' ';
      breakdown.textContent = (q.ok ? q.parts : card.breakdown).map((x) => `${x.label} ${money(x.incl)}`).join(' · ');
      let lineTotal = 0;
      if (card.needsSize) {
        for (const [size, n] of Object.entries(sel.sizes)) {
          if (!(n > 0)) continue;
          const sv = card.variations.find((x) => x.productId === sel.productId && x.colorSlug === sel.colorSlug && x.size === size);
          const sq = cardPrice(card, { variationId: sv ? sv.id : null, productId: sel.productId, qty, options: sel.options, placement: sel.placement }, offers.pricing, locale);
          lineTotal += sq.ok ? sq.unitIncl * n : 0;
        }
      } else {
        lineTotal = (q.ok ? q.unitIncl : 0) * effectiveQty();
      }
      const fee = q.ok && q.fees.length ? q.fees.reduce((a, f) => a + f.incl, 0) : 0;
      const showTotal = effectiveQty() > 1 || fee > 0;
      total.hidden = !showTotal;
      if (showTotal) total.textContent = tr('cs.total', { price: money(lineTotal + fee) }) + (fee > 0 ? ' (' + q.fees.map((f) => f.label).join(', ') + ')' : '');
      addBtn.disabled = card.needsSize && editorBuilt && !edit.hidden && effectiveQty() < 1;
    }

    function mockupKind() {
      if (card.id === 'hoodie') return sel.placement === 'back' ? 'hoodie_back' : 'hoodie_chest';
      return (card.mockup.byProduct && card.mockup.byProduct[String(sel.productId)]) || card.mockup.kind;
    }
    function draw() {
      const dpr = Math.min(2, (typeof window !== 'undefined' && window.devicePixelRatio) || 1);
      const cssW = Math.max(200, Math.round(canvas.clientWidth || 320));
      const cssH = Math.round(cssW * 0.75);
      if (canvas.width !== cssW * dpr) {
        canvas.width = cssW * dpr;
        canvas.height = cssH * dpr;
      }
      const ctx = canvas.getContext('2d');
      if (!ctx) return 0;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      const v = currentVariation();
      const colorHex = (v && v.colorHex) || (card.kind === 'pvc' ? '#222222' : offers.neutralHex || '#8a8d91');
      const useAlt = card.alt && sel.placement === card.alt.placement;
      const kind = mockupKind();
      const ms = drawMockup(ctx, kind, {
        patchImg: env.patchImg || null,
        colorHex,
        placement: sel.placement,
        mono: card.mockup.mono,
        engraveHex: card.mockup.engraveHex,
        patchSizeCm: useAlt ? card.alt.patchSizeCm : card.patchSizeCm || 6,
        scaleRefMm: card.mockup.scaleRefMm,
        plateLines: sel.plate,
        plateHex: card.mockup.plateHex,
        plateTextHex: card.mockup.plateTextHex,
        fallbackText: tr('cs.mockupFallback'),
      }, cssW, cssH);
      const colorName = v && v.colorName ? v.colorName.toLowerCase() : tr('cs.colorUnknown');
      canvas.setAttribute('aria-label', tr('cs.alt.' + kind, { color: colorName }));
      node.dataset.renderMs = String(Math.round(ms * 10) / 10);
      return ms;
    }

    function buildEditor() {
      editorBuilt = true;
      edit.replaceChildren();
      const group = (legend, ...kids) => el('fieldset', { class: 'bps-cs__group' }, el('legend', { text: legend }), ...kids);
      if (card.products.length > 1 && card.kind !== 'frame') {
        const name = 'bps-cs-m-' + ++uid;
        edit.append(group(tr('cs.model'), el('div', { class: 'bps-cs__chips' }, card.products.map((pr) => {
          const id = name + '-' + pr.id;
          const inp = el('input', { type: 'radio', name, id, value: pr.id, checked: pr.id === sel.productId });
          inp.addEventListener('change', () => {
            sel.productId = pr.id;
            const vars = card.variations.filter((v) => v.productId === pr.id);
            const keep = vars.find((v) => v.colorSlug === sel.colorSlug && v.inStock);
            const vid = keep ? keep.id : pickDefaultVariation(vars, offers.edgeHex || null, offers.defaultColors || []);
            const nv = vars.find((v) => v.id === vid);
            sel.variationId = vid;
            sel.colorSlug = nv ? nv.colorSlug : null;
            sel.size = nv && nv.size && !card.needsSize ? nv.size : null;
            buildEditor();
            refresh();
          });
          return el('label', { class: 'bps-cs__chip', for: id }, inp, el('span', { text: pr.name }));
        }))));
      }
      const colors = colorsOf(card, sel.productId);
      if (colors.length > 1 || (colors.length === 1 && colors[0].colorSlug)) {
        const name = 'bps-cs-c-' + ++uid;
        edit.append(group(tr('cs.color'), el('div', { class: 'bps-cs__swatches' }, colors.map((v) => {
          const id = name + '-' + v.colorSlug;
          const inp = el('input', { type: 'radio', name, id, value: v.colorSlug, checked: v.colorSlug === sel.colorSlug, disabled: !v.inStock });
          inp.addEventListener('change', () => {
            sel.colorSlug = v.colorSlug;
            const sv = sizesOf(card, sel.productId, v.colorSlug);
            if (!card.needsSize && sv.length) {
              const hit = sv.find((x) => x.size === sel.size && x.inStock) || sv.find((x) => x.inStock) || sv[0];
              sel.size = hit.size;
              sel.variationId = hit.id;
            } else {
              sel.variationId = v.id;
            }
            if (card.needsSize) buildEditor();
            refresh();
          });
          return el('label', { class: 'bps-cs__swatch', for: id, title: v.colorName },
            inp, el('span', { class: 'bps-cs__dot', style: `background:${v.colorHex || offers.neutralHex || '#8a8d91'}`, 'aria-hidden': 'true' }),
            el('span', { class: v.colorHex ? 'bps-cs__sr' : 'bps-cs__swname', text: v.colorName }));
        }))));
      }
      const sizes = sizesOf(card, sel.productId, sel.colorSlug);
      if (card.needsSize) {
        edit.append(group(tr('cs.sizes'), el('p', { class: 'bps-cs__help', text: tr('cs.sizesHelp') }), el('div', { class: 'bps-cs__sizes' }, sizes.map((v) => {
          const id = 'bps-cs-s-' + ++uid;
          const inp = el('input', { type: 'number', id, min: 0, max: 999, step: 1, inputmode: 'numeric', value: sel.sizes[v.size] || '', disabled: !v.inStock, placeholder: '0' });
          inp.addEventListener('input', () => {
            const n = Math.max(0, Math.min(999, parseInt(inp.value, 10) || 0));
            sel.sizes[v.size] = n;
            updatePrice();
          });
          return el('label', { class: 'bps-cs__size', for: id }, el('span', { text: v.size }), inp);
        }))));
      } else {
        if (sizes.length > 1) {
          const name = 'bps-cs-z-' + ++uid;
          edit.append(group(tr('cs.size'), el('div', { class: 'bps-cs__chips' }, sizes.map((v) => {
            const id = name + '-' + v.id;
            const inp = el('input', { type: 'radio', name, id, value: v.size, checked: v.size === sel.size, disabled: !v.inStock });
            inp.addEventListener('change', () => {
              sel.size = v.size;
              sel.variationId = v.id;
              refresh();
            });
            return el('label', { class: 'bps-cs__chip', for: id }, inp, el('span', { text: v.size }));
          }))));
        }
        edit.append(stepper());
      }
      if (card.alt) {
        const name = 'bps-cs-p-' + ++uid;
        const opts = [{ id: card.placement, cm: card.patchSizeCm }, { id: card.alt.placement, cm: card.alt.patchSizeCm }];
        edit.append(group(tr('cs.placement'), el('div', { class: 'bps-cs__chips' }, opts.map((o) => {
          const id = name + '-' + o.id;
          const inp = el('input', { type: 'radio', name, id, value: o.id, checked: sel.placement === o.id });
          inp.addEventListener('change', () => {
            sel.placement = o.id;
            refresh();
          });
          return el('label', { class: 'bps-cs__chip', for: id }, inp, el('span', { text: `${tr('cs.placement.' + o.id)} · ${String(o.cm).replace('.', ',')} cm` }));
        }))));
      }
      if (card.options && card.options.length) {
        const namesWrap = el('div', { class: 'bps-cs__names', hidden: !sel.options.includes('name_back') });
        const ta = el('textarea', { rows: 4, 'aria-describedby': editId + '-nh' });
        ta.value = sel.names;
        ta.addEventListener('input', () => {
          sel.names = ta.value;
          updatePrice();
        });
        const taId = 'bps-cs-n-' + ++uid;
        ta.id = taId;
        namesWrap.append(el('label', { for: taId, text: tr('cs.namesBack') }), ta, el('p', { class: 'bps-cs__help', id: editId + '-nh', text: tr('cs.namesHelp') }));
        edit.append(group(tr('cs.options'), ...card.options.map((o) => {
          const id = 'bps-cs-o-' + ++uid;
          const inp = el('input', { type: 'checkbox', id, value: o.id, checked: sel.options.includes(o.id) });
          inp.addEventListener('change', () => {
            sel.options = inp.checked ? [...sel.options, o.id] : sel.options.filter((x) => x !== o.id);
            if (o.id === 'name_back') namesWrap.hidden = !inp.checked;
            updatePrice();
          });
          return el('label', { class: 'bps-cs__check', for: id }, inp, el('span', { text: `${o.label} (+ ${money(o.incl)})` }));
        }), namesWrap));
      }
      if (card.needsPlate) {
        const max = (card.plate && card.plate.maxChars) || 28;
        const help = el('p', { class: 'bps-cs__help', text: tr('cs.plateHelp', { n: max }) });
        const fields = [0, 1].map((i) => {
          const id = 'bps-cs-pl-' + ++uid;
          const inp = el('input', { type: 'text', id, maxlength: max, value: sel.plate[i], autocomplete: 'off' });
          inp.addEventListener('input', () => {
            sel.plate[i] = inp.value.slice(0, max);
            raf(draw);
          });
          return el('label', { class: 'bps-cs__field', for: id }, el('span', { text: tr(i === 0 ? 'cs.plate1' : 'cs.plate2') }), inp);
        });
        edit.append(group(tr('cs.writePlate'), ...fields, help));
      }
    }

    function stepper() {
      const id = 'bps-cs-q-' + ++uid;
      const min = card.minQty || 1;
      const step = card.qtyStep || 1;
      const inp = el('input', { type: 'number', id, min, step, max: 9990, inputmode: 'numeric', value: sel.qty });
      const set = (n) => {
        n = Math.max(min, Math.min(9990, Math.ceil(n / step) * step));
        sel.qty = n;
        inp.value = String(n);
        updatePrice();
      };
      inp.addEventListener('change', () => set(parseInt(inp.value, 10) || min));
      const minus = el('button', { type: 'button', class: 'bps-cs__step', 'aria-label': tr('cs.minus'), onclick: () => set(sel.qty - step) }, el('span', { 'aria-hidden': 'true', text: '−' }));
      const plus = el('button', { type: 'button', class: 'bps-cs__step', 'aria-label': tr('cs.plus'), onclick: () => set(sel.qty + step) }, el('span', { 'aria-hidden': 'true', text: '+' }));
      return el('div', { class: 'bps-cs__qty' }, el('label', { for: id, text: tr('cs.qty') }), el('div', { class: 'bps-cs__stepper' }, minus, inp, plus));
    }

    function toggleEdit(open) {
      if (open && !editorBuilt) buildEditor();
      edit.hidden = !open;
      changeBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (needsSecond) addBtn.textContent = open ? tr('cs.add') : tr(card.needsSize ? 'cs.chooseSizes' : 'cs.writePlate');
      updatePrice();
      if (open) {
        const first = edit.querySelector('input:not([disabled]),textarea');
        if (first && needsSecond) first.focus();
      }
    }

    function refresh() {
      updatePrice();
      raf(draw);
    }

    function body() {
      const lines = [];
      if (card.needsSize) {
        for (const [size, n] of Object.entries(sel.sizes)) {
          if (!(n > 0)) continue;
          const v = card.variations.find((x) => x.productId === sel.productId && x.colorSlug === sel.colorSlug && x.size === size);
          if (v) lines.push({ productId: sel.productId, variationId: v.id, qty: n });
        }
      } else {
        const v = currentVariation();
        lines.push({ productId: sel.productId, variationId: v ? v.id : 0, qty: effectiveQty() });
      }
      const names = sel.options.includes('name_back') ? namesList() : null;
      return {
        item: card.id,
        lines,
        options: sel.options.slice(),
        plate: card.needsPlate ? { l1: sel.plate[0].trim(), l2: sel.plate[1].trim() } : null,
        names,
        placement: sel.placement,
      };
    }

    async function add() {
      if (needsSecond && edit.hidden) {
        toggleEdit(true);
        return;
      }
      err.hidden = true;
      const b = body();
      if (card.needsSize && !b.lines.length) {
        err.textContent = tr('cs.err.sizes');
        err.hidden = false;
        return;
      }
      if (b.names && !b.names.length) {
        err.textContent = tr('cs.err.names');
        err.hidden = false;
        return;
      }
      addBtn.disabled = true;
      const label = addBtn.textContent;
      addBtn.textContent = tr('cs.adding');
      try {
        const r = (await env.onAdd(card, b)) || {};
        keys = Array.isArray(r.keys) ? r.keys : [];
        actions.hidden = true;
        edit.hidden = true;
        done.hidden = false;
        node.classList.add('is-added');
        live.textContent = tr('cs.addedLive', { title: card.title });
        markAdded();
        undoBtn.focus();
      } catch (e) {
        err.textContent = (e && e.message && e.code !== 'network' && e.code !== 'offline' ? e.message : '') || tr('cs.err.generic');
        err.hidden = false;
      } finally {
        addBtn.disabled = false;
        addBtn.textContent = label;
      }
    }

    async function undo() {
      undoBtn.disabled = true;
      try {
        for (const k of keys) await env.onUndo(k);
        keys = [];
        done.hidden = true;
        actions.hidden = false;
        node.classList.remove('is-added');
        live.textContent = tr('cs.undoneLive', { title: card.title });
        addBtn.focus();
      } catch (e) {
        err.textContent = tr('cs.err.generic');
        err.hidden = false;
      } finally {
        undoBtn.disabled = false;
      }
    }

    addBtn.addEventListener('click', add);
    changeBtn.addEventListener('click', () => toggleEdit(edit.hidden));
    undoBtn.addEventListener('click', undo);
    updatePrice();
    return { node, draw, card, sel, body };
  }

  return {
    panel,
    firstFocus: () => panel.querySelector('.bps-cs__add') || decline,
    get addedAny() {
      return addedAny;
    },
    redraw: () => controllers.map((c) => c.draw()),
    controllers,
  };
}
