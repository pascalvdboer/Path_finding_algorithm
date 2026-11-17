#!/usr/bin/env node
/**
 * Patch Studio build (eigenaar: architect). Zie docs/SPEC.md §5.
 *   node scripts/build.mjs            productie (minified, IIFE)
 *   node scripts/build.mjs --dev      met sourcemaps, niet geminificeerd
 *   node scripts/build.mjs --watch    herbouwen bij wijziging
 *   node scripts/build.mjs --size-only enkel gzip-groottes van dist tonen
 * Entry points die (nog) niet bestaan worden overgeslagen, zodat bouwers parallel kunnen werken.
 */
import { existsSync, readFileSync, mkdirSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const plugin = resolve(root, 'plugin/borduur-patch-studio');
const src = resolve(plugin, 'assets/src');
const dist = resolve(plugin, 'assets/dist');
const args = new Set(process.argv.slice(2));
const dev = args.has('--dev') || args.has('--watch');

/** Budgetten in bytes gzip (SPEC §5.3; patch-studio.js 60 → 100 kB door integrator, zie docs/integratie.md) */
const BUDGET = { 'patch-studio.js': 100 * 1024, 'patch-studio-worker.js': 25 * 1024, 'patch-studio-admin.js': 15 * 1024 };

const entries = [
  { in: 'main.js', out: 'patch-studio.js', globalName: 'BPS' },
  { in: 'analysis/worker.js', out: 'patch-studio-worker.js', globalName: undefined },
  { in: 'admin/index.js', out: 'patch-studio-admin.js', globalName: 'BPSAdmin' },
];

function sizes() {
  let fail = false;
  for (const name of Object.keys(BUDGET)) {
    const f = resolve(dist, name);
    if (!existsSync(f)) { console.log(`  ${name.padEnd(26)} (niet gebouwd)`); continue; }
    const raw = readFileSync(f);
    const gz = gzipSync(raw, { level: 9 }).length;
    const ok = gz <= BUDGET[name];
    if (!ok) fail = true;
    console.log(`  ${name.padEnd(26)} ${(raw.length / 1024).toFixed(1).padStart(7)} kB  gzip ${(gz / 1024).toFixed(1).padStart(6)} kB  budget ${(BUDGET[name] / 1024).toFixed(0)} kB ${ok ? 'OK' : 'TE GROOT'}`);
  }
  return !fail;
}

if (args.has('--size-only')) process.exit(sizes() ? 0 : 1);

let esbuild;
try {
  esbuild = await import('esbuild');
} catch {
  console.error('esbuild ontbreekt: voer eerst `npm install` uit in ' + root);
  process.exit(1);
}

mkdirSync(dist, { recursive: true });
const common = {
  bundle: true,
  format: 'iife',
  target: ['es2019', 'safari14'],
  minify: !dev,
  sourcemap: dev ? 'linked' : false,
  legalComments: 'none',
  loader: { '.json': 'json' },
  define: { 'process.env.NODE_ENV': JSON.stringify(dev ? 'development' : 'production') },
  logLevel: 'info',
};

const builds = entries.filter((e) => existsSync(resolve(src, e.in)));
for (const e of entries) if (!builds.includes(e)) console.log(`(overgeslagen: assets/src/${e.in} bestaat nog niet)`);

if (args.has('--watch')) {
  for (const e of builds) {
    const ctx = await esbuild.context({ ...common, entryPoints: [resolve(src, e.in)], outfile: resolve(dist, e.out), globalName: e.globalName });
    await ctx.watch();
  }
  console.log('watching…');
} else {
  for (const e of builds) {
    await esbuild.build({ ...common, entryPoints: [resolve(src, e.in)], outfile: resolve(dist, e.out), globalName: e.globalName });
  }
  console.log('\nGroottes:');
  const ok = sizes();
  if (!ok && !dev) {
    console.error('\nBudget overschreden (SPEC §5.3).');
    process.exit(2);
  }
}
