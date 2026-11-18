/**
 * P06 designs-account: bridge that runs the PHP unit tests of the design domain (tests/woo/run-woo-php.php) when
 * PHP is available. The REST/WooCommerce integration suite (tests/woo/run-woo-wp.php) needs a running test site
 * and is started by tests/e2e/11-account.e2e.cjs. Run: npm test
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const plugin = path.join(root, 'plugin/borduur-patch-studio');

test('PHP: clone, previewvarianten, opslagplafond, revise, DST/PES, herinnering, account (run-woo-php.php)', (t) => {
  const probe = spawnSync('php', ['-v'], { encoding: 'utf8' });
  if (probe.status !== 0) {
    t.skip('php niet gevonden');
    return;
  }
  const res = spawnSync('php', [path.join(here, 'run-woo-php.php')], { encoding: 'utf8' });
  assert.equal(res.status, 0, `${res.stdout}\n${res.stderr}`);
  const m = /(\d+)\/\1 ok/.exec(res.stdout);
  assert.ok(m, res.stdout);
  assert.ok(Number(m[1]) >= 100, `minstens 100 checks (kreeg ${m && m[1]})`);
});

test('eerlijke klantteksten: herinneringsmail zonder valse urgentie of schaarste', () => {
  const forbidden = /\b(laatste kans|nog maar|vandaag nog|beperkt aanbod|haast je|dernière chance|offre limitée|plus que)\b|aftel/iu;
  for (const f of ['templates/emails/design-reminder.php', 'templates/emails/plain/design-reminder.php', 'includes/woo/emails/class-design-reminder.php', 'templates/myaccount/designs.php']) {
    const src = fs.readFileSync(path.join(plugin, f), 'utf8');
    assert.ok(!forbidden.test(src), `${f} bevat druktaal`);
  }
  const mail = fs.readFileSync(path.join(plugin, 'templates/emails/design-reminder.php'), 'utf8');
  assert.match(mail, /enige herinnering/, 'de mail zegt eerlijk dat het de enige herinnering is');
  assert.match(mail, /unsubscribe_url/, 'de mail heeft een afmeldlink');
});

test('integratiesuite en e2e 11 bestaan en dekken de acceptatiecriteria', () => {
  const wpSuite = fs.readFileSync(path.join(here, 'run-woo-wp.php'), 'utf8');
  const checks = (wpSuite.match(/p06_ok\(/g) || []).length - 1; // minus the definition
  assert.ok(checks >= 60, `run-woo-wp.php heeft ${checks} checks`);
  for (const needle of ['/revise', '/account/claim', '/account/designs', '/reorder', '/reminder/unsubscribe', "'/cart'", 'maxDraftStorageMb', 'clublogo.dst']) {
    assert.ok(wpSuite.includes(needle), `run-woo-wp.php test ${needle}`);
  }
  assert.ok(fs.existsSync(path.join(root, 'tests/e2e/11-account.e2e.cjs')));
});
