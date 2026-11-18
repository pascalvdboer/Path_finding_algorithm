#!/usr/bin/env node
/**
 * Cross-sell — Playwright-controle van dev/crosssell.html (SPEC-EXTRA §5 "Crosssell klaar als").
 *   NODE_PATH="$(npm root -g)" node tests/crosssell/e2e-panel.mjs
 * Controleert: juiste kaarten per voorbeeldontwerp, echte modale dialog, Esc = "Nee, bedankt" + focus terug,
 * niets vooraf aangevinkt, "Nee, bedankt" even groot als "Toevoegen", mockups ≤ 25 ms, geen horizontale scroll op gsm.
 * Schermafdrukken: docs/extra/screenshots/crosssell-*.png (gsm 390 × 844 en desktop 1280 × 800).
 */
import { createServer } from 'node:http';
import { readFile, stat, mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { resolve, dirname, extname, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const shots = resolve(root, 'docs/extra/screenshots');

let chromium;
try {
  ({ chromium } = require('playwright'));
} catch {
  console.error('Playwright ontbreekt. Draai met NODE_PATH="$(npm root -g)".');
  process.exit(2);
}

const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.woff2': 'font/woff2' };
const server = createServer(async (req, res) => {
  try {
    const p = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    const file = normalize(resolve(root, '.' + p));
    if (!file.startsWith(root) || !(await stat(file)).isFile()) throw new Error('404');
    res.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream' });
    res.end(await readFile(file));
  } catch {
    res.writeHead(404).end();
  }
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}/dev/crosssell.html`;
await mkdir(shots, { recursive: true });

let failures = 0;
const check = (ok, msg) => {
  console.log(`${ok ? 'ok  ' : 'FOUT'} ${msg}`);
  if (!ok) failures++;
};

const browser = await chromium.launch();
const errors = [];
async function page(viewport, query) {
  const ctx = await browser.newContext({ viewport, reducedMotion: 'reduce', deviceScaleFactor: viewport.width < 700 ? 2 : 1, hasTouch: viewport.width < 700, isMobile: viewport.width < 700 });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => errors.push(String(e)));
  p.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  await p.goto(`${base}?${query}`);
  await p.waitForSelector('body[data-ready="1"]');
  return p;
}
const cardIds = (p) => p.$$eval('.bps-cs__card', (n) => n.map((x) => x.dataset.item));

try {
  // 1. Juiste kaarten per voorbeeldontwerp (inline, desktop).
  const expect = {
    flat25: ['cap', 'hoodie', 'beanie'],
    photo1: ['frame', 'leather_keychain', 'cap'],
    pvc50: ['cap', 'pvc_keychain', 'hoodie'],
  };
  for (const [id, want] of Object.entries(expect)) {
    const p = await page({ width: 1280, height: 800 }, `view=inline&design=${id}&month=6`);
    await p.waitForSelector('.bps-cs__card');
    const got = await cardIds(p);
    check(JSON.stringify(got) === JSON.stringify(want), `${id}: kaarten ${got.join(', ')} (verwacht ${want.join(', ')})`);
    if (id === 'photo1') check(!got.includes('pvc_keychain'), 'foto → geen PVC-kaart');
    await p.waitForFunction(() => [...document.querySelectorAll('.bps-cs__card')].every((c) => c.dataset.renderMs));
    const ms = await p.$$eval('.bps-cs__card', (n) => n.map((c) => Number(c.dataset.renderMs)));
    check(ms.every((x) => x <= 25), `${id}: mockups ${ms.join(' / ')} ms (≤ 25 ms)`);
    await p.context().close();
  }
  {
    const p = await page({ width: 1280, height: 800 }, 'view=inline&design=illu12&month=6');
    await p.waitForSelector('.bps-cs__card');
    const got = await cardIds(p);
    check(got.includes('cap') && got.includes('hoodie'), `illu12: ${got.join(', ')} bevat pet en hoodie`);
    await p.context().close();
  }

  // 2. Dialog op gsm: modaal, focus, niets aangevinkt, knoppen even groot, Esc = Nee, bedankt.
  {
    const p = await page({ width: 390, height: 844 }, 'design=flat25&month=6');
    const opener = await p.$('#dev-open');
    await opener.click();
    await p.waitForSelector('dialog.bps-cs[open] .bps-cs__card');
    await p.waitForFunction(() => [...document.querySelectorAll('.bps-cs__card')].every((c) => c.dataset.renderMs));
    check(await p.$eval('dialog.bps-cs', (d) => d.matches(':modal')), 'paneel is een echte modale <dialog>');
    check(await p.evaluate(() => !!document.activeElement && !!document.activeElement.closest('dialog.bps-cs')), 'focus staat in het paneel');
    check(await p.$$eval('dialog.bps-cs input[type=checkbox]:checked, dialog.bps-cs input[type=radio][data-paid]:checked', (n) => n.length === 0), 'geen vooraf aangevinkte opties');
    const sizes = await p.evaluate(() => {
      const a = document.querySelector('.bps-cs__add').getBoundingClientRect();
      const d = document.querySelector('.bps-cs__decline').getBoundingClientRect();
      return { add: a.height, decline: d.height, declineW: d.width };
    });
    check(sizes.decline >= 44 && sizes.decline >= sizes.add - 1, `"Nee, bedankt" ${Math.round(sizes.decline)} px hoog, "Toevoegen" ${Math.round(sizes.add)} px`);
    const box = await p.$eval('.bps-cs__sheet', (s) => { const r = s.getBoundingClientRect(); return { h: r.height, bottom: r.bottom, w: r.width }; });
    check(box.h <= 844 * 0.86 && Math.abs(box.bottom - 844) < 2, `gsm: bottom sheet (${Math.round(box.h)} px hoog, onderaan)`);
    check(await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'gsm: geen horizontale scroll');
    const txt = await p.$eval('dialog.bps-cs', (d) => d.textContent);
    check(!/nog maar|bijna uitverkocht|meest gekozen|\d+ ?min(uten)? nog/i.test(txt), 'geen schaarste- of druktekst');
    check(/Voorbeeld\. Je krijgt een digitale proef/.test(txt), 'mockup-label "Voorbeeld …" staat in de DOM');
    await p.screenshot({ path: resolve(shots, 'crosssell-gsm-paneel.png') });
    await p.$eval('.bps-cs__sheet', (s) => { s.scrollTop = s.scrollHeight; });
    await p.screenshot({ path: resolve(shots, 'crosssell-gsm-paneel-onder.png') });
    await p.keyboard.press('Escape');
    await p.waitForSelector('body[data-result]');
    check(await p.$eval('body', (b) => b.dataset.result) === 'declined', 'Esc = "Nee, bedankt" (resultaat declined)');
    check(await p.evaluate(() => document.activeElement && document.activeElement.id === 'dev-open'), 'focus terug op de knop die het paneel opende');
    check(await p.$('dialog.bps-cs') === null, 'paneel verwijderd na sluiten');
    await p.context().close();
  }

  // 3. Eén klik "Toevoegen" → payload + "Toegevoegd ✓ · Ongedaan maken"; hoodie vraagt maten.
  {
    const p = await page({ width: 390, height: 844 }, 'design=flat25&month=6');
    await p.click('#dev-open');
    await p.waitForSelector('dialog.bps-cs[open] .bps-cs__card');
    await p.click('.bps-cs__card[data-item="cap"] .bps-cs__add');
    await p.waitForSelector('.bps-cs__card[data-item="cap"] .bps-cs__done:not([hidden])');
    const out = JSON.parse(await p.$eval('#dev-out', (n) => n.textContent));
    check(out.item === 'cap' && out.lines.length === 1 && out.lines[0].qty === 25 && out.lines[0].variationId > 0, `pet: één klik → 1 regel, 25 stuks, variatie ${out.lines[0].variationId}`);
    check(await p.$eval('.bps-cs__tocart', (a) => !a.hidden), '"Naar de winkelmand" verschijnt na een toevoeging');
    await p.click('.bps-cs__card[data-item="hoodie"] .bps-cs__add');
    await p.waitForSelector('.bps-cs__card[data-item="hoodie"] .bps-cs__sizes');
    const inputs = await p.$$('.bps-cs__card[data-item="hoodie"] .bps-cs__sizes input:not([disabled])');
    check(inputs.length >= 3, `hoodie: maatlijst met ${inputs.length} maten`);
    await inputs[1].fill('10');
    await inputs[2].fill('15');
    await p.$eval('.bps-cs__card[data-item="hoodie"]', (n) => n.scrollIntoView({ block: 'start' }));
    await p.screenshot({ path: resolve(shots, 'crosssell-gsm-hoodie-maten.png') });
    await p.click('.bps-cs__card[data-item="hoodie"] .bps-cs__add');
    await p.waitForSelector('.bps-cs__card[data-item="hoodie"] .bps-cs__done:not([hidden])');
    const h = JSON.parse(await p.$eval('#dev-out', (n) => n.textContent));
    check(h.item === 'hoodie' && h.lines.length === 2 && h.lines.reduce((a, l) => a + l.qty, 0) === 25, 'hoodie: 2 maatregels, samen 25');
    await p.click('.bps-cs__card[data-item="cap"] .bps-cs__undo');
    await p.waitForFunction(() => document.querySelector('#dev-out').textContent.includes('undo'));
    check(true, 'ongedaan maken stuurt POST /crosssell/undo');
    await p.keyboard.press('Escape');
    await p.waitForSelector('body[data-result]');
    check(await p.$eval('body', (b) => b.dataset.result) === 'added', 'resultaat "added" na een toevoeging');
    await p.context().close();
  }

  // 4. Desktop: kaart rechts (420 px) + foto-ontwerp + kaderplaatje; FR op gsm.
  {
    const p = await page({ width: 1280, height: 800 }, 'design=flat25&month=6');
    await p.click('#dev-open');
    await p.waitForSelector('dialog.bps-cs[open] .bps-cs__card');
    await p.waitForFunction(() => [...document.querySelectorAll('.bps-cs__card')].every((c) => c.dataset.renderMs));
    const r = await p.$eval('.bps-cs__sheet', (s) => { const b = s.getBoundingClientRect(); return { w: b.width, right: b.right }; });
    check(r.w <= 440 && r.right >= 1270, `desktop: paneel rechts, ${Math.round(r.w)} px breed`);
    await p.screenshot({ path: resolve(shots, 'crosssell-desktop-paneel.png') });
    await p.context().close();
  }
  {
    const p = await page({ width: 1280, height: 800 }, 'design=photo1&month=6');
    await p.click('#dev-open');
    await p.waitForSelector('dialog.bps-cs[open] .bps-cs__card');
    await p.click('.bps-cs__card[data-item="frame"] .bps-cs__add');
    const fields = await p.$$('.bps-cs__card[data-item="frame"] .bps-cs__field input');
    check(fields.length === 2, 'kader: 2 tekstregels voor het plaatje');
    await fields[0].fill('Bram & Lotte');
    await fields[1].fill('12.09.2026');
    await p.waitForTimeout(100);
    await p.screenshot({ path: resolve(shots, 'crosssell-desktop-foto-kader.png') });
    const txt = await p.$eval('dialog.bps-cs', (d) => d.textContent);
    check(/gravure in bruintinten/.test(txt), 'foto: eerlijk label "gravure in bruintinten" op de lederkaart');
    await p.context().close();
  }
  {
    const p = await page({ width: 390, height: 844 }, 'design=pvc50&lang=fr&month=6');
    await p.click('#dev-open');
    await p.waitForSelector('dialog.bps-cs[open] .bps-cs__card');
    await p.waitForFunction(() => [...document.querySelectorAll('.bps-cs__card')].every((c) => c.dataset.renderMs));
    const txt = await p.$eval('dialog.bps-cs', (d) => d.textContent);
    check(/Non merci/.test(txt) && /Votre écusson est dans votre panier/.test(txt), 'FR: teksten in het Frans');
    check(!/winkelmand|Toevoegen/.test(txt), 'FR: geen Nederlandse resten');
    await p.$eval('.bps-cs__card[data-item="pvc_keychain"]', (n) => n.scrollIntoView({ block: 'start' }));
    await p.screenshot({ path: resolve(shots, 'crosssell-gsm-fr-pvc.png') });
    await p.context().close();
  }
  check(errors.length === 0, `geen JS-fouten in de console${errors.length ? ': ' + errors.join(' | ') : ''}`);
} catch (e) {
  console.error(e);
  failures++;
} finally {
  await browser.close();
  server.close();
}
console.log(failures ? `\ncross-sell e2e: ${failures} fout(en)` : '\ncross-sell e2e: alles in orde');
process.exit(failures ? 1 : 0);
