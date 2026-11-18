/**
 * Patch Studio UI — tiny DOM helpers (no framework).
 */

let uidN = 0;
/** Unique id with prefix (unique per page, also when the tool is placed twice). */
export const uid = (p = 'bps') => `${p}-${++uidN}`;

/**
 * h('button.bps-btn', {type:'button', onclick: fn, 'aria-label': 'x'}, 'text', childNode, [more])
 * Attribute values false/null/undefined are skipped; `class` may be an array; `on*` are listeners;
 * `dataset`, `style` (object) and `props` (assigned as properties) are supported.
 */
export function h(sel, attrs, ...kids) {
  const m = /^([a-z0-9-]+)?((?:\.[\w-]+)*)$/i.exec(sel) || [];
  const el = document.createElement(m[1] || 'div');
  if (m[2]) el.className = m[2].slice(1).split('.').join(' ');
  if (attrs && (typeof attrs !== 'object' || attrs instanceof Node || Array.isArray(attrs))) { kids.unshift(attrs); attrs = null; }
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    if (k === 'class') el.className = [el.className, ...[].concat(v)].filter(Boolean).join(' ');
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
    else if (k === 'props') Object.assign(el, v);
    else if (k === 'html') el.innerHTML = v; // only used with static, trusted markup (inline SVG icons)
    else el.setAttribute(k, v === true ? '' : String(v));
  }
  append(el, kids);
  return el;
}

export function append(el, kids) {
  for (const k of [].concat(kids)) {
    if (k == null || k === false) continue;
    if (Array.isArray(k)) append(el, k);
    else el.appendChild(k instanceof Node ? k : document.createTextNode(String(k)));
  }
  return el;
}

/** Replace children of `el` (keeps `el`). */
export function fill(el, ...kids) {
  el.textContent = '';
  return append(el, kids);
}

/** Set textContent only when it changed (avoids needless live-region chatter and reflow). */
export function text(el, s) {
  s = s == null ? '' : String(s);
  if (el.textContent !== s) el.textContent = s;
  return el;
}

/**
 * Rebuild a container only when `key` changed. Focus is restored on the element with the same
 * data-fk inside the container, so keyboard users never lose their place.
 */
export function keyed(el, key, build) {
  if (el._bpsKey === key) return false;
  el._bpsKey = key;
  const active = document.activeElement;
  const fk = active && el.contains(active) && active.getAttribute('data-fk');
  fill(el, build());
  if (fk) {
    const again = el.querySelector(`[data-fk="${CSS && CSS.escape ? CSS.escape(fk) : fk}"]`);
    if (again) again.focus({ preventScroll: true });
  }
  return true;
}

export function debounce(fn, ms) {
  let t = 0;
  const d = (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  d.cancel = () => clearTimeout(t);
  return d;
}

/** Inline SVG icons (decorative: aria-hidden). */
const ICONS = {
  ok: '<path d="M5 12.5l4.2 4.2L19 7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>',
  warn: '<path d="M12 6v8M12 17.6v.4" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/>',
  bad: '<path d="M7 7l10 10M17 7L7 17" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/>',
  upload: '<path d="M12 16V4m0 0l-5 5m5-5l5 5M4 16v3a1 1 0 001 1h14a1 1 0 001-1v-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
  info: '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 11v6M12 7.5v.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>',
  chat: '<path d="M4 20l1.4-4A8 8 0 1112 20a8 8 0 01-4-1.1z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>',
  arrow: '<path d="M5 12h14m-5-5l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',
  scissors: '<circle cx="6" cy="7" r="2.5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="6" cy="17" r="2.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 8.5L20 17M8 15.5L20 7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
  center: '<circle cx="12" cy="12" r="3" fill="currentColor"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
  fit: '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
  image: '<rect x="3.5" y="4.5" width="17" height="15" rx="3" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="9" cy="10" r="1.8" fill="currentColor"/><path d="M4 17l5-4.5 3.5 3 3-2.5L20 17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
  type: '<path d="M5 7V5h14v2M12 5v14m-3 0h6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
  shape: '<path d="M12 3.5l7.4 4.2v8.6L12 20.5l-7.4-4.2V7.7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
  edge: '<circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-dasharray="2 2"/>',
  colors: '<path d="M12 3.5c4.7 0 8.5 3.4 8.5 7.6 0 2.6-2.1 4.2-4.4 4.2h-1.6c-1 0-1.6 1.1-1.1 2l.3.6c.6 1.2-.3 2.6-1.7 2.6-4.7 0-8.5-3.9-8.5-8.6S7.3 3.5 12 3.5z" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="8" cy="10.5" r="1.3" fill="currentColor"/><circle cx="11.5" cy="7.5" r="1.3" fill="currentColor"/><circle cx="15.5" cy="9" r="1.3" fill="currentColor"/>',
  back: '<path d="M12 4l8.5 4.5L12 13 3.5 8.5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M3.5 12.5L12 17l8.5-4.5M3.5 16.5L12 21l8.5-4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
  ruler: '<path d="M3.5 15.5l12-12 5 5-12 12z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M7.5 11.5l2 2M10.5 8.5l2 2M13.5 5.5l2 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
  undo: '<path d="M9 7L4.5 11.5 9 16M5 11.5h9a5 5 0 010 10h-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
  redo: '<path d="M15 7l4.5 4.5L15 16M19 11.5h-9a5 5 0 000 10h2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
  plus: '<path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>',
  minus: '<path d="M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>',
  trash: '<path d="M5 7h14M10 7V5h4v2M7 7l1 12h8l1-12" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
  spark: '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM18.5 15l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z" fill="currentColor"/>',
  clock: '<circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 7.5V12l3 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
  cart: '<path d="M3 4h2.5l2.2 11h10.6L20.5 7H7" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="19" r="1.5" fill="currentColor"/><circle cx="17" cy="19" r="1.5" fill="currentColor"/>',
  euro: '<path d="M17.5 6.5A6.5 6.5 0 007 12a6.5 6.5 0 0010.5 5.5M4.5 10.5h9M4.5 13.5h8" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>',
  chevron: '<path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',
  back2: '<path d="M15 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',
  arc: '<path d="M4 16a8 8 0 0116 0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M9 11.5l1.5-1M14.5 10.5l1.5 1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
};
export function icon(name, cls = '') {
  const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('aria-hidden', 'true');
  s.setAttribute('focusable', 'false');
  s.setAttribute('class', ('bps-ico ' + cls).trim());
  s.innerHTML = ICONS[name] || '';
  return s;
}

/** Visually hidden but available to screen readers. */
export const sr = (s) => h('span.bps-sr', s);

/** Safe storage access (private mode / blocked storage never breaks the tool). */
export function store(kind) {
  try {
    const s = window[kind];
    const k = '__bps_t';
    s.setItem(k, '1');
    s.removeItem(k);
    return s;
  } catch (e) {
    return null;
  }
}
