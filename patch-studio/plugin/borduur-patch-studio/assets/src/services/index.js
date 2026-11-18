/**
 * Diensten — kleine ESM-module die de studio importeert (SPEC-AI-DIENSTEN §10.5). Geen eigen bundel.
 *
 *   import { evaluate, card, dismissedFor } from '../services/index.js';
 *   const offer = evaluate(signals, config.services.catalog, dismissedFor(designRef));
 *   if (offer) card(assistantEl, offer, { locale, api: { base, nonce, token }, host, designRef });
 *
 * `evaluate()` is puur en geeft dezelfde uitkomst als `Services\Services::evaluate()` in PHP (gedeelde vectoren:
 * tests/services/offer-vectors.json). Max 1 aanbod, volgorde = volgorde van de catalogus, nooit vooraf gekozen,
 * nooit als er geen echt probleem of voordeel is, en een afwijzing wordt per ontwerp onthouden.
 */
import { createT } from '../shared/i18n.js';
import { roundHalfUp } from '../shared/money.js';
import catalogMessages from './messages.js';
import { createServicesApi } from './api.js';

export const version = '1.0.0';

const langOf = (l) => (String(l || 'nl').toLowerCase().startsWith('fr') ? 'fr' : 'nl');

/** Bedrag zoals Services\Text::money(): "€ 24,95" (nl) / "24,95 €" (fr), harde spatie. */
export function money(amount, lang) {
  const v = roundHalfUp(Number(amount) || 0, 2);
  const [i, d] = v.toFixed(2).split('.');
  const n = i.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + d;
  return langOf(lang) === 'fr' ? n + ' €' : '€ ' + n;
}

function fill(s, vars) {
  return String(s || '').replace(/\{(\w+)\}/g, (m, k) => (vars[k] != null ? String(vars[k]) : m));
}
function label(v, lang) {
  if (v && typeof v === 'object') return String(v[lang] != null ? v[lang] : v.nl != null ? v.nl : '');
  return v == null ? '' : String(v);
}

/**
 * Publieke catalogus uit config/ai-services.json (zelfde vorm als Services::catalog(), enkel ingeschakelde diensten).
 * Nuttig voor de dev-pagina en tests; in de winkel komt de catalogus kant-en-klaar uit config.services.catalog.
 * @param {object} json  inhoud van ai-services.json (eventueel met instellingen samengevoegd)
 * @param {string} lang  nl|fr
 * @param {number} [vatRate=0.21]
 */
export function catalogFromConfig(json, lang, vatRate) {
  const L = langOf(lang);
  const vat = vatRate == null ? 0.21 : Number(vatRate);
  const incl = (x) => roundHalfUp(Number(x || 0) * (1 + vat), 2);
  const out = [];
  const services = (json && json.services) || {};
  for (const id of Object.keys(services)) {
    const s = services[id];
    if (!s || typeof s !== 'object' || !/^[a-z0-9_]{1,32}$/.test(id) || !s.enabled) continue;
    const priceIncl = incl(s.priceExcl);
    const def = {
      id,
      enabled: true,
      kind: s.kind === 'line_option' ? 'line_option' : 'product',
      label: label(s.label || id, L),
      short: label(s.short || '', L),
      reason: label(s.reason || '', L),
      priceIncl,
      priceHtml: money(priceIncl, L),
      leadDays: Number(s.leadDays || 0),
      needsConsent: !!s.needsConsent,
      stages: (s.stages || []).map(String),
      triggers: s.triggers || {},
      freeAlternative: s.freeAlternative ? label(s.freeAlternative, L) || null : null,
    };
    if (s.minExcl != null) def.minIncl = incl(s.minExcl);
    ['percent', 'freeFromQty', 'refundFromQty', 'techniques'].forEach((k) => {
      if (s[k] != null) def[k] = s[k];
    });
    out.push(def);
  }
  return out;
}

