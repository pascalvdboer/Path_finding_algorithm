/**
 * Patch Studio UI — visual Madeira Polyneon thread picker (critique-1 #7, STUDIO-REDESIGN hard requirement 3).
 * One popover for every thread choice (design colours, text, border): recommended colours first, search on number
 * or name, colour families, and "show all 411" (the full card is loaded lazily: config.assets.polyneon (inline,
 * demo) or assets/data/polyneon.json next to the plugin assets). A thread picked from the full card is added to
 * config.threads, so price, advice, cart and production sheet know its number and name.
 */
import { h, icon, text, uid } from './dom.js';

const FAMILIES = ['all', 'neutral', 'yellow', 'orange', 'red', 'pink', 'purple', 'blue', 'green', 'brown'];

function hexRgb(hex) { const n = parseInt(String(hex).replace('#', '').slice(0, 6), 16) || 0; return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; }
/** Colour family of a hex (hue buckets; greys/black/white = neutral; dull warm = brown). */
export function familyOf(hex, given) {
  const map = { white: 'neutral', grey: 'neutral', gray: 'neutral', black: 'neutral', beige: 'brown' };
  if (given) return map[given] || given;
  const [r, g, b] = hexRgb(hex).map((v) => v / 255);
  const mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn, l = (mx + mn) / 2;
  const s = d === 0 ? 0 : d / (1 - Math.abs(2 * l - 1));
  if (s < 0.14 || d < 0.06) return 'neutral';
  let hue = mx === r ? ((g - b) / d) % 6 : mx === g ? (b - r) / d + 2 : (r - g) / d + 4;
  hue = (hue * 60 + 360) % 360;
  if (hue < 45 && l < 0.5 && s < 0.6) return 'brown';
  if (hue < 12 || hue >= 345) return l > 0.7 ? 'pink' : 'red';
  if (hue < 40) return l < 0.42 ? 'brown' : 'orange';
  if (hue < 68) return l < 0.4 && s < 0.6 ? 'brown' : 'yellow';
  if (hue < 165) return 'green';
  if (hue < 255) return 'blue';
  if (hue < 300) return 'purple';
  return 'pink';
}

