/**
 * Naamstroken — gebruikersinterface: 3 schermpjes (Namen → Stijl → Controle), sticky voorbeeld bovenaan, vaste balk onderaan
 * (SPEC-EXTRA §1.2). Imports: enkel eigen bestanden en ../shared/*.js.
 */
import { createT, langOf } from '../shared/i18n.js';
import { formatMoney, formatNumber } from '../shared/money.js';
import { contrastRatio } from '../shared/color-math.js';
import messages from './messages.js';
import { parseList, mergeDuplicates, findDuplicates, parseBlood } from './parse.js';
import { checkRow, resolveFormat, applyCase, rowLines, bloodText, fontById, formatById, bestSplit, cleanText } from './fit.js';
import { quote as computeQuote } from './pricing.js';
import { renderInto, toBlob } from './render.js';
import { createStore, clearSaved, defaultStyle } from './state.js';

const BLOOD_GROUPS = [['O', '+'], ['O', '-'], ['A', '+'], ['A', '-'], ['B', '+'], ['B', '-'], ['AB', '+'], ['AB', '-']];
const MOBILE_MQ = '(max-width: 899px)';
let uid = 0;

const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const enabled = (x) => x && x.enabled !== false;
const inStock = (x) => enabled(x) && x.inStock !== false;

/**
 * Mount de naamstrookmodule.
 * @param {HTMLElement} root
 * @param {object} cfg  publieke config (Nametape\Config::public_config of dev-pagina)
 */
