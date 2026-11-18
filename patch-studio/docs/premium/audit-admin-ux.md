# Audit admin-ux: beheerervaring voor de eigenaar

Auditor: admin-ux, 17-11-2026. Er is geen code gewijzigd.
Getest op de lokale WordPress 7.1.4 + WooCommerce 11.3.0 (tests/e2e/start-wp.sh, http://127.0.0.1:8899) met Playwright/Chromium,
op 1366 px en op 390 px breed. `npm test` gaf 228 geslaagd, 0 gefaald en 3 todo. `npm run test:php` gaf 652 checks en 0 gefaald.
Er zijn geen tests voor de beheerpagina zelf.

Screenshots staan in `docs/premium/screenshots-admin-ux/`: `desktop-{technieken,prijzen,kleuren,teksten,algemeen}.png`,
`desktop-prijzen-edit.png`, `mobile-prijzen.png`, `mobile-kleuren.png` en `plugins.png`.

## Wat al goed is
- Vijf tabbladen met uitleg in gewone taal. Euro-velden tonen live "klant betaalt € x incl. btw". Ook "standaard: x" bij gewijzigde waarden,
  een melding bij niet-bewaarde wijzigingen, "Herstel standaard" per tabblad en een statusblok met zelftests.
  Er is een prijstabel (voorbeeldtabel) met de echte `Pricing::quote`, een kassa-check met de bestaande producten en de knop "Maak de ontwerppagina aan".
- Er zijn geen JSON-editors, en een decimale komma werkt.

## Gemeten
| Tab | Hoogte (px, 1366 breed) | Invoervelden | Uitlegteksten |
|---|---|---|---|
| Technieken | 2044 | 149 | 28 |
| Prijzen | 4596 | 113 | 12 |
| Kleuren | 2882 | 172 | 2 |
| Teksten | 2368 | 43 | 7 |
| Algemeen | 1547 | 21 | 13 |

## Bevindingen (gesorteerd op ernst)
Zie de lijst hieronder. Elke bevinding heeft een id, ernst, bestanden, probleem, voorstel en acceptatiecriterium.

### admin-ux-1 · hoog · Knop "+ Nog een kleur" doet niets
- **Bestanden:** `includes/admin/views/tab-kleuren.php` (de knop `data-bps-add-row="#bps-fabrics"`), `includes/admin/assets/settings.js` (handler add-row)
- **Probleem:** de selector `#bps-fabrics` vindt de `<h2>`, niet de sectie. `h2.querySelector('tbody')` geeft dus niets, en de reserve `closest('section')` wordt nooit gebruikt. Gemeten: 10 rijen vóór de klik en 10 erna, bij Stofkleuren en Ledertinten. De eigenaar kan per keer maar één kleur toevoegen (via de lege rij) en moet daarna bewaren.
- **Voorstel:** geef de `<section>` een eigen id (`bps-sec-fabrics`) of zoek altijd met `button.closest('section')`. Voeg een e2e-test toe.
- **Acceptatie:** na een klik op "+ Nog een kleur" is het aantal rijen +1 en heeft het tekstveld de focus. Na drie klikken, invullen en bewaren staan er 3 nieuwe stofkleuren in `bps_settings.fabrics`.

### admin-ux-2 · hoog · Geen onboarding-wizard bij activatie
- **Bestanden:** `includes/class-plugin.php` (`activate_site`, `ACTIVATED_TRANSIENT`), `includes/admin/class-settings-page.php` (`render_quickstart`)
- **Probleem:** na activatie verschijnt enkel een melding en een kaart "Snel aan de slag" met 3 stappen. Er is geen begeleide eerste installatie. De eigenaar komt meteen op een tabblad van 2044 px met 149 velden, en dat is precies wat hij bij Fancy Product Designer te moeilijk vond. Hij kiest ook nergens bewust welke technieken hij aanbiedt of welke contactgegevens getoond worden.
- **Voorstel:** een wizard (`admin.php?page=bps-setup`) met 5 schermen die je kan overslaan: 1) Je zaak (naam, e-mail, telefoon, logo, accentkleur), 2) Wat maak je? (grote kaarten per techniek, aan/uit + online/offerte), 3) Prijzen nakijken (live prijstabel), 4) Ontwerppagina maken (1 knop), 5) Klaar (links naar de pagina, een testbestelling en de instellingen). Eén keer doorsturen na activatie (niet bij bulk-activatie). Optie `bps_onboarding_done`. Knop "Installatiehulp opnieuw starten" bij Algemeen.
- **Acceptatie:** bij een verse installatie leidt de eerste admin-paginalading na activatie naar de wizard. Doorlopen met de standaardwaarden kan in ≤ 8 klikken en levert een gepubliceerde ontwerppagina op. Een Playwright-test doorloopt de wizard. Een tweede activatie stuurt niet opnieuw door.

