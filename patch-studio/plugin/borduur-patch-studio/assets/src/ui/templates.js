/**
 * Patch Studio UI — start gallery (critique-1 #4, STUDIO-REDESIGN hard requirement 1): ready-made starting points,
 * rendered live by our own renderer in a real technique (embroidered, extra relief, woven, PVC, chenille, leather,
 * printed). Every card is a template with editable text layers: one click opens the studio with it.
 * No brands, no real clubs: neutral example words the customer replaces.
 */
import * as R from '../render/index.js';
import { h, icon, text } from './dom.js';
import { initialState, reconcileOptions, normalizeQty } from './state.js';
import { derive, renderOptions } from './derive.js';

const T = (text, o = {}) => ({ text, font: 'Sora', heightMm: 6, threadCode: 'auto', path: 'straight', offsetMm: { x: 0, y: 0 }, angleDeg: 0, insetMm: 0, letterSpacing: 0.06, outline: 'none', ...o });

/** use: club, company, workwear, motor, youth, event, name, gift */
export const TEMPLATES = [
  { id: 'emblem', use: ['club', 'youth'], shape: 'circle', sizeCm: 8, technique: 'embroidered', fabric: 'navy', edge: '1724',
    texts: [T('KALMTHOUT', { path: 'arc_top', heightMm: 7, threadCode: '1724', letterSpacing: 0.12 }), T('SINDS 1985', { path: 'arc_bottom', heightMm: 5.5, threadCode: '1801', letterSpacing: 0.12 }), T('VC', { font: 'Graduate', heightMm: 16, threadCode: '1801', offsetMm: { x: 0, y: 1 } })] },
  { id: 'puff', use: ['club', 'gift'], shape: 'circle', sizeCm: 7, technique: 'embroidered', options: ['extra_thick'], fabric: 'black', edge: '1508',
    texts: [T('24', { font: 'Graduate', heightMm: 26, threadCode: '1508', offsetMm: { x: 0, y: -2 } }), T('TEAM', { heightMm: 6, threadCode: '1801', offsetMm: { x: 0, y: 19 }, letterSpacing: 0.2 })] },
  { id: 'shield', use: ['club', 'youth'], shape: 'shield', aspect: 0.86, sizeCm: 9, technique: 'woven', fabric: 'royal', edge: '1801',
    texts: [T('DE LEEUW', { font: 'Oswald', heightMm: 9, threadCode: '1924', offsetMm: { x: 0, y: -10 } }), T('JEUGD', { heightMm: 5, threadCode: '1801', offsetMm: { x: 0, y: 4 }, letterSpacing: 0.25 }), T('2026', { font: 'Oswald', heightMm: 7, threadCode: '1924', offsetMm: { x: 0, y: 17 } })] },
  { id: 'banner', use: ['company', 'workwear'], shape: 'rect', aspect: 3.2, sizeCm: 10, technique: 'pvc', fabric: 'black', edge: 'auto',
    texts: [T('BOUWTEAM', { font: 'Oswald', heightMm: 12, threadCode: '1955', letterSpacing: 0.08 })] },
  { id: 'name', use: ['name', 'workwear'], shape: 'rounded', aspect: 3.6, sizeCm: 10, technique: 'embroidered', fabric: 'olive', edge: '1798',
    texts: [T('J. JANSSENS', { font: 'Roboto Slab', heightMm: 9, threadCode: '1526', letterSpacing: 0.06 })] },
  { id: 'vintage', use: ['motor', 'event'], shape: 'oval', aspect: 1.45, sizeCm: 10, technique: 'chenille', fabric: 'red', edge: '1803',
    texts: [T('ROAD CREW', { font: 'Cinzel', path: 'arc_top', heightMm: 7, threadCode: '1803', letterSpacing: 0.1 }), T('MC', { font: 'Pacifico', heightMm: 17, threadCode: '1803', offsetMm: { x: 0, y: 3 } })] },
  { id: 'leather', use: ['gift', 'company'], shape: 'rounded', aspect: 1.5, sizeCm: 8, technique: 'leather', fabric: 'leather_cognac', edge: 'auto',
    texts: [T('HANDMADE', { font: 'Cinzel', heightMm: 8, threadCode: '1800', offsetMm: { x: 0, y: -4 }, letterSpacing: 0.14 }), T('ATELIER · 2026', { heightMm: 4, threadCode: '1800', offsetMm: { x: 0, y: 8 }, letterSpacing: 0.14 })] },
  { id: 'event', use: ['event', 'gift'], shape: 'circle', sizeCm: 7, technique: 'printed', fabric: 'white', edge: '1934',
    texts: [T('FUN RUN', { font: 'Oswald', path: 'arc_top', heightMm: 8, threadCode: '1934', letterSpacing: 0.08 }), T('2026', { font: 'Graduate', heightMm: 13, threadCode: '1965', offsetMm: { x: 0, y: 2 } }), T('10 KM', { path: 'arc_bottom', heightMm: 5.5, threadCode: '1934', letterSpacing: 0.15 })] },
  { id: 'opa', use: ['gift'], shape: 'shield', aspect: 0.9, sizeCm: 7, technique: 'embroidered', fabric: 'bottle', edge: '1724',
    texts: [T('BESTE', { font: 'Cinzel', heightMm: 5, threadCode: '1724', offsetMm: { x: 0, y: -12 }, letterSpacing: 0.2 }), T('OPA', { font: 'Graduate', heightMm: 14, threadCode: '1801', offsetMm: { x: 0, y: 2 } })] },
];

