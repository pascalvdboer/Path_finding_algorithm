# Audit "concurrentie-features": wat premium product-designers wel hebben (18-11-2026)

Rol: vergelijken met Fancy Product Designer (FPD), Zakeke, Lumise, de Printful-designer en Kickflip. De vraag is welke
**serverkant- en beheerfuncties** een premium patch-tool voor **één winkel** (borduurservicebelgie.be) echt nodig heeft, en
welke features overbodige rommel zijn die de tool moeilijker maken (de eigenaar vond FPD te moeilijk). Er is geen code gewijzigd.

## Bronnen en werkwijze
- Webonderzoek: de officiële sites fancyproductdesigner.com en gokickflip.com waren niet bereikbaar vanuit deze sessie (netwerk).
  Daarom komen de concurrentiegegevens uit zoekresultaten:
  - FPD: support center (order viewer "Load in Order Viewer", Pro Export met printing box, 300 dpi, "Exclude in Export",
    fonts directory) en pricing rules / "Get a quote".
  - Lumise: docs en WooCommerce-listing (30+ templates, 300+ cliparts, templates per categorie en per product, eigen fonts,
    printklare bestanden op 300 dpi, uploadlimieten).
  - Zakeke: printklare bestanden, 3D/AR, virtual try-on.
  - Kickflip: regels die onmogelijke combinaties blokkeren, configuraties op de orderpagina.
  - Printful: borduurpalet, max. kleuren, automatische dichtstbijzijnde garenkleur, 3D-puffregels, eenmalige digitaliseerkost.
- Code gelezen: `includes/class-plugin.php`, `includes/frontend/*`, `includes/woo/class-designs.php`, `class-quotes.php`,
  `class-uploads.php`, `includes/admin/class-order-production.php`, `includes/crosssell/class-stats.php`, `config/defaults.json`,
  en (alleen-lezen) `assets/src/ui/samples.js`.
- Gedraaid:
  - `npm test`: 359 tests, 354 geslaagd, **2 gefaald** (`tests/analysis/accuracy.test.js`: flat-club-logo en
    one-colour-silhouette krijgen "sublimated" aanbevolen in plaats van "embroidered", met `low_contrast` overal). Dat valt
    waarschijnlijk onder het team dat de renderer/UI herbouwt, en is hier enkel ter info.
  - `npm run test:php`: 47 vectoren, 652 checks, 0 gefaald.
  - `tests/e2e/start-wp.sh start` op de lokale WP + Woo, daarna gemeten met WP-CLI en REST:
    - het WooCommerce-menu bevat "Patch Studio" en "Patch-offertes";
    - REST `patch-studio/v1` heeft 9 routes: cart, designs (+email, original), quote, quotes, token;
    - geen routes voor sjablonen, elementen, AI, naamstroken of cross-sell;
    - `bps_design` telt 73 ontwerpen, en daarvoor is geen beheerscherm (`show_ui => false`).

## Overlap met andere audits (hier niet dubbel geteld)
- Exportpakket / printklare bestanden voor de digitaliseerder (FPD Pro Export, Lumise 300 dpi): **atelier-flow-6**.
- Order viewer, proefrondes, specwijziging door het atelier: **atelier-flow-1/-3/-8**. Batch-/atelieroverzicht: **atelier-flow-5**.
- Opslaan, delen, "Mijn ontwerpen" en herbestellen: **klant-account-2/-4/-5**, **woo-compat-3**.
- Offerte → bestelling en betaallink: **woo-compat-11**, **klant-account-6**.
- Live voorbeeld, import/export van instellingen, merk: **admin-ux-5/-6/-8**. Vormen/lettertypes aan/uit: **admin-ux-13**.
- Modules (AI, naamstroken, cross-sell, diensten) worden niet geboot (`Plugin::COMPONENTS`): **productisering-10**. Dat
  bevestig ik op de testsite: de functies waarmee concurrenten adverteren (cross-sell, naambadges) staan er wel, maar zijn inactief.
- Regels voor optiecombinaties (Kickflip): al aanwezig via `options.*.techniques` en `requiresAny`. Geen bevinding.

## Bevindingen (gesorteerd op ernst)

