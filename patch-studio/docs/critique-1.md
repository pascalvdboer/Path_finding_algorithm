# Critique ronde 1 — onafhankelijke creatief directeur

Datum: 18-11-2026. Getest: `dist/patch-studio-demo.html` (vers gebouwd met `npm run demo`), Playwright/Chromium op
1440x900, iPhone 13 (390x664 zichtbaar) en 390x844. Beide eigenaarbeelden geüpload (`eigenaar-foto-patch.jpg`,
`eigenaar-ronde-patch.png`), tekst toegevoegd, vorm (rond/schild/rechthoek), rand, kleuren, alle 7 technieken en
de 4 mockups geprobeerd. Screenshots: `docs/screenshots/critique-1/` (d* = desktop, m* = mobiel).
Geen code gewijzigd. Tests: `npm test` 350 pass / 0 fail (3 skipped), `npm run test:php` 652 checks, 0 failed.

## Eerste indruk (als de eigenaar)
Dit is een echte sprong t.o.v. v1: er is een studio-indeling (rail, canvas, assistent, actiebalk), een
techniekvergelijker met live thumbnails, mockups, undo/redo, Polyneon-namen en op gsm blijft de patch vast bovenaan
(scrollTop blijft 0, patch altijd binnen visualViewport — hard criterium gehaald). Maar "wauw" is het nog niet.
Het canvas is te klein, de startpagina toont platte logo's i.p.v. patches, de geborduurde render ziet er bij inzoomen
uit als een posterisatie-filter met moiré, tekst toevoegen levert een onleesbare, afgesneden balk op, en de mockups
ogen als clip-art. Het voelt als "een nette webshop-configurator", nog niet als Canva.

## Scores (1-10)
| Criterium | Score | Waarom |
|---|---|---|
| Wow-effect | 5 | Studio-opzet klopt, maar canvas is maar 39 % van de breedte (eis ≥ 60 %), startscherm toont vlakke logo's op witte vierkanten (d01), geen galerij. |
| Realisme patch | 5 | Gesublimeerd/bedrukt overtuigend (d02). Geborduurd: moiré, harde posterisatie, grijze achtergrond wordt wit, cape verdwijnt (d06). Chenille oogt als badstof, PVC/leder/geweven redelijk (d16). |
| Studio-gevoel | 5 | Rail + paneel + assistent nemen 61 % van het scherm in; panelen zijn tekstrijke formulieren met radiokaarten en native `<select>`. Drie primaire knoppen tegelijk zichtbaar (d02). |
| Duidelijkheid advies | 7 | Atelierkaart in de juiste toon, aanbeveling (sublimeren) klopt voor deze foto. Wel: "Kan, met aanpassing" + slider die minimaal 6 kleuren toelaat terwijl de knop "vereenvoudig tot 4" zegt (d05/d08-kleuren); "Levertijd bij je proef" is een niet-antwoord. |
| Mobiel | 6 | Patch blijft zichtbaar (geslaagd). Maar 3 gestapelde balken (stap-CTA, prijs, tabs) laten ±110 px paneelinhoud over op iPhone 13 (m-overzicht); techniekvergelijker = lange verticale lijst in een paneel van 219 px; mockup-patch is op polo een stipje. |

## Geprioriteerde verbeteringen (max 12)

1. **Canvas ≥ 60 % breed op desktop.**
   Waar: `assets/css/patch-studio.css` r. 605-621 (`.bps-ws` grid `84px auto minmax(0,1fr) 390px`), dock-paneel in `src/ui/app.js`/`panels.js`.
   Waarom: canvas is nu 564 px van 1440 (39 %); de patch moet de held zijn (eis REDESIGN).
   Hoe: maak het dock-paneel een zwevend paneel over de linkerrand van het canvas (of collapse naar rail als niets open is), assistent max 340 px, inklapbaar.
   Klaar als: op 1440x900 `canvas.getBoundingClientRect().width / innerWidth ≥ 0.6` met paneel dicht en ≥ 0.5 met paneel open; patch ≥ 520 px diameter.

