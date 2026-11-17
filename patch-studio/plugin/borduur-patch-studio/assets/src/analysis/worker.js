/**
 * Patch Studio — analysis Web Worker entry (bundled to dist/patch-studio-worker.js, SPEC §5.2).
 * In a worker: answers the protocol documented in analyzer.js.
 * Loaded as a classic <script> on a page (main-thread fallback): registers window.BPS.analysisCore instead.
 */
import { analyzeImage } from './core.js';

const isWorker = typeof WorkerGlobalScope !== 'undefined' && typeof self !== 'undefined' && self instanceof WorkerGlobalScope; // eslint-disable-line no-undef

if (isWorker) {
  let current = 0;
  const cancelled = new Set();
  self.onmessage = async (e) => {
    const m = e.data || {};
    if (m.type === 'cancel') { cancelled.add(m.job); return; }
    if (m.type !== 'analyze') return;
    const job = m.job;
    current = job;
    const input = m.bitmap || m.imageData;
    try {
      const result = await analyzeImage(input, m.config || {}, {
        job,
        svgText: m.svgText || null,
        srcSize: m.srcSize || null,
        onProgress: (p) => self.postMessage({ type: 'progress', job, stage: p.stage, partial: p.partial }),
        shouldAbort: () => current !== job || cancelled.has(job),
      });
      const transfer = [result.preview.data.buffer, result.labels.buffer];
      if (result.backdrop) transfer.push(result.backdrop.data.buffer);
      self.postMessage({ type: 'done', job, result }, transfer);
    } catch (err) {
      self.postMessage({ type: 'error', job, code: (err && err.code) || 'analysis_failed', message: String((err && err.message) || err) });
    } finally {
      cancelled.delete(job);
      if (m.bitmap && typeof m.bitmap.close === 'function') m.bitmap.close();
    }
  };
  self.postMessage({ type: 'ready', offscreen: typeof OffscreenCanvas !== 'undefined' });
} else if (typeof window !== 'undefined') {
  const g = window;
  g.BPS = g.BPS || {};
  g.BPS.analysisCore = { analyzeImage };
}
