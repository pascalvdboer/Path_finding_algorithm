# Render v2: belichte materialen, mockups en technieken vergelijken

Eigenaar van de module: renderspecialist. Code staat in `plugin/borduur-patch-studio/assets/src/render/**`.
Importeer alles via `render/index.js`. De v1-API blijft werken zoals hij was: elke bestaande aanroep geeft
hetzelfde soort resultaat, maar het beeld is nu belicht.

Screenshots (voor/na, per techniek, op kledij, lichtrichting, vergelijker): `docs/screenshots/v2-render/`.
Opnieuw maken: `node tests/render/screenshots-v2.mjs` (Playwright + Chromium).

## Wat er veranderd is (de "wow")

Elke laag wordt eerst omgezet naar een **geometriebuffer**. Die bevat de basiskleur, de hoogte (die de
normalen geeft), de draadrichting, de glans en de holtes. Daarna wordt de laag **belicht** met één
lichtrichting (`light.js`). Belichting: diffuus + Blinn-glans. Bij garen is de glans anisotroop
(Kajiya-Kay): satijn licht op waar de draden dwars op het licht liggen. Beweegt het licht, dan beweegt de
glans mee.

| Techniek | Wat je ziet |
|---|---|
| Geborduurd | Satijnkolommen hebben een ronde bolling en draden dwars over de kolom. In ringen lopen de draden radiaal. Tatami-vulling met baksteenverband voor brede vlakken. Groefjes waar vlakken elkaar raken. Spikkels kleiner dan ±0,7 mm² worden opgeslokt door het grootste aangrenzende vlak, want geen digitaliseerder borduurt die. Twill is zichtbaar onder open stukken, of een volledig geborduurde tatami-achtergrond. |
| Geweven | Fijne inslagdraden met bindpunten (5-schachts satijnbinding). Bijna plat, scherpe randen. |
| Bedrukt / gesublimeerd | Inkt in twill (mat) of in fijne polyester (zachte satijnglans). |
| PVC | Gegoten plateaus met afgeronde schouders. Met `pvcDepth: '3d'` worden de vlakken gebeeldhouwde koepels. Zachte rubberglans. |
| Chenille | Hoge, matte pool van lussen met vezels, op vilt. |
| Leder | Laser-gravure: ingebrand, verzonken en mat, op nerfleder. |
| Randen | **Merrow** is een echte opgerolde buis met schuine wikkelsteken. **Satijnrand** met wikkelsteken. **Hot-cut** is een smalle gesmolten parel, bij leder een ingebrande rand. Verder een PVC-rand en een vilten rand met zigzagsteek. Elke rand werpt een schaduw op de patch. |
| Scène | Zachte studio-achtergrond (`scene: 'studio' \| 'linen' \| 'slate'`). Contactschaduw en zachte slagschaduw volgen het licht. |
| Kledij | Polo (borst), pet (voorkant, gebogen), jas (rug) en **nieuw: rugzak** (`backpack_front`). Volume, plooien, naden en stofstructuur (piqué, twill, ripstop, cordura) worden met hetzelfde licht belicht. Plooien en licht lopen ook over de patch door. |

## API

### `createRenderer(canvas, { config, onRedraw })` → `{ draw, toBlob, dispose, prewarm }` (ongewijzigd)

Nieuwe, optionele `RenderOptions`:

| optie | waarden | betekenis |
|---|---|---|
| `quality` | `'fast'` \| `'high'` (standaard `'high'`) | `fast`: hergebruikt de gecachte lagen op elke maat en lichtrichting. Bij alleen een lichtwissel wordt enkel de ontwerplaag op halve resolutie herbelicht, zodat de glans de muis volgt. Gebruik dit tijdens slepen, zoomen en lichtbewegingen. `high`: volledige kwaliteit, gebruik dit bij loslaten. |
| `light` | `{x, y}` of `{azimuthDeg, elevationDeg}` | `{x, y}` is de richting naar het licht in schermcoördinaten, van -1 tot 1. `{0,0}` is licht recht van boven, lengte 1 is laag strijklicht. Standaard `{x:-0.55, y:-0.65}` (linksboven). Intern afgerond op 5° azimut en 3° hoogte, zodat caches hergebruikt worden. |
| `scene` | `'studio'` \| `'linen'` \| `'slate'` | Achtergrond van de close-up. |
| `pvcDepth` | `'2d'` \| `'3d'` | Diepte van de PVC-gietvorm. Wordt ook gelezen uit `design.pvcDepth`. |
| `garment` | `'polo_chest'` \| `'cap_front'` \| `'jacket_back'` \| `'backpack_front'` | Bij `view: 'garment'`. |

Diktes uit `design.options` (`thick`, `extra_thick`) verhogen automatisch het reliëf (×1,35 en ×1,7).

`RenderResult` geeft nu ook `light: { azimuthDeg, elevationDeg }` terug: de afgeronde lichtrichting die
gebruikt is.

### `renderTechniques(opts, { size = 220, techniques?, config?, light?, scene?, transparent?, supersample? })`

Rendert hetzelfde ontwerp in alle technieken (standaard `config.techniqueOrder`) en geeft
`[{ technique, canvas, edge, ms }]` terug. Elke techniek krijgt een passende rand: de huidige rand als de
techniek die aanbiedt, anders de standaardrand van die techniek (PVC → `molded`, chenille → `felt_border`,
leder → `laser`). Leder krijgt een lederkleur als de stof geen leder is. Onder 360 px wordt standaard 2×
supersampled.

Kosten: ±35–110 ms per kaart van 260 px, de eerste keer per ontwerp. **Tip voor de UI:** render één kaart
per `requestIdleCallback` met `techniques: [t]`, zodat het canvas vloeiend blijft.