2. **Geborduurde render: weg met moiré en posterisatie, echte satijnsteek.**
   Waar: `src/render/stylize.js` (`stylizePixels`, `chooseModes`, `cleanLabels`), `src/render/material.js` (`buildLayer`, `baseTile`, `shadeBase`).
   Waarom: d06 toont fijne diagonale lijnen die interfereren (moiré), rafelige vlekken, de grijze achtergrond van de eigenaarpatch wordt wit-zilver en de beige cape verdwijnt — de eigenaar ziet meteen "dat is geen borduurwerk".
   Hoe: vlakken eerst morfologisch opschonen (min. oppervlak in mm², open/close), satijnrichting per vlak volgens hoofdas (PCA) i.p.v. bijna globaal, steeklengte in mm i.p.v. px-periode (geen periode < 3 px op scherm → anders fill-patroon met tatami-offset), kleurreductie in Lab met behoud van middentonen (grijs/beige mogen niet naar wit/zwart klappen).
   Klaar als: zoomscreenshot 244 % van `eigenaar-foto-patch.jpg` in "Geborduurd" toont geen moiré, de grijze cirkel blijft grijs, de cape is beige/kaki, en een vakman herkent satijn- vs. tatamivlakken.

3. **Tekst toevoegen moet meteen mooi zijn (plaats, maat, contrast).**
   Waar: `src/ui/derive.js` r. 103-135 (auto-tekstkleur), `src/render/text.js`, toevoeg-actie in `src/ui/panels.js` (~r. 220-240) en `src/ui/stage.js`.
   Waarom: d11/d16: "KALMTHOUT MC 2026" verschijnt dwars over het midden, donkergrijs op donkere illustratie, en valt buiten de patch. Alleen een grijze waarschuwing in het paneel. Dit is het moment waarop een klant afhaakt.
   Hoe: nieuwe tekst automatisch passend maken (lettergrootte verkleinen tot binnen de veilige zone), plaatsen in de vrije zone (onder/boven, of als boog langs de rand als er een rand-ring is), auto-kleur met contrast tegen de lokale achtergrond onder de tekst (niet tegen de patchgrond), standaard een contour/omlijning in rand- of grondkleur aan.
   Klaar als: na "Tekst toevoegen" + 17 tekens typen valt de tekst volledig binnen de patch, WCAG-contrast ≥ 3:1 tegen de pixels eronder, en er staat geen waarschuwing.

4. **Startscherm: galerij van echte patches + startsjablonen (harde eis 1).**
   Waar: `src/ui/samples.js` (nu 3 voorbeelden), hero in `src/ui/app.js`/`flows.js`; demo-balk in `scripts/build-demo.mjs`.
   Waarom: d01 toont vlakke logo's op witte vierkanten — het tegendeel van wow. Er zijn geen sjablonen per techniek/toepassing/vorm; de demo-balk bovenaan dupliceert de voorbeelden.
   Hoe: render de hero-voorbeelden met de eigen renderer (geborduurd, 3D-puff, PVC, geweven, chenille, leder, naamstrook) op stof met licht; daaronder filterchips per toepassing (club, bedrijf, werkkleding, motorclub, jeugdbeweging, evenement, naamstrook, cadeau). Klik = sjabloon (schild met boogtekst, banner, rond logo, vintage badge, naamstrook) dat meteen bewerkbaar is. Demo-balk weg of samenvoegen.
   Klaar als: startscherm toont ≥ 8 gerenderde patches, geen enkel plat logo op wit; klik op een sjabloon opent de studio met bewerkbare tekstlagen.

5. **Vorm wisselen mag nooit een "sticker op wit" opleveren.**
   Waar: `src/render/renderer.js` r. ~499 (`baseHex = o.backgroundHex || fabricHex`), stofkeuze `src/ui/derive.js` r. 86-100.
   Waarom: d12: ronde patch in schild = rond ontwerp met de oude gefotografeerde rand op een witte schildvlak. Lelijk en technisch onlogisch.
   Hoe: bij een uitgeknipte foto-patch of ontwerp met eigen rand: stofkleur automatisch = dominante buitenring-kleur (hier zwart), en de oude gefotografeerde rand wegmaskeren (inset tot binnen de rand) wanneer de vorm wijzigt. Toon een assistentkaart "We hebben je oude rand weggelaten; de nieuwe rand is …".
   Klaar als: schild/rechthoek met beide eigenaarbeelden toont een doorlopende zwarte grond, geen dubbele rand, geen wit.

