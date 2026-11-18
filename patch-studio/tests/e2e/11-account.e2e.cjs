'use strict';
/**
 * E2E 11 (P06 designs-account) — the design domain behind the studio, on a real WordPress + WooCommerce site:
 *  a) REST integration suite tests/woo/run-woo-wp.php (WP-CLI, rest_do_request; every line becomes a check):
 *     revise, security-4 clone on /cart, save without e-mail, account list/ownership/claim, reorder, the reminder,
 *     storage ceiling, DST upload, preview variants;
 *  b) browser + real HTTP: a logged-in customer saves a design, "Mijn ontwerpen" shows 1 card with the right ref and
 *     a light preview, "Verder ontwerpen" opens the same design (same size and texts), account routes without nonce
 *     give 401, customer B gets 404 and does not see the design, a guest design is claimed after login, one-click
 *     reorder from the card puts 1 "Herbestelling van …" line in the cart, the cart thumbnail is the webp variant;
 *  c) reminder end to end: /email with reminder:true, the action runs once (fixed clock via tests/e2e/mu/p06-clock.php)
 *     = 1 mail with an unsubscribe link that opens a NL/FR page, a second run sends nothing;
 *  d) account.enabled = false: /mijn-account/<slug>/ answers 404 and the menu item is gone.
 * API level for the studio parts: the customer UI is being rebuilt by another team.
 */
const fs = require('fs');
const path = require('path');
const { ENV, PROJECT_DIR, E2E_DIR, Scenario, launch, wp, wpEvalJson } = require('./lib/harness.cjs');
const { API, DESIGN, token, createDesign } = require('../security/e2e-helpers.cjs');

const MU_SRC = path.join(E2E_DIR, 'mu', 'p06-clock.php');
const MU_DST = path.join(ENV.site, 'wp-content', 'mu-plugins', 'bps-p06-clock.php');
const MAILS = path.join(ENV.site, 'wp-content', 'bps-mails');
const PASS = 'p06-e2e-klant-2026';

function ensureMu() {
  const loaded = () => { try { return wp(['eval', 'echo defined("BPS_P06_TEST_HOOKS") ? "yes" : "no";']).endsWith('yes'); } catch (e) { return false; } };
  if (!loaded()) {
    fs.mkdirSync(path.dirname(MU_DST), { recursive: true });
    fs.copyFileSync(MU_SRC, MU_DST);
  }
  return loaded();
}
function ensureUser(login) {
  try { wp(['user', 'create', login, `${login}@example.test`, '--role=customer', `--user_pass=${PASS}`]); } catch (e) { wp(['user', 'update', login, `--user_pass=${PASS}`]); }
  return Number(wp(['user', 'get', login, '--field=ID']));
}
function setting(pathExpr, value) {
  wp(['eval', `$o = get_option( 'bps_settings', array() ); $o = is_array( $o ) ? $o : array(); $o${pathExpr} = ${value}; update_option( 'bps_settings', $o ); \\BorduurPatchStudio\\Settings::flush();`]);
}
function mails() {
  if (!fs.existsSync(MAILS)) return [];
  return fs.readdirSync(MAILS).filter((f) => f.endsWith('.json')).map((f) => JSON.parse(fs.readFileSync(path.join(MAILS, f), 'utf8')));
}
async function login(ctx, user) {
  const page = await ctx.newPage();
  await page.goto(`${ENV.base}/mijn-account/`, { waitUntil: 'domcontentloaded' });
  await page.fill('#username', user);
  await page.fill('#password', PASS);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}), page.click('button[name="login"]')]);
  return page;
}
/** X-WP-Nonce for the logged-in visitor, as the studio gets it (account.nonce in the public config). */
async function studioNonce(page) {
  await page.goto(ENV.studio, { waitUntil: 'domcontentloaded' });
  const html = await page.content();
  const m = /"account":\{[^}]*"nonce":"([a-f0-9]+)"/.exec(html);
  return m ? m[1] : '';
}

