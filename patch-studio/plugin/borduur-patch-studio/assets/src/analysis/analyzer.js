/**
 * Patch Studio — createAnalyzer(): runs analyzeImage() in a Web Worker, falls back to the main thread.
 * Deliberately does NOT import core.js, so the main bundle stays small: in the (rare) fallback the core is
 * loaded from the worker bundle itself as a classic <script> (worker.js then registers window.BPS.analysisCore).
 *
 * Protocol (SPEC §7.2):
 *   main → worker  {type:'analyze', job, bitmap|imageData, svgText, config: config.analysis, srcSize?}   (bitmap transferred)
 *                  {type:'cancel', job}
 *   worker → main  {type:'ready', offscreen}  once at start-up
 *                  {type:'progress', job, stage, partial}   stages: background, shape, colors, details, preview
 *                  {type:'done', job, result}               (preview/labels buffers transferred)
 *                  {type:'error', job, code, message}
 * Older jobs are ignored: a new analyze() rejects the previous promise with error.code === 'cancelled'.
 */
import { bitmapToImageData } from './decode.js';

const READY_TIMEOUT_MS = 5000;

function err(code, message) {
  const e = new Error(message || code);
  e.code = code;
  return e;
}

/**
 * @param {{workerUrl?:string|null, config?:Object, core?:{analyzeImage:Function}|null, forceMainThread?:boolean}} opts
 *        core: optional injected analysis core (tests/dev) used for the main-thread fallback
 * @returns {{analyze:(decoded:Object, o?:{job?:number, onProgress?:Function}) => Promise<Object>, cancel:(job:number)=>void, terminate:()=>void, mode:()=>string}}
 */
