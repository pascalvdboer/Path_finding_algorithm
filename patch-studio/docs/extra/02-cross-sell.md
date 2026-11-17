# 02 — Cross-sell: "zelfde ontwerp, ander product"

Onderzoeker: "cross-sell". Datum: 17-11-2026.
Bronnen: de publieke Store API van borduurservicebelgie.be (65 producten, opgehaald 17-11), de pagina's /petten-bedrukken/,
/laser-graveren/ en /relatiegeschenken/, `docs/research/02-huidig-aanbod.md` (gemeten kassagedrag),
`docs/research/04-patch-techniek.md` (techniekregels) en een beperkte marktverkenning (zie §9).

Labels: **[gemeten]** = staat zo in de shop/kassa · **[bron]** = externe bron · **[schatting]** = eigen inschatting, eigenaar
moet kostprijs/inkoop bevestigen · **[eigenaar]** = beslissing van de eigenaar nodig.

> **Kort.** De shop verkoopt al blanco petten (€ 18,10), mutsen (€ 4,60–8,20) en hoodies (€ 40–92) en heeft een eigen laser
> (hout, glas, leder, geanodiseerd aluminium, vanaf 1 stuk). Daardoor kunnen we vijf cross-sells meteen aanbieden zonder nieuwe
> machines: **patch op pet/muts**, **patch op hoodie**, **lederen (gelaserde) sleutelhanger/tassenlabel**, **ingekaderde patch met
> gelaserd plaatje** en (vanaf 50 stuks, via de PVC-leverancier) **PVC-sleutelhanger**. Het echte, eerlijke voordeel: de
> borduurkaart (€ 54,45) wordt in de kassa **maar één keer per bestelling** aangerekend [gemeten], dus hetzelfde ontwerp op extra
> producten kost geen tweede keer instelkosten. Toon cross-sell **pas na "In winkelmand"**, maximaal 3 kaarten, met een
> mockup van het eigen ontwerp, de volledige prijs en een even zichtbare "Nee, bedankt".

---

## 1. Wat de shop al heeft (Store API, 17-11-2026) [gemeten]

| Product (id) | Prijs incl. | Excl. | Bruikbaar als drager |
|---|---|---|---|
| FL6606RT Classic Retro Trucker pet (36262) | € 18,10 | 14,96 | **ja**: katoenen voorpaneel met buckram, 3 kleuren |
| FL6089MT Classic snapback tweekleurig (36278) | € 18,10 | 14,96 | **ja**: hoog vlak voorpaneel, 21 kleuren |
| FL6277 Flexfit Wooly Combed (36027) | € 18,10 | 14,96 | ja: dad-cap-achtig gesloten model, 26 kleuren, 3 maten |
| KP908 Flexfit 5 panelen (36247) | € 27,23 | 22,50 | ja, maar duurder |
| RC027 muts dubbel tricot, zonder label (36237) | € 4,60 | 3,80 | **ja**: goedkoopste muts, omslag |
| RC133X lichte Thinsulate-muts (36225) | € 5,42 | 4,48 | ja (ook fluo oranje/geel: werf, hulpdiensten) |
| B447 Thinsulate-muts (36209) | € 7,09–8,20 | 5,86– | ja: vlakke omslag |
| KP884 fleece muts met omslag (36178) | € 6,82 | 5,64 | ja, **niet opstrijken** (fleece) → naaien |
| Unisex Hoodie Eco (15590) | € 40,81 | 33,73 | **ja** |
| Heren Sweatshirt met capuchon (16400) | € 40,76–53,98 | 33,69– | ja |
| Unisex Hoodie met rits Eco (15324) | € 48,07–50,22 | 39,73– | ja ("jas"-alternatief) |
| Fanshop: Supporterspet KFC Stroomvaart (39894) | € 27,77 | 22,95 | **prijsanker**: pet met clublogo |
| Fanshop: Supportersmuts (39900) | € 24,14 | 19,95 | prijsanker muts |
| Fanshop: Supportershoodie Boxing Gym (40669) | € 50,00 | 41,32 | prijsanker hoodie |

