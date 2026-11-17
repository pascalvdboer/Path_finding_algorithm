# 07 — Beeldanalyse: onderzoek, pseudocode en drempels

Rol: onderzoeker "beeldanalyse". Doel: de analyse uit `reference/demo-v1.html` / `demo-v2.html` betrouwbaar, snel en eerlijk maken.
Dit document is de **enige bron voor de bouwers**. Alles wat hier als drempel staat, is ofwel gemeten (§13) ofwel
expliciet gemarkeerd als **[schatting]** / **[bron]**.

Bijgeleverd (allemaal in deze map):

| Bestand | Wat |
|---|---|
| `07-beeldanalyse-ref.js` | Werkende referentiecode: sRGB→Lab, **CIEDE2000 (gevalideerd)**, Lab-histogram, gewogen k-means++, auto-palet, rand-statistiek, "valse randen", classificatie, garenmatch, steek- en tijdschatting. Geen afhankelijkheden, draait in een Worker. |
| `07-testharness.js` | Playwright-script dat alle testbeelden analyseert en de kenmerken + klasse print (kalibratie, regressietest). |
| `../../reference/testbeelden/` | 4 CC0-foto's (licentie in `LICENTIE.txt`) + de eigenaar-beelden in `../../reference/`. Synthetische beelden maakt de harness zelf. |

---

## 0. TL;DR voor de bouwers

1. **Decoderen buiten de main thread**: raster (PNG/JPG/WEBP/GIF) → `createImageBitmap(blob)` → transfer naar een **Web Worker** met `OffscreenCanvas`. SVG → op de main thread rasteriseren (2048 px) en dan het `ImageBitmap` doorgeven. PDF/AI/EPS/PSD → niet analyseren, wél aannemen en om een PNG/SVG-export vragen (§3).
2. **Achtergrond + vorm** zoals demo-v2 (randmediaan, zachte flood fill, vlekjes weg, vorm herkennen), plus de verbeteringen in §4 (wit-in-ontwerp, halo/anti-aliasing aan de rand, transparantie).
3. **Kleuren in CIELAB**: 5-bit histogram → Lab → **gewogen k-means++ (k=16)** → clusters samenvoegen als **ΔE2000 < 7** → kleuren < **0,4 %** weg (behalve dunne lijnen) → **3×3 modusfilter** op de labels (anti-aliasing). Getest: JPG q30 geeft exact hetzelfde palet als het PNG-origineel (§13).
4. **Classificatie** (vlak logo / verloop / illustratie / foto) met 4 kenmerken: `cover`, `hard`, `chg`, `fake` (§6). 14/14 testbeelden correct, 25–120 ms op 360 px.
5. **Garenmatch** met ΔE2000 naar de Polyneon-lijst van de eigen site (`01-garenkleuren-polyneon.json`, 411 kleuren). Neutralen (zwart/wit) apart afhandelen, ΔE-getal **niet** aan de klant tonen, wel "dichtstbijzijnde garenkleur, afgestemd bij je digitale proef" (§5).
6. **Tekst**: connected components + lijndikte via distance transform → regels/bogen → letterhoogte in mm → toetsen aan minima per techniek (§7).
7. **Steekaantal** = vulling 1,6 st/mm² + satijn 5 st/mm lengte + loopsteek 0,4 st/mm + 30/kleur + 15/object, altijd als **bereik (−20 %/+25 %)**; tijd = steken/(750 spm × 0,7) + kleurwissels. Alle constanten in de admin (§11).

---

## 1. Pipeline en datastructuren

```
[main]  bestand gekozen
          ├─ type? raster ──► createImageBitmap(blob, {imageOrientation:'from-image'})
          │        svg    ──► svgToBitmap(file, 2048)        (main thread; zie §3.2)
          │        pdf/ai/eps/psd/heic-zonder-decoder ──► "bestand ontvangen" + exportvraag (§3.3)
          └─ worker.postMessage({job, bitmap, opts}, [bitmap])
[worker]  pass A (≤600 px): alfa, achtergrondmasker, vorm, kader          → post 'shape'  (UI toont al de patch)
          pass B (≤360 px): palet (Lab k-means), labels, modusfilter,
                            kenmerken, classificatie, garenmatch          → post 'colors'
          pass C (≤360 px): distance transform, fijnste lijn, tekstregels,
                            steekschatting                                → post 'details'
          pass D (≤700 px): weergave-canvassen (origineel + gekwantiseerd) → post 'preview' (ImageBitmap, transfer)
[main]  elke stap werkt het advies progressief bij; nieuwe upload ⇒ job-id verhogen, oude berichten negeren
```

