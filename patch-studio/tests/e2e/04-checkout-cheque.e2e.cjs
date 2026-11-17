'use strict';
/**
 * E2E 4 — second order, paid by cheque, with free shipping.
 *  owner's round illustration → printed · 8 cm · 50 pieces (≥ € 150 incl. → free shipping)
 *  price check: UI == /cart == /quote == cart page == order; shipping 0 in the quote and in the order.
 */
const path = require('path');
const fs = require('fs');
const { REF_DIR, FIXTURES, Scenario, launch, newPage, shot, studio, restQuote, cart, checkout, state } = require('./lib/harness.cjs');
const { orderInfo } = require('./lib/order-check.cjs');

const t = new Scenario('04-checkout-cheque');
t.run(async (keep) => {
  const browser = await launch();
  keep(browser);
  const { page, problems, context } = await newPage(browser);
  await cart.empty(context.request);

  const owner = path.join(REF_DIR, 'eigenaar-ronde-patch.png');
  await studio.open(page);
  await studio.upload(page, fs.existsSync(owner) ? owner : path.join(FIXTURES, 'logo-flat.png'));
  await studio.waitAnalysis(page);
  const tech = await studio.toSummary(page, { technique: 'printed', sizeCm: 8, qty: 50 });
  t.ok(tech === 'printed', 'technique printed selected', tech);
  const st = await studio.state(page);
  t.ok(st.qty === 50 && st.sizeCm === 8, 'qty 50 and 8 cm in state', { qty: st.qty, size: st.sizeCm });
  const uiTotal = await studio.uiTotal(page);
  const priceTxt = await page.locator('.bps-price').innerText();
  t.ok(/gratis/i.test(priceTxt), 'price card shows free shipping', priceTxt.replace(/\n/g, ' | ').slice(0, 300));

  const seen = await studio.order(page);
  t.ok(seen.cart && seen.cart.status === 200, 'POST /cart → 200', seen.cart);
  const ref = seen.designs && seen.designs.body && seen.designs.body.ref;
  const q = await restQuote(context.request, { technique: st.technique, sizeCm: st.sizeCm, qty: st.qty, options: st.options, locale: 'nl-BE' });
  t.ok(q.body && q.body.ok && q.body.shipping && q.body.shipping.free === true, 'server quote: free shipping', q.body && q.body.shipping);
  t.eqMoney(uiTotal, seen.cart.body.quote.totalIncl, 'UI total == /cart server quote');
  t.eqMoney(uiTotal, q.body.totalIncl, 'UI total == /quote server engine');

  const c = await cart.read(page);
  t.info(`cart (${c.type}): total ${c.total}, shipping ${String(c.shipping).split('\n')[0]}`);
  t.ok(c.type !== 'classic' || c.freePreselected, 'free shipping is pre-selected in the cart (no paid method by default)', c);
  t.eqMoney(c.total, q.body.totalIncl, 'WooCommerce cart total == server quote (free shipping)');
  await shot(page, '04-cart');

  const res = await checkout(page, { method: 'cheque', email: 'klant.cheque@example.test' });
  t.ok(!!res.orderId, 'order received page reached', res.url);
  await shot(page, '04-order-received');
  const o = orderInfo(res.orderId);
  t.ok(o.payment === 'cheque', 'payment method cheque', o.payment);
  t.ok(o.status === 'on-hold', 'cheque order is on-hold', o.status);
  t.eqMoney(o.total, q.body.totalIncl, 'order total == server quote');
  t.eqMoney(o.shipping, 0, 'order shipping is free');
  const item = (o.items || [])[0] || { meta: {} };
  t.ok(item.meta.bps_ref === ref && /Bedrukt/.test(item.meta.bps_technique || ''), 'order line: ref + "Bedrukt"', item.meta);
  t.ok(item.qty === 50, 'order line quantity 50', item.qty);

  state.merge({ chequeOrderId: res.orderId, chequeRef: ref });
  t.ok(problems.length === 0, 'no console errors', problems);
});
