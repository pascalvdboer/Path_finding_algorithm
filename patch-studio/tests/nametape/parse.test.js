import test from 'node:test';
import assert from 'node:assert/strict';
import { readHere, cfg } from './lib.js';
import { parseList, parseBlood, mergeDuplicates, splitLine } from '../../plugin/borduur-patch-studio/assets/src/nametape/parse.js';

const bt = (b) => (b ? b.group + b.rh : '');

for (const c of readHere('parse-cases.json').cases) {
  test(`parseList: ${c.name}`, () => {
    const r = parseList(c.text, { source: c.source, config: cfg });
    assert.equal(r.delimiter, c.delimiter, 'scheidingsteken');
    assert.equal(!!r.header, c.header, 'koptekst');
    assert.deepEqual(r.rows.map((x) => [x.name, x.line2, bt(x.blood), x.qty]), c.rows);
    assert.deepEqual([...new Set(r.warnings.map((w) => w.key))], c.warnings);
    if (c.bloodOnly != null) assert.equal(r.bloodOnly, c.bloodOnly);
    if (c.duplicates != null) assert.equal(r.duplicates.length, c.duplicates);
  });
}

test('parseList: meer dan maxRows → afgekapt + offerte-melding', () => {
  const text = Array.from({ length: 260 }, (_, i) => `NAAM ${i + 1}`).join('\n');
  const r = parseList(text, { source: 'paste', config: cfg });
  assert.equal(r.rows.length, 250);
  assert.equal(r.truncated, true);
  assert.ok(r.warnings.some((w) => w.key === 'nt.parse.tooMany' && w.params.max === 250));
});

test('parseList: xlsx-bestand → uitleg in plaats van rommel', () => {
  const r = parseList('PK\u0003\u0004binary', { source: 'csv', fileName: 'team.xlsx' });
  assert.equal(r.rows.length, 0);
  assert.equal(r.warnings[0].key, 'nt.parse.xlsx');
});

test('parseList: CSV met komma maar onregelmatig aantal kolommen → één kolom', () => {
  const r = parseList('A, B\nC\nD\nE, F, G\nH', { source: 'csv', config: cfg });
  assert.equal(r.delimiter, null);
  assert.equal(r.rows.length, 5);
});

test('parseList: ids lopen door vanaf startId (toevoegen aan een lijst)', () => {
  const r = parseList('A\nB', { startId: 7 });
  assert.deepEqual(r.rows.map((x) => x.id), ['r7', 'r8']);
});

test('parseBlood: varianten en ongeldig', () => {
  assert.deepEqual(parseBlood('0 POS'), { group: 'O', rh: '+' });
  assert.deepEqual(parseBlood('ab−'), { group: 'AB', rh: '-' });
  assert.deepEqual(parseBlood('BT: A negatief'), { group: 'A', rh: '-' });
  assert.deepEqual(parseBlood({ group: '0', rh: '+' }), { group: 'O', rh: '+' });
  assert.equal(parseBlood('C+'), null);
  assert.equal(parseBlood('O'), null);
  assert.equal(parseBlood(''), null);
});

test('splitLine: aanhalingstekens en lege velden', () => {
  assert.deepEqual(splitLine('"a;b";c;;"d""e"', ';'), ['a;b', 'c', '', 'd"e']);
});

test('mergeDuplicates: aantallen opgeteld (max 99), tweede rij weg', () => {
  const rows = [{ id: 'r1', name: 'A', qty: 2 }, { id: 'r2', name: 'B', qty: 1 }, { id: 'r3', name: 'a', qty: 3 }];
  assert.deepEqual(mergeDuplicates(rows, ['r1', 'r3']), [{ id: 'r1', name: 'A', qty: 5 }, { id: 'r2', name: 'B', qty: 1 }]);
  assert.equal(mergeDuplicates([{ id: 'x', qty: 90 }, { id: 'y', qty: 20 }], ['x', 'y'])[0].qty, 99);
});
