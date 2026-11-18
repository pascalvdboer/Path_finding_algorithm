/**
 * Patch Studio UI — the assistant (right column on desktop, "Techniek" sheet on phones): one clear voice from the
 * atelier. Headline advice, the technique comparer (live mini-renders of THIS design in every technique, each with
 * one sentence why, price per piece and lead time; click = choose) and short craft notes as cards.
 */
import * as R from '../render/index.js';
import { h, text, keyed, icon, uid } from './dom.js';
import { renderArt, renderOptions, fabricList, detailMm } from './derive.js';

const RNS = R; // namespace object: optional render features are looked up at runtime
const STATUS_ICON = { ok: 'ok', warn: 'warn', bad: 'bad' };
/** Comparer tiles are ±170 CSS px wide: 280 px renders (2× supersampled inside render) stay crisp on retina. */
const THUMB_PX = 280;
const idle = (fn) => (typeof requestIdleCallback === 'function' ? requestIdleCallback(fn, { timeout: 600 }) : setTimeout(fn, 30));

/** Standalone render of the current design in technique `tech` (a canvas, or an empty placeholder). */
export function thumb(ctx, s, d, tech, extra = {}, px = 192, detail = null) {
  const { config } = ctx;
  let img = null;
  try { img = d.analysis ? R.artFromAnalysis(d.analysis) : null; } catch (e) { img = null; }
  // Each technique on a fabric it can really be made on (sublimation: white only).
  const fl = fabricList(config, tech);
  const fabricHex = !fl.length || fl.some((f) => f.hex === d.fabricHex) ? d.fabricHex : fl[0].hex;
  const opts = renderOptions(s, d, renderArt(d, img), { technique: tech, fabricHex, reduced: !!config.techniques[tech].threadBased && !!d.reduced, quality: 'high', pxSize: px, view: 'patch', zoom: 1, ...extra });
  try {
    // render v2: each technique with its own fitting border/material (feature-detected, older render: renderThumb).
    const RT = RNS['renderTechniques'];
    const c = typeof RT === 'function' ? (RT(opts, { size: px, techniques: [tech], config, ...(detail || {}) }) [0] || {}).canvas : R.renderThumb(opts, px);
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
    headline, body);
  const cards = h('div.bps-cards');
  const more = h('div.bps-cards.bps-cards--more', { id: uid('bps-more'), hidden: true });
  const moreBtn = h('button.bps-btn.bps-btn--link', { type: 'button', 'aria-expanded': 'false', 'aria-controls': more.id, onclick: () => { ctx.ui.showAllTech = !ctx.ui.showAllTech; ctx.refresh(); } });
  const compare = h('fieldset.bps-fieldset.bps-techs',
    h('legend.bps-legend.bps-compare__title', t('ui.assist.compare')),
    h('p.bps-help.bps-compare__sub', t('ui.assist.compareSub')),
    cards, moreBtn, more);
  const tips = h('div.bps-tips');
  const ideas = h('section.bps-ideas', { hidden: true });
  const dismissed = new Set();
  const noAnalysis = h('p.bps-notice.bps-notice--info', { hidden: true }, icon('info'), h('span', t('ui.s2.noAnalysis')));
  const el = h('section.bps-panel.bps-step.bps-assist', { dataset: { panel: 'advice' }, 'aria-labelledby': ctx.uidBase + '-p-advice', hidden: true },
    h('header.bps-panel__head', h('h2.bps-panel__title', { id: ctx.uidBase + '-p-advice', tabindex: '-1' }, icon('spark'), t('ui.panel.advice')),
      h('button.bps-ibtn.bps-panel__fold', { type: 'button', 'aria-label': t('ui.side.fold'), title: t('ui.side.fold'), onclick: () => ctx.toggleSide(false) }, icon('chevron'))),
    h('div.bps-panel__body', noAnalysis, adviceBox, compare, ideas, h('section.bps-seen', h('h3.bps-h3', t('ui.assist.seen')), notes), tips,
      h('p.bps-disclaimer', t('advice.disclaimer'), ' ', t('ui.s2.disclaimer')),
      h('p.bps-nextlink', h('button.bps-btn.bps-btn--link', { type: 'button', onclick: () => ctx.openPanel('size', { focus: true }) }, t('ui.s2.next'), icon('arrow')))));

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
        // a 2.5× detail of a characteristic spot: thread, weave and print look different at a glance (critique-2 #6)
        const cv = thumb(ctx, job.s, job.d, job.id, {}, THUMB_PX, { zoom: 2.5, zoomCenterMm: detailMm(job.s, job.d) });
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
    if (!q || tech.mode === 'quote' || q.mode === 'quote' || !(q.totalIncl > 0)) {
      // no fixed price: an indicative range when the settings give one, else an honest "price with your proof"
      // (never "Aanrader" next to a bare "Prijs op aanvraag", critique-2 #11)
      const ip = tech.indicativePrice;
      if (ip && ip.from > 0 && ip.to >= ip.from) return h('span.bps-card__price.is-request', t('ui.s2.priceRange', { from: ctx.money(ip.from), to: ctx.money(ip.to) }));
      return h('span.bps-card__price.is-request', t('ui.s2.priceAfterProof'));
    }
    return h('span.bps-card__price', h('strong', t('pricing.perPiece', { amount: ctx.money(q.unitIncl, q.lineExcl / q.qty) })), ' ', h('span.bps-muted', t('ui.s2.forQty', { qty: q.qty })));
  }

  function card(id, s, d, sig) {
    const v = d.advice.verdicts[id];
    if (!v) return null;
    const tech = config.techniques[id];
    const rec = d.advice.recommended === id;
    const inputId = `${ctx.uidBase}-tech-${id}`;
    const fix = (v.fixes || [])[0];
    const lead = L(tech.leadTime) || ''; // only a real lead time from the settings; no empty promise (critique-1 #12)
    const cached = thumbs.get(sig + id);
    const slot = h('span.bps-card__vis' + (cached ? '.is-ready' : ''), { dataset: { thumb: id } }, cached ? copy(cached) : h('span.bps-thumb.bps-thumb--empty', { 'aria-hidden': 'true' }));
    if (!cached && (ctx.analysis || s.texts.length)) queue.push({ sig, id, s, d });
    // hover / focus shows this technique on the big canvas (nothing is chosen until you click)
    const peek = () => { if (s.technique !== id && ctx.previewPatch) ctx.previewPatch({ technique: id }); };
    const unpeek = () => { if (ctx.previewPatch) ctx.previewPatch(null); };
    return h('div.bps-card' + (s.technique === id ? '.is-selected' : '') + '.is-' + v.status,
      { onpointerenter: (e) => { if (e.pointerType !== 'touch') peek(); }, onpointerleave: unpeek, onfocusin: peek, onfocusout: unpeek },
      h('input.bps-card__radio', { type: 'radio', id: inputId, name: ctx.uidBase + '-tech', value: id, checked: s.technique === id, 'data-fk': 'tech-' + id, onchange: () => { unpeek(); ctx.applyPatch({ technique: id, techniquePicked: true }); } }),
      h('label.bps-card__label', { for: inputId },
        slot,
        h('span.bps-card__txt',
          h('span.bps-card__top', h('span.bps-card__tech', L(tech.label)), rec ? h('span.bps-badge', t('advice.badge.recommended')) : null),
          h('span.bps-status.bps-status--' + v.status, icon(STATUS_ICON[v.status]), t('advice.status.' + v.status)),
          h('span.bps-card__head', v.headline && v.headline.text),
          h('span.bps-card__meta', priceLine(id, d), lead ? h('span.bps-card__lead', icon('clock'), lead) : null))),
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
        out.push({ k: unsure ? 'warn' : 'ok', txt: (unsure ? t('ui.s1.shapeAsk') : t('ui.s1.shapeFound', { shape: shapeName })) + (warns.some((w) => /mockup/.test(w.key || '')) ? ' ' + t('ui.a.original') : ''), acts: [act(t('ui.s1.otherShape'), () => ctx.openPanel('shape', { focus: true })), ctx.file ? act(t('ui.s1.adjust'), () => ctx.openAdjust()) : null] });
      }
      // A picture of a patch is never an error: one neutral note, never orange next to a green "cut out" (critique-1 #12).
      const mockW = warns.find((w) => /mockup/.test(w.key || ''));
      if (mockW && !a.isPatchPhoto && !(a.shape && a.shape.fillsPatch && a.shape.type && !s.shapePicked)) out.push({ k: 'info', txt: mockW.text });
      const bg = (d.analysis && d.analysis.background) || {};
      if (d.keepBg) out.push({ k: 'ok', txt: t('ui.s1.keepBg') });
      else if (bg.type === 'white' && !a.isPatchPhoto) out.push({ k: 'ok', txt: t('ui.s1.whiteBg', { fabric: L(d.fabric.label).toLowerCase() }), acts: [act(t('ui.s1.otherFabric'), () => ctx.openPanel('colors', { focus: true }))] });
      for (const w of warns) {
        if (/mockup/.test(w.key || '')) continue;
        if (/patch_photo_cut/.test(w.key || '') && a.isPatchPhoto) continue;
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

  /* ---------------- ideas: 1-2 proactive suggestions, only what is technically sound (critique-1 #11) ---------------- */
  function ideaList(s, d) {
    const out = [];
    const has = !!ctx.analysis || (s.texts || []).some((l) => l.text && l.text.trim());
    if (!has) return out;
    const v = d.advice.verdicts || {};
    const tech = config.techniques[s.technique] || {};
    const edge = d.layout.edge || {};
    const opt = (id) => (config.options || []).find((o) => o.id === id && o.enabled !== false && (o.techniques || []).includes(s.technique));
    // more relief for embroidery (3D look), when the atelier offers it
    if (tech.threadBased && s.technique === 'embroidered' && opt('extra_thick') && !s.options.includes('extra_thick') && (!v.embroidered || v.embroidered.status !== 'bad')) {
      const next = s.options.filter((x) => !['thickness_normal', 'thick'].includes(x)).concat('extra_thick');
      out.push({ id: 'relief', txt: t('ui.idea.relief'), vis: () => thumb(ctx, { ...s, options: next }, d, s.technique, { design: { ...s, options: next } }, 160),
        apply: () => ctx.applyPatch({ options: next }) });
    }
    // a gold border on a thread border (satin / merrow), if gold thread is in the palette
    const gold = (config.threads || []).find((x) => x.code === '1724') || (config.threads || []).find((x) => x.family === 'yellow' && /goud|or\b/i.test(L(x.name)));
    if (gold && (edge.style === 'satin' || edge.style === 'merrow') && d.edgeHex !== gold.hex && s.edgeColor !== gold.code) {
      out.push({ id: 'gold', txt: t('ui.idea.gold', { code: gold.code }), vis: () => thumb(ctx, s, d, s.technique, { edgeHex: gold.hex }, 160), apply: () => ctx.store.set({ edgeColor: gold.code }) });
    }
    // see it on a cap (patches are often ordered for caps too)
    if ((s.mockup || 'none') === 'none' && ctx.preview && config.features && config.features.garmentView !== false) {
      out.push({ id: 'cap', txt: t('ui.idea.cap'), icon: 'image', apply: () => ctx.store.set({ mockup: 'cap_front' }) });
    }
    // another technique only when it is a real step up: never the twin of the current one (printed ↔ sublimated)
    // and never one that looks cheaper than what you have (critique-2 #11)
    const LOOK = { sublimated: 1, printed: 1, woven: 2, embroidered: 3, chenille: 3, pvc: 3, leather: 3 };
    const twin = (a, b) => LOOK[a] === 1 && LOOK[b] === 1;
    const alt = (d.advice.ranking || []).find((id) => id !== s.technique && !twin(id, s.technique) && (LOOK[id] || 0) > (LOOK[s.technique] || 0)
      && v[id] && v[id].status === 'ok' && config.techniques[id] && config.techniques[id].enabled);
    if (alt) out.push({ id: 'tech-' + alt, txt: t('ui.idea.tech', { tech: L(config.techniques[alt].label).toLowerCase() }), why: v[alt].headline && v[alt].headline.text,
      vis: () => thumb(ctx, s, d, alt, {}, 160), apply: () => ctx.applyPatch({ technique: alt, techniquePicked: true }) });
    return out.filter((x) => !dismissed.has(x.id)).slice(0, 2);
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
      keyed(notes, JSON.stringify(nl.map((n) => n.k + n.txt)), () => nl.map((n) => h('li.bps-note.bps-note--' + n.k, icon(n.k === 'warn' ? 'warn' : n.k === 'info' ? 'info' : 'ok'), h('span.bps-note__txt', n.txt, (n.acts || []).filter(Boolean).length ? h('span.bps-note__acts', n.acts.filter(Boolean)) : null))));
      notes.parentNode.hidden = !nl.length;

      const ranking = adv.ranking || [];
      // Phone: one horizontal row with every technique (swipe); desktop: top 4 + "show all".
      const mob = ctx.isMobile && ctx.isMobile();
      const top = mob ? ranking.slice() : ranking.slice(0, 4);
      if (!top.includes(s.technique) && ranking.includes(s.technique)) top[3] = s.technique;
      const rest = ranking.filter((id) => !top.includes(id));
      const sig = thumbSig(s, d);
      if (sig !== sigNow) { sigNow = sig; queue = []; if (thumbs.size > 40) thumbs.clear(); }
      const csig = JSON.stringify([sig, mob, s.technique, ranking, ctx.ui.showAllTech, Object.values(adv.verdicts).map((v) => [v.status, v.headline && v.headline.text, (v.fixes[0] || {}).msg && v.fixes[0].msg.text]),
        Object.values(d.quotes).map((q) => q.totalIncl), ctx.ui.exclVat]);
      const before = queue.length;
      keyed(cards, csig, () => top.map((id) => card(id, s, d, sig)));
      keyed(more, csig + '|m', () => (ctx.ui.showAllTech ? rest.map((id) => card(id, s, d, sig)) : []));
      if (queue.length !== before || queue.length) pump();
      more.hidden = !ctx.ui.showAllTech;
      moreBtn.hidden = !rest.length;
      moreBtn.setAttribute('aria-expanded', String(!!ctx.ui.showAllTech));
      text(moreBtn, ctx.ui.showAllTech ? t('ui.s2.showLess') : t('ui.s2.showAll', { n: ranking.length }));

      const il = ideaList(s, d);
      const ik = JSON.stringify([il.map((x) => x.id + x.txt), ctx.analysis ? ctx.analysis.job : 0, s.technique, s.options, d.edgeHex, d.fabric.id, s.shape, s.texts.length, ctx.ui.exclVat]);
      keyed(ideas, ik, () => (il.length ? [
        h('h3.bps-h3.bps-ideas__title', icon('spark'), t('ui.idea.title')),
        h('ul.bps-ideas__list', il.map((x) => h('li.bps-idea',
          (() => { const sp = h('span.bps-idea__vis', x.vis ? null : icon(x.icon || 'spark')); if (x.vis) idle(() => { try { sp.replaceChildren(x.vis()); } catch (e) { /* ignore */ } }); return sp; })(),
          h('span.bps-idea__txt', h('strong', x.txt), x.why ? h('span.bps-idea__why', x.why) : null,
            h('span.bps-idea__acts',
              h('button.bps-btn.bps-btn--small.bps-btn--secondary', { type: 'button', 'data-idea': x.id, onclick: () => { x.apply(); ctx.announce(t('ui.idea.done')); } }, t('ui.idea.apply')),
              h('button.bps-btn.bps-btn--small.bps-btn--link', { type: 'button', onclick: () => { dismissed.add(x.id); ctx.refresh(); } }, t('ui.idea.skip')))))))] : []));
      ideas.hidden = !il.length;

      keyed(tips, JSON.stringify((adv.upsells || []).map((u) => u.msg.text)), () => ((adv.upsells || []).length
        ? [h('h3.bps-h3', t('ui.s2.upsells')), h('ul.bps-notes', adv.upsells.map((u) => h('li.bps-note.bps-note--tip', icon('spark'), h('span.bps-note__txt', u.msg.text,
          u.action && u.action.type === 'set' ? [' ', h('button.bps-btn.bps-btn--small.bps-btn--link', { type: 'button', onclick: () => ctx.applyFix(u.action) }, t('ui.assist.apply'))] : null))))]
        : []));
    },
  };
}
