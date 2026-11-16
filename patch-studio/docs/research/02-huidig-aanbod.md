# 02 — Huidig patch-aanbod en prijzen van borduurservicebelgie.be

Onderzoeker: "huidig-aanbod". Opgehaald op 16-11-2026 via de publieke WooCommerce Store API, de productpagina's en
testwinkelmandjes in de Store API (zonder bestelling te plaatsen). Alle bedragen hieronder zijn **echt gemeten**, niet geschat.
Tenzij anders vermeld: "excl." = zonder btw (zo staan ze in WooCommerce), "incl." = met 21% btw (zo toont de site ze).

> Kort: de shop heeft **4 patchproducten** (geborduurd, geprint, gesublimeerd, PVC), elk als variabel product met
> **5 maten (4/6/8/10/12 cm)**. Opties lopen via **WooCommerce Product Add-Ons**, staffelkorting via **Woo Discount Rules**,
> eenmalige kosten via **Conditional Extra Fees**. Verzending: **€ 10 excl. (€ 12,10 incl.)**, **gratis vanaf € 150 incl. btw**
> artikelwaarde, alleen naar **BE en NL**. Op /patches/ staat al een eenvoudige "patch-samensteller" (handgeschreven
> Elementor-HTML) waarvan enkele bedragen **niet** overeenkomen met wat de kassa echt rekent (zie §8).

---

## 1. Bronnen

| Wat | URL |
|---|---|
| Producten (Store API) | https://borduurservicebelgie.be/wp-json/wc/store/v1/products?per_page=100 (65 producten, 4 in categorie `patch`) |
| Categorieën | https://borduurservicebelgie.be/wp-json/wc/store/v1/products/categories?per_page=100 (`patch` = id 179, 4 producten) |
| Variatieprijzen | https://borduurservicebelgie.be/wp-json/wc/store/v1/products/{variation_id} |
| Geborduurde patch | https://borduurservicebelgie.be/product/geborduurde-patch/ (product 29902) |
| Geprinte patch | https://borduurservicebelgie.be/product/geprinte-patch/ (product 30484) |
| Gesublimeerde patch | https://borduurservicebelgie.be/product/gesublimeerde-patch/ (product 36158) |
| PVC patch | https://borduurservicebelgie.be/product/pvc-patch/ (product 30541) |
| Bestaande samensteller + keuzehulp | https://borduurservicebelgie.be/patches/ (Elementor HTML-widget `#bsb-patch-samensteller`, pagina-id 23153) |
| Patches laten maken (minima, advies) | https://borduurservicebelgie.be/patches/laten-maken/ |
| Geborduurde patches (specs) | https://borduurservicebelgie.be/patches/geborduurd/ |
| Badges (maten, randen) | https://borduurservicebelgie.be/badges/ |
| Digitaliseren (borduurkaart-prijs) | https://borduurservicebelgie.be/digitaliseren/ |
| FAQ (verzending, "Ontwerp het Zelf") | https://borduurservicebelgie.be/faq/ |
| Algemene voorwaarden | https://borduurservicebelgie.be/algemene-voorwaarden/ |
| Winkel (doel van "ontwerp het Zelf"-knop) | https://borduurservicebelgie.be/winkel/ |
| Kassagedrag (fees, staffel, verzending) | Store API `/wp-json/wc/store/v1/cart` na add-to-cart via het gewone productformulier (testmandjes, niets besteld) |

---

## 2. Basisprijs per stuk (variatieprijs, 1 stuk, zonder opties)

WooCommerce bewaart prijzen **excl. btw**; de site toont incl. 21% btw met de suffix "incl. btw".

