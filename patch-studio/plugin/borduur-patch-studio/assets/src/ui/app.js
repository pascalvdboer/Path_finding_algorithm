/**
 * Patch Studio UI — application: builds the layout inside the root, owns the store, runs analysis,
 * derives advice/price, steps + history, persistence, events (SPEC §1.2, §7.4, §7.8).
 */
import { createT, langOf } from '../shared/i18n.js';
import { label } from '../shared/config.js';
import { formatMoney, formatNumber } from '../shared/money.js';
import { EV, emit } from '../shared/events.js';
import { decodeFile, createAnalyzer, messages as analysisMessages } from '../analysis/index.js';
import { messages as adviceMessages } from '../advice/index.js';
import { colorMessages } from '../colors/index.js';
import { messages as pricingMessages } from '../pricing/index.js';
import uiMessages from './messages.js';
import { h, text, icon, debounce, uid } from './dom.js';
import { createStore, initialState, sanitizeState, normalizeQty, reconcileOptions, persist, loadPersisted, clearPersisted } from './state.js';
import { derive, fabricList } from './derive.js';
import { createApi } from './api.js';
import { createPreview } from './preview.js';
import { emptyState, stepDesign } from './step-design.js';
import { stepTechnique } from './step-technique.js';
import { stepSize } from './step-size.js';
import { stepFinish } from './step-finish.js';
import { stepSummary } from './step-summary.js';
import { createPriceCard, createStickyBar } from './price.js';
import { createFlows } from './flows.js';

const STEP_RE = /bps_step=(\d)/;

function overridesFor(config) {
  const s = config.strings || {};
  const loc = config.locale || 'nl-BE';
  const nested = s[loc] || s[langOf(loc)];
  if (nested && typeof nested === 'object') return nested;
  const flat = {};
  for (const [k, v] of Object.entries(s)) if (typeof v === 'string') flat[k] = v;
  return flat;
}

