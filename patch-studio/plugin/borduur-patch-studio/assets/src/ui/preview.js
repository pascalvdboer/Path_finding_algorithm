/**
 * Patch Studio UI — live patch preview: canvas + renderer (SPEC §7.6), drag (mouse/touch), keyboard,
 * dynamic aria-label. The renderer is used only through render/index.js; a tiny built-in fallback keeps
 * the preview alive if the renderer throws (never a blank or broken preview).
 */
import { createRenderer, artFromAnalysis } from '../render/index.js';
import { label } from '../shared/config.js';
import { formatNumber } from '../shared/money.js';
import { h, debounce } from './dom.js';
import { renderArt, renderOptions } from './derive.js';

const clamp = (v, a, b) => Math.min(b, Math.max(a, v));

/** Minimal fallback drawing (flat colours) — only used when the real renderer fails. */
function fallbackDraw(canvas, o) {
  const c = canvas.getContext('2d');
  const L = o.layout;
  const W = canvas.width, H = canvas.height;
  c.clearRect(0, 0, W, H);
  const ppm = Math.min(W / (L.patchWmm * 1.12), H / (L.patchHmm * 1.12));
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
  c.lineWidth = Math.max(2, (L.edge.widthMm || 2) * ppm);
  c.strokeStyle = o.edgeHex || '#1e1b1b';
  c.stroke(path);
  return { ms: 0, pxPerMm: ppm, originPx: { x: ox, y: oy } };
}

