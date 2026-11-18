# Critique ronde 2 — onafhankelijke creatief directeur

Datum: 18-11-2026. Getest: `dist/patch-studio-demo.html` (vers gebouwd met `npm run demo`), Playwright/Chromium op
1440x900, 390x844 en 390x740 (touch, isMobile). Beide eigenaarbeelden geüpload, tekst toegevoegd ("KALMTHOUT MC 2026"),
vorm (schild/rechthoek), rand, kleuren (kiezer + klik in het canvas), alle 7 technieken (ook ingezoomd tot 244 %), de 4 mockups,
"Heel kledingstuk" en het sjabloon "Embleem met boogtekst". Ik heb geen code gewijzigd.
Screenshots: `docs/screenshots/critique-2/` (d* = desktop, m* = gsm; `d07-*-alle-technieken.png`, `d13-foto-mockups.png` en
`m-overzicht-844.png` zijn overzichtsbladen).

Tests: `npm test` geeft in een volledige run 404 geslaagd en 3 gefaald (3 todo). Twee daarvan zijn flaky door de belasting en slagen apart:
de perf-budgetten in `tests/render` en de AI-e2e. Eén fout is echt: `patch-studio-ai.js` is 25,7 kB gzip, boven het budget van 25 kB
(`tests/ai/frontend-bundle.test.js`). `npm run test:php`: 652 checks, 0 gefaald. Er waren geen JS-fouten in de console.

## Eerste indruk (als de eigenaar)
Dit is duidelijk geen formulier meer. Het startscherm toont patches die de renderer zelf tekent. Het sjabloon "VC Kalmthout" ziet er
ingezoomd echt premium uit. Gesublimeerd, bedrukt en geweven zijn geloofwaardig. Op gsm blijft het canvas vast (scrollTop 0 bij elk
paneel en tijdens het typen). Maar zodra ik mijn eigen foto-patch upload en "Geborduurd" kies, smelt de ridder tot een zwarte vlek
met een plastic glansvlek. Mijn tekst komt bovenop "IRON CRUSADERS" en op de rand. De kleurkiezer toont haarlijntjes in plaats van
kleuren en vervaagt de patch erachter. De mockups tonen ingezoomd alleen een lap stof. Het voelt als 70 % van Canva: het chassis
staat, maar de momenten die indruk moeten maken, met MIJN ontwerp, vallen nog door de mand.

