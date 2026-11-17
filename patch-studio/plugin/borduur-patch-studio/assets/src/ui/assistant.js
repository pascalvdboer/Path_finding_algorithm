/**
 * Patch Studio UI — the assistant (right column on desktop, "Techniek" sheet on phones): one clear voice from the
 * atelier. Headline advice, the technique comparer (live mini-renders of THIS design in every technique, each with
 * one sentence why, price per piece and lead time; click = choose) and short craft notes as cards.
 */
import * as R from '../render/index.js';
import { h, text, keyed, icon, uid } from './dom.js';
import { renderArt, renderOptions, fabricList } from './derive.js';

const STATUS_ICON = { ok: 'ok', warn: 'warn', bad: 'bad' };
const idle = (fn) => (typeof requestIdleCallback === 'function' ? requestIdleCallback(fn, { timeout: 600 }) : setTimeout(fn, 30));

/** Standalone render of the current design in technique `tech` (a canvas, or an empty placeholder). */
export function thumb(ctx, s, d, tech, extra = {}, px = 192) {
  const { config } = ctx;
  let img = null;
  try { img = d.analysis ? R.artFromAnalysis(d.analysis) : null; } catch (e) { img = null; }
  // Each technique on a fabric it can really be made on (sublimation: white only).
  const fl = fabricList(config, tech);
  const fabricHex = !fl.length || fl.some((f) => f.hex === d.fabricHex) ? d.fabricHex : fl[0].hex;
  const opts = renderOptions(s, d, renderArt(d, img), { technique: tech, fabricHex, reduced: !!config.techniques[tech].threadBased && !!d.reduced, quality: 'high', pxSize: px, view: 'patch', zoom: 1, ...extra });
  try {
    const c = R.renderThumb(opts, px);
    if (c && c.nodeType === 1) { c.setAttribute('aria-hidden', 'true'); c.classList.add('bps-thumb'); return c; }
    if (c && c.width) {
      const cv = h('canvas.bps-thumb', { width: c.width, height: c.height, 'aria-hidden': 'true' });
      cv.getContext('2d').drawImage(c, 0, 0);
      return cv;
    }
  } catch (e) { console.warn('[bps] thumb', e); }
  return h('span.bps-thumb.bps-thumb--empty', { 'aria-hidden': 'true' });
}