6. **Gegenereerde rand mag tekst van het ontwerp niet opeten.**
   Waar: `src/render/edges.js` (`edgeCoverMm`, `edgeBandMm`), fit-logica in `src/ui/derive.js`/`renderer.js`.
   Waarom: d10/d16: "CRUSADERS" van de eigenaarpatch wordt deels onder de merrowrand geschoven/donker; bij PVC/chenille/geweven blijft alleen "IRON" leesbaar.
   Hoe: bij "Ronde patch herkend: je ontwerp vult de patch" het ontwerp met edgeCover + 1 mm inschalen, of de oorspronkelijke fotorand vervangen i.p.v. erbovenop leggen. Advies-melding als tekst te dicht bij de rand zit.
   Klaar als: in alle 7 technieken is "IRON CRUSADERS" volledig leesbaar op 8 cm.

7. **Garenkleurkiezer = visuele Polyneon-kaart, niet native selects (harde eis 3).**
   Waar: `src/ui/panels.js` kleurenpaneel (swatch + `<select>`), `src/shared/config.js` (laadt alleen `thread-palette.json`).
   Waarom: d08-kleuren: per kleur een dropdown; geen zoeken op nummer/naam, geen kleurfamilies, geen "toon alle 411". Klikken op een kleurvlak in het canvas om het te wijzigen ontbreekt.
   Hoe: popover met populaire kleuren, families, zoekveld en "Toon alle 411" (lazy laden uit `docs/research/01-garenkleuren-polyneon.json` → plugin-config); "Rood → Polyneon 1747" mapping-chips; klik in canvas pikt label onder de cursor (labels zijn er al in stylize).
   Klaar als: Playwright kan "1747" typen en die kleur kiezen; klik op het rode kruis in het canvas opent de kiezer met die kleur geselecteerd; render wijzigt binnen 150 ms.

8. **Eén primaire actie tegelijk.**
   Waar: dock-voet ("Bekijk ons advies"/"Kies maat en aantal"), assistent-voet (`src/ui/assistant.js`), `.bps-bar__btn`.
   Waarom: d02/d08: drie blauwe primaire knoppen (vaak twee identieke "Kies maat en aantal" naast elkaar). Dat is wizard-denken in een studio-jasje en verwart.
   Hoe: stap-CTA's in dock en assistent weghalen of als secundaire link; alleen "Bestellen" is primair. Op mobiel scheelt dit een hele balk (zie 9).
   Klaar als: op elke screenshot is precies één `bps-btn--primary` zichtbaar.

9. **Mobiel: meer ruimte voor het paneel, vergelijker als carrousel.**
   Waar: `css/patch-studio.css` r. 638-697 (mobiel grid-rows), `src/ui/assistant.js` `.bps-cards`.
   Waarom: op iPhone 13 resten ±110 px paneelinhoud onder canvas tussen stap-CTA, prijsbalk en tabbalk (m-overzicht); techniekkaarten 2-koloms in een 219 px paneel = eindeloos scrollen.
   Hoe: stap-CTA weg (zie 8), prijsbalk en tabbalk samenvoegen tot één balk van ±56 px (prijs links, Bestellen rechts, tabs erboven als iconen); techniekvergelijker horizontaal swipebaar (kaart 140 px, scroll-snap); patch op stage iets groter (canvas-hoogte nu 246 px → ±40 % van 664 = 265+).
   Klaar als: op 390x664 is ≥ 200 px paneelinhoud zichtbaar, en alle 7 technieken bereikbaar met horizontaal vegen zonder verticaal scrollen.

