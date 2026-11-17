/**
 * Patch Studio UI — the canvas stage (the hero of the studio): renderer via render/index.js, view switcher
 * (close-up / on a garment), zoom + pan (wheel, pinch, buttons, keys), direct manipulation (drag, corner handles,
 * double-click text to edit in place), a contextual toolbar, crossfade on technique changes and a dynamic
 * description for screen readers. A tiny built-in fallback keeps the preview alive if the renderer throws.
 * New renderer features (light direction, extra garments, extra qualities) are used only when render exposes them.
 */
import * as R from '../render/index.js';
import { label } from '../shared/config.js';
import { formatNumber } from '../shared/money.js';
import { h, debounce, icon, text } from './dom.js';
import { renderArt, renderOptions } from './derive.js';

const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
const RNS = R; // namespace object: optional render-v2 features are looked up at runtime (feature detection)
const CAPS = RNS['capabilities'] || {};
const ZMIN = 0.6, ZMAX = 6;
const reduceMotion = () => typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Minimal fallback drawing (flat colours) — only used when the real renderer fails. */
function fallbackDraw(canvas, o) {
  const c = canvas.getContext('2d');
  const L = o.layout;
  const W = canvas.width, H = canvas.height;
  c.clearRect(0, 0, W, H);
  const ppm = Math.min(W / (L.patchWmm * 1.2), H / (L.patchHmm * 1.2)) * (o.zoom || 1);
  const ox = (W - L.patchWmm * ppm) / 2, oy = (H - L.patchHmm * ppm) / 2;
  const path = new Path2D();
  const w = L.patchWmm * ppm, hh = L.patchHmm * ppm;
  if (L.shape === 'circle' || L.shape === 'oval') path.ellipse(ox + w / 2, oy + hh / 2, w / 2, hh / 2, 0, 0, Math.PI * 2);
  else path.rect(ox, oy, w, hh);
  c.save();
  c.fillStyle = o.backgroundHex || o.fabricHex || '#ffffff';
  c.fill(path);
  c.clip(path);
  if (o.art && o.art.image && L.artRectMm) {
    const r = L.artRectMm;
    try { c.drawImage(o.art.image, ox + r.x * ppm, oy + r.y * ppm, r.w * ppm, r.h * ppm); } catch (e) { /* ignore */ }
  }
  for (const t of o.design.texts || []) {
    c.fillStyle = (o.textHex && o.textHex[t.id]) || '#1e1b1b';
    c.font = `700 ${t.heightMm * ppm * 1.4}px sans-serif`;
    c.textAlign = 'center';
    const y = t.path === 'arc_top' ? oy + (t.insetMm + t.heightMm + 4) * ppm : t.path === 'arc_bottom' ? oy + hh - (t.insetMm + 4) * ppm : oy + hh / 2 + (t.offsetMm.y + t.heightMm / 2) * ppm;
    c.fillText(t.text, ox + w / 2 + (t.path === 'straight' ? t.offsetMm.x * ppm : 0), y);
  }
  c.restore();
  c.lineWidth = Math.max(2, ((L.edge && L.edge.widthMm) || 2) * ppm);
  c.strokeStyle = o.edgeHex || '#1e1b1b';
  c.stroke(path);
  return { ms: 0, pxPerMm: ppm, originPx: { x: ox, y: oy } };
}

let measureCtx = null;
/** Width (mm) of a text layer as the renderer sets it (cap height ≈ 0.71 × font size, render/text.js). */
export function textWidthMm(config, l) {
  try {
    if (!measureCtx) measureCtx = document.createElement('canvas').getContext('2d');
    const f = (config.fonts || []).find((x) => x.id === l.font) || {};
    measureCtx.font = `${f.weight || 700} 100px "${f.id || 'Sora'}", ${f.fallback || 'sans-serif'}`;
    const chars = Array.from(l.text || '');
    const px = chars.reduce((a, ch) => a + measureCtx.measureText(ch).width, 0) + (Number(l.letterSpacing) || 0) * 100 * Math.max(0, chars.length - 1);
    return (px / 100) * (l.heightMm / 0.71);
  } catch (e) { return (l.text || '').length * l.heightMm * 0.75; }
}

