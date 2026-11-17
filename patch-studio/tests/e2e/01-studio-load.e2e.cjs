'use strict';
/**
 * E2E 1 — the page with [patch_studio] loads: server skeleton, valid public config (no private keys),
 * the app mounts without console errors, assets are served, REST token works, a11y (axe) is clean.
 */
const { ENV, Scenario, launch, newPage, shot, axe } = require('./lib/harness.cjs');

const t = new Scenario('01-studio-load');
t.run(async (keep) => {
  const browser = await launch();
  keep(browser);

  // 1. Server HTML (before JS): root + config + skeleton with a real file input.
  const { page, problems, context } = await newPage(browser);
  const res = await context.request.get(ENV.studio);
  t.ok(res.status() === 200, 'studio page answers 200', res.status());
  const html = await res.text();
  t.ok(/data-bps-root/.test(html), 'root div [data-bps-root] in server HTML');
  t.ok(/<input[^>]+type=["']?file/.test(html), 'skeleton contains a real <input type=file> (works before JS)');
  t.ok(/<noscript/i.test(html), 'noscript fallback present');
  t.ok(/patch-studio\.js/.test(html) && /patch-studio\.css/.test(html), 'bps-app script and bps-style css enqueued');
  const m = /<script type="application\/json" class="bps-config">([\s\S]*?)<\/script>/.exec(html);
  let cfg = null;
  try { cfg = m && JSON.parse(m[1]); } catch (e) { /* reported below */ }
  t.ok(!!cfg, 'public config JSON parses');
  if (cfg) {
    t.ok(!cfg.woo && !cfg.retention && !cfg.security && !cfg.admin, 'private keys (woo/retention/security/admin) stripped', Object.keys(cfg));
    t.ok(cfg.locale === 'nl-BE', 'locale nl-BE', cfg.locale);
    t.ok(cfg.rest && /patch-studio\/v1\/$/.test(cfg.rest.root), 'rest.root points to patch-studio/v1/', cfg.rest);
    t.ok(cfg.assets && /patch-studio-worker\.js/.test(cfg.assets.workerUrl || ''), 'assets.workerUrl set', cfg.assets);
    const s = JSON.stringify(cfg);
    t.ok(!/@example\.test|admin_email/.test(s), 'no admin e-mail leaked in config');
  }

  // 2. Assets are reachable (worker must be same-origin or Blob fallback).
  if (cfg && cfg.assets) {
    const w = await context.request.get(cfg.assets.workerUrl);
    t.ok(w.status() === 200, 'worker script served', w.status());
  }

  // 3. REST token route + no-store.
  const tok = await context.request.get(`${ENV.base}/wp-json/patch-studio/v1/token`);
  const tj = await tok.json().catch(() => ({}));
  t.ok(tok.status() === 200 && typeof tj.token === 'string' && tj.token.length >= 8, 'GET /token returns a token', tj);
  t.ok(/no-store/.test(tok.headers()['cache-control'] || ''), 'REST answers with Cache-Control: no-store', tok.headers()['cache-control']);

  // 4. Browser: app mounts, empty state visible, no console errors.
  await page.goto(ENV.studio, { waitUntil: 'load' });
  await page.waitForSelector('.bps-studio .bps-empty', { timeout: 20000 });
  t.ok(await page.locator('.bps-studio h2, .bps-studio h1').first().isVisible(), 'heading visible');
  const title = await page.locator('.bps-empty h2, .bps-empty h1').first().innerText().catch(() => '');
  t.ok(/Ontwerp je patch/i.test(title), 'Flemish heading "Ontwerp je patch"', title);
  t.ok(await page.locator('.bps-empty input[type=file]').count() === 1, 'one file input in the mounted app');
  t.ok(await page.evaluate(() => typeof window.BPS === 'object' && typeof window.BPS.mount === 'function'), 'window.BPS.mount exists');
  t.ok(await page.locator('.bps-skeleton:visible').count() === 0, 'server skeleton replaced by the app');
  const hasScroll = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  t.ok(!hasScroll, 'no horizontal scroll (desktop)');
  const v = await axe(page);
  if (v === null) t.info('axe-core not installed: a11y check skipped (npm i in tests/e2e)');
  else t.ok(v.length === 0, 'axe: 0 serious/critical violations (empty state)', v);
  await shot(page, '01-studio-empty');
  await page.waitForTimeout(500);
  t.ok(problems.length === 0, 'no console errors, page errors or failing plugin requests', problems);
});
