'use strict';
/**
 * E2E 3 — in the cart with the right price, then checkout with bank transfer (bacs).
 *  flat logo → embroidered · 8 cm · 10 pieces → "Bestel deze patch"
 *  price check: UI total == POST /cart quote == POST /quote (server engine) == WooCommerce cart total == order total
 *  cart shows the design ref, technique, size and the custom-made notice; order meta + design status "ordered".
 */
const path = require('path');
const { ENV, FIXTURES, Scenario, launch, newPage, shot, studio, restQuote, cart, checkout, state } = require('./lib/harness.cjs');
const { orderInfo } = require('./lib/order-check.cjs');

const t = new Scenario('03-cart-checkout-bacs');
t.run(async (keep) => {
  const browser = await launch();
  keep(browser);
  const { page, problems, context } = await newPage(browser);
  await cart.empty(context.request);

  await studio.open(page);
  await studio.upload(page, path.join(FIXTURES, 'logo-flat.png'));
  await studio.waitAnalysis(page);
  const tech = await studio.toSummary(page, { technique: 'embroidered', sizeCm: 8, qty: 10 });
  t.ok(tech === 'embroidered', 'technique embroidered selected', tech);
  t.ok(/Overzicht/.test(await studio.currentStep(page)), 'step 5 "Overzicht" is current');
  const summary = await page.locator('.bps-summary').innerText();
  t.ok(/Geborduurd/.test(summary) && /8 cm/.test(summary) && /10/.test(summary), 'summary lists technique, size and quantity', summary.replace(/\n/g, ' | '));
  t.ok(/digitale proef/i.test(await page.locator('.bps-step:visible').innerText()), '"Wat er nu gebeurt" mentions the digital proof');
  const rights = page.locator('.bps-rights input[type=checkbox]');
  t.ok(!(await rights.isChecked()), 'rights checkbox is NOT pre-checked');

  // Clicking order without rights → inline error, no request.
  await page.locator('.bps-cta .bps-btn--primary').first().click();
  t.ok(await page.locator('.bps-rights .bps-field__error:visible').count() === 1, 'order without rights confirmation shows an inline error');
  const uiTotal = await studio.uiTotal(page);
  const btn = await page.locator('.bps-cta .bps-btn--primary').first().innerText();
  t.ok(/Bestel deze patch/.test(btn), 'order button "Bestel deze patch · € …"', btn);
  await shot(page, '03-step5');

  const st = await studio.state(page);
  const seen = await studio.order(page);
  t.ok(seen.designs && seen.designs.status === 201, 'POST /designs → 201', seen.designs);
  t.ok(seen.cart && seen.cart.status === 200 && seen.cart.body && seen.cart.body.ok, 'POST /cart → ok', seen.cart);
  const ref = seen.designs && seen.designs.body && seen.designs.body.ref;
  t.ok(/^PS-\d{4}-\d{4,}$/.test(ref || ''), 'design ref PS-YYYY-NNNN', ref);
  const cartQuote = seen.cart && seen.cart.body && seen.cart.body.quote;

  // Independent server price via POST /quote with the same choices.
  const input = { technique: st.technique, sizeCm: st.sizeCm, qty: st.qty, options: st.options, locale: 'nl-BE' };
  const q = await restQuote(context.request, input);
  t.ok(q.status === 200 && q.body && q.body.ok, 'POST /quote ok', q);

  t.eqMoney(uiTotal, cartQuote && cartQuote.totalIncl, 'UI total == /cart server quote');
  t.eqMoney(uiTotal, q.body && q.body.totalIncl, 'UI total == /quote server engine');

  // WooCommerce cart page.
  t.ok(/winkelmand/.test(page.url()), 'redirected to the cart', page.url());
  const c = await cart.read(page);
  t.info(`cart (${c.type}): total ${c.total}, fees ${JSON.stringify(c.fees)}, shipping ${c.shipping}`);
  t.eqMoney(c.total, q.body && q.body.totalIncl, 'WooCommerce cart total == server quote (incl. fees + shipping)');
  const rowText = (c.rows || []).join(' ');
  t.ok(rowText.includes(ref), 'cart line shows the design ref', rowText.slice(0, 300));
  t.ok(/Geborduurd/.test(rowText) && /8 cm/.test(rowText), 'cart line shows technique and size', rowText.slice(0, 300));
  t.ok(/herroepingsrecht|digitale proef/i.test(rowText), 'cart line shows the custom-made notice', rowText.slice(0, 400));
  const feeText = (c.fees || []).join(' ');
  t.ok(/€/.test(feeText) || c.type === 'block', 'one-time fee shown in the cart', c.fees);
  const thumb = await page.locator('.woocommerce-cart-form__cart-item img, .wc-block-cart-item__image img').first().getAttribute('src').catch(() => null);
  t.ok(!!thumb && /patch-studio\/previews\//.test(thumb), 'cart thumbnail is the design preview', thumb);
  await shot(page, '03-cart');

  // Checkout with bank transfer.
  const res = await checkout(page, { method: 'bacs', email: 'klant.overschrijving@example.test' });
  t.ok(!!res.orderId, 'order received page reached', res.url);
  await shot(page, '03-order-received');
  const thanks = await page.locator('main, #content, body').first().innerText();
  t.ok(/BE68\s?5390\s?0754\s?7034|BE68539007547034|Overschrijving/i.test(thanks), 'thank-you page shows bank transfer instructions', thanks.slice(0, 200));

  const o = orderInfo(res.orderId);
  t.ok(o.found, 'order exists');
  t.ok(o.payment === 'bacs', 'payment method bacs', o.payment);
  t.ok(o.status === 'on-hold', 'bacs order is on-hold (awaiting payment)', o.status);
  t.eqMoney(o.total, q.body && q.body.totalIncl, 'order total == server quote');
  const item = (o.items || [])[0] || { meta: {} };
  t.ok(item.meta.bps_ref === ref, 'order item meta bps_ref', item.meta.bps_ref);
  t.ok(/Geborduurd/.test(item.meta.bps_technique || ''), 'order item meta bps_technique', item.meta.bps_technique);
  t.ok(/8 cm/.test(item.meta.bps_size || ''), 'order item meta bps_size "8 cm (… × … mm)"', item.meta.bps_size);
  t.ok(!!item.meta.bps_rights, 'rights confirmation stored on the order line', item.meta.bps_rights);
  t.ok(!!item.meta._bps_payload, 'payload snapshot stored (_bps_payload)');
  t.ok(item.design && item.design.status === 'ordered', 'design status "ordered"', item.design);
  t.ok(item.design && Number(item.design.order_id) === Number(res.orderId), 'design linked to the order', item.design);

  // Order confirmation mail was caught (mu-plugin) and contains the preview.
  await page.waitForTimeout(500);
  const fs = require('fs');
  const mailDir = path.join(ENV.site, 'wp-content/bps-mails');
  const mails = fs.existsSync(mailDir) ? fs.readdirSync(mailDir).map((f) => JSON.parse(fs.readFileSync(path.join(mailDir, f), 'utf8'))) : [];
  const mine = mails.filter((m) => (m.to || []).some((x) => x.includes('klant.overschrijving@example.test')));
  t.ok(mine.length >= 1, 'customer order mail sent', mails.map((m) => m.subject));
  if (mine.length) t.ok(/patch-studio\/previews\//.test(mine[mine.length - 1].message), 'order mail shows the design preview');

  state.merge({ bacsOrderId: res.orderId, bacsRef: ref, bacsDesignId: seen.designs.body.designId, bacsTotal: o.total });
  t.ok(problems.length === 0, 'no console errors', problems);
});