/** Garment views offered by the switcher (whatever render can draw and the config knows). */
export function viewIds(config) {
  const g = (R.GARMENTS || []).filter((id) => !config.mockups || config.mockups[id] || /back(pack|_?bag)|rugzak/.test(id));
  return ['none', ...g];
}

export function createStage(ctx) {
  const { config, t, store } = ctx;
  const canvas = h('canvas.bps-preview__canvas', { role: 'img', tabindex: '0', 'aria-label': t('ui.preview.empty'), 'aria-describedby': null, width: 800, height: 600 });
  const fade = h('canvas.bps-stage__fade', { 'aria-hidden': 'true', width: 2, height: 2 });
  const sel = h('div.bps-sel', { hidden: true, 'aria-hidden': 'true' }, ['nw', 'ne', 'sw', 'se'].map((k) => h('span.bps-sel__h', { dataset: { h: k } })));
  const ctxbar = h('div.bps-ctxbar', { role: 'toolbar', hidden: true, 'aria-label': t('ui.ctx.label') });
  const chip = h('p.bps-stage__chip', { 'aria-hidden': 'true' });
  const wrap = h('div.bps-stage__wrap', canvas, fade, sel, ctxbar);
  const zoomOut = h('output.bps-zoom__out', { 'aria-live': 'off' }, '100 %');
  const zbtn = (name, lbl, fn) => h('button.bps-ibtn.bps-zoom__btn', { type: 'button', 'aria-label': lbl, title: lbl, onclick: fn }, icon(name));
  const zoomBar = h('div.bps-zoom', { role: 'group', 'aria-label': t('ui.zoom.label') },
    zbtn('minus', t('ui.zoom.out'), () => setZoom(zoom / 1.25)), zoomOut, zbtn('plus', t('ui.zoom.in'), () => setZoom(zoom * 1.25)),
    zbtn('fit', t('ui.zoom.fit'), () => { setZoom(1); center = null; }));
  const viewName = ctx.uidBase + '-view';
  const views = h('div.bps-views', { role: 'radiogroup', 'aria-label': t('ui.view.label') });
  const el = h('figure.bps-stage.bps-preview', { 'aria-label': t('ui.preview.label') }, views, wrap, zoomBar, chip);

  let renderer = null, broken = false, lastResult = null, queued = false, quality = 'high', hiTimer = 0;
  let state = null, d = null, zoom = 1, center = null, light = null, lastTech = null, warmedJob = null;

  /* ------------------------------- views -------------------------------- */
  function viewLabel(id) {
    if (id === 'none') return t('ui.view.none');
    const k = 'ui.view.' + id;
    const s = t(k);
    if (s !== k) return s;
    return (config.mockups && config.mockups[id] && ctx.L(config.mockups[id].label)) || id;
  }
  function buildViews() {
    const ids = config.features && config.features.garmentView === false ? ['none'] : viewIds(config);
    views.hidden = ids.length < 2;
    views.replaceChildren(...ids.map((id) => h('label.bps-views__opt',
      h('input', { type: 'radio', name: viewName, value: id, 'data-fk': 'view-' + id, onchange: () => { store.set({ mockup: id }); setZoom(1); center = null; } }),
      h('span', viewLabel(id)))));
  }
  buildViews();

  function getRenderer() {
    if (renderer || broken) return renderer;
    try { renderer = R.createRenderer(canvas, { config, onRedraw: (r) => { lastResult = r; placeOverlay(); } }); } catch (e) { broken = true; console.warn('[bps] renderer', e); }
    return renderer;
  }

  function art() {
    if (!d || !d.analysis) return null;
    let img = null;
    try { img = R.artFromAnalysis(d.analysis); } catch (e) { img = null; }
    return renderArt(d, img);
  }

  const garmentOf = () => (state && state.mockup && state.mockup !== 'none' ? state.mockup : null);

  function options(extra = {}) {
    const g = garmentOf();
    const o = renderOptions(state, d, art(), {
      view: g ? 'garment' : 'patch',
      garment: g || undefined,
      garmentHex: g && config.mockups && config.mockups[g] ? config.mockups[g].garmentHex : undefined,
      quality,
      pxSize: Math.max(canvas.width, canvas.height),
      zoom,
      zoomCenterMm: center || undefined,
      ...extra,
    });
    if (CAPS.light && light) o.light = light;
    return o;
  }

  function size() {
    const r = wrap.getBoundingClientRect();
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    const k = Math.min(1, 1700 / Math.max(1, Math.max(r.width, r.height) * dpr));
    const w = Math.round(clamp((r.width || 320) * dpr * k, 200, 1700));
    const hh = Math.round(clamp((r.height || 320) * dpr * k, 200, 1700));
    if (canvas.width !== w) canvas.width = w;
    if (canvas.height !== hh) canvas.height = hh;
  }

  function crossfade() {
    if (reduceMotion() || !lastResult || !canvas.width) return;
    try {
      fade.width = canvas.width; fade.height = canvas.height;
      fade.getContext('2d').drawImage(canvas, 0, 0);
      fade.classList.remove('is-out');
      fade.style.opacity = '1';
      requestAnimationFrame(() => requestAnimationFrame(() => { fade.classList.add('is-out'); fade.style.opacity = '0'; }));
    } catch (e) { /* ignore */ }
  }

  function draw() {
    queued = false;
    if (!state || !d) return;
    size();
    const o = options();
    const r = getRenderer();
    try {
      lastResult = r ? r.draw(o) : fallbackDraw(canvas, o);
    } catch (e) {
      console.warn('[bps] render failed, using fallback', e);
      broken = true;
      lastResult = fallbackDraw(canvas, o);
    }
    el.classList.add('is-drawn');
    placeOverlay();
    const job = d.analysis ? d.analysis.job : null;
    if (r && r.prewarm && job != null && job !== warmedJob && quality === 'high') {
      warmedJob = job;
      try { r.prewarm(o, (d.advice.ranking || []).slice(0, 4).filter((x) => x !== state.technique)); } catch (e) { /* best effort */ }
    }
  }

  function schedule() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(draw);
  }
  /** Fast quality while interacting, high quality shortly after. */
  function interacting() {
    quality = 'fast';
    clearTimeout(hiTimer);
    hiTimer = setTimeout(() => { quality = 'high'; schedule(); }, 160);
  }

  function setZoom(z, focusMm) {
    z = clamp(Math.round(z * 100) / 100, ZMIN, ZMAX);
    if (focusMm && z > 1 && !garmentOf()) center = focusMm;
    if (z <= 1) center = null;
    zoom = z;
    text(zoomOut, Math.round(z * 100) + ' %');
    interacting();
    schedule();
  }

  const describe = debounce(() => {
    if (!state || !d) return;
    let s = '';
    try { if (R.describeRender) s = R.describeRender(options(), config, state.locale); } catch (e) { s = ''; }
    if (!s) {
      const loc = state.locale;
      const texts = (state.texts || []).map((x) => x.text).filter(Boolean).join(' / ');
      s = t('ui.preview.aria', {
        shape: label((config.shapes[state.shape] || {}).label, loc).toLowerCase(), size: formatNumber(state.sizeCm, loc),
        tech: label((config.techniques[state.technique] || {}).label, loc).toLowerCase(), edge: d.edgeThread ? label(d.edgeThread.name, loc).toLowerCase() : '',
        texts: (texts ? t('ui.preview.ariaTexts', { texts }) : '') + (d.analysis ? '' : t('ui.preview.ariaNoArt')),
      });
    }
    canvas.setAttribute('aria-label', s);
  }, 400);

  /* ---------------------------- geometry ---------------------------- */
  const pxScale = () => canvas.width / (canvas.getBoundingClientRect().width || canvas.width);
  /** Pointer → mm relative to the patch centre. */
  function toMm(ev) {
    const r = canvas.getBoundingClientRect();
    const s = pxScale();
    const x = (ev.clientX - r.left) * s, y = (ev.clientY - r.top) * s;
    const ppm = (lastResult && lastResult.pxPerMm) || 1;
    const o = (lastResult && lastResult.originPx) || { x: 0, y: 0 };
    const L = d.layout;
    return { x: (x - o.x) / ppm - L.patchWmm / 2, y: (y - o.y) / ppm - L.patchHmm / 2 };
  }
  /** mm (from patch centre) → CSS px inside the wrap. */
  function toPx(x, y) {
    const s = pxScale();
    const ppm = (lastResult && lastResult.pxPerMm) || 1;
    const o = (lastResult && lastResult.originPx) || { x: 0, y: 0 };
    const L = d.layout;
    return { x: (o.x + (x + L.patchWmm / 2) * ppm) / s, y: (o.y + (y + L.patchHmm / 2) * ppm) / s };
  }

  /** Box of a layer in mm from the patch centre: {x, y (centre), w, h, rot (deg)}. */
  function boxOf(id) {
    const L = d.layout;
    if (id === 'art') {
      const r = L.artRectMm;
      if (!r) return null;
      return { x: r.x + r.w / 2 - L.patchWmm / 2, y: r.y + r.h / 2 - L.patchHmm / 2, w: r.w, h: r.h, rot: 0 };
    }
    const l = (state.texts || []).find((x) => x.id === id);
    if (!l) return null;
    const w = Math.max(6, textWidthMm(config, l)), hh = Math.max(4, l.heightMm * 1.3);
    if (l.path === 'straight') return { x: l.offsetMm.x, y: l.offsetMm.y, w, h: hh, rot: 0 };
    const top = l.path === 'arc_top';
    const edge = (L.edge && L.edge.widthMm) || 0;
    const R0 = Math.min(L.patchWmm, L.patchHmm) / 2;
    const rad = top ? R0 - Math.max(l.insetMm, edge + l.heightMm + 0.5) + l.heightMm / 2 : R0 - Math.max(l.insetMm, edge + 0.7) - l.heightMm / 2;
    const a = ((top ? -90 : 90) + l.angleDeg) * Math.PI / 180;
    return { x: Math.cos(a) * rad, y: Math.sin(a) * rad, w: Math.min(w, 2 * rad * 0.98), h: hh, rot: l.angleDeg };
  }

  function inBox(p, b, pad = 1.5) {
    if (!b) return false;
    const a = -b.rot * Math.PI / 180;
    const dx = p.x - b.x, dy = p.y - b.y;
    const x = dx * Math.cos(a) - dy * Math.sin(a), y = dx * Math.sin(a) + dy * Math.cos(a);
    return Math.abs(x) <= b.w / 2 + pad && Math.abs(y) <= b.h / 2 + pad;
  }

  function hitTest(p) {
    const texts = (state.texts || []).filter((l) => l.text);
    for (let i = texts.length - 1; i >= 0; i--) if (inBox(p, boxOf(texts[i].id))) return texts[i].id;
    if (d.analysis && inBox(p, boxOf('art'), 0)) return 'art';
    const L = d.layout;
    if (Math.abs(p.x) <= L.patchWmm / 2 && Math.abs(p.y) <= L.patchHmm / 2) return d.analysis ? 'art' : null;
    return null;
  }

  /* ---------------------------- overlay ---------------------------- */
  function placeOverlay() {
    const tgt = ctx.dragTarget;
    const b = state && d && lastResult && tgt ? boxOf(tgt) : null;
    const editable = !!b && !editing;
    sel.hidden = !editable;
    ctxbar.hidden = !editable || !!drag;
    if (!editable) return;
    const ppm = (lastResult.pxPerMm || 1) / pxScale();
    const c = toPx(b.x, b.y);
    const w = b.w * ppm, hh = b.h * ppm;
    Object.assign(sel.style, { left: c.x + 'px', top: c.y + 'px', width: w + 'px', height: hh + 'px', transform: `translate(-50%,-50%) rotate(${b.rot}deg)` });
    sel.classList.toggle('is-text', tgt !== 'art');
    // Toolbar above the selection, kept inside the stage.
    const ww = wrap.clientWidth, wh = wrap.clientHeight;
    const bw = ctxbar.offsetWidth || 200, bh = ctxbar.offsetHeight || 44;
    const extent = Math.max(w, hh) / 2;
    let y = c.y - (b.rot ? extent : hh / 2) - bh - 12;
    if (y < 8) y = Math.min(wh - bh - 8, c.y + (b.rot ? extent : hh / 2) + 12);
    ctxbar.style.left = clamp(c.x - bw / 2, 8, Math.max(8, ww - bw - 8)) + 'px';
    ctxbar.style.top = clamp(y, 8, Math.max(8, wh - bh - 8)) + 'px';
  }

  function tbtn(name, lbl, fn, extra = {}) {
    return h('button.bps-ctxbar__btn', { type: 'button', 'aria-label': lbl, title: lbl, onclick: fn, ...extra }, icon(name), extra.txt ? h('span', extra.txt) : null);
  }

  let barKey = '';
  function buildBar() {
    const tgt = ctx.dragTarget;
    const l = tgt && tgt !== 'art' ? (state.texts || []).find((x) => x.id === tgt) : null;
    const key = tgt + '|' + (l ? l.path : '') + '|' + (l ? l.heightMm : (state.art || {}).scale);
    if (key === barKey) return;
    barKey = key;
    const T = config.text || {};
    const L = config.layout || {};
    if (l) {
      const next = { straight: 'arc_top', arc_top: 'arc_bottom', arc_bottom: 'straight' }[l.path];
      ctxbar.replaceChildren(
        tbtn('type', t('ui.ctx.editText'), () => startEdit(l.id), { txt: t('ui.ctx.edit') }),
        h('span.bps-ctxbar__sep', { 'aria-hidden': 'true' }),
        tbtn('minus', t('ui.ctx.smaller'), () => setLayer(l.id, { heightMm: clamp(l.heightMm - 0.5, T.minHeightMm || 3, T.maxHeightMm || 40) })),
        h('span.bps-ctxbar__val', { 'aria-hidden': 'true' }, ctx.n(l.heightMm) + ' mm'),
        tbtn('plus', t('ui.ctx.bigger'), () => setLayer(l.id, { heightMm: clamp(l.heightMm + 0.5, T.minHeightMm || 3, T.maxHeightMm || 40) })),
        h('span.bps-ctxbar__sep', { 'aria-hidden': 'true' }),
        tbtn(l.path === 'straight' ? 'arc' : 'type', t('ui.ctx.path', { path: t('ui.text.path.' + next) }), () => setLayer(l.id, { path: next, angleDeg: 0, insetMm: 0, offsetMm: { x: 0, y: 0 } })),
        tbtn('trash', t('ui.text.remove', { text: l.text || '…' }), () => ctx.removeText(l.id)));
    } else if (tgt === 'art') {
      const sc = (state.art && state.art.scale) || 1;
      const set = (scale) => store.set({ art: { ...store.get().art, scale: clamp(Math.round(scale * 20) / 20, L.artScaleMin || 0.2, L.artScaleMax || 2) } });
      ctxbar.replaceChildren(
        tbtn('minus', t('ui.ctx.smaller'), () => set(sc - 0.05)),
        h('span.bps-ctxbar__val', { 'aria-hidden': 'true' }, Math.round(sc * 100) + ' %'),
        tbtn('plus', t('ui.ctx.bigger'), () => set(sc + 0.05)),
        h('span.bps-ctxbar__sep', { 'aria-hidden': 'true' }),
        tbtn('center', t('ui.s1.center'), () => store.set({ art: { ...store.get().art, offsetMm: { x: 0, y: 0 } } })),
        tbtn('fit', t('ui.s1.fit'), () => store.set({ art: { ...store.get().art, scale: 1, offsetMm: { x: 0, y: 0 } } })));
    } else ctxbar.replaceChildren();
  }

  function setLayer(id, patch) {
    store.set({ texts: store.get().texts.map((x) => (x.id === id ? { ...x, ...patch } : x)) });
  }

  /* --------------------------- inline text edit --------------------------- */
  let editing = null;
  function startEdit(id) {
    const l = (state.texts || []).find((x) => x.id === id);
    const b = boxOf(id);
    if (!l || !b || !lastResult) return;
    stopEdit();
    const T = config.text || {};
    const ppm = (lastResult.pxPerMm || 1) / pxScale();
    const c = toPx(b.x, b.y);
    const inp = h('input.bps-inline', {
      type: 'text', value: l.text, maxlength: T.maxChars || 60, autocomplete: 'off', 'aria-label': t('ui.text.value'),
      style: { left: c.x + 'px', top: c.y + 'px', fontSize: clamp(l.heightMm * ppm * 1.25, 14, 64) + 'px', minWidth: Math.max(120, b.w * ppm + 24) + 'px', transform: `translate(-50%,-50%) rotate(${b.rot}deg)` },
    });
    const done = () => { if (editing === inp) stopEdit(); };
    inp.addEventListener('input', () => setLayer(id, { text: inp.value.slice(0, T.maxChars || 60) }));
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === 'Escape') { e.preventDefault(); done(); canvas.focus({ preventScroll: true }); } });
    inp.addEventListener('blur', done);
    editing = inp;
    wrap.appendChild(inp);
    placeOverlay();
    inp.focus();
    inp.select();
  }
  function stopEdit() {
    if (!editing) return;
    const e = editing;
    editing = null;
    e.remove();
    if (ctx.history) ctx.history.commit();
    placeOverlay();
  }

  /* ---------------------------- interaction ---------------------------- */
  function moveTarget(target, dxMm, dyMm, start, p) {
    const L = d.layout;
    const s = store.get(); // always the latest state (several moves can happen before a redraw)
    if (target === 'art') {
      const base = start || s.art.offsetMm;
      store.set({ art: { ...s.art, offsetMm: { x: clamp(base.x + dxMm, -L.patchWmm / 2, L.patchWmm / 2), y: clamp(base.y + dyMm, -L.patchHmm / 2, L.patchHmm / 2) } } });
      return;
    }
    const texts = s.texts.map((l) => {
      if (l.id !== target) return l;
      if (l.path === 'straight') {
        const base = start ? start.offsetMm : l.offsetMm;
        return { ...l, offsetMm: { x: clamp(base.x + dxMm, -L.patchWmm / 2, L.patchWmm / 2), y: clamp(base.y + dyMm, -L.patchHmm / 2, L.patchHmm / 2) } };
      }
      const top = l.path === 'arc_top';
      const maxIn = Math.min(L.patchWmm, L.patchHmm) / 2 - l.heightMm;
      if (p && start) {
        // Angle follows the pointer around the centre; distance to the centre sets the inset.
        const ang = (Math.atan2(p.y, p.x) * 180) / Math.PI - (top ? -90 : 90);
        const a = ((ang + 540) % 360) - 180;
        const inset = clamp(start.insetMm + (start.dist - Math.hypot(p.x, p.y)), 0, maxIn);
        return { ...l, angleDeg: Math.round(a), insetMm: Math.round(inset * 2) / 2 };
      }
      // Keyboard: left/right = position on the edge, up/down = distance to the edge.
      return { ...l, angleDeg: clamp(Math.round(l.angleDeg + dxMm * 2 * (top ? 1 : -1)), -180, 180), insetMm: clamp(l.insetMm + (top ? dyMm : -dyMm), 0, maxIn) };
    });
    store.set({ texts });
  }

  let drag = null;
  const pointers = new Map();
  let pinch = null;

  function resizeFrom(p) {
    const b = drag.box;
    const r0 = Math.max(0.5, Math.hypot(drag.p0.x - b.x, drag.p0.y - b.y));
    const k = Math.hypot(p.x - b.x, p.y - b.y) / r0;
    if (drag.target === 'art') {
      const L = config.layout || {};
      store.set({ art: { ...store.get().art, scale: clamp(Math.round(drag.scale * k * 100) / 100, L.artScaleMin || 0.2, L.artScaleMax || 2) } });
    } else {
      const T = config.text || {};
      setLayer(drag.target, { heightMm: clamp(Math.round(drag.heightMm * k * 2) / 2, T.minHeightMm || 3, T.maxHeightMm || 40) });
    }
  }

  sel.addEventListener('pointerdown', (ev) => {
    const hnd = ev.target.closest && ev.target.closest('.bps-sel__h');
    if (!hnd || !state || !d) return;
    const tgt = ctx.dragTarget;
    const l = (state.texts || []).find((x) => x.id === tgt);
    drag = { id: ev.pointerId, mode: 'resize', target: tgt, p0: toMm(ev), box: boxOf(tgt), scale: state.art.scale, heightMm: l ? l.heightMm : 0 };
    try { sel.setPointerCapture(ev.pointerId); } catch (e) { /* ignore */ }
    el.classList.add('is-dragging');
    ev.preventDefault();
    ev.stopPropagation();
  });
  sel.addEventListener('pointermove', (ev) => { if (drag && drag.mode === 'resize' && ev.pointerId === drag.id) { interacting(); resizeFrom(toMm(ev)); } });

  canvas.addEventListener('pointerdown', (ev) => {
    if (!state || !d || (ev.button != null && ev.button > 0)) return;
    canvas.focus({ preventScroll: true });
    pointers.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
    try { canvas.setPointerCapture(ev.pointerId); } catch (e) { /* ignore */ }
    ev.preventDefault();
    if (pointers.size === 2) {
      const [a, b] = [...pointers.values()];
      pinch = { dist: Math.hypot(a.x - b.x, a.y - b.y) || 1, zoom };
      drag = null;
      return;
    }
    stopEdit();
    const p = toMm(ev);
    const target = ev.altKey || ctx.spaceDown ? null : hitTest(p);
    if (target) {
      ctx.setDragTarget(target);
      const l = state.texts.find((x) => x.id === target);
      drag = {
        id: ev.pointerId, mode: 'move', target, p0: p, moved: false,
        start: target === 'art' ? { ...state.art.offsetMm } : l ? { offsetMm: { ...l.offsetMm }, insetMm: l.insetMm, dist: Math.hypot(p.x, p.y) } : null,
      };
    } else {
      ctx.setDragTarget(null);
      drag = zoom > 1 && !garmentOf() ? { id: ev.pointerId, mode: 'pan', c0: center || { x: d.layout.patchWmm / 2, y: d.layout.patchHmm / 2 }, x0: ev.clientX, y0: ev.clientY } : null;
    }
    if (drag) el.classList.add('is-dragging');
  });
  canvas.addEventListener('pointermove', (ev) => {
    if (pointers.has(ev.pointerId)) pointers.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
    if (pinch && pointers.size >= 2) {
      const [a, b] = [...pointers.values()];
      setZoom(pinch.zoom * Math.hypot(a.x - b.x, a.y - b.y) / pinch.dist);
      return;
    }
    if (!drag || ev.pointerId !== drag.id) {
      if (CAPS.light && !drag && ev.pointerType === 'mouse') {
        const r = canvas.getBoundingClientRect();
        light = { x: clamp((ev.clientX - r.left) / r.width * 2 - 1, -1, 1), y: clamp((ev.clientY - r.top) / r.height * 2 - 1, -1, 1) };
        interacting();
        schedule();
      }
      return;
    }
    interacting();
    if (drag.mode === 'pan') {
      const ppm = (lastResult && lastResult.pxPerMm) || 1;
      const s = pxScale();
      center = { x: drag.c0.x - (ev.clientX - drag.x0) * s / ppm, y: drag.c0.y - (ev.clientY - drag.y0) * s / ppm };
      schedule();
      return;
    }
    const p = toMm(ev);
    drag.moved = drag.moved || Math.hypot(p.x - drag.p0.x, p.y - drag.p0.y) > 0.4;
    moveTarget(drag.target, p.x - drag.p0.x, p.y - drag.p0.y, drag.start, p);
  });
  const end = (ev) => {
    pointers.delete(ev.pointerId);
    if (pointers.size < 2) pinch = null;
    if (!drag || ev.pointerId !== drag.id) return;
    drag = null;
    el.classList.remove('is-dragging');
    quality = 'high';
    if (ctx.history) ctx.history.commit();
    schedule();
  };
  for (const n of ['pointerup', 'pointercancel']) { canvas.addEventListener(n, end); sel.addEventListener(n, end); }
  canvas.addEventListener('dblclick', (ev) => {
    if (!state || !d) return;
    const tgt = hitTest(toMm(ev));
    if (tgt && tgt !== 'art') startEdit(tgt);
    else if (!tgt && zoom !== 1) setZoom(1);
  });
  sel.addEventListener('dblclick', () => { if (ctx.dragTarget && ctx.dragTarget !== 'art') startEdit(ctx.dragTarget); });

  let wheelArmed = false;
  el.addEventListener('pointerdown', () => { wheelArmed = true; });
  el.addEventListener('pointerleave', () => { wheelArmed = false; });
  wrap.addEventListener('wheel', (ev) => {
    if (!state || !d || !(ev.ctrlKey || ev.metaKey || wheelArmed)) return;
    ev.preventDefault();
    const p = toMm(ev);
    const L = d.layout;
    setZoom(zoom * Math.exp(-ev.deltaY * (ev.ctrlKey ? 0.01 : 0.0018)), { x: p.x + L.patchWmm / 2, y: p.y + L.patchHmm / 2 });
  }, { passive: false });

  canvas.addEventListener('keydown', (ev) => {
    if (!state || !d) return;
    if (ev.key === '+' || ev.key === '=') { ev.preventDefault(); setZoom(zoom * 1.25); return; }
    if (ev.key === '-') { ev.preventDefault(); setZoom(zoom / 1.25); return; }
    if (ev.key === '0') { ev.preventDefault(); setZoom(1); return; }
    const tgt = ctx.dragTarget || (d.analysis ? 'art' : state.texts[0] && state.texts[0].id);
    if (!tgt) return;
    if (ev.key === 'Enter' && tgt !== 'art') { ev.preventDefault(); startEdit(tgt); return; }
    if ((ev.key === 'Delete' || ev.key === 'Backspace') && tgt !== 'art') { ev.preventDefault(); ctx.removeText(tgt); return; }
    if (ev.key === 'Escape') { ctx.setDragTarget(null); return; }
    if (ev.key === 'Tab' && !ev.shiftKey) return;
    const k = { ArrowUp: [0, -1], ArrowDown: [0, 1], ArrowLeft: [-1, 0], ArrowRight: [1, 0] }[ev.key];
    if (!k) return;
    ev.preventDefault();
    if (!ctx.dragTarget) ctx.setDragTarget(tgt);
    const step = ev.shiftKey ? 5 : 1;
    moveTarget(tgt, k[0] * step, k[1] * step);
  });
  canvas.addEventListener('focus', () => { if (!ctx.dragTarget && d && (d.analysis || (state.texts || []).length)) ctx.setDragTarget(d.analysis ? 'art' : state.texts[0].id); });

  if (typeof ResizeObserver === 'function') new ResizeObserver(() => schedule()).observe(wrap);
  else window.addEventListener('resize', debounce(schedule, 120));

  function chipText() {
    const tgt = ctx.dragTarget;
    const l = tgt && tgt !== 'art' ? (state.texts || []).find((x) => x.id === tgt) : null;
    if (l) return t('ui.text.dragging', { text: l.text || '…' });
    if (tgt === 'art') return t('ui.text.dragArt');
    return d.analysis || (state.texts || []).length ? t('ui.stage.hint') : '';
  }

  return {
    el,
    canvas,
    update(s, derived) {
      const techChanged = state && lastTech && s.technique !== lastTech;
      state = s;
      d = derived;
      if (!s || !derived) return;
      if (techChanged) crossfade();
      lastTech = s.technique;
      const cur = s.mockup && viewIds(config).includes(s.mockup) ? s.mockup : 'none';
      views.querySelectorAll('input').forEach((i) => { i.checked = i.value === cur; i.parentNode.classList.toggle('is-on', i.value === cur); });
      zoomBar.hidden = false;
      buildBar();
      text(chip, chipText());
      chip.hidden = !chip.textContent;
      schedule();
      describe();
    },
    nudge(dx, dy) { moveTarget(ctx.dragTarget || 'art', dx, dy); },
    redraw: schedule,
    startEdit,
    setZoom,
    /** PNG export for cart/e-mail/production (≥ 600 px; transparent, patch only). */
    async toBlob(px = 1200) {
      const o = options({ view: 'patch', transparent: true, quality: 'high', pxSize: px, zoom: 1, zoomCenterMm: undefined });
      const r = getRenderer();
      if (r && r.toBlob) {
        try { return await r.toBlob(o, { size: px, type: 'image/png' }); } catch (e) { console.warn('[bps] toBlob', e); }
      }
      const c = document.createElement('canvas');
      c.width = c.height = px;
      fallbackDraw(c, o);
      return new Promise((res) => c.toBlob(res, 'image/png'));
    },
    options,
  };
}
