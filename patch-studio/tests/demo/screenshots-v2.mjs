#!/usr/bin/env node
/**
 * Integrator v2: Playwright check + screenshots of dist/patch-studio-demo.html with the owner's two test images.
 * Desktop 1440×900 and phone 390×844: no console errors, no horizontal scroll, patch always in view on the phone.
 *   npm run demo && node tests/demo/screenshots-v2.mjs   → docs/screenshots/v2/
 */
import { createRequire } from 'node:module';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const out = path.resolve(root, 'docs/screenshots/v2');
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
fs.mkdirSync(out, { recursive: true });
const URL0 = pathToFileURL(path.resolve(root, 'dist/patch-studio-demo.html')).href;
const IMGS = { foto: path.resolve(root, 'reference/eigenaar-foto-patch.jpg'), rond: path.resolve(root, 'reference/eigenaar-ronde-patch.png') };
const only = process.argv[2];
const report = [];
let failed = false;
const check = (ok, msg) => { report.push((ok ? 'OK   ' : 'FOUT ') + msg); if (!ok) failed = true; };

const browser = await chromium.launch();
async function open(width, query = '') {
  const mobile = width < 600;
  const ctx = await browser.newContext({ viewport: { width, height: mobile ? 844 : 900 }, deviceScaleFactor: mobile ? 2 : 1, hasTouch: mobile, isMobile: mobile });
  const page = await ctx.newPage();
  const problems = [];
  page.on('console', (m) => { if (m.type() === 'error') problems.push('console: ' + m.text()); });
  page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
  page.on('request', (r) => { if (/^https?:/.test(r.url())) problems.push('external: ' + r.url()); });
  await page.goto(URL0 + query);
  await page.waitForFunction(() => window.BPS_DEMO && document.querySelector('#studio[data-bps-mounted]'));
  return { ctx, page, problems };
}
const hscroll = (p) => p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const settle = async (p, ms = 900) => { await p.waitForTimeout(ms); };
async function upload(page, file) {
  await page.evaluate(() => { window.BPS_DEMO.lastAdvice = null; });
  await page.locator('#studio input[type=file]').first().setInputFiles(file);
  await page.waitForFunction(() => window.BPS_DEMO.lastAdvice && window.BPS_DEMO.lastQuote, null, { timeout: 40000 });
  await settle(page, 2500);
}
/** Is the whole patch canvas inside the visual viewport, and is the page unscrolled? */
const patchInView = (page) => page.evaluate(() => {
  const c = document.querySelector('.bps-preview__canvas');
  const r = c.getBoundingClientRect();
  const vv = window.visualViewport || { height: innerHeight, width: innerWidth, offsetTop: 0 };
  return { ok: r.top >= -1 && r.bottom <= vv.height + 1 && r.height > 120, top: r.top, bottom: r.bottom, h: r.height, scroll: document.scrollingElement.scrollTop };
});

async function desktop(name, file) {
  const { ctx, page, problems } = await open(1440);
  if (name === 'foto') await page.screenshot({ path: path.join(out, 'desktop-0-start.png') });
  await upload(page, file);
  await page.screenshot({ path: path.join(out, `desktop-${name}-1-studio.png`) });
  // glint follows the mouse (subtle), then settles in high quality
  const cv = page.locator('.bps-preview__canvas');
  const b = await cv.boundingBox();
  await page.mouse.move(b.x + b.width * 0.9, b.y + b.height * 0.15, { steps: 6 });
  await settle(page, 600);
  await page.screenshot({ path: path.join(out, `desktop-${name}-2-licht-rechtsboven.png`), clip: b });
  // compare cards ready?
  await page.waitForFunction(() => document.querySelectorAll('.bps-card__vis.is-ready').length >= 3, null, { timeout: 20000 }).catch(() => {});
  const ready = await page.locator('.bps-card__vis.is-ready canvas').count();
  check(ready >= 3, `${name} desktop: vergelijker toont ${ready} live renders`);
  const asst = page.locator('.bps-assist');
  await page.locator('.bps-assist .bps-techs').scrollIntoViewIfNeeded().catch(() => {});
  await settle(page, 300);
  if (await asst.isVisible()) await asst.screenshot({ path: path.join(out, `desktop-${name}-3-vergelijker.png`) });
  // garments
  const views = page.locator('.bps-views input');
  const nViews = await views.count();
  check(nViews >= 5, `${name} desktop: ${nViews} weergaven (close-up + kledij)`);
  for (let i = 1; i < nViews; i++) {
    const v = await views.nth(i).getAttribute('value');
    await views.nth(i).check({ force: true });
    await settle(page, 900);
    if (name === 'foto' || v === 'backpack_front') await page.screenshot({ path: path.join(out, `desktop-${name}-4-${v}.png`) });
  }
  await views.nth(0).check({ force: true });
  await settle(page, 500);
  check(await hscroll(page) <= 0, `${name} desktop 1440: geen horizontale scroll`);
  check(problems.length === 0, `${name} desktop: geen consolefouten ${problems.join(' | ')}`);
  await ctx.close();
}

async function mobile(name, file) {
  const { ctx, page, problems } = await open(390);
  if (name === 'foto') await page.screenshot({ path: path.join(out, 'mobiel-0-start.png') });
  await upload(page, file);
  let v = await patchInView(page);
  check(v.ok && v.scroll === 0, `${name} mobiel: patch in beeld na upload (${Math.round(v.top)}–${Math.round(v.bottom)}, scroll ${v.scroll})`);
  await page.screenshot({ path: path.join(out, `mobiel-${name}-1-studio.png`) });
  const tabs = page.locator('.bps-tabs button');
  const n = await tabs.count();
  for (let i = 0; i < n; i++) {
    const tab = tabs.nth(i);
    if (!(await tab.isVisible())) continue;
    const lbl = ((await tab.innerText()) || 'tab' + i).trim().split(/\s+/)[0].toLowerCase();
    await tab.click();
    await settle(page, 700);
    v = await patchInView(page);
    check(v.ok && v.scroll === 0, `${name} mobiel: patch in beeld met paneel "${lbl}" (scroll ${v.scroll})`);
    check(await hscroll(page) <= 0, `${name} mobiel: geen horizontale scroll in "${lbl}"`);
    if (name === 'foto' || i === 0) await page.screenshot({ path: path.join(out, `mobiel-${name}-2-${i}-${lbl}.png`) });
  }
  check(problems.length === 0, `${name} mobiel: geen consolefouten ${problems.join(' | ')}`);
  await ctx.close();
}

for (const [name, file] of Object.entries(IMGS)) {
  if (only && only !== name) continue;
  await desktop(name, file);
  await mobile(name, file);
}
await browser.close();
console.log(report.join('\n'));
process.exit(failed ? 1 : 0);
