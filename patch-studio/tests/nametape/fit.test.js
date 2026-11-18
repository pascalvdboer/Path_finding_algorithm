import test from 'node:test';
import assert from 'node:assert/strict';
import { readHere, cfg, metrics, merge, batch } from './lib.js';
import { fitText, checkRow, rowLines, bloodText, reviewHits, bestSplit, resolveFormat, lineEm } from '../../plugin/borduur-patch-studio/assets/src/nametape/fit.js';

const V = readHere('fit-vectors.json');

test('fit-vectors: minstens 20 gevallen, alle statussen vertegenwoordigd', () => {
  assert.ok(V.fit.length >= 20);
  const st = new Set(V.fit.map((c) => c.expect.status));
  assert.deepEqual([...st].sort(), ['error', 'ok', 'warn']);
  assert.ok(V.fit.some((c) => c.expect.capMm === 6), 'grensgeval 6,0 mm');
  assert.ok(V.fit.some((c) => c.expect.capMm === 4), 'grensgeval 4,0 mm');
});

for (const c of V.fit) {
  test(`fitText: ${c.name}`, () => {
    assert.deepEqual(fitText(c.input, cfg, metrics), c.expect);
  });
}
for (const c of V.rows) {
  test(`checkRow: ${c.name}`, () => {
    const conf = c.cfg ? merge(cfg, c.cfg) : cfg;
    assert.deepEqual(c.batch.rows.map((r) => checkRow(r, c.batch, conf, metrics)), c.expect);
  });
}

const F = (id) => resolveFormat({ format: id }, cfg);
const base = { fontId: 'block', borderId: 'satin_letter', uppercase: true, format: F('10x2.5') };

test('grenzen: 6,0 mm = ok, 5,9 = warn, 4,0 = warn, 3,9 = error', () => {
  const by = (mm) => V.fit.find((c) => c.expect.capMm === mm && /grens/.test(c.name)).expect.status;
  assert.equal(by(6), 'ok');
  assert.equal(by(5.9), 'warn');
  assert.equal(by(4), 'warn');
  assert.equal(by(3.9), 'error');
});

test('fixes lossen het probleem echt op (opnieuw gecontroleerd)', () => {
  const rank = { error: 0, warn: 1, ok: 2 };
  for (const c of V.fit) {
    for (const f of c.expect.fixes) {
      let r = null;
      if (f === 'use_font:condensed') r = fitText({ ...c.input, fontId: 'condensed' }, cfg, metrics);
      else if (f.startsWith('use_format:')) r = fitText({ ...c.input, format: F(f.slice(11)) }, cfg, metrics);
      else if (f === 'drop_line2') r = fitText({ ...c.input, lines: [c.input.lines[0]] }, cfg, metrics);
      else if (f === 'split_lines') r = fitText({ ...c.input, lines: bestSplit(c.input.lines[0], metrics.fonts[c.input.fontId]) }, cfg, metrics);
      if (r) assert.ok(rank[r.status] > rank[c.expect.status], `${c.name}: ${f}`);
    }
    if (c.expect.status !== 'ok') assert.equal(c.expect.fixes.at(-1), 'edit', 'naam aanpassen kan altijd');
  }
});

test('alle regels krijgen dezelfde letterhoogte; breedte past binnen de strook', () => {
  const r = fitText({ ...base, format: F('12x3'), lines: ['PEETERS J.', 'VERPLEEGKUNDIGE'] }, cfg, metrics);
  assert.equal(r.widthsMm.length, 2);
  for (const w of r.widthsMm) assert.ok(w <= 120 - 2 * 3.5 + 0.1);
});

test('script-lettertype: geen hoofdletters en strengere grens', () => {
  const r = fitText({ ...base, fontId: 'script', lines: ['Alexandra Vandenbroucke-Smet'] }, cfg, metrics);
  assert.equal(r.status, 'warn');
  assert.ok(r.capMm >= 6 && r.capMm < 7);
  const blk = fitText({ ...base, lines: ['abc'] }, cfg, metrics);
  const scr = fitText({ ...base, fontId: 'script', lines: ['abc'] }, cfg, metrics);
  assert.notEqual(lineEm('ABC', metrics.fonts.block), lineEm('abc', metrics.fonts.script));
  assert.equal(blk.status, 'ok'); assert.equal(scr.status, 'ok');
});

test('accenten gebruiken de breedte van de basisletter', () => {
  assert.equal(lineEm('É', metrics.fonts.block), lineEm('E', metrics.fonts.block));
  assert.equal(lineEm('☺', metrics.fonts.block), metrics.fonts.block.fallbackEm);
});

test('bloedgroep: notatie en plaats', () => {
  assert.equal(bloodText({ group: 'O', rh: '+' }, 'O POS'), 'O POS');
  assert.equal(bloodText({ group: 'AB', rh: '-' }, 'O+'), 'AB-');
  const row = { name: 'PEETERS J.', line2: '', blood: { group: 'A', rh: '+' } };
  assert.deepEqual(rowLines(row, 'inline', cfg), ['PEETERS J. A POS']);
  assert.deepEqual(rowLines(row, 'line2', cfg), ['PEETERS J.', 'A POS']);
  assert.deepEqual(rowLines(row, 'separate', cfg), ['PEETERS J.']);
  assert.deepEqual(rowLines({ ...row, line2: 'ARTS' }, 'line2', cfg), ['PEETERS J.', 'ARTS', 'A POS']);
});

test('eigen bloedgroepstrook wordt ook gecontroleerd', () => {
  const b = batch({ bloodMode: 'separate', rows: [{ name: 'X', line2: '', blood: { group: 'AB', rh: '-' }, qty: 1 }] });
  const r = checkRow(b.rows[0], b, cfg, metrics);
  assert.equal(r.bloodFit.status, 'ok');
});

test('controlewoorden: heel woord, zonder accenten, hoofdletterongevoelig', () => {
  assert.deepEqual(reviewHits(['Jan', 'lokale politie Kalmthout'], cfg.reviewWords), ['POLITIE', 'LOKALE POLITIE']);
  assert.deepEqual(reviewHits(['Politieke partij'], cfg.reviewWords), []);
  assert.deepEqual(reviewHits(['Maréchaussée'], cfg.reviewWords), ['MARECHAUSSEE']);
  assert.deepEqual(reviewHits(['police-secours'], cfg.reviewWords), ['POLICE']);
});

test('prestaties: 250 rijen controleren < 250 ms', () => {
  const b = batch({ rows: Array.from({ length: 250 }, (_, i) => ({ name: 'VERMEULEN-JANSSENS ' + i, line2: '', blood: null, qty: 1 })) });
  const t0 = performance.now();
  for (const r of b.rows) checkRow(r, b, cfg, metrics);
  assert.ok(performance.now() - t0 < 250);
});
