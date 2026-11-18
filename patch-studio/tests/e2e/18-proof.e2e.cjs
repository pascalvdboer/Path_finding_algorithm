'use strict';
/**
 * E2E 18 — proof workflow (premium P05), on seeded orders (tests/e2e/mu/p05-proof-seed.php):
 *  1. mobile proof page 390×844: approve button visible without scrolling and ≥ 48 px, image links to full size,
 *     Referrer-Policy no-referrer, standalone (no theme scripts), axe 0 serious/critical; a POST without the check
 *     box shows the error and changes nothing; ticked + approve → approved;
 *  2. the same design in order A and B: B starts without a proof, B approves, A stays byte-identical and A's link
 *     still says "already approved";
 *  3. an old link (design format, before the per-line storage) still opens the page;
 *  4. a refunded order: link → 410 with the notice, POST changes nothing, AJAX "send proof" is refused;
 *  5. whole-order proof for 3 patches: 1 mail, 1 page, "Alles goedkeuren" approves all three (mobile, axe);
 *  6. My account: the customer sees "Wacht op jouw goedkeuring" + "Proef bekijken" (valid page); another customer
 *     gets WooCommerce's refusal and no link.
 */
const { ENV, Scenario, launch, newPage, shot, axe, adminLogin, wpEvalJson, phpClassExists } = require('./lib/harness.cjs');
const { ensureSeed, seedOrder, proofOf, mailsSince, mailSnapshot, links } = require('../proof/e2e-helpers.cjs');

const t = new Scenario('18-proof');

