# SPEC-EXTRA — Naamstroken-modus en Cross-sell (v1.0, bindend voor de bouwers "nametape" en "crosssell")

Architect, 17-11-2026. Bronnen: `docs/BRIEF.md` (EXTRA PRODUCTEN 17-11, WENSEN 17-11), `docs/SPEC.md` (contracten §4, §6, §7, §11),
`docs/STUDIO-REDESIGN.md` (harde gsm-eis), `docs/extra/01-naamstroken.md` (markt + prijsvoorstel), `docs/extra/02-cross-sell.md`
(pastoetsen, prijzen, UX), `docs/research/01-merk-en-site.md`, `02-huidig-aanbod.md`.

> **In het kort**
> - **Naamstroken**: eigen pagina/modus (`[patch_studio_naamstroken]`), **3 schermpjes** op gsm (Namen → Stijl → Controle), lijst plakken
>   of CSV, live voorbeeld per persoon, passingstoets die in JS én PHP **identiek** rekent (tabel met letterbreedtes), **één
>   winkelmandregel per lijst**, prijs altijd op de server. Geen borduurkaart voor tekst.
> - **Cross-sell**: niets verkopen tijdens het ontwerpen. **Na "In winkelmand"** één paneel met max. 3 kaarten (pet, muts, hoodie,
>   lederen sleutelhanger/tassenlabel, kader, PVC-sleutelhanger), alleen wat de **server-pastoets** goedkeurt, met een **eigen lichte
>   canvas-mockup** van het echte patchbeeld, volledige prijs incl. btw en een even grote "Nee, bedankt".
> - Elke drager is een **gewoon WooCommerce-product**; prijzen van blanco's komen uit WooCommerce, toeslagen uit de instellingen.
> - Twee modules, elk volledig in eigen mappen. Wat in bestaande code nodig is, staat in `docs/handoff/extra-products-needs.md`.

Labels: **[eigenaar]** = standaardwaarde gekozen, eigenaar bevestigt in de instellingen. Klantteksten: Vlaams NL met "je", FR met "vous".
Eerlijkheid (BRIEF): geen nep-reviews, geen "meest gekozen" zonder cijfers, geen aftelklokken, geen vooraf aangevinkte betalende opties
(Richtlijn 2011/83/EU art. 22).

---

## 0. Beslissingen (lees eerst)

| # | Beslissing | Waarom |
|---|---|---|
| D1 | Naamstroken is een **aparte, lichte module** met eigen shortcode, bundel en renderer, niet een paneel in de grote studio. De studio linkt ernaar ("Alleen een naam of ID?"). | Leek in < 2 min klaar; geen upload/analyse nodig; bundel klein; geen conflict met het studio-team. |
| D2 | Eén lijst = **één stijl + één formaat**; namen verschillen per regel. Ander formaat = nog een lijst (tweede winkelmandregel). | Eén beslissing per scherm; productie werkt ook per stof/formaat. |
| D3 | Passingstoets (letterhoogte/lengte) via een **tabel met letterbreedtes per lettertype** (`config/nametape-metrics.json`), zelfde algoritme in JS en PHP, getest met gedeelde vectors. Het canvas-voorbeeld tekent met het echte lettertype op de berekende hoogte. | Client en server zijn het altijd eens; de server meet geen fonts. |
| D4 | Winkelmand: naamstrooklijst = **1 regel, aantal 1 ("1 lijst")**, prijs = absolute lijnprijs excl. btw die de server berekent. Aantal in de mand is vergrendeld; wijzigen gaat via "Namen wijzigen". | Eén regel per lijst (01 §7.6), geen afrondingsverschil per stuk, staffel over alle namen samen. |
| D5 | Naamstroken tellen mee in de **patchstaffel** (pool) en omgekeerd, instelbaar (`tiers.pooledWithPatches`, standaard aan). | 01 §9 advies "ja", voorkomt verwarring. |
| D6 | Cross-sell = **de patch zelf, op de drager genaaid** (nooit direct op de pet borduren). Elke cross-sell is een **eigen winkelmandregel** gekoppeld via `design_id` (niet via de mandsleutel): de klant mag de losse patches verwijderen en enkel petten houden. | 02 §2 eerlijkheidsregel; geen verrassende verwijderingen. |
| D7 | Pastoets en prijzen van cross-sell worden **op de server** berekend uit de opgeslagen `DesignPayload` (bevat `analysis`, maten, techniek, garens). De JS-tweeling bestaat alleen voor de dev-pagina en tests. | Klant kan niets vervalsen; één bron. |
| D8 | Mockups = **eigen vector-canvas** in `assets/src/crosssell/mockups.js` met de echte preview-PNG van het ontwerp. De renderer van het studio-team is optioneel (handoff), nooit een voorwaarde. | Geen afhankelijkheid tussen teams; klein en snel. |
| D9 | Instellingen in **eigen opties** (`bps_nametape_settings`, `bps_crosssell_settings`) bovenop eigen JSON-configs, met eigen beheerpagina's onder WooCommerce. | Bestaande `Settings`/`defaults.json` blijven onaangeroerd. |
| D10 | Elke module registreert zich als component (`BorduurPatchStudio\Nametape\Module`, `BorduurPatchStudio\Crosssell\Module`), autoloader vindt ze al (`includes/nametape/class-module.php`). Enkel het toevoegen aan de componentenlijst is een handoff. | SPEC §4.1. |

---

## 1. Naamstroken-modus

### 1.1 Wat de klant kan
Naamstroken en kleine ID-patches (naam, regel 2 = functie/rang/afdeling, bloedgroep) op klittenband (haakzijde, optioneel
lus-tegenstuk), opnaai of opstrijk. Formaten 10 × 2,5 (standaard), 12 × 3, 8 × 5, 5 × 2,5 en "andere maat". Stofkleuren, letterkleur,
lettertype, rand. Eén naam typen of een hele lijst plakken / CSV kiezen. Live voorbeeld per persoon, prijs per stuk met staffel.