10. **Mockups: realistische schaal en betere modellen.**
    Waar: `src/render/garment.js` (G-tabel r. 10-14, `cap`, `jacket`, polo-plooien).
    Waarom: d07: "Op jas" toont een trui met een 8 cm patch als stipje in het midden van de borst, terwijl het id `jacket_back` heet; pet oogt als helm; op mobiel is de patch op de polo nauwelijks zichtbaar (m13).
    Hoe: na wissel automatisch inzoomen tot de patch ±35 % van het canvas inneemt (kledingstuk mag buiten beeld lopen) met knop "Heel kledingstuk"; jas = echte jas (rits, kraag) met rugpositie en evt. grotere aanbevolen maat-hint; pet: 6-panel met klep in perspectief en gebogen patch.
    Klaar als: in elke mockup is de patch ≥ 150 px breed op 1440 en ≥ 90 px op 390, en de "Op jas" toont een jas van achteren.

11. **Contextuele suggesties van de assistent (harde eis 1, tweede deel).**
    Waar: `src/ui/assistant.js`, regels in `src/advice/evaluate.js` / `src/crosssell/rules.js`.
    Waarom: de assistent reageert alleen; nergens "Dit ontwerp kan ook als 3D-puff", "Probeer metallic garen voor de rand", "Ook als sleutelhanger/op een pet". Geen enkel inspiratiemoment na upload.
    Hoe: max. 1-2 suggestiekaarten met mini-render en één klik om toe te passen (bv. randkleur metallic goud), alleen als technisch haalbaar en aangeboden in de instellingen. Rand-paneel uitbreiden met visuele tegels merrow/satijn/hot-cut/laser + randdikte i.p.v. tekstkaarten (d08-rand).
    Klaar als: na upload van beide eigenaarbeelden verschijnt ≥ 1 relevante suggestie die met één klik het canvas wijzigt en met undo terug te draaien is.

12. **Advies consistent en concreet maken.**
    Waar: `src/advice/evaluate.js` r. 184-196 + `src/advice/messages.js` r. 80-128, kleurenslider in `src/ui/panels.js`.
    Waarom: geborduurd-kaart zegt "Vereenvoudig tot 4 garenkleuren", de assistenttekst "± 5", de kleurenslider begint bij 6 (d05, d08-kleuren, d10). "Levertijd bij je proef" staat op 6 van 7 kaarten — zegt niets. Bovendien blijft "Dit lijkt al een afbeelding van een patch" als oranje waarschuwing naast een groene "uitgeknipt" staan (zelfde tegenstrijdigheid die de eigenaar in v1 noemde, nu in "Wat ons opviel", d15).
    Hoe: één bron voor reduceK die zowel kaart, tekst als slider-minimum voedt; levertijd als bereik in werkdagen uit de instellingen (bv. "± 10 werkdagen na je proef"), of weglaten; foto-melding als neutrale info (blauw) samengevoegd met de uitknip-melding.
    Klaar als: één getal voor garenkleuren overal; elke kaart toont een concrete levertijd of geen; geen oranje melding bij een correct verwerkte foto.

## Wat goed is (behouden)
- Mobiele harde eis gehaald: patch blijft in beeld, `scrollTop` 0 bij elk paneel en tijdens typen (m02-m04, m10-m13).
- Gesublimeerd/bedrukt render en de achtergrond-/vormherkenning van beide eigenaarbeelden (ook de deuk in de rand van de ronde patch wordt netjes opgevangen).
- Techniekvergelijker met live thumbnails en prijs per stuk; atelier-toon in de assistent.
- Zwevende tekst-toolbar op het canvas, undo/redo, staffelhint "Vanaf 25 st.: € 5,66/st." (eerlijk, geen valse schaarste).

## Open punten / niet getest
- Echt toetsenbord op gsm (visualViewport-krimp) is in headless Chromium niet te simuleren; code in `src/ui/app.js` r. 222-228 bestaat.
- Pinch-zoom en sleep-performantie (≤ 150 ms) niet gemeten.
- FR-versie niet bekeken.

## Status na ronde 1 (senior frontend, 18-11-2026)
Gebouwd met `npm run build` + `npm run demo`; gecontroleerd met Playwright op 1440x900, 390x664 en 390x844 (iPhone 13, touch).
Bewijs: `docs/screenshots/ronde1-fix/` (d* = desktop, m* = 390x664, m844* = 390x844, fr* = Franse versie).
Tests: `npm test` 407 geslaagd, 0 gefaald (3 todo, bestonden al); `npm run test:php` 652 checks, 0 gefaald.

