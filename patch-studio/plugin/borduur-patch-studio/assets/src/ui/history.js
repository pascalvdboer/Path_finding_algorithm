/**
 * Patch Studio UI — undo/redo for design choices (Canva-like). Snapshots of the design keys only: server ids,
 * source file, price pool and notes are never rewound. Changes in quick succession (dragging, typing) form one step.
 */
const KEYS = ['technique', 'techniquePicked', 'shape', 'shapePicked', 'aspect', 'sizeCm', 'sizePicked', 'qty', 'options', 'fabric', 'edgeColor',
  'threadOverrides', 'maxThreadColors', 'art', 'texts', 'mockup'];

export function createHistory(store, { limit = 60, onChange } = {}) {
  let past = [], future = [], base = null, timer = 0, applying = false;
  const snap = (s) => { const o = {}; for (const k of KEYS) o[k] = s[k]; return o; };
  const same = (a, b) => KEYS.every((k) => a[k] === b[k]);
  const notify = () => onChange && onChange();

  function commit() {
    clearTimeout(timer);
    timer = 0;
    const cur = snap(store.get());
    if (base && !same(base, cur)) {
      past.push(base);
      if (past.length > limit) past.shift();
      future = [];
    }
    base = cur;
    notify();
  }

  store.subscribe((s, changed) => {
    if (applying || !base) return;
    if (!changed.some((k) => KEYS.includes(k))) return;
    clearTimeout(timer);
    timer = setTimeout(commit, 450);
  });

  function apply(to) {
    applying = true;
    store.set(to);
    base = to;
    Promise.resolve().then(() => { applying = false; });
    notify();
  }

  return {
    /** Start a fresh history (new design, after the automatic choices). */
    reset() { clearTimeout(timer); timer = 0; past = []; future = []; base = snap(store.get()); notify(); },
    commit,
    undo() {
      if (timer) commit();
      if (!past.length) return false;
      future.push(snap(store.get()));
      apply(past.pop());
      return true;
    },
    redo() {
      if (timer) commit();
      if (!future.length) return false;
      past.push(snap(store.get()));
      apply(future.pop());
      return true;
    },
    canUndo: () => past.length > 0 || (!!timer && !!base && !same(base, snap(store.get()))),
    canRedo: () => future.length > 0,
  };
}
