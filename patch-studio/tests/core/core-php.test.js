/**
 * Core lifecycle (P02): bridge that runs the PHP tests in tests/core/run-core-php.php when PHP is available.
 * upload_max_filesize=2M makes the Site Health upload test give its recommendation (maxUploadMb = 25).
 * Run: npm test
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));

test('PHP: Logger, modules, owned_data + uninstall, upgrades, WC-versie, licentie, statusrapport, cache-purge (run-core-php.php)', (t) => {
  const probe = spawnSync('php', ['-v'], { encoding: 'utf8' });
  if (probe.status !== 0) {
    t.skip('php niet gevonden');
    return;
  }
  const res = spawnSync('php', ['-d', 'upload_max_filesize=2M', '-d', 'post_max_size=8M', path.join(here, 'run-core-php.php')], { encoding: 'utf8' });
  assert.equal(res.status, 0, `${res.stdout}\n${res.stderr}`);
  assert.match(res.stdout, /(\d+)\/\1 ok/);
});