let fullCard = null, fullLoading = null;
/** Load the full Polyneon card once (→ [{code, hex, name:{nl,fr}, family}]). */
export function loadFullCard(config) {
  if (fullCard) return Promise.resolve(fullCard);
  if (fullLoading) return fullLoading;
  const norm = (rows) => rows.map((r) => (Array.isArray(r) ? { code: String(r[0]), hex: r[1], name: { nl: r[2], fr: r[2] } } : { ...r, code: String(r.code) }))
    .filter((t) => t.code && /^#[0-9a-f]{6}$/i.test(t.hex)).map((t) => ({ ...t, hex: t.hex.toLowerCase(), family: familyOf(t.hex, t.family) }));
  const a = config.assets || {};
  const inline = a.polyneon && (a.polyneon.colors || a.polyneon);
  if (Array.isArray(inline)) { fullCard = norm(inline); return Promise.resolve(fullCard); }
  let url = a.polyneonUrl || '';
  if (!url && a.workerUrl && !/^blob:|^data:/.test(a.workerUrl)) url = a.workerUrl.replace(/dist\/[^/?#]*([?#].*)?$/, 'data/polyneon.json');
  if (!url || typeof fetch !== 'function') return Promise.resolve(null);
  fullLoading = fetch(url, { credentials: 'same-origin' }).then((r) => (r.ok ? r.json() : null)).then((j) => {
    fullCard = j ? norm(j.colors || j) : null;
    return fullCard;
  }).catch(() => null);
  return fullLoading;
}

/** Make sure a thread (possibly from the full card) is known to config.threads (new array → caches refresh). */
export function ensureThread(config, t) {
  if (!t) return null;
  const have = (config.threads || []).find((x) => String(x.code) === String(t.code));
  if (have) return have;
  const add = { code: String(t.code), hex: t.hex, name: t.name, family: t.family, fullCard: true };
  config.threads = [...(config.threads || []), add];
  return add;
}

export function createColorPicker(ctx) {
  const { t, config } = ctx;
  const L = (o) => ctx.L(o);
  const searchId = uid('bps-cp-q');
  const titleId = uid('bps-cp-t');
  const title = h('h2.bps-h3.bps-cp__title', { id: titleId });
  const sub = h('p.bps-cp__sub');
  const q = h('input.bps-input.bps-cp__q', { id: searchId, type: 'search', autocomplete: 'off', placeholder: t('ui.cp.search'), 'aria-label': t('ui.cp.search') });
  const fams = h('div.bps-cp__fams', { role: 'group', 'aria-label': t('ui.cp.families') });
  const grid = h('div.bps-cp__grid', { role: 'listbox', 'aria-label': t('ui.cp.colors') });
  const status = h('p.bps-cp__status', { role: 'status' });
  const allBtn = h('button.bps-btn.bps-btn--small.bps-btn--ghost.bps-cp__all', { type: 'button' });
  const autoBtn = h('button.bps-btn.bps-btn--small.bps-btn--link.bps-cp__auto', { type: 'button', hidden: true });
  const el = h('dialog.bps-dialog.bps-cp', { 'aria-labelledby': titleId },
    h('div.bps-dialog__head', h('div', title, sub), h('button.bps-ibtn.bps-panel__close', { type: 'button', 'aria-label': t('ui.panel.close'), onclick: () => close() }, icon('bad'))),
    h('div.bps-dialog__body', q, fams, grid, h('div.bps-cp__foot', status, autoBtn, allBtn)));
  el.addEventListener('click', (e) => { if (e.target === el) close(); });
  el.addEventListener('close', () => { if (state) { const st = state; state = null; if (st.onClose) st.onClose(); } });

  let state = null, fam = 'all', showAll = false;
  const curated = () => (config.threads || []).filter((x) => !x.fullCard).map((x) => ({ ...x, family: familyOf(x.hex, x.family) }));

  function list() {
    const base = showAll && fullCard ? fullCard : curated();
    const words = q.value.trim().toLowerCase();
    let out = base;
    if (fam !== 'all') out = out.filter((x) => x.family === fam);
    if (words) {
      // search the whole card when it is loaded (a number from the shade card should always be found)
      const pool = fullCard ? [...new Map([...curated(), ...fullCard].map((x) => [x.code, x])).values()] : base;
      out = pool.filter((x) => x.code.includes(words) || [L(x.name), x.name && x.name.nl, x.name && x.name.fr].some((n) => n && String(n).toLowerCase().includes(words)));
    }
    return out;
  }

  function draw() {
    if (!state) return;
    const items = list();
    const cur = String(state.current || '');
    grid.replaceChildren(...items.slice(0, 420).map((x) => h('button.bps-cp__sw' + (x.code === cur ? '.is-on' : ''), {
      type: 'button', role: 'option', 'aria-selected': String(x.code === cur), title: `${x.code} · ${L(x.name)}`, 'data-code': x.code,
      'aria-label': `Polyneon ${x.code} ${L(x.name)}`, onclick: () => pick(x),
    }, h('span.bps-cp__chip', { style: { background: x.hex }, 'aria-hidden': 'true' }), h('span.bps-cp__code', x.code))));
    text(status, items.length ? t('ui.cp.count', { n: items.length }) : t('ui.cp.none'));
    allBtn.hidden = showAll && !!fullCard;
    text(allBtn, t('ui.cp.all', { n: (fullCard && fullCard.length) || 411 }));
    fams.querySelectorAll('button').forEach((b) => { const on = b.dataset.fam === fam; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', String(on)); });
  }

  fams.append(...FAMILIES.map((f) => h('button.bps-cp__fam', { type: 'button', dataset: { fam: f }, 'aria-pressed': 'false', onclick: () => { fam = f; draw(); } },
    f === 'all' ? null : h('span.bps-cp__famdot.is-' + f, { 'aria-hidden': 'true' }), t('ui.cp.fam.' + f))));
  q.addEventListener('input', () => {
    if (q.value.trim() && !fullCard) loadFullCard(config).then(() => draw());
    draw();
  });
  q.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    const first = list()[0];
    if (first) { e.preventDefault(); pick(first); }
  });
  allBtn.addEventListener('click', () => {
    text(allBtn, t('ui.cp.loading'));
    loadFullCard(config).then((c) => { showAll = !!c; if (!c) text(status, t('ui.cp.fail')); draw(); });
  });
  autoBtn.addEventListener('click', () => { if (state && state.onAuto) state.onAuto(); close(); });

  function pick(x) {
    const th = ensureThread(config, x);
    const s = state;
    close();
    if (s && s.onPick) s.onPick(th);
  }
  function close() {
    const st = state;
    state = null;
    if (el.open) { if (el.close) el.close(); else el.removeAttribute('open'); }
    if (st && st.onClose) st.onClose();
    const back = st && st.returnFocus;
    if (back && back.focus && back.isConnected) back.focus({ preventScroll: true });
  }

  /**
   * @param {object} o  {title, sub, current (code), onPick(thread), onAuto?, autoLabel?, returnFocus?}
   */
  function open(o) {
    state = { ...o, returnFocus: o.returnFocus || document.activeElement };
    text(title, o.title || t('ui.cp.title'));
    text(sub, o.sub || t('ui.cp.sub'));
    autoBtn.hidden = !o.onAuto;
    if (o.onAuto) text(autoBtn, o.autoLabel || t('ui.text.auto'));
    q.value = '';
    fam = 'all';
    // a current colour that only exists on the full card: show the full card right away
    showAll = !!(o.current && !curated().some((x) => x.code === String(o.current)) && fullCard);
    if (!el.parentNode) ctx.root.querySelector('.bps-app').appendChild(el);
    draw();
    if (el.showModal) { if (!el.open) el.showModal(); } else el.setAttribute('open', '');
    const on = grid.querySelector('.is-on');
    if (on) on.scrollIntoView({ block: 'nearest' });
    if (!ctx.isMobile || !ctx.isMobile()) q.focus({ preventScroll: true });
  }

  return { el, open, close, get isOpen() { return !!state; } };
}

/** Small swatch button that opens the picker (replaces native selects). */
export function swatchButton(ctx, { hex, label, onClick, cls = '', fk }) {
  return h('button.bps-swbtn' + cls, { type: 'button', onclick: onClick, 'data-fk': fk || null, 'aria-haspopup': 'dialog' },
    h('span.bps-swbtn__chip', { style: { background: hex || '#ccc' }, 'aria-hidden': 'true' }),
    h('span.bps-swbtn__txt', label), icon('chevron'));
}
