/**
 * Cross-sell — compacte regel in de winkelmand ("Ook op pet, muts of in een kader?"). De server rendert de regel
 * (class-cart-prompt.php) enkel als er kaarten passen; dit script koppelt de knop en verbergt de regel na "Nee, bedankt".
 * Nooit in de kassa.
 */
export const DECLINED_KEY = 'bps:cs:declined';

export function isDeclined() {
  try {
    return sessionStorage.getItem(DECLINED_KEY) === '1';
  } catch (e) {
    return false;
  }
}
export function setDeclined() {
  try {
    sessionStorage.setItem(DECLINED_KEY, '1');
  } catch (e) { /* privé-venster: enkel de WC-sessie onthoudt het */ }
}

/**
 * Koppel alle cart-regels op de pagina.
 * @param {() => Promise<string>} openFromCart
 */
export function bindCartPrompts(openFromCart, doc = document) {
  const nodes = doc.querySelectorAll('[data-bps-cs-cartline]');
  nodes.forEach((n) => {
    if (n.dataset.bpsCsBound) return;
    n.dataset.bpsCsBound = '1';
    const btn = n.querySelector('button');
    // De server rendert de regel verborgen: zonder JS zou de knop niets doen.
    if (isDeclined() || !btn) {
      n.hidden = true;
      return;
    }
    n.hidden = false;
    btn.addEventListener('click', async () => {
      const r = await openFromCart({ opener: btn });
      if (r === 'declined' || r === 'none') n.hidden = true;
      if (r === 'added') {
        // Klassieke mand: herladen toont de nieuwe regels en totalen; blokkenmand ververst via de Store API.
        if (doc.querySelector('.woocommerce-cart-form')) location.reload();
        else doc.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart', { bubbles: true }));
      }
    });
  });
  return nodes.length;
}
