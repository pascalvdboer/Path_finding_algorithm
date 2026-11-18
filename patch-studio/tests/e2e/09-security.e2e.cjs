'use strict';
/**
 * E2E 9 — security regressions (P01), API level (no studio UI involved):
 *  admin download / proof AJAX refuse guests and customers, a forged proof link shows nothing, a design token only
 *  opens its own design, /quotes is rate limited (6th → 429, help line from the contact settings), the private
 *  upload folder is not reachable, and a cross-site Origin/Referer is refused on every public write route.
 */
const fs = require('fs');
const path = require('path');
const { ENV, Scenario, launch, wpEvalJson } = require('./lib/harness.cjs');

const { API, png, DESIGN, ensureMuHooks, setTestOption, token, createDesign, json, ensureCustomer } = require('../security/e2e-helpers.cjs');

/* ---------- scenario ---------- */
const t = new Scenario('09-security');
t.run(async (keep) => {
  const browser = await launch();
  keep(browser);
  t.ok(ensureMuHooks(), 'P01 test hooks loaded (tests/e2e/mu/p01-test-hooks.php)');
  setTestOption({ ip: true, rate: { quotes: 5 } });
  try {
    const guest = await browser.newContext();
    const req = guest.request;
    const ip = { 'X-BPS-Test-IP': '198.51.100.91' };
    const tok = await token(req);
    t.ok(typeof tok === 'string' && tok.length >= 10, 'GET /token gives a token');

    // Two designs to play with.
    const a = await createDesign(req, tok, ip);
    const b = await createDesign(req, tok, ip);
    t.ok(a.status === 201 && b.status === 201, 'two designs created', { a, b });
    if (a.status !== 201 || b.status !== 201) return;
    const idA = a.body.designId;
    const idB = b.body.designId;

    // 1. Design token of another design → 403 (query and header).
    let r = await json(await req.get(`${API}/designs/${idA}?token=${b.body.token}`));
    t.ok(r.status === 403 && r.body.code === 'bps_forbidden', 'GET /designs/A?token=<token of B> → 403 bps_forbidden', r);
    r = await json(await req.get(`${API}/designs/${idA}`, { headers: { 'X-BPS-Design-Token': b.body.token } }));
    t.ok(r.status === 403 && r.body.code === 'bps_forbidden', 'GET /designs/A with header token of B → 403', r);
    r = await json(await req.get(`${API}/designs/${idA}`, { headers: { 'X-BPS-Design-Token': a.body.token } }));
    t.ok(r.status === 200 && r.body.ref === a.body.ref, 'GET /designs/A with its own header token → 200', r.status);

    // 2. Admin download of the original and proof AJAX: guest and customer → 403.
    const dl = `${ENV.base}/wp-admin/admin-post.php?action=bps_download&design=${idA}`;
    let raw = await req.get(dl, { maxRedirects: 0 });
    t.ok(raw.status() !== 200 && !(await raw.text()).includes('PNG'), 'bps_download as guest → refused, no file', raw.status());

    ensureCustomer();
    const cust = await browser.newContext();
    const page = await cust.newPage();
    await page.goto(`${ENV.base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await page.fill('#user_login', 'p01klant');
    await page.fill('#user_pass', 'p01-klant-2026');
    await Promise.all([page.waitForNavigation({ timeout: 30000 }).catch(() => {}), page.click('#wp-submit')]);
    const cookies = await cust.cookies();
    t.ok(cookies.some((c) => /^wordpress_logged_in_/.test(c.name)), 'customer p01klant logged in');
    const creq = cust.request;
    raw = await creq.get(dl, { maxRedirects: 0 });
    t.ok(raw.status() === 403, 'bps_download as customer without nonce → 403', raw.status());
    raw = await creq.get(`${dl}&_wpnonce=0123456789`, { maxRedirects: 0 });
    t.ok(raw.status() === 403, 'bps_download as customer with a forged nonce → 403', raw.status());
    raw = await creq.post(`${ENV.base}/wp-admin/admin-ajax.php`, { form: { action: 'bps_send_proof', order_id: '1', item_id: '1', nonce: 'forged' } });
    const proofBody = await raw.text();
    t.ok(raw.status() === 403 && !/"success":true/.test(proofBody), 'bps_send_proof as customer → 403', { status: raw.status(), body: proofBody.slice(0, 200) });

    // 3. Forged proof link → 403, no design data in the HTML.
    raw = await creq.get(`${ENV.base}/?bps_proof=${idA}&o=1&t=${'0'.repeat(64)}&a=approve`);
    const html = await raw.text();
    t.ok(raw.status() === 403, 'forged proof token → 403', raw.status());
    t.ok(!html.includes(a.body.ref), 'forged proof page does not reveal the design ref', a.body.ref);
    await cust.close();

    // 4. Rate limit on /quotes: 5 per hour → the 6th is 429 (own test IP, other scenarios are not disturbed).
    const qip = { 'X-BPS-Test-IP': `2001:db8:9:${Math.floor(Math.random() * 65535).toString(16)}::1` };
    const statuses = [];
    let last = null;
    for (let i = 0; i < 6; i++) {
      last = await json(await req.post(`${API}/quotes`, { headers: { 'X-BPS-Token': tok, 'Content-Type': 'application/json', ...qip }, data: { website: 'bot' } }));
      statuses.push(last.status);
    }
    t.ok(statuses.slice(0, 5).every((s) => s === 400) && statuses[5] === 429, '6x POST /quotes → 5x processed, 6th 429', statuses);
    t.ok(last.body && last.body.code === 'bps_rate' && /Even geduld/.test(last.body.message), '429 code bps_rate with the NL message', last.body);
    const contact = wpEvalJson('echo wp_json_encode( \\BorduurPatchStudio\\Woo\\Config::path( "contact", array() ) );');
    if (contact.whatsapp && contact.phone) t.ok(/WhatsApp/.test(last.body.message), '429 help line follows the contact settings (phone + WhatsApp)', last.body.message);

    // 5. Private folder is never served.
    const priv = path.join(ENV.site, 'wp-content/uploads/patch-studio/private');
    fs.mkdirSync(priv, { recursive: true });
    const probe = `p01-probe-${Date.now()}.txt`;
    fs.writeFileSync(path.join(priv, probe), 'secret-p01');
    raw = await req.get(`${ENV.base}/wp-content/uploads/patch-studio/private/${probe}`);
    const body = await raw.text();
    fs.unlinkSync(path.join(priv, probe));
    t.ok(raw.status() !== 200 && !body.includes('secret-p01'), 'uploads/patch-studio/private/ is not reachable', raw.status());

    // 6. Origin / Referer of another site → 403 bps_bad_origin (every public write route goes through Guard).
    const base = { 'X-BPS-Token': tok, 'Content-Type': 'application/json' };
    r = await json(await req.post(`${API}/cart`, { headers: { ...base, Origin: 'https://evil.example', 'X-BPS-Test-IP': '198.51.100.94' }, data: { designId: idA, token: a.body.token, rightsConsent: { accepted: true, v: '1' } } }));
    t.ok(r.status === 403 && r.body.code === 'bps_bad_origin', 'POST /cart with valid token and Origin https://evil.example → 403 bps_bad_origin', r);
    r = await json(await req.post(`${API}/designs/${idB}/email`, { headers: { ...base, Origin: 'https://evil.example', 'X-BPS-Test-IP': '198.51.100.94' }, data: { token: b.body.token, email: 'x@example.test' } }));
    t.ok(r.status === 403 && r.body.code === 'bps_bad_origin', 'POST /designs/{id}/email with a foreign Origin → 403', r.status);
    r = await createDesign(req, tok, { Origin: 'https://evil.example', 'X-BPS-Test-IP': '198.51.100.94' });
    t.ok(r.status === 403 && r.body.code === 'bps_bad_origin', 'POST /designs with a foreign Origin → 403', r.status);
    r = await json(await req.post(`${API}/cart`, { headers: { ...base, Referer: 'https://evil.example/x', 'X-BPS-Test-IP': '198.51.100.94' }, data: { designId: idA, token: a.body.token } }));
    t.ok(r.status === 403 && r.body.code === 'bps_bad_origin', 'POST /cart without Origin but with a foreign Referer → 403', r.status);
    r = await json(await req.post(`${API}/cart`, { headers: { ...base, Origin: ENV.base, 'X-BPS-Test-IP': '198.51.100.94' }, data: { designId: idA, token: a.body.token } }));
    t.ok(r.body && r.body.code === 'bps_rights_required', 'own Origin passes the check (next check: rights consent)', r);
    await guest.close();
  } finally {
    setTestOption(null);
  }
});