export function createApp(root, cfg) {
  const id = 'bpsnt' + ++uid;
  const locale = cfg.locale || 'nl-BE';
  const lang = langOf(locale);
  const t = createT([messages], locale, (cfg.strings && (cfg.strings[lang] || cfg.strings)) || {});
  const metrics = cfg.metrics || { fonts: {} };
  const L = (obj) => (obj && typeof obj === 'object' ? obj[lang] || obj.nl || '' : String(obj || ''));
  const money = (x) => formatMoney(x, locale);
  const mm = (x) => formatNumber(x, locale, 1);
  const store = createStore(cfg);
  const mq = typeof window.matchMedia === 'function' ? window.matchMedia(MOBILE_MQ) : { matches: false, addEventListener() {} };
  const fontsState = { loaded: false, loading: false };
  let maxVvh = 0;
  let liveTimer = 0, quoteTimer = 0, lastQuoteKey = '';
  let io = null;

  /* ------------------------------------------------------------------ afgeleide gegevens */

  function activeRows(s = store.get()) {
    if (s.mode === 'single') return [s.rows[0] || { id: 'r1', name: '', line2: '', blood: null, qty: 1 }];
    return s.rows;
  }
  function hasBlood(rows) { return rows.some((r) => r.blood && r.blood.group); }
  /** Toestemmingstekst bloedgroep: de server levert de exacte zin (die hij hasht), messages.js is de terugval. */
  function bloodConsentCfg() { return (cfg.privacy && cfg.privacy.bloodConsent) || {}; }
  function bloodConsentText() { return bloodConsentCfg().text || t('nt.s3.bloodConsent'); }
  /** Algemene fout + hulpzin uit de ingevulde contactvelden (Help_Line), nooit een vaste telefoon/WhatsApp. */
  function genericError() { return [t('nt.error.generic'), cfg.helpLine || ''].filter(Boolean).join(' '); }
  function hasLine2(rows) { return rows.some((r) => cleanText(r.line2) !== ''); }

  function bloodModeFor(s, rows, format) {
    if (!hasBlood(rows)) return null;
    const m = s.style.bloodMode;
    if (m === 'line2' && format && format.lines >= 2 && !hasLine2(rows)) return 'line2';
    if (m === 'inline' || m === 'separate') return m;
    return format && format.lines >= 2 && !hasLine2(rows) ? 'line2' : 'separate';
  }

  function batchFor(s = store.get(), override = {}) {
    const st = { ...s.style, ...override };
    const rows = activeRows(s).filter((r) => cleanText(r.name) !== '' || cleanText(r.line2) !== '');
    const format = resolveFormat(st, cfg);
    return {
      v: 1,
      locale,
      format: st.format,
      custom: st.format === 'custom' ? st.custom : null,
      fabric: st.fabric,
      letter: st.letter,
      border: st.border,
      borderColor: st.border === 'satin_color' ? st.borderColor || st.letter : null,
      font: st.font,
      uppercase: !!st.uppercase,
      backing: st.backing,
      loop: !!st.loop && st.backing === 'hook',
      bloodMode: bloodModeFor({ ...s, style: st }, rows, format),
      rows: rows.map((r) => ({ name: cleanText(r.name), line2: cleanText(r.line2), blood: r.blood || null, qty: r.qty || 1, _id: r.id })),
      orgNote: s.orgNote || '',
    };
  }

  function ctxFor() {
    const p = cfg.page || {};
    return {
      patchPoolQty: Number(p.poolQty) || 0,
      otherNametapePieces: Number(p.otherNametapePieces) || 0,
      otherItemsInclVat: Number(p.otherItemsInclVat) || 0,
      vatRate: (cfg.vat && cfg.vat.rate) || 0.21,
      tiers: cfg.tiers || { steps: [] },
      shipping: cfg.shipping || null,
      locale,
    };
  }

  function computeAll(s = store.get()) {
    const batch = batchFor(s);
    const checks = new Map();
    for (const r of batch.rows) checks.set(r._id, checkRow(r, batch, cfg, metrics));
    let q = computeQuote(batch, ctxFor(), cfg);
    if (s.serverQuote && s.serverQuote.key === quoteKey(batch)) q = s.serverQuote.quote;
    return { batch, checks, quote: q };
  }

  function quoteKey(batch) {
    return JSON.stringify({ ...batch, rows: batch.rows.map(({ _id, ...r }) => r) });
  }

  function styleObjects(st) {
    const fabric = (cfg.fabrics || []).find((f) => f.id === st.fabric) || (cfg.fabrics || [])[0] || { hex: '#1c1c1c' };
    const letter = (cfg.letterColors || []).find((c) => c.id === st.letter) || { hex: '#ffffff' };
    const border = (cfg.borders || []).find((b) => b.id === st.border) || { borderMm: 0 };
    const bc = st.border === 'satin_color' ? (cfg.letterColors || []).find((c) => c.id === (st.borderColor || st.letter)) || letter : letter;
    const font = fontById(cfg, st.font) || fontById(cfg, 'block') || (cfg.fonts || [])[0] || { id: 'block', family: 'sans-serif' };
    return { fabric, letter, border, borderHex: border.borderMm > 0 ? bc.hex : null, font };
  }

  function tapeSpec(row, batch, check, opts = {}) {
    const st = store.get().style;
    const so = styleObjects(st);
    const fm = (metrics.fonts || {})[so.font.id] || {};
    const format = opts.format || resolveFormat(batch, cfg) || { w: 100, h: 25, lines: 1 };
    const lines = opts.lines || rowLines(row, batch.bloodMode, cfg).map((l) => applyCase(l, batch.uppercase, so.font));
    const empty = !lines.some((l) => l);
    return {
      format,
      fabric: so.fabric,
      letterHex: so.letter.hex,
      borderHex: so.borderHex,
      borderMm: so.border.borderMm,
      font: { ...so.font, capHeightEm: fm.capHeightEm || 0.72, loadedFamily: fontsState.loaded ? 'BPSNT-' + so.font.id : null },
      lines: empty ? [t('nt.preview.empty')] : lines,
      capMm: empty ? Math.min(6, format.h / 3) : (opts.capMm != null ? opts.capMm : (check && check.capMm) || 0),
      lineGapRatio: (cfg.fit && cfg.fit.lineGapRatio) || 0.35,
      placeholder: empty,
    };
  }

  function formatLabel(st) {
    if (st.format === 'custom' && st.custom) return `${mm(st.custom.w / 10)} × ${mm(st.custom.h / 10)} cm`;
    const f = formatById(cfg, st.format);
    return f ? L(f.label) || f.id : '';
  }

  /* ------------------------------------------------------------------ lettertypes */

  function loadFonts() {
    if (fontsState.loading || typeof FontFace === 'undefined') return;
    const base = cfg.assets && cfg.assets.fontsUrl;
    if (!base) return;
    fontsState.loading = true;
    const jobs = (cfg.fonts || []).filter(enabled).map((f) => {
      const ff = new FontFace('BPSNT-' + f.id, `url(${base}${f.file}) format("woff2")`, { weight: String(f.weight || 400), display: 'swap' });
      return ff.load().then((x) => { document.fonts.add(x); return true; }).catch(() => false);
    });
    Promise.all(jobs).then(() => {
      fontsState.loaded = true;
      root.classList.add('bps-nt--fonts');
      drawPreview();
      redrawMinis(true);
    });
  }

  /* ------------------------------------------------------------------ skelet */

  root.innerHTML = '';
  root.classList.add('bps-nametape--ready');
  const app = document.createElement('div');
  app.className = 'bps-nt';
  app.innerHTML = `
    <section class="bps-nt__preview" aria-label="${esc(t('nt.title'))}">
      <h2 class="bps-nt__title">${esc(t('nt.title'))}</h2>
      <div class="bps-nt__stage"><canvas class="bps-nt__canvas" role="img" aria-label=""></canvas></div>
      <div class="bps-nt__who">
        <button type="button" class="bps-nt__nav" data-act="prev" aria-label="${esc(t('nt.preview.prev'))}">\u2039</button>
        <span class="bps-nt__whoText" aria-live="off"></span>
        <button type="button" class="bps-nt__nav" data-act="next" aria-label="${esc(t('nt.preview.next'))}">\u203A</button>
      </div>
    </section>
    <div class="bps-nt__panel">
      <nav class="bps-nt__steps" aria-label="${esc(t('nt.steps'))}"><ol></ol></nav>
      <div class="bps-nt__main" tabindex="-1"></div>
      <div class="bps-nt__bar">
        <div class="bps-nt__barInfo"><span class="bps-nt__barCount"></span><span class="bps-nt__barPrice"></span></div>
        <button type="button" class="bps-nt__cta" data-act="cta"></button>
      </div>
    </div>
    <div class="bps-nt__live bps-nt__sr" role="status" aria-live="polite"></div>`;
  root.appendChild(app);
  const el = {
    canvas: app.querySelector('.bps-nt__canvas'),
    stage: app.querySelector('.bps-nt__stage'),
    preview: app.querySelector('.bps-nt__preview'),
    who: app.querySelector('.bps-nt__whoText'),
    prev: app.querySelector('[data-act="prev"]'),
    next: app.querySelector('[data-act="next"]'),
    steps: app.querySelector('.bps-nt__steps ol'),
    main: app.querySelector('.bps-nt__main'),
    count: app.querySelector('.bps-nt__barCount'),
    price: app.querySelector('.bps-nt__barPrice'),
    cta: app.querySelector('.bps-nt__cta'),
    live: app.querySelector('.bps-nt__live'),
  };

  /* ------------------------------------------------------------------ lay-out (gsm: vaste hoogte = visualViewport) */

  function layout() {
    const vv = window.visualViewport;
    if (!mq.matches) {
      app.classList.remove('bps-nt--app', 'bps-nt--kbd');
      app.style.removeProperty('--bps-nt-h');
      app.style.removeProperty('--bps-nt-pv');
      drawPreview();
      return;
    }
    const h = Math.round(vv ? vv.height : window.innerHeight);
    maxVvh = Math.max(maxVvh, h);
    const a = document.activeElement;
    const typing = a && app.contains(a) && (a.tagName === 'TEXTAREA' || (a.tagName === 'INPUT' && /^(text|search|number|email|tel)$/.test(a.type)));
    const kbd = typing && h < maxVvh * 0.8;
    app.classList.add('bps-nt--app');
    app.classList.toggle('bps-nt--kbd', !!kbd);
    app.style.setProperty('--bps-nt-h', h + 'px');
    app.style.setProperty('--bps-nt-pv', Math.round(h * (kbd ? 0.2 : 0.34)) + 'px');
    if (document.scrollingElement && app.getBoundingClientRect().top <= 0 && document.scrollingElement.scrollTop > 0 && root.offsetTop === 0) {
      document.scrollingElement.scrollTop = 0;
    }
    drawPreview();
  }

  /* ------------------------------------------------------------------ voorbeeld */

  function drawPreview() {
    const s = store.get();
    const { batch, checks } = computeAll(s);
    const rows = batch.rows;
    const n = rows.length;
    const idx = Math.min(s.selected, Math.max(0, n - 1));
    const row = rows[idx] || { name: '', line2: '', blood: null, qty: 1 };
    const chk = row._id ? checks.get(row._id) : null;
    const spec = tapeSpec(row, batch, chk && chk.fit);
    const box = el.stage.getBoundingClientRect();
    const t0 = (typeof performance !== 'undefined' ? performance.now() : 0);
    renderInto(el.canvas, spec, { maxCssW: Math.max(120, box.width - 16), maxCssH: Math.max(40, box.height - 8) });
    root.dataset.drawMs = String(Math.round(((typeof performance !== 'undefined' ? performance.now() : 0) - t0) * 10) / 10);
    const so = styleObjects(s.style);
    el.canvas.setAttribute('aria-label', t('nt.preview.aria', {
      format: formatLabel(s.style),
      fabric: L(so.fabric.label).toLowerCase(),
      letter: L(so.letter.label).toLowerCase(),
      text: spec.placeholder ? t('nt.preview.empty') : spec.lines.join(' / '),
    }));
    const name = spec.placeholder ? '' : spec.lines[0];
    el.who.textContent = n > 1 ? `${name} · ${t('nt.preview.of', { i: idx + 1, n })}` : name;
    el.prev.hidden = el.next.hidden = n <= 1;
    el.prev.disabled = idx <= 0;
    el.next.disabled = idx >= n - 1;
  }

  /* ------------------------------------------------------------------ balk + stappen */

  function canLeave1(s, all) { return all.batch.rows.some((r) => cleanText(r.name) !== '') || pasteWaiting(s); }

  /** Getypte (nog niet overgenomen) namen in het lijstveld tellen ook: "Verder" neemt ze eerst over. */
  function pasteWaiting(s) { return !!(s.showPaste && s.pasteText && s.pasteText.trim()); }

  /** De knop blijft aanraakbaar (aria-disabled): een tik toont wat nog ontbreekt in plaats van niets te doen. */
  function ctaBlocked() { return el.cta.disabled || el.cta.getAttribute('aria-disabled') === 'true'; }

  /** Tik op een geblokkeerde knop: breng de klant naar wat er nog moet gebeuren. */
  function showWhatsMissing() {
    const s = store.get();
    let target = null;
    if (s.step === 1) target = el.main.querySelector('[data-bind="name"], [data-fk="pastearea"]');
    else if (s.step === 3) {
      target = el.main.querySelector('.bps-nt__row.is-error [data-act="rowfix"], [data-act="fix"], .bps-nt__row.is-error .bps-nt__rowMain');
      if (!target && !s.rights) target = el.main.querySelector('[data-act="rights"]');
      if (!target && !s.bloodConsent) target = el.main.querySelector('[data-act="bloodConsent"]');
    }
    if (!target) return;
    // Alleen het paneel scrollen (op gsm moet het voorbeeld bovenaan blijven staan, pagina-scrollTop 0).
    const m = el.main;
    if (m.scrollHeight > m.clientHeight + 1) {
      const dy = target.getBoundingClientRect().top - m.getBoundingClientRect().top - m.clientHeight / 2;
      m.scrollTop += dy;
    } else target.scrollIntoView({ block: 'center' });
    target.focus({ preventScroll: true });
    const why = el.main.querySelector('.bps-nt__why');
    if (why) { why.classList.remove('is-flash'); void why.offsetWidth; why.classList.add('is-flash'); }
  }

  function blockers(s, all) {
    const out = [];
    let bad = 0;
    for (const c of all.checks.values()) if (c.fit.status === 'error' || (c.bloodFit && c.bloodFit.status === 'error')) bad++;
    if (!all.batch.rows.length) out.push(t('nt.bar.needName'));
    if (bad) out.push(t('nt.s3.blocked'));
    if (!s.rights) out.push(t('nt.s3.needRights'));
    if (hasBlood(all.batch.rows) && !s.bloodConsent) out.push(t('nt.s3.needBloodConsent'));
    return out;
  }

  function renderBar(all) {
    const s = store.get();
    const q = all.quote;
    const waiting = pasteWaiting(s);
    let hint = '';
    if (!all.batch.rows.length && !waiting) {
      el.count.textContent = t('nt.bar.needName');
      el.price.textContent = '';
    } else if (waiting && s.step === 1) {
      el.count.textContent = t('nt.bar.pasteWaiting');
      el.price.textContent = '';
    } else {
      el.count.textContent = t('nt.bar.count', { n: q.pieces });
      el.price.textContent = q.ok ? ' · ' + (s.step === 3 ? t('nt.bar.total', { price: money(q.totalIncl) }) : t('nt.bar.perPiece', { price: money(q.unitIncl) })) : '';
    }
    let label = t('nt.next'), blocked = false, busy = false;
    if (s.step === 1) blocked = !canLeave1(s, all);
    if (s.step === 3) {
      label = s.status === 'busy' ? t('nt.busy') : s.editKey ? t('nt.update') : t('nt.addToCart');
      busy = s.status === 'busy';
      const why = blockers(s, all);
      blocked = why.length > 0;
      if (blocked && !busy) hint = why[0];
    }
    if (s.status === 'added') { label = t('nt.toCart'); blocked = false; busy = false; hint = ''; }
    // Op scherm 3 toont de balk wat er nog ontbreekt (staat anders onderaan, buiten beeld op gsm).
    el.count.parentNode.classList.toggle('is-hint', !!hint);
    if (hint) { el.count.textContent = hint; el.price.textContent = ''; }
    el.cta.textContent = label;
    el.cta.disabled = busy;
    if (blocked) el.cta.setAttribute('aria-disabled', 'true'); else el.cta.removeAttribute('aria-disabled');
    el.cta.setAttribute('aria-describedby', id + '-why');
    clearTimeout(liveTimer);
    liveTimer = setTimeout(() => {
      el.live.textContent = all.batch.rows.length && q.ok ? `${t('nt.bar.count', { n: q.pieces })}, ${t('nt.bar.perPiece', { price: money(q.unitIncl) })}, ${t('nt.s3.total')} ${money(q.totalIncl)}` : '';
    }, 500);
  }

  function renderSteps(all) {
    const s = store.get();
    const ok1 = canLeave1(s, all);
    el.steps.innerHTML = [1, 2, 3].map((n) => {
      const cur = s.step === n;
      const reach = n === 1 || (ok1 && n <= 3);
      return `<li><button type="button" data-step="${n}" ${cur ? 'aria-current="step"' : ''} ${reach ? '' : 'disabled'} class="${cur ? 'is-current' : ''}${n < s.step ? ' is-done' : ''}"><span class="bps-nt__stepNo">${n}</span> ${esc(t('nt.step' + n))}</button></li>`;
    }).join('');
  }

  /* ------------------------------------------------------------------ hulp voor statussen */

  function fitMessage(row, chk, st) {
    const f = chk.fit;
    const fmtL = formatLabel(st);
    if (f.status === 'ok') return t('nt.fit.ok', { mm: mm(f.capMm) });
    const c = f.codes[0];
    const font = fontById(cfg, st.font);
    const name = applyCase(row.name, st.uppercase, font) || '…';
    switch (c) {
      case 'small_text': return t('nt.fit.small_text', { mm: mm(f.capMm) });
      case 'too_long': return t('nt.fit.too_long', { name, format: fmtL });
      case 'bad_chars': return t('nt.fit.bad_chars', { chars: f.badChars.join(' ') });
      case 'too_many_chars': return t('nt.fit.too_many_chars', { max: (cfg.fit && cfg.fit.maxCharsPerLine) || 30 });
      case 'line2_no_room': return t('nt.fit.line2_no_room', { format: fmtL });
      case 'too_many_lines': return t('nt.fit.too_many_lines', { format: fmtL, lines: (resolveFormat(st, cfg) || {}).lines || 1 });
      default: return t('nt.fit.' + c);
    }
  }
  function worst(chk) {
    if (!chk) return 'ok';
    const a = chk.fit.status, b = chk.bloodFit ? chk.bloodFit.status : 'ok';
    const r = { error: 0, warn: 1, ok: 2 };
    return r[a] <= r[b] ? a : b;
  }
  function dot(status) {
    const icon = status === 'ok' ? '✓' : status === 'warn' ? '!' : '×';
    return `<span class="bps-nt__dot is-${status}" aria-hidden="true">${icon}</span><span class="bps-nt__sr">${esc(t('nt.status.' + status))}</span>`;
  }

  /* ------------------------------------------------------------------ scherm 1: namen */

  function bloodOptions(sel) {
    const notation = (cfg.blood && cfg.blood.notation) || 'O POS';
    const cur = sel ? sel.group + sel.rh : '';
    return `<option value="">${esc(t('nt.s1.bloodNone'))}</option>` + BLOOD_GROUPS.map(([g, rh]) => {
      const v = g + rh;
      return `<option value="${v}" ${v === cur ? 'selected' : ''}>${esc(bloodText({ group: g, rh }, notation))}</option>`;
    }).join('');
  }

  function screen1(s, all) {
    const r0 = s.rows[0] || {};
    const list = s.mode === 'list';
    let html = `<h3 class="bps-nt__h">${esc(t('nt.s1.title'))}</h3>`;
    if (!list) {
      html += `
      <div class="bps-nt__field">
        <label for="${id}-name" class="bps-nt__label">${esc(t('nt.s1.nameLabel'))}</label>
        <input id="${id}-name" class="bps-nt__input bps-nt__input--big" type="text" data-fk="name" data-bind="name" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="60" placeholder="${esc(t('nt.s1.namePh'))}" value="${esc(r0.name || '')}">
        <p class="bps-nt__hint" id="${id}-name-st" data-row-status="${esc(r0.id)}"></p>
      </div>
      <details class="bps-nt__details" data-fk="extra" ${s.showExtra ? 'open' : ''}>
        <summary>${esc(t('nt.s1.extra'))}</summary>
        <div class="bps-nt__field">
          <label for="${id}-l2" class="bps-nt__label">${esc(t('nt.s1.line2'))}</label>
          <input id="${id}-l2" class="bps-nt__input" type="text" data-fk="l2" data-bind="line2" autocomplete="off" maxlength="60" placeholder="${esc(t('nt.s1.line2Ph'))}" value="${esc(r0.line2 || '')}">
        </div>
        <div class="bps-nt__field">
          <label for="${id}-bl" class="bps-nt__label">${esc(t('nt.s1.blood'))}</label>
          <select id="${id}-bl" class="bps-nt__input" data-fk="bl" data-bind="blood">${bloodOptions(r0.blood)}</select>
        </div>
      </details>`;
    } else {
      html += listHtml(s, all, false);
    }
    html += `
      <label class="bps-nt__switch"><input type="checkbox" role="switch" data-fk="upper" data-act="uppercase" ${s.style.uppercase ? 'checked' : ''}><span>${esc(t('nt.s1.uppercase'))}</span></label>`;
    html += noticesHtml(s);
    if (s.pending) {
      html += `<div class="bps-nt__ask" role="group" aria-label="${esc(t('nt.s1.replaceAsk'))}"><p>${esc(t('nt.s1.replaceAsk'))}</p>
        <button type="button" class="bps-nt__btn" data-act="pending-replace">${esc(t('nt.s1.replace'))}</button>
        <button type="button" class="bps-nt__btn" data-act="pending-append">${esc(t('nt.s1.append'))}</button></div>`;
    }
    html += `
      <details class="bps-nt__details bps-nt__paste" data-fk="paste" ${s.showPaste ? 'open' : ''}>
        <summary>${esc(t(list ? 'nt.s1.pasteMore' : 'nt.s1.paste'))}</summary>
        <label for="${id}-paste" class="bps-nt__label">${esc(t('nt.s1.pasteLabel'))}</label>
        <p class="bps-nt__hint" id="${id}-paste-help">${esc(t('nt.s1.pasteHelp'))}</p>
        <textarea id="${id}-paste" class="bps-nt__input bps-nt__textarea" rows="8" data-fk="pastearea" aria-describedby="${id}-paste-help" spellcheck="false" autocapitalize="characters" autocomplete="off">${esc(s.pasteText)}</textarea>
        <p class="bps-nt__row-actions"><button type="button" class="bps-nt__btn bps-nt__btn--primary" data-act="apply-paste">${esc(t('nt.s1.pasteApply'))}</button></p>
        <label class="bps-nt__btn bps-nt__file"><input type="file" accept=".csv,.txt,text/csv,text/plain" data-act="csv">${esc(t('nt.s1.csv'))}</label>
      </details>`;
    if (list) html += `<p class="bps-nt__row-actions"><button type="button" class="bps-nt__link" data-act="single">${esc(t('nt.s1.single'))}</button></p>`;
    return html;
  }

  function noticesHtml(s) {
    let html = '';
    for (const n of s.notices) {
      const text = t(n.key, n.params || {});
      const extra = n.key === 'nt.parse.tooMany' && cfg.quoteUrl ? ` <a href="${esc(cfg.quoteUrl)}">${esc(t('nt.parse.quoteLink'))}</a>` : '';
      html += `<p class="bps-nt__notice is-${n.level || 'info'}">${esc(text)}${extra}</p>`;
    }
    const dups = findDuplicates(activeRows(s));
    for (const d of dups) {
      html += `<p class="bps-nt__notice is-info">${esc(t('nt.parse.dup', { count: d.count, name: d.name }))}
        <button type="button" class="bps-nt__btn bps-nt__btn--sm" data-act="merge" data-ids="${esc(d.ids.join(','))}">${esc(t('nt.parse.merge', { count: d.count }))}</button></p>`;
    }
    return html;
  }

  function listHtml(s, all) {
    const rows = s.rows;
    let html = `<p class="bps-nt__listTitle">${esc(t('nt.s1.listTitle', { n: rows.length }))}</p><ul class="bps-nt__list">`;
    rows.forEach((r, i) => {
      const chk = all.checks.get(r.id);
      const st = worst(chk);
      const bt = r.blood ? bloodText(r.blood, cfg.blood && cfg.blood.notation) : '';
      html += `<li class="bps-nt__li${i === s.selected ? ' is-selected' : ''}">
        <button type="button" class="bps-nt__liMain" data-act="select" data-i="${i}">${dot(st)}
          <span class="bps-nt__liName">${esc(r.name || '—')}</span>
          ${r.line2 ? `<span class="bps-nt__liSub">${esc(r.line2)}</span>` : ''}
          ${bt ? `<span class="bps-nt__liSub">${esc(bt)}</span>` : ''}
          ${r.qty > 1 ? `<span class="bps-nt__liQty">× ${r.qty}</span>` : ''}
        </button>
        <button type="button" class="bps-nt__x" data-act="remove" data-id="${esc(r.id)}" aria-label="${esc(t('nt.s1.remove', { name: r.name }))}">×</button>
      </li>`;
    });
    return html + '</ul>';
  }

  /* ------------------------------------------------------------------ scherm 2: stijl */

  function screen2(s, all) {
    const st = s.style;
    const so = styleObjects(st);
    const ctx = ctxFor();
    const rows = all.batch.rows.length ? all.batch.rows : [{ name: 'X', line2: '', blood: null, qty: 1 }];
    const unitFor = (override) => {
      const q = computeQuote({ ...batchFor(s, override), rows }, ctx, cfg);
      return q.ok ? money(q.unitIncl) : '';
    };
    const formats = (cfg.formats || []).filter((f) => enabled(f) && (!f.bloodFormat || all.batch.bloodOnly || true));
    const maxW = Math.max(...formats.map((f) => f.w), 120);
    let html = `<h3 class="bps-nt__h">${esc(t('nt.s2.title'))}</h3>`;

    // 1. Formaat
    html += `<fieldset class="bps-nt__fs"><legend>${esc(t('nt.s2.format'))}</legend><div class="bps-nt__formats">`;
    for (const f of formats) {
      const w = Math.round((f.w / maxW) * 88), h = Math.round((f.h / maxW) * 88);
      html += `<label class="bps-nt__card${st.format === f.id ? ' is-on' : ''}">
        <input type="radio" name="${id}-fmt" value="${esc(f.id)}" data-act="format" data-fk="fmt-${esc(f.id)}" ${st.format === f.id ? 'checked' : ''}>
        <span class="bps-nt__shape" style="width:${w}px;height:${h}px;background:${esc(so.fabric.hex)};border-color:${esc(so.borderHex || so.fabric.hex)}"></span>
        <span class="bps-nt__cardT">${esc(L(f.label))}</span>
        <span class="bps-nt__cardS">${esc(L(f.hint))}</span>
        <span class="bps-nt__cardP">${esc(unitFor({ format: f.id }))}</span>
      </label>`;
    }
    const cs = cfg.customSize || {};
    if (cs.enabled) {
      html += `<label class="bps-nt__card${st.format === 'custom' ? ' is-on' : ''}">
        <input type="radio" name="${id}-fmt" value="custom" data-act="format" data-fk="fmt-custom" ${st.format === 'custom' ? 'checked' : ''}>
        <span class="bps-nt__shape bps-nt__shape--custom" aria-hidden="true">↔</span>
        <span class="bps-nt__cardT">${esc(t('nt.s2.custom'))}</span>
        <span class="bps-nt__cardP">${st.format === 'custom' ? esc(unitFor({})) : ''}</span>
      </label>`;
    }
    html += '</div>';
    if (st.format === 'custom') {
      const c = st.custom || { w: 100, h: (cs.heights || [25])[0] };
      html += `<div class="bps-nt__custom">
        <div class="bps-nt__field"><label for="${id}-cw" class="bps-nt__label">${esc(t('nt.s2.customW'))}</label>
          <input id="${id}-cw" class="bps-nt__input" type="number" inputmode="numeric" min="${cs.minW}" max="${cs.maxW}" step="1" value="${c.w}" data-act="custom-w" data-fk="cw" aria-describedby="${id}-cwh">
          <p class="bps-nt__hint" id="${id}-cwh">${esc(t('nt.s2.customRange', { min: cs.minW, max: cs.maxW }))}</p></div>
        <div class="bps-nt__field"><label for="${id}-ch" class="bps-nt__label">${esc(t('nt.s2.customH'))}</label>
          <select id="${id}-ch" class="bps-nt__input" data-act="custom-h" data-fk="ch">${(cs.heights || []).map((h) => `<option value="${h}" ${h === c.h ? 'selected' : ''}>${esc(mm(h / 10))} cm</option>`).join('')}</select></div>
      </div>`;
    }
    html += '</fieldset>';

    // 2. Snelle stijlen
    const presets = (cfg.presets || []).filter((p) => inStock((cfg.fabrics || []).find((f) => f.id === p.fabric)) && enabled((cfg.letterColors || []).find((c) => c.id === p.letter)));
    if (presets.length) {
      html += `<fieldset class="bps-nt__fs"><legend>${esc(t('nt.s2.presets'))}</legend><div class="bps-nt__chips">`;
      for (const p of presets) {
        const fab = (cfg.fabrics || []).find((f) => f.id === p.fabric), let_ = (cfg.letterColors || []).find((c) => c.id === p.letter);
        const on = st.fabric === p.fabric && st.letter === p.letter;
        html += `<button type="button" class="bps-nt__chip${on ? ' is-on' : ''}" aria-pressed="${on}" data-act="preset" data-id="${esc(p.id)}" data-fk="preset-${esc(p.id)}">
          <span class="bps-nt__chipSw" style="background:${esc(fab.hex)};color:${esc(let_.hex)}" aria-hidden="true">Aa</span>${esc(L(p.label))}</button>`;
      }
      html += '</div></fieldset>';
    }

    // 3-5. Meer stijlopties
    const moreOpen = s.moreOpen == null ? !mq.matches : s.moreOpen;
    html += `<details class="bps-nt__details bps-nt__more" data-fk="more" ${moreOpen ? 'open' : ''}><summary>${esc(t('nt.s2.more'))}</summary>`;
    html += swatches('fabric', t('nt.s2.fabric'), (cfg.fabrics || []).filter(inStock), st.fabric);
    if (cfg.irOnRequest) html += `<p class="bps-nt__hint">${esc(t('nt.ir'))}</p>`;
    if (so.fabric.note) html += `<p class="bps-nt__hint">${esc(L(so.fabric.note))}</p>`;
    html += swatches('letter', t('nt.s2.letter'), (cfg.letterColors || []).filter(enabled), st.letter);
    const contrast = contrastInfo(st);
    if (contrast) html += contrastHtml(contrast);
    html += `<fieldset class="bps-nt__fs"><legend>${esc(t('nt.s2.border'))}</legend><div class="bps-nt__radios">`;
    for (const b of (cfg.borders || []).filter(enabled)) {
      html += `<label class="bps-nt__radio"><input type="radio" name="${id}-border" value="${esc(b.id)}" data-act="border" data-fk="border-${esc(b.id)}" ${st.border === b.id ? 'checked' : ''}><span>${esc(L(b.label))}</span></label>`;
    }
    html += '</div></fieldset>';
    if (st.border === 'satin_color') html += swatches('borderColor', t('nt.s2.borderColor'), (cfg.letterColors || []).filter(enabled), st.borderColor || st.letter);
    const sample = (all.batch.rows[s.selected] || all.batch.rows[0] || {}).name || t('nt.s1.namePh');
    html += `<fieldset class="bps-nt__fs"><legend>${esc(t('nt.s2.font'))}</legend><div class="bps-nt__fonts">`;
    for (const f of (cfg.fonts || []).filter(enabled)) {
      const txt = applyCase(sample, st.uppercase, f);
      html += `<label class="bps-nt__fontTile${st.font === f.id ? ' is-on' : ''}"><input type="radio" name="${id}-font" value="${esc(f.id)}" data-act="font" data-fk="font-${esc(f.id)}" ${st.font === f.id ? 'checked' : ''}>
        <span class="bps-nt__fontSample" style="font-family:'BPSNT-${esc(f.id)}','${esc(f.family)}',${f.script ? 'cursive' : 'sans-serif'};font-weight:${f.weight || 400}">${esc(txt)}</span>
        <span class="bps-nt__fontName">${esc(L(f.label))}</span></label>`;
    }
    html += '</div></fieldset></details>';

    // Achterkant
    html += `<fieldset class="bps-nt__fs"><legend>${esc(t('nt.s2.backing'))}</legend><div class="bps-nt__radios">`;
    for (const b of cfg.backings || []) {
      html += `<label class="bps-nt__radio"><input type="radio" name="${id}-back" value="${esc(b.id)}" data-act="backing" data-fk="back-${esc(b.id)}" ${st.backing === b.id ? 'checked' : ''}><span>${esc(L(b.label))}</span></label>`;
    }
    html += '</div>';
    const lc = cfg.loopCounterpart || {};
    if (st.backing === 'hook') {
      html += `<p class="bps-nt__hint">${esc(t('nt.hook'))}</p>`;
      if (lc.enabled && (lc.onlyWith || ['hook']).includes('hook')) {
        const vat = (cfg.vat && cfg.vat.rate) || 0.21;
        html += `<label class="bps-nt__check"><input type="checkbox" data-act="loop" data-fk="loop" ${st.loop ? 'checked' : ''}><span>${esc(t('nt.s2.loop', { price: money(Number(lc.priceExcl) * (1 + vat)) }))}</span></label>
          <p class="bps-nt__hint">${esc(t('nt.hookHelp'))}</p>`;
      }
    }
    html += '</fieldset>';

    // Bloedgroep
    if (hasBlood(all.batch.rows)) {
      const fmt = resolveFormat(st, cfg) || { lines: 1 };
      const mode = all.batch.bloodMode;
      const bf = formatById(cfg, (cfg.blood && cfg.blood.separateFormat) || '5x2.5');
      const q = computeQuote({ ...all.batch, bloodMode: 'separate' }, ctx, cfg);
      const bUnit = q.ok && q.bloodPieces ? money(q.bloodUnitExcl * (1 + ctx.vatRate)) : '';
      const opts = [['separate', t('nt.s2.blood.separate', { format: bf ? L(bf.label) : '', price: bUnit })]];
      if (fmt.lines >= 2 && !hasLine2(all.batch.rows)) opts.push(['line2', t('nt.s2.blood.line2')]);
      opts.push(['inline', t('nt.s2.blood.inline')]);
      html += `<fieldset class="bps-nt__fs"><legend>${esc(t('nt.s2.bloodMode'))}</legend><div class="bps-nt__radios">`;
      for (const [v, lab] of opts) {
        html += `<label class="bps-nt__radio"><input type="radio" name="${id}-blood" value="${v}" data-act="bloodmode" data-fk="bm-${v}" ${mode === v ? 'checked' : ''}><span>${esc(lab)}</span></label>`;
      }
      html += `</div><p class="bps-nt__hint">${esc(t('nt.s2.blood.disclaimer'))}</p></fieldset>`;
    }
    if (cfg.page && cfg.page.studioUrl) html += `<p class="bps-nt__hint"><a href="${esc(cfg.page.studioUrl)}">${esc(t('nt.s1.logoLink'))} →</a></p>`;
    return html;
  }

  function swatches(act, legend, list, cur) {
    let html = `<fieldset class="bps-nt__fs"><legend>${esc(legend)}</legend><div class="bps-nt__swatches">`;
    for (const c of list) {
      html += `<label class="bps-nt__sw${cur === c.id ? ' is-on' : ''}" title="${esc(L(c.label))}">
        <input type="radio" name="${id}-${act}" value="${esc(c.id)}" data-act="${act}" data-fk="${act}-${esc(c.id)}" ${cur === c.id ? 'checked' : ''}>
        <span class="bps-nt__swC${c.pattern ? ' is-' + esc(c.pattern) : ''}" style="background:${esc(c.hex || '#777')}" aria-hidden="true"></span>
        <span class="bps-nt__sr">${esc(L(c.label))}</span></label>`;
    }
    return html + '</div></fieldset>';
  }

  function contrastInfo(st) {
    const so = styleObjects(st);
    const preset = (cfg.presets || []).find((p) => p.fabric === st.fabric && p.letter === st.letter);
    if (preset && preset.subdued) return null;
    if (!so.fabric.hex || !so.letter.hex) return null;
    const ratio = contrastRatio(so.letter.hex, so.fabric.hex);
    if (ratio >= 2) return null;
    const white = (cfg.letterColors || []).find((c) => c.id === 'white' && enabled(c));
    const black = (cfg.letterColors || []).find((c) => c.id === 'black' && enabled(c));
    const cands = [white, black].filter(Boolean);
    cands.sort((a, b) => contrastRatio(b.hex, so.fabric.hex) - contrastRatio(a.hex, so.fabric.hex));
    return { ratio, fix: cands[0] || null };
  }
  function contrastHtml(c) {
    return `<p class="bps-nt__notice is-warn">${esc(t('nt.contrast'))}${c.fix ? ` <button type="button" class="bps-nt__btn bps-nt__btn--sm" data-act="letter-fix" data-id="${esc(c.fix.id)}">${esc(t('nt.contrast.fix', { color: L(c.fix.label).toLowerCase() }))}</button>` : ''}</p>`;
  }

  /* ------------------------------------------------------------------ scherm 3: controle */

  function listFixes(all) {
    const counts = new Map();
    for (const c of all.checks.values()) {
      if (c.fit.status === 'ok') continue;
      for (const f of c.fit.fixes) if (f.startsWith('use_font:') || f.startsWith('use_format:')) counts.set(f, (counts.get(f) || 0) + 1);
    }
    return [...counts.entries()];
  }

  function fixLabel(code, n, multi) {
    if (code === 'use_font:condensed') return multi ? t('nt.fix.condensed', { n }) : t('nt.fix.condensedOne');
    if (code.startsWith('use_format:')) {
      const f = formatById(cfg, code.slice(11));
      const format = f ? L(f.label) : code.slice(11);
      return multi ? t('nt.fix.format', { n, format }) : t('nt.fix.formatOne', { format });
    }
    if (code === 'split_lines') return t('nt.fix.split');
    if (code === 'drop_line2') return t('nt.fix.drop_line2');
    return t('nt.fix.edit');
  }

  function screen3(s, all) {
    const st = s.style;
    const so = styleObjects(st);
    const rows = all.batch.rows;
    const order = rows.map((r, i) => ({ r, i, w: worst(all.checks.get(r._id)) }));
    const rank = { error: 0, warn: 1, ok: 2 };
    order.sort((a, b) => rank[a.w] - rank[b.w] || a.i - b.i);
    const problems = order.filter((o) => o.w !== 'ok').length;
    const multi = rows.length > 1;

    let html = `<h3 class="bps-nt__h">${esc(t('nt.s3.title'))}</h3>`;
    html += problems
      ? `<p class="bps-nt__notice is-${order[0].w === 'error' ? 'error' : 'warn'}">${esc(t('nt.s3.problems', { n: problems }))}</p>`
      : `<p class="bps-nt__notice is-ok">${esc(t('nt.s3.allOk'))}</p>`;
    const lf = listFixes(all);
    if (lf.length) {
      html += '<div class="bps-nt__fixes">' + lf.map(([code, n]) => `<button type="button" class="bps-nt__btn bps-nt__btn--fix" data-act="fix" data-fix="${esc(code)}">${esc(fixLabel(code, n, multi))}</button>`).join('') + '</div>';
    }
    const contrast = contrastInfo(st);
    if (contrast) html += contrastHtml(contrast);

    html += '<ul class="bps-nt__rows">';
    for (const { r, i, w } of order) {
      const chk = all.checks.get(r._id);
      const src = s.rows.find((x) => x.id === r._id) || r;
      const editing = s.editing === r._id;
      const rowFixes = chk.fit.status !== 'ok' ? chk.fit.fixes.filter((f) => !f.startsWith('use_font:') && !f.startsWith('use_format:')) : [];
      html += `<li class="bps-nt__row is-${w}${i === s.selected ? ' is-selected' : ''}" data-row="${esc(r._id)}">
        <button type="button" class="bps-nt__rowMain" data-act="select-edit" data-i="${i}" aria-expanded="${editing}">
          <canvas class="bps-nt__mini" width="240" height="70" role="img" aria-label="${esc(rowLines(r, all.batch.bloodMode, cfg).join(' / '))}" data-mini="${esc(r._id)}"></canvas>
          <span class="bps-nt__rowText">
            <span class="bps-nt__rowName">${dot(w)} ${esc(applyCase(r.name, st.uppercase, so.font))}${r.line2 ? ' · ' + esc(applyCase(r.line2, st.uppercase, so.font)) : ''}</span>
            <span class="bps-nt__rowSt" data-row-status="${esc(r._id)}">${esc(fitMessage(r, chk, st))}</span>
          </span>
        </button>
        <div class="bps-nt__qty" role="group" aria-label="${esc(t('nt.s1.qty'))}">
          <button type="button" class="bps-nt__step" data-act="qty-" data-id="${esc(r._id)}" aria-label="−1" ${src.qty <= 1 ? 'disabled' : ''}>−</button>
          <output aria-live="off">${src.qty}</output>
          <button type="button" class="bps-nt__step" data-act="qty+" data-id="${esc(r._id)}" aria-label="+1" ${src.qty >= 99 ? 'disabled' : ''}>+</button>
        </div>
        ${rowFixes.length ? `<div class="bps-nt__rowFixes">${rowFixes.map((f) => `<button type="button" class="bps-nt__btn bps-nt__btn--sm" data-act="rowfix" data-fix="${esc(f)}" data-id="${esc(r._id)}">${esc(f === 'edit' ? (chk.fit.codes.includes('too_long') || chk.fit.codes.includes('small_text') ? t('nt.fix.edit') : t('nt.fix.editPlain')) : fixLabel(f, 1, false))}</button>`).join('')}</div>` : ''}
        ${editing ? editHtml(src) : ''}
      </li>`;
    }
    html += '</ul>';

    // controlewoorden
    const review = [...all.checks.values()].some((c) => c.review.length);
    if (review) {
      html += `<div class="bps-nt__notice is-info"><p>${esc(t('nt.review'))}</p><p>${esc(t('nt.s1.policyHint'))}</p>
        <label for="${id}-org" class="bps-nt__label">${esc(t('nt.s3.orgNote'))}</label>
        <input id="${id}-org" class="bps-nt__input" type="text" maxlength="200" data-act="orgnote" data-fk="org" value="${esc(s.orgNote)}"></div>`;
    }

    // samenvatting + prijs
    const q = all.quote;
    const back = (cfg.backings || []).find((b) => b.id === st.backing);
    html += `<div class="bps-nt__summary"><p class="bps-nt__sumLine">${esc(t('nt.s3.summary', {
      count: t('nt.s3.summaryCount', { n: q.mainPieces }),
      format: formatLabel(st),
      fabric: L(so.fabric.label).toLowerCase(),
      letter: L(so.letter.label).toLowerCase(),
      backing: (back ? L(back.label) : '').toLowerCase() + (all.batch.loop ? ' + ' + (lang === 'fr' ? 'contre-partie' : 'tegenstuk') : ''),
    }))}</p>`;
    if (q.ok) {
      html += '<dl class="bps-nt__price">';
      for (const b of q.breakdown) {
        if (b.key === 'shipping') continue;
        html += `<div><dt>${esc(b.label)}</dt><dd>${esc(money(b.incl))}</dd></div>`;
      }
      html += `<div><dt>${esc(t('nt.s3.perPiece'))}</dt><dd>${esc(money(q.unitIncl))}</dd></div>`;
      const ship = q.breakdown.find((b) => b.key === 'shipping');
      if (ship) html += `<div><dt>${esc(ship.incl ? t('nt.s3.shipping') : t('nt.s3.shippingFree'))}</dt><dd>${esc(money(ship.incl))}</dd></div>`;
      html += `<div class="bps-nt__total"><dt>${esc(t('nt.s3.total'))}</dt><dd>${esc(money(q.totalIncl))}</dd></div></dl>`;
      if (q.tier.discount > 0) html += `<p class="bps-nt__hint">${esc(t('nt.s3.tierNow', { pct: Math.round(q.tier.discount * 100) }))}</p>`;
      if (q.nextTier) html += `<p class="bps-nt__hint">${esc(t('nt.tier.next', { n: q.nextTier.needed, price: money(q.nextTier.unitIncl) }))}</p>`;
      if (cfg.tiers && cfg.tiers.pooledWithPatches !== false && (cfg.page && cfg.page.poolQty)) html += `<p class="bps-nt__hint">${esc(t('nt.s3.pooled'))}</p>`;
    }
    html += '</div>';
    const pr = cfg.promises || {};
    const proof = pr.proof === true ? t('nt.proof') : L(pr.proof);
    if (proof) html += `<p class="bps-nt__promise">${esc(proof)}</p>`;
    const lead = L(pr.leadTime);
    if (lead) html += `<p class="bps-nt__promise">${esc(lead)}</p>`;
    html += `<label class="bps-nt__check bps-nt__rights"><input type="checkbox" data-act="rights" data-fk="rights" ${s.rights ? 'checked' : ''} required><span>${esc(t('nt.s3.rights'))}</span></label>
      <p class="bps-nt__hint">${esc(t('nt.s3.customMade'))}</p>`;
    // Bloedgroep = gezondheidsgegeven: apart vakje, nooit vooraf aangevinkt, alleen als er een bloedgroep in de lijst staat.
    if (hasBlood(all.batch.rows)) {
      html += `<label class="bps-nt__check bps-nt__rights bps-nt__bloodConsent"><input type="checkbox" data-act="bloodConsent" data-fk="bloodConsent" aria-describedby="${id}-bc-hint" ${s.bloodConsent ? 'checked' : ''} required><span>${esc(bloodConsentText())}</span></label>
      <p class="bps-nt__hint" id="${id}-bc-hint">${esc(t('nt.s3.bloodConsentHint'))}</p>`;
    }
    const why = blockers(s, all);
    html += `<p class="bps-nt__why" id="${id}-why">${why.length ? esc(why.join(' ')) : ''}</p>`;
    if (s.status === 'error' && s.message) html += `<p class="bps-nt__notice is-error" role="alert">${esc(s.message)}</p>`;
    if (s.devPayload) html += `<div class="bps-nt__dev"><p>${esc(t('nt.devPayload'))}</p><pre>${esc(JSON.stringify(s.devPayload, null, 2))}</pre></div>`;
    return html;
  }

  function editHtml(r) {
    return `<div class="bps-nt__edit">
      <div class="bps-nt__field"><label class="bps-nt__label" for="${id}-e-n-${esc(r.id)}">${esc(t('nt.s1.nameLabel'))}</label>
        <input id="${id}-e-n-${esc(r.id)}" class="bps-nt__input" type="text" maxlength="60" data-edit="name" data-id="${esc(r.id)}" data-fk="en-${esc(r.id)}" value="${esc(r.name)}"></div>
      <div class="bps-nt__field"><label class="bps-nt__label" for="${id}-e-l-${esc(r.id)}">${esc(t('nt.s1.line2'))}</label>
        <input id="${id}-e-l-${esc(r.id)}" class="bps-nt__input" type="text" maxlength="60" data-edit="line2" data-id="${esc(r.id)}" data-fk="el-${esc(r.id)}" value="${esc(r.line2 || '')}"></div>
      <div class="bps-nt__field"><label class="bps-nt__label" for="${id}-e-b-${esc(r.id)}">${esc(t('nt.s1.blood'))}</label>
        <select id="${id}-e-b-${esc(r.id)}" class="bps-nt__input" data-edit="blood" data-id="${esc(r.id)}" data-fk="eb-${esc(r.id)}">${bloodOptions(r.blood)}</select></div>
      <button type="button" class="bps-nt__btn" data-act="edit-done">${esc(t('nt.s3.done'))}</button>
    </div>`;
  }

  /* ------------------------------------------------------------------ mini-voorbeelden (lui) */

  function redrawMinis(force) {
    if (!io) return;
    for (const c of el.main.querySelectorAll('canvas[data-mini]')) {
      if (force) c.dataset.key = '';
      io.unobserve(c); io.observe(c);
    }
  }
  function drawMini(c) {
    const s = store.get();
    const all = computeAll(s);
    const r = all.batch.rows.find((x) => x._id === c.dataset.mini);
    if (!r) return;
    const chk = all.checks.get(r._id);
    const spec = tapeSpec(r, all.batch, chk.fit);
    const key = JSON.stringify([spec.lines, spec.capMm, spec.fabric.id, spec.letterHex, spec.borderHex, spec.font.id, spec.format, fontsState.loaded]);
    if (c.dataset.key === key) return;
    c.dataset.key = key;
    renderInto(c, spec, { maxCssW: 120, maxCssH: 44 });
  }
  function setupMinis() {
    if (io) io.disconnect();
    if (typeof IntersectionObserver === 'undefined') {
      for (const c of el.main.querySelectorAll('canvas[data-mini]')) drawMini(c);
      return;
    }
    io = new IntersectionObserver((entries) => {
      for (const e of entries) if (e.isIntersecting) { drawMini(e.target); io.unobserve(e.target); }
    }, { root: el.main, rootMargin: '200px 0px' });
    for (const c of el.main.querySelectorAll('canvas[data-mini]')) io.observe(c);
  }

  /* ------------------------------------------------------------------ volledige render */

  function renderMain() {
    const s = store.get();
    const all = computeAll(s);
    const a = document.activeElement;
    const fk = a && el.main.contains(a) ? a.dataset.fk : null;
    const selStart = fk && a.selectionStart != null ? [a.selectionStart, a.selectionEnd] : null;
    const scroll = el.main.scrollTop;
    let html = '';
    if (s.status === 'added') html = addedHtml();
    else if (s.step === 1) html = screen1(s, all);
    else if (s.step === 2) html = screen2(s, all);
    else html = screen3(s, all);
    el.main.innerHTML = html;
    el.main.scrollTop = scroll;
    if (fk) {
      const n = el.main.querySelector(`[data-fk="${CSS && CSS.escape ? CSS.escape(fk) : fk}"]`);
      if (n) { n.focus({ preventScroll: true }); if (selStart && n.setSelectionRange) try { n.setSelectionRange(selStart[0], selStart[1]); } catch (e) { /* number-velden */ } }
    }
    updateSingleStatus(all);
    renderSteps(all);
    renderBar(all);
    drawPreview();
    if (s.step === 3) setupMinis();
    scheduleServerQuote(all);
  }

  function addedHtml() {
    const cartUrl = (cfg.rest && cfg.rest.cartUrl) || (cfg.page && cfg.page.cartUrl) || '';
    return `<div class="bps-nt__added" role="status"><p class="bps-nt__h">${esc(t('nt.added'))}</p>
      ${cartUrl ? `<p><a class="bps-nt__btn bps-nt__btn--primary" href="${esc(cartUrl)}">${esc(t('nt.toCart'))}</a></p>` : ''}
      <p><button type="button" class="bps-nt__btn" data-act="again">${esc(t('nt.more'))}</button></p></div>`;
  }

  /** Lichte update tijdens typen (geen volledige herbouw van het scherm). */
  function lightUpdate() {
    const all = computeAll();
    updateSingleStatus(all);
    renderSteps(all);
    renderBar(all);
    drawPreview();
    scheduleServerQuote(all);
  }

  function updateSingleStatus(all) {
    const s = store.get();
    for (const p of el.main.querySelectorAll('[data-row-status]')) {
      const rid = p.getAttribute('data-row-status');
      const r = all.batch.rows.find((x) => x._id === rid);
      const chk = r && all.checks.get(rid);
      if (!r || !chk) { p.textContent = ''; p.className = p.className.replace(/ ?is-(ok|warn|error)/g, ''); continue; }
      const w = worst(chk);
      p.textContent = (s.step === 1 && w !== 'ok' ? t('nt.status.' + w) + ': ' : '') + fitMessage(r, chk, s.style);
      p.classList.remove('is-ok', 'is-warn', 'is-error');
      p.classList.add('is-' + w);
    }
  }

  /* ------------------------------------------------------------------ serverprijs (pool, context van de mand) */

  function scheduleServerQuote(all) {
    if (!cfg.rest || !cfg.rest.root || !all.batch.rows.length) return;
    const key = quoteKey(all.batch);
    if (key === lastQuoteKey) return;
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(async () => {
      lastQuoteKey = key;
      try {
        const res = await fetch(cfg.rest.root + 'nametape/quote', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-BPS-Locale': locale },
          body: JSON.stringify({ batch: stripIds(all.batch) }),
        });
        if (!res.ok) return;
        const data = await res.json();
        if (data && data.quote && quoteKey(batchFor()) === key) {
          store.set({ serverQuote: { key, quote: data.quote } });
          renderBar(computeAll());
        }
      } catch (e) { /* offline: JS-prijs blijft staan */ }
    }, 600);
  }

  function stripIds(batch) {
    return { ...batch, rows: batch.rows.map(({ _id, ...r }) => r) };
  }

  /* ------------------------------------------------------------------ acties */

  function setRows(rows, extra = {}) {
    store.set((s) => ({ rows, selected: Math.min(s.selected, Math.max(0, rows.length - 1)), serverQuote: null, ...extra }));
  }
  function updateRow(rid, patch) {
    const s = store.get();
    setRows(s.rows.map((r) => (r.id === rid ? { ...r, ...patch } : r)));
  }
  function setStyle(patch) {
    store.set((s) => ({ style: { ...s.style, ...patch }, serverQuote: null }));
  }

  function autoFormat(rows, bloodOnly) {
    const s = store.get();
    if (s.style.formatTouched) return;
    let fid = (formatById(cfg, '10x2.5') && '10x2.5') || defaultStyle(cfg).format;
    if (bloodOnly && formatById(cfg, (cfg.blood && cfg.blood.separateFormat) || '5x2.5')) fid = (cfg.blood && cfg.blood.separateFormat) || '5x2.5';
    else if (hasLine2(rows) && formatById(cfg, '12x3')) fid = '12x3';
    if (fid !== s.style.format) setStyle({ format: fid });
  }

  function applyParsed(res, how) {
    const s = store.get();
    let rows = res.rows;
    let next = s.nextId;
    rows = rows.map((r) => ({ ...r, id: 'r' + next++ }));
    let all = rows;
    if (how === 'append') all = activeRows(s).filter((r) => cleanText(r.name) || cleanText(r.line2)).concat(rows);
    const max = Number(cfg.maxRows) || 250;
    const notices = res.warnings.filter((w) => w.key !== 'nt.parse.empty' || !rows.length).map((w) => ({ ...w, level: w.key === 'nt.parse.xlsx' || w.key === 'nt.parse.empty' ? 'warn' : 'info' }));
    if (all.length > max) { all = all.slice(0, max); notices.push({ key: 'nt.parse.tooMany', params: { max }, level: 'warn' }); }
    if (!all.length) { store.set({ notices, pending: null }); renderMain(); return; }
    if (rows.length) notices.unshift({ key: 'nt.parse.read', params: { n: rows.length }, level: 'ok' });
    store.set({
      rows: all, nextId: next, mode: all.length > 1 ? 'list' : 'single', selected: 0, notices, pending: null,
      pasteText: '', showPaste: false, serverQuote: null,
    });
    autoFormat(all, res.bloodOnly);
    renderMain();
  }

  function handleParsed(res) {
    const s = store.get();
    if (!res.rows.length) { applyParsed(res, 'replace'); return; }
    if (s.mode === 'list' && s.rows.length >= 2) {
      store.set({ pending: res });
      renderMain();
      return;
    }
    applyParsed(res, 'replace');
  }

  /** Getypte of geplakte lijst overnemen (knop "Gebruik deze namen" of "Verder"). */
  function applyTypedList() {
    const s = store.get();
    clearTimeout(pasteTimer);
    if (s.pasteText && s.pasteText.trim()) handleParsed(parseList(s.pasteText, { source: 'paste', config: cfg }));
  }

  async function readFile(file) {
    if (!file) return;
    if (/\.xlsx?$/i.test(file.name)) { handleParsed(parseList('', { fileName: file.name })); return; }
    if (file.size > 1024 * 1024) { store.set({ notices: [{ key: 'nt.parse.fileError', level: 'warn' }] }); renderMain(); return; }
    try {
      const buf = await file.arrayBuffer();
      let text = new TextDecoder('utf-8').decode(buf);
      if ((text.match(/�/g) || []).length > 0) text = new TextDecoder('windows-1252').decode(buf);
      handleParsed(parseList(text, { source: 'csv', config: cfg, fileName: file.name }));
    } catch (e) {
      store.set({ notices: [{ key: 'nt.parse.fileError', level: 'warn' }] });
      renderMain();
    }
  }

  function goStep(n) {
    const s = store.get();
    const all = computeAll(s);
    if (n > 1 && !canLeave1(s, all)) n = 1;
    if (n === 2 && s.step === 1) autoFormat(all.batch.rows, false);
    store.set({ step: n, editing: null, status: s.status === 'added' ? 'idle' : s.status, devPayload: null, notices: n === 1 ? s.notices : [] });
    el.main.scrollTop = 0;
    renderMain();
    const h = el.main.querySelector('.bps-nt__h');
    if (h && !mq.matches) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
  }

  function applyFix(code, rid) {
    const s = store.get();
    if (code === 'use_font:condensed') { setStyle({ font: 'condensed' }); renderMain(); return; }
    if (code.startsWith('use_format:')) { setStyle({ format: code.slice(11), formatTouched: true }); renderMain(); return; }
    const r = s.rows.find((x) => x.id === rid);
    if (!r) return;
    if (code === 'split_lines') {
      const so = styleObjects(s.style);
      const fm = (metrics.fonts || {})[so.font.id];
      const sp = fm ? bestSplit(r.name, fm) : null;
      if (sp) updateRow(rid, { name: sp[0], line2: sp[1] });
    } else if (code === 'drop_line2') {
      updateRow(rid, { line2: '' });
    } else if (code === 'edit') {
      store.set({ editing: rid, selected: activeRows(s).findIndex((x) => x.id === rid) });
      renderMain();
      const inp = el.main.querySelector(`[data-edit="name"][data-id="${rid}"]`);
      if (inp) { inp.focus(); inp.select(); }
      return;
    }
    renderMain();
  }

  async function addToCart() {
    const s = store.get();
    const all = computeAll(s);
    if (blockers(s, all).length) return;
    const batch = {
      ...stripIds(all.batch),
      priceClient: { totalIncl: all.quote.totalIncl },
      rightsConsent: { v: (cfg.legal && cfg.legal.rightsTextVersion) || '', accepted: true },
    };
    if (hasBlood(all.batch.rows)) batch.bloodConsent = { v: bloodConsentCfg().v || '', accepted: true };
    if (!cfg.rest || !cfg.rest.root) {
      store.set({ devPayload: batch });
      root.dispatchEvent(new CustomEvent('bps:nametape-payload', { bubbles: true, detail: { batch } }));
      renderMain();
      return;
    }
    store.set({ status: 'busy', message: '' });
    renderBar(computeAll());
    try {
      const first = all.batch.rows[0];
      const blob = await toBlob(tapeSpec(first, all.batch, all.checks.get(first._id).fit)).catch(() => null);
      const send = async (token) => {
        const fd = new FormData();
        fd.append('batch', JSON.stringify(batch));
        if (blob) fd.append('preview', blob, 'preview.png');
        if (s.editKey) fd.append('replace', s.editKey);
        fd.append('website', '');
        return fetch(cfg.rest.root + 'nametape/cart', { method: 'POST', credentials: 'same-origin', headers: { 'X-BPS-Token': token, 'X-BPS-Locale': locale }, body: fd });
      };
      const tok = async () => {
        const r = await fetch(cfg.rest.root + 'token', { credentials: 'same-origin', headers: { 'X-BPS-Locale': locale } });
        return (await r.json()).token || '';
      };
      let res = await send(await tok());
      if (res.status === 403) res = await send(await tok());
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        store.set({ status: 'error', message: (data && data.message) || genericError() });
        renderMain();
        return;
      }
      clearSaved();
      store.set({ status: 'added', editKey: '', cartUrl: data.cartUrl });
      if (data.cartUrl) cfg.rest.cartUrl = data.cartUrl;
      try { if (window.jQuery) window.jQuery(document.body).trigger('wc_fragment_refresh'); } catch (e) { /* geen jQuery */ }
      root.dispatchEvent(new CustomEvent('bps:nametape-added', { bubbles: true, detail: { cartItemKey: data.cartItemKey, cartCount: data.cartCount, quote: data.quote } }));
      renderMain();
    } catch (e) {
      store.set({ status: 'error', message: t('nt.error.offline') });
      renderMain();
    }
  }

  async function loadEdit(key) {
    if (!cfg.rest || !cfg.rest.root || !key) return;
    try {
      const res = await fetch(cfg.rest.root + 'nametape/cart/' + encodeURIComponent(key), { credentials: 'same-origin', headers: { 'X-BPS-Locale': locale } });
      if (!res.ok) { store.set({ editKey: '' }); renderMain(); return; }
      const { batch } = await res.json();
      if (!batch || !Array.isArray(batch.rows)) return;
      let next = 1;
      const rows = batch.rows.map((r) => ({ id: 'r' + next++, name: r.name || '', line2: r.line2 || '', blood: parseBlood(r.blood) || null, qty: r.qty || 1 }));
      store.set((s) => ({
        rows, nextId: next, mode: rows.length > 1 ? 'list' : 'single', selected: 0, step: 3, orgNote: batch.orgNote || '',
        style: { ...s.style, format: batch.format, custom: batch.custom || null, fabric: batch.fabric, letter: batch.letter, border: batch.border,
          borderColor: batch.borderColor || null, font: batch.font, uppercase: batch.uppercase !== false, backing: batch.backing, loop: !!batch.loop,
          bloodMode: batch.bloodMode || null, formatTouched: true },
      }));
      renderMain();
    } catch (e) { /* laat de lege toestand staan */ }
  }

  /* ------------------------------------------------------------------ events */

  let pasteTimer = 0;
  app.addEventListener('input', (e) => {
    const n = e.target;
    const s = store.get();
    if (n.dataset.bind === 'name' || n.dataset.bind === 'line2') {
      const r0 = s.rows[0];
      setRows([{ ...r0, [n.dataset.bind]: n.value }, ...s.rows.slice(1)]);
      lightUpdate();
    } else if (n.dataset.edit === 'name' || n.dataset.edit === 'line2') {
      updateRow(n.dataset.id, { [n.dataset.edit]: n.value });
      lightUpdate();
      const li = n.closest('[data-row]');
      const all = computeAll();
      const chk = all.checks.get(n.dataset.id);
      const r = all.batch.rows.find((x) => x._id === n.dataset.id);
      if (li && chk && r) {
        li.className = li.className.replace(/is-(ok|warn|error)/, 'is-' + worst(chk));
        const mini = li.querySelector('canvas[data-mini]');
        if (mini) drawMini(mini);
      }
    } else if (n.dataset.fk === 'pastearea') {
      // Typen = de klant maakt zelf een lijst: niets overnemen of dichtklappen tijdens het typen
      // (anders verdwijnt het veld na de eerste naam). Plakken neemt meteen over (zie 'paste').
      store.set({ pasteText: n.value });
      clearTimeout(pasteTimer);
      renderBar(computeAll());
    } else if (n.dataset.act === 'orgnote') {
      store.set({ orgNote: n.value.slice(0, 200) });
    } else if (n.dataset.act === 'custom-w') {
      const w = Math.round(Number(n.value));
      const cs = cfg.customSize || {};
      if (w >= cs.minW && w <= cs.maxW) { setStyle({ custom: { ...(s.style.custom || { h: (cs.heights || [25])[0] }), w } }); lightUpdate(); }
    }
  });

  app.addEventListener('paste', (e) => {
    if (e.target && e.target.dataset && e.target.dataset.fk === 'pastearea') {
      setTimeout(() => {
        clearTimeout(pasteTimer);
        if (e.target.value.trim()) handleParsed(parseList(e.target.value, { source: 'paste', config: cfg }));
      }, 0);
    }
  });

  app.addEventListener('change', (e) => {
    const n = e.target;
    const act = n.dataset.act;
    const s = store.get();
    if (n.dataset.bind === 'blood') { setRows([{ ...s.rows[0], blood: parseBlood(n.value) }, ...s.rows.slice(1)]); renderMain(); return; }
    if (n.dataset.edit === 'blood') { updateRow(n.dataset.id, { blood: parseBlood(n.value) }); renderMain(); return; }
    switch (act) {
      case 'uppercase': setStyle({ uppercase: n.checked }); renderMain(); break;
      case 'csv': readFile(n.files && n.files[0]); n.value = ''; break;
      case 'format': {
        const patch = { format: n.value, formatTouched: true };
        if (n.value === 'custom' && !s.style.custom) patch.custom = { w: 100, h: ((cfg.customSize || {}).heights || [25])[0] };
        setStyle(patch); renderMain(); break;
      }
      case 'custom-h': setStyle({ custom: { ...(s.style.custom || { w: 100 }), h: Number(n.value) } }); renderMain(); break;
      case 'custom-w': renderMain(); break;
      case 'fabric': setStyle({ fabric: n.value, preset: null }); renderMain(); break;
      case 'letter': setStyle({ letter: n.value, preset: null }); renderMain(); break;
      case 'borderColor': setStyle({ borderColor: n.value }); renderMain(); break;
      case 'border': setStyle({ border: n.value }); renderMain(); break;
      case 'font': setStyle({ font: n.value }); renderMain(); break;
      case 'backing': setStyle({ backing: n.value, loop: n.value === 'hook' ? s.style.loop : false }); renderMain(); break;
      case 'loop': setStyle({ loop: n.checked }); renderMain(); break;
      case 'bloodmode': setStyle({ bloodMode: n.value }); renderMain(); break;
      case 'rights': store.set({ rights: n.checked }); renderBar(computeAll()); { const w = el.main.querySelector('.bps-nt__why'); if (w) w.textContent = blockers(store.get(), computeAll()).join(' '); } break;
      case 'bloodConsent': store.set({ bloodConsent: n.checked }); renderBar(computeAll()); { const w = el.main.querySelector('.bps-nt__why'); if (w) w.textContent = blockers(store.get(), computeAll()).join(' '); } break;
      default: break;
    }
  });

  app.addEventListener('toggle', (e) => {
    const d = e.target;
    if (!(d instanceof HTMLElement) || d.tagName !== 'DETAILS') return;
    const fk = d.dataset.fk;
    if (fk === 'extra') store.set({ showExtra: d.open });
    else if (fk === 'paste') {
      const s = store.get();
      const r0 = s.rows[0] || {};
      // Al een naam getypt? Zet die al als eerste regel, dan typ je gewoon verder.
      const prefill = d.open && s.mode !== 'list' && !s.pasteText && cleanText(r0.name) ? r0.name + '\n' : null;
      store.set(prefill ? { showPaste: d.open, pasteText: prefill } : { showPaste: d.open });
      const ta = d.querySelector('textarea');
      if (ta && prefill) ta.value = prefill;
      if (d.open && ta && !mq.matches) { ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); }
      renderBar(computeAll());
    } else if (fk === 'more') store.set({ moreOpen: d.open });
  }, true);

  app.addEventListener('click', (e) => {
    const b = e.target.closest('[data-act],[data-step]');
    if (!b || !app.contains(b)) return;
    if (b.dataset.step) { goStep(Number(b.dataset.step)); return; }
    const s = store.get();
    const act = b.dataset.act;
    switch (act) {
      case 'cta':
        if (s.status === 'added') { const u = (cfg.rest && cfg.rest.cartUrl) || ''; if (u) window.location.href = u; return; }
        if (ctaBlocked()) { showWhatsMissing(); return; }
        if (s.step === 1 && pasteWaiting(s)) {
          applyTypedList();
          if (store.get().pending) return; // eerst kiezen: vervangen of toevoegen
        }
        if (s.step < 3) goStep(s.step + 1); else addToCart();
        break;
      case 'apply-paste': applyTypedList(); break;
      case 'prev': store.set({ selected: Math.max(0, s.selected - 1) }); drawPreview(); markSelected(); break;
      case 'next': store.set({ selected: Math.min(activeRows(s).length - 1, s.selected + 1) }); drawPreview(); markSelected(); break;
      case 'select': store.set({ selected: Number(b.dataset.i) }); drawPreview(); markSelected(); break;
      case 'select-edit': {
        const i = Number(b.dataset.i);
        const all = computeAll(s);
        const r = all.batch.rows[i];
        store.set({ selected: i, editing: r && s.editing !== r._id ? r._id : null });
        renderMain();
        break;
      }
      case 'edit-done': store.set({ editing: null }); renderMain(); break;
      case 'remove': {
        const rows = s.rows.filter((r) => r.id !== b.dataset.id);
        setRows(rows.length ? rows : [{ id: 'r' + s.nextId, name: '', line2: '', blood: null, qty: 1 }], { mode: rows.length > 1 ? 'list' : 'single', nextId: s.nextId + 1 });
        renderMain();
        break;
      }
      case 'single': setRows([s.rows[0]], { mode: 'single', notices: [] }); renderMain(); break;
      case 'merge': setRows(mergeDuplicates(s.rows, b.dataset.ids.split(','))); renderMain(); break;
      case 'pending-replace': applyParsed(s.pending, 'replace'); break;
      case 'pending-append': applyParsed(s.pending, 'append'); break;
      case 'preset': {
        const p = (cfg.presets || []).find((x) => x.id === b.dataset.id);
        if (p) { setStyle({ fabric: p.fabric, letter: p.letter, border: p.border || s.style.border, preset: p.id }); renderMain(); }
        break;
      }
      case 'letter-fix': setStyle({ letter: b.dataset.id, preset: null }); renderMain(); break;
      case 'qty-': case 'qty+': {
        const r = s.rows.find((x) => x.id === b.dataset.id);
        if (r) { updateRow(r.id, { qty: Math.max(1, Math.min(99, (r.qty || 1) + (act === 'qty+' ? 1 : -1))) }); renderMain(); }
        break;
      }
      case 'fix': applyFix(b.dataset.fix); break;
      case 'rowfix': applyFix(b.dataset.fix, b.dataset.id); break;
      case 'again': store.reset(); renderMain(); break;
      default: break;
    }
  });

  function markSelected() {
    const s = store.get();
    el.main.querySelectorAll('.bps-nt__li').forEach((li, i) => li.classList.toggle('is-selected', i === s.selected));
  }

  app.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target && e.target.dataset && e.target.dataset.bind === 'name') {
      e.preventDefault();
      if (!ctaBlocked()) goStep(2);
    }
  });
  app.addEventListener('focusin', () => setTimeout(layout, 50));
  app.addEventListener('focusout', () => setTimeout(layout, 50));

  const onResize = () => layout();
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', onResize);
    window.visualViewport.addEventListener('scroll', onResize);
  }
  window.addEventListener('resize', onResize);
  if (mq.addEventListener) mq.addEventListener('change', () => { layout(); renderMain(); });

  // start
  layout();
  renderMain();
  loadFonts();
  if (cfg.page && cfg.page.editKey) loadEdit(cfg.page.editKey);

  return {
    store,
    computeAll,
    batch: () => stripIds(batchFor()),
    render: renderMain,
    goStep,
    destroy() {
      if (window.visualViewport) {
        window.visualViewport.removeEventListener('resize', onResize);
        window.visualViewport.removeEventListener('scroll', onResize);
      }
      window.removeEventListener('resize', onResize);
      if (io) io.disconnect();
      root.innerHTML = '';
    },
  };
}