export function createApp(root, config) {
  const locale = config.locale || 'nl-BE';
  const overrides = overridesFor(config);
  const t = createT([uiMessages, analysisMessages, adviceMessages, colorMessages, pricingMessages], locale, overrides);
  const moneyLocale = langOf(locale) === 'fr' ? 'fr-BE' : 'nl-BE';
  const store = createStore(initialState(config));
  const api = createApi(config);

  const ctx = {
    root, config, t, store, api, locale, overrides,
    uidBase: uid('bps-app'),
    step: 0,
    maxStep: 0,
    analysis: null,
    analysisState: { status: 'idle', stage: null, message: '' },
    file: null,
    tweaks: null,
    dragTarget: null,
    busy: false,
    ui: { exclVat: false, showAllTech: false, showChanges: false, rights: false, device: false, qtyAdjustedFrom: null },
    derived: null,
    L: (o) => label(o, locale),
    fmt: (x) => formatMoney(x, moneyLocale),
    n: (x, d = 1) => formatNumber(x, moneyLocale, d),
  };
  /** Price according to the excl./incl. toggle (main total stays incl., SPEC §1.2). */
  ctx.money = (incl, excl) => ctx.fmt(ctx.ui.exclVat && excl != null ? excl : incl);
  ctx.hasDesign = () => {
    const s = store.get();
    return s.source.kind === 'text' || !!s.source.fileName || !!ctx.analysis;
  };

  /* ------------------------------ live regions ------------------------------ */
  const status = h('div.bps-sr', { role: 'status', 'aria-live': 'polite' });
  const alertBox = h('div.bps-sr', { role: 'alert' });
  ctx.announce = debounce((m) => { status.textContent = ''; setTimeout(() => { status.textContent = m; }, 30); }, 500);
  ctx.alert = (m) => { alertBox.textContent = m; };

  ctx.track = (event, props = {}) => {
    try {
      const f = config.features || {};
      const consent = typeof window.getCkyConsent === 'function' && window.getCkyConsent();
      if (!f.analyticsEvents || !(consent && consent.categories && consent.categories.analytics === true)) return;
      emit(root, EV.TRACK, { event, props });
      if (Array.isArray(window.dataLayer)) window.dataLayer.push({ event: 'bps_' + event, ...props });
    } catch (e) { /* never break the tool for analytics */ }
  };

  /* ------------------------------ components ------------------------------ */
  const preview = createPreview(ctx);
  ctx.preview = preview;
  const flows = createFlows(ctx);
  Object.assign(ctx, flows);

  const empty = emptyState(ctx);
  const steps = [null, stepDesign(ctx), stepTechnique(ctx), stepSize(ctx), stepFinish(ctx), stepSummary(ctx)];
  const card = createPriceCard(ctx);
  const bar = createStickyBar(ctx);
  const restoreBox = h('div.bps-restore', { hidden: true });

  const stepBtns = [];
  const stepNav = h('nav.bps-stepsnav', { 'aria-label': t('ui.steps.label') },
    h('ol.bps-steps', [1, 2, 3, 4, 5].map((n) => {
      const b = h('button.bps-steps__btn', { type: 'button', onclick: () => ctx.go(n) },
        h('span.bps-steps__num', { 'aria-hidden': 'true' }, String(n)), h('span.bps-steps__name', t('ui.step.' + n)), h('span.bps-sr.bps-steps__done'));
      stepBtns.push(b);
      return h('li.bps-steps__item', b);
    })),
    h('button.bps-btn.bps-btn--link.bps-btn--small.bps-steps__new', { type: 'button', onclick: () => ctx.reset() }, t('ui.new')));

  const c = config.contact || {};
  const help = h('p.bps-helpline', icon('chat'), ' ',
    c.whatsapp ? h('a', { href: 'https://wa.me/' + String(c.whatsapp).replace(/\D/g, ''), target: '_blank', rel: 'noopener' }, t('ui.help', { phone: c.phone || '' })) : t('ui.help', { phone: c.phone || '' }),
    c.email ? [' ', h('a', { href: 'mailto:' + c.email }, t('ui.help.mail', { email: c.email }))] : null);

  const stepWrap = h('div.bps-stepwrap', steps.slice(1).map((s) => s.el));
  const main = h('div.bps-main', restoreBox, empty.el, stepWrap, help);
  const side = h('div.bps-side', preview.el, h('p.bps-disclaimer.bps-side__disc', t('ui.s2.disclaimer')), card.el);
  const app = h('div.bps-app', { role: 'region', 'aria-label': t('ui.app.label') }, status, alertBox, stepNav, h('div.bps-grid', main, side), bar.el, bar.sheet);

  /* ------------------------------ rendering ------------------------------ */
  let raf = 0;
  let lastQuoteTotal = null;
  let lastAdviceSig = '';
  let changedAcc = new Set();

  function render() {
    raf = 0;
    const s = store.get();
    const d = derive(s, ctx.analysis, config, ctx.tweaks);
    ctx.derived = d;
    const has = ctx.hasDesign();
    empty.el.hidden = has && ctx.step > 0;
    stepWrap.hidden = !(has && ctx.step > 0);
    side.hidden = !(has && ctx.step > 0);
    stepNav.hidden = !(has && ctx.step > 0);
    root.classList.toggle('is-empty', !(has && ctx.step > 0));
    root.dataset.step = String(has ? ctx.step : 0);
    steps.forEach((st, i) => { if (st) st.el.hidden = i !== ctx.step; });
    stepBtns.forEach((b, i) => {
      const n = i + 1;
      const cur = n === ctx.step;
      if (cur) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
      b.disabled = !has || n > ctx.maxStep;
      b.parentNode.classList.toggle('is-done', n < ctx.maxStep || (n <= ctx.maxStep && !cur && n < ctx.step));
      b.parentNode.classList.toggle('is-current', cur);
      text(b.querySelector('.bps-steps__done'), n < ctx.step ? ' ' + t('ui.steps.done') : '');
    });
    if (has && ctx.step > 0) {
      preview.update(s, d);
      const cur = steps[ctx.step];
      if (cur) cur.update(s, d);
      // Keep other steps fresh enough for "Wijzig" jumps (cheap: they rebuild only on key changes).
      card.update(s, d);
      bar.update(s, d, card);
    } else {
      bar.update(s, d, card);
    }
    // Events
    const changed = [...changedAcc];
    changedAcc = new Set();
    if (changed.length) emit(root, EV.STATE, { state: s, changed });
    const asig = d.advice.recommended + '|' + (d.advice.summary && d.advice.summary.headline && d.advice.summary.headline.text);
    if (asig !== lastAdviceSig && has) { lastAdviceSig = asig; emit(root, EV.ADVICE, { advice: d.advice }); }
    if (d.quote.totalIncl !== lastQuoteTotal && has) {
      if (lastQuoteTotal != null && ctx.step >= 3 && d.quote.totalIncl > 0) ctx.announce(t('ui.status.price', { total: ctx.fmt(d.quote.totalIncl) }));
      lastQuoteTotal = d.quote.totalIncl;
      emit(root, EV.QUOTE, { quote: d.quote, source: 'client' });
    }
  }
  ctx.refresh = () => { if (!raf) raf = requestAnimationFrame(render); };

  store.subscribe((s, changed) => {
    changed.forEach((k) => changedAcc.add(k));
    persist(s, ctx.ui.device);
    ctx.refresh();
  });

  /* ------------------------------ navigation ------------------------------ */
  ctx.go = (n, focusId, { push = true, focus = true } = {}) => {
    n = Math.max(0, Math.min(5, Number(n) || 0));
    if (n > 0 && !ctx.hasDesign()) n = 0;
    const prev = ctx.step;
    ctx.step = n;
    ctx.maxStep = Math.max(ctx.maxStep, n);
    if (push && prev !== n) {
      try {
        const url = new URL(location.href);
        url.hash = n ? 'bps_step=' + n : '';
        history.pushState({ bpsStep: n }, '', url);
      } catch (e) { /* sandboxed */ }
    }
    if (prev !== n) { emit(root, EV.STEP, { step: n }); ctx.track('step', { step: n }); }
    render();
    if (focus && prev !== n) {
      const target = (focusId && root.querySelector(`[id^="bps-${focusId}"]`)) || (n > 0 ? steps[n].el.querySelector('h2') : empty.el.querySelector('h2'));
      if (target) {
        target.focus({ preventScroll: true });
        const r = (n > 0 ? stepNav : root).getBoundingClientRect();
        if (r.top < 0 || r.top > window.innerHeight * 0.6) (n > 0 ? stepNav : root).scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
      }
    }
  };
  window.addEventListener('popstate', () => {
    const m = STEP_RE.exec(location.hash);
    ctx.go(m ? Number(m[1]) : 0, null, { push: false });
  });

  ctx.barAction = () => {
    if (ctx.step < 5) ctx.go(ctx.step + 1);
    else steps[5].submit();
  };
  ctx.openSheet = () => {
    bar.refreshSheet(store.get(), ctx.derived, card);
    if (bar.sheet.showModal) bar.sheet.showModal(); else bar.sheet.setAttribute('open', '');
  };
  ctx.setDragTarget = (id) => { if (ctx.dragTarget !== id) { ctx.dragTarget = id; ctx.refresh(); } };

  /* ------------------------------ state actions ------------------------------ */
  ctx.applyPatch = (patch) => {
    const s = store.get();
    const p = { ...patch };
    if (p.technique && p.technique !== s.technique) {
      const prevQty = p.qty != null ? p.qty : s.qty;
      p.options = p.options ? reconcileOptions(config, p.technique, p.options) : reconcileOptions(config, p.technique, s.options);
      p.qty = normalizeQty(config, p.technique, prevQty);
      ctx.ui.qtyAdjustedFrom = p.qty !== prevQty ? prevQty : null;
      if (s.fabric !== 'auto' && !fabricList(config, p.technique).some((f) => f.id === s.fabric)) p.fabric = 'auto';
    } else if (p.options) {
      p.options = reconcileOptions(config, p.technique || s.technique, p.options);
    }
    if (p.qty != null && !p.technique) p.qty = normalizeQty(config, s.technique, p.qty);
    if (p.sizeCm != null) p.sizePicked = true;
    store.set(p);
  };
  ctx.applyFix = (action, techId) => {
    if (!action) return;
    if (action.type === 'set') ctx.applyPatch({ ...(techId && !action.patch.technique ? { technique: techId, techniquePicked: true } : {}), ...action.patch });
    else if (action.type === 'quote') ctx.openQuote();
    else if (action.type === 'upload') { const i = root.querySelector('.bps-stepwrap input[type=file]'); if (i) i.click(); }
  };
  ctx.setShape = (id) => {
    const a = ctx.analysis;
    let aspect = 1;
    if (id !== 'circle') aspect = (a && a.shape && a.shape.aspect) || (a && a.artBox ? a.artBox.w / a.artBox.h : 1.5);
    if (id !== 'circle' && !a && Math.abs(aspect - 1) < 0.01 && id !== 'shield') aspect = 1.5;
    store.set({ shape: id, shapePicked: true, aspect });
  };

  const fontLoads = new Map();
  ctx.loadFont = (id) => {
    const f = (config.fonts || []).find((x) => x.id === id);
    if (!f || fontLoads.has(id) || typeof FontFace === 'undefined' || !document.fonts) return;
    const base = (config.assets && config.assets.fontsUrl) || '';
    if (!base || !f.file) { fontLoads.set(id, null); return; }
    try {
      const face = new FontFace(f.id, `url(${base.replace(/\/?$/, '/')}${f.file}) format("woff2")`, { weight: String(f.weight || 400), display: 'swap' });
      const p = face.load().then((ff) => { document.fonts.add(ff); preview.redraw(); ctx.refresh(); }).catch(() => { /* fallback font is used */ });
      fontLoads.set(id, p);
    } catch (e) { fontLoads.set(id, null); }
  };

  ctx.addText = () => {
    const s = store.get();
    const T = config.text || {};
    if (s.texts.length >= (T.maxLayers || 10)) return;
    const font = ((config.fonts || [])[0] || {}).id || 'Sora';
    const first = !s.texts.length;
    const arc = first && !ctx.analysis && (s.shape === 'circle' || s.shape === 'oval');
    const layer = {
      id: 't' + Date.now().toString(36) + s.texts.length,
      text: t('ui.text.default'),
      font,
      heightMm: T.defaultHeightMm || 6,
      threadCode: 'auto',
      path: arc ? 'arc_top' : 'straight',
      offsetMm: { x: 0, y: first ? 0 : Math.min(20, s.texts.length * ((T.defaultHeightMm || 6) + 3)) },
      angleDeg: 0,
      insetMm: 0,
      letterSpacing: 0.05,
    };
    store.set({ texts: [...s.texts, layer] });
    ctx.dragTarget = layer.id;
    ctx.loadFont(font);
    requestAnimationFrame(() => requestAnimationFrame(() => {
      const inp = root.querySelector(`[data-layer="${layer.id}"] input[type=text]`);
      if (inp) { inp.focus(); inp.select(); }
    }));
  };
  ctx.removeText = (id) => {
    const s = store.get();
    store.set({ texts: s.texts.filter((l) => l.id !== id) });
    if (ctx.dragTarget === id) ctx.dragTarget = ctx.analysis ? 'art' : null;
    requestAnimationFrame(() => { const b = root.querySelector('.bps-texts .bps-btn'); if (b) b.focus(); });
  };

  ctx.startText = () => {
    const s = store.get();
    const page = config.page || {};
    ctx.file = null;
    ctx.analysis = null;
    ctx.tweaks = null;
    ctx.analysisState = { status: 'idle', stage: null, message: '' };
    const tech = s.techniquePicked ? s.technique : ((config.advice && config.advice.textOnlyTechnique) || s.technique);
    store.set({
      source: { kind: 'text', fileName: null, fileType: null, fileBytes: null, hash: null },
      designId: null, token: null, ref: null,
      shape: page.shape && page.shape !== 'auto' ? page.shape : 'circle', aspect: 1,
      sizeCm: page.sizeCm || (config.sizes && config.sizes.defaultTextOnlyCm) || 6,
      technique: tech,
      options: reconcileOptions(config, tech, s.options),
      qty: normalizeQty(config, tech, s.qty),
      texts: [],
    });
    emit(root, EV.DESIGN_START, { mode: 'text' });
    ctx.track('design_start', { mode: 'text' });
    ctx.go(1);
    ctx.addText();
  };

  ctx.reset = () => {
    clearPersisted();
    ctx.ui.device = false;
    ctx.ui.rights = false;
    ctx.file = null;
    ctx.analysis = null;
    ctx.tweaks = null;
    ctx.dragTarget = null;
    ctx.analysisState = { status: 'idle', stage: null, message: '' };
    ctx.maxStep = 0;
    restoreBox.hidden = true;
    store.set(initialState(config));
    ctx.go(0);
  };

  /* ------------------------------ analysis ------------------------------ */
  let analyzer = null;
  let analyzerKey = '';
  let job = 0;

  function getAnalyzer(cfg) {
    const key = JSON.stringify(cfg.analysis || {});
    if (!analyzer || key !== analyzerKey) {
      if (analyzer) try { analyzer.terminate(); } catch (e) { /* ignore */ }
      analyzer = createAnalyzer({ workerUrl: (config.assets && config.assets.workerUrl) || null, config: cfg });
      analyzerKey = key;
    }
    return analyzer;
  }

  /** Automatic choices after analysis (only where the customer did not choose, SPEC §7.4). */
  function applyAutoChoices(first) {
    const a = ctx.analysis;
    if (!a) return;
    const s0 = store.get();
    const patch = {};
    if (!s0.shapePicked) {
      patch.shape = (a.shape && a.shape.type) || 'circle';
      patch.aspect = patch.shape === 'circle' ? 1 : (a.shape && a.shape.aspect) || (a.artBox ? a.artBox.w / a.artBox.h : 1);
    }
    let s1 = { ...s0, ...patch };
    if (!s0.techniquePicked) {
      // Start from the neutral default technique: a technique left over from a previous design (e.g. sublimated
      // after a photo, which forces a white fabric) must not bias the advice for a new file.
      const t0 = initialState(config).technique;
      if (t0 && t0 !== s1.technique && config.techniques[t0] && config.techniques[t0].enabled) {
        patch.technique = t0;
        patch.options = reconcileOptions(config, t0, s1.options);
        patch.qty = normalizeQty(config, t0, s1.qty);
        s1 = { ...s0, ...patch };
      }
      // Follow the advice until it is stable (the recommendation may depend on the current technique).
      for (let i = 0; i < 3; i++) {
        const rec = derive(s1, a, config, ctx.tweaks).advice.recommended;
        if (!rec || rec === s1.technique || !config.techniques[rec] || !config.techniques[rec].enabled) break;
        patch.technique = rec;
        patch.options = reconcileOptions(config, rec, s1.options);
        patch.qty = normalizeQty(config, rec, s1.qty);
        s1 = { ...s0, ...patch };
      }
    }
    if (first) {
      const d2 = derive(s1, a, config, ctx.tweaks);
      if (!s0.sizePicked && d2.advice.size && d2.advice.size.recommendedCm) patch.sizeCm = d2.advice.size.recommendedCm;
      if (d2.advice.colors && d2.advice.colors.maxThreadColors) patch.maxThreadColors = d2.advice.colors.maxThreadColors;
    }
    store.set(patch);
  }

  async function runAnalysis(file, { cfg = config, first = true } = {}) {
    const my = ++job;
    ctx.analysisState = { status: 'running', stage: 'decode', message: '' };
    ctx.announce(t('ui.s1.analyzing'));
    store.touch();
    let decoded;
    try {
      decoded = await decodeFile(file, cfg);
    } catch (e) {
      decoded = { kind: 'unsupported', reason: 'decode', ext: (file.name.split('.').pop() || '').toLowerCase() };
    }
    if (my !== job) return;
    if (!decoded || decoded.kind === 'unsupported') {
      const reason = (decoded && decoded.reason) || 'decode';
      const mb = (cfg.analysis && cfg.analysis.maxFileMb) || 25;
      ctx.analysis = null;
      ctx.analysisState = { status: reason === 'type' ? 'unsupported' : 'error', stage: null, message: t('analysis.decode.' + reason, { ext: decoded && decoded.ext ? decoded.ext : '', mb }) };
      if (reason === 'size') ctx.file = null;
      emit(root, EV.ANALYSIS_ERROR, { code: 'decode_' + reason, message: ctx.analysisState.message });
      ctx.announce(ctx.analysisState.message);
      store.touch();
      return;
    }
    const run = async (dec, retry) => {
      try {
        const result = await getAnalyzer(cfg).analyze(dec, {
          job: my,
          onProgress: (p) => {
            if (my !== job) return;
            ctx.analysisState.stage = p.stage;
            emit(root, EV.ANALYSIS_PROGRESS, { stage: p.stage, partial: p.partial });
            store.touch();
          },
        });
        if (my !== job) return;
        ctx.analysis = result;
        ctx.analysisState = { status: 'done', stage: 'done', message: '' };
        ctx.dragTarget = 'art';
        applyAutoChoices(first);
        emit(root, EV.ANALYSIS_DONE, { result });
        store.touch();
        requestAnimationFrame(() => {
          const d = ctx.derived;
          if (d && my === job) ctx.announce(t('ui.status.done', { tech: label(config.techniques[d.advice.recommended].label, locale) }));
        });
      } catch (e) {
        if (my !== job || (e && e.code === 'cancelled')) return;
        if (e && e.code === 'worker_failed' && !retry) {
          try { return run(await decodeFile(file, cfg), true); } catch (x) { /* fall through */ }
        }
        console.warn('[bps] analysis failed', e);
        ctx.analysis = null;
        ctx.analysisState = { status: 'error', stage: null, message: t('analysis.error') };
        emit(root, EV.ANALYSIS_ERROR, { code: (e && e.code) || 'analysis_failed', message: ctx.analysisState.message });
        ctx.announce(ctx.analysisState.message);
        store.touch();
      }
    };
    await run(decoded, false);
  }

  async function hashFile(file) {
    try {
      if (!crypto || !crypto.subtle) return null;
      const buf = await file.arrayBuffer();
      const d = await crypto.subtle.digest('SHA-256', buf);
      return Array.from(new Uint8Array(d)).map((b) => b.toString(16).padStart(2, '0')).join('').slice(0, 40);
    } catch (e) {
      return null;
    }
  }

  ctx.handleFile = (file, { keepChoices = false } = {}) => {
    if (!file) return;
    const s = store.get();
    const page = config.page || {};
    const keep = keepChoices || (ctx.pendingRestore && ctx.pendingRestore === file.name);
    ctx.pendingRestore = null;
    restoreBox.hidden = true;
    ctx.file = file;
    ctx.analysis = null;
    ctx.tweaks = null;
    const patch = {
      source: { kind: 'upload', fileName: file.name, fileType: file.type || null, fileBytes: file.size, hash: null },
      designId: null, token: null, ref: null,
    };
    if (!keep) Object.assign(patch, {
      shapePicked: !!(page.shape && page.shape !== 'auto'),
      shape: page.shape && page.shape !== 'auto' ? page.shape : s.shape,
      art: { scale: 1, offsetMm: { x: 0, y: 0 }, rotation: 0 },
      threadOverrides: {},
      fabric: 'auto',
      edgeColor: 'auto',
      sizePicked: page.sizeCm != null,
      techniquePicked: !!page.technique,
      texts: s.source.kind === 'text' ? [] : s.texts,
    });
    store.set(patch);
    emit(root, EV.DESIGN_START, { file: { name: file.name, type: file.type, size: file.size }, mode: 'upload' });
    ctx.track('design_start', { mode: 'upload', type: file.type });
    ctx.go(1);
    hashFile(file).then((hash) => { if (ctx.file === file && hash) store.set({ source: { ...store.get().source, hash } }); });
    runAnalysis(file, { first: !keep });
  };

  /** Re-run the cut-out with other settings (adjust dialog). */
  ctx.reanalyze = ({ tolerance, floodNeighborDE, keepBackground, shape }) => {
    ctx.tweaks = { tolerance, keepBackground };
    if (shape && shape !== store.get().shape) ctx.setShape(shape);
    if (!ctx.file) { ctx.refresh(); return; }
    const cfg = { ...config, analysis: { ...(config.analysis || {}), floodNeighborDE } };
    runAnalysis(ctx.file, { cfg, first: false });
  };

  /* ------------------------------ restore ------------------------------ */
  function stateFromPayload(p, designId, token, ref) {
    return sanitizeState({
      ...p,
      designId, token, ref,
      techniquePicked: true, shapePicked: true, sizePicked: true,
      fabric: p.fabric && p.fabric.id ? p.fabric.id : 'auto',
      edgeColor: p.edgeColor && p.edgeColor.code ? p.edgeColor.code : 'auto',
      texts: (p.texts || []).map((l, i) => ({ ...l, id: 't' + i })),
      source: { kind: p.source && p.source.fileName ? 'upload' : 'text', fileName: p.source && p.source.fileName, fileType: p.source && p.source.fileType },
    }, config);
  }

  async function restoreFromLink(id, tok) {
    restoreBox.hidden = false;
    restoreBox.replaceChildren(h('p.bps-notice.bps-notice--info', { role: 'status' }, h('span.bps-spinner', { 'aria-hidden': 'true' }), t('ui.restore.loading')));
    try {
      const r = await api.getDesign(id, tok);
      const st = stateFromPayload(r.payload || {}, r.designId || Number(id), tok, r.ref || null);
      store.set(st);
      restoreBox.hidden = true;
      if (r.originalUrl) {
        try {
          const res = await fetch(r.originalUrl, { credentials: 'same-origin' });
          if (res.ok) {
            const blob = await res.blob();
            const f = new File([blob], st.source.fileName || 'ontwerp', { type: blob.type || st.source.fileType || '' });
            ctx.file = f;
            ctx.go(2, null, { push: false, focus: false });
            await runAnalysis(f, { first: false });
            store.set({ designId: st.designId, token: st.token, ref: st.ref });
            return;
          }
        } catch (e) { /* show summary without image */ }
      }
      ctx.maxStep = 5;
      ctx.go(5, null, { push: false, focus: false });
    } catch (e) {
      restoreBox.replaceChildren(h('p.bps-notice.bps-notice--warn', { role: 'alert' }, t('ui.restore.failed')));
    }
  }

  function restoreSession() {
    const saved = loadPersisted();
    if (!saved || !saved.data) return false;
    const st = sanitizeState(saved.data, config);
    ctx.ui.device = !!saved.device;
    if (st.source.kind === 'text') {
      store.set(st);
      st.texts.forEach((l) => ctx.loadFont(l.font));
      const m = STEP_RE.exec(location.hash);
      ctx.go(m ? Number(m[1]) : 1, null, { push: false, focus: false });
      return true;
    }
    if (st.source.fileName) {
      store.set({ ...st, source: { ...st.source, fileName: null } });
      ctx.pendingRestore = st.source.fileName;
      restoreBox.hidden = false;
      restoreBox.replaceChildren(h('div.bps-notice.bps-notice--info', icon('info'), h('div.bps-notice__body',
        h('p', t('ui.restore.session'), ' ', h('strong', st.source.fileName)),
        h('button.bps-btn.bps-btn--small.bps-btn--link', { type: 'button', onclick: () => ctx.reset() }, t('ui.restore.discard')))));
      return true;
    }
    return false;
  }

  /* ------------------------------ mount ------------------------------ */
  function mount() {
    const skeleton = root.querySelector('[data-bps-skeleton], .bps-skeleton');
    const preFile = skeleton && skeleton.querySelector('input[type=file]');
    const pending = preFile && preFile.files && preFile.files[0];
    if (skeleton) skeleton.remove();
    root.appendChild(app);
    root.classList.add('is-ready');
    if (config.page && config.page.compact) root.classList.add('bps-studio--compact');
    const q = new URLSearchParams(location.search);
    // Security: drop the restore token from the address bar right away, so it does not end up in
    // bookmarks, shared links, screenshots or later analytics hits (the values stay in `q`).
    let cleanUrl = location.href;
    if (q.has('bps_design') || q.has('bps_token')) {
      try {
        const u = new URL(location.href);
        u.searchParams.delete('bps_design');
        u.searchParams.delete('bps_token');
        cleanUrl = u.toString();
      } catch (e) { /* ignore */ }
    }
    try { history.replaceState({ bpsStep: 0 }, '', cleanUrl); } catch (e) { /* ignore */ }
    if (pending) ctx.handleFile(pending);
    else if (q.get('bps_design') && q.get('bps_token') && api.online) restoreFromLink(q.get('bps_design'), q.get('bps_token'));
    else if (!restoreSession()) render();
    else render();
    // Optional: patches already in the cart count for the tier (SPEC §1.2 step 3).
    if (config.features && config.features.cartPoolHint && api.online) {
      api.storeCart().then((cart) => {
        if (!cart || !Array.isArray(cart.items)) return;
        let pool = 0, ours = 0;
        const fees = new Set();
        for (const it of cart.items) {
          const b = it.extensions && it.extensions.bps;
          if (!b) continue;
          const tt = config.techniques[b.technique] || {};
          if (tt.tiers) pool += Number(it.quantity) || 0;
          if (tt.feeGroup) fees.add(tt.feeGroup);
          ours += (Number(it.totals && it.totals.line_total) + Number(it.totals && it.totals.line_total_tax)) || 0;
        }
        const minor = Math.pow(10, Number(cart.totals && cart.totals.currency_minor_unit) || 2);
        const items = (Number(cart.totals && cart.totals.total_items) + Number(cart.totals && cart.totals.total_items_tax)) || 0;
        const other = Math.max(0, (items - ours) / minor);
        store.set({ poolQty: config.tiers && config.tiers.pooled ? pool : 0, feesAlreadyCharged: config.fees && config.fees.scope === 'order' ? [...fees] : [], otherItemsInclVat: other + ours / minor });
      });
    }
  }

  // Keyboard: Esc closes the price sheet (native dialog does this) — nothing else global.
  mount();
  return { ctx, store, destroy() { app.remove(); } };
}
