/**
 * Patch Studio — browser entry (IIFE `window.BPS`, SPEC §7.8).
 *   BPS.mount(el, config?)  mount one root (config read from <script type="application/json" class="bps-config"> if omitted)
 *   BPS.mountAll()          mount every [data-bps-root] on the page (runs automatically on DOMContentLoaded)
 *   BPS.buildPublicConfig   for the dev page and tests
 *   BPS.version
 */
import { buildPublicConfig } from './shared/config.js';
import { createApp } from './ui/app.js';

export const version = '1.0.0';
export { buildPublicConfig };

function readConfig(el) {
  const s = el.querySelector('script.bps-config');
  if (!s) return null;
  try { return JSON.parse(s.textContent || '{}'); } catch (e) { console.error('[bps] invalid config JSON', e); return null; }
}

export function mount(el, config) {
  if (!el || el._bps) return el && el._bps;
  const cfg = config || readConfig(el);
  if (!cfg || !cfg.techniques) { console.error('[bps] no config for', el); return null; }
  try {
    el._bps = createApp(el, cfg);
    el.setAttribute('data-bps-mounted', '');
    return el._bps;
  } catch (e) {
    // Never leave the page broken: the server skeleton (upload + quote link) stays usable.
    console.error('[bps] mount failed', e);
    return null;
  }
}

export function mountAll(scope) {
  const out = [];
  (scope || document).querySelectorAll('[data-bps-root]').forEach((el) => { const a = mount(el); if (a) out.push(a); });
  return out;
}

if (typeof document !== 'undefined' && !(window.BPS_NO_AUTOMOUNT)) {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => mountAll());
  else mountAll();
  // Elementor editor preview: widgets are (re)rendered after load. Never wait for this on the live page.
  const hook = () => {
    try {
      const ef = window.elementorFrontend;
      if (ef && ef.hooks && ef.isEditMode && ef.isEditMode()) {
        ef.hooks.addAction('frontend/element_ready/bps-patch-studio.default', (scope) => {
          const node = scope && (scope[0] || scope);
          if (node && node.querySelectorAll) mountAll(node);
        });
      }
    } catch (e) { /* not in Elementor */ }
  };
  window.addEventListener('elementor/frontend/init', hook);
}