Niet in de shop: jassen (softshell/bodywarmer), lederen artikelen, kaders, sleutelhangers als product. De laser- en
relatiegeschenkenpagina's noemen wel lederen labels, sleutelhangers in hout, fotokaders en naamplaatjes in geanodiseerd aluminium,
allemaal **vanaf 1 stuk met digitale proef** [gemeten, /laser-graveren/]. De productie kan het dus; het zit alleen nog niet als
bestelbaar product in de shop.

Patchprijzen en fees: zie `02-huidig-aanbod.md` §2–5 (bv. geborduurd 6 cm € 4,04 excl., 10 cm € 6,23 excl.; borduurkaart € 45 excl.
één keer per bestelling; PVC-mal € 80 excl.; staffel 5/10/15/20 % opgeteld over alle patchregels).

---

## 2. De combinaties: wat past technisch bij welk ontwerp

De studio heeft na de analyse al: classificatie (`flat` / `gradient` / `illustration` / `photo`), aantal (gereduceerde) kleuren,
fijnste lijn en kleinste tussenruimte in mm, kleine-tekst-signaal, beeldverhouding, gekozen techniek en maat. Daarmee kan elke
cross-sell een eenvoudige **pastoets** krijgen. Alleen wat "past" of "past met aanpassing" wordt getoond; wat niet past wordt
**niet** getoond (één irrelevante suggestie doet klanten alle suggesties negeren, Baymard [bron]).

