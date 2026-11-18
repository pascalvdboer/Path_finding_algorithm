/**
 * Cross-sell — entry (SPEC-EXTRA §2.9). IIFE-bundel: window.BPSCrossSell.
 *
 *   BPSCrossSell.open({ root, designId, token, ref, cartUrl })  → Promise<'added'|'declined'|'none'>  (na "In winkelmand")
 *   BPSCrossSell.openFromCart()                                 → zelfde paneel voor het laatste ontwerp in de mand
 *   BPSCrossSell.mount(el, ctx)                                 → paneel inline in `el` (dev-pagina, eigen pagina's)
 *
 * `open()` lost ALTIJD op (nooit een throw die de bestelling blokkeert): 'none' bij geen kaarten, fout of time-out.
 */
import { fitItem, designFromPayload, defaultQty, pickDefaultVariation } from './fit.js';
import { pick } from './rules.js';
import { priceItem, tierFor, patchOptions } from './pricing.js';
import { buildOffers, cardPrice } from './offers.js';
import { renderPanel } from './panel.js';
import { createApi } from './api.js';
import { loadImage, drawMockup, monochrome } from './mockups.js';
import { bindCartPrompts, isDeclined, setDeclined } from './cart-prompt.js';

export const version = '1.0.0';

const cfg = () => (typeof window !== 'undefined' && window.BPSCrossSellConfig) || {};
let api = null;
const getApi = () => api || (api = createApi(cfg()));
let busy = false;

const shownKey = (id) => 'bps:cs:shown:' + id;
function wasShown(id) {
  try {
    return sessionStorage.getItem(shownKey(id)) === '1';
  } catch (e) {
    return false;
  }
}
function markShown(id) {
  try {
    sessionStorage.setItem(shownKey(id), '1');
  } catch (e) { /* geen opslag: dan hooguit opnieuw tonen */ }
}

function withTimeout(p, ms) {
  return Promise.race([p, new Promise((resolve) => setTimeout(() => resolve(null), ms))]);
}

/** Gravure-versie (laser) vooraf berekenen, zodat elke kaart binnen het tekenbudget blijft. */
function warm(offers, img) {
  if (!img || !offers || !Array.isArray(offers.cards)) return img;
  for (const c of offers.cards) {
    if (c.mockup && c.mockup.mono) {
      try { monochrome(img, c.mockup.engraveHex || '#5a3a22'); } catch (e) { /* tekent dan zonder voorberekening */ }
    }
  }
  return img;
}

function refreshFragments() {
  try {
    if (window.jQuery) window.jQuery(document.body).trigger('wc_fragment_refresh');
  } catch (e) { /* geen jQuery: blokkenthema */ }
}

/**
 * Paneel in een <dialog>. Gsm: bottom sheet; desktop: kaart rechts (CSS).
 * @returns {Promise<'added'|'declined'>}
 */
function showDialog(offers, env, opener) {
  return new Promise((resolve) => {
    const dlg = document.createElement('dialog');
    dlg.className = 'bps-cs bps-cs--dialog';
    dlg.setAttribute('lang', env.locale || 'nl-BE');
    const inner = document.createElement('div');
    inner.className = 'bps-cs__sheet';
    dlg.append(inner);
    document.body.append(dlg);
    let declined = false;
    const finish = () => {
      const r = view.addedAny ? 'added' : 'declined';
      if (r === 'declined' && declined) {
        setDeclined();
        if (env.onDeclinedAll) env.onDeclinedAll();
      }
      dlg.remove();
      document.documentElement.classList.remove('bps-cs-open');
      if (opener && typeof opener.focus === 'function') opener.focus();
      resolve(r);
    };
    const view = renderPanel(inner, offers, Object.assign({}, env, {
      inDialog: true,
      onDecline: () => {
        declined = true;
        dlg.close();
      },
      onToCart: () => {
        dlg.close();
        if (env.onToCart) env.onToCart();
      },
    }));
    // Esc = "Nee, bedankt" (SPEC-EXTRA §2.4).
    dlg.addEventListener('cancel', (e) => {
      e.preventDefault();
      declined = true;
      dlg.close();
    });
    dlg.addEventListener('close', finish, { once: true });
    // Klik op de achtergrond (buiten het blad) = sluiten zoals Esc.
    dlg.addEventListener('click', (e) => {
      if (e.target === dlg) {
        declined = true;
        dlg.close();
      }
    });
    document.documentElement.classList.add('bps-cs-open');
    if (typeof dlg.showModal === 'function') dlg.showModal();
    else dlg.setAttribute('open', '');
    const f = view.firstFocus();
    if (f) f.focus();
    // Mockups opnieuw op de echte breedte (na layout).
    requestAnimationFrame(() => view.redraw());
  });
}

function onlineEnv(offers, opts) {
  const c = cfg();
  const a = getApi();
  return {
    locale: c.locale || 'nl-BE',
    overrides: c.strings || {},
    cartUrl: opts.cartUrl || c.cartUrl || '/winkelmand/',
    onAdd: async (card, body) => {
      const payload = Object.assign({ designId: offers.designId || opts.designId, token: opts.token || '', source: opts.source || null }, body);
      const r = await a.addToCart(payload);
      refreshFragments();
      return r;
    },
    onUndo: async (key) => {
      await a.undo(key);
      refreshFragments();
    },
    onShown: (ids) => ids.forEach((id) => a.event(id, 'shown')),
    onDeclinedAll: () => (offers.cards || []).forEach((cd) => a.event(cd.id, 'declined')),
    onToCart: () => {
      location.href = opts.cartUrl || c.cartUrl || '/';
    },
  };
}

