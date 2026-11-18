/**
 * Patch Studio UI — the one store (DesignState, SPEC §7.4). Immutable updates, batched notifications,
 * session persistence (choices only, never the image).
 */
import { defaultOptions, enabledTechniques } from '../shared/config.js';
import { store as webStore } from './dom.js';

export const SESSION_KEY = 'bps:state:v1';
export const DEVICE_KEY = 'bps:state:v1:device';

const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
const num = (v, d) => (Number.isFinite(Number(v)) && v !== '' && v !== null ? Number(v) : d);

/** First enabled technique that can be ordered directly (fallback for broken configs). */
function fallbackTechnique(config) {
  const en = enabledTechniques(config);
  return en.find((id) => config.techniques[id].mode === 'order') || en[0] || 'embroidered';
}

/** Round qty up to the technique minimum and step. */
export function normalizeQty(config, techId, qty) {
  const t = (config.techniques && config.techniques[techId]) || {};
  const min = Math.max(1, num(t.minQty, 1));
  const step = Math.max(1, num(t.qtyStep, 1));
  const max = num(config.quantity && config.quantity.max, 10000);
  let q = Math.round(num(qty, min));
  if (q < min) q = min;
  if ((q - 0) % step) q = Math.ceil(q / step) * step;
  return clamp(q, min, max);
}

/** Keep options that are still valid for `techId`, fill required radio groups with defaults. */
export function reconcileOptions(config, techId, current) {
  const defs = defaultOptions(config, techId);
  const opts = config.options || [];
  const valid = (id) => {
    const o = opts.find((x) => x.id === id);
    return o && o.enabled !== false && (o.techniques || []).includes(techId);
  };
  const groupOf = (id) => (opts.find((x) => x.id === id) || {}).group;
  const groups = config.optionGroups || {};
  const tech = (config.techniques && config.techniques[techId]) || {};
  // One choice per radio group (last one wins), invalid ids dropped.
  let out = [];
  for (const id of (current || []).filter(valid)) {
    const g = groupOf(id);
    if ((groups[g] || {}).type === 'radio') out = out.filter((x) => groupOf(x) !== g);
    if (!out.includes(id)) out.push(id);
  }
  for (const d of defs) {
    const g = groupOf(d);
    if ((groups[g] || {}).type === 'radio' && !out.some((id) => groupOf(id) === g)) out.push(d);
  }
  // Conditional groups (e.g. velcro colour): drop when the condition is gone, fill when it appears.
  out = out.filter((id) => {
    const o = opts.find((x) => x.id === id) || {};
    const cond = o.requiresAny || (groups[o.group] || {}).showIfAny;
    return !cond || cond.some((c) => out.includes(c));
  });
  for (const [g, grp] of Object.entries(groups)) {
    if (grp.type !== 'radio' || !grp.required || !grp.showIfAny || !grp.showIfAny.some((c) => out.includes(c))) continue;
    if (out.some((id) => groupOf(id) === g)) continue;
    const inG = opts.filter((o) => o.group === g && valid(o.id));
    const pick = inG.find((o) => o.id === (tech.defaults && tech.defaults[g])) || inG.find((o) => o.default) || inG[0];
    if (pick) out.push(pick.id);
  }
  return out;
}

export function initialState(config) {
  const page = config.page || {};
  const techs = config.techniques || {};
  const pageTech = page.technique && techs[page.technique] && techs[page.technique].enabled ? page.technique : null;
  const technique = pageTech || fallbackTechnique(config);
  const shapes = config.shapes || {};
  const shape = page.shape && page.shape !== 'auto' && shapes[page.shape] ? page.shape : 'circle';
  const size = num(page.sizeCm, num(config.sizes && config.sizes.defaultCm, 8));
  const qty = normalizeQty(config, technique, num(page.qty, num(config.quantity && config.quantity.default, 10)));
  return {
    v: 1,
    designId: null,
    token: null,
    ref: null,
    source: { kind: 'upload', fileName: null, fileType: null, fileBytes: null, hash: null },
    technique,
    techniquePicked: !!pageTech,
    shape,
    shapePicked: shape !== 'circle' || (page.shape && page.shape !== 'auto'),
    aspect: 1,
    sizeCm: size,
    sizePicked: page.sizeCm != null,
    qty,
    options: defaultOptions(config, technique),
    fabric: 'auto',
    edgeColor: 'auto',
    threadOverrides: {},
    maxThreadColors: num(config.advice && config.advice.defaultMaxThreadColors, 8),
    art: { scale: 1, offsetMm: { x: 0, y: 0 }, rotation: 0 },
    texts: [],
    mockup: 'none',
    notes: '',
    locale: config.locale || 'nl-BE',
    useCase: '',
  };
}

