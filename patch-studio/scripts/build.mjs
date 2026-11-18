#!/usr/bin/env node
/**
 * Patch Studio build (eigenaar: architect). Zie docs/SPEC.md §5.
 *   node scripts/build.mjs            productie (minified, IIFE)
 *   node scripts/build.mjs --dev      met sourcemaps, niet geminificeerd
 *   node scripts/build.mjs --watch    herbouwen bij wijziging
 *   node scripts/build.mjs --size-only enkel gzip-groottes van dist tonen
 * Entry points die (nog) niet bestaan worden overgeslagen, zodat bouwers parallel kunnen werken.
 *
 * Language bundles (prestatie-4): besides the bilingual patch-studio.js (demo, dev, fallback) the studio is built
 * once per language into assets/dist/{nl,fr}/patch-studio.js. An esbuild onLoad plugin rewrites every
 * `*\/messages.js` so it only carries the target language: for NL the FR catalogue becomes empty, for FR the FR
 * catalogue is completed with the NL text of missing keys (same fallback as shared/i18n.js) and the NL catalogue
 * becomes empty. The {nl, fr} shape stays, so createT works unchanged. Every rewrite is verified by importing the
 * original and the rewritten module and comparing what each language sees; on any mismatch the original source is
 * kept. No source file is changed. Plugin::app_bundle() picks the bundle per Settings::public_locale().
 * The file name stays patch-studio.js (in a language folder) so tools that look for "patch-studio.js" keep working.
 */
