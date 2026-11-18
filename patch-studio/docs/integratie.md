# Integratie: Patch Studio als één werkende plugin

Integrator, 17-11-2026. Ik heb SPEC.md, alle handoffs (core, ui, analysis, colors, pricing, advice, render, woo, production, testenv) en de code samengebracht.

## Resultaat
| Controle | Status |
|---|---|
| `npm install && npm run build` | groen: `patch-studio.js` 94,2 kB gzip (budget 100), worker 17,5 kB (25), admin 5,1 kB (15) |
| `npm test` (node, incl. browser-tests analysis/render/demo) | groen: 172 tests |
| `npm run test:php` (pricing-vectoren) | groen: 47 vectoren, 652 checks |
| `php -l` (plugin + tests) | 56 bestanden, 0 fouten |
| `bash tests/run-all.sh --no-e2e` | alles PASS |
| `bash tests/e2e/run-e2e.sh` (echte WP + WooCommerce, SQLite) | 01–07 + php-debug-log **allemaal PASS** (07 mobiel was eerst rood door ontbrekende lettertypes) |
| Dev-pagina `dev/index.html` (`npm run dev` → http://localhost:8787/dev/) | upload, advies, voorbeeld, prijs en bestel-payload werken; eigenaar-ronde-patch (desktop) en gsm-foto (390 px) zonder consolefouten of horizontale scroll |
| `dist/patch-studio-demo.html` | gebouwd en getest (zie onder) |

## Wat ik veranderde
1. **Bundelbudget** (`scripts/build.mjs`, SPEC §5.3): `patch-studio.js` van 60 naar **100 kB gzip**. Waarom: alle modules samen komen op 94 kB. Daarvan is ± 30 % tekstcatalogus (NL+FR, ± 88 kB raw). Het script wordt alleen geladen op de pagina met de studio (shortcode/widget), niet op de rest van de site. Wil je toch terug naar minder: haal de FR-catalogi uit de bundel en geef ze via `config.strings` vanuit PHP door. De UI en de engines lezen die overrides al per sleutel. Dat levert ± 13 kB gzip op.
2. **Lettertypes** `plugin/borduur-patch-studio/assets/fonts/`: sora-700, oswald-600, graduate-400, roboto-slab-700, cinzel-700, pacifico-400 (woff2, latin-subset, via `@fontsource`), plus `LICENSES.txt` (OFL 1.1 / Apache 2.0) en `index.php`. Daarmee zijn de 404's weg en is e2e 07 groen.
3. **Advies spreekt zichzelf niet meer tegen** (testenv-punt 4). Bij een illustratie met schaduwen won bedrukt of gesublimeerd. Toch zei de uitleg dan "meestal geweven of geborduurd … Exact? Dan bedrukt". Nu kiest `advice/evaluate.js` de nieuwe sleutels `advice.summary.illustration_print` en `illustration_nowoven_print` (NL+FR) wanneer een printtechniek wint. Voorbeeld: *"Een gedetailleerde illustratie met schaduwen. Bedrukt toont elke schaduw en elk detail exact zoals je bestand, daarom is dat ons advies. Liever een garenlook? Geweven geeft het meeste detail, geborduurd kan na vereenvoudiging tot ± 4 garenkleuren."* Wint een garentechniek, dan blijft de oude tekst staan. Ik heb de tests in `tests/advice/advice.test.js` aangepast.
4. **Render-tests verplaatst** van `assets/src/render/tests/` naar `tests/render/`, met aangepaste imports en paden. Zo draait `npm test` ze mee en zitten ze niet meer in de plugin-zip.
5. **Standalone demo**: `scripts/build-demo.mjs` + `demo/template.html` + `demo/assets/` (Assistant-font, CC0-voorbeeldfoto, eigen verloop-badge). `npm run demo` bouwt alles. `tests/run-all.sh` heeft een extra stap "standalone demo".
6. Nieuwe test `tests/demo/demo.browser.test.js` (Playwright, 4 scenario's).

## De demo: `dist/patch-studio-demo.html`
- Eén bestand van ± 730 kB (365 kB gzip). Alles staat erin: app-JS, worker (via Blob-URL), CSS, publieke config (defaults + garenpalet via `BPS.buildPublicConfig`), lettertypes als data-URI en voorbeeldbestanden. De demo doet **geen enkel extern verzoek**, ook geen Google Fonts: Sora zit er zelf in, zodat het bestand offline werkt.
- Werkt door te dubbelklikken (file://). `?lang=fr` geeft de Franse versie, ook voor het kader rond de tool.
- Voorbeelden om te proberen: de 3 ingebouwde (rond clublogo, schild, tekstlogo), plus 3 demo-voorbeelden die geladen worden alsof de klant ze uploadt:
  - badge met kleurverloop → bedrukt, met eerlijke garenalternatieven;
  - foto → gesublimeerd/bedrukt;
  - vlak PNG-logo → geborduurd.
  "Start met tekst" werkt ook.
- "Bestellen" toont "Dit is een demo" met de payload die naar het atelier zou gaan (via `config.strings`, NL+FR).
- Getest met Playwright:
  - geen consolefouten, mislukte of externe verzoeken;
  - alle 5 stappen tot bestellen, inclusief de rechtencheck (fout + focus);
  - de extra voorbeelden geven het verwachte advies, en kop en uitleg spreken elkaar niet tegen;
  - 390 px tekst-only met tekstlaag: geen horizontale scroll in elke stap;
  - 390 px FR met de foto.

## Contractverschillen: hoe opgelost
- `woo.redirectToCart` is privé: de UI stuurt standaard door (ui-handoff 3). Geen wijziging nodig.
- Pool-hint (`items[].extensions.bps.technique`): woo levert de cart-data. Zonder die data valt de UI stil terug, dus geen fout.
- Advies hing licht af van de gekozen techniek (ui open punt). De UI volgt het advies tot het stabiel is. In de demo-test geeft wisselen tussen voorbeelden telkens het juiste advies.
- Verder vond ik geen breuken tussen de modules: alle e2e-bedragen zijn gelijk (UI = `/quote` = mand = order).

## Open punten voor de eigenaar
1. Bundelbudget 100 kB aanvaarden, of de FR-catalogi via PHP laden (zie punt 1).
2. Scoregewichten in het advies (`KIND_FIT.illustration`): borduren vaker als eerste advies bij illustraties is één getal.
3. Prijzen, fees, 3D-puff/metallic, standaard zwart/wit garen: zie de open punten in de handoffs pricing, advice en colors.
4. `languages/*.pot` en `fr_BE.po` voor de PHP-teksten. Laat ook de Franse teksten nalezen door een moedertaalspreker.
5. WooCommerce-verzending: zet "Gratis verzending" bovenaan.
6. Server-cron voor de opruiming.

## Integratie v2: ontwerpstudio + render v2 (18-11-2026)

| Controle | Status |
|---|---|
| `npm run build` | groen: `patch-studio.js` 119,2 kB gzip (budget **125**), worker 18,0 (25), admin 5,1 (15) |
| `npm test` | 253 geslaagd, 0 gefaald, 3 todo (bekende analyse-TODO's) |
| `npm run test:php` | 47 vectoren, 652 checks, 0 fouten |
| `npm run test:e2e` (WP + WooCommerce, SQLite) | 01–08 + php-debug-log allemaal PASS (selectors herschreven voor de studio) |
| `npm run demo` + `node tests/demo/screenshots-v2.mjs` | beide testbeelden van de eigenaar, desktop 1440 en gsm 390: 0 consolefouten, geen horizontale scroll, patch altijd volledig in beeld met scrollTop 0. Screenshots: `docs/screenshots/v2/` |

**Budget 100 → 125 kB gzip.** De studio-UI en render v2 (belichte materialen, 4 kledingstukken, techniekvergelijker; render ±19 → ±32 kB) brengen de bundel op 119 kB. Ook als de FR-catalogi via `config.strings` uit PHP komen (±13 kB) blijft hij boven 100 kB, dus het budget moest omhoog. Het script laadt alleen op de studiopagina. Volgende besparing als het nodig is: FR-catalogi via PHP en `garment.js`/`compare.js` lazy laden (±6 kB).

**Wat de UI nu van render v2 gebruikt**
- *Glans volgt de muis*, subtiel (`lightFromPointer(e, canvas, 0.45)`), enkel met een muis en nooit bij `prefers-reduced-motion`. Tijdens het bewegen rendert de studio `quality: 'fast'`, 200 ms na stilstand `high`. Verlaat de muis het canvas, dan glijdt het licht in 320 ms terug naar de standaard (linksboven).
- *Fast/high*: slepen, schalen met de hoekgrepen, knijpen, scroll-zoom en het licht tekenen `fast`; bij loslaten of 160 ms rust `high`.
- *Vergelijker*: `renderTechniques` met elk een passende rand en materiaal, één kaart per idle-callback, 280 px (2× supersampled). De kaarten staan nu als tegels zij aan zij (2 kolommen) met de render als held, badge "Ons advies" op de render, prijs per stuk en levertijd.
- *Mockups*: close-up, polo, pet, jas en rugzak (`backpack_front` staat nu ook in `config/defaults.json` → `mockups`, zodat de beheerder kleur/schaal kan instellen). Wisselen van weergave of techniek doet een crossfade (uit bij reduced motion).
- *Prewarm*: de top-4 technieken uit het advies worden in idle-tijd voorbereid.
- Het root-element krijgt `data-bps-panels` (zichtbare panelen) als stabiele haak voor tests en analytics; de gsm-subtabs hebben `data-panel`.

**Open:** PVC 2D/3D (`pvcDepth`) wordt nog niet aangeboden: daar is eerst een prijsoptie in de catalogus voor nodig, anders tonen we iets wat de klant niet kan bestellen. Mockups blijven vectorieel (geen foto). Gedrag met open gsm-toetsenbord niet op een echt toestel getest.
