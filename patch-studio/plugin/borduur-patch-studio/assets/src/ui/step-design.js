/**
 * Patch Studio UI — step 0 (empty state) and step 1 (design: analysis feedback, shape, arrange, text layers).
 */
import { h, fill, text, keyed, icon, uid } from './dom.js';
import { SAMPLES, sampleFile, sampleThumb } from './samples.js';

export const ACCEPT = 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml,application/pdf,.ai,.eps,.psd';

/** File input wrapped in a label (real input, keyboard + screen reader friendly). */
export function fileLabel(ctx, cls, kids) {
  const id = uid('bps-file');
  const input = h('input.bps-file', { id, type: 'file', accept: ACCEPT, onchange: (e) => { const f = e.target.files && e.target.files[0]; if (f) ctx.handleFile(f); e.target.value = ''; } });
  return h('label' + cls, { for: id }, kids, input);
}

export function emptyState(ctx) {
  const { t, config } = ctx;
  const mb = (config.analysis && config.analysis.maxFileMb) || 25;
  const drop = fileLabel(ctx, '.bps-drop', [
    icon('upload', 'bps-drop__ico'),
    h('span.bps-drop__main', h('strong', t('ui.empty.drop')), ' ', t('ui.empty.or'), ' ', h('span.bps-drop__link', t('ui.empty.choose'))),
    h('span.bps-drop__hint', t('ui.empty.hint', { mb })),
  ]);
  ['dragenter', 'dragover'].forEach((n) => drop.addEventListener(n, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach((n) => drop.addEventListener(n, () => drop.classList.remove('is-over')));
  drop.addEventListener('drop', (e) => {
    e.preventDefault();
    const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) ctx.handleFile(f);
  });
  const c = config.contact || {};
  const el = h('section.bps-empty', { 'aria-labelledby': 'x' },
    h('h2.bps-h2.bps-empty__title', t('ui.empty.title')),
    h('p.bps-lead', t('ui.empty.subtitle')),
    drop,
    h('p.bps-reassure', t('ui.empty.reassure')),
    h('p.bps-empty__alt', h('button.bps-btn.bps-btn--ghost', { type: 'button', onclick: () => ctx.startText() }, t('ui.empty.textOnly'))),
    h('div.bps-samples',
      h('p.bps-samples__label', t('ui.empty.samples')),
      h('ul.bps-samples__list', SAMPLES.map((s, i) => h('li', h('button.bps-sample', {
        type: 'button',
        'aria-label': t('ui.empty.sample', { name: t('ui.sample.' + (i + 1)) }),
        onclick: () => ctx.handleFile(sampleFile(s), { sample: true }),
      }, h('img', { src: sampleThumb(s), alt: '', width: 64, height: 64, loading: 'lazy' }), h('span', t('ui.sample.' + (i + 1)))))))),
    h('ul.bps-promises', [
      t('ui.empty.promise1'), t('ui.empty.promise2'), t('ui.empty.promise3', { city: c.city || 'Kalmthout' }),
    ].map((s) => h('li', icon('ok', 'bps-ico--ok'), s))),
    h('p.bps-privacy', t('ui.empty.privacy'), ' ', c.privacyUrl ? h('a', { href: c.privacyUrl }, t('ui.empty.privacyLink')) : null),
  );
  el.removeAttribute('aria-labelledby');
  return { el, update() {} };
}

/* ------------------------------------------------------------------------- */

function notice(kind, kids) {
  return h('div.bps-notice.bps-notice--' + kind, icon(kind === 'ok' ? 'ok' : kind === 'warn' ? 'warn' : 'info'), h('div.bps-notice__body', kids));
}

export function stepDesign(ctx) {
  const { t, config, store } = ctx;
  const L = (o) => ctx.L(o);
  const found = h('ul.bps-found', { 'aria-label': t('ui.s1.found') });
  const notices = h('div.bps-notices');
  const shapeBox = h('div');
  const shapeFs = h('fieldset.bps-fieldset', { id: uid('bps-shape') }, h('legend.bps-legend', t('ui.s1.shape')), shapeBox);
  const scaleMin = Math.round(((config.layout && config.layout.artScaleMin) || 0.2) * 100);
  const scaleMax = Math.round(((config.layout && config.layout.artScaleMax) || 2) * 100);
  const scaleOut = h('output.bps-range__out');
  const scale = h('input.bps-range', {
    type: 'range', min: scaleMin, max: scaleMax, step: 5, id: uid('bps-scale'),
    oninput: (e) => { const s = store.get(); store.set({ art: { ...s.art, scale: Number(e.target.value) / 100 } }); },
  });
  const nudge = (dx, dy) => (ev) => { const m = ev && ev.shiftKey ? 5 : 1; ctx.setDragTarget('art'); ctx.preview.nudge(dx * m, dy * m); };
  const ibtn = (name, lbl, fn) => h('button.bps-ibtn', { type: 'button', 'aria-label': lbl, title: lbl, onclick: fn }, icon(name));
  const arrange = h('fieldset.bps-fieldset.bps-arrange', h('legend.bps-legend', t('ui.s1.arrange')),
    h('div.bps-range-row', h('label', { for: scale.id }, t('ui.s1.size')), scale, scaleOut),
    h('div.bps-arrange__btns',
      h('div.bps-pad',
        ibtn('up', t('ui.s1.up'), nudge(0, -1)),
        h('span.bps-pad__row', ibtn('up', t('ui.s1.left'), nudge(-1, 0)), ibtn('up', t('ui.s1.right'), nudge(1, 0))),
        ibtn('up', t('ui.s1.down'), nudge(0, 1))),
      h('div.bps-arrange__more',
        h('button.bps-btn.bps-btn--small.bps-btn--ghost', { type: 'button', onclick: () => { const s = store.get(); store.set({ art: { ...s.art, offsetMm: { x: 0, y: 0 } } }); } }, icon('center'), t('ui.s1.center')),
        h('button.bps-btn.bps-btn--small.bps-btn--ghost', { type: 'button', onclick: () => { const s = store.get(); store.set({ art: { ...s.art, scale: 1, offsetMm: { x: 0, y: 0 } } }); } }, icon('fit'), t('ui.s1.fit')))),
    h('p.bps-help', t('ui.s1.arrangeHint')));
  arrange.querySelectorAll('.bps-pad .bps-ibtn')[1].classList.add('is-left');
  arrange.querySelectorAll('.bps-pad .bps-ibtn')[2].classList.add('is-right');
  arrange.querySelectorAll('.bps-pad .bps-ibtn')[3].classList.add('is-down');

  const textList = h('div.bps-texts__list');
  const addBtn = h('button.bps-btn.bps-btn--ghost.bps-btn--small', { type: 'button', onclick: () => ctx.addText() }, t('ui.text.add'));
  const textMax = h('p.bps-help', { hidden: true });
  const texts = config.features && config.features.textLayers === false ? null
    : h('section.bps-texts', h('h3.bps-h3', t('ui.text.title')), textList, addBtn, textMax, h('p.bps-help', t('ui.text.dragHint')));

  const el = h('section.bps-step',
    h('h2.bps-h2', { tabindex: '-1' }, t('ui.s1.title')),
    found, notices, shapeFs, arrange, texts,
    h('div.bps-step__nav',
      fileLabel(ctx, '.bps-btn.bps-btn--ghost', t('ui.s1.newFile')),
      h('button.bps-btn.bps-btn--primary', { type: 'button', onclick: () => ctx.go(2) }, t('ui.s1.next'), icon('arrow'))));

  /* ----- text layer editor ----- */
  function layerEditor(l, i, s) {
    const T = config.text || {};
    const set = (patch) => store.set({ texts: store.get().texts.map((x) => (x.id === l.id ? { ...x, ...patch } : x)) });
    const id = (k) => `${ctx.uidBase}-${l.id}-${k}`;
    const pathName = `${ctx.uidBase}-${l.id}-path`;
    const fonts = config.fonts || [];
    const threads = config.threads || [];
    const fld = (k, lbl, input) => h('div.bps-field', h('label', { for: id(k) }, lbl), input);
    const arc = l.path !== 'straight';
    return h('fieldset.bps-fieldset.bps-layer', { dataset: { layer: l.id }, onfocusin: () => ctx.setDragTarget(l.id) },
      h('legend.bps-legend', t('ui.text.layer', { n: i + 1 })),
      fld('text', t('ui.text.value'), h('input.bps-input', { id: id('text'), 'data-fk': id('text'), type: 'text', maxlength: T.maxChars || 60, value: l.text, autocomplete: 'off', oninput: (e) => set({ text: e.target.value.slice(0, T.maxChars || 60) }) })),
      h('div.bps-field-row',
        fld('font', t('ui.text.font'), h('select.bps-select', { id: id('font'), 'data-fk': id('font'), onchange: (e) => { set({ font: e.target.value }); ctx.loadFont(e.target.value); } },
          fonts.map((f) => h('option', { value: f.id, selected: f.id === l.font }, f.label || f.id)))),
        fld('height', t('ui.text.height'), h('input.bps-input', { id: id('height'), 'data-fk': id('height'), type: 'number', inputmode: 'decimal', min: T.minHeightMm || 3, max: T.maxHeightMm || 40, step: 0.5, value: l.heightMm,
          onchange: (e) => { const v = Math.min(T.maxHeightMm || 40, Math.max(T.minHeightMm || 3, Number(String(e.target.value).replace(',', '.')) || 6)); e.target.value = v; set({ heightMm: v }); } }))),
      fld('color', t('ui.text.color'), h('select.bps-select', { id: id('color'), 'data-fk': id('color'), onchange: (e) => set({ threadCode: e.target.value }) },
        h('option', { value: 'auto', selected: l.threadCode === 'auto' }, t('ui.text.auto')),
        threads.map((th) => h('option', { value: th.code, selected: th.code === l.threadCode }, `${L(th.name)} (${th.code})`)))),
      h('fieldset.bps-fieldset.bps-fieldset--inline', h('legend.bps-legend', t('ui.text.path')),
        h('div.bps-chips', ['straight', 'arc_top', 'arc_bottom'].map((p) => h('label.bps-chip',
          h('input', { type: 'radio', name: pathName, value: p, checked: l.path === p, 'data-fk': id('p-' + p), onchange: () => set({ path: p, angleDeg: 0, insetMm: 0, offsetMm: { x: 0, y: 0 } }) }),
          h('span', t('ui.text.path.' + p)))))),
      arc ? h('div.bps-range-row', h('label', { for: id('angle') }, t('ui.text.angle')),
        h('input.bps-range', { id: id('angle'), 'data-fk': id('angle'), type: 'range', min: -180, max: 180, step: 1, value: l.angleDeg, 'aria-valuetext': `${l.angleDeg}°`, oninput: (e) => set({ angleDeg: Number(e.target.value) }) })) : null,
      arc ? h('div.bps-range-row', h('label', { for: id('inset') }, t('ui.text.inset')),
        h('input.bps-range', { id: id('inset'), 'data-fk': id('inset'), type: 'range', min: 0, max: 30, step: 0.5, value: l.insetMm, 'aria-valuetext': `${String(l.insetMm).replace('.', ',')} mm`, oninput: (e) => set({ insetMm: Number(e.target.value) }) })) : null,
      h('div.bps-layer__actions',
        h('button.bps-btn.bps-btn--small.bps-btn--ghost', { type: 'button', 'data-fk': id('edit'), onclick: () => { ctx.setDragTarget(l.id); ctx.preview.canvas.focus(); } }, t('ui.text.edit')),
        h('button.bps-btn.bps-btn--small.bps-btn--link', { type: 'button', 'aria-label': t('ui.text.remove', { text: l.text || '…' }), onclick: () => ctx.removeText(l.id) }, t('ui.text.removeShort'))));
  }

  function foundLines(s, d) {
    const a = ctx.analysis;
    const st = ctx.analysisState;
    const lines = [];
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

  function noticeList(s, d) {
    const a = ctx.analysis;
    const st = ctx.analysisState;
    const out = [];
    if (st.status === 'unsupported' || st.status === 'error') {
      out.push(notice('info', [st.status === 'unsupported' ? h('strong', t('ui.s1.unsupportedTitle')) : null, h('p', st.message)]));
    }
    if (s.source.kind === 'text' && !a) out.push(notice('info', h('p', t('ui.s1.textOnlyNote'))));
    if (!a || st.status === 'running') return out;
    const shapeName = L((config.shapes[s.shape] || {}).label).toLowerCase();
    if (a.isPatchPhoto) {
      out.push(notice('ok', [h('p', t('ui.s1.cutAsk')),
        h('button.bps-btn.bps-btn--small.bps-btn--ghost', { type: 'button', onclick: () => ctx.openAdjust() }, icon('scissors'), t('ui.s1.adjust'))]));
    } else if (a.shape && a.shape.fillsPatch && a.shape.type && !s.shapePicked) {
      const unsure = (a.shape.confidence || 0) < ((config.analysis && config.analysis.shapeMinConfidence) || 0.75);
      out.push(notice(unsure ? 'warn' : 'ok', [h('p', unsure ? t('ui.s1.shapeAsk') : t('ui.s1.shapeFound', { shape: shapeName })),
        h('button.bps-btn.bps-btn--small.bps-btn--link', { type: 'button', onclick: () => { const r = shapeFs.querySelector('input:checked') || shapeFs.querySelector('input'); if (r) r.focus(); } }, t('ui.s1.otherShape')),
        h('button.bps-btn.bps-btn--small.bps-btn--link', { type: 'button', onclick: () => ctx.openAdjust() }, t('ui.s1.adjust'))]));
    }
    const bg = (d.analysis && d.analysis.background) || {};
    if (d.keepBg) out.push(notice('ok', h('p', t('ui.s1.keepBg'))));
    else if (bg.type === 'white' && !a.isPatchPhoto) {
      out.push(notice('ok', [h('p', t('ui.s1.whiteBg', { fabric: L(d.fabric.label).toLowerCase() })),
        h('button.bps-btn.bps-btn--small.bps-btn--link', { type: 'button', onclick: () => ctx.go(4, 'fabric') }, t('ui.s1.otherFabric'))]));
    }
    // Advice warnings, minus the ones this step already explains with its own action.
    for (const w of (d.advice && d.advice.warnings) || []) {
      if (/patch_photo_cut/.test(w.key || '') && a.isPatchPhoto) continue;
      if (/shape_uncertain/.test(w.key || '') && a.shape && a.shape.fillsPatch && !s.shapePicked && !a.isPatchPhoto) continue;
      out.push(notice('warn', h('p', w.text)));
    }
    return out;
  }

  return {
    el,
    update(s, d) {
      const st = ctx.analysisState;
      const lines = foundLines(s, d);
      keyed(found, JSON.stringify(lines), () => lines.map((l) => h('li.bps-found__item' + (l.done ? '.is-done' : '.is-busy'), l.done ? icon('ok', 'bps-ico--ok') : h('span.bps-spinner', { 'aria-hidden': 'true' }), l.text)));
      found.hidden = !lines.length;
      const nl = noticeList(s, d);
      keyed(notices, (ctx.analysis ? ctx.analysis.job : 0) + st.status + s.shape + s.shapePicked + d.fabric.id + d.keepBg + nl.length + (st.message || '') + s.source.kind, () => nl);

      keyed(shapeBox, s.shape, () => h('div.bps-chips', (config.shapeOrder || Object.keys(config.shapes)).map((id) => h('label.bps-chip',
        h('input', { type: 'radio', name: ctx.uidBase + '-shape', value: id, checked: s.shape === id, 'data-fk': 'shape-' + id, onchange: () => ctx.setShape(id) }),
        h('span', L(config.shapes[id].label))))));

      arrange.hidden = !ctx.analysis;
      const pct = Math.round(s.art.scale * 100);
      if (document.activeElement !== scale) scale.value = pct;
      scale.setAttribute('aria-valuetext', t('ui.s1.sizeValue', { n: pct }));
      text(scaleOut, pct + ' %');

      if (texts) {
        const max = (config.text && config.text.maxLayers) || 10;
        keyed(textList, s.texts.map((l) => l.id + l.path).join('|'), () => s.texts.map((l, i) => layerEditor(l, i, s)));
        // keep values in sync without rebuilding (e.g. after dragging on the canvas)
        for (const l of s.texts) {
          const box = textList.querySelector(`[data-layer="${l.id}"]`);
          if (!box) continue;
          const ang = box.querySelector('input[type=range][id$="-angle"]');
          if (ang && document.activeElement !== ang) { ang.value = l.angleDeg; ang.setAttribute('aria-valuetext', `${l.angleDeg}°`); }
          const ins = box.querySelector('input[type=range][id$="-inset"]');
          if (ins && document.activeElement !== ins) { ins.value = l.insetMm; ins.setAttribute('aria-valuetext', `${String(l.insetMm).replace('.', ',')} mm`); }
          box.classList.toggle('is-active', ctx.dragTarget === l.id);
        }
        addBtn.hidden = s.texts.length >= max;
        textMax.hidden = s.texts.length < max;
        text(textMax, t('ui.text.max', { n: max }));
      }
    },
  };
}
