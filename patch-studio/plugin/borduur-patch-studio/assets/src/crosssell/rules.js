/**
 * Cross-sell — kaartkeuze (SPEC-EXTRA §2.1). JS-tweeling van includes/crosssell/class-rules.php.
 * De volgorde volgt de bestelling en de pastoets, NOOIT de marge.
 */

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const num = (v, d = 0) => (typeof v === 'number' && Number.isFinite(v) ? v : d);

/**
 * Past een regel bij de bestelcontext?
 * @param {Object} when  { qtyMin?, qtyMax?, class?: string[], technique?: string[], mode?: string }
 * @param {{qty:number, technique:string, kind:string, month:number, mode:string}} c
 */
export function ruleMatches(when, c) {
  if (!isObj(when)) return true;
  if (when.qtyMin !== undefined && !(c.qty >= num(when.qtyMin))) return false;
  if (when.qtyMax !== undefined && !(c.qty <= num(when.qtyMax))) return false;
  if (Array.isArray(when.class) && !when.class.includes(c.kind)) return false;
  if (Array.isArray(when.technique) && !when.technique.includes(c.technique)) return false;
  if (typeof when.mode === 'string' && when.mode !== (c.mode || 'patch')) return false;
  return true;
}

/**
 * Kies de kaarten.
 * @param {Object<string,{fit:string}>} fits   item-id → resultaat van fitItem (alleen kandidaten met een koopbaar product)
 * @param {{qty:number, technique:string, kind:string, month:number, mode?:string}} context
 * @param {Object} cfg  crosssell-config (items, rules, maxCards)
 * @param {Object<string,{autoDisabled?:boolean}>} [stats]
 * @returns {{ids:string[], rule:string|null}}
 */
export function pick(fits, context, cfg, stats = {}) {
  const items = (cfg && Array.isArray(cfg.items) ? cfg.items : []).filter(isObj);
  const max = Math.max(0, Math.floor(num(cfg && cfg.maxCards, 3)));
  const c = {
    qty: num(context && context.qty),
    technique: String((context && context.technique) || ''),
    kind: String((context && context.kind) || 'flat'),
    month: Math.floor(num(context && context.month, 1)),
    mode: String((context && context.mode) || 'patch'),
  };
  const usable = (id) => {
    const it = items.find((i) => i.id === id);
    if (!it || it.enabled === false) return false;
    if (stats && isObj(stats[id]) && stats[id].autoDisabled) return false;
    const f = fits && fits[id];
    return isObj(f) && (f.fit === 'ok' || f.fit === 'adjust');
  };

  let rule = null;
  for (const r of cfg && Array.isArray(cfg.rules) ? cfg.rules : []) {
    if (isObj(r) && ruleMatches(r.when, c)) {
      rule = r;
      break;
    }
  }
  let order = rule && Array.isArray(rule.order) ? rule.order.map(String) : [];
  if (rule && isObj(rule.boost) && Array.isArray(rule.boost.months) && rule.boost.months.includes(c.month)) {
    const b = String(rule.boost.item || '');
    if (order.includes(b)) order = [b, ...order.filter((x) => x !== b)];
  }
  const out = [];
  for (const id of order) if (usable(id) && !out.includes(id)) out.push(id);
  for (const it of items) if (usable(it.id) && !out.includes(it.id)) out.push(it.id);
  return { ids: out.slice(0, max), rule: rule ? String(rule.id || '') : null };
}