import { existsSync, readFileSync, mkdirSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { resolve, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const plugin = resolve(root, 'plugin/borduur-patch-studio');
const src = resolve(plugin, 'assets/src');
const dist = resolve(plugin, 'assets/dist');
const args = new Set(process.argv.slice(2));
const dev = args.has('--dev') || args.has('--watch');

/** Languages that get their own studio bundle. */
const LANGS = ['nl', 'fr'];

/**
 * Budgetten in bytes gzip (SPEC §5.3). patch-studio.js: 60 → 100 kB (integrator v1) → 125 kB (integrator v2).
 * Waarom 125: de v2-ontwerpstudio (canvas, panelen, assistent) en render v2 (belichte materialen, 4 kledingstukken,
 * techniekvergelijker; render ±19 → ±32 kB) brengen de bundel op ±119 kB. Ook met de FR-catalogi via config.strings
 * (±13 kB) blijft hij boven 100 kB. Het script laadt alleen op de pagina met de studio. Zie docs/integratie.md.
 * 125 → 135 kB (critique-1): Polyneon-kleurkiezer, startgalerij met sjablonen, tekstplaatsing en suggesties (±10 kB).
 * De volledige kleurenkaart (411 kleuren) zit NIET in de bundel: die laadt pas bij "Toon alle" (assets/data/polyneon.json).
 * Taalbundels (prestatie-4): ±13 kB gzip kleiner dan de tweetalige bundel (18-11: 131,4 → 117,8 NL / 118,9 FR);
 * budget 120 kB per taal. Het doel ≤ 110 kB vraagt dat render/advice lui laden via dynamische imports in
 * main.js/ui (verboden paden voor dit pakket: handoff premium-ui-needs.md).
 * Modulebundels: budgetten uit docs/handoff/extra-products-needs.md en ai-services-needs.md. AI 25 → 28 kB: de
 * AI-panelen groeien nog (18-11: 25,1 kB); tests/ai/frontend-bundle.test.js houdt de eigen, strengere grens.
 */
const BUDGET = {
  'patch-studio.js': 135 * 1024,
  'nl/patch-studio.js': 120 * 1024,
  'fr/patch-studio.js': 120 * 1024,
  'patch-studio-worker.js': 25 * 1024,
  'patch-studio-admin.js': 15 * 1024,
  'patch-studio-ai.js': 28 * 1024,
  'patch-studio-ai-mock.js': 40 * 1024,
  'patch-studio-nametape.js': 30 * 1024,
};

/**
 * Entries. `langs: true` = also build one bundle per language. Cross-sell has no entry yet on purpose: its bundle
 * switches the panel on (Crosssell\Module::bundle_exists()) and the studio does not open it yet (handoff 2.3).
 */
const entries = [
  { in: 'main.js', out: 'patch-studio.js', globalName: 'BPS', langs: true },
  { in: 'analysis/worker.js', out: 'patch-studio-worker.js', globalName: undefined },
  { in: 'admin/index.js', out: 'patch-studio-admin.js', globalName: 'BPSAdmin' },
  { in: 'ai/index.js', out: 'patch-studio-ai.js', globalName: 'BPSAI' },
  { in: 'ai/mock-api.js', out: 'patch-studio-ai-mock.js', globalName: 'BPSAIMock' },
  { in: 'nametape/index.js', out: 'patch-studio-nametape.js', globalName: 'BPSNametape' },
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

/* -------------------------------------------------------------------------
 * Language stripping (prestatie-4)
 * ---------------------------------------------------------------------- */

/** Index just after a string, template literal or comment starting at i (or i when there is none). */
function skipNonCode(s, i) {
  const c = s[i];
  const n = s[i + 1];
  if (c === '/' && n === '/') { const e = s.indexOf('\n', i); return e < 0 ? s.length : e; }
  if (c === '/' && n === '*') { const e = s.indexOf('*/', i + 2); return e < 0 ? s.length : e + 2; }
  if (c === '"' || c === "'") {
    let j = i + 1;
    while (j < s.length && s[j] !== c) { if (s[j] === '\\') j++; if (s[j] === '\n') break; j++; }
    return j + 1;
  }
  if (c === '`') {
    let j = i + 1;
    while (j < s.length && s[j] !== '`') {
      if (s[j] === '\\') { j += 2; continue; }
      if (s[j] === '$' && s[j + 1] === '{') { j = matchClose(s, j + 1) + 1; continue; }
      j++;
    }
    return j + 1;
  }
  return i;
}

/** Index of the bracket that closes the one at i ({ or [). */
function matchClose(s, i) {
  const open = s[i];
  const close = open === '{' ? '}' : ']';
  let depth = 0;
  let j = i;
  while (j < s.length) {
    const k = skipNonCode(s, j);
    if (k !== j) { j = k; continue; }
    const ch = s[j];
    if (ch === '{' || ch === '[') depth++;
    else if (ch === '}' || ch === ']') { depth--; if (depth === 0) return ch === close ? j : -1; }
    j++;
  }
  return -1;
}

/**
 * Find catalogue literals: properties `nl: {…}` / `fr: […]` (quoted keys too) and top-level `const nl = {…}`.
 * Returns [{lang, start, end, group}] where siblings share a group (parent object or top-level scope).
 */
function findCatalogues(s) {
  const found = [];
  const stack = [];
  let i = 0;
  while (i < s.length) {
    const k = skipNonCode(s, i);
    if (k !== i) {
      // A quoted key "nl": / 'fr':
      const q = s[i];
      if ((q === '"' || q === "'") && /^(nl|fr)$/.test(s.slice(i + 1, k - 1))) {
        const m = /^\s*:\s*/.exec(s.slice(k));
        if (m && (s[k + m[0].length] === '{' || s[k + m[0].length] === '[')) {
          const start = k + m[0].length;
          const end = matchClose(s, start);
          if (end > 0) found.push({ lang: s.slice(i + 1, k - 1), start, end: end + 1, group: stack.length ? stack[stack.length - 1] : 'top' });
        }
      }
      i = k;
      continue;
    }
    const ch = s[i];
    if (ch === '{' || ch === '[' || ch === '(') { stack.push(i); i++; continue; }
    if (ch === '}' || ch === ']' || ch === ')') { stack.pop(); i++; continue; }
    if (/[A-Za-z_$]/.test(ch) && !/[A-Za-z0-9_$.]/.test(s[i - 1] || '')) {
      const m = /^[A-Za-z_$][A-Za-z0-9_$]*/.exec(s.slice(i));
      const word = m[0];
      const rest = s.slice(i + word.length);
      if (word === 'nl' || word === 'fr') {
        const prop = /^\s*:\s*/.exec(rest);
        const prev = s.slice(Math.max(0, i - 12), i);
        const decl = /(?:const|let|var)\s+$/.test(prev) ? /^\s*=\s*/.exec(rest) : null;
        const m2 = prop || decl;
        if (m2) {
          const start = i + word.length + m2[0].length;
          if (s[start] === '{' || s[start] === '[') {
            const end = matchClose(s, start);
            if (end > 0) {
              const group = decl ? `decl:${stack.length}` : (stack.length ? stack[stack.length - 1] : 'top');
              found.push({ lang: word, start, end: end + 1, group });
            }
          }
        }
      }
      i += word.length;
      continue;
    }
    i++;
  }
  return found;
}

/** Evaluate a pure data literal (strings, numbers, nested objects/arrays). Throws when it is not pure data. */
function evalLiteral(text) {
  // eslint-disable-next-line no-new-func
  return Function('"use strict"; return (' + text + ');')();
}

const isPlain = (v) => v && typeof v === 'object' && !Array.isArray(v);

/** Rewrite a messages.js source for one language (no verification). */
function rewriteMessages(source, lang) {
  const cats = findCatalogues(source);
  const groups = new Map();
  for (const c of cats) {
    if (!groups.has(c.group)) groups.set(c.group, {});
    groups.get(c.group)[c.lang] = c;
  }
  const edits = [];
  for (const g of groups.values()) {
    if (!g.nl || !g.fr) continue;
    let nlVal, frVal;
    try { nlVal = evalLiteral(source.slice(g.nl.start, g.nl.end)); frVal = evalLiteral(source.slice(g.fr.start, g.fr.end)); } catch { continue; }
    const empty = (v) => (Array.isArray(v) ? '[]' : '{}');
    if (lang === 'nl') {
      edits.push({ start: g.fr.start, end: g.fr.end, text: empty(frVal) });
    } else {
      let filled = frVal;
      if (isPlain(nlVal) && isPlain(frVal)) {
        filled = { ...frVal };
        for (const [key, value] of Object.entries(nlVal)) if (!(key in filled)) filled[key] = value;
      }
      edits.push({ start: g.fr.start, end: g.fr.end, text: JSON.stringify(filled) });
      edits.push({ start: g.nl.start, end: g.nl.end, text: empty(nlVal) });
    }
  }
  edits.sort((a, b) => b.start - a.start);
  let out = source;
  for (const e of edits) out = out.slice(0, e.start) + e.text + out.slice(e.end);
  return { out, changed: edits.length > 0 };
}

/** What a language sees: every {nl, fr} pair projected to that language (FR falls back to NL per key). */
function project(value, lang, seen = new Set()) {
  if (typeof value === 'function') return `fn:${String(value)}`;
  if (!value || typeof value !== 'object') return value;
  if (seen.has(value)) return '[circular]';
  seen.add(value);
  if (Array.isArray(value)) return value.map((v) => project(v, lang, seen));
  if ('nl' in value && 'fr' in value) {
    const { nl, fr, ...rest } = value;
    let view;
    if (lang === 'nl') view = nl;
    else if (isPlain(nl) && isPlain(fr)) { view = { ...nl, ...fr }; }
    else view = Array.isArray(fr) && fr.length === 0 ? nl : fr;
    const restView = {};
    for (const k of Object.keys(rest).sort()) restView[k] = project(rest[k], lang, seen);
    return { view: project(view, lang, seen), rest: restView };
  }
  const out = {};
  for (const k of Object.keys(value).sort()) out[k] = project(value[k], lang, seen);
  return out;
}

const importSource = (code) => import('data:text/javascript;base64,' + Buffer.from(code).toString('base64'));
const stripCache = new Map();

/** Rewritten + verified source, or the original when anything does not match. */
async function stripLanguage(path, lang) {
  const source = readFileSync(path, 'utf8');
  const key = `${lang}\0${path}\0${source.length}\0${source}`;
  if (stripCache.has(key)) return stripCache.get(key);
  let result = { contents: source, saved: 0, verified: false };
  try {
    const { out, changed } = rewriteMessages(source, lang);
    if (changed) {
      const [a, b] = await Promise.all([importSource(source), importSource(out)]);
      const same = JSON.stringify(project({ ...a }, lang)) === JSON.stringify(project({ ...b }, lang));
      if (same) result = { contents: out, saved: source.length - out.length, verified: true };
      else console.warn(`  (taalbundel ${lang}: ${path.replace(src + '/', '')} niet ingekort, controle faalde)`);
    }
  } catch (err) {
    console.warn(`  (taalbundel ${lang}: ${path.replace(src + '/', '')} niet ingekort: ${err.message})`);
  }
  stripCache.set(key, result);
  return result;
}

/** esbuild plugin: keep only `lang` in every assets/src/**\/messages.js. */
function langPlugin(lang) {
  return {
    name: `bps-lang-${lang}`,
    setup(build) {
      build.onLoad({ filter: /[\\/]messages\.js$/ }, async (a) => {
        const r = await stripLanguage(a.path, lang);
        return { contents: r.contents, loader: 'js', resolveDir: dirname(a.path) };
      });
    },
  };
}

/** Exported for tests (tests/core/lang-bundles.test.js); importing this file does not build. */
export { rewriteMessages, findCatalogues, project, stripLanguage, langPlugin, LANGS, BUDGET };

async function main() {
  if (args.has('--size-only')) process.exit(sizes() ? 0 : 1);

  let esbuild;
  try {
    esbuild = await import('esbuild');
  } catch {
    console.error('esbuild ontbreekt: voer eerst `npm install` uit in ' + root);
    process.exit(1);
  }

  mkdirSync(dist, { recursive: true });
  for (const l of LANGS) mkdirSync(resolve(dist, l), { recursive: true });
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

  /** All esbuild option sets: one per entry plus one per language for `langs` entries. */
  const configs = [];
  for (const e of builds) {
    configs.push({ ...common, entryPoints: [resolve(src, e.in)], outfile: resolve(dist, e.out), globalName: e.globalName });
    if (e.langs) {
      for (const l of LANGS) {
        configs.push({ ...common, entryPoints: [resolve(src, e.in)], outfile: resolve(dist, l, e.out), globalName: e.globalName, plugins: [langPlugin(l)] });
      }
    }
  }

  if (args.has('--watch')) {
    for (const c of configs) {
      const ctx = await esbuild.context(c);
      await ctx.watch();
    }
    console.log('watching…');
  } else {
    for (const c of configs) {
      await esbuild.build(c);
    }
    console.log('\nGroottes:');
    const ok = sizes();
    if (!ok && !dev) {
      console.error('\nBudget overschreden (SPEC §5.3).');
      process.exit(2);
    }
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  await main();
}
