/**
 * Proof workflow (premium P05): bridge that runs the PHP tests (tests/proof/run-proof-php.php) when PHP is
 * available, plus static checks on the split of Order_Production. Run: npm test
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

test('Order_Production is opgesplitst: < 1200 regels, geen klassen in functies, geen tweede Franse catalogus', () => {
  const src = fs.readFileSync(path.join(plugin, 'includes/admin/class-order-production.php'), 'utf8');
  assert.ok(src.split('\n').length < 1200, `class-order-production.php telt ${src.split('\n').length} regels`);
  assert.ok(!/function\s+declare_emails/.test(src), 'declare_emails bestaat niet meer');
  assert.ok(!/const FR\s*=/.test(src), 'const FR is weg');
  for (const f of ['class-proof.php', 'class-proof-response.php', 'class-proof-reminder.php', 'class-proof-order.php']) {
    const e = fs.readFileSync(path.join(plugin, 'includes/woo/emails', f), 'utf8');
    assert.equal((e.match(/^class\s/mg) || []).length, 1, `${f}: één klasse`);
  }
  for (const f of fs.readdirSync(path.join(plugin, 'templates/admin'), { recursive: true }).filter((x) => x.endsWith('.php'))) {
    const t = fs.readFileSync(path.join(plugin, 'templates/admin', f), 'utf8');
    assert.ok(!/BPS_OP::l\(|Order_Production::l\(/.test(t), `${f}: geen BPS_OP::l() meer`);
  }
});

test('PHP: proeftokens, per-regel-opslag, migratie, dossier, herinneringen, 410, hele bestelling (run-proof-php.php)', (t) => {
  const probe = spawnSync('php', ['-v'], { encoding: 'utf8' });
  if (probe.status !== 0) {
    t.skip('php niet gevonden');
    return;
  }
  const res = spawnSync('php', [path.join(here, 'run-proof-php.php')], { encoding: 'utf8' });
  assert.equal(res.status, 0, `${res.stdout}\n${res.stderr}`);
  assert.match(res.stdout, /(\d+)\/\1 ok/);
});
