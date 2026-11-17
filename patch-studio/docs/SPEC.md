# Patch Studio — bindend ontwerp (SPEC v1.0)

Hoofdarchitect · 16-11-2026 · voor 10 bouwers die **parallel** werken zonder elkaar te zien.
Dit document is **bindend**. Bij twijfel: dit document > onderzoeksdocumenten > eigen inschatting.
Wijk je toch af (bv. omdat iets technisch niet kan), schrijf het dan in `docs/handoff/<rol>.md` onder "Afwijkingen van SPEC"
en hou het contract naar buiten toe intact.

Bronnen (gelezen en verwerkt): `BRIEF.md`, `research/01…08`, `reference/demo-v1.html`, `reference/demo-v2.html`.
Al gemaakt door de architect (bevroren, niet wijzigen zonder SPEC-update):

| Bestand | Inhoud |
|---|---|
| `plugin/borduur-patch-studio/config/defaults.json` | De ene config die PHP en JS delen (§8) |
| `plugin/borduur-patch-studio/config/thread-palette.json` | **Seed** (9 garens) — de colors-bouwer vervangt dit door het volledige palet |
| `plugin/borduur-patch-studio/assets/src/shared/types.js` | Alle JSDoc-contracten (§7) |
| `…/shared/color-math.js` | sRGB↔Lab, ΔE2000 (gevalideerd), WCAG-contrast |
| `…/shared/geometry.js` | `layoutPatch()` = ontwerp-px → mm op de patch (§7.6) |
| `…/shared/money.js` | `roundHalfUp/round2/round4`, `formatMoney` (§6.3) |
| `…/shared/i18n.js` | `createT()`, `msg()` — berichtencatalogi NL/FR (§7.9) |
| `…/shared/config.js` | `deepMerge`, `buildPublicConfig`, `defaultOptions`, `optionsFor`, `label` … |
| `…/shared/events.js` | Eventnamen `EV.*` tussen UI-modules (§7.8) |
| `package.json`, `scripts/build.mjs`, `scripts/serve-dev.mjs` | Build/test/dev (§5) |
| `tests/shared/shared.test.js` | Tests van de shared-modules (`npm test` = groen) |

---

## 0. Beslissingen (lees eerst)

| # | Beslissing | Waarom |
|---|---|---|
| D1 | **Winkelmandmodel `own_product` (standaard)**: één verborgen WooCommerce-product "Patch op maat"; de prijs wordt **server-side** gezet door `Pricing` (PHP) in `woocommerce_before_calculate_totals` (prio 20); eenmalige kosten via `woocommerce_cart_calculate_fees`. Ons item wordt **uitgesloten van Woo Discount Rules** (2 filters, 06 §5b-5). | Werkt zonder Product Add-Ons/WDR/Conditional Fees (testomgeving!) en met één bron van waarheid. De getallen in de config zijn exact de kassa-getallen (02 §10), dus het bedrag is gelijk aan de bestaande producten. Modus `existing_products` (bestellen op de 4 bestaande producten met PA-velden, 06 §3a) is **fase 2**; de `legacy`-velden in de config staan klaar. |
| D2 | **Privacy "lokaal eerst"** (08 §2.4 V1): analyse gebeurt volledig in de browser; het bestand gaat pas naar de server bij *bestellen*, *offerte* of *bewaar & mail*. | Privacywinst, sneller, eerlijk te communiceren. |
| D3 | **Kleur van de UI = het blauw dat de site echt toont** (`#21A9E2` + navy `#0A1F44`), niet paars/koraal (01 §0.1: die globale kleuren komen visueel bijna niet voor). Knoppen met witte tekst gebruiken **`#0B74A6`** (donker merkblauw, 5,17:1) want wit op `#21A9E2` = 2,68:1 (faalt WCAG). Paars `#5926C5` = focusring (8,37:1). Koraal enkel als decor. | WCAG 2.2 AA + echt in het thema. |
| D4 | **Prijs = per maat** (4/6/8/10/12 cm, langste zijde), **niet** per steek. Tussenmaten worden geprijsd als de **volgende** geprijsde maat ("maximale maat", zoals de shop). > 12 cm of offerte-techniek → offerte. Steekaantal = uitleg, geen prijsfactor. | Zo rekent de kassa (02 §2, 04 §10.3). |
| D5 | Fee-gedrag en gesublimeerd-zonder-staffel volgen **de kassa van vandaag** (1× per bestelling per fee-groep; gesublimeerd `tiers:false`). Alles instelbaar; open punten in §12. | "Tool = kassa", geen valse beloftes (06 §16-20). |
| D6 | Vanilla JS, ES-modules in `assets/src`, gebundeld door esbuild tot **IIFE** (`window.BPS`) + aparte worker. Geen jQuery, geen framework, geen externe CDN's, lettertypes lokaal. | WP Rocket Delay-JS-veilig, < 60 kB gzip, GDPR (08 §2.7). |
| D7 | Config gaat naar de browser als `<script type="application/json" class="bps-config">` **in** de root-div (geen nonce, geen persoonsgegevens → mag gecachet worden). Schrijfacties halen eerst een token via `GET /patch-studio/v1/token`. | 06 §8-9. |
| D8 | Bronteksten **Vlaams Nederlands met "je"**; Frans met "vous". JS-catalogi per module (NL+FR), PHP kan elke sleutel overschrijven via `config.strings`. PHP-teksten via `__()` met text domain `borduur-patch-studio`. | 08 §6. |
| D9 | Eerlijkheid is een **harde eis**: geen sterren, nep-reviews, aftelklokken, valse schaarste, vooraf aangevinkte betaalde opties, doorstreepte was-prijzen, verzonnen cijfers. Testimonial standaard **uit**. | BRIEF, 05 R20, EU-UCPD. |

---

## 1. Productvisie en klantflow

### 1.1 Visie in één zin
"Upload je logo, schets of zelfs een gsm-foto van een bestaande patch: je ziet meteen een mooie, volle patch, een eerlijk vakmansadvies
per techniek met gemeten feiten, wat het kost (alles inbegrepen) en wat er nu gebeurt — vanaf 1 stuk, met digitale proef vóór productie,
gemaakt in Kalmthout."

Het **wow-moment** zit in de eerste 3 seconden na de upload: de tool knipt de patch uit (ook uit een gsm-foto), herkent de vorm,
vult de patch mooi, telt de kleuren, meet de fijnste lijn en kleinste tekst **in mm op de gekozen maat**, en zegt in mensentaal wat
een vakman zou zeggen. Concurrenten doen dat niet (03 §1).

### 1.2 Schermen (5 stappen, labels vast, nooit van aantal veranderen)

Desktop (≥ 1025 px): links de stappen (60 %), rechts een **sticky kolom** met het patchvoorbeeld + prijskaart (40 %).
Mobiel (≤ 767 px): voorbeeld bovenaan (vierkant, max 70 vh), stappen eronder, **sticky bestelbalk** onderaan (64 px, boven
`env(safe-area-inset-bottom)`; verberg `.bsb-mob-balk` op de studiopagina met CSS `body:has(.bps-studio) .bsb-mob-balk{display:none}`).
Stappenbalk = `<ol>` met namen: **1 Ontwerp · 2 Techniek · 3 Maat & aantal · 4 Afwerking · 5 Overzicht**. Huidige stap
`aria-current="step"`; afgewerkte stappen klikbaar met ✓. Browser-terug = vorige stap (History API, `?bps_step=n` in de hash).

#### Stap 0 — Lege staat (vóór upload)
- Kop **"Ontwerp je patch"**; subtitel "Upload je logo, tekening of zelfs een foto van een bestaande patch. Wij kijken meteen of het te borduren is."
- Grote dropzone = `<label for>` rond een echte `<input type=file accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml,application/pdf,.ai,.eps,.psd">`.
  Tekst: "**Sleep je bestand hierheen** of *kies een bestand* · PNG, JPG, SVG of PDF · max. 25 MB".
