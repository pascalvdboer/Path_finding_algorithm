'use strict';
/**
 * E2E 10 — error paths of the public REST API (P01), API level (no studio UI, which is being rebuilt):
 *  missing/forged token (403), rate limit (429, IPv6 /64), too-big original (413), body over post_max_size (413 on a
 *  second php -S with post_max_size=1M, not a misleading design error), preview > 8 MB (413), garbage PNG, SVG with
 *  script/onload (stored sanitised), simulated full disk (507), design token in a header, quote without consent,
 *  the bps_design_emailed action, and re-ordering an ordered design via /cart (clone, when Designs::clone exists).
 *  After every refused upload no file may be left in uploads/patch-studio.
 */
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const { ENV, E2E_DIR, Scenario, launch, wp, wpEvalJson } = require('./lib/harness.cjs');
const { API, png, DESIGN, ensureMuHooks, setTestOption, token, createDesign, json, storedFiles } = require('../security/e2e-helpers.cjs');

const SMALL_PORT = Number(process.env.BPS_WP_PORT || 8899) + 13;
const SMALL_BASE = `http://${process.env.BPS_WP_HOST || '127.0.0.1'}:${SMALL_PORT}`;

/** Second server on the same site with post_max_size=1M (php -S cannot change it per request). */
async function startSmallServer() {
  const proc = spawn('php', ['-d', 'post_max_size=1M', '-d', 'upload_max_filesize=1M', '-d', 'display_errors=0', '-d', 'log_errors=1',
    '-S', `127.0.0.1:${SMALL_PORT}`, '-t', ENV.site, path.join(E2E_DIR, 'lib', 'router.php')], { stdio: 'ignore', detached: false });
  for (let i = 0; i < 50; i++) {
    await new Promise((r) => setTimeout(r, 200));
    try {
      const res = await fetch(`${SMALL_BASE}/wp-json/patch-studio/v1/token`);
      if (res.ok) return proc;
    } catch (e) { /* not up yet */ }
  }
  proc.kill();
  return null;
}

