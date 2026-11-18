'use strict';
/**
 * E2E 17 (P04 woo-cart-pricing) — prices in every WooCommerce context + reorder. API/WP-CLI level, no studio UI
 * (the customer UI is being rebuilt by another team):
 *  a) POST /cart, then a request that only LOADS the cart: wc-ajax=get_refreshed_fragments shows "10 × € 5,98"
 *     (BE, incl. btw) and at most 2 meta lines per patch; the cart page still shows every line incl. "Maatwerk";
 *  b) Store API: items[].prices.regular_price === price and sale_price === price (no fake discount);
 *  c) woocommerce_prices_include_tax = no / yes: same subtotal 49,40 and same total; _bps_unit_excl 4,94 on the order;
 *  d) "Opnieuw bestellen" on a completed order, after the old design was removed (Cleanup): one line with a NEW design,
 *     an honest "Herbestelling van …" line, the setup fee with reorderKeepsSetup=false and none with true;
 *     the old order stays linked to the old design.
 */
const { ENV, Scenario, launch, wp, wpEvalJson } = require('./lib/harness.cjs');
const { API, DESIGN, token, createDesign } = require('../security/e2e-helpers.cjs');

const decode = (html) => String(html || '')
  .replace(/<[^>]+>/g, ' ')
  .replace(/&times;|&#215;/g, '×').replace(/&euro;|&#8364;/g, '€').replace(/&nbsp;|&#160;| /g, ' ')
  .replace(/\s+/g, ' ').trim();

function setPromise(on) {
  wp(['eval', `$o = get_option( 'bps_settings', array() ); $o = is_array( $o ) ? $o : array(); $o['promises']['reorderKeepsSetup'] = ${on ? 'true' : 'false'}; update_option( 'bps_settings', $o ); \\BorduurPatchStudio\\Settings::flush();`]);
}

const t = new Scenario('17-minicart-reorder');
t.run(async (keep) => {
  const browser = await launch();
  keep(browser);

  /* ---------- a) mini-cart fragments ---------- */
  const ctx = await browser.newContext();
  const req = ctx.request;
  const tok = await token(req);
  const options = wpEvalJson("echo wp_json_encode( \\BorduurPatchStudio\\Pricing::default_options( \\BorduurPatchStudio\\Settings::get(), 'embroidered' ) );");
  const d = await createDesign(req, tok, {}, { design: JSON.stringify({ ...DESIGN, options }) });
  t.ok(d.status === 201 && d.body && d.body.designId, 'design created (POST /designs)', d);
  if (!d.body || !d.body.designId) return;
  const designId = d.body.designId;
  const c = await req.post(`${API}/cart`, {
    headers: { 'Content-Type': 'application/json', 'X-BPS-Token': tok },
    data: { designId, token: d.body.token, rightsConsent: { v: 'bogus-client', accepted: true } },
  });
  const cb = await c.json().catch(() => null);
  t.ok(c.status() === 200 && cb && cb.ok, 'POST /cart 200', cb);
  if (cb && cb.quote) t.ok(Object.prototype.hasOwnProperty.call(cb.quote, 'estimate'), 'cart quote carries an estimate key (null without lead-time settings)', cb.quote.estimate);

  const f = await req.post(`${ENV.base}/?wc-ajax=get_refreshed_fragments`);
  const fj = await f.json().catch(() => null);
  const frag = fj && fj.fragments ? fj.fragments['div.widget_shopping_cart_content'] : '';
  const text = decode(frag);
  t.info('mini-cart: ' + text.slice(0, 240));
  t.ok(/10\s*×\s*€\s*5,98/.test(text), 'mini-cart fragment: "10 × € 5,98" (price set on cart load)', text);
  const items = String(frag).split(/<li[^>]*class="[^"]*mini_cart_item/).slice(1);
  const dts = items.map((li) => (li.match(/<dt/g) || []).length);
  t.ok(items.length === 1 && dts.every((n) => n <= 2), 'mini-cart: at most 2 meta lines per patch', dts);

  const cartPage = await req.get(`${ENV.base}/winkelmand/`);
  const cartHtml = decode(await cartPage.text());
  t.ok(/Maatwerk/.test(cartHtml) && /Techniek/.test(cartHtml), 'cart page still shows every line incl. "Maatwerk"');

  /* ---------- b) Store API ---------- */
  const s = await req.get(`${ENV.base}/wp-json/wc/store/v1/cart`);
  const sj = await s.json().catch(() => null);
  const pr = sj && sj.items && sj.items[0] ? sj.items[0].prices : null;
  t.ok(pr && pr.regular_price === pr.price && pr.sale_price === pr.price, 'Store API: regular_price == price == sale_price', pr);
  t.ok(pr && Number(pr.price) > 0, 'Store API: price > 0', pr);
  await ctx.close();

  /* ---------- c) prices incl./excl. btw ---------- */
  const php = (mode) => `
    update_option( 'woocommerce_prices_include_tax', '${mode}' );
    wc_load_cart();
    WC()->cart->empty_cart();
    $d = \\BorduurPatchStudio\\Woo\\Designs::get( ${designId} );
    $r = \\BorduurPatchStudio\\Woo\\Cart::add_design( $d, array( 'v' => 'x', 'accepted' => true ) );
    WC()->cart->calculate_totals();
    $oid = WC()->checkout()->create_order( array( 'payment_method' => 'bacs', 'billing_email' => 'p04@example.test', 'billing_country' => 'BE' ) );
    $o = wc_get_order( $oid );
    $unit = 0; foreach ( $o->get_items() as $it ) { $unit = (float) $it->get_meta( '_bps_unit_excl' ); }
    $out = array( 'sub' => (float) WC()->cart->get_subtotal(), 'total' => (float) WC()->cart->get_total( 'edit' ), 'unit' => $unit, 'order' => $oid );
    $o->delete( true );
    WC()->cart->empty_cart();
    echo wp_json_encode( $out );`;
  const before = wp(['option', 'get', 'woocommerce_prices_include_tax']);
  let no;
  let yes;
  try {
    no = wpEvalJson(php('no'));
    yes = wpEvalJson(php('yes'));
  } finally {
    wp(['option', 'update', 'woocommerce_prices_include_tax', before || 'no']);
  }
  t.eqMoney(no.sub, 49.40, 'prices excl. btw: subtotal 49,40');
  t.eqMoney(yes.sub, 49.40, 'prices incl. btw: subtotal 49,40');
  t.eqMoney(yes.total, no.total, 'same total in both modes');
  t.ok(Math.abs(no.unit - 4.94) < 0.0001 && Math.abs(yes.unit - 4.94) < 0.0001, '_bps_unit_excl 4,94 on the order line (both modes)', { no: no.unit, yes: yes.unit });

  /* ---------- d) reorder ---------- */
  try { wp(['user', 'create', 'p04klant', 'p04klant@example.test', '--role=customer', '--user_pass=p04-klant-2026']); } catch (e) { /* exists */ }
  const made = wpEvalJson(`
    $u = get_user_by( 'login', 'p04klant' );
    wp_set_current_user( $u->ID );
    wc_load_cart();
    WC()->cart->empty_cart();
    $d = \\BorduurPatchStudio\\Woo\\Designs::get( ${designId} );
    \\BorduurPatchStudio\\Woo\\Cart::add_design( $d, array( 'v' => 'x', 'accepted' => true ) );
    WC()->cart->calculate_totals();
    $oid = WC()->checkout()->create_order( array( 'payment_method' => 'bacs', 'billing_email' => 'p04klant@example.test', 'billing_first_name' => 'Jan', 'billing_country' => 'BE' ) );
    $o = wc_get_order( $oid );
    $o->set_customer_id( $u->ID );
    $o->save();
    \\BorduurPatchStudio\\Woo\\Order::mark_ordered( $o );
    $o->update_status( 'completed' );
    WC()->cart->empty_cart();
    $item = 0; foreach ( $o->get_items() as $it ) { $item = $it->get_id(); }
    $author = (int) get_post_field( 'post_author', ${designId} );
    wp_delete_post( ${designId}, true ); // Cleanup removed the design and its files.
    echo wp_json_encode( array( 'order' => $oid, 'item' => $item, 'author' => $author, 'uid' => $u->ID, 'ref' => (string) wc_get_order_item_meta( $item, 'bps_ref' ) ) );`);
  t.ok(made.order > 0, 'completed order with a patch line for the customer', made);
  t.ok(made.author === made.uid, 'mark_ordered linked the anonymous design to the order customer (klant-account-2)', made);

  const cctx = await browser.newContext();
  const page = await cctx.newPage();
  await page.goto(`${ENV.base}/mijn-account/`, { waitUntil: 'domcontentloaded' });
  await page.fill('#username', 'p04klant');
  await page.fill('#password', 'p04-klant-2026');
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}), page.click('button[name="login"]')]);
  await page.goto(`${ENV.base}/mijn-account/view-order/${made.order}/`, { waitUntil: 'domcontentloaded' });
  const again = page.locator('a.order-again, .order-again a').first();
  t.ok(await again.count(), '"Opnieuw bestellen" button on the completed order');
  if (await again.count()) {
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}), again.click()]);
  }
  await page.goto(`${ENV.base}/winkelmand/`, { waitUntil: 'domcontentloaded' });
  const body = decode(await page.content());
  const rows = await page.locator('.woocommerce-cart-form__cart-item').count();
  t.ok(rows === 1, 'cart has 1 line after reorder', rows);
  t.ok(new RegExp(`Herbestelling van ${made.ref}`).test(body), `honest line "Herbestelling van ${made.ref}"`, body.slice(0, 400));
  t.ok(!/kan niet|niet meer beschikbaar|could not be added|unavailable/i.test(body), 'no error notice');
  t.ok(/Borduurkaart/i.test(body), 'reorderKeepsSetup=false: setup fee "Borduurkaart en instelkosten" charged');

  setPromise(true);
  try {
    await page.goto(`${ENV.base}/winkelmand/`, { waitUntil: 'domcontentloaded' });
    const body2 = decode(await page.content());
    t.ok(!/Borduurkaart/i.test(body2), 'reorderKeepsSetup=true: no setup fee');
    t.ok(/geen nieuwe instelkosten/i.test(body2), 'cart explains the waived setup fee');
  } finally {
    setPromise(false);
  }

  const link = wpEvalJson(`
    $q = get_posts( array( 'post_type' => 'bps_design', 'post_status' => 'any', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'meta_key' => '_bps_reorder_of' ) );
    $new = $q ? $q[0]->ID : 0;
    $ro = $new ? get_post_meta( $new, '_bps_reorder_of', true ) : null;
    echo wp_json_encode( array( 'new' => $new, 'reorder_of' => $ro, 'oldItemDesign' => (int) wc_get_order_item_meta( ${made.item}, '_bps_design_id' ) ) );`);
  t.ok(link.new > 0 && link.new !== designId, 'reorder created a new design record', link);
  t.ok(link.reorder_of && Number(link.reorder_of.order_id) === made.order, 'new design refers to the old order', link.reorder_of);
  t.ok(link.oldItemDesign === designId, 'old order stays linked to the old design id', link);
  await cctx.close();
});