| Techniek (product-id) | 4 cm | 6 cm | 8 cm | 10 cm | 12 cm | Min. / stap | Bron |
|---|---|---|---|---|---|---|---|
| **Geborduurd** (29902) excl. | 2,50 | 4,04 | 5,20 | 6,23 | 7,17 | 1 / 1 | [product](https://borduurservicebelgie.be/product/geborduurde-patch/) |
| Geborduurd incl. | **3,03** | **4,89** | **6,29** | **7,54** | **8,68** | | |
| **Geprint** (30484) excl. | 3,50 | 4,00 | 4,50 | 5,50 | 6,50 | 1 / 1 | [product](https://borduurservicebelgie.be/product/geprinte-patch/) |
| Geprint incl. | **4,24** | **4,84** | **5,45** | **6,66** | **7,87** | | |
| **Gesublimeerd** (36158) excl. | 2,50 | 3,50 | 4,00 | 4,50 | 5,00 | 1 / 1 | [product](https://borduurservicebelgie.be/product/gesublimeerde-patch/) |
| Gesublimeerd incl. | **3,03** | **4,24** | **4,84** | **5,45** | **6,05** | | |
| **PVC** (30541) excl. | 3,00 | 4,00 | 5,00 | 6,00 | 7,00 | **50 / per 10** | [product](https://borduurservicebelgie.be/product/pvc-patch/) |
| PVC incl. | **3,63** | **4,84** | **6,05** | **7,26** | **8,47** | | |

Variatie-ID's (nodig om in de winkelmand te leggen; attribuut `attribute_pa_sizes` = `4cm`…`12cm`):

| Maat | Geborduurd | Geprint | Gesublimeerd | PVC |
|---|---|---|---|---|
| 4 cm | 30260 | 30485 | 36159 | 30542 |
| 6 cm | 30261 | 30486 | 36160 | 30543 |
| 8 cm | 30262 | 30487 | 36161 | 30544 |
| 10 cm | 30254 | 30488 | 36162 | 30545 |
| 12 cm | 30255 | 30489 | 36163 | 30546 |

"Maat" = **maximale maat** van de patch (langste zijde): letterlijk "Kies de maximale maat van uw patch" op de productpagina.
Op infopagina's staat "van 5 cm tot 30 cm" en "tot 30 x 30 cm op aanvraag" ([geborduurd](https://borduurservicebelgie.be/patches/geborduurd/)):
alles boven 12 cm is dus **offerte**.

Niet als product te koop (enkel info / offerte): **geweven** (vanaf 100 stuks), **chenille**, **rubber**, vilt, twill-badges,
leder/gelaserd komt nergens voor. Bron: [patches/laten-maken](https://borduurservicebelgie.be/patches/laten-maken/)
("Geweven patch: … Vanaf 100 stuks", "PVC-patch: … Vanaf 50 stuks"), [badges](https://borduurservicebelgie.be/badges/) ("Geweven badge … Vanaf 100 stuks").

---

## 3. Staffelkorting (Woo Discount Rules)

Tabel op elke patch-productpagina ("Staffelkorting – Hoe meer je bestelt, hoe groter de korting!"):

| Aantal | Korting |
|---|---|
| 1–9 | 0% |
| 10–24 | 5% |
| 25–49 | 10% |
| 50–99 | 15% |
| 100 of meer | 20% |

**Gemeten gedrag in de kassa** (belangrijk voor de bouwers):

1. Korting geldt op **variatieprijs + per-stuk add-ons** (bv. 25 × geborduurd 10 cm + strijklaag + folierand: (6,23 + 1,50 + 2,50) × 0,90 = 9,207 excl./st., lijn € 230,18 excl. — klopt exact).
2. Het aantal wordt **opgeteld over alle patchregels in de mand** (geborduurd + geprint + PVC, alle maten samen).
   Test: 20 geborduurd 4 cm + 5 geprint 8 cm ⇒ beide regels kregen **10%** (25 stuks samen).
3. **Gesublimeerde patch krijgt in de kassa GEEN staffelkorting** (getest met 10, 25, 50 en 100 stuks: steeds volle prijs)
   en telt ook niet mee voor de staffel van de andere patches — terwijl de staffeltabel wél op de pagina staat. Zie §8.

Prijs per stuk incl. btw na staffel (zonder opties; zo rekent de kassa vandaag):

| Geborduurd | 1–9 | 10–24 | 25–49 | 50–99 | 100+ |
|---|---|---|---|---|---|
| 4 cm | 3,03 | 2,87 | 2,72 | 2,57 | 2,42 |
| 6 cm | 4,89 | 4,64 | 4,40 | 4,16 | 3,91 |
| 8 cm | 6,29 | 5,98 | 5,66 | 5,35 | 5,03 |
| 10 cm | 7,54 | 7,16 | 6,78 | 6,41 | 6,03 |
| 12 cm | 8,68 | 8,24 | 7,81 | 7,37 | 6,94 |

| Geprint | 1–9 | 10–24 | 25–49 | 50–99 | 100+ |
|---|---|---|---|---|---|
| 4 cm | 4,24 | 4,02 | 3,81 | 3,60 | 3,39 |
| 6 cm | 4,84 | 4,60 | 4,36 | 4,11 | 3,87 |
| 8 cm | 5,45 | 5,17 | 4,90 | 4,63 | 4,36 |
| 10 cm | 6,66 | 6,32 | 5,99 | 5,66 | 5,32 |
| 12 cm | 7,87 | 7,47 | 7,08 | 6,69 | 6,29 |

| PVC (min. 50) | 50–99 | 100+ |
|---|---|---|
| 4 cm | 3,09 | 2,90 |
| 6 cm | 4,11 | 3,87 |
| 8 cm | 5,14 | 4,84 |
| 10 cm | 6,17 | 5,81 |
| 12 cm | 7,20 | 6,78 |

Gesublimeerd: in de kassa vandaag altijd de basisprijs (3,03 / 4,24 / 4,84 / 5,45 / 6,05).

Afronding: reken in **excl. btw per regel** en tel btw op regelniveau op zoals WooCommerce; een per-stuk-incl.-bedrag kan € 0,01 afwijken
(bv. kassa toont 10 × geborduurd 4 cm als € 2,88/st. incl., de rekensom geeft 2,874).

---

## 4. Opties (WooCommerce Product Add-Ons)

Typen: `quantity_based` = **per stuk**, `flat_fee` = **eenmalig per orderregel**, `percentage_based` = % op de regel.
Prijzen excl. btw (incl. tussen haakjes, zoals op de site).

### 4a. "Afwerking achterkant Patch" (checkboxen, meerdere mogelijk, niet verplicht)
Geldt voor **geborduurd, geprint en gesublimeerd** (identiek):

| Optie | Type | Excl. | Incl. |
|---|---|---|---|
| Geen wens / geen afwerking op de achterkant | — | 0 | 0 |
| Klittenband haak (hard) zwart (niet gesneden) | per stuk | 2,00 | 2,42 |
| Klittenband haak (hard) wit (niet gesneden) | per stuk | 2,00 | 2,42 |
| Klittenband lus (zacht) zwart (niet gesneden) | per stuk | 2,00 | 2,42 |
| Klittenband lus (zacht) wit (niet gesneden) | per stuk | 2,00 | 2,42 |
| Gesneden (klittenband op vorm gesneden) | per stuk | 1,00 | 1,21 |
| Opstrijkbare lijmlaag | per stuk | 1,50 | 1,82 |
| Dikke patch (dubbele onderlaag) | per stuk | 1,50 | 1,82 |
| Extra dikke patch (drie dubbele onderlaag) | per stuk | 3,00 | 3,63 |

**PVC** heeft enkel: Klittenband haak (hard) of lus (zacht), per stuk 1,00 excl. (1,21 incl.). Zonder keuze = klaar om op te naaien.

### 4b. "Rand afwerking" — alleen geborduurd, **verplicht** (radio)

| Optie | Type | Excl. | Incl. |
|---|---|---|---|
| Patch geknipt uit doek (lichte rafels mogelijk) — standaard | — | 0 | 0 |
| Patch op folie (verfijnde rand) | per stuk | 2,50 | 3,03 |
| Ik wil besparen, en knip ze zelf uit (van een backing stof) | % | −10% | −10% |

Er is **geen** keuze merrow/kettelrand vs. lasercut als product-optie, hoewel de infopagina's "merrow, laser cut, gestikt" vermelden
([geborduurd](https://borduurservicebelgie.be/patches/geborduurd/)). Bij geprint en gesublimeerd wordt de rand altijd
"op de borduurmachine" afgewerkt (geborduurde rand), zonder keuze.

### 4c. "Patch basis materiaal" — alleen gesublimeerd, **verplicht**

| Optie | Type | Excl. | Incl. |
|---|---|---|---|
| Standaard drager (glad, wit polyester) | — | 0 | 0 |
| Flock (mat, fluweelzacht, iets dikker) | **flat_fee** (eenmalig per regel!) | 0,75 | 0,91 |

Flock is als eenmalig bedrag ingesteld (gemeten: 100 stuks + flock = +€ 0,75 op de hele regel). Waarschijnlijk bedoeld als per stuk — zie §8.

### 4d. "Diverse extra's" (checkboxen)

| Optie | Type | Excl. | Incl. | Bij |
|---|---|---|---|---|
| Klittenband haak/lus, zwart/wit (los, tegenstuk) | per stuk | 2,00 | 2,42 | geborduurd, geprint, gesublimeerd |
| Rol garen in zelfde kleur als buitenrand | eenmalig | 5,00 | 6,05 | geborduurd, gesublimeerd, PVC |
| Rol garen zwart | eenmalig | 5,00 | 6,05 | geborduurd, gesublimeerd, PVC |
| Gebogen stofschaar | eenmalig | 25,00 | 30,25 | geborduurd, gesublimeerd, PVC |
| Rechte stofschaar | eenmalig | 19,95 | 24,14 | gesublimeerd |

### 4e. Overige velden
- **Upload** ("Upload hier uw bestand/ontwerp", optioneel, max. 3 GB): "je kunt je ontwerp ook later doorsturen; we nemen contact op."
- **Opmerkingen** (vrij tekstveld).

---

## 5. Eenmalige kosten (Conditional Extra Fees, automatisch in de kassa)

| Fee-naam in kassa | Excl. | Incl. | Wanneer (gemeten) |
|---|---|---|---|
| **Borduurkaart en Instelkosten** | **45,00** | **54,45** | zodra er een geborduurde patch in de mand zit; **één keer per bestelling**, ook bij 2 verschillende geborduurde ontwerpen/maten |
| **Instelkosten** | **20,00** | **24,20** | geprint en/of gesublimeerd in de mand; **één keer**, ook als beide technieken samen besteld worden |
| **Eenmalige kosten voor aanmaak van de mal** | **80,00** | **96,80** | PVC in de mand |

Fees krijgen **geen** staffelkorting en tellen **niet** mee voor gratis verzending.

Tegenstrijdige teksten op de site (bouwers: niet overnemen zonder bevestiging, zie §8):
- PVC-productpagina: "De kosten hiervan worden tijdens het afrekenen toegevoegd (€150)" — kassa rekent € 80 excl. / € 96,80 incl.
- /patches/-samensteller: "eenmalig een borduurkaart … van € 29,95 per ontwerp" — kassa rekent € 45 excl. / € 54,45 incl.
- /digitaliseren/: "€ 29,95 per borst- of ruglogo (€ 45,- voor borst- en ruglogo samen). In sommige gevallen vervallen deze kosten."
- /patches/laten-maken/: "Geen opstartkosten, geen verplichtingen." (onder "per stuk – zonder minimum").
- Herbestelling: "Bestel je later hetzelfde ontwerp opnieuw, dan gebruiken we die borduurkaart opnieuw" ([laten-maken](https://borduurservicebelgie.be/patches/laten-maken/)) —
  de kassa heeft daar geen mechanisme voor; de fee wordt altijd aangerekend.

---

## 6. Verzending, levertijd, landen

| Item | Waarde | Bron |
|---|---|---|
| Verzendtarief (flat rate) | **€ 10,00 excl. / € 12,10 incl.** | kassa (BE 2920 en NL 4811) |
| Gratis verzending | "Gratis verzending vanaf € 150": drempel = **artikelsubtotaal incl. btw ≥ € 150**, fees tellen niet mee (38 st. geprint = € 144,84 incl. → geen; 40 st. = € 152,46 → wel) | kassa-test |
| Let op | Gratis verzending verschijnt als **tweede keuze**; het betaalde tarief staat standaard geselecteerd | kassa (Store API `selected`) |
| Landen | **BE en NL**; DE/FR krijgen geen verzendtarief ("op verzoek offerte") | kassa + [FAQ](https://borduurservicebelgie.be/faq/) |
| Afhalen | geen afhaaloptie in de kassa; "op afspraak langs te komen" in FAQ | kassa + FAQ |
| Levertijd PVC | "Levertijd: rond de 4 weken" | [PVC](https://borduurservicebelgie.be/product/pvc-patch/) |
| Levertijd overige patches | nergens een termijn: "Afhankelijk van techniek en aantal stuks; je krijgt de termijn bij je offerte." / "Levertijd volgens je offerte" | [laten-maken](https://borduurservicebelgie.be/patches/laten-maken/), [/patches/](https://borduurservicebelgie.be/patches/) |
| Winkel algemeen | "kleine personalisaties zijn vaak binnen enkele werkdagen klaar" | [winkel](https://borduurservicebelgie.be/winkel/) |
| Btw | 21%, prijzen getoond incl. btw; BTW BE1012263492, "factuur mogelijk" | /patches/ |
| Herroeping | geen herroepingsrecht op gepersonaliseerde stukken; geen annulatie zodra productie gestart is | [AV](https://borduurservicebelgie.be/algemene-voorwaarden/), [winkel](https://borduurservicebelgie.be/winkel/) |

**Advies voor de tool:** toon geen vaste levertijd voor geborduurd/geprint/gesublimeerd zolang de klant die niet heeft vastgelegd.
Maak het een configureerbaar veld per techniek (standaard leeg → tekst "We bevestigen de levertijd samen met je digitale proef");
PVC standaard "± 4 weken na goedkeuring".

---

## 7. Minimumaantallen, maten, randen, achterkanten — samenvatting

| Techniek | Verkoopbaar online | Min. | Maten online | Rand | Achterkant / extra |
|---|---|---|---|---|---|
| Geborduurd | ja | 1 | 4–12 cm (groter: offerte, tot 30 cm) | uit doek geknipt (std.), op folie (+2,50 excl.), zelf knippen (−10%) | klittenband, strijklaag, dik, extra dik, gesneden |
| Geprint (print op stof, geborduurde rand) | ja | 1 | 4–12 cm | geborduurde rand (vast) | idem geborduurd |
| Gesublimeerd (wit polyester / flock) | ja | 1 | 4–12 cm | geborduurde rand (vast) | idem + flock-drager |
| PVC | ja | **50, per 10** | 4–12 cm | gegoten vorm | klittenband haak/lus (+1,00 excl.) of opnaaien |
| Geweven | **nee** (offerte) | **100** | — | — | — |
| Chenille / rubber / vilt | **nee** (enkel vermeld) | ? | — | — | — |

Technische richtwaarden die de site zelf publiceert (handig voor het advies in de tool, [geborduurd](https://borduurservicebelgie.be/patches/geborduurd/)):
minimale teksthoogte borduren **4 mm**; "fijn borduurwerk met tot 12 kleuren"; onderstof twill 180 g/m²; polyester garen, wasbaar tot 60 °C.
Keuzehulp op /patches/ gebruikt "fijne details of kleine tekst (kleiner dan ± 3 mm)" als grens. Ronde badge 7–10 cm = "meest gevraagde
maat voor clubs"; borstlogo/naam op werkkleding "6 tot 8 cm breed" ([badges](https://borduurservicebelgie.be/badges/)).
Grote rugemblemen: site raadt **geprint met geborduurde rand** aan i.p.v. borduren ([laten-maken](https://borduurservicebelgie.be/patches/laten-maken/)).
Achterkant-advies van de site: strijklaag op katoen/polyester-katoen, niet op leer/nylon/gecoat/fleece → opnaaien of klittenband.

---

## 8. Hoe "ontwerp het Zelf" en de bestaande samensteller vandaag werken

- De knop **"ontwerp het Zelf"** op de homepage linkt naar **/winkel/** (gewone shop). Er is **geen** online ontwerptool
  (geen Fancy Product Designer of andere designer geladen). De FAQ belooft nochtans een "Ontwerp het Zelf‑module … om direct tekst te
  plaatsen en eenvoudige ontwerpen op kleding aan te brengen" → Patch Studio vult dat gat.
- Op **/patches/** staat een handgemaakte **"Stel je patch samen & zie meteen je prijs"**-widget met keuzehulp (3 vragen: aantal kleuren,
  verloop/foto, details < 3 mm → advies borduur / geweven / gesublimeerd). Prijzen zijn hard gecodeerd ("bron: Patch-prijslijst-per-type-2026-07-04.xlsx").
  De knop "Stel samen & bestel" stuurt alleen door naar de productpagina; **de keuzes worden niet meegegeven**: de klant moet alles opnieuw invullen.
- Afwijkingen tussen die widget en de echte kassa (de nieuwe tool moet **uit één config** rekenen die op de kassa aansluit):

| Onderwerp | Widget /patches/ | Echte kassa |
|---|---|---|
| Borduurkaart | € 29,95 per ontwerp | € 45 excl. / € 54,45 incl., één keer per bestelling |
| Instelkosten geprint/gesublimeerd | niet vermeld | € 20 excl. / € 24,20 incl. |
| PVC-mal | niet vermeld (offerte-modus) | € 80 excl. / € 96,80 incl. (productpagina zegt € 150) |
| Gesublimeerd | linkt naar /geprinte-patch/, met staffel | eigen product /gesublimeerde-patch/, **zonder** staffel |
| Geweven | prijs = geborduurd, linkt naar geborduurde patch, vanaf 1 | geen product; elders "vanaf 100 stuks" |
| Opties "Lijm 2 — premium incl. randafwerking" (6,66) en "Uitgesneden / custom vorm" (3,63) | aanwezig | bestaan **niet** als add-on |
| Meerdere opties combineren | slechts 1 optie kiesbaar | add-ons zijn checkboxen, combineerbaar |

**Te bevestigen door de eigenaar (open punten):**
1. Moet gesublimeerd wél staffelkorting krijgen (tabel op de pagina zegt ja, kassa zegt nee)?
2. Juiste eenmalige kosten: borduurkaart € 29,95 of € 45 excl.? PVC-mal € 80 excl. of € 150? Vervalt de borduurkaart bij herbestelling?
3. Flock: per stuk of per regel?
4. Geweven: prijzen + minimum (100?) om online te tonen, of altijd offerte?
5. Indicatieve levertijden per techniek.
6. Rekenen fees per ontwerp (zoals de teksten suggereren) of per bestelling (zoals de kassa nu doet)?

---

## 9. Geverifieerde kassavoorbeelden (bewijs dat het rekenmodel klopt)

Alle bedragen incl. btw, BE 2920, uit de Store API-winkelmand.

| Mand | Artikelen | Fees | Verzending | Totaal |
|---|---|---|---|---|
| 1 × geborduurd 4 cm, rand uit doek | 3,03 | 54,45 | 12,10 | **69,58** |
| 10 × geborduurd 4 cm | 28,74 | 54,45 | 12,10 | **95,29** |
| 1 × geborduurd 12 cm | 8,68 | 54,45 | 12,10 | **75,23** |
| 25 × geborduurd 10 cm + strijklaag + folierand | 278,52 | 54,45 | 0 mogelijk (12,10 standaard geselecteerd) | 345,07 (met 12,10) |
| 1 × geprint 4 cm | 4,24 | 24,20 | 12,10 | **40,54** |
| 10 × geprint 4 cm | 40,23 | 24,20 | 12,10 | **76,53** |
| 1 × gesublimeerd 4 cm standaard drager | 3,03 | 24,20 | 12,10 | **39,33** |
| 50 × PVC 4 cm | 154,28 | 96,80 | 12,10 of gratis | 263,18 (met 12,10) |
| 100 × PVC 8 cm + klittenband haak | 580,80 | 96,80 | 12,10 of gratis | 689,70 (met 12,10) |

Rekenregel (excl. btw, dan × 1,21):
```
regel = (variatieprijs + Σ per-stuk-add-ons) × (1 − randkorting%) × (1 − staffel(totaal patches in mand, excl. gesublimeerd)) × aantal
        + Σ eenmalige add-ons van die regel
bestelling = Σ regels + fees (45 als geborduurd aanwezig; 20 als geprint/gesublimeerd aanwezig; 80 als PVC aanwezig)
           + verzending (10, of 0 als Σ artikelen incl. btw ≥ 150)
```

---

## 10. Voorstel standaardconfiguratie Patch Studio (sluit aan op de echte prijzen)

Principes: **dezelfde getallen als de kassa**, alles in één JSON-config die de beheerder kan wijzigen (backend-instelling,
geen hard gecodeerde prijzen), en toevoegen aan de mand **op de bestaande producten/variaties met hun add-ons** zodat Woo Discount Rules,
Conditional Extra Fees en verzending ongewijzigd blijven werken. Zo hoeft de eigenaar geen nieuwe prijslogica te onderhouden.

### 10a. Config (excl. btw, zoals WooCommerce)
```json
{
  "vat": 0.21,
  "pricesIncludeTax": false,
  "sizesCm": [4, 6, 8, 10, 12],
  "sizeQuoteAboveCm": 12,
  "tiers": [[1,0],[10,0.05],[25,0.10],[50,0.15],[100,0.20]],
  "tierPoolExcludes": ["sublimated"],
  "shipping": { "flat": 10.00, "freeFromInclVat": 150, "countries": ["BE","NL"] },
  "techniques": {
    "embroidered": { "productId": 29902, "minQty": 1, "step": 1, "tiers": true,
      "base": {"4":2.50,"6":4.04,"8":5.20,"10":6.23,"12":7.17},
      "variations": {"4":30260,"6":30261,"8":30262,"10":30254,"12":30255},
      "setupFee": {"label":"Borduurkaart en instelkosten","amount":45.00,"group":"embroidery"},
      "edge": {"required": true, "default":"cut_from_fabric",
        "options": {"cut_from_fabric":0, "on_film":2.50, "self_cut":"-10%"}},
      "leadTime": "" },
    "printed": { "productId": 30484, "minQty": 1, "step": 1, "tiers": true,
      "base": {"4":3.50,"6":4.00,"8":4.50,"10":5.50,"12":6.50},
      "variations": {"4":30485,"6":30486,"8":30487,"10":30488,"12":30489},
      "setupFee": {"label":"Instelkosten","amount":20.00,"group":"print"}, "leadTime": "" },
    "sublimated": { "productId": 36158, "minQty": 1, "step": 1, "tiers": false,
      "base": {"4":2.50,"6":3.50,"8":4.00,"10":4.50,"12":5.00},
      "variations": {"4":36159,"6":36160,"8":36161,"10":36162,"12":36163},
      "setupFee": {"label":"Instelkosten","amount":20.00,"group":"print"},
      "carrier": {"standard":0, "flock":{"amount":0.75,"per":"line"}}, "leadTime": "" },
    "pvc": { "productId": 30541, "minQty": 50, "step": 10, "tiers": true,
      "base": {"4":3.00,"6":4.00,"8":5.00,"10":6.00,"12":7.00},
      "variations": {"4":30542,"6":30543,"8":30544,"10":30545,"12":30546},
      "setupFee": {"label":"Eenmalige kosten voor aanmaak van de mal","amount":80.00,"group":"pvc"},
      "backing": {"sew_on":0, "velcro_hook":1.00, "velcro_loop":1.00},
      "leadTime": "± 4 weken" },
    "woven":    { "mode": "quote", "minQty": 100 },
    "chenille": { "mode": "quote" },
    "leather":  { "mode": "quote" }
  },
  "backingFabricBased": {
    "none":0, "velcro_hook_black":2.00, "velcro_hook_white":2.00, "velcro_loop_black":2.00, "velcro_loop_white":2.00,
    "velcro_cut_to_shape":1.00, "iron_on":1.50, "thick":1.50, "extra_thick":3.00
  },
  "extras": { "thread_roll_edge_colour":5.00, "thread_roll_black":5.00, "scissors_curved":25.00, "scissors_straight":19.95 }
}
```
Add-on veldnamen voor add-to-cart (classic form POST; de Store API weigert de mand zonder verplichte add-ons):
geborduurd `addon-29902-1[]` (achterkant), `addon-29902-2` (rand, verplicht), `addon-29902-3[]` (extra's);
geprint `addon-30484-1[]`, `addon-30484-2[]`; gesublimeerd `addon-36158-1[]` (drager, verplicht: `standaard-drager`/`flock`),
`addon-36158-2[]`, `addon-36158-3[]`; PVC `addon-30541-1[]`, `addon-30541-2[]`; upload telkens `addon-<id>-0`.
Waarden = slugs zoals `opstrijkbare-lijm-laag`, `klittenband-haak-hard-zwart-niet-gesneden`, `patch-op-folie-verfijnde-rand`.
De veld-index kan veranderen als de beheerder add-ons herschikt: **lees ze dynamisch uit** (productpagina of `WC_Product_Addons_Helper::get_product_addons()` in PHP) in plaats van ze hard te coderen.

### 10b. Standaardkeuzes in de tool (startscherm)
| Instelling | Standaard | Waarom |
|---|---|---|
| Techniek | wordt **voorgesteld door de analyse**; zonder upload: **geborduurd** | kernproduct, vanaf 1 stuk, bestaande samensteller start ook zo |
| Maat | **8 cm** (club/borstlogo); bij tekst-only of naam: 6 cm voorstellen | site: borstlogo 6–8 cm, clubbadge 7–10 cm |
| Aantal | **10** | eerste staffeltrede (−5%), zelfde startwaarde als huidige widget; aanpasbaar naar 1 ("eerst één stuk testen" is een echte belofte van de site) |
| Achterkant | **geen** (opnaaien) met advies-tekst per gebruik (strijklaag voor katoen, klittenband voor uniform) | geen verborgen meerprijs |
| Rand (geborduurd) | **uit doek geknipt** (standaard, 0 €), folierand als upgrade tonen | kassa-default |
| PVC | aantal springt naar **50**, stappen van 10 | kassa-minimum |
| Geweven/chenille/leder | geen prijs, knop **"Vraag prijs op maat"** met analyse + bestand mee | geen product in shop |

### 10c. Snelle referentie: wat een klant echt betaalt (incl. btw, zonder opties, inclusief eenmalige kosten, excl. verzending)
| Configuratie | 1 st. | 10 st. | 25 st. | 50 st. | 100 st. |
|---|---|---|---|---|---|
| Geborduurd 8 cm | 6,29 + 54,45 = **60,74** | 59,77 + 54,45 = **114,22** | 141,57 + 54,45 = **196,02** | 267,41 + 54,45 = **321,86** | 503,36 + 54,45 = **557,81** |
| Geprint 8 cm | 5,45 + 24,20 = **29,65** | 51,73 + 24,20 = **75,93** | 122,51 + 24,20 = **146,71** | 231,41 + 24,20 = **255,61** | 435,60 + 24,20 = **459,80** |
| Gesublimeerd 8 cm (kassa vandaag) | 4,84 + 24,20 = **29,04** | 48,40 + 24,20 = **72,60** | 121,00 + 24,20 = **145,20** | 242,00 + 24,20 = **266,20** | 484,00 + 24,20 = **508,20** |
| PVC 8 cm | — | — | — | 257,13 + 96,80 = **353,93** | 484,00 + 96,80 = **580,80** |

Eerlijk adviesgegeven dat hieruit volgt (mag de tool tonen, het zijn echte cijfers): bij kleine aantallen weegt de eenmalige kost zwaarder
dan de stuksprijs (1 geborduurde patch 8 cm ≈ € 60,74 waarvan € 54,45 eenmalig); de prijs per stuk inclusief eenmalige kosten daalt bij
geborduurd 8 cm van € 60,74 (1 st.) naar € 11,42 (10 st.) en € 5,58 (100 st.). Toon dus altijd **"prijs per stuk alles inbegrepen"** naast de
stuksprijs, en de **volgende staffeltrede** ("Bij 25 stuks betaal je € x per stuk") — dat is transparant en overtuigend zonder trucs.
Gratis verzending vanaf € 150 artikelwaarde incl. btw is een echte drempel en mag als hint getoond worden ("nog € x tot gratis verzending").
