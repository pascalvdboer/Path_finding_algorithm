'use strict';
/**
 * Shared helpers for the Patch Studio e2e scenarios (plain Node + Playwright, no test runner needed).
 *
 * Conventions
 *  - every scenario is a standalone `*.e2e.cjs` script: exit 0 = pass, 1 = fail, 77 = skipped (precondition missing);
 *  - scenarios that depend on an earlier one read/write `<artifacts>/state.json` (order id, design ref, …);
 *  - screenshots + a JSON report per scenario go to `<artifacts>/` (BPS_E2E_ARTIFACTS or <wp-dir>/e2e-artifacts).
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const E2E_DIR = path.resolve(__dirname, '..');
const PROJECT_DIR = path.resolve(E2E_DIR, '..', '..');
const REF_DIR = path.join(PROJECT_DIR, 'reference');
const FIXTURES = path.join(E2E_DIR, 'fixtures');

/* ------------------------------------------------------------------ */
/* Environment (same precedence as start-wp.sh: env > .env > .env.example) */
/* ------------------------------------------------------------------ */
function loadEnv(file) {
  if (!fs.existsSync(file)) return;
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const m = /^\s*(BPS_[A-Z0-9_]+)\s*=(.*)$/.exec(line);
    if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2].trim();
  }
}
loadEnv(path.join(E2E_DIR, '.env'));
loadEnv(path.join(E2E_DIR, '.env.example'));

const ENV = {
  wpDir: process.env.BPS_WP_DIR,
  base: `http://${process.env.BPS_WP_HOST || '127.0.0.1'}:${process.env.BPS_WP_PORT || '8899'}`,
  user: process.env.BPS_WP_ADMIN_USER || 'admin',
  pass: process.env.BPS_WP_ADMIN_PASS || 'patchstudio-local-2026',
  checkout: process.env.BPS_WP_CHECKOUT || 'classic',
  headed: process.env.BPS_E2E_HEADED === '1',
};
ENV.site = path.join(ENV.wpDir, 'site');
ENV.artifacts = process.env.BPS_E2E_ARTIFACTS || path.join(ENV.wpDir, 'e2e-artifacts');
ENV.studio = `${ENV.base}/patch-studio/`;
fs.mkdirSync(ENV.artifacts, { recursive: true });

/* ------------------------------------------------------------------ */
/* WP-CLI                                                              */
/* ------------------------------------------------------------------ */
function wp(args, { json = false } = {}) {
  const out = execFileSync('bash', [path.join(E2E_DIR, 'start-wp.sh'), 'wp', ...args], {
    encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 32 * 1024 * 1024,
  });
  return json ? JSON.parse(out) : out.trim();
}
/** Run PHP inside WordPress and return the JSON it echoes. */
function wpEvalJson(php) {
  const out = wp(['eval', php]);
  const start = out.indexOf('{') >= 0 && (out.indexOf('[') < 0 || out.indexOf('{') < out.indexOf('[')) ? out.indexOf('{') : out.indexOf('[');
  return JSON.parse(out.slice(start));
}

/* ------------------------------------------------------------------ */
/* Scenario bookkeeping                                                */
/* ------------------------------------------------------------------ */
class Scenario {
  constructor(name) {
    this.name = name;
    this.results = [];
    this.failed = 0;
    this.started = Date.now();
    console.log(`\n=== ${name} ===`);
  }
  ok(cond, msg, detail) {
    const pass = !!cond;
    this.results.push({ pass, msg, detail });
    if (!pass) this.failed++;
    console.log(`${pass ? '  PASS' : '  FAIL'} ${msg}${detail !== undefined && !pass ? ' :: ' + stringify(detail) : ''}`);
    return pass;
  }
  /** Equality with rounding tolerance for money (cents). */
  eqMoney(a, b, msg) {
    return this.ok(typeof a === 'number' && typeof b === 'number' && Math.abs(a - b) < 0.005, `${msg} (${fmt(a)} = ${fmt(b)})`, { a, b });
  }
  info(msg) { console.log(`  ·    ${msg}`); }
  /** Abort the scenario as skipped (exit 77) – e.g. a module that is not built yet. */
  skip(reason) {
    console.log(`  SKIP ${reason}`);
    this.write('skip', reason);
    process.exit(77);
  }
  write(status, reason) {
    const file = path.join(ENV.artifacts, `${this.name}.json`);
    fs.writeFileSync(file, JSON.stringify({ name: this.name, status, reason, ms: Date.now() - this.started, results: this.results }, null, 2));
  }
  done() {
    const status = this.failed ? 'fail' : 'pass';
    this.write(status);
    console.log(`  => ${status.toUpperCase()} (${this.results.length - this.failed}/${this.results.length}, ${((Date.now() - this.started) / 1000).toFixed(1)} s)`);
    process.exit(this.failed ? 1 : 0);
  }
  /** Wrap the scenario body: uncaught errors count as a failure, browser is always closed. */
  async run(fn) {
    let browser = null;
    try {
      await fn((b) => { browser = b; });
    } catch (e) {
      this.ok(false, `uncaught: ${e && e.message ? e.message.split('\n')[0] : e}`, e && e.stack ? e.stack.split('\n').slice(0, 6).join(' | ') : undefined);
    } finally {
      if (browser) await browser.close().catch(() => {});
    }
    this.done();
  }
}
const stringify = (x) => { try { return typeof x === 'string' ? x : JSON.stringify(x); } catch (e) { return String(x); } };
const fmt = (n) => (typeof n === 'number' ? n.toFixed(2) : String(n));

