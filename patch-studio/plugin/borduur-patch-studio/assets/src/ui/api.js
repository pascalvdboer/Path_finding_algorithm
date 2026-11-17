/**
 * Patch Studio UI — REST client for patch-studio/v1 (SPEC §6.2). No dependencies.
 * Writes carry X-BPS-Token from GET /token; on 403 bps_bad_token we fetch a new token once and retry.
 */

export class ApiError extends Error {
  constructor(code, message, status, data) {
    super(message || code);
    this.code = code;
    this.status = status;
    this.data = data || {};
  }
}

export function createApi(config) {
  const rest = config.rest || null;
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

  async function call(method, path, body, { noToken = false, retried = false } = {}) {
    if (!root) throw new ApiError('offline', 'No REST root configured', 0);
    const headers = { Accept: 'application/json', 'X-BPS-Locale': config.locale || 'nl-BE' };
    if (rest.nonce) headers['X-WP-Nonce'] = rest.nonce;
    if (!noToken && method !== 'GET') headers['X-BPS-Token'] = await getToken();
    let payload = body;
    if (body && !(body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }
    let res;
    try {
      res = await fetch(root + path, { method, headers, body: payload, credentials: 'same-origin', cache: 'no-store' });
    } catch (e) {
      throw new ApiError('network', 'network', 0);
    }
    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (!res.ok) {
      const code = (data && data.code) || 'http_' + res.status;
      if (code === 'bps_bad_token' && !retried && !noToken) {
        await getToken(true);
        return call(method, path, body, { noToken, retried: true });
      }
      throw new ApiError(code, data && data.message, res.status, data && data.data);
    }
    return data;
  }

  return {
    online: !!root,
    getToken,
    quote: (input) => call('POST', 'quote', input, { noToken: true }),
    /** multipart: design JSON + optional original + preview PNG (≥ 600 px) + honeypot. */
    createDesign({ payload, original, preview, pageUrl }) {
      const fd = new FormData();
      fd.append('design', JSON.stringify(payload));
      if (original) fd.append('original', original, original.name || 'ontwerp');
      if (preview) fd.append('preview', preview, 'preview.png');
      fd.append('website', '');
      if (pageUrl) fd.append('pageUrl', pageUrl);
      return call('POST', 'designs', fd);
    },
    getDesign: (id, tok) => call('GET', `designs/${encodeURIComponent(id)}?token=${encodeURIComponent(tok)}`, null, { noToken: true }),
    addToCart: (body) => call('POST', 'cart', body),
    requestQuote: (body) => call('POST', 'quotes', body),
    emailDesign: (id, body) => call('POST', `designs/${encodeURIComponent(id)}/email`, body),
    /** Store API cart (optional pool hint). Returns null on any problem. */
    async storeCart() {
      if (!rest || !rest.storeApi) return null;
      try {
        const r = await fetch(String(rest.storeApi).replace(/\/?$/, '/') + 'cart', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        return r.ok ? await r.json() : null;
      } catch (e) {
        return null;
      }
    },
  };
}