### concurrentie-features-1 · hoog · Geen beheerbare bibliotheek van sjablonen en een echte galerij
- **Bestanden:** `assets/src/ui/samples.js` (alleen-lezen: 3 vaste SVG-voorbeelden), `config/defaults.json` (geen sleutel
  `templates`), `includes/rest/class-routes.php` (geen `/templates`), `includes/frontend/class-shortcode.php`.
- **Probleem:** FPD en Lumise laten de winkel zelf sjablonen beheren, met categorieën, volgorde en een selectie per product.
  De eigenaar eist een galerij per techniek, per toepassing en per vorm ("klik = start met dat sjabloon"). Nu zijn er 3
  ingebakken voorbeelden in JS. De eigenaar kan er zonder ontwikkelaar geen toevoegen, aanpassen of vervangen door echt werk.
  Het sterkste en eerlijkste verkoopargument ontbreekt: foto's van échte, geproduceerde patches naast het sjabloon.
- **Voorstel:**
  - CPT `bps_template` (beheer onder Patch Studio) met:
    - titel nl/fr;
    - taxonomieën techniek, toepassing (club, bedrijf, werkkleding, motorclub, jeugdbeweging, evenement, cadeau) en vorm;
    - `_bps_payload`, gevalideerd door `Payload::validate()`;
    - een previewbeeld;
    - een optionele "foto van een echte productie" (label "Echte patch uit ons atelier" / "Vrai écusson de notre atelier");
    - volgorde, uitgelicht en aan/uit.
  - Admin-actie "Bewaar als sjabloon" op een orderregel en op een offerte. Een vinkje "klant gaf toestemming" is daarbij
    verplicht, en wordt gelogd met datum en gebruiker.
  - Publieke route `GET /templates?technique=&use=&shape=`, compact en cachebaar (`Cache-Control: public`, ETag), met
    labels uit de taalfallback.
  - Shortcode- en widgetattribuut `sjabloon="slug"`.
  - Bij activatie 10 à 12 neutrale startsjablonen zonder merken (embleem met boogtekst, schild, banner, rond logo,
    vintage badge, naamstrook).
  - UI-behoefte genoteerd in `docs/handoff/premium-ui-needs.md`.
- **Acceptatie:**
  - De beheerder maakt in ≤ 2 minuten een sjabloon aan uit een bestaande bestelling, zonder JSON.
  - `GET /templates` geeft per item labels nl+fr, techniek, toepassing, vorm, preview-URL en payload. Het antwoord met
    12 sjablonen is ≤ 30 kB gzip en is cachebaar.
  - Een ongeldige payload wordt bij het opslaan geweigerd, met een melding.
  - Zonder toestemmingsvinkje kan je uit een klantorder geen sjabloon maken.
  - `[patch_studio sjabloon="schild"]` geeft `page.template = "schild"` in de publieke config.

### concurrentie-features-2 · hoog · Geen overzicht met inzichten: de eigenaar ziet niet wat de studio oplevert
- **Bestanden:** `includes/crosssell/class-stats.php` (alleen cross-sell-tellers, in een losse optie),
  `includes/woo/class-designs.php`, `class-quotes.php`, `config/defaults.json` (`features.analyticsEvents`: alleen een
  dataLayer naar Google Analytics, standaard uit).
- **Probleem:** Zakeke en Kickflip verkopen zichzelf met rapportering over configuraties en bestellingen. Hier zit de data al
  op de eigen server (73 ontwerpen op de testsite, offertes, ordermeta met techniek, maat en advies), maar er is geen enkel
  scherm dat ze toont. De eigenaar kan niet zien:
  - hoeveel ontwerpen een bestelling werden;
  - welke techniek het advies aanraadt en welke de klant uiteindelijk koopt;
  - waarom ontwerpen "niet borduurbaar" zijn;
  - hoeveel offertes omgezet worden;
  - de omzet per techniek;
  - de gemiddelde maat en het gemiddelde aantal.

  Zonder die cijfers kan hij prijzen en advies niet bijsturen.