/* ------------------------------------------------------------------ */
/* Shared state between scenarios                                      */
/* ------------------------------------------------------------------ */
const STATE_FILE = path.join(ENV.artifacts, 'state.json');
const state = {
  read() { try { return JSON.parse(fs.readFileSync(STATE_FILE, 'utf8')); } catch (e) { return {}; } },
  merge(obj) { const s = { ...state.read(), ...obj }; fs.writeFileSync(STATE_FILE, JSON.stringify(s, null, 2)); return s; },
};

/* ------------------------------------------------------------------ */
/* Browser                                                             */
/* ------------------------------------------------------------------ */
function playwright() {
  try { return require('playwright'); } catch (e) { /* fall through */ }
  for (const p of ['/opt/node-tools/node_modules/playwright', '/opt/node22/lib/node_modules/playwright', '/usr/local/lib/node_modules_global/playwright']) {
    try { return require(p); } catch (e) { /* next */ }
  }
  throw new Error('Playwright not found (npm i -g playwright && npx playwright install chromium)');
}

const IGNORED_URLS = [/\/favicon\.ico/, /wp-json\/wc\/store\/v1\/batch/];
/**
 * New browser context + page that records console errors, page errors and failing requests.
 * @returns {{context, page, problems: string[]}}
 */
async function newPage(browser, { mobile = false, storageState, locale = 'nl-BE' } = {}) {
  const context = await browser.newContext(mobile
    ? { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, hasTouch: true, isMobile: true, locale, storageState }
    : { viewport: { width: 1280, height: 900 }, locale, storageState });
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  const problems = [];
  // The test site is offline by design: external requests (gravatar, wordpress.org, woocommerce.com) are aborted
  // and their "Failed to load resource" messages are not counted as problems.
  await context.route((url) => !url.href.startsWith(ENV.base) && !/^(data|blob|about):/.test(url.href), (route) => route.abort());
  const isLocal = (u) => !u || u.startsWith(ENV.base);
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    const loc = (m.location && m.location().url) || '';
    if (/Failed to load resource/.test(m.text()) && !isLocal(loc)) return;
    if (/Failed to load resource/.test(m.text()) && IGNORED_URLS.some((re) => re.test(loc))) return;
    problems.push(`console: ${m.text()}${loc ? ' @ ' + loc : ''}`);
  });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  page.on('response', (r) => {
    const u = r.url();
    if (r.status() >= 500 || (r.status() === 404 && /borduur-patch-studio/.test(u))) {
      if (!IGNORED_URLS.some((re) => re.test(u))) problems.push(`http ${r.status()}: ${u}`);
    }
  });
  page.on('requestfailed', (r) => {
    const u = r.url();
    if (u.startsWith(ENV.base) && !IGNORED_URLS.some((re) => re.test(u)) && !/ERR_ABORTED/.test(r.failure()?.errorText || '')) {
      problems.push(`requestfailed: ${u} ${r.failure()?.errorText}`);
    }
  });
  return { context, page, problems };
}
async function launch() {
  const { chromium } = playwright();
  return chromium.launch({ headless: !ENV.headed });
}
async function shot(page, name) {
  const file = path.join(ENV.artifacts, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true }).catch(() => {});
  return file;
}