### admin-ux-3 · hoog · De prijstabel is niet live
- **Bestanden:** `includes/admin/views/tab-prijzen.php` ("De tabel wordt bijgewerkt nadat je bewaart"), `includes/admin/class-settings-page.php` (`price_examples`), `includes/admin/assets/settings.js`
- **Probleem:** de belangrijkste hulp voor de eigenaar ("Zo ziet de klant het") toont pas na bewaren wat een prijswijziging doet. Bij staffels, eenmalige kosten en opties moet hij telkens bewaren en scrollen (4596 px). Alleen het hint "klant betaalt € x incl. btw" per veld is live.
- **Voorstel:** een admin-endpoint `POST /bps/v1/admin/preview-quote` (capability + nonce). Het neemt de niet-bewaarde formulierwaarden, laat ze door `Settings::sanitize`/`enforce` lopen zonder op te slaan, en geeft de tabel terug. Debounce van 300 ms. Een sticky mini-tabel bovenaan, en gewijzigde cellen even markeren (oud → nieuw).
- **Acceptatie:** wijzig je de 8 cm-prijs van Geborduurd, dan verandert de tabelcel binnen 600 ms zonder bewaren. Na bewaren zijn de bedragen identiek (op de cent) aan `Pricing::quote`. `bps_settings` verandert niet zolang je niet bewaart.

### admin-ux-4 · hoog · Validatie is stil en onvolledig
- **Bestanden:** `includes/admin/class-settings-page.php` (`number()` krijgt bij prijzen en kortingen geen min/max), `includes/class-settings.php` (`LIMITS`, `clamp`, `sanitize_value` → SKIP), `includes/admin/assets/settings.js` (`validate`)
- **Probleem:** gemeten in de browser: een basisprijs van "-5" wordt niet als fout gemarkeerd (aria-invalid=false), en een staffelkorting van "150" % ook niet. De server klemt die waarden stil (prijs → 0, korting → 95 %). Tekst die niet te lezen is, wordt stil genegeerd terwijl de melding "Bewaard" verschijnt. Een fout is alleen een rode rand: er staat geen tekst bij die zegt wat er mis is (ook niet voor schermlezers).
- **Voorstel:** geef de grenzen uit `Settings::LIMITS` door als `data-min`/`data-max` aan elk veld. Zet een foutmelding in gewone taal onder het veld ("Vul een bedrag tussen € 0 en € 10.000 in"), gekoppeld met `aria-describedby`. Laat de server elke geklemde of genegeerde waarde verzamelen in `Settings::last_notices()`, met veldlabel en nieuwe waarde. Toon "Bewaard, maar 2 waarden aangepast" in plaats van alleen groen.
- **Acceptatie:** "-5", "150" (%) en "abc" tonen elk direct een Vlaamse foutmelding onder het veld, en Bewaren focust het eerste foute veld. Een POST die de JS-validatie omzeilt met korting 150 geeft een waarschuwing met "Staffelkorting" en "95 %". Er bestaat geen pad meer waarbij een invoer stil verdwijnt.

### admin-ux-5 · hoog · Geen import, export of versiegeschiedenis van instellingen
- **Bestanden:** `includes/admin/class-settings-page.php`, `includes/admin/views/tab-algemeen.php`, `includes/class-settings.php`
- **Probleem:** er is geen back-up van prijzen en instellingen, en je kan ze niet overzetten van een staging- naar een live-site. Na "Herstel standaard" is alles weg zonder weg terug. Een betaalde plugin heeft dit standaard.
- **Voorstel:** bij Algemeen een blok "Back-up": 1) Exporteren als `patch-studio-instellingen-JJJJ-MM-DD.json` (enkel de overrides, plus schema en versie). 2) Importeren met een voorbeeld van de verschillen ("12 prijzen, 3 kleuren wijzigen") vóór bevestiging, via `Settings::update` (dus gevalideerd). 3) Automatisch een momentopname vóór elke import of reset (laatste 10, optie `bps_settings_history`, niet autoload) met "Terugzetten". Ook WP-CLI: `wp bps settings export|import`.
- **Acceptatie:** export op site A en import op een verse site B geven een identieke `bps_settings`. Een ongeldig of vreemd JSON-bestand geeft een duidelijke fout en wijzigt niets. Na een reset zet "Terugzetten" de vorige waarden exact terug.

