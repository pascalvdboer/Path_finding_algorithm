'use strict';
/**
 * E2E 5 — production sheet in wp-admin for the order of scenario 3 (bank transfer).
 *  order screen: production card with preview, ref, technique, size in mm, thread codes, analysis;
 *  "download origineel" returns the uploaded file as an attachment (admin only); printable A4 sheet;
 *  digital proof: send from the order → mail with link → customer approves → status "approved" + order note.
 * Skips (exit 77) when scenario 3 did not run or the production module is not in the plugin yet.
 */
const fs = require('fs');
const path = require('path');
const { ENV, Scenario, launch, newPage, shot, axe, adminLogin, state, phpClassExists, wpEvalJson } = require('./lib/harness.cjs');
const { orderInfo } = require('./lib/order-check.cjs');

const t = new Scenario('05-admin-fiche');
const st = state.read();
if (!st.bacsOrderId) t.skip('no order from 03-cart-checkout-bacs (run that scenario first)');

t.run(async (keep) => {
  const browser = await launch();
  keep(browser);
  const o = orderInfo(st.bacsOrderId);
  t.ok(o.found, 'order from scenario 3 exists', st.bacsOrderId);
  const { page, problems, context } = await newPage(browser);
  await adminLogin(page);
  await page.goto(o.edit_url, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#order_data, .woocommerce-order-data, #woocommerce-order-items', { timeout: 30000 });

  // Visible order item meta (woo module) — always required.
  const items = await page.locator('#woocommerce-order-items').innerText();
  t.ok(items.includes(st.bacsRef), 'order items show the design ref', items.slice(0, 300));
  t.ok(/Techniek/.test(items) && /Geborduurd/.test(items), 'order items show "Techniek: Geborduurd" (readable labels)');
  t.ok(!/_bps_/.test(items), 'hidden _bps_ meta is not shown');

  if (!phpClassExists('BorduurPatchStudio\\Admin\\Order_Production')) {
    await shot(page, '05-order-no-production');
    t.info('production module (Admin\\Order_Production) not present yet: production sheet checks skipped');
    t.ok(problems.length === 0, 'no console errors on the order screen', problems);
    return;
  }

  /* ---------- production card ---------- */
  const prod = page.locator('[data-bps-production]');
  await prod.waitFor({ timeout: 15000 });
  t.ok(await prod.isVisible(), 'production metabox visible on the order screen');
  const card = prod.locator('.bps-card').first();
  const cardText = await card.innerText();
  t.ok(cardText.includes(st.bacsRef), 'card shows the ref', cardText.slice(0, 200));
  t.ok(/Geborduurd/.test(cardText), 'card shows the technique');
  t.ok(/\d+\s*×\s*\d+\s*mm/.test(cardText), 'card shows the size in mm (w × h)');
  t.ok(/\b\d{4}\b/.test(cardText) && /garen|Polyneon/i.test(cardText), 'card lists thread colours with Polyneon codes');
  t.ok(/steken|steek/i.test(cardText), 'card shows the estimated stitch count');
  const preview = await card.locator('.bps-preview img').first().getAttribute('src').catch(() => null);
  t.ok(!!preview && /patch-studio\/previews\//.test(preview), 'card shows the preview image', preview);
  await shot(page, '05-order-fiche');
  const v = await axe(page, '[data-bps-production]');
  if (v) t.ok(v.length === 0, 'axe: 0 serious/critical in the production metabox', v);

  /* ---------- download original ---------- */
  const dl = card.locator('.bps-card__files a.button-primary, a[href*="action=bps_download"]').first();
  const href = await dl.getAttribute('href').catch(() => null);
  t.ok(!!href && /bps_download/.test(href), 'download-original link present', href);
  if (href) {
    const r = await context.request.get(href);
    const body = await r.body();
    t.ok(r.status() === 200, 'download original → 200 for admin', r.status());
    t.ok(/attachment/i.test(r.headers()['content-disposition'] || ''), 'served as attachment', r.headers()['content-disposition']);
    t.ok(body.slice(0, 8).toString('hex') === '89504e470d0a1a0a', 'the original PNG comes back byte-for-byte (PNG signature)');
    const fixture = fs.readFileSync(path.join(__dirname, 'fixtures', 'logo-flat.png'));
    t.ok(Buffer.compare(body, fixture) === 0, 'downloaded original is identical to the uploaded file');
    // Same URL without the admin session must not leak the file.
    const anon = await browser.newContext();
    const r2 = await anon.request.get(href, { maxRedirects: 0 }).catch((e) => ({ status: () => 0, e }));
    t.ok(r2.status() !== 200 || !/attachment/i.test((r2.headers && r2.headers()['content-disposition']) || ''), 'download refused without login', r2.status());
    await anon.close();
  }

  /* ---------- printable sheet ---------- */
  const printHref = await prod.locator('.bps-prod__print').first().getAttribute('href').catch(() => null);
  t.ok(!!printHref && /bps_print_fiche/.test(printHref), 'print link present', printHref);
  if (printHref) {
    const pp = await context.newPage();
    const r = await pp.goto(printHref);
    t.ok(r && r.status() === 200, 'printable sheet → 200', r && r.status());
    const txt = await pp.locator('body').innerText();
    t.ok(txt.includes(st.bacsRef), 'printable sheet shows the ref');
    await pp.screenshot({ path: path.join(ENV.artifacts, '05-print.png'), fullPage: true }).catch(() => {});
    await pp.close();
  }

  /* ---------- digital proof ---------- */
  const open = card.locator('[data-bps-proof-open]').first();
  const adminJs = fs.existsSync(path.join(ENV.site, 'wp-content/plugins/borduur-patch-studio/assets/dist/patch-studio-admin.js'));
  if (!(await open.count())) {
    t.info('no "Digitale proef versturen" button: proof flow skipped');
  } else if (!adminJs) {
    t.info('assets/dist/patch-studio-admin.js not built yet (npm run build): proof flow skipped');
    // The missing bundle is expected until the build runs; do not count its 404 twice.
    const keepOnly = problems.filter((p) => !/patch-studio-admin\.js|status of 404/.test(p));
    problems.splice(0, problems.length, ...keepOnly);
  } else {
    const mailDir = path.join(ENV.site, 'wp-content/bps-mails');
    const before = new Set(fs.existsSync(mailDir) ? fs.readdirSync(mailDir) : []);
    await open.click();
    const dlg = page.locator('dialog[data-bps-proof-dialog]');
    await dlg.waitFor({ state: 'visible', timeout: 10000 });
    const to = dlg.locator('[data-bps-proof-to]');
    t.ok((await to.inputValue()).includes('klant.overschrijving@example.test'), 'proof recipient pre-filled with the billing e-mail', await to.inputValue());
    await dlg.locator('[data-bps-proof-msg]').fill('Hier is je digitale proef. Laat ons weten of alles klopt.');
    await dlg.locator('[data-bps-proof-send]').click();
    await page.waitForFunction(() => !document.querySelector('dialog[data-bps-proof-dialog][open]'), null, { timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(500);
    const statusTxt = await card.locator('[data-bps-proof-status]').innerText().catch(() => '');
    t.ok(/verstuurd|verzonden|wacht/i.test(statusTxt), 'proof status on the card: sent', statusTxt.slice(0, 200));
    const fresh = (fs.existsSync(mailDir) ? fs.readdirSync(mailDir) : []).filter((f) => !before.has(f))
      .map((f) => JSON.parse(fs.readFileSync(path.join(mailDir, f), 'utf8')));
    const proofMail = fresh.find((m) => /bps_proof=/.test(m.message));
    t.ok(!!proofMail, 'proof mail sent with approve/change links', fresh.map((m) => m.subject));
    if (proofMail) {
      t.ok(proofMail.to.some((x) => x.includes('klant.overschrijving@example.test')), 'proof mail to the customer', proofMail.to);
      const links = [...proofMail.message.matchAll(/href=["']([^"']*bps_proof=[^"']*)["']/g)].map((m) => m[1].replace(/&amp;/g, '&'));
      const approve = links.find((l) => /[?&]a=approve/.test(l));
      t.ok(!!approve && links.some((l) => /[?&]a=changes/.test(l)), 'mail has "Ik keur goed" and "Ik wil iets aanpassen" links', links);
      if (approve) {
        const customer = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const cp = await customer.newPage();
        const r = await cp.goto(approve);
        t.ok(r && r.status() === 200, 'approve link opens a confirmation page', r && r.status());
        t.ok(/noindex/.test(await cp.content()), 'proof page is noindex');
        await cp.locator('form button[type=submit]').first().click();
        await cp.waitForLoadState('domcontentloaded');
        await cp.screenshot({ path: path.join(ENV.artifacts, '05-proof-approved.png'), fullPage: true }).catch(() => {});
        await customer.close();
        const proof = wpEvalJson(`$r = \\BorduurPatchStudio\\Woo\\Designs::get(${Number(st.bacsDesignId)}); echo wp_json_encode( $r ? $r['proof'] : null );`);
        t.ok(proof && proof.status === 'approved', 'design proof status "approved"', proof);
        const notes = wpEvalJson(`echo wp_json_encode( array_map( function ( $n ) { return $n->content; }, wc_get_order_notes( array( 'order_id' => ${Number(st.bacsOrderId)} ) ) ) );`);
        t.ok(notes.some((n) => /goedgekeurd|keurde|keurt|approved/i.test(n)), 'order note about the approval', notes.slice(0, 5));
        const o2 = orderInfo(st.bacsOrderId);
        t.ok(o2.status === o.status, 'order status not changed automatically (owner decides)', [o.status, o2.status]);
      }
    }
  }
  t.ok(problems.length === 0, 'no console errors in admin', problems);
});
