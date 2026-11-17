# Handoff — advice

Bouwer: advice · SPEC §7.7 · tests: `tests/advice/advice.test.js` (31 tests, `npm test` groen, totaal 93/93).

## Geleverde bestanden (`plugin/borduur-patch-studio/assets/src/advice/`)
| Bestand | Inhoud |
|---|---|
| `index.js` | Publieke API: `evaluate`, `messages` (contract) + extra's `adviceMessages` (alias), `measure`, `layoutFor`, `optionsForTechnique`, `minSizeForTechnique`. |
| `evaluate.js` | De engine: oordeel per techniek, ranking, samenvatting, maat-, kleur-, upsell-, vertrouwens- en waarschuwingsadvies. Puur, deterministisch. |
| `measure.js` | Metingen in mm per techniek via `shared/geometry.js#layoutPatch()` (rand/safe zone per techniek), maatzoeker ("Maak 8 cm"), kleurentelling. |
| `context.js` | Vertaal-/opmaakcontext (createT + gecachete getalnotatie, bedragen incl. btw via `formatMoney`). |
| `messages.js` | Catalogus NL ("je") + FR ("vous"), 165 sleutels `advice.*`, identieke sleutelsets. |

## Gedrag (kort)
- **Meten**: per techniek eigen layout (merrow 3 mm safe zone ≠ satijnrand 2 mm). Tekst: `capPx` én x-hoogte getoetst tegen `textCapsMm`/`textLowerXHeightMm` × `serifScriptFactor` (bij `serifOrScript`). Tekstlagen: `heightMm` × factor als het font `thin|serif|script` is. Gemeten waarden worden in teksten **naar beneden** afgerond op 0,1 mm (nooit mooier voorgesteld dan het is).
- **Status**: `bad` = harde grens die met geen maat ≤ `maxCm` op te lossen is, foto bij `photo:false`, verloop bij `gradients:false`, `shadedIllustration:'no'`; `warn` = kan na concrete aanpassing; anders `ok`. Lijnen/tussenruimtes zijn nooit blokkerend (digitaliseerder maakt dikker/ruimer), wel warn/info.
- **Volgorde oplossingen**: eerst maat (geprijsde maat ≤ 12 cm, anders grid-maat "via offerte"), dan vereenvoudigen, dan techniek wisselen. Elke warn/bad heeft ≥ 1 fix; rode kaarten krijgen altijd een "Kies {techniek}" (best groene, anders best oranje) of "Laat een vakman meekijken".
- **Ranking**: basis-geschiktheid per ontwerptype (vakkennis 04 §2–9, 05 §4.3) − gemeten strafpunten − kleine prijsfactor (max 6 punten, enkel met `quotes`). `preferOrderable` bij verschil ≤ `closeScoreDelta`. Gelijkspel wordt eerlijk benoemd ("Geborduurd en bedrukt zijn hier allebei goed: …").
- **Samenvatting** (≤ 3 zinnen) per type: vlak logo, illustratie (geweven / geborduurd tot ± k garens / bedrukt exact), foto, verloop, gsm-foto van patch (nabestelling), tekst-only, niet-analyseerbaar bestand. Bij een vlak logo waar borduren pas groter kan: "Borduren kan ook, vanaf 10 cm: …".
- **Garenkleuren**: één `reducePalette(pal, 1)` → aantal "natuurlijke" (niet-geforceerde) samenvoegingen → kleinste keuze uit `threadColorChoices` (gecachet per analyse).
- **Upsells** (max `advice.maxUpsells`, prioriteit): 3D-puff (kleinste tekst ≥ `minTextCapsMm`, ≤ `maxColors` kleuren) · metallic (goud-/zilvertint ≥ 3 %) · klittenband (use case uniform/werkkleding/airsoft… of PVC, met echte meerprijs incl. btw) · op folie (geborduurd ≤ 6 cm) · één maat groter als tekst legaal maar onder comfort is. Specials `on_request` → actie `{type:'quote'}` met "Op aanvraag; we nemen het mee in je proef".
- **Vertrouwen**: enkel uit `config.promises` (proef, vanaf 1 stuk, reactietijd; `proofDays` → "binnen n werkdagen"). Niets wat niet in de config staat.
- Snelheid: ± 3 ms bij een nieuwe analyse (16 kleuren), < 1–2 ms per wijziging daarna (test bewaakt ≤ 5 ms).
- Gecontroleerd op de echte eigenaarsbeelden (Playwright-decode + echte `analyzeImage`): ronde illustratie → illustratie-samenvatting met ± 5 garenkleuren, mock-up-melding; gsm-foto → nabestellingstekst + "uit de foto geknipt".