- **Voorstel:** een pagina "Patch Studio › Overzicht" als eerste submenu en startpagina van de plugin:
  - kaarten voor 7/30/90 dagen: ontwerpen → offertes → bestellingen, omzet per techniek, advies ≠ gekozen techniek,
    de top-5 adviesredenen (codes zoals `line_too_thin` en `low_contrast` uit `_bps_payload.advice`), de gemiddelde
    maat en het gemiddelde aantal, en openstaande proeven (koppeling met atelier-flow-9);
  - berekend uit eigen data, zonder tracking, cookies of persoonsgegevens;
  - een samenvattingstabel (of transient van 1 u) die bijgewerkt wordt bij `designs`-, `quotes`- en
    order-statusgebeurtenissen;
  - een CSV-export van de cijfers;
  - de cross-sell-tellers verhuizen naar dit scherm.
- **Acceptatie:**
  - Op de testsite met fixtures komen de getoonde aantallen exact overeen met tellingen via WP-CLI.
  - De pagina laadt in < 1 s met 10 000 ontwerpen (seed-script).
  - De pagina doet 0 externe requests.
  - Er staan geen namen of e-mailadressen op de pagina.

### concurrentie-features-3 · hoog · Het atelier kan geen ontwerp maken namens de klant en geen "bestel dit"-link sturen
- **Bestanden:** `includes/rest/class-routes.php` (alleen klantroutes), `includes/woo/class-cart.php`,
  `includes/woo/class-quotes.php`.
- **Probleem:** de kernbelofte is "Persoonlijke service op de traditionele manier" en "Een logo, schets of idee is genoeg".
  In de praktijk mailt een klant een logo of belt hij, en dan moet het atelier het ontwerp zelf in de studio kunnen samenstellen
  en de klant één link sturen om te bestellen. FPD (admin maakt en bewerkt producten) en Kickflip (self-service portal) laten
  dat toe. Hier kan het niet: woo-compat-11 lost alleen offerte → order op, maar geen ontwerp dat het atelier zelf maakt.
- **Voorstel:**
  - Een "atelier-modus" van de studio (`?bps_atelier=<nonce>`, alleen met `manage_woocommerce`), zonder rate-limits.
    Daarin kan de beheerder:
    - een klant-e-mail invullen;
    - een interne notitie toevoegen;
    - optioneel een prijsafwijking ingeven (vast bedrag of %, met een verplichte reden).
  - Bij opslaan ontstaat een `bps_design` (bron `atelier`) en een ondertekende link "Bestel dit ontwerp" / "Commander ce design"
    (vervalt na 30 dagen). Die link legt exact deze regel in de winkelmand, met de vergrendelde prijs.
  - Daarnaast de knop "Maak bestelling + betaallink" (sluit aan bij woo-compat-11).
  - De mail aan de klant volgt de huisstijl van de proefmail.
- **Acceptatie:**
  - e2e: de admin maakt een ontwerp en kopieert de link. Een gast opent de link, en de winkelmand bevat 1 regel met dezelfde
    payload en prijs. Die gast rekent af, en de order heeft een fiche en een proefknop.
  - Een prijsafwijking verschijnt als ordernotitie met de admin-gebruiker en de reden.
  - Een verlopen of gewijzigde link geeft een nette melding in nl/fr.

### concurrentie-features-4 · middel · Geen beheerlijst van ontwerpen: bewaarde, niet-bestelde ontwerpen blijven onzichtbaar
- **Bestanden:** `includes/woo/class-designs.php:44-70` (`'show_ui' => false`).
- **Probleem:** de 73 ontwerpen op de testsite zijn alleen te vinden via een order of offerte. Een klant die "Bewaar & mail"
  gebruikte, is een warme lead, maar het atelier ziet die nergens. Ook een klant die belt over "mijn ontwerp van vorige week"
  is niet terug te vinden. Concurrenten tonen een lijst van bewaarde of gemaakte configuraties.
- **Voorstel:**
  - Een `WP_List_Table` "Ontwerpen", alleen-lezen, met:
    - ref en miniatuur (300 px webp, zie prestatie-1);
    - datum;
    - geadviseerde en gekozen techniek, maat en aantal;
    - status (draft, saved, quoted, ordered);
    - links naar de offerte of order.
  - Het e-mailadres staat er alleen als de klant het zelf gaf.
  - Filter "bewaard, niet besteld, ouder dan 3 dagen", zoeken op ref of e-mail, en de actie "Open in atelier-modus"
    (concurrentie-features-3).
  - De retentie uit `retention.*` blijft gelden.