/* ------------------------------------------------------------------ */
/* axe-core                                                            */
/* ------------------------------------------------------------------ */
let AXE_SRC = null;
function axeSource() {
  if (AXE_SRC !== null) return AXE_SRC;
  for (const p of [path.join(E2E_DIR, 'node_modules/axe-core/axe.min.js'), path.join(PROJECT_DIR, 'node_modules/axe-core/axe.min.js')]) {
    if (fs.existsSync(p)) return (AXE_SRC = fs.readFileSync(p, 'utf8'));
  }
  return (AXE_SRC = '');
}
/** Returns serious/critical violations inside `selector` (null when axe-core is not installed). */
async function axe(page, selector = '.bps-studio') {
  const src = axeSource();
  if (!src) return null;
  if (!(await page.evaluate(() => !!window.axe))) await page.addScriptTag({ content: src });
  const v = await page.evaluate(async (sel) => {
    const el = document.querySelector(sel) || document;
    const r = await window.axe.run(el, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] });
    return r.violations.map((x) => ({ id: x.id, impact: x.impact, n: x.nodes.length, targets: x.nodes.slice(0, 3).map((n) => n.target.join(' ')) }));
  }, selector);
  return v.filter((x) => x.impact === 'serious' || x.impact === 'critical');
}

/* ------------------------------------------------------------------ */
/* Money                                                               */
/* ------------------------------------------------------------------ */
/** "€ 1.234,56" / "1 234,56 €" / "€1,234.56" → 1234.56 (null when no amount). */
function money(text) {
  if (text == null) return null;
  const m = String(text).replace(/ | /g, ' ').match(/-?\d[\d. ,]*/);
  if (!m) return null;
  let s = m[0].trim().replace(/ /g, '');
  const lastComma = s.lastIndexOf(','), lastDot = s.lastIndexOf('.');
  if (lastComma > lastDot) s = s.replace(/\./g, '').replace(',', '.');
  else s = s.replace(/,/g, '');
  const n = Number(s);
  return Number.isFinite(n) ? n : null;
}

