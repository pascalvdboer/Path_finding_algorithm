/**
 * AI-ontwerpstudio — REST-client (SPEC-AI-DIENSTEN §4). Namespace `patch-studio/v1`.
 *
 * Elke methode geeft het JSON-antwoord terug, of gooit een `ApiError` met `{status, code, message, data}` in de
 * foutvorm van de server (`{code, message, data:{status}}`). Netwerkfouten → code `network`, status 0.
 * Gasten sturen `X-BPS-Token` (zelfde token als `/designs`), ingelogden `X-WP-Nonce`. Bij 401 `bps_bad_token`
 * vragen we één keer een vers token en proberen we opnieuw.
 * Er gaat nooit een prompt of persoonsgegeven mee: enkel de velden die de klant zelf invulde (§4.3).
 */

export class ApiError extends Error {
  /** @param {number} status @param {string} code @param {string} message @param {object} [data] */
  constructor(status, code, message, data) {
    super(message || code);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.data = data || {};
  }
}

/** Willekeurige UUID v4 (idempotentiesleutel voor dubbel tikken). */
export function uuid() {
  const c = typeof globalThis !== 'undefined' ? globalThis.crypto : null;
  if (c && typeof c.randomUUID === 'function') return c.randomUUID();
  const b = new Uint8Array(16);
  if (c && c.getRandomValues) c.getRandomValues(b);
  else for (let i = 0; i < 16; i++) b[i] = Math.floor(Math.random() * 256);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

const JOB_ID = /^[0-9A-Za-z]{10,40}$/;
const enc = (id) => {
  if (!JOB_ID.test(String(id))) throw new ApiError(400, 'bps_ai_invalid', 'bad job id');
  return encodeURIComponent(String(id));
};

/**
 * @param {{ base: string, nonce?: string, token?: () => Promise<string>, lang?: string, fetch?: typeof fetch }} opts
 */
export function createApi(opts) {
  const base = String(opts.base || '').replace(/\/+$/, '');
  const lang = opts.lang === 'fr' ? 'fr' : 'nl';
  const doFetch = opts.fetch || (typeof fetch !== 'undefined' ? fetch.bind(globalThis) : null);
  let token = '';

  async function getToken(fresh) {
    if (!opts.token) return '';
    if (!token || fresh) {
      try {
        token = String((await opts.token(!!fresh)) || '');
      } catch (e) {
        token = '';
      }
    }
    return token;
  }

  async function call(method, path, body, retried) {
    if (!doFetch) throw new ApiError(0, 'network', 'fetch unavailable');
    const headers = { Accept: 'application/json', 'X-BPS-Locale': lang };
    if (opts.nonce) headers['X-WP-Nonce'] = opts.nonce;
    const tk = await getToken(false);
    if (tk) headers['X-BPS-Token'] = tk;
    let url = base + path;
    const init = { method, headers, credentials: 'same-origin', cache: 'no-store' };
    if (method === 'GET') {
      url += (url.includes('?') ? '&' : '?') + 'lang=' + lang;
    } else {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(Object.assign({ lang }, body || {}));
    }
    let res;
    try {
      res = await doFetch(url, init);
    } catch (e) {
      throw new ApiError(0, 'network', 'network');
    }
    let json = null;
    try {
      json = await res.json();
    } catch (e) {
      json = null;
    }
    if (res.ok) return json || {};
    const code = (json && json.code) || 'http_' + res.status;
    if (res.status === 401 && code === 'bps_bad_token' && !retried && opts.token) {
      await getToken(true);
      return call(method, path, body, true);
    }
    throw new ApiError(res.status, code, (json && json.message) || '', (json && json.data) || {});
  }

  return {
    lang,
    status: () => call('GET', '/ai/status'),
    generate: (body) => call('POST', '/ai/generate', body),
    job: (id) => call('GET', '/ai/jobs/' + enc(id)),
    cancel: (id) => call('POST', '/ai/jobs/' + enc(id) + '/cancel', {}),
    refine: (id, body) => call('POST', '/ai/jobs/' + enc(id) + '/refine', body),
    choose: (id, body) => call('POST', '/ai/jobs/' + enc(id) + '/choose', body),
    history: () => call('GET', '/ai/history'),
    credits: () => call('GET', '/ai/credits'),
    consent: (body) => call('POST', '/ai/credits/consent', body),
    email: (body) => call('POST', '/ai/email', body),
    emailVerify: (body) => call('POST', '/ai/email/verify', body),
    checkout: (body) => call('POST', '/ai/checkout', body),
    /** Beeld ophalen als File (na "Gebruik dit"): zelfde origin, ondertekende URL, geen extra headers nodig. */
    async file(url, name, mime) {
      let res;
      try {
        res = await doFetch(url, { credentials: 'same-origin' });
      } catch (e) {
        throw new ApiError(0, 'network', 'network');
      }
      if (!res.ok) throw new ApiError(res.status, 'storage', 'image');
      const blob = await res.blob();
      return toFile(blob, name, mime || blob.type);
    },
  };
}

/** Blob → File (met terugval voor omgevingen zonder File-constructor). */
export function toFile(blob, name, type) {
  if (typeof File === 'function') {
    try {
      return new File([blob], name, { type: type || blob.type });
    } catch (e) { /* oude Safari: hieronder */ }
  }
  const b = blob.slice(0, blob.size, type || blob.type);
  try {
    b.name = name;
    b.lastModified = Date.now();
  } catch (e) { /* alleen-lezen */ }
  return b;
}

/**
 * Vertaal een ApiError naar wat het paneel moet doen. Puur (getest).
 * @returns {{ view: string, key?: string, params?: object, sheet?: string, jobId?: string, retryAfter?: number, message?: string }}
 */
export function mapError(err) {
  const code = (err && err.code) || 'network';
  const data = (err && err.data) || {};
  switch (code) {
    case 'bps_ai_no_rounds':
      return { view: 'needRounds', sheet: 'auto' };
    case 'bps_ai_consent_needed':
      return { view: 'needRounds', sheet: 'consent' };
    case 'bps_ai_busy':
      return { view: 'waiting', jobId: data.jobId || '' };
    case 'bps_ai_slow_down':
      return { view: 'notice', key: 'ai.slow_down', params: { s: Math.max(1, Math.ceil(Number(data.retryAfter) || 8)) }, retryAfter: Math.max(1, Math.ceil(Number(data.retryAfter) || 8)) };
    case 'bps_ai_daily_limit':
      return { view: 'notice', key: 'ai.daily_limit' };
    case 'bps_ai_paused':
      return { view: 'paused', key: 'legal.ai.abuse_pause' };
    case 'bps_ai_budget':
      return data.freeOnly ? { view: 'needRounds', sheet: 'pack', key: 'ai.free_paused' } : { view: 'budget', key: 'ai.unavailable' };
    case 'bps_ai_off':
      return { view: 'budget', key: 'ai.off' };
    case 'bps_ai_invalid':
      return { view: 'notice', key: 'ai.prompt.short', message: err.message || '' };
    case 'network':
      return { view: 'notice', key: 'ai.network' };
    default:
      return { view: 'notice', key: 'ai.error.nocharge', message: '' };
  }
}