/** Past een dienst bij de signalen? Spiegel van Services::matches() (PHP). */
export function matches(def, signals) {
  const t = (def && def.triggers) || {};
  const stage = String((signals && signals.stage) || '');
  if (!(def.stages || []).includes(stage)) return false;
  const a = signals.analysis || {};
  const check = signals.check && typeof signals.check === 'object' ? signals.check : null;
  const tech = String(signals.technique || '');
  const isAi = !!signals.isAi;
  switch (def.id) {
    case 'd1_finish':
      return isAi && check !== null && (check.status || 'ok') !== 'ok' && !check.autoFixAvailable;
    case 'd2_redraw': {
      if (isAi) return false;
      const px = a.minSidePx != null ? Number(a.minSidePx) : null;
      const noise = a.jpegNoise != null ? Number(a.jpegNoise) : null;
      return (
        (px !== null && px > 0 && t.minSidePxBelow != null && px < Number(t.minSidePxBelow)) ||
        (!!t.photoOfLogo && !!a.photoOfLogo) ||
        (noise !== null && t.jpegNoiseAbove != null && noise > Number(t.jpegNoiseAbove))
      );
    }
    case 'd2b_redraw_xl':
      return !isAi && !!t.sketch && !!a.sketch;
    case 'd3_file':
      return !!signals.selfEmbroider;
    case 'd3b_file_addon':
      return stage === 'thanks' && !signals.selfEmbroider;
    case 'd4_rush': {
      const techs = def.techniques || [];
      const need = String(signals.neededBy || '');
      const norm = String(signals.normalDate || '');
      const rush = String(signals.rushDate || '');
      return need !== '' && norm !== '' && rush !== '' && need < norm && rush <= need && (!techs.length || techs.includes(tech));
    }
    case 'd6_sample': {
      const qty = Math.floor(Number(signals.qty) || 0);
      const total = Number(signals.totalIncl) || 0;
      return (
        (t.offerFromQty != null && qty >= Number(t.offerFromQty)) ||
        (t.offerFromTotalIncl != null && total >= Number(t.offerFromTotalIncl)) ||
        (tech !== '' && (t.techniques || []).includes(tech))
      );
    }
    default:
      return false; // extra diensten enkel via de PHP-filter bps_svc_offer_rules
  }
}

/**
 * Beste enkele aanbod, of null. Puur.
 * @param {object} signals   { stage, analysis, check, isAi, qty, totalIncl, technique, neededBy, normalDate, rushDate, selfEmbroider, lang }
 * @param {object[]} catalog ServiceDef-lijst
 * @param {string[]} [dismissed]
 * @returns {null | {serviceId, label, reason, short, priceIncl, priceHtml, freeAlternative, cta, decline, preselected:false, kind, leadDays}}
 */
export function evaluate(signals, catalog, dismissed) {
  const sig = signals || {};
  const L = langOf(sig.lang);
  const t = createT([catalogMessages], L);
  const no = Array.isArray(dismissed) ? dismissed : [];
  for (const def of Array.isArray(catalog) ? catalog : []) {
    if (!def || !def.id || !def.enabled || no.includes(def.id)) continue;
    if (!matches(def, sig)) continue;
    let price = Number(def.priceIncl) || 0;
    if (def.id === 'd4_rush') price = roundHalfUp(Math.max(Number(def.minIncl) || 0, ((Number(sig.totalIncl) || 0) * (def.percent != null ? Number(def.percent) : 25)) / 100), 2);
    if (def.id === 'd7_photo_test' && def.freeFromQty != null && Math.floor(Number(sig.qty) || 0) >= Number(def.freeFromQty)) price = 0;
    const vars = {
      px: sig.analysis && sig.analysis.minSidePx != null ? Math.floor(Number(sig.analysis.minSidePx)) : '',
      qty: Math.floor(Number(sig.qty) || 0),
      refundQty: Math.floor(Number(def.refundFromQty) || 0),
      date: String(sig.rushDate || ''),
      normalDate: String(sig.normalDate || ''),
    };
    const priceHtml = money(price, L);
    return {
      serviceId: String(def.id),
      label: String(def.label || ''),
      reason: fill(def.reason, vars),
      short: String(def.short || ''),
      priceIncl: price,
      priceHtml,
      freeAlternative: def.freeAlternative ? fill(def.freeAlternative, vars) : null,
      cta: t('svc.add', { price: priceHtml }),
      decline: t('svc.no'),
      preselected: false,
      kind: def.kind || 'product',
      leadDays: Number(def.leadDays) || 0,
      humanWork: !!def.needsConsent,
    };
  }
  return null;
}

