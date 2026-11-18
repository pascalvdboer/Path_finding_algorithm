/**
 * AI-ontwerpstudio — polling van een job (SPEC-AI-DIENSTEN §10.4).
 * Interval: `pollAfterMs` van de server, anders 1,5 s en na 10 s 3 s. Stopt na `jobTimeoutSec + 10` s met een nette
 * fout (`timeout`). Pauzeert terwijl het tabblad verborgen is (geen verspilde verzoeken) en hervat bij terugkeer.
 * Netwerkfouten tijdens het wachten: tot 3 keer opnieuw met back-off, daarna `network`.
 * Klok en document zijn injecteerbaar zodat dit zonder browser getest kan worden.
 */

export const FINAL = ['done', 'failed', 'blocked', 'cancelled'];

/**
 * @param {object} o
 * @param {() => Promise<object>} o.fetchJob    haalt de JobResponse op
 * @param {(job: object) => void} o.onUpdate    elke tussenstand
 * @param {(job: object) => void} o.onFinal     done|failed|blocked|cancelled
 * @param {(err: {code: string}) => void} o.onError  timeout|network
 * @param {number} [o.timeoutSec=100]           jobTimeoutSec (90) + 10
 * @param {{ now: () => number, setTimeout: Function, clearTimeout: Function }} [o.clock]
 * @param {{ hidden: boolean, addEventListener: Function, removeEventListener: Function } | null} [o.doc]
 * @returns {{ stop: () => void, kick: () => void }}
 */
export function pollJob(o) {
  const clock = o.clock || { now: () => Date.now(), setTimeout: (f, ms) => setTimeout(f, ms), clearTimeout: (t) => clearTimeout(t) };
  const doc = o.doc !== undefined ? o.doc : typeof document !== 'undefined' ? document : null;
  const limitMs = (o.timeoutSec || 100) * 1000;
  const start = clock.now();
  let timer = null;
  let stopped = false;
  let inflight = false;
  let netFails = 0;
  let waitingForVisible = false;

  function stop() {
    stopped = true;
    if (timer) clock.clearTimeout(timer);
    timer = null;
    if (doc) doc.removeEventListener('visibilitychange', onVis);
  }

  function onVis() {
    if (!stopped && waitingForVisible && !doc.hidden) {
      waitingForVisible = false;
      tick();
    }
  }

  function next(ms) {
    if (stopped) return;
    if (timer) clock.clearTimeout(timer);
    timer = clock.setTimeout(tick, ms);
  }

  function defaultDelay() {
    return clock.now() - start > 10000 ? 3000 : 1500;
  }

  async function tick() {
    timer = null;
    if (stopped || inflight) return;
    if (clock.now() - start > limitMs) {
      stop();
      o.onError({ code: 'timeout' });
      return;
    }
    if (doc && doc.hidden) {
      waitingForVisible = true;
      return;
    }
    inflight = true;
    let job;
    try {
      job = await o.fetchJob();
      netFails = 0;
    } catch (e) {
      inflight = false;
      if (stopped) return;
      if (e && e.status && e.status !== 0 && e.status !== 502 && e.status !== 503 && e.status !== 504) {
        stop();
        o.onError({ code: e.code || 'provider_error', status: e.status });
        return;
      }
      netFails += 1;
      if (netFails > 3) {
        stop();
        o.onError({ code: 'network' });
        return;
      }
      next(1000 * 2 ** netFails);
      return;
    }
    inflight = false;
    if (stopped) return;
    if (job && FINAL.includes(job.status)) {
      stop();
      o.onFinal(job);
      return;
    }
    o.onUpdate(job || {});
    const ms = job && job.pollAfterMs > 0 ? Math.min(10000, Math.max(500, job.pollAfterMs)) : defaultDelay();
    next(ms);
  }

  if (doc) doc.addEventListener('visibilitychange', onVis);
  next(o.firstDelayMs != null ? o.firstDelayMs : 600);
  return {
    stop,
    /** Meteen opnieuw kijken (bv. na terugkeer naar het tabblad). */
    kick() {
      if (!stopped) next(0);
    },
  };
}