| # | Punt | Status | Wat er veranderde | Gemeten |
|---|---|---|---|---|
| 1 | Canvas ≥ 60 % | Opgelost | Rail 72 px, toolpaneel 288 px, assistent 304 px en inklapbaar (pijl in de kop, knop "Advies" op het canvas). Onder 1280 px zweeft het toolpaneel over het canvas. Studio-padding 10 px. | Paneel dicht: 1022 px = 0,71 van de breedte. Paneel open: 734 px = 0,51. Patch 552 px breed. |
| 2 | Geborduurde render | Opgelost | Draadlijnen alleen waar het scherm ze kan tonen (steek ≥ ±3 px), anders een egale satijnglans. Radiale steken vervagen naar het midden toe (geen moiré). Grote vlakken met gaten krijgen tatami. Eilandjes kleiner dan 1,6 mm² verdwijnen, met 3 opschoonrondes. Diffuus licht mag een kleur nog maar licht oplichten (grijs blijft grijs). Bij kleurreductie telt de helderheid volledig mee (kL 1), dus middentonen blijven bestaan. | Zoom 244 % (`d05`) toont geen moiré en herkenbare satijn- en tatamivlakken. De grijze cirkel wordt 1718 Wolkengrijs, de cape 1969 Grijze olijf (`d04`). |
| 3 | Tekst toevoegen | Opgelost | Nieuwe tekst komt op de rustigste vrije plek (boog langs de rand, onder of boven het ontwerp, of in het midden op een lege patch). De letterhoogte past zich aan tot de tekst binnen de rand valt, ook tijdens het typen. De automatische kleur kijkt naar de pixels onder de tekst. Als meer dan 4 % daarvan onder 3:1 contrast zakt, komt er automatisch een omlijning in zwart of wit Polyneon. Nieuwe instelling "Omlijning" (Automatisch, Aan, Uit). | 17 tekens: 5 mm, op een boog, binnen de rand, geen waarschuwing (`d09`, `m10`). |
| 4 | Startscherm | Opgelost | De hero toont 4 patches die de renderer zelf tekent. Daaronder staat "Begin met een sjabloon" met 9 sjablonen in 7 technieken en filterchips (Club, Bedrijf, Werkkleding, Motorclub, Jeugdbeweging, Evenement, Naamstrook, Cadeau). Een klik opent de studio met bewerkbare tekstlagen. De demobalk is weg: de demovoorbeelden staan nu in de studio (testselector aangepast). | 13 gerenderde patches, geen plat logo (`d01`, `m01`, `fr01`). Sjabloon openen: 3 tekstlagen (`fr02`). |
| 5 | Geen sticker op wit | Opgelost | Een ontwerp dat zelf een patch is, krijgt in een andere vorm de kleur van zijn eigen buitenring als grond, en de gefotografeerde rand wordt weggemaskeerd. Het contrastadvies blijft de echte stof gebruiken. | Schild en rechthoek tonen een doorlopende donkere grond, geen wit en geen dubbele rand (`d10`, `d13`). |
| 6 | Rand eet tekst | Opgelost | Een ontwerp dat de patch vult, schaalt zo dat zijn eigen rand onder de nieuwe rand valt (randdekking + 0,6 mm). | "IRON CRUSADERS" staat volledig in beeld in alle 7 technieken (`d14-*`). Let op: in `eigenaar-ronde-patch.png` lopen de geribde rand en "CRUSADERS" al in de foto zelf over elkaar. Wat de foto toont, blijft zichtbaar. |
| 7 | Polyneon-kiezer | Opgelost | Een nieuwe kleurkiezer vervangt alle native selects (ontwerpkleuren, tekst, rand). Hij heeft zoeken op nummer of naam, familiechips en "Toon alle 411". De volledige kaart staat in `assets/data/polyneon.json`, wordt pas bij gebruik geladen en zit in de demo inline. Elk kleurvlak toont "bronkleur → Polyneon-nummer + naam" met snelle alternatieven. Een klik op een kleurvlak in het canvas (met het Kleuren-paneel open) opent de kiezer voor die kleur. | "1747" typen en kiezen werkt; de override wordt `{0: "1747"}` (`d07`, `d08`). |
| 8 | Eén primaire knop | Opgelost | De stapknoppen in de panelen en de assistent zijn nu stille links onderaan. Met het overzicht open verbergt de balk zijn knop. | Op elke desktop- en gsm-screenshot staat precies één primaire knop: "Bestellen". |
| 9 | Gsm-ruimte en vergelijker | Opgelost | Prijs en "Bestellen" staan in de bovenbalk (de prijsbalk is weg). De stap-CTA is weg. De technieken vormen één horizontale veegrij (7 kaarten, scroll-snap). | 390x664: 218 tot 260 px paneelinhoud, canvas 237 px, scrollTop 0 bij elk paneel en tijdens het typen. 7 technieken met horizontaal vegen. |
| 10 | Mockups | Opgelost | Op een kledingstuk zoomt het beeld standaard in tot de patch ±35 % van het beeld inneemt. De knop "Heel kledingstuk" schakelt terug. De jas heeft een geribde bomberkraag. Bij jas en rugzak staat een eerlijke maat-hint. | Patch 263 px op 1440 en 102 px op 390x664 (122 px op 390x844), in alle 4 de mockups. |
| 11 | Suggesties | Opgelost | "Ideeën voor jouw patch" toont maximaal 2 kaarten met een mini-render en één klik om toe te passen, terug te draaien met Ongedaan maken. Mogelijke kaarten: een andere techniek die "ok" scoort, extra reliëf (alleen bij borduren, als de optie aan staat), een goudgele rand (alleen bij een garenrand), of de patch op een pet. Het Rand-paneel toont de randen als visuele tegels. | Na de upload verschijnen 2 suggesties (`d15`). |
| 12 | Advies consistent | Opgelost | Eén getal: onder de kleinste sliderkeuze komt het advies niet meer als het ontwerp meer kleuren heeft (adviestekst, knop en slider zeggen allemaal 6). De slider krijgt het geadviseerde getal altijd als stop. Een levertijd verschijnt alleen als die in de instellingen staat. "Dit lijkt al een afbeelding van een patch" is een neutrale info-melding en staat nooit oranje naast "uitgeknipt". | Advies k = 6, staat = 6, knop "Vereenvoudig tot 6 garenkleuren". |