### admin-ux-6 · hoog · Geen live voorbeeld van de studio met niet-bewaarde instellingen
- **Bestanden:** `includes/admin/class-settings-page.php` (enkel een link "Bekijk je ontwerppagina"), `includes/class-settings.php` (`public_config`)
- **Probleem:** wat technieken aan/uit, stofkleuren, garen, opties en teksten doen voor de klant, zie je alleen door te bewaren (dus live voor klanten) en de pagina in een ander tabblad te openen. Onveilig experimenteren schrikt een niet-technische eigenaar af.
- **Voorstel:** een knop "Voorbeeld" die een zijpaneel of modaal opent met de studio in een iframe (`?bps_preview=<nonce>`). De studio leest dan een tijdelijke config: een transient per gebruiker van 15 minuten, gevuld met de niet-bewaarde formulierwaarden. Wisselen tussen gsm- en desktopbreedte. Voor de UI is een kleine hook nodig, zie `docs/handoff/premium-ui-needs.md`.
- **Acceptatie:** zet je "Gesublimeerd" uit zonder te bewaren, dan verdwijnt het binnen 2 s uit het voorbeeld, maar niet op de publieke pagina voor een uitgelogde bezoeker. Het voorbeeld werkt alleen voor gebruikers met `manage_woocommerce`.

### admin-ux-7 · hoog · Beheer ontbreekt voor de modules die de eigenaar vroeg (AI-studio, diensten, naamstroken, cross-sell, licentie)
- **Bestanden:** `config/defaults.json` (geen sleutels `ai`, `services`, `nameStrips`, `crossSell`, `license`), `config/ai-prompts.json`, `config/ai-legal.json`, `includes/ai/class-ai-prompt-engine.php`, `includes/admin/class-settings-page.php`
- **Probleem:** de BRIEF vraagt instelbare verdienmodellen: AI-kredieten als product, gratis proefgeneraties, terugbetaling als korting, dag- en maandlimiet met kost per generatie in een log, diensten als WooCommerce-producten, naamstrook-formaten en stoffen, cross-sell-producten, en een optionele licentie/update-client die standaard uit staat. Geen van die dingen heeft een veld in de beheerpagina. De AI-configuratie staat alleen in JSON-bestanden, terwijl SPEC §8 zegt: "geen JSON-editors voor de eigenaar".
- **Voorstel:** nieuwe tabbladen "AI-ontwerp" (aan/uit, API-sleutel als wachtwoordveld met "Test verbinding", model, limieten in € per dag en per maand met een meter van het huidige verbruik, kredietproduct kiezen uit WooCommerce, aantal gratis generaties, % terug als korting, link naar de voorwaarden), "Diensten & extra producten" (per dienst: aan/uit, WooCommerce-product koppelen, wanneer tonen) en "Licentie" (verborgen tot aangezet). Alles standaard uit, met uitleg in gewone taal.
- **Acceptatie:** elke instelbare waarde uit BRIEF "WENSEN 17-11" en "EXTRA PRODUCTEN" heeft een veld met eenheid en uitleg. De API-sleutel wordt nooit naar de browser gestuurd (die toont `••••1234`). Met de AI-module uit verandert er niets aan de studio.

### admin-ux-8 · middel · Merkgegevens liggen vast in de code: geen white-label of merkinstellingen
- **Bestanden:** `borduur-patch-studio.php` (Plugin Name "… – Borduurservice België"), `config/defaults.json` (`contact.email` info@borduurservicebelgie.be), `config/thread-palette.json` (source), `includes/admin/views/tab-teksten.php`
- **Probleem:** de beslissing van de eigenaar is "geen hardgecodeerde merkgegevens (alles via instellingen)". Toch zijn er geen velden voor winkelnaam, logo, accentkleur van de studio of de titel van de tool. Het standaard e-mailadres en de pluginnaam bevatten de eigen zaak. Bij een verkoop krijgt elke koper "Borduurservice België" te zien.
- **Voorstel:** een blok "Je merk" (bij Algemeen of als wizardstap 1): winkelnaam (standaard `get_bloginfo('name')`), logo uit de mediabibliotheek, accentkleur met live contrastcontrole (AA), titel van de studio NL/FR. De standaardcontactgegevens zijn leeg en vallen terug op `admin_email`. Een neutrale pluginnaam "Patch Studio", met het merk via een filter.
- **Acceptatie:** `grep -ri borduurservice plugin/ --include=*.php` geeft enkel `@package` of commentaar. Een andere accentkleur verandert de CSS-variabele van de studio na bewaren. Bij een contrast onder 4,5:1 verschijnt een waarschuwing.

