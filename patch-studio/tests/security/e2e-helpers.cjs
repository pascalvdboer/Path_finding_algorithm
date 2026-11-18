'use strict';
/**
 * Helpers for the P01 e2e scenarios 09-security and 10-api-errors (tests/e2e/lib belongs to P13, so the
 * P01-specific bits live here). API-level only: Playwright request contexts, no studio UI.
 */
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const { ENV, E2E_DIR, wp } = require('../e2e/lib/harness.cjs');

const API = `${ENV.base}/wp-json/patch-studio/v1`;
const MU_SRC = path.join(E2E_DIR, 'mu', 'p01-test-hooks.php');
const MU_DST = path.join(ENV.site, 'wp-content', 'mu-plugins', 'bps-p01-test-hooks.php');

function crc32(buf) {
  let crc = 0xffffffff;
  for (let n = 0; n < buf.length; n++) {
    let c = (crc ^ buf[n]) & 0xff;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    crc = (crc >>> 8) ^ c;
  }
  return (crc ^ 0xffffffff) >>> 0;
}
function chunk(type, data) {
  const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
}
/** Solid RGB PNG (the server wants ≥ 600 px previews). */
function png(w = 600, h = 600) {
  const raw = Buffer.alloc((w * 3 + 1) * h);
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) raw.set([33, 169, 226], y * (w * 3 + 1) + 1 + x * 3);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
const DESIGN = { v: 1, technique: 'embroidered', shape: 'circle', sizeCm: 8, qty: 10, locale: 'nl-BE' };

function ensureMuHooks() {
  const loaded = () => { try { return wp(['eval', 'echo defined("BPS_P01_TEST_HOOKS") ? "yes" : "no";']).endsWith('yes'); } catch (e) { return false; } };
  if (!loaded()) {
    fs.mkdirSync(path.dirname(MU_DST), { recursive: true });
    fs.copyFileSync(MU_SRC, MU_DST);
  }
  return loaded();
}
function setTestOption(obj) {
  if (obj) wp(['option', 'update', 'bps_p01_test', JSON.stringify(obj), '--format=json']);
  else { try { wp(['option', 'delete', 'bps_p01_test']); } catch (e) { /* not set */ } }
}
async function token(request) {
  const r = await request.get(`${API}/token`);
  return (await r.json()).token;
}
/**
 * POST /designs (multipart). `parts` overrides/extends the default fields (design JSON + valid 600 px preview);
 * a part set to null is left out. `api` allows another server (e.g. one with a small post_max_size).
 */
async function createDesign(request, tok, headers = {}, parts = {}, api = API) {
  const multipart = { design: JSON.stringify(DESIGN), preview: { name: 'preview.png', mimeType: 'image/png', buffer: png() }, ...parts };
  for (const k of Object.keys(multipart)) if (multipart[k] === null) delete multipart[k];
  const h = { ...headers };
  if (tok) h['X-BPS-Token'] = tok;
  const r = await request.post(`${api}/designs`, { headers: h, multipart, timeout: 120000 });
  return { status: r.status(), body: await r.json().catch(() => null) };
}
const json = async (r) => ({ status: r.status(), body: await r.json().catch(() => null) });

function ensureCustomer() {
  try {
    wp(['user', 'create', 'p01klant', 'p01klant@example.test', '--role=customer', '--user_pass=p01-klant-2026']);
  } catch (e) {
    if (!/exist|already|bestaat/i.test(String((e && e.stderr) || (e && e.message) || ''))) throw e;
  }
}

/** Files stored by Patch Studio (originals + previews), without the protection files. */
function storedFiles() {
  const base = path.join(ENV.site, 'wp-content/uploads/patch-studio');
  const out = [];
  const walk = (d) => {
    if (!fs.existsSync(d)) return;
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) walk(p);
      else if (!['.htaccess', 'index.php', 'web.config'].includes(e.name)) out.push(p);
    }
  };
  walk(base);
  return out.sort();
}

module.exports = { API, png, DESIGN, ensureMuHooks, setTestOption, token, createDesign, json, ensureCustomer, storedFiles, MU_SRC, MU_DST };