- **Acceptatie:**
  - Op de testsite toont de lijst alle 73 ontwerpen, en de filters geven dezelfde aantallen als WP-CLI.
  - De lijst is alleen zichtbaar met `manage_woocommerce`.
  - Er wordt geen e-mailadres getoond zonder `_bps_email`.
  - De AVG-export en -wissing dekken dezelfde records.

### concurrentie-features-5 · middel · Lettertypes zijn niet beheerbaar en niet gekoppeld aan de borduurfonts van het atelier
- **Bestanden:** `config/defaults.json` (`fonts`: 6 vaste fonts), `assets/fonts/*.woff2`,
  `includes/admin/class-order-production.php` (`font()` toont alleen de naam).
- **Probleem:** FPD (fonts directory) en Lumise (eigen fonts) laten de winkel fonts toevoegen. Een club met een eigen
  huisletter of een atelier met een favoriet blokschrift kan hier niets. Belangrijker voor borduren: de digitaliseerder werkt
  met de alfabetten van de borduursoftware, niet met webfonts. De fiche zegt niet welk machine-alfabet overeenkomt met
  "Oswald", dus het resultaat kan afwijken van wat de klant zag.
- **Voorstel:**
  - Een lettertypebeheer:
    - upload van woff2/ttf/otf (controle op magic bytes, ≤ 1 MB, opslag in `uploads/patch-studio/fonts/`);
    - een verplicht vinkje "ik heb de licentie voor webgebruik";
    - per font: label nl/fr, `thin`/`script` (strengere minimale letterhoogte), toegestane technieken en `machineFont`
      (de naam in de borduursoftware).
  - De fiche en het digitaliseerpakket (atelier-flow-6) tonen "Oswald → machinefont: …".
  - Aan/uit per font zit al in admin-ux-13.
- **Acceptatie:**
  - Een geüpload TTF verschijnt in `public_config.fonts` met een URL en de juiste vlaggen.
  - Een hernoemd PNG-bestand wordt geweigerd.
  - De fiche van een order met tekst toont `machineFont` zodra dat ingevuld is.
  - Voor een font met `script: true` geldt dezelfde minimale letterhoogte als voor Pacifico (unit-test).

### concurrentie-features-6 · middel · Geen bibliotheek met patch-typische elementen (in plaats van 300 losse cliparts)
- **Bestanden:** geen. Sanitizer: `includes/woo/class-svg-sanitizer.php`.
- **Probleem:** Lumise levert meer dan 300 cliparts en FPD een designbibliotheek. Voor patches is een generieke clipartberg
  rommel, maar een handvol vaste bouwstenen zit wel in bijna elke clubpatch:
  - lauwerkrans;
  - sterren;
  - banner of lint voor tekst;
  - vlaggen van BE, NL en de gewesten;
  - "EST./SINDS"-jaartalbadge;
  - anker, bliksem, kroon.

  Nu moet de klant die zelf aanleveren.
- **Voorstel:**
  - 30–50 zelfgemaakte (of correct gelicentieerde), borduurbare SVG-elementen.
  - Beheer als mediacategorie of CPT `bps_element`, met de bestaande SVG-sanitizer, categorieën, en per element de
    technieken waarvoor het geschikt is.
  - Route `GET /elements`, cachebaar.
  - Een buildscript controleert dat elk element op 6 cm de minimale lijndikte haalt (de analyseregels voor borduren).
  - Geen prijs per element (rommel bij Lumise).
- **Acceptatie:**
  - Elk element komt ongewijzigd door de sanitizer.
  - Een script in `npm test` toont dat 100 % van de elementen op 6 cm de status `ok` of `warn` krijgt voor borduren, en nooit
    `no`.
  - `GET /elements` is ≤ 60 kB gzip.
  - De beheerder kan een element uitschakelen, en het verdwijnt dan uit de route.

### concurrentie-features-7 · middel · Geen berekende leverdatum en geen deadlinecontrole
- **Bestanden:** `config/defaults.json`:
  - `techniques.*.leadTime`: vrije tekst, overal leeg;
  - `leadDays: null`;
  - `promises.leadTimeFallback`: "We bevestigen de levertijd samen met je digitale proef";
  - `promises.deadline.enabled: false`.
