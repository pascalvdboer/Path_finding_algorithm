/**
 * Security regression tests (P01). Integrity of the SVG vector file + a bridge that runs the PHP suite
 * (tests/security/run-security-php.php: SVG sanitizer, Settings limits, Guard, tokens, uploads, payload, REST
 * error paths) when PHP is available. Run: npm test
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const vectors = JSON.parse(fs.readFileSync(path.join(here, 'svg-vectors.json'), 'utf8'));

test('svg-vectors.json: ≥ 30 unieke vectoren, elk met verboden inhoud', () => {
  assert.ok(vectors.vectors.length >= 30, `slechts ${vectors.vectors.length} vectoren`);
  const names = new Set();
  for (const v of vectors.vectors) {
    assert.ok(v.name && typeof v.svg === 'string' && v.svg.includes('<svg'), `vector zonder naam/svg: ${JSON.stringify(v).slice(0, 80)}`);
    assert.ok(!names.has(v.name), `dubbele naam: ${v.name}`);
    names.add(v.name);
    assert.ok(Array.isArray(v.mustNotContain) && v.mustNotContain.length > 0, `${v.name}: mustNotContain ontbreekt`);
  }
  const s6 = vectors.vectors.filter((v) => v.finding === 'security-6');
  assert.ok(s6.length >= 6, 'de vectoren van security-6 (CSS-escapes, string-URL-functies) staan erin');
});

test('legitieme SVG-fixtures hebben een gouden uitvoer', () => {
  const dir = path.join(here, 'fixtures');
  const inputs = fs.readdirSync(dir).filter((f) => f.startsWith('legit-') && f.endsWith('.svg') && !f.includes('.expected.'));
  assert.ok(inputs.length >= 8);
  for (const f of inputs) {
    assert.ok(fs.existsSync(path.join(dir, f.replace(/\.svg$/, '.expected.svg'))), `${f}: .expected.svg ontbreekt`);
  }
});

test('PHP: SVG, Settings-limieten, Guard, tokens, uploads, payload, REST-foutpaden (run-security-php.php)', (t) => {
  const probe = spawnSync('php', ['-v'], { encoding: 'utf8' });
  if (probe.status !== 0) {
    t.skip('php niet gevonden');
    return;
  }
  const res = spawnSync('php', [path.join(here, 'run-security-php.php')], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
  assert.equal(res.status, 0, `${res.stdout}\n${res.stderr}`);
  const m = /(\d+)\/(\d+) ok/.exec(res.stdout);
  assert.ok(m && m[1] === m[2], res.stdout);
  assert.ok(Number(m[2]) >= 40, `minstens 40 asserties (nu ${m[2]})`);
});