/**
 * Na een geslaagde "In winkelmand" in de studio.
 * @param {{root?:HTMLElement, designId:number, token:string, ref?:string, cartUrl?:string, opener?:HTMLElement}} opts
 */
export async function open(opts = {}) {
  const c = cfg();
  try {
    if (busy || !c.enabled || !opts.designId || isDeclined() || wasShown(opts.designId)) return 'none';
    busy = true;
    const offers = await withTimeout(getApi().offers({ designId: opts.designId, token: opts.token }, c.openTimeoutMs || 4000).catch(() => null), c.openTimeoutMs || 4000);
    if (!offers || !Array.isArray(offers.cards) || !offers.cards.length) return 'none';
    markShown(opts.designId);
    const img = warm(offers, await withTimeout(loadImage(offers.previewUrl), 2500));
    return await showDialog(offers, Object.assign(onlineEnv(offers, opts), { patchImg: img }), opts.opener || document.activeElement);
  } catch (e) {
    return 'none';
  } finally {
    busy = false;
  }
}

/** Vanuit de winkelmand: laatste ontwerp in de eigen WC-sessie, geen token nodig. */
export async function openFromCart(opts = {}) {
  const c = cfg();
  try {
    if (busy || !c.enabled) return 'none';
    busy = true;
    const offers = await getApi().offers({ source: 'cart' }, c.openTimeoutMs || 4000).catch(() => null);
    if (!offers || !Array.isArray(offers.cards) || !offers.cards.length) return 'none';
    const img = warm(offers, await withTimeout(loadImage(offers.previewUrl), 2500));
    return await showDialog(offers, Object.assign(onlineEnv(offers, Object.assign({ source: 'cart' }, opts)), { patchImg: img }), opts.opener || document.activeElement);
  } catch (e) {
    return 'none';
  } finally {
    busy = false;
  }
}

/**
 * Inline paneel (dev-pagina en eigen pagina's). ctx:
 *   { offers }                                     kant-en-klare kaarten (server-antwoord), of
 *   { design:{payload, previewUrl}, cfg, main, catalog, patchUnit, cart?, month? }   offline opbouwen (JS-tweeling)
 *   + { locale, patchImg?, onAdd?, onUndo?, onDecline?, dialog?:boolean }
 * @returns {Promise<Object>} controller van het paneel (of het dialoogresultaat als ctx.dialog)
 */
export async function mount(el, ctx = {}) {
  const locale = ctx.locale || cfg().locale || 'nl-BE';
  const offers = ctx.offers || buildOffers(ctx.design, { cfg: ctx.cfg, main: ctx.main, catalog: ctx.catalog, cart: ctx.cart, patchUnit: ctx.patchUnit, locale, month: ctx.month, overrides: ctx.overrides });
  const patchImg = warm(offers, ctx.patchImg !== undefined ? ctx.patchImg : await withTimeout(loadImage(offers.previewUrl || (ctx.design && ctx.design.previewUrl)), 2500));
  const env = {
    locale,
    overrides: ctx.overrides || {},
    patchImg,
    cartUrl: ctx.cartUrl || '#',
    onAdd: ctx.onAdd || (async () => ({ keys: [] })),
    onUndo: ctx.onUndo || (async () => {}),
    onDecline: ctx.onDecline || (() => {}),
    onToCart: ctx.onToCart || null,
  };
  if (ctx.dialog) return showDialog(offers, env, ctx.opener || null);
  if (!el) return null;
  el.classList.add('bps-cs', 'bps-cs--inline');
  el.setAttribute('lang', locale);
  const view = renderPanel(el, offers, env);
  view.offers = offers;
  return view;
}

/** Terugval: luister naar het studio-event als de studio open() (nog) niet expliciet aanroept. */
function listen() {
  if (typeof document === 'undefined') return;
  document.addEventListener('bps:added-to-cart', (e) => {
    const d = (e && e.detail) || {};
    if (!d.designId || !d.token) return;
    setTimeout(() => {
      if (!busy) open({ designId: d.designId, token: d.token, ref: d.ref, cartUrl: d.cartUrl });
    }, 50);
  });
  const bind = () => bindCartPrompts((o) => openFromCart(o));
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
  else bind();
}

const BPSCrossSell = {
  open,
  openFromCart,
  mount,
  version,
  drawMockup,
  _test: {
    fit: { fitItem, designFromPayload, defaultQty, pickDefaultVariation },
    rules: { pick },
    price: { priceItem, tierFor, patchOptions, cardPrice },
    buildOffers,
  },
};

if (typeof window !== 'undefined') {
  if (!window.BPSCrossSell) {
    window.BPSCrossSell = BPSCrossSell;
    if (!window.BPS_CS_NO_AUTOLISTEN) listen();
  }
}

export const _test = BPSCrossSell._test;
export default BPSCrossSell;