## Aanvullingen op het contract (extra, niets verwijderd)
1. **`input.context`** (optioneel): `{ useCase?: string, garmentFabric?: string }`. `useCase` (`uniform`, `werkkleding`, `airsoft`, `scouts`, …) activeert de klittenband-upsell en het opnaai-advies; `garmentFabric` (`leather|nylon|fleece|coated|softshell`) + gekozen `iron_on` → "strijklaag houdt niet". Leest ook `design.useCase`. **Voor de integrator/ui**: DesignState heeft (nog) geen veld hiervoor; een optionele vraag "Waarvoor gebruik je de patch?" in stap 4 maakt dit advies actief. Zonder veld: geen schade, upsell verschijnt dan enkel bij PVC.
2. **Fix-acties op een andere kaart** dan de gekozen techniek bevatten ook `technique` + `techniquePicked:true` in `patch` (een fix kiezen op de kaart "Geweven" kiest ook geweven). Optiepatches bevatten de volledige optielijst voor die techniek.
3. `summary.text` heeft extra veld `parts: Msg[]` (de losse zinnen); `key/params` = eerste zin, `text` = alle zinnen.
4. Reden-codes (naast die uit types.js): `text_ok`, `text_small`, `line_ok`, `line_small`, `line_too_thin`, `gap_too_small`, `colors_ok`, `colors_many`, `monochrome`, `gradient`, `photo`, `no_thread_match`, `low_res`, `merrow_small`, `size_too_large`. `severity:'ok'`-redenen zijn geruststellende feiten ("Je fijnste lijn wordt 1,4 mm, ruim boven het minimum").
5. Trust-Msg's hebben sleutels `advice.trust.proof|from_one|response` met tekst uit de config; `config.strings` kan ze overschrijven.
6. Extra catalogussleutels voor de UI: `advice.status.ok|warn|bad` (Aanrader / Kan, met aanpassing / Raden we af), `advice.badge.recommended`, `advice.source` ({city}), `advice.disclaimer`.

## Afhankelijkheden
- `../colors/index.js`: `prepareThreads`, `reducePalette`, `pickFabric` (via index, zoals SPEC §2).
- **ui** geeft mee: `colors.reduced` (voor de gekozen `maxThreadColors`), `colors.matches` (paars-zonder-garen), `colors.contrast` (tegen huidige stof) en `colors.fabricHex`; `quotes` = `pricing.quoteAll(...)` (optioneel). Zonder `colors` werkt alles, alleen contrast/garen-match-advies valt weg.
- `autoChoices.fabric` = `colors.pickFabric(...)` op de (gereduceerde) kleuren, met behouden achtergrond.

## Voor de integrator
- **Bundelgrootte**: advice ≈ 20 kB gzip (catalogus NL+FR ≈ 9 kB, engine ≈ 9 kB). Past in het 60 kB-budget naast de rest, maar is niet klein. Mocht het knellen: de FR-catalogus kan uit de bundel en via `config.strings` (PHP `.po`) aangeleverd worden — de engine leest overrides al per sleutel.
- `createT([uiMessages, adviceMessages, colorMessages, …])` in de UI mag; de engine maakt zelf haar teksten, dus de UI hoeft adviesteksten niet opnieuw te vertalen (wel mogelijk via `key` + `params`).

## Open punten (eigenaar)
1. 3D-puff en metallic: aanbod/meerprijs bevestigen (`specials.*._openPoint`); nu "op aanvraag" via de offerte.
2. Scoregewichten (`KIND_FIT`, `PEN` in `evaluate.js`) zijn vakmatig gekozen, niet gemeten. Gevolg vandaag: bij een illustratie met schaduwen wint bedrukt (exact) meestal nipt van geborduurd (na vereenvoudiging); de samenvatting noemt altijd alle drie eerlijk. Wil de eigenaar borduren vaker als eerste advies bij illustraties, dan is dat één getal (`KIND_FIT.illustration.embroidered`). Eventueel later naar de config verhuizen.
3. Merrow-controle gebeurt op vorm-id (`options[merrow].shapes`) en `hardWithMerrow`; holle hoeken binnen een "schild" detecteren kan pas als analysis convexiteit meegeeft.
4. Franse teksten laten nalezen door een moedertaalspreker.

## Afwijkingen van SPEC
Geen. Enkel bovenstaande optionele extra's.
