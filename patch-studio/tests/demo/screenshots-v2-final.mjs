#!/usr/bin/env node
/**
 * Eindscreenshots versie 2 (ontwerpstudio) van dist/patch-studio-demo.html → docs/screenshots/v2-final/
 *   npm run demo && node tests/demo/screenshots-v2-final.mjs [templateId]
 * 1. desktop-closeup.png  1440×900, close-up van een geborduurd sjabloon
 * 2. desktop-polo.png     1440×900, dezelfde patch op een polo
 * 3. mobiel.png           390×844 (iPhone 13), patch bovenaan, tabbalk onderaan
 * Faalt bij consolefouten, externe verzoeken of horizontale scroll.
 */
import { createRequire } from 'node:module';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const out = path.resolve(root, 'docs/screenshots/v2-final');
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
fs.mkdirSync(out, { recursive: true });
const URL0 = pathToFileURL(path.resolve(root, 'dist/patch-studio-demo.html')).href;
const TPL = process.argv[2] || '';
const report = [];
let failed = false;
const check = (ok, msg) => { report.push((ok ? 'OK   ' : 'FOUT ') + msg); if (!ok) failed = true; };

const browser = await chromium.launch();
async function open(width, height, mobile) {
  const ctx = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: mobile ? 3 : 2, hasTouch: mobile, isMobile: mobile });
  const page = await ctx.newPage();
  const problems = [];
  page.on('console', (m) => { if (m.type() === 'error') problems.push('console: ' + m.text()); });
  page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
  page.on('request', (r) => { if (/^https?:/.test(r.url())) problems.push('external: ' + r.url()); });
  await page.goto(URL0);
  await page.waitForFunction(() => window.BPS_DEMO && document.querySelector('#studio[data-bps-mounted]'));
  // Start from a template of the gallery (first one = embleem met boogtekst, unless one is named).
  const card = TPL ? page.locator(`.bps-gal:not(.bps-gal--hero) .bps-gal__card[data-tpl="${TPL}"]`).first() : page.locator('.bps-gal:not(.bps-gal--hero) .bps-gal__card').first();
  await card.scrollIntoViewIfNeeded();
  await card.click();
  await page.waitForFunction(() => document.querySelector('#studio.is-studio') && window.BPS_DEMO.lastQuote, null, { timeout: 40000 });
  await page.evaluate(() => window.scrollTo(0, 0));
  // drop the selection handles: focus the canvas and press Escape
  await page.locator('.bps-preview__canvas').focus().catch(() => {});
  await page.keyboard.press('Escape');
  await page.locator('.bps-preview__canvas').blur().catch(() => {});
  await page.waitForTimeout(2500);
  return { ctx, page, problems };
}
const hscroll = (p) => p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

{
  const { ctx, page, problems } = await open(1440, 900, false);
  await page.mouse.move(900, 300, { steps: 4 });
  await page.waitForTimeout(800);
  await page.screenshot({ path: path.join(out, 'desktop-closeup.png') });
  const polo = page.locator('.bps-views input[value="polo_chest"]');
  check(await polo.count() === 1, 'weergave "Op polo" bestaat');
  await polo.check({ force: true });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: path.join(out, 'desktop-polo.png') });
  const btn = await page.locator('.bps-bar__btn').boundingBox();
  check(btn && btn.x + btn.width <= 1440, 'desktop: knop "Bestellen" volledig in beeld');
  check(await hscroll(page) <= 0, 'desktop: geen horizontale scroll');
  check(problems.length === 0, 'desktop: geen consolefouten ' + problems.join(' | '));
  await ctx.close();
}
{
  const { ctx, page, problems } = await open(390, 844, true);
  const v = await page.evaluate(() => {
    const r = document.querySelector('.bps-preview__canvas').getBoundingClientRect();
    return { ok: r.top >= -1 && r.bottom <= (window.visualViewport || window).height + 1, scroll: document.scrollingElement.scrollTop };
  });
  check(v.ok && v.scroll === 0, 'mobiel: patch volledig in beeld, pagina niet gescrold');
  check(await page.locator('.bps-bar__btn').isVisible(), 'mobiel: "Bestellen" zichtbaar');
  await page.screenshot({ path: path.join(out, 'mobiel.png') });
  check(await hscroll(page) <= 0, 'mobiel: geen horizontale scroll');
  check(problems.length === 0, 'mobiel: geen consolefouten ' + problems.join(' | '));
  await ctx.close();
}
await browser.close();
console.log(report.join('\n'));
process.exit(failed ? 1 : 0);
