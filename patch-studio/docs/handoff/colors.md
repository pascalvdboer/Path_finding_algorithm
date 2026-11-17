# Handoff — colors

Bouwer: colors · SPEC §7.3 · tests: `tests/colors/colors.test.js` (23 tests, `npm test` groen).

## Geleverde bestanden
| Bestand | Inhoud |
|---|---|
| `plugin/borduur-patch-studio/config/thread-palette.json` | **106 garens** (Madeira Polyneon), code + hex van de site (`docs/research/01-garenkleuren-polyneon.json`), eigen neutrale NL/FR-namen, `family`. Compact 9,9 kB (budget 10 kB). |
| `assets/src/colors/index.js` | Publieke API (enige importpunt voor andere modules). |
| `assets/src/colors/match.js` | `prepareThreads`, `matchColor`, `matchPalette`, `threadByCode` + extra's `qualityFor`, `QUALITY_LIMITS`, `nearestThreads`, `specialThread`, `normalizeHex`. |
| `assets/src/colors/reduce.js` | `reducePalette` + extra's `reducedHexes`, `distinctThreadCount`. |
| `assets/src/colors/contrast.js` | `contrastAgainst`, `pickFabric`, `pickEdgeColor` + extra `worstContrast`. |
| `assets/src/colors/describe.js` | Klantteksten als `Msg` (`threadLabel`, `qualityLabel`, `colorDisclaimer`, `describeMatch`, `describeMerges`, `describeContrast`, `describeFabric`). |
| `assets/src/colors/messages.js` | Catalogus NL ("je") + FR ("vous"), sleutels `colors.*`. Geëxporteerd als `colorMessages`. |

Bundelgrootte (index.js + gedeelde helpers, minified + gzip): ± 7,6 kB.

## Hoe het palet gekozen is
- Bron: enkel de eigen site-lijst (411 kleuren). **Fluo-reeks weggelaten** (hexwaarden op de site zijn kopieerfouten), dubbele hexwaarden geschrapt.
- Selectie met een dekkingsalgoritme (greedy facility-location op ΔE2000) over (a) het volledige assortiment en (b) een raster van 216 typische schermkleuren,
  met vaste basiskleuren (zwart 1800, wit 1801, rood 1839, koningsblauw 1934, marine 1555, grasgroen 1988, goud 1724, …).
- Dekking van het volledige assortiment: mediaan ΔE00 4,1 · p90 6,9 · max 10,3. Huisstijlproef: `#21A9E2` → 1593 (goed), `#FD5C48` → 1778 (goed), paars `#5926C5` → `none` (eerlijk: "kies bedrukt").
- Namen: eigen generieke kleurwoorden (geen Pantone-/PMS-namen, geen fabrieksnamen als hoofdnaam). Merk + code enkel beschrijvend: "Rood · Polyneon 1839" (`colors.thread.label`).

## Gedrag (kort)
- `matchColor`: zwart/wit volgens `config.analysis.black/white` → `config.threadSpecial` (kwaliteit `very_good`, veld `special:'black'|'white'`); anders dichtste ΔE2000. Banden <2 / <5 / <10 / ≥10.
- `reducePalette`: (1) zelfde garen → samen (`reason:'same_thread'`), (2) dichtste paar onder oplopende drempels `analysis.embroideryMergeSteps`, eerst met dunne lijnen beschermd, (3) zo nodig `forced`. De **dominante** (of `keep`) kleur overleeft met haar eigen kleur en garen (geen modderige gemiddelden). Altijd ≤ max (min 1). Deterministisch.
- `contrastAgainst`: `bad` = ΔE < `badDE` én (share ≥ `minShareForBad` of tekstlaag `index:-1` of `important:true`); `low` = ΔE < `lowDE`. WCAG-ratio als extra info.
- `pickFabric`: achtergrond die behouden blijft (`background.keep` of hexstring) → dichtste stof (`'background'`); anders beste gewogen ΔE-contrast (dunne lijnen tellen ≥ 5 %), nooit een stof ≈ hoofdkleur (`'contrast'`); geen kleuren → eerste stof (`'default'`). Stoffen met `enabled:false` of `stock:false` worden overgeslagen.
- `pickEdgeColor`: donkerste zichtbare kleur met ≥ 10 % aandeel, anders dominante zichtbare kleur, anders standaard zwart (lichte stof) / wit (donkere stof).

## Aanvullingen op het contract (extra velden, niets verwijderd)
- `ThreadMatch.special: 'black'|'white'|null`. `matchColor(hexOrLab, prepared, config, n=3, {kL})` — 5e parameter optioneel (kL=2 voor gsm-foto's van echte stof).
- Lege/ongeldige invoer: `matchColor` → `{thread:null, quality:'none', …}`; `pickFabric` zonder stoffen → `null`; `pickEdgeColor` zonder garens → `null`.
- `ReducedPalette` extra: `forced:boolean`, `maxDeltaE`, `maxColors`; per kleur `thin`, `keep`; per merge `reason:'same_thread'|'similar'|'forced'`, `fromThread`, `fromCode`, `intoCode`.
  **Advies**: `forced === true` betekent "kan niet zonder merkbare verandering" → reden voor warn/bad of techniekwissel.
- `ContrastCheck.share` extra. `contrastAgainst` leest optioneel `index` (bv. -1 voor tekstlaag) en `important` per item.
- `pickFabric` geeft extra `score` (contrast) of `deltaE` (background).

## Voor de integrator / andere bouwers
- **ui**: neem `colorMessages` op in `createT([...catalogi])` (of gebruik de `describe*`-helpers, die zelf een `t` maken uit `locale` + `config.strings`). Toon `colorDisclaimer()` bij de garenkleuren. Nooit `deltaE` tonen.
- **ui/render**: voorbeeld "vereenvoudigd" = `reducedHexes(reduced, 'thread')` per paletindex (+ `analysis.labels`).
- **advice**: paars-geen-garen = `match.quality === 'none'` (tekst `colors.match.none` beschikbaar); vereenvoudiging = `reducePalette(..., n)` met `n` uit `advice.threadColorChoices`.
- **production/woo**: garencode + naam: `threadByCode(code, config.threads)`; label via `threadLabel(thread, {config, locale})`.
- `prepareThreads` cachet per array-referentie: geef steeds `config.threads` (zelfde referentie) door.

## Open punten (eigenaar)
1. Hexwaarden zijn **schermkleuren** van de site, geen meetwaarden. Na een echte garenkaart-scan kunnen de hexen in `thread-palette.json` bijgewerkt worden (zelfde codes).
2. Standaard zwart/wit (1800 / 1801) bevestigen (staat al als `_openPoint` in defaults).
3. Wil de eigenaar méér dan 106 garens aanbieden? Dan wordt het config-budget (10 kB) overschreden; voorstel fase 2: volledige lijst server-side, publieke config enkel de actieve codes.
4. Metallic garens (goud/zilver) zitten bewust **niet** in het palet (geen site-data); ze lopen via `specials.metallic` (op aanvraag).
5. Fluo-garens: pas toevoegen als de juiste hexwaarden bekend zijn (`special:'fluo'`).
6. Franse namen/teksten laten nalezen door een moedertaalspreker.

## Afwijkingen van SPEC
Geen. Alleen bovenstaande extra (optionele) velden/parameters.
