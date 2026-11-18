/**
 * Integrity tests for config/ai-prompts.json (prompt engine, docs/ai/02-prompting.md) and a bridge that runs the
 * PHP prompt-engine vectors (tests/ai/run-prompt-php.php) when PHP is available. Run: npm test
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
const cfg = JSON.parse(fs.readFileSync(path.join(plugin, 'config/ai-prompts.json'), 'utf8'));
const defaults = JSON.parse(fs.readFileSync(path.join(plugin, 'config/defaults.json'), 'utf8'));
const vectors = JSON.parse(fs.readFileSync(path.join(here, 'prompt-vectors.json'), 'utf8'));

const keys = (o) => Object.keys(o).filter((k) => !k.startsWith('_'));
const noDoc = (o) => JSON.parse(JSON.stringify(o, (k, v) => (k.startsWith('_') ? undefined : v)));
const bilingual = (o, where) => {
  assert.ok(o && typeof o.nl === 'string' && o.nl.length > 0, `${where}: nl ontbreekt`);
  assert.ok(typeof o.fr === 'string' && o.fr.length > 0, `${where}: fr ontbreekt`);
};

test('7 stijlen, elk met nl/fr-label en -uitleg, Engels fragment en geldige standaarden', () => {
  const ids = Object.keys(cfg.styles);
  assert.deepEqual(ids.sort(), ['badge', 'club', 'mascot', 'military', 'minimal', 'typographic', 'vintage']);
  for (const [id, s] of Object.entries(cfg.styles)) {
    bilingual(s.label, `styles.${id}.label`);
    bilingual(s.hint, `styles.${id}.hint`);
    assert.ok(typeof s.en === 'string' && s.en.length > 40, `styles.${id}.en`);
    assert.ok(cfg.shapes[s.shapeHint], `styles.${id}.shapeHint`);
    assert.ok(s.textPlacement in cfg.textPlacements, `styles.${id}.textPlacement`);
    assert.ok(s.defaultColors >= 1 && s.defaultColors <= cfg.limits.maxColorsAbsolute);
  }
});

test('vormen en technieken komen overeen met defaults.json', () => {
  assert.deepEqual(Object.keys(cfg.shapes).sort(), [...defaults.shapeOrder].sort());
  for (const s of Object.values(cfg.shapes)) assert.match(s.size, /^(1024x1024|1536x1024|1024x1536)$/);
  assert.deepEqual(keys(cfg.techniqueColors).sort(), [...defaults.techniqueOrder].sort());
  assert.deepEqual(keys(cfg.techniqueFragments).sort(), [...defaults.techniqueOrder].sort());
  for (const [t, c] of Object.entries(noDoc(cfg.techniqueColors))) {
    const prodMax = defaults.techniques[t].rules.colors.max;
    assert.ok(c.default <= c.max, `${t}: default > max`);
    if (prodMax != null) assert.ok(c.max <= prodMax, `${t}: AI-max ${c.max} boven productiemax ${prodMax}`);
  }
});

test('elke regex compileert (PCRE-subset die ook in JS geldig is)', () => {
  const s = cfg.safety;
  const all = [...s.copyIntent, ...s.officialInsignia, ...s.person, ...s.styleOfArtist, ...s.hate, ...s.adult,
    ...s.violence, ...s.drugs, ...Object.values(cfg.privacy.patterns),
    ...Object.values(noDoc(cfg.refineLocal)).filter((r) => r.patterns).flatMap((r) => r.patterns.filter((p) => p[0] !== '@'))];
  for (const re of all) assert.doesNotThrow(() => new RegExp(re, 'u'), re);
});

test('alle klantmeldingen bestaan in nl en fr', () => {
  for (const code of ['brand', 'official', 'person', 'artist', 'copy', 'hate', 'adult', 'violence', 'drugs', 'empty']) {
    bilingual(cfg.safety.messages[code], `safety.messages.${code}`);
  }
  for (const [k, r] of Object.entries(noDoc(cfg.refineLocal))) if (r.message) bilingual(r.message, `refineLocal.${k}`);
});

test('merkenlijsten: geen dubbels, geen dier/woord in de harde lijst dat ook in de woordenlijst staat', () => {
  const hard = cfg.safety.brandsHard.map((b) => b.toLowerCase());
  assert.equal(new Set(hard).size, hard.length, 'dubbel in brandsHard');
  const glossaryWords = new Set(Object.entries(cfg.glossary).filter(([k]) => k !== '_doc').flatMap(([, v]) => Object.keys(v)));
  for (const b of hard) assert.ok(!glossaryWords.has(b), `"${b}" staat hard geblokkeerd maar is ook een gewoon woord`);
});

test('regelblok bevat de kernregels en alle placeholders worden door de engine gevuld', () => {
  const joined = Object.values(noDoc(cfg.rules)).join(' ');
  for (const must of ['no gradients', 'photo-realism', 'at most {max_colors}', 'outlines', 'Centered', 'original', 'watermark']) {
    assert.ok(joined.includes(must), `regel mist "${must}"`);
  }
  const known = new Set(['shape_en', 'technique_fragment', 'max_colors', 'color_list', 'outline_pct', 'detail_pct',
    'shape_composition', 'margin_pct', 'background_rule', 'fabric_en', 'reserve_rule', 'placement', 'text', 'spelled',
    'font_kind', 'placement_ai', 'cap_pct', 'accent_note', 'change', 'keep_extra', 'history', 'rules_short', 'items']);
  const text = JSON.stringify(noDoc([cfg.rules, cfg.backgrounds, cfg.textModes, cfg.edit]));
  for (const m of text.matchAll(/\{([a-z_]+)\}/g)) assert.ok(known.has(m[1]), `onbekende placeholder {${m[1]}}`);
});

test('20 voorbeeldvertalingen, NL en FR gemengd', () => {
  assert.equal(vectors.translations.length, 20);
  const langs = new Set(vectors.translations.map((t) => t.input.lang));
  assert.deepEqual([...langs].sort(), ['fr', 'nl']);
});

test('PHP prompt-engine vectoren (tests/ai/run-prompt-php.php)', (t) => {
  const probe = spawnSync('php', ['-v'], { encoding: 'utf8' });
  if (probe.status !== 0) {
    t.skip('php niet beschikbaar');
    return;
  }
  const r = spawnSync('php', [path.join(here, 'run-prompt-php.php')], { encoding: 'utf8' });
  assert.equal(r.status, 0, r.stderr + r.stdout);
});
