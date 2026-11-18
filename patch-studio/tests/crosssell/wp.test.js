// Cross-sell: WordPress-laag (Module, Cart, Service, Products) via php tests/crosssell/run-wp.php met stubs.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const php = spawnSync('php', ['-v']);
test('WordPress-laag: hooks, mandprijs = kaartprijs, fees nooit dubbel', { skip: php.status !== 0 && 'php ontbreekt' }, () => {
  const r = spawnSync('php', [fileURLToPath(new URL('./run-wp.php', import.meta.url))], { encoding: 'utf8' });
  assert.equal(r.status, 0, r.stderr + r.stdout);
  assert.match(r.stdout, /alle \d+ controles in orde/);
});
