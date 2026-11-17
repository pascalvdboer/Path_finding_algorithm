// Tests for the colors module (owner: colors builder). Run: npm test
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { hexToLab, deltaE2000 } from '../../plugin/borduur-patch-studio/assets/src/shared/color-math.js';
import { buildPublicConfig } from '../../plugin/borduur-patch-studio/assets/src/shared/config.js';
import { createT } from '../../plugin/borduur-patch-studio/assets/src/shared/i18n.js';
import * as C from '../../plugin/borduur-patch-studio/assets/src/colors/index.js';

const base = new URL('../../plugin/borduur-patch-studio/config/', import.meta.url);
const defaults = JSON.parse(readFileSync(new URL('defaults.json', base)));
const paletteJson = JSON.parse(readFileSync(new URL('thread-palette.json', base)));
const research = JSON.parse(readFileSync(new URL('../../docs/research/01-garenkleuren-polyneon.json', import.meta.url)));
const config = buildPublicConfig(defaults, paletteJson, { locale: 'nl-BE' });
const prepared = C.prepareThreads(config.threads);

const pc = (hex, share, extra = {}) => ({ hex, lab: hexToLab(hex), share, thin: false, ...extra });

/* ------------------------------ palette file ------------------------------ */

test('thread-palette.json: schema, 60-120 threads, unique codes and names, budget', () => {
  assert.equal(paletteJson.brand, 'Madeira Polyneon');
  assert.match(paletteJson.source, /^https:\/\/borduurservicebelgie\.be\//);
  const th = paletteJson.threads;
  assert.ok(th.length >= 60 && th.length <= 120, `count ${th.length}`);
  const families = new Set(['red', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'brown', 'grey', 'black', 'white', 'metallic']);
  const codes = new Set(), nl = new Set(), fr = new Set();
  for (const t of th) {
    assert.match(t.code, /^\d{4}$/);
    assert.match(t.hex, /^#[0-9a-f]{6}$/);
    assert.ok(families.has(t.family), `family ${t.family}`);
    assert.ok(t.name.nl && t.name.fr, `names ${t.code}`);
    assert.ok(!codes.has(t.code), `dup code ${t.code}`);
    assert.ok(!nl.has(t.name.nl.toLowerCase()), `dup nl ${t.name.nl}`);
    assert.ok(!fr.has(t.name.fr.toLowerCase()), `dup fr ${t.name.fr}`);
    codes.add(t.code); nl.add(t.name.nl.toLowerCase()); fr.add(t.name.fr.toLowerCase());
    // No Pantone references, no manufacturer names as main name.
    assert.doesNotMatch(t.name.nl + t.name.fr, /pantone|pms|madeira|polyneon/i);
  }
  // Config budget (SPEC §5.3): compact JSON ≤ 10 kB.
  assert.ok(JSON.stringify({ brand: paletteJson.brand, threads: th }).length <= 10240);
  // Standard black/white threads exist.
  assert.ok(codes.has(defaults.threadSpecial.black));
  assert.ok(codes.has(defaults.threadSpecial.white));
});

test('thread-palette.json: codes and hex values come from the site assortment, no fluo series', () => {
  const site = new Map(research.colors.map((c) => [c.code, c]));
  const siteNames = new Set(research.colors.map((c) => c.name.toLowerCase()));
  for (const t of paletteJson.threads) {
    const s = site.get(t.code);
    assert.ok(s, `code ${t.code} not on site`);
    assert.equal(t.hex, s.hex.toLowerCase(), `hex ${t.code}`);
    assert.doesNotMatch(s.name, /fluorescent/i);
    // Own names: not a copy of the manufacturer name.
    assert.ok(!siteNames.has(t.name.nl.toLowerCase()) || /^(magenta|fuchsia|taupe|aubergine)$/i.test(t.name.nl), `own name ${t.name.nl}`);
  }
});

test('palette covers the full site assortment reasonably (median ΔE00 < 5, max < 12)', () => {
  const d = research.colors.filter((c) => !/fluorescent/i.test(c.name))
    .map((c) => C.matchColor(c.hex, prepared, { ...config, analysis: { white: { L: 101, C: 0 }, black: { L: -1, C: 0 } } }).deltaE)
    .sort((a, b) => a - b);
  assert.ok(d[d.length >> 1] < 5, `median ${d[d.length >> 1]}`);
  assert.ok(d[d.length - 1] < 12, `max ${d[d.length - 1]}`);
});

/* ------------------------------ matching ------------------------------ */

test('prepareThreads: Lab added, cached per array, invalid and out-of-stock skipped', () => {
  assert.equal(C.prepareThreads(config.threads), prepared);
  assert.ok(prepared.every((t) => Array.isArray(t.lab) && t.lab.length === 3));
  const raw = [{ code: '1', hex: '#FF0000', name: { nl: 'a' }, family: 'red' }, { code: '2', hex: 'nope' }, { code: '3', hex: '#00ff00', stock: false }, { hex: '#000' }];
  const p = C.prepareThreads(raw);
  assert.equal(p.length, 1);
  assert.equal(p[0].hex, '#ff0000');
  assert.deepEqual(C.prepareThreads(null), []);
});

test('threadByCode', () => {
  assert.equal(C.threadByCode('1839', config.threads).name.nl, 'Rood');
  assert.equal(C.threadByCode(1839, prepared).code, '1839');
  assert.equal(C.threadByCode('0000', prepared), null);
  assert.equal(C.threadByCode(null, prepared), null);
});

test('matchColor: exact thread colour → very_good, ΔE 0, 2 alternatives', () => {
  const m = C.matchColor('#ac1e32', prepared, config);
  assert.equal(m.thread.code, '1839');
  assert.ok(m.deltaE < 1e-6);
  assert.equal(m.quality, 'very_good');
  assert.equal(m.alternatives.length, 2);
  assert.ok(m.alternatives.every((t) => t.code !== '1839'));
  // Lab input works the same.
  assert.equal(C.matchColor(hexToLab('#ac1e32'), prepared, config).thread.code, '1839');
});

test('matchColor: house-style colours (07 §5.2 proof)', () => {
  const blue = C.matchColor('#21A9E2', prepared, config);
  assert.equal(blue.thread.code, '1593');
  assert.equal(blue.quality, 'good');
  const coral = C.matchColor('#FD5C48', prepared, config);
  assert.equal(coral.thread.code, '1778');
  assert.equal(coral.quality, 'good');
  // Purple #5926C5 has no good thread → honest 'none' (advice: "kies bedrukt").
  const purple = C.matchColor('#5926C5', prepared, config);
  assert.equal(purple.quality, 'none');
  assert.ok(purple.deltaE >= 10);
});

test('matchColor: near-black / near-white use threadSpecial', () => {
  const k = C.matchColor('#000000', prepared, config);
  assert.equal(k.thread.code, '1800');
  assert.equal(k.special, 'black');
  assert.equal(k.quality, 'very_good');
  const w = C.matchColor('#ffffff', prepared, config);
  assert.equal(w.thread.code, '1801');
  assert.equal(w.special, 'white');
  // Configurable.
  const cfg2 = { ...config, threadSpecial: { black: '1589', white: '1803' } };
  assert.equal(C.matchColor('#050505', prepared, cfg2).thread.code, '1589');
  assert.equal(C.matchColor('#fdfdfd', prepared, cfg2).thread.code, '1803');
  // Unknown special code → falls back to darkest neutral.
  const cfg3 = { ...config, threadSpecial: { black: '9999' } };
  assert.equal(C.matchColor('#000000', prepared, cfg3).thread.family, 'black');
});

test('matchColor: quality bands and edge cases', () => {
  assert.equal(C.qualityFor(1.9), 'very_good');
  assert.equal(C.qualityFor(2), 'good');
  assert.equal(C.qualityFor(4.99), 'good');
  assert.equal(C.qualityFor(5), 'approx');
  assert.equal(C.qualityFor(9.99), 'approx');
  assert.equal(C.qualityFor(10), 'none');
  assert.equal(C.qualityFor(NaN), 'none');
  const bad = C.matchColor('zz', prepared, config);
  assert.equal(bad.thread, null);
  assert.equal(bad.quality, 'none');
  assert.equal(C.matchColor('#ff0000', [], config).thread, null);
  assert.equal(C.matchColor('#f00', prepared, config).thread.code, C.matchColor('#ff0000', prepared, config).thread.code);
  // n = 1 → no alternatives.
  assert.equal(C.matchColor('#ff0000', prepared, config, 1).alternatives.length, 0);
});

test('matchColor: best thread is truly the minimum ΔE00 (brute force check)', () => {
  const samples = ['#123456', '#a0b0c0', '#ff8800', '#33cc66', '#cc3399', '#777777', '#e0c090', '#204020'];
  for (const s of samples) {
    const lab = hexToLab(s);
    const min = Math.min(...prepared.map((t) => deltaE2000(lab, t.lab)));
    const m = C.matchColor(s, prepared, config);
    if (!m.special) assert.ok(Math.abs(m.deltaE - min) < 1e-9, s);
  }
});

test('matchPalette keeps order and uses lab when present', () => {
  const pal = [pc('#ac1e32', 0.5), pc('#125e9a', 0.3), { hex: '#ffffff', share: 0.2 }];
  const ms = C.matchPalette(pal, prepared, config);
  assert.deepEqual(ms.map((m) => m.thread.code), ['1839', '1934', '1801']);
  assert.deepEqual(C.matchPalette(null, prepared, config), []);
});

test('matching is fast: 16 colours × palette ≪ 5 ms average', () => {
  const pal = Array.from({ length: 16 }, (_, i) => pc('#' + ((i * 977231) % 0xffffff).toString(16).padStart(6, '0'), 1 / 16));
  const t0 = performance.now();
  for (let r = 0; r < 50; r++) C.matchPalette(pal, prepared, config);
  const avg = (performance.now() - t0) / 50;
  assert.ok(avg < 5, `avg ${avg.toFixed(2)} ms`);
});

/* ------------------------------ reduction ------------------------------ */

test('reducePalette: two shades on the same thread are merged (same_thread)', () => {
  const pal = [pc('#ac1e32', 0.6), pc('#ae1f33', 0.1), pc('#125e9a', 0.3)];
  const r = C.reducePalette(pal, 12, { prepared, config });
  assert.equal(r.k, 2);
  assert.equal(r.merges.length, 1);
  assert.equal(r.merges[0].reason, 'same_thread');
  assert.deepEqual(r.merges[0].from, [1]);
  assert.equal(r.colors[r.merges[0].into].thread.thread.code, '1839');
  assert.deepEqual(r.map, [0, 0, 1]);
  assert.ok(Math.abs(r.colors[0].share - 0.7) < 1e-9);
  assert.equal(r.colors[0].hex, '#ac1e32'); // dominant colour kept, no muddy average
  assert.equal(r.forced, false);
});

test('reducePalette: reduces to ≤ N with rising thresholds, closest first, dominant survives', () => {
  const pal = [
    pc('#0b5695', 0.30), pc('#125e9a', 0.05), // very close blues (different threads)
    pc('#ac1e32', 0.20), pc('#d71e3a', 0.05), // reds
    pc('#f9d408', 0.15), pc('#0f8c44', 0.10), pc('#ffffff', 0.10), pc('#000000', 0.05),
  ];
  const r = C.reducePalette(pal, 6, { prepared, config });
  assert.ok(r.k <= 6);
  assert.equal(r.forced, false);
  // the small blue goes into the big blue
  const blue = r.colors[r.map[0]];
  assert.equal(r.map[1], r.map[0]);
  assert.equal(blue.hex, '#0b5695');
  assert.ok(Math.abs(r.colors.reduce((s, c) => s + c.share, 0) - 1) < 1e-9);
  // map is consistent with sources
  r.colors.forEach((c, ci) => c.sources.forEach((s) => assert.equal(r.map[s], ci)));
  // colors sorted on share desc
  for (let i = 1; i < r.colors.length; i++) assert.ok(r.colors[i - 1].share >= r.colors[i].share);
});

test('reducePalette: thin lines survive when possible; keep is respected', () => {
  const pal = [
    pc('#1e3957', 0.5), pc('#264b87', 0.3),
    pc('#125e9a', 0.004, { thin: true }), // thin line, closer to the mid blue than the two big blues are to each other
    pc('#f9d408', 0.196),
  ];
  const r = C.reducePalette(pal, 3, { prepared, config });
  assert.equal(r.k, 3);
  assert.notEqual(r.map[2], r.map[3]);
  // the thin colour stays separate; the two big blues merge instead
  assert.equal(r.map[0], r.map[1]);
  assert.notEqual(r.map[2], r.map[0]);

  // keep: the small colour survives as its own colour and absorbs nothing it should not
  const pal2 = [pc('#ac1e32', 0.7), pc('#b32232', 0.2), pc('#f9d408', 0.1)];
  const r2 = C.reducePalette(pal2, 2, { prepared, config, keep: [1] });
  assert.equal(r2.k, 2);
  const kept = r2.colors[r2.map[1]];
  assert.equal(kept.keep, true);
  assert.equal(kept.hex, '#b32232');
});

test('reducePalette: forced merges when thresholds are not enough; never more than N', () => {
  const pal = [pc('#ac1e32', 0.25), pc('#125e9a', 0.25), pc('#f9d408', 0.25), pc('#0f8c44', 0.25)];
  const r = C.reducePalette(pal, 2, { prepared, config });
  assert.equal(r.k, 2);
  assert.equal(r.forced, true);
  assert.ok(r.merges.some((m) => m.reason === 'forced'));
  assert.ok(r.maxDeltaE >= 18);
  const r1 = C.reducePalette(pal, 0, { prepared, config });
  assert.equal(r1.k, 1);
  // N larger than palette → untouched
  const r12 = C.reducePalette(pal, 12, { prepared, config });
  assert.equal(r12.k, 4);
  assert.equal(r12.merges.length, 0);
  // empty
  const e = C.reducePalette([], 6, { prepared, config });
  assert.equal(e.k, 0);
  assert.deepEqual(e.map, []);
});

test('reducePalette: deterministic and respects config steps', () => {
  const pal = Array.from({ length: 16 }, (_, i) => pc('#' + ((i * 1234567 + 99) % 0xffffff).toString(16).padStart(6, '0'), 1 / 16));
  const a = C.reducePalette(pal, 8, { prepared, config });
  const b = C.reducePalette(pal, 8, { prepared, config });
  assert.deepEqual(a.map, b.map);
  assert.ok(a.k <= 8);
  // A single huge step merges everything similar in one round.
  const cfg = { ...config, analysis: { ...config.analysis, embroideryMergeSteps: [100] } };
  const c = C.reducePalette(pal, 8, { prepared, config: cfg });
  assert.equal(c.forced, false);
  assert.ok(c.k <= 8);
});

test('reducedHexes gives per-palette-index colours for the preview', () => {
  const pal = [pc('#ac1e32', 0.6), pc('#ae1f33', 0.1), pc('#125e9a', 0.3)];
  const r = C.reducePalette(pal, 12, { prepared, config });
  assert.deepEqual(C.reducedHexes(r, 'design'), ['#ac1e32', '#ac1e32', '#125e9a']);
  assert.deepEqual(C.reducedHexes(r), ['#ac1e32', '#ac1e32', '#125e9a']); // thread hex equals here
  assert.equal(C.distinctThreadCount(pal, prepared, config), 2);
});

/* ------------------------------ contrast & fabric ------------------------------ */

test('contrastAgainst: levels bad/low/ok with share rule, ratio reported', () => {
  const navy = '#1b2a4a';
  const checks = C.contrastAgainst([
    { hex: '#1b2a4a', share: 0.4 }, // same as fabric, big → bad
    { hex: '#1b2a4a', share: 0.01 }, // same as fabric, tiny → low (not bad)
    { hex: '#34507f', share: 0.2 }, // lighter navy → low
    { hex: '#ffffff', share: 0.39 }, // ok
    { hex: '#1c2b4b', share: 0, index: -1 }, // text layer → always important → bad
  ], navy, config);
  assert.deepEqual(checks.map((c) => c.level), ['bad', 'low', 'low', 'ok', 'bad']);
  assert.equal(checks[4].index, -1);
  assert.ok(checks[3].ratio > 10);
  assert.ok(checks[0].deltaE < 1e-6);
  assert.equal(C.worstContrast(checks), 'bad');
  assert.equal(C.worstContrast([]), 'ok');
  assert.deepEqual(C.contrastAgainst([{ hex: '#fff', share: 1 }], 'nope', config), []);
});

test('pickFabric: white design → never white fabric; navy-only logo → not navy', () => {
  const fabrics = config.fabrics;
  const white = C.pickFabric([{ hex: '#ffffff', share: 1 }], fabrics, config);
  assert.notEqual(white.id, 'white');
  assert.equal(white.reason, 'contrast');
  const navy = C.pickFabric([{ hex: '#1b2a4a', share: 0.9 }, { hex: '#ffffff', share: 0.1 }], fabrics, config);
  assert.notEqual(navy.id, 'navy');
  assert.notEqual(navy.id, 'black');
  // Every colour visible on the chosen fabric for a typical 3-colour logo.
  const logo = [{ hex: '#ac1e32', share: 0.5 }, { hex: '#f9d408', share: 0.3 }, { hex: '#ffffff', share: 0.2 }];
  const f = C.pickFabric(logo, fabrics, config);
  const checks = C.contrastAgainst(logo, f.hex, config);
  assert.ok(checks.every((c) => c.level !== 'bad'), JSON.stringify(f));
  assert.notEqual(f.id, 'red');
});

test('pickFabric: thin/text colours count, background mode, default, disabled fabrics', () => {
  const fabrics = config.fabrics;
  // Big yellow + thin white line: white fabric would hide the line; prefer a dark fabric.
  const f = C.pickFabric([{ hex: '#f9d408', share: 0.99 }, { hex: '#ffffff', share: 0.01, thin: true }], fabrics, config);
  const lab = hexToLab(f.hex);
  assert.ok(lab[0] < 60, f.id);
  // Kept background → nearest fabric.
  const bg = C.pickFabric([{ hex: '#ffffff', share: 1 }], fabrics, config, { background: { type: 'solid', hex: '#b02a30', keep: true } });
  assert.deepEqual([bg.id, bg.reason], ['red', 'background']);
  assert.equal(C.pickFabric([], fabrics, config, { background: '#20304f' }).id, 'navy');
  // Not kept → contrast mode.
  assert.equal(C.pickFabric([{ hex: '#000000', share: 1 }], fabrics, config, { background: { type: 'white', hex: '#ffffff', keep: false } }).reason, 'contrast');
  // Nothing → default (first).
  assert.deepEqual(C.pickFabric([], fabrics, config), { id: 'white', hex: '#ffffff', reason: 'default' });
  // Disabled fabrics are skipped; no fabrics → null.
  const only = [{ id: 'white', hex: '#ffffff', enabled: false }, { id: 'grey', hex: '#6b6f76' }];
  assert.equal(C.pickFabric([{ hex: '#ff0000', share: 1 }], only, config).id, 'grey');
  assert.equal(C.pickFabric([{ hex: '#ff0000', share: 1 }], [], config), null);
});

test('pickEdgeColor: darkest visible colour, else dominant, else black/white', () => {
  const navyLogo = [pc('#ffffff', 0.5), pc('#0b5695', 0.3), pc('#f9d408', 0.2)];
  const onWhite = C.pickEdgeColor('#ffffff', navyLogo, prepared, config);
  assert.equal(onWhite.code, '1842');
  // On royal-blue fabric the dark blue is invisible → dominant visible colour (white).
  const onRoyal = C.pickEdgeColor('#1f5aa6', navyLogo, prepared, config);
  assert.equal(onRoyal.code, '1801');
  // Uses ThreadMatch when present.
  const withMatch = [{ hex: '#ac1e32', share: 0.6, thread: C.matchColor('#ac1e32', prepared, config) }];
  assert.equal(C.pickEdgeColor('#ffffff', withMatch, prepared, config).code, '1839');
  // No colours → black on light, white on dark.
  assert.equal(C.pickEdgeColor('#ffffff', [], prepared, config).code, '1800');
  assert.equal(C.pickEdgeColor('#151515', [], prepared, config).code, '1801');
  assert.equal(C.pickEdgeColor('#ffffff', [], [], config), null);
});

/* ------------------------------ texts ------------------------------ */

test('texts: quality labels, thread labels, merges, contrast, fabric (NL + FR, no ΔE numbers)', () => {
  const t1839 = C.threadByCode('1839', prepared);
  assert.equal(C.threadLabel(t1839, { config }), 'Rood · Polyneon 1839');
  assert.equal(C.threadLabel(t1839, { config, locale: 'fr-BE' }), 'Rouge · Polyneon 1839');
  assert.equal(C.threadLabel(t1839, { config, short: true }), 'Rood (1839)');
  assert.equal(C.qualityLabel('good', { locale: 'nl-BE' }), 'goed');
  assert.equal(C.qualityLabel('approx', { locale: 'fr-BE' }), 'approchante');
  assert.match(C.colorDisclaimer({ locale: 'nl-BE' }), /digitale proef/);

  const none = C.describeMatch(C.matchColor('#5926C5', prepared, config), { config });
  assert.equal(none.key, 'colors.match.none');
  assert.match(none.text, /Kies dan bedrukt/);
  assert.equal(C.describeMatch(C.matchColor('#ac1e32', prepared, config), { config }), null);

  const pal = [pc('#ac1e32', 0.6), pc('#ae1f33', 0.1), pc('#125e9a', 0.2), pc('#0b5695', 0.1)];
  const r = C.reducePalette(pal, 1, { prepared, config });
  const msgs = C.describeMerges(r, { config });
  assert.ok(msgs.length >= 2);
  assert.match(msgs[0].text, /2 tinten rood worden 1 garenkleur: Rood · Polyneon 1839\./);
  for (const m of msgs) assert.doesNotMatch(m.text, /ΔE|delta|\d+\.\d/i);
  const fr = C.describeMerges(r, { config, locale: 'fr-BE' });
  assert.match(fr[0].text, /2 nuances de rouge deviennent 1 couleur de fil/);

  const checks = C.contrastAgainst([{ hex: '#1b2a4a', share: 0.5 }, { hex: '#ffffff', share: 0.5 }], '#1b2a4a', config);
  const cw = C.describeContrast(checks, config.fabrics.find((f) => f.id === 'navy'), { config, names: ['Marineblauw', 'Wit'] });
  assert.equal(cw.length, 1);
  assert.equal(cw[0].level, 'bad');
  assert.match(cw[0].text, /Marineblauw is bijna onzichtbaar op Marineblauw/);

  const fm = C.describeFabric({ id: 'navy', reason: 'contrast' }, config.fabrics, { config });
  assert.equal(fm.text, 'We kozen marineblauw: daarop komen je kleuren het best uit.');
  // Catalogue: every NL key has a FR translation.
  for (const k of Object.keys(C.colorMessages.nl)) assert.ok(C.colorMessages.fr[k], `fr ${k}`);
  // config.strings overrides work (PHP side).
  const t = createT([C.colorMessages], 'nl-BE', { 'colors.quality.good': 'prima' });
  assert.equal(C.qualityLabel('good', { t }), 'prima');
});
