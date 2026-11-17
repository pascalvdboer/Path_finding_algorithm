# Review "accuracy": klopt het advies?

Reviewer accuracy, 17-11-2026. Ik heb de hele keten getest zoals de UI hem draait: `analyzeImage` → automatische vorm, techniek, maat en garen (`ui/app.js#applyAutoChoices`) → `derive()` (kleuren, prijzen, advies). Dat gebeurde op 42 gegenereerde, realistische ontwerpen. Elk advies heb ik vergeleken met de vakregels uit onderzoek 04:

- borduren: letters minstens 4 mm, satijn vanaf 1 mm, steeksteek vanaf 0,4 mm, tussenruimte vanaf 1 mm, hoogstens 12 kleuren, geen foto;
- geweven: letters minstens 2 mm;
- bedrukt: letters minstens 1,6 mm, verder kan alles;
- PVC: geen verloop of foto, hoogstens 8 kleuren.

## Bestanden
| Bestand | Wat |
|---|---|
| `tests/analysis/accuracy-cases.js` | 42 cases: bouwfunctie (synth.js) + verwachting als **regel**, geen snapshot. Bijvoorbeeld: "borduren is niet bad", "aanbevolen ∈ printtechnieken", "tekst 3,4–4,6 mm". |
| `tests/analysis/accuracy-pipeline.js` | Keten zoals de UI hem draait, plus `digest()` en `check()`. Met de hand bekijken kan zo: `node tests/analysis/accuracy-pipeline.js <caseId…>`. Elke case toont dan `ok` of `FAIL: …`. |
| `tests/analysis/accuracy.test.js` | Draait mee in `npm test`. Cases met `knownGap` zijn node:test-TODO's: ze worden gerapporteerd, maar laten de suite niet falen. |

Metingen (`textMm`, `lineMm`) gebeuren altijd op de geborduurde lay-out op de startmaat van 8 cm. Zo betekent "4 mm" in elke case hetzelfde.

## Wat fout ging, en wat ik heb aangepast
1. **Nep-haarlijnen door anti-aliasing (ernstig).** Zwarte tekst op wit kreeg een rand van 1 px "wit garen", net als de rand van de crop. Dat gebeurde zodra wit ook ergens binnen het ontwerp voorkwam (bv. het binnenste van een A). Gevolg: fijnste lijn 0,25 mm, borduren op "warn", en het advies werd **bedrukt voor een tekst van 13 mm hoog**. Hetzelfde gebeurde bij goud op een donkerblauwe achtergrond die we behouden.
   Fix in `analysis/core.js` (`dropFringe`): een randpixel van het ontwerp waarvan de dichtstbijzijnde paletkleur gelijk is aan de achtergrond (ΔE < `fringeBgDE` = 12), wordt achtergrond. Het gaat om een mengzone die de achtergrond raakt, met hoogstens 2 px per pas. Geldt niet voor geometrische vormen.
2. **Mengkleuren door anti-aliasing bleven in het palet.** Bij kleine tekst kunnen mengkleuren 5–8 % halen. De limiet voor kandidaten stond op 3 %. Daarnaast haalde de lichtpaarse rand rond een paarse schijf 0,698 op de buurtest, terwijl de drempel 0,7 was. Hij bleef dus staan als "dunne lijn".
   Fix in `analysis/palette.js`: de share-limiet is nu `mixMaxShare` = 0,08, de buurtest 0,6, en een kleur die gedeeltelijk een mengkleur is (`mixLike`, ≥ 0,3) krijgt de dunne-lijnuitzondering niet meer. Dat laatste lost het geval op van de **gsm-foto van een eenvoudige patch**: daar maakte de ruis op de grens zwart/goud een lijn van 0,3 mm, zodat bedrukt werd aangeraden. Nu luidt het advies geborduurd.
3. **Halzen zijn geen lijnen** (`analysis/details.js`, `dropNecks`). Waar twee vlakken met een hoek tegen elkaar komen, of een tussenruimte tot een punt versmalt, is de middellijn maar een paar pixels lang. Smalle ridge-runs (≤ 4,5 px breed) van minder dan 4 px lang tellen niet mee voor de fijnste lijn of tussenruimte.
4. **Strook tussen ontwerp en crop-rand geen "tussenruimte".** Een cropbox die niet op hele pixels valt, gaf een gat van 1 px aan de rand. Dat valt nu weg in `details.js`.
5. **Testharnas**: `ui/derive.js` memoriseert garenmatches per `analysis.job`. De oude pipeline gaf elke analyse `job = 1`, waardoor cases elkaars kleuren erfden. Nu krijgt elke run een eigen job, zoals de UI ook doet. In de UI zelf zat geen fout.
6. Nieuwe instellingen in `config/defaults.json` → `analysis`: `"mixMaxShare": 0.08, "fringeBgDE": 12`, met dezelfde defaults in `ANALYSIS_DEFAULTS`.

Na de fixes: `npm run build` geeft groen (95,0 / 18,0 / 5,1 kB gzip) en `npm test` geeft 214 tests: 211 pass, 0 fail, 3 TODO. Ook `test:php` is groen. De bestaande analyse-, browser- (eigenaarsbeelden) en stitches-tests blijven groen.

