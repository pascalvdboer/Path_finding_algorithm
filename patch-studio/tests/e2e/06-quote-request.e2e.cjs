'use strict';
/**
 * E2E 6 — quote request ("Vraag je prijs op maat aan") for a quote-only technique (woven).
 *  step 5 button switches to the quote text; the form validates inline; a bot-fast submit is refused by the server;
 *  a normal submit creates OF-YYYY-NNNN, mails the shop (Reply-To customer) and the customer; the quote is listed in wp-admin.
 */
const fs = require('fs');
const path = require('path');
const { ENV, FIXTURES, Scenario, launch, newPage, shot, axe, studio, adminLogin } = require('./lib/harness.cjs');

const t = new Scenario('06-quote-request');
t.run(async (keep) => {
  const browser = await launch();
  keep(browser);
  const { page, problems } = await newPage(browser);
  await studio.open(page);
  await studio.upload(page, path.join(FIXTURES, 'logo-flat.png'));
  await studio.waitAnalysis(page);
  await studio.toSummary(page, { technique: 'woven', sizeCm: 8, qty: 100 });
  const btn = page.locator('.bps-cta .bps-btn--primary').first();
  t.ok(/prijs op maat/i.test(await btn.innerText()), 'step 5 button: "Vraag je prijs op maat aan"', await btn.innerText());
  t.ok(/aanvraag/i.test(await page.locator('.bps-price').innerText()), 'price card says "Prijs op aanvraag"');
  await page.check('.bps-rights input[type=checkbox]');
  const opened = Date.now();
  await btn.click();
  const dlg = page.locator('dialog.bps-dialog[open]');
  await dlg.waitFor({ timeout: 10000 });
  t.ok(await dlg.locator('form').count() === 1, 'quote dialog opens');
  // Inline validation: empty submit.
  await dlg.locator('button[type=submit]').click();
  t.ok(await dlg.locator('.bps-field__error:visible').count() >= 1, 'empty form → inline errors (no alert)');
  const v = await axe(page, 'dialog.bps-dialog[open]');
  if (v) t.ok(v.length === 0, 'axe: 0 serious/critical in the quote dialog', v);
  await dlg.locator('input[name=name]').fill('Marie Testers');
  await dlg.locator('input[name=email]').fill('marie.offerte@example.test');
  await dlg.locator('input[name=phone]').fill('0470 11 22 33');
  await dlg.locator('input[name=company]').fill('Scouts Kalmthout');
  await dlg.locator('textarea[name=message]').fill('100 geweven badges voor ons jubileum.');
  // The server refuses submits faster than 3 s (bot check) – wait like a human would.
  const wait = 3500 - (Date.now() - opened);
  if (wait > 0) await page.waitForTimeout(wait);
  const mailDir = path.join(ENV.site, 'wp-content/bps-mails');
  const before = new Set(fs.existsSync(mailDir) ? fs.readdirSync(mailDir) : []);
  const resp = page.waitForResponse((r) => /patch-studio\/v1\/quotes/.test(r.url()) && r.request().method() === 'POST', { timeout: 30000 });
  await dlg.locator('button[type=submit]').click();
  const r = await resp;
  const body = await r.json().catch(() => null);
  t.ok(r.status() === 200 && body && body.ok, 'POST /quotes → ok', { status: r.status(), body });
  t.ok(/^OF-\d{4}-\d{4,}$/.test((body && body.ref) || ''), 'quote ref OF-YYYY-NNNN', body && body.ref);
  await page.waitForTimeout(500);
  const done = await page.locator('dialog.bps-dialog[open]').innerText().catch(() => '');
  t.ok(/Marie/.test(done) && (body && done.includes(body.ref)), 'confirmation shows first name and ref', done.slice(0, 300));
  await shot(page, '06-quote-done');

  const fresh = (fs.existsSync(mailDir) ? fs.readdirSync(mailDir) : []).filter((f) => !before.has(f))
    .map((f) => JSON.parse(fs.readFileSync(path.join(mailDir, f), 'utf8')));
  const toCustomer = fresh.find((m) => m.to.some((x) => x.includes('marie.offerte@example.test')));
  const toShop = fresh.find((m) => !m.to.some((x) => x.includes('marie.offerte@example.test')));
  t.ok(!!toCustomer, 'confirmation mail to the customer', fresh.map((m) => [m.to, m.subject]));
  t.ok(!!toShop, 'notification mail to the shop', fresh.map((m) => [m.to, m.subject]));
  if (toShop) t.ok(/Reply-To:.*marie\.offerte@example\.test/i.test(JSON.stringify(toShop.headers)), 'shop mail has Reply-To = customer', toShop.headers);

  // Admin list "Patch-offertes".
  const { page: ap } = await newPage(browser);
  await adminLogin(ap);
  await ap.goto(`${ENV.base}/wp-admin/edit.php?post_type=bps_quote`, { waitUntil: 'domcontentloaded' });
  const list = await ap.locator('#the-list').innerText().catch(() => '');
  t.ok(body && list.includes(body.ref), 'quote listed under WooCommerce › Patch-offertes', list.slice(0, 300));
  await ap.screenshot({ path: path.join(ENV.artifacts, '06-admin-quotes.png'), fullPage: true }).catch(() => {});

  t.ok(problems.length === 0, 'no console errors', problems);
});