/**
 * Sanitise a state coming from storage or a restore link: unknown keys dropped, numbers bounded,
 * enums checked against the config. Never trust stored data.
 */
export function sanitizeState(raw, config) {
  const base = initialState(config);
  if (!raw || typeof raw !== 'object') return base;
  const s = { ...base };
  const techs = config.techniques || {};
  if (raw.technique && techs[raw.technique] && techs[raw.technique].enabled) s.technique = raw.technique;
  s.techniquePicked = !!raw.techniquePicked;
  if (raw.shape && (config.shapes || {})[raw.shape]) s.shape = raw.shape;
  s.shapePicked = !!raw.shapePicked;
  s.aspect = clamp(num(raw.aspect, 1), 0.2, 5);
  const sz = config.sizes || {};
  s.sizeCm = clamp(Math.round(num(raw.sizeCm, base.sizeCm) * 2) / 2, num(sz.minCm, 4), num(sz.maxQuoteCm, 30));
  s.sizePicked = !!raw.sizePicked;
  s.qty = normalizeQty(config, s.technique, raw.qty);
  s.options = reconcileOptions(config, s.technique, Array.isArray(raw.options) ? raw.options.map(String) : []);
  s.fabric = typeof raw.fabric === 'string' ? raw.fabric.slice(0, 40) : 'auto';
  s.edgeColor = typeof raw.edgeColor === 'string' ? raw.edgeColor.slice(0, 20) : 'auto';
  s.threadOverrides = {};
  if (raw.threadOverrides && typeof raw.threadOverrides === 'object') {
    for (const [k, v] of Object.entries(raw.threadOverrides).slice(0, 32)) if (/^\d+$/.test(k)) s.threadOverrides[k] = String(v).slice(0, 20);
  }
  const choices = (config.advice && config.advice.threadColorChoices) || [6, 8, 10, 12];
  s.maxThreadColors = choices.includes(num(raw.maxThreadColors, 0)) ? num(raw.maxThreadColors, 8) : base.maxThreadColors;
  const a = raw.art || {};
  const L = config.layout || {};
  s.art = {
    scale: clamp(num(a.scale, 1), num(L.artScaleMin, 0.2), num(L.artScaleMax, 2)),
    offsetMm: { x: clamp(num(a.offsetMm && a.offsetMm.x, 0), -300, 300), y: clamp(num(a.offsetMm && a.offsetMm.y, 0), -300, 300) },
    rotation: [0, 90, 180, 270].includes(num(a.rotation, 0)) ? num(a.rotation, 0) : 0,
  };
  const T = config.text || {};
  const fonts = (config.fonts || []).map((f) => f.id);
  s.texts = (Array.isArray(raw.texts) ? raw.texts : []).slice(0, num(T.maxLayers, 10)).map((l, i) => ({
    id: String((l && l.id) || 't' + i).slice(0, 20),
    text: String((l && l.text) || '').slice(0, num(T.maxChars, 60)),
    font: fonts.includes(l && l.font) ? l.font : fonts[0] || 'Sora',
    heightMm: clamp(num(l && l.heightMm, num(T.defaultHeightMm, 6)), num(T.minHeightMm, 3), num(T.maxHeightMm, 40)),
    threadCode: String((l && l.threadCode) || 'auto').slice(0, 20),
    path: ['straight', 'arc_top', 'arc_bottom'].includes(l && l.path) ? l.path : 'straight',
    offsetMm: { x: clamp(num(l && l.offsetMm && l.offsetMm.x, 0), -150, 150), y: clamp(num(l && l.offsetMm && l.offsetMm.y, 0), -150, 150) },
    angleDeg: clamp(num(l && l.angleDeg, 0), -180, 180),
    insetMm: clamp(num(l && l.insetMm, 0), -5, 60),
    letterSpacing: clamp(num(l && l.letterSpacing, 0.05), -0.05, 0.5),
    outline: ['auto', 'on', 'none'].includes(l && l.outline) ? l.outline : 'auto',
    autoFit: !!(l && l.autoFit),
  }));
  // View on a garment (UI only; the stage checks the id against the garments render can draw).
  s.mockup = typeof raw.mockup === 'string' && /^[a-z][a-z_]{1,29}$/.test(raw.mockup) ? raw.mockup : 'none';
  s.notes = String(raw.notes || '').slice(0, 2000);
  s.useCase = String(raw.useCase || '').slice(0, 30);
  const src = raw.source || {};
  s.source = {
    kind: src.kind === 'text' ? 'text' : 'upload',
    fileName: src.fileName ? String(src.fileName).slice(0, 200) : null,
    fileType: src.fileType ? String(src.fileType).slice(0, 100) : null,
    fileBytes: src.fileBytes != null ? num(src.fileBytes, null) : null,
    hash: src.hash ? String(src.hash).slice(0, 80) : null,
  };
  s.designId = raw.designId != null ? num(raw.designId, null) : null;
  s.token = raw.token ? String(raw.token).slice(0, 128) : null;
  s.ref = raw.ref ? String(raw.ref).slice(0, 40) : null;
  return s;
}

