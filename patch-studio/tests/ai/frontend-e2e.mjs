#!/usr/bin/env node
/**
 * AI-ontwerpstudio + dienstkaart — Playwright-controle van dev/ai.html (mock, geen WordPress nodig).
 *   NODE_PATH="$(npm root -g)" node tests/ai/frontend-e2e.mjs [--no-shots]
 * Ook via `npm test` (tests/ai/frontend-e2e.test.js) als Playwright + Chromium aanwezig zijn.
 *
 * Controleert (SPEC-AI-DIENSTEN §1, §10, §16.1):
 *  - gsm 390×844: ingang → idee → voorstellen → kiezen → `bps:ai:design:chosen` met File + ai + hints, < 2 min
 *  - label "Gemaakt met AI" als DOM-tekst op elke tegel, radiogroup met pijltjestoetsen
 *  - gratis verfijning (lokaal) toont "Open in de studio" + "Toch met AI"; AI-verfijning geeft 2 versies
 *  - rondes op → e-mailsheet (+2 met code) zonder de flow te verlaten; pakketsheet → saldo komt vanzelf binnen
 *  - vergrendelde rondes → toestemmingssheet met 2 lege vakjes
 *  - merk gevraagd → geweigerd, geen ronde afgeboekt, knop "eigen logo"
 *  - Esc sluit, focus terug naar de opener; geen horizontale scroll; doelen ≥ 44 px; axe zonder ernstige fouten
 *  - dienstkaart: niets vooraf aangevinkt, "Nee, dank je" even groot als "Voeg toe"
 * Schermafdrukken: docs/ai/screenshots/ai-*.png (gsm + desktop).
 */
