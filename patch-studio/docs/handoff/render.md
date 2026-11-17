# Handoff — render

Bouwer: render · SPEC §7.6 · code: `plugin/borduur-patch-studio/assets/src/render/**`

## Geleverd
| Bestand | Inhoud |
|---|---|
| `render/index.js` | Publieke API: `createRenderer(canvas,{config,onRedraw?})` → `{draw, toBlob, dispose, prewarm}`, `renderThumb(opts,size)`, `artFromAnalysis(analysis)`. Extra: `artBundleFromAnalysis(analysis, reduced?)`, `describeRender(opts, config, locale)` (aria-label NL/FR), `renderMessages`, `patchPolygonMm`, `GARMENTS`, `setCanvasFactory`. |
| `render/renderer.js` | Compositie + caches (onderlaag, ontwerplaag, tekstlagen, rand/afwerking, contour). |
| `render/stylize.js` | Pixelkernels: borduren (satijn per vlak met eigen steekrichting, radiaal in ringen, tatami in brede vlakken, glans afhankelijk van lichtrichting, reliëf), geweven, chenille (lussen + pluizige rand), PVC (vlakke niveaus + afgeronde bevel), leder (gegraveerd, monochroom). Label-opschoning (majority filter) en regio-analyse. |
| `render/edges.js` | Randen: satin (wikkelsteek, optioneel rafels), merrow (dikke buis met schuine wikkels), laser (schone/geschroeide snede), molded (PVC), felt (viltrand + zigzag), uncut (stuk stof + knipstippellijn). |
| `render/shapes.js` | Vormen als polygonen (rond, ovaal, rechthoek, afgerond, schild), offset, booglengte, contour-tracering (dilatatie + closing + gaten vullen). |
| `render/text.js` | Rechte tekst en tekst langs de patchrand (boog boven/onder, elke vorm). |
| `render/garment.js` | Polo-borst, pet-voorkant (cilindrische vervorming), jas-rug als vectorsilhouet op ware schaal (`mockups.*.scaleRefMm` = breedte van het lijf/pet-front). |
| `render/textures.js` | Procedurele naadloze texturen (twill, polyester, tatami, weefsel, vilt, lederkorrel, piqué, ripstop). Geen externe afbeeldingen. |
| `render/messages.js` | Catalogus `render.*` NL + FR. |
| `render/tests/*` | `render.test.js` (node, 7 tests), `render.browser.test.js` (Playwright, 7 tests), `harness.mjs`, `page-entry.js`, `gallery.mjs` (visuele galerij). |

## Testen
```bash
node --test plugin/borduur-patch-studio/assets/src/render/tests/render.test.js           # puur (node)
node --test plugin/borduur-patch-studio/assets/src/render/tests/render.browser.test.js   # Chromium: alle technieken×randen×vormen, export, kledingstuk, timing
node plugin/borduur-patch-studio/assets/src/render/tests/gallery.mjs /tmp/gal            # PNG-galerij om te bekijken
```
**Voor de integrator:** `npm test` zoekt alleen `tests/**/*.test.js`. Verplaats/kopieer `render/tests/` naar `tests/render/` (imports zijn relatief: pas `../index.js` → `../../plugin/borduur-patch-studio/assets/src/render/index.js` aan) of voeg het pad toe aan het testscript. De testbestanden worden niet gebundeld (niemand importeert ze), maar horen niet in de plugin-zip.

## Prestaties (headless Chromium, software-rendering, 1000 px)
- Herteken met gecachete lagen (slepen, aantal/prijs wijzigt, kleine maatwijziging): **± 4–6 ms** (`high` én `fast`). Getest: mediaan ≤ 50 ms, fast ≤ 16 ms.
- Eerste render van een techniek (pixelkernel op ± 800² px + eenmalige labelopschoning): 40–125 ms (borduren eerste keer ±170 ms incl. eenmalige regio-analyse). Daarom:
  - laaggroottes worden op 6 %-stappen gesnapt → maat/schaal-schuiven hergebruikt de laag;
  - `renderer.prewarm(opts, advice.ranking.slice(0,3))` bouwt de lagen van andere technieken in `requestIdleCallback` → techniekwissel komt uit de cache. **Aanbevolen: ui roept dit aan na de eerste draw van een nieuw ontwerp.**
- `quality:'fast'` hergebruikt de laatst gebouwde ontwerplaag op elke maat en tekent tekst zonder kernel (niet gecachet).

## Integratienotities / afwijkingen van SPEC
1. `art.backdrop` mag rauw (`analysis.backdrop` = `{width,height,data,artBox}`, zoals ui/derive.js het doorgeeft) of `{image, artBox}` zijn. Labels/palet/backdrop worden ook automatisch gevonden via de afbeelding uit `artFromAnalysis`.
2. Draadlooks (embroidered/woven/chenille/pvc) kleuren elk label met `reducedHex[reducedMap[label]]` als `reduced !== false`, anders `paletteHex[label]`. Printed/sublimated tonen het exacte beeld; sublimated wordt met `multiply` op de drager gezet (wit = drager).
3. PVC: rand altijd `molded` (ook als de layout iets anders meegeeft); randkleur = `edgeHex`.
4. Boogtekst: `insetMm` wordt opgetrokken tot (randbreedte + letterhoogte + 0,5 mm) bij boog boven en (randbreedte + 0,7 mm) bij boog onder, zodat letters nooit in de rand lopen (BRIEF). `angleDeg` positief = met de klok mee.
5. `design.art.rotation` (90/180/270) roteert het ontwerp rond het midden van `artRectMm` (layoutPatch houdt geen rekening met rotatie).
6. `renderThumb(opts,size)`: config komt uit `opts.config` of anders van de laatst gemaakte renderer (signatuur in SPEC heeft geen config).
7. Extra opties: `zoom` (1–8) + `zoomCenterMm` (loep/close-up). Extra resultaat: `view`, `garment`.
8. Fonts: ontbreekt een lettertype nog, dan start de renderer `document.fonts.load()` en tekent hij opnieuw (callback `onRedraw(result)`); `toBlob` wacht altijd op de fonts. De UI moet de `FontFace`s nog registreren met family = `config.fonts[].id`.
9. `toBlob`: transparant, enkel de patch, bijgesneden op de patch (langste zijde = `size`, min 600).

## Open punten
- **Bundelbudget**: render weegt ± 19 kB gzip; de volledige `patch-studio.js` zit nu op ± 94 kB gzip (budget 60 kB) — over alle modules heen. Voorstel integrator: budget herzien of render/analysis-wrapper lazy laden.
- Kledingstukken zijn bewust eenvoudige silhouetten; `mockups.*.patchCenterMm` verschuift de patch t.o.v. de standaardplaats (linkerborst / pet-midden / bovenrug).
- Vormen met `merrow` bij niet-convexe contour: render tekent het wel; het advies moet de klant naar een andere rand sturen (SPEC §7.7).
