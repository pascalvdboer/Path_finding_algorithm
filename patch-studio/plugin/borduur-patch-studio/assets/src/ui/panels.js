/**
 * Patch Studio UI — tool panels of the studio (left dock on desktop, bottom sheet on phones):
 * Ontwerp, Tekst, Vorm, Rand, Kleuren, Achterkant, Maat & aantal. Every control edits the store directly, so the
 * canvas shows each change at once. Panels end with one "next" hint for customers who like to be guided.
 */
import { optionsFor } from '../shared/config.js';
import { optionEffect, savingHint } from '../pricing/index.js';
import { threadLabel, qualityLabel, colorDisclaimer, describeMerges, describeFabric } from '../colors/index.js';
import { h, text, keyed, icon, uid } from './dom.js';
import { SAMPLES, sampleFile, sampleThumb } from './samples.js';
import { reconcileOptions, normalizeQty } from './state.js';
import { textWidthMm } from './stage.js';
import { thumb } from './assistant.js';

export const ACCEPT = 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml,application/pdf,.ai,.eps,.psd';
const USE_CASES = ['uniform', 'club', 'airsoft', 'fashion', 'bag'];

/** File input wrapped in a label (real input, keyboard + screen reader friendly). */
export function fileLabel(ctx, cls, kids) {
  const id = uid('bps-file');
  const input = h('input.bps-file', { id, type: 'file', accept: ACCEPT, onchange: (e) => { const f = e.target.files && e.target.files[0]; if (f) ctx.handleFile(f); e.target.value = ''; } });
  return h('label' + cls, { for: id }, kids, input);
}