### `lightFromPointer(event, element, strength = 1)` → `{x, y}`

Rekent een muispositie om naar `opts.light`. `strength` 0,4 tot 0,6 geeft een subtiel effect. Respecteer
`prefers-reduced-motion`: volg de muis dan niet.

### Overige exports

`normLight(l)` (afgeronde lichtvector, cache-sleutel), `DEFAULT_LIGHT`, `SCENE_KINDS`, `GARMENTS` (nu 4) en
`renderMessages`. `render.garment.backpack_front` bestaat in NL ("op de voorkant van een rugzak") en FR.

## Integratierecepten

```js
// slepen / zoomen
onPointerMove: r.draw({ ...opts, quality: 'fast' });
onPointerUp:   r.draw({ ...opts, quality: 'high' });

// glans laat de muis volgen (subtiel)
let raf = 0, idle = 0;
canvas.addEventListener('pointermove', (e) => {
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const light = lightFromPointer(e, canvas, 0.5);
  cancelAnimationFrame(raf);
  raf = requestAnimationFrame(() => r.draw({ ...opts, light, quality: 'fast' }));   // ≤ 25 ms
  clearTimeout(idle);
  idle = setTimeout(() => r.draw({ ...opts, light, quality: 'high' }), 180);       // volle kwaliteit bij stilstand
});
```

**Wisselen van techniek.** Een crossfade of "stitch-in" doet de UI met twee canvassen en een
CSS-opacity-overgang. `prefers-reduced-motion` zet die uit. `prewarm(opts, advice.ranking.slice(0, 3))`
bouwt de lagen van andere technieken alvast in idle-tijd.

**Rugzak in de beheerinstellingen (optioneel).** `config.mockups.backpack_front = { label, garmentHex,
scaleRefMm: 300, patchCenterMm: [0, 0] }`. Ontbreekt die, dan gebruikt de renderer zijn eigen standaarden
(`#2b2f36`, 300 mm). De UI moet de knop "Op rugzak" zelf tonen.

## Prestaties

Gemeten in headless Chromium met software-GL (`tests/render/render-v2.browser.test.js`, 1200 px). Op een
gewone laptop met GPU zijn de tekenstappen sneller. De JS-lussen zijn even snel.

| | geborduurd | geweven | bedrukt | PVC | chenille | leder |
|---|---|---|---|---|---|---|
| eerste render nieuw ontwerp/techniek | ~330 ms | ~330 | ~210 | ~260 | ~290 | ~170 |
| `high` na slepen (gecachte lagen) | 6 ms | 6 | 6 | 6 | 6 | 6 |
| `fast` (slepen, zoomen, muislicht) | 13–16 ms | 14 | 13 | 14 | 13 | 13 |
| `high` met nieuwe lichtrichting (alles herbelicht) | 105–155 ms | | | | | |

Budget uit de opdracht: high ≤ 150 ms en fast ≤ 25 ms bij 1200 px. Dat wordt gehaald na de eerste render
van een ontwerp. Een volledige herbelichting zit rond de grens. Gebruik `fast` voor het muislicht.

Debug: zet `globalThis.__bpsProf = {}` en je krijgt de tijd per stap (`under`, `artBuild`, `artLit`, `over`,
`band`, …).

## Bestanden

- `light.js`: lichtmodel, `shadeGeo`, `normLight`, de halve-resolutiekopie voor `fast`.
- `material.js`: geometrie per techniek (`buildLayer`) en de naadloze, belichte basistegel van de patch
  (`baseTile` en `shadeBase`).
- `field.js`: afstandsvelden (chamfer en exacte EDT), de bandveld-functie voor randen, `absorbSmall`
  (spikkels weg) en `blurGrid`.
- `edges.js`: `edgeGeo`/`shadeEdge` voor de belichte randen, `edgeDecor` (rafels, snijlijn, zigzag). De oude
  `drawEdge` staat er nog, maar wordt niet meer gebruikt.
- `scene.js`: achtergronden.
- `garment.js`: vier belichte kledingstukken en `placePatch`.
- `compare.js`: `renderTechniques`.
- `renderer.js`: compositie, caches (begrensd op pixels), snelle paden.

Tests: `tests/render/render.test.js` en `render.browser.test.js` (v1, ongewijzigd en groen),
`tests/render/render-v2.browser.test.js` (licht, vergelijker, rugzak, PVC 3D, budgetten). Galerij:
`node tests/render/gallery.mjs <map>`.

## Open punten / grenzen

- **Bundelgrootte.** De rendermodule gaat van ±19 kB naar ±32 kB gzip. De totale `patch-studio.js` zit
  boven het budget van 100 kB (118,9 kB). Een deel daarvan komt van andere teams. Lazy-loading van
  `garment.js` en `compare.js` kan ±6 kB schelen.
- **Mockups.** Ze zijn vectorieel en belicht: overtuigend, maar geen foto. Voor productfoto-niveau zijn echte
  (eigen) foto's met verplaatsingskaarten nodig. Dat kan later via `config.mockups`.
- **Chenille.** Chenille ziet er bij gedetailleerde illustraties druk uit. Dat klopt technisch: chenille
  past bij weinig kleuren en grote vlakken. Het advies moet dat ook zo zeggen.
- **Inzoomen.** Boven ±2,5× wordt de ontwerplaag begrensd op 1700 px, dus zachter. Bij geweven zie je dan
  de randen van het analyserooster.
- **Satijn in letters.** De richting van de satijnsteek in letters volgt de hoofdas per letter. Een echte
  digitaliseerder draait de steek mee in bochten.
