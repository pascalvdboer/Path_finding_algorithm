/**
 * AI-ontwerpstudio — mini-DOM-helpers. Alles via createElement/textContent: klant- en servertekst wordt nooit als
 * HTML ingevoegd (geen XSS). Enkel de vaste iconen hieronder (eigen, statische SVG) gaan via innerHTML.
 */
import { roundHalfUp } from '../shared/money.js';

/**
 * h('button', { class: 'a-btn', onclick: fn, 'aria-label': '…' }, 'tekst', kindElement, [lijst])
 * Attributen met waarde false/null/undefined worden weggelaten; `on*` = event-listener; `hidden: true` werkt.
 */
export function h(tag, attrs, ...kids) {
  const el = document.createElement(tag);
  if (attrs) {
    for (const k of Object.keys(attrs)) {
      const v = attrs[k];
      if (v == null || v === false) continue;
      if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
      else if (k === 'class') el.className = v;
      else if (k === 'value') el.value = v;
      else if (k === 'checked') el.checked = !!v;
      else if (k === 'html') el.innerHTML = v; // enkel voor de vaste iconen in dit bestand
      else el.setAttribute(k, v === true ? '' : String(v));
    }
  }
  append(el, kids);
  return el;
}

function append(el, kids) {
  for (const c of kids) {
    if (c == null || c === false) continue;
    if (Array.isArray(c)) append(el, c);
    else el.appendChild(typeof c === 'object' ? c : document.createTextNode(String(c)));
  }
}

export const ICON = {
  spark: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2l1.9 5.6L19.5 9.5l-5.6 1.9L12 17l-1.9-5.6L4.5 9.5l5.6-1.9zM19 14l.9 2.6 2.6.9-2.6.9L19 21l-.9-2.6-2.6-.9 2.6-.9zM5 15l.7 1.8 1.8.7-1.8.7L5 20l-.7-1.8-1.8-.7 1.8-.7z"/></svg>',
  close: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" fill="none"/></svg>',
  back: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>',
  send: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M5 12h13M13 6l6 6-6 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>',
  stop: '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle cx="24" cy="24" r="21" fill="#FFF5E3" stroke="#B45309" stroke-width="3"/><path d="M24 13v14" stroke="#B45309" stroke-width="4" stroke-linecap="round"/><circle cx="24" cy="34" r="2.6" fill="#B45309"/></svg>',
  ok: '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle cx="24" cy="24" r="21" fill="#EAF8F0" stroke="#15803D" stroke-width="3"/><path d="M15 25l6 6 12-13" stroke="#15803D" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>',
};

/** Focusbare elementen binnen een container (voor de focus-trap). */
export function focusables(root) {
  return Array.from(
    root.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),textarea:not([disabled]),select:not([disabled]),summary,[tabindex]:not([tabindex="-1"])')
  ).filter((el) => !el.closest('[hidden]') && !el.closest('[inert]') && el.getClientRects().length > 0);
}

/** Bedrag zoals de server (Services\Text::money): "€ 4,95" (nl) / "4,95 €" (fr), harde spatie. */
export function money(amount, lang) {
  const v = roundHalfUp(Number(amount) || 0, 2);
  const [i, d] = v.toFixed(2).split('.');
  const n = i.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + d;
  return lang === 'fr' ? n + ' €' : '€ ' + n;
}