/**
 * Minimal store: get(), set(patch | fn), subscribe(fn(state, changedKeys)).
 * Notifications are batched per microtask ("gedebounced 0 ms", SPEC §7.8).
 */
export function createStore(initial) {
  let state = initial;
  let changed = new Set();
  let queued = false;
  const subs = new Set();
  const flush = () => {
    queued = false;
    const c = [...changed];
    changed = new Set();
    for (const fn of subs) fn(state, c);
  };
  return {
    get: () => state,
    set(patch) {
      const p = typeof patch === 'function' ? patch(state) : patch;
      if (!p) return;
      let any = false;
      const next = { ...state };
      for (const [k, v] of Object.entries(p)) {
        if (next[k] !== v) { next[k] = v; changed.add(k); any = true; }
      }
      if (!any) return;
      state = next;
      if (!queued) { queued = true; Promise.resolve().then(flush); }
    },
    subscribe(fn) { subs.add(fn); return () => subs.delete(fn); },
    /** Force subscribers to run (e.g. after the analysis changed outside the state). */
    touch(key = 'analysis') {
      changed.add(key);
      if (!queued) { queued = true; Promise.resolve().then(flush); }
    },
  };
}

/** Persist choices (never the image). Device storage only after the explicit "Bewaar op dit toestel". */
export function persist(state, device = false) {
  const data = JSON.stringify(state);
  const s = webStore('sessionStorage');
  if (s) try { s.setItem(SESSION_KEY, data); } catch (e) { /* quota */ }
  if (device) {
    const l = webStore('localStorage');
    if (l) try { l.setItem(DEVICE_KEY, data); } catch (e) { /* quota */ }
  }
}

export function loadPersisted() {
  for (const [kind, key] of [['sessionStorage', SESSION_KEY], ['localStorage', DEVICE_KEY]]) {
    const s = webStore(kind);
    if (!s) continue;
    try {
      const v = s.getItem(key);
      if (v) return { data: JSON.parse(v), device: kind === 'localStorage' };
    } catch (e) { /* corrupt */ }
  }
  return null;
}

export function hasDeviceCopy() {
  const l = webStore('localStorage');
  try { return !!(l && l.getItem(DEVICE_KEY)); } catch (e) { return false; }
}

export function clearPersisted() {
  for (const [kind, key] of [['sessionStorage', SESSION_KEY], ['localStorage', DEVICE_KEY]]) {
    const s = webStore(kind);
    if (s) try { s.removeItem(key); } catch (e) { /* ignore */ }
  }
}