export function createPreview(ctx) {
  const { config, t } = ctx;
  const canvas = h('canvas.bps-preview__canvas', { role: 'img', tabindex: '0', 'aria-label': t('ui.preview.empty'), width: 600, height: 600 });
  const empty = h('div.bps-preview__empty', { 'aria-hidden': 'true' }, t('ui.preview.empty'));
  const dragChip = h('p.bps-preview__chip', { hidden: true });
  const el = h('figure.bps-preview', { 'aria-label': t('ui.preview.label') }, h('div.bps-preview__stage', canvas, empty), dragChip);
  let renderer = null;
  let broken = false;
  let lastResult = null;
  let queued = false;
  let quality = 'high';
  let state = null, d = null;

  function getRenderer() {
    if (renderer || broken) return renderer;
    try { renderer = createRenderer(canvas, { config }); } catch (e) { broken = true; console.warn('[bps] renderer', e); }
    return renderer;
  }

  function art() {
    if (!d || !d.analysis) return null;
    let img = null;
    try { img = artFromAnalysis(d.analysis); } catch (e) { img = null; }
    return renderArt(d, img);
  }

  function options(extra = {}) {
    const view = state.mockup && state.mockup !== 'none' && ctx.step === 3 ? 'garment' : 'patch';
    const g = view === 'garment' ? state.mockup : undefined;
    return renderOptions(state, d, art(), {
      view,
      garment: g,
      garmentHex: g && config.mockups && config.mockups[g] ? config.mockups[g].garmentHex : undefined,
      quality,
      pxSize: canvas.width,
      ...extra,
    });
  }

  function size() {
    const r = canvas.getBoundingClientRect();
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    const px = Math.round(clamp((r.width || 320) * dpr, 240, 1400));
    if (canvas.width !== px) { canvas.width = px; canvas.height = px; }
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
    empty.hidden = true;
  }

  function schedule() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(draw);
  }

  const updateAria = debounce(() => {
    if (!state || !d) return;
    const loc = state.locale;
    const shape = label((config.shapes[state.shape] || {}).label, loc).toLowerCase();
    const tech = label((config.techniques[state.technique] || {}).label, loc).toLowerCase();
    const edge = d.edgeThread ? label(d.edgeThread.name, loc).toLowerCase() : '';
    const texts = (state.texts || []).map((x) => x.text).filter(Boolean).join(' / ');
    canvas.setAttribute('aria-label', t('ui.preview.aria', {
      shape, size: formatNumber(state.sizeCm, loc), tech, edge,
      texts: (texts ? t('ui.preview.ariaTexts', { texts }) : '') + (d.analysis ? '' : t('ui.preview.ariaNoArt')),
    }));
  }, 500);

  /* ----------------------------- interaction ----------------------------- */

  /** Canvas px per CSS px. */
  const scale = () => canvas.width / (canvas.getBoundingClientRect().width || canvas.width);
  /** Pointer → mm relative to the patch centre. */
  function toMm(ev) {
    const r = canvas.getBoundingClientRect();
    const s = scale();
    const x = (ev.clientX - r.left) * s, y = (ev.clientY - r.top) * s;
    const ppm = (lastResult && lastResult.pxPerMm) || 1;
    const o = (lastResult && lastResult.originPx) || { x: 0, y: 0 };
    const L = d.layout;
    return { x: (x - o.x) / ppm - L.patchWmm / 2, y: (y - o.y) / ppm - L.patchHmm / 2 };
  }

  /** Approximate centre (mm from patch centre) of a text layer, for hit-testing. */
  function textPoint(l) {
    const L = d.layout;
    if (l.path === 'straight') return { x: l.offsetMm.x, y: l.offsetMm.y, w: Math.max(8, l.text.length * l.heightMm * 0.7) / 2, h: Math.max(5, l.heightMm) };
    const top = l.path === 'arc_top';
    const rad = Math.min(L.patchWmm, L.patchHmm) / 2 - (Math.max(l.insetMm, L.edge.widthMm || 0) + l.heightMm / 2);
    const a = ((top ? -90 : 90) + l.angleDeg) * Math.PI / 180;
    return { x: Math.cos(a) * rad, y: Math.sin(a) * rad, w: Math.max(10, l.text.length * l.heightMm * 0.5), h: Math.max(6, l.heightMm * 1.4) };
  }

  function hitTest(p) {
    let best = null, bestD = Infinity;
    for (const l of state.texts || []) {
      if (!l.text) continue;
      const q = textPoint(l);
      const dx = Math.abs(p.x - q.x), dy = Math.abs(p.y - q.y);
      if (dx <= q.w && dy <= q.h) {
        const dd = dx * dx + dy * dy;
        if (dd < bestD) { bestD = dd; best = l.id; }
      }
    }
    return best || (d.analysis ? 'art' : (state.texts[0] && state.texts[0].id) || null);
  }

  let drag = null;
  function showChip() {
    const tgt = ctx.dragTarget;
    const l = tgt && tgt !== 'art' ? state.texts.find((x) => x.id === tgt) : null;
    dragChip.hidden = !(state && (d.analysis || l) && ctx.step === 1);
    dragChip.textContent = l ? t('ui.text.dragging', { text: l.text || '…' }) : t('ui.text.dragArt');
  }

  function moveTarget(target, dxMm, dyMm, start, p) {
    const L = d.layout;
    const state = ctx.store.get(); // always the latest state (several moves can happen before a redraw)
    if (target === 'art') {
      const base = start || state.art.offsetMm;
      const off = { x: clamp(base.x + dxMm, -L.patchWmm / 2, L.patchWmm / 2), y: clamp(base.y + dyMm, -L.patchHmm / 2, L.patchHmm / 2) };
      ctx.store.set({ art: { ...state.art, offsetMm: off } });
      return;
    }
    const texts = state.texts.map((l) => {
      if (l.id !== target) return l;
      if (l.path === 'straight') {
        const base = start ? start.offsetMm : l.offsetMm;
        return { ...l, offsetMm: { x: clamp(base.x + dxMm, -L.patchWmm / 2, L.patchWmm / 2), y: clamp(base.y + dyMm, -L.patchHmm / 2, L.patchHmm / 2) } };
      }
      const top = l.path === 'arc_top';
      if (p && start) {
        // Angle follows the pointer around the centre; distance to the centre sets the inset.
        const ang = (Math.atan2(p.y, p.x) * 180) / Math.PI - (top ? -90 : 90);
        const a = ((ang + 540) % 360) - 180;
        const dist = Math.hypot(p.x, p.y);
        const inset = clamp(start.insetMm + (start.dist - dist), 0, Math.min(L.patchWmm, L.patchHmm) / 2 - l.heightMm);
        return { ...l, angleDeg: Math.round(a), insetMm: Math.round(inset * 2) / 2 };
      }
      // Keyboard: left/right = position on the edge, up/down = distance to the edge.
      return {
        ...l,
        angleDeg: clamp(Math.round(l.angleDeg + dxMm * 2), -180, 180),
        insetMm: clamp(l.insetMm + (top ? dyMm : -dyMm), 0, Math.min(L.patchWmm, L.patchHmm) / 2 - l.heightMm),
      };
    });
    ctx.store.set({ texts });
  }

  canvas.addEventListener('pointerdown', (ev) => {
    if (!state || !d || ctx.step !== 1 || (ev.button != null && ev.button > 0)) return;
    const p = toMm(ev);
    const target = hitTest(p);
    if (!target) return;
    ctx.setDragTarget(target);
    const l = state.texts.find((x) => x.id === target);
    drag = {
      id: ev.pointerId, target, p0: p,
      start: target === 'art' ? { ...state.art.offsetMm } : l ? { offsetMm: { ...l.offsetMm }, insetMm: l.insetMm, dist: Math.hypot(p.x, p.y) } : null,
    };
    try { canvas.setPointerCapture(ev.pointerId); } catch (e) { /* ignore */ }
    quality = 'fast';
    ev.preventDefault();
  });
  canvas.addEventListener('pointermove', (ev) => {
    if (!drag || ev.pointerId !== drag.id) return;
    const p = toMm(ev);
    moveTarget(drag.target, p.x - drag.p0.x, p.y - drag.p0.y, drag.start, p);
  });
  const end = (ev) => {
    if (!drag || ev.pointerId !== drag.id) return;
    drag = null;
    quality = 'high';
    schedule();
  };
  canvas.addEventListener('pointerup', end);
  canvas.addEventListener('pointercancel', end);
  canvas.addEventListener('keydown', (ev) => {
    if (!state || ctx.step !== 1) return;
    const k = { ArrowUp: [0, -1], ArrowDown: [0, 1], ArrowLeft: [-1, 0], ArrowRight: [1, 0] }[ev.key];
    if (!k) return;
    const tgt = ctx.dragTarget || (d.analysis ? 'art' : state.texts[0] && state.texts[0].id);
    if (!tgt) return;
    ev.preventDefault();
    const step = ev.shiftKey ? 5 : 1;
    moveTarget(tgt, k[0] * step, k[1] * step);
  });
  window.addEventListener('resize', debounce(schedule, 120));

  return {
    el,
    canvas,
    update(s, derived) {
      state = s;
      d = derived;
      if (!s || !derived) return;
      schedule();
      updateAria();
      showChip();
    },
    /** For move buttons in step 1. */
    nudge(dx, dy) {
      const tgt = ctx.dragTarget || 'art';
      moveTarget(tgt, dx, dy);
    },
    redraw: schedule,
    /** PNG export for cart/e-mail/production (≥ 600 px; transparent, patch only). */
    async toBlob(size = 1200) {
      const o = options({ view: 'patch', transparent: true, quality: 'high', pxSize: size });
      const r = getRenderer();
      if (r && r.toBlob) {
        try { return await r.toBlob(o, { size, type: 'image/png' }); } catch (e) { console.warn('[bps] toBlob', e); }
      }
      const c = document.createElement('canvas');
      c.width = c.height = size;
      fallbackDraw(c, o);
      return new Promise((res) => c.toBlob(res, 'image/png'));
    },
    options,
  };
}