## Resultaten (na de fixes)
Kolommen: kleuren = significante kleuren zoals de klant ze ziet, tekst/lijn in mm op 8 cm (geborduurde lay-out). Advies = aanbevolen techniek.

| case | soort | achtergrond | vorm | kleuren | tekst | lijn | advies | status bij 8 cm | test |
|---|---|---|---|---|---|---|---|---|---|
| `flat-club-logo` | flat | white | circle | 3 | – | 2,85 | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
| `flat-3col-text` | flat | white | – | 3 | 8,52 | 1,27 | druk | bord:warn gew:warn druk:ok pvc:ok | ok |
| `one-colour-silhouette` | flat | white | contour | 1 | – | – | bord | bord:ok gew:warn druk:ok pvc:ok | ok |
| `one-colour-text-big` | flat | white | – | 2 | – | 2,28 | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
| `two-colour-badge-rect` | flat | white | rect | 2 | – | 1,67 | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
| `shield-two-colour` | flat | white | shield | 2 | – | 15,44 | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
| `round-patch-arc-text` | flat | white | circle | 4 | 4,72 | 0,78 | druk | bord:warn gew:ok druk:ok pvc:ok | ok |
| `star-contour` | flat | white | contour | 1 | – | – | bord | bord:ok gew:warn druk:ok pvc:ok | ok |
| `small-text-fixable` | flat | white | – | 2 | 1,88 | 0,25 | druk | bord:warn gew:warn druk:ok pvc:warn | ok |
| `text-just-legal` | flat | white | – | 3 | 3,73 | 0,59 | druk | bord:warn gew:ok druk:ok pvc:ok | ok |
| `tiny-text-unfixable` | flat | white | circle | 2 | – | 5,67 | bord | bord:ok gew:ok druk:ok pvc:ok | TODO |
| `reverse-small-text` | flat | white | rect | 2 | 2,71 | 1,41 | druk | bord:warn gew:ok druk:ok pvc:warn | ok |
| `hairline` | flat | white | contour | 3 | – | 0,33 | druk | bord:warn gew:warn druk:ok pvc:warn | ok |
| `thin-line-run-stitch` | flat | white | circle | 2 | – | 0,78 | druk | bord:warn gew:ok druk:ok pvc:ok | ok |
| `line-ok` | flat | white | circle | 2 | – | 2,11 | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
| `small-gap` | flat | white | – | 1 | – | 29,98 | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
| `gradient-full-bleed` | gradient | none | rect | 7 | – | 1,67 | druk | bord:warn gew:warn druk:ok pvc:bad | ok |
| `gradient-radial-badge` | gradient | white | circle | 3 | – | 13,22 | druk | bord:warn gew:warn druk:ok pvc:bad | ok |
| `gradient-text-on-white` | illustration | white | rect | 8 | – | 0,33 | druk | bord:warn gew:ok druk:ok pvc:warn | ok |
| `photo-full-bleed` | photo | none | rect | 16 | – | 0,33 | subl | bord:bad gew:bad druk:ok pvc:bad | ok |
| `photo-in-circle` | photo | white | circle | 15 | – | 0,78 | subl | bord:bad gew:bad druk:ok pvc:bad | ok |
| `photo-jpg-noisy` | photo | none | rect | 16 | – | 0,33 | subl | bord:bad gew:bad druk:ok pvc:bad | ok |
| `jpg-flat-logo` | flat | white | circle | 3 | – | 2,85 | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
| `jpg-flat-logo-heavy` | flat | white | circle | 3 | – | 2,85 | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
| `jpg-flat-logo-heavy-no-photo-cut` | flat | white | circle | 3 | – | 2,85 | bord | bord:ok gew:ok druk:ok pvc:ok | TODO |
| `white-on-white-enclosed` | flat | white | circle | 2 | – | 12,78 | bord | bord:ok gew:ok druk:warn pvc:ok | ok |
| `white-element-touching-paper` | flat | white | – | 2 | – | 6,8 | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
| `transparent-flat` | flat | transparent | circle | 2 | – | 3,92 | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
| `transparent-white-shadow` | flat | transparent | circle | 2 | – | 2,11 | bord | bord:ok gew:ok druk:warn pvc:ok | ok |
| `coloured-background-kept` | flat | solid | – | 2 | – | 2,28 | bord | bord:ok gew:ok druk:warn pvc:ok | ok |
| `svg-flat` | flat | white | circle | 3 | – | 2,85 | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
| `svg-small-vector` | flat | white | circle | 2 | – | – | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
| `svg-gradient` | gradient | white | circle | 3 | – | 22,96 | druk | bord:warn gew:warn druk:ok pvc:bad | ok |
| `many-colours-flat` | illustration | white | – | 16 | – | 0,25 | druk | bord:warn gew:warn druk:ok pvc:warn | ok |
| `many-colours-flat-wording` | illustration | white | – | 16 | – | 0,25 | druk | bord:warn gew:warn druk:ok pvc:warn | TODO |
| `ten-colours-flat` | flat | white | – | 10 | – | 5,32 | druk | bord:warn gew:warn druk:ok pvc:warn | ok |
| `illustration-shaded` | illustration | white | contour | 13 | – | 0,78 | druk | bord:warn gew:warn druk:ok pvc:warn | ok |
| `illustration-round-patch` | illustration | white | circle | 8 | 5,56 | 0,78 | druk | bord:warn gew:ok druk:ok pvc:warn | ok |
| `low-res-logo` | flat | white | circle | 3 | – | 2,37 | bord | bord:ok gew:ok druk:warn pvc:ok | ok |
| `brand-purple` | flat | white | circle | 2 | – | 2,11 | druk | bord:warn gew:warn druk:ok pvc:ok | ok |
| `phone-photo-patch` | flat | white | circle | 2 | – | 9,67 | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
| `big-text-puff` | flat | white | circle | 2 | – | 4,78 | bord | bord:ok gew:ok druk:ok pvc:ok | ok |
## Klopt het advies met onderzoek 04? Mijn oordeel per groep
- **Vlakke logo's (1–3 kleuren, schild, rond, rechthoek, transparant, SVG, JPG-ruis):** geborduurd, status ok, juiste vorm, juist aantal kleuren. Wit dat binnen het ontwerp ligt telt als garen. Wit dat de pagina raakt is papier. Klopt.
- **Kleine tekst:** 1,9 mm → borduren `text_too_small` (warn, met grotere maat), bedrukt ok (geen `text_too_small`, enkel comfortnotitie). 3,7 mm → warn met "vanaf 13,5 cm". Die maat volgt uit de lijndikte (0,6 → 1 mm satijn), niet uit de letterhoogte. Klopt met de regels, al is het streng (zie open punt 3).
- **Lijnen:** 0,3 mm → warn, 0,8 mm → warn (steeksteek kan), 2 mm → ok. Kleine tussenruimte 0,8 mm → ok met reden `gap_too_small`. Klopt.
- **Verloop en foto:** verloop → bedrukt/gesublimeerd, PVC bad, borduren warn ("vereenvoudigen"). Foto → borduren, geweven en PVC bad, gesublimeerd aanbevolen. Klopt.
- **Illustraties met schaduw:** "illustration", nooit "photo". Borduren warn met vereenvoudiging tot ± N kleuren, bedrukt aanbevolen. Klopt met §9.
- **Veel kleuren:** 10 kleuren → borduren ok/warn (boven comfort 8). 20 kleuren → borduren warn ("te veel kleuren"), PVC warn. Klopt, maar zie bekende beperking B.

