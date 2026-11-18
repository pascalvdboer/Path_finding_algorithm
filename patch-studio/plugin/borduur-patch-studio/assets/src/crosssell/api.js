/**
 * Cross-sell — eigen REST-helper (patch-studio/v1/crosssell/*). Geen gedeelde code met de studio (SPEC-EXTRA §4).
 * Schrijfroutes dragen X-BPS-Token (GET /token); bij 403 bps_bad_token één keer een nieuw token en opnieuw.
 */

export class CsError extends Error {
  constructor(code, message, status, data) {
    super(message || code);
    this.code = code;
    this.status = status;
    this.data = data || {};
  }
}

/**
 * @param {{rest:{root:string, nonce?:string}|null, locale?:string}} config
 * @param {Function} [fetchImpl]
 */
export function createApi(config, fetchImpl) {
  const f = fetchImpl || (typeof fetch !== 'undefined' ? fetch.bind(globalThis) : null);
  const rest = (config && config.rest) || null;
  const root = rest && rest.root ? String(rest.root).replace(/\/?$/, '/') : null;
  let token = null;
  let tokenExp = 0;

  async function getToken(force = false) {
    if (!force && token && Date.now() / 1000 < tokenExp - 60) return token;
    const r = await call('GET', 'token', null, { noToken: true });
    token = r.token;
    tokenExp = Number(r.expires) || Date.now() / 1000 + 3600;
    return token;
  }

  async function call(method, path, body, { noToken = false, retried = false, timeoutMs = 0, headers: extra = {} } = {}) {
    if (!root || !f) throw new CsError('offline', 'offline', 0);
    const headers = Object.assign({ Accept: 'application/json', 'X-BPS-Locale': (config && config.locale) || 'nl-BE' }, extra);
    if (rest.nonce) headers['X-WP-Nonce'] = rest.nonce;
    if (!noToken && method !== 'GET') headers['X-BPS-Token'] = await getToken();
    let payload;
    if (body) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }
    const ctrl = typeof AbortController !== 'undefined' && timeoutMs > 0 ? new AbortController() : null;
    const timer = ctrl ? setTimeout(() => ctrl.abort(), timeoutMs) : null;
    let res;
    try {
      res = await f(root + path, { method, headers, body: payload, credentials: 'same-origin', cache: 'no-store', signal: ctrl ? ctrl.signal : undefined });
    } catch (e) {
      throw new CsError(e && e.name === 'AbortError' ? 'timeout' : 'network', 'network', 0);
    } finally {
      if (timer) clearTimeout(timer);
    }
    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (!res.ok) {
      const code = (data && data.code) || 'http_' + res.status;
      if (code === 'bps_bad_token' && !retried && !noToken) {
        await getToken(true);
        return call(method, path, body, { noToken, retried: true, timeoutMs, headers: extra });
      }
      throw new CsError(code, data && data.message, res.status, data && data.data);
    }
    return data;
  }

  return {
    online: !!root,
    /** GET /crosssell/offers — met ontwerp-token (header, niet in de URL-logs) of source=cart. */
    offers({ designId, token: designToken, source }, timeoutMs = 4000) {
      if (source === 'cart') return call('GET', 'crosssell/offers?source=cart', null, { noToken: true, timeoutMs });
      return call('GET', `crosssell/offers?designId=${encodeURIComponent(designId)}`, null, {
        noToken: true, timeoutMs, headers: { 'X-BPS-Design-Token': String(designToken || '') },
      });
    },
    addToCart: (body) => call('POST', 'crosssell/cart', body),
    undo: (key) => call('POST', 'crosssell/undo', { key }),
    event: (item, kind) => call('POST', 'crosssell/event', { item, kind }, { noToken: true }).catch(() => null),
  };
}
