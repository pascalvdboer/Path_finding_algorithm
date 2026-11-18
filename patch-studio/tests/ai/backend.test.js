/**
 * ai-backend: config integrity (ai-providers.json, ai-mock.json + SVGs), "no secrets anywhere", php -l on every
 * AI PHP file, and a bridge that runs the PHP backend tests (tests/ai/backend/run-backend-php.php) when PHP exists.
 * Run: npm test
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
const json = (rel) => JSON.parse(fs.readFileSync(path.join(plugin, rel), 'utf8'));
const hasPhp = spawnSync('php', ['-v']).status === 0;

const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
  const p = path.join(dir, e.name);
  return e.isDirectory() ? walk(p) : [p];
});

test('ai-providers.json: mock + openai (standaard) + recraft (terugval), prijzen niet geverifieerd, geen sleutels', () => {
  const cfg = json('config/ai-providers.json');
  assert.equal(cfg.schema, 1);
  assert.ok(cfg._doc);
  assert.equal(cfg.default, 'openai');
  assert.equal(cfg.fallback, 'recraft');
  for (const id of ['mock', 'openai', 'recraft']) assert.ok(cfg.providers[id], id);
  assert.equal(cfg.providers.mock.estUsdPerImage, 0);
  assert.equal(cfg.providers.openai.model, 'gpt-image-2');
  assert.ok(cfg.providers.openai.models.includes(cfg.providers.openai.model));
  assert.equal(cfg.providers.openai.moderation, 'auto');
  assert.equal(cfg.providers.openai.verified, false);
  assert.equal(cfg.providers.recraft.verified, false);
  assert.match(cfg.providers.openai.endpoint, /^https:\/\/api\.openai\.com\//);
  assert.match(cfg.providers.recraft.endpoint, /^https:\/\/[a-z.]*recraft\.ai\//);
  assert.ok(cfg.n >= 2 && cfg.n <= 4);
  assert.equal(cfg.moderation.model, 'omni-moderation-latest');
  const raw = JSON.stringify(cfg);
  assert.doesNotMatch(raw, /sk-[A-Za-z0-9]{8,}|"(api_?key|secret|token)"\s*:/i);
});

test('moderatie-categorieën verwijzen naar bestaande weigeringsmeldingen', () => {
  const map = json('config/ai-providers.json').moderation.categoryMap;
  const msgs = json('config/ai-prompts.json').safety.messages;
  for (const [cat, code] of Object.entries(map)) assert.ok(msgs[code], `${cat} → ${code}`);
});

test('ai-mock.json: 10 eigen sjablonen, trefwoorden nl/fr/en, borduurbare SVG (≤ 6 kleuren, transparant, geen script)', () => {
  const mock = json('config/ai-mock.json');
  assert.equal(mock.templates.length, 10);
  for (const t of mock.templates) {
    for (const l of ['en', 'nl', 'fr']) assert.ok(t.keywords[l]?.length, `${t.id}.keywords.${l}`);
    assert.ok(t.alt?.nl && t.alt?.fr, `${t.id}.alt`);
    const svg = fs.readFileSync(path.join(plugin, 'config/ai-mock', t.file), 'utf8');
    assert.match(svg, /^<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg" viewBox="0 0 1024 1024"/, t.file);
    assert.doesNotMatch(svg, /<script|on[a-z]+=|href=|<image|<text/i, `${t.file}: geen script/links/raster/tekst`);
    const colors = new Set(svg.match(/\{\{c[1-6]\}\}/g));
    assert.ok(colors.size >= 2 && colors.size <= 6, `${t.file}: ${colors.size} kleuren`);
    assert.doesNotMatch(svg, /#[0-9a-f]{3,6}\b/i, `${t.file}: enkel plaatshouders, geen vaste kleuren`);
    assert.doesNotMatch(svg, /<rect[^>]*width="1024"/, `${t.file}: transparante achtergrond`);
  }
  for (const hex of Object.values(mock.colorHex)) assert.match(hex, /^#[0-9a-f]{6}$/);
});

test('geen sleutels of klantgegevens in de AI-bron of config', () => {
  const files = [
    ...walk(path.join(plugin, 'includes/ai')),
    ...fs.readdirSync(path.join(plugin, 'config')).filter((f) => f.startsWith('ai-') && f.endsWith('.json')).map((f) => path.join(plugin, 'config', f)),
  ];
  for (const f of files) {
    const src = fs.readFileSync(f, 'utf8');
    assert.doesNotMatch(src, /sk-(proj|live|test)?-?[A-Za-z0-9]{20,}/, `${f}: lijkt een sleutel`);
  }
  const rest = fs.readFileSync(path.join(plugin, 'includes/ai/class-module.php'), 'utf8');
  assert.doesNotMatch(rest, /Secrets::get/, 'public config leest nooit sleutels');
});

test('php -l op elk AI-PHP-bestand', { skip: !hasPhp && 'php niet gevonden' }, () => {
  const files = walk(path.join(plugin, 'includes/ai')).filter((f) => f.endsWith('.php'));
  assert.ok(files.length >= 25, `${files.length} bestanden`);
  for (const f of files) {
    const r = spawnSync('php', ['-l', f], { encoding: 'utf8' });
    assert.equal(r.status, 0, `${f}\n${r.stdout}${r.stderr}`);
  }
});

test('PHP-backendtests (providers, jobs, limieten, kosten, opruiming, privacy, admin)', { skip: !hasPhp && 'php niet gevonden' }, () => {
  const r = spawnSync('php', [path.join(here, 'backend/run-backend-php.php')], { encoding: 'utf8', timeout: 120000 });
  assert.equal(r.status, 0, r.stdout + r.stderr);
  assert.match(r.stdout, / 0 mislukt/);
});