export const USES = ['all', 'club', 'company', 'workwear', 'motor', 'youth', 'event', 'name', 'gift'];

/** Store state for a template (techniques/fabrics that are switched off fall back to what is available). */
export function templateState(config, tpl, t) {
  const base = initialState(config);
  const techs = config.techniques || {};
  const tech = techs[tpl.technique] && techs[tpl.technique].enabled ? tpl.technique : base.technique;
  const fabrics = (techs[tech] && techs[tech].fabricList === 'leathers' ? config.leathers : config.fabrics) || [];
  const fabric = fabrics.some((f) => f.id === tpl.fabric && f.enabled !== false) ? tpl.fabric : 'auto';
  const threads = config.threads || [];
  const code = (c) => (c && c !== 'auto' && threads.some((x) => x.code === c) ? c : 'auto');
  const fonts = (config.fonts || []).map((f) => f.id);
  const opts = reconcileOptions(config, tech, [...(base.options || []), ...(tpl.options || [])]);
  return {
    ...base,
    source: { kind: 'text', fileName: null, fileType: null, fileBytes: null, hash: null },
    technique: tech, techniquePicked: true,
    shape: tpl.shape, shapePicked: true, aspect: tpl.aspect || 1,
    sizeCm: tpl.sizeCm, sizePicked: true,
    qty: normalizeQty(config, tech, base.qty),
    options: opts,
    fabric,
    edgeColor: code(tpl.edge),
    texts: tpl.texts.map((l, i) => ({
      ...l, id: 'g' + i + tpl.id, text: t ? t('ui.tpl.' + tpl.id + '.' + i, {}, l.text) : l.text,
      font: fonts.includes(l.font) ? l.font : fonts[0] || 'Sora', threadCode: code(l.threadCode),
    })),
  };
}

/** Load the fonts of a template first (the render uses them; a fallback font would look cheap). */
function fontsFor(ctx, st) {
  const ids = [...new Set(st.texts.map((l) => l.font))];
  ids.forEach((id) => { try { if (ctx.loadFont) ctx.loadFont(id); } catch (e) { /* ignore */ } });
  if (typeof document === 'undefined' || !document.fonts || !document.fonts.load) return Promise.resolve();
  const fonts = ctx.config.fonts || [];
  const loads = ids.map((id) => { const f = fonts.find((x) => x.id === id) || {}; return document.fonts.load(`${f.weight || 700} 20px "${id}"`).catch(() => null); });
  return Promise.race([Promise.all(loads), new Promise((r) => setTimeout(r, 1500))]);
}

const idle = (fn) => (typeof requestIdleCallback === 'function' ? requestIdleCallback(fn, { timeout: 900 }) : setTimeout(fn, 40));

