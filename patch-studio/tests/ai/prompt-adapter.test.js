/**
 * Prompt_Adapter + prompt data contract tests (SPEC-AI-DIENSTEN §7, §10.3, §6.4) and a bridge that runs the PHP
 * adapter tests (tests/ai/run-prompt-adapter-php.php) when PHP is available. Run: npm test
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
const mock = JSON.parse(fs.readFileSync(path.join(plugin, 'config/ai-mock.json'), 'utf8'));
const defaults = JSON.parse(fs.readFileSync(path.join(plugin, 'config/defaults.json'), 'utf8'));

const entries = (o) => Object.entries(o).filter(([k]) => !k.startsWith('_'));
const word = (kw) => new RegExp(`\\b${kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`);
const bilingual = (o, where) => {
  assert.ok(o && typeof o.nl === 'string' && o.nl.length > 0, `${where}: nl ontbreekt`);
  assert.ok(typeof o.fr === 'string' && o.fr.length > 0, `${where}: fr ontbreekt`);
};

// SPEC §10.3, event bps:ai:local:action.
const STUDIO_ACTIONS = ['open_text', 'open_colors', 'reduce_colors', 'open_size', 'open_shape', 'open_arrange'];

test('refine: elke lokale actie is een studio-actie uit SPEC §10.3', () => {
  for (const [k, r] of entries(cfg.refineLocal)) {
    assert.ok(STUDIO_ACTIONS.includes(r.action), `refineLocal.${k}.action = ${r.action}`);
  }
  const php = fs.readFileSync(path.join(plugin, 'includes/ai/class-prompt-adapter.php'), 'utf8');
  const m = php.match(/const LOCAL_ACTIONS = array\(([^)]*)\)/);
  assert.ok(m, 'LOCAL_ACTIONS ontbreekt in Prompt_Adapter');
  assert.deepEqual([...m[1].matchAll(/'([a-z_]+)'/g)].map((x) => x[1]), STUDIO_ACTIONS);
});

test('regexen die op genormaliseerde tekst draaien, bevatten geen accenten', () => {
  // Prompt_Engine::normalize() haalt accenten weg: "écusson" in een patroon matcht dus nooit.
  const s = cfg.safety;
  const lists = { copyIntent: s.copyIntent, officialInsignia: s.officialInsignia, person: s.person,
    styleOfArtist: s.styleOfArtist, hate: s.hate, adult: s.adult, violence: s.violence, drugs: s.drugs };
  for (const [k, r] of entries(cfg.refineLocal)) lists[`refineLocal.${k}`] = (r.patterns || []).filter((p) => p[0] !== '@');
  for (const [where, list] of Object.entries(lists)) {
    for (const re of list) {
      const stripped = re.replace(/armée/g, ''); // historisch dubbel met "armee", onschadelijk
      assert.ok(!/[^\x00-\x7f]/.test(stripped), `${where}: accent in ${re}`);
    }
  }
});

test('colorNames: elke Engelse kleur uit de woordenlijst heeft nl/fr-label en hex, gelijk aan de mock', () => {
  const colors = new Set(entries(cfg.glossary.color).map(([, v]) => v));
  for (const c of [...colors, ...entries(cfg.fabricColors).map(([, v]) => v)]) {
    const n = cfg.colorNames[c];
    assert.ok(n, `colorNames["${c}"] ontbreekt`);
    bilingual(n, `colorNames.${c}`);
    assert.match(n.hex, /^#[0-9a-f]{6}$/);
  }
  for (const [c, n] of entries(cfg.colorNames)) {
    assert.ok(mock.colorHex[c], `ai-mock.json colorHex["${c}"] ontbreekt (Jobs::colors_hex gebruikt die)`);
    assert.equal(mock.colorHex[c].toLowerCase(), n.hex, `hex van ${c} verschilt tussen ai-prompts en ai-mock`);
  }
});

test('understoodLabels: alle vormen en meldingen in nl en fr, FR in "vous"', () => {
  const ul = cfg.understoodLabels;
  for (const s of defaults.shapeOrder) bilingual(ul.shapes[s], `understoodLabels.shapes.${s}`);
  for (const k of ['stylePrefix', 'colorsJoin', 'maxColors', 'maxColorsOne', 'textAi', 'textStudio', 'textReserve',
    'subjectFallback', 'typographic', 'alt']) bilingual(ul[k], `understoodLabels.${k}`);
  assert.ok(ul.maxColors.nl.includes('{n}') && ul.maxColors.fr.includes('{n}'));
  assert.ok(ul.alt.nl.includes('{i}') && ul.alt.fr.includes('{summary}'));
  for (const code of ['personal_data_removed', 'text_too_long_for_ai', 'ai_text_missing', 'too_many_colors', 'text_small_for_size']) {
    bilingual(ul.warnings[code], `understoodLabels.warnings.${code}`);
  }
  const fr = JSON.stringify(Object.values(ul.warnings).map((w) => w.fr));
  assert.ok(!/\b(tu|ton|ta|tes|toi)\b/i.test(fr), 'FR moet "vous" gebruiken');
  const nl = JSON.stringify(Object.values(ul.warnings).map((w) => w.nl));
  assert.ok(!/\b(u|uw)\b/.test(nl), 'NL moet "je" gebruiken');
});

test('fabricAliases: elke stofkleur van de studio komt uit op een fabricColors-sleutel', () => {
  for (const f of defaults.fabrics) {
    const key = cfg.fabricColors[f.id] ? f.id : cfg.fabricAliases[f.id];
    assert.ok(key && cfg.fabricColors[key], `studiostof "${f.id}" heeft geen fabricColors-sleutel of alias`);
  }
});

test('mock-trefwoorden volgen de Engelse woordenlijst (SPEC §7 taak 3)', () => {
  const english = new Set(['subject', 'object', 'group', 'adj'].flatMap((c) => entries(cfg.glossary[c]).map(([, v]) => v)));
  const keywords = mock.templates.flatMap((t) => t.keywords.en.map((kw) => [t.id, kw]));
  for (const [id, kw] of keywords) {
    assert.ok([...english].some((v) => word(kw).test(v)), `ai-mock.json ${id}: trefwoord "${kw}" komt in geen enkele Engelse vertaling voor (dood trefwoord)`);
  }
  for (const [, subject] of entries(cfg.glossary.subject)) {
    assert.ok(keywords.some(([, kw]) => word(kw).test(subject)), `onderwerp "${subject}" valt op geen enkel mock-sjabloon`);
  }
});

test('PHP Prompt_Adapter (tests/ai/run-prompt-adapter-php.php)', (t) => {
  const probe = spawnSync('php', ['-v'], { encoding: 'utf8' });
  if (probe.status !== 0) {
    t.skip('php niet beschikbaar');
    return;
  }
  const r = spawnSync('php', [path.join(here, 'run-prompt-adapter-php.php')], { encoding: 'utf8' });
  assert.equal(r.status, 0, r.stderr + r.stdout);
});