- **Probleem:** "Wanneer heb ik het?" is bij club- en evenementpatches de beslissende vraag. Printful toont per product
  een productietijd. Hier krijgt de klant geen datum, terwijl de techniek, de proefdagen en de verzending bekend zijn. Een
  eerlijke, berekende vork verkoopt beter dan "we laten het weten", en vangt deadlines op voordat er besteld wordt.
- **Voorstel:**
  - Een klasse `Lead_Time` met:
    - per techniek `productionDays` (werkdagen, uitbesteed werk zoals PVC of geweven apart);
    - `proofDays`;
    - verzenddagen per land;
    - sluitingsperiodes (vakantie, instelbaar) en Belgische feestdagen;
    - een optionele toeslag per 100 stuks.
  - `/quote` en `/cart` geven `estimate: {from, to}` terug, of `null` als het niet ingesteld is. Dan wordt er nooit een
    datum verzonnen.
  - Tekst: "Rond 3–6 december in huis, gerekend vanaf je akkoord op de proef" / "Vers le 3–6 décembre, à compter de votre
    accord sur l'épreuve".
  - Een optioneel deadlineveld: ligt de deadline vóór `to`, dan volgt een eerlijke melding en het aanbod van de dienst
    "spoed" (als die actief is).
  - De beloofde vork komt als ordermeta op de fiche.
- **Acceptatie:**
  - Unit-tests: een bestelling op vrijdag, over Kerstmis met een sluitingsweek, en op 1 mei/21 juli geven de verwachte
    datums.
  - Met lege instellingen is `estimate === null` en blijft de huidige fallbacktekst.
  - De order bevat `_bps_promised_from/to`, en de fiche toont die.

### concurrentie-features-8 · middel · Een bestaand borduurbestand (DST/PES/EXP/JEF) kan niet geüpload worden
- **Bestanden:** `config/defaults.json` (`analysis.acceptStore`: alleen pdf/ai/eps/psd), `includes/class-uploads.php:30, 209`.
- **Probleem:** geen van de generieke designers doet dit, maar voor een borduuratelier is het een duidelijk voordeel.
  Herbestellers en klanten die van een ander atelier komen, hebben vaak al een DST- of PES-bestand. Nu wordt dat geweigerd
  ("Dit bestandstype kunnen we niet lezen"). Ze moeten dan mailen, of ze betalen opnieuw digitaliseerkosten. Printful toont
  hoe zwaar die eenmalige kost weegt.
- **Voorstel:**
  - .dst, .pes, .exp en .jef aanvaarden (≤ 5 MB, controle op de header-signatuur, privé opgeslagen zoals de originelen).
  - Server-side de DST-header lezen (ST = steken, CO = kleurwissels, +X/-X/+Y/-Y = afmetingen in 0,1 mm) en dat als
    `embFile: {stitches, colorChanges, widthMm, heightMm}` in de payload en op de fiche zetten.
  - De digitaliseerkost vervalt niet automatisch. De regel krijgt "bestaand borduurbestand – het atelier controleert of het
    bruikbaar is" en de instelling `fees.embFileDiscount` (standaard uit).
  - Geen beloftes die het atelier niet nagekeken heeft.
- **Acceptatie:**
  - Een fixture-DST geeft exact de stekenaantallen en maten uit de header.
  - Een PES zonder geldige `#PES`-header wordt geweigerd met een melding in nl/fr.
  - Het bestand is niet publiek opvraagbaar (HTTP 403 of buiten de webroot).
  - De fiche toont de vier waarden.

### concurrentie-features-9 · middel · Varianten met namen op hetzelfde embleem ontbreken (teambestelling)
- **Bestanden:** `includes/woo/class-payload.php` (`texts()`), `includes/class-pricing.php`, `includes/nametape/class-validator.php`
  (bestaande CSV-parser).
- **Probleem:** Zakeke en FPD bieden naam/nummer-varianten, en de naamstrook-module doet het voor losse strips. Maar het
  klassieke patchscenario is "ons clubembleem, met onder elk exemplaar een andere naam" (motorclub, brandweer, scouts,
  personeel). Nu moet de klant per naam een apart ontwerp maken, en betaalt hij het opzetwerk meermaals of krijgt hij geen
  gepoolde staffel.
