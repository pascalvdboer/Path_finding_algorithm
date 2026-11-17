# 04 — Patch-techniek: vakregels per patchtype (voor de adviesmotor)

Onderzoeker: "patch-techniek". Stand: 16-11-2026.
Doel: de bouwers hebben **één bron** met concrete, toetsbare regels om een ontwerp per techniek te beoordelen
(maat, letterhoogte, lijndikte, kleuren, randen, achterkant, wassen, levertijd) en om steken/prijs te schatten.
Het machine-leesbare blok staat in **§10** (`patchTechRules`). Alles daarbuiten is uitleg en kant-en-klare adviestekst.

## 0. Hoe betrouwbaar zijn deze cijfers? (lees dit eerst)

- Er bestaat **geen industrienorm**. Elke fabrikant publiceert eigen minima en die verschillen (bv. chenille min. maat
  1", 2" of 3" naargelang de leverancier). Ik geef daarom per regel een **"hard"** waarde (onder deze grens: "kan niet/afraden")
  en een **"comfort"** waarde (vanaf hier: "ziet er strak uit"). Tussen beide = "kan, met aandachtspunt".
- Waar **borduurservicebelgie.be zelf** een waarde publiceert, wint die (eigen machines, eigen beloftes):
  min. teksthoogte borduren **4 mm**, **tot 12 kleuren**, twill 180 g/m², polyester garen **wasbaar tot 60 °C**,
  keuzehulp-grens "details kleiner dan ± 3 mm" (bron: [geborduurd](https://borduurservicebelgie.be/patches/geborduurd/),
  [/patches/](https://borduurservicebelgie.be/patches/), samengevat in `02-huidig-aanbod.md` §7 en `01-merk-en-site.md` §4.2).
- Labels in dit document: **[bron]** = letterlijk/omgerekend uit een bron; **[afgeleid]** = mijn vakmatige afleiding of
  middeling van bronnen; **[eigenaar]** = moet de eigenaar bevestigen voor eigen productie.
- Alle getallen zitten in de config (§10) zodat de eigenaar ze kan bijstellen. Bouw **geen** hard gecodeerde drempels.
- Omrekening: 1 inch = 25,4 mm; 1 in² = 6,4516 cm²; 1 pt = 0,353 mm.
- Netwerk: worldemblem.com, stahls.com en transferexpress.com konden niet rechtstreeks geopend worden in deze sessie;
  hun specs komen uit zoekresultaat-uittreksels van die pagina's (URL's vermeld). Bij twijfel: eigenaar laten valideren.

---

## 1. Kernbegrippen die de tool moet kennen

### 1.1 Meten op ware grootte
Alle regels gelden in **millimeter op de afgewerkte patch**, niet in pixels. De analyse moet dus omrekenen:

```
mmPerPx      = bruikbareBreedteMm / ontwerpBreedtePx
bruikbaar    = patchMaatMm − 2 × randZoneMm        (zie 1.2)
letterHoogte = letterHoogtePx × mmPerPx
lijnDikte    = (2 × distanceTransformMax_px) × mmPerPx
```
"Maat" op de site = **langste zijde** van de patch (`02-huidig-aanbod.md` §2).

### 1.2 De rand neemt ruimte in (belangrijk!)
Een rand ligt **over** of **naast** de buitenkant van het ontwerp. Het ontwerp krimpt dus, of de rand bedekt detail.
| Rand | Breedte rand | Veilige afstand ontwerp → buitenkant | Bron |
|---|---|---|---|
| Merrow / overlock (kettelrand) | 2–4 mm (typ. 3 mm, ⅛") | 3 mm (woven), 5 mm ± 2 mm in overlap-zone; borduurdetail ≥ 1,6 mm van de rand | [bron] [World Emblem woven/embroidered](https://worldemblem.com/patches/woven), [A-B Emblem design tips](https://abemblem.com/design-tips/), [Transfer Express woven spec](https://assets.transferexpress.com/pdf/specs/TXP-Woven-SpecSheet.pdf) (5 mm ± 1), [Stahls woven spec](https://assets.stahls.com/stahls/content/pdf/flyers/STAHLS-SpecsSheet-WovenPatches.pdf) (6 mm ± 1) |
| Geborduurde satijnrand + hot-cut/lasercut | 1,6–3 mm (1/16"–1/8") | ≥ 1 mm binnen de satijnrand | [bron] [Fairware guide](https://online.flippingbook.com/view/649798138); [afgeleid] |
| Randloos (lasercut/hot-cut zonder satijn) | 0 | 3 mm ± 1 mm ontwerp → snijlijn (woven) | [bron] World Emblem woven |
| Chenille viltrand | 4 mm ± 1–1,5 mm + 2 mm geborduurde binnenomlijning | 4–6 mm | [bron] [Transfer Express chenille](https://assets.transferexpress.com/pdf/specs/TXP-Chenille-SpecSheet.pdf) |
| PVC (gegoten rand) | 1–2 mm opstaande rand (meestal) | ≥ 1 mm | [afgeleid] |
| Leder (lasercut contour) | 0 | ≥ 2 mm (verbranding/donkere snijrand) | [afgeleid] |

**Regel voor de tool:** `randZoneMm` per randtype uit de config; bij een ontwerp dat zelf al een rand heeft (bv. de ronde
eigenaarspatch met tekst tegen de cirkel) moet de tool tonen: "Je eigen buitenring wordt de rand. Tekst die tegen de rand
loopt, schuift ± 3 mm naar binnen." Bij merrow: tekst/detail binnen 3 mm van de rand → waarschuwing.

### 1.3 Welke rand kan bij welke vorm?
| Vorm | Merrow | Satijnrand + hot-cut | Lasercut | Opmerking |
|---|---|---|---|---|
| Rond, ovaal | ja | ja | ja | merrow = klassiek, sterkst |
| Rechthoek/vierkant met afgeronde hoeken | ja | ja | ja | |
| Rechthoek met scherpe hoeken | matig (hoek wordt afgerond/dikker) | ja | ja | [bron] merrow "hates inside corners and sharp points" |
| Schild / wapen met zachte bochten | ja, zolang er **geen holle (concave) hoeken** zijn | ja | ja | |
| Ster, contour van logo, holle hoeken, uitsparingen binnenin | **nee** | ja | ja | [bron] [Monterey merrow vs heat cut](https://www.montereycompany.com/blog/merrowed-edge-vs-heat-cut-edges-on-patches), [Bespoke Patches borders](https://www.bespokepatches.co.uk/blog/best-borders-for-your-custom-patches) |
| Zeer klein (< ± 30 mm) | afraden | ja | ja | woven merrow-min 1,5" = 38 mm [bron World Emblem]; borduur-merrow < 30 mm [afgeleid] |

Vuistregels voor detectie [afgeleid]:
- **Merrow toegestaan** als de vorm convex is (convexiteit = oppervlakte / oppervlakte convex omhulsel ≥ 0,97),
  kleinste hoekstraal ≥ 5 mm en kleinste zijde ≥ 30 mm. Anders: satijnrand + hot-cut/lasercut voorstellen.
- Scherpe uitstekende punten (< 60°) worden bij elke snijtechniek iets afgerond (silicone-leverancier rondt 60°-punten af tot
  Ø 1 mm [bron] [Lion Brothers silicone](https://lionbrothers.com/silicone-patches)); toon dat eerlijk in de proef.
- Op de site heet het vandaag: "Patch geknipt uit doek (lichte rafels mogelijk)" en "Patch op folie (verfijnde rand)";
  merrow en lasercut worden op infopagina's vermeld maar zijn **geen** productoptie (`02-huidig-aanbod.md` §4b) → [eigenaar].

---

## 2. Geborduurd (plat)

**Wat het is:** polyester garen (op de site: Polyneon, 411 kleuren, `01-garenkleuren-polyneon.json`) wordt met
satijnsteken (smalle kolommen, glanzend) en vulsteken (tatami, grotere vlakken) op twill (site: 180 g/m²) geborduurd.
Volledige dekking (100 % garen) of gedeeltelijk (twill zichtbaar als achtergrondkleur → minder steken, goedkoper).

| Regel | Hard (onder = afraden) | Comfort | Bron |
|---|---|---|---|
| Maat | 25 mm | 50–120 mm (online 40–120, tot 300 op aanvraag) | site: "5 tot 30 cm", shop 4–12 cm |
| Hoofdletters (cap-height) | **4 mm** | ≥ 6 mm (vet schreefloos) | [bron] site 4 mm; [Stahls/World Emblem 4 mm + 1 mm kolom](https://worldemblem.com/patches/embroidered); Printful-achtige gidsen 0,3" = 7,6 mm voor kleding |
| Kleine letters (x-hoogte, hoogte van "x") | 3 mm | ≥ 4 mm | [afgeleid] uit 0,25" (6,4 mm) totale hoogte kleine letters ([bystadium](https://help.bystadium.com/hc/en-us/articles/40706833307031-Embroidery-Guidelines)) |
| Schreef-/scriptletters | +50 % op bovenstaande | — | [bron] "script fonts or hairline typography are particularly problematic" |
| Lijn als satijnkolom | **1,0 mm** | ≥ 1,5 mm | [bron] World Emblem 1 mm kolom; site-voorbeeldtekst "geen lijnen dunner dan 1 mm" |
| Lijn als loopsteek (enkel/drievoudig) | 0,4 mm (zichtbaar als garenlijn, niet als vlak) | — | [afgeleid]: dunne contouren kan de digitaliseerder als loopsteek zetten; ze worden dan ± garendikte |
| Tussenruimte tussen elementen / binnenruimte letters (a, e, o) | 1,0 mm | ≥ 1,5 mm | [bron] "avoid design elements smaller than 1 mm" ([Ottocap](https://help.ottocap.com/artwork-requirements)) |
| Kleinste punt/vlak | 1,5 × 1,5 mm | 3 mm | [afgeleid] |
| Kleuren | max **12** (site) | ≤ 8 strakst en voordeligst | [bron] site "tot 12 kleuren" |
| Verloop / schaduw | niet echt: wordt **vereenvoudigd** tot 2–4 tinten of met mengsteken nagebootst | — | [bron] alle leveranciers; zie §9 |
| Foto | **nee** | — | |

**Wat niet kan:** haarlijnen < 0,4 mm, tekst < 4 mm, echte kleurverlopen, foto's, fijne rasters/texturen,
kleine witte tekst in een donker vlak (loopt dicht), heel veel kleine losse elementen (veel springsteken/knipdraden).
Tip die de site zelf geeft: "een borduurnaald heeft ruimte nodig, waardoor fijne details dichtlopen" (`01-merk-en-site.md` §3.2).

**Randen:** merrow (eenvoudige vormen), satijnrand + hot-cut/lasercut (contour), site: uit doek geknipt of op folie.
**Achterkant:** opnaaien (standaard), opstrijk-lijmlaag, klittenband (haak/lus), dubbele onderlaag (stijver), zelfklevend (tijdelijk),
magneet [afgeleid; niet in shop], speld/vlinder (enkel kleine stijve patches) [afgeleid].
**Wassen:** polyester garen tot 60 °C (site). Met opstrijklaag: binnenstebuiten, 30–40 °C, niet in de droogkast
([bespokepatches](https://www.bespokepatches.co.uk/blog/can-you-wash-custom-iron-on-patches/), [StickerYou](https://www.stickeryou.com/blog/post/apply-and-remove-patches)).
**Duurzaamheid:** zeer hoog; kleurvast; merrow-rand rafelt niet. Uit doek geknipt = lichte rafels mogelijk (site).
**Toepassingen:** clubs, scouts, jeugdbewegingen, werkkleding, brandweer/hulpdiensten, militaire/airsoft, merchandise, jassen.
**Productietijd:** site: "meestal binnen 2–3 weken", "gemiddeld 3–4 weken, spoed 1–2 weken" na goedkeuring proef
(`01-merk-en-site.md`, rij Levertijd). Markt: 2–4 weken ([Owl Badges](https://owlbadges.com/?p=347264), [Patch & Bagel](https://patchandbagel.com/blogs/custom-patches-guides-tips/custom-patch-lead-times-ordering-timeline-singapore-2026)).

### 2.1 3D-puff (borduren over schuim)
| Regel | Hard | Comfort | Bron |
|---|---|---|---|
| Letterhoogte | 11 mm (7/16") | ≥ 15 mm | [bron] [Imperial 1916 guidelines](https://custom.imperial1916.com/printing/3) |
| Kolombreedte | 5 mm | 6–12 mm (breder dan ± 12 mm wordt plat) | [bron] Imperial 3/16"–1/2"; [Magnetic Hoop 5–7 mm](https://www.magnetichoop.com/blogs/news/3d-puff-embroidery-designs-mastering-techniques-materials-and-troubleshooting) |
| Kleinste element | 2 mm | — | [bron] Ottocap "2 mm for 3D embroidery" |
| Kleuren in puff | 1–3 | 1 | [afgeleid] |
**Niet geschikt:** schreefletters, kleine binnenruimtes, dunne lijnen, grote vlakken, ronde kleine details.
Combineer: puff voor de hoofdnaam, plat borduurwerk voor de rest. Patch wordt dikker en stugger. Puff-patches bij de site: [eigenaar].

### 2.2 Metallic garen (goud/zilver)
- Letters ≥ 5–6 mm; vermijd grote dichte vulvlakken (metallic is stugger, breekt sneller, lager toerental)
  [bron] [Madeira: embroidering with metallic thread](https://www.madeirausa.com/resources/embroidering-with-metallic-thread/default.aspx).
- In geweven patches telt metallic bij sommige leveranciers als **4 kleuren** [bron] World Emblem woven.
- Advies: metallic voor randen, tekst en accenten, niet als achtergrond. Meerprijs: [eigenaar].

### 2.3 Speciale garens: glow-in-the-dark en reflecterend
- **Glow:** moet eerst "opgeladen" worden met licht; gloed dooft na verloop van tijd en is zwak in volledig donker; lastig te verwerken
  (trager, grotere naald) [bron] [Embroidery Hoop Store](https://embroideryhoopstore.com/blogs/articles/glow-reflective-embroidery-threads), [Superior Threads](https://www.superiorthreads.com/glow-in-the-dark).
  Eerlijk advies: "leuk effect, geen veiligheidsfunctie".
- **Reflecterend garen:** licht niet zelf op, kaatst enkel licht terug; grover garen → letters ≥ 6 mm [bron] idem; [Madeira visibility PDF](https://www.madeirausa.com/_resources/common/userfiles/file/Mastering%20Embroidery%20Techniques%20for%20Increased%20Visibility,%20Safety%20&%20Special%20Effects.pdf).
- **Reflecterende patch voor zichtbaarheid/veiligheid:** gebruik reflecterende **folie/stof** (heat transfer of genaaide strook), geen garen.
  Geen certificering (EN ISO 20471) beloven tenzij de eigenaar gecertificeerd materiaal gebruikt [afgeleid].

---

## 3. Geweven (woven)

**Wat het is:** het ontwerp wordt in de stof **geweven** met heel dun garen (damast-weefgetouw). Plat, dun, soepel, zeer fijn detail.

| Regel | Hard | Comfort | Bron |
|---|---|---|---|
| Maat | 25 × 13 mm randloos; 38 × 38 mm met merrow | 30–100 mm; max ± 190 mm (7,48") bij sommige leveranciers | [bron] World Emblem woven; Stahls/Transfer Express woven |
| Hoofdletters | **2 mm** | ≥ 3 mm | [bron] "Text Minimum: 2mm H" (World Emblem, Stahls) |
| Kleine letters (x-hoogte) | 1,5 mm | ≥ 2 mm | [afgeleid] |
| Lijn / element | 0,2 mm (spec) — praktisch 0,3–0,5 mm | ≥ 0,5 mm | [bron] "Element Minimum: 0.2mm"; [afgeleid] praktijk |
| Kleuren | min 2, max **6–12** (leverancier-afhankelijk; vaak 8) | ≤ 8 | [bron] World Emblem 12, Stahls/TXP 6, Fairware 8 |
| Verloop | gesuggereerd met garenmenging (beperkt) | — | [bron] "gradient-like blends" |
| Foto | nee | — | |
**Randen:** merrow, hot-cut/lasercut randloos (3 mm marge). **Achterkant:** opnaai, opstrijk, klittenband, zelfklevend.
**Wassen:** zeer goed, 40–60 °C (dun polyester). **Duurzaamheid:** hoog; dunner en soepeler dan borduurwerk, minder reliëf.
**Toepassingen:** kleine logo's, kledinglabels, gedetailleerde club-/militaire emblemen, kleine tekst, schouderbadges.
**Minimum op de site:** "vanaf 100 stuks" (offerte) (`02-huidig-aanbod.md` §2).
**Productietijd:** 3–5 weken (markt) [bron] Owl Badges.

---

## 4. Bedrukt / gesublimeerd (met geborduurde rand)

**Wat het is:** het ontwerp wordt digitaal geprint (sublimatie op wit polyester twill of flock; of "geprint" op stof) en de rand
wordt geborduurd/ge-merrowd. Op de site: "Geprinte patch" en "Gesublimeerde patch" (drager standaard wit polyester of flock).

| Regel | Hard | Comfort | Bron |
|---|---|---|---|
| Maat | 25 mm | 40–300 mm (grote rugemblemen: site raadt dit aan) | [bron] 1"–12" (World Emblem sublimated, Transfer Kingdom) |
| Hoofdletters | **1,6 mm** | ≥ 2,5 mm | [bron] "sublimated text 1.6 mm height" (World Emblem, Transfer Express print-stitch) |
| Kleine letters (x-hoogte) | 1,2 mm | ≥ 2 mm | [afgeleid] |
| Lijn | 0,25 mm | ≥ 0,4 mm | [afgeleid] (stofstructuur twill maakt haarlijnen onscherp) |
| Kleuren | onbeperkt | — | [bron] |
| Verloop / schaduw / foto | **ja** | — | [bron] "photorealistic images" |
| Witte inkt | sublimatie heeft **geen wit**: wit = kleur van de drager. Donkere drager kan niet bij sublimatie. | | [afgeleid; algemeen sublimatieprincipe] |
**Randen:** geborduurde rand of merrow (merrow-rand "vereist backing" bij sommige leveranciers) [bron]. Op de site altijd een geborduurde rand.
**Achterkant:** opnaai, opstrijk, klittenband, zelfklevend. **Wassen:** sublimatie is in de vezel → kleurvast; 40 °C, binnenstebuiten [afgeleid].
**Duurzaamheid:** goed; kan na jaren UV-bleken; geen reliëf (vlak, "minder ambachtelijk" gevoel). Flock = fluweelzacht, iets dikker.
**Toepassingen:** foto's, illustraties met schaduwen, verlopen, veel kleuren, grote rugpatches, evenementen, kleine reeksen goedkoop.
**Productietijd:** kort; vaak sneller dan borduren [afgeleid] → instelbaar [eigenaar].
**Opmerking (site):** bij sublimatie kunnen kleuren licht afwijken van scherm; borduurrandkleur kiest de klant uit Polyneon.

---

## 5. Chenille

**Wat het is:** lussen van dik garen ("handdoekstof") op vilt, met vilt- en geborduurde rand. Varsity/college-look, groot en zacht.

| Regel | Hard | Comfort | Bron |
|---|---|---|---|
| Maat | 50 mm (leveranciers: 25/50/75 mm) | 75–300 mm; max ± 300 × 400 mm | [bron] [Transfer Express chenille](https://www.transferexpress.com/heat-press-transfers/chenille-patches) 1"×1" tot 12"×16"; Stahls 2"; Gift A Feeling 3" |
| Letterhoogte (chenille-deel) | **30 mm** | ≥ 40 mm | [bron] TXP "smallest character at least 30mm" |
| Lijn/stam (chenille) | **3 mm** | ≥ 5 mm | [bron] TXP 3 mm stem |
| Element | 3 × 3 mm; uitsparingen binnenin ≥ 10 mm | — | [bron] World Emblem chenille |
| Fijn detail | wordt als **plat borduurwerk** gezet: tekst ≥ 6 mm, kolom 1 mm | — | [bron] TXP "direct embroidery at least 6mm tall with 1mm column" |
| Kleuren chenille | max **6** (soms 9) | 2–4 | [bron] |
| Verloop / foto | nee | — | |
**Rand:** viltrand 4 mm ± 1,5 mm + binnenomlijning geborduurd 2 mm. Vorm = contour (gesneden vilt). Geen merrow.
**Achterkant:** opnaai (aangeraden), opstrijk, klittenband. **Wassen:** koud/30 °C, zacht programma, niet in de droogkast; kan pluizen [afgeleid].
**Duurzaamheid:** matig-goed; lussen kunnen haken. **Dikte:** ± 3 mm [bron].
**Toepassingen:** letters op varsity-jassen, studenten-/sportclubs, streetwear, grote initialen.
**Site:** niet als product, enkel vermeld (`02-huidig-aanbod.md` §7) → offerte-modus.

---

## 6. PVC / rubber (2D en 3D)

**Wat het is:** vloeibare PVC wordt kleur per kleur in een **mal** gegoten. 2D = vlakke niveaus; 3D = gemodelleerd reliëf (afgeronde vormen).
Waterdicht, zeer duurzaam. Mal = eenmalige kost (site: € 80 excl. in de kassa / € 150 op de productpagina → [eigenaar]), min. 50 stuks.

| Regel | Hard | Comfort | Bron |
|---|---|---|---|
| Maat | 13 mm (0,5") | 40–100 mm; groter op aanvraag | [bron] [Stahls PVC spec](https://assets.stahls.com/stahls/content/pdf/flyers/Stahls-PVC-Patches-Specifications.pdf) 0,5"–4" |
| Lijn / element / tekst-stam | **0,6 mm** | ≥ 1,0 mm | [bron] Stahls 0,6 mm; NY Custom Labels "smaller than 1mm may not reproduce" |
| Hoofdletters | 3 mm | ≥ 4 mm | [afgeleid] (stam 0,6 mm ⇒ ± 3 mm letter) |
| Tussenruimte tussen kleuren | 0,6 mm | ≥ 1 mm (elke kleur = eigen malkamer met scheidingswand) | [afgeleid] |
| Kleuren | max **4–8** per mal (leverancier) | ≤ 6 | [bron] TXP 4; anderen ± 8 |
| Verloop / foto | **nee**, enkel effen PMS-kleuren | — | [bron] "only solid colors ... without any gradients" |
| Dikte | 2D ± 2–2,5 mm; 3D ± 3,5 mm (tot 4+ mm) | — | [bron] [Monterey 3D vs 2D](https://www.montereycompany.com/blog/3d-vs-2d-pvc-patches/) |
**2D vs 3D:** 2D = strakke niveaus, goedkoper mal; 3D = gezichten/figuren met volume, duurdere mal [bron] Monterey.
**Rand:** vorm volgt de mal (elke contour kan, ook uitsparingen); opstaande rand meestal 1 kleur.
**Achterkant:** klittenband (haak/lus) **ingenaaid of ingegoten** (zeer populair: airsoft/militair), opnaai (naairand), zelfklevend,
pin/vlinder (kleine), magneet (op aanvraag). **Niet opstrijken** (PVC smelt/vervormt) [afgeleid; algemeen].
**Wassen:** waterdicht; handwas/koud, niet strijken, niet in droogkast [afgeleid]. **Duurzaamheid:** zeer hoog, buitenbestendig, kleurvast.
**Toepassingen:** airsoft/tactisch, outdoor, rugzakken, petten, hondenhalsbanden, merchandise, sleutelhangers (zelfde techniek).
**Productietijd:** site "rond de 4 weken"; markt 4–8 weken (mal) [bron] Owl Badges.

---

## 7. Leder (echt of kunstleder)

**Twee technieken:**
- **Lasergraveren**: laser brandt het ontwerp in (donker, tone-on-tone). Fijn detail mogelijk, geen stempel nodig → ook kleine aantallen.
- **Debossed (ingedrukt)**: verwarmde stempel/matrijs drukt het ontwerp in. Luxe tastbaar effect, maar stempelkost en minder detail.

| Regel | Laser | Debossed | Bron |
|---|---|---|---|
| Maat | min 13 mm (kunstleder), comfort 40–100 mm | min 38 × 38 mm, max 150 × 150 mm | [bron] World Emblem faux/debossed |
| Kleinste element/lijn | **0,3 mm** (comfort 0,5 mm) | 1,0 mm hard, comfort 1,5 mm [afgeleid] | [bron] World Emblem 0,3 mm (faux leather) |
| Kleinste teken | 1 mm (spec), comfort ≥ 2,5 mm | 4 mm [afgeleid] | [bron] World Emblem 1 mm |
| Kleuren | **1** (brandtint; grijswaarden als dieptegraad mogelijk) | 1 (ton-sur-ton) | [bron] [Monterey debossed vs laser](https://www.montereycompany.com/blog/debossed-leather-vs-laser-engraved-leather-patches/) |
| Foto | laser kan "fotografisch" detail als grijswaarde-gravure; resultaat = bruintinten | nee | [bron] embpunch / Monterey |
**Rand:** lasercut contour (donkere geschroeide snijrand), of rechthoek/rond, eventueel met naaigaatjes. **Achterkant:** opnaaien (standaard; petten,
jeans, tassen), klittenband, zelfklevend/hittelijm op aanvraag. Echt leder **niet opstrijken met een strijkijzer** [afgeleid].
**Wassen:** echt leder: niet in de wasmachine; kunstleder: koud, voorzichtig [afgeleid]. **Duurzaamheid:** hoog, krijgt patina.
**Toepassingen:** petten (trucker/snapback), jeans-labels, tassen, werkschorten, premium merchandise.
**Site:** komt niet voor → offerte-modus [eigenaar].

---

## 8. TPU / silicone (heat transfer "rubber look")

Zacht, flexibel, licht reliëf; wordt meestal met een hittepers aangebracht (geen genaaide patch).
| Regel | Waarde | Bron |
|---|---|---|
| Kleinste lijn/element | 0,8 mm (comfort ≥ 1 mm) | [bron] [World Emblem plotter-cut](https://www.worldemblem.com/transfers/plottercuttransfers/), Lion Brothers 0,8 mm |
| Tussenruimte | 0,7 mm | [bron] Lion Brothers |
| Kleuren | max 6, effen, PMS-match | [bron] Lion Brothers, Ottocap |
| Verloop | nee | [bron] |
| Maat | 13 mm – 305 × 229 mm | [bron] |
**Toepassing:** sportkleding, technische stoffen, logo's met clean "rubber"-look. Site: niet in aanbod → enkel vermelden of weglaten [eigenaar].

---

## 9. Illustraties met schaduwen (de "eigenaarspatch"-situatie)

Feedback van de eigenaar (`BRIEF.md`): een gedetailleerde illustratie met schaduwen is **geen foto**. Eerlijk vakadvies per techniek:

| Techniek | Wat gebeurt er met je illustratie? | Adviestekst (Vlaams, "je") |
|---|---|---|
| Geweven | meeste detail, schaduw als 1–2 extra tinten | "Geweven toont het meeste detail van je tekening: lijnen tot ± 0,3 mm en tekst vanaf 2 mm. De schaduwen zetten we om naar een paar tinten." |
| Geborduurd | digitaliseerder vereenvoudigt tot **N garenkleuren** (N = aantal dominante kleurclusters, max 12); kleine details vallen weg of worden vergroot | "Borduren kan, mits vereenvoudiging tot ongeveer {N} garenkleuren. Schaduwen worden 1 of 2 donkere tinten. Je krijgt eerst een digitale proef, zodat je ziet wat er overblijft." |
| Bedrukt/gesublimeerd | exact zoals je bestand | "Wil je élk detail en elke schaduw exact zoals op je scherm? Dan is een bedrukte patch met geborduurde rand de eerlijkste keuze." |
| PVC | effen kleurvlakken, geen schaduwen; 3D-reliëf kan schaduw "vervangen" | "In PVC worden schaduwen effen vlakken of echt reliëf (3D)." |
| Chenille | ongeschikt voor detail | "Chenille is voor grote, eenvoudige vormen; je illustratie verliest hier te veel." |

**Advieslogica [afgeleid]:**
1. Bepaal `kleurClusters` (k-means in Lab, ΔE-drempel ± 10) en `fijnsteDetailMm`, `kleinsteTekstMm` op ware grootte.
2. Geborduurd = "ok" als clusters ≤ 8, detail ≥ 1 mm, tekst ≥ 4 mm; "kan met vereenvoudiging" als clusters ≤ 12 of verloop aanwezig;
   "afraden" bij tekst < 4 mm die niet weg mag, of echte foto.
3. Stel **eerst de maat** voor die het probleem oplost ("Maak de patch 8 cm i.p.v. 6 cm, dan wordt je tekst 4,2 mm"): dat is het meest
   overtuigende advies (vergelijk `01-merk-en-site.md` voorbeeldzin). Formule: `nodigeMaat = huidigeMaat × (minLetterMm / gemetenLetterMm)`, afronden naar boven op de shopmaten (4/6/8/10/12).
4. Pas daarna een andere techniek voorstellen (geweven bij fijne tekst; gesublimeerd bij verloop/foto).

---

## 10. Steekaantal schatten (geborduurd) en invloed op prijs

### 10.1 Vuistregels uit de bronnen
| Steektype | Waarde (bron) | Omgerekend |
|---|---|---|
| Vulsteek (fill), volledig dicht | ± 2.000 steken per in² [bron] [Apliiq](https://help.apliiq.com/portal/en/kb/articles/estimated-stitch-counts-for-embroidery), [chholderby](https://www.chholderby.com/tips-tricks-and-help/estimating-stitches/) | **± 310 st/cm²** |
| Vulsteek, normale dichtheid, langere steek | 1.000 (6 mm steek) – 1.500 (4 mm steek) per in² [bron] stitch-matrix ([annthegran PDF](https://community.annthegran.com/FILES%2f2016%2f11%2fCommonEmbStitchMatrix-2-11-10.pdf.axdx)) | 155–230 st/cm² |
| Echt logo (open ruimte, tekst, satijn) | 1.000–1.500 per in² [bron] | 155–230 st/cm² van de **ontwerp-bounding-box** |
| Satijnkolom | 100 (1 mm breed) – 175 (breed) steken per **lopende inch** [bron] matrix; ± 150/inch [bron] | **40–70 st/cm lengte** |
| Lijn onder logo / schaduwlijn | + 200 per inch [bron] | + 80 st/cm |
| Wilcom: dichtheid satijn standaard 0,40 mm tussenafstand | [bron] [Wilcom satin stitch count](https://docs.wilcom.com/embroiderystudio/27/en/OnlineHelp/Digitizing/stitches/stitches-11.htm) | |

Kalibratie met de eigen site (kleding, `01-merk-en-site.md` §5): 6.339 steken op 69 × 78 mm (53,8 cm² bbox) = 118 st/cm²;
19.929 steken op 96 × 77 mm (73,9 cm²) = 270 st/cm². Dus: open logo ≈ 120, vol logo ≈ 270 — consistent met de bronnen.

### 10.2 Formule voor de plugin [afgeleid]
```
A_fill   = oppervlakte (cm²) van vlakken breder dan 6 mm      (pixels → cm² via mmPerPx)
A_satin  = oppervlakte (cm²) van kolommen 1–6 mm breed        (uit distance transform: halve breedte 0,5–3 mm)
L_run    = lengte (cm) van lijnen < 1 mm                       (skelet van dunne lijnen)
P_rand   = omtrek (cm) van de patch als de rand een geborduurde satijnrand is (niet bij merrow: aparte machine)

steken  = A_fill × 230            // tatami met onderlaag, gemiddelde
        + A_satin × 300           // satijn: ± 60 st/cm lengte per 2 mm breedte ≈ 300 st/cm²
        + L_run × 12              // drievoudige loopsteek, 2,5 mm steek ≈ 4 st/cm × 3
        + P_rand × 60             // satijnrand 3 mm
        + kleuren × 40 + objecten × 15   // vastzet-/afhechtsteken
volledige dekking (twill niet zichtbaar): A_fill = patchoppervlak − A_satin; gebruik 250–310 st/cm².
Toon het als bereik: [0,8 × steken, 1,2 × steken], afgerond op 500.
```
Controle: ronde patch Ø 80 mm volledig gevuld = 50,3 cm² × 250 ≈ 12.500 + rand (25 cm × 60 = 1.500) ≈ **14.000 steken**;
markt: patches van 3" volledig geborduurd liggen typisch rond 10.000–15.000 steken [afgeleid uit 2.000/in² × 7 in²]. Klopt.

### 10.3 Tijd en prijs
- Machinesnelheid patches ± 600–900 steken/min per kop (multi-kop draait meerdere patches tegelijk) [afgeleid; eigenaar: eigen IMTA-machines].
  `minuten = steken / 750 + kleurwissels × 0,1 + vaste opspan-/knipminuten`.
- **Prijsinvloed:** op de site hangt de patchprijs vandaag **enkel af van maat** (4–12 cm), niet van steken (`02-huidig-aanbod.md` §2).
  Gebruik het steekaantal dus **niet om de prijs te veranderen** (de kassa rekent het niet), maar wel als:
  1) eerlijke uitleg ("± 12.000 steken — volledig geborduurd"), 2) signaal voor offerte bij extreme dekking (bv. > 400 st/cm² of
  > 25.000 steken bij ≤ 12 cm) [afgeleid], 3) eventuele toekomstige toeslag (configureerbaar, standaard uit).
- Kalibratiegegeven van de site (kleding): (13,01 − 6,83) € / (19.929 − 6.339) steken ≈ **€ 0,45 per 1.000 extra steken** (incl. btw) [afgeleid].
- Andere prijsdrijvers: aantal kleuren (garenwissels), randtype (merrow/folie), achterkant, oplage (staffel), eenmalige borduurkaart.
- Minder steken = gedeeltelijke dekking (twill als achtergrondkleur): goedkoper, soepeler, sneller. Goede eerlijke tip
  ([ASI: reduce stitch count](https://members.asicentral.com/news/how-to/october-2019/how-to-reduce-stitch-count-without-jeopardizing-quality/)).

---

## 11. Achterkanten: wat past waar

| Achterkant | Geborduurd | Geweven | Bedrukt/sublim. | Chenille | PVC | Leder | Opmerking |
|---|---|---|---|---|---|---|---|
| Opnaaien | ja | ja | ja | ja (aanbevolen) | ja (naairand) | ja (standaard) | sterkst, altijd mogelijk |
| Opstrijk-lijmlaag | ja | ja | ja | ja | **nee** | **nee** | site: op katoen/polyester-katoen; **niet** op leer, nylon, gecoat textiel, fleece → opnaaien/klittenband |
| Klittenband haak/lus | ja | ja | ja | ja | ja (vaak ingegoten) | ja | tactisch/werkkleding, wisselbaar |
| Zelfklevend | ja | ja | ja | beperkt | ja | ja | **tijdelijk**, niet wasbaar [afgeleid] |
| Magneet | ja | ja | ja | nee | ja | ja | niet wassen; niet bij pacemaker (veiligheidsmelding) [afgeleid] |
| Pin / vlinderspeld | kleine stijve | nee | nee | nee | ja | ja | |
Opstrijkinstructie (voor de bevestigingsmail/infobox): strijkijzer katoenstand zonder stoom, bakpapier/teflon ertussen,
30–45 s stevig drukken zonder schuiven, binnenkant nog 15–20 s; hittepers ± 150–165 °C, 15–20 s; laten afkoelen; 24 u wachten met
wassen [bron] [Patch Corner](https://patchcorner.com/info/iron-on-instructions), [thecheapplace](https://www.thecheapplace.com/iron-on-instructions-patches); 24 u = [afgeleid].
Eerlijke tip: "Opstrijken is handig, opnaaien houdt het langst. Voor werkkleding die vaak heet gewassen wordt raden we opnaaien aan."

---

## 12. Levertijden (indicatief, instelbaar)

| Techniek | Site | Markt | Standaardtekst tool |
|---|---|---|---|
| Geborduurd | 2–3 w, gem. 3–4 w, spoed 1–2 w | 2–4 w | "± 2–3 weken na goedkeuring van je proef" [eigenaar] |
| Bedrukt/gesublimeerd | niet vermeld | 1–3 w | leeg → "We bevestigen de levertijd met je digitale proef" |
| Geweven | offerte | 3–5 w | offerte |
| PVC | ± 4 weken | 4–8 w | "± 4 weken na goedkeuring" |
| Chenille, leder, TPU | n.v.t. | 3–6 w | offerte |
Bronnen markt: [Owl Badges](https://owlbadges.com/?p=347264), [Patch & Bagel lead times](https://patchandbagel.com/blogs/custom-patches-guides-tips/custom-patch-lead-times-ordering-timeline-singapore-2026). Toon nooit een harde datum zonder bevestiging (BRIEF: eerlijkheid).

---

## 13. Machine-leesbare regels (`patchTechRules`)

Eenheden: mm, °C, werkdagen. `hard` = onder deze waarde "afraden/kan niet"; `comfort` = vanaf hier "strak".
`null` = niet van toepassing. `confidence`: `site` (eigen site), `sourced` (meerdere bronnen), `derived` (afgeleid), `owner` (eigenaar moet bevestigen).
Technique-id's sluiten aan op `02-huidig-aanbod.md` §10a (`embroidered`, `printed`, `sublimated`, `pvc`, `woven`, `chenille`, `leather`).

```json
{
  "schema": "patchTechRules/1",
  "updated": "2026-11-16",
  "units": { "length": "mm", "temperature": "C", "leadTime": "workdays" },
  "edges": {
    "merrow": {
      "label": { "nl": "Kettelrand (merrow)", "fr": "Bordure merrow (surjet)" },
      "widthMm": 3, "safeZoneMm": 3, "safeZoneOverlapMm": 5,
      "allowedShapes": ["circle", "oval", "rect_rounded", "rect", "shield_convex"],
      "requiresConvex": true, "minConvexity": 0.97, "minCornerRadiusMm": 5, "minSizeMm": 30,
      "techniques": ["embroidered", "woven", "printed", "sublimated"],
      "confidence": "sourced"
    },
    "satin_hotcut": {
      "label": { "nl": "Geborduurde rand, op vorm gesneden", "fr": "Bordure brodée, découpe à chaud" },
      "widthMm": 2.5, "safeZoneMm": 1, "allowedShapes": ["any"], "allowsInnerCutouts": true, "minSizeMm": 20,
      "techniques": ["embroidered", "printed", "sublimated"], "confidence": "sourced"
    },
    "lasercut_borderless": {
      "label": { "nl": "Lasergesneden, zonder rand", "fr": "Découpe laser, sans bordure" },
      "widthMm": 0, "safeZoneMm": 3, "allowedShapes": ["any"], "allowsInnerCutouts": true, "minSizeMm": 13,
      "techniques": ["woven", "embroidered", "leather"], "confidence": "sourced"
    },
    "site_cut_from_fabric": {
      "label": { "nl": "Geknipt uit doek (lichte rafels mogelijk)", "fr": "Découpé dans le tissu (léger effilochage possible)" },
      "widthMm": 0, "safeZoneMm": 1.5, "allowedShapes": ["any"], "techniques": ["embroidered"], "confidence": "site"
    },
    "site_on_film": {
      "label": { "nl": "Op folie (verfijnde rand)", "fr": "Sur film (bord affiné)" },
      "widthMm": 0, "safeZoneMm": 1.5, "allowedShapes": ["any"], "techniques": ["embroidered"], "confidence": "site"
    },
    "felt_border": {
      "label": { "nl": "Viltrand", "fr": "Bordure en feutrine" },
      "widthMm": 4, "innerOutlineMm": 2, "safeZoneMm": 6, "allowedShapes": ["any"], "allowsInnerCutouts": true,
      "minInnerCutoutMm": 10, "techniques": ["chenille"], "confidence": "sourced"
    },
    "molded": {
      "label": { "nl": "Gegoten rand", "fr": "Bord moulé" },
      "widthMm": 1.5, "safeZoneMm": 1, "allowedShapes": ["any"], "allowsInnerCutouts": true,
      "techniques": ["pvc"], "confidence": "derived"
    }
  },
  "backings": {
    "sew_on":      { "label": { "nl": "Opnaaien", "fr": "À coudre" }, "washable": true, "permanent": true },
    "iron_on":     { "label": { "nl": "Opstrijkbare lijmlaag", "fr": "Thermocollant" }, "washable": true, "permanent": true,
                     "notOnFabrics": ["leer", "nylon", "gecoat textiel", "fleece", "regenjas"], "washMaxC": 40, "tumbleDry": false },
    "velcro":      { "label": { "nl": "Klittenband (haak/lus)", "fr": "Velcro (crochet/boucle)" }, "washable": true, "permanent": false },
    "adhesive":    { "label": { "nl": "Zelfklevend (tijdelijk)", "fr": "Autocollant (temporaire)" }, "washable": false, "permanent": false },
    "magnet":      { "label": { "nl": "Magneet", "fr": "Aimant" }, "washable": false, "permanent": false,
                     "warning": { "nl": "Niet geschikt voor dragers van een pacemaker.", "fr": "Déconseillé aux porteurs de stimulateur cardiaque." } },
    "pin":         { "label": { "nl": "Speld (vlindersluiting)", "fr": "Pin (fermoir papillon)" }, "washable": false, "permanent": false }
  },
  "techniques": {
    "embroidered": {
      "label": { "nl": "Geborduurd", "fr": "Brodé" },
      "sizeMm": { "hard": 25, "comfortMin": 50, "comfortMax": 120, "max": 300 },
      "textCapsMm": { "hard": 4, "comfort": 6 },
      "textLowerXHeightMm": { "hard": 3, "comfort": 4 },
      "serifScriptFactor": 1.5,
      "lineMm": { "hard": 1.0, "comfort": 1.5, "runStitchHard": 0.4 },
      "gapMm": { "hard": 1.0, "comfort": 1.5 },
      "smallestElementMm": { "hard": 1.5, "comfort": 3 },
      "colors": { "max": 12, "comfortMax": 8, "gradients": "simplify", "photo": false, "shadedIllustration": "simplify" },
      "edges": ["merrow", "satin_hotcut", "lasercut_borderless", "site_cut_from_fabric", "site_on_film"],
      "backings": ["sew_on", "iron_on", "velcro", "adhesive", "magnet", "pin"],
      "washMaxC": 60, "durability": "very_high", "thicknessMm": [1.5, 3],
      "leadTimeWorkdays": { "min": 10, "max": 20, "rushMin": 5, "confidence": "owner" },
      "cannot": ["foto", "haarlijnen < 0,4 mm", "tekst < 4 mm", "echte kleurverlopen", "fijne rasters"],
      "applications": ["club", "scouts", "werkkleding", "hulpdiensten", "militair/airsoft", "merch", "jassen"],
      "confidence": "site+sourced",
      "variants": {
        "puff3d": { "label": { "nl": "3D-puff borduurwerk", "fr": "Broderie 3D puff" },
          "textCapsMm": { "hard": 11, "comfort": 15 }, "columnMm": { "hard": 5, "comfort": 6, "max": 12 },
          "smallestElementMm": 2, "colors": { "max": 3 }, "serifAllowed": false, "confidence": "sourced", "offered": "owner" },
        "metallic": { "label": { "nl": "Metallic garen", "fr": "Fil métallisé" },
          "textCapsMm": { "hard": 5, "comfort": 6 }, "avoidLargeFills": true, "confidence": "sourced", "offered": "owner" },
        "glow": { "label": { "nl": "Glow-in-the-dark garen", "fr": "Fil phosphorescent" },
          "textCapsMm": { "hard": 6, "comfort": 8 }, "note": { "nl": "Moet opgeladen worden met licht; gloed dooft na verloop van tijd.", "fr": "Doit être chargé à la lumière ; la lueur diminue avec le temps." },
          "confidence": "sourced", "offered": "owner" },
        "reflective_thread": { "label": { "nl": "Reflecterend garen", "fr": "Fil réfléchissant" },
          "textCapsMm": { "hard": 6, "comfort": 8 }, "note": { "nl": "Licht niet zelf op; kaatst licht terug. Geen veiligheidscertificaat.", "fr": "N'émet pas de lumière ; réfléchit la lumière. Pas de certification de sécurité." },
          "confidence": "sourced", "offered": "owner" }
      }
    },
    "woven": {
      "label": { "nl": "Geweven", "fr": "Tissé" },
      "sizeMm": { "hard": 25, "hardWithMerrow": 38, "comfortMin": 30, "comfortMax": 100, "max": 190 },
      "textCapsMm": { "hard": 2, "comfort": 3 },
      "textLowerXHeightMm": { "hard": 1.5, "comfort": 2 },
      "serifScriptFactor": 1.25,
      "lineMm": { "hard": 0.3, "comfort": 0.5, "specMin": 0.2 },
      "gapMm": { "hard": 0.3, "comfort": 0.5 },
      "smallestElementMm": { "hard": 0.5, "comfort": 1 },
      "colors": { "min": 2, "max": 12, "comfortMax": 8, "metallicCountsAs": 4, "gradients": "limited", "photo": false, "shadedIllustration": "good" },
      "edges": ["merrow", "lasercut_borderless"],
      "backings": ["sew_on", "iron_on", "velcro", "adhesive"],
      "washMaxC": 60, "durability": "high", "thicknessMm": [0.5, 1],
      "minQty": 100, "mode": "quote",
      "leadTimeWorkdays": { "min": 15, "max": 25, "confidence": "sourced" },
      "cannot": ["foto", "echte kleurverlopen", "reliëf"],
      "applications": ["kleine logo's", "fijne tekst", "labels", "gedetailleerde emblemen"],
      "confidence": "sourced"
    },
    "printed": {
      "label": { "nl": "Bedrukt met geborduurde rand", "fr": "Imprimé avec bordure brodée" },
      "sizeMm": { "hard": 25, "comfortMin": 40, "comfortMax": 300, "max": 300 },
      "textCapsMm": { "hard": 1.6, "comfort": 2.5 },
      "textLowerXHeightMm": { "hard": 1.2, "comfort": 2 },
      "serifScriptFactor": 1.0,
      "lineMm": { "hard": 0.25, "comfort": 0.4 },
      "gapMm": { "hard": 0.25, "comfort": 0.4 },
      "smallestElementMm": { "hard": 0.3, "comfort": 0.5 },
      "colors": { "max": null, "gradients": true, "photo": true, "shadedIllustration": "exact" },
      "edges": ["merrow", "satin_hotcut"],
      "backings": ["sew_on", "iron_on", "velcro", "adhesive", "magnet"],
      "washMaxC": 40, "durability": "good", "thicknessMm": [1, 2],
      "leadTimeWorkdays": { "min": null, "max": null, "confidence": "owner" },
      "cannot": ["reliëf/garen-look in het beeld"],
      "applications": ["foto's", "illustraties met schaduwen", "verlopen", "grote rugemblemen", "events"],
      "confidence": "sourced"
    },
    "sublimated": {
      "label": { "nl": "Gesublimeerd", "fr": "Sublimé" },
      "sizeMm": { "hard": 25, "comfortMin": 40, "comfortMax": 300, "max": 300 },
      "textCapsMm": { "hard": 1.6, "comfort": 2.5 },
      "textLowerXHeightMm": { "hard": 1.2, "comfort": 2 },
      "serifScriptFactor": 1.0,
      "lineMm": { "hard": 0.25, "comfort": 0.4 },
      "gapMm": { "hard": 0.25, "comfort": 0.4 },
      "smallestElementMm": { "hard": 0.3, "comfort": 0.5 },
      "colors": { "max": null, "gradients": true, "photo": true, "shadedIllustration": "exact", "whiteInk": false,
                  "note": { "nl": "Wit in je ontwerp = de witte drager. Een donkere drager kan niet.", "fr": "Le blanc de votre design = le support blanc. Un support foncé n'est pas possible." } },
      "carriers": ["standard_white_polyester", "flock"],
      "edges": ["merrow", "satin_hotcut"],
      "backings": ["sew_on", "iron_on", "velcro", "adhesive", "magnet"],
      "washMaxC": 40, "durability": "good", "thicknessMm": [1, 2],
      "leadTimeWorkdays": { "min": null, "max": null, "confidence": "owner" },
      "cannot": ["donkere drager", "reliëf"],
      "applications": ["foto's", "illustraties met schaduwen", "veel kleuren", "kleine reeksen"],
      "confidence": "sourced"
    },
    "chenille": {
      "label": { "nl": "Chenille", "fr": "Chenille" },
      "sizeMm": { "hard": 50, "comfortMin": 75, "comfortMax": 300, "max": 400 },
      "textCapsMm": { "hard": 30, "comfort": 40, "embroideredDetailHard": 6 },
      "textLowerXHeightMm": { "hard": 25, "comfort": 30 },
      "lineMm": { "hard": 3, "comfort": 5, "embroideredDetailHard": 1 },
      "smallestElementMm": { "hard": 3, "comfort": 6 },
      "innerCutoutMm": 10,
      "colors": { "max": 6, "comfortMax": 4, "gradients": false, "photo": false, "shadedIllustration": "no" },
      "edges": ["felt_border"],
      "backings": ["sew_on", "iron_on", "velcro"],
      "washMaxC": 30, "tumbleDry": false, "durability": "medium", "thicknessMm": [3, 4],
      "mode": "quote",
      "leadTimeWorkdays": { "min": 15, "max": 30, "confidence": "derived" },
      "cannot": ["fijn detail", "kleine tekst", "foto", "verloop"],
      "applications": ["varsity-jassen", "sportclubs", "studentenclubs", "streetwear", "grote initialen"],
      "confidence": "sourced"
    },
    "pvc": {
      "label": { "nl": "PVC / rubber", "fr": "PVC / caoutchouc" },
      "sizeMm": { "hard": 13, "comfortMin": 40, "comfortMax": 100, "max": 150 },
      "textCapsMm": { "hard": 3, "comfort": 4 },
      "textLowerXHeightMm": { "hard": 2.5, "comfort": 3 },
      "lineMm": { "hard": 0.6, "comfort": 1.0 },
      "gapMm": { "hard": 0.6, "comfort": 1.0 },
      "smallestElementMm": { "hard": 0.6, "comfort": 1.0 },
      "colors": { "max": 8, "comfortMax": 6, "gradients": false, "photo": false, "shadedIllustration": "flatten_or_3d", "pms": true },
      "variants": { "2d": { "thicknessMm": [2, 2.5] }, "3d": { "thicknessMm": [3, 4.5], "note": { "nl": "Echt reliëf, duurdere mal.", "fr": "Vrai relief, moule plus cher." } } },
      "edges": ["molded"],
      "backings": ["sew_on", "velcro", "adhesive", "pin", "magnet"],
      "notBackings": ["iron_on"],
      "washMaxC": 30, "ironing": false, "waterproof": true, "durability": "very_high",
      "minQty": 50, "qtyStep": 10,
      "leadTimeWorkdays": { "min": 20, "max": 30, "confidence": "site" },
      "cannot": ["verloop", "foto", "lijnen < 0,6 mm", "opstrijken"],
      "applications": ["airsoft/tactisch", "outdoor", "rugzakken", "petten", "halsbanden", "merch"],
      "confidence": "sourced"
    },
    "leather": {
      "label": { "nl": "Leder", "fr": "Cuir" },
      "variants": {
        "laser": { "label": { "nl": "Gelaserd", "fr": "Gravé au laser" },
          "sizeMm": { "hard": 13, "comfortMin": 40, "comfortMax": 100, "max": 300 },
          "textCapsMm": { "hard": 1.5, "comfort": 2.5 }, "lineMm": { "hard": 0.3, "comfort": 0.5 },
          "colors": { "max": 1, "grayscaleDepth": true, "photo": "grayscale_engraving" }, "confidence": "sourced" },
        "debossed": { "label": { "nl": "Ingedrukt (debossed)", "fr": "Embossé en creux (debossed)" },
          "sizeMm": { "hard": 38, "comfortMin": 50, "comfortMax": 100, "max": 150 },
          "textCapsMm": { "hard": 4, "comfort": 6 }, "lineMm": { "hard": 1.0, "comfort": 1.5 },
          "colors": { "max": 1, "photo": false }, "needsDie": true, "confidence": "derived" }
      },
      "edges": ["lasercut_borderless"],
      "backings": ["sew_on", "velcro", "adhesive"],
      "notBackings": ["iron_on"],
      "washMaxC": null, "machineWash": false, "durability": "high",
      "mode": "quote",
      "leadTimeWorkdays": { "min": 10, "max": 25, "confidence": "derived" },
      "cannot": ["kleuren (enkel brandtint)", "opstrijken (echt leder)"],
      "applications": ["petten", "jeanslabels", "tassen", "schorten", "premium merch"],
      "confidence": "sourced"
    },
    "tpu_silicone": {
      "label": { "nl": "TPU / silicone transfer", "fr": "Transfert TPU / silicone" },
      "sizeMm": { "hard": 13, "comfortMin": 30, "comfortMax": 200, "max": 305 },
      "textCapsMm": { "hard": 3, "comfort": 4 },
      "lineMm": { "hard": 0.8, "comfort": 1.0 }, "gapMm": { "hard": 0.7, "comfort": 1.0 },
      "colors": { "max": 6, "gradients": false, "photo": false, "pms": true },
      "sharpPointRoundingMm": 1.0,
      "application": "heat_press",
      "mode": "not_offered", "confidence": "sourced"
    },
    "reflective_film": {
      "label": { "nl": "Reflecterende patch (folie/stof)", "fr": "Écusson réfléchissant (film/tissu)" },
      "textCapsMm": { "hard": 5, "comfort": 8 }, "lineMm": { "hard": 1.0, "comfort": 1.5 },
      "colors": { "max": 2, "gradients": false, "photo": false },
      "note": { "nl": "Voor zichtbaarheid; geen certificering tenzij gecertificeerd materiaal.", "fr": "Pour la visibilité ; pas de certification sauf matériau certifié." },
      "mode": "not_offered", "confidence": "derived"
    }
  },
  "stitchModel": {
    "appliesTo": "embroidered",
    "stitchesPerCm2": { "fill": 230, "fillDenseMax": 310, "satinArea": 300, "openLogoBbox": 120, "fullLogoBbox": 270, "fullCoverage": [250, 310] },
    "satinPerCmLength": [40, 70],
    "runStitchPerCm": 12,
    "satinBorderPerCm": 60,
    "perColorChange": 40, "perObject": 15,
    "classify": { "runBelowMm": 1.0, "satinMinMm": 1.0, "satinMaxMm": 6.0, "fillAboveMm": 6.0 },
    "rangeFactor": [0.8, 1.2], "roundTo": 500,
    "quoteSignal": { "maxStitchesPerCm2": 400, "maxStitchesUpTo120mm": 25000 },
    "machineStitchesPerMin": 750,
    "priceImpact": { "enabled": false, "eurPer1000ExtraStitchesInclVat": 0.45, "note": "kalibratie uit kledingvoorbeelden op de site; patchprijs hangt vandaag enkel af van maat" },
    "confidence": "sourced+derived"
  },
  "advice": {
    "sizeFix": "requiredSize = currentSize * (minTextMm / measuredTextMm); round up to shop sizes",
    "shopSizesCm": [4, 6, 8, 10, 12],
    "order": ["sizeFix", "simplifyColors", "switchTechnique"],
    "switchRules": [
      { "if": "photo", "suggest": ["sublimated", "printed"] },
      { "if": "shadedIllustration && wantsThread", "suggest": ["woven", "embroidered"], "note": "embroidered = vereenvoudiging tot N kleuren" },
      { "if": "textCapsMm < 4 && textCapsMm >= 2", "suggest": ["woven", "printed"] },
      { "if": "textCapsMm < 2", "suggest": ["printed", "sublimated"] },
      { "if": "colors > 12 || gradients", "suggest": ["sublimated", "printed"] },
      { "if": "outdoor || waterproof || tactical", "suggest": ["pvc"] },
      { "if": "nonConvexShape && edge == merrow", "suggest_edge": "satin_hotcut" }
    ]
  },
  "sources": [
    "https://borduurservicebelgie.be/patches/geborduurd/",
    "https://borduurservicebelgie.be/patches/laten-maken/",
    "https://worldemblem.com/patches/embroidered",
    "https://worldemblem.com/patches/woven",
    "https://worldemblem.com/patches/sublimated-patches/sublimated",
    "https://ca.worldemblem.com/patches/Chenille",
    "https://www.worldemblem.com/patches/leather-patches/debossed/",
    "https://www.worldemblem.com/patches/leather-patches/faux/",
    "https://www.worldemblem.com/transfers/plottercuttransfers/",
    "https://assets.stahls.com/stahls/content/pdf/flyers/Stahls-PVC-Patches-Specifications.pdf",
    "https://assets.stahls.com/stahls/content/pdf/flyers/STAHLS-SpecsSheet-WovenPatches.pdf",
    "https://assets.stahls.com/stahls/content/pdf/flyers/3D-Embroidered-Patches-Specs-Sheet.pdf",
    "https://assets.transferexpress.com/pdf/specs/TXP-Chenille-SpecSheet.pdf",
    "https://assets.transferexpress.com/pdf/specs/TXP-Woven-SpecSheet.pdf",
    "https://assets.transferexpress.com/pdf/specs/TXP-PVC-SpecSheet.pdf",
    "https://assets.transferexpress.com/pdf/specs/TXP-PrintStitch-SpecSheet.pdf",
    "https://lionbrothers.com/silicone-patches",
    "https://help.ottocap.com/artwork-requirements",
    "https://custom.imperial1916.com/printing/3",
    "https://www.magnetichoop.com/blogs/news/3d-puff-embroidery-designs-mastering-techniques-materials-and-troubleshooting",
    "https://help.bystadium.com/hc/en-us/articles/40706833307031-Embroidery-Guidelines",
    "https://abemblem.com/design-tips/",
    "https://www.montereycompany.com/blog/merrowed-edge-vs-heat-cut-edges-on-patches",
    "https://www.montereycompany.com/blog/3d-vs-2d-pvc-patches/",
    "https://www.montereycompany.com/blog/debossed-leather-vs-laser-engraved-leather-patches/",
    "https://www.bespokepatches.co.uk/blog/best-borders-for-your-custom-patches",
    "https://www.bespokepatches.co.uk/blog/can-you-wash-custom-iron-on-patches/",
    "https://www.madeirausa.com/resources/embroidering-with-metallic-thread/default.aspx",
    "https://embroideryhoopstore.com/blogs/articles/glow-reflective-embroidery-threads",
    "https://www.superiorthreads.com/glow-in-the-dark",
    "https://help.apliiq.com/portal/en/kb/articles/estimated-stitch-counts-for-embroidery",
    "https://www.chholderby.com/tips-tricks-and-help/estimating-stitches/",
    "https://community.annthegran.com/FILES%2f2016%2f11%2fCommonEmbStitchMatrix-2-11-10.pdf.axdx",
    "https://docs.wilcom.com/embroiderystudio/27/en/OnlineHelp/Digitizing/stitches/stitches-11.htm",
    "https://members.asicentral.com/news/how-to/october-2019/how-to-reduce-stitch-count-without-jeopardizing-quality/",
    "https://patchcorner.com/info/iron-on-instructions",
    "https://owlbadges.com/?p=347264",
    "https://patchandbagel.com/blogs/custom-patches-guides-tips/custom-patch-lead-times-ordering-timeline-singapore-2026"
  ]
}
```

---

## 14. Korte adviesteksten per situatie (klaar voor de UI)

NL ("je") / FR ("vous" — standaard in Belgisch-Franse webshops).
| Situatie | NL | FR |
|---|---|---|
| Tekst te klein voor borduren | "Je kleinste tekst is {x} mm hoog. Borduren vraagt minstens 4 mm, anders loopt het dicht. Maak je patch {maat} cm, of kies geweven (vanaf 2 mm)." | "Votre plus petit texte mesure {x} mm. La broderie demande au moins 4 mm. Passez à {maat} cm, ou choisissez le tissé (dès 2 mm)." |
| Lijn te dun | "Er zitten lijnen van {x} mm in. Een satijnsteek heeft minstens 1 mm nodig; dunnere lijnen zetten we als fijne garenlijn of we maken ze iets dikker in de proef." | "Certaines lignes font {x} mm. Un point satin demande au moins 1 mm ; nous les épaissirons légèrement dans l'épreuve." |
| Te veel kleuren | "Je ontwerp heeft ± {n} tinten. In garen werken we met maximaal 12 kleuren; we vereenvoudigen naar {k} garenkleuren. Je ziet het resultaat in je digitale proef." | "Votre design compte ± {n} teintes. Nous simplifions à {k} couleurs de fil ; vous verrez le résultat dans l'épreuve numérique." |
| Merrow niet mogelijk | "Deze vorm heeft holle hoeken. Een kettelrand volgt alleen eenvoudige vormen (rond, ovaal, rechthoek). We werken je patch af met een geborduurde rand en snijden hem op vorm." | "Cette forme a des angles rentrants. Une bordure merrow ne suit que les formes simples ; nous utilisons une bordure brodée découpée à la forme." |
| Rand neemt ruimte | "De rand is ± 3 mm breed. Tekst die tegen de buitenkant loopt, schuift daardoor wat naar binnen." | "La bordure fait ± 3 mm. Le texte proche du bord sera légèrement décalé vers l'intérieur." |
| Foto | "Dit is een foto. Garen kan geen foto nabootsen; een bedrukte patch met geborduurde rand toont elk detail." | "C'est une photo. Le fil ne peut pas reproduire une photo ; un écusson imprimé avec bordure brodée montre chaque détail." |
| Opstrijken op verkeerde stof | "Op leer, nylon, fleece of gecoate stof houdt een strijklaag niet goed. Kies opnaaien of klittenband." | "Sur cuir, nylon, polaire ou tissu enduit, le thermocollant tient mal. Choisissez couture ou velcro." |
| PVC | "PVC is waterdicht en bijna onverwoestbaar, ideaal voor buiten. Het werkt met effen kleuren (geen verloop) en vraagt een eenmalige mal; vanaf 50 stuks." | "Le PVC est étanche et quasi indestructible. Couleurs unies uniquement, moule unique ; dès 50 pièces." |

---

## 15. Open punten voor de eigenaar
1. Eigen machines: max. kleuren per patch (site zegt 12; aantal naalden?), machinesnelheid, kan de werkplaats merrow/kettelrand en lasercut?
2. Worden 3D-puff, metallic, glow en reflecterend garen aangeboden? Meerprijs?
3. Minimum patchmaat in eigen productie (shop: 4 cm; info: 5 cm).
4. Levertijden per techniek (vast getal of "bij proef").
5. Geweven, chenille, leder: zelf maken of uitbesteden (bepaalt min. aantal, levertijd en de regels in §13)?
6. Wasvoorschrift dat de eigenaar wil communiceren (site: polyester garen 60 °C; met strijklaag adviseer ik 30–40 °C).
