/**
 * Browser harness for render tests: bundles page-entry.js with esbuild and opens it in Chromium (Playwright).
 * Serves /img/* from patch-studio/reference and /cfg/* from the plugin config folder.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
export const pluginDir = path.resolve(here, '../../plugin/borduur-patch-studio');
export const projectDir = path.resolve(pluginDir, '../..');
const require = createRequire(path.join(projectDir, 'package.json'));
const MIME = { '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.js': 'text/javascript', '.json': 'application/json' };

export async function openHarness() {
  let chromium, esbuild;
  try { ({ chromium } = require('playwright')); } catch (e) { return { skip: 'playwright not available' }; }
  try { esbuild = await import(require.resolve('esbuild')); } catch (e) { return { skip: 'esbuild not available' }; }
  let browser;
  try { browser = await chromium.launch(); } catch (e) { return { skip: 'chromium not available: ' + e.message }; }
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'bps-render-'));
  await esbuild.build({ entryPoints: [path.join(here, 'page-entry.js')], bundle: true, format: 'iife', target: ['es2019', 'safari14'],
    outfile: path.join(tmp, 'api.js'), logLevel: 'silent', minify: true });
  const page = await browser.newPage({ deviceScaleFactor: 1 });
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  await page.route('http://bps.test/**', (route) => {
    const u = new URL(route.request().url());
    if (u.pathname === '/') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><body style="margin:0;background:#fff"><script src="/api.js"></script>' });
    let file;
    if (u.pathname.startsWith('/img/')) file = path.join(projectDir, 'reference', decodeURIComponent(u.pathname.slice(5)));
    else if (u.pathname.startsWith('/cfg/')) file = path.join(pluginDir, 'config', path.basename(u.pathname));
    else file = path.join(tmp, path.basename(u.pathname));
    if (!fs.existsSync(file)) return route.fulfill({ status: 404, body: 'nf' });
    return route.fulfill({ contentType: MIME[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file) });
  });
  await page.goto('http://bps.test/');
  await page.evaluate(async () => {
    const d = await (await fetch('/cfg/defaults.json')).json();
    const p = await (await fetch('/cfg/thread-palette.json')).json();
    window.CFG = window.T.buildPublicConfig(d, p, { locale: 'nl-BE' });
  });
  return {
    browser, page, errors,
    async close() { await browser.close(); fs.rmSync(tmp, { recursive: true, force: true }); },
  };
}