Resultaatobject (uitbreiding van demo-v2's `analyze()`):

```js
{
  job, srcW, srcH, kind: 'vlak'|'verloop'|'illustratie'|'foto', hasSoftShading,
  bg: {type:'transparant'|'wit'|'effen'|'geen', rgb, keep:boolean},  shape: 'rond'|'ovaal'|'rechthoek'|'schild'|'contour'|null,
  bbox: {x,y,w,h},                       // in bronpixels
  palette: [{rgb, lab, share, thin:boolean, thread:{code,name,hex,dE}, alt:[...]}],
  embroideryPalette: {k, merged:[[i,j],...]},     // vereenvoudigd voor borduren (§4.5)
  fineLinePx, textLines: [{label, heightPx, xHeightPx|null, bbox, arc:boolean}],
  regions: {fillAreaPx, satinLenPx, runLenPx, objects},   // voor de steekschatting (§11)
  features: {cover, hard, chg, fake, residual, flat, soft, texture}, warnings: [...]
}
```

Alle maten worden pas in de UI naar mm omgerekend met `mmPerPx` uit de gekozen patchgrootte (demo-v2 `geometry()`).

---

## 2. Kleurruimte: waarom CIELAB en ΔE2000

* RGB-afstand (demo-v1/v2: `dist2 < 52²`) is perceptueel ongelijk: twee donkerblauwen lijken in RGB ver uiteen, twee lichtgelen dichtbij. Clusteren en matchen in **CIELAB (D65)** lost het grootste deel op; **CIEDE2000** corrigeert de resterende fouten (blauw-gebied, neutralen, chroma). [bron] Sharma, Wu & Dalal 2005, https://hajim.rochester.edu/ece/sites/gsharma/ciede2000/
* Praktische verdeling: **clusteren** met kwadratische Lab-afstand (ΔE76, snel), **samenvoegen en garenmatch** met ΔE2000 (nauwkeurig, weinig paren).
* Implementatie (`deltaE00` in de referentiecode) is getest tegen de testparen van Sharma et al. (o.a. paar 1 → 2,0425; paar 13 → 7,1792 rond de discontinuïteit; paar 17 → 27,1492; paar 34 → 0,9082): **8/8 exact op 4 decimalen**. Valkuilen die de paper noemt (graden vs radialen, hue-gemiddelde bij |Δh|>180°) zijn afgedekt. Testdata: https://hajim.rochester.edu/ece/sites/gsharma/ciede2000/ en een onafhankelijke validatie: https://skinoptics.readthedocs.io/en/latest/_static/validation_Delta_E_00.html
* sRGB→Lab met een **256-waarden LUT** voor de gamma-linearisatie (zie code) — dat maakt de conversie van 130 k pixels ±10 ms.

Interpretatie (vuistregels, Lab/ΔE2000): < 1 onzichtbaar, 1–2 enkel naast elkaar zichtbaar, 2–5 duidelijk maar "dezelfde kleur", 5–10 andere tint, > 10 andere kleur. [algemeen; zie Sharma-pagina]

---

## 3. Invoer: bestandstypes, oriëntatie, grootte, transparantie

### 3.1 Raster (PNG, JPG, WEBP, GIF, AVIF)
* `accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml,application/pdf,.ai,.eps,.psd"` (de laatste vier worden niet geanalyseerd maar wél aangenomen, §3.3).
* Decoderen: `createImageBitmap(file, {imageOrientation:'from-image'})` (EXIF-rotatie van gsm-foto's). Browsers decoderen een Blob hierbij buiten de main thread. Fallback (oude Safari): `<img>` + `img.decode()`.
* **Maximale grootte**: iOS Safari weigert canvassen boven ±16,7 Mpx (4096×4096) [schatting/bekende beperking]; gsm-foto's zijn 12–48 Mpx. Analyseer daarom nooit op volle resolutie: pass A op ≤600 px, pass B/C op ≤360 px, weergave ≤700 px (zoals demo-v2). Downscalen in stappen van ×0,5 (`drawImage` met `imageSmoothingQuality='high'`) om aliasing (moiré op stof) te vermijden.
* **HEIC** (iPhone): iOS zet bij `<input type=file accept="image/*">` foto's doorgaans om naar JPEG [schatting, gedrag kan per iOS-instelling verschillen]. Kan `createImageBitmap` het bestand niet decoderen → zelfde vriendelijke melding als §3.3, bestand toch meesturen.
* GIF: alleen eerste frame. Bestanden > 25 MB: weigeren met uitleg (instelbaar).

### 3.2 SVG (rasteriseren op hoge resolutie)
* `createImageBitmap(svgBlob)` werkt **niet** voor SVG in de huidige browsers, zeker niet in een Worker ("The source image could not be decoded"). Werkwijze: **main thread** → `<img src=blobURL>` → canvas → `createImageBitmap(canvas)` → transfer naar de worker. [bron] https://coderapp.vercel.app/answer/79196371 , https://bugs.webkit.org/show_bug.cgi?id=182424
* SVG zonder `width/height` (enkel `viewBox`) heeft in Firefox `naturalWidth = 0`. Daarom eerst de tekst parsen (`DOMParser`), `width`/`height` zetten op de viewBox-verhouding met langste zijde **2048 px**, en pas dan laden.
* Een SVG in `<img>` voert geen scripts uit en laadt geen externe bronnen: veilig voor de preview. **Server-side** (upload naar WordPress/bestelling) moet een SVG wél gesaneerd worden (bv. `enshrined/svg-sanitize`, GPL-2.0-compatibel, via composer) — WordPress staat SVG standaard niet toe.
* Bonus uit de SVG-bron (voor de bouwers, fase 2): `fill`/`stroke`-waarden geven het **exacte** palet; `<linearGradient>/<radialGradient>` ⇒ `hasGradient=true` zeker; `<text>` ⇒ tekst zeker + `font-size`; ingesloten `<image href="data:...">` ⇒ eigenlijk een rasterbestand ("vector-wrapper", vaak een foto: behandel als raster).
* Vlakke SVG-logo's zijn na rasteriseren op 2048 px perfect "vlak" (anti-aliasing alleen aan randen): de classificatie in §6 vangt dat (zie `flatPng` in §13).

### 3.3 PDF / AI / EPS / PSD / CDR: niet in de browser
* PDF.js (`pdfjs-dist`, Apache-2.0) kan PDF (en AI-bestanden die "PDF-compatibel" zijn opgeslagen) renderen, maar is ±1 MB JS + worker. Voor een snelle productpagina (WP Rocket) **niet standaard laden**. EPS/PSD/CDR kunnen helemaal niet betrouwbaar in de browser.
* Aanbevolen UX (eerlijk en in lijn met de site-belofte *"Je hoeft geen perfect bestand te hebben"*):
  > **We hebben je bestand goed ontvangen.** Een .ai/.pdf kunnen we hier niet live tonen, maar onze digitaliseerder werkt er graag mee. Wil je nu al een voorbeeld zien? Exporteer het als **PNG** (min. 1000 px breed) of **SVG** en sleep het hierheen. Je kan ook gewoon verder: je krijgt sowieso een digitale proef vóór we starten.
* Fase 2 (optioneel, instelbaar): PDF.js **lazy** laden (dynamic `import()`) enkel wanneer de klant een PDF kiest; pagina 1 renderen op 2048 px en als raster analyseren.

### 3.4 Transparantie
* Pixel met `alpha < 128` = achtergrond (zoals demo-v2). **Voor kleurstatistiek** enkel `alpha ≥ 200` gebruiken: half-transparante randpixels hebben door premultiplied-alpha afrondfouten afwijkende kleuren (vooral bij lage alfa).
* Rand met > 50 % transparante pixels ⇒ `bg.type='transparant'` (demo-v2-regel).
* **Halfdoorschijnende schaduw** (drop shadow met 10–60 % alfa) herkennen: aandeel pixels met `20 ≤ alpha ≤ 230` die **niet** op de 1-px rand van het masker liggen > 3 % ⇒ `warnings.push('transparante schaduw')`. Advies: borduren/weven = schaduw wordt een effen garentint of valt weg; bedrukt = mogelijk.
* Transparantie in combinatie met effen kleur: transparante PNG's **met** een witte plaat eronder worden "wit"; dat is goed.

---

## 4. Achtergrond, vorm en verbeteringen op demo-v2

Demo-v2 (randmediaan → flood fill over zachte overgangen < 14 per kanaal en < 95 van bg → vlekjes < 2 % weg → vorm via ellips/rechthoek-vulling) werkt op beide eigenaar-beelden. Verbeteringen:

### 4.1 Doe de flood fill in Lab
Vervang `max(|ΔR|,|ΔG|,|ΔB|) < 14` door **ΔE76(buur) < 6** en `dist(bg) < 95` door **ΔE76(bg) < 35**. Lab is gelijkmatiger in schaduwen en geplooide witte stof (gsm-foto): plooien veranderen vooral L*, niet a*/b*. Extra regel voor gsm-foto's: sta grotere L*-sprongen toe zolang chroma laag blijft: `|ΔL| < 10 && Δchroma < 4`.

### 4.2 Wit in het ontwerp vs witte achtergrond
* Probleem: witte letters of een wit vlak dat de witte achtergrond **raakt** lekt weg in de flood fill.
* Regel 1 (bestaat): flood fill start enkel aan de rand ⇒ wit dat volledig door ontwerp omsloten is, blijft ontwerp. ✔
* Regel 2 (nieuw, "lek-test"): na de flood fill de achtergrond **morfologisch openen** (erosie 2 px + dilatatie 2 px op 360 px schaal). Achtergronddelen die daarbij loskomen van de rand-achtergrond (smalle "tongen" die het ontwerp binnenlopen, breedte < 4 px) terugzetten naar ontwerp.
* Regel 3 (vormcontrole): als een vorm herkend is (rond/ovaal/rechthoek), alles **binnen** de vorm (ellips-test `e ≤ 0,92`) is ontwerp, ook als het wit is. Dat lost het "witte cirkel-binnenvlak" op.
* Weergave: witte achtergrond = papier (BRIEF): niet overnemen, stofkleur automatisch kiezen. **Wit binnen het ontwerp** wordt witte garen — tenzij de stof wit is: dan kan de digitaliseerder die delen **open laten** (stof zichtbaar), wat steken spaart. Zeg dat ook: *"Op witte stof laten we de witte delen open: dat geeft een strakker resultaat en scheelt steken."*
* Definitie "wit" (Lab): **L\* > 92 en C\* < 6** (C\* = √(a²+b²)). "Bijna-wit papier/foto": L\* > 80 en C\* < 10 én de flood fill bereikt het via de rand.

### 4.3 Halo en anti-aliasing aan de ontwerprand
Logo's op wit hebben aan de rand een krans van mengkleuren (logo-kleur ↔ wit). Zonder correctie wordt dat een extra lichte "kleur" of een witte rand in het voorbeeld op donkere stof.
```
voor elke ontwerppixel p op de maskerrand (een buur is achtergrond):
    c = kleur(p); f = dichtstbijzijnde paletkleur van een binnenbuur (2 px naar binnen)
    t = projectie van c op lijn bg→f in Lab, geklemd [0,1]          // ≈ dekking
    als ΔE(c, lerp(bg,f,t)) < 4:  alfa(p) = t; kleur(p) = f        // "decontaminate"
```
Gebruik `alfa(p)` voor de weergave (zachte rand op elke stofkleur) en tel deze pixels **niet** mee in het palet.

### 4.4 Gsm-foto van een echte patch
Bestaat in demo-v2. Aanvullend: (a) **perspectief**: na het masker een ellips fitten (momentenmethode: covariantie van maskerpixels → assen en hoek). Assenverhouding 0,85–1,0 en vulling > 0,95 ⇒ "rond, licht schuin gefotografeerd" ⇒ affine rechttrekken (ellips → cirkel) vóór kleuranalyse. (b) Licht ongelijk: per kleurcluster de L\*-spreiding meten; is die > 15 terwijl a\*/b\* stabiel blijven ⇒ schaduw/licht, niet ontwerp ⇒ samenvoegen (MERGE in §4.5 met kL=2, d.w.z. L\* half zo zwaar wegen). (c) Altijd tonen: *"We hebben je patch uit de foto geknipt"* + knop "Bijstellen".

### 4.5 Kleurkwantisatie (algoritmekeuze)

| Methode | Kwaliteit | Snelheid | Opmerking |
|---|---|---|---|
| Popularity / RGB-buckets (demo) | laag | zeer snel | mist kleine maar belangrijke kleuren (rode accenten) |
| Median cut (Heckbert) | middel | snel | deelt dozen langs grootste as; goed startpunt |
| Wu (1991, variantie-minimalisatie) | goed | snel | deterministisch; goede init voor k-means |
| **k-means in Lab (k-means++ init)** | **best** | ok op histogram | Celebi 2011: "efficient k-means with appropriate initialization … very effective color quantizer"; quantette-benchmark: k-means/Oklab beter dan Wu/sRGB op DSSIM bij 16/64/256 kleuren |

[bron] Celebi, *Improving the performance of k-means for color quantization*, https://arxiv.org/abs/1101.0395 · quantette benchmark https://docs.rs/crate/quantette/0.1.0 · kleurruimte hangt af van beeld: https://arxiv.org/pdf/2601.19117

**Keuze: gewogen k-means++ in Lab op een 5-bit histogram** (max 32 768 punten, in de praktijk 300–3000) → onafhankelijk van beeldgrootte, deterministisch (vaste seed, zodat dezelfde upload altijd hetzelfde advies geeft).

```
pts      = labHistogram(rgba, mask)                // {n, lab} per 5-bit bin, alleen alfa≥200 en niet-halo
km       = kmeansLab(pts, k=16, iters≤12)          // k-means++ seed = grootste bin, daarna D²-gewogen
clusters = samenvoegen zolang min ΔE2000(i,j) < 7  // gewogen gemiddelde
palet    = clusters met aandeel ≥ 0,4 %   OF  (dunne-lijn-uitzondering)
labels   = dichtstbijzijnde paletkleur per pixel → 3×3 modusfilter (demo-v2) 
```

* **Dunne-lijn-uitzondering**: een kleur < 0,4 % die in ≥ 1 connected component voorkomt met lengte (bbox-diagonaal) > 10 % van het ontwerp en dikte ≤ 3 px (distance transform) = echte dunne lijn (bv. rode cirkellijn in het eigenaar-ontwerp, 1,1–1,3 %) ⇒ **behouden**, `thin:true`.
* **Anti-aliasing-kleur verwijderen**: kleur c met aandeel < 3 % is een mengkleur als er i,j in het palet bestaan met `ΔE00(c, lerp(i,j,t)) < 3` voor een t∈[0,15;0,85] **en** ≥ 70 % van zijn pixels grenst aan zowel i als j. ⇒ pixels hertoewijzen aan de dichtste van i/j.
* **JPG-ruis**: geen voorfilter nodig voor q ≥ 30 (gemeten: zelfde 4 kleuren als PNG, residu 0,42 vs 0,16; §13). Alleen bij zeer zware compressie (blokartefacten, `flat < 0,85` bij een `cover ≤ 4` beeld) een 3×3 mediaanfilter vóór het histogram.
* **Borduurpalet (vereenvoudiging)**: voor illustraties/foto's het palet herhaald samenvoegen met oplopende drempel (7 → 10 → 14 → 18 ΔE00) tot ≤ doel (instelbaar, standaard **8**, maximum site **12**). Toon in de UI een schuif *"Aantal garenkleuren: 6 · 8 · 10 · 12"* met live voorbeeld (kwantiseer weergavecanvas met dat palet). Dit is precies wat de digitaliseerder doet en maakt het advies tastbaar.
* Samenvoegen in Lab met **kL = 2** (L\* half gewicht) voor foto's van echte stof (licht/schaduw), **kL = 1** voor digitale bestanden.

---

## 5. Garenmatch (ΔE2000) en garenkaarten

### 5.1 Data en licenties
| Bron | Inhoud | Licentie / opmerking |
|---|---|---|
| **Eigen site** `borduurservicebelgie.be/beschikbare-kleuren-garen/` → `01-garenkleuren-polyneon.json` (411 kleuren, code+naam+hex) | het assortiment dat de klant echt heeft | **Eerste keus.** Eigen gepubliceerde data van de klant. Hex = schermkleur, geen meetwaarde. |
| Ink/Stitch-paletten (`palettes/*.gpl`: Madeira Polyneon/Rayon, Isacord, Gunold, Robison-Anton, Sulky, …) https://github.com/inkstitch/inkstitch/tree/main/palettes | RGB per garennummer voor ±75 merken | Repository **GPL-3.0**. WordPress-plugins zijn GPL ⇒ compatibel mits naamsvermelding en GPL-3.0-verenigbare plugin-licentie ("GPL-2.0-or-later" is OK). Waarden zijn ook door de community overgenomen benaderingen. |
| Fabrikant-PDF's (Madeira, Amann/Isacord Download Center) | officiële kaarten, soms RGB/Pantone | Auteursrecht/ **EU-databankenrecht** (Richtlijn 96/9/EG) op substantiële overname ⇒ niet integraal kopiëren zonder toestemming. |

Gemeten: de site-hexwaarden en Ink/Stitch "Madeira Polyneon" komen voor alle **349** gedeelde codes overeen met mediaan ΔE76 = 0,63 (p90 = 4,2) — ze hebben dus duidelijk dezelfde oorsprong. Gebruik de **site-lijst**; Ink/Stitch is een fallback voor kleuren zonder hex.

**Merkrecht**: Madeira®, Polyneon®, Isacord® zijn merken. Gebruik ze beschrijvend ("garen: Madeira Polyneon 1839 Christmas Red"), geen logo's, en vermeld in de UI: *"Schermkleuren zijn benaderingen; garen glanst en kan afwijken. We stemmen de kleuren af in je digitale proef."* Geen ΔE-cijfer tonen aan klanten (verwarrend); wel de kwaliteitsbadge.

### 5.2 Algoritme
```
threads = prepareThreads(json)               // hex→Lab één keer; cache in worker
voor elke paletkleur c:
    if L*(c) < 15 && C*(c) < 8:  thread = config.black     // site-swatches van zwart zijn grijzig (Ebony #191D29 → ΔE 10 t.o.v. #000)
    elif L*(c) > 92 && C*(c) < 6: thread = config.white
    else: top3 = sort(threads, ΔE00(c, t.lab))[0..2]
    kwaliteit = ΔE<2 "zeer goed" | <5 "goed" | <10 "benadering" | ≥10 "geen exacte garenkleur"
samenvallen: twee paletkleuren → zelfde garen ⇒ samenvoegen en zeggen ("2 tinten grijs worden 1 garenkleur")
```
* Snelheid: 16 kleuren × 411 garens ≈ 4 ms (1000 matches = 229 ms in Node).
* Dichtheid van de kaart: mediane afstand tot het dichtste buurgaren = **ΔE00 3,7** (p90 5,9). ΔE < 2 is dus zelden haalbaar; "goed" (< 5) is de realistische norm.
* Proef met huisstijlkleuren: coral #FD5C48 → 1778 Carrot (ΔE 4,9 goed); blauw #21A9E2 → 1593 Blue Macaw (2,1 goed); mint #52D7B4 → 1845 Mint (5,9 benadering); **paars #5926C5 → beste 1566 (10,5): geen goede match.** Eerlijk advies in dat geval: *"Deze paarse tint bestaat niet exact als garen. De dichtste is 1566 Brilliant Blue (iets blauwer). Wil je exact deze kleur? Kies dan bedrukt."* — dat is overtuigen met deskundigheid.
* Admin: `config.black`/`config.white` instelbaar; per garen een vlag "op voorraad".

---

## 6. Detectie: vlak logo / verloop / illustratie / foto

### 6.1 Kenmerken (op het ontwerpmasker, langste zijde 360 px — de drempels zijn schaalafhankelijk!)
| Kenmerk | Definitie | Intuïtie |
|---|---|---|
| `cover` | aantal 4-bit RGB-buckets dat 85 % van de pixels dekt (demo-v2) | hoeveel "echte" tinten |
| `hard` | aandeel buurparen met ΔE76 ≥ 18 | contouren/harde randen |
| `chg` | aandeel buurparen met verschillend label (na palet) | hoeveel randen er na kwantisatie zijn |
| `fake` | van die labelwissels: aandeel waar het origineel ΔE76 < 6 heeft | **valse randen** = banding in verloop/schaduw |
| `residual` | gemiddelde ΔE00 pixel→palet | hoe goed het palet het beeld benadert |

### 6.2 Beslisboom (in `classify()`, volgorde belangrijk)
```
if fake < 0.08 and cover ≤ 12                    → 'vlak'          (effen logo; ook JPG q30–60)
elif cover ≥ 70 and hard < 0.04                  → 'foto'          (veel tinten, weinig contouren)
elif hard < 0.012 and chg < 0.03 and cover ≤ 40  → 'verloop'       (vloeiend, bijna geen randen)
else                                             → 'illustratie'   (contouren + tinten/schaduw)
hasSoftShading = fake ≥ 0.15                     (los vlagje: "er zit ergens een verloop/schaduw in")
```
Advies per klasse (afstemmen met 04-patch-techniek.md):
* **vlak** → borduren is ideaal (exact palet, garenmatch tonen).
* **verloop** → borduren: verloop wordt 2–4 garenbanden (of "gradient fill" door digitaliseerder, beperkt); geweven: beperkt mengen; bedrukt: exact.
* **illustratie** → BRIEF-feedback: GEEN "foto" noemen. Geweven = meeste detail; geborduurd mits vereenvoudiging tot *N* garenkleuren (toon de schuif uit §4.5); bedrukt = exact.
* **foto** → bedrukt/sublimatie aanbevolen; borduren enkel als gestileerde versie (eerlijk zeggen).

Waarom niet de demo-drempel `cover > 150 ⇒ foto`: de echte CC0-foto's halen 89–113, het eigenaar-ontwerp 42–45. 150 classificeert foto's als illustratie.

### 6.3 Mock-up-herkenning (eigenaar-PNG)
Het eigenaar-PNG is een **mock-up** met geweven stofstructuur (`texture` = 0,71, hoogste van alle tests; echte digitale illustraties 0,02–0,03). Regel: `texture > 0.6 && hard > 0.04 && kind==='illustratie'` ⇒ `warnings.push('mockup')` ⇒ tekst: *"Dit lijkt al een afbeelding van een patch (met stofstructuur). Geen probleem: we gebruiken het als voorbeeld. Heb je het originele ontwerpbestand? Dan wordt het resultaat nog scherper."* Gebruik bij een mock-up voor de fijnste-lijn-meting een 3×3 mediaan vooraf (stofstructuur geeft anders valse fijne lijnen).

---

## 7. Tekstdetectie (vooral: te kleine letters)

Geen OCR nodig (Tesseract.js = enkele MB aan data, te zwaar en overbodig): we hoeven niet te lezen wát er staat, enkel **hoe groot** het is.

```
voor elk label ℓ in het palet (behalve achtergrond/stofkleur):
    comps = connected components (8-buren) van pixels met label ℓ
    kandidaat c als:
        h = bbox-hoogte (bij gedraaide tekst: kleinste-omsluitende-rechthoek via PCA → korte zijde-lengte langs de regel)
        0.008·Hontwerp ≤ h ≤ 0.15·Hontwerp
        0.08 ≤ w/h ≤ 1.6               (letters, ook "I" en "M")
        0.15 ≤ pixels/(w·h) ≤ 0.85     (geen volle blokken, geen haarlijnen)
        sw = 2·mediaan(distance transform op skelet);  0.05 ≤ sw/h ≤ 0.35;  CV(sw) < 0.6  (gelijke lijndikte = letter)
    groepeer kandidaten tot regels:
        zelfde label, |h1−h2| < 0.3·max(h), afstand tussen centroïden < 1.8·h
        ≥ 3 kandidaten; centroïden liggen op een lijn (residu < 0.25·h)  → rechte regel
        of op een cirkel (RANSAC 3-punts cirkel, residu < 0.25·h, r > 2h) → boogtekst (zoals "IRON CRUSADERS")
    per regel: capHoogte = 80e percentiel van h
               kleine letters: tweede hoogtecluster met verhouding 0.5–0.72 ⇒ xHoogte (die is maatgevend)
               lijndikte = mediaan sw
```
Omrekenen: `letterhoogte_mm = capHoogte_px · mmPerPx(analyse)`. Toetsen aan minima (zie 04-patch-techniek.md; site: borduren min. **4 mm**): toets de **x-hoogte** als er kleine letters zijn (x-hoogte ≥ 0,6 × minimum), en de **lijndikte** (satijn ≥ 1 mm, anders loopsteek ⇒ "dunner en minder dekkend"). Schreef/script-lettertypes (hoge `CV(sw)` 0,4–0,6) ⇒ minimum +1 mm.

UI: markeer de regel in het voorbeeld met een kader + *"Deze tekst wordt ±3,1 mm hoog. Borduren kan vanaf 4 mm: maak de patch 7 cm breed of kies geweven (vanaf ±2 mm)."* (Concreet getal = berekende grootte die het wél haalt.)

Valkuilen: sterren, kogels, puntjes (rond, `w/h≈1`, `CV(sw)` laag) kunnen kandidaten zijn ⇒ eis ≥ 3 op een rij; omkaderde tekst (letters raken kader) ⇒ component te groot ⇒ wordt gemist (aanvaardbaar: de fijnste-lijn-meting vangt het meestal).

---

## 8. Fijnste lijn (bestaand, kleine verbeteringen)
Demo-v2's chamfer distance transform + rugpixels + gewogen 4e percentiel blijft. Verbeteringen: (1) uitvoeren op de **gemoduleerde labels** (na modusfilter) — gebeurt al; (2) rugpixels op de **maskerrand** van een herkende patchvorm negeren (de rand van de patch zelf is geen dunne lijn); (3) lijnen met `thin:true` (§4.5) altijd meenemen; (4) bij mock-ups eerst mediaan (§6.3).

---

## 9. Web Worker + OffscreenCanvas (snelheid)

* Gemeten (Chromium desktop, main thread): volledige kleuranalyse 360 px **23–117 ms** per beeld (§13). Op een middenklasse-gsm reken ×3–5 ⇒ 0,1–0,6 s: in een worker blijft de UI vloeiend (slepen, schuifbalken) en de eerste patchvorm verschijnt na pass A.
* **OffscreenCanvas 2D** in workers: Chrome 69+, Firefox 105+, Safari 16.4+ [bron: MDN https://developer.mozilla.org/en-US/docs/Web/API/OffscreenCanvas ; caniuse https://caniuse.com/offscreencanvas]. Fallback: dezelfde functies op de main thread, opgeknipt met `await new Promise(r=>setTimeout(r))` tussen de passes. De referentiecode gebruikt alleen typed arrays, dus werkt in beide.
* Protocol:
```js
// main
const w = new Worker(PS.workerUrl);            // same-origin plugin-asset, ?ver=PLUGIN_VERSION
let job = 0;
async function analyse(file){ const my = ++job;
  const bmp = isSvg(file) ? await svgToBitmap(file, 2048) : await createImageBitmap(file, {imageOrientation:'from-image'});
  w.postMessage({job: my, bmp, opts}, [bmp]); }
w.onmessage = e => { if (e.data.job !== job) return; render(e.data.stage, e.data.result); };
// worker
onmessage = async ({data:{job, bmp, opts}}) => {
  const c = new OffscreenCanvas(...fit(bmp, 600)); const x = c.getContext('2d', {willReadFrequently:true});
  x.drawImage(bmp, 0, 0, c.width, c.height);
  postMessage({job, stage:'shape', result: passA(x)}); /* … passB, passC … */
  const prev = await createPreview(...); postMessage({job, stage:'preview', result:{orig:prev.o, quant:prev.q}}, [prev.o, prev.q]);
};
```
* **WP Rocket**: zet het worker-script en het hoofd-script op de uitsluitingslijst van *Delay JavaScript execution* en *Combine/Minify JS* (anders breekt `new Worker(url)` of laadt het pas na interactie). Laad Patch Studio-JS alleen op pagina's met de shortcode/het blok. Als de site een CDN-domein gebruikt voor assets: worker vanuit een **Blob-URL** starten (`new Worker(URL.createObjectURL(new Blob([await (await fetch(url)).text()])))`), want cross-origin workers zijn niet toegestaan.
* Geheugen: `bitmap.close()` na gebruik; nooit de originele 48 Mpx-foto in een canvas tekenen.

---

## 10. Wat de klant te zien krijgt (analysestappen als vertrouwen)
Progressief, kort, zonder vakjargon (eerlijk: de stappen gebeuren echt):
1. "Achtergrond herkend: wit — we kiezen de stofkleur" / "Vorm herkend: rond — je ontwerp vult de patch"
2. "6 kleuren gevonden → 6 garenkleuren (Madeira Polyneon)" met swatches + kwaliteit
3. "Fijnste lijn ±0,6 mm · kleinste tekst ±3,1 mm bij 8 cm"
4. "Geschat ±5.100–8.000 steken" (alleen tonen als de prijs op steken gebaseerd is; anders weglaten)

---

## 11. Steekaantal en productietijd

### 11.1 Steektype per gebied (uit de distance transform)
Lokale breedte `w = 2·D` op de rug (mm, via mmPerPx):
* `w < 1,0 mm` → **loopsteek** (enkel/drievoudig); lengte = skeletlengte.
* `1,0 ≤ w ≤ 7 mm` → **satijn**; lengte = oppervlakte / gemiddelde breedte (of skeletlengte). Satijn > ±8–12 mm wordt los en kwetsbaar [bron: Wilcom-help "12 mm max satin"; https://docs.wilcom.com/embroiderystudio/28/en/OnlineHelp/Digitizing/stitches/stitches-11.htm].
* `w > 7 mm` → **vulling (tatami)**; oppervlakte.
* Gebieden in de **stofkleur** worden niet gestikt (gedeeltelijke dekking). Bij een volledig gestikte achtergrond ("100 % garen") telt de patch-oppervlakte min ontwerp als vulling.

### 11.2 Formule (`estimateStitches`, constanten `ST` in de referentiecode, admin-instelbaar)
```
steken = 1.6 · vulOpp_mm²  + 5.0 · satijnLengte_mm + 0.4 · loopLengte_mm
       + 30 · kleuren      + 15 · objecten
       (+ 6.0 · omtrek_mm  als de rand een satijnrand is; merrowrand = aparte machine, niet meetellen)
bereik = [0.8 · steken ; 1.25 · steken]   → altijd als bereik tonen, afgerond op 100
```
Onderbouwing:
* Vulling ±2000 st/inch² bij 100 % dekking, logo's gemiddeld 1000–1500 st/inch² [bron: vuistregels uit stitch-estimators, o.a. https://www.blankstyle.com/embroidery-stitch-estimator.pdf , https://help.apliiq.com/portal/en/kb/articles/estimated-stitch-counts-for-embroidery]. 1 inch² = 645 mm² ⇒ 1,55–3,1 st/mm². Rekenvoorbeeld (Bernina): vlak 10×40 mm, densiteit 0,4 mm = 626 steken = **1,56 st/mm²** [bron: https://blog.bernina.com/en/?p=18093]. ⇒ 1,6 incl. onderlaag als middenwaarde.
* Satijn 4–6 mm breed ≈ 150 st per lopende inch = **5,9 st/mm**; zelfde vlak als satijn i.p.v. vulling: 217 i.p.v. 626 steken [bronnen idem]. ⇒ 5,0 st/mm (smalle satijnen tellen minder).
* Loopsteek 2,5 mm steeklengte = 0,4 st/mm (drievoudig: ×3) [afgeleid].
* +30/kleur en +15/object (onderlaag, vastzetsteken, trims) [schatting; kalibreren met echte .DST-bestanden van de klant: neem 10 bestaande orders, vergelijk schatting en echt, pas `FILL_PER_MM2` en `SATIN_PER_MM` aan — dit is de belangrijkste open taak].
* Voorbeeld: 2500 mm² vulling + 400 mm satijn + 200 mm loop + 5 kleuren + 12 objecten ⇒ **6.400 (5.100–8.000)** steken.

### 11.3 Productietijd (`productionMinutes`)
```
minuten = steken / (spm · efficiëntie) + kleuren·kleurwissel_s/60 + kleuren·trims·trim_s/60
standaard: spm 750, efficiëntie 0,7, kleurwissel 6 s, 2 trims/kleur à 4 s   [schatting — admin]
```
Voorbeeld hierboven: ±13 min per kop. Bij meerkoppige machines/meerdere patches per raam: `tijd_order = ceil(aantal / (koppen·patchesPerRaam)) · minuten + insteltijd`. **Niet** als leverbelofte tonen; enkel intern of voor prijsberekening. De levertijd op de site blijft leidend.

---

## 12. Drempels — overzicht (één tabel om te implementeren)

| Naam | Waarde | Waar |
|---|---|---|
| Analysegrootte A / B,C / weergave | 600 / 360 / 700 px | §1 |
| Alfa achtergrond / statistiek | < 128 / ≥ 200 | §3.4 |
| Flood fill buur / t.o.v. bg | ΔE76 < 6 / < 35 (L-sprong < 10 bij C\* < 4) | §4.1 |
| Wit | L\* > 92, C\* < 6 | §4.2, §5.2 |
| Zwart (garen-speciaal) | L\* < 15, C\* < 8 | §5.2 |
| k-means k / iteraties | 16 / ≤ 12 | §4.5 |
| Samenvoegen palet | ΔE00 < 7 (foto van stof: kL = 2) | §4.5 |
| Min. kleuraandeel | 0,4 % (behalve dunne lijn) | §4.5 |
| Mengkleur (anti-aliasing) | ΔE00 < 3 tot lerp, t∈[0,15;0,85], ≥ 70 % grenspixels, aandeel < 3 % | §4.5 |
| Borduurpalet doel / max | 8 / 12; drempels 7→10→14→18 | §4.5 |
| Rand: vlak / hard | ΔE76 < 2,5 / ≥ 18 | §6 |
| Valse rand | origineel ΔE76 < 6 bij labelwissel | §6 |
| vlak | fake < 0,08 en cover ≤ 12 | §6.2 |
| foto | cover ≥ 70 en hard < 0,04 | §6.2 |
| verloop | hard < 0,012, chg < 0,03, cover ≤ 40 | §6.2 |
| hasSoftShading | fake ≥ 0,15 | §6.2 |
| mock-up | texture > 0,6, hard > 0,04 | §6.3 |
| Garenkwaliteit | < 2 zeer goed, < 5 goed, < 10 benadering | §5.2 |
| Tekstkandidaat | h 0,8–15 % ontwerp, w/h 0,08–1,6, vulling 0,15–0,85, sw/h 0,05–0,35, CV(sw) < 0,6, ≥ 3 op lijn/boog | §7 |
| Steektype | < 1 mm loop, 1–7 mm satijn, > 7 mm vulling | §11.1 |
| Steken | 1,6/mm² · 5/mm · 0,4/mm · 30/kleur · 15/object; bereik ×0,8–×1,25 | §11.2 |

---

## 13. Testbeelden en kalibratieresultaten

Draaien: `cd patch-studio && NODE_PATH=$(npm root -g) node docs/research/07-testharness.js`
(De harness gebruikt voor de twee eigenaar-beelden een handmatig cirkelmasker; in de plugin komt het masker uit §4.)

| Beeld | Soort (verwacht) | cover | hard | chg | fake | residu | klasse | ms |
|---|---|---|---|---|---|---|---|---|
| eigenaar-ronde-patch.png | illustratie (mock-up) | 45 | 0,054 | 0,255 | 0,22 | 3,86 | illustratie ✔ | 117 |
| eigenaar-foto-patch.jpg | illustratie (gsm-foto) | 42 | 0,126 | 0,304 | 0,11 | 3,44 | illustratie ✔ | 77 |
| tourist-and-building.webp | foto | 113 | 0,018 | 0,141 | 0,50 | 3,60 | foto ✔ | 66 |
| art-gallery.webp | foto | 89 | 0,021 | 0,142 | 0,47 | 3,84 | foto ✔ | 68 |
| green-staircase.webp | minimalistische foto | 13 | 0,021 | 0,045 | 0,29 | 1,46 | illustratie (aanvaardbaar: weinig tinten) | 67 |
| abstract-geometric-art.webp | grafisch | 24 | 0,038 | 0,140 | 0,27 | 2,80 | illustratie ✔ | 41 |
| synth vlak logo PNG | vlak | 2 | 0,014 | 0,009 | 0,00 | 0,16 | vlak ✔ | 41 |
| idem JPG q60 | vlak | 2 | 0,017 | 0,009 | 0,005 | 0,42 | vlak ✔ | 41 |
| idem JPG q30 | vlak | 2 | 0,018 | 0,015 | 0,015 | 0,42 | vlak ✔ | 42 |
| synth lineair verloop + tekst | verloop | 13 | 0,005 | 0,016 | 0,69 | 2,20 | verloop ✔ | 26 |
| idem JPG q70 | verloop | 14 | 0,007 | 0,020 | 0,47 | 2,57 | verloop ✔ | 35 |
| synth radiaal verloop | verloop | 7 | 0 | 0,014 | 1,00 | 2,01 | verloop ✔ | 34 |
| synth illustratie (contour + schaduw) | illustratie | 15 | 0,020 | 0,032 | 0,46 | 1,24 | illustratie ✔ | 37 |
| idem JPG q70 | illustratie | 19 | 0,023 | 0,041 | 0,27 | 1,66 | illustratie ✔ | 45 |

Paletten (fragment): vlak logo PNG → `#ffffff 72,1 % · #5926c5 18,4 % · #fd5c48 7,4 % · #1e1b1b 1,2 %` — JPG q60 → `#ffffff · #5826c6 · #fc5c49 · #1d1c1c` (ΔE < 1). Eigenaar-ontwerp: 14 kleuren incl. de dunne rode lijn (#a22d2f 1,3 %) ⇒ borduurpalet 8 via §4.5.

**Nog toe te voegen aan de testset** (bouwers/QA): transparante PNG met drop shadow; wit logo op wit met aanrakende witte letters; zwart-wit lijntekening (1 kleur, haarlijnen); scan van een handschets (papier grijzig, potlood); schild-vorm; tekst-only logo met kleine letters 2–3 mm bij 6 cm; SVG zonder width/height; SVG met `<linearGradient>`; HEIC; 48 Mpx gsm-foto (geheugen); sterk JPG q10.

---

## 14. Open punten
1. **Steekconstanten kalibreren** met 10–20 echte borduurbestanden (.DST/.EMB) + bijbehorende ontwerpen van de klant (§11.2).
2. Garen zwart/wit-codes en "op voorraad"-vlag bevestigen bij de eigenaar (site-swatches van zwart/wit zijn grijzig).
3. Drempels zijn gekalibreerd op 14 beelden bij 360 px; na livegang analyses (anoniem: kenmerken + gekozen techniek, geen beelden) loggen om bij te stellen — enkel met cookie-/privacytoestemming.
4. PDF.js lazy-load (fase 2) en SVG-bronanalyse (exact palet) zijn nog niet prototyped.
5. Perspectiefcorrectie (§4.4a) en tekstdetectie (§7) zijn pseudocode, niet in de referentiecode.

---

## Bronnen
* CIEDE2000, formules en testdata — Sharma, Wu & Dalal (2005): https://hajim.rochester.edu/ece/sites/gsharma/ciede2000/ · paper: https://hajim.rochester.edu/ece/sites/gsharma/papers/CIEDE2000CRNAFeb05.pdf
* Onafhankelijke ΔE00-validatie: https://skinoptics.readthedocs.io/en/latest/_static/validation_Delta_E_00.html
* Celebi, k-means voor kleurkwantisatie: https://arxiv.org/abs/1101.0395
* quantette (Wu vs k-means, sRGB/Lab/Oklab): https://docs.rs/crate/quantette/0.1.0
* Maitra 2026, kleurruimte en k-means: https://arxiv.org/pdf/2601.19117
* Ink/Stitch garenpaletten (GPL-3.0): https://github.com/inkstitch/inkstitch/tree/main/palettes
* Garenkleuren eigen site: https://borduurservicebelgie.be/beschikbare-kleuren-garen/
* Conversiekaarten/ RGB-benaderingen: https://edutechwiki.unige.ch/en/Embroidery_thread_color_and_chart , https://support.oesd.com/article/48-machine-embroidery-thread-conversion-charts
* EU-databankenrichtlijn 96/9/EG: https://eur-lex.europa.eu/legal-content/NL/TXT/?uri=CELEX:31996L0009
* SVG en createImageBitmap in workers: https://coderapp.vercel.app/answer/79196371 · https://bugs.webkit.org/show_bug.cgi?id=182424
* OffscreenCanvas: https://developer.mozilla.org/en-US/docs/Web/API/OffscreenCanvas · https://caniuse.com/offscreencanvas
* Steekschatting: https://blog.bernina.com/en/?p=18093 · https://www.blankstyle.com/embroidery-stitch-estimator.pdf · https://help.apliiq.com/portal/en/kb/articles/estimated-stitch-counts-for-embroidery · https://docs.wilcom.com/embroiderystudio/28/en/OnlineHelp/Digitizing/stitches/stitches-11.htm · https://wilcom.com/resources/blog/stitch-spacing-or-density
* SVG-sanitizer (PHP): https://github.com/darylldoyle/svg-sanitizer (composer `enshrined/svg-sanitize`)
* PDF.js: https://github.com/mozilla/pdf.js
* Testfoto's CC0 uit Twenty Twenty-Four: https://downloads.wordpress.org/theme/twentytwentyfour.zip (zie `reference/testbeelden/LICENTIE.txt`)

Noot: een deel van de webpagina's kon niet rechtstreeks opgehaald worden (netwerkbeperking); cijfers van die pagina's komen uit zoekresultaat-samenvattingen en zijn als [bron] gemarkeerd met een middenwaarde. Alle eigen metingen (§5, §13) zijn reproduceerbaar met de bijgeleverde scripts.