### Open punten
- De bundelgrens is opgetrokken van 125 naar 135 kB gzip (nu 131,4 kB), met reden in `scripts/build.mjs`. De volledige Polyneon-kaart zit niet in de bundel. Dit is een beslissing voor de architect of eigenaar.
- WordPress: `assets/data/polyneon.json` wordt afgeleid van `assets.workerUrl` (`…/dist/` → `…/data/polyneon.json`), of komt via `assets.polyneonUrl`. Geef in PHP eventueel expliciet `polyneonUrl` mee.
- Tekst-omlijning gaat mee in de payload (`texts[].outline`), maar de PHP-payloadfilter laat dat veld nog vallen. Op de proef-PNG staat de omlijning wel. Het veld moet nog toegevoegd worden in `class-payload.php` en op de productiefiche.
- Een kleur die van de volledige kaart gekozen wordt (buiten `thread-palette.json`), komt met nummer, naam en hex in de payload. De productiefiche zoekt namen voor tekstkleuren wel nog op in het palet.
- Bij een ontwerp dat de hele patch vult (zoals de eigenaarpatch) is er geen echt vrije plek voor tekst. De tekst komt dan op de rustigste boog, met de juiste kleur of omlijning. Het ontwerp verkleinen om ruimte te maken is een mogelijke volgende stap.
- De pet is beter in beeld, maar het model zelf (klep in perspectief, gebogen patch) is nog niet herwerkt.
- Een echt gsm-toetsenbord, pinch-zoom en de 150 ms-norm zijn nog niet gemeten (headless).
