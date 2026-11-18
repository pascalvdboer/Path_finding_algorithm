/**
 * AI-ontwerpstudio — bundel-entry (SPEC-AI-DIENSTEN §10). Build: `ai/index.js` → `patch-studio-ai.js`,
 * globalName `BPSAI` (zie docs/handoff/ai-services-needs.md §frontend). De studio laadt deze bundel lui bij de eerste
 * tik op een AI-ingang:
 *
 *   const panel = window.BPSAI.mount(sheetOrColumnEl, ctx);   // één keer per studio-root (idempotent)
 *   panel.open({ entry: 'empty-state' });                     // of 'toolbar', 'design-tab', 'history'
 *
 * Geen andere globals dan window.BPSAI. Geen afhankelijkheden. Stijl zit in de bundel (styles.js).
 */
import { createPanel } from './panel.js';

export const version = '1.0.0';

const panels = typeof WeakMap === 'function' ? new WeakMap() : null;

/**
 * @param {HTMLElement} el  container: bottom sheet (gsm) of assistent-kolom (desktop)
 * @param {object} ctx      { host, config, locale, api:{base, nonce?, token?}, getStudio, quickCheck?, renderOnPatch?,
 *                            openCheckout?, mockApi?, presentation?: 'auto'|'sheet'|'inline' }
 * @returns {{ open(o?): void, close(): void, refreshBalance(): Promise<void>, destroy(): void, state(): object }}
 */
export function mount(el, ctx) {
  if (panels && el && panels.has(el)) return panels.get(el);
  const p = createPanel(el, ctx || {});
  const pub = {
    open: p.open,
    close: p.close,
    refreshBalance: p.refreshBalance,
    destroy() {
      p.destroy();
      if (panels) panels.delete(el);
    },
    state: p.state,
    _act: p._act,
  };
  if (panels) panels.set(el, pub);
  return pub;
}

if (typeof window !== 'undefined' && !window.BPSAI) {
  window.BPSAI = { mount, version };
}