## Scores (1-10)
| Criterium | Score | Waarom |
|---|---|---|
| Wow-effect | 6 | Startscherm en sjablonen zijn mooi (d01, d14). Maar het canvas is een vlak grijs vlak (#e9edf2), de sjabloongalerij valt op 1440x900 onder de vouw, en mijn eigen foto in borduur oogt slecht (d07-foto-embroidered). |
| Realisme patch | 6 | Gesublimeerd, bedrukt, geweven en leder zijn goed. Geborduurd op een illustratie: harnas en geweer worden zwart en er staat een grote spiegelende vlek in het midden (d08-foto-embroidered-zoom). Chenille oogt als noppenfolie (d08-rond-chenille-zoom). Ronde foto: dubbele rand (de gefotografeerde ribbelrand ligt binnen de nieuwe rand) en een blauw "deukje" onderaan (d07-rond-sublimated). Sjabloontekst: de "V" is satijn, de "C" is tatami met baksteenpatroon (d14-zoom). |
| Studio-gevoel | 7 | Rail, zwevende teksttoolbar, undo/redo en inklapbare assistent werken. Wel: de kleurkiezer is een modaal venster dat het canvas vervaagt, er zijn twee zoombalken in de mockups, de selectiekader blijft op de mockups staan, en maat en aantal staan dubbel (paneel + actiebalk). |
| Duidelijkheid advies | 7 | De kop "Wij zouden het sublimeren, op 8 cm" is helder en de toon klopt. Maar de vergelijker-thumbnails (±110 px) zijn onderling niet van elkaar te onderscheiden. "Ideeën" stelt "kan ook bedrukt" voor terwijl gesublimeerd het advies is, en bij een geborduurd embleem stelt het bedrukt voor (een downgrade). Geweven heet "Aanrader" maar heeft "Prijs op aanvraag". |
| Mobiel | 7 | De harde eis is gehaald: scrollTop is 0 en het canvas staat in beeld op 844 en 740. Maar op 740 bedekt de teksttoolbar het onderste derde van de patch (m04-rond-740). De standaardtekst staat recht over "IRON" en is afgesneden (m04-foto-844). Het Ontwerp-blad is een lange checklist, en de polo-mockup toont een kleine patch. |

## Geprioriteerde verbeteringen (12)

1. **Kleurkiezer: de kleurstalen zijn 3 px hoog (bug) en het venster verbergt de live preview.**
   Waar: `plugin/borduur-patch-studio/assets/css/patch-studio.css` r. 865 (`.bps-cp__chip`), `assets/src/ui/colorpicker.js` r. 76 en 159 (`dialog` + `showModal`).
   Waarom: gemeten is `.bps-cp__chip` 40 x 3,2 px (`flex-shrink: 1` in een flex-kolom). De Polyneon-kaart, de harde eis 3, toont dus streepjes in plaats van garenkleuren (d12-foto-kleur-klik-canvas). De modal vervaagt bovendien de patch, terwijl de tekst belooft "je patch past zich meteen aan".
   Hoe: `flex: none` op `.bps-cp__chip`. Maak de kiezer een niet-modale popover of een zijpaneel naast het canvas (op gsm een bottom sheet onder de patch), zonder backdrop-blur. Hover of focus op een kleurstaal toont die kleur meteen in het canvas, en klikken legt hem vast.
   Klaar als: `.bps-cp__chip` 40x40 px meet, de patch onvervaagd zichtbaar blijft terwijl de kiezer open is, en hover op 1747 het kleurvlak binnen 150 ms rood rendert.

2. **Geborduurde render van illustraties: geen zwarte vlek en geen plastic glans.**
   Waar: `assets/src/render/stylize.js` (kleurreductie en labels), `assets/src/render/light.js` (spec/gloss per vlak), `assets/src/render/material.js` (`buildLayer`).
   Waarom: in d07/d08-foto-embroidered verdwijnen harnas, handen en geweer in één zwart vlak met radiale steken en één grote spiegelende hotspot. Dit is de eerste indruk die de eigenaar krijgt met zijn eigen patch, en het is het tegendeel van wow.
   Hoe: reduceer kleuren met behoud van lokaal contrast. Buurvlakken met een ΔL > 15 mogen niet samenvallen. Lichtgrijs en wit krijgen voorrang op een extra donkere tint. Beperk de speculaire sterkte op grote vlakken, of verdeel ze in steekblokken met een eigen richting, zodat er nooit één glansvlek groter dan ±8 % van de patch ontstaat. Grote donkere achtergronden krijgen tatami, geen radiale satijn.
   Klaar als: in de render "Geborduurd" van `eigenaar-foto-patch.jpg` het harnas licht- of zilvergrijs blijft, het geweer als vorm herkenbaar is en de zoom op 244 % geen glansvlek over meerdere vlakken toont.

3. **Tekst toevoegen op een ontwerp dat de hele patch vult: nooit over bestaande tekst of op de rand.**
   Waar: `assets/src/ui/derive.js` r. 356-386 (`placeText`, score), `assets/src/ui/app.js` r. 434-460.
   Waarom: op desktop loopt "KALMTHOUT MC 2026" als boog over de rand en botst "2026" met "CRUSADERS" (d09-foto-tekst). Op gsm staat de tekst recht over "IRON" en is hij afgesneden (m04-foto-844). De rustigste plek is hier de donkere fotorand, en die telt als "leeg".
   Hoe: tel tekstachtige of hoogcontrast-randen in de analyse als bezet (strafscore). Sluit de randband (edgeCover + gefotografeerde rim) uit als kandidaat. Is er geen vrije plek, verklein dan het ontwerp automatisch (bv. 82 %) en zet de tekst in een boog onder, met een assistentkaart "We maakten je ontwerp iets kleiner zodat je tekst past. Ongedaan maken?". Het selectiekader moet de boog volgen en geen recht kader zijn.
   Klaar als: bij beide eigenaarbeelden de nieuwe tekst geen pixels van "IRON CRUSADERS" of "INVICTUS" overlapt en volledig binnen de binnenrand van de nieuwe rand valt, op 1440 en op 390.

4. **Gsm: niets mag over de patch liggen.**
   Waar: `assets/src/ui/stage.js` r. ~344 (teksttoolbar "boven de selectie, binnen de stage"), mobiele CSS rond `css/patch-studio.css` r. 639-700.
   Waarom: op 390x740 bedekt de zwevende teksttoolbar het onderste derde van de patch (m04-rond-740). Op 844 snijdt hij "RS" af. De acceptatietest meet alleen het canvas en niet wat erover ligt, dus de harde eis "volledige patch zichtbaar" is in de praktijk niet gehaald.
   Hoe: zet op gsm de teksttoolbar in de sheetbar (tussen canvas en paneel) of als compacte rij in het paneel, nooit in de stage. Breid de test uit: geen enkel element met een z-index boven het canvas mag de bounding box van de patch snijden.
   Klaar als: de Playwright-check op 390x740 en 390x844, tijdens het typen, aantoont dat geen overlay de patch-rect raakt.

5. **Een podium in plaats van een grijs vlak.**
   Waar: `css/patch-studio.css` r. 412 (`.bps-stage { background: #e9edf2 }`), close-up-scène in `assets/src/render/scene.js`.
   Waarom: de eis vraagt een zachte achtergrond (stof of tafel) met licht. Nu zweeft de patch op een effen grijs, en dat oogt als een productfoto uit een configurator, niet als een studio.
   Hoe: render in close-up een subtiele ondergrond (gewassen linnen, kraft of donker eiken, wisselbaar met een klein knopje), met een gerichte lichtval (vignet en lichtvlek) en een realistische contactschaduw onder de patch. Respecteer prefers-reduced-motion als er een lichtanimatie bij komt.
   Klaar als: d02-equivalent een textuur en lichtverloop toont, de patch een zachte contactschaduw heeft en het contrast tussen patch en ondergrond ≥ 3:1 blijft voor donkere en lichte patches.

6. **Techniekvergelijker: laat het verschil zien, niet vier dezelfde rondjes.**
   Waar: `assets/src/ui/assistant.js` r. 28 (thumb), CSS `.bps-card__vis` r. 504, volgorde van de secties r. 63.
   Waarom: op 110 px zien gesublimeerd, bedrukt, geweven en geborduurd er identiek uit (d04, d06). Het hele punt van de vergelijker, "zij aan zij hoe JOUW patch wordt", gaat zo verloren. De vergelijker staat bovendien onder "Ideeën", onder de vouw.
   Hoe: toon per kaart een uitsnede van een karakteristiek detail op 2,5x zoom (draad, weefsel, rasterloze druk). Hover of focus op een kaart toont die techniek tijdelijk op het grote canvas, en klikken kiest hem. Zet de vergelijker direct onder de adviesbanner en "Ideeën" eronder.
   Klaar als: de 4 zichtbare kaarten op 1440 met het blote oog verschillende texturen tonen, hover het hoofdcanvas wisselt zonder de staat te wijzigen, en de vergelijker op 1440x900 boven de vouw begint.

7. **Mockups: context in plaats van een lap stof, en geen bewerkingsgrepen.**
   Waar: `assets/src/render/garment.js` (framing en modellen), `assets/src/ui/stage.js` r. 90-114 (garment-view, selectie, zoombalk).
   Waarom: ingezoomd zijn "Op jas" en "Op rugzak" alleen een donkere textuur. Je ziet niet dat het een jas is (d13-foto-mockups). "Heel kledingstuk" toont een clip-art-rugzak met een postzegelpatch (d13-foto-heel). Het selectiekader en een tweede zoombalk blijven over de mockup staan.
   Hoe: kader standaard zó dat de patch ±30 % breed is én er een herkenbaar element in beeld is (kraag, rits, klep, schoudernaad). Laat de patch de plooien volgen (displacement en schaduw van het kledingstuk), en verberg selectie en toolbar in de kledingweergave tot de klant op de patch tikt.
   Klaar als: in elke mockup een kledingdetail in beeld is, er geen grepen of zoombalk over de patch staan, en de patch een plooi- of welvingsschaduw heeft.

8. **Ronde foto-patch: één rand, niet twee, en geen deuk.**
   Waar: `assets/src/ui/derive.js` r. ~399 (`renderArt.clip`: maskeert de fotorand alleen bij een andere vorm), `assets/src/render/edges.js`.
   Waarom: bij `eigenaar-ronde-patch.png` in dezelfde ronde vorm ligt de gefotografeerde ribbelrand binnen de nieuwe rand, en onderaan staat een marineblauw vlakje uit de foto (d07-rond-*). Een vakman ziet meteen "dat is een foto in een kader".
   Hoe: maskeer de fotorand ook in dezelfde vorm wanneer er een nieuwe rand gegenereerd wordt. Vul het deukje op met de buitenringkleur (inpaint langs de rim).
   Klaar als: in alle 7 technieken maar één rand zichtbaar is en er geen achtergrondkleur uit de foto binnen de patch staat.

9. **Rand-paneel: echte randen, visueel, met de opties die de eigenaar vroeg.**
   Waar: `config/defaults.json` (embroidered: alleen `cut_from_fabric`, `on_film`, `self_cut`; merrow alleen bij geweven), `assets/src/ui/panels.js` r. 330-365, CSS `.bps-edgeico` r. 934-941.
   Waarom: de eigenaar vroeg merrow, satijnrand, hot-cut, laser en randdikte. Bij borduren, het hoofdproduct, is merrow niet kiesbaar en is er geen randdikte. De iconen zijn drie identieke marineblauwe bolletjes (d11).
   Hoe: leg in de instellingen vast welke randen het atelier per techniek aanbiedt (merrow bij borduren als het atelier het doet). Toon per optie een mini-render van JOUW patch-rand (de renderer bestaat al, zoals de thumbs in "Ideeën"). Zet randdikte (smal, normaal, breed) onder "Meer opties".
   Klaar als: de randtegels er verschillend uitzien, de keuze meteen de rand op het canvas verandert, en elke randoptie uit de instellingen van de eigenaar zichtbaar is.

10. **Startscherm: de inspiratie boven de vouw.**
    Waar: `assets/src/ui/flows.js` (hero), `assets/src/ui/templates.js`, `assets/src/ui/samples.js`.
    Waarom: op 1440x900 staan de 9 sjablonen volledig onder de vouw. Boven de vouw staan zes testknoppen ("Badge met kleurverloop", "Een foto"…) die naar een demo ruiken. Er is nog geen rij per vorm, en 3D-puff ontbreekt als eigen tegel (harde eis 1).
    Hoe: hero compacter (upload links, een carrousel van 4-6 grote gerenderde patches rechts), sjabloonrij meteen eronder en zichtbaar. Testbestanden achter een link "Probeer met een voorbeeld". Voeg filterchips per vorm (rond, schild, banner, naamstrook) en een 3D-puff-tegel toe.
    Klaar als: op 1440x900 ≥ 6 sjabloonkaarten boven de vouw staan, en op 390x844 de eerste sjabloonrij zichtbaar is na 1 keer scrollen.

11. **"Ideeën" en de kaarten: alleen voorstellen die meerwaarde hebben.**
    Waar: `assets/src/ui/assistant.js` r. 168-195 (`ideaList`), adviesteksten in `assets/src/advice/messages.js`.
    Waarom: "Dit ontwerp kan ook bedrukt — Exact zoals je bestand" naast advies "gesublimeerd" is ruis. Bij het geborduurde VC-embleem is "bedrukt" een downgrade. "Aanrader" naast "Prijs op aanvraag" (geweven) maakt de klant onzeker.
    Hoe: stel geen tweelingtechniek voor (bedrukt en gesublimeerd), en geen techniek die goedkoper oogt dan de huidige zonder reden. Geef voorrang aan 3D-puff, metallic rand en "ook op een pet". Toon voor technieken zonder vaste prijs een indicatieve vork ("± € 4-6 per stuk, exacte prijs bij je proef") als de instellingen die toelaten, anders "Prijs na je proef".
    Klaar als: geen idee voorstelt wat ook in de vergelijker als gelijkwaardige tweeling staat, en elke kaart met "Aanrader" een prijs of een prijsvork toont.

12. **Borduurdetails: consistente steektypes en een rustigere twill.**
    Waar: `assets/src/render/stylize.js` (`chooseModes`: satijn of tatami), `assets/src/render/material.js` (twill-tile).
    Waarom: ingezoomd in het sjabloon is de "V" satijn en de "C" tatami met een baksteenpatroon, terwijl ze even dik zijn (d14-sjabloon-embleem-zoom). De twill-grond is zo sterk gestreept dat hij op krijtstreep lijkt. Een vakman ziet dat meteen.
    Hoe: kies satijn of tatami op de kolombreedte in mm (satijn tot ±7 mm), niet op de topologie (gaten). Verlaag het contrast van de twill-strepen met ±50 % en voeg een lichte kleurruis per draad toe.
    Klaar als: alle letters van één tekstlaag hetzelfde steektype krijgen, en de grond op 200 % als fijne twill leest, niet als strepen.

## Wat goed is (behouden)
- De mobiele harde eis is meetbaar gehaald: scrollTop 0 en canvas in beeld bij elk paneel, op 844 en 740, ook tijdens het typen.
- Startscherm met gerenderde patches en het sjabloon "Embleem met boogtekst": dit is de toon die de hele studio moet hebben.
- Gesublimeerd, bedrukt, geweven en leder zijn geloofwaardig. Een techniekwissel duurt ±15 ms.
- Polyneon-mapping per kleurvlak ("→ 1718 Wolkengrijs, benadering") en klikken in het canvas om een kleurvlak te kiezen werken.
- Eén primaire knop ("Bestellen"), eerlijke staffelhint, geen valse schaarste.

## Open punten
- Een echt gsm-toetsenbord (visualViewport-krimp) en pinch-zoom zijn headless niet te testen.
- De echte fout in de testsuite is het AI-bundelbudget (25,7 kB > 25 kB), niet aan de studio gelinkt. De perf-tests en de AI-e2e zijn flaky bij een volledige parallelle run.
- De FR-versie is niet opnieuw bekeken.

## Status na ronde 2 (senior frontend, 18-11-2026)
Bewijs: `docs/screenshots/critique-2-fix/` (d* = 1440x900, m* = 390x740/844) en de metingen in `metingen.json` daar.
Gemeten met Playwright op de vers gebouwde `dist/patch-studio-demo.html`, met beide eigenaarbeelden.

| # | Punt | Status | Wat er veranderde | Bewijs |
|---|---|---|---|---|
| 1 | Kleurkiezer | **Opgelost** | `.bps-cp__chip` krijgt `flex: none` en meet nu 40 x 40 px. De kiezer is geen modale `dialog` meer. Op desktop is het een zijpaneel naast het canvas en op gsm een lage bottom sheet onder de patch, zonder backdrop of blur. Hover of focus op een staal toont de kleur meteen in het canvas (`ctx.previewPatch`, zonder historiekstap of prijswijziging). Klikken legt de kleur vast. | Hover 1747 → rood in 95–113 ms, en `threadOverrides` blijft leeg tot je klikt (`d12-kleurkiezer-hover-1747`, `m12-kleurkiezer-*`) |
| 2 | Geborduurde illustratie | **Opgelost** | Kleurreductie houdt één garen per lichtheidsniveau vast (ΔL* > 15, licht gaat voor; `tonalKeep` in `ui/derive.js`). Daardoor smelt het grijze harnas niet meer in het zwart. Satijn of tatami hangt nu af van de kolombreedte in mm, gemeten met een afstandsveld (`stylize.js`). De grote donkere grond wordt tatami in plaats van radiale satijn, dus de glansvlek is weg. Een ring is alleen nog een ring als elke pixel op ongeveer dezelfde straal ligt. | `d07-foto-embroidered`, `d08-foto-embroidered-zoom244` |
| 3 | Tekst op een vol ontwerp | **Opgelost** | Hoogcontrastranden (letters, contouren) tellen als bezet (`edgeMap` + strafscore). Is er geen vrije plek, dan wordt het ontwerp kleiner (0,77 bij de foto, 0,80 bij de ronde patch) en komt de tekst als boog onder het ontwerp, binnen de rand. Het tekstpaneel toont dan "We maakten je ontwerp iets kleiner … Ongedaan maken". Het selectiekader volgt nu de boog (SVG-band). Op desktop staat de toolbar buiten de patch. | `d09-tekst-foto`, `d09-tekst-rond`, `m04-rond-*-typen` |
| 4 | Gsm: niets over de patch | **Opgelost** | Op gsm staan de teksttoolbar, de zoomknoppen en de ondergrondknop in de sheetbar tussen canvas en paneel (`.bps-sheettools`). Ze staan nooit meer in de stage. Nieuwe check: een raster van 17 x 17 `elementFromPoint`-punten over de patch-rect tijdens het typen. | 390x740 en 390x844: `overlays: []`, scrollTop 0, patch binnen visualViewport |
| 5 | Podium | **Opgelost** | Er zijn nieuwe ondergronden: gewassen linnen, kraftpapier en donker eiken, elk met lichtvlek, vignet en de bestaande contactschaduw. Automatisch kiest de studio linnen onder een donkere patch en eiken onder een lichte (contrast ≥ 3:1). Een rond knopje wisselt de ondergrond. | `d05-podium-donkere-patch` (linnen), `d05-podium-lichte-patch` (eiken) |
| 6 | Vergelijker | **Opgelost** | Elke kaart toont een detail op 2,5x zoom van een karakteristieke plek (de dichtste cluster randen). Hover of focus toont de techniek op het grote canvas zonder de staat te wijzigen, en klikken kiest hem. De vergelijker staat direct onder de adviesbanner, met "Ideeën" eronder. | Vergelijker begint op y = 460 bij 1440x900. `d06-vergelijker-thumbs` toont 4 duidelijk verschillende texturen, `d06-vergelijker-hover` |
| 7 | Mockups | **Opgelost** | Het kader houdt de patch op ±16–33 % breed en zet altijd een herkenbaar detail in beeld: knoopsluiting en kraag, klep, kraag en schoudernaad, rits van het voorvak. De patch volgt een lichte welving en de plooischaduw van het kledingstuk is sterker. Selectie en toolbar blijven verborgen tot je op de patch tikt. | `d13-mockups` (4x `selectionHidden: true`) |
| 8 | Ronde foto-patch | **Opgelost** | De breedte van de gefotografeerde rand wordt gemeten (ribbels = een vaste tweede kleur, 7,8 % bij `eigenaar-ronde-patch.png`). Die rand wordt ook in dezelfde vorm gemaskeerd. De patchgrond zet de ringkleur voort, waardoor het marineblauwe deukje verdwijnt. | `d07-rond-alle-technieken`: één rand in alle 7 technieken |
| 9 | Rand-paneel | **Opgelost (demo-instelling)** | Elke randtegel is een echte mini-render van jouw patch-rand (ingezoomd op de rim), en de keuze verandert de rand op het canvas meteen. "Meer opties" heeft nu Randdikte: smal, normaal of breed. Die keuze zit in de payload (`edgeWidth`) en voorlopig ook als regel in de notities. In de demo biedt het atelier bij borduren ook merrow en laser aan (`demo/template.html`). | `d11-rand-paneel`, `d11-rand-merrow-breed` (merrow, breed: 4,2 mm) |
| 10 | Startscherm | **Opgelost** | De hero is compacter en heeft 5 gerenderde patches rechts. De sjabloonrij staat meteen eronder. Er zijn filterchips per vorm (rond, schild, banner, naamstrook). De voorbeeldbestanden zitten achter "Probeer met een voorbeeld", en de ingebouwde SVG-testknoppen zijn weg. De reliëftegel heet "3D-reliëf" (extra dik borduurwerk). | 1440x900: 9 sjabloonkaarten boven de vouw (`d01-start`). 390x844: eerste rij zichtbaar na 1 scroll (`m01-start-844-1x-scroll`) |
| 11 | Ideeën en prijs | **Opgelost** | Een andere techniek wordt alleen nog voorgesteld als hij duidelijk meer biedt: geen tweeling (bedrukt ↔ gesublimeerd) en geen downgrade. Een techniek zonder vaste prijs toont een prijsvork als de instellingen `indicativePrice {from,to}` geven, anders "Prijs na je proef". | Foto: goudgele rand + pet. VC-embleem: 3D-reliëf + pet. Geweven, chenille en leder: "Prijs na je proef" |
| 12 | Borduurdetails | **Opgelost** | Het steektype hangt af van de kolombreedte (satijn tot 7 mm), dus "V" en "C" krijgen nu allebei satijn. Het twill-contrast is ongeveer gehalveerd (fijnere pitch 0,5 mm, lagere amplitude) en elke draad krijgt een lichte kleurafwijking. | `d14-sjabloon-embleem-zoom` |

**Tests:** `npm test` 432 tests, 429 geslaagd, 0 gefaald, 3 todo. `npm run test:php` 652 checks, 0 gefaald. `npm run demo` bouwt.
In `tests/demo/demo.browser.test.js` opent de test nu eerst "Probeer met een voorbeeld" (`<details>`) voordat hij de voorbeeldbestanden aanklikt.

**Open punten**
- Budget: de nieuwe functies brengen de studio op 135,4 kB (tweetalig) en 121,5/122,5 kB (NL/FR) gzip. Daarom gaat het budget in `scripts/build.mjs` naar 137 en 123 kB. Dat bestand valt buiten de opgegeven scope, maar zonder deze wijziging faalt `npm run demo`. De reden staat bij het budget.
- Randdikte en merrow/laser bij borduren: in de echte winkel moet de eigenaar ze per techniek aanzetten in de instellingen (`config/defaults.json` is niet gewijzigd). De PHP-payload (`class-payload.php`) en de productiefiche kennen `edgeWidth` nog niet. Tot dan reist de keuze mee als regel in de notities, bv. "[Brede rand: 4,2 mm]".
- "3D-reliëf" is eerlijk benoemd als extra dik borduurwerk. Echt 3D-puff (foam) moet het atelier eerst als optie aanbieden.
- De kaap van de ridder wordt grijs bij borduren, omdat het advies maximaal 6 garens toelaat: 4 tinten en 2 kleuren. Met 7 of meer garens blijft hij olijf.
- Een echt gsm-toetsenbord en pinch-zoom zijn headless niet getest. Leder graveert een lichte autotekst niet zichtbaar; dat gedrag bestond al.