/** Drag & drop onto any element. */
export function dropTarget(ctx, el) {
  ['dragenter', 'dragover'].forEach((n) => el.addEventListener(n, (e) => { e.preventDefault(); el.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach((n) => el.addEventListener(n, () => el.classList.remove('is-over')));
  el.addEventListener('drop', (e) => {
    e.preventDefault();
    const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) ctx.handleFile(f);
  });
  return el;
}

/* ------------------------------ empty state ------------------------------ */

export function emptyState(ctx) {
  const { t, config } = ctx;
  const mb = (config.analysis && config.analysis.maxFileMb) || 25;
  const c = config.contact || {};
  const drop = dropTarget(ctx, fileLabel(ctx, '.bps-drop', [
    h('span.bps-drop__ico', icon('upload')),
    h('span.bps-drop__main', h('strong', t('ui.empty.drop')), ' ', t('ui.empty.or'), ' ', h('span.bps-drop__link', t('ui.empty.choose'))),
    h('span.bps-drop__hint', t('ui.empty.hint', { mb })),
  ]));
  const showcase = h('div.bps-hero__show', { 'aria-hidden': 'true' },
    SAMPLES.map((s, i) => h('span.bps-hero__patch.bps-hero__patch--' + (i + 1), h('img', { src: sampleThumb(s), alt: '', width: 160, height: 160 }))));
  const el = h('section.bps-empty.bps-hero',
    h('div.bps-hero__copy',
      h('p.bps-hero__kicker', icon('spark'), t('ui.empty.kicker')),
      h('h2.bps-h2.bps-empty__title', { tabindex: '-1' }, t('ui.empty.title')),
      h('p.bps-lead', t('ui.empty.subtitle')),
      drop,
      h('div.bps-hero__alt',
        h('button.bps-btn.bps-btn--ghost', { type: 'button', 'data-bps-action': 'text-only', onclick: () => ctx.startText() }, icon('type'), t('ui.empty.textOnly'))),
      h('div.bps-samples',
        h('p.bps-samples__label', t('ui.empty.samples')),
        h('ul.bps-samples__list', SAMPLES.map((s, i) => h('li', h('button.bps-sample', {
          type: 'button',
          'aria-label': t('ui.empty.sample', { name: t('ui.sample.' + (i + 1)) }),
          onclick: () => ctx.handleFile(sampleFile(s), { sample: true }),
        }, h('img', { src: sampleThumb(s), alt: '', width: 48, height: 48, loading: 'lazy' }), h('span', t('ui.sample.' + (i + 1)))))))),
      h('ul.bps-promises', [t('ui.empty.promise1'), t('ui.empty.promise2'), t('ui.empty.promise3', { city: c.city || 'Kalmthout' })].map((s) => h('li', icon('ok', 'bps-ico--ok'), s))),
      h('p.bps-privacy', t('ui.empty.privacy'), ' ', c.privacyUrl ? h('a', { href: c.privacyUrl }, t('ui.empty.privacyLink')) : null)),
    showcase);
  return { el, update() {} };
}

/* ------------------------------ helpers ------------------------------ */

function panel(ctx, id, title, body, nextKey) {
  const { t } = ctx;
  const NEXT = { advice: 'ui.s1.next', size: 'ui.s2.next', edge: 'ui.s3.next', checkout: 'ui.s4.next' };
  const nav = nextKey ? h('div.bps-step__nav',
    h('button.bps-btn.bps-btn--primary.bps-btn--block', { type: 'button', onclick: () => ctx.openPanel(nextKey, { focus: true }) }, t(NEXT[nextKey]), icon('arrow'))) : null;
  const el = h('section.bps-panel.bps-step', { dataset: { panel: id }, 'aria-labelledby': ctx.uidBase + '-p-' + id, hidden: true },
    h('header.bps-panel__head', h('h2.bps-panel__title', { id: ctx.uidBase + '-p-' + id, tabindex: '-1' }, title),
      h('button.bps-ibtn.bps-panel__close', { type: 'button', 'aria-label': t('ui.panel.close'), onclick: () => ctx.closePanel(id) }, icon('bad'))),
    h('div.bps-panel__body', body), nav);
  return el;
}

const field = (lbl, forId, input, cls = '') => h('div.bps-field' + cls, h('label', { for: forId }, lbl), input);

/** Radio tiles inside a fieldset (keyboard: arrow keys, native). */
function tiles(name, items, cur, onPick, cls = '') {
  return h('div.bps-tiles' + cls, items.map((it) => h('label.bps-tile' + (it.id === cur ? '.is-on' : ''),
    h('input', { type: 'radio', name, value: it.id, checked: it.id === cur, 'data-fk': name + '-' + it.id, onchange: () => onPick(it.id) }),
    it.vis || null, h('span.bps-tile__txt', h('span.bps-tile__name', it.label), it.sub ? h('span.bps-tile__sub', it.sub) : null))));
}

const SHAPE_SVG = {
  circle: '<circle cx="20" cy="20" r="15"/>',
  oval: '<ellipse cx="20" cy="20" rx="17" ry="12"/>',
  rect: '<rect x="4" y="9" width="32" height="22" rx="1.5"/>',
  rounded: '<rect x="4" y="9" width="32" height="22" rx="7"/>',
  shield: '<path d="M20 4l14 5v11c0 9-6 14-14 17C12 34 6 29 6 20V9z"/>',
  contour: '<path d="M10 28c-5-4-4-12 2-14 1-6 9-9 13-4 6-2 11 4 8 10 4 4 0 11-6 10-3 4-11 4-13-1-2 1-3 0-4-1z"/>',
};
function shapeIcon(id) {
  const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  s.setAttribute('viewBox', '0 0 40 40');
  s.setAttribute('aria-hidden', 'true');
  s.setAttribute('class', 'bps-shapeico');
  s.innerHTML = SHAPE_SVG[id] || SHAPE_SVG.rounded;
  return s;
}

/* ------------------------------ panels ------------------------------ */

export function createPanels(ctx) {
  const { t, config, store } = ctx;
  const L = (o) => ctx.L(o);
  const P = {};

  /* ----- Ontwerp ----- */
  {
    const found = h('ul.bps-found', { 'aria-label': t('ui.s1.found') });
    const status = h('div.bps-srcnote');
    const scaleMin = Math.round(((config.layout && config.layout.artScaleMin) || 0.2) * 100);
    const scaleMax = Math.round(((config.layout && config.layout.artScaleMax) || 2) * 100);
    const scaleOut = h('output.bps-range__out');
    const scale = h('input.bps-range', {
      type: 'range', min: scaleMin, max: scaleMax, step: 5, id: uid('bps-scale'),
      oninput: (e) => { const s = store.get(); ctx.setDragTarget('art'); store.set({ art: { ...s.art, scale: Number(e.target.value) / 100 } }); },
    });
    const arrange = h('div.bps-group',
      h('h3.bps-h3', t('ui.s1.arrange')),
      h('div.bps-range-row', h('label', { for: scale.id }, t('ui.s1.size')), scale, scaleOut),
      h('div.bps-btnrow',
        h('button.bps-btn.bps-btn--small.bps-btn--ghost', { type: 'button', onclick: () => { const s = store.get(); store.set({ art: { ...s.art, offsetMm: { x: 0, y: 0 } } }); } }, icon('center'), t('ui.s1.center')),
        h('button.bps-btn.bps-btn--small.bps-btn--ghost', { type: 'button', onclick: () => { const s = store.get(); store.set({ art: { ...s.art, scale: 1, offsetMm: { x: 0, y: 0 } } }); } }, icon('fit'), t('ui.s1.fit'))),
      h('p.bps-help', t('ui.s1.arrangeHint')));
    const adjustBtn = h('button.bps-btn.bps-btn--small.bps-btn--ghost', { type: 'button', onclick: () => ctx.openAdjust() }, icon('scissors'), t('ui.s1.adjust'));
    const replace = dropTarget(ctx, fileLabel(ctx, '.bps-replace', [h('span.bps-replace__ico', icon('upload')), h('span.bps-replace__txt', h('strong.bps-replace__name'), h('span.bps-replace__sub', t('ui.s1.newFile')))]));
    const el = panel(ctx, 'design', t('ui.panel.design'), [replace, status, found, h('div.bps-btnrow.bps-adjustrow', adjustBtn), arrange,
      h('div.bps-group.bps-group--soft', h('button.bps-btn.bps-btn--ghost.bps-btn--block', { type: 'button', onclick: () => { ctx.openPanel('text'); ctx.addText(); } }, icon('type'), t('ui.text.add')))], 'advice');

    function foundLines(s, d) {
      const a = ctx.analysis, st = ctx.analysisState, lines = [];
      if (st.status === 'running') {
        const order = ['decode', 'background', 'shape', 'colors', 'details', 'preview'];
        const cur = order.indexOf(st.stage || 'decode');
        order.forEach((k, i) => { if (i <= cur) lines.push({ text: t('analysis.stage.' + k), done: i < cur }); });
        return lines;
      }
      if (!a) return lines;
      const bg = a.background || {};
      if (a.isPatchPhoto) lines.push({ text: t('analysis.found.cut'), done: true });
      else if (bg.type) lines.push({ text: t('analysis.found.background.' + (bg.type === 'solid' && !bg.keep ? 'solid_dropped' : bg.type)), done: true });
      const sh = a.shape || {};
      if (sh.type && sh.fillsPatch) lines.push({ text: t((sh.confidence || 0) < ((config.analysis && config.analysis.shapeMinConfidence) || 0.75) ? 'analysis.found.shape.uncertain' : 'analysis.found.shape.' + sh.type), done: true });
      const n = (a.palette || []).length;
      if (n) lines.push({ text: t('analysis.found.colors', { n }), done: true });
      if (a.kind) lines.push({ text: t('ui.s1.kind.' + a.kind), done: true });
      const m = d.meas || {};
      const fmt = (x) => ctx.n(Math.floor(x * 10) / 10, 1);
      if (m.fineLineMm != null && m.minTextMm != null) lines.push({ text: t('ui.s1.measure', { line: fmt(m.fineLineMm), text: fmt(m.minTextMm), size: ctx.n(s.sizeCm) }), done: true });
      else if (m.fineLineMm != null) lines.push({ text: t('ui.s1.measureLine', { line: fmt(m.fineLineMm), size: ctx.n(s.sizeCm) }), done: true });
      return lines;
    }

    P.design = {
      el,
      update(s, d) {
        const st = ctx.analysisState;
        text(replace.querySelector('.bps-replace__name'), s.source.kind === 'text' ? t('ui.s5.textOnly') : s.source.fileName || t('ui.empty.choose'));
        const lines = foundLines(s, d);
        keyed(found, JSON.stringify(lines), () => lines.map((l) => h('li.bps-found__item' + (l.done ? '.is-done' : '.is-busy'), l.done ? icon('ok', 'bps-ico--ok') : h('span.bps-spinner', { 'aria-hidden': 'true' }), l.text)));
        found.hidden = !lines.length;
        const msg = st.status === 'unsupported' || st.status === 'error' ? st.message : s.source.kind === 'text' && !ctx.analysis ? t('ui.s1.textOnlyNote') : '';
        keyed(status, msg, () => (msg ? h('p.bps-notice.bps-notice--info', icon('info'), h('span', msg)) : []));
        adjustBtn.parentNode.hidden = !(ctx.analysis && ctx.file);
        arrange.hidden = !ctx.analysis;
        const pct = Math.round(s.art.scale * 100);
        if (document.activeElement !== scale) scale.value = pct;
        scale.setAttribute('aria-valuetext', t('ui.s1.sizeValue', { n: pct }));
        text(scaleOut, pct + ' %');
      },
    };
  }

  /* ----- Tekst ----- */
  {
    const list = h('div.bps-texts__list');
    const addBtn = h('button.bps-btn.bps-btn--secondary.bps-btn--block', { type: 'button', onclick: () => ctx.addText() }, icon('plus'), t('ui.text.add').replace(/^\+\s*/, ''));
    const max = h('p.bps-help', { hidden: true });
    const el = panel(ctx, 'text', t('ui.panel.text'), [h('section.bps-texts', list, addBtn, max, h('p.bps-help', t('ui.text.dragHint')))], 'advice');

    function fit(l, Lp) {
      if (!Lp || !l.text || !l.text.trim()) return null;
      const w = textWidthMm(config, l);
      const edge = (Lp.edge && Lp.edge.widthMm) || 0;
      const round = Lp.shape === 'circle' || Lp.shape === 'oval';
      let room;
      if (l.path !== 'straight' && round) room = Math.PI * (Math.min(Lp.patchWmm, Lp.patchHmm) / 2 - edge - Math.max(l.insetMm || 0, 0) - l.heightMm) * 0.9;
      else if (l.path === 'straight' && round) {
        const r = Math.min(Lp.patchWmm, Lp.patchHmm) / 2 - edge - 1, y = Math.abs(l.offsetMm.y) + l.heightMm / 2;
        room = 2 * Math.sqrt(Math.max(0, r * r - y * y));
      } else room = (Lp.patchWmm - 2 * (edge + 1)) * (Lp.shape === 'shield' && l.path === 'arc_bottom' ? 0.65 : 1);
      return w > room + 0.5 ? { w, room: Math.max(0, room) } : null;
    }

    function layer(l, i) {
      const T = config.text || {};
      const set = (patch) => store.set({ texts: store.get().texts.map((x) => (x.id === l.id ? { ...x, ...patch } : x)) });
      const id = (k) => `${ctx.uidBase}-${l.id}-${k}`;
      const fonts = config.fonts || [];
      const threads = config.threads || [];
      const arc = l.path !== 'straight';
      const fontName = id('font');
      return h('fieldset.bps-fieldset.bps-layer', { dataset: { layer: l.id }, onfocusin: () => ctx.setDragTarget(l.id), onclick: () => ctx.setDragTarget(l.id) },
        h('legend.bps-legend', t('ui.text.layer', { n: i + 1 })),
        h('div.bps-layer__top',
          h('label.bps-sr', { for: id('text') }, t('ui.text.value')),
          h('input.bps-input.bps-layer__input', { id: id('text'), 'data-fk': id('text'), type: 'text', maxlength: T.maxChars || 60, value: l.text, autocomplete: 'off', oninput: (e) => set({ text: e.target.value.slice(0, T.maxChars || 60) }) }),
          h('button.bps-ibtn', { type: 'button', 'aria-label': t('ui.text.remove', { text: l.text || '…' }), title: t('ui.text.removeShort'), onclick: () => ctx.removeText(l.id) }, icon('trash'))),
        h('fieldset.bps-fieldset.bps-fieldset--inline', h('legend.bps-legend.bps-legend--small', t('ui.text.font')),
          h('div.bps-fonts', fonts.map((f) => h('label.bps-font' + (f.id === l.font ? '.is-on' : ''),
            h('input', { type: 'radio', name: fontName, value: f.id, checked: f.id === l.font, 'data-fk': id('f-' + f.id), onchange: () => { set({ font: f.id }); ctx.loadFont(f.id); } }),
            h('span.bps-font__sample', { style: { fontFamily: `"${f.id}", ${f.fallback || 'sans-serif'}` }, 'aria-hidden': 'true' }, 'Aa'),
            h('span.bps-font__name', (L(f.label) || f.id).replace(/\s*\(.*\)$/, '')))))),
        h('div.bps-field-row',
          field(t('ui.text.height'), id('height'), h('input.bps-input', { id: id('height'), 'data-fk': id('height'), type: 'number', inputmode: 'decimal', min: T.minHeightMm || 3, max: T.maxHeightMm || 40, step: 0.5, value: l.heightMm,
            onchange: (e) => { const v = Math.min(T.maxHeightMm || 40, Math.max(T.minHeightMm || 3, Number(String(e.target.value).replace(',', '.')) || 6)); e.target.value = v; set({ heightMm: v }); } })),
          field(t('ui.text.color'), id('color'), h('select.bps-select', { id: id('color'), 'data-fk': id('color'), onchange: (e) => set({ threadCode: e.target.value }) },
            h('option', { value: 'auto', selected: l.threadCode === 'auto' }, t('ui.text.auto')),
            threads.map((th) => h('option', { value: th.code, selected: th.code === l.threadCode }, `${L(th.name)} (${th.code})`))))),
        h('fieldset.bps-fieldset.bps-fieldset--inline', h('legend.bps-legend.bps-legend--small', t('ui.text.path')),
          h('div.bps-seg', ['straight', 'arc_top', 'arc_bottom'].map((p) => h('label.bps-seg__opt' + (l.path === p ? '.is-on' : ''),
            h('input', { type: 'radio', name: id('path'), value: p, checked: l.path === p, 'data-fk': id('p-' + p), onchange: () => set({ path: p, angleDeg: 0, insetMm: 0, offsetMm: { x: 0, y: 0 } }) }),
            h('span', t('ui.text.path.' + p)))))),
        arc ? h('div.bps-range-row', h('label', { for: id('angle') }, t('ui.text.angle')),
          h('input.bps-range', { id: id('angle'), 'data-fk': id('angle'), type: 'range', min: -180, max: 180, step: 1, value: l.angleDeg, 'aria-valuetext': `${l.angleDeg}°`, oninput: (e) => set({ angleDeg: Number(e.target.value) }) })) : null,
        arc ? h('div.bps-range-row', h('label', { for: id('inset') }, t('ui.text.inset')),
          h('input.bps-range', { id: id('inset'), 'data-fk': id('inset'), type: 'range', min: 0, max: 30, step: 0.5, value: l.insetMm, 'aria-valuetext': `${String(l.insetMm).replace('.', ',')} mm`, oninput: (e) => set({ insetMm: Number(e.target.value) }) })) : null,
        h('p.bps-help.bps-layer__fit', { role: 'status', hidden: true }));
    }

    P.text = {
      el,
      update(s, d) {
        const n = (config.text && config.text.maxLayers) || 10;
        keyed(list, s.texts.map((l) => l.id + l.path + l.font).join('|'), () => (s.texts.length ? s.texts.map((l, i) => layer(l, i)) : h('p.bps-empty-note', t('ui.text.none'))));
        for (const l of s.texts) {
          const box = list.querySelector(`[data-layer="${l.id}"]`);
          if (!box) continue;
          const inp = box.querySelector('.bps-layer__input');
          if (inp && document.activeElement !== inp && inp.value !== l.text) inp.value = l.text;
          const hgt = box.querySelector('input[type=number]');
          if (hgt && document.activeElement !== hgt) hgt.value = l.heightMm;
          const ang = box.querySelector('input[id$="-angle"]');
          if (ang && document.activeElement !== ang) { ang.value = l.angleDeg; ang.setAttribute('aria-valuetext', `${l.angleDeg}°`); }
          const ins = box.querySelector('input[id$="-inset"]');
          if (ins && document.activeElement !== ins) { ins.value = l.insetMm; ins.setAttribute('aria-valuetext', `${String(l.insetMm).replace('.', ',')} mm`); }
          box.classList.toggle('is-active', ctx.dragTarget === l.id);
          // Eigenaar: alles blijft binnen de rand. Te lange tekst: eerlijk zeggen in plaats van stil bij te snijden.
          const f = box.querySelector('.bps-layer__fit');
          const tl = fit(l, d.layout);
          f.hidden = !tl;
          text(f, tl ? t('ui.text.tooLong', { w: ctx.n(tl.w, 0), room: ctx.n(tl.room, 0) }) : '');
        }
        addBtn.hidden = s.texts.length >= n;
        max.hidden = s.texts.length < n;
        text(max, t('ui.text.max', { n }));
      },
    };
  }

  /* ----- Vorm ----- */
  {
    const box = h('div');
    const note = h('p.bps-help');
    const el = panel(ctx, 'shape', t('ui.panel.shape'), [h('fieldset.bps-fieldset', h('legend.bps-legend.bps-sr', t('ui.s1.shape')), box), note], 'advice');
    P.shape = {
      el,
      update(s) {
        keyed(box, s.shape, () => tiles(ctx.uidBase + '-shape', (config.shapeOrder || Object.keys(config.shapes)).map((id) => ({ id, label: L(config.shapes[id].label), vis: shapeIcon(id) })), s.shape, (id) => ctx.setShape(id), '.bps-tiles--shape'));
        const a = ctx.analysis;
        text(note, a && a.shape && a.shape.fillsPatch && !s.shapePicked ? t('ui.shape.auto') : t('ui.shape.help'));
      },
    };
  }

  /* ----- option groups (Rand / Achterkant) ----- */
  function choose(gid, id, on) {
    const s = store.get();
    const g = config.optionGroups[gid];
    let opts = s.options.slice();
    if (g.type === 'radio') opts = opts.filter((x) => (config.options.find((o) => o.id === x) || {}).group !== gid).concat(id);
    else opts = on ? opts.concat(id) : opts.filter((x) => x !== id);
    store.set({ options: reconcileOptions(config, s.technique, opts) });
  }
  function group(gid, g, s, { legend = true } = {}) {
    const opts = optionsFor(config, s.technique).filter((o) => o.group === gid);
    if (!opts.length) return null;
    if (g.showIfAny && !g.showIfAny.some((id) => s.options.includes(id))) return null;
    const name = `${ctx.uidBase}-opt-${gid}`;
    return h('fieldset.bps-fieldset.bps-group', h('legend.bps-legend' + (legend ? '' : '.bps-sr'), L(g.label)),
      h('div.bps-optlist', opts.map((o) => {
        const id = `${name}-${o.id}`;
        const eff = optionEffect(o, s.technique, config, ctx.locale, ctx.overrides);
        const help = L(o.help);
        const on = s.options.includes(o.id);
        return h('div.bps-opt' + (on ? '.is-on' : ''),
          h('input', { type: g.type === 'radio' ? 'radio' : 'checkbox', id, name, value: o.id, checked: on, 'data-fk': id, 'aria-describedby': help ? id + '-h' : null, onchange: (e) => choose(gid, o.id, e.target.checked) }),
          h('label', { for: id }, h('span.bps-opt__label', L(o.label)), h('span.bps-opt__eff' + (eff.amountIncl < 0 ? '.is-minus' : ''), eff.text)),
          help ? h('p.bps-opt__help', { id: id + '-h' }, help) : null);
      })),
      gid === 'backing' ? h('p.bps-help', icon('info'), t('ui.s4.backingTip')) : null);
  }

  /* ----- Rand ----- */
  {
    const groups = h('div');
    const edgeId = uid('bps-edge');
    const edgeSel = h('select.bps-select', { id: edgeId, onchange: (e) => store.set({ edgeColor: e.target.value }) });
    const edgeField = field(t('ui.s4.edgeColor'), edgeId, h('div.bps-colorpick', h('span.bps-colorpick__chip', { 'aria-hidden': 'true' }), edgeSel));
    const el = panel(ctx, 'edge', t('ui.panel.edge'), [groups, edgeField], 'checkout');
    P.edge = {
      el,
      update(s, d) {
        keyed(groups, JSON.stringify([s.technique, s.options, ctx.ui.exclVat]), () => (group('edge', config.optionGroups.edge, s, { legend: false }) || h('p.bps-help', t('ui.edge.none'))));
        const style = d.layout.edge && d.layout.edge.style;
        edgeField.hidden = !(style === 'satin' || style === 'merrow');
        keyed(edgeSel, JSON.stringify([s.edgeColor, d.autoEdge && d.autoEdge.code]), () => [
          h('option', { value: 'auto', selected: s.edgeColor === 'auto' }, t('ui.s4.edgeAuto', { name: d.autoEdge ? L(d.autoEdge.name).toLowerCase() : '' })),
          (config.threads || []).map((th) => h('option', { value: th.code, selected: th.code === s.edgeColor }, `${L(th.name)} (${th.code})`)),
        ]);
        edgeField.querySelector('.bps-colorpick__chip').style.background = d.edgeHex;
      },
    };
  }

  /* ----- Achterkant ----- */
  {
    const groups = h('div');
    const adv = h('div');
    const more = h('details.bps-more', h('summary', t('ui.s4.more')), adv);
    const useId = uid('bps-use');
    const useSel = h('select.bps-select', { id: useId, onchange: (e) => store.set({ useCase: e.target.value }) },
      h('option', { value: '' }, t('ui.s4.useCase.none')), USE_CASES.map((u) => h('option', { value: u }, t('ui.s4.useCase.' + u))));
    const el = panel(ctx, 'back', t('ui.panel.back'), [groups, more, field(t('ui.s4.useCase'), useId, useSel)], 'checkout');
    P.back = {
      el,
      update(s) {
        const all = Object.entries(config.optionGroups || {}).filter(([gid]) => gid !== 'edge');
        const key = JSON.stringify([s.technique, s.options, ctx.ui.exclVat]);
        keyed(groups, key, () => all.filter(([, g]) => !g.advanced).map(([gid, g]) => group(gid, g, s)));
        keyed(adv, key, () => all.filter(([, g]) => g.advanced).map(([gid, g]) => group(gid, g, s)));
        more.hidden = !adv.children.length;
        if (document.activeElement !== useSel) useSel.value = s.useCase || '';
      },
    };
  }

  /* ----- Kleuren ----- */
  {
    const swatches = h('ul.bps-swatches');
    const countOut = h('output.bps-range__out');
    const choices = (config.advice && config.advice.threadColorChoices) || [6, 8, 10, 12];
    const count = h('input.bps-range', { type: 'range', min: 0, max: choices.length - 1, step: 1, id: uid('bps-count'), oninput: (e) => store.set({ maxThreadColors: choices[Number(e.target.value)] }) });
    const countRow = h('div', h('div.bps-range-row', h('label', { for: count.id }, t('ui.s2.threadCount')), count, countOut),
      h('div.bps-ticks', { 'aria-hidden': 'true' }, choices.map((c) => h('span', String(c)))));
    const few = h('p.bps-help', { hidden: true });
    const changesBox = h('div.bps-changes', { hidden: true });
    const changesBtn = h('button.bps-btn.bps-btn--small.bps-btn--ghost', { type: 'button', 'aria-expanded': 'false', onclick: () => { ctx.ui.showChanges = !ctx.ui.showChanges; ctx.refresh(); } }, t('ui.s2.showChanges'));
    const threads = h('section.bps-threads.bps-group', h('h3.bps-h3', t('ui.s2.threads')), countRow, few, swatches, changesBtn, changesBox,
      h('p.bps-help', colorDisclaimer({ config, locale: ctx.locale })));
    const printNote = h('p.bps-notice.bps-notice--info', { hidden: true }, icon('info'), h('span', t('ui.colors.print')));
    const fabricBox = h('div');
    const fabricH = h('h3.bps-h3');
    const fabricFs = h('section.bps-group', { id: uid('bps-fabric') }, fabricH, fabricBox);
    const el = panel(ctx, 'colors', t('ui.panel.colors'), [threads, printNote, fabricFs], 'size');

    P.colors = {
      el,
      update(s, d) {
        const hasA = !!ctx.analysis;
        const show = hasA && d.useThreads && !!d.reduced;
        threads.hidden = !show;
        printNote.hidden = !(hasA && !d.useThreads);
        if (show) {
          const idx = Math.max(0, choices.indexOf(s.maxThreadColors));
          if (document.activeElement !== count) count.value = idx;
          const k = d.reduced.k;
          const isFew = (ctx.analysis.palette || []).length <= Math.min(...choices);
          countRow.hidden = isFew;
          few.hidden = !isFew;
          if (isFew) text(few, t('ui.s2.threadsFew', { n: k }));
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
              h('span.bps-swatch__txt', h('span.bps-swatch__name', threadLabel(th, { config, locale: ctx.locale })),
                h('span.bps-swatch__q', c.override ? '' : qualityLabel(c.thread && c.thread.quality, { config, locale: ctx.locale }))),
              alts.length > 1 ? h('span.bps-swatch__alt', h('label.bps-sr', { for: selId }, t('ui.s2.threadPick', { name: L(th.name) })),
                h('select.bps-select.bps-select--small', { id: selId, 'data-fk': 'alt-' + pi, onchange: (e) => store.set({ threadOverrides: { ...store.get().threadOverrides, [pi]: e.target.value } }) },
                  alts.map((a) => h('option', { value: a.code, selected: a.code === th.code }, `${L(a.name)} (${a.code})`)))) : null);
          }));
          changesBtn.setAttribute('aria-expanded', String(!!ctx.ui.showChanges));
          changesBox.hidden = !ctx.ui.showChanges;
          if (ctx.ui.showChanges && !el.hidden) {
            keyed(changesBox, [ctx.analysis.job, s.technique, s.maxThreadColors, JSON.stringify(s.threadOverrides), d.fabric.id].join('|'), () => {
              const merges = describeMerges(d.reduced, { config, locale: ctx.locale }) || [];
              return [h('div.bps-changes__pair',
                h('figure', thumb(ctx, s, d, 'printed', { reduced: false }, 200), h('figcaption', t('ui.s2.original'))),
                h('figure', thumb(ctx, s, d, s.technique, { reduced: true }, 200), h('figcaption', t('ui.s2.simplified')))),
              merges.length ? h('ul.bps-changes__list', merges.map((m) => h('li', m.text))) : null];
            });
          }
        }
        const leather = (config.techniques[s.technique] || {}).fabricList === 'leathers';
        fabricFs.hidden = (d.fabrics || []).length <= 1;
        text(fabricH, leather ? t('ui.s4.fabricLeather') : t('ui.s4.fabric'));
        const autoName = d.fabricPick ? L((d.fabrics.find((f) => f.id === d.fabricPick.id) || {}).label) : '';
        const why = d.fabricPick ? describeFabric(d.fabricPick, d.fabrics, { config, locale: ctx.locale }) : null;
        const fills = !!(ctx.analysis && ctx.analysis.shape && ctx.analysis.shape.fillsPatch && !s.shapePicked && s.art && s.art.scale >= 1);
        keyed(fabricBox, JSON.stringify([s.fabric, s.technique, autoName, d.keepBg, fills]), () => [
          h('div.bps-swatchgrid', { role: 'radiogroup', 'aria-label': leather ? t('ui.s4.fabricLeather') : t('ui.s4.fabric') },
            [{ id: 'auto', hex: d.fabricPick ? (d.fabrics.find((f) => f.id === d.fabricPick.id) || {}).hex : '#fff', auto: true }, ...d.fabrics].map((f) => {
              const id = `${ctx.uidBase}-fab-${f.id}`;
              const on = s.fabric === f.id || (f.auto && s.fabric === 'auto');
              return h('div.bps-fab' + (on ? '.is-on' : ''),
                h('input', { type: 'radio', id, name: ctx.uidBase + '-fabric', value: f.id, checked: on, 'data-fk': id, onchange: () => store.set({ fabric: f.id }) }),
                h('label', { for: id, title: f.auto ? t('ui.s4.autoFabric', { fabric: autoName.toLowerCase() }) : L(f.label) },
                  h('span.bps-fab__chip' + (f.auto ? '.is-auto' : ''), { style: { background: f.hex }, 'aria-hidden': 'true' }, f.auto ? icon('spark') : null),
                  h('span.bps-fab__name', f.auto ? t('ui.s4.auto') : L(f.label))));
            })),
          d.keepBg ? h('p.bps-help', icon('info'), t('ui.s4.bgKept')) : fills ? h('p.bps-help', icon('info'), t('ui.s4.artFills')) : why && s.fabric === 'auto' ? h('p.bps-help', icon('info'), why.text) : null,
        ]);
      },
    };
  }

  /* ----- Maat & aantal ----- */
  {
    const sz = config.sizes || {};
    const priced = sz.pricedCm || [4, 6, 8, 10, 12];
    const sizeBox = h('div');
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
    const ladder = h('div.bps-ladder');
    const saving = h('div.bps-saving');
    const shipHint = h('p.bps-help');
    const poolHint = h('p.bps-help', { hidden: true });
    const adjusted = h('p.bps-notice.bps-notice--info', { hidden: true, role: 'status' });
    const qty = ctx.qtyControl('panel');
    const el = panel(ctx, 'size', t('ui.panel.size'), [
      h('fieldset.bps-fieldset.bps-group', h('legend.bps-legend', t('ui.s3.size')), h('p.bps-help', t('ui.s3.sizeHelp')), sizeBox,
        h('div.bps-field.bps-field--inline', h('label', { for: otherId }, t('ui.s3.otherSizeLabel')), other), dims, hint, quoteNote),
      h('fieldset.bps-fieldset.bps-group', h('legend.bps-legend', t('ui.s3.qty')), qty.el, adjusted, h('p.bps-help', t('ui.s3.fromOne')), poolHint),
      ladder, saving, shipHint, ctx.priceCard.el], 'edge');

    P.size = {
      el,
      update(s, d) {
        const tech = config.techniques[s.technique] || {};
        keyed(sizeBox, s.sizeCm + '|' + s.technique, () => h('div.bps-chips', priced.map((cm) => h('label.bps-chip',
          h('input', { type: 'radio', name: ctx.uidBase + '-size', value: cm, checked: s.sizeCm === cm, 'data-fk': 'size-' + cm, onchange: () => store.set({ sizeCm: cm, sizePicked: true }) }),
          h('span', t('ui.s3.sizeCm', { n: ctx.n(cm) }))))));
        if (document.activeElement !== other) other.value = priced.includes(s.sizeCm) ? '' : s.sizeCm;
        text(dims, t('ui.s3.dims', { w: Math.round(d.layout.patchWmm), h: Math.round(d.layout.patchHmm) }));
        const hh = (sz.hints || []).find((x) => s.sizeCm <= x.upToCm);
        text(hint, hh ? t('ui.s3.hint', { hint: L(hh.label) }) : '');
        quoteNote.hidden = !(s.sizeCm > (tech.maxOrderCm || sz.maxOrderCm || 12));
        text(quoteNote, t('ui.s3.quoteSize'));
        qty.update(s, d);
        const adj = ctx.ui.qtyAdjustedFrom != null && ((tech.minQty || 1) > 1 || (tech.qtyStep || 1) > 1);
        adjusted.hidden = !adj;
        if (adj) text(adjusted, t('ui.s3.qtyAdjusted', { tech: L(tech.label), min: tech.minQty || 1, step: tech.qtyStep || 1, qty: s.qty }));
        const q = d.quote;
        const lad = q && q.ok && tech.tiers && q.ladder && q.ladder.length > 1 ? q.ladder : null;
        keyed(ladder, JSON.stringify([lad && lad.map((r) => [r.min, r.unitIncl, r.current]), ctx.ui.exclVat]), () => (lad
          ? [h('h3.bps-h3', t('ui.s3.ladder')), h('ol.bps-ladder__list', lad.map((r) => h('li.bps-ladder__row' + (r.current ? '.is-current' : ''), r.current ? { 'aria-current': 'true' } : null,
            h('button.bps-ladder__btn', { type: 'button', 'aria-label': t('ui.ladder.pick', { min: r.min }), onclick: () => ctx.setQty(Math.max(r.min, normalizeQty(config, s.technique, r.min))) },
              h('span.bps-ladder__min', t('ui.s3.ladderRow', { min: r.min })),
              h('span.bps-ladder__unit', ctx.money(r.unitIncl, r.unitIncl / (1 + config.vat.rate))),
              r.discount ? h('span.bps-ladder__pct', t('ui.s3.ladderDiscount', { pct: Math.round(r.discount * 100) })) : h('span.bps-ladder__pct'),
              r.current ? h('span.bps-ladder__you', t('ui.s3.ladderCurrent')) : null))))]
          : []));
        const sh = q && q.ok && tech.tiers ? savingHint(q, ctx.locale, ctx.overrides) : null;
        keyed(saving, sh ? sh.main.text + (sh.secondary ? sh.secondary.text : '') : '', () => (sh
          ? h('div.bps-notice.bps-notice--info', icon('info'), h('div.bps-notice__body',
            h('p', sh.main.text, ' ', h('button.bps-btn.bps-btn--small.bps-btn--link', { type: 'button', onclick: () => ctx.setQty(sh.nextTier.qty) }, String(sh.nextTier.qty) + ' ' + t('ui.s3.qty').toLowerCase())),
            sh.extra ? h('p', sh.extra.text) : null, sh.secondary ? h('p.bps-muted', sh.secondary.text) : null))
          : []));
        const ship = q && q.shipping;
        const articles = q ? q.lineIncl + (s.otherItemsInclVat || 0) : 0;
        const showShip = ship && !ship.free && ship.remainingForFreeInclVat != null && articles >= ((config.shipping && config.shipping.hintFromInclVat) || Infinity);
        text(shipHint, showShip ? t('pricing.shippingRemaining', { amount: ctx.fmt(ship.remainingForFreeInclVat) }) : '');
        poolHint.hidden = !(s.poolQty > 0 && tech.tiers);
        if (s.poolQty > 0) text(poolHint, t('ui.s3.poolHint', { n: s.poolQty }));
      },
    };
  }

  return P;
}