import { createServer } from 'node:http';
import { readFile, stat, mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { resolve, dirname, extname, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const shotsDir = resolve(root, 'docs/ai/screenshots');
const SHOTS = !process.argv.includes('--no-shots');

let chromium;
try {
  ({ chromium } = require('playwright'));
} catch {
  console.error('Playwright ontbreekt. Draai met NODE_PATH="$(npm root -g)".');
  process.exit(2);
}
let axeSource = null;
try {
  axeSource = await readFile(require.resolve('axe-core/axe.min.js', { paths: [resolve(root, 'tests/e2e/node_modules'), root] }), 'utf8');
} catch { /* axe optioneel */ }

const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png' };
const server = createServer(async (req, res) => {
  try {
    const p = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    const file = normalize(resolve(root, '.' + p));
    if (!file.startsWith(root) || !(await stat(file)).isFile()) throw new Error('404');
    res.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
    res.end(await readFile(file));
  } catch {
    res.writeHead(404).end();
  }
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}/dev/ai.html`;
if (SHOTS) await mkdir(shotsDir, { recursive: true });

let failures = 0;
let passes = 0;
function check(cond, msg) {
  if (cond) {
    passes++;
    console.log('  ok   ' + msg);
  } else {
    failures++;
    console.log('  FOUT ' + msg);
  }
}
const GSM = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, hasTouch: true, isMobile: true };
const DESK = { viewport: { width: 1280, height: 860 } };

const browser = await chromium.launch();
const errors = [];

async function page(opts, query) {
  const ctx = await browser.newContext(opts);
  const p = await ctx.newPage();
  p.on('pageerror', (e) => errors.push(e.message));
  p.on('console', (m) => {
    if (m.type() === 'error') errors.push(m.text());
  });
  await p.goto(base + '?' + new URLSearchParams(Object.assign({ latency: '500' }, query)).toString());
  await p.waitForSelector('html[data-ready="1"]');
  await p.evaluate(() => {
    window.__ev = [];
    const host = document.getElementById('studio');
    ['bps:ai:design:chosen', 'bps:ai:close', 'bps:ai:blocked', 'bps:ai:local:action', 'bps:ai:job:done', 'bps:svc:offer:shown'].forEach((n) =>
      host.addEventListener(n, (e) => {
        const d = e.detail || {};
        window.__ev.push({ n, file: d.file ? { name: d.file.name, type: d.file.type, size: d.file.size, isFile: d.file instanceof File } : null, ai: d.ai || null, hints: d.hints || null, source: d.source || null, reason: d.reason, code: d.code, action: d.action });
      })
    );
  });
  return p;
}
const shot = async (p, name) => {
  if (SHOTS) await p.screenshot({ path: resolve(shotsDir, name + '.png') });
};
const events = (p) => p.evaluate(() => window.__ev);
const balance = (p) => p.evaluate(() => window.__mockApi._debug().bal);
async function axe(p, label) {
  if (!axeSource) return;
  await p.addScriptTag({ content: axeSource });
  const res = await p.evaluate(async () => {
    const r = await window.axe.run(document.querySelector('.bps-ai'), { resultTypes: ['violations'] });
    return r.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id + ': ' + v.nodes.map((n) => n.target.join(' ')).slice(0, 3).join(', '));
  });
  check(res.length === 0, `axe (${label}): geen ernstige toegankelijkheidsfouten ${res.length ? JSON.stringify(res) : ''}`);
}
async function noHScroll(p, label) {
  const w = await p.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
  check(w[0] <= w[1] + 1, `${label}: geen horizontale scroll (${w[0]} ≤ ${w[1]})`);
}
async function smallTargets(p) {
  return p.evaluate(() =>
    Array.from(document.querySelectorAll('.bps-ai button, .bps-ai summary, .bps-ai .a-seg span, .bps-ai a.a-btn'))
      .filter((b) => b.getClientRects().length && !b.closest('[inert]'))
      .map((b) => [b.textContent.trim().slice(0, 24) || b.getAttribute('aria-label'), Math.round(b.getBoundingClientRect().height), Math.round(b.getBoundingClientRect().width)])
      .filter(([, hgt, w]) => hgt < 44 || w < 44)
  );
}

try {
  /* ---------------------------------------------------------------- 1. gsm: hoofdflow */
  console.log('gsm 390×844 — hoofdflow');
  {
    const t0 = Date.now();
    const p = await page(GSM, { n: '4' });
    await p.click('#btn-ai');
    await p.waitForSelector('#bps-ai-desc');
    check(await p.locator('.bps-ai__panel[role=dialog][aria-modal=true]').count() === 1, 'bottom sheet is een modale dialog');
    check((await p.textContent('.bps-ai__counter')).includes('1 gratis ronde'), 'rondeteller: "1 gratis ronde"');
    check((await p.textContent('.bps-ai__test')).includes('Testmodus'), 'testmodus-regel zichtbaar');
    check((await p.textContent('.bps-ai__foot')).includes('Maak 4 voorstellen'), 'primaire knop zegt wat hij doet');
    check((await p.textContent('.bps-ai__foot')).includes('1 ronde'), 'knop toont de kost (1 ronde)');
    check((await smallTargets(p)).length === 0, 'alle doelen ≥ 44 px ' + JSON.stringify(await smallTargets(p)));
    await noHScroll(p, 'beschrijven');
    await axe(p, 'beschrijven');
    await shot(p, 'ai-gsm-1-beschrijven');
    await p.click('.a-idea >> nth=0');
    check((await p.inputValue('#bps-ai-desc')).length > 10, 'ideekaart vult een voorbeeldzin in');
    await p.fill('#bps-ai-desc', 'een wolf met een bijl voor onze motorclub, rond, zwart-rood');
    await p.fill('#bps-ai-text', 'MC WOLVES 1998');
    await p.click('.bps-ai__foot .a-btn--primary');
    await p.waitForSelector('.a-skel');
    await shot(p, 'ai-gsm-2-wachten');
    await p.waitForSelector('.a-tile', { timeout: 10000 });
    await p.waitForTimeout(400);
    check(await p.locator('.a-tile').count() === 4, '4 voorstellen');
    check(await p.locator('.a-tile .a-ailabel', { hasText: 'Gemaakt met AI' }).count() === 4, 'label "Gemaakt met AI" als DOM-tekst op elke tegel');
    check(await p.locator('[role=radiogroup] [role=radio][aria-checked=true]').count() === 1, 'radiogroup met één keuze');
    check((await p.textContent('.bps-ai__counter')).includes('Geen rondes meer'), 'teller daalt naar 0');
    const alt = await p.getAttribute('.a-tile >> nth=0 >> img', 'alt');
    check(/Voorstel 1: .*wolvenkop/.test(alt || ''), 'alt-tekst uit "zo begrepen we je" (' + alt + ')');
    await p.focus('.a-tile[aria-checked=true]');
    await p.keyboard.press('ArrowRight');
    check((await p.getAttribute('.a-tile[aria-checked=true]', 'data-index')) === '1', 'pijltje rechts kiest voorstel 2');
    await p.waitForTimeout(300);
    await noHScroll(p, 'voorstellen');
    await axe(p, 'voorstellen');
    await shot(p, 'ai-gsm-3-voorstellen');

    // Gratis lokale verfijning.
    await p.fill('#bps-ai-refine', 'andere kleuren graag');
    await p.press('#bps-ai-refine', 'Enter');
    await p.waitForSelector('[data-k=local-open]');
    check(await p.locator('[data-k=local-force]').count() === 1, 'lokale verfijning: "Toch met AI (1 ronde)"');
    // AI-verfijning zonder rondes → e-mailsheet (flow blijft).
    await p.fill('#bps-ai-refine', 'maak de wolf bozer');
    await p.press('#bps-ai-refine', 'Enter');
    await p.waitForSelector('#bps-ai-email');
    check((await p.textContent('#bps-ai-sheet-title')).includes('2 extra rondes'), 'rondes op → e-mailsheet "+2"');
    await axe(p, 'e-mailsheet');
    await shot(p, 'ai-gsm-4-email');
    await p.fill('#bps-ai-email', 'test@example.com');
    await p.click('[data-k=send]');
    await p.waitForSelector('#bps-ai-code');
    await p.fill('#bps-ai-code', '000000');
    await p.click('[data-k=verify]');
    await p.waitForSelector('.a-note--warn');
    check((await p.textContent('.bps-ai__sheet')).includes('klopt niet'), 'foute code: duidelijke melding met pogingen');
    await p.fill('#bps-ai-code', '123456');
    await p.click('[data-k=verify]');
    await p.waitForSelector('.bps-ai__sheet', { state: 'detached' });
    check((await p.textContent('.bps-ai__counter')).includes('2 gratis rondes'), 'e-mail bevestigd → 2 gratis rondes');
    check(await p.locator('.a-tile').count() === 4, 'voorstellen bleven staan na de sheet');
    await p.fill('#bps-ai-refine', 'maak de wolf bozer');
    await p.press('#bps-ai-refine', 'Enter');
    await p.waitForFunction(() => document.querySelectorAll('.a-tile').length === 2, null, { timeout: 10000 });
    check(true, 'AI-verfijning geeft 2 versies');
    await shot(p, 'ai-gsm-5-verfijnd');
    await p.click('.bps-ai__foot .a-btn--primary');
    await p.waitForFunction(() => window.__ev.some((e) => e.n === 'bps:ai:design:chosen'));
    const ev = (await events(p)).find((e) => e.n === 'bps:ai:design:chosen');
    check(ev.file && ev.file.isFile && ev.file.size > 200 && ev.file.type === 'image/svg+xml', 'design:chosen bevat een File (' + JSON.stringify(ev.file) + ')');
    check(ev.source === 'ai' && ev.ai && ev.ai.label === 'Gemaakt met AI' && ev.ai.humanFinish === false && /^[0-9A-Z]{26}$/.test(ev.ai.jobId), 'design:chosen bevat ai-gegevens');
    check(ev.hints && ev.hints.text === 'MC WOLVES 1998' && ev.hints.textMode === 'studio' && ev.hints.background === 'transparent', 'hints: tekst voor de studio, transparante achtergrond');
    check((await events(p)).some((e) => e.n === 'bps:ai:close' && e.reason === 'chosen'), 'paneel sluit met reason "chosen"');
    check(await p.locator('.bps-ai').isHidden(), 'paneel verborgen na kiezen');
    await p.waitForTimeout(300);
    await shot(p, 'ai-gsm-6-op-de-patch');
    check(Date.now() - t0 < 120000, `hele flow < 2 min (${Math.round((Date.now() - t0) / 1000)} s, mock)`);
    await p.context().close();
  }

  /* ---------------------------------------------------------------- 2. geweigerd merk */
  console.log('gsm — merk geweigerd, geen ronde af');
  {
    const p = await page(GSM, { n: '3' });
    await p.click('#btn-ai');
    await p.waitForSelector('#bps-ai-desc');
    const before = (await balance(p)).free;
    await p.fill('#bps-ai-desc', 'het logo van nike op een cirkel');
    await p.click('.bps-ai__foot .a-btn--primary');
    await p.waitForSelector('[data-k=own-logo]');
    check((await balance(p)).free === before, 'geen ronde afgeboekt bij weigering');
    check((await p.textContent('.bps-ai__body')).includes('Er is geen ronde afgeboekt'), 'melding zegt dat het niets kostte');
    check((await events(p)).some((e) => e.n === 'bps:ai:blocked' && e.code === 'brand'), 'event bps:ai:blocked {code: brand}');
    await shot(p, 'ai-gsm-7-geweigerd');
    // Esc sluit + focus terug.
    await p.keyboard.press('Escape');
    check(await p.locator('.bps-ai').isHidden(), 'Esc sluit het paneel');
    check(await p.evaluate(() => document.activeElement && document.activeElement.id === 'btn-ai'), 'focus terug naar de opener');
    await p.context().close();
  }

  /* ---------------------------------------------------------------- 3. pakket kopen zonder de flow te verlaten */
  console.log('gsm — pakket kopen, saldo komt vanzelf');
  {
    const p = await page(GSM, { n: '3', bal: 'emptyMail' });
    await p.click('#btn-ai');
    await p.waitForSelector('#bps-ai-desc');
    check((await p.textContent('.bps-ai__foot')).includes('Meer rondes'), 'zonder rondes: primaire knop "Meer rondes"');
    await p.fill('#bps-ai-desc', 'een anker in een cirkel, marineblauw');
    await p.click('.bps-ai__foot .a-btn--primary');
    await p.waitForSelector('.a-pack');
    check(await p.locator('.a-pack input:checked').count() === 1, 'pakket: bovenste als keuze geselecteerd (radio, geen vinkje)');
    check((await p.textContent('.bps-ai__sheet')).includes('terug als korting'), 'terugbetaalregel staat ernaast');
    check(!/beperkt|nog maar|populair|credits|tokens/i.test(await p.textContent('.bps-ai__sheet')), 'geen druk- of creditwoorden');
    await axe(p, 'pakketsheet');
    await noHScroll(p, 'pakketsheet');
    await shot(p, 'ai-gsm-8-pakket');
    await p.click('[data-k=pay]');
    await p.waitForSelector('[data-k=paid]');
    await shot(p, 'ai-gsm-9-betalen');
    await p.waitForSelector('.bps-ai__sheet', { state: 'detached', timeout: 9000 });
    check((await p.textContent('.bps-ai__counter')).includes('Nog 10 rondes'), 'na betalen: "Nog 10 rondes" zonder herladen');
    check((await p.inputValue('#bps-ai-desc')).includes('anker'), 'beschrijving bleef bewaard');
    await p.context().close();
  }

  /* ---------------------------------------------------------------- 4. vergrendelde rondes → toestemming */
  console.log('gsm — gekochte rondes vergrendeld → toestemming');
  {
    const p = await page(GSM, { n: '3', bal: 'locked' });
    await p.click('#btn-ai');
    await p.waitForSelector('#bps-ai-desc');
    await p.fill('#bps-ai-desc', 'een berg met een zon');
    await p.click('.bps-ai__foot .a-btn--primary');
    await p.waitForSelector('[data-k=consent-go]');
    check(await p.locator('.bps-ai__sheet input[type=checkbox]:checked').count() === 0, 'twee vakjes, niet vooraf aangevinkt');
    check(await p.isDisabled('[data-k=consent-go]'), '"Start nu" pas na beide vakjes');
    await shot(p, 'ai-gsm-10-toestemming');
    await p.check('[data-k=consent-0]');
    await p.check('[data-k=consent-1]');
    await p.click('[data-k=consent-go]');
    await p.waitForSelector('.bps-ai__sheet', { state: 'detached' });
    check((await p.textContent('.bps-ai__counter')).includes('Nog 10 rondes'), 'rondes ontgrendeld');
    await p.context().close();
  }

  /* ---------------------------------------------------------------- 5. FR */
  console.log('gsm — FR');
  {
    const p = await page(GSM, { n: '3', lang: 'fr' });
    await p.click('#btn-ai');
    await p.waitForSelector('#bps-ai-desc');
    check((await p.textContent('.bps-ai__title')).includes('Créer avec l’IA'), 'titel in het Frans');
    check((await p.textContent('.bps-ai__counter')).includes('1 essai gratuit'), 'teller in het Frans (essai)');
    await p.fill('#bps-ai-desc', 'un loup avec une hache, rond, noir et rouge');
    await p.click('.bps-ai__foot .a-btn--primary');
    await p.waitForSelector('.a-tile', { timeout: 10000 });
    check(await p.locator('.a-ailabel', { hasText: /Créé avec l.IA/ }).count() === 3, 'label "Créé avec l’IA" op elke tegel');
    await shot(p, 'ai-gsm-11-fr-propositions');
    await p.context().close();
  }

  /* ---------------------------------------------------------------- 6. desktop */
  console.log('desktop 1280×860');
  {
    const p = await page(DESK, { n: '4' });
    await p.click('#btn-ai');
    await p.waitForSelector('#bps-ai-desc');
    check(await p.locator('.bps-ai--inline .bps-ai__panel[role=region]').count() === 1, 'desktop: paneel in de assistent-kolom (geen modale overlay)');
    await shot(p, 'ai-desktop-1-beschrijven');
    await p.fill('#bps-ai-desc', 'een adelaar met gespreide vleugels, schild, goud en marineblauw');
    await p.click('.bps-ai__foot .a-btn--primary');
    await p.waitForSelector('.a-tile', { timeout: 10000 });
    await p.click('.a-tile >> nth=2');
    await p.waitForTimeout(400);
    const prev = await p.getAttribute('#patch img', 'src');
    check(!!prev && prev.startsWith('data:image/svg+xml'), 'voorstel aantikken = op de patch tonen');
    await axe(p, 'desktop voorstellen');
    await shot(p, 'ai-desktop-2-voorstellen');
    await p.click('.bps-ai__counter');
    await p.waitForSelector('.a-pack');
    await shot(p, 'ai-desktop-3-pakket');
    await p.keyboard.press('Escape');
    await p.waitForSelector('.a-tile');
    check(await p.locator('.a-tile').count() === 4, 'Esc sluit eerst enkel de sheet');
    // Dienstkaart.
    await p.selectOption('#svc-scn', { label: 'klein bestand: hertekenen' });
    await p.waitForSelector('.bps-svc');
    const sizes = await p.evaluate(() => ['.bps-svc__add', '.bps-svc__no'].map((s) => document.querySelector(s).getBoundingClientRect()).map((r) => [Math.round(r.width), Math.round(r.height)]));
    check(sizes[0][0] === sizes[1][0] && sizes[0][1] === sizes[1][1] && sizes[0][1] >= 44, '"Nee, dank je" even groot als "Voeg toe" ' + JSON.stringify(sizes));
    check(await p.locator('.bps-svc input:checked').count() === 0, 'dienst nooit vooraf aangevinkt');
    check((await p.textContent('.bps-svc')).includes('Upload dat'), 'gratis alternatief zichtbaar');
    await shot(p, 'ai-desktop-4-dienstkaart');
    await p.context().close();
  }

  /* ---------------------------------------------------------------- 7. mislukte job → ronde terug */
  console.log('gsm — storing: ronde terug');
  {
    const p = await page(GSM, { n: '3', bal: 'paid7' });
    await p.click('#btn-ai');
    await p.waitForSelector('#bps-ai-desc');
    await p.fill('#bps-ai-desc', 'een leeuw [[mock:fail]]');
    await p.click('.bps-ai__foot .a-btn--primary');
    await p.waitForSelector('text=je ronde is teruggezet', { timeout: 10000 });
    check((await balance(p)).paid === 7, 'mislukte ronde teruggeboekt (7 blijft 7)');
    await p.context().close();
  }
} catch (e) {
  failures++;
  console.log('  FOUT (uitzondering) ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e));
}

check(errors.length === 0, 'geen JS-fouten in de console ' + (errors.length ? JSON.stringify(errors.slice(0, 5)) : ''));
await browser.close();
server.close();
console.log(`\n${passes} ok, ${failures} fout`);
process.exit(failures ? 1 : 0);
