/**
 * Integrator: browser test of dist/patch-studio-demo.html (one self-contained file, opened via file://).
 * Checks: no console errors / failed requests / external requests, every step works, worker analysis,
 * text-only flow with a text layer, the extra demo samples, FR, and phone width 390 px without horizontal scroll.
 * Skipped when Playwright/Chromium is missing or the demo is not built (npm run demo).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const demoFile = path.resolve(here, '../../dist/patch-studio-demo.html');
const require = createRequire(import.meta.url);
let chromium = null;
try { ({ chromium } = require('playwright')); } catch (e) { /* skipped */ }
const skip = !chromium ? 'playwright not available' : !fs.existsSync(demoFile) ? 'demo not built (npm run demo)' : false;
const URL0 = pathToFileURL(demoFile).href;

const NEXT = {
  nl: [/Bekijk ons advies/, /Kies maat en aantal/, /Kies de afwerking/, /Naar het overzicht/],
  fr: [/Voir notre conseil/, /Choisir la taille/, /Choisir la finition/, /Vers le récapitulatif/],
};

let browser;
test.before(async () => { if (!skip) browser = await chromium.launch(); });
test.after(async () => { if (browser) await browser.close(); });

async function open(opts = {}) {
  const mobile = opts.width && opts.width < 600;
  const ctx = await browser.newContext({ viewport: { width: opts.width || 1280, height: mobile ? 844 : 900 }, hasTouch: !!mobile, isMobile: !!mobile });
  const page = await ctx.newPage();
  const problems = [];
  page.on('console', (m) => { if (m.type() === 'error') problems.push('console: ' + m.text()); });
  page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
  page.on('requestfailed', (r) => problems.push('requestfailed: ' + r.url().slice(0, 80)));
  page.on('request', (r) => { const u = r.url(); if (/^https?:/.test(u)) problems.push('external request: ' + u); });
  await page.goto(URL0 + (opts.query || ''));
  await page.waitForFunction(() => window.BPS_DEMO && document.querySelector('#studio[data-bps-mounted]'));
  // critique-2 #10: the example files sit behind "Probeer met een voorbeeld" (a <details>); open it like a customer
  await page.evaluate(() => document.querySelectorAll('.bps-samples').forEach((d) => { d.open = true; }));
  return { page, ctx, problems };
}

const hscroll = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const waitAdvice = (page) => page.waitForFunction(() => window.BPS_DEMO.lastAdvice && window.BPS_DEMO.lastQuote, null, { timeout: 30000 });

async function walk(page, lang, { checkScroll = false } = {}) {
  const scroll = [];
  for (const re of NEXT[lang]) {
    const btn = page.getByRole('button', { name: re }).first();
    await btn.click();
    await page.waitForTimeout(400);
    if (checkScroll) scroll.push(await hscroll(page));
  }
  const order = page.getByRole('button', { name: lang === 'fr' ? /Commander cet|Demander/ : /Bestel deze patch|Vraag/ }).first();
  await order.click();
  // Without the rights checkbox: error + focus on the checkbox, no dialog.
  assert.equal(await page.locator('dialog[open]').count(), 0, 'no order without rights consent');
  assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.type), 'checkbox');
  await page.locator('.bps-studio label', { hasText: lang === 'fr' ? /Je confirme/ : /Ik bevestig/ }).first().click();
  await order.click();
  const dlg = page.locator('dialog[open]');
  await dlg.waitFor();
  const txt = await dlg.innerText();
  assert.match(txt, lang === 'fr' ? /Ceci est une démo/ : /Dit is een demo/);
  const payload = JSON.parse(txt.slice(txt.indexOf('{')));
  return { payload, scroll };
}

