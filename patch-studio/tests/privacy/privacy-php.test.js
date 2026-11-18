/**
 * Privacy and retention (premium P03): bridge that runs the PHP tests in tests/privacy/run-privacy-php.php when PHP is
 * available, plus static checks on the name-tape UI texts. Run: npm test
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

test('naamstroken: bloedgroep-toestemming in NL en FR, geen vaste "bel of WhatsApp" meer', async () => {
  const { default: messages } = await import(path.join(plugin, 'assets/src/nametape/messages.js'));
  for (const lang of ['nl', 'fr']) {
    for (const k of ['nt.s3.bloodConsent', 'nt.s3.bloodConsentHint', 'nt.s3.needBloodConsent', 'nt.error.generic']) {
      assert.ok(typeof messages[lang][k] === 'string' && messages[lang][k].trim(), `${lang} mist ${k}`);
    }
    assert.ok(!/whatsapp/i.test(JSON.stringify(messages[lang])), `${lang}: vaste WhatsApp-verwijzing`);
  }
  assert.match(messages.nl['nt.s3.bloodConsent'], /toestemming/);
  assert.match(messages.fr['nt.s3.bloodConsent'], /accord/);
  const php = fs.readFileSync(path.join(plugin, 'includes/nametape/class-privacy.php'), 'utf8');
  // The UI fallback text must equal the server text (the server hashes its own copy into the consent record).
  assert.ok(php.includes(messages.nl['nt.s3.bloodConsent']), 'NL-tekst gelijk aan de server');
  assert.ok(php.includes(messages.fr['nt.s3.bloodConsent']), 'FR-tekst gelijk aan de server');
});

test('PHP: AVG-export/-wissing, bewaartermijnen, naamstroken (run-privacy-php.php)', (t) => {
  const probe = spawnSync('php', ['-v'], { encoding: 'utf8' });
  if (probe.status !== 0) {
    t.skip('php niet gevonden');
    return;
  }
  const res = spawnSync('php', [path.join(here, 'run-privacy-php.php')], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
  assert.equal(res.status, 0, `${res.stdout}\n${res.stderr}`);
  const m = res.stdout.match(/(\d+)\/(\d+) ok/);
  assert.ok(m && m[1] === m[2], res.stdout);
  assert.ok(Number(m[2]) >= 40, `minstens 40 privacychecks, nu ${m && m[2]}`);
});