### admin-ux-9 · middel · Garenkaart: 106 in plaats van 411 Polyneon-kleuren, en te weinig hulpmiddelen
- **Bestanden:** `config/thread-palette.json` (106 kleuren), `includes/admin/views/tab-kleuren.php`, `includes/admin/assets/settings.js`
- **Probleem:** de eigenaar eist de volledige Madeira Polyneon-kaart van 411 kleuren (STUDIO-REDESIGN, harde eisen). De teller toont nu "106 van 106 beschikbaar". Je kan alleen op naam of nummer zoeken. Er is geen filter op kleurfamilie of "enkel uitgeschakeld", en geen import van een voorraadlijst. Je ziet ook niet welke kleur de tool gebruikt als je er een uitzet.
- **Voorstel:** een palet met 411 kleuren (inclusief `family`), filterknoppen per familie, de schakelaar "Toon enkel uitgeschakelde", plakken of importeren van een lijst nummers ("deze heb ik op voorraad"). Bij een uitgezette kleur een klein label "→ vervangen door 1801 Wit (ΔE 2,1)", met dezelfde matching als `colors/match.js`.
- **Acceptatie:** de teller toont "411 van 411 beschikbaar". De familie "Blauw" filtert correct. Plak je 50 nummers en klik je "Enkel deze aan", dan staan er precies 50 aan. Elke uitgezette kleur toont een vervangkleur.

