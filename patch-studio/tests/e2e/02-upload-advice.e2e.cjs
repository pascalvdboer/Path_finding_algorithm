'use strict';
/**
 * E2E 2 — upload a logo and see the advice.
 *  a) flat 3-colour logo (fixtures/logo-flat.png): analysis log, round shape recognised, white background → fabric,
 *     advice box + exactly one "Ons advies" card, verdict as text (icon + word), prices on the cards, thread colours.
 *  b) the owner's round illustration (reference/eigenaar-ronde-patch.png): recognised as round + illustration, never "foto",
 *     design is not cropped (contain) and the advice is honest about simplification.
 */
const path = require('path');
const fs = require('fs');
const { REF_DIR, FIXTURES, Scenario, launch, newPage, shot, axe, studio } = require('./lib/harness.cjs');

const t = new Scenario('02-upload-advice');
t.run(async (keep) => {
  const browser = await launch();
  keep(browser);
  const { page, problems } = await newPage(browser);

  /* ---------- a) flat logo ---------- */
  await studio.open(page);
  const t0 = Date.now();
  await studio.upload(page, path.join(FIXTURES, 'logo-flat.png'));
  await studio.waitAnalysis(page);
  const ms = Date.now() - t0;
  t.ok(ms < 8000, `analysis finished quickly (${ms} ms, target ≤ 3 s on a phone)`, ms);
  const found = await page.locator('.bps-found').innerText();
  t.info('analysis log: ' + found.replace(/\n/g, ' | '));
  t.ok(/rond/i.test(found), 'round shape recognised', found);
  t.ok(/\d+\s+kleur/i.test(found), 'colour count reported', found);
  t.ok(!/foto/i.test(found), 'flat logo is not called a photo', found);
  t.ok(await page.locator('[role="status"]').count() > 0, 'progress is announced in a role=status region');
  const canvas = page.locator('.bps-preview__canvas').first();
  t.ok(await canvas.isVisible(), 'patch preview canvas visible');
  const aria = await canvas.getAttribute('aria-label');
  t.ok(!!aria && aria.length > 10, 'preview canvas has a descriptive aria-label', aria);
  // Preview is not blank: sample pixels of the canvas.
  const nonBlank = await canvas.evaluate((c) => {
    try {
      const x = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
      const seen = new Set();
      for (let i = 0; i < x.length; i += 4 * 97) seen.add((x[i] >> 4) + ',' + (x[i + 1] >> 4) + ',' + (x[i + 2] >> 4));
      return seen.size;
    } catch (e) { return -1; }
  });
  t.ok(nonBlank === -1 || nonBlank > 3, 'preview canvas is painted (several colours)', nonBlank);
  await shot(page, '02-flat-step1');

  await studio.next(page); // → step 2
  t.ok(/Techniek/.test(await studio.currentStep(page)), 'step 2 "Techniek" is current', await studio.currentStep(page));
  const adviceHead = (await page.locator('.bps-advice__head').innerText().catch(() => '')).trim();
  const adviceBody = (await page.locator('.bps-advice__body').innerText().catch(() => '')).trim();
  t.info(`advice: ${adviceHead} — ${adviceBody.replace(/\n/g, ' ')}`);
  t.ok(adviceHead.length > 5, 'advice headline shown', adviceHead);
  t.ok(/Advies van ons atelier/i.test(await page.locator('.bps-advice').innerText()), 'advice labelled "Advies van ons atelier …"');
  t.ok(/digitaliseerder|proef/i.test(await page.locator('.bps-advice').innerText()), 'advice says a person checks it for the proof');
  const cards = page.locator('.bps-step:visible .bps-cards').first().locator('.bps-card');
  const nCards = await cards.count();
  t.ok(nCards === 3, 'top-3 technique cards', nCards);
  const badges = await page.locator('.bps-step:visible .bps-card .bps-badge').count();
  t.ok(badges === 1, 'exactly one "Ons advies" badge', badges);
  const statuses = await page.locator('.bps-step:visible .bps-cards').first().locator('.bps-status').allInnerTexts();
  t.ok(statuses.length === nCards && statuses.every((s) => /Aanrader|Kan, met aanpassing|Raden we af/.test(s)), 'verdict as text (Aanrader / Kan, met aanpassing / Raden we af)', statuses);
  const prices = await page.locator('.bps-step:visible .bps-cards').first().locator('.bps-card__price').allInnerTexts();
  t.ok(prices.some((p) => /€\s?\d/.test(p)), 'at least one card shows a price in euro', prices);
  t.ok(prices.every((p) => /€\s?\d|Prijs op aanvraag/.test(p)), 'every card shows a price or "Prijs op aanvraag"', prices);
  const st = await studio.state(page);
  t.ok(st && st.technique, 'a technique is chosen automatically', st && st.technique);
  const flatRecommended = st && st.technique;
  // Flat logos are the textbook case for embroidery (SPEC §7.7: flat → borduren).
  t.ok(['embroidered', 'woven'].includes(flatRecommended), 'flat logo → embroidery (or woven) recommended', flatRecommended);

  // Thread colours for a thread technique.
  await studio.chooseTechnique(page, 'embroidered');
  const threads = page.locator('.bps-threads:visible');
  t.ok(await threads.count() === 1, 'thread colours section visible for embroidered');
  if (await threads.count()) {
    const sw = await threads.innerText();
    t.ok(/Polyneon|\d{4}/.test(sw), 'thread colours show Polyneon codes', sw.slice(0, 200));
    t.ok(!/ΔE|delta/i.test(sw), 'no ΔE numbers shown to customers');
  }
  const v2 = await axe(page);
  if (v2) t.ok(v2.length === 0, 'axe: 0 serious/critical (step 2)', v2);
  await shot(page, '02-flat-step2');

  // Size advice in step 3.
  await studio.next(page);
  t.ok(/Maat/.test(await studio.currentStep(page)), 'step 3 "Maat & aantal" is current');
  const ladder = await page.locator('.bps-ladder__row').count();
  t.ok(ladder >= 2, 'tier ladder shown for embroidered', ladder);
  const total = await studio.uiTotal(page);
  t.ok(total > 0, 'price card shows a total incl. VAT', total);
  t.ok(/incl\.? btw/i.test(await page.locator('.bps-price').innerText()), 'price card says "incl. btw"');

  /* ---------- b) the owner's round illustration ---------- */
  const owner = path.join(REF_DIR, 'eigenaar-ronde-patch.png');
  if (fs.existsSync(owner)) {
    const { page: p2, problems: pr2 } = await newPage(browser);
    await studio.open(p2);
    await studio.upload(p2, owner);
    await studio.waitAnalysis(p2);
    const f2 = await p2.locator('.bps-found').innerText();
    t.info('owner patch: ' + f2.replace(/\n/g, ' | '));
    t.ok(/rond/i.test(f2), 'owner patch: round shape recognised', f2);
    t.ok(/illustratie/i.test(f2) && !/\bfoto\b/i.test(f2), 'owner patch: illustration, not "foto"', f2);
    const st2 = await studio.state(p2);
    t.ok(st2 && st2.shape === 'circle', 'owner patch: shape set to circle', st2 && st2.shape);
    const scale = st2 && st2.art && st2.art.scale;
    t.ok(scale == null || scale <= 1.0001, 'owner patch: design fits inside the patch (contain, not cover)', scale);
    await studio.next(p2);
    const body2 = await p2.locator('.bps-advice').innerText();
    t.ok(/geweven|vereenvoudig|bedrukt/i.test(body2), 'owner patch: advice explains woven / simplified embroidery / print', body2.slice(0, 300));
    await shot(p2, '02-owner-step2');
    t.ok(pr2.length === 0, 'no console errors (owner patch)', pr2);
  } else {
    t.info('reference/eigenaar-ronde-patch.png not found: part b skipped');
  }

  t.ok(problems.length === 0, 'no console errors (flat logo)', problems);
});
