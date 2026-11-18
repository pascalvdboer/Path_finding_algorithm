'use strict';
/**
 * E2E 19 — privacy and retention (P03) on the real WordPress + WooCommerce test site:
 *  runs tests/premium/P03 privacy-retention/wp-smoke.php inside WordPress (eraser registry, name-tape retention via
 *  the real order-itemmeta SQL, failed order → design back to "carted", quote anonymisation by Cleanup::run, eraser,
 *  user deletion, AI owner retention SQL) and checks over HTTP that GET /ai/status never sets the bps_ai_sid cookie.
 *  The smoke script deletes everything it creates.
 */
const path = require('path');
const { ENV, Scenario, wp, PROJECT_DIR } = require('./lib/harness.cjs');

const t = new Scenario('19-privacy');
t.run(async () => {
  const script = path.join(PROJECT_DIR, 'tests/premium/P03 privacy-retention/wp-smoke.php');
  let out = '';
  try {
    out = wp(['eval-file', script]);
  } catch (e) {
    out = String((e && (e.stdout || e.message)) || e);
  }
  const lines = out.split('\n').map((l) => l.trim()).filter(Boolean);
  for (const l of lines) {
    if (l.startsWith('ok ')) t.ok(true, l.slice(3).trim());
    else if (l.startsWith('FAIL ')) t.ok(false, l.slice(5).trim());
    else if (l.startsWith('skip ')) t.info(l);
  }
  const sum = out.match(/(\d+)\/(\d+) ok/);
  t.ok(sum && sum[1] === sum[2] && Number(sum[2]) >= 15, `smoke-script: ${sum ? sum[0] : 'geen samenvatting'}`, sum ? undefined : out.slice(-600));

  // gdpr-juridisch-11: a status read without cookies never sets the AI cookie (route only exists with the AI module on).
  const res = await fetch(`${ENV.base}/wp-json/patch-studio/v1/ai/status`, { headers: { Accept: 'application/json' } });
  const cookie = res.headers.get('set-cookie') || '';
  if (404 === res.status) t.info('AI-module staat uit: /ai/status bestaat niet (geen cookie mogelijk)');
  t.ok(!/bps_ai_sid=/.test(cookie), `GET /ai/status zonder cookies zet geen bps_ai_sid (HTTP ${res.status})`, cookie);
});