/* ------------------------------------------------------------------ */
/* Studio helpers (selectors follow the ui module, see docs/handoff/ui.md) */
/* ------------------------------------------------------------------ */
const studio = {
  async open(page, url = ENV.studio) {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.bps-studio .bps-empty, .bps-studio .bps-step', { timeout: 20000 });
  },
  async upload(page, file) {
    await page.locator('.bps-studio input[type=file]').first().setInputFiles(file);
  },
  async waitAnalysis(page, timeout = 45000) {
    await page.waitForFunction(() => {
      const f = document.querySelector('.bps-found');
      return f && f.querySelector('.is-done') && !f.querySelector('.is-busy');
    }, null, { timeout });
  },
  async currentStep(page) {
    return (await page.locator('.bps-steps__item.is-current, .bps-steps [aria-current="step"]').first().innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
  },
  /** Click the primary "next" button of the visible step. */
  async next(page) {
    const btn = page.locator('.bps-step:visible .bps-step__nav .bps-btn--primary').first();
    await btn.click();
    await page.waitForTimeout(350);
  },
  async chooseTechnique(page, id) {
    const input = page.locator(`.bps-step:visible input[data-fk="tech-${id}"]`).first();
    await input.waitFor({ state: 'attached', timeout: 10000 });
    const label = page.locator(`label[for="${await input.getAttribute('id')}"]`).first();
    if (!(await label.isVisible().catch(() => false))) {
      const more = page.locator('.bps-step:visible .bps-techs > .bps-btn--link').first();
      if (await more.count()) await more.click();
    }
    await label.click();
    await page.waitForTimeout(400);
  },
  async pickSize(page, cm) {
    await page.locator(`input[data-fk="size-${cm}"]`).first().check({ force: true });
    await page.waitForTimeout(250);
  },
  async pickQty(page, qty) {
    const preset = page.locator(`button[data-fk="pr-${qty}"]`).first();
    if (await preset.count()) await preset.click();
    else { await page.fill('.bps-qty__input', String(qty)); await page.locator('.bps-qty__input').blur(); }
    await page.waitForTimeout(250);
  },
  /**
   * From step 1 (design analysed) to step 5 with the given choices. Unspecified choices keep the advice/defaults.
   * Returns the technique id that ends up selected.
   */
  async toSummary(page, { technique, sizeCm, qty } = {}) {
    await studio.next(page); // 1 -> 2
    if (technique) await studio.chooseTechnique(page, technique);
    await studio.next(page); // 2 -> 3
    if (sizeCm) await studio.pickSize(page, sizeCm);
    if (qty) await studio.pickQty(page, qty);
    await studio.next(page); // 3 -> 4
    await studio.next(page); // 4 -> 5
    await page.waitForSelector('.bps-rights input[type=checkbox]', { timeout: 10000 });
    return (await studio.state(page) || {}).technique;
  },
  /**
   * Step 5: confirm rights, click the order button and wait for the cart redirect.
   * Captures the REST answers of POST /designs and POST /cart.
   */
  async order(page) {
    const seen = {};
    const onResp = async (r) => {
      const u = r.url();
      if (r.request().method() !== 'POST') return;
      if (/patch-studio\/v1\/designs(\?|$)/.test(u)) seen.designs = { status: r.status(), body: await r.json().catch(() => null) };
      if (/patch-studio\/v1\/cart(\?|$)/.test(u)) seen.cart = { status: r.status(), body: await r.json().catch(() => null) };
    };
    page.on('response', onResp);
    await page.check('.bps-rights input[type=checkbox]');
    const btnText = await page.locator('.bps-cta .bps-btn--primary').first().innerText();
    seen.buttonText = btnText;
    await page.locator('.bps-cta .bps-btn--primary').first().click();
    await page.waitForURL(/winkelmand|cart/, { timeout: 45000 }).catch(() => {});
    await page.waitForTimeout(300);
    page.off('response', onResp);
    return seen;
  },
  async uiTotal(page) {
    return money(await page.locator('.bps-price .bps-price__total').first().innerText());
  },
  /** Read the persisted design state (sessionStorage, written by ui/state.js). */
  async state(page) {
    return page.evaluate(() => { try { return JSON.parse(sessionStorage.getItem('bps:state:v1')); } catch (e) { return null; } });
  },
};

/* ------------------------------------------------------------------ */
/* REST helpers                                                        */
/* ------------------------------------------------------------------ */
async function restQuote(request, input) {
  const r = await request.post(`${ENV.base}/wp-json/patch-studio/v1/quote`, { data: input, headers: { 'Content-Type': 'application/json' } });
  return { status: r.status(), body: await r.json().catch(() => null) };
}

/* ------------------------------------------------------------------ */
/* WooCommerce: cart + checkout                                        */
/* ------------------------------------------------------------------ */
const cart = {
  /** Totals from the classic or block cart page. */
  async read(page) {
    await page.goto(`${ENV.base}/winkelmand/`, { waitUntil: 'domcontentloaded' });
    if (await page.locator('.wp-block-woocommerce-cart').count()) {
      await page.waitForSelector('.wc-block-components-totals-footer-item .wc-block-components-totals-item__value', { timeout: 20000 });
      return {
        type: 'block',
        total: money(await page.locator('.wc-block-components-totals-footer-item .wc-block-components-totals-item__value').first().innerText()),
        rows: await page.locator('.wc-block-cart-items__row').allInnerTexts(),
        fees: await page.locator('.wc-block-components-totals-fees').allInnerTexts(),
      };
    }
    await page.waitForSelector('.cart_totals, .cart-empty', { timeout: 20000 });
    // Record what WooCommerce pre-selects, then make sure free shipping is chosen when offered.
    const free = page.locator('input.shipping_method[value^="free_shipping"]');
    const freeOffered = (await free.count()) > 0;
    const freePreselected = freeOffered && (await free.isChecked());
    if (freeOffered && !freePreselected) {
      await free.check();
      await page.waitForResponse((r) => r.url().includes('update_shipping_method'), { timeout: 15000 }).catch(() => {});
      await page.waitForTimeout(800);
    }
    const txt = async (sel) => (await page.locator(sel).count() ? page.locator(sel).first().innerText() : null);
    return {
      type: 'classic',
      freeOffered,
      freePreselected,
      total: money(await txt('.cart_totals .order-total .woocommerce-Price-amount')),
      subtotal: money(await txt('.cart_totals .cart-subtotal .woocommerce-Price-amount')),
      fees: await page.locator('.cart_totals tr.fee').allInnerTexts(),
      shipping: await txt('.cart_totals .woocommerce-shipping-totals td'),
      rows: await page.locator('.woocommerce-cart-form__cart-item').allInnerTexts(),
      itemMeta: await page.locator('.woocommerce-cart-form__cart-item .variation').allInnerTexts(),
    };
  },
  async empty(request) {
    // Store API: remove all items (needs a nonce from a GET first).
    const g = await request.get(`${ENV.base}/wp-json/wc/store/v1/cart`);
    const nonce = g.headers()['nonce'] || g.headers()['x-wc-store-api-nonce'];
    await request.delete(`${ENV.base}/wp-json/wc/store/v1/cart/items`, { headers: { Nonce: nonce || '' } }).catch(() => {});
  },
};

const BILLING = {
  first: 'Jan', last: 'Testmans', company: 'Testclub vzw', address: 'Dorpsstraat 12', postcode: '2920', city: 'Kalmthout',
  phone: '0470 00 00 00',
};

/**
 * Complete the checkout with the given payment method ('bacs' | 'cheque').
 * Returns { orderId, orderKey, url }.
 */
async function checkout(page, { method = 'bacs', email = 'klant@example.test' } = {}) {
  await page.goto(`${ENV.base}/afrekenen/`, { waitUntil: 'domcontentloaded' });
  if (await page.locator('.wp-block-woocommerce-checkout').count()) {
    await page.waitForSelector('#email', { timeout: 30000 });
    await page.fill('#email', email);
    // Block checkout asks for the shipping address and re-uses it for billing (checkbox on by default).
    const f = async (field, v) => {
      for (const prefix of ['shipping', 'billing']) {
        const l = page.locator(`#${prefix}-${field}`);
        if ((await l.count()) && (await l.isVisible())) { await l.fill(v); return; }
      }
    };
    await f('first_name', BILLING.first);
    await f('last_name', BILLING.last);
    await f('address_1', BILLING.address);
    await f('postcode', BILLING.postcode);
    await f('city', BILLING.city);
    await f('phone', BILLING.phone);
    const radio = page.locator(`input[name="radio-control-wc-payment-method-options"][value="${method}"], #radio-control-wc-payment-method-options-${method}`).first();
    await radio.waitFor({ state: 'attached', timeout: 30000 });
    await radio.check({ force: true });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(500);
    await page.locator('.wc-block-components-checkout-place-order-button').click();
  } else {
    await page.waitForSelector('#billing_first_name', { timeout: 30000 });
    await page.fill('#billing_first_name', BILLING.first);
    await page.fill('#billing_last_name', BILLING.last);
    if (await page.locator('#billing_company').count()) await page.fill('#billing_company', BILLING.company);
    await page.fill('#billing_address_1', BILLING.address);
    await page.fill('#billing_postcode', BILLING.postcode);
    await page.fill('#billing_city', BILLING.city);
    if (await page.locator('#billing_phone').count()) await page.fill('#billing_phone', BILLING.phone);
    await page.fill('#billing_email', email);
    // Wait for the AJAX review refresh triggered by the address fields before choosing the gateway.
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForSelector('.blockUI', { state: 'detached', timeout: 15000 }).catch(() => {});
    await page.locator(`#payment_method_${method}`).check({ force: true });
    await page.waitForTimeout(300);
    await page.locator('#place_order').click();
  }
  await page.waitForURL(/order-received|bestelling-ontvangen|commande-recue/, { timeout: 45000 });
  const url = page.url();
  const m = /order-received\/(\d+)|\/(\d+)\/?\?key=/.exec(url) || /[?&]order(?:-received)?=(\d+)/.exec(url);
  const key = (/[?&]key=([^&]+)/.exec(url) || [])[1] || null;
  return { orderId: m ? Number(m[1] || m[2]) : null, orderKey: key, url };
}

/* ------------------------------------------------------------------ */
/* Admin                                                               */
/* ------------------------------------------------------------------ */
async function adminLogin(page) {
  await page.goto(`${ENV.base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', ENV.user);
  await page.fill('#user_pass', ENV.pass);
  await Promise.all([page.waitForURL(/wp-admin/, { timeout: 30000 }), page.click('#wp-submit')]);
}

/** Is the plugin active and does a PHP class exist? (used to skip scenarios for modules still being built). */
function phpClassExists(cls) {
  try {
    return wp(['eval', `echo class_exists(${JSON.stringify(cls)}) ? 'yes' : 'no';`]).endsWith('yes');
  } catch (e) { return false; }
}

module.exports = {
  ENV, PROJECT_DIR, REF_DIR, FIXTURES, E2E_DIR,
  Scenario, state, launch, newPage, shot, axe, money, studio, restQuote, cart, checkout, adminLogin, wp, wpEvalJson, phpClassExists, BILLING,
};
