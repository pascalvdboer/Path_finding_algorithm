/**
 * Naamstroken — één onveranderlijke store (SPEC-EXTRA §1.10). Bewaart de lijst in sessionStorage['bps:nt:v1']
 * (try/catch: privé-venster of geblokkeerde opslag mag niets breken). Namen zijn persoonsgegevens: enkel sessionStorage,
 * nooit localStorage, nooit naar analytics.
 */
export const STORAGE_KEY = 'bps:nt:v1';

/** Standaardstijl uit de config (formaat, stof, letters, rand, lettertype, achterkant). */
export function defaultStyle(cfg) {
  const pick = (list, pred) => (list || []).find(pred);
  const enabled = (x) => x && x.enabled !== false;
  const inStock = (x) => enabled(x) && x.inStock !== false;
  const page = cfg.page || {};
  const fmt = pick(cfg.formats, (f) => f.id === page.format && enabled(f)) || pick(cfg.formats, (f) => f.default && enabled(f)) || pick(cfg.formats, enabled) || { id: '10x2.5' };
  const preset = pick(cfg.presets, (p) => {
    const fab = pick(cfg.fabrics, (f) => f.id === p.fabric);
    return inStock(fab);
  });
  const pageFabric = pick(cfg.fabrics, (f) => f.id === page.fabric && inStock(f));
  const pagePreset = pageFabric ? pick(cfg.presets, (p) => p.fabric === pageFabric.id) : null;
  const fabric = pageFabric ? pageFabric.id : (preset && preset.fabric) || (pick(cfg.fabrics, inStock) || { id: 'black' }).id;
  const letter = pageFabric ? (pagePreset ? pagePreset.letter : 'white') : (preset && preset.letter) || (pick(cfg.letterColors, enabled) || { id: 'white' }).id;
  const border = (pick(cfg.borders, (b) => b.default && enabled(b)) || pick(cfg.borders, enabled) || { id: 'none' }).id;
  const font = (pick(cfg.fonts, (f) => f.default && enabled(f)) || pick(cfg.fonts, enabled) || { id: 'block' }).id;
  const backing = (pick(cfg.backings, (b) => b.default) || (cfg.backings || [])[0] || { id: 'hook' }).id;
  return {
    format: fmt.id,
    custom: null,
    fabric,
    letter,
    border,
    borderColor: null,
    font,
    uppercase: !(cfg.text && cfg.text.uppercaseDefault === false),
    backing,
    loop: false,
    preset: pageFabric ? (pagePreset ? pagePreset.id : null) : preset ? preset.id : null,
    bloodMode: null,
    formatTouched: !!(page.format && fmt.id === page.format),
  };
}

export function initialState(cfg) {
  return {
    step: 1,
    mode: 'single',          // 'single' | 'list'
    rows: [{ id: 'r1', name: '', line2: '', blood: null, qty: 1 }],
    nextId: 2,
    selected: 0,
    style: defaultStyle(cfg),
    rights: false,
    bloodConsent: false,     // aparte toestemming voor bloedgroepen (nooit bewaard, nooit vooraf aangevinkt)
    orgNote: '',
    editKey: (cfg.page && cfg.page.editKey) || '',
    moreOpen: null,          // null = standaard (dicht op gsm, open op desktop)
    showExtra: false,
    showPaste: false,
    pasteText: '',
    pending: null,           // {rows, warnings} wachtend op vervangen/toevoegen
    notices: [],
    duplicates: [],
    editing: null,           // id van de rij die inline bewerkt wordt (scherm 3)
    status: 'idle',          // idle | busy | added | error
    message: '',
    devPayload: null,
    serverQuote: null,
  };
}

/** Velden die we bewaren (geen tijdelijke UI-toestand). */
const PERSIST = ['step', 'mode', 'rows', 'nextId', 'selected', 'style', 'orgNote', 'showExtra'];

function load(cfg) {
  try {
    const raw = window.sessionStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    const data = JSON.parse(raw);
    if (!data || data.v !== 1 || !Array.isArray(data.s.rows)) return null;
    const s = { ...initialState(cfg) };
    for (const k of PERSIST) if (data.s[k] !== undefined) s[k] = data.s[k];
    s.style = { ...defaultStyle(cfg), ...(data.s.style || {}) };
    if (!s.rows.length) s.rows = initialState(cfg).rows;
    s.selected = Math.min(Math.max(0, s.selected | 0), s.rows.length - 1);
    if (s.step === 3) s.step = 2; // rechten opnieuw bevestigen
    return s;
  } catch (e) {
    return null;
  }
}

function save(state) {
  try {
    const s = {};
    for (const k of PERSIST) s[k] = state[k];
    window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ v: 1, s }));
  } catch (e) { /* opslag niet beschikbaar: niets aan te doen */ }
}

export function clearSaved() {
  try { window.sessionStorage.removeItem(STORAGE_KEY); } catch (e) { /* idem */ }
}

/**
 * @param {object} cfg
 * @param {{persist?:boolean}} [opt]
 */
export function createStore(cfg, opt = {}) {
  const persist = opt.persist !== false && !(cfg.page && cfg.page.editKey);
  let state = (persist && load(cfg)) || initialState(cfg);
  const subs = new Set();
  let timer = 0;
  return {
    get: () => state,
    /** @param {object|function(object):object} patch */
    set(patch) {
      const p = typeof patch === 'function' ? patch(state) : patch;
      if (!p) return;
      state = Object.freeze({ ...state, ...p });
      if (persist) {
        clearTimeout(timer);
        timer = setTimeout(() => save(state), 150);
      }
      for (const fn of subs) fn(state);
    },
    subscribe(fn) { subs.add(fn); return () => subs.delete(fn); },
    reset() { state = initialState(cfg); clearSaved(); for (const fn of subs) fn(state); },
  };
}
