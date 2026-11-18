#!/usr/bin/env node
/**
 * Naamstroken — Playwright-controle van dev/nametape.html (SPEC-EXTRA §5 "Nametape klaar als").
 *   node tests/nametape/e2e-mobile.mjs            (start zelf een statische server op een vrije poort)
 * Controleert op 390 × 844 en 360 × 740:
 *   - één naam → winkelmand-payload in ≤ 3 schermen; voorbeeld altijd binnen het zichtbare venster, scrollTop 0,
 *     ook als het toetsenbord opent (gesimuleerd door het venster te verkleinen);
 *   - 25 namen met 2 te lange: 2 rode rijen, één klik op de lijstoplossing maakt alles in orde;
 *   - FR volledig (geen NL-teksten zichtbaar, geen ontbrekende sleutels).
 * Schermafdrukken: docs/extra/screenshots/nametape-*.png (gsm + desktop). Exitcode 1 bij een fout.
 */
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import { resolve, dirname, extname, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const shots = resolve(root, 'docs/extra/screenshots');
const require = createRequire(import.meta.url);

function loadPlaywright() {
  for (const p of ['playwright', '/opt/node-tools/node_modules/playwright', '/opt/node22/lib/node_modules/playwright']) {
    try { return require(p); } catch (e) { /* volgende */ }
  }
  return null;
}

const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8', '.woff2': 'font/woff2', '.png': 'image/png' };
function serve() {
  return new Promise((ok) => {
    const srv = createServer(async (req, res) => {
      try {
        const p = decodeURIComponent(new URL(req.url, 'http://x').pathname);
        const file = normalize(resolve(root, '.' + p));
        if (!file.startsWith(root)) { res.writeHead(403).end(); return; }
        res.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream' });
        res.end(await readFile(file));
      } catch { res.writeHead(404).end(); }
    }).listen(0, '127.0.0.1', () => ok(srv));
  });
}

let fails = 0;
const check = (ok, name, extra) => {
  if (ok) console.log('  ok  ', name);
  else { fails++; console.log('  FOUT', name, extra === undefined ? '' : JSON.stringify(extra)); }
};

/** Voorbeeld volledig binnen visualViewport + pagina niet gescrold. */
async function layoutOk(page, label) {
  const r = await page.evaluate(() => {
    const pv = document.querySelector('.bps-nt__preview');
    const b = pv.getBoundingClientRect();
    const vv = window.visualViewport || { height: innerHeight, offsetTop: 0 };
    const bar = document.querySelector('.bps-nt__bar').getBoundingClientRect();
    return { top: b.top, bottom: b.bottom, h: b.height, vh: vv.height, scroll: document.scrollingElement.scrollTop, barBottom: bar.bottom, sw: document.documentElement.scrollWidth, iw: innerWidth };
  });
  check(r.top >= -0.5 && r.bottom <= r.vh + 0.5 && r.h >= r.vh * 0.18 - 1, `${label}: voorbeeld binnen het venster (${Math.round(r.h)} / ${Math.round(r.vh)} px)`, r);
  check(r.scroll === 0, `${label}: scrollTop 0`, r.scroll);
  check(r.barBottom <= r.vh + 0.5, `${label}: vaste balk zichtbaar`, r);
  check(r.sw <= r.iw, `${label}: geen horizontale scroll`, r);
}

async function run() {
  const pw = loadPlaywright();
  if (!pw) { console.log('Playwright niet gevonden: overgeslagen.'); return 0; }
  await mkdir(shots, { recursive: true });
  const srv = await serve();
  const base = `http://127.0.0.1:${srv.address().port}/dev/nametape.html`;
  const browser = await pw.chromium.launch();
  try {
    for (const vp of [{ width: 390, height: 844, tag: '390' }, { width: 360, height: 740, tag: '360' }]) {
      console.log(`\n# gsm ${vp.width} × ${vp.height}: één naam → payload`);
      const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(e.message));
      await page.goto(base);
      await page.waitForSelector('.bps-nt__cta');
      await layoutOk(page, 'scherm 1');
      await page.click('[data-fk="name"]');
      // Toetsenbord open: venster ~ 55 % lager.
      await page.setViewportSize({ width: vp.width, height: Math.round(vp.height * 0.55) });
      await page.waitForTimeout(200);
      await page.keyboard.type('Peeters J.');
      await page.waitForTimeout(150);
      await layoutOk(page, 'toetsenbord open');
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.waitForTimeout(150);
      const aria = await page.getAttribute('.bps-nt__canvas', 'aria-label');
      check(/PEETERS J\./.test(aria || ''), 'canvas aria-label met de naam', aria);
      if (vp.tag === '390') await page.screenshot({ path: `${shots}/nametape-gsm-1-namen.png` });
      await page.click('.bps-nt__cta');
      await page.waitForTimeout(250);
      await layoutOk(page, 'scherm 2');
      if (vp.tag === '390') await page.screenshot({ path: `${shots}/nametape-gsm-2-stijl.png` });
      await page.click('.bps-nt__cta');
      await page.waitForTimeout(250);
      await layoutOk(page, 'scherm 3');
      const disabled = await page.$eval('.bps-nt__cta', (b) => b.disabled || b.getAttribute('aria-disabled') === 'true');
      check(disabled, 'zonder rechtenverklaring: knop uit');
      await page.check('[data-act="rights"]');
      await page.waitForTimeout(150);
      if (vp.tag === '390') await page.screenshot({ path: `${shots}/nametape-gsm-3-controle.png` });
      await page.click('.bps-nt__cta');
      await page.waitForSelector('.bps-nt__dev pre', { timeout: 4000 }).catch(() => {});
      const payload = await page.$eval('.bps-nt__dev pre', (p) => JSON.parse(p.textContent)).catch(() => null);
      const batch = payload && (payload.batch || payload);
      check(batch && batch.rows && batch.rows.length === 1 && /PEETERS/i.test(batch.rows[0].name) && batch.format === '10x2.5', 'payload na 3 schermen', batch);
      check(batch && batch.rightsConsent && batch.rightsConsent.accepted === true, 'payload: rechtenverklaring', batch && batch.rightsConsent);
      check(!errors.length, 'geen JS-fouten', errors);
      await ctx.close();
    }

    console.log('\n# 25 namen, 2 te lang (390 × 844)');
    {
      const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
      const page = await ctx.newPage();
      await page.goto(base + '?rows=25&long=2');
      await page.waitForSelector('.bps-nt__cta');
      await page.screenshot({ path: `${shots}/nametape-gsm-lijst-1.png` });
      await page.click('.bps-nt__cta'); await page.waitForTimeout(200);
      await page.click('.bps-nt__cta'); await page.waitForTimeout(300);
      const red = await page.$$eval('.bps-nt__row.is-error', (l) => l.length);
      check(red === 2, '2 rode rijen in scherm 3', red);
      const top = await page.$$eval('.bps-nt__row', (l) => l.slice(0, 2).every((x) => x.classList.contains('is-error')));
      check(top, 'rode rijen staan bovenaan');
      await layoutOk(page, 'lijst scherm 3');
      await page.screenshot({ path: `${shots}/nametape-gsm-lijst-3-fouten.png` });
      const fixBtn = await page.$('[data-act="fix"]');
      check(!!fixBtn, 'één-klik-oplossing voor de lijst aanwezig');
      if (fixBtn) {
        const label = await fixBtn.textContent();
        check(/2/.test(label), 'oplossing vermeldt het aantal namen', label);
        await fixBtn.click();
        await page.waitForTimeout(300);
        const red2 = await page.$$eval('.bps-nt__row.is-error', (l) => l.length);
        check(red2 === 0, 'na één klik geen rode rijen meer', red2);
        await page.screenshot({ path: `${shots}/nametape-gsm-lijst-3-opgelost.png` });
      }
      await ctx.close();
    }

    console.log('\n# FR (360 × 740)');
    {
      const ctx = await browser.newContext({ viewport: { width: 360, height: 740 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
      const page = await ctx.newPage();
      await page.goto(base + '?lang=fr&rows=3');
      await page.waitForSelector('.bps-nt__cta');
      const texts = [];
      for (let i = 0; i < 3; i++) {
        texts.push(await page.$eval('.bps-nametape', (r) => r.innerText));
        if (i === 1) await page.screenshot({ path: `${shots}/nametape-gsm-fr-2-style.png` });
        if (i < 2) { await page.click('.bps-nt__cta'); await page.waitForTimeout(250); }
      }
      const all = texts.join('\n');
      check(!/\bnt\.[a-z]/.test(all), 'FR: geen ontbrekende sleutels', all.match(/\bnt\.[a-zA-Z.]+/g));
      const dutch = all.match(/\b(Verder|Naamstroken|winkelmand|Namen|Stijl|Controle|lettertype|stroken)\b/g);
      check(!dutch, 'FR: geen Nederlandse woorden zichtbaar', dutch);
      check(/Tout est correct|Qui reçoit/.test(all), 'FR: Franse titels');
      await ctx.close();
    }

    console.log('\n# desktop 1280 × 860');
    {
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
      const page = await ctx.newPage();
      await page.goto(base + '?rows=25&long=2');
      await page.waitForSelector('.bps-nt__cta');
      await page.screenshot({ path: `${shots}/nametape-desktop-1-namen.png` });
      await page.click('.bps-nt__cta'); await page.waitForTimeout(250);
      await page.screenshot({ path: `${shots}/nametape-desktop-2-stijl.png` });
      await page.click('.bps-nt__cta'); await page.waitForTimeout(300);
      await page.screenshot({ path: `${shots}/nametape-desktop-3-controle.png` });
      const sw = await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
      check(sw, 'desktop: geen horizontale scroll');
      await ctx.close();
    }
  } finally {
    await browser.close();
    srv.close();
  }
  console.log(`\nnametape e2e: ${fails ? fails + ' fout(en)' : 'alles ok'}`);
  return fails ? 1 : 0;
}

run().then((c) => process.exit(c), (e) => { console.error(e); process.exit(1); });