const t = new Scenario('11-account');
t.run(async (keep) => {
  const ok = wp(['eval', "echo class_exists('BorduurPatchStudio\\\\Rest\\\\Account_Routes') ? 'yes' : 'no';"]).endsWith('yes');
  if (!ok) t.skip('Account_Routes (P06) not loaded on the test site');
  t.ok(ensureMu(), 'P06 clock mu-plugin loaded');
  try { wp(['option', 'delete', 'bps_p06_now']); } catch (e) { /* not set */ }

  /* ---------- a) REST integration suite inside WordPress ---------- */
  const suite = wp(['eval-file', path.join(PROJECT_DIR, 'tests/woo/run-woo-wp.php')]);
  const lines = suite.split('\n').filter((l) => /^(OK|FAIL) /.test(l));
  for (const l of lines) {
    const pass = l.startsWith('OK ');
    const [msg, detail] = l.replace(/^(OK|FAIL) /, '').split(' | ');
    t.ok(pass, `[wp] ${msg}`, detail);
  }
  const res = /RESULT (\d+)\/(\d+)/.exec(suite);
  t.ok(res && Number(res[2]) >= 60 && res[1] === res[2], `[wp] integration suite complete (${res ? res[0] : 'no result line'})`, suite.slice(-800));

  const browser = await launch();
  keep(browser);
  const uidA = ensureUser('p06e2ea');
  const uidB = ensureUser('p06e2eb');
  // Start from an empty "Mijn ontwerpen" for both customers (earlier runs, reorders).
  wp(['eval', `foreach ( get_posts( array( 'post_type' => 'bps_design', 'post_status' => 'any', 'author__in' => array( ${uidA}, ${uidB} ), 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }
    foreach ( array( ${uidA}, ${uidB} ) as $u ) { delete_user_meta( $u, '_woocommerce_persistent_cart_' . get_current_blog_id() ); if ( class_exists( 'WC_Session_Handler' ) ) { ( new WC_Session_Handler() )->delete_session( $u ); } }`]);
  setting("['account']['enabled']", 'true');
  setting("['reminder']['enabled']", 'true');

  /* ---------- b) logged-in customer: save, Mijn ontwerpen, continue ---------- */
  const ctxA = await browser.newContext();
  const pageA = await login(ctxA, 'p06e2ea');
  const tok = await token(ctxA.request);
  const options = wpEvalJson("echo wp_json_encode( \\BorduurPatchStudio\\Pricing::default_options( \\BorduurPatchStudio\\Settings::get(), 'embroidered' ) );");
  const payload = { ...DESIGN, sizeCm: 9, qty: 30, options, texts: [{ text: 'Brandweer Kalmthout', font: 'sora' }] };
  const d = await createDesign(ctxA.request, tok, {}, { design: JSON.stringify(payload) });
  t.ok(d.status === 201 && d.body && d.body.designId, 'logged-in customer: POST /designs → 201', d);
  if (!d.body || !d.body.designId) return;
  const author = Number(wp(['post', 'get', String(d.body.designId), '--field=post_author']));
  t.ok(author === uidA, 'design belongs to the logged-in customer (cookie restored in REST)', { author, uidA });
  const sv = await ctxA.request.post(`${API}/designs/${d.body.designId}/save`, {
    headers: { 'Content-Type': 'application/json', 'X-BPS-Token': tok },
    data: { token: d.body.token, title: 'Brandweer 2027' },
  });
  const svb = await sv.json().catch(() => null);
  t.ok(sv.status() === 200 && svb && svb.ok && /mijn-ontwerpen/.test(svb.accountUrl || ''), 'POST /designs/{id}/save (no e-mail field) → 200 with the account URL', svb);

  await pageA.goto(`${ENV.base}/mijn-account/mijn-ontwerpen/`, { waitUntil: 'domcontentloaded' });
  const cards = pageA.locator('.bps-account-design');
  t.ok(await cards.count() === 1, '"Mijn ontwerpen" shows 1 card', await cards.count());
  const card = pageA.locator(`.bps-account-design[data-design="${d.body.designId}"]`);
  t.ok(await card.getAttribute('data-ref') === d.body.ref, `card has the right ref (${d.body.ref})`);
  t.ok(/Brandweer 2027/.test(await card.innerText()), 'card shows the customer title');
  const img = await card.locator('img').getAttribute('src');
  t.ok(/-300\.webp$/.test(img || ''), 'card preview is the 300 px webp variant', img);
  const imgRes = await ctxA.request.get(img);
  const imgBody = await imgRes.body();
  t.ok(imgRes.status() === 200 && imgBody.length <= 40960, `preview variant served (${imgBody.length} B ≤ 40 kB)`, imgRes.status());
  t.ok(await pageA.locator('.woocommerce-MyAccount-navigation a', { hasText: 'Mijn ontwerpen' }).count() === 1, 'menu item "Mijn ontwerpen"');
  const cont = await card.locator('a.bps-account-design__continue').getAttribute('href');
  const u = new URL(cont, ENV.base);
  t.ok(u.searchParams.get('bps_design') === String(d.body.designId) && /^[a-f0-9]{64}$/.test(u.searchParams.get('bps_token') || ''), '"Verder ontwerpen" links to the studio with design id + token', cont);
  const studioRes = await pageA.goto(cont, { waitUntil: 'domcontentloaded' });
  t.ok(studioRes && studioRes.status() === 200, 'studio page opens from "Verder ontwerpen"');
  const g = await ctxA.request.get(`${API}/designs/${d.body.designId}`, { headers: { 'X-BPS-Design-Token': u.searchParams.get('bps_token') } });
  const gb = await g.json().catch(() => null);
  t.ok(g.status() === 200 && gb.payload.sizeCm === 9 && gb.payload.texts[0].text === 'Brandweer Kalmthout', 'the restored design has the same size and texts', gb && gb.payload);

  // Account routes: without nonce 401, with the nonce from the studio config 200.
  const noNonce = await ctxA.request.get(`${API}/account/designs`);
  t.ok(noNonce.status() === 401, 'GET /account/designs with cookie but without X-WP-Nonce → 401', noNonce.status());
  const nonceA = await studioNonce(pageA);
  t.ok(/^[a-f0-9]{10}$/.test(nonceA), 'studio config carries account.nonce for the logged-in visitor');
  const withNonce = await ctxA.request.get(`${API}/account/designs`, { headers: { 'X-WP-Nonce': nonceA } });
  const wn = await withNonce.json().catch(() => null);
  t.ok(withNonce.status() === 200 && wn.designs.some((x) => x.id === d.body.designId), 'with nonce: 200 and the design is listed');
  t.ok(/no-store/.test(withNonce.headers()['cache-control'] || ''), 'account answers: Cache-Control no-store');

  /* ---------- customer B ---------- */
  const ctxB = await browser.newContext();
  const pageB = await login(ctxB, 'p06e2eb');
  const nonceB = await studioNonce(pageB);
  const pb = await ctxB.request.patch(`${API}/account/designs/${d.body.designId}`, { headers: { 'X-WP-Nonce': nonceB, 'Content-Type': 'application/json' }, data: { title: 'van B' } });
  const db = await ctxB.request.delete(`${API}/account/designs/${d.body.designId}`, { headers: { 'X-WP-Nonce': nonceB } });
  t.ok(pb.status() === 404 && db.status() === 404, 'customer B: PATCH and DELETE on the design of A → 404', [pb.status(), db.status()]);
  await pageB.goto(`${ENV.base}/mijn-account/mijn-ontwerpen/`, { waitUntil: 'domcontentloaded' });
  t.ok(!(await pageB.content()).includes(d.body.ref), 'the design of A is not in the list of B');
  const guestCtx = await browser.newContext();
  const guestStudio = await (await guestCtx.newPage()).goto(ENV.studio, { waitUntil: 'domcontentloaded' });
  t.ok(guestStudio && !/"account":\{[^}]*"nonce"/.test(await guestStudio.text()), 'guest studio page has no account nonce');

  /* ---------- guest design, login, claim ---------- */
  const gtok = await token(guestCtx.request);
  const gd = await createDesign(guestCtx.request, gtok, {}, { design: JSON.stringify({ ...DESIGN, qty: 15 }) });
  t.ok(gd.status === 201, 'guest: design created', gd.status);
  const gPage = await login(guestCtx, 'p06e2ea');
  const gNonce = await studioNonce(gPage);
  const cl = await guestCtx.request.post(`${API}/account/claim`, { headers: { 'X-WP-Nonce': gNonce, 'Content-Type': 'application/json' }, data: { designId: gd.body.designId, token: gd.body.token } });
  t.ok(cl.status() === 200, 'after login: POST /account/claim with the stored token → 200', cl.status());
  await guestCtx.request.post(`${API}/designs/${gd.body.designId}/save`, { headers: { 'Content-Type': 'application/json', 'X-BPS-Token': await token(guestCtx.request) }, data: { token: gd.body.token } });
  await gPage.goto(`${ENV.base}/mijn-account/mijn-ontwerpen/`, { waitUntil: 'domcontentloaded' });
  t.ok((await gPage.content()).includes(gd.body.ref), 'claimed guest design appears in "Mijn ontwerpen"');
  await guestCtx.close();

  /* ---------- one-click reorder from the card ---------- */
  const made = wpEvalJson(`
    wp_set_current_user( ${uidA} );
    wc_load_cart();
    WC()->cart->empty_cart();
    $d = \\BorduurPatchStudio\\Woo\\Designs::get( ${d.body.designId} );
    $add = \\BorduurPatchStudio\\Woo\\Cart::add_design( $d, array( 'v' => 'x', 'accepted' => true ) );
    if ( is_wp_error( $add ) ) { echo wp_json_encode( array( 'order' => 0, 'error' => $add->get_error_message() ) ); return; }
    WC()->cart->calculate_totals();
    $oid = WC()->checkout()->create_order( array( 'payment_method' => 'bacs', 'billing_email' => 'p06e2ea@example.test', 'billing_first_name' => 'An', 'billing_country' => 'BE' ) );
    $o = wc_get_order( $oid );
    $o->set_customer_id( ${uidA} );
    $o->save();
    \\BorduurPatchStudio\\Woo\\Order::mark_ordered( $o );
    $o->update_status( 'completed' );
    WC()->cart->empty_cart();
    echo wp_json_encode( array( 'order' => $oid ) );`);
  t.ok(made.order > 0, 'completed order for the saved design', made);
  await pageA.goto(`${ENV.base}/mijn-account/mijn-ontwerpen/`, { waitUntil: 'domcontentloaded' });
  const reorder = pageA.locator(`.bps-account-design[data-design="${d.body.designId}"] a.bps-account-design__reorder`);
  t.ok(await reorder.count() === 1, 'ordered card shows "Opnieuw bestellen"');
  t.ok(/Besteld/.test(await pageA.locator(`.bps-account-design[data-design="${d.body.designId}"]`).innerText()), 'card status "Besteld" with order link');
  if (await reorder.count()) {
    await Promise.all([pageA.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}), reorder.click()]);
  }
  t.ok(/winkelmand/.test(pageA.url()), 'reorder lands in the cart', pageA.url());
  const rows = await pageA.locator('.woocommerce-cart-form__cart-item').count();
  const cartText = (await pageA.content()).replace(/<[^>]+>/g, ' ');
  t.ok(rows === 1, 'cart: 1 line after the one-click reorder', rows);
  t.ok(new RegExp(`Herbestelling van ${d.body.ref}`).test(cartText), `honest line "Herbestelling van ${d.body.ref}"`);
  const thumb = await pageA.locator('.woocommerce-cart-form__cart-item img').first().getAttribute('src').catch(() => '');
  t.ok(/\.webp$/.test(thumb || '') && !/\.png$/.test(thumb || ''), 'cart thumbnail is a webp variant, not the PNG master', thumb);
  const fresh = await ctxA.request.get(thumb);
  t.ok(fresh.status() === 200 && (await fresh.body()).length <= 40960, 'cart image ≤ 40 kB');
  await ctxB.close();

  /* ---------- c) reminder end to end ---------- */
  const rctx = await browser.newContext();
  const rtok = await token(rctx.request);
  const rd = await createDesign(rctx.request, rtok, {}, { design: JSON.stringify({ ...DESIGN, qty: 12 }) });
  const before = mails().length;
  const rmail = `p06-reminder-${Date.now()}@example.test`; // A fresh address: unsubscribes of earlier runs persist.
  const em = await rctx.request.post(`${API}/designs/${rd.body.designId}/email`, {
    headers: { 'Content-Type': 'application/json', 'X-BPS-Token': rtok },
    data: { token: rd.body.token, email: rmail, reminder: true, locale: 'nl-BE' },
  });
  t.ok(em.status() === 200, 'POST /designs/{id}/email with reminder:true → 200', em.status());
  const plan = wpEvalJson(`echo wp_json_encode( array( 'next' => as_next_scheduled_action( 'bps_design_reminder', array( ${rd.body.designId} ), 'bps-reminder' ), 'now' => time() ) );`);
  t.ok(plan.next && Math.abs(plan.next - (plan.now + 3 * 86400)) < 120, 'one reminder planned at +3 days', plan);
  wp(['option', 'update', 'bps_p06_now', String(plan.now + 3 * 86400 + 60)]);
  try {
    const run = () => wp(['eval', `echo \\BorduurPatchStudio\\Woo\\Reminder::run( ${rd.body.designId} );`]);
    const first = run();
    const second = run();
    t.ok(first.endsWith('sent') && second.endsWith('already_sent'), 'reminder run: sent, then already_sent', [first, second]);
  } finally {
    wp(['option', 'delete', 'bps_p06_now']);
  }
  const reminderMails = mails().slice(before).filter((m) => m.to.includes(rmail) && /staat nog voor je klaar/.test(m.subject));
  t.ok(reminderMails.length === 1, 'exactly 1 reminder mail', reminderMails.map((m) => m.subject));
  const link = reminderMails[0] ? (/(http[^"'\s<>]+reminder\/unsubscribe[^"'\s<>]+)/.exec(reminderMails[0].message) || [])[1] : '';
  t.ok(!!link, 'reminder mail carries the unsubscribe link');
  const hdr = reminderMails[0] ? JSON.stringify(reminderMails[0].headers) : '';
  t.ok(/List-Unsubscribe:/.test(hdr), 'List-Unsubscribe header present');
  if (link) {
    const up = await rctx.newPage();
    const resp = await up.goto(link.replace(/&amp;/g, '&').replace(/&#038;/g, '&'), { waitUntil: 'domcontentloaded' });
    const txt = await up.innerText('body');
    t.ok(resp.status() === 200 && /Je bent afgemeld/.test(txt) && /Tu es désinscrit/.test(txt), 'unsubscribe link: 200 page in NL and FR', [resp.status(), txt.slice(0, 200)]);
  }
  await rctx.close();

  /* ---------- d) account.enabled = false ---------- */
  setting("['account']['enabled']", 'false');
  try {
    const off = await pageA.goto(`${ENV.base}/mijn-account/mijn-ontwerpen/`, { waitUntil: 'domcontentloaded' });
    t.ok(off.status() === 404, 'account.enabled=false: /mijn-account/mijn-ontwerpen/ → 404', off.status());
    await pageA.goto(`${ENV.base}/mijn-account/`, { waitUntil: 'domcontentloaded' });
    t.ok(await pageA.locator('.woocommerce-MyAccount-navigation a', { hasText: 'Mijn ontwerpen' }).count() === 0, 'account.enabled=false: no menu item');
  } finally {
    setting("['account']['enabled']", 'true');
    await pageA.goto(`${ENV.base}/mijn-account/`, { waitUntil: 'domcontentloaded' }); // Flush the rewrite rules back.
  }
  await ctxA.close();
});