export function createAssistant(ctx) {
  const { t, config } = ctx;
  const L = (o) => ctx.L(o);
  const city = (config.contact && config.contact.city) || 'Kalmthout';
  const headline = h('p.bps-advice__head');
  const body = h('div.bps-advice__body');
  const notes = h('ul.bps-notes');
  const adviceBox = h('section.bps-advice', { 'aria-live': 'off' },
    h('p.bps-advice__src', h('span.bps-avatar', { 'aria-hidden': 'true' }, icon('spark')), h('span', t('advice.source', { city }))),
    headline, body, notes);
  const cards = h('div.bps-cards');
  const more = h('div.bps-cards.bps-cards--more', { id: uid('bps-more'), hidden: true });
  const moreBtn = h('button.bps-btn.bps-btn--link', { type: 'button', 'aria-expanded': 'false', 'aria-controls': more.id, onclick: () => { ctx.ui.showAllTech = !ctx.ui.showAllTech; ctx.refresh(); } });
  const compare = h('fieldset.bps-fieldset.bps-techs',
    h('legend.bps-legend.bps-compare__title', t('ui.assist.compare')),
    h('p.bps-help.bps-compare__sub', t('ui.assist.compareSub')),
    cards, moreBtn, more);
  const tips = h('div.bps-tips');
  const noAnalysis = h('p.bps-notice.bps-notice--info', { hidden: true }, icon('info'), h('span', t('ui.s2.noAnalysis')));
  const el = h('section.bps-panel.bps-step.bps-assist', { dataset: { panel: 'advice' }, 'aria-labelledby': ctx.uidBase + '-p-advice', hidden: true },
    h('header.bps-panel__head', h('h2.bps-panel__title', { id: ctx.uidBase + '-p-advice', tabindex: '-1' }, icon('spark'), t('ui.panel.advice'))),
    h('div.bps-panel__body', noAnalysis, adviceBox, compare, tips,
      h('p.bps-disclaimer', t('advice.disclaimer'), ' ', t('ui.s2.disclaimer'))),
    h('div.bps-step__nav', h('button.bps-btn.bps-btn--primary.bps-btn--block', { type: 'button', onclick: () => ctx.openPanel('size', { focus: true }) }, t('ui.s2.next'), icon('arrow'))));

  /* ---------------- thumbnails: rendered progressively in idle time ---------------- */
  const thumbs = new Map();
  let queue = [], busy = false, sigNow = '';
  function pump() {
    if (busy || !queue.length) return;
    busy = true;
    idle(() => {
      busy = false;
      const job = queue.shift();
      if (job && job.sig === sigNow) {
        const cv = thumb(ctx, job.s, job.d, job.id);
        thumbs.set(job.sig + job.id, cv);
        const slot = el.querySelector(`[data-thumb="${job.id}"]`);
        if (slot) { slot.replaceChildren(cv.cloneNode ? copy(cv) : cv); slot.classList.add('is-ready'); }
      }
      pump();
    });
  }
  function copy(cv) {
    if (!(cv instanceof HTMLCanvasElement)) return cv.cloneNode(true);
    const c = h('canvas.bps-thumb', { width: cv.width, height: cv.height, 'aria-hidden': 'true' });
    try { c.getContext('2d').drawImage(cv, 0, 0); } catch (e) { /* ignore */ }
    return c;
  }
  function thumbSig(s, d) {
    return [ctx.analysis ? ctx.analysis.job : 0, d.fabric.id, d.edgeHex, s.shape, s.aspect, s.sizeCm, s.maxThreadColors, JSON.stringify(s.texts), JSON.stringify(s.art),
      s.options.join(), d.keepBg, JSON.stringify(s.threadOverrides), JSON.stringify(d.textHex)].join('|');
  }

  /* ---------------- technique card ---------------- */
  function priceLine(id, d) {
    const q = d.quotes[id];
    const tech = config.techniques[id];
    if (!q || tech.mode === 'quote' || q.mode === 'quote' || !(q.totalIncl > 0)) return h('span.bps-card__price.is-request', t('pricing.onRequest'));
    return h('span.bps-card__price', h('strong', t('pricing.perPiece', { amount: ctx.money(q.unitIncl, q.lineExcl / q.qty) })), ' ', h('span.bps-muted', t('ui.s2.forQty', { qty: q.qty })));
  }

  function card(id, s, d, sig) {
    const v = d.advice.verdicts[id];
    if (!v) return null;
    const tech = config.techniques[id];
    const rec = d.advice.recommended === id;
    const inputId = `${ctx.uidBase}-tech-${id}`;
    const fix = (v.fixes || [])[0];
    const lead = L(tech.leadTime) || t('ui.lead.proof');
    const cached = thumbs.get(sig + id);
    const slot = h('span.bps-card__vis' + (cached ? '.is-ready' : ''), { dataset: { thumb: id } }, cached ? copy(cached) : h('span.bps-thumb.bps-thumb--empty', { 'aria-hidden': 'true' }));
    if (!cached && (ctx.analysis || s.texts.length)) queue.push({ sig, id, s, d });
    return h('div.bps-card' + (s.technique === id ? '.is-selected' : '') + '.is-' + v.status,
      h('input.bps-card__radio', { type: 'radio', id: inputId, name: ctx.uidBase + '-tech', value: id, checked: s.technique === id, 'data-fk': 'tech-' + id, onchange: () => ctx.applyPatch({ technique: id, techniquePicked: true }) }),
      h('label.bps-card__label', { for: inputId },
        slot,
        h('span.bps-card__txt',
          h('span.bps-card__top', h('span.bps-card__tech', L(tech.label)), rec ? h('span.bps-badge', t('advice.badge.recommended')) : null),
          h('span.bps-status.bps-status--' + v.status, icon(STATUS_ICON[v.status]), t('advice.status.' + v.status)),
          h('span.bps-card__head', v.headline && v.headline.text),
          h('span.bps-card__meta', priceLine(id, d), h('span.bps-card__lead', icon('clock'), lead)))),
      fix || (v.reasons || []).length > 1 || (v.notes || []).length ? h('div.bps-card__actions',
        fix ? h('button.bps-btn.bps-btn--small.bps-btn--ghost', { type: 'button', 'data-fk': 'fix-' + id, onclick: () => ctx.applyFix(fix.action, id) }, fix.msg.text) : null,
        (v.reasons || []).length > 1 || (v.notes || []).length
          ? h('details.bps-why', h('summary', t('ui.s2.why')),
            h('ul', (v.reasons || []).map((r) => h('li.bps-why__' + r.severity, r.msg.text)), (v.notes || []).map((n) => h('li', n.text))))
          : null) : null);
  }

  /* ---------------- craft notes: one voice, no contradicting boxes ---------------- */
  function noteList(s, d) {
    const a = ctx.analysis, st = ctx.analysisState, out = [];
    const act = (lbl, fn) => h('button.bps-btn.bps-btn--small.bps-btn--link', { type: 'button', onclick: fn }, lbl);
    if (st.status === 'unsupported' || st.status === 'error') out.push({ k: 'info', txt: st.message });
    if (a && st.status !== 'running') {
      const shapeName = L((config.shapes[s.shape] || {}).label).toLowerCase();
      const warns = (d.advice && d.advice.warnings) || [];
      if (a.isPatchPhoto) {
        const mock = warns.some((w) => /mockup/.test(w.key || ''));
        out.push({ k: 'ok', txt: t('ui.s1.cutAsk') + (mock ? ' ' + t('ui.a.original') : ''), acts: [ctx.file ? act(t('ui.s1.adjust'), () => ctx.openAdjust()) : null] });
      } else if (a.shape && a.shape.fillsPatch && a.shape.type && !s.shapePicked) {
        const unsure = (a.shape.confidence || 0) < ((config.analysis && config.analysis.shapeMinConfidence) || 0.75);
        out.push({ k: unsure ? 'warn' : 'ok', txt: unsure ? t('ui.s1.shapeAsk') : t('ui.s1.shapeFound', { shape: shapeName }), acts: [act(t('ui.s1.otherShape'), () => ctx.openPanel('shape', { focus: true })), ctx.file ? act(t('ui.s1.adjust'), () => ctx.openAdjust()) : null] });
      }
      const bg = (d.analysis && d.analysis.background) || {};
      if (d.keepBg) out.push({ k: 'ok', txt: t('ui.s1.keepBg') });
      else if (bg.type === 'white' && !a.isPatchPhoto) out.push({ k: 'ok', txt: t('ui.s1.whiteBg', { fabric: L(d.fabric.label).toLowerCase() }), acts: [act(t('ui.s1.otherFabric'), () => ctx.openPanel('colors', { focus: true }))] });
      for (const w of warns) {
        if (/patch_photo_cut|mockup/.test(w.key || '') && a.isPatchPhoto) continue;
        if (/shape_uncertain/.test(w.key || '') && a.shape && a.shape.fillsPatch && !s.shapePicked) continue;
        out.push({ k: 'warn', txt: w.text });
      }
    }
    const sa = d.advice.size || {};
    if (sa.reason && (ctx.analysis || s.texts.length)) {
      const up = sa.recommendedCm && sa.recommendedCm !== s.sizeCm;
      out.push({ k: up && sa.recommendedCm > s.sizeCm ? 'warn' : 'ok', txt: sa.reason.text, acts: [up ? act(t('ui.s3.adviceBtn', { n: ctx.n(sa.recommendedCm) }), () => ctx.store.set({ sizeCm: sa.recommendedCm, sizePicked: true })) : null] });
    }
    return out;
  }

  return {
    el,
    update(s, d) {
      const adv = d.advice;
      const hasA = !!ctx.analysis;
      noAnalysis.hidden = hasA || s.source.kind === 'text' || !s.source.fileName || ctx.analysisState.status === 'running';
      text(headline, adv.summary && adv.summary.headline ? adv.summary.headline.text : '');
      const parts = (adv.summary && adv.summary.text && (adv.summary.text.parts || [adv.summary.text])) || [];
      keyed(body, parts.map((p) => p.text).join('|'), () => parts.map((p) => h('p', p.text)));
      const nl = noteList(s, d);
      keyed(notes, JSON.stringify(nl.map((n) => n.k + n.txt)), () => nl.map((n) => h('li.bps-note.bps-note--' + n.k, icon(n.k === 'warn' ? 'warn' : n.k === 'info' ? 'info' : 'ok'), h('span.bps-note__txt', n.txt, (n.acts || []).filter(Boolean).length ? [' ', ...n.acts.filter(Boolean)] : null))));
      notes.hidden = !nl.length;

      const ranking = adv.ranking || [];
      const top = ranking.slice(0, 4);
      if (!top.includes(s.technique) && ranking.includes(s.technique)) top[3] = s.technique;
      const rest = ranking.filter((id) => !top.includes(id));
      const sig = thumbSig(s, d);
      if (sig !== sigNow) { sigNow = sig; queue = []; if (thumbs.size > 40) thumbs.clear(); }
      const csig = JSON.stringify([sig, s.technique, ranking, ctx.ui.showAllTech, Object.values(adv.verdicts).map((v) => [v.status, v.headline && v.headline.text, (v.fixes[0] || {}).msg && v.fixes[0].msg.text]),
        Object.values(d.quotes).map((q) => q.totalIncl), ctx.ui.exclVat]);
      const before = queue.length;
      keyed(cards, csig, () => top.map((id) => card(id, s, d, sig)));
      keyed(more, csig + '|m', () => (ctx.ui.showAllTech ? rest.map((id) => card(id, s, d, sig)) : []));
      if (queue.length !== before || queue.length) pump();
      more.hidden = !ctx.ui.showAllTech;
      moreBtn.hidden = !rest.length;
      moreBtn.setAttribute('aria-expanded', String(!!ctx.ui.showAllTech));
      text(moreBtn, ctx.ui.showAllTech ? t('ui.s2.showLess') : t('ui.s2.showAll', { n: ranking.length }));

      keyed(tips, JSON.stringify((adv.upsells || []).map((u) => u.msg.text)), () => ((adv.upsells || []).length
        ? [h('h3.bps-h3', t('ui.s2.upsells')), h('ul.bps-notes', adv.upsells.map((u) => h('li.bps-note.bps-note--tip', icon('spark'), h('span.bps-note__txt', u.msg.text,
          u.action && u.action.type === 'set' ? [' ', h('button.bps-btn.bps-btn--small.bps-btn--link', { type: 'button', onclick: () => ctx.applyFix(u.action) }, t('ui.assist.apply'))] : null))))]
        : []));
    },
  };
}
