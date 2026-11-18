/**
 * Diensten — REST-client (SPEC-AI-DIENSTEN §4.2): POST /services/cart, POST /services/dismiss, GET /services/catalog.
 * Headers zoals /cart: X-BPS-Token (gast), X-WP-Nonce (ingelogd), X-BPS-Locale. Gooit `{status, code, message, data}`.
 */

/**
 * @param {{ base: string, nonce?: string, token?: () => Promise<string>, lang?: string, fetch?: typeof fetch }} opts
 */
export function createServicesApi(opts) {
  const o = opts || {};
  const base = String(o.base || '').replace(/\/+$/, '');
  const lang = o.lang === 'fr' ? 'fr' : 'nl';
  const doFetch = o.fetch || (typeof fetch !== 'undefined' ? fetch.bind(globalThis) : null);

  async function call(method, path, body) {
    if (!doFetch) throw { status: 0, code: 'network', message: 'network', data: {} };
    const headers = { Accept: 'application/json', 'X-BPS-Locale': lang };
    if (o.nonce) headers['X-WP-Nonce'] = o.nonce;
    if (o.token) {
      try {
        const tk = await o.token();
        if (tk) headers['X-BPS-Token'] = String(tk);
      } catch (e) { /* zonder token: server antwoordt 401 */ }
    }
    const init = { method, headers, credentials: 'same-origin', cache: 'no-store' };
    let url = base + path;
    if (method === 'GET') url += (url.includes('?') ? '&' : '?') + 'lang=' + lang;
    else {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(Object.assign({ lang }, body || {}));
    }
    let res;
    try {
      res = await doFetch(url, init);
    } catch (e) {
      throw { status: 0, code: 'network', message: 'network', data: {} };
    }
    let json = null;
    try {
      json = await res.json();
    } catch (e) {
      json = null;
    }
    if (res.ok) return json || {};
    throw { status: res.status, code: (json && json.code) || 'http_' + res.status, message: (json && json.message) || '', data: (json && json.data) || {} };
  }

  return {
    catalog: () => call('GET', '/services/catalog'),
    cart: (serviceId, designRef) => call('POST', '/services/cart', { serviceId, designRef }),
    dismiss: (serviceId, designRef) => call('POST', '/services/dismiss', { serviceId, designRef }),
  };
}