- **Voorstel:**
  - Een tekstlaag mag `variable: true` zijn, met `values[]` (plakken of CSV, hergebruik van de naamstrook-parser, max 500).
  - Server:
    - het aantal = het aantal waarden;
    - één keer opzetkosten en de staffel op het totaal;
    - een instelbare toeslag per naam (`fees.perVariantName`);
    - de fiche en werkbon tonen een genummerde namenlijst plus een CSV-download.
  - De validatie hergebruikt `Nametape\Validator` (lengte, tekens).
  - UI-behoefte gaat naar `docs/handoff/premium-ui-needs.md`.
- **Acceptatie:**
  - PHP-test: een embleem met 12 namen geeft qty 12, 1× opzetkosten, 12 × de toeslag, en dezelfde stukprijs als qty 12
    zonder namen plus de toeslag.
  - De fiche toont 12 regels.
  - Een 501e naam geeft een duidelijke fout.
  - Een naam met verboden tekens wordt per regel gemeld.

### concurrentie-features-10 · middel · Landings- en productpagina's kunnen de studio maar beperkt vooraf instellen
- **Bestanden:** `includes/frontend/class-shortcode.php:45-131` (alleen `techniek`, `maat`, `aantal`, `vorm`, `compact`),
  `includes/frontend/class-elementor-widget.php`.
- **Probleem:** FPD stelt de designer per product in. De site leeft van SEO-pagina's per techniek (PVC-patches, chenille,
  naamstroken op klittenband, geweven labels). Op zo'n pagina wil de eigenaar:
  - alleen die techniek, of een kleine subset;
  - een passend sjabloon;
  - voorgekozen opties (bv. klittenband);
  - bij chenille alleen "offerte aanvragen".

  `techniek` kiest nu alleen voor, maar sluit niets uit.
- **Voorstel:**
  - Extra attributen: `technieken="pvc,woven"` (beperking), `sjabloon`, `opties="velcro_hook"`, `stof` en
    `actie="offerte|bestellen"`.
  - Dezelfde velden in de Elementor-widget en in een eenvoudig Gutenberg-blok.
  - Een metabox "Studio op deze productpagina" op een Woo-product met dezelfde presets.
  - De server valideert alles tegen de config (onbekend = genegeerd) en geeft het door in `page`.
  - `/cart` weigert een techniek die de pagina uitsluit niet (de klant mag via de hoofdstudio alles). `actie=offerte`
    verbergt wel de bestelknop.
- **Acceptatie:**
  - `[patch_studio technieken="pvc,woven" opties="velcro_hook"]` geeft `page.techniques == ["pvc","woven"]` en
    `page.options == ["velcro_hook"]`.
  - Een onbekende waarde valt weg (PHP-test).
  - De Elementor-widget en het blok bieden dezelfde controls (test die de lijsten vergelijkt).

