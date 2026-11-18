/**
 * P04 woo-cart-pricing: bridge that runs the PHP tests in tests/woo-cart/run-woo-cart-php.php when PHP is available
 * (prices incl./excl. VAT, mini-cart pricing, pricing errors, reorders, rights record, custom-made notices, shipping
 * zone, VAT check, lead time, name variants, plurals).
 * Run: npm test
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));

test('PHP: btw-modi, mini-cart, prijsfouten, herbestellen, leverdatum, namenvarianten (run-woo-cart-php.php)', (t) => {
  const probe = spawnSync('php', ['-v'], { encoding: 'utf8' });
  if (probe.status !== 0) {
    t.skip('php niet gevonden');
    return;
  }
  const res = spawnSync('php', [path.join(here, 'run-woo-cart-php.php')], { encoding: 'utf8' });
  assert.equal(res.status, 0, `${res.stdout}\n${res.stderr}`);
  assert.match(res.stdout, /(\d+)\/\1 ok/);
});
