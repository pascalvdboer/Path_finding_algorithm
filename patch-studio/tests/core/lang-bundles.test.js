/**
 * Language bundles (prestatie-4): scripts/build.mjs keeps only one language per messages.js catalogue. Checks every
 * catalogue rewrite against the original module and that the NL/FR studio bundle is clearly smaller.
 * Run: npm test
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { gzipSync } from 'node:zlib';
import { fileURLToPath } from 'node:url';
import { stripLanguage, langPlugin, LANGS, BUDGET } from '../../scripts/build.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const src = path.join(root, 'plugin/borduur-patch-studio/assets/src');
const catalogues = fs.readdirSync(src, { withFileTypes: true })
  .filter((d) => d.isDirectory() && fs.existsSync(path.join(src, d.name, 'messages.js')))
  .map((d) => path.join(src, d.name, 'messages.js'));
const load = (code) => import('data:text/javascript;base64,' + Buffer.from(code).toString('base64'));

test('build.mjs: twee taalbundels met budget', () => {
  assert.deepEqual(LANGS, ['nl', 'fr']);
  assert.ok(BUDGET['nl/patch-studio.js'] && BUDGET['fr/patch-studio.js'], 'budget per taal');
  assert.ok(BUDGET['nl/patch-studio.js'] < BUDGET['patch-studio.js'], 'taalbudget kleiner dan het tweetalige');
});

for (const file of catalogues) {
  const name = path.relative(src, file);
  test(`taalbundel: ${name} correct ingekort voor NL en FR`, async () => {
    const original = await load(fs.readFileSync(file, 'utf8'));
    const cats = Object.entries(original).filter(([, v]) => v && typeof v === 'object' && 'nl' in v && 'fr' in v);
    for (const lang of LANGS) {
      const r = await stripLanguage(file, lang);
      assert.equal(r.verified, true, `${name} (${lang}) werd niet ingekort`);
      assert.ok(r.saved > 0, `${name} (${lang}) wordt kleiner`);
      const mod = await load(r.contents);
      for (const [exp, cat] of cats) {
        const got = mod[exp];
        if (Array.isArray(cat.nl)) continue;
        for (const key of Object.keys(cat.nl)) {
          const want = lang === 'nl' ? cat.nl[key] : (cat.fr[key] ?? cat.nl[key]);
          const have = lang === 'nl' ? got.nl[key] : (got.fr[key] ?? got.nl[key]);
          assert.deepEqual(have, want, `${name} ${exp}.${key} (${lang})`);
        }
        const other = lang === 'nl' ? got.fr : got.nl;
        assert.equal(Object.keys(other).length, 0, `${name}: de andere taal is leeg in de ${lang}-bundel`);
      }
    }
  });
}

test('studio-bundel per taal ≥ 10 kB gzip kleiner dan de tweetalige (in-memory build)', async (t) => {
  let esbuild;
  try { esbuild = await import('esbuild'); } catch { t.skip('esbuild ontbreekt'); return; }
  const opts = { entryPoints: [path.join(src, 'main.js')], bundle: true, minify: true, format: 'iife', globalName: 'BPS', target: ['es2019', 'safari14'], write: false, logLevel: 'silent', loader: { '.json': 'json' } };
  const size = async (extra) => gzipSync((await esbuild.build({ ...opts, ...extra })).outputFiles[0].contents, { level: 9 }).length;
  const all = await size({});
  for (const lang of LANGS) {
    const one = await size({ plugins: [langPlugin(lang)] });
    assert.ok(all - one >= 10 * 1024, `${lang}: ${(all / 1024).toFixed(1)} → ${(one / 1024).toFixed(1)} kB gzip`);
  }
});