export function createAnalyzer({ workerUrl = null, config = {}, core = null, forceMainThread = false } = {}) {
  const A = (config && config.analysis) || {};
  const jobs = new Map(); // job → {resolve, reject, onProgress}
  let latest = 0, worker = null, offscreen = false, startP = null, mode = 'pending', corePromise = null, dead = false;

  const settle = (job, fn, value) => {
    const j = jobs.get(job);
    if (!j) return;
    jobs.delete(job);
    j[fn](value);
  };

  function onMessage(e) {
    const m = e.data || {};
    if (m.type === 'ready') return;
    const j = jobs.get(m.job);
    if (!j || m.job !== latest) return; // stale
    if (m.type === 'progress') { if (j.onProgress) try { j.onProgress({ job: m.job, stage: m.stage, partial: m.partial }); } catch (x) { /* UI */ } }
    else if (m.type === 'done') settle(m.job, 'resolve', m.result);
    else if (m.type === 'error') settle(m.job, 'reject', err(m.code || 'analysis_failed', m.message));
  }

  function start() {
    if (startP) return startP;
    startP = new Promise((resolve) => {
      if (forceMainThread || !workerUrl || typeof Worker === 'undefined') { mode = 'main'; resolve(false); return; }
      let w = null, settled = false;
      const finish = (ok) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        if (!ok) { try { if (w) w.terminate(); } catch (x) { /* ignore */ } worker = null; mode = 'main'; }
        resolve(ok);
      };
      const attach = (instance) => {
        w = instance;
        w.onmessage = (e) => {
          if (e.data && e.data.type === 'ready') { offscreen = !!e.data.offscreen; worker = w; mode = 'worker'; finish(true); return; }
          onMessage(e);
        };
        w.onerror = (e) => {
          if (e && e.preventDefault) e.preventDefault();
          if (!settled) { finish(false); return; }
          // Crash after start-up: running jobs fail (their bitmap was transferred); later jobs use the main thread.
          worker = null; mode = 'main'; startP = Promise.resolve(false);
          for (const job of [...jobs.keys()]) settle(job, 'reject', err('worker_failed', 'analysis worker crashed'));
        };
      };
      const timer = setTimeout(() => finish(false), READY_TIMEOUT_MS);
      try {
        attach(new Worker(workerUrl));
      } catch (e) {
        // Cross-origin (CDN) worker URL: start it from a same-origin Blob URL (07 §9, WP Rocket CDN).
        fetch(workerUrl, { credentials: 'same-origin' })
          .then((r) => (r.ok ? r.text() : Promise.reject(new Error('fetch'))))
          .then((code) => attach(new Worker(URL.createObjectURL(new Blob([code], { type: 'text/javascript' })))))
          .catch(() => finish(false));
      }
    });
    return startP;
  }

  function loadCore() {
    if (core) return Promise.resolve(core);
    const g = typeof window !== 'undefined' ? window : null;
    if (g && g.BPS && g.BPS.analysisCore) return Promise.resolve(g.BPS.analysisCore);
    if (corePromise) return corePromise;
    corePromise = new Promise((ok, no) => {
      if (!g || !g.document || !workerUrl) { no(err('no_analysis_core')); return; }
      const s = g.document.createElement('script');
      s.src = workerUrl;
      s.async = true;
      s.setAttribute('data-cfasync', 'false');
      s.onload = () => (g.BPS && g.BPS.analysisCore ? ok(g.BPS.analysisCore) : no(err('no_analysis_core')));
      s.onerror = () => no(err('no_analysis_core'));
      g.document.head.appendChild(s);
    });
    corePromise.catch(() => { corePromise = null; });
    return corePromise;
  }

  async function runMain(job, decoded) {
    const c = await loadCore();
    let input = decoded.bitmap || decoded.imageData, srcSize = null;
    if (decoded.bitmap && typeof OffscreenCanvas === 'undefined') {
      srcSize = { w: decoded.bitmap.width, h: decoded.bitmap.height };
      input = bitmapToImageData(decoded.bitmap, 2048);
    }
    return c.analyzeImage(input, A, {
      job, svgText: decoded.svgText || null, srcSize,
      onProgress: (p) => { const j = jobs.get(job); if (j && j.onProgress && job === latest) j.onProgress(p); },
      shouldAbort: () => job !== latest || !jobs.has(job),
    });
  }

  function analyze(decoded, { job, onProgress = null } = {}) {
    if (dead) return Promise.reject(err('terminated'));
    if (job == null) job = latest + 1;
    latest = job;
    for (const old of [...jobs.keys()]) {
      if (old === job) continue;
      if (worker) worker.postMessage({ type: 'cancel', job: old });
      settle(old, 'reject', err('cancelled'));
    }
    return new Promise((resolve, reject) => {
      if (!decoded || (!decoded.bitmap && !decoded.imageData)) { reject(err('unsupported', 'nothing to analyse')); return; }
      jobs.set(job, { resolve, reject, onProgress });
      start().then((ok) => {
        if (!jobs.has(job)) return; // cancelled meanwhile
        if (ok && worker) {
          const msg = { type: 'analyze', job, svgText: decoded.svgText || null, config: A };
          const transfer = [];
          if (decoded.bitmap && offscreen) { msg.bitmap = decoded.bitmap; transfer.push(decoded.bitmap); }
          else if (decoded.bitmap) {
            msg.srcSize = { w: decoded.bitmap.width, h: decoded.bitmap.height };
            const id = bitmapToImageData(decoded.bitmap, 2048);
            msg.imageData = id; transfer.push(id.data.buffer);
          } else {
            const d = decoded.imageData; // copy: the caller keeps its own ImageData
            msg.imageData = { width: d.width, height: d.height, data: new Uint8ClampedArray(d.data) };
            transfer.push(msg.imageData.data.buffer);
          }
          try { worker.postMessage(msg, transfer); } catch (e) { settle(job, 'reject', err('analysis_failed', String(e && e.message))); }
        } else {
          runMain(job, decoded).then(
            (res) => settle(job, 'resolve', res),
            (e) => settle(job, 'reject', e && e.code ? e : err('analysis_failed', String(e && e.message))),
          );
        }
      });
    });
  }

  return {
    analyze,
    cancel(job) {
      if (worker) worker.postMessage({ type: 'cancel', job });
      settle(job, 'reject', err('cancelled'));
    },
    terminate() {
      dead = true;
      for (const job of [...jobs.keys()]) settle(job, 'reject', err('terminated'));
      if (worker) worker.terminate();
      worker = null;
    },
    /** 'pending' | 'worker' | 'main' — for diagnostics. */
    mode: () => mode,
  };
}