test('demo: built-in example → advice → all steps → order (desktop)', { skip }, async () => {
  const { page, ctx, problems } = await open();
  await page.locator('.bps-studio button.bps-sample').first().click();
  await waitAdvice(page);
  const advice = await page.evaluate(() => window.BPS_DEMO.lastAdvice);
  assert.ok(advice.ranking.length >= 3, 'ranking');
  assert.ok(await page.locator('canvas').first().isVisible(), 'preview canvas');
  assert.match(await page.locator('.bps-studio').innerText(), /€\s?\d/);
  const { payload } = await walk(page, 'nl');
  assert.ok(payload.technique && payload.sizeCm && payload.qty >= 1);
  assert.deepEqual(problems, []);
  await ctx.close();
});

test('demo: extra samples (verloop, foto, vlak logo) each give consistent advice', { skip }, async () => {
  const { page, ctx, problems } = await open();
  // The demo's example files live in the studio's start screen (critique-1 #4: no separate demo bar).
  const n = await page.locator('.bps-studio [data-demo-sample]').count();
  assert.equal(n, 3);
  const tops = [];
  for (let i = 0; i < n; i++) {
    await page.evaluate(() => { window.BPS_DEMO.lastAdvice = null; });
    // Loading another example replaces the current design (as a customer would do).
    await page.evaluate(() => window.BPS_DEMO.app.ctx.go(0)); // back to the start screen, as "Terug" does
    await page.evaluate(() => document.querySelectorAll('.bps-samples').forEach((d) => { d.open = true; }));
    await page.locator('.bps-studio [data-demo-sample]').nth(i).click();
    await waitAdvice(page);
    await page.waitForTimeout(1500); // the UI follows the advice until it is stable
    const a = await page.evaluate(() => window.BPS_DEMO.lastAdvice);
    tops.push(a.ranking[0]);
    // Headline (top technique) and the explanation must not contradict each other.
    const s = a.summary && a.summary.text && a.summary.text.key;
    if (['printed', 'sublimated'].includes(a.ranking[0])) assert.ok(!/^advice\.summary\.illustration(_nowoven)?$/.test(s), 'print headline with thread-first text: ' + s);
  }
  assert.ok(tops[2] === 'embroidered', 'flat logo → embroidered, got ' + tops[2]);
  assert.ok(['printed', 'sublimated'].includes(tops[1]), 'photo → print, got ' + tops[1]);
  assert.deepEqual(problems, []);
  await ctx.close();
});

test('demo: phone 390 px, text-only patch with a text layer, no horizontal scroll', { skip }, async () => {
  const { page, ctx, problems } = await open({ width: 390 });
  assert.equal(await hscroll(page), 0);
  await page.getByRole('button', { name: /Start met tekst/ }).first().click();
  await page.waitForTimeout(500);
  const input = page.locator('.bps-studio input[type=text], .bps-studio textarea').first();
  await input.fill('KALMTHOUT');
  await page.waitForTimeout(600);
  assert.equal(await hscroll(page), 0);
  await waitAdvice(page);
  const { payload, scroll } = await walk(page, 'nl', { checkScroll: true });
  assert.ok(scroll.every((x) => x <= 0), 'horizontal scroll in a step: ' + scroll);
  assert.equal(payload.texts.length >= 1, true);
  assert.equal(payload.texts[0].text, 'KALMTHOUT');
  assert.deepEqual(problems, []);
  await ctx.close();
});

test('demo: phone 390 px with the photo sample + FR', { skip }, async () => {
  const { page, ctx, problems } = await open({ width: 390, query: '?lang=fr' });
  assert.equal(await page.evaluate(() => document.documentElement.lang), 'fr-BE');
  await page.locator('.bps-studio [data-demo-sample]').nth(1).click();
  await waitAdvice(page);
  assert.equal(await hscroll(page), 0);
  const { scroll } = await walk(page, 'fr', { checkScroll: true });
  assert.ok(scroll.every((x) => x <= 0), 'horizontal scroll in a step: ' + scroll);
  assert.deepEqual(problems, []);
  await ctx.close();
});