/* ------------------------------------------------------------------ afwijzingen onthouden */
const dismissKey = (ref) => 'bps_svc_dismiss_' + String(ref || 'none').replace(/[^\w-]/g, '').slice(0, 64);

/** Diensten die de klant voor dit ontwerp al afwees (localStorage, in try/catch). */
export function dismissedFor(designRef) {
  try {
    const v = JSON.parse(window.localStorage.getItem(dismissKey(designRef)) || '[]');
    return Array.isArray(v) ? v.filter((x) => typeof x === 'string') : [];
  } catch (e) {
    return [];
  }
}
export function rememberDismiss(designRef, serviceId) {
  try {
    const list = dismissedFor(designRef);
    if (!list.includes(serviceId)) list.push(serviceId);
    window.localStorage.setItem(dismissKey(designRef), JSON.stringify(list.slice(-20)));
  } catch (e) { /* geen opslag: dan hooguit opnieuw tonen */ }
}

/* ------------------------------------------------------------------ kaart */
const CSS = `
.bps-svc{--s-ink:var(--bps-ink,#14192B);--s-muted:var(--bps-muted,#5D6577);--s-line:var(--bps-line,#E2E8F0);--s-cta:var(--bps-cta,#0B74A6);--s-focus:var(--bps-focus,#5926C5);font-family:var(--bps-font-body,"Assistant",system-ui,sans-serif);color:var(--s-ink);background:var(--bps-bg,#fff);border:1px solid var(--s-line);border-radius:var(--bps-r-card,14px);padding:14px 16px;margin:12px 0;font-size:15px;line-height:1.45}
.bps-svc *{box-sizing:border-box}
.bps-svc :focus-visible{outline:3px solid var(--s-focus);outline-offset:2px}
.bps-svc__kicker{margin:0 0 2px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--s-muted)}
.bps-svc__title{margin:0 0 6px;font-size:16px;font-weight:700}
.bps-svc p{margin:0 0 8px}
.bps-svc__meta{font-size:13px;color:var(--s-muted)}
.bps-svc__alt{font-size:13px;background:var(--bps-bg-soft,#F6F8FB);border-radius:10px;padding:8px 10px}
.bps-svc details{font-size:13px;margin:0 0 10px}
.bps-svc summary{cursor:pointer;color:var(--s-cta);font-weight:600;min-height:44px;display:flex;align-items:center}
.bps-svc__row{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.bps-svc button,.bps-svc a.bps-svc__btn{font:inherit;font-weight:600;min-height:48px;border-radius:var(--bps-r-btn,10px);border:1.5px solid var(--bps-line-2,#CBD5E1);background:#fff;color:var(--s-ink);cursor:pointer;padding:0 12px;display:inline-flex;align-items:center;justify-content:center;text-align:center;text-decoration:none}
.bps-svc button.bps-svc__add{background:var(--s-cta);border-color:var(--s-cta);color:#fff}
.bps-svc button[disabled]{opacity:.55;cursor:not-allowed}
.bps-svc__status{font-size:14px;font-weight:600;margin:8px 0 0}
.bps-svc__status:empty{display:none}
@media (max-width:360px){.bps-svc__row{grid-template-columns:1fr}}
`;
function injectCss(doc) {
  if (!doc || doc.getElementById('bps-svc-css')) return;
  const s = doc.createElement('style');
  s.id = 'bps-svc-css';
  s.textContent = CSS;
  (doc.head || doc.documentElement).appendChild(s);
}
let seq = 0;

/**
 * Toon één dienstkaart in `el` (assistentkanaal). Nooit vooraf aangevinkt; "Nee, dank je" is even groot als
 * "Voeg toe". Events op ctx.host: bps:svc:offer:shown, bps:svc:added, bps:svc:dismissed.
 * @param {HTMLElement} el
 * @param {object} offer  uit evaluate()
 * @param {{ locale?: string, api?: object, host?: HTMLElement, designRef?: string, strings?: object, info?: string }} ctx
 *   ctx.info = config.services.info (legal.svc.info): getoond bij menselijk werk (offer.humanWork).
 * @returns {{ destroy(): void, el: HTMLElement }}
 */