| Cross-sell | Past als … | Past met aanpassing | Niet tonen als … | Waarom |
|---|---|---|---|---|
| **Patch op pet** (trucker, snapback, wooly/dad cap) | patchhoogte ≤ 65 mm bij gekozen maat; elke patchtechniek | hoogte > 65 mm → voorstel in 6 cm (of breedte 10 cm bij brede logo's), prijs van die maat tonen | patch > 12 cm (offerte) of rugpatch | voorpaneel trucker/snapback ± 11 × 6,5 cm bruikbaar [schatting; eigenaar meet na] |
| **Patch op muts** | patchhoogte ≤ 55 mm | > 55 mm → voorstel in 4 of 6 cm | brede, liggende ontwerpen (verhouding > 2,2) met kleine tekst | omslag ± 6–7 cm hoog [schatting] |
| **Patch op hoodie** (borst/mouw 6–10 cm, rug 12 cm) | altijd | — | — | groot vlak; naaien, op fleece niet strijken [bron 04 §achterkant] |
| **PVC-sleutelhanger** | classificatie `flat` (of `illustration` die tot ≤ 6 kleuren reduceert), fijnste lijn ≥ 1,0 mm en tekst ≥ 4 mm **op sleutelhangermaat** (± 5 cm), géén verloop/foto | 7–8 kleuren of lijnen 0,6–1,0 mm → "vereenvoudigen in de proef" | `gradient` / `photo`, of < 50 stuks gewenst | PVC = effen kleuren per malkamer, 4–8 kleuren, lijn ≥ 0,6 mm hard (04 §6 [bron]) |
| **Lederen sleutelhanger / tassenlabel** (lasergravure) | ontwerp leesbaar in 1 tint: logo/silhouet/tekst, lijn ≥ 0,5 mm, tekst ≥ 2,5 mm | foto → "gravure in bruintinten" (eerlijk label + monochrome preview) | ontwerp dat enkel via kleur leesbaar is (hoofdkleuren met ΔL\* < 15 onderling) | laser = 1 kleur, contrast komt uit het materiaal (04 §7 [bron], /laser-graveren/ [gemeten]) |
| **Lederen patch op pet** | idem leder | idem | idem | trendy op trucker/snapback; goede uitweg voor ontwerpen die te fijn zijn om te borduren |
| **Ingekaderde patch** (schaduwdoos + gelaserd plaatje) | altijd: patch ≤ 10 cm → kader 20 × 20, ≤ 12 cm → 25 × 25 | — | patch > 12 cm (offerte) | geen technische beperking; cadeau-moment |

Belangrijke eerlijkheidsregel voor petten: **direct op de pet borduren** vraagt een ander borduurbestand (petframe, gebogen vlak),
dus "digitaliseren al gedaan" klopt dan **niet**. De cross-sell is daarom bewust **de patch zelf, op de pet genaaid**: exact
hetzelfde product en bestand. Direct borduren blijft de bestaande offerte-route van /petten-bedrukken/.

PVC-mal: of een sleutelhanger uit **dezelfde mal** als de PVC-patch kan (zelfde vorm en maat, met oogje), hangt af van de
leverancier. Standaard **niet** beloven (`sharedMold: false`); pas op `true` zetten als de leverancier het schriftelijk bevestigt
[eigenaar]. Marktvoorbeeld: Amerikaanse PVC-aanbieder rekent herbestellingen goedkoper "because we'd have the mold already" [bron].

---

## 3. Kosten en prijsvoorstellen

Principe: **blanco product aan winkelprijs + patch aan de gewone patchprijs (telt mee in de patchstaffel) + een klein,
zichtbaar bedrag "aanbrengen"**. Zo is elke prijs uitlegbaar, klopt hij met de bestaande shop, en zit er geen verborgen
opslag in. Alle bedragen excl. btw zoals WooCommerce ze bewaart; incl. = × 1,21 afgerond.

### 3a. Aanbrengen (nieuwe, instelbare bedragen) [schatting]

Onderbouwing: een patch netjes opnaaien op een pet of omslag duurt ± 5–8 minuten (positioneren, eventueel lijm/hittepers vooraf,
stikken); aan ± € 35/u loonkost is dat € 3–4,70.

| Handeling | Excl. | Incl. |
|---|---|---|
| Patch op pet naaien (voorpaneel) | 4,00 | 4,84 |
| Patch op muts naaien (omslag) | 3,50 | 4,24 |
| Patch op hoodie/trui naaien (borst/mouw, ≤ 10 cm) | 4,00 | 4,84 |
| Patch op rug naaien (12 cm) | 5,00 | 6,05 |
| Opnaaiservice op **eigen** kledingstuk (ingestuurd), per patch ≤ 12 cm | 6,00 | 7,26 |
| Toeslag leder/dikke jas bij eigen kledingstuk | 3,00 | 3,63 |

Staffel: de patch en het aanbrengen krijgen dezelfde patchstaffel (de patches worden in dezelfde run gemaakt), het blanco
product niet (de shop geeft vandaag ook geen staffel op blanco's) [eigenaar].

### 3b. Voorbeeldprijzen per stuk (1–9 stuks, geborduurde patch) [berekend uit gemeten prijzen + 3a]

| Combinatie | Opbouw excl. | Totaal excl. | **Incl.** | Anker in de shop |
|---|---|---|---|---|
| Trucker FL6606RT + patch 6 cm | 14,96 + 4,04 + 4,00 | 23,00 | **€ 27,83** | Supporterspet € 27,77 |
| Snapback FL6089MT + patch 6 cm | 14,96 + 4,04 + 4,00 | 23,00 | **€ 27,83** | idem |
| Flexfit KP908 + patch 6 cm | 22,50 + 4,04 + 4,00 | 30,54 | € 36,95 | — |
| Muts RC027 + patch 6 cm | 3,80 + 4,04 + 3,50 | 11,34 | **€ 13,72** | Supportersmuts € 24,14 |
| Thinsulate-muts B447 + patch 6 cm | 5,86 + 4,04 + 3,50 | 13,40 | € 16,21 | idem |
| Unisex Hoodie Eco + patch 10 cm (borst) | 33,73 + 6,23 + 4,00 | 43,96 | **€ 53,19** | Supportershoodie € 50,00 |

De bedragen landen vlak bij wat de shop vandaag al vraagt voor een fanshop-pet en -hoodie: geloofwaardig, geen dumping, geen
woekerprijs. De borduurkaart komt er één keer per bestelling bij, of de klant nu 1 of 5 producttypes kiest [gemeten].

### 3c. Nieuwe producten (laser in eigen huis) [schatting, eigenaar bevestigt inkoop]

| Product | Kostprijs (schatting) | Voorstel excl. | **Incl.** | Staffel | Instelkosten |
|---|---|---|---|---|---|
| Lederen sleutelhanger ± 6 × 4 cm (kunstleder), gelaserd ontwerp, ring | blanco € 0,80–2,00 + 2–3 min laser + ring ≈ € 3–4 | 7,00 | **€ 8,47** | patchstaffel 5/10/15/20 % | geen (vector bestaat al uit de studio) |
| Echt leder i.p.v. kunstleder | + € 0,80 | + 1,50 | + € 1,82 | | |
| Naam per stuk op de achterkant (lijst plakken, 1 regel) | + 1 min laser | + 1,00 | + € 1,21 | | |
| Lederen tassenlabel ± 10 × 6 cm met riempje, naam achterop inbegrepen | ≈ € 4–5 | 9,50 | **€ 11,50** | idem | geen |
| Lederen patch ± 7 × 5 cm (kunstleder, gelaserd), los | ≈ € 1,50–2 | 4,50 | € 5,45 | idem | geen |
| → op trucker/snapback genaaid | 14,96 + 4,50 + 4,00 | 23,46 | **€ 28,39** | | |
| Houten sleutelhanger, gegraveerd (alternatief onder 50 st.) | ≈ € 1,50–2,50 | 5,50 | € 6,66 | idem | geen |
| Schaduwdoos 20 × 20 cm (patch ≤ 10 cm) + gelaserd alu-plaatje (2 regels, bv. naam + datum) | lijst € 6–12 + plaatje € 1 + 10 min montage | 24,75 | **€ 29,95** | geen | geen |
| Schaduwdoos 25 × 25 cm (patch 12 cm) + plaatje | lijst € 8–15 + idem | 28,92 | € 34,99 | geen | geen |
| Houten lijst 20 × 20 met tekst in het hout gegraveerd (geen plaatje) | lijst € 5–8 + laser | 20,62 | € 24,95 | geen | geen |

De patch in het kader wordt gewoon als patch aangerekend (en telt mee in de staffel).
Marktreferentie: lederen-patch-petten op Etsy rond € 17–26 per stuk [bron]; onze € 28,39 is hoger maar lokaal, vanaf 1 stuk,
met proef. Wil de eigenaar onder het Etsy-niveau zitten: aanbrengen € 3,00 en lederen patch € 3,75 → € 26,36.

### 3d. PVC-sleutelhanger (leverancier, min. 50) [schatting]

| | Excl. | Incl. |
|---|---|---|
| Basis ± 5 cm, 2D, met ring | 4,20 | 5,08 |
| 50–99 st. (PVC-staffel −15 %) | 3,57 | **4,32** |
| 100+ st. (−20 %) | 3,36 | **4,07** |
| Mal | 80,00 | 96,80 |
| Mal als dezelfde PVC-patch (zelfde vorm/maat) in deze bestelling zit **én** `sharedMold: true` | 0 | 0 |

Ter vergelijking: een Amerikaanse fabrieksprijs is ± $ 1,65 bij 50 en $ 1,37 bij 100 stuks [bron]; wij verkopen lokaal met
proef, Belgische factuur en service, maar boven ± € 5 incl. per stuk wordt het moeilijk te verdedigen. Levertijd ± 4 weken zoals
de PVC-patch [gemeten] — dat moet bij de kaart staan.

### 3e. Wat het kan opbrengen (rekenvoorbeeld, geen belofte) [schatting]

Een club bestelt 25 geborduurde patches 8 cm (± € 141 incl. + borduurkaart). Neemt ze 10 truckers met dezelfde patch erbij:
+ 10 × ± € 27 ≈ € 270 omzet, waarvan ± € 4/stuk loon voor naaien en de blanco-marge van de shop. Neemt 1 op 10 particuliere
klanten (1–3 patches) een kader van € 29,95 met een geschatte brutomarge van ± € 12, dan verdient de shop per 100 cadeaukopers
± € 120 extra. Werkelijke cijfers meten (§6) in plaats van aannemen.

---

## 4. Welke kaart wanneer (relevantie, max. 3)

| Situatie (uit de bestelling) | Toon in deze volgorde | Niet tonen |
|---|---|---|
| 1–3 patches (particulier/cadeau) | Kader · Lederen sleutelhanger · Pet | PVC-sleutelhanger (min. 50) |
| 10–49 patches (club, team, bedrijf) | Pet · Hoodie · Muts (okt–feb eerst Muts) | Kader (wel als "1 voor de voorzitter" na de andere) |
| ≥ 50 patches of techniek PVC | Pet · PVC-sleutelhanger · Hoodie | — |
| Naamstrook/ID-patch (nametape-modus) | Muts (fluo/Thinsulate) · Pet | Kader, sleutelhanger met logo |
| Ontwerp is `photo`/`gradient` | Kader · Lederen sleutelhanger (bruintinten-label) · Pet met geprinte/gesublimeerde patch | PVC |

De volgorde volgt de bestelling en de pastoets (§2), nooit de marge (zelfde regel als "Ons advies" in `05-conversie.md` B9).

---

## 5. UX: eerlijk en verleidelijk, niet opdringerig

**Waar.**
1. **Tijdens het ontwerpen: niets verkopen.** De bestaande weergave "Op pet / Op jas" in de studio (STUDIO-REDESIGN) is genoeg
   als zachte inspiratie. Eis nummer 1 is "binnen 2 minuten ontwerpen en bestellen"; extra keuzes onderweg vertragen dat.
2. **Na "In winkelmand": één bevestigingspaneel** (bottom sheet op gsm, kaart rechts op desktop):
   - Kop: "Je patch zit in je winkelmand." Daaronder: "Hetzelfde ontwerp ook op …"
   - Max. 3 kaarten, elk met: mockup van **hun eigen ontwerp** op het product (geen stockfoto), volledige productnaam,
     prijs per stuk **incl. btw** inclusief aanbrengen, 1 zin waarom ("Zelfde patch, op een trucker genaaid"),
     en knop **"Toevoegen"**.
   - Even groot en even zichtbaar: **"Nee, bedankt — naar de winkelmand"**. Geen countdown, geen "nog maar X", geen vooraf
     aangevinkte vakjes (in de EU ook verboden voor extra betalingen, Richtlijn 2011/83/EU art. 22).
3. **In de winkelmand:** één compacte regel "Ook op pet, muts of in een kader?" die het paneel opnieuw opent. Niet in de
   kassa zelf (daar alleen afrekenen).

**Eén klik waar het kan, twee waar het moet.** Pet, muts, sleutelhanger en kader: "Toevoegen" met slimme standaard
(petkleur met het beste contrast met de patchrand via `pickFabric`, zelfde aantal als de patches voor teams of 1 voor
cadeaus) en een klein "wijzig" om kleur/aantal aan te passen. Hoodie heeft altijd een maatkeuze nodig: daar opent de
kaart een kleine maatlijst (meerdere maten met aantallen, voor teams). Kader: 2 tekstregels voor het plaatje, met live
voorbeeld.

**Eerlijke teksten (voorbeelden, NL "je" / FR "vous").**

| Situatie | NL | FR |
|---|---|---|
| Kop | Hetzelfde ontwerp ook op … | Le même design aussi sur … |
| Voordeel fee (enkel als er al een geborduurde patch in de mand zit) | Geen extra borduurkaart: die betaal je maar één keer per bestelling. | Pas de carte de broderie supplémentaire : vous ne la payez qu'une fois par commande. |
| Pet | Je patch, stevig op een trucker genaaid. € 27,83 per stuk. | Votre écusson, solidement cousu sur une casquette trucker. 27,83 € la pièce. |
| Lederen sleutelhanger | Je ontwerp in leder gelaserd, in één bruine tint. | Votre design gravé au laser dans le cuir, en une seule teinte brune. |
| Foto op leder | Een foto wordt een gravure in bruintinten, geen kleurenfoto. | Une photo devient une gravure en tons bruns, pas une photo en couleur. |
| PVC-minimum | Vanaf 50 stuks (vaste mal). Minder nodig? Kies leder of hout, vanaf 1 stuk. | À partir de 50 pièces (moule fixe). Besoin de moins ? Choisissez cuir ou bois, dès 1 pièce. |
| PVC-levertijd | Levertijd ± 4 weken. Wil je de rest van je bestelling eerder? We sturen in twee delen (verzendkosten 1×). [eigenaar] | Délai ± 4 semaines. … |
| Kader | Ingekaderd met een gegraveerd plaatje: een cadeau dat blijft hangen. | Encadré avec une plaquette gravée : un cadeau qui reste. |
| Mockup-label (altijd) | Voorbeeld. Je krijgt een digitale proef vóór productie. | Aperçu. Vous recevez une épreuve numérique avant la production. |
| Weigeren | Nee, bedankt | Non merci |

**Niet doen:** stockfoto's met een ander logo, "meest gekozen" zonder echte cijfers, reviews die niet over dit product gaan,
prijs zonder aanbrengen tonen en die pas in de mand optellen, cross-sell herhalen na "Nee, bedankt" in dezelfde sessie.

**Toegankelijkheid:** paneel is een echte dialog (focus erin, Esc sluit = "Nee, bedankt"), knoppen ≥ 44 px, prijzen als tekst
(niet in de afbeelding), mockup heeft alt-tekst ("Je patch op een zwarte trucker-pet, voorbeeld").

---

## 6. Meten en bijsturen

Per kaarttype loggen (zonder persoonsgegevens): getoond, toegevoegd, later uit de mand verwijderd, besteld. Een kaart die na
200 vertoningen < 1 % toevoegingen haalt, gaat standaard uit (instelbaar). Zo blijft het paneel relevant en kort.

---

## 7. Voorstel voor de configuratie (`config/crosssell.json`, voor de bouwers)

```json
{
  "enabled": true,
  "maxCards": 3,
  "showAfter": "add_to_cart",
  "hideAfterDecline": "session",
  "application": { "cap": 4.00, "beanie": 3.50, "garment_front": 4.00, "garment_back": 5.00,
                   "own_garment": 6.00, "own_garment_leather": 3.00, "tiers": "patch" },
  "items": [
    { "id": "cap", "type": "patch_on_blank", "wcProductIds": [36262, 36278, 36027, 36247],
      "defaultProductId": 36262, "fit": { "maxPatchHeightMm": 65, "suggestSizeCm": 6 }, "zone": "cap_front" },
    { "id": "beanie", "type": "patch_on_blank", "wcProductIds": [36237, 36225, 36209, 36178],
      "defaultProductId": 36237, "fit": { "maxPatchHeightMm": 55, "suggestSizeCm": 6, "maxAspect": 2.2 },
      "noIronOn": [36178], "season": { "boostMonths": [10, 11, 12, 1, 2] } },
    { "id": "hoodie", "type": "patch_on_blank", "wcProductIds": [15590, 16400, 15324],
      "defaultProductId": 15590, "requiresSize": true, "placements": ["chest", "sleeve", "back"] },
    { "id": "leather_keychain", "type": "laser", "material": "leatherette", "priceExcl": 7.00,
      "options": { "real_leather": 1.50, "name_back": 1.00 }, "tiers": "patch",
      "fit": { "minLineMm": 0.5, "minTextMm": 2.5, "minDeltaL": 15, "photo": "grayscale_label" } },
    { "id": "leather_bagtag", "type": "laser", "priceExcl": 9.50, "includesName": true, "tiers": "patch" },
    { "id": "leather_patch_cap", "type": "laser_patch_on_blank", "patchExcl": 4.50, "blank": "cap" },
    { "id": "pvc_keychain", "type": "pvc", "priceExcl": 4.20, "minQty": 50, "qtyStep": 10,
      "moldFeeExcl": 80.00, "sharedMold": false, "leadTime": { "nl": "± 4 weken", "fr": "± 4 semaines" },
      "fit": { "classes": ["flat", "illustration"], "maxColors": 6, "hardMaxColors": 8,
               "minLineMm": 1.0, "hardMinLineMm": 0.6, "minTextMm": 4, "sizeMm": 50 } },
    { "id": "frame", "type": "frame", "sizes": [
        { "id": "20x20", "maxPatchCm": 10, "priceExcl": 24.75 },
        { "id": "25x25", "maxPatchCm": 12, "priceExcl": 28.92 } ],
      "plate": { "lines": 2, "maxChars": 28, "material": "anodized_alu" } }
  ],
  "rules": [
    { "when": { "qtyMax": 3 }, "order": ["frame", "leather_keychain", "cap"] },
    { "when": { "qtyMin": 10, "qtyMax": 49 }, "order": ["cap", "hoodie", "beanie"] },
    { "when": { "qtyMin": 50 }, "order": ["cap", "pvc_keychain", "hoodie"] },
    { "when": { "mode": "nametape" }, "order": ["beanie", "cap"] },
    { "when": { "class": ["photo", "gradient"] }, "order": ["frame", "leather_keychain", "cap"] }
  ]
}
```
Alle Woo-ID's zijn de echte ID's van 17-11-2026; elke nieuwe drager (lederen sleutelhanger, kader) wordt een gewoon
WooCommerce-product dat de eigenaar zelf aanmaakt en in de instellingen koppelt. De ontwerp-ID gaat als cart-item-meta mee,
zodat de productiefiche weet welke patch op welke pet hoort.

## 8. Wat de bouwers nodig hebben (voor `docs/handoff/extra-products-needs.md`)
- **Renderer:** mockup-functie per drager met het echte patchbeeld (`toBlob`), minstens: trucker en snapback voorpaneel (kleur
  per variatie), muts-omslag, hoodie-borst, monochrome lasergravure op leder/hout (`leather` bestaat al), schaduwdoos met plaatje.
- **Studio-UI:** event/hook na "In winkelmand" (`bps:added-to-cart` met design-ID, techniek, maat, aantal, analyse-samenvatting)
  waarop het cross-sell-paneel opent; geen knop in de ontwerpflow zelf.
- **Pricing:** patch in een cross-sell telt mee in de patchpool (`quote` met extra regels) en het aanbrengbedrag krijgt dezelfde
  staffel; de borduurkaart blijft 1× per bestelling.
- **Woo:** add-to-cart van blanco variatie + gekoppelde patchregel als één groep (samen verwijderen), productiefiche toont
  "patch X op FL6606RT Black/White, voorpaneel".

## 9. Extra ideeën met meerwaarde (waar klanten voor betalen)
1. **Opnaaiservice op eigen kleding** (motorclubvesten, werkjassen): klant stuurt kleding op of brengt ze binnen in Kalmthout;
   € 6 excl./patch, leder + € 3. Grote, bestaande vraag in motorclubs en hulpdiensten; geen voorraad nodig.
2. **"Zet dit ontwerp in je clubwinkel"**: de shop heeft al fanshops (KFC Stroomvaart, Boxing Gym). Voor clubs met ≥ 10 patches
   een kaart "Laat leden zelf pet/hoodie met dit logo bestellen" → offerte/aanvraag. Hoogste levenslange waarde van alle ideeën.
3. **Houten of lederen sleutelhanger als alternatief voor PVC onder 50 stuks** (laser, vanaf 1 stuk): lost het eerlijke
   "minimum 50"-nee op met een ja.
4. **Herbestel-knop** in "Mijn account" voor hetzelfde ontwerp op een nieuw product (de site belooft dat de borduurkaart opnieuw
   gebruikt wordt bij herbestelling, maar de kassa heeft daar nog geen mechanisme voor, `02-huidig-aanbod.md` §5) [eigenaar].
5. **Kraamcadeau/jubileum-kader**: de site heeft al een kraamcadeaus-pagina; kader met naam + geboortedatum op het plaatje.

## 10. Bronnen
- Store API producten: https://borduurservicebelgie.be/wp-json/wc/store/v1/products?per_page=100 (17-11-2026)
- https://borduurservicebelgie.be/petten-bedrukken/ · https://borduurservicebelgie.be/laser-graveren/ · https://borduurservicebelgie.be/relatiegeschenken/
- Baymard, product page suggestions: https://baymard.com/blog/product-page-suggestions en
  https://baymard.com/blog/product-page-suggestions-information · cart recommendations: https://baymard.com/blog/product-recommendations-cart
- PVC-sleutelhangerprijzen en mal bij herbestelling (VS): https://alchemymerch.com/products/pvc-keychains · https://v2.4over4.com/product/pvc-keychains
- Lederen patch-petten (prijsniveau Etsy): https://www.etsy.com/market/solid_hat_patch_borders · lederen patches: https://www.realthread.com/patches/leather
- Richtlijn 2011/83/EU art. 22 (geen vooraf aangevinkte extra betalingen).
- Interne research: `docs/research/02-huidig-aanbod.md`, `04-patch-techniek.md`, `05-conversie.md`.
