# Feedback van de eigenaar: status per punt

Bron: de secties "BELANGRIJKE FEEDBACK", "Tweede testbeeld", "EIS: altijd een mooie, volle patch" en
"EIS: zelf schikken en tekst toevoegen" in `docs/BRIEF.md`. De werkende referentie is `reference/demo-v2.html`.
Gecontroleerd op 17-11-2026 met de gebouwde plugin (`npm run build`), op de dev-pagina (`dev/index.html`) met
Playwright + Chromium, op gsm-breedte (390 px) en desktop (1280 px).

**Kort:** de meeste punten zaten al in de plugin. Ik heb drie dingen bijgewerkt (zie "Wijzigingen"):
de tekstlagen in de winkelmand, de bestelling en de offerte, de Franse namen van de lettertypes, en een
controlescript.

## Testresultaten met de testbeelden van de eigenaar

| Testbeeld | Herkend | Vorm | Ontwerpvlak (mm) op patch 80×80 | Volledig zichtbaar |
|---|---|---|---|---|
| `eigenaar-ronde-patch.png` (illustratie, navy achtergrond) | illustratie (geen foto), achtergrond effen → weggelaten | rond, zekerheid 0,98, vult de patch | x 0, y 0, b 80, h 80 | ja |
| `eigenaar-foto-patch.jpg` (gsm-foto, witte stof met plooien) | "Foto van een patch herkend: we hebben de patch uitgeknipt" + knop **Bijstellen** | rond, zekerheid 0,98, vult de patch | x 0, y 0, b 80, h 80 | ja |

Screenshots staan in `docs/screenshots/feedback-eigenaar/`: `ronde-patch-gsm.png`, `foto-patch-desktop.png`,
`ronde-patch-render-1200.png` en `foto-patch-render-geborduurd-1200.png` (de PNG die naar de winkelmand en de fiche gaat).
Daarop staat het hele ontwerp: de tekst "IRON CRUSADERS / INVICTUS" en beide kogels. De eigen rand van het
ontwerp wordt de rand van de patch.

## Per punt

