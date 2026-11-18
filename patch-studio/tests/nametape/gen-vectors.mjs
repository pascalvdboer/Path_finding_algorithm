#!/usr/bin/env node
/**
 * Schrijft tests/nametape/fit-vectors.json en price-vectors.json: invoer + verwachte uitkomst volgens de JS-engine.
 * De node-test controleert de vaste bedragen uit SPEC-EXTRA §1.7 apart (hand-gecontroleerd); PHP (run-php.php) moet
 * exact dezelfde uitkomsten geven als hier vastgelegd. Opnieuw draaien na een bewuste wijziging aan algoritme of config:
 *   node tests/nametape/gen-vectors.mjs
 */
import { writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { cfg, metrics, defaultCtx, batch, names, merge } from './lib.js';
import { fitText, checkRow } from '../../plugin/borduur-patch-studio/assets/src/nametape/fit.js';
import { quote } from '../../plugin/borduur-patch-studio/assets/src/nametape/pricing.js';

const here = dirname(fileURLToPath(import.meta.url));
const F = (id) => { const f = cfg.formats.find((x) => x.id === id); return { id: f.id, w: f.w, h: f.h, lines: f.lines }; };
const base = { fontId: 'block', borderId: 'satin_letter', uppercase: true, format: F('10x2.5') };

/** Zoek een naam die precies op een cap-hoogte uitkomt (grensgevallen 6,0 en 4,0 mm). */
function findExact(target, input) {
  const A = 'ABCDEFGHIJKLMNOPRSTUVWZ';
  const stems = ['VERMEULEN', 'VANDENBROUCKE', 'DE WILDE', 'JANSSENS', 'VAN HOOREBEKE', 'MOMMAERTS'];
  for (const stem of stems) {
    for (let rep = 0; rep < 6; rep++) {
      const s0 = stem + (rep ? ' ' + stem.slice(0, rep * 2) : '');
      for (const a of A) for (const b of A) {
        const name = `${s0} ${a}${b}.`;
        const r = fitText({ ...input, lines: [name] }, cfg, metrics, { fixes: false });
        if (r.capMm === target) return name;
      }
    }
  }
  throw new Error('geen naam gevonden voor ' + target);
}

const fitCases = [
  ['kort, blokletter', { ...base, lines: ['PEETERS J.'] }],
  ['kleine letters worden hoofdletters', { ...base, lines: ['peeters j.'] }],
  ['geen hoofdletters', { ...base, uppercase: false, lines: ['Peeters J.'] }],
  ['accenten (é, ë, ç)', { ...base, lines: ['HÉLÈNE DE BRÛCKER-ÇA'] }],
  ['lange naam: te lang', { ...base, lines: ['VANDENBROUCKE-DESMEDT ALEX.'] }],
  ['te lang, precies 30 tekens', { ...base, lines: ['DE VLEESCHAUWER-VERSTRAETEN J.'] }],
  ['middellange naam: kleine letters (warn)', { ...base, lines: ['VERSTRAETEN-JANSSENS K.'] }],
  ['grens precies 6,0 mm (ok)', { ...base, lines: [findExact(6.0, base)] }],
  ['grens 5,9 mm (warn)', { ...base, lines: [findExact(5.9, base)] }],
  ['grens precies 4,0 mm (warn)', { ...base, lines: [findExact(4.0, base)] }],
  ['grens 3,9 mm (error)', { ...base, lines: [findExact(3.9, base)] }],
  ['smalle letter', { ...base, fontId: 'condensed', lines: ['VANDENBROUCKE-DESMEDT A.'] }],
  ['schrijfletter (geen hoofdletters, strenger)', { ...base, fontId: 'script', lines: ['Peeters Jan'] }],
  ['schrijfletter lang: strengere grens (warn)', { ...base, fontId: 'script', lines: ['Alexandra Vandenbroucke-Smet'] }],
  ['college', { ...base, fontId: 'college', lines: ['MAES L.'] }],
  ['klassiek (serif)', { ...base, fontId: 'serif', lines: ['DUBOIS M.'] }],
  ['zonder rand', { ...base, borderId: 'none', lines: ['VERSTRAETEN-JANSSENS K.'] }],
  ['twee regels op 12 × 3', { ...base, format: F('12x3'), lines: ['PEETERS J.', 'VERPLEEGKUNDIGE'] }],
  ['regel 2 op 1-regelformaat', { ...base, lines: ['PEETERS J.', 'BRANDWEER'] }],
  ['drie regels op 8 × 5', { ...base, format: F('8x5'), lines: ['PEETERS J.', 'SERGEANT', 'O POS'] }],
  ['vier regels op 8 × 5', { ...base, format: F('8x5'), lines: ['A', 'B', 'C', 'D'] }],
  ['5 × 2,5 bloedgroep', { ...base, format: F('5x2.5'), lines: ['O POS'] }],
  ['5 × 2,5 bloedgroep kort', { ...base, format: F('5x2.5'), lines: ['AB-'] }],
  ['verboden tekens', { ...base, lines: ['PEETERS ☺ J.!'] }],
  ['te veel tekens', { ...base, lines: ['ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEF'] }],
  ['lege naam', { ...base, lines: [''] }],
  ['spaties en tabs worden opgeschoond', { ...base, lines: ['  PEETERS \t  J.  '] }],
  ['typografische apostrof', { ...base, lines: ['D’HONDT P.'] }],
  ['eigen maat 140 × 30', { ...base, format: { id: 'custom', w: 140, h: 30, lines: 2 }, lines: ['VANDENBROUCKE-DESMEDT A.'] }],
  ['split op 2 regels mogelijk (12 × 3)', { ...base, format: F('12x3'), lines: ['VANDENBROUCKE DESMEDT-WOUTERS'] }],
  ['ß wordt SS', { ...base, lines: ['STRAßER K.'] }],
  ['cijfers en tekens', { ...base, lines: ['TEAM #12 (A&B) 3/4'] }],
];

const rowCases = [
  ['bloedgroep op eigen strook', batch({ bloodMode: 'separate', rows: [{ name: 'PEETERS J.', line2: '', blood: { group: 'O', rh: '+' }, qty: 2 }] })],
  ['bloedgroep op regel 2 (12 × 3)', batch({ format: '12x3', bloodMode: 'line2', rows: [{ name: 'PEETERS J.', line2: '', blood: { group: 'AB', rh: '-' }, qty: 1 }] })],
  ['bloedgroep achter de naam', batch({ bloodMode: 'inline', rows: [{ name: 'PEETERS J.', line2: '', blood: { group: 'A', rh: '+' }, qty: 1 }] })],
  ['controlewoord POLITIE', batch({ rows: [{ name: 'JANSSENS K.', line2: 'Lokale Politie', blood: null, qty: 1 }] })],
  ['controlewoord met accent (Maréchaussée)', batch({ format: '12x3', rows: [{ name: 'DE VRIES T.', line2: 'Koninklijke Maréchaussée', blood: null, qty: 1 }] })],
  ['geen controlewoord in POLITIEKE', batch({ format: '12x3', rows: [{ name: 'MAES L.', line2: 'POLITIEKE PARTIJ', blood: null, qty: 1 }] })],
  ['notatie O+', batch({ bloodMode: 'separate', rows: [{ name: 'PEETERS J.', line2: '', blood: { group: 'O', rh: '-' }, qty: 1 }] }), { blood: { notation: 'O+', separateFormat: '5x2.5', defaultMode: 'auto' } }],
];

const fitVectors = {
  _doc: 'Gegenereerd door tests/nametape/gen-vectors.mjs (JS = referentie). Node en PHP (run-php.php) moeten exact gelijk zijn.',
  fit: fitCases.map(([name, input]) => ({ name, input, expect: fitText(input, cfg, metrics) })),
  rows: rowCases.map(([name, b, over]) => ({ name, batch: b, cfg: over || null, expect: b.rows.map((r) => checkRow(r, b, over ? merge(cfg, over) : cfg, metrics)) })),
};

const priceCases = [
  ['controle: 25 namen 10 × 2,5, haak, zwart', batch({ rows: names(25) }), {}],
  ['1 strook', batch(), {}],
  ['1 strook met instelkost aan', batch(), {}, { smallOrderFee: { enabled: true } }],
  ['10 × 12 × 3 opnaaien (5 %)', batch({ format: '12x3', backing: 'sew', rows: names(10) }), {}],
  ['bloedgroep op eigen strook', batch({ bloodMode: 'separate', rows: [...names(8), { name: 'X', line2: '', blood: { group: 'O', rh: '+' }, qty: 2 }] }), {}],
  ['camo + tegenstuk', batch({ fabric: 'camo', loop: true, rows: names(3) }), {}],
  ['tegenstuk telt niet bij opnaaien', batch({ backing: 'sew', loop: true, rows: names(3) }), {}],
  ['pool: 20 patches + 5 stroken (10 %)', batch({ rows: names(5) }), { patchPoolQty: 20 }],
  ['pool uitgeschakeld', batch({ rows: names(5) }), { patchPoolQty: 20 }, { tiers: { source: 'patch', pooledWithPatches: false } }],
  ['andere naamstrooklijst telt mee', batch({ rows: names(5) }), { otherNametapePieces: 5 }],
  ['eigen maat 140 × 30', batch({ format: 'custom', custom: { w: 140, h: 30 }, rows: names(4) }), {}],
  ['eigen maat buiten bereik', batch({ format: 'custom', custom: { w: 160, h: 30 } }), {}],
  ['eigen maat 50 × 50', batch({ format: 'custom', custom: { w: 50, h: 50 }, rows: names(2) }), {}],
  ['ongeldig formaat', batch({ format: '99x9' }), {}],
  ['onbekende stof', batch({ fabric: 'paars' }), {}],
  ['100 stroken (20 %), gratis verzending', batch({ rows: names(100) }), {}],
  ['aantallen per rij (2 × 12 + 1)', batch({ rows: [{ name: 'A', line2: '', blood: null, qty: 12 }, { name: 'B', line2: '', blood: null, qty: 12 }, { name: 'C', line2: '', blood: null, qty: 1 }] }), {}],
  ['aantal wordt begrensd (0 → 1, 250 → 99)', batch({ rows: [{ name: 'A', line2: '', blood: null, qty: 0 }, { name: 'B', line2: '', blood: null, qty: 250 }] }), {}],
  ['fluo + opstrijk, FR', batch({ locale: 'fr-BE', fabric: 'fluo-yellow', backing: 'iron', rows: names(9) }), { locale: 'fr-BE' }],
  ['gratis verzending dankzij andere artikelen', batch({ rows: names(2) }), { otherItemsInclVat: 145 }],
  ['geen rijen', batch({ rows: [] }), {}],
  ['instelkost vervalt door andere lijst', batch(), { otherNametapePieces: 4 }, { smallOrderFee: { enabled: true } }],
];
const priceVectors = {
  _doc: 'Gegenereerd door tests/nametape/gen-vectors.mjs. ctx = defaults.json (btw, staffel, verzending) + overrides; cfg = nametape.json + overrides.',
  cases: priceCases.map(([name, b, ctxOver, cfgOver]) => {
    const c = cfgOver ? merge(cfg, cfgOver) : cfg;
    return { name, batch: b, ctx: ctxOver, cfg: cfgOver || null, expect: quote(b, defaultCtx(ctxOver), c) };
  }),
};

writeFileSync(resolve(here, 'fit-vectors.json'), JSON.stringify(fitVectors, null, 1) + '\n');
writeFileSync(resolve(here, 'price-vectors.json'), JSON.stringify(priceVectors, null, 1) + '\n');
console.log(`fit: ${fitVectors.fit.length} + ${fitVectors.rows.length} rijen · prijs: ${priceVectors.cases.length}`);
