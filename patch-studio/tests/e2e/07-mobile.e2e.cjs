'use strict';
/**
 * E2E 7 — phone (390 × 844, touch): text-only patch through the studio tabs (Techniek, Prijs, Vorm → Rand) with the
 * slim price bar above the tab bar; the patch stays fully in view with the page unscrolled (owner's hard requirement),
 * no horizontal scroll, axe clean per panel, bar amount == price card, order from the bar lands in the cart.
 */
const { Scenario, launch, newPage, shot, axe, studio, money, cart } = require('./lib/harness.cjs');

const t = new Scenario('07-mobile');
t.run(async (keep) => {
  const browser = await launch();
  keep(browser);
  const { page, problems, context } = await newPage(browser, { mobile: true });
  await cart.empty(context.request);
  const noHScroll = async (label) => {
    const [sw, iw] = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    t.ok(sw <= iw, `no horizontal scroll (${label}: ${sw} ≤ ${iw})`);
  };
  const axeStep = async (label) => {
    const v = await axe(page);
    if (v) t.ok(v.length === 0, `axe: 0 serious/critical (${label})`, v);
  };

  await studio.open(page);
  await noHScroll('empty');
  await axeStep('empty');
  t.ok(!(await page.locator('.bps-bar').isVisible().catch(() => false)), 'sticky bar hidden before there is a design');
  await page.locator('.bps-studio [data-bps-action="text-only"], .bps-studio button:has-text("Start met tekst")').first().click();
  await page.waitForSelector('.bps-layer input[type=text]', { timeout: 15000 });
  await page.fill('.bps-layer input[type=text]', 'KFC KALMTHOUT');
  await page.waitForTimeout(400);
  t.ok(await page.locator('.bps-bar').isVisible(), 'sticky bar visible once there is a design');
  const barBox = await page.locator('.bps-bar').boundingBox();
  const tabBox = await page.locator('.bps-tabs').boundingBox();
  // v2 studio: price + "Bestellen" sit in the slim top bar (above the patch), the tool tab bar at the bottom.
  t.ok(barBox && tabBox && tabBox.y + tabBox.height >= 844 - 2 && barBox.y <= 4 && barBox.height <= 60,
    'slim price bar (≤ 60 px) in the top bar, tab bar at the bottom', { barBox, tabBox });
  t.ok(/€/.test(await page.locator('.bps-bar__amount').innerText()), 'bar shows the amount incl. VAT');
  await page.waitForFunction(() => /KFC KALMTHOUT/.test(document.querySelector('.bps-preview__canvas')?.getAttribute('aria-label') || ''), null, { timeout: 4000 }).catch(() => {});
  const label = await page.locator('.bps-preview__canvas').getAttribute('aria-label');
  t.ok(/KFC KALMTHOUT/.test(label || ''), 'canvas description mentions the text layer', label);
  await noHScroll('step 1');
  await axeStep('step 1');
  await shot(page, '07-m-step1');

  const patchInView = () => page.evaluate(() => {
    const r = document.querySelector('.bps-preview__canvas').getBoundingClientRect();
    const vv = window.visualViewport || { height: innerHeight };
    return { ok: r.top >= -1 && r.bottom <= vv.height + 1 && r.height > 120, top: Math.round(r.top), bottom: Math.round(r.bottom), scrollTop: document.scrollingElement.scrollTop };
  });
  const steps = [['advice', 'Techniek'], ['size', 'Maat'], ['edge', 'Afwerking'], ['checkout', 'Overzicht']];
  for (const [id, name] of steps) {
    await studio.show(page, id);
    const cur = await studio.panels(page);
    t.ok(cur.includes(id), `tab bar → ${name}`, cur);
    const v = await patchInView();
    t.ok(v.ok && v.scrollTop === 0, `patch fully in view, page not scrolled (${name})`, v);
    await noHScroll(name);
    await axeStep(name);
    // Touch targets of the primary controls ≥ 44 px.
    const small = await page.evaluate(() => [...document.querySelectorAll('.bps-studio .bps-step button, .bps-bar button, .bps-tabs button')]
      .filter((b) => b.offsetParent && !b.classList.contains('bps-btn--link') && !b.classList.contains('bps-ibtn--tiny'))
      .map((b) => { const r = b.getBoundingClientRect(); return { t: b.innerText.trim().slice(0, 20) || b.getAttribute('aria-label'), h: Math.round(r.height), w: Math.round(r.width) }; })
      .filter((r) => r.h < 44 && r.w < 44));
    t.ok(small.length === 0, `touch targets ≥ 44 px (${name})`, small);
    if (id === 'size') await studio.pickQty(page, 25);
  }
  const barAmount = money(await page.locator('.bps-bar__amount').innerText());
  await page.locator('.bps-bar__info').click().catch(() => {});
  await page.waitForTimeout(300);
  const sheetTotal = money(await page.locator('dialog.bps-sheet[open] .bps-price__total').first().innerText().catch(() => ''));
  t.eqMoney(barAmount, sheetTotal, 'bar amount == price details sheet');
  await page.keyboard.press('Escape');
  t.ok(/Bestellen/.test(await page.locator('.bps-bar__btn').innerText()), 'bar button says "Bestellen" with the overview open');
  await shot(page, '07-m-step5');

  // Order from the bar: first without rights (error + focus), then with.
  await page.locator('.bps-bar__btn').click();
  await page.waitForTimeout(300);
  t.ok(await page.evaluate(() => document.activeElement && document.activeElement.type === 'checkbox'), 'focus moves to the rights checkbox when missing');
  await page.check('.bps-rights input[type=checkbox]');
  await page.locator('.bps-bar__btn').click();
  await page.waitForURL(/winkelmand/, { timeout: 45000 });
  const c = await cart.read(page);
  t.eqMoney(c.total, barAmount, 'cart total (mobile) == amount in the bar');
  const [sw, iw] = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
  t.ok(sw <= iw, `cart page: no horizontal scroll (${sw} ≤ ${iw})`);
  await shot(page, '07-m-cart');
  t.ok(problems.length === 0, 'no console errors (mobile)', problems);
});
