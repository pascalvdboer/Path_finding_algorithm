'use strict';
/**
 * E2E 8 — cart edge cases + the owner's phone photo (classic AND block cart/checkout, depending on BPS_WP_CHECKOUT).
 *  a) owner's GSM photo (reference/eigenaar-foto-patch.jpg) analyses in WordPress without errors and is never cropped away;
 *  b) design in the cart, customer changes the quantity IN THE CART (10 → 25): unit price follows the tiers,
 *     cart total == POST /quote for 25 pieces;
 *  c) second design (text-only) in the same cart: both lines present, total ≤ sum of the two single quotes (tier pool);
 *  d) checkout at 390 px: no horizontal scroll, custom-made notice visible, order placed, both lines on the order.
 */
const path = require('path');
const { REF_DIR, FIXTURES, ENV, Scenario, launch, newPage, shot, studio, restQuote, cart, checkout, wpEvalJson } = require('./lib/harness.cjs');

const t = new Scenario('08-cart-edges');
t.run(async (keep) => {
  const browser = await launch();
  keep(browser);
  const { page, problems, context } = await newPage(browser);
  await cart.empty(context.request);

  /* ---------- a) owner's phone photo ---------- */
  await studio.open(page);
  await studio.upload(page, path.join(REF_DIR, 'eigenaar-foto-patch.jpg'));
  await studio.waitAnalysis(page, 60000);
  const found = await page.locator('.bps-found').innerText();
  t.info('analysis log (gsm-foto): ' + found.replace(/\n/g, ' | '));
  t.ok(/rond/i.test(found), 'phone photo: round patch recognised', found);
  t.ok(await page.locator('.bps-advice, .bps-techs').first().isVisible().catch(() => false) || (await page.locator('.bps-step:visible').count()) > 0, 'phone photo: studio continues to the advice');
  await shot(page, '08-gsm-photo-step1');

  /* ---------- b) quantity change in the cart ---------- */
  await studio.open(page);
  await page.evaluate(() => { try { sessionStorage.clear(); } catch (e) {} });
  await studio.open(page);
  await studio.upload(page, path.join(FIXTURES, 'logo-flat.png'));
  await studio.waitAnalysis(page);
  await studio.toSummary(page, { technique: 'embroidered', sizeCm: 8, qty: 10 });
  const st = await studio.state(page);
  const seen = await studio.order(page);
  t.ok(seen.cart && seen.cart.status === 200, 'design 1 in the cart', seen.cart && seen.cart.status);
  const ref1 = seen.designs && seen.designs.body && seen.designs.body.ref;

  const base = { technique: st.technique, sizeCm: st.sizeCm, options: st.options, locale: 'nl-BE' };
  const q10 = (await restQuote(context.request, { ...base, qty: 10 })).body;
  const q25 = (await restQuote(context.request, { ...base, qty: 25 })).body;
  let c = await cart.read(page);
  t.eqMoney(c.total, q10 && q10.totalIncl, `cart total (${c.type}) == /quote for 10`);

  if (c.type === 'block') {
    const input = page.locator('.wc-block-components-quantity-selector__input').first();
    await input.fill('25');
    const done = page.waitForResponse((r) => /wc\/store\/v1\/(batch|cart\/update-item)/.test(r.url()) && r.request().method() === 'POST', { timeout: 15000 }).catch(() => null);
    await input.blur();
    await done;
    await page.waitForTimeout(1200);
  } else {
    await page.locator('.woocommerce-cart-form input.qty').first().fill('25');
    await page.locator('button[name="update_cart"]').click();
    await page.waitForSelector('.blockUI', { state: 'detached', timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(1200);
  }
  c = await cart.read(page);
  t.eqMoney(c.total, q25 && q25.totalIncl, `after changing the quantity in the cart to 25: total == /quote for 25`);
  t.ok(q25 && q10 && q25.unitExcl <= q10.unitExcl, 'unit price for 25 ≤ unit price for 10 (tiers)', { u10: q10 && q10.unitExcl, u25: q25 && q25.unitExcl });
  await shot(page, `08-cart-qty25-${c.type}`);


  /* ---------- c) second design (text only) ---------- */
  await studio.open(page);
  await page.evaluate(() => { try { sessionStorage.clear(); } catch (e) {} });
  await studio.open(page);
  await page.locator('.bps-studio [data-bps-action="text-only"], .bps-studio button:has-text("Start met tekst")').first().click();
  await page.waitForSelector('.bps-layer input[type=text]', { timeout: 15000 });
  await page.fill('.bps-layer input[type=text]', 'KFC KALMTHOUT');
  await page.waitForTimeout(400);
  await studio.toSummary(page, { technique: 'embroidered', sizeCm: 8, qty: 10 });
  const st2 = await studio.state(page);
  const seen2 = await studio.order(page);
  t.ok(seen2.cart && seen2.cart.status === 200, 'design 2 (text only) in the cart', seen2.cart && seen2.cart.body);
  const ref2 = seen2.designs && seen2.designs.body && seen2.designs.body.ref;
  t.ok(ref2 && ref2 !== ref1, 'second design gets its own ref', { ref1, ref2 });
  const q2 = (await restQuote(context.request, { technique: st2.technique, sizeCm: st2.sizeCm, qty: st2.qty, options: st2.options, locale: 'nl-BE' })).body;
  c = await cart.read(page);
  const rows = (c.rows || []).join(' ');
  t.ok(rows.includes(ref1) && rows.includes(ref2), 'cart shows both designs', rows.slice(0, 300));
  const sum = (q25 ? q25.totalIncl : 0) + (q2 ? q2.totalIncl : 0);
  t.ok(typeof c.total === 'number' && c.total > 0 && c.total <= sum + 0.005, `two designs: cart total ${c.total} ≤ sum of single quotes ${sum.toFixed(2)} (tier pool / shared fee)`);
  await shot(page, `08-cart-two-designs-${c.type}`);

  /* ---------- d) checkout on a phone ---------- */
  const storage = await context.storageState();
  const m = await newPage(browser, { mobile: true, storageState: storage });
  const mp = m.page;
  await mp.goto(`${ENV.base}/afrekenen/`, { waitUntil: 'domcontentloaded' });
  await mp.waitForSelector('#email, #billing_first_name', { timeout: 30000 });
  await mp.waitForTimeout(1200);
  const [sw, iw] = await mp.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
  t.ok(sw <= iw, `checkout (390 px): no horizontal scroll (${sw} ≤ ${iw})`);
  const box = await mp.locator('#email, #billing_first_name').first().boundingBox();
  t.ok(box && box.x >= 0 && box.x + box.width <= iw, 'checkout (390 px): form fields fully on screen (not clipped)', box);
  const body = await mp.locator('body').innerText();
  t.ok(/maatwerk|op maat|herroepingsrecht|digitale proef/i.test(body), 'checkout shows the custom-made / digital proof notice');
  await shot(mp, `08-m-checkout-${c.type}`);
  const res = await checkout(mp, { method: 'bacs', email: 'klant.mobiel@example.test' });
  t.ok(!!res.orderId, 'order placed from the phone', res);
  await shot(mp, `08-m-order-received-${c.type}`);
  if (res.orderId) {
    const o = wpEvalJson(`$o = wc_get_order(${res.orderId}); $refs = array(); foreach ( $o->get_items() as $i ) { $refs[] = $i->get_meta( 'bps_ref' ); } echo wp_json_encode( array( 'total' => (float) $o->get_total(), 'refs' => $refs ) );`);
    t.ok(o.refs.includes(ref1) && o.refs.includes(ref2), 'order has both design lines', o.refs);
    t.eqMoney(o.total, c.total, 'order total == cart total');
  }

  t.ok(problems.length === 0 && m.problems.length === 0, 'no console errors', [...problems, ...m.problems]);
});