### concurrentie-features-11 · middel · Wat we NIET bouwen, en het beheer niet versnipperen
- **Bestanden:** `includes/admin/class-settings-page.php:75`, `includes/ai/class-admin.php:86`, `includes/nametape/class-admin.php:39`,
  `includes/crosssell/class-admin.php:36`, `includes/services/class-admin-sections.php:478`, `includes/woo/class-quotes.php`
  (6 aparte submenu's onder WooCommerce zodra de modules geboot worden), `docs/SPEC.md`.
- **Probleem:** de eigenaar haakte af op FPD omdat het te moeilijk was. De concurrenten stapelen features die voor één
  patchwinkel geen waarde hebben en het beheer verzwaren:
  - 3D/AR en virtual try-on (Zakeke);
  - meerdere zijden (voor/achter);
  - productkleurvarianten;
  - print-on-demand-koppelingen (Printful);
  - marktplaats/vendors;
  - een prijs per clipart (Lumise);
  - 1000+ Google Fonts;
  - import uit Instagram, Drive of Facebook;
  - JSON-layout-editors en UI-thema's;
  - watermerken op downloads.

  Tegelijk groeit het eigen beheer naar 6 losse items tussen de WooCommerce-menu's, en `defaults.json` heeft 1055
  instelbare bladwaarden (`ai-prompts.json` nog eens 955).
- **Voorstel:**
  - Een sectie "Niet-doelen" in SPEC.md met bovenstaande lijst en waarom.
  - Eén hoofdmenu "Patch Studio" met: Overzicht (concurrentie-features-2), Atelier (atelier-flow-5), Offertes, Ontwerpen,
    Sjablonen & elementen, Instellingen (tabbladen, met modules aan/uit volgens productisering-10).
  - Geavanceerde sleutels (analysedrempels, prompts) blijven buiten de gewone schermen, achter "Gevorderd".
- **Acceptatie:**
  - De plugin voegt precies 1 hoofdmenu toe met ≤ 7 subpagina's, en 0 items onder WooCommerce (WP-CLI-test op `$submenu`).
  - SPEC.md bevat "Niet-doelen".
  - Er bestaat geen instelling voor een niet-doel.
  - Elk gewoon instellingenscherm toont ≤ 25 velden zonder "Gevorderd" open te klikken.

### concurrentie-features-12 · laag · De offerte is geen deelbaar document voor een bestuur of aankoopdienst
- **Bestanden:** `templates/emails/quote-customer.php` (alleen een ontvangstbevestiging), `includes/woo/class-quotes.php`.
- **Probleem:** clubs, scholen en bedrijven moeten een offerte intern laten goedkeuren. Concurrenten met een offerteflow
  (FPD "Get a quote", Kickflip) leveren een duidelijke prijsopgave. Hier krijgt de klant geen document met preview,
  specificaties, staffel, geldigheid en ref.
- **Voorstel:**
  - Een print- en PDF-vriendelijke offertepagina (eigen print-CSS, geen externe PDF-dienst) via een ondertekende link in de
    offertemail, met:
    - ref;
    - preview (600 px jpg);
    - techniek, maat, opties en garens (Polyneon nr. + naam);
    - een prijstabel incl./excl. btw volgens woo-compat-5;
    - "geldig tot" (instelling `quote.validDays`, standaard 30);
    - een knop "Offerte aanvaarden" (klant-account-6).
  - Vlaams en Frans.
- **Acceptatie:**
  - De link toont de pagina zonder login en met een geldig token, en geeft 403 met een gewijzigd token.
  - Afdrukken past op 1 A4 (Playwright `page.pdf` ≤ 1 pagina).
  - De geldigheidsdatum = aanvraagdatum + `validDays`.
  - Na de vervaldatum staat er "Deze offerte is verlopen, vraag een nieuwe aan".

### concurrentie-features-13 · laag · Tekstlagen worden niet nagekeken op haat- of misbruiktekens
- **Bestanden:** `includes/woo/class-payload.php` (`texts()`). Alleen de AI-module heeft `class-moderation.php`.
- **Probleem:** vrije tekst op patches (motorclubs, airsoft) kan haatcodes of beledigingen bevatten. Die komen nu zonder
  signaal tot in productie, tenzij iemand ze bij de proef opmerkt. Ik heb niet kunnen bevestigen welke concurrent hier een
  filter voor heeft. Dit voorstel staat op eigen merites.
- **Voorstel:**
  - Een instelbare lijst (standaard een korte, gedocumenteerde lijst van ondubbelzinnige termen; leeg kan ook).
  - **Nooit stil blokkeren** en geen fout aan de klant.
  - Een treffer zet de vlag `_bps_text_review` op de orderregel. Die verschijnt als oranje label in de orderlijst en op de
    fiche ("Tekst nakijken vóór de proef").
  - Normalisatie: hoofdletters, cijfers↔letters (1→i, 0→o) en spaties.
- **Acceptatie:**
  - PHP-test: een term uit de lijst, ook met leetspeak, zet de vlag.
  - "Brandweer Kalmthout 1988" zet de vlag niet met de standaardlijst.
  - Met een lege lijst wordt er niets gevlagd.
  - Bestellen werkt in alle gevallen.

## Prioriteit voor de bouw
1. concurrentie-features-1 (sjablonen/galerij) en -3 (atelier-modus + bestellink): direct omzet en de harde eis "proactief tonen".
2. concurrentie-features-2 (overzicht) en -4 (ontwerpenlijst): de eigenaar stuurt bij op cijfers en volgt warme leads op.
3. concurrentie-features-7 (leverdatum), -9 (namen op het embleem), -10 (presets per landingspagina) en -5 (fonts ↔ machinefonts).
4. concurrentie-features-6, -8, -11, -12 en -13.