Niet in v1 (eerlijk weggelaten, in de UI niet tonen): IR-reflecterend (alleen tekstregel "op aanvraag voor professionelen" als
`irOnRequest` aan staat), vlaggen (nog geen gedigitaliseerde bibliotheek: `flags.enabled:false`), eigen logo op 8 × 5 (→ link "Logo
erbij? Ontwerp je ID-patch in de studio").

### 1.2 Flow: max. 3 schermpjes op gsm

Vaste lay-out op 390 × 844 en 360 × 740 (zelfde harde eis als STUDIO-REDESIGN):
- **Voorbeeld bovenaan, sticky, 30–38 % van `visualViewport.height`**: de strook van de *geselecteerde* persoon op ware verhouding op
  een zachte stofachtergrond, met eronder in klein: "PEETERS J. · 1 van 24 · ‹ ›" (vorige/volgende persoon).
- **Midden**: inhoud van het actieve scherm; alleen dit vlak scrollt.
- **Onder, vast** (56 px): links "24 stroken · € 4,90/st" (incl. btw), rechts de hoofdknop van het scherm ("Verder" / "In winkelmand").
- Een stappenbalkje bovenaan het middenvlak: `1 Namen · 2 Stijl · 3 Controle` (aanklikbaar terug, nooit vooruit springen voorbij een fout).
- Toetsenbord open: voorbeeld krimpt mee (min. 18 % van `visualViewport.height`), wordt nooit weggeduwd; `document.scrollingElement.scrollTop` blijft 0.

```
┌──────────────────────────────┐
│  ▓▓▓▓▓ PEETERS J. ▓▓▓▓▓      │ ← sticky voorbeeld (canvas)
│  PEETERS J. · 1/24   ‹  ›    │
├──────────────────────────────┤
│ 1 Namen · 2 Stijl · 3 Contr. │
│ Naam op je strook            │
│ [ PEETERS J.              ]  │  ← scherm 1
│ + Meerdere namen? Plak lijst │
│ + Regel 2 / bloedgroep       │
├──────────────────────────────┤
│ 1 strook · € 5,45/st [Verder]│ ← vaste balk
└──────────────────────────────┘
```

**Scherm 1 — Namen** ("Wie krijgt een naamstrook?")
- Eén groot veld "Naam op je strook" met placeholder `PEETERS J.`; typen = live in het voorbeeld (debounce 0, render ≤ 16 ms).
- Knop **"Meerdere namen? Plak je lijst"** opent een tekstvak (8 regels hoog) met uitleg "Eén persoon per regel. Kopieer gerust uit
  Excel." en een tweede knop **"Kies een CSV-bestand"** (`<input type=file accept=".csv,.txt,text/csv">`). Na plakken/kiezen verschijnt
  meteen de lijst (zie §1.3) als compacte rijen: naam · regel 2 · bloedgroep · aantal, elk met statusbolletje.
- Inklapper **"Regel 2 of bloedgroep toevoegen"** (enkel bij één naam): veld "Functie, rang of afdeling" en keuzelijst bloedgroep
  (8 groepen, notatie volgens instelling: `O POS` of `O+`). Bij een lijst komen deze uit de kolommen.
- "Hoofdletters" schakelaar (standaard aan, `uppercaseDefault`).
- Verder → scherm 2. Lege lijst = knop uitgeschakeld met tekst "Typ minstens één naam".

**Scherm 2 — Stijl** ("Hoe ziet je strook eruit?")
Volgorde = belangrijkste eerst, alles met slimme standaard, elke keuze direct in het voorbeeld:
1. **Formaat**: 4 kaarten met tekening op ware verhouding + prijs incl. btw (10 × 2,5 voorgeselecteerd, of 12 × 3 als een regel 2
   aanwezig is, of 5 × 2,5 als de lijst enkel bloedgroepen bevat). "Andere maat" opent breedte 50–150 mm × hoogte 25/30/50 mm.
2. **Snelle stijlen** (chips, één tik zet stof + letterkleur + rand): "Klassiek zwart/wit", "Navy/wit (hulpdienst)", "Olijf/tan
   (tactisch, ingetogen)", "Coyote/zwart", "Fluo geel/zwart (crew)", "Wit/navy (zorg)". Enkel stoffen met `inStock:true`.
3. **Stofkleur** (swatches) · **Letterkleur** (8–10 swatches, met contrastwaarschuwing tenzij de stijl `subdued` is) ·
   **Rand** (geborduurde rand in letterkleur / andere randkleur / zonder rand).
4. **Lettertype** (4–5 tegels met de echte naam erin geschreven).
5. **Achterkant**: "Klittenband (haakzijde)" standaard · "Opnaaien" · "Opstrijken"; vinkje (niet vooraf aangevinkt) "+ zacht
   tegenstuk om op je kledij te naaien (+ € 1,21/st)". Uitleg-icoon: "Je jas of vest heeft het zachte deel? Dan heb je de haakzijde nodig."
6. Bij bloedgroepen in de lijst: **"Bloedgroep"**: "Op een eigen strook van 5 × 2,5 cm" (standaard bij formaten met 1 regel) /
   "Op regel 2" (alleen formaat met ≥ 2 regels en geen functie ingevuld) / "Achter de naam". Prijs van de eigen strook staat erbij.
Op gsm: groepen 3–5 zitten in een inklapper "Meer stijlopties" (eerste keer dicht), zodat scherm 2 zonder scrollen bruikbaar is.

**Scherm 3 — Controle & bestellen** ("Alles goed?")
- Raster/lijst van alle personen, elk met mini-voorbeeld (canvas 240 px breed, lazy via IntersectionObserver), status en aantal-stepper.
- Rode/oranje rijen bovenaan met een **één-klik-oplossing** (§1.5). Tik op een rij = die persoon in het grote voorbeeld + inline bewerken.
- Samenvatting: "24 naamstroken 10 × 2,5 cm · zwart / wit · klittenband (haak)" + prijsblok: per stuk, staffel ("10 % staffelkorting
  vanaf 25 stuks" / "Nog 1 strook en je krijgt 10 %"), eventuele instelkost, totaal incl. btw, verzending-schatting.
- Verplichte rechtenverklaring (zelfde tekstversie als de studio, `legal.rightsTextVersion`) en de maatwerk-melding.
- Bij een controlewoord (§1.5.4): melding "We kijken dit even na voor we borduren. We nemen contact op als we vragen hebben." (geen blokkade).
- Belofte uit de config, enkel als ingevuld: digitale proef ("Je krijgt een digitale proef met alle namen voor we borduren").
- Knop **"In winkelmand"** (uitgeschakeld zolang er een rode rij is; tekst eronder zegt waarom).

### 1.3 Bulk-invoer (plakken en CSV)

Pure functie `parseList(text, {source:'paste'|'csv', config}) → {rows: Row[], header: string[]|null, delimiter, warnings: Msg[]}` in
`assets/src/nametape/parse.js` (enkel client; de server krijgt al gestructureerde rijen en valideert opnieuw, §1.6).

1. Normaliseer: BOM weg, `\r\n`/`\r` → `\n`, Unicode NFC, niet-afdrukbare tekens weg, dubbele spaties → één, trim. Lege regels en regels
   die met `#` beginnen overslaan.
2. **Scheidingsteken**: bevat een regel een tab → tab (plakken uit Excel/Sheets); anders `;`; anders (alleen bij CSV-bestand) `,` als
   ≥ 80 % van de regels hetzelfde aantal komma-kolommen heeft; anders één kolom. Velden tussen `"…"` respecteren (`""` = aanhalingsteken).
3. **Koptekst**: eerste rij is koptekst als een cel (hoofdletterongevoelig, zonder accenten) een sleutelwoord is:
   naam/name/nom/achternaam · functie/fonction/rang/grade/afdeling/service/regel 2/ligne 2 · bloedgroep/blood/groupe sanguin/bt ·
   aantal/qty/quantite/stuks/pieces. Kolommen worden dan op naam toegewezen.
4. **Zonder koptekst**, per kolom op inhoud: kolom 1 = naam; een kolom waar ≥ 60 % van de gevulde cellen een bloedgroep is
   (`^(A|B|AB|O|0)\s*(POS|NEG|POSITIEF|NEGATIEF|\+|-|−)$`i, ook met voorvoegsel `BT:`/`BG:`) = bloedgroep; een kolom met enkel gehele
   getallen 1–99 = aantal; de rest = regel 2 (eerste kolom die overblijft; meer kolommen → waarschuwing "Kolom 4 en verder negeren we").
5. Bloedgroep normaliseren naar `{group:'O'|'A'|'B'|'AB', rh:'+'|'-'}`; `0` → `O`. Weergave via `bloodNotation` (`"O POS"` of `"O+"`).
6. `Row = { id, name, line2, blood: {group, rh}|null, qty (1–99, standaard 1), raw }`. Max `maxRows` (250); daarboven: rijen afkappen +
   melding "Meer dan 250 namen? Vraag een offerte, dan maken we het samen in orde." met link naar de offertepagina.
7. **Dubbels** (zelfde naam + regel 2, hoofdletterongevoelig): info "2× dezelfde naam: klopt dat?" met knop "Samenvoegen (aantal 2)".
8. Na plakken: de lijst vervangt de vorige niet stil; als er al ≥ 2 rijen zijn: keuze "Vervangen" / "Toevoegen".
9. XLSX: niet ondersteund (geen bibliotheek). Bij `.xlsx` gekozen: "Kopieer de cellen in Excel en plak ze hier, of bewaar als CSV."

### 1.4 Formaten, stoffen, lettertypes: config `config/nametape.json`

Structuur (bouwer vult waarden aan; sleutels zijn bevroren na v1). Alle bedragen **excl. btw**.

```json
{
  "schema": 1,
  "enabled": true,
  "formats": [
    { "id": "10x2.5", "w": 100, "h": 25, "lines": 1, "priceExcl": 4.50, "default": true, "label": { "nl": "10 × 2,5 cm", "fr": "10 × 2,5 cm" }, "hint": { "nl": "Standaard naamstrook", "fr": "Bande nominative standard" } },
    { "id": "12x3",   "w": 120, "h": 30, "lines": 2, "priceExcl": 5.00 },
    { "id": "8x5",    "w": 80,  "h": 50, "lines": 3, "priceExcl": 5.80 },
    { "id": "5x2.5",  "w": 50,  "h": 25, "lines": 1, "priceExcl": 3.00, "bloodFormat": true }
  ],
  "customSize": { "enabled": true, "minW": 50, "maxW": 150, "heights": [25, 30, 50],
                  "price": [ { "maxW": 50, "maxH": 25, "priceExcl": 3.00 }, { "maxW": 100, "maxH": 25, "priceExcl": 4.50 },
                             { "maxW": 120, "maxH": 30, "priceExcl": 5.00 }, { "maxW": 150, "maxH": 30, "priceExcl": 5.50 },
                             { "maxW": 150, "maxH": 50, "priceExcl": 6.50 } ] },
  "fabrics": [
    { "id": "black", "hex": "#1c1c1c", "label": { "nl": "Zwart", "fr": "Noir" }, "inStock": true, "surchargeExcl": 0 },
    { "id": "navy", "hex": "#1b2a4a" }, { "id": "olive", "hex": "#4b5320" }, { "id": "coyote", "hex": "#8d7350" },
    { "id": "grey", "hex": "#6e6f71" }, { "id": "white", "hex": "#f4f4f2" }, { "id": "red", "hex": "#b3202a" },
    { "id": "camo", "pattern": "camo-woodland", "label": { "nl": "Camo (meerkleurig)", "fr": "Camo (multicolore)" }, "inStock": false, "surchargeExcl": 0.50 },
    { "id": "fluo-yellow", "hex": "#d7f20f", "surchargeExcl": 0.25, "note": { "nl": "Fluo-stof, geen gecertificeerde signalisatie.", "fr": "Tissu fluo, pas de signalisation certifiée." } },
    { "id": "fluo-orange", "hex": "#ff6a13", "surchargeExcl": 0.25 },
    { "id": "reflective", "hex": "#c9cbcc", "pattern": "reflective", "surchargeExcl": 1.50, "inStock": false,
      "note": { "nl": "Reflecteert koplampen. Geen nachtzicht/IR en geen gecertificeerde signalisatie.", "fr": "Réfléchit la lumière des phares. Pas d'IR/vision nocturne, pas de signalisation certifiée." } }
  ],
  "letterColors": [
    { "id": "white", "hex": "#ffffff", "threadCode": "" }, { "id": "black", "hex": "#111111" }, { "id": "gold", "hex": "#d4a017" },
    { "id": "red", "hex": "#c1121f" }, { "id": "silver", "hex": "#a7a9ac" }, { "id": "tan", "hex": "#c2a878" },
    { "id": "olive", "hex": "#5b6236" }, { "id": "navy", "hex": "#1b2a4a" }, { "id": "fluo-yellow", "hex": "#d7f20f" }
  ],
  "presets": [
    { "id": "classic", "fabric": "black", "letter": "white", "border": "satin_letter", "label": { "nl": "Klassiek zwart/wit", "fr": "Classique noir/blanc" } },
    { "id": "rescue", "fabric": "navy", "letter": "white", "border": "satin_letter" },
    { "id": "subdued_olive", "fabric": "olive", "letter": "tan", "border": "satin_letter", "subdued": true },
    { "id": "coyote", "fabric": "coyote", "letter": "black", "border": "satin_letter", "subdued": true },
    { "id": "crew", "fabric": "fluo-yellow", "letter": "black", "border": "satin_letter" },
    { "id": "care", "fabric": "white", "letter": "navy", "border": "satin_letter" }
  ],
  "fonts": [
    { "id": "block",     "family": "Sora",        "file": "sora-700.woff2",        "weight": 700, "label": { "nl": "Blokletter", "fr": "Capitales bâton" }, "default": true },
    { "id": "condensed", "family": "Oswald",      "file": "oswald-600.woff2",      "weight": 600, "label": { "nl": "Smal (lange namen)", "fr": "Étroite (noms longs)" } },
    { "id": "college",   "family": "Graduate",    "file": "graduate-400.woff2",    "weight": 400, "label": { "nl": "College", "fr": "College" } },
    { "id": "serif",     "family": "Roboto Slab", "file": "roboto-slab-700.woff2", "weight": 700, "label": { "nl": "Klassiek", "fr": "Classique" } },
    { "id": "script",    "family": "Pacifico",    "file": "pacifico-400.woff2",    "weight": 400, "label": { "nl": "Schrijfletter", "fr": "Manuscrite" }, "script": true, "forceMixedCase": true }
  ],
  "borders": [
    { "id": "satin_letter", "borderMm": 2.0, "label": { "nl": "Geborduurde rand (letterkleur)", "fr": "Bord brodé (couleur du texte)" }, "default": true },
    { "id": "satin_color",  "borderMm": 2.0, "label": { "nl": "Geborduurde rand (andere kleur)", "fr": "Bord brodé (autre couleur)" } },
    { "id": "none",         "borderMm": 0,   "label": { "nl": "Zonder rand (strak gesneden)", "fr": "Sans bord (découpe nette)" } }
  ],
  "backings": [
    { "id": "hook", "deltaExcl": 0, "default": true, "label": { "nl": "Klittenband (haakzijde)", "fr": "Velcro (côté crochets)" } },
    { "id": "sew",  "deltaExcl": -0.75, "label": { "nl": "Opnaaien", "fr": "À coudre" } },
    { "id": "iron", "deltaExcl": -0.25, "label": { "nl": "Opstrijken", "fr": "Thermocollant" } }
  ],
  "loopCounterpart": { "enabled": true, "priceExcl": 1.00, "onlyWith": ["hook"] },
  "fit": { "padMm": 1.5, "lineGapRatio": 0.35, "maxCapMm": 13, "minCapMm": 6, "absMinCapMm": 4,
           "scriptMinCapMm": 7, "scriptAbsMinCapMm": 5, "maxCharsPerLine": 30 },
  "text": { "uppercaseDefault": true, "allowedPattern": "^[\\p{L}\\p{M}\\p{N} .,'’\\-/&+#()]+$" },
  "blood": { "notation": "O POS", "separateFormat": "5x2.5", "defaultMode": "auto" },
  "tiers": { "source": "patch", "pooledWithPatches": true },
  "smallOrderFee": { "enabled": false, "belowQty": 5, "amountExcl": 7.50, "label": { "nl": "Instelkost naamstroken (onder 5 stuks)", "fr": "Frais de mise en route bandes nominatives (moins de 5 pièces)" } },
  "maxRows": 250,
  "reviewWords": ["POLITIE", "POLICE", "POLIZEI", "LOKALE POLITIE", "FEDERALE POLITIE", "MILITAIRE POLITIE", "DOUANE", "DOUANES", "VIGILIS", "MARECHAUSSEE", "KONINKLIJKE MARECHAUSSEE"],
  "irOnRequest": false,
  "flags": { "enabled": false },
  "promises": { "proof": "", "leadTime": "" },
  "woo": { "productName": { "nl": "Naamstroken op maat", "fr": "Bandes nominatives sur mesure" }, "categorySlug": "naamstroken", "shippingClassSlug": "", "pageId": 0 }
}
```

Regels:
- `inStock:false` → niet tonen (ook niet in presets: preset valt dan weg). Camo en reflecterend staan standaard uit [eigenaar].
- Niet "MultiCam" of "Velcro" noemen in productteksten (merken). FR-shoptekst: "scratch"/"bande auto-agrippante"; "velcro" mag in uitleg.
- `script`-lettertype: geen hoofdletter-transformatie (`forceMixedCase`), strengere letterhoogte.
- Stencil/militair lettertype pas toevoegen als de eigenaar zegt welke borduurlettertypes de software heeft [eigenaar]; dan een
  OFL-woff2 in `assets/fonts/` via handoff (ui-eigendom).
- `config/nametape-metrics.json` (gegenereerd door de nametape-bouwer met een script in `tests/nametape/`, dat elk woff2-bestand in
  Playwright/canvas meet): per font-id `{ "capHeightEm": number, "adv": { "A": em, …, "Z", "a"…"z", "0"…"9", " ", ".", ",", "'", "-", "/", "&", "+", "#", "(", ")" }, "fallbackEm": number }`.
  Letters met accent gebruiken de basisletter (NFD, combinerende tekens weg); onbekend teken → `fallbackEm`.

### 1.5 Validatie

#### 1.5.1 Passingstoets (pure functie, JS = PHP)
`fitText({ lines: string[], fontId, format: {w,h,lines}, borderId, uppercase }, cfg, metrics) → FitResult`

1. Tekst per regel: uppercase toepassen (tenzij `forceMixedCase`), trim. Lege regel 2 telt niet als regel.
2. `inset = border.borderMm + fit.padMm`; `innerW = w − 2·inset`; `innerH = h − 2·inset`.
3. `n` = aantal niet-lege regels. `n > format.lines` → status `error`, code `too_many_lines`.
4. Regelhoogte: `capMaxH = min( fit.maxCapMm, innerH / (n + (n−1)·fit.lineGapRatio) )`.
5. Per regel: `em = Σ adv(teken)`; `capMaxW = innerW · capHeightEm / em` (breedte bij cap 1 mm = `em / capHeightEm`).
6. `capMm = round1( min(capMaxH, min over regels van capMaxW) )` — alle regels dezelfde hoogte. `widthMm` per regel = `capMm · em / capHeightEm`.
7. Grenzen: `min = script ? scriptMinCapMm : minCapMm`, `abs = script ? scriptAbsMinCapMm : absMinCapMm`.
   `capMm ≥ min` → `ok`; `abs ≤ capMm < min` → `warn` (`small_text`); `capMm < abs` → `error` (`too_long`).
   Tekens > `maxCharsPerLine` → `error` (`too_many_chars`); tekens buiten `allowedPattern` → `error` (`bad_chars`, met de tekens).
8. Resultaat: `{ status: 'ok'|'warn'|'error', codes: string[], capMm, widthsMm: number[], fixes: Fix[] }`.
9. **Fixes** (berekend door `fitText` opnieuw te draaien, enkel tonen wat het probleem echt oplost, in deze volgorde):
   `use_font:condensed` → "Kies smalle letter" · `use_format:<id>` (kleinste groter formaat dat `ok` geeft) → "Kies 12 × 3 cm" ·
   `split_lines` (alleen als het formaat ≥ 2 regels heeft en de naam een spatie heeft; splitsen op de spatie die de langste regel het
   kortst maakt) → "Zet op 2 regels" · `edit` → "Naam inkorten" (opent het veld). Fixes die **de hele lijst** raken (lettertype, formaat)
   tonen het aantal rijen dat daardoor in orde komt: "Kies smalle letter (lost 3 namen op)".
- Afronding: `round1` = `roundHalfUp(x, 1)` uit `shared/money.js` (PHP: eigen tweeling, zelfde formule als SPEC §6.3).
- Vectors: `tests/nametape/fit-vectors.json` (min. 20: korte/lange namen, accenten, script, 2 regels, rand/geen rand, elk formaat,
  grensgevallen precies op 6,0 en 4,0 mm, verboden tekens). Node-test en `tests/nametape/run-php.php` vergelijken exact.

#### 1.5.2 Regel 2 en bloedgroep
- Regel 2 bij een formaat met 1 regel → `error` `line2_no_room` met fixes `use_format:12x3` en `drop_line2` ("Regel 2 weglaten").
- Bloedgroep-modus `auto`: formaat ≥ 2 regels én geen regel 2 → `line2`; anders `separate` (eigen 5 × 2,5-strook, zelfde stijl, telt
  als extra stuk in prijs en staffel). `inline` = achter de naam met een spatie (telt mee in de passingstoets).
- De eigen bloedgroepstrook wordt met dezelfde `fitText` gecontroleerd (kan in de praktijk niet falen; toch testen).
- Disclaimer onder de bloedgroepkeuze: "Medische gegevens op kledij zijn jouw keuze; wij controleren ze niet." /
  "Les données médicales sur les vêtements relèvent de votre choix ; nous ne les vérifions pas."

#### 1.5.3 Contrast
Letterkleur vs. stof: `contrastRatio` (shared/color-math.js) < 2,0 en preset niet `subdued` → oranje melding "Weinig contrast: je naam is
van ver moeilijk te lezen." met fix "Kies wit" / "Kies zwart" (wat het hoogste contrast geeft). Geen blokkade.

#### 1.5.4 Controlewoorden
Woorden uit `reviewWords` (heel woord, hoofdletterongevoelig, zonder accenten) in naam of regel 2 → rij krijgt vlag `review`. Niet
blokkeren; melding in scherm 3 (zie §1.2) en vraag "Voor welke organisatie zijn deze stroken?" (vrij tekstveld, optioneel, gaat mee als
`orgNote`). Server zet de vlag opnieuw en schrijft een ordernotitie "Manuele controle nodig: bevat 'POLITIE' (rij 3)". Klanttekst bij de
lijst: "Werk je voor een dienst met kledijvoorschriften? Check de regels van je organisatie, wij borduren wat je opgeeft."

### 1.6 Payload (client → server)

```js
/** @typedef {Object} NametapeBatch
 * @property {1} v
 * @property {string} locale                       'nl-BE' | 'fr-BE'
 * @property {string} format                       format-id of 'custom'
 * @property {{w:number,h:number}|null} custom     enkel bij 'custom' (mm; h ∈ customSize.heights)
 * @property {string} fabric                       fabric-id (inStock)
 * @property {string} letter                       letterColor-id
 * @property {string} border                       border-id
 * @property {string|null} borderColor             letterColor-id, enkel bij 'satin_color'
 * @property {string} font                         font-id
 * @property {boolean} uppercase
 * @property {string} backing                      backing-id
 * @property {boolean} loop                        lus-tegenstuk (enkel bij 'hook')
 * @property {'line2'|'separate'|'inline'|null} bloodMode
 * @property {{name:string, line2:string, blood:{group:'O'|'A'|'B'|'AB', rh:'+'|'-'}|null, qty:number}[]} rows   1..maxRows
 * @property {string} [orgNote]                    ≤ 200 tekens
 * @property {{totalIncl:number}} priceClient      enkel ter controle
 * @property {{v:string, accepted:true}} rightsConsent
 */
```
Server (`Nametape\Validator`, puur): whitelist van alle enums uit de (gemergde) config, `inStock`, getallen begrensd (`qty` 1–99,
som ≤ 2000), tekst NFC + `allowedPattern` + lengte, `bloodMode` geldig voor het formaat, dan `fitText` per rij: een rij met `error`
→ 400 `bps_nt_invalid` met `data.rows = [{index, codes}]`; `warn` is toegestaan. Controlewoorden opnieuw bepalen.

### 1.7 Prijs (server rekent, JS-tweeling voor live weergave)

`Nametape\Pricing::quote( array $batch, array $ctx, array $cfg ): array` en `quote(batch, ctx, cfg)` in `assets/src/nametape/pricing.js`.
`ctx = { patchPoolQty, otherNametapePieces, otherItemsInclVat, vatRate, tiers: config.tiers, shipping: config.shipping, locale }`
(de hoofdconfig levert `vat`, `tiers`, `shipping`; in de browser zit die in de public config van de studio, zie §1.9).

1. `fmt` = gekozen formaat; bij `custom`: eerste `customSize.price`-rij met `w ≤ maxW` en `h ≤ maxH` (anders error `size_out_of_range`).
2. `deltas = fabric.surchargeExcl + backing.deltaExcl + (loop ? loopCounterpart.priceExcl : 0)`.
3. `mainUnit = fmt.priceExcl + deltas`; `mainPieces = Σ rows.qty`.
4. `bloodPieces = bloodMode==='separate' ? Σ qty van rijen met blood : 0`; `bloodUnit = format(blood.separateFormat).priceExcl + deltas`.
5. `pieces = mainPieces + bloodPieces`. `tierQty = pieces + otherNametapePieces + (tiers.pooledWithPatches ? patchPoolQty : 0)`.
   `tier` = hoogste stap van `config.tiers.steps` met `min ≤ tierQty`.
6. `mainUnitExcl = round4(mainUnit × (1 − d))`, `bloodUnitExcl = round4(bloodUnit × (1 − d))`.
   `lineExcl = round2( round2(mainUnitExcl × mainPieces) + round2(bloodUnitExcl × bloodPieces) )`, `lineTax = round2(lineExcl × vat)`,
   `lineIncl = round2(lineExcl + lineTax)`, `unitIncl = round2(mainUnitExcl × (1 + vat))` (weergave per stuk).
7. Instelkost: `smallOrderFee.enabled` en `pieces + otherNametapePieces < belowQty` → fee `{key:'fee:nametape_setup', excl, tax, incl}`.
   Geen borduurkaart (tekst in standaard borduurlettertype vraagt geen digitalisering).
8. Verzending (schatting, zelfde regel als SPEC §6.3 stap 10) op `lineIncl + otherItemsInclVat`.
9. `nextTier` = eerste stap met `min > tierQty`: `{min, needed: min − tierQty, unitIncl}` → "Nog 1 strook en je betaalt € 4,90/st".
10. Output `NametapeQuote = { ok, errors[], pieces, mainPieces, bloodPieces, tier:{min,discount}, mainUnitExcl, bloodUnitExcl,
    unitIncl, lineExcl, lineTax, lineIncl, fees[], feesIncl, shippingIncl, totalIncl, perPieceAllInIncl, nextTier|null, breakdown[] }`
    met labels in `locale`.
- Controle (moet als vector): 25 namen 10 × 2,5, haak, zwart: `mainUnitExcl 4.05`, `lineExcl 101.25`, `lineIncl 122.51`, verzending 12,10
  (onder € 150), `totalIncl 134.61`. Plus: 1 strook (5,45 incl.), 1 strook met fee aan (14,53 + verzending), 10 × 12 × 3 sew (5 % staffel),
  bloedgroep `separate`, camo + loop, pool 20 patches + 5 stroken (10 %), custom 140 × 30, ongeldig formaat.
- Vectors in `tests/nametape/price-vectors.json`, node en PHP vergelijken exact.

### 1.8 WooCommerce: winkelmand en bestelling

- **Product** (`Nametape\Product::ensure()`, bij activatie en lui bij eerste gebruik): verborgen `WC_Product_Simple` "Naamstroken op
  maat", prijs 0, belastbaar, niet virtueel, meta `_bps_nametape_product=1`, ID in optie `bps_nametape_product_id`, productcategorie
  `woo.categorySlug` (aanmaken als ze ontbreekt) en optioneel verzendklasse `woo.shippingClassSlug`. **Niet** in de categorie
  geborduurde patches: zo telt Conditional Extra Fees geen € 45 borduurkaart [eigenaar controleert die regel, zie statusblok].
  Directe "In winkelmand" zonder lijst → `woocommerce_add_to_cart_validation` weigert met link naar de naamstrookpagina.
- **Cart item data** `bps_nt` (enkel scalars/kleine arrays): `{ v:1, batch_id (16 hex), format, custom, fabric, letter, border,
  borderColor, font, uppercase, backing, loop, bloodMode, rows:[{n, l2, b:'O+'|'', q}], pieces, review:bool, org_note,
  preview_url, rights:{v, at, locale} }`. Aantal = 1. `batch_id` maakt elke lijst een aparte regel.
- **Prijs**: eigen `woocommerce_before_calculate_totals` op **prio 25** (na woo's 20): pool = Σ qty van `bps`-regels waarvan de techniek
  `tiers:true` heeft (+ patches van cross-sell-regels, zie §2.6) en `otherNametapePieces` = stukken van de andere `bps_nt`-regels;
  `set_price( lineExcl )` (absoluut, idempotent). WDR uitsluiten met dezelfde twee filters als woo (eigen callbacks).
- **Fee**: `woocommerce_cart_calculate_fees` → instelkost één keer per bestelling als van toepassing.
- **Aantal vergrendeld**: `woocommerce_cart_item_quantity` (klassiek: toont "1 lijst") en `woocommerce_store_api_product_quantity_editable`
  → false; `woocommerce_update_cart_validation` weigert wijzigingen.
- **Weergave** (`woocommerce_get_item_data`, klassiek + blokken): "24 naamstroken · 10 × 2,5 cm", "Stijl: zwart / wit · blokletter ·
  geborduurde rand", "Achterkant: klittenband (haakzijde) + tegenstuk", "Namen: PEETERS J., JANSSENS A., … (+21)", "Bloedgroep: op eigen
  strook (24×)", maatwerk-melding. Thumbnail = `preview_url`. Link "Namen wijzigen" → naamstrookpagina `?bps_nt_edit=<cart_item_key>`
  (laadt via REST, "Bijwerken" vervangt de regel).
- **Ordermeta** (`woocommerce_checkout_create_order_line_item`, CRUD/HPOS): zichtbaar `bps_nt_summary` ("Naamstroken"),
  `bps_nt_style` ("Stijl"), `bps_nt_backing` ("Achterkant"), `bps_nt_count` ("Aantal namen"); verborgen `_bps_nt_batch` (JSON-snapshot),
  `_bps_nt_unit_excl`, `_bps_nt_review` (0/1), `_bps_nt_schema` = 1, `_bps_rights_consent`. Bij `review` een ordernotitie (§1.5.4).
- **Beheer bij de bestelling** (`woocommerce_after_order_itemmeta`, enkel voor `bps_nt`-regels): knoppen **"Namenlijst (CSV)"**
  (`admin-post.php?action=bps_nt_csv&order=…&item=…&_wpnonce`, cap `edit_shop_orders`, CSV-injectie-veilig: cellen die met `= + - @`
  beginnen krijgen een `'`) en **"Productielijst / proef afdrukken"** (`action=bps_nt_print`): HTML-pagina met per persoon het voorbeeld
  (zelfde JS-renderer, bundel `bps-nametape` in admin), stof, letterkleur, lettertype, letterhoogte in mm, aantal, achterkant. Die pagina
  dient ook als digitale proef (afdrukken als PDF en mailen); een geautomatiseerde proefmail voor naamstroken is fase 2 (handoff production).
- **Preview-bestand**: de client stuurt bij `/nametape/cart` een PNG van de eerste persoon (≤ 300 kB, ≥ 600 px breed). Server her-codeert
  met GD (`imagecreatefrompng` → `imagepng`), bewaart in `uploads/patch-studio/nametape/<32 hex>.png`. Cron `bps_nt_cleanup` (dagelijks)
  verwijdert bestanden die na 7 dagen niet aan een bestelling hangen. Namen zijn persoonsgegevens van het team van de klant: geen logging,
  bewaring volgt de bestelling; privacy-exporter/eraser van WooCommerce dekt de ordermeta.

### 1.9 REST (namespace `patch-studio/v1`, permissies via `Rest\Guard`)

| Methode + pad | Body | Antwoord 200 | Fouten |
|---|---|---|---|
| `POST /nametape/quote` | `{ batch: NametapeBatch }` (rijen mogen leeg/fout zijn) | `{ quote: NametapeQuote, rows: [{index, fit: FitResult, review}] }` | 400 `bps_nt_invalid` (enums) |
| `POST /nametape/cart` (multipart) | `batch` = JSON, `preview` = PNG, `replace` = cart item key (optioneel), `website` honeypot | `{ ok, cartUrl, cartCount, cartItemKey, quote }` | 400 `bps_nt_invalid` (+`rows`), 400 `bps_rights_required`, 413, 429 |
| `GET /nametape/cart/{key}` | — (eigen WC-sessie) | `{ batch }` | 404 |

`/quote` = `Guard::public_read`; schrijfroutes = `Guard::public_write` (token `X-BPS-Token` van `GET /token`). Rate-limit bucket `cart`.
Foutmeldingen in het Vlaams/Frans, klantvriendelijk.

**Public config**: de module voegt via de bestaande filter `bps_public_config` toe:
`extras.nametape = { enabled, pageUrl }` (voor de studioknop). De naamstrookpagina zelf krijgt een eigen config-JSON
(`Nametape\Config::public_config($locale)`): de gemergde `nametape.json` (zonder `woo`), metrics, en uit de hoofdconfig enkel
`locale`, `vat`, `tiers`, `shipping`, `legal.rightsTextVersion`, `promises` die relevant zijn, `rest`, `assets.fontsUrl`, plus
`page = { editKey }`.

### 1.10 Frontend-bouw (nametape)

- Shortcode `[patch_studio_naamstroken formaat="" stof="" compact="no"]` → `<div class="bps-nametape" data-bps-nametape-root lang="…">
  <script type="application/json" class="bps-nt-config">…</script><div class="bps-nt-skeleton">server-side titel + naamveld + noscript</div></div>`.
  Enqueue `bps-nametape` (`assets/dist/patch-studio-nametape.js`, defer, footer) en `bps-nametape-style` (`assets/src/nametape/nametape.css`,
  plain CSS, gescoped onder `.bps-nametape`, zelfde tokens als SPEC §7.8).
- Entry `assets/src/nametape/index.js` → IIFE `window.BPSNametape = { mount(el, cfg?), mountAll(), version, _test:{ parseList, fitText, quote } }`.
- Modules: `parse.js`, `fit.js`, `pricing.js`, `render.js` (canvas: twill-textuur, satijnachtige letters met lichte steekrichting en
  schaduw, geborduurde rand, camo/reflecterend-patroon procedureel; `drawTape(ctx, {format, style, lines, capMm}, pxPerMm)`;
  `toBlob()`), `state.js` (één store, onveranderlijk, `sessionStorage['bps:nt:v1']` met try/catch), `ui.js` (3 schermen),
  `messages.js` (`nt.*`, nl + fr). Imports alleen eigen bestanden + `../shared/*.js`. Geen import uit `ui/`, `render/` enz.
- Fonts via `FontFace` vanaf `config.assets.fontsUrl`, pas laden bij eerste render; tot dan systeemfont met dezelfde metrics-uitkomst
  (status hangt niet van de renderfont af).
- Prestaties: voorbeeldstrook ≤ 8 ms per tekening; lijst van 250 rijen vloeiend (mini-voorbeelden lui, gecachet per rijtekst+stijl).
- Budget bundel: ≤ 30 kB gzip.
- Toegankelijkheid: echte `<label>`s, `<fieldset><legend>` per keuze, canvas `role="img"` met `aria-label` ("Naamstrook 10 bij 2,5 cm,
  zwarte stof, witte blokletters: PEETERS J."), status als tekst ("Te lang" + icoon), doelen ≥ 44 px, `role="status"` voor de prijs
  (debounce 500 ms), werkt op 320 px en 200 % zoom, `prefers-reduced-motion`.
- Dev-pagina `dev/nametape.html`: laadt de **bronmodules als ES-modules** (`<script type="module">` met import uit
  `../plugin/borduur-patch-studio/assets/src/nametape/index.js`), haalt `config/nametape.json`, `nametape-metrics.json` en
  `defaults.json` op, offline-modus (`rest:null`: prijs lokaal, "In winkelmand" toont de payload). `?lang=fr`, `?rows=25` (voorbeeldlijst).
  Zo werkt de bouwer ook vóór de build-entry bestaat.

### 1.11 Klantteksten (selectie; volledige catalogus in `messages.js`)

| Sleutel | NL | FR |
|---|---|---|
| `nt.title` | Naamstroken met klittenband | Bandes nominatives avec scratch |
| `nt.s1.title` | Wie krijgt een naamstrook? | Qui reçoit une bande nominative ? |
| `nt.s1.paste` | Meerdere namen? Plak je lijst | Plusieurs noms ? Collez votre liste |
| `nt.s1.pasteHelp` | Eén persoon per regel. Kopieer gerust uit Excel. Kolommen: naam, regel 2, bloedgroep, aantal. | Une personne par ligne. Copiez depuis Excel sans souci. Colonnes : nom, ligne 2, groupe sanguin, quantité. |
| `nt.s2.title` | Hoe ziet je strook eruit? | À quoi ressemble votre bande ? |
| `nt.s3.title` | Alles goed? | Tout est correct ? |
| `nt.fit.too_long` | {name} is te lang voor {format}. | {name} est trop long pour {format}. |
| `nt.fit.small_text` | Past, maar de letters worden {mm} mm hoog: klein maar leesbaar. | Ça rentre, mais les lettres font {mm} mm : petit mais lisible. |
| `nt.fix.condensed` | Kies smalle letter (lost {n} namen op) | Choisissez une police étroite (règle {n} noms) |
| `nt.hook` | De haakzijde (hard) komt op je naamstrook. Je kledij heeft het zachte deel nodig. | Le côté crochets (rugueux) est sur la bande. Votre vêtement doit avoir le côté velours. |
| `nt.tier.next` | Nog {n} {n, one: strook, other: stroken} en je betaalt {price} per stuk. | Encore {n} bande(s) et vous payez {price} la pièce. |
| `nt.review` | We kijken dit even na voor we borduren. We nemen contact op als we vragen hebben. | Nous vérifions avant de broder et vous contactons en cas de question. |
| `nt.added` | Je naamstroken zitten in je winkelmand. | Vos bandes nominatives sont dans votre panier. |

### 1.12 Instellingen (beheerpagina "Patch Studio · Naamstroken", submenu van WooCommerce, cap `manage_woocommerce`)
Secties: **Status** (product bestaat, categorie, pagina, geen € 45-fee bij proefmand: knop "Test de winkelmand" die een fictieve
quote toont), **Snel aan de slag** (knop "Maak de naamstrookpagina aan" → pagina met shortcode, ID in `woo.pageId`), **Formaten & prijzen**
(tabel), **Stoffen** (op voorraad, toeslag), **Letterkleuren, lettertypes, randen** (aan/uit), **Achterkant & tegenstuk**, **Staffel**
(meetellen met patches ja/nee), **Instelkost onder X stuks** (uit/aan), **Controlewoorden**, **Beloftes** (proef, levertijd; leeg = niets
beloven), **Verzendklasse** (keuzelijst bestaande klassen; uitleg "maak eventueel een brievenbustarief aan in WooCommerce › Verzending").
Opslaan: enkel verschil met `nametape.json` in `bps_nametape_settings` (`Settings::deep_merge`-regels: lijsten vervangen), sanering per
veld, `do_action('bps_nametape_settings_updated', $cfg)`, WP Rocket-cache legen als `rocket_clean_domain` bestaat. Knop "Herstel standaard".

---

## 2. Cross-sell: "zelfde ontwerp, meer producten"

### 2.1 Wanneer wordt wat getoond
- **Tijdens het ontwerpen: niets.** De bestaande weergaven "Op pet / Op jas" in de studio blijven de enige inspiratie.
- **Na een geslaagde "In winkelmand"** in de studio: het cross-sell-paneel opent (één keer per ontwerp per sessie). Protocol met de UI
  in §2.7 en de handoff.
- **In de winkelmand**: één compacte regel "Ook op pet, muts of in een kader?" onder de mandtabel (klassiek:
  `woocommerce_after_cart_table`; blokken: `render_block_woocommerce/cart-items-block` of `woocommerce/cart` filter) die hetzelfde paneel
  opent voor het (laatste) ontwerp in de mand. Niet tonen na "Nee, bedankt" in dezelfde sessie (`sessionStorage['bps:cs:declined']` +
  WC-sessie `bps_cs_declined`), niet tonen als er geen enkele kaart past. **Nooit in de kassa.**

**Kaartkeuze** (pure functie, `Crosssell\Rules::pick()` + JS-tweeling `rules.js`):
1. Kandidaten = ingeschakelde items waarvan het gekoppelde WooCommerce-product bestaat, koopbaar is en (voor variabele producten) ≥ 1
   koopbare variatie heeft.
2. Pastoets per kandidaat (§2.2) → `ok` / `adjust` / `hide`. `hide` valt weg.
3. Volgorde uit de eerste regel in `rules` die past (bestelcontext: `qty` = aantal patches van dit ontwerp in de mand, `technique`,
   `analysis.kind`, maand). Standaard (02 §4):
   - `analysis.kind ∈ {photo, gradient}` → frame, leather_keychain, cap (krijgt voorrang op de aantallen-regels)
   - `qty ≤ 3` → frame, leather_keychain, cap
   - `10 ≤ qty ≤ 49` → cap, hoodie, beanie (okt–feb: beanie eerst)
   - `qty ≥ 50` of `technique = pvc` → cap, pvc_keychain, hoodie
   - anders (4–9) → cap, hoodie, leather_keychain
4. Items die niet in de gekozen volgorde staan maar wel passen, komen daarna; neem de eerste `maxCards` (3).
5. De volgorde hangt **nooit** af van marge. Items met `stats.autoDisabled` (§2.8) vallen weg.

### 2.2 Pastoetsen (server = `Crosssell\Fit`, puur; JS-tweeling `fit.js`, gedeelde vectors)
Invoer uit de opgeslagen `DesignPayload`: `technique`, `sizeCm`, `widthMm`, `heightMm`, `shape`, `qty`, `analysis {kind, colors,
fineLineMm, minTextMm}`, `threads[] {hex, share}`, `texts[] {heightMm}` + techniekgrenzen uit de hoofdconfig.

| Item | ok | adjust (met voorstel) | hide |
|---|---|---|---|
| `cap`, `leather_patch_cap` | `heightMm ≤ fit.maxPatchHeightMm` (65) en `widthMm ≤ maxPatchWidthMm` (110) | anders: schaal naar `suggestSizeCm` (6; bij verhouding > 1,6: breedte 10 cm), **maar alleen als** na schalen `minTextMm` en `fineLineMm` (× factor) nog ≥ de grens van de techniek zijn (`techniques[t].limits`) | schalen faalt, of `sizeCm > 12`, of techniek met `mode:'quote'` |
| `beanie` | `heightMm ≤ 55` | naar 4 of 6 cm (zelfde schaaltoets) | verhouding > 2,2 én `minTextMm < 5` na schalen |
| `hoodie` | altijd (borst ≤ 10 cm, rug 12 cm) | `sizeCm > 10` voor borst → keuze borst 10 cm / rug | techniek met `mode:'quote'` |
| `pvc_keychain` | `kind ∈ {flat, illustration}`, kleuren ≤ 6, `fineLineMm ≥ 1,0`, `minTextMm ≥ 4` **op 50 mm** (schaalfactor 50 / langste zijde) | 7–8 kleuren of lijn 0,6–1,0 → "We vereenvoudigen dit in de proef." | `photo`/`gradient`, > 8 kleuren, lijn < 0,6; of `pvc_keychain.minQty` (50) is voor deze klant onrealistisch: alleen tonen als `qty ≥ 10` of techniek = pvc |
| `leather_keychain`, `leather_bagtag` | ontwerp leesbaar in 1 tint: max. ΔL\* tussen de twee grootste garenkleuren (share ≥ 10 %) ≥ `minDeltaL` (15), `fineLineMm ≥ 0,5` en `minTextMm ≥ 2,5` op 60 mm | `kind = photo` → label "Een foto wordt een gravure in bruintinten, geen kleurenfoto." | alle hoofdkleuren onderling ΔL\* < 15 (enkel via kleur leesbaar) |
| `frame` | `sizeCm ≤ 12` (20 × 20 bij ≤ 10 cm, 25 × 25 bij ≤ 12 cm) | — | `sizeCm > 12` |

Extra regels: een cross-sell met patch gebruikt dezelfde techniek als het ontwerp; techniek met `minQty` (PVC 50) → de kaart is enkel
toonbaar als `patches van dit ontwerp in de mand + standaardaantal van de kaart ≥ minQty`, anders `hide`. ΔL\* via `hexToLab`
(shared/color-math.js; PHP-tweeling in `Crosssell\Fit`). Vectors: `tests/crosssell/fit-vectors.json` (min. 20).

### 2.3 Mockups (eigen, licht, canvas)
`assets/src/crosssell/mockups.js`: `drawMockup(ctx, kind, { patchImg, colorHex, placement, mono, plateLines }, W, H)` met Path2D-vectoren,
geen externe afbeeldingen:
- `cap_trucker`, `cap_snapback`, `cap_dad` (voorpaneel met naden, klep, schaduw; patch licht gebogen met een eenvoudige
  verticale sinus-warp in 8 stroken), `beanie_cuff` (omslag met ribstructuur), `hoodie_chest` / `hoodie_back` (silhouet, koord, plooi),
  `leather_tag` (kunstleder-textuur, naad, ring; patchbeeld → **monochroom**: luminantie → 3 bruintinten, `engraveHex` uit config),
  `bagtag` (idem + riempje), `frame_box` (schaduwdoos, passe-partout, schaduw binnenin, gelaserd alu-plaatje met 2 regels tekst in
  de plaatjeskleur), `pvc_keychain` (patchbeeld met afgeronde rubberrand, glans, oogje + ring).
- `patchImg` = de **echte preview-PNG** van het ontwerp (`previewUrl`, zelfde domein → canvas niet "tainted"; bij CORS-fout:
  `crossOrigin='anonymous'` en anders terugval op een neutrale vorm met de tekst "Voorbeeld volgt in je proef").
- Ware schaal: `scaleRefMm` per mockup in `crosssell.json`; de patch wordt getekend op `patchSizeCm` van de kaart.
- Kleur van de drager: `colorHex` van de gekozen variatie (§2.5 kleurmap). Licht en schaduw vast (linksboven), `prefers-reduced-motion` = geen animatie.
- Elke mockup draagt het label **"Voorbeeld. Je krijgt een digitale proef vóór productie."** (tekst in de DOM, niet in het canvas) en
  alt-tekst ("Je patch op een zwarte trucker-pet, voorbeeld").
- Prestaties: ≤ 25 ms per kaart bij 480 px; renderen pas als het paneel opent.
- Optioneel (handoff render): als `window.BPS?.render?.drawGarment` bestaat en `config.extras.crosssell.useStudioRenderer` aan staat,
  mag de pet/hoodie-mockup die gebruiken. Nooit verplicht.

### 2.4 Paneel en interactie
- `<dialog>` (focus erin, Esc = "Nee, bedankt", focus terug naar de knop die het opende). Gsm: bottom sheet, max. 85 % hoog, intern
  scrollend; desktop: kaart rechts (420 px). Doelen ≥ 44 px.
- Kop "Je patch zit in je winkelmand." · onder "Hetzelfde ontwerp ook op …". Als er al een borduurkaart in de mand staat:
  "Geen extra borduurkaart: die betaal je maar één keer per bestelling." (enkel als dat waar is: `fees.scope === 'order'` en de fee-groep
  van de techniek staat al in de mand).
- Max. 3 kaarten: mockup, productnaam, 1 zin waarom, **prijs per stuk incl. btw, inclusief patch en aanbrengen**, bij `adjust` de
  aanpassing ("Op de pet maken we je patch 6 cm."), bij PVC minimum en levertijd, knop **"Toevoegen"** + klein **"Wijzig"**.
- **Eén klik**: pet, muts, sleutelhanger, PVC: standaardvariatie (§2.5) en standaardaantal (team ≥ 10 patches: hetzelfde aantal als de
  patches; anders 1; PVC: `minQty`). "Wijzig" opent inline kleurswatches + aantal-stepper (en opties: echt leder, naam achterop).
- **Twee klikken waar het moet**: hoodie opent een maatlijst (alle maten van de variaties, aantal per maat, voor teams);
  kader opent 2 tekstregels voor het plaatje (max. 28 tekens/regel) met live voorbeeld; lederen naam-achterop: tekstveld of lijst plakken
  (zelfde `parseList` als naamstroken is **niet** gedeeld: eenvoudige regel-splitsing in eigen code).
- Even groot en even zichtbaar: **"Nee, bedankt"** (secundaire knop van dezelfde grootte), plus "Naar de winkelmand" na een toevoeging.
- Na "Toevoegen": kaart toont "Toegevoegd ✓ · Ongedaan maken" (verwijdert die regel via REST), mand-teller ververst
  (`wc_fragment_refresh`). Geen countdown, geen "nog maar X", niets vooraf aangevinkt.

### 2.5 Kleur- en variatiekeuze
- Variatiekleur uit het attribuut (`attribute_pa_kleur`/`pa_color`/eigen naam, instelbaar `colorAttribute`); hex uit de kleurmap in
  `crosssell.json` (`colorMap: { "black": "#1c1c1c", "navy": "#1b2a4a", "zwart": "#1c1c1c", … }`, sleutel = term-slug), ontbrekend →
  neutraal grijs en de naam als tekst.
- Standaardkleur: onder de variaties met voorraad, die met de hoogste `deltaE2000` tussen dragerkleur en de **randkleur** van de patch
  (`edgeColor.hex`, anders `fabric.hex`), beperkt tot `defaultColors` (zwart, navy, wit, grijs) zodat het geen opvallende kleur wordt.
- Hoodie/maten: attribuut `pa_maat`/`pa_size` (instelbaar `sizeAttribute`).

### 2.6 Prijzen (server: `Crosssell\Pricing`, puur; JS-tweeling voor de dev-pagina)
Alle bedragen excl. btw; incl. = per regel `round2(excl × (1 + vat))` zoals SPEC §6.3.

- **Patch op drager** (`cap`, `beanie`, `hoodie`, `leather_patch_cap`):
  `unitExcl = blankExcl + patchUnitExcl + round4(application[zone] × (1 − tierDiscount))`
  - `blankExcl` = prijs van de variatie excl. btw (`wc_get_price_excluding_tax`), geen staffel (zoals de shop nu).
  - `patchUnitExcl` = `Pricing::cart_unit_excl( { technique, size_cm: patchSizeCm, options: <ontwerpopties met backing vervangen door
    application.forceBacking ('sew_on')>, … }, qty, poolQty )` → telt mee in en profiteert van de patchstaffel. Leder-patch: vaste
    `leatherPatchExcl` (4,50) × (1 − tierDiscount).
  - `application` = `{cap 4.00, beanie 3.50, garment_front 4.00, garment_back 5.00}` [eigenaar]; zelfde staffel als de patch
    (`application.tiers = 'patch'`) of geen (`'none'`).
- **Laser-items** (`leather_keychain`, `leather_bagtag`): `unitExcl = round4((productExcl + Σ opties) × (1 − d))` met `productExcl` = prijs
  van het gekoppelde WooCommerce-product, opties `real_leather 1.50`, `name_back 1.00`, `d` = staffel op het eigen aantal (`config.tiers.steps`, niet gepoold). Geen instelkost.
- **Kader** (`frame`): `unitExcl = productExcl(maat) + patchUnitExcl` (de patch in het kader is een extra patch, telt in de pool). Geen staffel op het kader.
- **PVC-sleutelhanger**: `unitExcl = round4(productExcl × (1 − pvcTier))` met de PVC-techniekstaffel; fee **"PVC-mal sleutelhanger"**
  `moldFeeExcl` (80) één keer per bestelling, **0** als `sharedMold === true` én een PVC-patch van hetzelfde ontwerp met dezelfde vorm in de mand zit.
  `minQty` 50, `qtyStep` 10, levertijd uit config.
- **Borduurkaart**: blijft één keer per bestelling via woo. Als de mand geen `bps`-regel meer heeft maar wel een cross-sell met
  geborduurde patch, voegt de crosssell-module dezelfde fee-groep toe (zelfde fee-id `bps-fee-<group>`, dus nooit dubbel).
  Met `fees.scope === 'design'` idem per ontwerp.
- **Pool**: patches in cross-sell-regels (cap/beanie/hoodie/frame) tellen mee in de patchpool. Zolang de handoff-filter
  `bps_cart_pool_qty` er niet is, telt de crosssell-module de pool zelf voor de eigen regels (woo's patchregels zien de cross-sell-patches
  dan nog niet: gedocumenteerde beperking, verdwijnt met de handoff).
- Vectors `tests/crosssell/price-vectors.json` met de voorbeelden uit 02 §3b (trucker + 6 cm = 23,00 excl. / 27,83 incl.; muts RC027 = 13,72;
  hoodie Eco + 10 cm = 53,19), laser (8,47; met staffel), kader 20 × 20 + patch, PVC 50 st. met en zonder `sharedMold`.

### 2.7 Toevoegen aan de winkelmand (gekoppelde regels)
- **Cart item data** `bps_cs`: `{ v:1, item, design_id, ref, technique, patch_size_cm, placement ('front'|'chest'|'sleeve'|'back'|'cuff'),
  options: string[], plate: {l1, l2}|null, names: string[]|null (naam achterop, ≤ 250), preview_url }` op het **blanco/laserproduct**
  (variatie-ID voor variabele producten). Eén regel per variatie; aantal = gewone WooCommerce-hoeveelheid (aanpasbaar in de mand, met
  `minQty`/`qtyStep` voor PVC).
- Koppeling via `design_id`/`ref`; de regel blijft geldig als de losse patchregel verwijderd wordt (D6). Weergave: "Met patch
  PS-2026-0042 (6 cm, geborduurd), op het voorpaneel genaaid", prijsopbouw ("Pet € 18,10 · patch € 4,89 · aanbrengen € 4,84").
  Thumbnail = de mockup als PNG? **Nee** (geen opslag van gegenereerde beelden); thumbnail = `preview_url` van het ontwerp.
- **Prijs**: `woocommerce_before_calculate_totals` prio **30**, `set_price(unitExcl)` absoluut; WDR uitsluiten voor `bps_cs`-regels.
- **Validatie**: `woocommerce_check_cart_items` → regel met een ontwerp dat niet meer bestaat of een pastoets die nu `hide` geeft → foutmelding
  en regel verwijderen (zoals woo's `drop_orphans`). Direct toevoegen van laser/kader-producten zonder `bps_cs` → geweigerd met uitleg.
- **Ordermeta**: zichtbaar `bps_cs_design` ("Ontwerp"), `bps_cs_patch` ("Patch": "6 cm, geborduurd, op voorpaneel genaaid"),
  `bps_cs_plate` ("Plaatje"), `bps_cs_names` ("Namen achterop"); verborgen `_bps_cs` (JSON), `_bps_design_id`, `_bps_cs_unit_excl`.
  Productiefiche: via bestaande filter `bps_fiche_sections` een sectie "Hetzelfde ontwerp ook op" met alle `bps_cs`-regels van de bestelling.
- **UI-protocol na "In winkelmand" (studio)**: de studio roept, als `window.BPSCrossSell` bestaat en `config.extras.crosssell.enabled`,
  `BPSCrossSell.open({ root, designId, token, ref, cartUrl })` aan **vóór** een eventuele doorverwijzing naar de mand. Die belofte geeft
  `'added' | 'declined' | 'none'` terug (`'none'` = geen kaarten of fout of time-out 4 s). Daarna doet de studio wat ze nu doet
  (melding of doorverwijzen). Zonder die aanroep luistert de module ook naar `bps:added-to-cart` op `document` en opent het paneel als
  het event `designId` + `token` bevat (terugval; dan kan de studio intussen al doorverwijzen: daarom is de expliciete aanroep de norm).

### 2.8 REST, meten, instellingen
| Methode + pad | Body | Antwoord 200 | Fouten |
|---|---|---|---|
| `GET /crosssell/offers?designId=&token=` of `?source=cart` | — (`X-BPS-Design-Token` header mag i.p.v. query) | `{ heading, feeNote|null, previewUrl, cards: Card[] }` | 403 `bps_forbidden`, 404 |
| `POST /crosssell/cart` | `{ designId, token, item, lines:[{productId, variationId?, qty}], options?, plate?, names?, placement? }` | `{ ok, cartCount, cartUrl, keys:[…], cards: Card[] (her-berekend) }` | 400 `bps_cs_invalid`, 409 `bps_cs_unfit`, 429 |
| `POST /crosssell/undo` | `{ key }` (eigen sessie, enkel `bps_cs`-regels) | `{ ok, cartCount }` | 404 |
| `POST /crosssell/event` | `{ item, kind:'shown'|'declined' }` | `{ ok }` | 429 |

`Card = { id, kind, title, why, fit:'ok'|'adjust', adjustNote|null, patchSizeCm|null, unitIncl, breakdown:[{label, incl}], minQty, qtyStep,
defaultQty, needsSize, needsPlate, options:[{id,label,incl}], variations:[{id, colorSlug, colorName, colorHex, size|null, inStock}],
defaultVariationId|null, mockup:{kind, placement, scaleRefMm, mono, engraveHex}, leadTime|null, note|null }`. `?source=cart` gebruikt de
eigen WC-sessie (laatste `bps`-regel), geen token nodig. Permissies: offers/event = `Guard::public_read` + ontwerp-token (behalve
`source=cart`); cart/undo = `Guard::public_write`.

**Meten** (zonder persoonsgegevens, optie `bps_cs_stats`, enkel als `stats.enabled`): per item `shown`, `added` (server bij /cart),
`removed` (`woocommerce_cart_item_removed` op een `bps_cs`-regel), `ordered` (bij checkout). Item met ≥ `stats.minShown` (200) vertoningen
en < `stats.minAddRate` (1 %) toevoegingen → `autoDisabled` als `stats.autoDisable` aan staat (standaard **uit**, eigenaar kiest). Beheerpagina
toont de tabel en een knop "Teller op nul".

**Config `config/crosssell.json`**: structuur zoals 02 §7, aangevuld met: `colorAttribute`, `sizeAttribute`, `colorMap`,
`defaultColors`, `application.forceBacking`, per item `enabled`, `label{nl,fr}`, `why{nl,fr}`, `mockup`, `scaleRefMm`,
`stats {enabled:true, autoDisable:false, minShown:200, minAddRate:0.01}`, `useStudioRenderer:false`, `resizeNote`. Woo-ID's van 17-11-2026
als standaard (36262, 36278, 36027, 36247 · 36237, 36225, 36209, 36178 · 15590, 16400, 15324); laser/kader/PVC-ID's standaard 0.

**Instellingen** (beheerpagina "Patch Studio · Cross-sell"): aan/uit, max. kaarten, per item: aan/uit, gekoppelde producten
(productzoeker `wc-product-search`), standaardproduct, aanbrengbedrag, pastoets-grenzen (gevorderd, ingeklapt), teksten NL/FR.
Knop **"Maak ontbrekende producten aan"**: verborgen simple-producten met de voorgestelde prijzen (lederen sleutelhanger 7,00, tassenlabel 9,50,
schaduwdoos 20 × 20 24,75, 25 × 25 28,92, PVC-sleutelhanger 4,20; meta `_bps_cs_product=<item>`), de eigenaar past prijzen gewoon in
WooCommerce aan. Status per item: "In orde" / "Product ontbreekt" / "Geen koopbare variatie" (item wordt dan niet getoond). `sharedMold`
staat standaard **uit** met uitleg "Zet pas aan als je PVC-leverancier schriftelijk bevestigt dat de mal herbruikbaar is."

### 2.9 Frontend-bouw (crosssell)
- Entry `assets/src/crosssell/index.js` → IIFE `window.BPSCrossSell = { open(opts), openFromCart(), version, _test:{ fit, rules, price } }`.
  Modules: `fit.js`, `rules.js`, `pricing.js`, `mockups.js`, `panel.js`, `cart-prompt.js`, `api.js`, `messages.js` (`cs.*`), `crosssell.css`
  (gescoped `.bps-cs`). Imports: eigen bestanden + `../shared/*.js`.
- Laden: de module enqueuet `bps-crosssell` zelf in de footer wanneer `bps-app` ge-enqueued is (`wp_print_footer_scripts` prio 1:
  `wp_script_is('bps-app','enqueued')`) en op de winkelmandpagina (`is_cart()`). Config via `wp_add_inline_script` (`window.BPSCrossSellConfig = …`,
  JSON veilig ge-encodeerd) met `rest`, `locale`, teksten, `enabled`. Budget ≤ 25 kB gzip.
- Dev-pagina `dev/crosssell.html`: ES-modules uit `src/`, voorbeeldpayloads (vlak logo 8 cm × 25, foto 6 cm × 1, PVC 4 cm × 50, illustratie
  10 cm × 12) en een voorbeeld-PNG uit `reference/`, offline-modus: kaarten via de JS-tweeling, "Toevoegen" toont de regel-payload.
  Paneel op 390 × 844 en 1280 × 800.

### 2.10 Klantteksten (selectie)
| Sleutel | NL | FR |
|---|---|---|
| `cs.added` | Je patch zit in je winkelmand. | Votre écusson est dans votre panier. |
| `cs.heading` | Hetzelfde ontwerp ook op … | Le même design aussi sur … |
| `cs.feeNote` | Geen extra borduurkaart: die betaal je maar één keer per bestelling. | Pas de carte de broderie supplémentaire : vous ne la payez qu'une fois par commande. |
| `cs.cap.why` | Je patch, stevig op een trucker genaaid. | Votre écusson, solidement cousu sur une casquette trucker. |
| `cs.adjust.size` | Op de pet maken we je patch {cm} cm. | Sur la casquette, votre écusson fera {cm} cm. |
| `cs.leather.why` | Je ontwerp in leder gelaserd, in één bruine tint. | Votre design gravé au laser dans le cuir, en une seule teinte brune. |
| `cs.leather.photo` | Een foto wordt een gravure in bruintinten, geen kleurenfoto. | Une photo devient une gravure en tons bruns, pas une photo en couleur. |
| `cs.pvc.min` | Vanaf {n} stuks (vaste mal). Minder nodig? Kies leder, vanaf 1 stuk. | À partir de {n} pièces (moule fixe). Besoin de moins ? Choisissez le cuir, dès 1 pièce. |
| `cs.frame.why` | Ingekaderd met een gegraveerd plaatje: een cadeau dat blijft hangen. | Encadré avec une plaquette gravée : un cadeau qui reste. |
| `cs.mockup` | Voorbeeld. Je krijgt een digitale proef vóór productie. | Aperçu. Vous recevez une épreuve numérique avant la production. |
| `cs.decline` | Nee, bedankt | Non merci |
| `cs.cartLine` | Ook op pet, muts of in een kader? | Aussi sur casquette, bonnet ou dans un cadre ? |

---

## 3. WooCommerce-producten en instellingen (overzicht)

| Product | Type | Wie maakt het | Prijsbron | Meta / herkenning |
|---|---|---|---|---|
| Naamstroken op maat | simple, verborgen, categorie `naamstroken` | nametape, automatisch | server (`set_price` = lijnprijs) | `_bps_nametape_product=1`, optie `bps_nametape_product_id` |
| Petten FL6606RT, FL6089MT, FL6277, KP908 | bestaande variabele producten | eigenaar (bestaat) | variatieprijs + patch + aanbrengen | gekoppeld in `crosssell.items.cap.wcProductIds` |
| Mutsen RC027, RC133X, B447, KP884 | bestaand | eigenaar | idem (KP884: `noIronOn`, altijd naaien) | `beanie.wcProductIds` |
| Hoodies 15590, 16400, 15324 | bestaand | eigenaar | idem | `hoodie.wcProductIds` |
| Lederen sleutelhanger, lederen tassenlabel | simple, verborgen | crosssell-knop "Maak ontbrekende producten aan" | productprijs + opties | `_bps_cs_product=leather_keychain` / `leather_bagtag` |
| Schaduwdoos 20 × 20 en 25 × 25 met plaatje | simple, verborgen | idem | productprijs + patch | `_bps_cs_product=frame_20x20` / `frame_25x25` |
| PVC-sleutelhanger | simple, verborgen | idem | productprijs × PVC-staffel + mal-fee | `_bps_cs_product=pvc_keychain` |

| Optie | Inhoud | Eigenaar |
|---|---|---|
| `bps_nametape_settings` | overrides op `config/nametape.json` | nametape |
| `bps_nametape_product_id`, `bps_nametape_page_id` | ID's | nametape |
| `bps_crosssell_settings` | overrides op `config/crosssell.json` | crosssell |
| `bps_cs_stats` | tellers per item | crosssell |
| Cron `bps_nt_cleanup` (dagelijks) | wees-previews naamstroken weg | nametape |

Filters/acties die de modules **zelf aanbieden**: `bps_nametape_config` (filter, gemergde config), `bps_nametape_quote` (filter, na
berekening), `bps_nametape_settings_updated` (actie), `bps_nametape_added` (actie: `$cart_item_key, $batch`), `bps_crosssell_config`,
`bps_crosssell_cards` (filter: `$cards, $design, $context`), `bps_crosssell_added` (actie: `$keys, $item, $design_id`).

Bestaande hooks die de modules **gebruiken** (geen wijziging nodig): `bps_public_config`, `bps_fiche_sections`, `bps_settings_updated`
(cache), `bps_activate`/`bps_deactivate` (cron), plus WooCommerce-hooks hierboven. Publieke klassen die ze **aanroepen**:
`Settings::get()`, `Settings::deep_merge()`, `Pricing::cart_unit_excl()` / `Pricing::quote()` / `Pricing::round_half_up()`,
`Woo\Designs::get()` / `verify_token()`, `Rest\Guard::public_read()` / `public_write()`. Ontbreekt een klasse (tests), dan netjes terugvallen
(`class_exists`), nooit fataal.

---

## 4. Bestandseigendom per bouwer

**Bouwer "nametape"** (alleen deze paden):
```
plugin/borduur-patch-studio/includes/nametape/**          class-module.php, class-config.php, class-fit.php*, class-pricing.php*,
                                                          class-validator.php*, class-product.php, class-cart.php, class-rest.php,
                                                          class-shortcode.php, class-admin.php, class-order-tools.php, views/**
plugin/borduur-patch-studio/assets/src/nametape/**        index.js, parse.js, fit.js, pricing.js, render.js, state.js, ui.js,
                                                          messages.js, nametape.css
plugin/borduur-patch-studio/config/nametape.json, config/nametape-metrics.json
dev/nametape.html
tests/nametape/**                                         *.test.js (node --test), fit-vectors.json, price-vectors.json, parse-cases.json,
                                                          run-php.php, measure-fonts.mjs (genereert metrics), e2e-mobile.mjs (Playwright)
docs/extra/nametape-notes.md                              eigen notities/aannames
docs/handoff/extra-products-needs.md                      enkel de sectie "Van nametape" aanvullen
```
**Bouwer "crosssell"** (alleen deze paden):
```
plugin/borduur-patch-studio/includes/crosssell/**         class-module.php, class-config.php, class-fit.php*, class-rules.php*,
                                                          class-pricing.php*, class-products.php, class-cart.php, class-cart-prompt.php,
                                                          class-rest.php, class-stats.php, class-admin.php, views/**
plugin/borduur-patch-studio/assets/src/crosssell/**       index.js, fit.js, rules.js, pricing.js, mockups.js, panel.js, cart-prompt.js,
                                                          api.js, messages.js, crosssell.css
plugin/borduur-patch-studio/config/crosssell.json
dev/crosssell.html
tests/crosssell/**                                        *.test.js, fit-vectors.json, rules-vectors.json, price-vectors.json, run-php.php,
                                                          e2e-panel.mjs (Playwright)
docs/extra/crosssell-notes.md
docs/handoff/extra-products-needs.md                      enkel de sectie "Van crosssell" aanvullen
```
\* **Pure klassen** (geen WordPress): beginnen met `if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) { exit; }` zodat
`run-php.php` ze standalone laadt. Alle andere PHP-bestanden: `defined( 'ABSPATH' ) || exit;`.

Regels (zoals SPEC §2): niets buiten je paden aanraken; de twee bouwers delen **geen** code (elk eigen rounding-tweeling, eigen api-helper);
JS importeert enkel eigen bestanden en `../shared/*.js` (bevroren); namespace `BorduurPatchStudio\Nametape` / `BorduurPatchStudio\Crosssell`,
prefix `bps_nt_` / `bps_cs_` voor hooks, opties, nonces, admin-post-acties en CSS-klassen (`.bps-nametape`, `.bps-cs`); text domain
`borduur-patch-studio`; WordPress Coding Standards; PHP 8.1+; alle invoer saneren, alle uitvoer escapen, nonces + capabilities op elke beheeractie.

## 5. Tests en "klaar"

- `npm test` pikt `tests/nametape/*.test.js` en `tests/crosssell/*.test.js` automatisch op. PHP: `php tests/nametape/run-php.php` en
  `php tests/crosssell/run-php.php` (zelf draaien; opname in `npm run test:php` is een handoff). Bestaande tests blijven groen.
- **Nametape klaar als**: parse-gevallen (tab, `;`, CSV met aanhalingstekens, koptekst NL/FR, bloedgroep-varianten, dubbels, > 250 rijen),
  fit- en prijsvectors identiek in node en PHP; dev-pagina: één naam → in winkelmand-payload in ≤ 3 schermen; Playwright op 390 × 844 en
  360 × 740: voorbeeld altijd binnen `visualViewport` (ook met toetsenbord-simulatie), `scrollTop` 0, schermafdrukken per scherm in
  `docs/screenshots/extra/`; een lijst van 25 namen met 2 te lange namen toont 2 rode rijen met werkende één-klik-oplossing; FR volledig.
- **Crosssell klaar als**: fit/rules/price-vectors identiek in node en PHP (incl. de 02 §3b-bedragen); dev-pagina toont voor de 4
  voorbeeldpayloads de juiste kaarten (foto → geen PVC; 25 stuks → pet, hoodie, muts; 1 stuk → kader, leder, pet); paneel is een echte
  dialog, Esc = Nee, bedankt; geen vooraf aangevinkte opties; mockups ≤ 25 ms; schermafdrukken gsm + desktop.
- Beide: PHP-lint (`php -l`) op alle eigen bestanden; geen PHP-notices bij laden zonder WooCommerce (modules doen dan niets).

## 6. Open punten voor de eigenaar (standaard ingevuld, aanpasbaar in de instellingen)
1. Naamstrookprijzen (§1.4) en instelkost onder 5 stuks (standaard uit). 2. Naamstroken in de patchstaffel (standaard ja).
3. Stoffen op voorraad (camo/reflecterend standaard uit) en welke borduurlettertypes de software heeft. 4. Brievenbustarief (verzendklasse).
5. Aanbrengbedragen cross-sell en of de patch op een pet een staffel krijgt. 6. Kleinere patch voor op de pet: geen extra kosten (standaard),
   of als nieuw ontwerp aanrekenen? 7. `sharedMold` PVC (standaard uit). 8. Prijzen lederen items/kader (producten aanmaken en prijzen nakijken).
9. Conditional Extra Fees: controleren dat "Naamstroken op maat" en de laser/kaderproducten geen € 45 borduurkaart triggeren.
10. Digitale proef voor naamstroken: printpagina als proef (v1) of automatische proefmail (fase 2).