## Bekende beperkingen (TODO-cases, niet opgelost)
A. `tiny-text-unfixable`: pass B analyseert op **360 px**. Een lijn van 1,3 px in een bestand van 1400 px (voettekst van ± 0,5 mm) verdwijnt volledig. De tool waarschuwt dan niet, terwijl die tekst in geen enkele techniek leesbaar is. De digitale proef vangt dit op, maar beter is: `sizes.b` naar 480–512 (kost ± 2× rekentijd in de worker), of een aparte kleine-tekstscan op de preview (700 px).
B. `many-colours-flat-wording`: meer dan 16 vlakke kleuren (k-means k = 16) → "illustration" + reden `gradient`. De tekst zegt dan "illustratie met schaduwen". Dat is zeldzaam. De oplossing is k = 20 of een classificatie die kijkt naar harde randen.
C. `jpg-flat-logo-heavy-no-photo-cut`: een JPG van zeer lage kwaliteit (± 12 ruis op wit) krijgt de melding "we hebben je patch uit de foto geknipt". Dat is onschuldig.
D. Tekst hoger dan 15 % van het ontwerp wordt niet als "tekst" gemeten (by design, 07 §7). De lijndikte dekt die gevallen.

## Open punten voor de eigenaar
1. **Merkpaars #5926C5 bestaat niet als garen.** In de volledige lijst van 411 Polyneon-kleuren ligt geen garen binnen ΔE 10. Het dichtstbij komen 1566 Brilliant Blue (ΔE 10,5) en 1922 Regal Purple (ΔE 12,7). Het advies zegt daarom eerlijk "geen exacte garenkleur", en bij een eenvoudig paars logo wint bedrukt. Heb je toch een violet garen in huis, voeg het dan toe aan `thread-palette.json`. Anders blijft dit het juiste, eerlijke advies.
2. De straf voor "geen exacte garenkleur" (`PEN.noThread` = 10) laat borduren bij een paars logo zakken tot achter PVC. Dat is een keuze voor de advice-eigenaar. Ik heb het niet aangepast.
3. Satijn ≥ 1 mm (onderzoek 04) maakt kleine blokletters streng: een letter van 4 mm met een lijndikte van 0,6 mm vraagt 13,5 cm. In de praktijk borduurt men kleine letters vaak met smallere satijn. Laat de digitaliseerder `rules.lineMm` bevestigen.
4. Kalibreer de cases ooit met echte klantbestanden. De synthetische beelden testen de regels, niet elke soort lettertype.