### admin-ux-10 · middel · Tabblad Prijzen is te lang en star (4596 px, 113 velden)
- **Bestanden:** `includes/admin/views/tab-prijzen.php`, `includes/admin/class-settings-page.php` (`collect_prijzen`)
- **Probleem:** alles staat op één lange pagina, met 25 opties onder elkaar. Het soort prijs (per stuk, per bestelregel of %) kan je niet wijzigen. Een nieuwe optie, bv. "reflecterend garen", kan je niet toevoegen. Groepen kan je niet inklappen. De sticky bewaarbalk valt over de inhoud. Basisprijzen zijn alleen per vaste maat in te vullen, zonder snelle acties als "alle prijzen +5 %".
- **Voorstel:** subnavigatie of accordeons (Basisprijzen · Staffel · Eenmalige kosten · Afwerking & extra's · Verzending). De schakelaar "Toon enkel wat afwijkt van standaard". Een keuzelijst voor het prijstype en een knop "+ Optie toevoegen" (naam NL/FR, techniek, prijs). Een bulkactie "Alle basisprijzen aanpassen met x %", met eerst een voorbeeld.
- **Acceptatie:** de eerste weergave van Prijzen is ≤ 1800 px hoog op 1366×900. Een nieuwe optie verschijnt na bewaren in de studio en in `Pricing::quote`. Een bulkactie van +5 % verandert alle basisprijzen op de cent juist, pas na bevestigen.

### admin-ux-11 · middel · Staffelkorting: maar één lege rij, geen knop om toe te voegen, en horizontaal scrollen op gsm
- **Bestanden:** `includes/admin/views/tab-prijzen.php` (tabel `bps-tiers` zonder `bps-table-wrap`), `includes/admin/assets/settings.css`
- **Probleem:** voor twee nieuwe treden moet je twee keer bewaren. "Laat een rij leeg om ze te verwijderen" is verborgen gedrag. Op 390 px heeft de pagina Prijzen horizontale scroll (scrollWidth 719 bij een clientWidth van 390, gemeten). De andere tabbladen hebben dat niet.
- **Voorstel:** een knop "+ Nog een trede" en een verwijderknop (×) per rij. Een korte lijn per trede ("vanaf 25 stuks: € 7,84/st."). Alle tabellen in een `.bps-table-wrap`, en de invoertabellen op smalle schermen als kaarten tonen.
- **Acceptatie:** drie treden toevoegen lukt in één keer bewaren. Op 390×844 geldt voor elk tabblad `document.documentElement.scrollWidth === clientWidth`.

### admin-ux-12 · middel · Technieken: herhaalde uitleg, geen volgorde of standaardtechniek, en vaktermen in "gevorderd"
- **Bestanden:** `includes/admin/views/tab-technieken.php`
- **Probleem:** dezelfde uitleg ("Bij een offerte vult de klant…", "Bijvoorbeeld 10 als je…", "Levertijd per techniek…") staat 7 keer op de pagina (28 uitlegteksten op één tab). `techniqueOrder`, de volgorde in de studio, kan je niet aanpassen. De velden onder "Technische grenzen" (x-hoogte, factor, merrow…) hebben geen uitleg of voorbeeld per veld. Een techniek op "Offerte" toont geen minimumaantal-context.
- **Voorstel:** algemene uitleg één keer bovenaan, en per kaart alleen wat specifiek is. Knoppen ↑/↓ of slepen om de volgorde te bepalen ("eerst getoond in de studio"). Per grensveld één zin uitleg en een klein voorbeeldbeeld (bv. 3 mm vs 6 mm letters). Een samenvattingsregel per kaart ("Online vanaf 1 stuk · levertijd 10 werkdagen").
- **Acceptatie:** op het tabblad Technieken staat ≤ 1 keer dezelfde uitlegtekst. De volgorde aanpassen en bewaren verandert `techniqueOrder` en de volgorde in de studio. Elk grensveld heeft een uitlegtekst.

### admin-ux-13 · middel · Instellingen zonder veld in de beheerpagina
- **Bestanden:** `config/defaults.json` (`sizes`, `quantity.presets`, `shapes`/`shapeOrder`, `fonts`, `text`, `mockups`, `advice`, `woo.rateLimits`), `includes/admin/class-settings-page.php`
- **Probleem:** de min/max/standaardmaat in cm, aantal-presets, vormen aan/uit, lettertypes aan/uit, tekstlimieten en mockups kan de eigenaar niet zelf aanpassen. Voor "bied geen schildvorm aan" of "standaard 10 cm" heeft hij een ontwikkelaar nodig.
- **Voorstel:** een tabblad "Studio" met vormen (aan/uit + volgorde, met een vormicoon), lettertypes (aan/uit met een voorbeeld in dat lettertype), maten (min/standaard/max cm), snelkeuze-aantallen en de maximale lengte en aantal tekstlagen. Rate limits onder "Gevorderd".
- **Acceptatie:** elke genoemde sleutel heeft een veld met eenheid, grenzen en standaard. Zet je "Schild" uit, dan verdwijnt het uit de studio. `npm run test:php` blijft groen.

### admin-ux-14 · laag · "Herstel standaard" is alles-of-niets, zonder ongedaan maken
- **Bestanden:** `includes/admin/class-settings-page.php` (`tabs()['algemeen']['reset']` bevat o.a. `admin`, `features`), `includes/admin/assets/settings.js` (`window.confirm`)
- **Probleem:** de reset van Algemeen zet ook `deleteDataOnUninstall`, alle onderdelen en de bewaartermijnen terug. Er is enkel een browser-confirm, en terugkeren kan niet. Je kan geen enkel veld terugzetten, terwijl "standaard: x" al getoond wordt.
- **Voorstel:** maak "standaard: x" klikbaar ("↺ terug naar x") per veld. Na een tab-reset een melding met de knop "Ongedaan maken" (via de momentopname uit admin-ux-5). Een eigen bevestigingsvenster dat opsomt hoeveel waarden wijzigen.
- **Acceptatie:** een klik op "↺" zet alleen dat veld terug en markeert het formulier als gewijzigd. "Ongedaan maken" na een reset herstelt `bps_settings` byte-identiek.

### admin-ux-15 · laag · Geen automatische tests voor de beheerpagina
- **Bestanden:** `tests/e2e/` (05 test enkel de fiche), `tests/run-all.sh`
- **Probleem:** de kapotte knop uit admin-ux-1 en de ontbrekende validatie zijn niet opgemerkt. Wijzigingen aan `collect_*` of `enforce` kunnen stil prijzen breken.
- **Voorstel:** `tests/e2e/09-admin-settings.e2e.cjs`: elk tabblad laadt zonder PHP-notices of consolefouten; per tabblad een roundtrip bewaren → herladen → waarde gelijk; add-row; validatie; reset; axe-core 0 serious/critical; geen horizontale scroll op 390 px. Plus PHP-unit-tests voor `collect_prijzen` en `collect_kleuren`.
- **Acceptatie:** de test draait in `bash tests/run-all.sh` (met de WP-omgeving), is groen na de fixes en faalt op de huidige code voor admin-ux-1.
