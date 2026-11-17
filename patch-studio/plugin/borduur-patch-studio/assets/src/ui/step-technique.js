/**
 * Patch Studio UI — step 2: expert advice, technique cards (radio group), compare view, thread colours.
 */
import { renderThumb, artFromAnalysis } from '../render/index.js';
import { threadLabel, qualityLabel, colorDisclaimer, describeMerges } from '../colors/index.js';
import { h, text, keyed, icon, uid } from './dom.js';
import { renderArt, renderOptions, fabricList } from './derive.js';

const STATUS_ICON = { ok: 'ok', warn: 'warn', bad: 'bad' };

export function stepTechnique(ctx) {
  const { t, config, store } = ctx;
  const L = (o) => ctx.L(o);
  const headline = h('p.bps-advice__head');
  const body = h('div.bps-advice__body');
  const adviceBox = h('section.bps-advice', { 'aria-live': 'off' },
    h('p.bps-advice__src', icon('chat'), h('span', t('advice.source', { city: (config.contact && config.contact.city) || 'Kalmthout' }))),
    headline, body,
    h('p.bps-advice__note', t('advice.disclaimer')));
  const cards = h('div.bps-cards');
  const more = h('div.bps-cards.bps-cards--more', { id: uid('bps-more'), hidden: true });
  const moreBtn = h('button.bps-btn.bps-btn--link', { type: 'button', 'aria-expanded': 'false', 'aria-controls': more.id, onclick: () => { ctx.ui.showAllTech = !ctx.ui.showAllTech; ctx.refresh(); } });
  const fs = h('fieldset.bps-fieldset.bps-techs', h('legend.bps-legend.bps-sr', t('ui.s2.legend')), cards, moreBtn, more);
  const upsells = h('div.bps-upsells');
  const compareList = h('ul.bps-compare__list');
  const compare = config.features && config.features.compare === false ? null
    : h('section.bps-compare', h('h3.bps-h3', t('ui.s2.compare')), compareList);
  const swatches = h('ul.bps-swatches');
  const countOut = h('output.bps-range__out');
  const choices = (config.advice && config.advice.threadColorChoices) || [6, 8, 10, 12];
  const count = h('input.bps-range', {
    type: 'range', min: 0, max: choices.length - 1, step: 1, id: uid('bps-count'),
    oninput: (e) => store.set({ maxThreadColors: choices[Number(e.target.value)] }),
  });
  const changesBox = h('div.bps-changes', { hidden: true });
  const changesBtn = h('button.bps-btn.bps-btn--small.bps-btn--ghost', { type: 'button', 'aria-expanded': 'false', onclick: () => { ctx.ui.showChanges = !ctx.ui.showChanges; ctx.refresh(); } }, t('ui.s2.showChanges'));
  const threads = h('section.bps-threads',
    h('h3.bps-h3', t('ui.s2.threads')),
    h('div.bps-range-row', h('label', { for: count.id }, t('ui.s2.threadCount')), count, countOut),
    h('div.bps-ticks', { 'aria-hidden': 'true' }, choices.map((c) => h('span', String(c)))),
    h('p.bps-help.bps-threads__few', { hidden: true }),
    swatches, changesBtn, changesBox,
    h('p.bps-help', colorDisclaimer({ config, locale: ctx.locale })));
  const noAnalysis = h('p.bps-notice.bps-notice--info', { hidden: true }, t('ui.s2.noAnalysis'));

  const el = h('section.bps-step',
    h('h2.bps-h2', { tabindex: '-1' }, t('ui.s2.title')),
    noAnalysis, adviceBox, fs, upsells, compare, threads,
    h('p.bps-disclaimer', t('ui.s2.disclaimer')),
    h('div.bps-step__nav',
      h('button.bps-btn.bps-btn--ghost', { type: 'button', onclick: () => ctx.go(1) }, t('ui.back')),
      h('button.bps-btn.bps-btn--primary', { type: 'button', onclick: () => ctx.go(3) }, t('ui.s2.next'), icon('arrow'))));

  function priceLine(id, d) {
    const q = d.quotes[id];
    const tech = config.techniques[id];
    if (!q || tech.mode === 'quote' || q.mode === 'quote' || !(q.totalIncl > 0)) return h('p.bps-card__price.is-request', t('pricing.onRequest'));
    return h('p.bps-card__price',
      h('strong', t('ui.s2.perPiece', { unit: ctx.money(q.unitIncl, q.lineExcl / q.qty), total: ctx.money(q.totalIncl, q.totalExcl) })),
      ' ', h('span.bps-muted', t('ui.s2.forQty', { qty: q.qty })));
  }

  function card(id, s, d) {
    const v = d.advice.verdicts[id];
    if (!v) return null;
    const tech = config.techniques[id];
    const rec = d.advice.recommended === id;
    const inputId = `${ctx.uidBase}-tech-${id}`;
    const fix = (v.fixes || [])[0];
    const reason = (v.reasons || [])[0];
    const whyId = uid('bps-why');
    return h('div.bps-card' + (s.technique === id ? '.is-selected' : '') + '.is-' + v.status,
      h('input.bps-card__radio', { type: 'radio', id: inputId, name: ctx.uidBase + '-tech', value: id, checked: s.technique === id, 'data-fk': 'tech-' + id, onchange: () => ctx.applyPatch({ technique: id, techniquePicked: true }) }),
      h('label.bps-card__label', { for: inputId },
        h('span.bps-card__top',
          h('span.bps-status.bps-status--' + v.status, icon(STATUS_ICON[v.status]), t('advice.status.' + v.status)),
          rec ? h('span.bps-badge', t('advice.badge.recommended')) : null),
        h('span.bps-card__tech', L(tech.label)),
        h('span.bps-card__head', v.headline && v.headline.text),
        reason ? h('span.bps-card__reason', reason.msg.text) : null),
      priceLine(id, d),
      h('div.bps-card__actions',
        fix ? h('button.bps-btn.bps-btn--small' + (s.technique === id ? '.bps-btn--secondary' : '.bps-btn--ghost'), { type: 'button', 'data-fk': 'fix-' + id, onclick: () => ctx.applyFix(fix.action, id) }, fix.msg.text) : null,
        (v.reasons || []).length > 1 || (v.notes || []).length
          ? h('details.bps-why', h('summary', { id: whyId }, t('ui.s2.why')),
            h('ul', (v.reasons || []).map((r) => h('li.bps-why__' + r.severity, r.msg.text)), (v.notes || []).map((n) => h('li', n.text))))
          : null));
  }

  function thumbsKey(s, d) {
    return [ctx.analysis ? ctx.analysis.job : 0, d.advice.ranking.slice(0, 3).join(), d.fabric.id, d.edgeHex, s.shape, s.sizeCm, s.maxThreadColors,
      JSON.stringify(s.texts), JSON.stringify(s.art), s.options.join(), d.keepBg, JSON.stringify(s.threadOverrides)].join('|');
  }

  function thumb(s, d, tech, extra = {}) {
    let img = null;
    try { img = d.analysis ? artFromAnalysis(d.analysis) : null; } catch (e) { img = null; }
    // Each technique on a fabric it can really be made on (sublimation: white only).
    const fl = fabricList(config, tech);
    const fabricHex = !fl.length || fl.some((f) => f.hex === d.fabricHex) ? d.fabricHex : fl[0].hex;
    const opts = renderOptions(s, d, renderArt(d, img), { technique: tech, fabricHex, reduced: !!config.techniques[tech].threadBased && !!d.reduced, quality: 'high', pxSize: 220, ...extra });
    try {
      const c = renderThumb(opts, 220);
      if (c && c.nodeType === 1) { c.setAttribute('aria-hidden', 'true'); c.classList.add('bps-thumb'); return c; }
      if (c && typeof c.convertToBlob === 'function') {
        const cv = h('canvas.bps-thumb', { width: c.width, height: c.height, 'aria-hidden': 'true' });
        cv.getContext('2d').drawImage(c, 0, 0);
        return cv;
      }
    } catch (e) { console.warn('[bps] thumb', e); }
    return h('span.bps-thumb.bps-thumb--empty', { 'aria-hidden': 'true' });
  }

  return {
    el,
    update(s, d) {
      const adv = d.advice;
      const hasA = !!ctx.analysis;
      noAnalysis.hidden = hasA || s.source.kind === 'text' || !s.source.fileName;
      text(headline, adv.summary && adv.summary.headline ? adv.summary.headline.text : '');
      const parts = (adv.summary && adv.summary.text && (adv.summary.text.parts || [adv.summary.text])) || [];
      keyed(body, parts.map((p) => p.text).join('|'), () => parts.map((p) => h('p', p.text)));

      const ranking = adv.ranking || [];
      const top = ranking.slice(0, 3);
      if (!top.includes(s.technique) && ranking.includes(s.technique)) top[2] = s.technique;
      const rest = ranking.filter((id) => !top.includes(id));
      const sig = JSON.stringify([s.technique, ranking, ctx.ui.showAllTech, Object.values(adv.verdicts).map((v) => [v.status, v.headline && v.headline.text, (v.reasons[0] || {}).msg && v.reasons[0].msg.text, (v.fixes[0] || {}).msg && v.fixes[0].msg.text]),
        Object.values(d.quotes).map((q) => q.totalIncl), ctx.ui.exclVat]);
      keyed(cards, sig, () => top.map((id) => card(id, s, d)));
      keyed(more, sig, () => rest.map((id) => card(id, s, d)));
      more.hidden = !ctx.ui.showAllTech;
      moreBtn.hidden = !rest.length;
      moreBtn.setAttribute('aria-expanded', String(!!ctx.ui.showAllTech));
      text(moreBtn, ctx.ui.showAllTech ? t('ui.s2.showLess') : t('ui.s2.showAll', { n: ranking.length }));

      keyed(upsells, JSON.stringify((adv.upsells || []).map((u) => u.msg.text)), () => (adv.upsells || []).length
        ? [h('h3.bps-h3', t('ui.s2.upsells')), h('ul.bps-upsells__list', adv.upsells.map((u) => h('li',
          h('span', u.msg.text), ' ',
          u.action && u.action.type === 'set' ? h('button.bps-btn.bps-btn--small.bps-btn--ghost', { type: 'button', onclick: () => ctx.applyFix(u.action) }, icon('ok')) : null)))]
        : []);
      upsells.querySelectorAll('button').forEach((b, i) => b.setAttribute('aria-label', (adv.upsells[i] && adv.upsells[i].msg.text) || ''));

      // Compare (only when the step is visible: rendering thumbs costs a few ms each).
      if (compare) {
        compare.hidden = !hasA && !s.texts.length;
        if (ctx.step === 2 && !compare.hidden) {
          keyed(compareList, thumbsKey(s, d) + ctx.ui.exclVat, () => top.map((id) => {
            const q = d.quotes[id];
            const v = adv.verdicts[id];
            return h('li.bps-compare__item', thumb(s, d, id),
              h('span.bps-compare__name', L(config.techniques[id].label)),
              h('span.bps-compare__line', v && v.headline ? v.headline.text : ''),
              h('span.bps-compare__price', q && q.mode === 'order' && q.totalIncl > 0 ? t('pricing.perPiece', { amount: ctx.money(q.unitIncl, q.lineExcl / q.qty) }) : t('pricing.onRequest')));
          }));
        }
      }

      // Thread colours (thread techniques with an analysed image).
      const showThreads = hasA && d.useThreads && !!d.reduced;
      threads.hidden = !showThreads;
      if (showThreads) {
        const idx = Math.max(0, choices.indexOf(s.maxThreadColors));
        if (document.activeElement !== count) count.value = idx;
        const k = d.reduced.k;
        // Fewer colours than the smallest choice: the slider would change nothing (and showed "2" on a 6-12 scale).
        const few = (ctx.analysis.palette || []).length <= Math.min(...choices);
        count.closest('.bps-range-row').hidden = few;
        threads.querySelector('.bps-ticks').hidden = few;
        const fewP = threads.querySelector('.bps-threads__few');
        fewP.hidden = !few;
        if (few) text(fewP, t('ui.s2.threadsFew', { n: k }));
        count.setAttribute('aria-valuetext', t('ui.s2.threadCountValue', { n: s.maxThreadColors }));
        text(countOut, t('ui.s2.threadCountValue', { n: k }));
        keyed(swatches, JSON.stringify([d.reducedColors.map((c) => (d.threadOf(c) || {}).code), s.threadOverrides]), () => d.reducedColors.map((c, i) => {
          const th = d.threadOf(c);
          if (!th) return null;
          const alts = [c.thread && c.thread.thread, ...((c.thread && c.thread.alternatives) || [])].filter(Boolean);
          const selId = uid('bps-alt');
          const pi = (c.sources || [i])[0];
          return h('li.bps-swatch',
            h('span.bps-swatch__chip', { style: { background: th.hex }, 'aria-hidden': 'true' }),
            h('span.bps-swatch__txt',
              h('span.bps-swatch__name', threadLabel(th, { config, locale: ctx.locale })),
              h('span.bps-swatch__q', c.override ? '' : qualityLabel(c.thread && c.thread.quality, { config, locale: ctx.locale }))),
            alts.length > 1 ? h('span.bps-swatch__alt',
              h('label.bps-sr', { for: selId }, t('ui.s2.threadPick', { name: L(th.name) })),
              h('select.bps-select.bps-select--small', { id: selId, 'data-fk': 'alt-' + pi, onchange: (e) => store.set({ threadOverrides: { ...store.get().threadOverrides, [pi]: e.target.value } }) },
                alts.map((a) => h('option', { value: a.code, selected: a.code === th.code }, `${L(a.name)} (${a.code})`)))) : null);
        }));
        changesBtn.setAttribute('aria-expanded', String(!!ctx.ui.showChanges));
        changesBox.hidden = !ctx.ui.showChanges;
        if (ctx.ui.showChanges && ctx.step === 2) {
          keyed(changesBox, thumbsKey(s, d) + s.technique, () => {
            const merges = describeMerges(d.reduced, { config, locale: ctx.locale }) || [];
            return [
              h('div.bps-changes__pair',
                h('figure', thumb(s, d, 'printed', { reduced: false }), h('figcaption', t('ui.s2.original'))),
                h('figure', thumb(s, d, s.technique, { reduced: true }), h('figcaption', t('ui.s2.simplified')))),
              merges.length ? h('ul.bps-changes__list', merges.map((m) => h('li', m.text))) : null,
            ];
          });
        }
      }
    },
  };
}