| # | Eis | Status | Waar in de code | Hoe getest |
|---|---|---|---|---|
| 1 | Nooit bijsnijden (contain, nooit cover) | **OK** | `shared/geometry.js` `layoutPatch` (contain + `fitInShape` als ontwerpvorm ≠ patchvorm) | unit-test "layout: nooit bijsnijden…"; Playwright: `artRectMm` = 0/0/80/80 voor beide beelden; screenshots |
| 2 | Vorm herkennen (rond/ovaal/rechthoek/schild/contour), automatisch kiezen, ontwerp vult de patch | **OK** | `analysis/shape.js` (ellips via momenten, tolereert scheve gsm-foto en zet die recht), `ui/app.js` past de vorm toe zolang de klant niets koos | `tests/analysis/browser.test.js` (beide beelden), Playwright: `state.shape = circle`, melding "Ronde patch herkend: je ontwerp vult de patch" |
| 2b | Achtergrond via flood fill vanaf de rand: mediaan van de rand, groeit alleen over zachte overgangen, rand moet grotendeels effen zijn, vlekjes weg | **OK** (strenger dan demo-v2: in Lab, met test op kleurverschuiving en zachte grens) | `analysis/background.js` | unit + browser-tests; gsm-foto correct uitgeknipt (screenshot) |
| 2c | "We hebben je patch uit de foto geknipt" + knop om bij te stellen | **OK** | `ui/step-design.js` (`isPatchPhoto` → melding + "Bijstellen"), `ui/flows.js` (bijstelvenster) | Playwright-screenshot `foto-patch-desktop.png` |
| 3 | Vlak logo / illustratie met tinten / echte foto onderscheiden + eerlijk vakadvies | **OK** | `analysis/features.js` → `kind`; `advice/evaluate.js` + `advice/messages.js` (shaded_simplify, shaded_woven, exact…) | Playwright: `kind = illustration`; advies: geborduurd "warn: vereenvoudigd tot ± 5 garenkleuren", geweven "ok: meeste detail", bedrukt/gesublimeerd "ok: exact zoals je bestand" |
| 4 | Gekleurde/getekende achtergrond (niet wit) wordt de achtergrond van de patch, uitgelijnd, tot aan de rand | **OK** | `analysis` → `background.keep` + `backdrop`; `render/renderer.js` (backdrop onder het ontwerp, geknipt op de vorm) | Playwright, testbeeld "ACE" op navy: `keepBg = true`, stof = navy #1b2a4a, melding "We nemen de achtergrond van je ontwerp mee tot aan de rand van de patch" (`achtergrond-behouden.png`) |
| 4b | Witte/transparante achtergrond = papier → stofkleur automatisch met het beste contrast | **OK** | `colors/contrast.js` `pickFabric`, `ui/derive.js` | Playwright: geel logo op wit → stof automatisch **zwart** (#151515) |
| 4c | Is het ontwerp zelf al een patch, dan geen eigen achtergrond (het ontwerp vult alles) | **OK** (zoals demo-v2: `keepBg = bg && !wit && !vorm`) | `ui/derive.js` | ronde patch op navy: `keep = false`, melding "solid_dropped" |
| 5 | Grootte 20–200 %, slepen (muis, touch, pijltjes, Shift = 5 mm), Centreren, Passend maken; alles binnen de rand (geknipt op de vorm) | **OK** | `ui/step-design.js` (schuifbalk, pijltjesknoppen), `ui/preview.js` (pointer events = muis + touch, toetsen, verschuiving begrensd tot ± halve patch), renderer knipt op de patchvorm | Playwright: schuifbalk min/max 20/200; 150 % → ontwerpvlak 120×120 mm; slepen → offset (40; 6,4) mm (begrensd); pijl links −1 mm; Centreren → (0; 0); Passend → schaal 1 |
| 6 | Tekstlagen: meerdere, 6 lettertypes, garenkleuren, letterhoogte in mm, Recht / Boog boven / Boog onder, slepen = draaien + afstand tot rand | **OK** | `ui/step-design.js` `layerEditor`, `ui/preview.js` (slepen langs de boog), `render/text.js` | Playwright: tekst toegevoegd, Pacifico, 3 mm, boog boven → gerenderd langs de rand (`tekst-boog.png`); lettertypes Sora, Oswald, Graduate, Roboto Slab, Cinzel, Pacifico aanwezig (woff2 in `assets/fonts/`) |
| 6b | Patch met alleen tekst (zonder afbeelding) | **OK** | knop "Nog geen ontwerp? Start met tekst" in de lege staat, `ctx.startText()` | Playwright (FR): `source.kind = text`, tekst in boog (`alleen-tekst-fr.png`) |
| 7 | Lettertoets per techniek (dunne/script strenger) + tekstkleuren tellen mee in de garenkleuren | **OK** | `advice/evaluate.js` §5 (`textCapsMm` × `serifScriptFactor` voor thin/serif/script), `ui/derive.js` (tekstkleuren in palet/stofkeuze), fiche: "(inclusief tekst)" | Playwright, "IRON CRUSADERS" in Pacifico 3 mm: geborduurd "warn: minstens 6 mm", PVC "min. 3,8 mm", chenille "min. 37,5 mm" (+ knop om te vergroten); geweven/bedrukt geen melding |
| 8 | Bewaren: zoom, positie, tekstlagen in winkelmand / bestelling / productiefiche | **OK, aangevuld** | JS `buildPayload` (art.scale/offsetMm/rotation, texts met offset/hoek/inset/letterafstand) → PHP `Payload::art/texts` (gecontroleerd) → design-record + order-meta `_bps_payload`; voorbeeld-PNG (met zoom/positie/tekst) = winkelmandafbeelding; productiefiche toont "Plaatsing ontwerp" en elke tekstlaag | **Nieuw:** zichtbare regel **Tekst** in winkelmand, bestelregel (`bps_texts`), e-mails en offerte. Getest met PHP CLI (`Cart::texts_text`) en `npm run test:php` |
| 9 | Franse vertaling | **Aangevuld** | `config/defaults.json` fonts.label is nu `{nl, fr}`; UI gebruikt `L()`, fiche gebruikt `label()` | Playwright `?lang=fr`: "Sora (net)", "Oswald (étroit et fort)", "Pacifico (manuscrite)"… |

## Wijzigingen

- `plugin/borduur-patch-studio/includes/woo/class-cart.php`: `texts_brief()` en `texts_text()`. Een winkelmandregel
  bewaart een korte kopie van de tekstlagen, en `summary_rows()` toont nu een regel "Tekst", bv.
  `“IRON CRUSADERS” (Oswald, 6 mm, boog boven) · “2026” (Sora, 4,5 mm, recht)`. In het Frans worden de vormen
  "droit / arc en haut / arc en bas".
- `includes/woo/class-order.php`: zichtbare bestelregelmeta `bps_texts` met het label "Tekst".
- `includes/woo/class-quotes.php`: de offerte- en e-mailrijen krijgen dezelfde tekstregel.
- `config/defaults.json`: de namen van de lettertypes staan nu in het Nederlands en het Frans.
  `ui/step-design.js` en `admin/class-order-production.php` lezen ze meertalig.
- `scripts/check-eigenaar-feedback.cjs`: Playwright-controle van zoom/slepen/centreren/passend, tekstlagen + lettertoets,
  stofkleur, achtergrond behouden en alleen tekst. Start eerst `npm run dev`. Gebruik: `PORT=8787 OUT=/tmp node scripts/check-eigenaar-feedback.cjs`.
- `npm run build` werkt. `npm test` geeft 213 geslaagd, 0 gefaald en 3 todo. `npm run test:php` geeft 652 checks en 0 gefaald.

## Open punten / bewuste keuzes

- **Aanbeveling bij illustraties.** demo-v2 zet geweven vooraan. De plugin raadt voor de ronde patch **gesublimeerd** aan
  (score 82,7, bestelbaar vanaf 1 stuk). Geweven krijgt ook "ok, meeste detail", maar is in de standaardconfig
  "op aanvraag, vanaf 100 stuks". Het advies is eerlijk en elke techniek legt uit waarom. Wil de eigenaar
  geweven of geborduurd vaker bovenaan, dan stelt hij dat in via de technieken- en adviesinstellingen. De code hoeft daarvoor niet te veranderen.
- **Geborduurde weergave van een foto-patch.** Met 5 garenkleuren wordt het harnas donker en grof
  (`foto-patch-render-geborduurd-1200.png`). Dat klopt met het eerlijke advies "vereenvoudigd", maar het oogt minder mooi
  dan bedrukt. Een betere reductie voor schaduwen kan later.
- **Labels in de winkelmand.** Labels als "Tekst" en "Techniek" lopen via `__()`. Er is nog geen `.po`/`.mo` voor fr_BE in `languages/`,
  dus op een Franstalige site staan de labels nog in het Nederlands. De waarden zelf zijn wel vertaald.
- **Perspectief.** Een licht scheve, ronde foto wordt rechtgezet (`rectify`). Sterk perspectief (trapezium) bij rechthoekige
  patches wordt niet gecorrigeerd. Daarvoor dient de knop "Bijstellen".
- **Volledige WooCommerce-flow.** Die heb ik niet opnieuw gedraaid (`npm run test:e2e` heeft de WP-testomgeving nodig).
  Te controleren: de nieuwe regel "Tekst" in de winkelmand en de bestelling met een echte WordPress.
