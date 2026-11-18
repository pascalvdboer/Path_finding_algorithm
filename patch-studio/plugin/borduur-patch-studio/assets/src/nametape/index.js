/**
 * Naamstroken — entry (SPEC-EXTRA §1.10). Build: IIFE `window.BPSNametape` (handoff: build-entry nametape/index.js).
 * Dev: als ES-module importeren (dev/nametape.html) en zelf mount() aanroepen.
 */
import { createApp } from './ui.js';
import { parseList } from './parse.js';
import { fitText, checkRow } from './fit.js';
import { quote } from './pricing.js';
import { drawTape, toBlob } from './render.js';
import { renderPrint } from './print.js';

export const version = '1.0.0';
const apps = new WeakMap();

/** Config uit <script type="application/json" class="bps-nt-config"> binnen de root. */
function readConfig(el) {
  const node = el.querySelector('script.bps-nt-config');
  if (!node) return null;
  try { return JSON.parse(node.textContent || '{}'); } catch (e) { return null; }
}

/**
 * @param {HTMLElement} el   root (data-bps-nametape-root)
 * @param {object} [cfg]     publieke config; anders uit de root gelezen
 */
export function mount(el, cfg) {
  if (!el) return null;
  if (apps.has(el)) return apps.get(el);
  const config = cfg || readConfig(el);
  if (!config || config.enabled === false) return null;
  // "Namen wijzigen" vanuit de winkelmand: sleutel uit de URL (pagina's zijn gecachet, dus niet server-side).
  try {
    const key = new URLSearchParams(window.location.search).get('bps_nt_edit');
    if (key && /^[a-f0-9]{32}$/.test(key)) config.page = { ...(config.page || {}), editKey: key };
  } catch (e) { /* geen URL */ }
  try {
    const app = createApp(el, config);
    apps.set(el, app);
    el.dispatchEvent(new CustomEvent('bps:nametape-ready', { bubbles: true }));
    return app;
  } catch (e) {
    // Nooit de pagina breken: de server-side skelettekst blijft staan.
    if (typeof console !== 'undefined') console.error('[Patch Studio naamstroken]', e); // eslint-disable-line no-console
    return null;
  }
}

export function mountAll() {
  // Productielijst / proef in het beheer (admin-post bps_nt_print): zelfde renderer, geen app.
  document.querySelectorAll('[data-bps-nt-print]').forEach((el) => {
    try { renderPrint(el); } catch (e) { /* lijst blijft leesbaar zonder tekening */ }
  });
  return Array.from(document.querySelectorAll('[data-bps-nametape-root]')).map((el) => mount(el));
}

export { renderPrint };

export const _test = { parseList, fitText, checkRow, quote, drawTape, toBlob };

if (typeof window !== 'undefined' && typeof document !== 'undefined' && !window.BPSNametapeNoAuto) {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountAll, { once: true });
  else mountAll();
}