/** Live render of a template (canvas) at `px`. */
function renderTemplate(config, st, px) {
  const d = derive(st, null, config, null);
  const o = renderOptions(st, d, null, { technique: st.technique, quality: 'high', pxSize: px, view: 'patch', zoom: 1, transparent: true, config });
  const c = R.renderThumb(o, px);
  if (!c) return c;
  const cv = c.nodeType === 1 ? c : h('canvas', { width: c.width, height: c.height }); // OffscreenCanvas → DOM canvas
  const x = cv.getContext('2d', { willReadFrequently: true });
  if (cv !== c) x.drawImage(c, 0, 0);
  // drop the faint shadow haze around the patch: the gallery adds its own soft shadow (no square halo)
  const id = x.getImageData(0, 0, cv.width, cv.height), dd = id.data;
  for (let i = 3; i < dd.length; i += 4) if (dd[i] < 150) dd[i] = dd[i] < 90 ? 0 : (dd[i] - 90) * 4;
  x.putImageData(id, 0, 0);
  return cv;
}

/**
 * Gallery element: filter chips per use + rendered template cards. `onPick(tpl)`.
 * Cards render progressively in idle time so the landing page stays fast.
 */
export function createGallery(ctx, { onPick, limit = 99, hero = false } = {}) {
  const { t, config } = ctx;
  let use = 'all';
  const chips = h('div.bps-gal__chips', { role: 'group', 'aria-label': t('ui.gal.filter') });
  const grid = h('ul.bps-gal__grid');
  const el = h('section.bps-gal' + (hero ? '.bps-gal--hero' : ''), hero ? null : [h('div.bps-gal__head', h('h3.bps-h3', t('ui.gal.title')), h('p.bps-help', t('ui.gal.sub'))), chips], grid);
  const cache = new Map();
  const tt = (k, p, fb) => { const s = t(k, p); return s === k ? fb : s; };

  function card(tpl) {
    const st = templateState(config, tpl, tt);
    const tech = config.techniques[st.technique] || {};
    const vis = h('span.bps-gal__vis', { 'aria-hidden': 'true' });
    const hit = cache.get(tpl.id);
    if (hit) vis.appendChild(hit.cloneNode(true));
    else idle(() => fontsFor(ctx, st).then(() => {
      try {
        const cv = renderTemplate(config, st, hero ? 360 : 300);
        // as an image: crisp, cheap to re-use and no canvas compositing halo around the patch
        if (cv) { const img = h('img.bps-gal__cv', { src: cv.toDataURL('image/png'), alt: '', width: cv.width, height: cv.height, decoding: 'async' }); cache.set(tpl.id, img); vis.replaceChildren(img); vis.classList.add('is-ready'); }
      } catch (e) { console.warn('[bps] gallery', e); }
    }));
    if (hit) vis.classList.add('is-ready');
    return h('li.bps-gal__item', h('button.bps-gal__card', { type: 'button', 'data-tpl': tpl.id, 'aria-label': t('ui.gal.pick', { name: t('ui.tpl.' + tpl.id), tech: ctx.L(tech.label) }), onclick: () => onPick(tpl) },
      vis, hero ? null : h('span.bps-gal__name', t('ui.tpl.' + tpl.id)), hero ? null : h('span.bps-gal__tech', ctx.L(tech.label) + (tpl.options && tpl.options.includes('extra_thick') ? ' · ' + t('ui.gal.relief') : ''))));
  }

  function draw() {
    const list = TEMPLATES.filter((x) => (config.techniques[x.technique] || {}).enabled !== false && (use === 'all' || x.use.includes(use))).slice(0, limit);
    grid.replaceChildren(...list.map(card));
    chips.querySelectorAll('button').forEach((b) => { const on = b.dataset.use === use; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', String(on)); });
  }
  if (!hero) chips.append(...USES.map((u) => h('button.bps-gal__chip', { type: 'button', dataset: { use: u }, onclick: () => { use = u; draw(); } }, t('ui.gal.use.' + u))));
  draw();
  return { el, draw };
}

export { icon, text };