- Geruststelling: "Je hoeft geen perfect bestand te hebben. Een schets of foto is genoeg om te beginnen."
- Secundair: **"Nog geen ontwerp? Start met tekst"** (tekst-only patch, standaard 6 cm, rond, tekst in boog).
- **2–3 voorbeeldontwerpen** (zelfgemaakte inline SVG's, geen merken): "Probeer met een voorbeeld" → laadt het voorbeeld alsof het geüpload werd.
- Privacyzin (08 §2.4 V1) klein onder de dropzone + link naar `config.contact.privacyUrl`.
- Server-side skelet (rendered door PHP) toont dezelfde kop + input + "Start met tekst", zodat er niets verspringt en het werkt vóór JS laadt.
  `<noscript>`: "Je browser ondersteunt de ontwerptool niet. Vraag je patch eenvoudig aan via ons offerteformulier." (link `contact.quoteUrl`).

#### Stap 1 — Ontwerp (na upload)
1. **Analyse met echte stappen** (geen kunstmatige vertraging), live in een `role="status"`-lijst met vinkjes:
   "Achtergrond herkend: wit — we kiezen de stofkleur" · "Vorm herkend: rond — je ontwerp vult de patch" · "7 kleuren gevonden" ·
   "Fijnste lijn ±0,8 mm · kleinste tekst ±3,1 mm bij 8 cm". Elke regel verschijnt zodra de worker die stage meldt (§7.2).
2. Het **voorbeeld** verschijnt na de stage `shape` (ruw) en wordt mooi na `preview`. Altijd **contain**, nooit bijgesneden (BRIEF).
3. Herkenningsmeldingen (alleen als waar):
   - Foto van een patch: "We hebben je patch uit de foto geknipt. Klopt de rand niet helemaal? **Bijstellen**" → `<dialog>` met vormkeuze, randtolerantie-schuif en "Achtergrond meenemen ja/nee".
   - Vorm herkend: "Je ontwerp is al rond, dus we maken er een ronde patch van. *Andere vorm kiezen*".
   - Witte achtergrond: "Je ontwerp heeft een witte achtergrond. Die laten we weg en we kiezen een stofkleur waarop alles goed uitkomt: **marineblauw**. *Andere stofkleur*".
   - Gekleurde/getekende achtergrond: "We nemen de achtergrond van je ontwerp mee tot aan de rand van de patch."
   - Mock-up: "Dit lijkt al een afbeelding van een patch. Geen probleem: we gebruiken het als voorbeeld. Heb je het originele bestand? Dan wordt het nog scherper."
   - PDF/AI/EPS/PSD: "**We hebben je bestand goed ontvangen.** Dit bestandstype kunnen we hier niet live tonen, maar onze digitaliseerder werkt er graag mee. Exporteer het als PNG of SVG voor een voorbeeld, of ga gewoon verder: je krijgt sowieso een digitale proef." → het bestand gaat mee; analyse = `null`; advies = algemeen + offerte-knop prominent.
4. **Schikken** (BRIEF): schuif "Grootte" 20–200 % (`aria-valuetext="120 %"`), slepen (muis/touch), pijltjes-knoppen (1 mm; Shift = 5 mm), "Centreren", "Passend maken". Alles blijft binnen de patchvorm (geknipt, rand erover).
5. **Tekstlagen**: "+ Tekst toevoegen" → tekst (max 60), lettertype (6, uit `config.fonts`), garenkleur (palet), letterhoogte mm, vorm Recht / Boog boven / Boog onder; boog: schuif "positie op de rand" (graden) en "afstand tot rand" (mm). Max 10 lagen. Slepen is nooit de enige manier (WCAG 2.5.7).
6. Knop **"Bekijk ons advies →"**.

#### Stap 2 — Techniek ("Zo zouden wij het maken")
- **Adviesbox** bovenaan (1–3 zinnen, `advice.summary`), met klein label "Advies van ons atelier in Kalmthout · Automatisch advies op basis van je bestand. Onze digitaliseerder kijkt alles na voor je proef." (08 §1.4-I).
- **3 techniekkaarten** (de top-3 uit `advice.ranking`), elk: status-icoon **+ woord** (✓ Aanrader / ! Kan, met aanpassing / ✕ Raden we af),
  kop (≤ 6 woorden), 1 reden met gemeten waarde, 1 actieknop (fix), en prijs: "€ x per stuk · € y alles inbegrepen" of "Prijs op aanvraag".
  Badge **"Ons advies"** op precies één kaart (`advice.recommended`). Radiogroep (`<fieldset>`), kaart = gestylede radio.
  "Toon alle technieken (7)" klapt de rest open.
- **"Vergelijk naast elkaar"**: 3 kleine renders (geborduurd/geweven/bedrukt of top-3) met 1 regel + prijs/st.
- Bij draadtechnieken: **garenkleuren** (swatch + eigen naam + Polyneon-nummer + kwaliteitslabel "zeer goed / goed / benadering"; nooit het ΔE-getal),
  schuif **"Aantal garenkleuren: 6 · 8 · 10 · 12"** met live voorbeeld, toggle **"Toon wat er verandert"** (origineel ↔ vereenvoudigd naast elkaar)
  en de zin wat er wegvalt ("De zachte schaduw onder de adelaar wordt een vlak in donkergrijs.").
- Disclaimer onder het voorbeeld (08 §1.4-H): "Voorbeeld ter illustratie. Garen- en drukkleuren kunnen licht afwijken van je scherm. Je digitale proef toont de definitieve versie."
- Knop **"Kies maat en aantal →"**.

#### Stap 3 — Maat & aantal ("Hoe groot en hoeveel?")
- Maat-chips `sizes.pricedCm` (4·6·8·10·12) + "Andere maat" (0,5 cm stappen tot `maxQuoteCm`; > `maxOrderCm` = offerte). "Maat = de langste zijde van je patch." + context-hint uit `sizes.hints`.
- **Maatadvies** (`advice.size`): "Wij raden **8 cm** aan: dan blijft je kleinste tekst 4,2 mm hoog en goed leesbaar." met knop.
- Toggle **"Bekijk op een kledingstuk"** (polo-borst / pet / jas-rug) → render `view:'garment'` op ware verhouding.
- Aantal: stepper −/+ (`inputmode="numeric"`), presets 1·10·25·50·100. "Vanaf 1 stuk. Eén stuk om te testen? Dat kan gewoon."
- **Staffelbalk** (`quote.ladder`) met huidige trede gemarkeerd + hint (alleen als volgende trede ≤ 2× huidig aantal):
  "Bij **25 stuks** betaal je **€ 5,66** per stuk (nu € 5,98)." + eerlijke tegenhint "Heb je er maar 10 nodig? Bestel er dan 10. Bijbestellen kan altijd."
  Nooit tonen voor technieken met `tiers:false`.
- PVC: aantal springt naar 50, stappen van 10: "PVC maken we vanaf 50 stuks, per 10. We hebben je aantal op **50** gezet."
- Verzendhint (alleen tussen `hintFromInclVat` en `freeFromInclVat`): "Nog € 18,40 tot gratis verzending (België en Nederland)."
- Optioneel (`features.cartPoolHint`): GET `/wc/store/v1/cart` → "Je hebt al 15 patches in je winkelmand; samen zit je in de 10 %-trede." (poolQty).

#### Stap 4 — Afwerking ("Laatste details")
Per `config.optionGroups` (volgorde zoals in de config; `advanced:true` achter "Meer opties"):
rand (`edge`), achterkant (`backing`) + klittenbandkleur/extra's (alleen als klittenband gekozen), dikte (advanced), drager (gesublimeerd),
**stofkleur/achtergrond** (auto met uitleg: "We kozen marineblauw: daarop komen je kleuren het best uit."), **randkleur** (auto = garen
dat het best past), extra's (advanced: rol garen, stofschaar). Elke optie toont haar effect: "+ € 1,82/st." / "inbegrepen" / "− 10 %" (incl. btw).
Achterkant-advies: "Op fleece of waterafstotende stof hecht strijken minder goed. Kies daar opnaaien of klittenband."

#### Stap 5 — Overzicht ("Jouw patch")
- Samenvatting met per regel *Wijzig* (springt naar stap): "Geborduurd · rond · 8 cm (80 × 80 mm) · 25 stuks · geborduurde rand · opstrijkbaar · stof marineblauw · 6 garenkleuren".
- "Wat er nu gebeurt": **1.** Je bestelt. **2.** {Binnen X werkdagen | Zo snel mogelijk} krijg je je digitale proef in je mailbox. **3.** Jij keurt goed, wij starten de productie.
  Levertijd: `techniques[t].leadTime` of `promises.leadTimeFallback`.
- **Rechten-checkbox** (verplicht, nooit vooraf aangevinkt, 08 §3.2) + "Waarom vragen we dit?".
- **Maatwerkmelding** (08 §1.4-A), + zin A+ als `promises.cancelUntilProof`.
- Hoofdknop **"Bestel deze patch · € 216,05"** met subregel "Je kijkt alles nog na in je winkelmand". Bij offerte-modus: **"Vraag je prijs op maat aan"**.
- Vertrouwensbundel direct onder de knop (05 R13, max 3 regels): ✓ Digitale proef vóór productie · ✓ Vanaf 1 stuk, eigen machines in Kalmthout · ✓ Vragen? `promises.responseTime` · WhatsApp-link.
- Secundaire rij: **"Bewaar & mail mijn ontwerp"** · **"Laat een vakman meekijken"** (offerte met alles vooraf ingevuld).
- Testimonial alleen als `promises.testimonial.enabled`.

#### Altijd zichtbaar
- **Prijskaart** (desktop rechts / mobiel bottom-sheet "Prijsdetails"): hoofdbedrag incl. btw (Sora 700, 28–32 px), "incl. btw · € x per stuk",
  regels (patches, eenmalige kosten met ⓘ-uitleg, verzending of "gratis"), uitklapper "Hoe is deze prijs opgebouwd?" (`quote.breakdown`),
  schakelaar "Toon prijzen excl. btw (voor bedrijven)" (`vat.showExclToggle`; hoofdbedrag blijft incl.).
- **Sticky mobiele balk**: links "€ 216,05 · 25 st. · incl. btw", rechts één knop: vóór stap 5 "Volgende", vanaf stap 5 "Bestellen". Verschijnt pas na een ontwerp. Geen animatie/pulse. `html{scroll-padding-bottom:80px}` (WCAG 2.4.11).
- "Hulp nodig? WhatsApp 0491 06 32 01" op een vaste plek in elke stap (WCAG 3.2.6).

### 1.3 Microcopy-richtlijnen (voor álle bouwers)
1. **Oordeel → reden (met gemeten getal) → actie.** "Te borduren vanaf 8 cm. Je kleinste tekst is 3,1 mm; garen vraagt minstens 4 mm. [Maak 8 cm]".
2. Wij-vorm met eigenaarschap ("Wij zouden…", "Bij ons atelier…"), nooit "het systeem". Vakwoord + uitleg één keer: "merrow (omgezoomde rand)".
3. Kort: adviesregels ≤ 20 woorden, adviesblok ≤ 3 zinnen; meer achter "Waarom?".
4. Geen uitroeptekens in advies (max 1 per scherm), geen hoofdletters als nadruk, `text-transform:none` op alle koppen.
5. Elk "nee" heeft een groen alternatief. Eerlijk over goedkopere opties ("Dat hoef je niet te kopen").
6. Getallen: komma als decimaal (`2,5 mm`), `€ 5,98` (NL) / `5,98 €` (FR) via `formatMoney`, "8 cm", "80 × 80 mm".
7. Fouten in context, mild, met oplossing (05 §6-tabel is de bron), nooit `alert()`, verdwijnt zodra opgelost.
8. Verboden woorden/patronen: "Perfect!!!", "Bestseller", "Populairst", "Slechts vandaag", "100 % tevredenheid", "gratis retour", sterren.
9. Frans: "vous", "écusson", "épreuve numérique", "TVAC/HTVA". Laat FR door de eigenaar nalezen (open punt).

### 1.4 Sterkste ideeën uit het onderzoek die we bouwen (checklist)
Automatisch techniekadvies op het echte bestand (03-1) · vorm automatisch uit het ontwerp/gsm-foto (03-7) · realistische preview per techniek,
nooit bijgesneden, met "wat er verandert" (03-6, 05 R12) · maatadvies dat het probleem oplost (04 §9.3) · live prijs alles inbegrepen + meerprijs
per optie + staffelladder + "alles inbegrepen per stuk" (02 §10c, 05 R6-R9) · garenmatch naar het echte Polyneon-assortiment (07 §5) ·
eerlijke paars-garen-melding ("deze tint bestaat niet exact als garen; kies bedrukt voor exact") · bewaren & mailen zonder account + offerte met alles
vooraf ingevuld (05 R16) · op kledingstuk bekijken (03-14) · productiefiche + digitale proef met goedkeuringslink (atelier) · leverbelofte enkel als ingesteld.

---

## 2. Mappenstructuur en bestandseigendom

```
patch-studio/
├─ package.json, package-lock.json        [architect]
├─ scripts/build.mjs, serve-dev.mjs       [architect]
├─ dev/index.html (+ dev/*.js|css)        [ui]
├─ docs/SPEC.md                           [architect]  docs/handoff/<rol>.md [elke bouwer, eigen bestand]
├─ tests/
│  ├─ shared/                             [architect]
│  ├─ analysis/                           [analysis]
│  ├─ colors/                             [colors]
│  ├─ advice/                             [advice]
│  ├─ pricing/  (vectors.json, *.test.js, run-php.php)   [pricing]
│  ├─ render/ ui/ production/ woo/  (optioneel, door die bouwer)
│  ├─ e2e/**                              [testenv]
│  └─ run-all.sh                          [testenv]
└─ plugin/borduur-patch-studio/
   ├─ borduur-patch-studio.php            [core]   plugin-header, constanten, autoloader, activatie
   ├─ uninstall.php                       [core]
   ├─ readme.txt                          [core]   (polish-fase; WP Rocket-instructies, nginx-regels)
   ├─ wpml-config.xml                     [core]
   ├─ config/defaults.json                [architect→core]  structuur bevroren; core mag WAARDEN aanvullen
   ├─ config/thread-palette.json          [colors]
   ├─ languages/**                        [core]   .pot (polish), fr_BE/fr_FR .po/.mo
   ├─ includes/
   │  ├─ class-plugin.php                 [core]   bootstrap, componenten, assets, WP Rocket
   │  ├─ class-settings.php               [core]   config laden/mergen/opslaan, public_config
   │  ├─ class-pricing.php                [pricing] PHP-prijsengine (puur, zonder WP)
   │  ├─ class-uploads.php                [woo]    privé-opslag, sniff, sanering, previews
   │  ├─ admin/class-settings-page.php (+ admin/views/**, admin/assets/**)  [core]
   │  ├─ admin/class-order-production.php [production]
   │  ├─ woo/**                           [woo]    class-integration.php, class-designs.php, class-quotes.php, class-cart.php, class-emails.php, class-privacy.php, emails/*.php …
   │  ├─ rest/**                          [woo]    class-routes.php (+ class-guard.php …)
   │  └─ frontend/class-shortcode.php, frontend/class-elementor-widget.php  [woo]
   ├─ templates/
   │  ├─ emails/**                        [woo]    save-design, quote-admin, quote-customer (+ plain/)
   │  └─ admin/**                         [production]  fiche, print, proof-mail, proof-response
   ├─ vendor-prefixed/**                  [woo]    (optioneel) enshrined/svg-sanitize via Strauss
   └─ assets/
      ├─ src/
      │  ├─ main.js                       [ui]     entry → dist/patch-studio.js (window.BPS)
      │  ├─ shared/**                     [architect]  BEVROREN
      │  ├─ analysis/** (index.js, worker.js, …)  [analysis]   worker.js → dist/patch-studio-worker.js
      │  ├─ colors/**                     [colors]
      │  ├─ advice/**                     [advice]
      │  ├─ pricing/**                    [pricing]
      │  ├─ render/**                     [render]
      │  ├─ ui/**                         [ui]
      │  └─ admin/** (index.js, *.css)    [production]  → dist/patch-studio-admin.js (+ .css)
      ├─ css/patch-studio.css (+ **)      [ui]     plain CSS, geen build
      ├─ fonts/**                         [ui]     woff2 (OFL), lokaal; zie §7.10
      └─ dist/**                          [build-output — niemand bewerkt met de hand]
```

Regels:
- **Raak geen bestand aan dat niet van jou is.** Heb je iets nodig van een ander, programmeer tegen het contract in §7 en
  documenteer de aanname in `docs/handoff/<rol>.md`. Ontbreekt een module nog bij het testen: maak een **lokale stub in je eigen tests**, niet in andermans map.
- JS-modules importeren elkaar **alleen via de `index.js`** van een module (`../colors/index.js`), plus `../shared/*.js`.
- Geen globale variabelen behalve `window.BPS` (main) en `window.BPSAdmin` (admin).
- PHP: namespace `BorduurPatchStudio`, prefix `bps_` (functies, opties, hooks), `_bps_` (verborgen meta), REST-namespace `patch-studio/v1`, text domain `borduur-patch-studio`.
- Elke PHP-file begint met `defined( 'ABSPATH' ) || exit;` — **uitzondering** `class-pricing.php`: `if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) { exit; }` (standalone tests).
- PHP ≥ 7.4-syntax-compatibel is niet nodig: **PHP 8.1+** (site draait 8.3). WordPress ≥ 6.4, WooCommerce ≥ 8.8.

---

## 3. Bouwers en hun opdracht (samengevat; volledige opdracht staat in je taaktekst)

| Rol | Levert | Hangt af van (contract) |
|---|---|---|
| core | bootstrap, autoload, activatie, Settings, beheerpagina (5 tabs), uninstall, i18n-infra | §6.1, §7.1, §8 |
| woo | product, shortcode/widget, REST, uploads, cart/checkout/order/e-mails, offertes, bewaren, privacy/cleanup | §6.2–6.6, §7.4–7.5, Pricing (PHP) |
| analysis | `analyzeImage`, worker, `decodeFile`, `estimateStitches`, node-tests | §7.2 |
| colors | thread-palette.json, matching, reductie, contrast, stofkeuze, node-tests | §7.3 |
| advice | `evaluate()` + berichtencatalogus NL/FR, node-tests | §7.7 |
| pricing | JS + PHP engine, gedeelde vectors, tests in node en php | §6.3, §7.5 |
| render | canvas-renderer per techniek, randen, vormen, kledingstuk, PNG-export | §7.6 |
| ui | klant-UI, main.js, CSS, fonts, dev-pagina | alles in §7 |
| production | productiefiche, print, digitale proef + goedkeuring | §6.5–6.6 |
| testenv | WP + Woo + SQLite in scratchpad, start-wp.sh, Playwright-scenario's, run-all.sh | §9 |

---

## 4. Integratie in WordPress (PHP-contracten)

### 4.1 Bootstrap (core)
`borduur-patch-studio.php`:
```php
/**
 * Plugin Name: Patch Studio – Borduurservice België
 * Description: Patches online ontwerpen met deskundig advies, live prijs en digitale proef.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.8
 * Text Domain: borduur-patch-studio
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */
// Source strings: nl_BE
define( 'BPS_VERSION', '1.0.0' );
define( 'BPS_FILE', __FILE__ );
define( 'BPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'BPS_URL', plugin_dir_url( __FILE__ ) );
```
- **Autoloader** (spl_autoload_register): `BorduurPatchStudio\Woo\Order_Meta` → `includes/woo/class-order-meta.php`.
  Regel: namespace-delen na `BorduurPatchStudio\` → mappen in kleine letters met `_`→`-`; klassenaam → `class-` + kleine letters, `_`→`-`.
  Voorbeelden: `BorduurPatchStudio\Settings` → `includes/class-settings.php`; `…\Admin\Settings_Page` → `includes/admin/class-settings-page.php`;
  `…\Rest\Routes` → `includes/rest/class-routes.php`; `…\Frontend\Elementor_Widget` → `includes/frontend/class-elementor-widget.php`.
- HPOS + blocks compat declareren op `before_woocommerce_init` (06 §6c), in het hoofdbestand.
- Activatie/deactivatie: `register_activation_hook` → `Plugin::activate()`; die roept `static activate()` op elke component die het heeft, daarna `do_action('bps_activate')`. Idem `deactivate`.
- Zonder WooCommerce: admin-notice "Patch Studio heeft WooCommerce nodig." en geen woo-componenten laden (Settings en beheerpagina wel).

`Plugin::boot()` (op `plugins_loaded`, prio 20) registreert deze componenten **als de klasse bestaat**; elke component heeft
`public static function register(): void` (hooks toevoegen) en optioneel `activate()`/`deactivate()`:
```php
const COMPONENTS = array(
	'BorduurPatchStudio\Settings',
	'BorduurPatchStudio\Admin\Settings_Page',
	'BorduurPatchStudio\Woo\Integration',        // woo: registreert zelf cart/order/e-mails/designs/quotes/privacy/cron
	'BorduurPatchStudio\Rest\Routes',
	'BorduurPatchStudio\Frontend\Shortcode',     // + Elementor-widget via 'elementor/widgets/register'
	'BorduurPatchStudio\Admin\Order_Production',
);
// filter: apply_filters( 'bps_components', COMPONENTS )
```
Core regelt ook: `load_plugin_textdomain` op `init`; asset-registratie (§4.3); WP Rocket-uitsluitingen (06 §9: `rocket_delay_js_exclusions`
met `/borduur-patch-studio/assets/` en `bps-app`, RUCSS-safelist `rocket_rucss_external_exclusions` voor onze CSS);
cache-purge (`rocket_clean_domain()` als die bestaat) op `bps_settings_updated`.

### 4.2 Settings (core) — PHP-signaturen
```php
namespace BorduurPatchStudio;
final class Settings {
	const OPTION = 'bps_settings';                  // enkel overrides t.o.v. defaults, autoload=yes
	public static function register(): void;
	public static function defaults(): array;       // config/defaults.json + thread-palette.json → ['threads'=>…]; per request gecachet
	public static function get(): array;            // deep_merge(defaults, overrides) → apply_filters('bps_config', $c); gecachet
	public static function get_path( string $dot, $default = null ); // 'techniques.embroidered.basePrice.8'
	public static function update( array $overrides ): void;         // valideert, bewaart enkel verschil met defaults, do_action('bps_settings_updated', $c)
	public static function reset( ?string $top_key = null ): void;   // tab "Herstel standaard"
	public static function deep_merge( array $base, array $over ): array; // assoc recursief, lijsten (array_is_list) VERVANGEN
	public static function public_config( ?string $locale = null, array $page = array() ): array; // §4.4
	public static function product_id(): int;      // woo.productId of optie bps_product_id (gezet door woo)
}
```
`public_config()` = identiek aan JS `buildPublicConfig()` (shared/config.js): verwijder `woo`, `retention`, `security`, `admin`,
`techniques.*.legacy`; filter `threadsDisabled` en `stock:false`; voeg toe: `locale` (`str_replace('_','-',determine_locale())`, enkel `nl-BE`/`fr-BE`; FR-locales → `fr-BE`, rest → `nl-BE`),
`rest` = `{ root: esc_url_raw( rest_url( 'patch-studio/v1/' ) ), storeApi: rest_url('wc/store/v1/'), cartUrl: wc_get_cart_url() }`,
`assets` = `{ workerUrl, fontsUrl, version }`, `strings` (PHP-overrides per JS-sleutel, standaard `{}`), `page` (shortcode-attributen §4.4).
Dan `apply_filters( 'bps_public_config', $public, $locale )`.

### 4.3 Assets (core registreert, shortcode/widget enqueuet)
| Handle | Bestand | Opmerking |
|---|---|---|
| `bps-app` | `assets/dist/patch-studio.js` | deps `[]`, `in_footer`, `strategy: defer`, versie `BPS_VERSION . '-' . filemtime()` |
| `bps-style` | `assets/css/patch-studio.css` | alleen op pagina's met de tool |
| `bps-admin` | `assets/dist/patch-studio-admin.js` (+ `.css` indien gebouwd) | enkel op orderscherm/print (production enqueuet) |
Worker-URL = `assets/dist/patch-studio-worker.js?ver=…` via `config.assets.workerUrl` (UI start de worker; bij cross-origin/CDN: Blob-URL-fallback, 07 §9).

### 4.4 Shortcode, widget en root-markup (woo)
`[patch_studio techniek="" maat="" aantal="" vorm="auto" compact="no"]` en Elementor-widget `bps-patch-studio` roepen **dezelfde**
`Frontend\Shortcode::render( array $atts ): string` aan (06 §10). Output:
```html
<div class="bps-studio" data-bps-root data-bps-version="1.0.0" lang="nl-BE">
  <script type="application/json" class="bps-config">{…public_config…}</script>
  <div class="bps-skeleton">…server-side kop, dropzone met echte <input type=file>, "Start met tekst", noscript…</div>
</div>
```
`page` in de config: `{ technique: ''|TechniqueId, sizeCm: number|null, qty: number|null, shape: 'auto'|ShapeId, compact: bool }`.
JSON met `wp_json_encode( $c, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )`.
`main.js` mount op `DOMContentLoaded` (of meteen als het document al geladen is) alle `[data-bps-root]`; in de Elementor-editor ook via
`elementorFrontend.hooks.addAction('frontend/element_ready/bps-patch-studio.default', …)` maar **nooit** daarop wachten op de live pagina.

---

## 5. Build-aanpak

### 5.1 Commando's (in `patch-studio/`)
```bash
npm install            # esbuild (devDependency)
npm run build          # → plugin/borduur-patch-studio/assets/dist/{patch-studio.js, patch-studio-worker.js, patch-studio-admin.js}
npm run build:dev      # sourcemaps, niet geminificeerd
npm run watch
npm test               # node --test "tests/**/*.test.js"
npm run test:php       # php tests/pricing/run-php.php
npm run dev            # http://localhost:8787/dev/  (statische server, dev/index.html)
npm run test:all       # bash tests/run-all.sh (unit + php + e2e)
```
### 5.2 Regels
- Bronnen: **ES-modules** (`import/export`, `.js`, `"type":"module"` in package.json). Geen CommonJS in `assets/src`.
  (Scripts die `require` gebruiken, zoals `docs/research/07-testharness.js`, moet je als `.cjs` kopiëren in je eigen scratch.)
- esbuild bundelt naar **IIFE**, target `es2019, safari14`, minify. Entry points: `assets/src/main.js` (globalName `BPS`),
  `assets/src/analysis/worker.js` (geen global), `assets/src/admin/index.js` (globalName `BPSAdmin`). Ontbrekende entries worden overgeslagen.
- JSON importeren mag (`import defaults from '../../config/defaults.json'`) — **alleen** in `dev/` en tests; de plugin-bundle krijgt config via de pagina.
- Geen DOM-code in `analysis/`, `colors/`, `advice/`, `pricing/`, `shared/` (moeten in Node en in de worker draaien). `render/` mag `OffscreenCanvas`/canvas gebruiken maar geen `document` buiten `createRenderer`.
- Zware dingen lazy: PDF.js is **niet** in scope (fase 2). Lettertypes via `FontFace` pas bij de eerste tekstlaag.
### 5.3 Budget (build faalt als overschreden)
| Bestand | gzip-budget |
|---|---|
| `patch-studio.js` (ui + advice + colors + pricing + render + analysis-wrapper) | **≤ 100 kB** (was 60; herzien door integrator: enkel geladen op de studiopagina, NL+FR-catalogi zitten erin — zie docs/integratie.md) |
| `patch-studio-worker.js` | ≤ 25 kB |
| `patch-studio-admin.js` | ≤ 15 kB |
| `assets/css/patch-studio.css` | ≤ 12 kB gzip (richtlijn) |
| thread-palette in de config | ≤ 10 kB JSON |
Prestaties: analyse ≤ 600 ms op middenklasse-gsm (worker, UI blijft vloeiend); render ≤ 50 ms bij 1000 px (`quality:'high'`), ≤ 16 ms bij `fast`;
prijs + advies ≤ 5 ms per wijziging; time-to-interactive van de tool ≤ 1 s na DOMContentLoaded op desktop.

---

## 6. Server-gedrag (woo, pricing, production)

### 6.1 Ontwerpopslag (woo)
- CPT `bps_design` (`public=>false, show_ui=>false, exclude_from_search, publicly_queryable=>false`, `capability_type` → `manage_woocommerce`).
- `post_title` = ref `PS-YYYY-NNNN` (teller in optie `bps_ref_seq`, per jaar). Offerte-ref: `OF-YYYY-NNNN` (CPT `bps_quote`, `show_in_menu => 'woocommerce'`, label "Patch-offertes").
- Meta (`update_post_meta` mag: geen orders):

| Meta-sleutel | Inhoud |
|---|---|
| `_bps_payload` | JSON van de gevalideerde `DesignPayload` (§7.4) |
| `_bps_status` | `draft` → `carted` → `ordered` · of `quoted` · of `saved` |
| `_bps_original` | `{path, name, ext, bytes}` (privé) of leeg bij tekst-only |
| `_bps_preview` | `{path, url}` publieke PNG (her-gecodeerd, max 1200 px, onraadbare naam) |
| `_bps_order_id`, `_bps_order_item_id` | na bestelling |
| `_bps_email` | enkel bij bewaren/offerte |
| `_bps_proof` | `{status:'none'|'sent'|'approved'|'changes_requested', sent_at, responded_at, note, image_url, history:[{at, action, by, note}]}` — **production schrijft** |
| `_bps_created_gmt`, `_bps_last_opened_gmt` | voor token en bewaartermijn |
- Token: `hash_hmac( 'sha256', $id . '|' . $created_gmt, wp_salt( 'auth' ) )` (64 hex). Nooit opslaan, altijd herberekenen.
- Publieke PHP-API (production en andere code gebruiken alleen deze):
```php
namespace BorduurPatchStudio\Woo;
final class Designs {
	public static function get( int $id ): ?array;      // DesignRecord (hieronder) of null
	public static function token_for( int $id ): string;
	public static function verify_token( int $id, string $token ): bool;   // hash_equals
	public static function set_status( int $id, string $status ): void;
	public static function update_proof( int $id, array $proof ): void;    // merge in _bps_proof
	public static function original_download_url( int $id ): string;       // admin-post bps_download + nonce
}
// DesignRecord = [ 'id'=>int, 'ref'=>string, 'status'=>string, 'created_gmt'=>string, 'payload'=>array(DesignPayload),
//   'original'=>?array{path,name,ext,bytes}, 'preview_url'=>string, 'order_id'=>?int, 'order_item_id'=>?int,
//   'email'=>?string, 'proof'=>array ]
```
- Uploads (`Uploads`, woo): privémap `wp-content/uploads/patch-studio/private/YYYY/MM/<32 tekens>.<ext>` (+ `.htaccess` deny + `index.php`),
  previews `…/patch-studio/previews/<32 tekens>.png`. Magic-bytes-sniff (png, jpg, webp, gif, svg, pdf/ai, eps, psd). SVG gesaneerd
  (enshrined/svg-sanitize via composer+Strauss, of — als dat niet lukt — weigeren met vriendelijke melding). Max `woo.maxUploadMb`.
  `Uploads::self_test(): array{ok:bool, message:string}` (06 §7a) — core toont het in tab Algemeen.
- Admin-download: `admin-post.php?action=bps_download&design=ID&_wpnonce=…` (capability `edit_shop_orders`, `Content-Disposition: attachment`, nosniff).
- WP-Cron `bps_cleanup` (dagelijks): `draft`/`carted` ouder dan `retention.orphanDays` → bestanden + post weg; `saved` na `savedDays` sinds laatste opening;
  `quoted` na `quoteDays`; `ordered` na `orderYears` jaar sinds de laatste bestelling (laat ordermeta "Ontwerpbestand verwijderd op {datum}").
- Privacy: `wp_add_privacy_policy_content` (tekst 08 §2.5), exporter/eraser op e-mail (08 §2.6).

### 6.2 REST-API (woo) — namespace `patch-studio/v1`
Algemeen: JSON; fouten = WP_Error-JSON `{code, message, data:{status}}` met **Vlaamse, klantvriendelijke** `message` (UI toont die).
Alle antwoorden `nocache_headers()` + `Cache-Control: no-store`. Schrijfroutes: `permission_callback` = `Guard::public_write`
(header **`X-BPS-Token`** = `wp_create_nonce('bps_public')` uit `/token`; voor ingelogde gebruikers ook `X-WP-Nonce`), rate-limit per IP-hash
(`woo.rateLimits`), honeypot waar vermeld. Bij `403 bps_bad_token`: UI haalt één keer een nieuw token en herhaalt.

| Methode + pad | Body | Antwoord 200 | Fouten |
|---|---|---|---|
| `GET /token` | — | `{ token: string, expires: number (unix) }` | — |
| `POST /quote` | `PriceInput` (§7.5) + `locale` | `PriceQuote` (server = PHP-engine) | 400 `bps_invalid` |
| `POST /designs` (multipart) | `design` = JSON `DesignPayload`; `original` = bestand (optioneel bij tekst-only); `preview` = PNG (verplicht, canvas-export ≥ 600 px); `website` honeypot | `{ designId, ref, token, previewUrl }` (201) | 400 `bps_invalid_design`, 413 `bps_too_big`, 415 `bps_type`, 429 `bps_rate` |
| `GET /designs/{id}?token=` | — | `{ designId, ref, payload, previewUrl, originalUrl|null }` (originalUrl = tijdelijk gesigneerd, 1 u, enkel png/jpg/webp/svg) | 403 `bps_forbidden`, 404 |
| `POST /cart` | `{ designId, token, rightsConsent:{v, accepted:true} }` | `{ ok:true, cartUrl, cartCount, cartItemKey, quote: PriceQuote }` | 400 `bps_rights_required`, 400 `bps_invalid_design`, 409 `bps_quote_only`, 400 `bps_min_qty` |
| `POST /quotes` | `{ designId, token, name, email, phone?, company?, vat?, qty, technique?, deadline?, message?, consent:true, rightsConsent:{v,accepted}, website:'' , elapsed:int, locale }` | `{ ok:true, ref }` | 400 `bps_invalid`, 429 |
| `POST /designs/{id}/email` | `{ token, email, reminder:false, locale }` | `{ ok:true }` | 400, 429 |

Details:
- `/designs`: server **valideert** de payload met een whitelist (enum techniek/vorm/lettertypes/opties uit de config, getallen begrensd,
  max 10 teksten × 60 tekens, notes ≤ 2000) en slaat ze op. `priceClient` wordt enkel gelogd bij afwijking.
- `/cart`: `wc_load_cart()`, sessie voor gasten, rechtenverklaring verplicht, techniek moet `mode:'order'` zijn en de maat ≤ `maxOrderCm`,
  aantal ≥ `minQty` en veelvoud van `qtyStep`. Voegt `Settings::product_id()` toe met aantal `payload.qty` en cart item data `bps` (§6.4).
  Daarna `WC()->cart->calculate_totals()` en antwoord met de **server-PriceQuote** voor deze regel (UI vergelijkt; verschil > € 0,01 → server wint, UI toont serverbedrag).
- UI-flow bij "Bestel deze patch": (1) `POST /designs` (hergebruik `designId` als de inhoudshash niet veranderde) → (2) `POST /cart` →
  (3) als `woo.redirectToCart`: `location = cartUrl`, anders melding "Toegevoegd ✓ — Verder winkelen / Naar winkelmand" en
  `document.body.dispatchEvent(new Event('wc_fragment_refresh'))` + jQuery-trigger als jQuery bestaat.
- Herstellen via link: de bewaarmail bevat `{pagina-url}?bps_design={id}&bps_token={token}`; UI leest dat en laadt `GET /designs/{id}`.

### 6.3 Prijsengine (pricing) — één algoritme in JS en PHP
Alle bedragen in de config zijn **excl. btw**. `vat = config.vat.rate`. Afronding met `roundHalfUp` (shared/money.js; PHP-tweeling):
```php
public static function round_half_up( float $x, int $d = 2 ): float {
	$s = $x < 0 ? -1 : 1;
	return $s * floor( (float) sprintf( '%.15g', abs( $x ) * 10 ** $d ) + 0.5 ) / 10 ** $d;
}
```
Algoritme `quote(input, config)`:
1. `tech = config.techniques[input.technique]`; onbekend/uitgeschakeld → error `unknown_technique`.
   `tech.mode === 'quote'` → `mode:'quote'`, error `quote_only`, bedragen 0, `ok:false`.
2. `sizeCm < tech.minCm` → `size_too_small`; `sizeCm > tech.maxOrderCm` → `size_too_large` + `mode:'quote'`.
   `pricedSizeCm` = kleinste waarde in `sizes.pricedCm` ≥ `sizeCm`; `base = tech.basePrice[String(pricedSizeCm)]`.
3. Aantal: `qty < tech.minQty` → `below_min_qty {min}`; `qty % tech.qtyStep !== 0` → `qty_step {step}` (UI corrigeert vooraf).
4. Opties normaliseren: optie bestaat niet of niet voor deze techniek / `enabled:false` → `invalid_option {id}`;
   optie met `requiresAny` waarvan niets gekozen is → **stil weglaten**; radio-groep met > 1 gekozen → `option_conflict {group}`;
   verplichte radio-groep die van toepassing is (heeft opties voor de techniek, en `showIfAny` voldaan) zonder keuze → `missing_required_option {group}`.
5. `pp = Σ price.amount` van `per_piece`-opties (met `priceByTechnique`-override), `pct = Σ amount` van `percent`-opties.
6. Staffel: als `tech.tiers`: `tierQty = qty + (config.tiers.pooled ? poolQty : 0)`, `tier` = hoogste stap met `min ≤ tierQty`; anders `{min:1,discount:0}`, `tierQty = qty`.
7. `unitExcl = round4( (base + pp) × (1 + pct) × (1 − tier.discount) )`
   `lineExcl = round2( unitExcl × qty )`, `lineTax = round2( lineExcl × vat )`, `lineIncl = round2( lineExcl + lineTax )`, `unitIncl = round2( lineIncl / qty )`.
8. Extra's: elke `per_line`-optie → `{key:'extra:<id>', label, excl:amount, tax:round2(amount×vat), incl}`.
9. Fees: als `tech.feeGroup` en die groep **niet** in `feesAlreadyCharged` → `{key:'fee:<group>', label, excl, tax, incl}` uit `config.fees.groups`.
10. `subtotalExcl/Incl` = som (round2). Verzending (schatting): `articles = lineIncl + otherItemsInclVat`; gratis als `articles ≥ shipping.freeFromInclVat`,
    anders `excl = shipping.flat`, `tax = round2(flat×vat)`; `remainingForFreeInclVat = round2(freeFrom − articles)` of `null` als gratis.
11. `totalExcl/Incl` = subtotal + verzending. `perPieceAllInIncl = round2( subtotalIncl / qty )`.
12. `ladder` (alleen als `tech.tiers`): voor elke stap met `min ≥ tech.minQty` een herberekening met `qty = min` (zelfde opties/pool, zonder ladder);
    `nextTier` = eerste stap met `min > tierQty`, berekend met `qty = min − poolQty`; `savingPerPieceIncl = round2(unitIncl − next.unitIncl)`.
13. `breakdown` = [item, …extras, …fees, shipping]. Labels in `input.locale` (of `config.locale`) uit de `{nl,fr}`-labels van de config;
    item-label: "{qty} × {techniek} {maat} cm".
14. `ok = mode==='order' && errors.length===0`.

**Gecontroleerde kassavoorbeelden** (02 §9) — moeten als vectors in `tests/pricing/vectors.json` (verplicht, allemaal incl. btw):

| Invoer | lineIncl | fees | verzending | totalIncl |
|---|---|---|---|---|
| embroidered 4 cm × 1, [cut_from_fabric, sew_on, thickness_normal] | 3,03 | 54,45 | 12,10 | **69,58** |
| embroidered 4 cm × 10 | 28,74 | 54,45 | 12,10 | **95,29** |
| embroidered 12 cm × 1 | 8,68 | 54,45 | 12,10 | **75,23** |
| embroidered 10 cm × 25 + iron_on + on_film | 278,52 | 54,45 | 0 (≥ 150) | **332,97** |
| printed 4 cm × 1 | 4,24 | 24,20 | 12,10 | **40,54** |
| printed 4 cm × 10 | 40,23 | 24,20 | 12,10 | **76,53** |
| sublimated 4 cm × 1 [carrier_standard] | 3,03 | 24,20 | 12,10 | **39,33** |
| pvc 4 cm × 50 | 154,28 | 96,80 | 0 (≥ 150) | **251,08** |
| pvc 8 cm × 100 + velcro_hook | 580,80 | 96,80 | 0 | **677,60** |
(De kassa selecteerde in 02 standaard het betaalde verzendtarief ook als gratis kon; onze schatting toont gratis — dat is de eerlijke weergave.)
Plus randgevallen: 7 cm → prijs van 8 cm; 13 cm → quote; woven → quote_only; pvc × 40 → below_min_qty; pvc × 55 → qty_step;
self_cut −10 %; flock per_line; extra's; poolQty 15 + qty 10 → 10 %-trede; gesublimeerd × 100 → geen korting; feesAlreadyCharged.

PHP-signatuur (pricing):
```php
namespace BorduurPatchStudio;
final class Pricing {
	public static function quote( array $input, ?array $config = null ): array;   // zelfde sleutels (camelCase) als JS PriceQuote; $config null → Settings::get()
	public static function round_half_up( float $x, int $d = 2 ): float;
	public static function tier_for( int $tier_qty, array $config ): array;          // ['min'=>int,'discount'=>float]
	public static function cart_unit_excl( array $bps, int $qty, int $pool_qty, ?array $config = null ): float; // unitExcl voor een mandregel
}
```
`class-pricing.php` gebruikt **geen** WordPress-functies (behalve via `Settings::get()` als `$config === null`), zodat `tests/pricing/run-php.php`
het standalone kan laden met `define('BPS_TESTING', true)`.

### 6.4 Winkelmand, kassa, bestelling (woo)
**Product**: bij activatie (en als het ontbreekt) een `WC_Product_Simple` "Patch op maat" (`woo.productName`), prijs 0, `catalog_visibility` = `woo.productVisibility` (`hidden`),
belastbaar, niet virtueel, `sold_individually=false`, meta `_bps_patch_product=1`; ID in optie `bps_product_id`. Directe "In winkelmand" zonder ontwerp → validatie weigert met link naar de studiopagina.

**Cart item data** (sleutel `bps`, enkel scalars/kleine arrays, nooit afbeeldingen):
```php
$cart_item['bps'] = array(
	'v'           => 1,
	'design_id'   => 123,
	'ref'         => 'PS-2026-0042',
	'technique'   => 'embroidered',
	'size_cm'     => 8.0,
	'shape'       => 'circle',
	'options'     => array( 'cut_from_fabric', 'iron_on', 'thickness_normal' ),
	'preview_url' => 'https://…/patch-studio/previews/abc….png',
	'rights'      => array( 'v' => '2026-11-1', 'at' => '2026-11-16T10:00:00Z', 'locale' => 'nl_BE' ),
);
```
**Prijzen in de mand**:
- `woocommerce_before_calculate_totals` (prio **20**): pool = Σ qty van `bps`-items waarvan de techniek `tiers:true` heeft (als `tiers.pooled`);
  per item `$item['data']->set_price( Pricing::cart_unit_excl( $bps, $qty, $pool - $qty ) )` (absolute prijs → idempotent).
- WDR uitsluiten: `advanced_woo_discount_rules_calculate_discount_for_cart_item` → false en `advanced_woo_discount_rules_include_cart_item_to_count_quantity` → false voor `bps`-items.
- `woocommerce_cart_calculate_fees`: per fee-groep die voorkomt bij `bps`-items één fee (`fees.scope='order'`) of één per ontwerp (`'design'`, label + ref),
  belastbaar (standaard tarief). Sla een groep over als `woo.skipFeeIfLegacyInCart` en de mand een legacy-product van die groep bevat (Conditional Extra Fees rekent dan al).
  Per-regel-extra's (`per_line`) → fee "{label} ({ref})".
- Aantal wijzigen in de mand: `woocommerce_update_cart_validation` + `woocommerce_quantity_input_args` dwingen `minQty`/`qtyStep` af.
**Weergave** (klassiek + blokken): `woocommerce_get_item_data` → regels "Ontwerp: PS-2026-0042", "Techniek: Geborduurd", "Maat: 8 cm (80 × 80 mm)",
"Vorm: Rond", "Afwerking: …", "Maatwerk: Geen herroepingsrecht · digitale proef vóór productie" (08 §1.4-C);
`woocommerce_cart_item_thumbnail` + `woocommerce_store_api_cart_item_images` → preview (`skip-lazy`, `data-no-lazy="1"`).
Kassa: `woocommerce_review_order_before_submit` melding D (08 §1.4) als er een `bps`-item is; `woocommerce_order_button_text` → "Bestellen en betalen" als `legal.fixOrderButton`.

**Order item meta** (`woocommerce_checkout_create_order_line_item`, werkt ook voor de blok-kassa; alles via CRUD → HPOS-veilig):

| Sleutel | Zichtbaar | Inhoud |
|---|---|---|
| `bps_ref` | ja (label "Ontwerp") | `PS-2026-0042` |
| `bps_technique` | ja ("Techniek") | vertaald label |
| `bps_size` | ja ("Maat") | "8 cm (80 × 80 mm)" |
| `bps_shape` | ja ("Vorm") | vertaald label |
| `bps_finish` | ja ("Afwerking") | opties als tekst, komma-gescheiden |
| `bps_rights` | ja ("Rechtenverklaring") | "Bevestigd op {datum} om {uur} (tekstversie {v})" |
| `_bps_design_id` | nee | int |
| `_bps_preview_url` | nee | URL |
| `_bps_schema` | nee | 1 |
| `_bps_technique_id`, `_bps_size_cm`, `_bps_shape_id` | nee | ruwe waarden |
| `_bps_options` | nee | JSON-array van optie-id's |
| `_bps_unit_excl` | nee | float (server) |
| `_bps_payload` | nee | JSON-snapshot van de `DesignPayload` op het moment van bestellen |
| `_bps_rights_consent` | nee | `{v, at, text_sha1, locale}` |
Leesbare labels via `woocommerce_order_item_display_meta_key` (technische sleutel → vertaald label).
Na de bestelling (`woocommerce_checkout_order_processed` én `woocommerce_store_api_checkout_order_processed`): `Designs::set_status('ordered')`,
`_bps_order_id`/`_bps_order_item_id` zetten, `do_action('bps_design_ordered', $design_id, $order, $item)`.
E-mails: preview via `woocommerce_email_order_items_args` (`show_image`) + `woocommerce_order_item_thumbnail`; blok F (08 §1.4) via
`woocommerce_email_after_order_table` als er een `bps`-item is.

**Offerte** (`/quotes`): CPT `bps_quote` (private) met meta contact/aantal/techniek/ontwerp/locale; ontwerp-status `quoted`; e-mails via
eigen `WC_Email`-klassen (`BPS_Email_Quote_Admin` met Reply-To klant, `BPS_Email_Quote_Customer` in de taal van de klant; templates in `templates/emails/`).
**Bewaren & mailen**: `BPS_Email_Saved_Design` (preview, samenvatting, prijs, knop "Verder met mijn ontwerp").

### 6.5 Productiefiche (production)
- Op het orderscherm (klassiek en HPOS; `woocommerce_after_order_itemmeta` of een metabox op `wc_get_page_screen_id('shop-order')`):
  per `bps`-item een **kaart**: preview (groot), ref, techniek, **maat in mm** (b × h), vorm, rand + randkleur (garencode), achterkant (+ klittenbandkleur/extra's),
  stof/achtergrondkleur, **garenkleuren met Polyneon-codes** en aandeel, tekstlagen (tekst, lettertype, hoogte mm, garencode, vorm),
  geschat steekaantal (bereik), analyse-samenvatting (soort, kleuren, fijnste lijn mm, kleinste tekst mm, waarschuwingen in mensentaal),
  opmerking klant, **download origineel** (`Designs::original_download_url`), status digitale proef.
- Bronnen: order item meta (§6.4) + `Designs::get( (int) $item->get_meta('_bps_design_id') )`. Valt de design-post weg (cleanup), toon de snapshot `_bps_payload`.
- **Printbare fiche**: `admin-post.php?action=bps_print_fiche&order={id}&_wpnonce=…` → `templates/admin/fiche-print.php` (A4, zwart-wit leesbaar, 1 patch per pagina, QR/ref groot).
- Assets: `assets/src/admin/index.js` (+ css) → `bps-admin`, enkel op het orderscherm en de printpagina.

### 6.6 Digitale proef (production)
- Knop **"Digitale proef versturen"** per patch: dialoog met proefafbeelding (standaard de preview-PNG; optioneel eigen bestand uploaden via de mediabibliotheek), boodschap aan de klant.
- Verstuurt een `WC_Email`-klasse `BPS_Email_Proof` (template `templates/admin/emails/proof.php`) naar het factuuradres, in de taal van het ontwerp (`switch_to_locale`),
  met afbeelding, samenvatting en twee knoppen: **"Ik keur goed"** en **"Ik wil iets aanpassen"** (+ tekstveld op de bevestigingspagina).
- Links: `home_url('/?bps_proof={designId}&o={orderId}&t={token}&a=approve|changes')`, `token = hash_hmac('sha256', "proof|{designId}|{orderId}|{n}", wp_salt('auth'))`
  (`n` = proefronde). Afgehandeld op `template_redirect` door production; bevestigingspagina `templates/admin/proof-response.php` (eenvoudig, in thema-stijl, noindex).
- Effect: `Designs::update_proof()`, order-notitie, mail naar de winkel, `do_action('bps_proof_response', $design_id, 'approved'|'changes', $note)`;
  `do_action('bps_proof_sent', $design_id, $order_id)` bij verzenden. Orderstatus wijzigt **niet** automatisch (eigenaar beslist; open punt).

---

## 7. Contracten (JS)

> De volledige JSDoc-typen staan in `assets/src/shared/types.js`. Hieronder de functies per module. Elke module exporteert
> exact deze namen vanuit zijn `index.js`. Extra exports mag; deze namen en vormen veranderen niet.

### 7.1 Config (gedeeld)
- In de browser: `config = JSON.parse(root.querySelector('script.bps-config').textContent)` = **publieke config** (defaults + overrides − privé-sleutels + runtime).
- Vorm van de config: zie `config/defaults.json` (§8) — dat bestand is het schema. Aanvullingen enkel met een SPEC-update (`schema` +1 bij breuk).
- Helpers in `shared/config.js`: `deepMerge`, `buildPublicConfig(defaults, palette, runtime, overrides)`, `technique`, `enabledTechniques`,
  `optionsFor(config, tech)`, `optionById`, `optionPrice(opt, tech)`, `defaultOptions(config, tech)`, `label(obj, locale)`.

### 7.2 analysis
```js
// assets/src/analysis/index.js
export async function decodeFile(file, config)
  // → { kind:'raster'|'svg'|'unsupported', ext:string, bitmap?:ImageBitmap, imageData?:ImageDataLike, svgText?:string, reason?:'type'|'size'|'decode' }
  // raster: createImageBitmap(file,{imageOrientation:'from-image'}) met <img>-fallback; svg: tekst parsen, op main thread rasteriseren (2048 px);
  // pdf/ai/eps/psd → kind 'unsupported' (bestand wordt wel meegestuurd). > analysis.maxFileMb → reason 'size'.
export function createAnalyzer({ workerUrl, config })
  // → { analyze(decoded, { job, onProgress }): Promise<AnalysisResult>, cancel(job), terminate() }
  // Draait in een Worker (OffscreenCanvas); fallback op main thread met await-pauzes tussen de passes. Oude jobs worden genegeerd.
export async function analyzeImage(input, config, { job = 1, svgText = null, onProgress = null } = {})
  // Pure kern (ook in Node): input = ImageDataLike {width,height,data:Uint8ClampedArray} of ImageBitmap. → AnalysisResult
export function estimateStitches(regions, mmPerPx, colorCount, stitchCfg, { perimeterMm = 0, satinBorder = false } = {})
  // → { estimate:number, min:number, max:number }   (07 §11.2; afgerond op stitchCfg.roundTo)
```
Worker-protocol: main → worker `{type:'analyze', job, bitmap|imageData, svgText, config: config.analysis}` (transfer);
worker → main `{type:'progress', job, stage, partial}` per stage in volgorde `background, shape, colors, details, preview` en tot slot
`{type:'done', job, result}` (transfer `result.preview.data.buffer`, `result.labels.buffer`) of `{type:'error', job, code, message}`.
Eisen: CIELAB-kwantisatie (gewogen k-means++, k 16, seed vast → **deterministisch**), achtergrond/transparantie + vorm (demo-v2 + 07 §4),
classificatie flat/gradient/illustration/photo (07 §6; eigenaar-PNG = illustration + isMockup), fijnste lijn en kleinste tussenruimte
(distance transform, patchrand genegeerd), kleine-tekst-heuristiek (07 §7), resolutie-check (`lowRes` als artBox < `analysis.lowResMinPx` bronpx),
halo-ontsmetting, `preview` + `labels`. Analyseert NIET de prijs of het advies.

### 7.3 colors
```js
// assets/src/colors/index.js
export function prepareThreads(threads)                       // → PreparedThread[] (Thread + lab), gecachet per array
export function matchColor(hexOrLab, prepared, config, n = 3) // → ThreadMatch  (zwart/wit-speciaal via config.threadSpecial en analysis.white/black)
export function matchPalette(palette, prepared, config)       // → ThreadMatch[] (zelfde volgorde als palette)
export function reducePalette(palette, maxColors, { prepared, config, kL = 1, keep = [] })
  // → ReducedPalette: samenvoegen met oplopende ΔE00-drempels (config.analysis.embroideryMergeSteps) tot ≤ maxColors;
  //   twee kleuren die op hetzelfde garen uitkomen worden samengevoegd; thin-lijnen blijven als dat kan; merges met uitleg.
export function contrastAgainst(colors, fabricHex, config)    // colors: [{hex, share}] → ContrastCheck[] (drempels config.advice.contrast)
export function pickFabric(colors, fabrics, config, { background = null } = {})
  // → { id, hex, reason:'background'|'contrast'|'default' }: beste contrast (gewogen op share), nooit een stof gelijk aan de hoofdkleur.
export function pickEdgeColor(fabricHex, colors, prepared, config) // → Thread (donkerste/dominante contrastkleur, anders zwart/wit)
export function threadByCode(code, threads)                   // → Thread|null
```
`config/thread-palette.json`: `{ "brand": "Madeira Polyneon", "source": "<url>", "threads": [ Thread, … ] }` met **60–120** gangbare kleuren uit
`docs/research/01-garenkleuren-polyneon.json` (code + hex van de site), met **eigen neutrale Nederlandse en Franse namen** (geen Pantone-namen,
geen fabrieksnamen als hoofdnaam); fluo-reeks overslaan of corrigeren; `family` invullen. Code + merk mogen beschrijvend getoond worden ("Polyneon 1747").
UI-zin: "Schermkleuren zijn benaderingen; garen glanst en kan afwijken. We stemmen de kleuren af in je digitale proef."

### 7.4 Ontwerpstaat en payload (ui ↔ server ↔ production)
`DesignState` en `DesignPayload`: zie types.js. De UI is de enige eigenaar van de staat (één store, onveranderlijke updates).
Persistentie: `sessionStorage['bps:state:v1']` na elke wijziging (try/catch, zonder afbeelding — enkel keuzes + `source.hash`);
"Bewaar op dit toestel" → ook `localStorage` (08 §2.7). Het geüploade bestand zelf: in geheugen (`File`), bij herstel uit `localStorage`/link
opnieuw ophalen via `GET /designs/{id}` als het al geüpload was, anders "Upload je bestand opnieuw om verder te gaan" (eerlijk).
Automatische keuzes na analyse (alleen als de klant ze niet zelf koos): `technique = advice.recommended`, `shape = analysis.shape.type ?? 'circle'`
(bij `shape.confidence < analysis.shapeMinConfidence`: vorm voorstellen + "Klopt dit?"-keuze), `sizeCm = advice.size.recommendedCm` (enkel bij de eerste analyse),
`fabric = colors.pickFabric(...)`, `options = defaultOptions(config, technique)` bij techniekwissel (behoud wat nog geldig is),
`qty` naar `minQty` en veelvoud van `qtyStep`.

### 7.5 pricing (JS)
```js
// assets/src/pricing/index.js
export function quote(input, config)        // PriceInput → PriceQuote (algoritme §6.3; identiek aan PHP)
export function quoteAll(input, config)     // → {[techniqueId]: PriceQuote} voor alle ingeschakelde technieken (techniekkaarten), elk met eigen defaultOptions
export function tierFor(tierQty, config)    // → {min, discount}
```
`tests/pricing/vectors.json`: `[{ "name": string, "input": PriceInput & {locale?}, "configOverrides": {} , "expect": { "ok", "mode", "errors":[codes],
"unitExcl", "lineExcl", "lineIncl", "feesIncl", "extrasIncl", "subtotalIncl", "shippingIncl", "totalIncl", "perPieceAllInIncl", "tierDiscount" } }]`.
Node-test en `tests/pricing/run-php.php` lezen hetzelfde bestand en vergelijken **exact** (`===` na round2). Minstens de 9 kassavoorbeelden + 10 randgevallen (§6.3).

### 7.6 render
```js
// assets/src/render/index.js
export function createRenderer(canvas, { config })
  // → { draw(opts: RenderOptions): RenderResult, toBlob(opts, { size = 1200, type = 'image/png' }): Promise<Blob>, dispose() }
export function renderThumb(opts, size)      // → HTMLCanvasElement|OffscreenCanvas (vergelijk-weergave, kaarten)
export function artFromAnalysis(analysis)    // → RenderArt.image (canvas uit analysis.preview), gecachet per job
```
- Lay-out komt **altijd** uit `shared/geometry.js#layoutPatch()` (de UI geeft `layout` mee); render verandert geen maten.
- Look per techniek (`config.techniques[t].render`): `embroidered` (satijnglans met steekrichting per vlak, tatami-textuur in grote vlakken, lichte
  reliëfschaduw, gereduceerde garenkleuren via `labels` + `reducedMap/reducedHex`), `woven` (fijne weefstructuur, platter), `printed`/`sublimated`
  (exact beeld, lichte stofstructuur; sublimated: wit = drager), `pvc` (vlakke niveaus, afgeronde reliëfrand, glans), `chenille` (lussen-textuur,
  viltrand), `leather` (monochroom gegraveerd in `leathers[].engrave`, geschroeide laserrand).
- Randen (`layout.edge.style`): `satin` (wikkelsteek-patroon; `fray` = subtiele rafels), `merrow` (dikke omgezoomde wikkelsteek), `laser`, `molded`, `felt`,
  `uncut` (patch op rechthoekig stuk stof met stippellijn). Vormen: circle, oval, rect, rounded (`shapes.rounded.cornerRatio`), shield, contour (silhouet van
  `labels` gedilateerd met `shapes.contour.offsetMm`).
- **Achtergrond (BRIEF "altijd een volle patch")**: `backgroundHex` (overgenomen achtergrond) of `fabricHex` vult de hele patchvorm; het ontwerp ligt erboven,
  geknipt op de vorm; rand erover; nooit lege of afgesneden stukken. Tekstlagen in garenkleur met de gekozen fonts (wacht op `document.fonts`).
- `view:'garment'`: polo-borst, pet-voorkant, jas-rug als vector-silhouet (Path2D, geen afbeeldingen) op ware schaal (`mockups[*].scaleRefMm`), met zachte schaduw.
- `toBlob`: transparante achtergrond, enkel de patch, voor winkelmand/e-mail/productiefiche (≥ 600 px; standaard 1200).
- Prestaties: offscreen textuurcache per (techniek, kleur, px-schaal); `quality:'fast'` tijdens slepen; ≤ 50 ms bij 1000 px.

### 7.7 advice
```js
// assets/src/advice/index.js
export function evaluate(input)   // AdviceInput → AdviceResult (puur, deterministisch, ≤ 5 ms)
export const messages             // { nl:{…}, fr:{…} } — catalogus met sleutels 'advice.*'
// AdviceInput = { analysis: AnalysisResult|null, design: DesignState, config, locale,
//   colors: { reduced: ReducedPalette|null, matches: ThreadMatch[]|null, contrast: ContrastCheck[]|null, fabricHex: string },
//   quotes?: {[techniqueId]: PriceQuote} }
```
Regels (bronnen 04 §9/§13/§14, 05 §4, 07 §6):
- Metingen in mm via `layoutPatch()` **per techniek** (rand/safe zone verschilt): `fineLineMm = fineLinePx × mmPerArtPx`, `minTextMm` = kleinste
  `capPx` (of `xPx`/0,6) × mmPerArtPx, tekstlagen: `heightMm` × (`serifScriptFactor` bij `font.thin|serif|script`).
- Status per techniek: **bad** als een harde grens geschonden wordt die niet met een maat ≤ `maxOrderCm`/`maxCm` op te lossen is, of foto bij `photo:false`,
  of kleuren > max zonder vereenvoudigingspad; **warn** als het kan na een concrete aanpassing (groter maken, vereenvoudigen tot N kleuren, rand wijzigen,
  lage resolutie, laag contrast); **ok** anders. Altijd ≥ 1 fix bij warn/bad, altijd een groen alternatief in de ranking.
- Volgorde van oplossingen: **eerst de maat** (`requiredSizeCm` naar geprijsde maat, "Maak 8 cm"), dan kleuren vereenvoudigen, dan techniek wisselen (04 §9.3).
- Ontwerptype → samenvatting (05 §4.3): flat → borduren; illustration → geweven (meeste detail) of geborduurd na vereenvoudiging tot ±k garenkleuren, bedrukt = exact;
  photo → bedrukt/gesublimeerd; gradient → bedrukt exact, borduren in 2–4 banden; patch-foto → "nabestelling, zo getrouw mogelijk".
- Specials: gesublimeerd + belangrijk wit op donkere achtergrond → uitleg "wit = drager"; paars-zonder-garenmatch (quality `none`) → "kies bedrukt voor exact deze kleur";
  merrow bij niet-convexe vorm → rand wijzigen; PVC → minimum 50 + mal; geweven/chenille/leder → `mode:'quote'` met eerlijke tekst (05 §5.3).
- Ranking: score = geschiktheid (status, marge tot comfort-grenzen) − kleine prijsfactor; `advice.preferOrderable` → bij gelijke score (`closeScoreDelta`) wint de direct
  bestelbare techniek; bij gelijk spel zeg het eerlijk ("Geborduurd en geweven zijn hier allebei goed …").
- Upsells (max `advice.maxUpsells`, alleen als relevant): 3D-puff bij tekst-caps ≥ `specials.puff3d.minTextCapsMm` en ≤ 3 kleuren; metallic bij goud/zilverachtige
  paletkleur; klittenband als de klant "uniform/werkkleding/airsoft" kiest of de techniek PVC is; "op folie" bij kleine patch (≤ 6 cm) met fijne rand; staffel
  enkel via de prijskaart (niet hier). Specials met `availability:'on_request'` → actie `{type:'quote'}` met tekst "op aanvraag, we nemen het mee in je proef".
- `trust`: proefbelofte, vanaf 1 stuk, reactietijd (uit `config.promises`), nooit iets dat niet in de config staat.
- Alle teksten via de eigen catalogus + `shared/i18n.js`; elk bericht = `Msg {key, params, text}` met al geformatteerde getallen (komma).

### 7.8 ui en events
- `main.js` exporteert (IIFE `window.BPS`): `mount(el, config?)`, `mountAll()`, `buildPublicConfig` (voor dev), `version`.
- Events (`shared/events.js`, CustomEvents op de root, `bubbles:true`): `bps:design-start`, `bps:analysis-progress`, `bps:analysis-done`, `bps:analysis-error`,
  `bps:state`, `bps:advice`, `bps:quote`, `bps:step`, `bps:added-to-cart`, `bps:quote-requested`, `bps:design-saved`, `bps:error`, `bps:track`.
  Interne UI-componenten communiceren via de store + deze events; geen directe koppeling tussen stappen.
- `bps:track` (en `dataLayer.push`) **alleen** als `features.analyticsEvents` én CookieYes-toestemming (`getCkyConsent?.().categories?.analytics === true`). Geen persoonsgegevens.
- Toegankelijkheid (08 §5.3 is de checklist): echte `<input type=file>` + label, `role="status"` (debounce 500 ms) en `role="alert"`, oordeel als tekst in de DOM
  (+ icoon + woord), `<fieldset><legend>` voor alle keuzes, `<canvas role="img" aria-label>` met dynamische beschrijving, sleepalternatieven, doelen ≥ 44 px,
  zichtbare focus (`outline:3px solid #5926C5; outline-offset:2px`), `<dialog>` voor modals, `prefers-reduced-motion`, werkt op 320 px en 200 % zoom.
- CSS: alles gescoped onder `.bps-studio` (01 §2.5 tokens, §6.3 kit-reset verplicht). Tokens: `--bps-blue:#21A9E2` (accent/rand/actief), `--bps-cta:#0B74A6` (knop met witte tekst),
  `--bps-navy:#0A1F44` (hover/koppen), `--bps-ink:#1E1B1B`, `--bps-muted:#6B6B6B`, `--bps-line:#E3EBF2`, invoerranden `#8A8A8A`, status-tekst ok `#15803D` / warn `#B45309` / bad `#B42318`
  (iconen mogen `#22C55E`/`#F5A524`/`#E5484D`). Fonts: Sora (koppen/knoppen) + Assistant (tekst), geërfd van de site, fallback `system-ui`. Lees eerst Elementor-variabelen (`var(--e-global-color-8bf8b31, #21A9E2)`).
- Dev-pagina `dev/index.html`: laadt `../plugin/borduur-patch-studio/assets/dist/patch-studio.js` en de css, haalt `defaults.json` + `thread-palette.json` op,
  bouwt de config met `BPS.buildPublicConfig(defaults, palette, {locale, rest:null, assets:{workerUrl:'../plugin/…/dist/patch-studio-worker.js', fontsUrl:'../plugin/…/assets/fonts/'}})`
  en mount. `rest:null` = offline-modus: prijs lokaal, "Bestellen" toont een dev-melding met de payload. Taalwissel `?lang=fr`.

### 7.9 Berichtencatalogi
- Elke module met klantteksten heeft `messages.js`: `export default { nl:{ 'ui.step1.title':'Ontwerp je patch', … }, fr:{ … } }`. Sleutelprefix = module (`ui.`, `advice.`, `pricing.`).
- `createT([uiMessages, adviceMessages, …], config.locale, config.strings)`. Ontbrekend FR → NL. Placeholders `{x}`; meervoud `{one, other}` + `n`.
- PHP-teksten (cart, e-mails, admin, REST-fouten) via `__()`/`esc_html__()`; geen tekst in canvas zonder vertaalbare bron.

### 7.10 Lettertypes
`assets/fonts/*.woff2` (subset latin, OFL; mag via npm `@fontsource/*` gedownload worden), bestandsnamen zoals `config.fonts[].file`. Laden met `FontFace` vanaf
`config.assets.fontsUrl` pas bij de eerste tekstlaag of een voorbeeld met tekst. Sora en Assistant voor de UI zelf **niet** laden (site heeft ze).

---

## 8. Standaardconfig (`config/defaults.json`)

Het bestand is de bron; hier de belangrijkste keuzes en hun herkomst. Alle prijzen **excl. btw**, zoals WooCommerce.

| Onderdeel | Waarde | Bron / status |
|---|---|---|
| Btw | 21 %, tonen incl., schakelaar excl. | 02, 08 §4 |
| Maten | 4–12 cm online (geprijsd 4/6/8/10/12; tussenmaat → volgende), > 12 cm tot 30 cm via offerte; standaard 8 cm, tekst-only 6 cm | 02 §2, §10b |
| Aantal | standaard 10, presets 1/10/25/50/100 | 02 §10b |
| Staffel | 10/25/50/100 → 5/10/15/20 %, gepoold over patchregels | **echt** (02 §3) |
| Geborduurd | 2,50 / 4,04 / 5,20 / 6,23 / 7,17; borduurkaart 45,00 (1× per bestelling) | **echt** (kassa) — open punt 29,95 |
| Bedrukt | 3,50 / 4,00 / 4,50 / 5,50 / 6,50; instelkosten 20,00 | **echt** |
| Gesublimeerd | 2,50 / 3,50 / 4,00 / 4,50 / 5,00; instelkosten 20,00 (gedeeld met bedrukt); **geen staffel** | **echt** — open punt |
| PVC | 3,00 / 4,00 / 5,00 / 6,00 / 7,00; mal 80,00; min. 50, per 10; klittenband 1,00 | **echt** — open punt mal 150 |
| Geweven / chenille / leder | offerte (`mode:'quote'`), geweven min. 100 | site / 04 |
| Opties | rand (uit doek 0 · folie +2,50 · zelf knippen −10 %), achterkant (opnaaien 0 · strijklaag +1,50 · klittenband haak/lus +2,00), klittenbandkleur, tegenstuk +2,00, op vorm gesneden +1,00, dik +1,50, extra dik +3,00, flock 0,75/regel, rol garen 5,00, scharen 25,00/19,95 | **echt** (02 §4) |
| Verzending | 10,00 excl., gratis vanaf € 150 incl. (artikelen), BE+NL | **echt** |
| Technische regels | per techniek `rules` (hard/comfort) uit 04 §13; site-waarden winnen (borduren 4 mm tekst, max 12 kleuren) | bron 04 |
| Analyse/steken | drempels 07 §12, steekconstanten 07 §11.2 | gekalibreerd op 14 beelden / schatting |
| Stofkleuren, leder, mockups | **voorbeeldlijsten** | eigenaar past aan |
| Levertijd | leeg (→ "We bevestigen de levertijd samen met je digitale proef."), PVC "± 4 weken na goedkeuring van je proef" | 02 §6 |
| Proefbelofte, vanaf 1 stuk, reactietijd | letterlijke site-claims | 01 §4 |
| Testimonial | uit | 05 R14 |
| Winkelmand | `own_product`, product verborgen, naar winkelmand na toevoegen | D1 |
| Bewaartermijnen | 30 d / 90 d / 365 d / 3 j | 08 §2.3 |

Beheer (core) bewaart enkel **verschillen** in optie `bps_settings`; "Herstel standaard" per tab. Tabbladen → config-paden:
- **Technieken**: `techniques.*.enabled`, `mode`, `minQty`, `qtyStep`, `leadTime`, `rules.*` (geavanceerd, ingeklapt), `specials.*.enabled`.
- **Prijzen**: `techniques.*.basePrice.*`, `techniques.*.tiers`, `tiers.steps`, `tiers.pooled`, `fees.groups.*.amount`, `fees.scope`, `options[*].price.amount` (+ `priceByTechnique`), `options[*].enabled`, `shipping.*`, `vat.showExclToggle`.
  Bovenaan een live **voorbeeldtabel** (1/10/25/50/100 stuks × 8 cm per techniek, incl. btw) berekend met `Pricing::quote` — zo ziet de eigenaar meteen wat de klant ziet.
- **Kleuren**: garenpalet (aan/uit per code → `threadsDisabled`), `threadSpecial`, `fabrics`, `leathers`.
- **Teksten & beloftes**: `promises.*` (proefdagen, annuleren tot proef, testimonial aan/uit + tekst, deadline-banner), `contact.*`, `techniques.*.leadTime`.
- **Algemeen**: `woo.cartMode` (enkel `own_product` actief in v1), `woo.productVisibility`, link naar het product, `woo.redirectToCart`, `woo.skipFeeIfLegacyInCart`,
  `legal.fixOrderButton`, `features.*`, `retention.*`, `woo.maxUploadMb`, statusblok (uploads-zelftest, WooCommerce/Elementor/WP Rocket gedetecteerd, shortcode om te kopiëren).
Eenvoud (les uit Fancy Product Designer): niets is verplicht om te starten; elke tab heeft een korte uitleg in gewone taal; numerieke velden met eenheid en min/max;
geen JSON-editors voor de eigenaar.

---

## 9. Teststrategie

| Laag | Wat | Waar | Door |
|---|---|---|---|
| Unit (Node, `node --test`) | shared (gedaan), analysis (synthetische testbeelden: vlak logo, JPG-ruis, verloop, illustratie met schaduw, transparant + drop shadow, ronde patch op wit, wit-op-wit, kleine tekst 2–3 mm, dunne lijn), colors (ΔE, matching, reductie, contrast, stofkeuze), advice (scenario's per ontwerptype × maat, tekst-only, PDF zonder analyse, PVC-minimum, paars-geen-garen), pricing (vectors) | `tests/<module>/*.test.js` | elke bouwer |
| PHP | `php tests/pricing/run-php.php` → zelfde vectors, exit-code ≠ 0 bij verschil; `php -l` op alle PHP-bestanden | `tests/pricing/` | pricing (+ iedereen `php -l`) |
| Browser-unit (optioneel) | render-timing (< 50 ms @1000 px), analyse op `reference/eigenaar-*.{png,jpg}` + testbeelden (verwacht: illustration, rond, fillsPatch) | `tests/render/`, `tests/analysis/` met Playwright | render, analysis |
| E2E (Playwright + echte WP) | (1) pagina met `[patch_studio]` laadt zonder console-fouten, (2) logo uploaden, (3) advies + oordeel zichtbaar, (4) in winkelwagen, (5) prijs in winkelwagen == `POST /quote` == UI-bedrag, (6) bestelling afronden met cheque/overschrijving, (7) productiefiche + download origineel in admin, (8) offerte-aanvraag, (9) mobiel 390 px: sticky balk, geen horizontaal scrollen | `tests/e2e/` | testenv |
| A11y | axe-core (npm) op de dev-pagina en de WP-pagina: 0 serious/critical | `tests/e2e/` | testenv/ui |
| Grootte | `npm run build` faalt boven budget | build | allen |

`tests/run-all.sh`: `npm run build` → `npm test` → `npm run test:php` → `php -l` op alle plugin-PHP → (als WP draait of te starten is) e2e. Exit-code = eerste fout.

### Definitie van klaar (per bouwer)
1. Alle bestanden in je eigendom bestaan, `php -l`-schoon / `npm run build` slaagt, geen console-fouten.
2. Je publieke API is **exact** zoals in §7 / §4 / §6; afwijkingen staan in `docs/handoff/<rol>.md`.
3. Je tests draaien groen met `npm test` (en `npm run test:php` voor pricing).
4. Klantteksten in Vlaams NL ("je") + FR ("vous") in de catalogus of via `__()`; geen hard gecodeerde prijzen/drempels buiten de config.
5. Eerlijkheidscheck (05 §10): geen nep-claims, geen druk, geen vooraf aangevinkte betaalde opties, preview gelabeld als indicatief.
6. `docs/handoff/<rol>.md` (kort): wat gebouwd is, hoe testen, bekende beperkingen, open punten.

### Definitie van klaar (product)
De eigenaar activeert de plugin, zet `[patch_studio]` op een pagina, en zonder iets in te stellen kan een klant op gsm: upload → advies binnen 3 s →
schikken/tekst → maat/aantal met staffel → afwerking → in winkelmand met **identieke** prijs → afrekenen; het atelier ziet de productiefiche en kan een proef sturen
die de klant goedkeurt. Alle e2e-scenario's groen, budgetten gehaald, axe 0 serious.

---

## 10. Testomgeving (testenv) — afspraken met de anderen
- WordPress + WooCommerce + `sqlite-database-integration` in `/tmp/claude-0/-home-user-Path-finding-algorithm/119da7ba-62fd-5edf-a213-c52888883035/scratchpad/wp`,
  `php -S 127.0.0.1:8899` (router voor permalinks), admin `admin` / wachtwoord in `tests/e2e/.env.example` (lokaal, geen echt geheim).
- `tests/e2e/start-wp.sh`: idempotent; symlinkt de plugin, activeert WooCommerce + Patch Studio, zet winkel op BE/EUR/21 % btw (prijzen excl. ingevoerd, incl. getoond),
  flat-rate 10,00 + gratis vanaf 150, betaalmethodes cheque + overschrijving (bacs), maakt pagina "Patch Studio" met `[patch_studio]`, permalinks "post name".
- Andere bouwers mogen het script gebruiken om handmatig te testen, maar wijzigen het niet.

---

## 11. Hooks en filters (PHP) — overzicht

| Naam | Type | Argumenten | Door |
|---|---|---|---|
| `bps_components` | filter | `string[] $classes` | core |
| `bps_config` | filter | `array $config` | core |
| `bps_public_config` | filter | `array $public, string $locale` | core |
| `bps_settings_updated` | action | `array $config` | core |
| `bps_activate` / `bps_deactivate` | action | — | core |
| `bps_price_quote` | filter | `array $quote, array $input` (na berekening, PHP) | pricing |
| `bps_design_created` | action | `int $design_id, array $payload` | woo |
| `bps_design_ordered` | action | `int $design_id, WC_Order $order, WC_Order_Item_Product $item` | woo |
| `bps_quote_requested` | action | `int $quote_id, int $design_id` | woo |
| `bps_upload_max_bytes` | filter | `int $bytes` | woo |
| `bps_cart_item_display` | filter | `array $item_data, array $bps` | woo |
| `bps_proof_sent` | action | `int $design_id, int $order_id` | production |
| `bps_proof_response` | action | `int $design_id, string $action, string $note` | production |
| `bps_fiche_sections` | filter | `array $sections, array $design, WC_Order_Item_Product $item` | production |

Opties: `bps_settings` (overrides), `bps_product_id`, `bps_ref_seq`, `bps_quote_seq`, `bps_db_version`. Transients: `bps_rl_*` (rate limit).
Cron: `bps_cleanup` (dagelijks). CPT's: `bps_design`, `bps_quote`. `uninstall.php` (core): opties + transients weg; CPT-posts en bestanden **alleen** als de beheerder
in Algemeen "Alles verwijderen bij deïnstalleren" aanvinkte (standaard uit: bestellingen verwijzen ernaar).

---

## 12. Open punten voor de eigenaar (ook in de UI-teksten neutraal opgelost)
1. Borduurkaart € 45 (kassa) of € 29,95 (teksten)? Per bestelling of per ontwerp? Vervalt hij bij herbestelling? (`fees`, `promises.reorderKeepsSetup`)
2. PVC-mal € 80 (kassa) of € 150 (productpagina)?
3. Gesublimeerd: staffelkorting ja/nee? Flock per stuk of per regel?
4. Geweven/chenille/leder: zelf maken of uitbesteden; online prijzen tonen of offerte houden? Minimum geweven 100?
5. Levertijden per techniek en proeftermijn ("binnen X werkdagen").
6. Merrow, lasercut, 3D-puff, metallic: worden ze aangeboden en tegen welke meerprijs?
7. Twillkleuren (stofkleuren) die echt op voorraad zijn; standaard zwart/wit garencode (1800/1801?).
8. "Kosteloos annuleren tot je de proef goedkeurt" beloven? (`promises.cancelUntilProof`) · "Bestellen en betalen"-knop sitebreed ok? (`legal.fixOrderButton`)
9. Testimonial van Jasper tonen? Echte patchfoto's/reviews aanleveren?
10. Franse teksten laten nalezen door een moedertaalspreker.
11. Na goedgekeurde proef automatisch een orderstatus zetten (bv. "In productie")?
12. Fase 2: bestellen op de bestaande producten met Product Add-Ons (`existing_products`), PDF.js-voorbeeld, perspectiefcorrectie, herbestellen zonder borduurkaart.