export function card(el, offer, ctx) {
  const c = ctx || {};
  const doc = el.ownerDocument || document;
  injectCss(doc);
  const L = langOf(c.locale);
  const t = createT([catalogMessages], L, c.strings || {});
  const api = c.api && typeof c.api.cart === 'function' ? c.api : createServicesApi(Object.assign({ lang: L }, c.api || {}));
  const host = c.host || el;
  const emit = (name, detail) => {
    try {
      host.dispatchEvent(new CustomEvent(name, { detail, bubbles: true }));
    } catch (e) { /* host weg */ }
  };
  const id = 'bps-svc-' + ++seq;
  const mk = (tag, cls, text) => {
    const n = doc.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  };
  const needsRef = offer.kind === 'line_option';
  const box = mk('section', 'bps-svc');
  box.setAttribute('aria-labelledby', id);
  box.setAttribute('data-service', offer.serviceId);
  box.appendChild(mk('p', 'bps-svc__kicker', t('svc.label')));
  const title = mk('h3', 'bps-svc__title', offer.label);
  title.id = id;
  box.appendChild(title);
  if (offer.reason) box.appendChild(mk('p', null, offer.reason));
  const meta = [offer.short, offer.leadDays > 0 ? t('svc.lead', { n: offer.leadDays }) : ''].filter(Boolean).join(' · ');
  if (meta) box.appendChild(mk('p', 'bps-svc__meta', meta));
  if (offer.freeAlternative) box.appendChild(mk('p', 'bps-svc__alt', t('svc.free_alt', { text: offer.freeAlternative })));
  if (offer.humanWork && c.info) {
    // legal.svc.info (04-juridisch §8): wie het werk doet en dat je het eerst ziet bij je proef.
    const good = mk('details', 'bps-svc__info');
    good.appendChild(mk('summary', null, t('svc.good_to_know')));
    good.appendChild(mk('p', null, String(c.info)));
    box.appendChild(good);
  }
  const why = mk('details');
  why.appendChild(mk('summary', null, t('svc.why')));
  why.appendChild(mk('p', null, t('svc.why.body')));
  box.appendChild(why);
  const row = mk('div', 'bps-svc__row');
  const add = mk('button', 'bps-svc__add', offer.cta || t('svc.add', { price: offer.priceHtml }));
  add.type = 'button';
  const no = mk('button', 'bps-svc__no', offer.decline || t('svc.no'));
  no.type = 'button';
  row.appendChild(add);
  row.appendChild(no);
  box.appendChild(row);
  const status = mk('p', 'bps-svc__status');
  status.setAttribute('role', 'status');
  status.setAttribute('aria-live', 'polite');
  box.appendChild(status);
  if (needsRef && !c.designRef) {
    add.disabled = true;
    status.textContent = t('svc.no_design');
  }

  let busy = false;
  add.addEventListener('click', async () => {
    if (busy || add.disabled) return;
    busy = true;
    add.disabled = true;
    add.setAttribute('aria-busy', 'true');
    status.textContent = t('svc.adding');
    try {
      const res = await api.cart(offer.serviceId, c.designRef || '');
      status.textContent = t('svc.added');
      if (res && res.cartUrl && /^(https?:\/\/|\/)/.test(res.cartUrl)) {
        const a = mk('a', 'bps-svc__btn', t('svc.cart'));
        a.href = res.cartUrl;
        row.replaceChildren(a);
      } else row.replaceChildren();
      emit('bps:svc:added', { serviceId: offer.serviceId, line: (res && res.line) || null });
      try {
        if (window.jQuery) window.jQuery(doc.body).trigger('wc_fragment_refresh');
      } catch (e) { /* blokkenthema */ }
    } catch (e) {
      status.textContent = t('svc.error');
      add.disabled = false;
    }
    add.removeAttribute('aria-busy');
    busy = false;
  });
  no.addEventListener('click', () => {
    rememberDismiss(c.designRef, offer.serviceId);
    if (c.designRef && api.dismiss) api.dismiss(offer.serviceId, c.designRef).catch(() => {});
    emit('bps:svc:dismissed', { serviceId: offer.serviceId });
    destroy();
  });

  function destroy() {
    if (box.parentNode) box.parentNode.removeChild(box);
  }
  el.appendChild(box);
  emit('bps:svc:offer:shown', { serviceId: offer.serviceId });
  return { destroy, el: box };
}