const t = new Scenario('10-api-errors');
t.run(async (keep) => {
  const browser = await launch();
  keep(browser);
  t.ok(ensureMuHooks(), 'P01 test hooks loaded (tests/e2e/mu/p01-test-hooks.php)');
  const salt = Math.floor(Math.random() * 4095).toString(16); // 'a' + ≤ 3 hex digits = one valid IPv6 group
  setTestOption({ ip: true, record: true, rate: { designs: 2, quotes: 50, emails: 50 } });
  let small = null;
  try {
    const ctx = await browser.newContext();
    const req = ctx.request;
    const tok = await token(req);
    const ipA = { 'X-BPS-Test-IP': `2001:db8:a${salt}:1::1` };

    // 1. Token errors.
    let r = await createDesign(req, null, ipA);
    t.ok(r.status === 403 && r.body.code === 'bps_bad_token' && /sessie/.test(r.body.message), 'POST /designs without token → 403 bps_bad_token (NL message)', r);
    r = await createDesign(req, 'forged0000', ipA);
    t.ok(r.status === 403 && r.body.code === 'bps_bad_token', 'POST /designs with a forged token → 403 bps_bad_token', r);

    // 2. Rate limit (designs: 2 per hour for this test) with the IPv6 /64 key.
    const ok1 = await createDesign(req, tok, ipA);
    const ok2 = await createDesign(req, tok, { 'X-BPS-Test-IP': `2001:db8:a${salt}:1::2` });
    const third = await createDesign(req, tok, { 'X-BPS-Test-IP': `2001:db8:a${salt}:1:ffff::9` });
    t.ok(ok1.status === 201 && ok2.status === 201, 'two designs within the limit → 201', [ok1.status, ok2.status]);
    t.ok(third.status === 429 && third.body.code === 'bps_rate', 'third design from the same IPv6 /64 → 429 bps_rate', third);
    const other = await createDesign(req, tok, { 'X-BPS-Test-IP': `2001:db8:a${salt}:2::1` });
    t.ok(other.status === 201, 'another /64 has its own counter → 201', other.status);
    setTestOption({ ip: true, record: true, rate: { designs: 1000, quotes: 50, emails: 50 } });
    const ip = { 'X-BPS-Test-IP': `198.18.${parseInt(salt.slice(0, 2) || '1', 16) % 255}.10` };

    // 3. Original larger than the upload limit (lowered to 200 kB for the test) → 413, nothing stored.
    setTestOption({ ip: true, record: true, rate: { designs: 1000, quotes: 50, emails: 50 }, maxBytes: 200 * 1024 });
    let before = storedFiles();
    r = await createDesign(req, tok, ip, { original: { name: 'groot.pdf', mimeType: 'application/pdf', buffer: Buffer.concat([Buffer.from('%PDF-1.4\n'), Buffer.alloc(300 * 1024, 65)]) } });
    t.ok(r.status === 413 && r.body.code === 'bps_too_big' && /groter dan \d+ MB/.test(r.body.message), 'original > upload limit → 413 bps_too_big "groter dan … MB"', r);
    t.ok(JSON.stringify(storedFiles()) === JSON.stringify(before), 'no file left behind after 413');
    setTestOption({ ip: true, record: true, rate: { designs: 1000, quotes: 50, emails: 50 } });

    // 4. Preview > 8 MB → 413 (checked before decoding).
    before = storedFiles();
    r = await createDesign(req, tok, ip, { preview: { name: 'preview.png', mimeType: 'image/png', buffer: Buffer.concat([png(), Buffer.alloc(9 * 1024 * 1024)]) } });
    t.ok(r.status === 413 && r.body.code === 'bps_too_big', 'preview > 8 MB → 413 bps_too_big', r);
    t.ok(JSON.stringify(storedFiles()) === JSON.stringify(before), 'no file left behind after the big preview');

    // 5. Garbage PNG: as preview → 400, as original → 415.
    const garbage = Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), Buffer.from(Array.from({ length: 5000 }, (_, i) => (i * 37) % 256))]);
    r = await createDesign(req, tok, ip, { preview: { name: 'preview.png', mimeType: 'image/png', buffer: garbage } });
    t.ok(r.status === 400 && r.body.code === 'bps_invalid_design', 'PNG header + random bytes as preview → 400 bps_invalid_design', r);
    r = await createDesign(req, tok, ip, { original: { name: 'logo.png', mimeType: 'image/png', buffer: Buffer.from(Array.from({ length: 4000 }, (_, i) => (i * 91 + 7) % 256)) } });
    t.ok(r.status === 415 && r.body.code === 'bps_type', 'random bytes named logo.png as original → 415 bps_type', r);
    t.ok(JSON.stringify(storedFiles()) === JSON.stringify(before), 'no file left behind after the garbage uploads');

    // 6. SVG with script/onload → stored, but sanitised.
    const evilSvg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><style>@imp\\ort url(https://evil.example/a.css);</style><rect width="10" height="10" fill="u\\rl(https://evil.example/x)" onclick="alert(3)"/></svg>';
    r = await createDesign(req, tok, ip, { original: { name: 'logo.svg', mimeType: 'image/svg+xml', buffer: Buffer.from(evilSvg) } });
    t.ok(r.status === 201, 'SVG with script/onload accepted (sanitised)', r);
    if (r.status === 201) {
      const meta = wpEvalJson(`echo wp_json_encode( get_post_meta( ${r.body.designId}, '_bps_original', true ) );`);
      const stored = meta && meta.path && fs.existsSync(meta.path) ? fs.readFileSync(meta.path, 'utf8') : '';
      t.ok(stored.includes('<rect') && !/script|onload|onclick|evil\.example|\\/i.test(stored), 'stored SVG has no script, handlers, remote URLs or backslashes', stored.slice(0, 300));
    }

    // 7. Simulated full disk → 507 bps_storage, nothing stored.
    setTestOption({ ip: true, record: true, rate: { designs: 1000, quotes: 50, emails: 50 }, lowDisk: true });
    before = storedFiles();
    r = await createDesign(req, tok, ip, { original: { name: 'logo.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%test\n') } });
    t.ok(r.status === 507 && r.body.code === 'bps_storage' && /e-mail/.test(r.body.message), 'low disk space → 507 bps_storage with the e-mail alternative', r);
    t.ok(JSON.stringify(storedFiles()) === JSON.stringify(before), 'no file left behind after 507');
    setTestOption({ ip: true, record: true, rate: { designs: 1000, quotes: 50, emails: 50 } });

    // 8. Invalid payload → 400 with an NL field message.
    r = await createDesign(req, tok, ip, { design: JSON.stringify({ ...DESIGN, technique: 'hologram' }) });
    t.ok(r.status === 400 && r.body.code === 'bps_invalid_design' && /techniek/.test(r.body.message), 'unknown technique → 400 "techniek"', r);

    // 9. Body over post_max_size (second server, post_max_size=1M) → 413, not a design error.
    small = await startSmallServer();
    if (!small) {
      t.ok(false, `second php -S on port ${SMALL_PORT} did not start`);
    } else {
      const sctx = await browser.newContext();
      const stok = await token(sctx.request).catch(() => null);
      before = storedFiles();
      r = await createDesign(sctx.request, stok, {}, { original: { name: 'logo.pdf', mimeType: 'application/pdf', buffer: Buffer.concat([Buffer.from('%PDF-1.4\n'), Buffer.alloc(1536 * 1024, 66)]) } }, `${SMALL_BASE}/wp-json/patch-studio/v1`);
      t.ok(r.status === 413 && r.body && r.body.code === 'bps_too_big' && /groter dan \d+ MB/.test(r.body.message), 'body > post_max_size → 413 bps_too_big "groter dan … MB"', r);
      t.ok(JSON.stringify(storedFiles()) === JSON.stringify(before), 'no file left behind after the oversized body');
      await sctx.close();
    }

    // 10. Design token in a header (security-5).
    const d = ok1.body;
    r = await json(await req.get(`${API}/designs/${d.designId}`, { headers: { 'X-BPS-Design-Token': d.token } }));
    t.ok(r.status === 200 && r.body.ref === d.ref, 'GET /designs/{id} with X-BPS-Design-Token and no ?token= → 200', r.status);
    r = await json(await req.get(`${API}/designs/${d.designId}`, { headers: { 'X-BPS-Design-Token': 'f'.repeat(64) } }));
    t.ok(r.status === 403 && r.body.code === 'bps_forbidden', 'wrong header token → 403 bps_forbidden', r);

    // 11. Quote request without consent (art. 6.1.b) → 200; with consent → 200.
    const quote = { name: 'Test P01', email: 'p01-offerte@example.test', qty: 25, technique: 'woven', locale: 'nl-BE' };
    r = await json(await req.post(`${API}/quotes`, { headers: { 'X-BPS-Token': tok, 'Content-Type': 'application/json', ...ip }, data: quote }));
    t.ok(r.status === 200 && r.body.ok && /^OF-/.test(r.body.ref), 'POST /quotes without consent → 200', r);
    r = await json(await req.post(`${API}/quotes`, { headers: { 'X-BPS-Token': tok, 'Content-Type': 'application/json', ...ip }, data: { ...quote, consent: true } }));
    t.ok(r.status === 200 && r.body.ok, 'POST /quotes with consent=true → 200', r.status);

    // 12. Save & mail fires bps_design_emailed (contract C4).
    try { wp(['option', 'delete', 'bps_p01_emailed']); } catch (e) { /* not set */ }
    r = await json(await req.post(`${API}/designs/${ok2.body.designId}/email`, { headers: { 'X-BPS-Token': tok, 'Content-Type': 'application/json', ...ip }, data: { token: ok2.body.token, email: 'p01-mail@example.test' } }));
    t.ok(r.status === 200 && r.body.ok, 'POST /designs/{id}/email → 200', r);
    const emailed = wpEvalJson('echo wp_json_encode( get_option( "bps_p01_emailed", array() ) );');
    t.ok(emailed && emailed.id === ok2.body.designId && emailed.email === 'p01-mail@example.test', 'action bps_design_emailed fired with id + e-mail', emailed);

    // 13. Re-order an ordered design via /cart (security-4): clone when Designs::clone exists.
    const opts = wpEvalJson('echo wp_json_encode( \\BorduurPatchStudio\\Settings::default_options( \\BorduurPatchStudio\\Settings::get(), "embroidered" ) );');
    const created = await createDesign(req, tok, ip, { design: JSON.stringify({ ...DESIGN, options: opts }) });
    t.ok(created.status === 201, 'design with the default options for the re-order case', created);
    const ordered = created.body;
    wp(['eval', `update_post_meta( ${ordered.designId}, '_bps_status', 'ordered' ); update_post_meta( ${ordered.designId}, '_bps_order_id', 999999 );`]);
    const hasClone = wp(['eval', 'echo method_exists( "\\\\BorduurPatchStudio\\\\Woo\\\\Designs", "clone" ) ? "yes" : "no";']).endsWith('yes');
    r = await json(await req.post(`${API}/cart`, { headers: { 'X-BPS-Token': tok, 'Content-Type': 'application/json', ...ip }, data: { designId: ordered.designId, token: ordered.token, rightsConsent: { accepted: true, v: '1' } } }));
    if (hasClone) {
      t.ok(r.status === 200 && r.body.cloned === true && r.body.designId !== ordered.designId && r.body.ref !== ordered.ref && /^[a-f0-9]{64}$/.test(r.body.token || ''), 'ordered design → /cart answers with a clone (new designId, ref, token)', r);
      const orig = wpEvalJson(`echo wp_json_encode( array( get_post_meta( ${ordered.designId}, '_bps_status', true ), (int) get_post_meta( ${ordered.designId}, '_bps_order_id', true ) ) );`);
      t.ok(orig[0] === 'ordered' && orig[1] === 999999, 'the original keeps its order link and status', orig);
    } else {
      t.ok(r.status === 200 && r.body.ok, '/cart still works for an ordered design (clone pending: Designs::clone from P06 not present yet)', r);
      t.info('Designs::clone() not available yet: clone assertions skipped');
    }
    await ctx.close();
  } finally {
    if (small) small.kill();
    setTestOption(null);
  }
});