/** Send a proof for a line from PHP and return the customer link of the current round. */
function sendProof(orderId, itemId, imageId = 0) {
  return wpEvalJson(`$o = wc_get_order(${Number(orderId)}); $i = $o->get_item(${Number(itemId)});
    $r = \\BorduurPatchStudio\\Proof\\Proof_Service::send( $o, $i, 'Hier is je proef.', ${Number(imageId)} );
    echo wp_json_encode( array( 'error' => is_wp_error( $r ) ? $r->get_error_code() : '', 'url' => \\BorduurPatchStudio\\Proof\\Proof_Service::url( wc_get_order(${Number(orderId)}), wc_get_order(${Number(orderId)})->get_item(${Number(itemId)}) ) ) );`);
}
/** Create (or reuse) a customer account; returns its id. */
function customer(login) {
  return wpEvalJson(`$u = get_user_by( 'login', ${JSON.stringify(login)} );
    $id = $u ? $u->ID : wp_insert_user( array( 'user_login' => ${JSON.stringify(login)}, 'user_pass' => 'klant-wachtwoord-2026', 'user_email' => ${JSON.stringify(login + '@example.test')}, 'role' => 'customer' ) );
    echo wp_json_encode( array( 'id' => (int) $id ) );`).id;
}
async function login(page, user) {
  await page.goto(`${ENV.base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', 'klant-wachtwoord-2026');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#wp-submit')]);
}

t.run(async (keep) => {
  if (!phpClassExists('BorduurPatchStudio\\Proof\\Proof_Service')) t.skip('Proof\\Proof_Service not in the plugin');
  if (!ensureSeed()) t.skip('seed helper (tests/e2e/mu/p05-proof-seed.php) could not be loaded');
  const browser = await launch();
  keep(browser);

  /* ---------- 1. mobile proof page ---------- */
  const a = seedOrder({ lines: 1 });
  const lineA = a.items[0];
  const imageId = wpEvalJson(`$f = get_option( 'bps_p05_fixture' ); $up = wp_upload_bits( 'proef-e2e.png', null, file_get_contents( $f ) );
    $id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'proef', 'post_status' => 'inherit' ), $up['file'] );
    require_once ABSPATH . 'wp-admin/includes/image.php'; wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
    echo wp_json_encode( array( 'id' => (int) $id, 'url' => wp_get_attachment_url( $id ) ) );`);
  const sentA = sendProof(a.order_id, lineA.item_id, imageId.id);
  t.ok(!sentA.error && /[?&]item=\d+/.test(sentA.url), 'proof sent for order A (link with item=)', sentA);

  const { page: mp, problems, context: mctx } = await newPage(browser, { mobile: true });
  const resp = await mp.goto(sentA.url, { waitUntil: 'domcontentloaded' });
  t.ok(resp && resp.status() === 200, 'proof page → 200', resp && resp.status());
  t.ok(resp.headers()['referrer-policy'] === 'no-referrer', 'header Referrer-Policy: no-referrer');
  const html = await mp.content();
  t.ok(/<meta name="referrer" content="no-referrer">/.test(html), 'meta referrer no-referrer');
  t.ok(!/wp-content\/themes\//.test(html), 'standalone page: no theme assets');
  t.ok((html.match(/<main/g) || []).length === 1, 'exactly one <main>');
  const btn = mp.locator('[data-bps-submit]');
  const box = await btn.boundingBox();
  t.ok(box && box.y >= 0 && box.y + box.height <= 844, 'approve button visible without scrolling (390×844)', box);
  t.ok(box && box.height >= 48, 'approve button ≥ 48 px high', box);
  const imgHref = await mp.locator('.bps-pp__imglink').first().getAttribute('href');
  t.ok(imgHref === imageId.url, 'image links to the full-size proof', { imgHref, full: imageId.url });
  const v = await axe(mp, '#bps-proof');
  if (v) t.ok(v.length === 0, 'axe: 0 serious/critical on the proof page', v);
  await shot(mp, '18-proof-mobile');

  // POST without the check box (bypassing the browser's "required"): error, nothing stored.
  const form = await mp.evaluate(() => Object.fromEntries(new FormData(document.querySelector('form.bps-pp__form'))));
  delete form.bps_confirm;
  const post = await mctx.request.post(`${ENV.base}/`, { form });
  const postHtml = await post.text();
  t.ok(/spelling, de kleuren, de maat en het aantal/.test(postHtml), 'POST without the check box → error message');
  t.ok(proofOf(a.order_id, lineA.item_id).status === 'sent', 'status unchanged without the check box');
  await mp.locator('input[name=bps_confirm]').check();
  await Promise.all([mp.waitForLoadState('domcontentloaded'), btn.click()]);
  t.ok(/goedgekeurd/i.test(await mp.locator('h1').innerText()), 'approved page shown');
  const proofA = proofOf(a.order_id, lineA.item_id);
  t.ok(proofA.status === 'approved', 'order line A approved', proofA.status);
  t.ok(problems.length === 0, 'no console errors on the proof page', problems);
  await mctx.close();

  /* ---------- 2. same design in order B ---------- */
  const b = seedOrder({ lines: 1, design_id: lineA.design_id });
  const lineB = b.items[0];
  t.ok(lineB.design_id === lineA.design_id, 'order B uses the same design id');
  t.ok(proofOf(b.order_id, lineB.item_id).status === 'none', 'B starts at "Nog geen proef verstuurd"');
  const beforeA = JSON.stringify(proofOf(a.order_id, lineA.item_id));
  const sentB = sendProof(b.order_id, lineB.item_id);
  const { page: bp, context: bctx } = await newPage(browser, { mobile: true });
  await bp.goto(sentB.url, { waitUntil: 'domcontentloaded' });
  await bp.locator('input[name=bps_confirm]').check();
  await Promise.all([bp.waitForLoadState('domcontentloaded'), bp.locator('[data-bps-submit]').click()]);
  t.ok(proofOf(b.order_id, lineB.item_id).status === 'approved', 'B approved through its own link');
  t.ok(JSON.stringify(proofOf(a.order_id, lineA.item_id)) === beforeA, 'proof data of A unchanged (byte-identical JSON)');
  await bp.goto(sentA.url, { waitUntil: 'domcontentloaded' });
  t.ok(/al goedgekeurd/.test(await bp.locator('.bps-pp__card').innerText()), "A's link says 'already approved'");

  /* ---------- 3. old link format ---------- */
  const c = seedOrder({ lines: 1 });
  const lineC = c.items[0];
  const legacy = wpEvalJson(`$o = wc_get_order(${c.order_id}); $i = $o->get_item(${lineC.item_id}); $i->delete_meta_data( '_bps_proof' ); $i->save();
    update_post_meta( ${lineC.design_id}, '_bps_proof', array( 'status' => 'sent', 'round' => 1, 'order_id' => ${c.order_id}, 'item_id' => ${lineC.item_id}, 'sent_at' => gmdate( 'c' ), 'history' => array( array( 'action' => 'sent', 'round' => 1 ) ) ) );
    echo wp_json_encode( array( 'url' => add_query_arg( array( 'bps_proof' => ${lineC.design_id}, 'o' => ${c.order_id}, 't' => \\BorduurPatchStudio\\Proof\\Proof_Service::legacy_token( ${lineC.design_id}, ${c.order_id}, 1 ), 'a' => 'approve' ), home_url( '/' ) ) ) );`);
  const lr = await bctx.request.get(legacy.url);
  t.ok(lr.status() === 200 && /Proef goedkeuren/.test(await lr.text()), 'old link (design format) still opens the proof page', lr.status());
  await bctx.close();

  /* ---------- 4. refunded order ---------- */
  const d = seedOrder({ lines: 1 });
  const sentD = sendProof(d.order_id, d.items[0].item_id);
  wpEvalJson(`wc_get_order(${d.order_id})->update_status( 'refunded' ); echo '{}';`);
  const guest = await browser.newContext();
  const r410 = await guest.request.get(sentD.url);
  const t410 = await r410.text();
  t.ok(r410.status() === 410, 'refunded order: proof link → 410', r410.status());
  t.ok(/geannuleerd/.test(t410), 'notice "Deze bestelling is geannuleerd"');
  const q = Object.fromEntries(new URL(sentD.url).searchParams);
  const p410 = await guest.request.post(`${ENV.base}/`, { form: { ...q, bps_confirm: '1' } });
  t.ok(p410.status() === 410 && proofOf(d.order_id, d.items[0].item_id).status === 'cancelled', 'POST changes nothing (status cancelled)');
  await guest.close();
  const { page: ap, context: actx } = await newPage(browser);
  await adminLogin(ap);
  await ap.goto(`${ENV.base}/wp-admin/admin.php?page=wc-orders&action=edit&id=${d.order_id}`, { waitUntil: 'domcontentloaded' });
  const nonce = await ap.evaluate(() => { const el = document.querySelector('script.bps-admin-data'); return el ? JSON.parse(el.textContent).nonce : ''; });
  t.ok(/Geannuleerd/.test(await ap.locator('[data-bps-production]').innerText()), 'fiche shows "Geannuleerd"');
  const aj = await actx.request.post(`${ENV.base}/wp-admin/admin-ajax.php`, { form: { action: 'bps_send_proof', order_id: String(d.order_id), item_id: String(d.items[0].item_id), nonce } });
  const ajBody = await aj.json().catch(() => ({}));
  t.ok(aj.status() === 400 && ajBody.success === false, 'AJAX "send proof" on a refunded order → error', ajBody);
  await ap.goto(`${ENV.base}/wp-admin/admin.php?page=wc-orders`, { waitUntil: 'domcontentloaded' });
  const row = ap.locator(`tr#order-${d.order_id}, tr:has(a[href*="id=${d.order_id}"])`).first();
  t.ok(/Geannuleerd/.test(await row.innerText().catch(() => '')), 'orders list column shows "Geannuleerd"');

  /* ---------- 5. whole-order proof ---------- */
  const e = seedOrder({ lines: 3 });
  const before = mailSnapshot();
  await ap.goto(`${ENV.base}/wp-admin/admin.php?page=wc-orders&action=edit&id=${e.order_id}`, { waitUntil: 'domcontentloaded' });
  const orderProof = ap.locator('[data-bps-order-proof]');
  t.ok(await orderProof.count() === 1, 'button "Proef voor hele bestelling versturen" on the order screen');
  await Promise.all([ap.waitForLoadState('domcontentloaded'), orderProof.click()]);
  await Promise.all([ap.waitForURL(/bps_order_proof=sent/, { timeout: 20000 }), ap.locator('[data-bps-order-proof-send]').click()]);
  const fresh = mailsSince(before);
  t.ok(fresh.length === 1 && /bps_proof_order=/.test(fresh[0].message), 'one mail for the whole order', fresh.map((m) => m.subject));
  await actx.close();
  const orderLink = fresh[0] ? links(fresh[0], /bps_proof_order=/)[0] : '';
  const { page: op, context: octx } = await newPage(browser, { mobile: true });
  await op.goto(orderLink, { waitUntil: 'domcontentloaded' });
  t.ok((await op.locator('section.bps-pp__card').count()) === 3, 'one card per patch');
  const v2 = await axe(op, '#bps-proof');
  if (v2) t.ok(v2.length === 0, 'axe: 0 serious/critical on the whole-order page', v2);
  await shot(op, '18-proof-order-mobile');
  await op.locator('input[name=bps_confirm]').check();
  await Promise.all([op.waitForLoadState('domcontentloaded'), op.locator('[data-bps-approve-all]').click()]);
  const statuses = e.items.map((i) => proofOf(e.order_id, i.item_id).status);
  t.ok(statuses.every((s) => s === 'approved'), '"Alles goedkeuren" approves all 3 lines', statuses);
  await octx.close();

  /* ---------- 6. My account ---------- */
  const uid = customer('klant-proef-e2e');
  customer('andere-klant-e2e');
  const f = seedOrder({ lines: 1, customer_id: uid, email: 'klant-proef-e2e@example.test' });
  sendProof(f.order_id, f.items[0].item_id);
  const viewUrl = wpEvalJson(`echo wp_json_encode( array( 'url' => wc_get_endpoint_url( 'view-order', ${f.order_id}, wc_get_page_permalink( 'myaccount' ) ) ) );`).url;
  const { page: cp, context: cctx } = await newPage(browser, { mobile: true });
  await login(cp, 'klant-proef-e2e');
  await cp.goto(viewUrl, { waitUntil: 'domcontentloaded' });
  const acc = await cp.locator('body').innerText();
  t.ok(/Wacht op jouw goedkeuring/.test(acc), 'view-order shows "Wacht op jouw goedkeuring"', acc.slice(0, 300));
  const proofBtn = cp.locator('.bps-account-proof__btn');
  t.ok(await proofBtn.count() === 1, 'button "Proef bekijken"');
  if (await proofBtn.count()) {
    const href = await proofBtn.getAttribute('href');
    const rr = await cctx.request.get(href);
    t.ok(rr.status() === 200 && /Proef goedkeuren/.test(await rr.text()), '"Proef bekijken" leads to a valid confirmation page');
  }
  await cctx.close();
  const { page: xp, context: xctx } = await newPage(browser);
  await login(xp, 'andere-klant-e2e');
  await xp.goto(viewUrl, { waitUntil: 'domcontentloaded' });
  t.ok((await xp.locator('.bps-account-proof__btn').count()) === 0, 'another customer sees no proof link');
  await xctx.close();
});
